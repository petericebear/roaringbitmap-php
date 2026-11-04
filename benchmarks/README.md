# Reproducible performance measurements

## Automated CI benchmarks

The `benchmarks` job runs after both test matrices pass on `main`, only when initiated by `petericebear`. It uses Ubuntu 24.04, PHP 8.4, the pinned native accelerator build, and a separate stock CRoaring build. All workloads execute sequentially on the same runner, with three repetitions at 100,000 and 1,000,000 IDs:

- Set algebra, construction, memory, and serialization: PHP arrays, package PHP, and native acceleration, using dense, mixed sparse, and range inputs (54 isolated-process samples).
- Rank/select: PHP and accelerated native mode, plus stock native mode, for both widths and dense/sparse inputs (72 samples with fresh bitmaps).

One `benchmark-<run-id>-<attempt>` artifact contains `report.md`, `summary.json`, the individual reports/raw JSON, set-operation CSV, harness snapshots, and exact package sources. The README shows the largest tested size, while the full artifact covers both sizes, cold and warm queries, mutation costs, and all operations. Results identify the measured commit, PHP/runtime settings, runner, library hashes, and source hashes. Shared CI machines vary; compare modes within a run and inspect sample ranges before interpreting small changes between runs. These measurements are not HTTP latency or universal speedup claims.

Benchmark publication is serialized. Only after generating a complete, verified bundle does cleanup trim matching main-branch benchmark artifacts to two; uploading the new bundle then keeps the total at three. A second check enforces the limit. Cleanup paginates through all artifacts and leaves unrelated names/branches alone. If an upload fails after cleanup, the previous two bundles remain. Expiry is also set to 90 days.

The workflow publishes the measured README section through the GitHub Contents API using its workflow token. It preserves content outside the markers and retries if another README edit wins a concurrent update. The snapshot states the measured source commit, which can differ from the current `main` if newer code was pushed while measurement was running. The generated docs commit skips CI, preventing a publication loop. No personal access token or extra repository secret is needed.

To download one of the retained bundles, follow its link in the README or use:

```sh
gh run list --repo petericebear/roaringbitmap-php --workflow tests.yml --branch main
gh run download RUN_ID --repo petericebear/roaringbitmap-php --name benchmark-RUN_ID-ATTEMPT --dir .build/downloaded-benchmark
```

GitHub requires sign-in/read access to download artifacts. Old artifact links stop working after count-based pruning or expiry; the public README retains the last published numbers.

To reproduce the bundle locally (including stock CRoaring):

```sh
bash tools/build-native.sh
bash tools/build-native.sh --stock
python3 benchmarks/ci.py --sizes 100000 1000000 --samples 3 --output .build/ci-benchmark-new
```

For a quick end-to-end harness check, use `--sizes 10000 --workloads dense --samples 3` with a new output directory. The artifact/README maintenance script refuses live changes outside an authorized main-branch CI context. Its cleanup and publication tests use mocked GitHub data.

## Local benchmarks

Read the [November 3, 2026 Herd measurement report](MEASUREMENTS.md) for measured results, tradeoffs, and links to all original samples.

The full suite compares three implementations of the same sets:

1. PHP integer-keyed arrays containing `true`, using `array_intersect_key`, `+`, `array_diff_key`, and `isset`.
2. This package with `Backend::Php` forced.
3. This package with `Backend::Native` forced, backed by CRoaring.

This is the before/after comparison for adopting the package, plus a PHP-to-native comparison. It does not compare two revisions of the package algorithms.

## Build and run with Herd on macOS

```sh
composer install
bash tools/build-native.sh

export ROARING_LIBRARY="$PWD/.build/croaring/libroaring.dylib"
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 tests/run.php
php -d ffi.enable=0 tests/run.php

python3 benchmarks/run.py \
  --sizes 100000 1000000 \
  --repetitions 5 \
  --output benchmarks/results/new-run
```

Herd's `php` on PATH is used by default. To choose a particular Herd binary:

```sh
python3 benchmarks/run.py \
  --php "$HOME/Library/Application Support/Herd/bin/php84" \
  --output benchmarks/results/php84-new-run
```

The runner locates `.build/croaring/libroaring.dylib` automatically on macOS (`libroaring.so` on Linux), or accepts `--library /absolute/path/to/library`. It requires Python 3.9+ with timezone data and uses only the Python standard library. No global Herd or PHP settings are changed. These are CLI measurements, not PHP-FPM or Laravel HTTP benchmarks.

