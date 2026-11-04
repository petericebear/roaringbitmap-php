# Rank/select and unsigned 64-bit benchmark

PHP 8.4.27; 100000 IDs per bitmap; 1000 queries per batch; 5 samples per configuration.

Times are median milliseconds. Cold rank is the first batch after construction; warm rank repeats the same inputs. Select is measured after a warmup batch. The last column is a single rank query after removing one ID.

| Workload | Backend | Build | Cold rank batch | Warm rank batch | Warm select batch | Rank after remove |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 32-dense | php | 40.4860 | 0.8844 | 0.8156 | 5.0724 | 0.0279 |
| 32-dense | native | 5.7594 | 0.1998 | 0.1972 | 0.4647 | 0.0003 |
| 32-sparse | php | 54.8894 | 1.1833 | 0.9922 | 0.6087 | 0.2345 |
| 32-sparse | native | 5.9764 | 0.9440 | 0.9141 | 3.4461 | 0.0012 |
| 64-dense | php | 151.3928 | 2.2195 | 1.9835 | 6.5771 | 0.0267 |
| 64-dense | native | 107.1048 | 1.3100 | 1.3017 | 1.6193 | 0.0016 |
| 64-sparse | php | 173.0123 | 3.0065 | 2.2542 | 2.3509 | 0.2606 |
| 64-sparse | native | 109.9434 | 9.7743 | 9.7353 | 10.0468 | 0.0112 |

32-bit dense IDs are `3*i`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. 64-bit dense IDs start at `2^63`, with lower bits `3*i`; sparse IDs use upper words `0x80000000 + floor(i/32)` and 32 lower-word IDs per bucket, spaced by 2048 with the same CRC-derived jitter. All input lists are sorted and unique.

Every 64-bit input/output ID is a decimal string above PHP_INT_MAX, so the measured public API includes exact conversion overhead. Source-ID generation is outside construction timing. Query timings include collection of the returned results; all results are checked exactly after timing.

Samples run sequentially in one process, with a fresh bitmap for each sample and alternating backend order. These are focused CLI microbenchmarks, not HTTP latency, independent-process RSS measurements, or a rerun of the earlier set-algebra report. Cold PHP queries build indexes; native queries use CRoaring directly. Cached PHP indexes can outperform native scans on some shapes. Raw samples, min/max ranges, runtime settings, and source hashes are in [results.json](results.json).

Reproduce from the package root:

```sh
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=100000 --queries=1000 --samples=5 --output=benchmarks/results/order-new-run
```
