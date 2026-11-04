# Rank/select and unsigned 64-bit benchmark

PHP 8.4.27; 100000 IDs per bitmap; 1000 queries per batch; 5 samples per configuration.

Times are median milliseconds. Cold rank is the first batch after construction; warm rank repeats the same inputs. Select is measured after a warmup batch. The last column is a single rank query after removing one ID.

| Workload | Backend | Build | Cold rank batch | Warm rank batch | Warm select batch | Rank after remove |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 32-dense | php | 42.9045 | 1.0240 | 0.8822 | 5.3552 | 0.0302 |
| 32-dense | native | 5.9140 | 0.2138 | 0.2161 | 0.4861 | 0.0005 |
| 32-sparse | php | 57.9020 | 1.2769 | 1.0294 | 0.6580 | 0.2648 |
| 32-sparse | native | 6.4914 | 1.0061 | 0.9743 | 3.6617 | 0.0013 |
| 64-dense | php | 160.6566 | 2.4921 | 2.1342 | 7.1236 | 0.0307 |
| 64-dense | native | 114.2018 | 1.5246 | 1.3866 | 1.7035 | 0.0022 |
| 64-sparse | php | 178.2234 | 3.1424 | 2.4586 | 2.3105 | 0.3520 |
| 64-sparse | native | 114.2575 | 1.7056 | 1.3763 | 1.5374 | 0.0116 |

32-bit dense IDs are `3*i`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. 64-bit dense IDs start at `2^63`, with lower bits `3*i`; sparse IDs use upper words `0x80000000 + floor(i/32)` and 32 lower-word IDs per bucket, spaced by 2048 with the same CRC-derived jitter. All input lists are sorted and unique.

Every 64-bit input/output ID is a decimal string above PHP_INT_MAX, so the measured public API includes exact conversion overhead. Source-ID generation is outside construction timing. Query timings include collection of the returned results; all results are checked exactly after timing.

Samples run sequentially in one process, with a fresh bitmap for each sample and alternating backend order. These are focused CLI microbenchmarks, not HTTP latency, independent-process RSS measurements, or a rerun of the earlier set-algebra report. Cold query batches include any lazy index construction. Native 64-bit queries can use the package C index when the loaded library provides it. Raw samples, min/max ranges, runtime settings, and source hashes are in [results.json](results.json).

Reproduce from the package root:

```sh
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php --size=100000 --queries=1000 --samples=5 --output=benchmarks/results/order-new-run
```
