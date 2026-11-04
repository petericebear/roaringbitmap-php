<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

/** @internal A sorted packed uint16 array, or an 8192-byte bitset. */
final class Container
{
    /** @var list<int>|null Counts preceding each 256-byte block, built on first order query. */
    private ?array $bitsetPrefix = null;

    private function __construct(
        private string $data = '',
        private int $cardinality = 0,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /** The codec has already validated sortedness and cardinality. */
    public static function fromArrayBytes(string $data): self
    {
        return new self($data, intdiv(strlen($data), 2));
    }

    public static function fromBitset(string $data): self
    {
        $count = self::popcount($data);
        $container = new self($data, $count);
        if ($count <= 4096) {
            $container->data = self::packBitsetValues($data);
        }

        return $container;
    }

    public function count(): int
    {
        return $this->cardinality;
    }

    public function bytes(): string
    {
        return $this->data;
    }

    public function contains(int $value): bool
    {
        if ($this->cardinality > 4096) {
            return (ord($this->data[$value >> 3]) & (1 << ($value & 7))) !== 0;
        }

        $index = $this->lowerBound($value);

        return $index < $this->cardinality && $this->at($index) === $value;
    }

    public function rank(int $value): int
    {
        if ($this->cardinality <= 4096) {
            return $this->lowerBound($value + 1);
        }
        $byte = $value >> 3;
        $block = $byte >> 8;
        $prefix = $this->bitsetPrefix();
        $partial = ord($this->data[$byte]) & ((1 << (($value & 7) + 1)) - 1);

        return $prefix[$block] + self::popcount(substr($this->data, $block * 256, $byte & 255))
            + self::byteCounts()[$partial];
    }

    /** The caller supplies an in-bounds position. */
    public function select(int $index): int
    {
        if ($this->cardinality <= 4096) {
            return $this->at($index);
        }
        $prefix = $this->bitsetPrefix();
        $low = 0;
        $high = 32;
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($prefix[$mid + 1] <= $index) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        $index -= $prefix[$low];
        $counts = self::byteCounts();
        for ($i = $low * 256; $i < ($low + 1) * 256; ++$i) {
            $byte = ord($this->data[$i]);
            if ($index >= $counts[$byte]) {
                $index -= $counts[$byte];
                continue;
            }
            for ($bit = 0; $bit < 8; ++$bit) {
                if (($byte & (1 << $bit)) !== 0 && $index-- === 0) {
                    return ($i << 3) | $bit;
                }
            }
        }
        throw new \LogicException('Invalid select position for container.');
    }

    /** @return list<int> */
    private function bitsetPrefix(): array
    {
        if ($this->bitsetPrefix === null) {
            $this->bitsetPrefix = [0];
            for ($i = 0; $i < 32; ++$i) {
                $this->bitsetPrefix[] = $this->bitsetPrefix[$i] + self::popcount(substr($this->data, $i * 256, 256));
            }
        }

        return $this->bitsetPrefix;
    }

    public function add(int $value): bool
    {
        if ($this->cardinality > 4096) {
            $index = $value >> 3;
            $byte = ord($this->data[$index]);
            $updated = $byte | (1 << ($value & 7));
            if ($byte === $updated) {
                return false;
            }
            $this->data[$index] = chr($updated);
        } else {
            $index = $this->lowerBound($value);
            if ($index < $this->cardinality && $this->at($index) === $value) {
                return false;
            }
            $this->data = substr_replace($this->data, pack('v', $value), $index * 2, 0);
            if ($this->cardinality === 4096) {
                // Include the newly inserted value before changing the representation.
                $bits = str_repeat("\0", 8192);
                for ($i = 0; $i <= 4096; ++$i) {
                    $low = $this->at($i);
                    $offset = $low >> 3;
                    $bits[$offset] = chr(ord($bits[$offset]) | (1 << ($low & 7)));
                }
                $this->data = $bits;
            }
        }
        $this->bitsetPrefix = null;
        ++$this->cardinality;

        return true;
    }

    /** @param list<int> $values Validated low 16-bit values in any order. */
    public function addMany(array $values): void
    {
        if ($values === []) {
            return;
        }
        $before = $this->cardinality;
        if ($before <= 4096 && $before > count($values) * 8) {
            // Scattered batches may touch only a few IDs in an existing container.
            // Insert those directly instead of unpacking and sorting the whole set.
            foreach ($values as $value) {
                $this->add($value);
            }

            return;
        }
        if ($before > 4096) {
            foreach ($values as $value) {
                $index = $value >> 3;
                $byte = ord($this->data[$index]);
                $updated = $byte | (1 << ($value & 7));
                if ($byte !== $updated) {
                    $this->data[$index] = chr($updated);
                    ++$this->cardinality;
                }
            }
        } else {
            // Temporary hashes are bounded by one container plus one input batch.
            $unique = array_fill_keys($values, true);
            if ($before !== 0) {
                $unique += array_fill_keys(unpack('v*', $this->data), true);
            }
            if (count($unique) === $before) {
                return;
            }
            $sorted = array_keys($unique);
            sort($sorted, SORT_NUMERIC);
            $result = self::fromSortedValues($sorted);
            $this->data = $result->data;
            $this->cardinality = $result->cardinality;
        }
        if ($before !== $this->cardinality) {
            $this->bitsetPrefix = null;
        }
    }

    public function remove(int $value): bool
    {
        if ($this->cardinality > 4096) {
            $index = $value >> 3;
            $byte = ord($this->data[$index]);
            $updated = $byte & ~(1 << ($value & 7));
            if ($byte === $updated) {
                return false;
            }
            $this->data[$index] = chr($updated);
            if ($this->cardinality === 4097) {
                $this->data = self::packBitsetValues($this->data);
            }
        } else {
            $index = $this->lowerBound($value);
            if ($index === $this->cardinality || $this->at($index) !== $value) {
                return false;
            }
            $this->data = substr_replace($this->data, '', $index * 2, 2);
        }
        $this->bitsetPrefix = null;
        --$this->cardinality;

        return true;
    }

    public function addRange(int $start, int $end): void
    {
        if ($start === $end) {
            return;
        }
        $bits = $this->bitset();
        // Set partial boundary bytes, and fill the interior in one string operation.
        while ($start < $end && ($start & 7) !== 0) {
            $index = $start >> 3;
            $bits[$index] = chr(ord($bits[$index]) | (1 << ($start & 7)));
            ++$start;
        }
        $fullBytes = ($end >> 3) - ($start >> 3);
        if ($fullBytes > 0) {
            $bits = substr_replace($bits, str_repeat("\xff", $fullBytes), $start >> 3, $fullBytes);
            $start += $fullBytes * 8;
        }
        while ($start < $end) {
            $index = $start >> 3;
            $bits[$index] = chr(ord($bits[$index]) | (1 << ($start & 7)));
            ++$start;
        }
        $result = self::fromBitset($bits);
        $this->data = $result->data;
        $this->cardinality = $result->cardinality;
        $this->bitsetPrefix = null;
    }

    /** @return \Generator<int, int> */
    public function values(): \Generator
    {
        if ($this->cardinality <= 4096) {
            for ($i = 0; $i < $this->cardinality; ++$i) {
                yield $this->at($i);
            }
        } else {
            yield from self::bitsetValues($this->data);
        }
    }

    public function combine(self $other, string $operation): self
    {
        if ($this->cardinality === $other->cardinality && $this->data === $other->data) {
            return match ($operation) {
                'union', 'intersect' => clone $this,
                'difference', 'xor' => self::empty(),
            };
        }
        if ($this->cardinality <= 4096 && $other->cardinality <= 4096) {
            // Do the per-value work in PHP's C array functions, then repack.
            // Neither temporary hash survives the operation.
            $a = array_fill_keys(unpack('v*', $this->data), true);
            $b = array_fill_keys(unpack('v*', $other->data), true);
            $values = array_keys(match ($operation) {
                'union' => $a + $b,
                'intersect' => array_intersect_key($a, $b),
                'difference' => array_diff_key($a, $b),
                'xor' => array_diff_key($a, $b) + array_diff_key($b, $a),
            });
            if ($operation === 'union' || $operation === 'xor') {
                sort($values, SORT_NUMERIC);
            }

            return self::fromSortedValues($values);
        }

        $a = $this->bitset();
        $b = $other->bitset();

        return self::fromBitset(match ($operation) {
            'union' => $a | $b,
            'intersect' => $a & $b,
            'difference' => $a & ~$b,
            'xor' => $a ^ $b,
        });
    }

    /** @param list<int> $values Sorted, unique low 16-bit values. */
    private static function fromSortedValues(array $values): self
    {
        $count = count($values);
        if ($count <= 4096) {
            return new self(pack('v*', ...$values), $count);
        }
        $bits = str_repeat("\0", 8192);
        foreach ($values as $value) {
            $index = $value >> 3;
            $bits[$index] = chr(ord($bits[$index]) | (1 << ($value & 7)));
        }

        return new self($bits, $count);
    }

    private function bitset(): string
    {
        if ($this->cardinality > 4096) {
            return $this->data;
        }
        $bits = str_repeat("\0", 8192);
        foreach ($this->values() as $value) {
            $index = $value >> 3;
            $bits[$index] = chr(ord($bits[$index]) | (1 << ($value & 7)));
        }

        return $bits;
    }

    private function at(int $index): int
    {
        $offset = $index * 2;

        return ord($this->data[$offset]) | (ord($this->data[$offset + 1]) << 8);
    }

    private function lowerBound(int $value): int
    {
        $low = 0;
        $high = $this->cardinality;
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($this->at($mid) < $value) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    private static function popcount(string $bits): int
    {
        $counts = self::byteCounts();
        $total = 0;
        foreach (count_chars($bits, 1) as $byte => $frequency) {
            $total += $counts[$byte] * $frequency;
        }

        return $total;
    }

    /** @return list<int> */
    private static function byteCounts(): array
    {
        static $counts = null;
        if ($counts === null) {
            $counts = [0];
            for ($i = 1; $i < 256; ++$i) {
                $counts[$i] = $counts[$i >> 1] + ($i & 1);
            }
        }
        return $counts;
    }

    /** @return \Generator<int, int> */
    private static function bitsetValues(string $bits): \Generator
    {
        for ($i = 0; $i < 8192; ++$i) {
            $byte = ord($bits[$i]);
            for ($bit = 0; $byte !== 0; ++$bit, $byte >>= 1) {
                if (($byte & 1) !== 0) {
                    yield ($i << 3) | $bit;
                }
            }
        }
    }

    private static function packBitsetValues(string $bits): string
    {
        $data = '';
        foreach (self::bitsetValues($bits) as $value) {
            $data .= pack('v', $value);
        }

        return $data;
    }
}
