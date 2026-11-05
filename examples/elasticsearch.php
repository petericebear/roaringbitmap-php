<?php

declare(strict_types=1);

namespace App\Services;

use Elastic\Elasticsearch\Client;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap64;
use RuntimeException;
use UnexpectedValueException;

/**
 * Laravel integration example: copy to app/Services/ElasticsearchBitmapMembership.php.
 * See examples/README.md for the migration, client binding, index mapping and usage.
 * Targets Elasticsearch's roaring_bitmap / bitmap_terms APIs (Serverless / 9.6).
 * The doc_num field is a non-negative long, so its portable bitmap width is 64 bits.
 * Merely loading this file performs no database or Elasticsearch operations.
 */
final class ElasticsearchBitmapMembership
{
    public function __construct(
        private readonly Client $client,
        private readonly Backend $backend = Backend::Auto,
        private readonly string $index = 'originals-read',
    ) {
    }

    /** Collect the complete selection in Elasticsearch and create a new saved tag. */
    public function createFromSearch(string $tenantId, string $tag, array $selectionQuery): int
    {
        $response = $this->client->search([
            'index' => $this->index,
            'allow_partial_search_results' => false,
            'body' => [
                'size' => 0,
                'track_total_hits' => false,
                'timeout' => '30s',
                'query' => ['bool' => ['filter' => [
                    ['term' => ['tenant_id' => $tenantId]],
                    $selectionQuery,
                ]]],
                'aggs' => ['members' => ['roaring_bitmap' => ['field' => 'doc_num']]],
            ],
        ])->asArray();

        // Never persist a timed-out or partial aggregation as the complete membership.
        self::requireCompleteSearch($response);
        $saved = $response['aggregations']['members']['value'] ?? null;
        if (!is_string($saved)) {
            // An unmapped field returns null; a mapped, empty selection returns an empty bitmap.
            throw new UnexpectedValueException('No bitmap returned; verify the doc_num long mapping.');
        }
        self::fromSavedString($saved, $this->backend); // Validate format, width and ID range before saving.

        return $this->insertNewTag($tenantId, $tag, $saved);
    }

    /** Or create membership from IDs already collected by the application. */
    public function createFromIds(string $tenantId, string $tag, array $ids): int
    {
        $bitmap = new Bitmap64(self::longIds($ids), $this->backend);

        return $this->insertNewTag($tenantId, $tag, base64_encode($bitmap->toPortableBytes()));
    }

    /**
     * A queue worker calls this after accepting membership events for the current rule/document versions.
     * Batch IDs when possible. Repeated add events are idempotent and do not advance the revision.
     *
     * @return array{added: int, cardinality: int, revision: int}
     */
    public function addMembers(string $tenantId, string $tag, array $ids): array
    {
        $ids = self::longIds($ids); // Materialize once so a deadlock retry replays the same input.

        return DB::transaction(function () use ($tenantId, $tag, $ids): array {
            // All writers lock the same existing row before reading its saved value.
            $row = $this->tagQuery($tenantId, $tag)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Create the tag before processing its membership events.');
            }
            $bitmap = self::fromSavedString($row->bitmap_base64, $this->backend);
            $before = count($bitmap);
            $bitmap->addMany($ids);
            $added = count($bitmap) - $before;
            $revision = (int) $row->revision;
            if ($added > 0) {
                ++$revision;
                $this->tagQuery($tenantId, $tag)->update([
                    'bitmap_base64' => base64_encode($bitmap->toPortableBytes()),
                    'revision' => $revision,
                    'updated_at' => Carbon::now(),
                ]);
            }

            return ['added' => $added, 'cardinality' => count($bitmap), 'revision' => $revision];
        }, 3);
    }

    /** Search the original documents directly with the saved bitmap; no toArray() is needed. */
    public function searchMembers(string $tenantId, string $tag, array $extraQuery = [], int $size = 20): array
    {
        if ($size < 0 || $size > 1000) {
            throw new InvalidArgumentException('This example supports a first page of 0..1000 hits.');
        }
        $saved = $this->savedString($tenantId, $tag);
        $filters = [
            ['term' => ['tenant_id' => $tenantId]],
            ['bitmap_terms' => ['field' => 'doc_num', 'value' => $saved]],
        ];
        if ($extraQuery !== []) {
            $filters[] = $extraQuery;
        }
        $response = $this->client->search([
            'index' => $this->index,
            'allow_partial_search_results' => false,
            'body' => [
                'size' => $size,
                'track_total_hits' => true,
                'query' => ['bool' => ['filter' => $filters]],
                'sort' => [['doc_num' => 'asc']],
            ],
        ])->asArray();
        self::requireCompleteSearch($response);

        return $response;
    }

    /** Optional expansion for consumers that require every ID; values remain decimal strings. */
    public function ids(string $tenantId, string $tag): array
    {
        return self::fromSavedString($this->savedString($tenantId, $tag), $this->backend)->toArray();
    }

    public function savedString(string $tenantId, string $tag): string
    {
        $row = $this->tagQuery($tenantId, $tag)->first();
        if ($row === null) {
            throw new RuntimeException('Unknown tag.');
        }

        return $row->bitmap_base64;
    }

    /** Read a Base64 value from Elasticsearch, SQL, a cache, or another compatible producer. */
    public static function fromSavedString(string $saved, Backend $backend = Backend::Auto): Bitmap64
    {
        $bytes = base64_decode($saved, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('The saved bitmap is not valid Base64.');
        }
        $bitmap = Bitmap64::fromPortableBytes($bytes, $backend);
        if ($bitmap->rank(PHP_INT_MAX) !== count($bitmap)) {
            throw new InvalidArgumentException('Elasticsearch long membership must fit 0..PHP_INT_MAX.');
        }

        return $bitmap;
    }

    private function insertNewTag(string $tenantId, string $tag, string $saved): int
    {
        // The unique (tenant_id, tag) constraint rejects an existing tag. An initial
        // aggregation must not overwrite newer additions made by a concurrent worker.
        return (int) DB::table('tag_bitmaps')->insertGetId([
            'tenant_id' => $tenantId,
            'tag' => $tag,
            'bitmap_base64' => $saved,
            'revision' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function tagQuery(string $tenantId, string $tag): Builder
    {
        return DB::table('tag_bitmaps')->where('tenant_id', $tenantId)->where('tag', $tag);
    }

    /** Validate before casting: values above PHP_INT_MAX must never be rounded or saturated. */
    private static function longIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id >= 0) {
                $normalized[] = $id;
                continue;
            }
            if (!is_string($id) || preg_match('/\A[0-9]+\z/D', $id) !== 1) {
                throw new InvalidArgumentException('Document IDs must be non-negative integers or decimal strings.');
            }
            $id = ltrim($id, '0');
            $id = $id === '' ? '0' : $id;
            $maximum = (string) PHP_INT_MAX;
            if (strlen($id) > strlen($maximum) || (strlen($id) === strlen($maximum) && strcmp($id, $maximum) > 0)) {
                throw new InvalidArgumentException('Document ID exceeds the Elasticsearch long range.');
            }
            $normalized[] = (int) $id;
        }

        return $normalized;
    }

    private static function requireCompleteSearch(array $response): void
    {
        if (($response['timed_out'] ?? false)
            || ($response['_shards']['failed'] ?? 0) > 0
            || ($response['_clusters']['partial'] ?? 0) > 0
            || ($response['_clusters']['failed'] ?? 0) > 0
            || ($response['_clusters']['skipped'] ?? 0) > 0) {
            throw new RuntimeException('Search did not complete; membership was not saved.');
        }
    }
}
