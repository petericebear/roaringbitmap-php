# Build membership, add an ID, and publish a saved snapshot

Measured 2026-11-04T15:37:50.677850+01:00 through 2026-11-04T15:39:21.253314+01:00 on macOS-26.7.1-arm64-arm-64bit-Mach-O, arm64, PHP 8.4.27. Each configuration has 3 fresh-process samples; workers run sequentially and backend order rotates.

This models collecting selected document IDs and processing a later membership event. For the small dense32 fixture the transition is exactly `{1, 3, 5}` → `{1, 3, 5, 7}`. The package implementation is unchanged; this report measures a different workload from set intersections.

## Findings on this machine

For one million existing positive 64-bit IDs:

- **Arrays win at construction and live insertion.** Dense construction took 94.46 ms for the keyed array, 542.55 ms for package PHP, and 148.92 ms for native. Warm additions took 0.042, 0.375, and 0.292 µs respectively. First-add observations were slower: 1.50, 6.79, and 8.63 µs. The timer floor was about 0.021 µs; these tiny warm timings should not be read as precise request latency.
- **Dense saved membership strongly favors bitmaps.** Restoring, adding one ID, and republishing took 38.88 ms for the array, 0.458 ms for package PHP, and 0.502 ms for native. The Base64 snapshot was 25.43 MiB for the array and 0.323 MiB for either bitmap. The list baseline took 50.39 ms and also permits duplicates.
- **Sparse saved updates still favor arrays on CPU time.** The same cycle took 49.02 ms for the keyed array, 63.19 ms for package PHP, and 61.92 ms for native. Bitmaps reduced the saved payload to 2.86 MiB. Native restoration accounted for 60.38 ms of its cycle, including the package's PHP format validation; this is the main measured opportunity for further optimization.
- **Publishing once per batch amortizes restoration.** Native restore/add/save for 100 new IDs took 0.528 ms for dense input and 61.14 ms for sparse input, or approximately 5.28 µs and 611 µs per new ID. These are full-snapshot CPU costs; network, storage, event scheduling, and chunk management are excluded.

For a worker that retains its membership set, the array is the faster baseline in these fixtures. For a worker that restores a saved value for each event, ID density and snapshot size materially change the result. A search field being mapped as `long` does not establish its ID distribution; both tested distributions use positive integer IDs above 2^40.

## Measurements

Membership updates: build one set, then add one new ID. In-memory additions are in **microseconds**; construction and restore → add → save are in **milliseconds**. The saved cycle includes Base64 and rewrites the entire snapshot; database/network time is excluded.

