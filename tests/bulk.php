<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;

foreach ($backends as $backend) {
    $makeBulk = static fn (iterable $values = []): Bitmap => new Bitmap($values, $backend, $library);

    test("{$backend->value}: bulk insertion preserves caches, copies, and container transitions", static function () use ($makeBulk, $backend, $library): void {
        $seed = array_merge(range(0, 4090), [65535, 65536, 2147483648, 4294967295]);
        $bitmap = $makeBulk($seed);
        $reference = array_fill_keys($seed, true);
        mt_srand(83214);
        $batches = [[], range(4091, 4200), [1, 1, 1, 65535, 4294967295], [6000, 6001]];
        foreach ([19, 1024, 2300, 3, 1024] as $size) {
            $values = [];
            for ($i = 0; $i < $size; ++$i) {
                $values[] = (mt_rand(0, 4) << 16) | mt_rand(0, 65535);
            }
            $batches[] = $values;
        }
        foreach ($batches as $values) {
            $before = normalized(array_keys($reference));
            same(count($before), $bitmap->rank(Bitmap::MAX_VALUE));
            $bitmap->rank(2000); // Populate the dense container's prefix cache as well.
            $snapshot = $bitmap->getIterator();
            $copy = clone $bitmap;
            $bitmap->addMany($values);
            foreach ($values as $value) {
                $reference[$value] = true;
            }
            $expected = normalized(array_keys($reference));
            same($expected, $bitmap->toArray());
            same(count($expected), count($bitmap));
            same($before, iterator_to_array($snapshot, false));
            same($before, $copy->toArray());
            foreach ([0, 20, min(4095, count($expected) - 1), count($expected) - 1] as $position) {
                same($expected[$position], $bitmap->select($position));
                same($position + 1, $bitmap->rank($expected[$position]));
            }
            same($expected, Bitmap::fromPortableBytes($bitmap->toPortableBytes(), $backend, $library)->toArray());
        }
        // Cross the promotion threshold in one batch and then demote by removal.
        $transition = $makeBulk(range(0, 4090));
        $transition->addMany([4091, 4092, 4093, 4094, 4095, 4096, 4096]);
        same(4097, count($transition));
        $transition->rank(4096);
        $transition->remove(4096);
        same(range(0, 4095), $transition->toArray());
        same(4096, $transition->rank(4096));
    });

    test("{$backend->value}: bulk validation commits only completed batches", static function () use ($makeBulk): void {
        foreach (['1', 1.5, true, null, [], -1, 4294967296] as $invalid) {
            $bitmap = $makeBulk();
            $values = (static function () use ($invalid): Generator {
                for ($i = 0; $i < 1030; ++$i) {
                    yield 'ignored key' => $i;
                }
                yield $invalid;
            })();
            raises(InvalidArgumentException::class, static fn () => $bitmap->addMany($values));
            same(range(0, 1023), $bitmap->toArray());
            same(1024, $bitmap->rank(2000));
        }
        $bitmap = $makeBulk();
        raises(RuntimeException::class, static fn () => $bitmap->addMany((static function (): Generator {
            yield from range(0, 2049);
            throw new RuntimeException('Interrupted source');
        })()));
        same(range(0, 2047), $bitmap->toArray());
        $bitmap->addMany(['ignored' => 4294967295]);
        same(4294967295, $bitmap->select(2048));
    });

    test("{$backend->value}: identical payload bytes in different container formats", static function () use ($makeBulk): void {
        // A 4096-value array and an 8192-byte bitmap can have identical bytes.
        $left = range(0, 4095);
        $bytes = pack('v*', ...$left);
        $right = [];
        for ($value = 0; $value < 65536; ++$value) {
            if ((ord($bytes[$value >> 3]) & (1 << ($value & 7))) !== 0) {
                $right[] = $value;
            }
        }
        $a = $makeBulk($left);
        $b = $makeBulk($right);
        same(normalized(array_intersect($left, $right)), $a->intersect($b)->toArray());
        same(normalized(array_diff($left, $right)), $a->difference($b)->toArray());
        same(normalized(array_merge($left, $right)), $a->union($b)->toArray());
        $equal = $a->intersect($makeBulk($left));
        $equal->remove(0);
        same(true, $a->contains(0));
        same([], $a->symmetricDifference($makeBulk($left))->toArray());
    });
}
