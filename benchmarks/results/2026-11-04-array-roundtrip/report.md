# Array round trips and saved Base64 bitmap expansion

Measured 2026-11-04T16:46:19.811954+01:00 through 2026-11-04T16:48:08.383636+01:00 on macOS-26.7.1-arm64-arm-64bit-Mach-O, PHP 8.4.27. Each configuration uses 3 fresh processes and 3 trials per process. Tables show medians of each process's trial median.

## Findings on this machine

For one million dense IDs:

- Starting from an existing integer array, the 32-bit bitmap round trip took **210.48 ms with package PHP** and **65.12 ms with native**. Direct append took **0.125 µs**. Importing and exporting the full set dominates the bitmap path.
- For 64-bit IDs, the round trip took **608.35 ms PHP / 122.60 ms native**, returning decimal strings as documented by Bitmap64. Including the separately measured conversion back to integer values increased the totals to **631.09 ms / 146.01 ms**. Direct array append was below the report's 0.1 µs display threshold.
- Starting from the same saved Base64 bitmap, decoding, adding, and returning an array took **167.62 ms PHP / 63.76 ms native** for 64-bit IDs. Expanding first and appending to the resulting array took **166.88 ms / 62.69 ms**. The small difference between update orders is much less than the conversion cost; it should not be treated as a reliable application-wide advantage.
- In that dense native 64-bit saved-input case, array materialization accounted for approximately **63 ms of the 64 ms total**. With sparse 64-bit input the complete native path took **122.53 ms**, split between approximately 57 ms restoring/validating and 64 ms materializing the array.

The already-existing-array baseline excludes retrieval and decoding because the array is already available. For a Base64 value from a database, both comparison paths include decoding the same saved bitmap. Database/network time and writing a new value back remain outside this measurement.

## Measurements

Array → bitmap → add one → array, compared with appending directly to an existing, unshared PHP list. Input creation is excluded. The new ID is absent and greater than all existing IDs.

| Workload | IDs | Array append (µs) | PHP round trip (ms) | Native round trip (ms) |
| --- | ---: | ---: | ---: | ---: |
| dense32 | 3 | <0.1 | 0.004 | 0.004 |
| dense32 | 100,000 | <0.1 | 20.533 | 6.011 |
| dense32 | 1,000,000 | 0.125 | 210.480 | 65.118 |
| sparse32 | 3 | <0.1 | 0.003 | 0.003 |
| sparse32 | 100,000 | <0.1 | 18.831 | 7.100 |
| sparse32 | 1,000,000 | <0.1 | 192.039 | 110.195 |
| dense64 | 3 | <0.1 | 0.004 | 0.008 |
| dense64 | 100,000 | <0.1 | 62.171 | 12.437 |
| dense64 | 1,000,000 | <0.1 | 608.351 | 122.603 |
| sparse64 | 3 | <0.1 | 0.005 | 0.006 |
| sparse64 | 100,000 | <0.1 | 90.285 | 13.428 |
| sparse64 | 1,000,000 | <0.1 | 896.999 | 137.882 |

External input is a **saved Base64 bitmap**, not JSON. Both approaches below decode the same saved value with the stated backend and return the same typed array. Complete times are milliseconds; storage/network I/O is excluded.

| Workload | IDs | Backend | Decode → bitmap add → array (ms) | Decode → array → append (ms) |
| --- | ---: | --- | ---: | ---: |
| dense32 | 3 | Package PHP | 0.003 | 0.003 |
| dense32 | 3 | Native accelerator | 0.004 | 0.004 |
| dense32 | 100,000 | Package PHP | 6.765 | 6.764 |
| dense32 | 100,000 | Native accelerator | 1.994 | 1.957 |
| dense32 | 1,000,000 | Package PHP | 66.837 | 66.969 |
| dense32 | 1,000,000 | Native accelerator | 22.824 | 23.128 |
| sparse32 | 3 | Package PHP | 0.003 | 0.003 |
| sparse32 | 3 | Native accelerator | 0.004 | 0.004 |
| sparse32 | 100,000 | Package PHP | 15.569 | 15.351 |
| sparse32 | 100,000 | Native accelerator | 7.996 | 7.761 |
| sparse32 | 1,000,000 | Package PHP | 161.304 | 159.751 |
| sparse32 | 1,000,000 | Native accelerator | 118.634 | 118.326 |
| dense64 | 3 | Package PHP | 0.004 | 0.004 |
| dense64 | 3 | Native accelerator | 0.007 | 0.008 |
| dense64 | 100,000 | Package PHP | 17.625 | 17.149 |
| dense64 | 100,000 | Native accelerator | 6.611 | 6.524 |
| dense64 | 1,000,000 | Package PHP | 167.615 | 166.883 |
| dense64 | 1,000,000 | Native accelerator | 63.764 | 62.691 |
| sparse64 | 3 | Package PHP | 0.005 | 0.004 |
| sparse64 | 3 | Native accelerator | 0.006 | 0.006 |
| sparse64 | 100,000 | Package PHP | 26.238 | 26.105 |
| sparse64 | 100,000 | Native accelerator | 12.245 | 12.212 |
| sparse64 | 1,000,000 | Package PHP | 264.768 | 265.302 |
| sparse64 | 1,000,000 | Native accelerator | 122.527 | 122.807 |

