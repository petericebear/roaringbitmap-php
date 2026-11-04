<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

use FFI;
use FFI\CData;
use RuntimeException;

/** @internal uint64_t arguments/results use exact signed PHP bit patterns at the FFI boundary. */
final class NativeEngine64 implements Engine64
{
    private ?CData $bitmap = null;
    private ?int $cardinality = 0;
    private ?CData $orderIndex = null;
    private ?CData $selectBuffer = null;
    private int $orderQueries = 0;
    private bool $indexAttempted = false;
    private bool $supportsOrderIndex;

    public function __construct(private FFI $ffi)
    {
        $this->bitmap = self::check($ffi->roaring64_bitmap_create());
        $this->supportsOrderIndex = NativeLibrary::supportsOrderIndex($ffi);
    }

    public function __destruct()
    {
        $this->invalidateOrderIndex();
        if ($this->bitmap !== null) {
            $this->ffi->roaring64_bitmap_free($this->bitmap);
        }
    }

    public function __clone(): void
    {
        $original = $this->bitmap;
        $this->bitmap = null;
        // A shallow clone initially borrows the original owner's index. Do not free it.
        $this->orderIndex = null;
        $this->selectBuffer = null;
        $this->orderQueries = 0;
        $this->indexAttempted = false;
        $this->bitmap = self::check($this->ffi->roaring64_bitmap_copy($original));
    }

    /** Input must have passed PortableCodec64 validation, or be serialized by our engines. */
    public static function fromValidatedBytes(FFI $ffi, string $bytes): self
    {
        $result = new self($ffi);
        $pointer = self::check($ffi->roaring64_bitmap_portable_deserialize_safe($bytes, strlen($bytes)));
        $ffi->roaring64_bitmap_free($result->bitmap);
        $result->bitmap = $pointer;
        $result->cardinality = null;
        if (!$ffi->roaring64_bitmap_internal_validate($result->bitmap, null)) {
            $normalized = PortableCodec64::decode($bytes)->toPortableBytes();
            $pointer = self::check($ffi->roaring64_bitmap_portable_deserialize_safe($normalized, strlen($normalized)));
            $ffi->roaring64_bitmap_free($result->bitmap);
            $result->bitmap = $pointer;
            if (!$ffi->roaring64_bitmap_internal_validate($result->bitmap, null)) {
                throw new RuntimeException('CRoaring rejected the normalized 64-bit bitmap.');
            }
        }
        $result->count(); // Enforce the package's Countable limit before exposing the object.

        return $result;
    }

    public function add(int $bits): bool
    {
        if ($this->count() === PHP_INT_MAX && !$this->contains($bits)) {
            throw new \OverflowException('Bitmap cardinality cannot exceed PHP_INT_MAX.');
        }

        $this->invalidateOrderIndex();
        $added = $this->ffi->roaring64_bitmap_add_checked($this->bitmap, $bits);
        $this->cardinality += (int) $added;

        return $added;
    }

    public function addMany(array $bits): void
    {
        $count = count($bits);
        if ($count === 0) {
            return;
        }
        if ($count > PHP_INT_MAX - $this->count()) {
            foreach ($bits as $value) {
                $this->add($value);
            }

            return;
        }
        $buffer = $this->ffi->new("uint64_t[$count]");
        foreach ($bits as $index => $value) {
            $buffer[$index] = $value;
        }
        $this->invalidateOrderIndex();
        $this->ffi->roaring64_bitmap_add_many($this->bitmap, $count, $buffer);
        $this->cardinality = null;
    }

    public function addRangeClosed(int $start, int $end): void
    {
        $this->invalidateOrderIndex();
        $this->ffi->roaring64_bitmap_add_range_closed($this->bitmap, $start, $end);
        $this->cardinality = null;
    }

    public function remove(int $bits): bool
    {
        $this->invalidateOrderIndex();
        $removed = $this->ffi->roaring64_bitmap_remove_checked($this->bitmap, $bits);
        if ($this->cardinality !== null) {
            $this->cardinality -= (int) $removed;
        }

        return $removed;
    }

