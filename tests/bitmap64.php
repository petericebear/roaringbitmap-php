<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;
use RoaringBitmap\Bitmap64;
use RoaringBitmap\Exception\NativeBackendUnavailable;
use RoaringBitmap\Internal\PrefixIndex;
use RoaringBitmap\Internal\UInt64;

require __DIR__ . '/weak-caller.php';

/** Compare canonical decimal strings without numeric-string coercion. */
function compare64(string $a, string $b): int
{
    return (strlen($a) <=> strlen($b)) ?: strcmp($a, $b);
}

/** @return list<string> */
function normalized64(array $values): array
{
    $values = array_values(array_unique(array_map('strval', $values), SORT_STRING));
    usort($values, 'compare64');

    return $values;
}

$vectors64 = json_decode(file_get_contents(__DIR__ . '/fixtures/uint64-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
$ids64 = array_column($vectors64, 'decimal');

test('uint64 exact conversion against independent Python integer vectors', static function () use ($vectors64): void {
    foreach ($vectors64 as $vector) {
        $bits = UInt64::parse($vector['decimal']);
        same($vector['decimal'], UInt64::decimal($bits));
        same($vector['little_endian_hex'], bin2hex(pack('V2', UInt64::low($bits), UInt64::high($bits))));
    }
    same(PHP_INT_MAX, UInt64::rangeSize(1, PHP_INT_MAX));
    same(2, UInt64::rangeSize(PHP_INT_MAX, PHP_INT_MIN));
    same(2, UInt64::rangeSize(UInt64::parse('4294967295'), UInt64::parse('4294967296')));
    same(1, UInt64::rangeSize(-1, -1));
    raises(OverflowException::class, static fn () => UInt64::rangeSize(0, PHP_INT_MAX));
    raises(OverflowException::class, static fn () => UInt64::checkedCount(-1));
    raises(OverflowException::class, static fn () => new PrefixIndex([0 => PHP_INT_MAX, 1 => 1]));
});

test('64-bit automatic fallback and strict native availability', static function (): void {
    $missing = __DIR__ . '/does-not-exist/libroaring.so';
    $bitmap = new Bitmap64([Bitmap64::MAX_VALUE], Backend::Auto, $missing);
    same(Backend::Php, $bitmap->backend());
    same([Bitmap64::MAX_VALUE], Bitmap64::fromPortableBytes($bitmap->toPortableBytes(), Backend::Auto, $missing)->toArray());
    raises(NativeBackendUnavailable::class, static fn () => new Bitmap64(backend: Backend::Native, libraryPath: $missing));
    raises(NativeBackendUnavailable::class, static fn () => Bitmap64::fromPortableBytes($bitmap->toPortableBytes(), Backend::Native, $missing));
});

foreach ($backends as $backend) {
    $make64 = static fn (iterable $values = []): Bitmap64 => new Bitmap64($values, $backend, $library);
    $read64 = static fn (string $bytes): Bitmap64 => Bitmap64::fromPortableBytes($bytes, $backend, $library);

    test("{$backend->value}: full uint64 domain, ordering, rank/select, mutation, clone", static function () use ($make64, $read64, $backend, $ids64): void {
        $bitmap = $make64(array_reverse($ids64));
        same($backend, $bitmap->backend());
        same($ids64, $bitmap->toArray());
        same(count($ids64), $bitmap->cardinality());
        foreach ($ids64 as $index => $id) {
            same(true, $bitmap->contains($id));
            same(false, $bitmap->add($id));
            same($index + 1, $bitmap->rank($id));
            same($id, $bitmap->select($index));
        }
        same(1, $bitmap->rank(0));
        same(2, $bitmap->rank(2));
        same(null, $bitmap->select(count($ids64)));
        same(null, $bitmap->select(PHP_INT_MAX));
        same('18446744073709551615', $bitmap->select(count($ids64) - 1));
        $copy = clone $bitmap;
        same(true, $copy->remove(Bitmap64::MAX_VALUE));
        same(false, $copy->remove(Bitmap64::MAX_VALUE));
        same(true, $bitmap->contains(Bitmap64::MAX_VALUE));
        same($ids64, $read64($bitmap->toPortableBytes())->toArray());
        same($ids64, unserialize(serialize($bitmap))->toArray());
        $snapshot = $bitmap->getIterator();
        $bitmap->remove(0);
        $bitmap->add(2);
        same($ids64, iterator_to_array($snapshot, false));
        same('1', $bitmap->select(0));
        same('2', $bitmap->select(1));
        foreach ($bitmap->toArray() as $id) {
            same(true, $bitmap->remove($id));
        }
        same(true, $bitmap->isEmpty());
        same([], $bitmap->toArray());
        same(0, $bitmap->rank(Bitmap64::MAX_VALUE));
        same(null, $bitmap->select(0));
        same([], $read64($bitmap->toPortableBytes())->toArray());
    });

    test("{$backend->value}: 64-bit exact input validation, including weak PHP callers", static function () use ($make64): void {
        $bitmap = $make64([0, '000', '0001', 1]);
        same(['0', '1'], $bitmap->toArray());
        same(false, $bitmap->add('00001'));
        $invalid = [-1, PHP_INT_MIN, true, false, null, 1.0, 9007199254740992.0, INF, NAN, [], new stdClass(),
            '', ' 1', '1 ', '+1', '-0', '-1', '1.0', '1e3', '0xff', "1\n", '18446744073709551616', '184467440737095516150'];
        foreach ($invalid as $value) {
            raises(InvalidArgumentException::class, static fn () => $bitmap->add($value));
            raises(InvalidArgumentException::class, static fn () => weakAdd64($bitmap, $value));
            raises(InvalidArgumentException::class, static fn () => $bitmap->contains($value));
            raises(InvalidArgumentException::class, static fn () => $bitmap->remove($value));
            raises(InvalidArgumentException::class, static fn () => $bitmap->rank($value));
            raises(InvalidArgumentException::class, static fn () => $bitmap->addMany([$value]));
        }
        same(['0', '1'], $bitmap->toArray());
        raises(InvalidArgumentException::class, static fn () => $bitmap->select(-1));
        raises(UnexpectedValueException::class, static fn () => $bitmap->__unserialize(['portable64' => 1]));
        raises(TypeError::class, static fn () => $bitmap->union(new Bitmap([1], Backend::Php)));
        same(['0', '4294967295'], $make64(new Bitmap([0, 4294967295], Backend::Php))->toArray());
    });

    test("{$backend->value}: 64-bit ranges across 32-bit, signed, and maximum boundaries", static function () use ($make64, $read64): void {
        $cases = [
            ['4294967294', '4294967298', ['4294967294', '4294967295', '4294967296', '4294967297']],
            ['9223372036854775806', '9223372036854775810', ['9223372036854775806', '9223372036854775807', '9223372036854775808', '9223372036854775809']],
            ['18446744073709551612', Bitmap64::END_EXCLUSIVE, ['18446744073709551612', '18446744073709551613', '18446744073709551614', '18446744073709551615']],
        ];
        foreach ($cases as [$start, $end, $expected]) {
            $bitmap = $make64();
            $bitmap->addRange($start, $end);
            same($expected, $bitmap->toArray());
            $bitmap->rank(Bitmap64::MAX_VALUE); // Build both levels of the PHP index.
            $bitmap->addRangeClosed($start, $expected[count($expected) - 1]);
            same(count($expected), count($bitmap));
            same($expected, $read64($bitmap->toPortableBytes())->toArray());
            same($expected, $bitmap->intersect($bitmap)->toArray());
            foreach ($expected as $index => $value) {
                same($index + 1, $bitmap->rank($value));
                same($value, $bitmap->select($index));
            }
        }
        $bitmap = $make64();
        $bitmap->addRange(Bitmap64::END_EXCLUSIVE, Bitmap64::END_EXCLUSIVE);
        $bitmap->addRange(0, 0);
        same(true, $bitmap->isEmpty());
        $bitmap->addRangeClosed(Bitmap64::MAX_VALUE, Bitmap64::MAX_VALUE);
        same([Bitmap64::MAX_VALUE], $bitmap->toArray());
        foreach ([[2, 1], [Bitmap64::END_EXCLUSIVE, Bitmap64::MAX_VALUE], [0, '18446744073709551617'], [1.0, 2], [false, 1]] as [$start, $end]) {
            raises(InvalidArgumentException::class, static fn () => $bitmap->addRange($start, $end));
        }
        raises(InvalidArgumentException::class, static fn () => $bitmap->addRangeClosed(2, 1));
        raises(OverflowException::class, static fn () => $bitmap->addRange(0, '9223372036854775808'));
        raises(OverflowException::class, static fn () => $bitmap->addRange('9223372036854775808', Bitmap64::END_EXCLUSIVE));
        raises(OverflowException::class, static fn () => $bitmap->addRangeClosed(0, Bitmap64::MAX_VALUE));
        same([Bitmap64::MAX_VALUE], $bitmap->toArray());
    });

    test("{$backend->value}: 64-bit streaming, large iteration batches, and index invalidation", static function () use ($make64): void {
        $values = (static function (): Generator {
            for ($i = 0; $i < 5000; ++$i) {
                yield 'ignored' => (string) (4294967296 + $i);
            }
            yield Bitmap64::MAX_VALUE;
        })();
        $bitmap = $make64($values);
        same(5001, count($bitmap));
        same('4294971392', $bitmap->select(4096));
        same(5000, $bitmap->rank('4294972295'));
        $clone = clone $bitmap;
        $bitmap->remove('4294967296');
        $bitmap->add(0);
        same('0', $bitmap->select(0));
        same('4294967297', $bitmap->select(1));
        same('4294967296', $clone->select(0));
        $bitmap->addRange('9223372036854775808', '9223372036854782808');
        same(12001, count($bitmap));
        same('9223372036854782807', $bitmap->select(11999));
        same(Bitmap64::MAX_VALUE, $bitmap->select(12000));
        same(12001, count($bitmap->toArray()));
        foreach ([0, 1, 4095, 4096, 4999, 5000, 11000, 12000] as $index) {
            same($index + 1, $bitmap->rank($bitmap->select($index)));
        }
        // Generator interruption must free its native iterator without affecting either set.
        $it = $bitmap->getIterator();
        $it->rewind();
        same('0', $it->current());
        unset($it);
        same(12001, count($bitmap));
    });

    test("{$backend->value}: 64-bit changing set oracle", static function () use ($make64, $ids64): void {
        mt_srand(64222);
        $bitmap = $make64();
        $reference = [];
        for ($i = 0; $i < 1000; ++$i) {
            $value = $ids64[mt_rand(0, count($ids64) - 1)];
            $key = 'id:' . $value;
            if (mt_rand(0, 2) === 0) {
                same(isset($reference[$key]), $bitmap->remove($value));
                unset($reference[$key]);
            } else {
                same(!isset($reference[$key]), $bitmap->add($value));
                $reference[$key] = $value;
            }
            $sorted = normalized64(array_values($reference));
            same(count($sorted), count($bitmap));
            same(count(array_filter($sorted, static fn (string $id): bool => compare64($id, $value) <= 0)), $bitmap->rank($value));
            $index = mt_rand(0, count($sorted));
            same($sorted[$index] ?? null, $bitmap->select($index));
        }
        same(normalized64(array_values($reference)), $bitmap->toArray());
    });

    test("{$backend->value}: strict 64-bit portable validation and embedded offsets", static function () use ($make64, $read64): void {
        same(str_repeat("\0", 8), $make64()->toPortableBytes());
        $one = (new Bitmap([1], Backend::Php))->toPortableBytes();
        $expected = pack('V2', 2, 0) . pack('V', 0) . $one . pack('V', 0xffffffff) . $one;
        same(['1', '18446744069414584321'], $read64($expected)->toArray());
        $valid = $make64([0, '4294967296', Bitmap64::MAX_VALUE])->toPortableBytes();
        for ($i = 0; $i < strlen($valid); ++$i) {
            raises(UnexpectedValueException::class, static fn () => $read64(substr($valid, 0, $i)));
        }
        $duplicate = pack('V2', 2, 0) . pack('V', 1) . $one . pack('V', 1) . $one;
        $descending = pack('V2', 2, 0) . pack('V', 1) . $one . pack('V', 0) . $one;
        $emptyBucket = pack('V2', 1, 0) . pack('V', 1) . (new Bitmap(backend: Backend::Php))->toPortableBytes();
        $badOffset = pack('V2', 1, 0) . pack('V', 1) . substr_replace($one, pack('V', 0), 12, 4);
        foreach ([$duplicate, $descending, $badOffset, $valid . "\0", $one, pack('V2', 0, 1)] as $bytes) {
            raises(UnexpectedValueException::class, static fn () => $read64($bytes));
        }
        $empty = $read64($emptyBucket);
        same([], $empty->toArray());
        same(0, $empty->rank(Bitmap64::MAX_VALUE));
        same(null, $empty->select(0));
        same(str_repeat("\0", 8), $empty->toPortableBytes());
        $withEmpty = pack('V2', 2, 0) . substr($emptyBucket, 8) . pack('V', 2) . $one;
        same(['8589934593'], $read64($withEmpty)->toArray());
        raises(UnexpectedValueException::class, static fn () => $read64(pack('V2', 2, 0) . substr($emptyBucket, 8) . pack('V', 1) . $one));
        // A no-offset run bitmap, followed by an offset-bearing array bitmap.
        $run = pack('V', 12347) . "\x01" . pack('v7', 0, 3, 2, 5, 1, 7, 1);
        $adjacent = pack('V2', 2, 0) . pack('V', 0x80000000) . $run . pack('V', 0xffffffff) . $one;
        same(['9223372036854775813', '9223372036854775814', '9223372036854775815', '9223372036854775816', '18446744069414584321'], $read64($adjacent)->toArray());
    });

    test("{$backend->value}: upstream C/C++ 64-bit portable fixtures", static function () use ($read64): void {
        $bitmap = $read64(file_get_contents(__DIR__ . '/fixtures/bitmap64.bin'));
        same(1032769, count($bitmap));
        same('0', $bitmap->select(0));
        same('65534', $bitmap->select(32767));
        same('4294967296', $bitmap->select(32768));
        same('4295967295', $bitmap->select(1032767));
        same('281474976710656', $bitmap->select(1032768));
        foreach ($bitmap as $index => $value) {
            $expected = $index < 32768 ? (string) ($index * 2)
                : ($index < 1032768 ? (string) (4294967296 + $index - 32768) : '281474976710656');
            same($expected, $value);
        }
        $other = $read64(file_get_contents(__DIR__ . '/fixtures/portable_bitmap64.bin'));
        // Expected sets follow the C generator's inclusive range endpoints.
        $expected = (static function (): Generator {
            foreach ([0, 4294967296] as $base) {
                for ($i = 0; $i <= 0x9000; ++$i) { yield (string) ($base + $i); }
                for ($i = 0xa000; $i <= 0x10000; ++$i) { yield (string) ($base + $i); }
                yield (string) ($base + 0x20000);
                yield (string) ($base + 0x20005);
                for ($i = 0; $i < 0x10000; $i += 2) { yield (string) ($base + 0x80000 + $i); }
            }
        })();
        $expected->rewind();
        $count = 0;
        foreach ($other as $value) {
            same(true, $expected->valid());
            same($expected->current(), $value);
            $expected->next();
            ++$count;
        }
        same(false, $expected->valid());
        same(188424, $count);
        same($count, count($other));
        same(true, $read64($other->toPortableBytes())->equals($other));
    });
}

foreach ($backends as $leftBackend) {
    foreach ($backends as $rightBackend) {
        test("64-bit {$leftBackend->value}/{$rightBackend->value}: mixed algebra, ranges, and serialization", static function () use ($leftBackend, $rightBackend, $library, $ids64): void {
            $left = $right = [];
            foreach ($ids64 as $index => $value) {
                if ($index % 3 !== 0) { $left[] = $value; }
                if ($index % 2 !== 0) { $right[] = $value; }
            }
            foreach ([[$left, $right], [[], []], [[], [Bitmap64::MAX_VALUE]], [[0, '9223372036854775808'], []]] as [$left, $right]) {
                $a = new Bitmap64($left, $leftBackend, $library);
                $b = new Bitmap64($right, $rightBackend, $library);
                $expected = [
                    'union' => normalized64(array_merge($left, $right)),
                    'intersect' => normalized64(array_intersect($left, $right)),
                    'difference' => normalized64(array_diff($left, $right)),
                    'symmetricDifference' => normalized64(array_merge(array_diff($left, $right), array_diff($right, $left))),
                ];
                foreach ($expected as $operation => $values) {
                    $result = $a->$operation($b);
                    same($leftBackend, $result->backend());
                    same($values, $result->toArray());
                    same(count($values), count($result));
                    foreach ($values as $i => $value) {
                        same($i + 1, $result->rank($value));
                        same($value, $result->select($i));
                    }
                    $result->add(333);
                    same(false, $a->contains(333));
                    same(false, $b->contains(333));
                }
                same(normalized64($left), $a->toArray());
                same(normalized64($right), $b->toArray());
                same(true, $a->equals(Bitmap64::fromPortableBytes($a->toPortableBytes(), $rightBackend, $library)));
                same([], $a->difference($a)->toArray());
                same([], $a->symmetricDifference($a)->toArray());
            }
            $a = new Bitmap64(backend: $leftBackend, libraryPath: $library);
            $a->addRange('9223372036854775808', '9223372036854785808');
            $b = new Bitmap64(backend: $rightBackend, libraryPath: $library);
            $b->addRange('9223372036854780808', '9223372036854790808');
            same(15000, count($a->union($b)));
            same(5000, count($a->intersect($b)));
            same(5000, count($a->difference($b)));
            same(10000, count($a->symmetricDifference($b)));
            same('9223372036854780808', $a->intersect($b)->select(0));
            same('9223372036854785807', $a->intersect($b)->select(4999));
        });
    }
}
