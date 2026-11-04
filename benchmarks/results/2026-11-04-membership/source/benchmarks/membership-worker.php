<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;
use RoaringBitmap\Bitmap64;

require dirname(__DIR__) . '/vendor/autoload.php';

// One process per configuration. Use only public package APIs; stdout is JSON.
$options = getopt('', ['backend:', 'workload:', 'size:', 'probes:', 'cycles:']);
$backend = $options['backend'] ?? '';
$workload = $options['workload'] ?? '';
$size = (int) ($options['size'] ?? 0);
$probes = (int) ($options['probes'] ?? 256);
$cycles = (int) ($options['cycles'] ?? 3);
if (!in_array($backend, ['list', 'array', 'php', 'native'], true)
    || !in_array($workload, ['dense32', 'sparse32', 'dense64', 'sparse64'], true)
    || !in_array($size, [3, 10000, 100000, 1000000], true)
    || $probes < 16 || $probes > 4096 || $cycles < 3 || $cycles > 10) {
    throw new InvalidArgumentException('Invalid membership benchmark arguments.');
}
$class = str_ends_with($workload, '64') ? Bitmap64::class : Bitmap::class;
$mode = $backend === 'native' ? Backend::Native : Backend::Php;

function idAt(string $workload, int $index): int
{
    $base = str_ends_with($workload, '64') ? 1099511627776 : 0; // 2^40, safely above uint32.

    return $base + (str_starts_with($workload, 'dense')
        ? 2 * $index + 1
        : 2048 * $index + (crc32(pack('V', $index)) & 2047));
}

function inputIds(string $workload, int $size): Generator
{
    for ($i = 0; $i < $size; ++$i) {
        yield idAt($workload, $i);
    }
}

function build(string $backend, string $class, Backend $mode, string $workload, int $size): array|Bitmap|Bitmap64
{
    if ($backend === 'list' || $backend === 'array') {
        $set = [];
        foreach (inputIds($workload, $size) as $id) {
            if ($backend === 'list') {
                $set[] = $id;
            } else {
                $set[$id] = true;
            }
        }

        return $set;
    }

    return new $class(inputIds($workload, $size), $mode);
}

// Arrays are passed by reference: taking a copy would introduce an O(N) COW cost.
function insert(array|Bitmap|Bitmap64 &$set, string $backend, int $id): void
{
    if ($backend === 'list') {
        $set[] = $id;
    } elseif ($backend === 'array') {
        $set[$id] = true;
    } else {
        $set->add($id);
    }
}

function undo(array|Bitmap|Bitmap64 &$set, string $backend, int $id): void
{
    if ($backend === 'list') {
        array_pop($set);
    } elseif ($backend === 'array') {
        unset($set[$id]);
    } else {
        $set->remove($id);
    }
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("Membership benchmark verification failed: $label");
    }
}

function has(array|Bitmap|Bitmap64 $set, string $backend, int $id): bool
{
    return match ($backend) {
        'list' => in_array($id, $set, true),
        'array' => isset($set[$id]),
        default => $set->contains($id),
    };
}

/** Compare every ID, in order, against the independently generated fixture. */
function verifyAll(array|Bitmap|Bitmap64 $set, string $backend, string $workload, int $size): void
{
    check(count($set) === $size, 'exact cardinality');
    $index = 0;
    foreach ($set as $key => $value) {
        $id = $backend === 'array' ? $key : $value;
        check((string) $id === (string) idAt($workload, $index++), 'exact membership');
    }
    check($index === $size, 'exact iteration length');
}

function encodeSet(array|Bitmap|Bitmap64 $set): string
{
    return base64_encode(is_array($set) ? serialize($set) : $set->toPortableBytes());
}

function decodeSet(string $saved, string $backend, string $class, Backend $mode): array|Bitmap|Bitmap64
{
    $bytes = base64_decode($saved, true);
    check($bytes !== false, 'valid Base64');

    return $backend === 'list' || $backend === 'array'
        ? unserialize($bytes, ['allowed_classes' => false])
        : $class::fromPortableBytes($bytes, $mode);
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $n = count($values);

    return ($values[intdiv($n - 1, 2)] + $values[intdiv($n, 2)]) / 2;
}

// Prime autoload/FFI with a tiny set; never warm a full-size bitmap's lazy caches.
$warm = build($backend, $class, $mode, $workload, 3);
if (!is_array($warm)) {
    check($warm->backend()->value === $backend, 'explicit backend');
}
$warm = decodeSet(encodeSet($warm), $backend, $class, $mode);
unset($warm);
gc_collect_cycles();
$heap = memory_get_usage(false);
$start = hrtime(true);
$set = build($backend, $class, $mode, $workload, $size);
$buildMs = (hrtime(true) - $start) / 1e6;
$heapDelta = memory_get_usage(false) - $heap;
$buildRss = getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);