`Bitmap64::toArray()` returns decimal strings; the original PHP-list baseline retains integers. The full report separately measures conversion back to integers for fixtures within PHP_INT_MAX. Append permits duplicates; these fixtures add a known-new ID. Medians below 0.1 µs are shown as <0.1, not zero. Single append timings include the timer floor and are not throughput measurements. Saved-bitmap timings include public format validation and complete array materialization.

## Array round-trip phases and integer output

The total includes constructing a bitmap from the existing input array, adding one ID, and calling toArray(). The optional integer-output total adds an explicitly measured array_map("intval", output) for Bitmap64. All fixture IDs fit PHP_INT_MAX; this conversion is not appropriate for larger unsigned values. Medians of individual phases need not add to the median total.

| Workload | IDs | Backend | Import (ms) | Add (µs) | toArray (ms) | Optional integer conversion (ms) | Integer-output total (ms) |
| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: |
| dense32 | 3 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| dense32 | 100,000 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| dense32 | 1,000,000 | PHP array append | 0.000 | 0.125 | 0.000 | 0.000 | 0.000 |
| dense64 | 3 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| dense64 | 100,000 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| dense64 | 1,000,000 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| sparse32 | 3 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| sparse32 | 100,000 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| sparse32 | 1,000,000 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| sparse64 | 3 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| sparse64 | 100,000 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| sparse64 | 1,000,000 | PHP array append | 0.000 | <0.1 | 0.000 | 0.000 | 0.000 |
| dense32 | 3 | Native accelerator | 0.002 | 0.250 | 0.001 | 0.000 | 0.004 |
| dense32 | 100,000 | Native accelerator | 4.117 | 1.666 | 1.900 | 0.000 | 6.011 |
| dense32 | 1,000,000 | Native accelerator | 41.734 | 2.917 | 23.384 | 0.000 | 65.118 |
| dense64 | 3 | Native accelerator | 0.002 | 0.500 | 0.003 | 0.000 | 0.009 |
| dense64 | 100,000 | Native accelerator | 5.978 | 2.459 | 6.358 | 2.275 | 14.620 |
| dense64 | 1,000,000 | Native accelerator | 60.033 | 2.833 | 63.031 | 23.013 | 146.011 |
| sparse32 | 3 | Native accelerator | 0.002 | 0.250 | 0.001 | 0.000 | 0.003 |
| sparse32 | 100,000 | Native accelerator | 4.628 | 2.000 | 2.392 | 0.000 | 7.100 |
| sparse32 | 1,000,000 | Native accelerator | 46.612 | 5.250 | 63.545 | 0.000 | 110.195 |
| sparse64 | 3 | Native accelerator | 0.002 | 0.459 | 0.003 | 0.000 | 0.006 |
| sparse64 | 100,000 | Native accelerator | 6.624 | 5.375 | 6.800 | 2.322 | 15.645 |
| sparse64 | 1,000,000 | Native accelerator | 72.120 | 17.792 | 65.606 | 22.902 | 160.679 |
| dense32 | 3 | Package PHP | 0.002 | 0.709 | 0.001 | 0.000 | 0.004 |
| dense32 | 100,000 | Package PHP | 13.888 | 5.625 | 6.660 | 0.000 | 20.533 |
| dense32 | 1,000,000 | Package PHP | 142.567 | 2.125 | 67.912 | 0.000 | 210.480 |
| dense64 | 3 | Package PHP | 0.002 | 0.625 | 0.002 | 0.000 | 0.005 |
| dense64 | 100,000 | Package PHP | 45.267 | 1.791 | 16.945 | 2.280 | 64.429 |
| dense64 | 1,000,000 | Package PHP | 440.635 | 0.750 | 168.383 | 22.545 | 631.093 |
| sparse32 | 3 | Package PHP | 0.001 | 0.625 | 0.001 | 0.000 | 0.003 |
| sparse32 | 100,000 | Package PHP | 8.247 | 3.167 | 10.486 | 0.000 | 18.831 |
| sparse32 | 1,000,000 | Package PHP | 85.690 | 4.708 | 105.431 | 0.000 | 192.039 |
| sparse64 | 3 | Package PHP | 0.002 | 0.792 | 0.002 | 0.000 | 0.005 |
| sparse64 | 100,000 | Package PHP | 69.380 | 0.708 | 21.247 | 2.321 | 92.512 |
| sparse64 | 1,000,000 | Package PHP | 680.371 | 0.792 | 215.188 | 22.672 | 919.306 |

