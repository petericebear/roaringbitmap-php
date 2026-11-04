<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

/** @internal IDs are uint64 bit patterns stored in signed PHP ints, never numeric floats. */
interface Engine64
{
    public function add(int $bits): bool;

    /** @param list<int> $bits */
    public function addMany(array $bits): void;

    /** The public facade has validated bounds and resulting cardinality. */
    public function addRangeClosed(int $start, int $end): void;

    public function remove(int $bits): bool;

    public function contains(int $bits): bool;

    public function count(): int;

    public function rank(int $bits): int;

    public function select(int $index): ?int;

    /** @return \Traversable<int, int> */
    public function values(): \Traversable;

    public function combine(Engine64 $other, string $operation): Engine64;

    public function toPortableBytes(): string;
}
