# Rank/select and unsigned 64-bit benchmark

PHP 8.4.27; 1000000 IDs per bitmap; 1000 queries per batch; 5 samples per configuration.

Times are median milliseconds. Cold rank is the first batch after construction; warm rank repeats the same inputs. Select is measured after a warmup batch. The last column is a single rank query after removing one ID.

| Workload | Backend | Build | Cold rank batch | Warm rank batch | Warm select batch | Rank after remove |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 32-dense | php | 414.9278 | 1.7740 | 0.9261 | 5.5356 | 0.0334 |
| 32-dense | native | 60.6427 | 0.2350 | 0.2264 | 0.5489 | 0.0004 |
| 32-sparse | php | 585.6209 | 4.2678 | 1.1383 | 0.7297 | 2.7878 |
| 32-sparse | native | 64.2113 | 9.5065 | 9.6707 | 34.4095 | 0.0105 |
| 64-dense | php | 1599.6625 | 3.0682 | 2.1860 | 7.3489 | 0.0348 |
| 64-dense | native | 1140.0419 | 1.5887 | 1.5372 | 1.8726 | 0.0027 |
| 64-sparse | php | 1804.8556 | 6.5207 | 2.8487 | 2.6546 | 3.2032 |
| 64-sparse | native | 1156.5157 | 3.4797 | 1.4757 | 1.6119 | 0.0885 |

32-bit dense IDs are `3*i`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. 64-bit dense IDs start at `2^63`, with lower bits `3*i`; sparse IDs use upper words `0x80000000 + floor(i/32)` and 32 lower-word IDs per bucket, spaced by 2048 with the same CRC-derived jitter. All input lists are sorted and unique.

Every 64-bit input/output ID is a decimal string above PHP_INT_MAX, so the measured public API includes exact conversion overhead. Source-ID generation is outside construction timing. Query timings include collection of the returned results; all results are checked exactly after timing.

Samples run sequentially in one process, with a fresh bitmap for each sample and alternating backend order. These are focused CLI microbenchmarks, not HTTP latency, independent-process RSS measurements, or a rerun of the earlier set-algebra report. Cold query batches include any lazy index construction. Native 64-bit queries can use the package C index when the loaded library provides it. Raw samples, min/max ranges, runtime settings, and source hashes are in [results.json](results.json).

Reproduce from the package root:

```sh
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=1000000 --queries=1000 --samples=5 --output=benchmarks/results/order-new-run
```
