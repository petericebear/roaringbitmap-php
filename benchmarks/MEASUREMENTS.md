# Before and after: Roaring Bitmap on Laravel Herd

Measured on **November 3, 2026**, using this Mac's **Laravel Herd PHP 8.4.27**, **Apple M4 Pro**, **48 GiB RAM**, and **macOS 26.7.1**. The native build uses **CRoaring 5.4.0**, compiled for ARM64 with Apple clang 21.0.0 and `-O3`.

**Result:** native Roaring substantially improves bulk set operations and reduces construction memory in these workloads. Construction from individual IDs, individual membership checks, and iteration have different tradeoffs. The PHP fallback provides portability and compact storage, but it is not uniformly faster than PHP arrays.

“Before” means a PHP array used as an integer-keyed set; “after” means this package's PHP or native backend. These measurements also compare enabling native acceleration after using the PHP backend. The package algorithms were not changed during this work.

## Main comparison: one million dense IDs per input

Two sets each contain 1,000,000 unique IDs, with 50% overlap. IDs are `3*i` and arrive in ascending order. All timings are milliseconds and are **medians of five independent PHP processes per configuration**.

| Measurement | Before: PHP array set | After: package PHP | After: package native |
| --- | ---: | ---: | ---: |
| Construct both inputs | 127.56 ms | 914.85 ms | 256.43 ms |
| One intersection | 5.6737 ms | 0.8803 ms | 0.01056 ms |
| One union | 13.5339 ms | 0.4681 ms | 0.01990 ms |
| One difference | 5.5432 ms | 4.5838 ms | 0.02093 ms |
| 10,000 membership probes, 50% hits | 0.1319 ms | 1.5015 ms | 1.2406 ms |
| Iterate every ID in one input | 5.9411 ms | 90.5625 ms | 29.2742 ms |
| Full-process construction peak RSS | 107.00 MiB | 28.64 MiB | 28.89 MiB |
| Serialized size of one input | 13,629,638 bytes | 377,208 bytes | 377,208 bytes |

For this case, native intersection takes **537× less time than the array baseline** and **83× less time than the PHP backend**. Native construction peak RSS is **73% lower than the array baseline**. These are operation-specific comparisons on this dataset, not an overall application speedup.

The costs matter too: native construction takes **2.01× as long as the array baseline**, 10,000 membership probes take **9.40× as long**, and iteration takes **4.93× as long**. Package iteration additionally guarantees sorted snapshots; PHP array iteration follows insertion order. The table compares the public APIs as implemented, including those guarantees.

For an illustrative workload that builds both inputs once and repeatedly intersects them, the phase medians predict native mode recovers its extra construction cost after approximately **23 intersections**:

```text
ceil((256.427458 - 127.557125) / (5.673650 - 0.010560)) = 23
```

This is a simple estimate from separately measured phases. It excludes first-use loading and application overhead and assumes the same inputs are retained. With just one construction and one intersection, the phase sums are approximately **133.23 ms for arrays** and **256.44 ms for native mode**.

## Sparse IDs: distribution changes the comparison

The original sparse pattern used `2048*i + (i % 31)`. A separate cross-check used `2048*i + (crc32(pack('V', i)) & 2047)` to mix the low bits while retaining the same broad density, sorted input, and 50% overlap. Both are deterministic synthetic datasets.

At 1,000,000 IDs per input:

| Sparse pattern | Array intersection | Package PHP intersection | Native intersection | Array / native time |
| --- | ---: | ---: | ---: | ---: |
| Patterned low bits | 100.9799 ms | 124.4347 ms | 0.7414 ms | 136.2× |
| Mixed low bits | 8.3690 ms | 123.0598 ms | 0.7301 ms | 11.5× |

The native result changes little, while the PHP array result changes substantially. Consequently, **11.5× is the more conservative observed sparse intersection comparison here**; the 136× figure should not be generalized to sparse data overall. The PHP fallback is approximately **14.7× slower than arrays** for mixed sparse intersections.

For the mixed sparse case, construction takes **274.10 ms with arrays**, **1,347.41 ms with PHP Roaring**, and **366.64 ms with native Roaring**. Construction peak RSS is approximately **107.0 MiB**, **40.6 MiB**, and **34.4 MiB**, respectively. The phase-based native break-even estimate is about **13 repeated intersections** after one construction.

## Consecutive ranges and shuffled ingestion

When the input is already a range, the package can use `addRange()` rather than enumerate individual IDs. For two overlapping ranges of 1,000,000 IDs, median construction takes **120.71 ms with PHP arrays**, **0.414 ms with PHP Roaring**, and **0.0088 ms with native Roaring**. The native range bitmap serializes to **230 bytes**, versus **131,208 bytes** for the PHP backend and **12,888,902 bytes** for the PHP array representation. These very short native timings exclude first-use initialization and should not be interpreted as complete request latency.

Native decoding of that compact run-based representation takes **9.332 ms**, compared with **0.213 ms** for decoding the PHP backend's larger array/bitset representation. The current native deserialization path validates through PHP and expands runs during that validation. Small stored size does not imply the fastest load time.

