<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

/** @internal */
final class PhpEngine implements Engine
{
    private int $cardinality = 0;

    private ?PrefixIndex $index = null;

    /** @param array<int, Container> $containers */
    public function __construct(private array $containers = [])
    {
        foreach ($containers as $container) {
            $this->cardinality += $container->count();
        }
    }

    public function __clone(): void
    {
        foreach ($this->containers as $key => $container) {
            $this->containers[$key] = clone $container;
        }
    }

    public function add(int $value): bool
    {
        $key = $value >> 16;
        $container = $this->containers[$key] ??= Container::empty();
        $added = $container->add($value & 0xffff);
        $this->cardinality += (int) $added;
        if ($added) {
            $this->index = null;
        }

        return $added;
    }

    public function addMany(array $values): void
    {
        $groups = [];
        foreach ($values as $value) {
            $groups[$value >> 16][] = $value & 0xffff;
        }
        foreach ($groups as $key => $lows) {
            $container = $this->containers[$key] ??= Container::empty();
            $before = $container->count();
            $container->addMany($lows);
            $added = $container->count() - $before;
            $this->cardinality += $added;
            if ($added !== 0) {
                $this->index = null;
            }
        }
    }

    public function addRange(int $start, int $end): void
    {
        if ($start < $end) {
            $this->index = null;
        }
        while ($start < $end) {
            $key = $start >> 16;
            $next = min($end, ($key + 1) << 16);
            $container = $this->containers[$key] ??= Container::empty();
            $before = $container->count();
            $container->addRange($start & 0xffff, $next - ($key << 16));
            $this->cardinality += $container->count() - $before;
            $start = $next;
        }
    }

    public function remove(int $value): bool
    {
        $key = $value >> 16;
        if (!isset($this->containers[$key])) {
            return false;
        }
        $removed = $this->containers[$key]->remove($value & 0xffff);
        $this->cardinality -= (int) $removed;
        if ($removed) {
            $this->index = null;
        }
        if ($this->containers[$key]->count() === 0) {
            unset($this->containers[$key]);
        }

        return $removed;
    }

    public function contains(int $value): bool
    {
        return isset($this->containers[$value >> 16])
            && $this->containers[$value >> 16]->contains($value & 0xffff);
    }

    public function count(): int
    {
        return $this->cardinality;
    }

    public function rank(int $value): int
    {
        $key = $value >> 16;

        return $this->prefixIndex()->beforeKey($key)
            + (isset($this->containers[$key]) ? $this->containers[$key]->rank($value & 0xffff) : 0);
    }

    public function select(int $index): ?int
    {
        $position = $this->prefixIndex()->locate($index);
        if ($position === null) {
            return null;
        }
        [$key, $local] = $position;

        return ($key << 16) | $this->containers[$key]->select($local);
    }

    private function prefixIndex(): PrefixIndex
    {
        return $this->index ??= new PrefixIndex(array_map(static fn (Container $c): int => $c->count(), $this->containers));
    }

    public function values(): \Traversable
    {
        ksort($this->containers, SORT_NUMERIC);
        foreach ($this->containers as $key => $container) {
            foreach ($container->values() as $low) {
                yield ($key << 16) | $low;
            }
        }
    }

    public function combine(Engine $other, string $operation): Engine
    {
        if (!$other instanceof self) {
            $other = PortableCodec::decode($other->toPortableBytes());
        }
        $containers = [];
        foreach ($this->containers as $key => $container) {
            if (isset($other->containers[$key])) {
                $result = $container->combine($other->containers[$key], $operation);
            } elseif ($operation === 'intersect') {
                continue;
            } else {
                $result = clone $container;
            }
            if ($result->count() !== 0) {
                $containers[$key] = $result;
            }
        }
        if ($operation === 'union' || $operation === 'xor') {
            foreach ($other->containers as $key => $container) {
                if (!isset($this->containers[$key])) {
                    $containers[$key] = clone $container;
                }
            }
        }

        return new self($containers);
    }

    public function toPortableBytes(): string
    {
        return PortableCodec::encode($this->containers);
    }
}
