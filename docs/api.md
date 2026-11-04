# API and data formats

[Back to the package overview](../README.md)

Use `RoaringBitmap\Bitmap` for unsigned 32-bit IDs and `RoaringBitmap\Bitmap64` for unsigned 64-bit IDs. Both implement `Countable` and `IteratorAggregate` and expose the same set operations. Their constructors accept an iterable, a `Backend` choice (default `Backend::Auto`), and an optional native `libraryPath`.

| Class | ID range | IDs returned by iteration, `toArray()`, and `select()` |
| --- | --- | --- |
| `Bitmap` | `0` through `4,294,967,295` | PHP integers |
| `Bitmap64` | `0` through `18,446,744,073,709,551,615` | Decimal strings |

`select()` returns `null` for positions beyond the set. Counts and positions are PHP integers for both classes. See [native backend setup](native-backend.md) for library discovery and deployment.

## Mutation and iteration

```php
use RoaringBitmap\Bitmap;

$ids = new Bitmap([1, 2, 3]);
$ids->add(4);                 // true: newly added
$ids->remove(2);              // true: previously present
$ids->addRange(100, 200);      // Includes 100; excludes 200
$ids->addMany([500, 600]);
$ids->contains(500);          // true

foreach ($ids as $id) {       // Ascending snapshot of the set
    echo $id, PHP_EOL;
}
```

## Rank and select

```php
$bitmap = new Bitmap([10, 20, 40]);

$bitmap->rank(25);    // 2: number of IDs less than or equal to 25
$bitmap->rank(20);    // 2: the bound is inclusive
$bitmap->select(1);   // 20: positions start at zero
$bitmap->select(3);   // null: position is outside the set
```

`rank()` accepts the same ID range as the bitmap, whether or not the queried ID is present. `select()` accepts a nonnegative PHP integer position; negative positions throw `InvalidArgumentException`. For an existing position `k`, `rank(select(k)) === k + 1`. Both backends query their containers directly, without materializing `toArray()`.

The PHP backend builds cumulative container/bucket counts lazily and uses binary search for subsequent queries. Dense containers also cache counts for 256-byte blocks. Membership changes invalidate affected caches; clones and snapshots remain independent. The first query after construction or mutation can therefore cost more than repeated queries.

The bundled native build also indexes 64-bit container counts in C. After 16 rank/select queries without a write, sets with at least 64 containers use binary search followed by CRoaring's container query. The index adds about 32 bytes per container on a 64-bit ABI, without copying IDs. Native writes release it before changing the bitmap; the next 15 queries use the direct path before rebuilding. Small sets and stock CRoaring libraries use direct queries. See [native index design and ownership](../native/README.md).

## Unsigned 64-bit IDs

```php
use RoaringBitmap\Bitmap64;

$ids = new Bitmap64([
    42,
    '4294967296',
    '9223372036854775808',
    '18446744073709551615',
]);

$ids->contains('18446744073709551615'); // true
$ids->rank('9223372036854775808');      // 3
$ids->select(3);                       // '18446744073709551615'
$ids->toArray();                       // Every element is a decimal string

$ids->addRange('18446744073709551612', Bitmap64::END_EXCLUSIVE);
$ids->addRangeClosed('9223372036854775807', '9223372036854775809');

$restored = Bitmap64::fromPortableBytes($ids->toPortableBytes());
```

`Bitmap64` mirrors the mutation, query, set-algebra, iteration, cloning, and serialization API of `Bitmap`. Native/PHP selection uses the same `Backend` enum and optional `libraryPath`. Set operations can mix PHP and native `Bitmap64` objects, retaining the left operand's backend.

