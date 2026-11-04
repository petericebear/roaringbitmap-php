# Bulk loading and sparse set operations — November 4, 2026

This change improves construction and sparse algebra while preserving packed storage, the public API, streaming validation, and portable bytes. At one million IDs per input, PHP dense construction improved 2.06×, mixed sparse construction improved 3.27×, and the standard mixed sparse intersection improved 45.09×. Native bulk construction improved by 8–12% in these samples.

PHP arrays still construct dense and mixed sparse sets faster. With partial overlap inside every sparse container, the PHP backend improved 6.4–8.6× but remains slower than arrays; native mode is faster than arrays in that check. The workload matters.

## Method

Measured on macOS-26.7.1-arm64-arm-64bit-Mach-O with PHP 8.4.27 (Herd CLI). The CPU model was not exposed to the benchmark process. Before: 2026-11-04T10:33:03.349731+01:00 through 2026-11-04T10:34:19.721262+01:00; after: 2026-11-04T10:44:03.229940+01:00 through 2026-11-04T10:45:01.991164+01:00.

- Baseline source commit: `682a9e2` (before these implementation changes). Exact before/after sources are included alongside each run; raw JSON contains their SHA-256 hashes.
- Same main harness, datasets, three repetitions, 100,000 and 1,000,000 IDs/input, native binary, and PHP settings. Five workloads × two sizes × three backends × three repetitions = 90 isolated-process samples per revision (180 total).
- Construction creates both input sets and includes streamed ID generation. Queries use warmed batches and include result destruction. RSS is whole-process peak immediately after construction, including native allocations.
- CLI OPcache/JIT disabled, FFI enabled, memory limit 1 GiB. Native mode is explicitly required by the workers. Existing cardinality, membership, checksum, iteration, and persistence verification is retained outside timers.
- The supplementary partial-overlap test adds 27 samples per revision (54 total), using one process, prebuilt inputs, and three warmed intersections per configuration. It makes no construction or memory comparison.
- Shared laptop, no CPU pinning or controlled thermal state. Raw sample ranges are available below. Unchanged array controls also vary (for example shuffled construction by about 9%); small native gains should be interpreted with that variation in mind. These are CLI measurements, not HTTP latency or universal speedups.

## Construction and intersection at one million IDs per input

Times are medians in milliseconds. The improvement column compares the package revision before/after, not the package against arrays.

| Workload | Backend | Build before | Build after | Build improvement | Intersection before | Intersection after | Intersection improvement |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| dense | PHP array | 131.644 | 136.664 | 0.96× | 6.0682 | 6.1896 | 0.98× |
| dense | Package PHP | 945.471 | 459.169 | 2.06× | 0.8768 | 0.7841 | 1.12× |
| dense | Native | 260.183 | 232.303 | 1.12× | 0.0106 | 0.0109 | 0.97× |
| shuffled | PHP array | 178.827 | 195.028 | 0.92× | 10.1168 | 10.0953 | 1.00× |
| shuffled | Package PHP | 1051.294 | 901.518 | 1.17× | 0.8861 | 0.7739 | 1.14× |
| shuffled | Native | 317.326 | 289.834 | 1.09× | 0.0115 | 0.0117 | 0.99× |
| sparse | PHP array | 876.929 | 914.832 | 0.96× | 109.8268 | 104.9383 | 1.05× |
| sparse | Package PHP | 1323.343 | 334.196 | 3.96× | 126.2852 | 2.8515 | 44.29× |
| sparse | Native | 288.205 | 257.955 | 1.12× | 0.7292 | 0.7229 | 1.01× |
| sparse_mixed | PHP array | 299.799 | 301.107 | 1.00× | 9.6493 | 10.2439 | 0.94× |
| sparse_mixed | Package PHP | 1416.956 | 433.537 | 3.27× | 126.5668 | 2.8067 | 45.09× |
| sparse_mixed | Native | 377.534 | 348.380 | 1.08× | 0.7396 | 0.7435 | 0.99× |
| ranges | PHP array | 124.958 | 124.139 | 1.01× | 5.9813 | 6.1976 | 0.97× |
| ranges | Package PHP | 0.418 | 0.373 | 1.12× | 0.1534 | 0.0388 | 3.95× |
| ranges | Native | 0.010 | 0.007 | 1.40× | 0.0012 | 0.0013 | 0.96× |

## Memory and remaining costs

