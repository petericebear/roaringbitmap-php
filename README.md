# Roaring Bitmap for PHP

A Composer library with one API and two implementations:

- **PHP:** no additional extensions or shared libraries; packed array and bitset containers.
- **Native:** optional CRoaring acceleration through PHP FFI, including CRoaring's run containers.

`Backend::Auto` uses native support when it can load it and falls back to PHP otherwise. Set operations and portable data work across both implementations. Use `backend()` to see which implementation was selected, or force a backend for predictable deployment behavior.

`Bitmap` stores unsigned **32-bit IDs** (`0` through `4,294,967,295`); `Bitmap64` stores unsigned **64-bit IDs** (`0` through `18,446,744,073,709,551,615`). Both support rank/select and require **64-bit PHP 8.2 or later in the PHP 8 series**. The package is not published on Packagist. `peter/roaring-bitmap` is a provisional package name to change before publication if desired.

## Performance

CI compares ordinary PHP sets, the Composer package's PHP backend, and the optional native accelerator (CRoaring plus the C order index, loaded through FFI). It also measures stock CRoaring separately for 64-bit rank/select. The latest completed benchmark updates the snapshot below and links to one artifact containing full reports, raw samples, CSV, and source snapshots.

<!-- benchmark:latest:start -->
Measured 2026-11-04T10:09:24.072627+01:00 with PHP 8.4.27 on Linux-7.0.0-1017-azure-x86_64-with-glibc2.39; 3 samples per configuration. Source: [58ce871](https://github.com/petericebear/roaringbitmap-php/commit/58ce871c50a687fd135122d49cd786e7471c58c8).

Median CLI timings; build creates both input sets. RSS is whole-process peak after construction.

| IDs/input | Workload | Backend | Build (ms) | Intersection (ms) | Peak RSS (MiB) |
| ---: | --- | --- | ---: | ---: | ---: |
| 1,000,000 | dense | PHP array | 236.42 | 27.0314 | 131.75 |
| 1,000,000 | dense | Package PHP | 1168.11 | 1.3883 | 53.48 |
| 1,000,000 | dense | Native accelerator | 338.79 | 0.0184 | 53.43 |
| 1,000,000 | sparse_mixed | PHP array | 423.74 | 44.3606 | 131.73 |
| 1,000,000 | sparse_mixed | Package PHP | 1736.50 | 156.9406 | 66.34 |
| 1,000,000 | sparse_mixed | Native accelerator | 507.35 | 1.3632 | 60.31 |
| 1,000,000 | ranges | PHP array | 202.51 | 26.6005 | 109.03 |
| 1,000,000 | ranges | Package PHP | 0.39 | 0.0955 | 52.82 |
| 1,000,000 | ranges | Native accelerator | 0.02 | 0.0023 | 52.72 |

Sparse unsigned 64-bit order queries: milliseconds per 1,000 warm queries. The accelerator column includes the package's C index; stock CRoaring uses direct scans.

| IDs | Query | Package PHP | Stock CRoaring | Native accelerator | Stock / accelerator |
| ---: | --- | ---: | ---: | ---: | ---: |
| 1,000,000 | rank | 3.5468 | 337.6129 | 1.9770 | 170.77× |
| 1,000,000 | select | 3.5154 | 205.1769 | 2.4515 | 83.70× |

These are synthetic CLI workloads on shared hardware. Set-algebra samples use fresh processes; order samples use fresh bitmaps in one process per mode/size. Input generation is included in set construction and excluded in order construction. Native mode is required; exact-result checks run outside the timers. Cold queries, mutation costs, other operations, settings, hashes, and sample ranges are retained in the full reports. Compare backends within the same run; CI host variation can affect comparisons between runs.

[Download full benchmark reports and raw samples](https://github.com/petericebear/roaringbitmap-php/actions/runs/41804665058/artifacts/13308674453) (GitHub sign-in required) · [CI run](https://github.com/petericebear/roaringbitmap-php/actions/runs/41804665058). Only the three newest benchmark bundles are retained; artifacts also expire after 90 days. The measured snapshot remains readable here.
<!-- benchmark:latest:end -->

## Start locally

```sh
composer install
php examples/basic.php
composer test
```

No native dependency is installed by Composer. To use this unpublished package from another project, add a Composer `path` repository pointing to this directory and require `peter/roaring-bitmap` at `@dev`.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;

$monday = new Bitmap([10, 20, 30, 1000000]);
$tuesday = new Bitmap([20, 30, 40]);

$returning = $monday->intersect($tuesday);
echo count($returning);                  // 2
print_r($returning->toArray());          // [20, 30]
echo $returning->backend()->value;       // "php" or "native"

$all = $monday->union($tuesday);         // New bitmap; inputs unchanged
$onlyMonday = $monday->difference($tuesday);
$oneDayOnly = $monday->symmetricDifference($tuesday);

$php = new Bitmap([1, 2, 3], backend: Backend::Php);
$php->add(4);                           // true if newly added
$php->remove(2);                        // true if previously present
$php->addRange(100, 200);                // Includes 100; excludes 200
$php->addMany([500, 600]);
var_dump($php->contains(500));          // true

$bytes = $all->toPortableBytes();
$restored = Bitmap::fromPortableBytes($bytes, backend: Backend::Php);
var_dump($restored->equals($all));       // true

foreach ($all as $id) {                 // Ascending snapshot of the set
    echo $id, PHP_EOL;
}
```

## Rank and select

```php
$bitmap = new Bitmap([10, 20, 40]);

$bitmap->rank(25);    // 2: number of IDs less than or equal to 25
$bitmap->rank(20);    // 2: the bound is inclusive
$bitmap->select(1);   // 20: positions start at zero
$bitmap->select(3);   // null: position is outside the set
```

`rank()` accepts the same ID range as the bitmap, whether or not the queried ID is present. `select()` accepts a nonnegative PHP integer position; negative positions throw `InvalidArgumentException`. For an existing position `k`, `rank(select(k)) === k + 1`. Both backends query their containers directly, without materializing `toArray()`.

The PHP backend builds cumulative container/bucket counts lazily and uses binary search for subsequent queries. Dense containers also cache counts for 256-byte blocks. Membership changes invalidate affected caches; clones and snapshots remain independent. The first query after construction or mutation can therefore cost more than repeated queries.

The bundled native build also indexes 64-bit container counts in C. After 16 rank/select queries without a write, sets with at least 64 containers use binary search followed by CRoaring's container query. The index adds about 32 bytes per container on a 64-bit ABI, without copying IDs. Native writes release it before changing the bitmap; the next 15 queries use the direct path before rebuilding. Small sets and stock CRoaring libraries use direct queries. See [native index design and ownership](native/README.md).

## Unsigned 64-bit IDs

```php
use RoaringBitmap\Bitmap64;

$ids = new Bitmap64([
    42,
    '4294967296',
    '9223372036854775808',
    '18446744073709551615',
]);

$ids->contains('18446744073709551615'); // true
$ids->rank('9223372036854775808');      // 3
$ids->select(3);                       // '18446744073709551615'
$ids->toArray();                       // Every element is a decimal string

$ids->addRange('18446744073709551612', Bitmap64::END_EXCLUSIVE);
$ids->addRangeClosed('9223372036854775807', '9223372036854775809');

$restored = Bitmap64::fromPortableBytes($ids->toPortableBytes());
```

`Bitmap64` mirrors the mutation, query, set-algebra, iteration, cloning, and serialization API of `Bitmap`. Native/PHP selection uses the same `Backend` enum and optional `libraryPath`. Set operations can mix PHP and native `Bitmap64` objects, retaining the left operand's backend.

- **Input IDs:** nonnegative PHP integers or decimal digit strings. Leading zeroes are normalized. Floats, booleans, negative values, whitespace, signs, exponent notation, and out-of-range values are rejected, including in PHP files without `strict_types`. Quote large integer literals: PHP otherwise converts literals above `PHP_INT_MAX` to floats before the package sees them.
- **Output IDs:** `select()`, iteration, and `toArray()` always return canonical decimal strings, including small IDs. JSON therefore preserves their exact values. Conversion and comparison use integer bit patterns and 32-bit parts, with no floats, GMP, or BCMath dependency.
- **Ordering:** unsigned numeric order. IDs above `PHP_INT_MAX` follow smaller IDs.
- **Ranges:** `addRange(start, end)` is half-open. The string constant `Bitmap64::END_EXCLUSIVE` is `2^64`, allowing a range to include the maximum ID; this sentinel is only valid as a range endpoint. `addRangeClosed(start, end)` includes both endpoints. Empty ranges are allowed.
- **Counts and positions:** the number of stored IDs is limited to `PHP_INT_MAX`, even though each ID can use all 64 bits. `count()`, `cardinality()`, and `rank()` return PHP integers; `select()` takes a PHP integer position. Ranges or results exceeding the cardinality limit throw `OverflowException`; ranges are checked before allocation. Smaller but enormous ranges still need application-level memory/time limits.
- **Width conversion:** promote a 32-bit bitmap with `new Bitmap64($bitmap32)`. Set operations require operands of the same width; they do not implicitly mix `Bitmap` and `Bitmap64`.
- **Persistence:** `Bitmap64` uses the unsigned 64-bit extension of RoaringFormatSpec: an ordered table of upper-32-bit keys and embedded portable 32-bit bitmaps. Use `Bitmap64::fromPortableBytes()` to read it. Other 64-bit Roaring formats are not automatically interchangeable. Existing `Bitmap` bytes and APIs retain their 32-bit semantics.

The PHP implementation reuses 32-bit engines under the upper-bit keys. Native mode uses CRoaring's 64-bit API. Readers validate embedded offsets, sorted keys, payloads, and trailing bytes before native deserialization; valid empty embedded bitmaps are normalized away. PHP `serialize()` stores portable data and selects an available backend when restored, for both widths. Run `php examples/advanced.php` for a complete example.

## Optional native backend

Enable `ext-ffi` and provide a CRoaring shared library. This package is tested with **CRoaring 5.4.0**. Other versions must expose the public CRoaring symbols declared for the selected bitmap width in `src/Internal/NativeLibrary.php`; they are not part of the tested compatibility claim. Package-specific index symbols are optional: stock CRoaring remains compatible.

The development helper downloads a pinned release, verifies SHA-256 hashes for its sources, and compiles it together with the package's C index into a local shared library. Verified cached sources are reused. It requires `curl`, PHP, and a C11 compiler. It supports Linux and macOS and does not install anything globally or modify `php.ini`. The addon uses CRoaring internals within the same compiled source unit and is tied to that pinned release. `bash tools/build-native.sh --stock` builds an additional unmodified `libroaring-stock` library for comparisons.

```sh
bash tools/build-native.sh

# macOS (on Linux, use libroaring.so):
export ROARING_LIBRARY="$PWD/.build/croaring/libroaring.dylib"
ROARING_REQUIRE_NATIVE=1 ROARING_REQUIRE_ORDER_INDEX=1 php -d ffi.enable=1 tests/run.php
php -d ffi.enable=1 benchmarks/compare.php
```

In an application, either set `ROARING_LIBRARY` to an absolute shared-library path or pass `libraryPath:`:

```php
$bitmap = new Bitmap(
    backend: Backend::Native,
    libraryPath: '/path/to/libroaring.so',
);
```

`Backend::Native` throws `NativeBackendUnavailable` when FFI is disabled, the library is missing, or required symbols cannot load. `Backend::Auto` falls back only for those availability failures; input, allocation, and operation errors are not hidden. Successful and failed library probes are cached for the current PHP process; restart workers after installing or replacing a library.

Without an explicit path, the loader checks `ROARING_LIBRARY`, then platform library names (and common Homebrew paths on macOS). Windows can supply a separately built compatible DLL; the included build helper and native CI cover Linux and macOS.

For PHP-FPM/web deployments, configure FFI for that SAPI. The CLI `-d` option only affects that CLI process. This version uses `FFI::cdef()` and requires permission to call it; `ffi.enable=preload` alone does not enable this loader in web requests. A preloaded `FFI::scope()` loader is not implemented yet.

## Behavior and format

The following table describes the 32-bit `Bitmap`; the 64-bit differences are documented above.

| Operation | Behavior |
| --- | --- |
| `add`, `remove` | Mutate the bitmap; return whether membership changed |
| `addMany` | Consumes an iterable in batches of 1,024; earlier completed batches remain if later input throws |
| `addRange(start, end)` | Adds `[start, end)`; `end` can be `4,294,967,296` |
| `union`, `intersect`, `difference`, `symmetricDifference` | Return an independent bitmap using the left operand's backend |
| `count`, `cardinality`, `isEmpty`, `contains`, `equals` | Query the set |
| `rank(value)` | Count IDs less than or equal to value |
| `select(index)` | ID at a zero-based position, or `null` when out of bounds |
| `clone` | Independent copy |
| Iteration | Sorted snapshot captured when `getIterator()` is called |
| `toArray` | Materializes the whole set as a PHP array |
| `toPortableBytes`, `fromPortableBytes` | Standard 32-bit Roaring portable bytes |
| PHP `serialize` / `unserialize` | Store portable bytes; select the backend automatically when restored |

The format reader supports array, bitset, and run containers. It checks lengths, offsets, sortedness, cardinalities, and trailing data before invoking native deserialization. Native objects are also checked with CRoaring's validator; adjacent runs are normalized when necessary.

PHP stores sparse containers as little-endian packed 16-bit values and promotes them to 8,192-byte bitsets above 4,096 entries. It demotes them when they become sparse again. Dense bitwise operations use PHP string operators. PHP writes array/bitset containers and expands imported run containers into those representations. Native mode can retain run compression. Both outputs describe the same sets and are interoperable, but their bytes and sizes can differ.

Large ranges and imported runs can consume much more memory in PHP than in native mode. Iteration takes a snapshot, and `toArray()` adds the overhead of a PHP array. Deserialization performs PHP validation even when native mode is selected, so its cost includes a temporary PHP representation. Apply application-level resource limits when accepting large external bitmaps.

## Verification and benchmarks

```sh
composer validate --strict
php -d ffi.enable=0 tests/run.php
ROARING_LIBRARY=/path/to/libroaring.so ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 tests/run.php
php -d ffi.enable=1 benchmarks/compare.php 100000
python3 benchmarks/run.py --sizes 100000 1000000 --repetitions 5 --output benchmarks/results/new-run
```

The tests cover unsigned boundaries through `2^64-1`, rank/select and cache invalidation, container transitions, ranges, mutation against independent set oracles, mixed-backend algebra, snapshots, malformed inputs, all 65,536 container keys, and Java/C/C++ fixtures from the official format repository. Independent Python integer vectors verify exact 64-bit conversions. CI runs PHP 8.2–8.5 with FFI disabled and native tests on Ubuntu 24.04 (x64) and macOS 26 (Apple Silicon).

CI jobs execute only when the GitHub account initiating the run is `petericebear`, including pushes, manual runs, and reruns. Automatic pull-request runs are disabled. The job conditions use [`github.triggering_actor`](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts#github-context), so the account requesting a rerun is checked too. Other users' pushes can create a skipped workflow record, but no jobs start. Benchmarks run on `main` after both test matrices pass, using Ubuntu 24.04 and PHP 8.4. To trigger CI manually while authenticated as `petericebear`, use **Actions → Tests → Run workflow**, or:

```sh
gh workflow run tests.yml --repo petericebear/roaringbitmap-php --ref main
```

Each successful benchmark uploads one `benchmark-<run-id>-<attempt>` artifact and updates only the marked performance snapshot in this README. At most three benchmark artifacts are retained; unrelated artifacts are untouched. Bundles also expire after 90 days. Artifact downloads require GitHub sign-in; the measured README snapshot is public. Publication uses the workflow token and a docs commit with `[skip ci]`, so it does not start another benchmark. See [CI benchmark details](benchmarks/README.md#automated-ci-benchmarks) for reproduction and downloads.

`composer bench` is a quick, single-sample timing check. The full benchmark runner compares PHP integer-keyed sets against both package backends using isolated processes, repeated samples, and full-process peak RSS that includes native allocations. It saves a Markdown report, raw JSON, and CSV; see [benchmark instructions](benchmarks/README.md). Native allocations are not included in PHP's allocator counters, so those counters alone are not used for the memory comparison.

The [Herd measurement report from November 3, 2026](benchmarks/MEASUREMENTS.md) includes before/after comparisons at 100,000 and 1,000,000 IDs, construction and query tradeoffs, and the original data from 150 isolated samples. That report records the earlier 32-bit implementation; its source fingerprints are preserved.

The [initial rank/select report](benchmarks/results/2026-11-03-order-verified/report.md) records the implementation before the native index. The [November 4 native optimization report](benchmarks/results/2026-11-04-native-order/report.md) compares fresh before/after runs at 100,000 and 1,000,000 IDs, including cold queries, warm queries, mutation costs, and index memory. Run `php -d ffi.enable=1 benchmarks/order.php` to measure these workloads locally.

Before a public release, choose the final vendor/package name and add workload-specific profiling. This version does not provide a Zend extension or an FFI preloading integration.

## Related implementations

- [Official software list](https://roaringbitmap.org/software/)
- [CRoaring](https://github.com/RoaringBitmap/CRoaring): the optional native engine
- [Portable format specification](https://github.com/RoaringBitmap/RoaringFormatSpec)
- [buexplain/roaring](https://github.com/buexplain/roaring): an existing PHP package based on FFI
- [paultan63/php-roaring_bitmap-extension](https://github.com/paultan63/php-roaring_bitmap-extension): an existing extension whose README targets PHP 5.6/7.x
- [PHP FFI documentation](https://www.php.net/manual/en/book.ffi.php)

Composer distributes this PHP library. [OPcache](https://www.php.net/manual/en/book.opcache.php) caches PHP's compiled bytecode; it is separate from the choice of bitmap implementation and does not replace CRoaring's native algorithms.

Original package code is MIT-licensed. Upstream test fixtures retain their Apache-2.0 license in `tests/fixtures/LICENSE.upstream`. CRoaring is separately licensed under Apache-2.0 and is downloaded only by the optional development build helper.