## Saved-bitmap phases

| Workload | IDs | Backend | Add location | Base64 (ms) | Restore/validate (ms) | Add (µs) | toArray (ms) |
| --- | ---: | --- | --- | ---: | ---: | ---: | ---: |
| dense32 | 3 | Native accelerator | array | 0.000 | 0.003 | <0.1 | 0.001 |
| dense32 | 3 | Native accelerator | bitmap | 0.000 | 0.003 | 0.375 | 0.001 |
| dense32 | 100,000 | Native accelerator | array | 0.003 | 0.116 | 0.166 | 1.850 |
| dense32 | 100,000 | Native accelerator | bitmap | 0.003 | 0.113 | 2.209 | 1.867 |
| dense32 | 1,000,000 | Native accelerator | array | 0.021 | 0.365 | 0.125 | 22.747 |
| dense32 | 1,000,000 | Native accelerator | bitmap | 0.021 | 0.394 | 1.334 | 22.448 |
| dense64 | 3 | Native accelerator | array | 0.000 | 0.004 | <0.1 | 0.002 |
| dense64 | 3 | Native accelerator | bitmap | 0.000 | 0.004 | 0.542 | 0.002 |
| dense64 | 100,000 | Native accelerator | array | 0.004 | 0.137 | 0.250 | 6.388 |
| dense64 | 100,000 | Native accelerator | bitmap | 0.003 | 0.129 | 2.583 | 6.473 |
| dense64 | 1,000,000 | Native accelerator | array | 0.024 | 0.398 | 0.292 | 62.232 |
| dense64 | 1,000,000 | Native accelerator | bitmap | 0.023 | 0.393 | 2.416 | 63.349 |
| sparse32 | 3 | Native accelerator | array | 0.000 | 0.003 | <0.1 | 0.001 |
| sparse32 | 3 | Native accelerator | bitmap | 0.000 | 0.003 | 0.375 | 0.001 |
| sparse32 | 100,000 | Native accelerator | array | 0.020 | 5.431 | 0.250 | 2.319 |
| sparse32 | 100,000 | Native accelerator | bitmap | 0.020 | 5.594 | 4.208 | 2.381 |
| sparse32 | 1,000,000 | Native accelerator | array | 0.331 | 55.352 | 0.334 | 62.335 |
| sparse32 | 1,000,000 | Native accelerator | bitmap | 0.296 | 55.940 | 15.500 | 63.221 |
| sparse64 | 3 | Native accelerator | array | 0.000 | 0.004 | <0.1 | 0.002 |
| sparse64 | 3 | Native accelerator | bitmap | 0.000 | 0.004 | 0.542 | 0.002 |
| sparse64 | 100,000 | Native accelerator | array | 0.020 | 5.823 | 0.334 | 6.396 |
| sparse64 | 100,000 | Native accelerator | bitmap | 0.020 | 5.858 | 4.875 | 6.419 |
| sparse64 | 1,000,000 | Native accelerator | array | 0.326 | 58.080 | 0.250 | 64.791 |
| sparse64 | 1,000,000 | Native accelerator | bitmap | 0.310 | 57.489 | 6.000 | 63.948 |
| dense32 | 3 | Package PHP | array | 0.000 | 0.002 | <0.1 | 0.001 |
| dense32 | 3 | Package PHP | bitmap | 0.000 | 0.002 | 0.792 | 0.001 |
| dense32 | 100,000 | Package PHP | array | 0.003 | 0.104 | 0.125 | 6.658 |
| dense32 | 100,000 | Package PHP | bitmap | 0.003 | 0.103 | 4.542 | 6.630 |
| dense32 | 1,000,000 | Package PHP | array | 0.021 | 0.356 | <0.1 | 66.591 |
| dense32 | 1,000,000 | Package PHP | bitmap | 0.021 | 0.351 | 3.417 | 66.474 |
| dense64 | 3 | Package PHP | array | 0.000 | 0.002 | <0.1 | 0.001 |
| dense64 | 3 | Package PHP | bitmap | 0.000 | 0.002 | 0.792 | 0.002 |
| dense64 | 100,000 | Package PHP | array | 0.003 | 0.120 | <0.1 | 17.025 |
| dense64 | 100,000 | Package PHP | bitmap | 0.003 | 0.125 | 5.875 | 17.491 |
| dense64 | 1,000,000 | Package PHP | array | 0.023 | 0.361 | 0.208 | 166.487 |
| dense64 | 1,000,000 | Package PHP | bitmap | 0.021 | 0.344 | 3.959 | 167.256 |
| sparse32 | 3 | Package PHP | array | 0.000 | 0.002 | <0.1 | 0.001 |
| sparse32 | 3 | Package PHP | bitmap | 0.000 | 0.002 | 0.666 | 0.001 |
| sparse32 | 100,000 | Package PHP | array | 0.021 | 5.258 | 0.250 | 10.129 |
| sparse32 | 100,000 | Package PHP | bitmap | 0.020 | 5.385 | 4.208 | 10.035 |
| sparse32 | 1,000,000 | Package PHP | array | 0.336 | 53.662 | <0.1 | 105.910 |
| sparse32 | 1,000,000 | Package PHP | bitmap | 0.317 | 53.214 | 7.250 | 107.521 |
| sparse64 | 3 | Package PHP | array | 0.000 | 0.002 | <0.1 | 0.002 |
| sparse64 | 3 | Package PHP | bitmap | 0.000 | 0.002 | 0.834 | 0.002 |
| sparse64 | 100,000 | Package PHP | array | 0.021 | 5.407 | <0.1 | 20.669 |
| sparse64 | 100,000 | Package PHP | bitmap | 0.020 | 5.447 | 4.625 | 20.685 |
| sparse64 | 1,000,000 | Package PHP | array | 0.319 | 54.809 | <0.1 | 210.342 |
| sparse64 | 1,000,000 | Package PHP | bitmap | 0.301 | 54.768 | 7.333 | 209.986 |

