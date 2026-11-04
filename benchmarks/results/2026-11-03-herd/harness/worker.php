<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;

require dirname(__DIR__) . '/vendor/autoload.php';

// One fresh process per backend, workload, size, and sample. JSON only on stdout.
$options = getopt('', ['backend:', 'workload:', 'size:', 'target-ms:', 'verify:']);
$backend = $options['backend'] ?? '';
$workload = $options['workload'] ?? '';
$size = (int) ($options['size'] ?? 0);
$targetMs = (float) ($options['target-ms'] ?? 25);
$verify = ($options['verify'] ?? '1') === '1';
if (!in_array($backend, ['array', 'php', 'native'], true)
    || !in_array($workload, ['dense', 'shuffled', 'sparse', 'ranges'], true)
    || !in_array($size, [10000, 100000, 1000000], true)
    || $targetMs < 1 || $targetMs > 1000) {
    throw new InvalidArgumentException('Invalid benchmark arguments.');
}

function idAt(string $workload, int $index): int
{
    return match ($workload) {
        'dense', 'shuffled' => 3 * $index,
        'sparse' => 2048 * $index + ($index % 31),
        'ranges' => $index,
    };
}

/** A and B contain N unique IDs each; exactly half their IDs overlap. */
function inputValues(string $workload, int $size, int $offset): Generator
{
    for ($i = 0; $i < $size; ++$i) {
        // 48271 is coprime to every supported size; this is a deterministic permutation.
        $index = $workload === 'shuffled' ? ($i * 48271) % $size : $i;
        yield idAt($workload, $index + $offset);
    }
}

function buildSet(string $backend, string $workload, int $size, int $offset): array|Bitmap
{
    if ($backend === 'array') {
        $set = [];
        foreach (inputValues($workload, $size, $offset) as $id) {
            $set[$id] = true;
        }

        return $set;
    }
    $engine = $backend === 'php' ? Backend::Php : Backend::Native;
    if ($workload === 'ranges') {
        $set = new Bitmap(backend: $engine);
        $set->addRange($offset, $offset + $size);

        return $set;
    }

    return new Bitmap(inputValues($workload, $size, $offset), $engine);
}

function sumSet(array|Bitmap $set): int
{
    $sum = 0;
    if (is_array($set)) {
        foreach ($set as $id => $_) {
            $sum += $id;
        }
    } else {
        foreach ($set as $id) {
            $sum += $id;
        }
    }

    return $sum;
}

/** Closed-form sum of the first N mapped IDs, independent of the bitmap algorithms. */
function prefixSum(string $workload, int $count): int
{
    $triangle = intdiv($count * ($count - 1), 2);
    if ($workload === 'sparse') {
        $remainder = $count % 31;

        return 2048 * $triangle + intdiv($count, 31) * 465 + intdiv($remainder * ($remainder - 1), 2);
    }

    return $triangle * ($workload === 'ranges' ? 1 : 3);
}

function requireSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Benchmark correctness check failed: $label; expected $expected, got $actual");
    }
}

/** Warm once, calibrate, then time a batch; result destruction is included. */
function measure(Closure $operation, float $targetMs): array
{
    $result = $operation();
    unset($result);
    $start = hrtime(true);
    $result = $operation();
    unset($result);
    $calibrationNs = max(1, hrtime(true) - $start);
    $iterations = max(1, min(100000, (int) ceil($targetMs * 1e6 / $calibrationNs)));
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $result = $operation();
        unset($result);
    }
    $elapsedNs = hrtime(true) - $start;

    return ['ms' => $elapsedNs / 1e6 / $iterations, 'iterations' => $iterations, 'batch_ms' => $elapsedNs / 1e6];
}

// Prime autoloading and native library resolution outside construction measurements.
$start = hrtime(true);
$warmup = buildSet($backend, 'dense', 8, 0);
if ($warmup instanceof Bitmap) {
    requireSame($backend, $warmup->backend()->value, 'explicit backend');
}
unset($warmup);
$initializationMs = (hrtime(true) - $start) / 1e6;
gc_collect_cycles();
$heapBefore = memory_get_usage(false);
$rssBefore = getrusage()['ru_maxrss'];
$start = hrtime(true);
$a = buildSet($backend, $workload, $size, 0);
$b = buildSet($backend, $workload, $size, intdiv($size, 2));
$buildMs = (hrtime(true) - $start) / 1e6;
$heapAfter = memory_get_usage(false);
$rssAfter = getrusage()['ru_maxrss'];
// macOS reports bytes; Linux reports KiB. Keep the raw value and platform in the output.
$rssScale = PHP_OS_FAMILY === 'Darwin' ? 1 : 1024;
requireSame($size, count($a), 'A cardinality');
requireSame($size, count($b), 'B cardinality');

