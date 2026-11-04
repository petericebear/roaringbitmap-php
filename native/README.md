# Optional native 64-bit order index

`bash tools/build-native.sh` compiles `roaring-index.c`, which includes the checksum-pinned CRoaring 5.4.0 amalgamation. It adds package-specific C symbols to the same library. Upstream downloads are unchanged; the addon is MIT-licensed and CRoaring remains Apache-2.0. Verified cached downloads are reused. `--stock` builds a separate unmodified `libroaring-stock` library for compatibility checks.

The addon must be compiled with that exact upstream version: it accesses internal ART/container helpers inside the same C translation unit. PHP sees only opaque pointers. `NativeLibrary` probes the addon ABI (currently 1) and all required symbols, falling back to stock CRoaring functions if they are absent or incompatible.

After 16 rank/select queries without a native write, a bitmap with at least 64 containers receives a sorted index of high-48-bit keys, cumulative counts, and borrowed container pointers/type codes. Binary search finds the container, then CRoaring's existing array/bitset/run rank/select routines answer the local query. Construction is O(C); indexed queries use O(log C) container lookup, plus the selected container's own query cost. On the tested 64-bit ABI, allocation is 8 + 32*C bytes, excluding allocator bookkeeping. No IDs or container payloads are duplicated.

`NativeEngine64` owns the index and frees it **before every native write**, including write attempts that ultimately leave membership unchanged. It also frees the index before the owning bitmap. Clones discard the shallow-copied index pointer without freeing the original's allocation, copy the bitmap, and build their own index lazily. Snapshot iterators therefore keep independent storage. Deserialized and algebra-result bitmaps start without an index. The select result buffer is reused within one owner and reset on cloning.

The first 15 order queries following construction or a write use the direct CRoaring path. This avoids allocating an index for occasional reads or repeated write/query pairs. Small sets and optional-cache allocation failures also use direct queries; the allocation attempt repeats only after another write. Index construction latency is included in cold-batch benchmarks.

Run the PHP suite with `ROARING_REQUIRE_ORDER_INDEX=1` to require the addon rather than silently testing stock mode. `tests/native-order.php` verifies activation, both unsigned halves, gaps, writes, clones, snapshots, persistence, mixed algebra, and cardinalities above 2^32. The standalone C test compares indexed answers to CRoaring and runs with AddressSanitizer and UndefinedBehaviorSanitizer:

```sh
cc -O1 -g -std=c11 -fsanitize=address,undefined -fno-omit-frame-pointer \
  -I.build/croaring tests/native-order.c -o .build/native-order-sanitized
.build/native-order-sanitized
```