| Workload | Existing IDs | Backend | Build (ms) | Add one in memory (µs) | Restore + add + save (ms) | Saved Base64 (KiB) |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| dense32 | 3 | PHP list (no dedup) | 0.001 | 0.042 | 0.001 | 0.04 |
| dense32 | 3 | PHP array set | 0.001 | 0.042 | 0.001 | 0.04 |
| dense32 | 3 | Package PHP | 0.005 | 0.458 | 0.003 | 0.03 |
| dense32 | 3 | Native accelerator | 0.007 | 0.167 | 0.005 | 0.03 |
| dense32 | 100,000 | PHP list (no dedup) | 8.243 | 0.042 | 3.511 | 2126.75 |
| dense32 | 100,000 | PHP array set | 8.983 | 0.042 | 2.648 | 1620.39 |
| dense32 | 100,000 | Package PHP | 23.513 | 1.167 | 0.133 | 36.47 |
| dense32 | 100,000 | Native accelerator | 13.019 | 0.208 | 0.134 | 36.47 |
| dense32 | 1,000,000 | PHP list (no dedup) | 82.892 | 0.042 | 42.900 | 23871.55 |
| dense32 | 1,000,000 | PHP array set | 93.526 | 0.042 | 32.262 | 17505.80 |
| dense32 | 1,000,000 | Package PHP | 245.220 | 0.209 | 0.474 | 331.00 |
| dense32 | 1,000,000 | Native accelerator | 135.069 | 0.167 | 0.477 | 331.00 |
| sparse32 | 3 | PHP list (no dedup) | 0.001 | 0.042 | 0.001 | 0.05 |
| sparse32 | 3 | PHP array set | 0.001 | 0.042 | 0.001 | 0.05 |
| sparse32 | 3 | Package PHP | 0.005 | 0.334 | 0.003 | 0.03 |
| sparse32 | 3 | Native accelerator | 0.005 | 0.208 | 0.004 | 0.03 |
| sparse32 | 100,000 | PHP list (no dedup) | 13.278 | 0.042 | 3.892 | 2519.07 |
| sparse32 | 100,000 | PHP array set | 13.920 | 0.167 | 2.880 | 2012.71 |
| sparse32 | 100,000 | Package PHP | 22.128 | 0.375 | 6.225 | 292.98 |
| sparse32 | 100,000 | Native accelerator | 18.491 | 0.250 | 5.766 | 292.98 |
| sparse32 | 1,000,000 | PHP list (no dedup) | 133.758 | 0.042 | 45.944 | 27794.75 |
| sparse32 | 1,000,000 | PHP array set | 168.231 | 0.166 | 39.820 | 21429.01 |
| sparse32 | 1,000,000 | Package PHP | 225.427 | 0.375 | 62.258 | 2929.70 |
| sparse32 | 1,000,000 | Native accelerator | 183.507 | 0.375 | 60.335 | 2929.70 |
| dense64 | 3 | PHP list (no dedup) | 0.001 | 0.042 | 0.001 | 0.09 |
| dense64 | 3 | PHP array set | 0.001 | 0.042 | 0.001 | 0.09 |
| dense64 | 3 | Package PHP | 0.006 | 0.584 | 0.004 | 0.05 |
| dense64 | 3 | Native accelerator | 0.006 | 0.250 | 0.005 | 0.05 |
| dense64 | 100,000 | PHP list (no dedup) | 8.626 | 0.042 | 4.679 | 3110.55 |
| dense64 | 100,000 | PHP array set | 9.104 | 0.042 | 3.425 | 2604.18 |
| dense64 | 100,000 | Package PHP | 54.901 | 1.333 | 0.132 | 36.48 |
| dense64 | 100,000 | Native accelerator | 15.404 | 0.292 | 0.154 | 36.48 |
| dense64 | 1,000,000 | PHP list (no dedup) | 84.050 | 0.042 | 50.386 | 32407.43 |
| dense64 | 1,000,000 | PHP array set | 94.461 | 0.042 | 38.878 | 26041.68 |
| dense64 | 1,000,000 | Package PHP | 542.546 | 0.375 | 0.458 | 331.02 |
| dense64 | 1,000,000 | Native accelerator | 148.922 | 0.292 | 0.502 | 331.02 |
| sparse64 | 3 | PHP list (no dedup) | 0.001 | 0.042 | 0.001 | 0.09 |
| sparse64 | 3 | PHP array set | 0.001 | 0.042 | 0.001 | 0.09 |
| sparse64 | 3 | Package PHP | 0.006 | 0.500 | 0.004 | 0.05 |
| sparse64 | 3 | Native accelerator | 0.007 | 0.292 | 0.007 | 0.05 |
| sparse64 | 100,000 | PHP list (no dedup) | 13.970 | 0.042 | 4.821 | 3110.55 |
| sparse64 | 100,000 | PHP array set | 14.294 | 0.166 | 3.484 | 2604.18 |
| sparse64 | 100,000 | Package PHP | 83.911 | 0.541 | 6.173 | 293.00 |
| sparse64 | 100,000 | Native accelerator | 20.007 | 0.333 | 6.152 | 293.00 |
| sparse64 | 1,000,000 | PHP list (no dedup) | 135.588 | 0.042 | 50.979 | 32407.43 |
| sparse64 | 1,000,000 | PHP array set | 173.657 | 0.167 | 49.017 | 26041.68 |
| sparse64 | 1,000,000 | Package PHP | 841.192 | 0.500 | 63.187 | 2929.71 |
| sparse64 | 1,000,000 | Native accelerator | 207.264 | 0.333 | 61.919 | 2929.71 |