## Method and limits

- These are additional workflows for the membership use case. The earlier saved-update benchmark ended with another compressed snapshot. This benchmark ends with a fully expanded PHP array; the totals measure different work.
- Dense IDs are `2*i+1`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. The 64-bit fixtures add `2^40`. Inputs are unique and sorted, with one upper-32-bit bucket in each 64-bit fixture. Decimal-string input parsing, randomized full-range uint64 values, duplicate new IDs, and unsorted inputs are not modeled.
- The small dense32 case is exactly `[1,3,5]` becoming `[1,3,5,7]`. Every trial verifies every output ID, array key, and PHP value type outside its timer. Bitmap mutation and array-only mutation are checked separately.
- A fresh, unshared input array is prepared outside each array-path timer. Direct append mutates that array in place. The bitmap path imports the same values and replaces the input variable with toArray(); releasing the old input array is included in that assignment. No retained reference introduces an artificial copy-on-write penalty. No result cache or previously constructed full-size bitmap is reused.
- Canonical Base64 fixtures are created with Backend::Php outside the timer. Their hashes must match across all backend processes. Each saved-path trial starts from the original value. Both update orders include strict Base64 decoding, public fromPortableBytes() validation, and toArray(), including its snapshot copy. Update order alternates across trials. There is no re-encoding or write-back in this workflow.
- Both saved paths return identical PHP types: ints for Bitmap and decimal strings for Bitmap64. In the existing-array comparison the direct list retains ints; the bitmap API returns its documented types. Optional integer conversion is timed separately before verification; integer-output totals are the sum of that phase and the primary trial. Raw string outputs remain available during conversion; disposal of the resulting arrays/bitmap is excluded.
- Native mode is explicit, without Auto fallback. Tiny fixtures prime codecs, library loading, and autoload before measurements. PHP CLI enables FFI, disables OPcache/JIT, and uses a 1 GiB memory limit. The raw results record runtime settings, source/library fingerprints, timer-only samples, every trial, and ranges.
- Workers run sequentially and configuration order rotates between repetitions. Timing includes hrtime overhead without subtraction. Very small append times are sensitive to timer resolution and caching; these sizes do not test an array-capacity growth boundary. Shared-machine load/thermal variation remains.
- Storage/database reads, network transfer, search-engine execution, queueing, and publishing a new snapshot are excluded. No production database or external service is contacted. These are generated package-compatible fixtures, not a compatibility check of another producer’s bitmap format.

## Reproduce

```sh
composer install
bash tools/build-native.sh
python3 benchmarks/roundtrip.py --sizes 3 100000 1000000 --samples 3 --cycles 3 --output .build/roundtrip-new
```

Use `--php` and `--library` to select a PHP executable and native library.

[Raw samples and provenance](results.json) · [CSV with ranges](summary.csv) · Exact measured sources: source/
