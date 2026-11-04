<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;
use RoaringBitmap\Exception\NativeBackendUnavailable;

require dirname(__DIR__) . '/vendor/autoload.php';

$size = isset($argv[1]) ? (int) $argv[1] : 100000;
if ($size < 1 || $size > 1000000) {
    fwrite(STDERR, "Usage: php benchmarks/compare.php [size: 1..1000000]\n");
    exit(1);
}
$values = range(0, ($size - 1) * 3, 3);
$others = range(0, ($size - 1) * 5, 5);
printf("PHP %s; %d IDs per bitmap; wall-clock milliseconds (one sample).\n", PHP_VERSION, $size);
printf("%-10s %14s %18s %16s\n", 'Backend', 'Build two', '100 intersections', 'Portable bytes');
foreach ([Backend::Php, Backend::Native] as $backend) {
    try {
        // Warm up loading outside the timed section.
        new Bitmap(backend: $backend);
        $start = hrtime(true);
        $a = new Bitmap($values, $backend);
        $b = new Bitmap($others, $backend);
        $buildMs = (hrtime(true) - $start) / 1e6;
        $start = hrtime(true);
        for ($i = 0; $i < 100; ++$i) {
            $intersection = $a->intersect($b);
        }
        $intersectMs = (hrtime(true) - $start) / 1e6;
        $expectedCount = intdiv(($size - 1) * 3, 15) + 1;
        if (count($intersection) !== $expectedCount) {
            throw new RuntimeException('Intersection cardinality check failed.');
        }
        printf("%-10s %14.3f %18.3f %16d\n", $backend->value, $buildMs, $intersectMs, strlen($a->toPortableBytes()));
        unset($a, $b, $intersection);
    } catch (NativeBackendUnavailable $error) {
        echo "native     unavailable; set ROARING_LIBRARY to enable comparison.\n";
    }
}
echo "Native allocations are not measured by PHP memory_get_usage(); no memory comparison is reported.\n";
