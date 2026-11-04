<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

use FFI;
use FFI\CData;
use RuntimeException;

/** @internal Owns exactly one native bitmap. */
final class NativeEngine implements Engine
{
    private ?CData $bitmap = null;

    public function __construct(private FFI $ffi)
    {
        $this->bitmap = self::check($ffi->roaring_bitmap_create_with_capacity(0));
    }

    public function __destruct()
    {
        if ($this->bitmap !== null) {
            $this->ffi->roaring_bitmap_free($this->bitmap);
        }
    }

    public function __clone(): void
    {
        $original = $this->bitmap;
        // A failed allocation must not make the clone free the original pointer.
        $this->bitmap = null;
        $this->bitmap = self::check($this->ffi->roaring_bitmap_copy($original));
    }

    /** Only call after PortableCodec has validated the input, or with our own serialized data. */
    public static function fromValidatedBytes(FFI $ffi, string $bytes): self
    {
        $result = new self($ffi);
        $pointer = self::check($ffi->roaring_bitmap_portable_deserialize_safe($bytes, strlen($bytes)));
        $ffi->roaring_bitmap_free($result->bitmap);
        $result->bitmap = $pointer;
        if (!$ffi->roaring_bitmap_internal_validate($result->bitmap, null)) {
            // The portable spec permits disjoint adjacent runs, while CRoaring
            // requires them to be merged. Normalize through our validated codec.
            $normalized = PortableCodec::decode($bytes)->toPortableBytes();
            $pointer = self::check($ffi->roaring_bitmap_portable_deserialize_safe($normalized, strlen($normalized)));
            $ffi->roaring_bitmap_free($result->bitmap);
            $result->bitmap = $pointer;
            if (!$ffi->roaring_bitmap_internal_validate($result->bitmap, null)) {
                throw new RuntimeException('CRoaring rejected the normalized bitmap.');
            }
        }

        return $result;
    }

    public function add(int $value): bool
    {
        return $this->ffi->roaring_bitmap_add_checked($this->bitmap, $value);
    }

    public function addMany(array $values): void
    {
        $count = count($values);
        if ($count === 0) {
            return;
        }
        $buffer = $this->ffi->new("uint32_t[$count]");
        foreach ($values as $i => $value) {
            $buffer[$i] = $value;
        }
        $this->ffi->roaring_bitmap_add_many($this->bitmap, $count, $buffer);
    }

    public function addRange(int $start, int $end): void
    {
        if ($start < $end) {
            $this->ffi->roaring_bitmap_add_range_closed($this->bitmap, $start, $end - 1);
        }
    }

    public function remove(int $value): bool
    {
        return $this->ffi->roaring_bitmap_remove_checked($this->bitmap, $value);
    }

    public function contains(int $value): bool
    {
        return $this->ffi->roaring_bitmap_contains($this->bitmap, $value);
    }

    public function count(): int
    {
        return $this->ffi->roaring_bitmap_get_cardinality($this->bitmap);
    }

    public function rank(int $value): int
    {
        return $this->ffi->roaring_bitmap_rank($this->bitmap, $value);
    }

    public function select(int $index): ?int
    {
        if ($index >= $this->count()) {
            return null;
        }
        $value = $this->ffi->new('uint32_t[1]');

        return $this->ffi->roaring_bitmap_select($this->bitmap, $index, $value) ? $value[0] : null;
    }

    public function values(): \Traversable
    {
        $count = $this->count();
        $buffer = $this->ffi->new('uint32_t[1024]');
        for ($offset = 0; $offset < $count; $offset += 1024) {
            $limit = min(1024, $count - $offset);
            $this->ffi->roaring_bitmap_range_uint32_array($this->bitmap, $offset, $limit, $buffer);
            for ($i = 0; $i < $limit; ++$i) {
                yield $buffer[$i];
            }
        }
    }

    public function combine(Engine $other, string $operation): Engine
    {
        if (!$other instanceof self || $other->ffi !== $this->ffi) {
            $other = self::fromValidatedBytes($this->ffi, $other->toPortableBytes());
        }
        $function = match ($operation) {
            'union' => 'roaring_bitmap_or',
            'intersect' => 'roaring_bitmap_and',
            'difference' => 'roaring_bitmap_andnot',
            'xor' => 'roaring_bitmap_xor',
        };
        $result = new self($this->ffi);
        $pointer = self::check($this->ffi->$function($this->bitmap, $other->bitmap));
        $this->ffi->roaring_bitmap_free($result->bitmap);
        $result->bitmap = $pointer;

        return $result;
    }

    public function toPortableBytes(): string
    {
        $size = $this->ffi->roaring_bitmap_portable_size_in_bytes($this->bitmap);
        $buffer = $this->ffi->new("char[$size]");
        $written = $this->ffi->roaring_bitmap_portable_serialize($this->bitmap, $buffer);
        if ($written !== $size) {
            throw new RuntimeException('CRoaring returned an unexpected serialized size.');
        }

        return FFI::string($buffer, $size);
    }

    private static function check(?CData $pointer): CData
    {
        if ($pointer === null || FFI::isNull($pointer)) {
            throw new RuntimeException('CRoaring could not allocate or deserialize a bitmap.');
        }

        return $pointer;
    }
}
