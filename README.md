# Roaring Bitmap for PHP

[![Tests](https://github.com/petericebear/roaringbitmap-php/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/petericebear/roaringbitmap-php/actions/workflows/tests.yml)

Compressed sets of integer IDs for PHP, with a pure PHP implementation and optional native acceleration. Use Roaring bitmaps to combine search results, apply filters, compare user segments, or store sets of IDs in a portable binary format.

- Union, intersection, difference, and membership queries.
- Unsigned 32-bit and 64-bit IDs, including values above `PHP_INT_MAX`.
- Rank/select queries, sorted iteration, and independent copies.
- One API for PHP and native backends, with automatic fallback to PHP.

## Installation

Requires **64-bit PHP 8.2–8.x** and Composer. The PHP backend needs no additional extensions.

Install the current development branch from GitHub in your application's directory:

```sh
composer config repositories.roaring-bitmap vcs https://github.com/petericebear/roaringbitmap-php
composer require peter/roaring-bitmap:dev-main
```

For a source checkout and development setup, see [Contributing](CONTRIBUTING.md).

## Quick start

```php
<?php

require 'vendor/autoload.php';

use RoaringBitmap\Bitmap;

$activeUsers = new Bitmap([10, 20, 30, 1_000_000]);
$subscribers = new Bitmap([20, 30, 40]);

$audience = $activeUsers->intersect($subscribers);

$audience->toArray();       // [20, 30]
count($audience);           // 2
$audience->contains(20);    // true

$all = $activeUsers->union($subscribers);
$nonSubscribers = $activeUsers->difference($subscribers);
```

Set operations return new bitmaps and leave their inputs unchanged. IDs are unique and iterate in ascending order. `Bitmap` accepts integers from `0` through `4,294,967,295`; use `Bitmap64` for larger IDs.

### Add, remove, and store IDs

```php
$audience->add(50);             // true if the ID was added
$audience->remove(20);          // true if the ID was present
$audience->addRange(100, 200);   // Adds 100 through 199

$bytes = $audience->toPortableBytes();
$restored = Bitmap::fromPortableBytes($bytes);
$restored->equals($audience);   // true
```

Portable bytes can be read by either backend. Both classes also support PHP serialization. See the [API and format reference](docs/api.md) for range semantics, iteration snapshots, and interoperability details.

### Rank and select

```php
$ids = new Bitmap([10, 20, 40]);

$ids->rank(25);    // 2: number of IDs less than or equal to 25
$ids->select(1);   // 20: ID at zero-based position 1
$ids->select(3);   // null: position is outside the set
```

### Unsigned 64-bit IDs

```php
use RoaringBitmap\Bitmap64;

$largeIds = new Bitmap64([42, '9223372036854775808', '18446744073709551615']);

$largeIds->contains('18446744073709551615'); // true
$largeIds->rank('9223372036854775808');      // 2
$largeIds->select(2);                       // '18446744073709551615'
```

Pass large IDs as decimal strings to preserve their exact values. `Bitmap64` accepts nonnegative integers or decimal strings and always returns IDs as decimal strings. Its API mirrors `Bitmap`, including set operations and portable serialization; operands must use the same bitmap width. [Read the 64-bit API details](docs/api.md#unsigned-64-bit-ids).

## Choosing a backend

`Backend::Auto` is the default: it uses native support when available and falls back to PHP otherwise. You can explicitly select the PHP backend:

```php
use RoaringBitmap\Backend;

$bitmap = new Bitmap([1, 2, 3], backend: Backend::Php);
$bitmap->backend()->value; // 'php'
```

The native backend uses **CRoaring through `ext-ffi`** and requires a compatible shared library. The included Linux/macOS build helper provides CRoaring 5.4.0 with an index for repeated 64-bit rank/select queries. Choose `Backend::Native` to require native support; it throws `NativeBackendUnavailable` if the library cannot load.

Follow the [native setup guide](docs/native-backend.md) for building the library, configuring its path, and enabling FFI for CLI or PHP-FPM. Set operations can combine PHP and native bitmaps of the same width; the result uses the left operand's backend.

## Performance

Performance depends on ID distribution, operation, and backend. PHP arrays can be cheaper to construct, while Roaring can reduce memory use and accelerate repeated set operations. Compare the workloads relevant to your application.

The results below compare PHP arrays, the package's PHP backend, and native acceleration. Membership benchmarks measure building a set, adding one ID later, and restoring and republishing a saved snapshot. The other workloads cover set operations and sparse 64-bit rank/select. CI refreshes these measurements automatically; [benchmark documentation](benchmarks/README.md) covers methodology and reproduction.

For tag membership and incremental collection, start with the [build-and-add benchmark](benchmarks/results/2026-11-04-membership/report.md). It compares append-only lists and deduplicating sets, separates live additions from saved updates, and measures publishing once per 100 new IDs. A fast intersection does not imply a faster single-item insertion.

If the result must be a PHP array, see the [array round-trip benchmark](benchmarks/results/2026-11-04-array-roundtrip/report.md). It measures the full array → bitmap → add → array path, and restoring a Base64 bitmap before adding and expanding it. Both PHP and native results include `toArray()`; the comparison also records `Bitmap64`'s decimal-string output and optional conversion back to integers.

The [bulk loading and sparse operations report](benchmarks/results/2026-11-04-bulk-comparison/report.md) compares package revisions, including partial-overlap workloads where PHP arrays remain faster than the PHP backend.

<details>
<summary>Latest benchmark results and downloads</summary>

<!-- benchmark:latest:start -->
Measured 2026-11-04T17:03:22.980476+01:00 with PHP 8.4.27 on Linux-7.0.0-1017-azure-x86_64-with-glibc2.39; 3 samples per configuration. Source: [47be656](https://github.com/petericebear/roaringbitmap-php/commit/47be656bace84b3e50047caaa886f7b56c329492).

Array → bitmap → add one → array, compared with appending directly to an existing, unshared PHP list. Input creation is excluded. The new ID is absent and greater than all existing IDs.

| Workload | IDs | Array append (µs) | PHP round trip (ms) | Native round trip (ms) |
| --- | ---: | ---: | ---: | ---: |
| dense32 | 1,000,000 | 0.371 | 278.942 | 132.916 |
| sparse32 | 1,000,000 | 0.401 | 254.077 | 213.642 |
| dense64 | 1,000,000 | 0.451 | 643.377 | 195.139 |
| sparse64 | 1,000,000 | 0.531 | 893.347 | 224.408 |

External input is a **saved Base64 bitmap**, not JSON. Both approaches below decode the same saved value with the stated backend and return the same typed array. Complete times are milliseconds; storage/network I/O is excluded.

| Workload | IDs | Backend | Decode → bitmap add → array (ms) | Decode → array → append (ms) |
| --- | ---: | --- | ---: | ---: |
| dense32 | 1,000,000 | Package PHP | 100.549 | 98.963 |
| dense32 | 1,000,000 | Native accelerator | 72.617 | 71.398 |
| sparse32 | 1,000,000 | Package PHP | 202.799 | 202.152 |
| sparse32 | 1,000,000 | Native accelerator | 218.741 | 218.687 |
| dense64 | 1,000,000 | Package PHP | 211.885 | 213.864 |
| dense64 | 1,000,000 | Native accelerator | 106.909 | 107.588 |
| sparse64 | 1,000,000 | Package PHP | 310.489 | 311.389 |
| sparse64 | 1,000,000 | Native accelerator | 186.630 | 186.769 |

`Bitmap64::toArray()` returns decimal strings; the original PHP-list baseline retains integers. The full report separately measures conversion back to integers for fixtures within PHP_INT_MAX. Append permits duplicates; these fixtures add a known-new ID. Medians below 0.1 µs are shown as <0.1, not zero. Single append timings include the timer floor and are not throughput measurements. Saved-bitmap timings include public format validation and complete array materialization.

Membership updates: build one set, then add one new ID. In-memory additions are in **microseconds**; construction and restore → add → save are in **milliseconds**. The saved cycle includes Base64 and rewrites the entire snapshot; database/network time is excluded.

| Workload | Existing IDs | Backend | Build (ms) | Add one in memory (µs) | Restore + add + save (ms) | Saved Base64 (KiB) |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| dense32 | 1,000,000 | PHP list (no dedup) | 96.730 | 0.100 | 118.813 | 23871.55 |
| dense32 | 1,000,000 | PHP array set | 118.720 | 0.060 | 94.554 | 17505.80 |
| dense32 | 1,000,000 | Package PHP | 245.605 | 0.280 | 0.195 | 331.00 |
| dense32 | 1,000,000 | Native accelerator | 129.462 | 0.251 | 0.288 | 331.00 |
| sparse32 | 1,000,000 | PHP list (no dedup) | 159.266 | 0.090 | 119.825 | 27794.75 |
| sparse32 | 1,000,000 | PHP array set | 233.221 | 0.181 | 117.869 | 21429.01 |
| sparse32 | 1,000,000 | Package PHP | 250.003 | 0.520 | 80.457 | 2929.70 |
| sparse32 | 1,000,000 | Native accelerator | 195.415 | 0.661 | 72.912 | 2929.70 |
| dense64 | 1,000,000 | PHP list (no dedup) | 94.272 | 0.101 | 133.872 | 32407.43 |
| dense64 | 1,000,000 | PHP array set | 117.945 | 0.060 | 127.446 | 26041.68 |
| dense64 | 1,000,000 | Package PHP | 507.543 | 0.501 | 0.386 | 331.02 |
| dense64 | 1,000,000 | Native accelerator | 152.630 | 0.460 | 0.312 | 331.02 |
| sparse64 | 1,000,000 | PHP list (no dedup) | 158.197 | 0.100 | 132.789 | 32407.43 |
| sparse64 | 1,000,000 | PHP array set | 199.028 | 0.190 | 135.096 | 26041.68 |
| sparse64 | 1,000,000 | Package PHP | 775.475 | 0.731 | 80.217 | 2929.71 |
| sparse64 | 1,000,000 | Native accelerator | 244.562 | 0.541 | 78.046 | 2929.71 |

The list baseline uses `$ids[] = $id` and retains duplicates. The array-set baseline uses `$ids[$id] = true` and deduplicates like a bitmap. Arrays use PHP serialization; bitmaps use their portable format. These persistence formats are not interchangeable. `dense64` uses positive integer IDs above 2^40, with the same gaps as `dense32`; `sparse64` similarly offsets `sparse32`. Single-add timings include timer and benchmark dispatch overhead; see the raw timer floor and sample ranges.

Median CLI timings; build creates both input sets. RSS is whole-process peak after construction.

| IDs/input | Workload | Backend | Build (ms) | Intersection (ms) | Peak RSS (MiB) |
| ---: | --- | --- | ---: | ---: | ---: |
| 1,000,000 | dense | PHP array | 206.01 | 23.5996 | 132.00 |
| 1,000,000 | dense | Package PHP | 497.97 | 0.8799 | 54.52 |
| 1,000,000 | dense | Native accelerator | 232.84 | 0.0138 | 53.52 |
| 1,000,000 | sparse_mixed | PHP array | 392.04 | 39.5543 | 131.77 |
| 1,000,000 | sparse_mixed | Package PHP | 473.40 | 3.6982 | 66.52 |
| 1,000,000 | sparse_mixed | Native accelerator | 359.54 | 1.0578 | 60.48 |
| 1,000,000 | ranges | PHP array | 176.65 | 23.4142 | 108.99 |
| 1,000,000 | ranges | Package PHP | 0.32 | 0.0221 | 52.65 |
| 1,000,000 | ranges | Native accelerator | 0.01 | 0.0017 | 52.48 |

Sparse unsigned 64-bit order queries: milliseconds per 1,000 warm queries. The accelerator column includes the package's C index; stock CRoaring uses direct scans.

| IDs | Query | Package PHP | Stock CRoaring | Native accelerator | Stock / accelerator |
| ---: | --- | ---: | ---: | ---: | ---: |
| 1,000,000 | rank | 2.7483 | 246.1905 | 1.5432 | 159.53× |
| 1,000,000 | select | 2.7360 | 164.2837 | 1.8787 | 87.45× |

Partial overlap inside every sparse container (1,048,576 IDs/input): warm intersection time in milliseconds. These inputs cannot use the identical-container shortcut.

| IDs/container | PHP array | Package PHP | Native accelerator |
| ---: | ---: | ---: | ---: |
| 32 | 38.692 | 87.637 | 2.346 |
| 256 | 24.969 | 65.289 | 0.557 |
| 4,096 | 21.814 | 62.923 | 0.456 |

These are synthetic CLI workloads on shared hardware. The construction/set-algebra suite uses fresh processes. The partial-overlap check times warm intersections in one process, with inputs prepared beforehand. Order samples use fresh bitmaps in one process per mode/size. Input generation is included in set construction and excluded in order construction. Native mode is required; exact-result checks run outside the timers. Cold queries, mutation costs, other operations, settings, hashes, and sample ranges are retained in the full reports. Compare backends within the same run; CI host variation can affect comparisons between runs.

[Download full benchmark reports and raw samples](https://github.com/petericebear/roaringbitmap-php/actions/runs/41862056583/artifacts/13340647855) (GitHub sign-in required) · [CI run](https://github.com/petericebear/roaringbitmap-php/actions/runs/41862056583). Only the three newest benchmark bundles are retained; artifacts also expire after 90 days. The measured snapshot remains readable here.
<!-- benchmark:latest:end -->

</details>

## Documentation

- [API and data formats](docs/api.md): operations, ranges, unsigned IDs, and serialization.
- [Native backend setup](docs/native-backend.md): build and deployment instructions.
- [Examples](examples/): basic usage and 64-bit operations.
- [Benchmarks](benchmarks/README.md): measurements, raw data, and reproduction.
- [Contributing](CONTRIBUTING.md): local tests, CI, and issue reporting.

Built on the [Roaring bitmap format](https://roaringbitmap.org/) and optionally powered by [CRoaring](https://github.com/RoaringBitmap/CRoaring).

## License

The package is [MIT-licensed](LICENSE). CRoaring and the upstream [interoperability fixtures](tests/fixtures/LICENSE.upstream) are licensed under Apache-2.0.
