<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;
use RoaringBitmap\Exception\NativeBackendUnavailable;

require dirname(__DIR__) . '/vendor/autoload.php';

// A dependency-free runner: exceptions fail tests even with zend.assertions=-1.
$assertions = $tests = 0;
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function same(mixed $expected, mixed $actual): void
{
    global $assertions;
    ++$assertions;
    if ($expected !== $actual) {
        throw new RuntimeException('Values differ: ' . substr(var_export($expected, true), 0, 300)
            . ' vs ' . substr(var_export($actual, true), 0, 300));
    }
}

function raises(string $class, Closure $action): void
{
    global $assertions;
    ++$assertions;
    try {
        $action();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException("Expected $class.");
}

function test(string $name, Closure $action): void
{
    global $tests;
    try {
        $action();
        ++$tests;
        echo "PASS $name\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n{$error->getTraceAsString()}\n");
        exit(1);
    }
}

/** @return list<int> */
function normalized(array $values): array
{
    $values = array_values(array_unique($values, SORT_NUMERIC));
    sort($values, SORT_NUMERIC);

    return $values;
}

$library = getenv('ROARING_LIBRARY') ?: null;
$backends = [Backend::Php];
try {
    new Bitmap(backend: Backend::Native, libraryPath: $library);
    $backends[] = Backend::Native;
} catch (NativeBackendUnavailable $error) {
    if (getenv('ROARING_REQUIRE_NATIVE') === '1') {
        throw $error;
    }
    echo "SKIP native backend (set ROARING_LIBRARY and ROARING_REQUIRE_NATIVE=1 to require it)\n";
}

test('automatic fallback and explicit failure', static function (): void {
    $missing = __DIR__ . '/does-not-exist/libroaring.so';
    same(Backend::Php, (new Bitmap([1], Backend::Auto, $missing))->backend());
    raises(NativeBackendUnavailable::class, static fn () => new Bitmap([], Backend::Native, $missing));
    $bytes = (new Bitmap([1], Backend::Php))->toPortableBytes();
    same([1], Bitmap::fromPortableBytes($bytes, Backend::Auto, $missing)->toArray());
    raises(NativeBackendUnavailable::class, static fn () => Bitmap::fromPortableBytes($bytes, Backend::Native, $missing));
});