For shuffled dense ingestion at 1,000,000 IDs per input, construction takes **169.86 ms with arrays**, **1,017.42 ms with PHP Roaring**, and **315.87 ms with native Roaring**. The corresponding intersections take **9.7581 ms**, **0.8811 ms**, and **0.01164 ms**. This case uses a deterministic permutation rather than a new random order on every sample.

## What was built and verified

- Ran `bash tools/build-native.sh`, downloaded the pinned CRoaring sources, verified their SHA-256 hashes, and rebuilt `.build/croaring/libroaring.dylib` as an ARM64 shared library.
- `composer validate --strict` passed.
- Before benchmarking, the PHP/native suite passed **22 tests and 38,223 assertions** on Herd PHP 8.4.27. With FFI disabled, the fallback suite passed **11 tests and 18,628 assertions**.
- The full main matrix completed **120 isolated process samples** across four workloads, two sizes, three backends, and five repetitions. The mixed sparse cross-check added **30 samples**, for **150 total**.
- All benchmark samples passed their cardinality, membership, iteration checksum, and deserialization checks. First repetitions additionally verified checksums of set-operation results and restored data against independent reference sums.
- The library source fingerprints and compiled native library hash match between both measurement runs. Exact harness snapshots are retained beside the raw results.

The main matrix ran **09:12:49–09:14:16 CET**; the mixed sparse cross-check ran **09:16:40–09:17:15 CET**. The reports include the full timestamps and environment.

## Measurement conditions

These are **CLI measurements using Herd's PHP binary**, not Laravel HTTP or PHP-FPM throughput. Workers run sequentially; no benchmark processes compete with one another. Backend order rotates between samples. The Mac remains a normal shared development machine, without controlled thermals or CPU pinning.

Each fresh worker primes autoloading and library loading with a tiny set. Construction includes streamed input generation and the package's public API validation. Query batches receive warmup and calibration calls, then target 25 ms per batch; result destruction is included. The reports show median, minimum, and maximum sample values, and the JSON/CSV preserve standard deviations and batch details.

All backends use the same worker flags: `ffi.enable=1`, `opcache.enable_cli=0`, `opcache.jit=disable`, and `memory_limit=1G`. Herd's existing CLI limit was 128M; the 1 GiB override applies only to benchmark processes and accommodates the large array baseline. No global Herd settings were changed. Native mode is explicitly required, so its measurements cannot silently fall back to PHP.

**Memory:** the headline figure is the operating system's whole-process peak RSS immediately after building both inputs, before the operation and validation stages. It includes interpreter, library, and C allocations. It does not measure later query peaks or isolate the bytes owned by the bitmap. PHP allocator deltas are present in JSON but exclude native C allocations.

**Persistence:** PHP arrays use PHP `serialize()`, while both package backends use standard Roaring portable bytes. Their size and serialization timings compare different formats; the files are not interchangeable. The JSON records the format of each result.

## Practical implications

The strongest measured fit is retaining compact bitmaps and performing bulk set algebra, particularly in native mode. If performance depends on CRoaring, select `Backend::Native` explicitly so unavailable acceleration produces an error instead of selecting the PHP fallback.

PHP arrays remain useful for fast individual lookups and iteration in these tests. The pure PHP backend offers deployment portability and substantially lower construction memory, with strong dense algebra results but much slower sparse algebra. Measure the operation mix and actual ID distribution before applying these ratios to an application.

For further performance work, the measurements identify concrete targets: ingestion overhead, sparse operations in the PHP backend, native iteration, and PHP validation during native decoding. No implementation optimization is included in these measurements.

## Full reports, data, and reproduction

- [Main matrix: all timings, both sizes, sample ranges, and methodology](results/2026-11-03-herd/report.md)
- [Main raw JSON](results/2026-11-03-herd/results.json) and [summary CSV](results/2026-11-03-herd/summary.csv)
- [Mixed sparse cross-check report](results/2026-11-03-herd-mixed-sparse/report.md)
- [Cross-check raw JSON](results/2026-11-03-herd-mixed-sparse/results.json) and [summary CSV](results/2026-11-03-herd-mixed-sparse/summary.csv)
- [Benchmark setup and metric definitions](README.md)

```sh
composer install
bash tools/build-native.sh

# Main matrix, preserving its original four workloads:
python3 benchmarks/run.py --sizes 100000 1000000 \
  --workloads dense shuffled sparse ranges --repetitions 5 \
  --output benchmarks/results/main-new-run

# Additional sparse distribution:
python3 benchmarks/run.py --sizes 100000 1000000 \
  --workloads sparse_mixed --repetitions 5 \
  --output benchmarks/results/mixed-sparse-new-run
```

The runner discovers the local native library and Herd `php` on PATH. Use `--php` or `--library` to override either path. Each output directory must be new, so previous measurements remain intact. Running without `--workloads` now includes all five workloads.
