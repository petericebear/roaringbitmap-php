<?php

declare(strict_types=1);

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap64;
use RoaringBitmap\Internal\NativeLibrary;
use RoaringBitmap\Internal\UInt64;

if (!in_array(Backend::Native, $backends, true)) {
    return;
}

$indexed = NativeLibrary::supportsOrderIndex(NativeLibrary::load($library, true));
if (getenv('ROARING_REQUIRE_ORDER_INDEX') === '1' && !$indexed) {
    throw new RuntimeException('The required native order index is unavailable; rebuild with tools/build-native.sh.');
}

// Observe ownership without exposing implementation details in the public API.
function hasNativeIndex(Bitmap64 $bitmap): bool
{
    $engine = (new ReflectionProperty($bitmap, 'engine'))->getValue($bitmap);

    return (new ReflectionProperty($engine, 'orderIndex'))->getValue($engine) !== null;
}

function checkNativeOrder(Bitmap64 $native, Bitmap64 $oracle, bool $indexed): void
{
    $values = $oracle->toArray();
    same(count($oracle), count($native));
    for ($i = 0; $i < 100; ++$i) {
        $position = ($i * 97) % count($values);
        same($values[$position], $native->select($position));
        same($position + 1, $native->rank($values[$position]));
    }
    foreach (['0', '1', '65536', '4294967295', '4294967296', '9223372036854775807',
        '9223372036854775808', '18446744073709551614', Bitmap64::MAX_VALUE] as $probe) {
        same($oracle->rank($probe), $native->rank($probe));
    }
    same(null, $native->select(count($values)));
    same(null, $native->select(PHP_INT_MAX));
    same($indexed, hasNativeIndex($native));
}

test('native 64-bit index activation and container threshold', static function () use ($library, $indexed): void {
    foreach ([63, 64, 257] as $containers) {
        $values = [];
        for ($i = 0; $i < $containers; ++$i) {
            $values[] = (string) ($i * 65536 + 7);
        }
        $bitmap = new Bitmap64($values, Backend::Native, $library);
        for ($i = 0; $i < 15; ++$i) {
            same('7', $bitmap->select(0));
        }
        same(false, hasNativeIndex($bitmap));
        if ($containers === 257) {
            same($values[256], $bitmap->select(256)); // Select can also build the index first.
        } else {
            same(1, $bitmap->rank(7));
        }
        same($indexed && $containers >= 64, hasNativeIndex($bitmap));
        same($containers, $bitmap->rank(Bitmap64::MAX_VALUE));
        same($values[$containers - 1], $bitmap->select($containers - 1));
    }
});

test('native 64-bit indexed mutations, clones, snapshots, and persistence', static function () use ($library, $indexed): void {
    $values = ['0', Bitmap64::MAX_VALUE];
    for ($i = 0; $i < 260; ++$i) {
        foreach ([3, 99, 65535] as $low) {
            $values[] = UInt64::decimal(UInt64::fromParts($i % 2 ? 0x80000000 : 0, ($i * 2 << 16) | $low));
        }
    }
    // Force a bitset container with individual additions, alongside run containers below.
    for ($i = 0; $i < 5000; ++$i) {
        $values[] = (string) (4294967296 + 2 * $i);
    }
    $bitmap = new Bitmap64($values, Backend::Native, $library);
    $oracle = new Bitmap64($values, Backend::Php);
    foreach ([$bitmap, $oracle] as $set) {
        $set->addRange('9223372041149743104', '9223372041149753104');
    }
    checkNativeOrder($bitmap, $oracle, $indexed);
    $clone = clone $bitmap;
    same(false, hasNativeIndex($clone));
    checkNativeOrder($clone, $oracle, $indexed);
    $snapshot = $bitmap->getIterator();
    $original = $oracle->toArray();

    $operations = [
        ['add', [123]], ['add', [4294901761]], ['remove', [0]], ['remove', [Bitmap64::MAX_VALUE]],
        ['addMany', [[17, '18446744073709551615', '9223372036854775817']]],
        ['addRange', ['4294967294', '4294967300']],
        ['addRangeClosed', ['18446744073709551612', Bitmap64::MAX_VALUE]],
        ['remove', [4294901761]], ['add', [123]], ['remove', [42]],
    ];
    foreach ($operations as [$method, $arguments]) {
        $bitmap->$method(...$arguments);
        $oracle->$method(...$arguments);
        same(false, hasNativeIndex($bitmap));
        checkNativeOrder($bitmap, $oracle, $indexed);
    }
    same($original, iterator_to_array($snapshot, false));
    same($original, $clone->toArray());
    unset($clone, $snapshot); // Free independent owners while the original cache is live.
    checkNativeOrder($bitmap, $oracle, $indexed);
    $clone = clone $bitmap;
    checkNativeOrder($clone, $oracle, $indexed);
    unset($bitmap); // The clone's index must refer to the clone's containers.
    checkNativeOrder($clone, $oracle, $indexed);
    $restored = Bitmap64::fromPortableBytes($clone->toPortableBytes(), Backend::Native, $library);
    checkNativeOrder($restored, $oracle, $indexed);
    foreach (['union', 'intersect', 'difference', 'symmetricDifference'] as $operation) {
        $other = new Bitmap64([123, 42424242, Bitmap64::MAX_VALUE], Backend::Php);
        checkNativeOrder($restored->$operation($other), $oracle->$operation($other), $indexed && $operation !== 'intersect');
    }
});

test('native 64-bit indexed counts beyond uint32 and run containers', static function () use ($library, $indexed): void {
    $bitmap = new Bitmap64(['9223372036854775808', Bitmap64::MAX_VALUE], Backend::Native, $library);
    $bitmap->addRange(0, '4294967296'); // Compact native runs: do not materialize four billion IDs.
    same(4294967298, count($bitmap));
    for ($i = 0; $i < 20; ++$i) {
        same(4294967296, $bitmap->rank('4294967295'));
    }
    same($indexed, hasNativeIndex($bitmap));
    foreach ([0 => '0', 2147483648 => '2147483648', 4294967295 => '4294967295',
        4294967296 => '9223372036854775808', 4294967297 => Bitmap64::MAX_VALUE] as $position => $value) {
        same($value, $bitmap->select($position));
        same($position + 1, $bitmap->rank($value));
    }
    same(null, $bitmap->select(4294967298));
    $bitmap->remove('2147483648');
    same(false, hasNativeIndex($bitmap));
    same('2147483649', $bitmap->select(2147483648));
    same(2147483648, $bitmap->rank('2147483648'));
});
