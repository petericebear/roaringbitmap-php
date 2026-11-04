# Rank/select and unsigned 64-bit benchmark

PHP 8.4.27; 100000 IDs per bitmap; 1000 queries per batch; 5 samples per configuration.

Times are median milliseconds. Cold rank is the first batch after construction; warm rank repeats the same inputs. Select is measured after a warmup batch. The last column is a single rank query after removing one ID.

| Workload | Backend | Build | Cold rank batch | Warm rank batch | Warm select batch | Rank after remove |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 32-dense | php | 42.4976 | 0.9330 | 0.8279 | 5.0715 | 0.0280 |
| 32-dense | native | 5.9692 | 0.2020 | 0.1858 | 0.4645 | 0.0003 |
| 32-sparse | php | 57.0971 | 1.1951 | 0.9959 | 0.6172 | 0.2307 |
| 32-sparse | native | 6.3575 | 0.9742 | 0.9887 | 3.5849 | 0.0012 |
| 64-dense | php | 150.4615 | 2.1690 | 2.0311 | 6.7046 | 0.0324 |
| 64-dense | native | 102.7466 | 1.3077 | 1.2570 | 1.7038 | 0.0016 |
| 64-sparse | php | 166.3026 | 2.9027 | 2.2332 | 2.1821 | 0.2916 |
| 64-sparse | native | 103.1922 | 9.8373 | 9.8827 | 10.1563 | 0.0112 |

32-bit dense IDs are `3*i`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. 64-bit dense IDs start at `2^63`, with lower bits `3*i`; sparse IDs use upper words `0x80000000 + floor(i/32)` and 32 lower-word IDs per bucket, spaced by 2048 with the same CRC-derived jitter. All input lists are sorted and unique.

Every 64-bit input/output ID is a decimal string above PHP_INT_MAX, so the measured public API includes exact conversion overhead. Source-ID generation is outside construction timing. Query timings include collection of the returned results; all results are checked exactly after timing.

Samples run sequentially in one process, with a fresh bitmap for each sample and alternating backend order. These are focused CLI microbenchmarks, not HTTP latency, independent-process RSS measurements, or a rerun of the earlier set-algebra report. Cold PHP queries build indexes; native queries use CRoaring directly. Cached PHP indexes can outperform native scans on some shapes. Raw samples, min/max ranges, runtime settings, and source hashes are in [results.json](results.json).

Reproduce from the package root:

```sh
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=100000 --queries=1000 --samples=5 --output=benchmarks/results/order-new-run
```
