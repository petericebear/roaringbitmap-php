<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

use InvalidArgumentException;
use OverflowException;

/** @internal Exact uint64 arithmetic using PHP ints as signed two's-complement bit patterns. */
final class UInt64
{
    public const MAX = '18446744073709551615';
    public const END = '18446744073709551616';
    private const BASE = 4294967296;
    private const MASK = 4294967295;

    public static function parse(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        $decimal = self::canonical($value, self::MAX);
        $high = $low = 0;
        for ($i = 0, $length = strlen($decimal); $i < $length; ++$i) {
            $next = $low * 10 + ord($decimal[$i]) - 48;
            $high = $high * 10 + intdiv($next, self::BASE);
            $low = $next & self::MASK;
        }

        return self::fromParts($high, $low);
    }

    /** null denotes the exclusive upper endpoint 2^64. */
    public static function endpoint(mixed $value): ?int
    {
        $decimal = self::canonical($value, self::END);

        return $decimal === self::END ? null : self::parse($decimal);
    }

    private static function canonical(mixed $value, string $maximum): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Unsigned 64-bit values must be nonnegative integers or decimal digit strings.');
        }
        $value = ltrim($value, '0');
        $value = $value === '' ? '0' : $value;
        if (strlen($value) > 20 || (strlen($value) === 20 && strcmp($value, $maximum) > 0)) {
            throw new InvalidArgumentException('Unsigned 64-bit value is out of range.');
        }

        return $value;
    }

    public static function decimal(int $bits): string
    {
        if ($bits >= 0) {
            return (string) $bits;
        }
        $high = self::high($bits);
        $low = self::low($bits);
        $result = '';
        // Every intermediate stays below 10 * 2^32, safely within PHP's int range.
        do {
            $part = ($high % 10) * self::BASE + $low;
            $high = intdiv($high, 10);
            $low = intdiv($part, 10);
            $result = ($part % 10) . $result;
        } while ($high !== 0 || $low !== 0);

        return $result;
    }

    public static function high(int $bits): int
    {
        return ($bits >> 32) & self::MASK;
    }

    public static function low(int $bits): int
    {
        return $bits & self::MASK;
    }

    public static function fromParts(int $high, int $low): int
    {
        return ($high << 32) | $low;
    }

    public static function compare(int $a, int $b): int
    {
        return ($a ^ PHP_INT_MIN) <=> ($b ^ PHP_INT_MIN);
    }

    public static function previous(int $bits): int
    {
        return $bits === PHP_INT_MIN ? PHP_INT_MAX : $bits - 1;
    }

    /** Range endpoints are inclusive. Reject sizes unsupported by Countable before allocating. */
    public static function rangeSize(int $start, int $end): int
    {
        if (self::compare($start, $end) > 0) {
            throw new InvalidArgumentException('Range start must not exceed its end.');
        }
        $low = self::low($end) - self::low($start);
        $high = self::high($end) - self::high($start) - (int) ($low < 0);
        $low &= self::MASK;
        if ($high >= 0x80000000 || ($high === 0x7fffffff && $low === self::MASK)) {
            throw new OverflowException('Bitmap cardinality cannot exceed PHP_INT_MAX.');
        }

        return self::fromParts($high, $low) + 1;
    }

    public static function checkedCount(int $count): int
    {
        if ($count < 0) {
            throw new OverflowException('Bitmap cardinality cannot exceed PHP_INT_MAX.');
        }

        return $count;
    }
}
