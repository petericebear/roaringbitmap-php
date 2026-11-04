<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

use UnexpectedValueException;

/** @internal RoaringFormatSpec's unsigned 64-bit portable extension. */
final class PortableCodec64
{
    /** @param array<int, PhpEngine> $buckets */
    public static function encode(array $buckets): string
    {
        if (count($buckets) > 4294967295) {
            throw new \OverflowException('The portable format permits at most 4294967295 buckets.');
        }
        ksort($buckets, SORT_NUMERIC);
        $bytes = pack('V2', count($buckets), 0);
        foreach ($buckets as $high => $bucket) {
            $bytes .= pack('V', $high) . $bucket->toPortableBytes();
        }

        return $bytes;
    }

    public static function decode(string $bytes): PhpEngine64
    {
        $length = strlen($bytes);
        if ($length < 8) {
            throw new UnexpectedValueException('Truncated 64-bit Roaring bucket count.');
        }
        $count = unpack('Vlow/Vhigh', $bytes);
        if ($count['high'] !== 0 || $count['low'] > intdiv($length - 8, 12)) {
            throw new UnexpectedValueException('Invalid or truncated 64-bit Roaring bucket table.');
        }
        $position = 8;
        $previous = -1;
        $buckets = [];
        for ($i = 0; $i < $count['low']; ++$i) {
            if ($length - $position < 4) {
                throw new UnexpectedValueException('Truncated 64-bit Roaring bucket key.');
            }
            $high = unpack('V', $bytes, $position)[1];
            $position += 4;
            if ($high <= $previous) {
                throw new UnexpectedValueException('64-bit Roaring bucket keys must be strictly increasing.');
            }
            $previous = $high;
            $bucket = PortableCodec::decodeFrom($bytes, $position);
            // Some map-based writers retain empty buckets after removals.
            // They are valid embedded 32-bit bitmaps; normalize them away.
            if ($bucket->count() !== 0) {
                $buckets[$high] = $bucket;
            }
        }
        if ($position !== $length) {
            throw new UnexpectedValueException('Trailing bytes after the 64-bit Roaring bitmap.');
        }

        return new PhpEngine64($buckets);
    }
}