    public function contains(int $bits): bool
    {
        return $this->ffi->roaring64_bitmap_contains($this->bitmap, $bits);
    }

    public function count(): int
    {
        return $this->cardinality ??= UInt64::checkedCount($this->ffi->roaring64_bitmap_get_cardinality($this->bitmap));
    }

    public function rank(int $bits): int
    {
        if ($this->indexedOrder()) {
            return UInt64::checkedCount($this->ffi->php_roaring64_order_index_rank($this->orderIndex, $bits));
        }
        return UInt64::checkedCount($this->ffi->roaring64_bitmap_rank($this->bitmap, $bits));
    }

    public function select(int $index): ?int
    {
        if ($index >= $this->count()) {
            return null;
        }
        $buffer = $this->selectBuffer ??= $this->ffi->new('uint64_t[1]');
        if ($this->indexedOrder()) {
            return $this->ffi->php_roaring64_order_index_select($this->orderIndex, $index, $buffer) ? $buffer[0] : null;
        }

        return $this->ffi->roaring64_bitmap_select($this->bitmap, $index, $buffer) ? $buffer[0] : null;
    }

    private function indexedOrder(): bool
    {
        // Amortize construction over repeated reads; small sets stay on CRoaring's direct path.
        if (!$this->indexAttempted && $this->supportsOrderIndex && ++$this->orderQueries >= 16) {
            $this->indexAttempted = true;
            $pointer = $this->ffi->php_roaring64_order_index_create($this->bitmap, 64);
            if ($pointer !== null && !FFI::isNull($pointer)) {
                $this->orderIndex = $pointer;
            }
        }

        return $this->orderIndex !== null;
    }

    private function invalidateOrderIndex(): void
    {
        if ($this->orderIndex !== null) {
            $this->ffi->php_roaring64_order_index_free($this->orderIndex);
            $this->orderIndex = null;
        }
        $this->orderQueries = 0;
        $this->indexAttempted = false;
    }

    public function values(): \Traversable
    {
        $iterator = self::check($this->ffi->roaring64_iterator_create($this->bitmap));
        try {
            $buffer = $this->ffi->new('uint64_t[1024]');
            while (($count = $this->ffi->roaring64_iterator_read($iterator, $buffer, 1024)) > 0) {
                for ($i = 0; $i < $count; ++$i) {
                    yield $buffer[$i];
                }
            }
        } finally {
            $this->ffi->roaring64_iterator_free($iterator);
        }
    }

    public function combine(Engine64 $other, string $operation): Engine64
    {
        if (!$other instanceof self || $other->ffi !== $this->ffi) {
            $other = self::fromValidatedBytes($this->ffi, $other->toPortableBytes());
        }
        $function = match ($operation) {
            'union' => 'roaring64_bitmap_or',
            'intersect' => 'roaring64_bitmap_and',
            'difference' => 'roaring64_bitmap_andnot',
            'xor' => 'roaring64_bitmap_xor',
        };
        $result = new self($this->ffi);
        $pointer = self::check($this->ffi->$function($this->bitmap, $other->bitmap));
        $this->ffi->roaring64_bitmap_free($result->bitmap);
        $result->bitmap = $pointer;
        $result->cardinality = null;
        $result->count();

        return $result;
    }

    public function toPortableBytes(): string
    {
        $size = $this->ffi->roaring64_bitmap_portable_size_in_bytes($this->bitmap);
        $buffer = $this->ffi->new("char[$size]");
        if ($this->ffi->roaring64_bitmap_portable_serialize($this->bitmap, $buffer) !== $size) {
            throw new RuntimeException('CRoaring returned an unexpected 64-bit serialized size.');
        }

        return FFI::string($buffer, $size);
    }

    private static function check(?CData $pointer): CData
    {
        if ($pointer === null || FFI::isNull($pointer)) {
            throw new RuntimeException('CRoaring could not allocate or deserialize a 64-bit bitmap/iterator.');
        }

        return $pointer;
    }
}
