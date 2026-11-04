<?php

declare(strict_types=1);

// Complement the standard suite with partial overlap inside every container.
$options = getopt('', ['source-dir:', 'samples:', 'output:']);
$source = realpath($options['source-dir'] ?? dirname(__DIR__) . '/src');
$samples = (int) ($options['samples'] ?? 3);
$destination = $options['output'] ?? null;
if ($source === false || !is_file($source . '/Bitmap.php') || $samples < 3 || $samples > 20
    || ($destination !== null && file_exists($destination))) {
    throw new InvalidArgumentException('Use a package src directory, 3..20 samples, and a new output file.');
}
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'RoaringBitmap\\';
    if (str_starts_with($class, $prefix)) {
        require $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;

function partialValues(int $width, int $step): Generator
{
    for ($key = 0; $key < intdiv(1048576, $width); ++$key) {
        $offset = crc32(pack('V', $key)) & 0xffff;
        for ($slot = 0; $slot < $width; ++$slot) {
            yield ($key << 16) | (($slot * $step + $offset) & 0xffff);
        }
    }
}

function partialSet(string $backend, int $width, int $step): array|Bitmap
{
    if ($backend !== 'array') {
        return new Bitmap(partialValues($width, $step), Backend::from($backend));
    }
    $set = [];
    foreach (partialValues($width, $step) as $value) {
        $set[$value] = true;
    }

    return $set;
}

$hashes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $hashes[substr($file->getPathname(), strlen($source) + 1)] = hash_file('sha256', $file->getPathname());
    }
}
ksort($hashes);
$library = getenv('ROARING_LIBRARY') ?: null;
$report = [
    'php' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'os' => php_uname(),
    'started_at' => date(DATE_ATOM), 'source_directory' => $source, 'source_sha256' => $hashes,
    'harness_sha256' => hash_file('sha256', __FILE__), 'command' => $argv,
    'library_sha256' => $library && is_file($library) ? hash_file('sha256', $library) : null,
    'ini' => ['ffi.enable' => ini_get('ffi.enable'), 'opcache.enable_cli' => ini_get('opcache.enable_cli'),
        'opcache.jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit')],
    'ids_per_input' => 1048576, 'repetitions' => $samples,
    'method' => 'Sequential warmed intersections in one process; inputs built outside timers; result allocation and destruction included. Every container partially overlaps; none has identical contents. A deterministic CRC-based rotation of low bits avoids concentrating array keys in a few hash buckets. No construction/RSS claim.',
    'samples' => [], 'summary' => [],
];
foreach ([32, 256, 4096] as $width) {
    foreach (['array', 'php', 'native'] as $backend) {
        $a = partialSet($backend, $width, 2);
        $b = partialSet($backend, $width, 3);
        $operation = $backend === 'array' ? static fn () => array_intersect_key($a, $b) : static fn () => $a->intersect($b);
        $result = $operation();
        $expected = intdiv(1048576, $width) * (intdiv($width - 1, 3) + 1);
        if (count($a) !== 1048576 || count($b) !== 1048576 || count($result) !== $expected) {
            throw new RuntimeException('Partial overlap cardinality differs.');
        }
        foreach ($result as $key => $value) {
            $id = $backend === 'array' ? $key : $value;
            $offset = crc32(pack('V', $id >> 16)) & 0xffff;
            $low = ($id - $offset) & 0xffff;
            if ($id < 0 || ($id >> 16) >= intdiv(1048576, $width) || $low % 6 !== 0 || $low > 2 * ($width - 1)) {
                throw new RuntimeException('Partial overlap membership differs.');
            }
        }
        unset($result);
        $times = [];
        for ($sample = 1; $sample <= $samples; ++$sample) {
            $start = hrtime(true);
            $result = $operation();
            unset($result);
            $ms = (hrtime(true) - $start) / 1e6;
            $times[] = $ms;
            $report['samples'][] = ['backend' => $backend, 'container_cardinality' => $width, 'sample' => $sample, 'intersection_ms' => $ms];
        }
        sort($times, SORT_NUMERIC);
        $middle = intdiv($samples, 2);
        $report['summary'][] = ['backend' => $backend, 'container_cardinality' => $width,
            'intersection_ms' => ['median' => $samples % 2 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2,
                'min' => $times[0], 'max' => $times[$samples - 1]]];
        unset($operation, $a, $b);
        gc_collect_cycles();
    }
}
$report['finished_at'] = date(DATE_ATOM);
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
if ($destination === null) {
    echo $json;
} else {
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0777, true);
    }
    file_put_contents($destination, $json);
    echo "Partial sparse intersection results: $destination\n";
}
