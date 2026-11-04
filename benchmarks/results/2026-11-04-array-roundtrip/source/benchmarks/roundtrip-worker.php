<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;
use RoaringBitmap\Bitmap64;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['backend:', 'pipeline:', 'workload:', 'size:', 'cycles:']);
$backend = $options['backend'] ?? '';
$pipeline = $options['pipeline'] ?? '';
$workload = $options['workload'] ?? '';
$size = (int) ($options['size'] ?? 0);
$cycles = (int) ($options['cycles'] ?? 3);
if (!in_array($backend, ['list', 'php', 'native'], true)
    || !in_array($pipeline, ['array', 'base64'], true)
    || ($pipeline === 'base64' && $backend === 'list')
    || !in_array($workload, ['dense32', 'sparse32', 'dense64', 'sparse64'], true)
    || !in_array($size, [3, 10000, 100000, 1000000], true)
    || $cycles < 3 || $cycles > 10) {
    throw new InvalidArgumentException('Invalid round-trip benchmark arguments.');
}
$wide = str_ends_with($workload, '64');
$class = $wide ? Bitmap64::class : Bitmap::class;
$mode = $backend === 'native' ? Backend::Native : Backend::Php;

function idAt(string $workload, int $index): int
{
    return (str_ends_with($workload, '64') ? 1099511627776 : 0)
        + (str_starts_with($workload, 'dense') ? 2 * $index + 1
            : 2048 * $index + (crc32(pack('V', $index)) & 2047));
}

function inputIds(string $workload, int $size): Generator
{
    for ($i = 0; $i < $size; ++$i) {
        yield idAt($workload, $i);
    }
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("Round-trip verification failed: $label");
    }
}

function verify(array $ids, string $workload, int $size, bool $strings): void
{
    check(array_is_list($ids) && count($ids) === $size, 'list cardinality');
    foreach ($ids as $index => $id) {
        $expected = idAt($workload, $index);
        check($id === ($strings ? (string) $expected : $expected), 'every ID and its PHP type');
    }
}

// Tiny warmup loads codecs and FFI before measurements. No Auto fallback is used.
if ($backend !== 'list' || $pipeline === 'base64') {
    $warm = new $class([1, 3, 5], $mode);
    check($warm->backend()->value === $backend, 'explicit backend');
    $warm = $class::fromPortableBytes($warm->toPortableBytes(), $mode);
    check($warm->add(7), 'new ID');
    check(!$warm->add(7), 'bitmap duplicate semantics');
    check($warm->toArray() === ($wide ? ['1', '3', '5', '7'] : [1, 3, 5, 7]), 'example');
    unset($warm);
}

$newId = idAt($workload, $size);
$saved = null;
if ($pipeline === 'base64') {
    // Canonical fixture: both backends and update orders receive identical bytes.
    $fixture = new $class(inputIds($workload, $size), Backend::Php);
    $saved = base64_encode($fixture->toPortableBytes());
    unset($fixture);
}
$timer = [];
for ($i = 0; $i < 256; ++$i) {
    $start = hrtime(true);
    $timer[] = (hrtime(true) - $start) / 1000;
}
$samples = [];
for ($cycle = 0; $cycle < $cycles; ++$cycle) {
    if ($pipeline === 'array') {
        // Fresh owned array, with no alias: direct append must not incur an artificial COW copy.
        $ids = iterator_to_array(inputIds($workload, $size), false);
        if ($backend === 'list') {
            $start = hrtime(true);
            $ids[] = $newId;
            $finish = hrtime(true);
            $import = $start;
            $added = $finish;
        } else {
            $start = hrtime(true);
            $bitmap = new $class($ids, $mode);
            $import = hrtime(true);
            $bitmap->add($newId);
            $added = hrtime(true);
            $ids = $bitmap->toArray();
            $finish = hrtime(true);
        }
        $totalMs = ($finish - $start) / 1e6;
        $row = [
            'method' => $backend === 'list' ? 'append' : 'bitmap_roundtrip', 'cycle' => $cycle + 1,
            'import_ms' => ($import - $start) / 1e6, 'add_us' => ($added - $import) / 1000,
            'export_ms' => ($finish - $added) / 1e6, 'total_ms' => $totalMs,
            'output_type' => $wide && $backend !== 'list' ? 'decimal-string' : 'int',
        ];
        // Measure optional conversion before validation can warm the output array.
        if ($wide && $backend !== 'list') {
            $start = hrtime(true);
            $integers = array_map('intval', $ids); // All fixture IDs fit PHP_INT_MAX.
            $row['normalize_int_ms'] = (hrtime(true) - $start) / 1e6;
            verify($integers, $workload, $size + 1, false);
            unset($integers);
        } else {
            $row['normalize_int_ms'] = 0.0;
        }
        $row['integer_output_total_ms'] = $totalMs + $row['normalize_int_ms'];
        verify($ids, $workload, $size + 1, $wide && $backend !== 'list');
        $samples[] = $row;
        unset($ids, $bitmap);
    } else {
        // Alternate order across cycles; every trial restores the original saved value.
        $methods = $cycle % 2 === 0 ? ['bitmap_first', 'array_first'] : ['array_first', 'bitmap_first'];
        foreach ($methods as $method) {
            $appendId = $wide ? (string) $newId : $newId;
            $start = hrtime(true);
            $bytes = base64_decode($saved, true);
            $decoded = hrtime(true);
            $bitmap = $class::fromPortableBytes($bytes, $mode);
            $restored = hrtime(true);
            if ($method === 'bitmap_first') {
                $bitmap->add($newId);
                $mutated = hrtime(true);
                $ids = $bitmap->toArray();
                $finish = hrtime(true);
                $addUs = ($mutated - $restored) / 1000;
                $exportMs = ($finish - $mutated) / 1e6;
            } else {
                $ids = $bitmap->toArray();
                $exported = hrtime(true);
                $ids[] = $appendId;
                $finish = hrtime(true);
                $addUs = ($finish - $exported) / 1000;
                $exportMs = ($exported - $restored) / 1e6;
            }
            $samples[] = [
                'method' => $method, 'cycle' => $cycle + 1,
                'base64_ms' => ($decoded - $start) / 1e6,
                'restore_ms' => ($restored - $decoded) / 1e6,
                'add_us' => $addUs, 'export_ms' => $exportMs, 'total_ms' => ($finish - $start) / 1e6,
                'output_type' => $wide ? 'decimal-string' : 'int',
            ];
            verify($ids, $workload, $size + 1, $wide);
            check(count($bitmap) === $size + (int) ($method === 'bitmap_first'), 'mutation location');
            unset($ids, $bitmap, $bytes);
        }
    }
}

echo json_encode([
    'pipeline' => $pipeline, 'backend' => $backend, 'workload' => $workload, 'size' => $size,
    'class' => $class, 'cycles' => $cycles, 'samples' => $samples, 'timer_samples_us' => $timer,
    'input_type' => $pipeline === 'array' ? 'list<int>' : 'Base64(Roaring portable)',
    'fixture_base64_sha256' => $saved === null ? null : hash('sha256', $saved),
    'fixture_base64_bytes' => $saved === null ? null : strlen($saved),
    'verified' => true,
    'runtime' => [
        'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'os' => PHP_OS_FAMILY, 'int_bytes' => PHP_INT_SIZE,
        'ffi.enable' => ini_get('ffi.enable'), 'opcache.enable_cli' => ini_get('opcache.enable_cli'),
        'opcache.jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
        'xdebug' => extension_loaded('xdebug'),
    ],
], JSON_THROW_ON_ERROR) . PHP_EOL;