`composer bench:report` runs the full suite into `benchmarks/results/latest`. An existing completed run is never overwritten; select a new `--output` directory for subsequent runs. Partial results are saved after every worker if a run is interrupted.

For a small harness check:

```sh
python3 benchmarks/run.py --sizes 10000 --workloads dense --repetitions 3 \
  --target-ms 5 --output .build/benchmark-check
```

## What is measured

For each input size, the suite covers sorted dense IDs, a deterministic permutation of those dense IDs, IDs dispersed across 16-bit containers with both patterned and mixed low bits, and consecutive IDs supplied through `addRange()` where supported. A and B each contain N unique IDs, with exactly 50% overlap. The second sparse pattern checks how sensitive results are to the original pattern's low bits; it is deterministic CRC-based jitter, not a claim to model all real-world IDs.

Each configuration has five samples, each in a fresh PHP process. Workers execute sequentially and backend order rotates between repetitions. Construction includes streamed ID generation; a large input array is not retained. Warm query batches target 25 ms. The output includes every batch duration and iteration count, medians, sample ranges, and standard deviations.

Measured operations are construction of two inputs, intersection, union, difference, 10,000 membership probes, full iteration, serialization, and deserialization. Correctness checks run outside timed sections. Deserialization includes the package's PHP validation pass, even in native mode.

The primary memory metric is **full-process peak RSS captured immediately after building both inputs**, including the interpreter and native C allocations. Subsequent query peaks are not part of that metric. PHP allocator deltas are retained in the raw JSON but exclude C allocations. Serialized payload size is a storage metric, not RAM usage.

Workers set `ffi.enable=1`, disable CLI OPcache/JIT, and use a 1 GiB memory limit for all backends so the large array baseline can run. The raw output records the actual PHP binary and settings, hardware, compiler, library hash, source hashes, and executed commands.

## Output

- `report.md`: comparison tables and methodology.
- `results.json`: every original sample, configuration, source fingerprint, and summary.
- `summary.csv`: aggregate measurements for plotting or further analysis.
- `harness/`: the exact worker and runner source used by the measurement run.

The small existing `compare.php` / `composer bench` script remains available as a quick timing check. It uses a different dataset and methodology, so its numbers should not be mixed with the full suite's results.

## Rank/select and 64-bit IDs

The [November 4 before/after native optimization report](results/2026-11-04-native-order/report.md) includes 160 workload samples and 10 cache lifecycle samples. The [initial Herd run](results/2026-11-03-order-verified/report.md) records the version before the native index. Both are separate from the historical array-versus-package set-algebra measurements.

```sh
ROARING_REQUIRE_NATIVE=1 php -d ffi.enable=1 -d opcache.enable_cli=0 \
  -d opcache.jit=disable -d memory_limit=1G benchmarks/order.php \
  --size=100000 --queries=1000 --samples=5 --output=benchmarks/results/order-new-run
```

This focused benchmark runs dense and sparse workloads for both widths, including decimal-string IDs above `PHP_INT_MAX`. It measures construction, the first rank batch, a repeated rank batch, warmed select, and a rank query after mutation invalidates the PHP index. All answers are checked exactly outside the timers. Samples use fresh bitmaps sequentially in one process, with alternating backend order; the report states this methodology separately from the original isolated-process set-algebra suite. It records individual samples, min/max values, settings, source fingerprints, and a copy of the benchmark source.

The native library is discovered locally or supplied via `--library` / `ROARING_LIBRARY`. Without `ROARING_REQUIRE_NATIVE=1`, an unavailable native backend is reported and only PHP is measured. `composer bench:order` runs the same script with defaults, without saving a report. Existing completed reports are not overwritten.

`bash tools/build-native.sh` includes the optional C index. `bash tools/build-native.sh --stock` produces a separate unmodified CRoaring library; use `--library` to compare it with the optimized build. Source fingerprints include the C addon and build helper. Cold batches include delayed index construction; a single query immediately after a native mutation uses the direct path.

To measure the index's activation latency and allocation size separately:

```sh
php -d ffi.enable=1 -d opcache.enable_cli=0 -d opcache.jit=disable -d memory_limit=1G \
  benchmarks/native-order-cache.php --output=benchmarks/results/cache-new-run.json
```

This probe requires the addon, verifies activation on the 16th query, and measures the allocated C index bytes directly. It does not claim to measure full-process RSS. It uses internal reflection for diagnostics only; application code should use the public bitmap API.