// The first add includes any lazy cardinality work: do not call count/verify first.
$newId = idAt($workload, $size);
$start = hrtime(true);
insert($set, $backend, $newId);
$firstAddUs = (hrtime(true) - $start) / 1000;
check(count($set) === $size + 1 && has($set, $backend, $newId), 'first addition');
insert($set, $backend, $newId);
check(count($set) === $size + ($backend === 'list' ? 2 : 1), 'duplicate semantics');
if ($backend === 'list') {
    undo($set, $backend, $newId);
}
undo($set, $backend, $newId);
verifyAll($set, $backend, $workload, $size);

$raw = ['timer_us' => [], 'add_us' => [], 'insert_gap_us' => [], 'duplicate_us' => []];
foreach ($raw as $operation => $_) {
    for ($i = 0; $i < $probes; ++$i) {
        $existing = idAt($workload, ($i * 48271) % $size);
        $id = match ($operation) {
            'add_us' => idAt($workload, $size + $i),
            'insert_gap_us' => $existing ^ 1, // A free ID in the same bucket as an existing member.
            default => $existing,
        };
        if ($operation === 'timer_us') {
            $start = hrtime(true);
            $elapsed = hrtime(true) - $start;
        } else {
            $start = hrtime(true);
            insert($set, $backend, $id);
            $elapsed = hrtime(true) - $start;
        }
        $raw[$operation][] = $elapsed / 1000;
        // Checks and reversal are outside the timer. Base cardinality stays N.
        if ($operation !== 'timer_us') {
            $changed = $operation !== 'duplicate_us' || $backend === 'list';
            check(count($set) === $size + (int) $changed && has($set, $backend, $id), $operation);
            if ($changed) {
                undo($set, $backend, $id);
            }
            check(count($set) === $size, 'probe restored base cardinality');
        }
    }
}
verifyAll($set, $backend, $workload, $size);
$start = hrtime(true);
$saved = encodeSet($set);
$initialSaveMs = (hrtime(true) - $start) / 1e6;
$savedBytes = strlen($saved);
unset($set); // Only the saved value remains between requests; avoid a second resident set.

$persistence = [];
foreach ([1, 100] as $batchSize) {
    $ids = iterator_to_array((static function () use ($workload, $size, $batchSize): Generator {
        for ($i = 0; $i < $batchSize; ++$i) {
            yield idAt($workload, $size + $i);
        }
    })(), false);
    for ($cycle = 0; $cycle < $cycles; ++$cycle) {
        $start = hrtime(true);
        $restored = decodeSet($saved, $backend, $class, $mode);
        $loaded = hrtime(true);
        foreach ($ids as $id) {
            insert($restored, $backend, $id);
        }
        $added = hrtime(true);
        $published = encodeSet($restored);
        $finished = hrtime(true);
        $persistence[] = [
            'new_ids' => $batchSize, 'cycle' => $cycle + 1,
            'load_ms' => ($loaded - $start) / 1e6,
            'add_ms' => ($added - $loaded) / 1e6,
            'save_ms' => ($finished - $added) / 1e6,
            'total_ms' => ($finished - $start) / 1e6,
            'published_base64_bytes' => strlen($published),
        ];
        check(count($restored) === $size + $batchSize, 'restored and updated cardinality');
        foreach ($ids as $id) {
            check(has($restored, $backend, $id), 'all new members');
        }
        unset($restored);
        $roundTrip = decodeSet($published, $backend, $class, $mode);
        check(count($roundTrip) === $size + $batchSize, 'published cardinality');
        if ($cycle === 0) {
            verifyAll($roundTrip, $backend, $workload, $size + $batchSize);
        }
        unset($roundTrip, $published);
    }
}

$metrics = [
    'build_ms' => $buildMs, 'first_add_us' => $firstAddUs,
    'initial_save_ms' => $initialSaveMs, 'saved_base64_bytes' => $savedBytes,
    'build_peak_rss_bytes' => $buildRss, 'build_php_heap_delta_bytes' => $heapDelta,
];
foreach ($raw as $name => $values) {
    $metrics[$name] = median($values);
}
foreach ([1, 100] as $batchSize) {
    $group = array_values(array_filter($persistence, static fn (array $row): bool => $row['new_ids'] === $batchSize));
    foreach (['load_ms', 'add_ms', 'save_ms', 'total_ms'] as $name) {
        $metrics["persist_{$batchSize}_$name"] = median(array_column($group, $name));
    }
}
echo json_encode([
    'backend' => $backend, 'workload' => $workload, 'size' => $size,
    'class' => $class, 'probes' => $probes, 'cycles' => $cycles,
    'metrics' => $metrics, 'resident_samples_us' => $raw, 'persistence_samples' => $persistence,
    'verified' => true, 'deduplicates' => $backend !== 'list',
    'serialization' => $backend === 'list' || $backend === 'array' ? 'Base64(PHP serialize)' : 'Base64(Roaring portable)',
    'runtime' => [
        'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'os' => PHP_OS_FAMILY, 'int_bytes' => PHP_INT_SIZE,
        'ffi.enable' => ini_get('ffi.enable'), 'opcache.enable_cli' => ini_get('opcache.enable_cli'),
        'opcache.jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
        'xdebug' => extension_loaded('xdebug'),
    ],
], JSON_THROW_ON_ERROR) . PHP_EOL;