foreach ($backends as $backend) {
    $make = static fn (iterable $values = []): Bitmap => new Bitmap($values, $backend, $library);
    $read = static fn (string $bytes): Bitmap => Bitmap::fromPortableBytes($bytes, $backend, $library);

    test("{$backend->value}: mutations, unsigned boundaries, clone, serialization", static function () use ($make, $read, $backend): void {
        $values = [0, 1, 255, 256, 65535, 65536, 2147483647, 2147483648, 4294967295];
        $bitmap = $make(array_reverse($values));
        same($backend, $bitmap->backend());
        if ($backend === Backend::Native) {
            same(Backend::Native, (new Bitmap())->backend());
        }
        same($values, $bitmap->toArray());
        same(count($values), count($bitmap));
        same(false, $bitmap->isEmpty());
        foreach ($values as $value) {
            same(true, $bitmap->contains($value));
            same(false, $bitmap->add($value));
        }
        same(false, $bitmap->contains(2));
        same(true, $bitmap->add(2));
        same(true, $bitmap->remove(2));
        same(false, $bitmap->remove(2));
        $copy = clone $bitmap;
        $copy->remove(0);
        $copy->add(2);
        same(true, $bitmap->contains(0));
        same(false, $bitmap->contains(2));
        same($values, $read($bitmap->toPortableBytes())->toArray());
        same($values, unserialize(serialize($bitmap))->toArray());
        foreach ($values as $value) {
            same(true, $bitmap->remove($value));
        }
        same(true, $bitmap->isEmpty());
        same([], $bitmap->toArray());
        same([], $read($bitmap->toPortableBytes())->toArray());
    });

    test("{$backend->value}: input validation and streamed bulk insert", static function () use ($make): void {
        $bitmap = $make();
        foreach ([-1, 4294967296, PHP_INT_MAX] as $value) {
            raises(InvalidArgumentException::class, static fn () => $bitmap->add($value));
            raises(InvalidArgumentException::class, static fn () => $bitmap->remove($value));
            raises(InvalidArgumentException::class, static fn () => $bitmap->contains($value));
        }
        foreach (['1', 1.5, true, null, []] as $value) {
            raises(InvalidArgumentException::class, static fn () => $bitmap->addMany([$value]));
        }
        raises(TypeError::class, static fn () => $bitmap->add('1'));
        foreach ([[-1, 0], [3, 2], [0, 4294967297], [4294967297, 4294967297]] as [$start, $end]) {
            raises(InvalidArgumentException::class, static fn () => $bitmap->addRange($start, $end));
        }
        $bitmap->addMany((static function (): Generator {
            for ($i = 3000; $i >= 0; --$i) {
                yield 'ignored key' => $i;
            }
        })());
        same(range(0, 3000), $bitmap->toArray());
    });

    test("{$backend->value}: array/bitset promotion and demotion", static function () use ($make, $read): void {
        $bitmap = $make(range(0, 4095));
        same(4096, count($bitmap));
        same(true, $bitmap->add(65535));
        same(4097, count($bitmap));
        same(array_merge(range(0, 4095), [65535]), $bitmap->toArray());
        same($bitmap->toArray(), $read($bitmap->toPortableBytes())->toArray());
        same(true, $bitmap->remove(100));
        same(4096, count($bitmap));
        same(false, $bitmap->contains(100));
        same(true, $bitmap->contains(65535));
        same($bitmap->toArray(), $read($bitmap->toPortableBytes())->toArray());
        $bitmap->add(100);
        $bitmap->remove(65535);
        same(range(0, 4095), $bitmap->toArray());
    });

    test("{$backend->value}: ranges and iterator snapshots", static function () use ($make, $read): void {
        $bitmap = $make();
        $bitmap->addRange(65530, 65542);
        $bitmap->addRange(65532, 65536);
        $bitmap->addRange(4294967290, 4294967296);
        $bitmap->addRange(4294967296, 4294967296);
        $bitmap->addRange(0, 0);
        $expected = array_merge(range(65530, 65541), range(4294967290, 4294967295));
        same($expected, $bitmap->toArray());
        same($expected, $read($bitmap->toPortableBytes())->toArray());
        $iterator = $bitmap->getIterator();
        $bitmap->remove(65530);
        $bitmap->add(2);
        same($expected, iterator_to_array($iterator, false));
        $full = $make();
        $full->addRange(0, 65536);
        same(65536, count($full));
        same(range(0, 65535), $read($full->toPortableBytes())->toArray());
        $copy = clone $full;
        $copy->remove(32768);
        same(true, $full->contains(32768));
    });

    test("{$backend->value}: deterministic mutation oracle", static function () use ($make): void {
        mt_srand(7645);
        $bitmap = $make();
        $reference = [];
        for ($i = 0; $i < 6000; ++$i) {
            $value = (mt_rand(0, 3) << 16) | mt_rand(0, 1023);
            if (mt_rand(0, 2) === 0) {
                same(isset($reference[$value]), $bitmap->remove($value));
                unset($reference[$value]);
            } else {
                same(!isset($reference[$value]), $bitmap->add($value));
                $reference[$value] = true;
            }
            same(isset($reference[$value]), $bitmap->contains($value));
            same(count($reference), count($bitmap));
        }
        same(normalized(array_keys($reference)), $bitmap->toArray());
    });

    test("{$backend->value}: portable golden values and run containers", static function () use ($make, $read): void {
        same('3a30000000000000', bin2hex($make()->toPortableBytes()));
        $singleton = hex2bin('3a3000000100000000000000100000000100');
        same([1], $read($singleton)->toArray());
        same($singleton, $make([1])->toPortableBytes());
        // One run [5, 7], no offsets for fewer than four containers.
        $run = pack('V', 12347) . "\x01" . pack('v5', 0, 2, 1, 5, 2);
        same([5, 6, 7], $read($run)->toArray());
        $adjacent = pack('V', 12347) . "\x01" . pack('v7', 0, 3, 2, 5, 1, 7, 1);
        $normalizedRuns = $read($adjacent);
        same([5, 6, 7, 8], $normalizedRuns->toArray());
        same([], $normalizedRuns->difference($normalizedRuns)->toArray());
        // Four run containers, including explicit offsets.
        $runs = pack('V', 12347 | (3 << 16)) . "\x0f";
        for ($i = 0; $i < 4; ++$i) {
            $runs .= pack('v2', $i, 2);
        }
        $runs .= pack('V4', 37, 43, 49, 55) . str_repeat(pack('v3', 1, 5, 2), 4);
        same([5, 6, 7, 65541, 65542, 65543, 131077, 131078, 131079, 196613, 196614, 196615], $read($runs)->toArray());
    });

    test("{$backend->value}: malformed portable data", static function () use ($make, $read): void {
        $valid = $make([0, 2, 65536])->toPortableBytes();
        for ($i = 0; $i < strlen($valid); ++$i) {
            raises(UnexpectedValueException::class, static fn () => $read(substr($valid, 0, $i)));
        }
        $badOffset = pack('V2v2Vv', 12346, 1, 0, 0, 15, 1);
        $duplicateArray = pack('V2v2Vv2', 12346, 1, 0, 1, 16, 1, 1);
        $unsortedArray = pack('V2v2Vv2', 12346, 1, 0, 1, 16, 2, 1);
        $badBitCount = pack('V2v2V', 12346, 1, 0, 4096, 16) . str_repeat("\0", 8192);
        $overflowRun = pack('V', 12347) . "\x01" . pack('v5', 0, 1, 1, 65535, 1);
        $badRunCount = pack('V', 12347) . "\x01" . pack('v5', 0, 1, 1, 0, 0);
        $overlappingRuns = pack('V', 12347) . "\x01" . pack('v7', 0, 3, 2, 0, 1, 1, 1);
        $duplicateKeys = pack('V2v4V2v2', 12346, 2, 1, 0, 1, 0, 24, 26, 2, 3);
        foreach (['', 'garbage', pack('V2', 12346, 65537), $valid . "\0", $badOffset,
            $duplicateArray, $unsortedArray, $badBitCount, $overflowRun, $badRunCount,
            $overlappingRuns, $duplicateKeys] as $bytes) {
            raises(UnexpectedValueException::class, static fn () => $read($bytes));
        }
        raises(UnexpectedValueException::class, static fn () => $make()->__unserialize([]));
    });

    test("{$backend->value}: upstream Java portable fixtures", static function () use ($read): void {
        $expected = [];
        for ($i = 0; $i < 100000; $i += 1000) {
            $expected[] = $i;
        }
        for ($i = 100000; $i < 200000; ++$i) {
            $expected[] = 3 * $i;
        }
        for ($i = 700000; $i < 800000; ++$i) {
            $expected[] = $i;
        }
        foreach (['bitmapwithruns.bin', 'bitmapwithoutruns.bin'] as $name) {
            $bitmap = $read(file_get_contents(__DIR__ . '/fixtures/' . $name));
            same(200100, count($bitmap));
            same($expected, $bitmap->toArray());
            same($expected, $read($bitmap->toPortableBytes())->toArray());
        }
    });
}

