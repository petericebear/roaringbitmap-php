<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

/** @internal Immutable cumulative counts, rebuilt lazily after membership changes. */
final class PrefixIndex
{
    /** @var list<int> */
    private array $keys = [];

    /** @var list<int> */
    private array $totals = [];

    /** @param array<int, int> $counts Nonempty containers/buckets keyed by unsigned integers. */
    public function __construct(array $counts)
    {
        ksort($counts, SORT_NUMERIC);
        $total = 0;
        foreach ($counts as $key => $count) {
            if ($count > PHP_INT_MAX - $total) {
                throw new \OverflowException('Bitmap cardinality exceeds PHP_INT_MAX.');
            }
            $this->keys[] = $key;
            $this->totals[] = $total += $count;
        }
    }

    public function beforeKey(int $key): int
    {
        $low = 0;
        $high = count($this->keys);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($this->keys[$mid] < $key) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low === 0 ? 0 : $this->totals[$low - 1];
    }

    /** @return array{int, int}|null [container key, position within container] */
    public function locate(int $index): ?array
    {
        $low = 0;
        $high = count($this->totals);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($this->totals[$mid] <= $index) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low === count($this->keys) ? null
            : [$this->keys[$low], $index - ($low === 0 ? 0 : $this->totals[$low - 1])];
    }
}