The list baseline uses `$ids[] = $id` and retains duplicates. The array-set baseline uses `$ids[$id] = true` and deduplicates like a bitmap. Arrays use PHP serialization; bitmaps use their portable format. These persistence formats are not interchangeable. `dense64` uses positive integer IDs above 2^40, with the same gaps as `dense32`; `sparse64` similarly offsets `sparse32`. Single-add timings include timer and benchmark dispatch overhead; see the raw timer floor and sample ranges.

## Duplicate events, insertion position, and batching

A duplicate does not grow any of the sets. List duplicates still append and are deliberately shown as n/a. A gap insertion adds a missing ID among existing members. The first addition includes any lazy cardinality work; the repeated probes operate on a warmed, unshared set at the original cardinality. Batching loads once, adds 100 new IDs individually, and saves once.

| Workload | IDs | Backend | First add (µs) | Gap insert (µs) | Duplicate (µs) | Save batch of 100 (ms) | Cycle per new ID (µs) |
| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: |
| dense32 | 3 | PHP array set | 0.375 | 0.042 | 0.042 | 0.005 | 0.050 |
| dense32 | 3 | PHP list (no dedup) | 0.333 | 0.042 | n/a | 0.005 | 0.049 |
| dense32 | 3 | Native accelerator | 1.416 | 0.167 | 0.167 | 0.021 | 0.206 |
| dense32 | 3 | Package PHP | 3.625 | 0.583 | 0.458 | 0.079 | 0.795 |
| dense32 | 100,000 | PHP array set | 1.125 | 0.167 | 0.166 | 2.614 | 26.137 |
| dense32 | 100,000 | PHP list (no dedup) | 1.167 | 0.042 | n/a | 3.590 | 35.899 |
| dense32 | 100,000 | Native accelerator | 4.625 | 0.208 | 0.167 | 0.145 | 1.450 |
| dense32 | 100,000 | Package PHP | 13.667 | 0.250 | 0.208 | 0.232 | 2.323 |
| dense32 | 1,000,000 | PHP array set | 1.958 | 0.167 | 0.167 | 31.809 | 318.090 |
| dense32 | 1,000,000 | PHP list (no dedup) | 1.084 | 0.042 | n/a | 42.736 | 427.360 |
| dense32 | 1,000,000 | Native accelerator | 9.584 | 0.292 | 0.167 | 0.524 | 5.236 |
| dense32 | 1,000,000 | Package PHP | 7.166 | 0.333 | 0.208 | 0.512 | 5.125 |
| dense64 | 3 | PHP array set | 0.333 | 0.042 | 0.042 | 0.006 | 0.055 |
| dense64 | 3 | PHP list (no dedup) | 0.334 | 0.042 | n/a | 0.006 | 0.061 |
| dense64 | 3 | Native accelerator | 1.833 | 0.292 | 0.250 | 0.031 | 0.305 |
| dense64 | 3 | Package PHP | 1.375 | 0.708 | 0.625 | 0.092 | 0.922 |
| dense64 | 100,000 | PHP array set | 1.334 | 0.166 | 0.084 | 3.419 | 34.194 |
| dense64 | 100,000 | PHP list (no dedup) | 0.750 | 0.042 | n/a | 4.681 | 46.809 |
| dense64 | 100,000 | Native accelerator | 6.375 | 0.292 | 0.292 | 0.156 | 1.564 |
| dense64 | 100,000 | Package PHP | 6.375 | 0.375 | 0.333 | 0.255 | 2.555 |
| dense64 | 1,000,000 | PHP array set | 1.500 | 0.167 | 0.167 | 38.620 | 386.204 |
| dense64 | 1,000,000 | PHP list (no dedup) | 0.875 | 0.083 | n/a | 50.765 | 507.649 |
| dense64 | 1,000,000 | Native accelerator | 8.625 | 0.375 | 0.250 | 0.528 | 5.277 |
| dense64 | 1,000,000 | Package PHP | 6.791 | 0.459 | 0.333 | 0.492 | 4.921 |
| sparse32 | 3 | PHP array set | 0.417 | 0.042 | 0.042 | 0.005 | 0.048 |
| sparse32 | 3 | PHP list (no dedup) | 0.333 | 0.042 | n/a | 0.005 | 0.048 |
| sparse32 | 3 | Native accelerator | 1.084 | 0.167 | 0.167 | 0.021 | 0.208 |
| sparse32 | 3 | Package PHP | 2.917 | 0.583 | 0.459 | 0.065 | 0.646 |
| sparse32 | 100,000 | PHP array set | 0.916 | 0.167 | 0.166 | 2.948 | 29.478 |
| sparse32 | 100,000 | PHP list (no dedup) | 0.584 | 0.042 | n/a | 3.902 | 39.016 |
| sparse32 | 100,000 | Native accelerator | 4.459 | 0.417 | 0.250 | 5.866 | 58.659 |
| sparse32 | 100,000 | Package PHP | 7.584 | 0.917 | 0.750 | 6.133 | 61.327 |
| sparse32 | 1,000,000 | PHP array set | 1.209 | 0.167 | 0.167 | 40.099 | 400.992 |
| sparse32 | 1,000,000 | PHP list (no dedup) | 1.000 | 0.042 | n/a | 43.738 | 437.379 |
| sparse32 | 1,000,000 | Native accelerator | 5.875 | 0.584 | 0.417 | 58.775 | 587.746 |
| sparse32 | 1,000,000 | Package PHP | 9.583 | 1.083 | 0.792 | 62.783 | 627.832 |
| sparse64 | 3 | PHP array set | 0.333 | 0.042 | 0.042 | 0.005 | 0.053 |
| sparse64 | 3 | PHP list (no dedup) | 0.292 | 0.042 | n/a | 0.005 | 0.055 |
| sparse64 | 3 | Native accelerator | 1.875 | 0.291 | 0.291 | 0.033 | 0.326 |
| sparse64 | 3 | Package PHP | 1.500 | 0.709 | 0.625 | 0.080 | 0.795 |
| sparse64 | 100,000 | PHP array set | 1.333 | 0.167 | 0.125 | 3.554 | 35.538 |
| sparse64 | 100,000 | PHP list (no dedup) | 0.959 | 0.042 | n/a | 4.535 | 45.350 |
| sparse64 | 100,000 | Native accelerator | 13.125 | 0.583 | 0.292 | 6.053 | 60.534 |
| sparse64 | 100,000 | Package PHP | 3.833 | 1.084 | 0.916 | 6.183 | 61.828 |
| sparse64 | 1,000,000 | PHP array set | 1.500 | 0.167 | 0.167 | 49.125 | 491.253 |
| sparse64 | 1,000,000 | PHP list (no dedup) | 0.875 | 0.042 | n/a | 49.993 | 499.932 |
| sparse64 | 1,000,000 | Native accelerator | 21.459 | 0.917 | 0.333 | 61.140 | 611.404 |
| sparse64 | 1,000,000 | Package PHP | 5.333 | 1.166 | 0.917 | 63.357 | 633.574 |

