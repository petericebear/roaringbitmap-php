<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

use UnexpectedValueException;

/** @internal Standard little-endian 32-bit Roaring portable format. */
final class PortableCodec
{
    /** @param array<int, Container> $containers */
    public static function encode(array $containers): string
    {
        ksort($containers, SORT_NUMERIC);
        $size = count($containers);
        $header = pack('V2', 12346, $size);
        $offsets = '';
        $payload = '';
        $offset = 8 + 8 * $size;
        foreach ($containers as $key => $container) {
            $header .= pack('v2', $key, $container->count() - 1);
            $offsets .= pack('V', $offset);
            $bytes = $container->bytes();
            $payload .= $bytes;
            $offset += strlen($bytes);
        }

        return $header . $offsets . $payload;
    }

    public static function decode(string $bytes): PhpEngine
    {
        $position = 0;
        $result = self::decodeFrom($bytes, $position);
        if ($position !== strlen($bytes)) {
            throw new UnexpectedValueException('Trailing bytes after the Roaring bitmap.');
        }

        return $result;
    }

    /** Parse one embedded bitmap and advance the absolute cursor, without copying the suffix. */
    public static function decodeFrom(string $bytes, int &$position): PhpEngine
    {
        $origin = $position;
        $length = strlen($bytes);
        if ($position < 0 || $position > $length) {
            throw new UnexpectedValueException('Invalid Roaring stream position.');
        }
        $read = static function (int $size) use ($bytes, $length, &$position): string {
            if ($size > $length - $position) {
                throw new UnexpectedValueException('Truncated Roaring portable data.');
            }
            $result = substr($bytes, $position, $size);
            $position += $size;

            return $result;
        };
        $cookie = unpack('V', $read(4))[1];
        $hasRuns = ($cookie & 0xffff) === 12347;
        if ($cookie === 12346) {
            $size = unpack('V', $read(4))[1];
            if ($size > 65536) {
                throw new UnexpectedValueException('Too many Roaring containers.');
            }
            $runFlags = '';
        } elseif ($hasRuns) {
            $size = ($cookie >> 16) + 1;
            $runFlags = $read(intdiv($size + 7, 8));
        } else {
            throw new UnexpectedValueException('Invalid Roaring portable cookie.');
        }

        $descriptions = $read(4 * $size);
        $hasOffsets = !$hasRuns || $size >= 4;
        $offsets = $hasOffsets ? $read(4 * $size) : '';
        $containers = [];
        $previousKey = -1;
        for ($i = 0; $i < $size; ++$i) {
            $description = unpack('vkey/vcount', $descriptions, 4 * $i);
            $key = $description['key'];
            $count = $description['count'] + 1;
            if ($key <= $previousKey) {
                throw new UnexpectedValueException('Container keys must be strictly increasing.');
            }
            $previousKey = $key;
            if ($hasOffsets && unpack('V', $offsets, 4 * $i)[1] !== $position - $origin) {
                throw new UnexpectedValueException('Invalid Roaring container offset.');
            }
            $isRun = $hasRuns && (ord($runFlags[$i >> 3]) & (1 << ($i & 7))) !== 0;
            if ($isRun) {
                $runCount = unpack('v', $read(2))[1];
                $runs = $read($runCount * 4);
                $bits = str_repeat("\0", 8192);
                $actualCount = 0;
                $previousEnd = -1;
                for ($j = 0; $j < $runCount; ++$j) {
                    $run = unpack('vstart/vlength', $runs, $j * 4);
                    $start = $run['start'];
                    $end = $start + $run['length'];
                    if ($start <= $previousEnd || $end > 65535) {
                        throw new UnexpectedValueException('Invalid, overlapping, or unsorted run.');
                    }
                    $previousEnd = $end;
                    $actualCount += $run['length'] + 1;
                    // Each byte is visited at most eight times over all disjoint runs.
                    for ($value = $start; $value <= $end;) {
                        $index = $value >> 3;
                        $last = min($end, ($index << 3) + 7);
                        $mask = ((1 << ($last - $value + 1)) - 1) << ($value & 7);
                        $bits[$index] = chr(ord($bits[$index]) | $mask);
                        $value = $last + 1;
                    }
                }
                if ($actualCount !== $count) {
                    throw new UnexpectedValueException('Run cardinality does not match its header.');
                }
                $container = Container::fromBitset($bits);
            } elseif ($count <= 4096) {
                $data = $read(2 * $count);
                $previous = -1;
                for ($j = 0; $j < $count; ++$j) {
                    $value = ord($data[2 * $j]) | (ord($data[2 * $j + 1]) << 8);
                    if ($value <= $previous) {
                        throw new UnexpectedValueException('Array values must be strictly increasing.');
                    }
                    $previous = $value;
                }
                $container = Container::fromArrayBytes($data);
            } else {
                $container = Container::fromBitset($read(8192));
                if ($container->count() !== $count) {
                    throw new UnexpectedValueException('Bitset cardinality does not match its header.');
                }
            }
            $containers[$key] = $container;
        }
        return new PhpEngine($containers);
    }
}