- **Input IDs:** nonnegative PHP integers or decimal digit strings. Leading zeroes are normalized. Floats, booleans, negative values, whitespace, signs, exponent notation, and out-of-range values are rejected, including in PHP files without `strict_types`. Quote large integer literals: PHP otherwise converts literals above `PHP_INT_MAX` to floats before the package sees them.
- **Output IDs:** `select()`, iteration, and `toArray()` always return canonical decimal strings, including small IDs. JSON therefore preserves their exact values. Conversion and comparison use integer bit patterns and 32-bit parts, with no floats, GMP, or BCMath dependency.
- **Ordering:** unsigned numeric order. IDs above `PHP_INT_MAX` follow smaller IDs.
- **Ranges:** `addRange(start, end)` is half-open. The string constant `Bitmap64::END_EXCLUSIVE` is `2^64`, allowing a range to include the maximum ID; this sentinel is only valid as a range endpoint. `addRangeClosed(start, end)` includes both endpoints. Empty ranges are allowed.
- **Counts and positions:** the number of stored IDs is limited to `PHP_INT_MAX`, even though each ID can use all 64 bits. `count()`, `cardinality()`, and `rank()` return PHP integers; `select()` takes a PHP integer position. Ranges or results exceeding the cardinality limit throw `OverflowException`; ranges are checked before allocation. Smaller but enormous ranges still need application-level memory/time limits.
- **Width conversion:** promote a 32-bit bitmap with `new Bitmap64($bitmap32)`. Set operations require operands of the same width; they do not implicitly mix `Bitmap` and `Bitmap64`.
- **Persistence:** `Bitmap64` uses the unsigned 64-bit extension of RoaringFormatSpec: an ordered table of upper-32-bit keys and embedded portable 32-bit bitmaps. Use `Bitmap64::fromPortableBytes()` to read it. Other 64-bit Roaring formats are not automatically interchangeable. Existing `Bitmap` bytes and APIs retain their 32-bit semantics.

The PHP implementation reuses 32-bit engines under the upper-bit keys. Native mode uses CRoaring's 64-bit API. Readers validate embedded offsets, sorted keys, payloads, and trailing bytes before native deserialization; valid empty embedded bitmaps are normalized away. PHP `serialize()` stores portable data and selects an available backend when restored, for both widths. Run `php examples/advanced.php` for a complete example.

## Behavior and format

The following table describes the 32-bit `Bitmap`; the 64-bit differences are documented above.

| Operation | Behavior |
| --- | --- |
| `add`, `remove` | Mutate the bitmap; return whether membership changed |
| `addMany` | Consumes an iterable in batches of 1,024; earlier completed batches remain if later input throws |
| `addRange(start, end)` | Adds `[start, end)`; `end` can be `4,294,967,296` |
| `union`, `intersect`, `difference`, `symmetricDifference` | Return an independent bitmap using the left operand's backend |
| `count`, `cardinality`, `isEmpty`, `contains`, `equals` | Query the set |
| `rank(value)` | Count IDs less than or equal to value |
| `select(index)` | ID at a zero-based position, or `null` when out of bounds |
| `clone` | Independent copy |
| Iteration | Sorted snapshot captured when `getIterator()` is called |
| `toArray` | Materializes the whole set as a PHP array |
| `toPortableBytes`, `fromPortableBytes` | Standard 32-bit Roaring portable bytes |
| PHP `serialize` / `unserialize` | Store portable bytes; select the backend automatically when restored |

The format reader supports array, bitset, and run containers. It checks lengths, offsets, sortedness, cardinalities, and trailing data before invoking native deserialization. Native objects are also checked with CRoaring's validator; adjacent runs are normalized when necessary.

PHP stores sparse containers as little-endian packed 16-bit values and promotes them to 8,192-byte bitsets above 4,096 entries. It demotes them when they become sparse again. Dense bitwise operations use PHP string operators. PHP writes array/bitset containers and expands imported run containers into those representations. Native mode can retain run compression. Both outputs describe the same sets and are interoperable, but their bytes and sizes can differ.

Bulk insertion groups each validated batch by container. Sparse bulk updates and set operations use temporary PHP hashes, bounded by the participating containers and input batch, then repack the result. Small updates use incremental insertion. Identical containers can be cloned for union/intersection or omitted for difference/XOR. Persistent storage remains packed arrays and bitsets.

Large ranges and imported runs can consume much more memory in PHP than in native mode. Iteration takes a snapshot, and `toArray()` adds the overhead of a PHP array. Deserialization performs PHP validation even when native mode is selected, so its cost includes a temporary PHP representation. Apply application-level resource limits when accepting large external bitmaps.
