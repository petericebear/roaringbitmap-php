<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RoaringBitmap\Bitmap;
use RoaringBitmap\Bitmap64;

$small = new Bitmap([10, 20, 40]);
printf("32-bit rank(25) = %d; select(1) = %d\n", $small->rank(25), $small->select(1));

$large = new Bitmap64([42, '4294967296', '9223372036854775808', Bitmap64::MAX_VALUE]);
printf("64-bit backend: %s\n", $large->backend()->value);
printf("rank(9223372036854775808) = %d; select(3) = %s\n", $large->rank('9223372036854775808'), $large->select(3));
$large->addRange('18446744073709551612', Bitmap64::END_EXCLUSIVE);
echo 'Exact JSON IDs: ' . json_encode($large->toArray(), JSON_THROW_ON_ERROR) . PHP_EOL;
$copy = Bitmap64::fromPortableBytes($large->toPortableBytes());
echo 'Portable round trip: ' . ($copy->equals($large) ? 'OK' : 'FAILED') . PHP_EOL;
