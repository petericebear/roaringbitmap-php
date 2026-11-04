<?php

declare(strict_types=1);

use RoaringBitmap\Bitmap;

foreach ($backends as $backend) {
    test("{$backend->value}: rank/select across sparse, dense, and empty containers", static function () use ($backend, $library): void {
        $cases = [[], [10, 20, 40], [0, 65535, 65536, 2147483648, 4294967295],
            range(30000, 36000), array_merge(range(0, 5000), range(131000, 138000), [4294967295])];
        foreach ($cases as $values) {
            $bitmap = new Bitmap(array_reverse($values), $backend, $library);
            $expected = normalized($values);
            foreach ([0, 1, 9, 10, 19, 20, 25, 40, 41, 2047, 2048, 29999, 30000, 35555, 36000, 65535, 65536, 120000, 131071, 131072, 4294967295] as $value) {
                same(count(array_filter($expected, static fn (int $id): bool => $id <= $value)), $bitmap->rank($value));
            }
            for ($i = 0; $i < count($expected); $i += 17) {
                same($expected[$i], $bitmap->select($i));
                same($i + 1, $bitmap->rank($bitmap->select($i)));
            }
            if ($expected !== []) {
                same($expected[count($expected) - 1], $bitmap->select(count($expected) - 1));
            }
            same(null, $bitmap->select(count($expected)));
            same(null, $bitmap->select(4294967296));
            same(null, $bitmap->select(PHP_INT_MAX));
            raises(InvalidArgumentException::class, static fn () => $bitmap->select(-1));
            raises(InvalidArgumentException::class, static fn () => $bitmap->rank(-1));
            raises(InvalidArgumentException::class, static fn () => $bitmap->rank(4294967296));
        }
    });

    test("{$backend->value}: order indexes survive mutation, clone, and conversion", static function () use ($backend, $library): void {
        $bitmap = new Bitmap(range(0, 4095), $backend, $library);
        same(4096, $bitmap->rank(65535));
        $bitmap->add(65535); // Promote to bitset.
        same(65535, $bitmap->select(4096));
        same(4097, $bitmap->rank(65535));
        $copy = clone $bitmap;
        $bitmap->remove(100); // Demote back to array.
        same(4096, $bitmap->rank(65535));
        same(101, $bitmap->select(100));
        same(100, $copy->select(100));
        $bitmap->addRange(60000, 70000);
        same(14095, $bitmap->rank(69999));
        same(69999, $bitmap->select(14094));
        $bitmap->add(4294967295);
        same(4294967295, $bitmap->select(count($bitmap) - 1));
        $bitmap->remove(4294967295); // Remove an entire top-level container.
        same(69999, $bitmap->select(count($bitmap) - 1));
        $bitmap->add(60000); // No-op must preserve valid cached counts.
        $bitmap->remove(123456);
        same(14095, $bitmap->rank(69999));
        foreach ([$bitmap->union($copy), $bitmap->intersect($copy), $bitmap->difference($copy),
            $bitmap->symmetricDifference($copy), Bitmap::fromPortableBytes($bitmap->toPortableBytes(), $backend, $library)] as $result) {
            foreach ($result as $index => $value) {
                if ($index % 31 === 0) {
                    same($index + 1, $result->rank($value));
                    same($value, $result->select($index));
                }
            }
        }
        // A populated dense block index must also be invalidated by dense-to-dense mutations.
        $dense = new Bitmap(range(0, 10000), $backend, $library);
        same(5001, $dense->rank(5000));
        $dense->remove(2048);
        same(5000, $dense->rank(5000));
        same(2049, $dense->select(2048));
        $dense->add(2048);
        same(2048, $dense->select(2048));
        $dense->addRange(60000, 65000);
        same(15001, $dense->rank(65535));
        same(64999, $dense->select(15000));
    });

    test("{$backend->value}: order statistics against a changing set oracle", static function () use ($backend, $library): void {
        mt_srand(932010);
        $bitmap = new Bitmap(backend: $backend, libraryPath: $library);
        $oracle = [];
        for ($i = 0; $i < 1500; ++$i) {
            $value = (mt_rand(0, 5) << 16) | mt_rand(0, 255);
            if (mt_rand(0, 2) === 0) {
                $bitmap->remove($value);
                unset($oracle[$value]);
            } else {
                $bitmap->add($value);
                $oracle[$value] = true;
            }
            $sorted = normalized(array_keys($oracle));
            same(count(array_filter($sorted, static fn (int $id): bool => $id <= $value)), $bitmap->rank($value));
            $position = mt_rand(0, count($sorted));
            same($sorted[$position] ?? null, $bitmap->select($position));
        }
    });
}
