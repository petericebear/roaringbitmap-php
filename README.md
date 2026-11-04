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

The [bulk loading and sparse operations report](benchmarks/results/2026-11-04-bulk-comparison/report.md) compares package revisions, including partial-overlap workloads where PHP arrays remain faster than the PHP backend.

<details>
<summary>Latest benchmark results and downloads</summary>

<!-- benchmark:latest:start -->
Measured 2026-11-04T15:49:49.697638+01:00 with PHP 8.4.27 on Linux-7.0.0-1017-azure-x86_64-with-glibc2.39; 3 samples per configuration. Source: [e0a948a](https://github.com/petericebear/roaringbitmap-php/commit/e0a948ac4077c4cacca67fd1c387c49c09d4154e).

Membership updates: build one set, then add one new ID. In-memory additions are in **microseconds**; construction and restore → add → save are in **milliseconds**. The saved cycle includes Base64 and rewrites the entire snapshot; database/network time is excluded.

| Workload | Existing IDs | Backend | Build (ms) | Add one in memory (µs) | Restore + add + save (ms) | Saved Base64 (KiB) |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| dense32 | 1,000,000 | PHP list (no dedup) | 122.240 | 0.100 | 138.276 | 23871.55 |
| dense32 | 1,000,000 | PHP array set | 146.853 | 0.080 | 110.673 | 17505.80 |
| dense32 | 1,000,000 | Package PHP | 317.713 | 0.381 | 0.247 | 331.00 |
| dense32 | 1,000,000 | Native accelerator | 160.301 | 0.361 | 0.414 | 331.00 |
| sparse32 | 1,000,000 | PHP list (no dedup) | 193.881 | 0.131 | 140.777 | 27794.75 |
| sparse32 | 1,000,000 | PHP array set | 223.098 | 0.171 | 128.813 | 21429.01 |
| sparse32 | 1,000,000 | Package PHP | 323.285 | 0.681 | 95.776 | 2929.70 |
| sparse32 | 1,000,000 | Native accelerator | 249.785 | 0.882 | 87.856 | 2929.70 |
| dense64 | 1,000,000 | PHP list (no dedup) | 114.984 | 0.160 | 157.191 | 32407.43 |
| dense64 | 1,000,000 | PHP array set | 149.280 | 0.080 | 150.672 | 26041.68 |
| dense64 | 1,000,000 | Package PHP | 664.221 | 0.631 | 0.464 | 331.02 |
| dense64 | 1,000,000 | Native accelerator | 193.886 | 0.611 | 0.421 | 331.02 |
| sparse64 | 1,000,000 | PHP list (no dedup) | 200.205 | 0.191 | 157.167 | 32407.43 |
| sparse64 | 1,000,000 | PHP array set | 248.135 | 0.175 | 178.502 | 26041.68 |
| sparse64 | 1,000,000 | Package PHP | 1017.564 | 0.941 | 96.386 | 2929.71 |
| sparse64 | 1,000,000 | Native accelerator | 322.949 | 0.706 | 91.875 | 2929.71 |

The list baseline uses `$ids[] = $id` and retains duplicates. The array-set baseline uses `$ids[$id] = true` and deduplicates like a bitmap. Arrays use PHP serialization; bitmaps use their portable format. These persistence formats are not interchangeable. `dense64` uses positive integer IDs above 2^40, with the same gaps as `dense32`; `sparse64` similarly offsets `sparse32`. Single-add timings include timer and benchmark dispatch overhead; see the raw timer floor and sample ranges.

Median CLI timings; build creates both input sets. RSS is whole-process peak after construction.

| IDs/input | Workload | Backend | Build (ms) | Intersection (ms) | Peak RSS (MiB) |
| ---: | --- | --- | ---: | ---: | ---: |
| 1,000,000 | dense | PHP array | 238.83 | 27.7092 | 131.80 |
| 1,000,000 | dense | Package PHP | 632.35 | 1.1251 | 54.43 |
| 1,000,000 | dense | Native accelerator | 280.62 | 0.0193 | 53.27 |
| 1,000,000 | sparse_mixed | PHP array | 418.05 | 48.0347 | 131.88 |
| 1,000,000 | sparse_mixed | Package PHP | 609.49 | 4.2605 | 66.49 |
| 1,000,000 | sparse_mixed | Native accelerator | 458.28 | 1.3678 | 60.39 |
| 1,000,000 | ranges | PHP array | 205.79 | 26.8379 | 109.06 |
| 1,000,000 | ranges | Package PHP | 0.37 | 0.0283 | 52.63 |
| 1,000,000 | ranges | Native accelerator | 0.02 | 0.0023 | 52.55 |

Sparse unsigned 64-bit order queries: milliseconds per 1,000 warm queries. The accelerator column includes the package's C index; stock CRoaring uses direct scans.

| IDs | Query | Package PHP | Stock CRoaring | Native accelerator | Stock / accelerator |
| ---: | --- | ---: | ---: | ---: | ---: |
| 1,000,000 | rank | 3.8961 | 336.1890 | 2.1389 | 157.18× |
| 1,000,000 | select | 3.5231 | 204.1790 | 2.5188 | 81.06× |

Partial overlap inside every sparse container (1,048,576 IDs/input): warm intersection time in milliseconds. These inputs cannot use the identical-container shortcut.

| IDs/container | PHP array | Package PHP | Native accelerator |
| ---: | ---: | ---: | ---: |
| 32 | 40.756 | 118.089 | 3.181 |
| 256 | 29.172 | 85.765 | 0.713 |
| 4,096 | 25.853 | 81.438 | 0.356 |

These are synthetic CLI workloads on shared hardware. The construction/set-algebra suite uses fresh processes. The partial-overlap check times warm intersections in one process, with inputs prepared beforehand. Order samples use fresh bitmaps in one process per mode/size. Input generation is included in set construction and excluded in order construction. Native mode is required; exact-result checks run outside the timers. Cold queries, mutation costs, other operations, settings, hashes, and sample ranges are retained in the full reports. Compare backends within the same run; CI host variation can affect comparisons between runs.

[Download full benchmark reports and raw samples](https://github.com/petericebear/roaringbitmap-php/actions/runs/41850505761/artifacts/13334872497) (GitHub sign-in required) · [CI run](https://github.com/petericebear/roaringbitmap-php/actions/runs/41850505761). Only the three newest benchmark bundles are retained; artifacts also expire after 90 days. The measured snapshot remains readable here.
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
