<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RoaringBitmap\Bitmap;
use RoaringBitmap\Bitmap64;
use RoaringBitmap\Backend;

// php examples/advanced.php --backend=php
// ROARING_LIBRARY=/path/to/libroaring.dylib php -d ffi.enable=1 examples/advanced.php --backend=native
// Auto uses native support when available, otherwise PHP. Native requires the library and FFI.
$options = getopt('', ['backend:']);
$backend = Backend::from($options['backend'] ?? 'auto');

/** Decode text read from a database column, cache, or API response. */
function savedBitmapBytes(string $savedBase64): string
{
    $bytes = base64_decode($savedBase64, true);
    if ($bytes === false) {
        throw new InvalidArgumentException('The saved bitmap is not valid Base64.');
    }

    return $bytes; // fromPortableBytes() also validates the binary bitmap format.
}

// 1. Existing array -> bitmap -> add one ID -> array.
$ids = [1, 3, 5];
$bitmap = new Bitmap($ids, $backend);
$bitmap->add(7);
$ids = $bitmap->toArray(); // [1, 3, 5, 7], sorted integers.
echo 'Array round trip: ' . json_encode($ids, JSON_THROW_ON_ERROR) . PHP_EOL;

// 2. Save portable bytes as Base64 text; later read that string from your storage.
$savedBase64 = base64_encode((new Bitmap([1, 3, 5], $backend))->toPortableBytes());
// In an application, replace $savedBase64 with the database/cache/API field's value.
$restored = Bitmap::fromPortableBytes(savedBitmapBytes($savedBase64), $backend);
$added = $restored->add(7); // true; adding the same ID again returns false.
$updatedBase64 = base64_encode($restored->toPortableBytes()); // Persist this updated string.
echo 'Saved 32-bit bitmap -> add -> array: ' . json_encode($restored->toArray(), JSON_THROW_ON_ERROR) . PHP_EOL;
echo 'Updated saved bitmap: ' . $updatedBase64 . PHP_EOL;

// 3. An alternative when only an array is needed: restore -> array -> append.
$restoredForArray = Bitmap::fromPortableBytes(savedBitmapBytes($savedBase64), $backend);
$arrayOnly = $restoredForArray->toArray();
$arrayOnly[] = 7; // Appending allows duplicates; it does not modify or persist the bitmap.
echo 'Saved bitmap -> array -> append: ' . json_encode($arrayOnly, JSON_THROW_ON_ERROR) . PHP_EOL;

// 4. The same saved-string workflow for 64-bit IDs. Match the reader to the writer:
// Bitmap64::fromPortableBytes() reads Bitmap64 bytes; Bitmap reads 32-bit bytes.
$savedBase64Long = base64_encode((new Bitmap64([1, 3, 5, '9223372036854775808'], $backend))->toPortableBytes());
$membership = Bitmap64::fromPortableBytes(savedBitmapBytes($savedBase64Long), $backend);
$membership->add(7);
$membership->addMany([9, 11, 11]); // Deduplicates; save once for the whole batch.
$updatedBase64Long = base64_encode($membership->toPortableBytes());
// Persist $updatedBase64Long. toArray() is optional when the next consumer accepts a bitmap.
echo 'Saved 64-bit bitmap -> add -> array: ' . json_encode($membership->toArray(), JSON_THROW_ON_ERROR) . PHP_EOL;
// Bitmap64 returns decimal strings, preserving values above PHP_INT_MAX. Do not cast those to int.
$verified = Bitmap64::fromPortableBytes(savedBitmapBytes($updatedBase64Long), $backend);
echo 'Saved 64-bit round trip: ' . ($verified->equals($membership) ? 'OK' : 'FAILED') . PHP_EOL;

// Order queries and unsigned boundaries.
$small = new Bitmap([10, 20, 40], $backend);
printf("32-bit rank(25) = %d; select(1) = %d\n", $small->rank(25), $small->select(1));

$large = new Bitmap64([42, '4294967296', '9223372036854775808', Bitmap64::MAX_VALUE], $backend);
printf("64-bit backend: %s\n", $large->backend()->value);
printf("rank(9223372036854775808) = %d; select(3) = %s\n", $large->rank('9223372036854775808'), $large->select(3));
$large->addRange('18446744073709551612', Bitmap64::END_EXCLUSIVE);
echo 'Exact JSON IDs: ' . json_encode($large->toArray(), JSON_THROW_ON_ERROR) . PHP_EOL;
$copy = Bitmap64::fromPortableBytes($large->toPortableBytes(), $backend);
echo 'Portable round trip: ' . ($copy->equals($large) ? 'OK' : 'FAILED') . PHP_EOL;
