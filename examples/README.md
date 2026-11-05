# Examples

- [basic.php](basic.php): set operations and binary persistence.
- [advanced.php](advanced.php): arrays, saved Base64 strings, batch additions, both bitmap widths, and rank/select. Run `php examples/advanced.php --backend=php`; select `--backend=native` with FFI and the native library configured, or omit the option for automatic selection.
- [elasticsearch.php](elasticsearch.php): a Laravel service that collects a search selection as a bitmap, stores it in SQL, adds matching IDs later, and searches with the updated bitmap.

## Laravel and Elasticsearch

This example targets deployments exposing the documented [`roaring_bitmap` aggregation](https://www.elastic.co/docs/reference/aggregations/search-aggregations-metrics-roaring-bitmap-aggregation) and [`bitmap_terms` query](https://www.elastic.co/docs/reference/query-languages/query-dsl/query-dsl-bitmap-terms-query), such as the Serverless / Elasticsearch 9.6 APIs. Check availability in your deployment. The PHP package supplies the bitmap implementation; the server supplies these search APIs.

Use a `long` field named `doc_num` and `Bitmap64`. Elasticsearch's bitmap APIs accept non-negative IDs through `2^63 - 1` for a `long` field. The PHP library also supports larger unsigned IDs, but this service rejects them because they do not fit the chosen Elasticsearch field. An Elasticsearch `integer` field requires `Bitmap` and portable 32-bit bytes instead. [Format and range requirements](https://www.elastic.co/docs/reference/query-languages/query-dsl/query-dsl-bitmap-terms-query#bitmap-format).

### Application setup

Install this package using the [package installation instructions](../README.md#installation), and install the official client in your **Laravel application**:

```sh
composer require elasticsearch/elasticsearch:^9.0
```

Copy `examples/elasticsearch.php` to `app/Services/ElasticsearchBitmapMembership.php`. Its namespace is already `App\Services`. Loading the file performs no requests or writes; application code calls its methods explicitly.

Add connection settings to `config/services.php`:

```php
'elasticsearch' => [
    'url' => env('ELASTICSEARCH_URL'),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'index' => env('ELASTICSEARCH_INDEX', 'originals-read'),
],
```

Add these imports to `App\Providers\AppServiceProvider`, then register the bindings in its `register()` method:

```php
use App\Services\ElasticsearchBitmapMembership;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use RoaringBitmap\Backend;
```

```php
public function register(): void
{
    $this->app->singleton(Client::class, fn () => ClientBuilder::create()
        ->setHosts([config('services.elasticsearch.url')])
        ->setApiKey(config('services.elasticsearch.api_key'))
        ->build());

    $this->app->singleton(ElasticsearchBitmapMembership::class, fn ($app) =>
        new ElasticsearchBitmapMembership(
            client: $app->make(Client::class),
            backend: Backend::Auto, // Php forces PHP; Native requires FFI + CRoaring.
            index: config('services.elasticsearch.index'),
        ));
}
```

Keep the endpoint/API key in your application's environment configuration. The example uses the official client's [connection API](https://www.elastic.co/docs/reference/elasticsearch/clients/php/connecting); use [native setup](../docs/native-backend.md) if selecting `Backend::Native`, including enabling FFI in the queue-worker/PHP-FPM runtime.

Create a migration for the saved membership. Put the following schema in its `up()` method, with the imports at the top of the migration file. Use a database with row locks, such as PostgreSQL or MySQL/InnoDB, for concurrent workers:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('tag_bitmaps', function (Blueprint $table): void {
    $table->id();
    $table->string('tenant_id', 64);
    $table->string('tag', 128);
    $table->longText('bitmap_base64');
    $table->unsignedBigInteger('revision')->default(1);
    $table->timestamps();
    $table->unique(['tenant_id', 'tag']);
});
```

The service reads the Base64 string inside a [transaction with `lockForUpdate()`](https://laravel.com/docs/13.x/queries#pessimistic-locking) before changing it. All membership writers must use the same locking protocol. Each batch is saved once. Duplicate-only batches leave the revision unchanged; concurrent additions cannot replace each other with stale snapshots. Creating a tag uses an insert, so it cannot overwrite an existing tag's newer revision.

Provision an index with the following mapping, and point the `originals-read` alias at it. This setup is separate from request handling; do not recreate an existing production index:

```php
$client->indices()->create([
    'index' => 'originals-demo-v1',
    'body' => [
        'mappings' => ['properties' => [
            'tenant_id' => ['type' => 'keyword'],
            'doc_num' => ['type' => 'long'],
            'priority' => ['type' => 'boolean'],
            'title' => ['type' => 'text'],
        ]],
        'aliases' => ['originals-read' => new \stdClass()],
    ],
]);
```

For a new membership-oriented index, `index_terms: true` is an optional mapping optimization; the bitmap APIs also work on the default numeric index. See Elastic's [mapping tradeoffs](https://www.elastic.co/docs/reference/elasticsearch/mapping-reference/number#index-terms-mapping-parameter) before choosing it.

### Collect and save a selection

From an authorized controller or application service, derive the tenant from the authenticated context. Pass a server-defined selection query:

```php
use App\Services\ElasticsearchBitmapMembership;

$memberships = app(ElasticsearchBitmapMembership::class);
$tenantId = (string) auth()->user()->tenant_id;

$memberships->createFromSearch($tenantId, 'priority', [
    'terms' => ['doc_num' => [1, 3, 5]],
]);

// Alternatively, when the application already has the selected IDs:
$memberships->createFromIds($tenantId, 'manual-selection', [1, 3, 5]);
```

`createFromSearch()` requests `aggregations.members.value`, validates its Base64/portable 64-bit format, and saves the string in `tag_bitmaps.bitmap_base64`. The query always includes the tenant filter. Failed or timed-out searches are rejected; a `null` aggregation value indicates a mapping problem. A mapped field with no matching IDs produces a valid empty bitmap.

### Add later matches and search

After indexing an incoming document and accepting its membership event, a Laravel queue job can call the service:

```php
// The tenant and stable document ID come from a validated event.
// Validate document/rule versions before applying membership events.
$result = $memberships->addMembers($tenantId, 'priority', [7]);
// ['added' => 1, 'cardinality' => 4, 'revision' => 2]

$retry = $memberships->addMembers($tenantId, 'priority', [7]);
// ['added' => 0, 'cardinality' => 4, 'revision' => 2]

$memberships->addMembers($tenantId, 'priority', [9, 11, 11]); // Deduplicate and save once.

$response = $memberships->searchMembers($tenantId, 'priority');
$documents = $response['hits']['hits'];
$total = $response['hits']['total']['value'];

$ids = $memberships->ids($tenantId, 'priority'); // Optional: ['1','3','5','7','9','11'].
$savedString = $memberships->savedString($tenantId, 'priority'); // Base64 ready for storage/API use.
```

`searchMembers()` supplies the saved string directly as `bitmap_terms.value` and adds the tenant filter. It does not expand the bitmap to an array. Adding membership does not create an Elasticsearch document: index that document separately, and account for Elasticsearch refresh visibility before expecting it in search. The example returns a first page; use the appropriate pagination strategy for further hits. Initialize the tag before processing its queued additions. Rebuilds, removals, revision conflict policies, and chunk partitioning belong in the application's event workflow.

### Read a saved string directly

Elastic's documented portable 64-bit example represents IDs 1, 3 and 5:

```php
use App\Services\ElasticsearchBitmapMembership;
use RoaringBitmap\Backend;

$savedString = 'AQAAAAAAAAAAAAAAOjAAAAEAAAAAAAIAEAAAAAEAAwAFAA==';
$bitmap = ElasticsearchBitmapMembership::fromSavedString($savedString, Backend::Php);
$bitmap->add(7);

$ids = $bitmap->toArray(); // ['1', '3', '5', '7']
$updatedString = base64_encode($bitmap->toPortableBytes());
```

Use `Backend::Native` with a configured native library for the same workflow. Base64 decoding is strict; portable parsing validates the bytes. An invalid saved value raises an exception rather than silently replacing membership with an empty set. `Bitmap64` returns decimal strings so IDs remain exact; avoid converting large IDs through floating point.