$operations = is_array($a) ? [
    'intersection' => static fn () => array_intersect_key($a, $b),
    'union' => static fn () => $a + $b,
    'difference' => static fn () => array_diff_key($a, $b),
] : [
    'intersection' => static fn () => $a->intersect($b),
    'union' => static fn () => $a->union($b),
    'difference' => static fn () => $a->difference($b),
];
$counts = ['intersection' => intdiv($size, 2), 'union' => $size + intdiv($size, 2), 'difference' => intdiv($size, 2)];
$sums = [
    'intersection' => prefixSum($workload, $size) - prefixSum($workload, intdiv($size, 2)),
    'union' => prefixSum($workload, $size + intdiv($size, 2)),
    'difference' => prefixSum($workload, intdiv($size, 2)),
];
foreach ($operations as $name => $operation) {
    $result = $operation();
    requireSame($counts[$name], count($result), "$name cardinality");
    if ($verify) {
        requireSame($sums[$name], sumSet($result), "$name checksum");
    }
    unset($result);
}

$probes = [];
$maximum = idAt($workload, $size - 1);
for ($i = 0; $i < 10000; ++$i) {
    $probes[] = $i % 2 === 0 ? idAt($workload, ($i * 97) % $size) : $maximum + 1000 + $i;
}
$operations['contains_10000'] = is_array($a)
    ? static function () use ($a, $probes): int {
        $hits = 0;
        foreach ($probes as $id) {
            $hits += (int) isset($a[$id]);
        }

        return $hits;
    }
    : static function () use ($a, $probes): int {
        $hits = 0;
        foreach ($probes as $id) {
            $hits += (int) $a->contains($id);
        }

        return $hits;
    };
requireSame(5000, $operations['contains_10000'](), '50 percent membership hits');
$operations['iterate_all'] = static fn () => sumSet($a);
requireSame(prefixSum($workload, $size), $operations['iterate_all'](), 'iteration checksum');
$operations['serialize'] = is_array($a) ? static fn () => serialize($a) : static fn () => $a->toPortableBytes();
$bytes = $operations['serialize']();
$operations['deserialize'] = is_array($a)
    ? static fn () => unserialize($bytes, ['allowed_classes' => false])
    : static fn () => Bitmap::fromPortableBytes($bytes, $backend === 'php' ? Backend::Php : Backend::Native);
$decoded = $operations['deserialize']();
requireSame($size, count($decoded), 'deserialized cardinality');
if ($verify) {
    requireSame(prefixSum($workload, $size), sumSet($decoded), 'deserialized checksum');
}
unset($decoded);

$timings = [];
foreach ($operations as $name => $operation) {
    $timings[$name] = measure($operation, $targetMs);
}
echo json_encode([
    'backend' => $backend,
    'workload' => $workload,
    'size' => $size,
    'initialization_ms' => $initializationMs,
    'build_pair_ms' => $buildMs,
    'build_peak_rss_bytes' => $rssAfter * $rssScale,
    'initial_peak_rss_bytes' => $rssBefore * $rssScale,
    'build_php_heap_delta_bytes' => $heapAfter - $heapBefore,
    'serialized_bytes' => strlen($bytes),
    'serialization_format' => $backend === 'array' ? 'PHP serialize(integer-keyed set)' : 'Roaring 32-bit portable',
    'timings' => $timings,
    'verified_checksums' => $verify,
    'runtime' => [
        'php' => PHP_VERSION,
        'binary' => PHP_BINARY,
        'os_family' => PHP_OS_FAMILY,
        'int_bytes' => PHP_INT_SIZE,
        'ffi.enable' => ini_get('ffi.enable'),
        'opcache.enable_cli' => ini_get('opcache.enable_cli'),
        'opcache.jit' => ini_get('opcache.jit'),
        'memory_limit' => ini_get('memory_limit'),
        'xdebug' => extension_loaded('xdebug'),
    ],
], JSON_THROW_ON_ERROR) . PHP_EOL;
