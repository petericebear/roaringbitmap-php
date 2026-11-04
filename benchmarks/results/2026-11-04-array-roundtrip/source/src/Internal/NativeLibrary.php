<?php

declare(strict_types=1);

namespace RoaringBitmap\Internal;

use FFI;
use RoaringBitmap\Exception\NativeBackendUnavailable;

/** @internal Only opaque pointers cross FFI; the optional package index has its own ABI. */
final class NativeLibrary
{
    private const DECLARATIONS = <<<'C'
        typedef struct roaring_bitmap_s roaring_bitmap_t;
        roaring_bitmap_t *roaring_bitmap_create_with_capacity(uint32_t cap);
        void roaring_bitmap_free(roaring_bitmap_t *r);
        roaring_bitmap_t *roaring_bitmap_copy(const roaring_bitmap_t *r);
        bool roaring_bitmap_add_checked(roaring_bitmap_t *r, uint32_t value);
        void roaring_bitmap_add_many(roaring_bitmap_t *r, size_t count, const uint32_t *values);
        void roaring_bitmap_add_range_closed(roaring_bitmap_t *r, uint32_t min, uint32_t max);
        bool roaring_bitmap_remove_checked(roaring_bitmap_t *r, uint32_t value);
        bool roaring_bitmap_contains(const roaring_bitmap_t *r, uint32_t value);
        uint64_t roaring_bitmap_get_cardinality(const roaring_bitmap_t *r);
        uint64_t roaring_bitmap_rank(const roaring_bitmap_t *r, uint32_t value);
        bool roaring_bitmap_select(const roaring_bitmap_t *r, uint32_t index, uint32_t *value);
        bool roaring_bitmap_range_uint32_array(const roaring_bitmap_t *r, size_t offset, size_t limit, uint32_t *values);
        roaring_bitmap_t *roaring_bitmap_or(const roaring_bitmap_t *a, const roaring_bitmap_t *b);
        roaring_bitmap_t *roaring_bitmap_and(const roaring_bitmap_t *a, const roaring_bitmap_t *b);
        roaring_bitmap_t *roaring_bitmap_andnot(const roaring_bitmap_t *a, const roaring_bitmap_t *b);
        roaring_bitmap_t *roaring_bitmap_xor(const roaring_bitmap_t *a, const roaring_bitmap_t *b);
        size_t roaring_bitmap_portable_size_in_bytes(const roaring_bitmap_t *r);
        size_t roaring_bitmap_portable_serialize(const roaring_bitmap_t *r, char *buffer);
        roaring_bitmap_t *roaring_bitmap_portable_deserialize_safe(const char *buffer, size_t length);
        bool roaring_bitmap_internal_validate(const roaring_bitmap_t *r, const char **reason);
        C;

    private const DECLARATIONS64 = <<<'C'
        typedef struct roaring64_bitmap_s roaring64_bitmap_t;
        typedef struct roaring64_iterator_s roaring64_iterator_t;
        roaring64_bitmap_t *roaring64_bitmap_create(void);
        void roaring64_bitmap_free(roaring64_bitmap_t *r);
        roaring64_bitmap_t *roaring64_bitmap_copy(const roaring64_bitmap_t *r);
        bool roaring64_bitmap_add_checked(roaring64_bitmap_t *r, uint64_t value);
        void roaring64_bitmap_add_many(roaring64_bitmap_t *r, size_t count, const uint64_t *values);
        void roaring64_bitmap_add_range_closed(roaring64_bitmap_t *r, uint64_t min, uint64_t max);
        bool roaring64_bitmap_remove_checked(roaring64_bitmap_t *r, uint64_t value);
        bool roaring64_bitmap_contains(const roaring64_bitmap_t *r, uint64_t value);
        uint64_t roaring64_bitmap_get_cardinality(const roaring64_bitmap_t *r);
        uint64_t roaring64_bitmap_rank(const roaring64_bitmap_t *r, uint64_t value);
        bool roaring64_bitmap_select(const roaring64_bitmap_t *r, uint64_t index, uint64_t *value);
        roaring64_bitmap_t *roaring64_bitmap_or(const roaring64_bitmap_t *a, const roaring64_bitmap_t *b);
        roaring64_bitmap_t *roaring64_bitmap_and(const roaring64_bitmap_t *a, const roaring64_bitmap_t *b);
        roaring64_bitmap_t *roaring64_bitmap_andnot(const roaring64_bitmap_t *a, const roaring64_bitmap_t *b);
        roaring64_bitmap_t *roaring64_bitmap_xor(const roaring64_bitmap_t *a, const roaring64_bitmap_t *b);
        size_t roaring64_bitmap_portable_size_in_bytes(const roaring64_bitmap_t *r);
        size_t roaring64_bitmap_portable_serialize(const roaring64_bitmap_t *r, char *buffer);
        roaring64_bitmap_t *roaring64_bitmap_portable_deserialize_safe(const char *buffer, size_t length);
        bool roaring64_bitmap_internal_validate(const roaring64_bitmap_t *r, const char **reason);
        roaring64_iterator_t *roaring64_iterator_create(const roaring64_bitmap_t *r);
        void roaring64_iterator_free(roaring64_iterator_t *it);
        uint64_t roaring64_iterator_read(roaring64_iterator_t *it, uint64_t *buffer, uint64_t count);
        C;

