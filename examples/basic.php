<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RoaringBitmap\Bitmap;

$visitorsMonday = new Bitmap([10, 20, 30, 1000000]);
$visitorsTuesday = new Bitmap([20, 30, 40]);
$returningVisitors = $visitorsMonday->intersect($visitorsTuesday);

echo 'Backend: ' . $returningVisitors->backend()->value . PHP_EOL;
echo 'Returning visitors: ' . implode(', ', $returningVisitors->toArray()) . PHP_EOL;
echo 'Unique visitors: ' . count($visitorsMonday->union($visitorsTuesday)) . PHP_EOL;

$bytes = $returningVisitors->toPortableBytes();
$restored = Bitmap::fromPortableBytes($bytes);
echo 'Round trip: ' . ($restored->equals($returningVisitors) ? 'OK' : 'FAILED') . PHP_EOL;
