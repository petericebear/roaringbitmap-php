# Herd benchmark: PHP sets before adoption, Roaring after adoption

Measured 2026-11-03T09:16:40.325750+01:00 through 2026-11-03T09:17:15.079716+01:00. All times below are medians of 5 independent PHP processes per configuration. These are local CLI measurements, not Laravel HTTP throughput.

**Before:** a PHP array keyed by integer ID, with `true` values. **After:** this package forced to `Backend::Php` or `Backend::Native`. This also measures the change from the PHP backend to native acceleration. The package algorithms were not modified for this run.

## Test machine and build

- CPU: Apple M4 Pro; 14 logical CPUs; 48 GiB RAM.
- OS: macOS-26.7.1-arm64-arm-64bit-Mach-O; architecture: arm64.
- PHP: 8.4.27, Laravel Herd binary `/Users/peter/Library/Application Support/Herd/bin/php84`.
- CRoaring: 5.4.0, compiled locally with `-O3 -std=c11 -dynamiclib -fPIC` on macOS (`-shared` on Linux); verified source hashes and library hash are in results.json.
- Compiler: Apple clang version 21.0.0 (clang-2100.3.34.2).
- Each worker uses `ffi.enable=1`, `opcache.enable_cli=0`, `opcache.jit=disable`, and `memory_limit=1G`. Herd's default CLI memory limit was 128M; the override permits the large PHP-array baseline. Xdebug state is recorded in raw results.
- The native library is passed via `ROARING_LIBRARY`. No global Herd/PHP configuration changes are required.

## Construction, intersection, and process memory

Each input contains N unique IDs. A and B overlap by exactly 50%. Construction builds both inputs; intersection returns a new set. RSS is the whole process peak captured immediately after construction, before query benchmarks or validation allocate large temporaries. It includes the PHP runtime and native allocations, not just bitmap storage.

| Workload | IDs/input | Backend | Build two (ms) | One intersection (ms) | Build peak RSS (MiB) | Stored A (bytes) |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| sparse_mixed | 100,000 | array | 23.456 | 0.519158 | 38.47 | 1,545,759 |
| sparse_mixed | 100,000 | php | 132.108 | 12.060639 | 28.84 | 225,008 |
| sparse_mixed | 100,000 | native | 35.839 | 0.072797 | 28.66 | 225,008 |
| sparse_mixed | 1,000,000 | array | 274.102 | 8.369000 | 106.98 | 16,457,479 |
| sparse_mixed | 1,000,000 | php | 1347.410 | 123.059750 | 40.62 | 2,250,008 |
| sparse_mixed | 1,000,000 | native | 366.637 | 0.730065 | 34.38 | 2,250,008 |

The PHP-array storage column uses PHP `serialize()`; the package columns use standard Roaring portable bytes. These formats are not interchangeable. This compares the respective persistence representations, not compression of identical encoded input.

## Before/after ratios

A ratio above 1 means the denominator took less time. These are ratios of medians, not an application-wide speedup.

| Workload | IDs/input | Array / native build | Array / PHP intersection | Array / native intersection | PHP / native intersection |
| --- | ---: | ---: | ---: | ---: | ---: |
| sparse_mixed | 100,000 | 0.65× | 0.04× | 7.13× | 165.67× |
| sparse_mixed | 1,000,000 | 0.75× | 0.07× | 11.46× | 168.56× |

## All operation timings

All values are milliseconds. Membership is a complete batch of 10,000 probes with 50% hits; iteration visits every ID in A and computes a checksum. Other rows are one operation. Ranges in parentheses are the minimum–maximum sample values.

### Dispersed IDs, mixed low bits — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 23.4557 (23.3239–24.5878) | 132.1081 (130.8572–132.7535) | 35.8394 (35.2524–36.1656) |
| Intersection | 0.5192 (0.5075–0.5649) | 12.0606 (11.9753–12.3060) | 0.0728 (0.0723–0.0732) |
| Union | 1.2990 (1.2593–1.3686) | 12.3669 (12.0433–13.2701) | 0.1688 (0.1629–0.1699) |
| Difference | 0.4931 (0.4854–0.4996) | 10.3687 (10.0434–10.5243) | 0.1139 (0.1115–0.1178) |
| 10,000 contains | 0.1340 (0.1321–0.1368) | 4.1158 (4.0170–4.2244) | 1.4748 (1.4378–1.4843) |
| Iterate all of A | 0.7693 (0.5658–0.8030) | 11.8194 (11.4577–12.5601) | 2.8115 (2.7257–2.8869) |
| Serialize A | 0.8521 (0.8137–0.8680) | 0.4992 (0.4896–0.6059) | 0.0213 (0.0208–0.0218) |
| Deserialize A | 1.5600 (1.4931–1.6263) | 5.3949 (5.2128–5.4606) | 5.5254 (5.4031–5.8558) |

