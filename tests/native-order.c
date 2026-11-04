/* Standalone correctness + AddressSanitizer/UBSan checks for the optional C index. */
#include "../native/roaring-index.c"

static void verify(roaring64_bitmap_t *bitmap) {
    php_roaring64_order_index *index = php_roaring64_order_index_create(bitmap, 0);
    uint64_t count = roaring64_bitmap_get_cardinality(bitmap);
    if (count == 0) { assert(index == NULL); return; }
    assert(index != NULL);
    assert(php_roaring64_order_index_bytes(index) >= sizeof(*index));
    uint64_t probes[] = {0, 1, 65535, 65536, UINT32_MAX, (uint64_t)UINT32_MAX + 1,
                         INT64_MAX, (uint64_t)INT64_MAX + 1, UINT64_MAX - 1, UINT64_MAX};
    for (size_t i = 0; i < sizeof(probes) / sizeof(probes[0]); ++i) {
        assert(php_roaring64_order_index_rank(index, probes[i]) == roaring64_bitmap_rank(bitmap, probes[i]));
    }
    for (uint64_t i = 0; i < 1000; ++i) {
        uint64_t position = (i * UINT64_C(982451653)) % count;
        uint64_t expected = 0, actual = 0;
        assert(roaring64_bitmap_select(bitmap, position, &expected));
        assert(php_roaring64_order_index_select(index, position, &actual));
        assert(expected == actual);
        assert(php_roaring64_order_index_rank(index, actual) == position + 1);
        if (actual != 0) assert(php_roaring64_order_index_rank(index, actual - 1) == position);
    }
    uint64_t value = 0;
    assert(!php_roaring64_order_index_select(index, count, &value));
    assert(!php_roaring64_order_index_select(index, UINT64_MAX, &value));
    php_roaring64_order_index_free(index);
}

int main(void) {
    roaring64_bitmap_t *bitmap = roaring64_bitmap_create();
    verify(bitmap);
    for (uint64_t i = 0; i < 300; ++i) {
        uint64_t base = (i << 32) | ((i & 1) << 63);
        roaring64_bitmap_add(bitmap, base);
        roaring64_bitmap_add(bitmap, base + 123);
        roaring64_bitmap_add(bitmap, base + 65535);
    }
    for (uint64_t i = 0; i < 6000; ++i) roaring64_bitmap_add(bitmap, 2 * i);
    roaring64_bitmap_add_range_closed(bitmap, UINT64_MAX - 100000, UINT64_MAX);
    verify(bitmap); // Arrays, bitsets, runs, both signed halves, and uint64 maximum.
    roaring64_bitmap_t *copy = roaring64_bitmap_copy(bitmap);
    roaring64_bitmap_free(bitmap);
    verify(copy);
    for (uint64_t i = 0; i < 300; ++i) {
        uint64_t base = (i << 32) | ((i & 1) << 63);
        roaring64_bitmap_remove_range_closed(copy, base, base + 65535);
    }
    verify(copy);
    roaring64_bitmap_free(copy);
    puts("Native order index sanitizer checks passed.");
    return 0;
}
