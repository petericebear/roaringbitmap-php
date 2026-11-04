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

The results below compare PHP arrays, the package's PHP backend, and native acceleration. They also compare stock CRoaring with the native order index for sparse 64-bit rank/select. CI refreshes these measurements automatically; [benchmark documentation](benchmarks/README.md) covers methodology and reproduction.

<details>
<summary>Latest benchmark results and downloads</summary>

<!-- benchmark:latest:start -->
Measured 2026-11-04T10:31:00.914876+01:00 with PHP 8.4.27 on Linux-7.0.0-1017-azure-x86_64-with-glibc2.39; 3 samples per configuration. Source: [97a7d23](https://github.com/petericebear/roaringbitmap-php/commit/97a7d239905821bed1027dbac9133d1f78bd43c3).

Median CLI timings; build creates both input sets. RSS is whole-process peak after construction.

| IDs/input | Workload | Backend | Build (ms) | Intersection (ms) | Peak RSS (MiB) |
| ---: | --- | --- | ---: | ---: | ---: |
| 1,000,000 | dense | PHP array | 244.34 | 27.9169 | 131.83 |
| 1,000,000 | dense | Package PHP | 1172.05 | 1.3999 | 53.32 |
| 1,000,000 | dense | Native accelerator | 346.44 | 0.0201 | 53.38 |
| 1,000,000 | sparse_mixed | PHP array | 417.63 | 47.6104 | 131.76 |
| 1,000,000 | sparse_mixed | Package PHP | 1733.96 | 155.8019 | 66.46 |
| 1,000,000 | sparse_mixed | Native accelerator | 505.17 | 1.3814 | 60.15 |
| 1,000,000 | ranges | PHP array | 208.74 | 27.5475 | 109.15 |
| 1,000,000 | ranges | Package PHP | 0.38 | 0.0957 | 52.65 |
| 1,000,000 | ranges | Native accelerator | 0.02 | 0.0023 | 52.77 |

Sparse unsigned 64-bit order queries: milliseconds per 1,000 warm queries. The accelerator column includes the package's C index; stock CRoaring uses direct scans.

| IDs | Query | Package PHP | Stock CRoaring | Native accelerator | Stock / accelerator |
| ---: | --- | ---: | ---: | ---: | ---: |
| 1,000,000 | rank | 3.5089 | 234.9436 | 2.0410 | 115.11× |
| 1,000,000 | select | 3.4828 | 204.9809 | 2.5593 | 80.09× |

These are synthetic CLI workloads on shared hardware. Set-algebra samples use fresh processes; order samples use fresh bitmaps in one process per mode/size. Input generation is included in set construction and excluded in order construction. Native mode is required; exact-result checks run outside the timers. Cold queries, mutation costs, other operations, settings, hashes, and sample ranges are retained in the full reports. Compare backends within the same run; CI host variation can affect comparisons between runs.

[Download full benchmark reports and raw samples](https://github.com/petericebear/roaringbitmap-php/actions/runs/41807683572/artifacts/13310956840) (GitHub sign-in required) · [CI run](https://github.com/petericebear/roaringbitmap-php/actions/runs/41807683572). Only the three newest benchmark bundles are retained; artifacts also expire after 90 days. The measured snapshot remains readable here.
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
