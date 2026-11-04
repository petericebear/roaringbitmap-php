<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

/** @internal All inputs are validated by Bitmap. */
interface Engine
{
    public function add(int $value): bool;

    /** @param list<int> $values */
    public function addMany(array $values): void;

    public function addRange(int $start, int $end): void;

    public function remove(int $value): bool;

    public function contains(int $value): bool;

    public function count(): int;

    public function rank(int $value): int;

    public function select(int $index): ?int;

    /** @return \Traversable<int, int> */
    public function values(): \Traversable;

    /** $operation is union, intersect, difference, or xor. */
    public function combine(Engine $other, string $operation): Engine;

    public function toPortableBytes(): string;
}
