# Rank/select and unsigned 64-bit benchmark

PHP 8.4.27; 100000 IDs per bitmap; 1000 queries per batch; 5 samples per configuration.

Measured November 3, 2026 with Laravel Herd on this Apple M4 Pro Mac, using the existing ARM64 CRoaring 5.4.0 build. All 40 samples completed with exact result checks. See [the earlier measurement report](../../MEASUREMENTS.md) for hardware/build details and the separate array-versus-package set-algebra baseline.

Times are median milliseconds. Cold rank is the first batch after construction; warm rank repeats the same inputs. Select is measured after a warmup batch. The last column is a single rank query after removing one ID.

| Workload | Backend | Build | Cold rank batch | Warm rank batch | Warm select batch | Rank after remove |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 32-dense | php | 42.0213 | 1.0057 | 0.8630 | 5.4734 | 0.0282 |
| 32-dense | native | 5.9312 | 0.2047 | 0.2008 | 0.4864 | 0.0003 |
| 32-sparse | php | 57.0695 | 1.2088 | 1.0136 | 0.6424 | 0.2462 |
| 32-sparse | native | 6.2056 | 1.0022 | 0.9536 | 3.2227 | 0.0014 |
| 64-dense | php | 155.5215 | 2.1737 | 2.0816 | 7.0369 | 0.0306 |
| 64-dense | native | 109.4072 | 1.3523 | 1.3013 | 1.6337 | 0.0019 |
| 64-sparse | php | 172.0446 | 2.9741 | 2.3555 | 2.2139 | 0.2737 |
| 64-sparse | native | 110.0329 | 9.8466 | 9.9317 | 9.8620 | 0.0105 |

For dense 64-bit data, native mode takes about **1.6× less time for warm rank** and **4.3× less time for warm select** than PHP. For sparse 64-bit data, the cached PHP index takes about **4.2× less time for rank** and **4.5× less time for select** than native. Mutation changes the tradeoff: the first sparse PHP rank after removal takes **0.274 ms**, compared with **0.0105 ms** natively. These ratios describe these datasets and query phases, not overall application speed.

32-bit dense IDs are `3*i`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. 64-bit dense IDs start at `2^63`, with lower bits `3*i`; sparse IDs use upper words `0x80000000 + floor(i/32)` and 32 lower-word IDs per bucket, spaced by 2048 with the same CRC-derived jitter. All input lists are sorted and unique.

Every 64-bit input/output ID is a decimal string above PHP_INT_MAX, so the measured public API includes exact conversion overhead. Source-ID generation is outside construction timing. Query timings include collection of the returned results; all results are checked exactly after timing.

Samples run sequentially in one process, with a fresh bitmap for each sample and alternating backend order. These are focused CLI microbenchmarks, not HTTP latency, independent-process RSS measurements, or a rerun of the earlier set-algebra report. Cold PHP queries build indexes; native queries use CRoaring directly. Cached PHP indexes can outperform native scans on some shapes. Raw samples, min/max ranges, runtime settings, and source hashes are in [results.json](results.json).

Before this run, the final implementation passed on installed Herd PHP **8.2.35, 8.3.36, 8.4.27, and 8.5.12**. Each version passed **48 tests / 2,887,132 assertions** with native mode required, and **24 tests / 1,442,624 assertions** with FFI disabled. Coverage includes full unsigned-ID boundaries, independent Python integer vectors, every value in the official C/C++ 64-bit fixtures, mixed-backend operations, rank/select cache invalidation, and malformed data. Composer validation and PHP lint also passed. Linux CI is configured but was not executed locally.

Reproduce from the package root:

```sh
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=100000 --queries=1000 --samples=5 --output=benchmarks/results/order-new-run
```