### Dispersed IDs, mixed low bits — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 274.1019 (249.2125–280.7698) | 1347.4102 (1331.0883–1362.3605) | 366.6365 (360.6071–367.1080) |
| Intersection | 8.3690 (7.3318–9.0557) | 123.0597 (121.9818–124.2694) | 0.7301 (0.7009–0.7375) |
| Union | 17.5955 (16.7532–18.1451) | 127.6526 (124.7408–129.0785) | 1.7328 (1.7213–1.7671) |
| Difference | 7.7490 (7.4174–8.0405) | 104.9739 (103.0630–105.6818) | 1.1522 (1.1229–1.1803) |
| 10,000 contains | 0.1353 (0.1319–0.1383) | 3.9911 (3.9061–4.0458) | 1.5641 (1.5497–1.5856) |
| Iterate all of A | 6.3705 (5.5725–7.4848) | 121.4275 (116.9892–122.8347) | 65.8724 (65.5026–67.2533) |
| Serialize A | 9.4776 (9.3655–9.6868) | 5.4686 (5.4085–5.6353) | 0.3702 (0.3552–0.3770) |
| Deserialize A | 19.8995 (18.3795–20.4729) | 53.6457 (51.4682–54.9209) | 54.6719 (54.2259–56.0081) |

## Method

- Workers run sequentially, never concurrently. Backend order rotates between repetitions to reduce ordering bias. Every sample starts a fresh PHP process.
- Tiny-set warmup primes autoloading and library loading before timing construction. Input generation and validation by the public API are included in construction; a prebuilt input array is not retained.
- Query operations get one warmup and one calibration call, followed by an adaptive batch targeting 25 ms (1–100,000 iterations). Result destruction is included. Each process contributes one per-operation batch average; the report takes the median across processes.
- Construction is measured once per fresh process. Initialization timing and every query batch size/duration are preserved in results.json.
- Dataset mapping: `dense` and `shuffled` use ID `3*i`; `sparse` uses `2048*i + (i % 31)`; `sparse_mixed` uses `2048*i + (crc32(pack('V', i)) & 2047)`; `ranges` uses `i`. A uses indices `[0,N)`, B uses `[N/2,3N/2)`. Shuffled inputs permute local indices as `(i*48271) % N`, a bijection for the tested sizes.
- Range construction uses `addRange()` for the package; PHP arrays enumerate the same IDs. This measures the benefit when range information is already available, not the ingestion of a supplied list.
- The array baseline uses `array_intersect_key`, array union (`+`), `array_diff_key`, and `isset`, rather than value-scanning set functions. Array iteration preserves insertion order; package iteration additionally guarantees sorted snapshots.
- All samples check cardinalities, membership hits, iteration checksums, and round-trip cardinalities. The first repetition additionally checks each algebra result and restored set against independent reference sums (closed-form except for the mixed sparse tails, which are computed separately outside the timing).
- `getrusage().ru_maxrss` is normalized to bytes (bytes on macOS, KiB on Linux). Full-process RSS is reported without subtracting unrelated high-water marks. The separately recorded PHP allocator delta excludes native C allocations and must not be interpreted as native bitmap size.

## Interpretation and limits

- Compare construction as well as queries. Repeated operations on retained bitmaps can amortize ingestion cost; one-shot queries may not. Membership, iteration, and decoding must be assessed separately from bulk algebra.
- Native decoding still performs the package's PHP validation pass. It is not a measurement of raw CRoaring deserialization alone.
- Native range containers and PHP array/bitset containers have different memory and persistence costs. No run optimization is applied to bulk-loaded native bitmaps in this benchmark.
- These are deterministic synthetic datasets with 50% overlap. They do not cover every cardinality distribution, mutation pattern, real request, or concurrency level.
- This is a single shared developer laptop with no CPU pinning or controlled thermal state. Sample ranges are disclosed; very short timings and large ratios should be treated accordingly.
- The native adapter is explicitly selected. Automatic fallback is tested in the package suite but does not participate in the native timing measurements.

## Reproduce

```sh
composer install
bash tools/build-native.sh
python3 benchmarks/run.py --sizes 100000 1000000 --workloads sparse_mixed --repetitions 5 --target-ms 25 --output benchmarks/results/new-run
```

Use `--php /absolute/path/to/herd/php84` or `--library /absolute/path/to/libroaring.dylib` to override discovery. The runner requires Python 3.9+ and `getrusage()` support on macOS or Linux. It does not need Python packages.

Files: [raw results](results.json), [summary CSV](summary.csv). All source SHA-256 digests, runtime settings, commands, sample timings, minima/maxima, and standard deviations are retained for auditing.
