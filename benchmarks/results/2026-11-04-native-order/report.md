# Native 64-bit rank/select: before and after

Measured November 4, 2026 on the same Apple M4 Pro Mac with Laravel Herd PHP 8.4.27 and CRoaring 5.4.0. The native library was rebuilt locally with the package C index. The comparison uses fresh before/after runs at both sizes, not the previous day’s numbers.

**Result:** repeated sparse native rank/select queries are substantially faster with the C index. The benefit grows with the number of containers. Exact public decimal-string inputs and outputs remain included in the timings.

## Sparse 64-bit results

Times are median milliseconds for **1,000 queries**, from five fresh bitmaps per configuration. Speedup is the before median divided by the after median. All query results were checked exactly outside the timers.

| IDs | Query phase | Before | After | Speedup | After min–max |
| ---: | --- | ---: | ---: | ---: | ---: |
| 100,000 | Cold rank batch | 9.7743 | 1.7056 | 5.7× | 1.6546–1.7775 |
| 100,000 | Warm rank batch | 9.7353 | 1.3763 | 7.1× | 1.3235–1.4135 |
| 100,000 | Warm select batch | 10.0468 | 1.5374 | 6.5× | 1.4594–1.5697 |
| 1,000,000 | Cold rank batch | 83.7847 | 3.4797 | 24.1× | 3.3878–3.8400 |
| 1,000,000 | Warm rank batch | 83.8040 | 1.4757 | 56.8× | 1.3762–1.5379 |
| 1,000,000 | Warm select batch | 84.1763 | 1.6119 | 52.2× | 1.5545–1.7803 |

The cold batch includes the first 15 direct queries and construction of the index on query 16. Warm rank repeats the probes; select follows a separate warmup batch. At one million IDs, sparse warm rank is about 1.48 microseconds per public call and select about 1.61 microseconds, including exact unsigned-ID conversion.

## Construction, mutation, and memory

| IDs | Build before | Build after | First rank after remove, before | First rank after remove, after |
| ---: | ---: | ---: | ---: | ---: |
| 100,000 | 109.9434 ms | 114.2575 ms | 0.0112 ms | 0.0116 ms |
| 1,000,000 | 1119.6872 ms | 1156.5157 ms | 0.0855 ms | 0.0885 ms |

The index is built during repeated queries, not bitmap construction. Every native write releases it before touching the bitmap. The first 15 subsequent order queries use CRoaring directly, avoiding an immediate rebuild for workloads that alternate one write with one query. The single-query measurements above are short and include normal timing noise.

A separate probe repeated a fixed midpoint rank query on five fresh bitmaps per size:

| IDs | Containers | Index allocation | Query 16, including index construction | Remove while indexed |
| ---: | ---: | ---: | ---: | ---: |
| 100,000 | 3,125 | 100,008 bytes | 0.0466 ms | 0.0032 ms |
| 1,000,000 | 31,250 | 1,000,008 bytes | 0.3639 ms | 0.0035 ms |

Allocation is measured through the C index API: 8 bytes plus 32 bytes per container on this ABI, approximately 98 KiB and 977 KiB in these workloads. It excludes allocator bookkeeping and is not full-process RSS. Container payloads and IDs are not duplicated. The allocation is freed on writes or destruction. [Raw cache lifecycle samples](cache-lifecycle.json) include all 17 query latencies per sample.

## Dense queries and controls

| IDs | Dense native query phase | Before | After | Change in time |
| ---: | --- | ---: | ---: | ---: |
| 100,000 | Warm rank batch | 1.3017 ms | 1.3866 ms | +6.5% |
| 100,000 | Warm select batch | 1.6193 ms | 1.7035 ms | +5.2% |
| 1,000,000 | Warm rank batch | 1.4423 ms | 1.5372 ms | +6.6% |
| 1,000,000 | Warm select batch | 1.8562 ms | 1.8726 ms | +0.9% |

These dense cases have fewer than 64 containers and continue using the direct path. No dense-query speedup is claimed: warm rank was approximately 6–7% slower in the after runs. Unchanged PHP and 32-bit native control measurements also generally moved upward, so these sequential runs cannot separate small implementation overheads from machine drift. All control results and sample ranges are retained in the full reports. The sparse gains are much larger than those changes.