    private const ORDER_INDEX = <<<'C'
        typedef struct php_roaring64_order_index_s php_roaring64_order_index;
        uint32_t php_roaring64_order_index_abi(void);
        php_roaring64_order_index *php_roaring64_order_index_create(const roaring64_bitmap_t *r, size_t minimum_containers);
        void php_roaring64_order_index_free(php_roaring64_order_index *index);
        size_t php_roaring64_order_index_bytes(const php_roaring64_order_index *index);
        uint64_t php_roaring64_order_index_rank(const php_roaring64_order_index *index, uint64_t value);
        bool php_roaring64_order_index_select(const php_roaring64_order_index *index, uint64_t rank, uint64_t *value);
        C;

    /** @var array<int, bool> FFI objects remain alive in $loaded. */
    private static array $orderIndex = [];

    public static function supportsOrderIndex(FFI $ffi): bool
    {
        return self::$orderIndex[spl_object_id($ffi)] ?? false;
    }

    /** @var array<string, FFI> */
    private static array $loaded = [];

    /** @var array<string, NativeBackendUnavailable> */
    private static array $failures = [];

    public static function load(?string $path = null, bool $wide = false): FFI
    {
        if (!extension_loaded('ffi')) {
            throw new NativeBackendUnavailable('Native mode requires the PHP FFI extension and CRoaring.');
        }
        $path ??= getenv('ROARING_LIBRARY') ?: null;
        $candidates = $path !== null ? [$path] : match (PHP_OS_FAMILY) {
            'Darwin' => ['libroaring.dylib', '/opt/homebrew/lib/libroaring.dylib', '/usr/local/lib/libroaring.dylib'],
            'Windows' => ['roaring.dll', 'libroaring.dll'],
            default => ['libroaring.so'],
        };
        $cacheKey = ($wide ? '64:' : '32:') . implode("\0", $candidates);
        if (isset(self::$loaded[$cacheKey])) {
            return self::$loaded[$cacheKey];
        }
        if (isset(self::$failures[$cacheKey])) {
            throw self::$failures[$cacheKey];
        }
        $lastError = null;
        foreach ($candidates as $candidate) {
            if ($wide) {
                try {
                    $ffi = FFI::cdef(self::DECLARATIONS . self::DECLARATIONS64 . self::ORDER_INDEX, $candidate);
                    if ($ffi->php_roaring64_order_index_abi() === 1) {
                        self::$orderIndex[spl_object_id($ffi)] = true;

                        return self::$loaded[$cacheKey] = $ffi;
                    }
                } catch (\Throwable) {
                    // Stock CRoaring remains supported without package-specific symbols.
                }
            }
            try {
                return self::$loaded[$cacheKey] = FFI::cdef(self::DECLARATIONS . ($wide ? self::DECLARATIONS64 : ''), $candidate);
            } catch (\Throwable $error) {
                $lastError = $error;
            }
        }
        throw self::$failures[$cacheKey] = new NativeBackendUnavailable(
            'Cannot load CRoaring. Enable FFI for this SAPI and set ROARING_LIBRARY to its shared library. '
                . ($lastError?->getMessage() ?? ''),
            previous: $lastError,
        );
    }
}