## Where a saved update spends its time

Per-phase medians need not add to the median complete cycle. Save includes serializing the updated set and Base64 encoding it. Payload size refers to the original saved set. RSS is whole-process peak immediately after construction, before later probes.

| Workload | IDs | Backend | Restore (ms) | Add (µs) | Save (ms) | Build peak RSS (MiB) |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| dense32 | 3 | PHP array set | 0.000 | 0.125 | 0.000 | 27.66 |
| dense32 | 3 | PHP list (no dedup) | 0.000 | 0.125 | 0.000 | 27.78 |
| dense32 | 3 | Native accelerator | 0.004 | 0.417 | 0.001 | 27.91 |
| dense32 | 3 | Package PHP | 0.002 | 0.667 | 0.001 | 27.66 |
| dense32 | 100,000 | PHP array set | 1.667 | 1.542 | 1.047 | 34.34 |
| dense32 | 100,000 | PHP list (no dedup) | 2.052 | 1.292 | 1.409 | 30.42 |
| dense32 | 100,000 | Native accelerator | 0.124 | 1.917 | 0.005 | 28.12 |
| dense32 | 100,000 | Package PHP | 0.120 | 4.583 | 0.005 | 28.59 |
| dense32 | 1,000,000 | PHP array set | 17.826 | 2.291 | 14.288 | 68.33 |
| dense32 | 1,000,000 | PHP list (no dedup) | 23.227 | 2.791 | 19.512 | 44.08 |
| dense32 | 1,000,000 | Native accelerator | 0.430 | 2.084 | 0.030 | 28.45 |
| dense32 | 1,000,000 | Package PHP | 0.430 | 3.917 | 0.037 | 28.95 |
| dense64 | 3 | PHP array set | 0.000 | 0.166 | 0.000 | 27.69 |
| dense64 | 3 | PHP list (no dedup) | 0.000 | 0.125 | 0.000 | 27.64 |
| dense64 | 3 | Native accelerator | 0.004 | 0.541 | 0.001 | 27.91 |
| dense64 | 3 | Package PHP | 0.002 | 0.875 | 0.001 | 27.62 |
| dense64 | 100,000 | PHP array set | 2.092 | 1.292 | 1.357 | 34.31 |
| dense64 | 100,000 | PHP list (no dedup) | 2.640 | 1.125 | 2.113 | 30.39 |
| dense64 | 100,000 | Native accelerator | 0.140 | 2.959 | 0.005 | 28.08 |
| dense64 | 100,000 | Package PHP | 0.114 | 5.500 | 0.007 | 27.62 |
| dense64 | 1,000,000 | PHP array set | 21.847 | 2.583 | 17.043 | 68.31 |
| dense64 | 1,000,000 | PHP list (no dedup) | 27.893 | 2.125 | 22.238 | 44.12 |
| dense64 | 1,000,000 | Native accelerator | 0.452 | 2.542 | 0.034 | 28.34 |
| dense64 | 1,000,000 | Package PHP | 0.402 | 4.625 | 0.045 | 27.84 |
| sparse32 | 3 | PHP array set | 0.000 | 0.125 | 0.000 | 27.67 |
| sparse32 | 3 | PHP list (no dedup) | 0.000 | 0.125 | 0.000 | 27.69 |
| sparse32 | 3 | Native accelerator | 0.003 | 0.375 | 0.001 | 27.95 |
| sparse32 | 3 | Package PHP | 0.002 | 0.667 | 0.001 | 27.72 |
| sparse32 | 100,000 | PHP array set | 1.767 | 1.250 | 1.132 | 34.33 |
| sparse32 | 100,000 | PHP list (no dedup) | 2.300 | 1.250 | 1.548 | 30.39 |
| sparse32 | 100,000 | Native accelerator | 5.723 | 4.333 | 0.041 | 28.30 |
| sparse32 | 100,000 | Package PHP | 5.663 | 5.750 | 0.555 | 28.09 |
| sparse32 | 1,000,000 | PHP array set | 24.234 | 2.375 | 15.131 | 68.33 |
| sparse32 | 1,000,000 | PHP list (no dedup) | 26.537 | 2.000 | 19.413 | 44.19 |
| sparse32 | 1,000,000 | Native accelerator | 59.269 | 33.750 | 0.736 | 31.22 |
| sparse32 | 1,000,000 | Package PHP | 55.771 | 9.459 | 6.444 | 34.30 |
| sparse64 | 3 | PHP array set | 0.000 | 0.125 | 0.000 | 27.75 |
| sparse64 | 3 | PHP list (no dedup) | 0.000 | 0.125 | 0.000 | 27.86 |
| sparse64 | 3 | Native accelerator | 0.005 | 0.583 | 0.001 | 27.95 |
| sparse64 | 3 | Package PHP | 0.003 | 0.958 | 0.001 | 27.78 |
| sparse64 | 100,000 | PHP array set | 2.121 | 1.209 | 1.385 | 34.33 |
| sparse64 | 100,000 | PHP list (no dedup) | 2.733 | 1.250 | 2.176 | 30.36 |
| sparse64 | 100,000 | Native accelerator | 6.032 | 5.500 | 0.118 | 28.55 |
| sparse64 | 100,000 | Package PHP | 5.625 | 7.375 | 0.539 | 28.11 |
| sparse64 | 1,000,000 | PHP array set | 31.240 | 2.625 | 16.730 | 68.34 |
| sparse64 | 1,000,000 | PHP list (no dedup) | 28.010 | 2.292 | 22.710 | 44.12 |
| sparse64 | 1,000,000 | Native accelerator | 60.379 | 9.500 | 1.569 | 32.19 |
| sparse64 | 1,000,000 | Package PHP | 56.643 | 9.417 | 6.710 | 34.28 |