In the after runs, indexed native sparse queries also beat the PHP backend’s cached index: approximately 1.5–1.9× across warm rank/select at the two sizes. This applies to these workloads, not every distribution or application.

## Implementation and verification

The previous CRoaring rank/select functions scanned preceding containers. The addon builds sorted high-48-bit keys, cumulative cardinalities, and borrowed container pointers. Binary search finds one container; CRoaring’s array, bitset, or run routine answers the local query. The index uses O(C) memory and construction work and O(log C) container lookup. It activates after 16 reads on sets with at least 64 containers.

The build helper checksum-verifies the pinned upstream amalgamation and compiles it with `native/roaring-index.c`. PHP passes opaque pointers and checks addon ABI version 1. Standard CRoaring remains usable through the direct path. The addon accesses private CRoaring details only within the same compiled source unit, so upgrading the pinned release requires reviewing and testing this integration.

- Herd PHP 8.2.35, 8.3.36, 8.4.27, and 8.5.12 each passed **51 tests / 2,891,501 assertions** with the optimized library and with stock CRoaring.
- Each version also passed **24 tests / 1,442,624 assertions** with FFI disabled.
- Regression coverage includes cache activation, selection as the first indexed operation, gaps, uint64 boundaries, arrays/bitsets/runs, mutations, clones and destruction order, snapshot iterators, serialization, mixed algebra, and cumulative counts above 2^32.
- The standalone C test passed AddressSanitizer and UndefinedBehaviorSanitizer checks. Composer validation, PHP lint, and shell syntax checks passed. Linux/macOS CI was extended, but hosted CI was not run in this local workspace.

## Measurement conditions and reproducibility

There are **160 workload samples** across before/after, two sizes, four workloads, two backends, and five repetitions, plus **10 cache lifecycle samples**. Samples ran sequentially in one CLI process per size/version, using fresh bitmaps and alternating backend order. No package tests or compilation ran concurrently with the timings. The Mac was an ordinary shared development machine without CPU pinning or thermal control.

Both revisions used `ffi.enable=1`, `opcache.enable_cli=0`, `opcache.jit=disable`, and `memory_limit=1G`. Input generation was excluded from construction timing. The sparse 64-bit inputs have 32 IDs per upper-32-bit bucket, all above PHP_INT_MAX, with deterministic CRC-derived jitter in the lower bits. Public queries use exact decimal strings. These timings do not measure HTTP/FPM latency or full-process memory.

The before and after harness snapshots differ only in source-fingerprint collection and explanatory text; the datasets, timers, query counts, warmups, and backend ordering are identical. Before/after source archives, raw samples, library hashes, addon hashes, and compiler information are retained alongside this report.

- [Before: 100,000 IDs](before-100k/report.md) · [raw JSON](before-100k/results.json)
- [After: 100,000 IDs](after-100k/report.md) · [raw JSON](after-100k/results.json)
- [Before: 1,000,000 IDs](before-1m/report.md) · [raw JSON](before-1m/results.json)
- [After: 1,000,000 IDs](after-1m/report.md) · [raw JSON](after-1m/results.json)
- [Build/verification manifest](manifest.json)
- [Before source archive](before-source.tar.gz) · [After source archive](after-source.tar.gz)

To rerun the optimized implementation from the package root:

```sh
bash tools/build-native.sh
ROARING_LIBRARY="$PWD/.build/croaring/libroaring.dylib" ROARING_REQUIRE_NATIVE=1 ROARING_REQUIRE_ORDER_INDEX=1 php -d ffi.enable=1 tests/run.php
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=100000 --queries=1000 --samples=5 --output=benchmarks/results/optimized-100k-new
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=1000000 --queries=1000 --samples=5 --output=benchmarks/results/optimized-1m-new
```

For a same-source stock-library comparison, run `bash tools/build-native.sh --stock` and pass `--library=.build/croaring/libroaring-stock.dylib`. On Linux use `.so`. To reproduce the exact old PHP implementation, extract `before-source.tar.gz` into a separate directory, run `composer install` there, and run its preserved `benchmarks/order.php` against the stock library using an absolute path. Always choose new output directories.
