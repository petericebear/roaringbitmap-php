/* Package code: MIT. Included CRoaring amalgamation: Apache-2.0.
 * Compile ONLY against the checksum-pinned CRoaring version in build-native.sh.
 * Internal container access stays in this translation unit; PHP sees opaque pointers.
 */
#include "roaring.c"

typedef struct {
    uint64_t key;                 /* Unsigned high 48 bits. */
    uint64_t total;               /* Cumulative cardinality through this entry. */
    const container_t *container; /* Borrowed: invalidate BEFORE any bitmap write. */
    uint8_t typecode;
} php_roaring64_order_entry;

typedef struct php_roaring64_order_index_s {
    size_t size;
    php_roaring64_order_entry entries[];
} php_roaring64_order_index;

uint32_t php_roaring64_order_index_abi(void) { return 1; }

/* NULL means the bitmap is too small or the optional cache could not allocate.
 * No bitmap state changes. The owner must outlive the index and remain unmodified.
 */
php_roaring64_order_index *php_roaring64_order_index_create(
    const roaring64_bitmap_t *bitmap, size_t minimum_containers) {
    size_t size = 0;
    art_iterator_t it = art_init_iterator((art_t *)&bitmap->art, true);
    while (it.value != NULL) {
        ++size;
        art_iterator_next(&it);
    }
    if (size == 0 || size < minimum_containers ||
        size > (SIZE_MAX - sizeof(php_roaring64_order_index)) /
                   sizeof(php_roaring64_order_entry)) {
        return NULL;
    }
    php_roaring64_order_index *index = roaring_malloc(
        sizeof(*index) + size * sizeof(php_roaring64_order_entry));
    if (index == NULL) return NULL;
    index->size = size;
    uint64_t total = 0;
    it = art_init_iterator((art_t *)&bitmap->art, true);
    for (size_t i = 0; i < size; ++i, art_iterator_next(&it)) {
        leaf_t leaf = (leaf_t)*it.value;
        const container_t *container = get_container(bitmap, leaf);
        uint8_t typecode = get_typecode(leaf);
        uint64_t count = container_get_cardinality(container, typecode);
        if (count > UINT64_MAX - total) {
            roaring_free(index);
            return NULL;
        }
        index->entries[i] = (php_roaring64_order_entry){
            combine_key(it.key, 0) >> 16, total += count, container, typecode};
    }
    return index;
}

void php_roaring64_order_index_free(php_roaring64_order_index *index) {
    roaring_free(index);
}

size_t php_roaring64_order_index_bytes(const php_roaring64_order_index *index) {
    return sizeof(*index) + index->size * sizeof(php_roaring64_order_entry);
}

uint64_t php_roaring64_order_index_rank(
    const php_roaring64_order_index *index, uint64_t value) {
    uint64_t key = value >> 16;
    size_t low = 0, high = index->size;
    while (low < high) {
        size_t mid = low + (high - low) / 2;
        if (index->entries[mid].key < key) low = mid + 1;
        else high = mid;
    }
    uint64_t before = low == 0 ? 0 : index->entries[low - 1].total;
    if (low == index->size || index->entries[low].key != key) return before;
    const php_roaring64_order_entry *entry = &index->entries[low];
    return before + container_rank(entry->container, entry->typecode, (uint16_t)value);
}

bool php_roaring64_order_index_select(const php_roaring64_order_index *index,
                                     uint64_t rank, uint64_t *value) {
    size_t low = 0, high = index->size;
    while (low < high) {
        size_t mid = low + (high - low) / 2;
        if (index->entries[mid].total <= rank) low = mid + 1;
        else high = mid;
    }
    if (low == index->size) return false;
    uint64_t before = low == 0 ? 0 : index->entries[low - 1].total;
    const php_roaring64_order_entry *entry = &index->entries[low];
    uint32_t start = 0, selected = 0;
    if (!container_select(entry->container, entry->typecode, &start,
                          (uint32_t)(rank - before), &selected)) return false;
    *value = (entry->key << 16) | selected;
    return true;
}