## Method and limits

- Construction streams one set of N unique, ascending IDs: `2*i+1` for dense inputs or `2048*i + (crc32(pack('V', i)) & 2047)` for sparse inputs. The 64-bit cases add `2^40`. All IDs fit positive PHP integers; these cases do not model decimal-string parsing or IDs scattered throughout uint64. Each 64-bit fixture lies within one upper-32-bit bucket.
- Bitmap construction uses the public bulk constructor, array/list construction uses a foreach loop. ID generation and package validation are included. No range shortcut or prebuilt large input array is used. Autoload, FFI loading, and tiny-fixture codec initialization are primed outside construction.
- The first new ID is inserted before calling count(), iteration, serialization, or any other operation on the full set. It is one observation per process, so it is noisier than warmed timings.
- Each resident metric is the median of 256 individually timed mutations, then the median across processes. New IDs above the current maximum and free IDs within existing containers are tested. After each changed membership, removal restores cardinality outside the timer. No clone or shared PHP array is retained. Reusing allocated capacity means these warm probes do not characterize allocation spikes, copy-on-write snapshots, or full-container promotion.
- Both hrtime() overhead and a shared benchmark dispatch function are included and not subtracted. The back-to-back hrtime() baseline is recorded. Sub-microsecond measurements have limited resolution; they are latency probes, not request-throughput measurements or a guarantee of an individual event's latency.
- Persistence metrics are medians of 3 independent restores of the same saved snapshot per process. No resident source set is kept. The complete cycle includes strict Base64 decoding, public deserialization/validation, one or 100 scalar additions, serialization, and Base64 encoding. It excludes result disposal, correctness checks, storage I/O, queueing, rule matching, network transfer, and concurrency coordination. No chunking is assumed.
- PHP arrays/lists use serialize()/unserialize(allowed_classes=false); bitmaps use toPortableBytes()/fromPortableBytes(). Native decoding includes PHP format validation. Native mode is explicit and cannot fall back to PHP.
- Every worker checks duplicate semantics, every mutation's cardinality, exact initial/retained membership, all newly added IDs, and published round trips. Every ID in the first one-ID and 100-ID published snapshot is checked. All correctness checks run outside timings. Raw resident probes and persistence phases are retained.
- Process RSS includes the interpreter and native allocations. PHP heap deltas exclude native allocation and are not a comparable bitmap memory metric. Encoded bytes measure storage size, not RAM.
- PHP CLI uses FFI enabled, OPcache/JIT disabled, and a 1 GiB limit. Runtime settings, source snapshots/hashes, library hash, commands, medians, min/max, and standard deviations are in results.json/summary.csv. Shared-machine thermal/load variation remains.
- This is a package CPU/storage benchmark. It does not test a search-engine plugin, its wire format, or its performance; the saved bitmap format accepted by a consumer must be verified separately.

## Reproduce

```sh
composer install
bash tools/build-native.sh
python3 benchmarks/membership.py --sizes 3 100000 1000000 --samples 3 --probes 256 --cycles 3 --output .build/membership-new
```

Use `--php /absolute/path/to/herd/php84` or `--library /absolute/path/to/libroaring.dylib` to override discovery.

[Raw samples and provenance](results.json) · [Summary with ranges](summary.csv) · Exact measured sources: source/
