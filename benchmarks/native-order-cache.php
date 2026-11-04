<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap64;
use RoaringBitmap\Internal\NativeLibrary;
use RoaringBitmap\Internal\UInt64;

require dirname(__DIR__) . '/vendor/autoload.php';
$options = getopt('', ['output:']);
$output = $options['output'] ?? null;
if ($output === null || file_exists($output)) {
    throw new InvalidArgumentException('Provide --output=<new-json-file>.');
}
$library = getenv('ROARING_LIBRARY') ?: dirname(__DIR__) . '/.build/croaring/libroaring.' . (PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
$ffi = NativeLibrary::load($library, true);
if (!NativeLibrary::supportsOrderIndex($ffi)) {
    throw new RuntimeException('Rebuild with tools/build-native.sh: this probe requires the native order index.');
}
$results = ['php' => PHP_VERSION, 'php_binary' => PHP_BINARY,
    'measured_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format(DATE_ATOM),
    'library_sha256' => hash_file('sha256', $library), 'harness_sha256' => hash_file('sha256', __FILE__),
    'ini' => ['ffi.enable' => ini_get('ffi.enable'), 'opcache.enable_cli' => ini_get('opcache.enable_cli'),
        'opcache.jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit')],
    'method' => 'Five fresh bitmaps per size; the same midpoint rank query repeated; times include public decimal input conversion. Index bytes are requested C allocation size, excluding allocator bookkeeping, not process RSS.',
    'samples' => []];
foreach ([100000, 1000000] as $size) {
    $values = [];
    for ($i = 0; $i < $size; ++$i) {
        $values[] = UInt64::decimal(UInt64::fromParts(0x80000000 + intdiv($i, 32),
            ($i % 32) * 2048 + (crc32(pack('V', $i)) & 2047)));
    }
    $probe = $values[intdiv($size, 2)];
    for ($sample = 1; $sample <= 5; ++$sample) {
        $bitmap = new Bitmap64($values, Backend::Native, $library);
        $engine = (new ReflectionProperty($bitmap, 'engine'))->getValue($bitmap);
        $indexProperty = new ReflectionProperty($engine, 'orderIndex');
        $timings = [];
        for ($query = 1; $query <= 17; ++$query) {
            $start = hrtime(true);
            $rank = $bitmap->rank($probe);
            $timings[$query] = (hrtime(true) - $start) / 1e6;
            if ($rank !== intdiv($size, 2) + 1 || ($indexProperty->getValue($engine) !== null) !== ($query >= 16)) {
                throw new RuntimeException('Incorrect rank or cache activation.');
            }
        }
        $bytes = $ffi->php_roaring64_order_index_bytes($indexProperty->getValue($engine));
        $start = hrtime(true);
        $bitmap->remove($probe);
        $removeMs = (hrtime(true) - $start) / 1e6;
        if ($indexProperty->getValue($engine) !== null) {
            throw new RuntimeException('Index was not released before mutation.');
        }
        $start = hrtime(true);
        $rank = $bitmap->rank($probe);
        $afterRemoveMs = (hrtime(true) - $start) / 1e6;
        if ($rank !== intdiv($size, 2)) {
            throw new RuntimeException('Incorrect rank after removal.');
        }
        $results['samples'][] = ['size' => $size, 'sample' => $sample, 'rank_query_ms' => $timings,
            'index_allocation_bytes' => $bytes, 'remove_with_index_ms' => $removeMs, 'first_rank_after_remove_ms' => $afterRemoveMs];
        unset($bitmap, $engine);
    }
    unset($values);
}
file_put_contents($output, json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
echo "Cache lifecycle measurements: $output\n";