$datasets = [
    [[], []],
    [[0, 1, 2, 65535, 4294967295], [1, 3, 65536, 4294967295]],
    [range(0, 7000), range(3000, 10000)],
    [range(0, 7000), [1, 65535, 65536]],
    [[1, 65535, 65536], range(0, 7000)],
    [range(0, 4095), range(4096, 8191)],
];
mt_srand(9852);
for ($round = 0; $round < 12; ++$round) {
    $left = $right = [];
    for ($i = 0; $i < 500; ++$i) {
        $left[] = (mt_rand(0, 8) << 16) | mt_rand(0, 1023);
        $right[] = (mt_rand(0, 8) << 16) | mt_rand(0, 1023);
    }
    $datasets[] = [$left, $right];
}

foreach ($backends as $leftBackend) {
    foreach ($backends as $rightBackend) {
        test("{$leftBackend->value}/{$rightBackend->value}: set algebra and cross-backend serialization", static function () use ($datasets, $leftBackend, $rightBackend, $library): void {
            foreach ($datasets as [$left, $right]) {
                $a = new Bitmap($left, $leftBackend, $library);
                $b = new Bitmap($right, $rightBackend, $library);
                $expected = [
                    'union' => normalized(array_merge($left, $right)),
                    'intersect' => normalized(array_intersect($left, $right)),
                    'difference' => normalized(array_diff($left, $right)),
                    'symmetricDifference' => normalized(array_merge(array_diff($left, $right), array_diff($right, $left))),
                ];
                foreach ($expected as $operation => $values) {
                    $result = $a->$operation($b);
                    same($leftBackend, $result->backend());
                    same($values, $result->toArray());
                    same(count($values), $result->cardinality());
                    $result->add(123456789);
                    same(false, $a->contains(123456789));
                    same(false, $b->contains(123456789));
                }
                same(normalized($left), $a->toArray());
                same(normalized($right), $b->toArray());
                same(normalized($left), Bitmap::fromPortableBytes($a->toPortableBytes(), $rightBackend, $library)->toArray());
                same(true, $a->equals(clone $a));
                same(normalized($left) === normalized($right), $a->equals($b));
                same([], $a->difference($a)->toArray());
                same([], $a->symmetricDifference($a)->toArray());
            }
        });
    }
}

test('all 65536 container keys', static function (): void {
    $bitmap = new Bitmap(backend: Backend::Php);
    for ($key = 0; $key < 65536; ++$key) {
        $bitmap->add($key << 16);
    }
    $decoded = Bitmap::fromPortableBytes($bitmap->toPortableBytes(), Backend::Php);
    same(65536, count($decoded));
    same($bitmap->toArray(), $decoded->toArray());
});

require __DIR__ . '/order.php';
require __DIR__ . '/bitmap64.php';
require __DIR__ . '/native-order.php';

echo "\n$tests tests, $assertions assertions passed on PHP " . PHP_VERSION . ".\n";
