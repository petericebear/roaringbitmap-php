<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

/** @internal Upper 32-bit keys map to 32-bit Roaring bitmaps of the lower bits. */
final class PhpEngine64 implements Engine64
{
    private int $cardinality = 0;
    private ?PrefixIndex $index = null;

    /** @param array<int, PhpEngine> $buckets */
    public function __construct(private array $buckets = [])
    {
        foreach ($buckets as $bucket) {
            if ($bucket->count() > PHP_INT_MAX - $this->cardinality) {
                throw new \OverflowException('Bitmap cardinality cannot exceed PHP_INT_MAX.');
            }
            $this->cardinality += $bucket->count();
        }
    }

    public function __clone(): void
    {
        foreach ($this->buckets as $key => $bucket) {
            $this->buckets[$key] = clone $bucket;
        }
    }

    public function add(int $bits): bool
    {
        if ($this->cardinality === PHP_INT_MAX && !$this->contains($bits)) {
            throw new \OverflowException('Bitmap cardinality cannot exceed PHP_INT_MAX.');
        }
        $key = UInt64::high($bits);
        $bucket = $this->buckets[$key] ??= new PhpEngine();
        $added = $bucket->add(UInt64::low($bits));
        if ($added) {
            ++$this->cardinality;
            $this->index = null;
        }

        return $added;
    }

    public function addMany(array $bits): void
    {
        foreach ($bits as $value) {
            $this->add($value);
        }
    }

    public function addRangeClosed(int $start, int $end): void
    {
        $first = UInt64::high($start);
        $last = UInt64::high($end);
        $this->index = null;
        for ($key = $first; $key <= $last; ++$key) {
            $bucket = $this->buckets[$key] ??= new PhpEngine();
            $before = $bucket->count();
            $bucket->addRange($key === $first ? UInt64::low($start) : 0,
                $key === $last ? UInt64::low($end) + 1 : 4294967296);
            $this->cardinality += $bucket->count() - $before;
        }
    }

    public function remove(int $bits): bool
    {
        $key = UInt64::high($bits);
        if (!isset($this->buckets[$key])) {
            return false;
        }
        $removed = $this->buckets[$key]->remove(UInt64::low($bits));
        if ($removed) {
            --$this->cardinality;
            $this->index = null;
        }
        if ($this->buckets[$key]->count() === 0) {
            unset($this->buckets[$key]);
        }

        return $removed;
    }

    public function contains(int $bits): bool
    {
        return isset($this->buckets[UInt64::high($bits)])
            && $this->buckets[UInt64::high($bits)]->contains(UInt64::low($bits));
    }

    public function count(): int
    {
        return $this->cardinality;
    }

    public function rank(int $bits): int
    {
        $key = UInt64::high($bits);

        return $this->prefixIndex()->beforeKey($key)
            + (isset($this->buckets[$key]) ? $this->buckets[$key]->rank(UInt64::low($bits)) : 0);
    }

    public function select(int $index): ?int
    {
        $position = $this->prefixIndex()->locate($index);
        if ($position === null) {
            return null;
        }
        [$key, $local] = $position;

        return UInt64::fromParts($key, $this->buckets[$key]->select($local));
    }

    private function prefixIndex(): PrefixIndex
    {
        return $this->index ??= new PrefixIndex(array_map(static fn (PhpEngine $b): int => $b->count(), $this->buckets));
    }

    public function values(): \Traversable
    {
        ksort($this->buckets, SORT_NUMERIC);
        foreach ($this->buckets as $key => $bucket) {
            foreach ($bucket->values() as $low) {
                yield UInt64::fromParts($key, $low);
            }
        }
    }

    public function combine(Engine64 $other, string $operation): Engine64
    {
        if (!$other instanceof self) {
            $other = PortableCodec64::decode($other->toPortableBytes());
        }
        $buckets = [];
        foreach ($this->buckets as $key => $bucket) {
            if (isset($other->buckets[$key])) {
                $result = $bucket->combine($other->buckets[$key], $operation);
            } elseif ($operation === 'intersect') {
                continue;
            } else {
                $result = clone $bucket;
            }
            if ($result->count() !== 0) {
                $buckets[$key] = $result;
            }
        }
        if ($operation === 'union' || $operation === 'xor') {
            foreach ($other->buckets as $key => $bucket) {
                if (!isset($this->buckets[$key])) {
                    $buckets[$key] = clone $bucket;
                }
            }
        }

        return new self($buckets);
    }

    public function toPortableBytes(): string
    {
        return PortableCodec64::encode($this->buckets);
    }
}
