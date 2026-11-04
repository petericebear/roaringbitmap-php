# Rank/select and unsigned 64-bit benchmark

PHP 8.4.27; 1000000 IDs per bitmap; 1000 queries per batch; 5 samples per configuration.

Times are median milliseconds. Cold rank is the first batch after construction; warm rank repeats the same inputs. Select is measured after a warmup batch. The last column is a single rank query after removing one ID.

| Workload | Backend | Build | Cold rank batch | Warm rank batch | Warm select batch | Rank after remove |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 32-dense | php | 404.0152 | 1.7554 | 0.9045 | 5.3865 | 0.0288 |
| 32-dense | native | 58.4043 | 0.2256 | 0.2190 | 0.5331 | 0.0005 |
| 32-sparse | php | 552.8692 | 3.8533 | 1.0675 | 0.6963 | 2.6319 |
| 32-sparse | native | 61.2909 | 9.1880 | 8.8179 | 31.9647 | 0.0090 |
| 64-dense | php | 1529.1530 | 2.9016 | 2.1103 | 6.8187 | 0.0291 |
| 64-dense | native | 1093.4683 | 1.4806 | 1.4423 | 1.8562 | 0.0021 |
| 64-sparse | php | 1730.8628 | 5.8895 | 2.4019 | 2.3228 | 2.7808 |
| 64-sparse | native | 1119.6872 | 83.7847 | 83.8040 | 84.1763 | 0.0855 |

32-bit dense IDs are `3*i`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. 64-bit dense IDs start at `2^63`, with lower bits `3*i`; sparse IDs use upper words `0x80000000 + floor(i/32)` and 32 lower-word IDs per bucket, spaced by 2048 with the same CRC-derived jitter. All input lists are sorted and unique.

Every 64-bit input/output ID is a decimal string above PHP_INT_MAX, so the measured public API includes exact conversion overhead. Source-ID generation is outside construction timing. Query timings include collection of the returned results; all results are checked exactly after timing.

Samples run sequentially in one process, with a fresh bitmap for each sample and alternating backend order. These are focused CLI microbenchmarks, not HTTP latency, independent-process RSS measurements, or a rerun of the earlier set-algebra report. Cold PHP queries build indexes; native queries use CRoaring directly. Cached PHP indexes can outperform native scans on some shapes. Raw samples, min/max ranges, runtime settings, and source hashes are in [results.json](results.json).

Reproduce from the package root:

```sh
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=1000000 --queries=1000 --samples=5 --output=benchmarks/results/order-new-run
```
