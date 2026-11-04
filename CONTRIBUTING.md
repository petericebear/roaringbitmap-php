# Contributing

## Development setup

Clone the repository and install its Composer dependencies:

```sh
git clone https://github.com/petericebear/roaringbitmap-php.git
cd roaringbitmap-php
composer install
php examples/basic.php
php examples/advanced.php
```

Use a 64-bit PHP 8.2–8.x build. The pure PHP backend needs no additional extensions. To build and exercise native mode, follow the [native setup guide](docs/native-backend.md).

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

Each successful benchmark uploads one `benchmark-<run-id>-<attempt>` artifact and updates only the marked performance snapshot in the package README. At most three benchmark artifacts are retained; unrelated artifacts are untouched. Bundles also expire after 90 days. Artifact downloads require GitHub sign-in; the measured README snapshot is public. Publication uses the workflow token and a docs commit with `[skip ci]`, so it does not start another benchmark. See [CI benchmark details](benchmarks/README.md#automated-ci-benchmarks) for reproduction and downloads.

`composer bench` is a quick, single-sample timing check. The full benchmark runner compares PHP integer-keyed sets against both package backends using isolated processes, repeated samples, and full-process peak RSS that includes native allocations. It saves a Markdown report, raw JSON, and CSV; see [benchmark instructions](benchmarks/README.md). Native allocations are not included in PHP's allocator counters, so those counters alone are not used for the memory comparison.

The [Herd measurement report from November 3, 2026](benchmarks/MEASUREMENTS.md) includes before/after comparisons at 100,000 and 1,000,000 IDs, construction and query tradeoffs, and the original data from 150 isolated samples. That report records the earlier 32-bit implementation; its source fingerprints are preserved.

The [initial rank/select report](benchmarks/results/2026-11-03-order-verified/report.md) records the implementation before the native index. The [November 4 native optimization report](benchmarks/results/2026-11-04-native-order/report.md) compares fresh before/after runs at 100,000 and 1,000,000 IDs, including cold queries, warm queries, mutation costs, and index memory. Run `php -d ffi.enable=1 benchmarks/order.php` to measure these workloads locally.

The current Composer package name is `peter/roaring-bitmap`. Native acceleration uses FFI; a Zend extension and an `FFI::scope()` preloading integration are not implemented.

## Reporting issues

[Open an issue](https://github.com/petericebear/roaringbitmap-php/issues) with a minimal example, expected and actual behavior, PHP version, operating system, and the result of `backend()`. For native failures, include the library version and relevant FFI settings. For performance reports, include the workload, backend, dataset size, and reproducible measurements.