| Workload | Backend | Build peak RSS before (MiB) | Build peak RSS after (MiB) |
| --- | --- | ---: | ---: |
| dense | PHP array | 106.83 | 106.88 |
| dense | Package PHP | 28.53 | 29.48 |
| dense | Native | 28.75 | 28.92 |
| sparse_mixed | PHP array | 106.86 | 106.88 |
| sparse_mixed | Package PHP | 41.52 | 41.62 |
| sparse_mixed | Native | 34.23 | 34.53 |

The PHP implementation now uses temporary hashes for bounded container operations. They are discarded after repacking. Dense construction peak RSS increased by about 1 MiB here; persistent storage is still compact. Iteration, membership checks, and decoding retain their existing costs; full reports include all operations, not just the improved ones.

## Partial overlap inside every container

The main sparse datasets share complete containers in their overlapping region. Identical-container detection legitimately avoids recomputing those sets. This supplementary case measures the general sparse intersection path instead: 1,048,576 IDs/input, 32/256/4,096 IDs per container, different contents in every pair of containers, and roughly one-third overlap. A deterministic CRC-based rotation of low bits avoids concentrating the PHP array baseline in a small number of hash buckets. Correctness is checked by cardinality and every result ID outside the timers.

| IDs/container | PHP array after (ms) | Package PHP before (ms) | Package PHP after (ms) | PHP improvement | Native after (ms) |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 32 | 13.913 | 376.276 | 58.539 | 6.43× | 3.751 |
| 256 | 9.368 | 354.340 | 43.844 | 8.08× | 2.634 |
| 4,096 | 7.179 | 360.115 | 41.853 | 8.60× | 2.279 |

This case is now included in the automated CI artifact and README snapshot. It shows where the compact PHP backend still pays for decoding and repacking data compared with an already-built PHP hash table.

## Implementation

- Group validated bulk input by 16-bit container key, then update each container once. Large sparse batches use built-in hash/sort/pack operations; small updates to existing containers use incremental insertion, avoiding the repeated full-container rebuild cost on shuffled input.
- Use built-in keyed-array operations for general sparse algebra, then repack immediately. Clone identical containers for union/intersection and return empty containers for difference/XOR.
- Compare cardinality as well as bytes before treating containers as identical: a 4,096-value packed array and a dense bitset can both occupy 8,192 bytes.
- Copy native batches with machine-endian 32-bit packing and one FFI memory copy instead of assigning every C-array element individually. Keep 1,024-value validation/commit boundaries and all existing range checks.

## Verification

PHP 8.2.35, 8.3.36, 8.4.27, and 8.5.12 passed 57 tests / 2,891,817 assertions with the native backend and 27 tests / 1,442,782 assertions with FFI disabled. Stock CRoaring also passed on PHP 8.4.27. New cases cover duplicate batches, shuffled updates, unsigned boundaries, array/bitset transitions, cached rank/select after mutation, snapshot/copy independence, invalid and interrupted streams, and identical bytes across different container formats.

## Data and reproduction

- [Before: full report](../2026-11-04-bulk-before/report.md), [raw samples](../2026-11-04-bulk-before/results.json), [CSV](../2026-11-04-bulk-before/summary.csv), [source snapshot](../2026-11-04-bulk-before/src/).
- [After: full report](../2026-11-04-bulk-after/report.md), [raw samples](../2026-11-04-bulk-after/results.json), [CSV](../2026-11-04-bulk-after/summary.csv), [source snapshot](../2026-11-04-bulk-after/src/).
- Partial-overlap samples: [before](partial-before.json), [after](partial-after.json), and [exact harness](sparse-intersection.php).

From the repository root, with the native library built:

```sh
python3 benchmarks/run.py --sizes 100000 1000000 \
  --workloads dense shuffled sparse sparse_mixed ranges --repetitions 3 \
  --output benchmarks/results/new-bulk-run

ROARING_LIBRARY=/absolute/path/to/libroaring.dylib php -d ffi.enable=1 \
  -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G \
  benchmarks/sparse-intersection.php --samples=3 \
  --output=benchmarks/results/new-partial-run.json
```

On Linux, use `libroaring.so`. To reproduce the old partial-overlap path, add `--source-dir=benchmarks/results/2026-11-04-bulk-before/src`. For the main suite, check out the baseline commit in a separate directory, install Composer dependencies, build the same native library, and use a fresh output directory. Existing measurements are never overwritten.
