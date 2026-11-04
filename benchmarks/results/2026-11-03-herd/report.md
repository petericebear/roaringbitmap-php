# Herd benchmark: PHP sets before adoption, Roaring after adoption

Measured 2026-11-03T09:12:49.056946+01:00 through 2026-11-03T09:14:16.128082+01:00. All times below are medians of 5 independent PHP processes per configuration. These are local CLI measurements, not Laravel HTTP throughput.

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
| dense | 100,000 | array | 12.672 | 0.462603 | 38.50 | 1,262,971 |
| dense | 100,000 | php | 93.379 | 0.037867 | 27.78 | 41,008 |
| dense | 100,000 | native | 25.212 | 0.001762 | 28.28 | 41,008 |
| dense | 1,000,000 | array | 127.557 | 5.673650 | 107.00 | 13,629,638 |
| dense | 1,000,000 | php | 914.852 | 0.880265 | 28.64 | 377,208 |
| dense | 1,000,000 | native | 256.427 | 0.010560 | 28.89 | 377,208 |
| shuffled | 100,000 | array | 14.636 | 0.546140 | 38.47 | 1,262,971 |
| shuffled | 100,000 | php | 103.960 | 0.038782 | 27.81 | 41,008 |
| shuffled | 100,000 | native | 30.146 | 0.001864 | 28.31 | 41,008 |
| shuffled | 1,000,000 | array | 169.857 | 9.758097 | 106.98 | 13,629,638 |
| shuffled | 1,000,000 | php | 1017.417 | 0.881066 | 29.42 | 377,208 |
| shuffled | 1,000,000 | native | 315.867 | 0.011638 | 30.47 | 377,208 |
| sparse | 100,000 | array | 23.514 | 3.430453 | 38.52 | 1,545,753 |
| sparse | 100,000 | php | 127.181 | 12.405500 | 28.83 | 225,008 |
| sparse | 100,000 | native | 28.440 | 0.075108 | 28.66 | 225,008 |
| sparse | 1,000,000 | array | 864.435 | 100.979875 | 107.00 | 16,457,472 |
| sparse | 1,000,000 | php | 1280.173 | 124.434750 | 40.61 | 2,250,008 |
| sparse | 1,000,000 | native | 285.528 | 0.741431 | 34.36 | 2,250,008 |
| ranges | 100,000 | array | 11.665 | 0.480505 | 35.95 | 1,188,901 |
| ranges | 100,000 | php | 0.073 | 0.034266 | 27.80 | 16,408 |
| ranges | 100,000 | native | 0.006 | 0.000894 | 28.03 | 25 |
| ranges | 1,000,000 | array | 120.708 | 5.472933 | 83.77 | 12,888,902 |
| ranges | 1,000,000 | php | 0.414 | 0.147720 | 27.89 | 131,208 |
| ranges | 1,000,000 | native | 0.009 | 0.001181 | 28.03 | 230 |

The PHP-array storage column uses PHP `serialize()`; the package columns use standard Roaring portable bytes. These formats are not interchangeable. This compares the respective persistence representations, not compression of identical encoded input.

## Before/after ratios

A ratio above 1 means the denominator took less time. These are ratios of medians, not an application-wide speedup.

| Workload | IDs/input | Array / native build | Array / PHP intersection | Array / native intersection | PHP / native intersection |
| --- | ---: | ---: | ---: | ---: | ---: |
| dense | 100,000 | 0.50× | 12.22× | 262.48× | 21.49× |
| dense | 1,000,000 | 0.50× | 6.45× | 537.26× | 83.36× |
| shuffled | 100,000 | 0.49× | 14.08× | 292.92× | 20.80× |
| shuffled | 1,000,000 | 0.54× | 11.08× | 838.44× | 75.70× |
| sparse | 100,000 | 0.83× | 0.28× | 45.67× | 165.17× |
| sparse | 1,000,000 | 3.03× | 0.81× | 136.20× | 167.83× |
| ranges | 100,000 | 1931.02× | 14.02× | 537.23× | 38.31× |
| ranges | 1,000,000 | 13664.07× | 37.05× | 4633.07× | 125.05× |

## All operation timings

All values are milliseconds. Membership is a complete batch of 10,000 probes with 50% hits; iteration visits every ID in A and computes a checksum. Other rows are one operation. Ranges in parentheses are the minimum–maximum sample values.

### Dense IDs, sorted bulk input — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 12.6722 (12.2878–12.8326) | 93.3788 (91.2309–94.7379) | 25.2119 (25.1236–25.3923) |
| Intersection | 0.4626 (0.4516–0.4736) | 0.0379 (0.0376–0.0383) | 0.0018 (0.0017–0.0018) |
| Union | 1.1816 (1.1764–1.2426) | 0.0189 (0.0188–0.0193) | 0.0023 (0.0022–0.0023) |
| Difference | 0.4611 (0.4452–0.4788) | 0.3883 (0.3593–0.3944) | 0.0024 (0.0023–0.0025) |
| 10,000 contains | 0.1301 (0.1286–0.1330) | 1.4530 (1.4497–1.5109) | 1.2260 (1.2049–1.2408) |
| Iterate all of A | 0.6925 (0.5930–0.7924) | 8.9679 (8.8456–9.2009) | 2.3856 (2.2941–2.5730) |
| Serialize A | 0.8284 (0.8023–0.8569) | 0.0023 (0.0023–0.0024) | 0.0015 (0.0015–0.0016) |
| Deserialize A | 1.3472 (1.3256–1.3673) | 0.0427 (0.0426–0.0431) | 0.0451 (0.0446–0.0462) |

### Dense IDs, sorted bulk input — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 127.5571 (124.1797–131.7145) | 914.8517 (904.1268–918.4052) | 256.4275 (249.6772–259.7623) |
| Intersection | 5.6737 (5.6135–5.9111) | 0.8803 (0.8582–0.8911) | 0.0106 (0.0104–0.0107) |
| Union | 13.5339 (13.2347–14.0134) | 0.4681 (0.4609–0.4705) | 0.0199 (0.0194–0.0203) |
| Difference | 5.5432 (5.4696–5.7300) | 4.5838 (4.5325–4.6983) | 0.0209 (0.0207–0.0215) |
| 10,000 contains | 0.1319 (0.1289–0.1334) | 1.5015 (1.4815–1.5031) | 1.2406 (1.2156–1.2675) |
| Iterate all of A | 5.9411 (5.5718–7.9848) | 90.5625 (89.5196–91.1613) | 29.2742 (28.4192–32.4328) |
| Serialize A | 9.5994 (9.2834–9.8769) | 0.0214 (0.0209–0.0217) | 0.0192 (0.0192–0.0193) |
| Deserialize A | 15.2972 (14.4716–15.5385) | 0.3214 (0.3198–0.3305) | 0.3282 (0.3148–0.3309) |

### Dense IDs, shuffled bulk input — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 14.6363 (12.5835–14.9700) | 103.9600 (102.2205–104.5162) | 30.1460 (29.7345–30.3403) |
| Intersection | 0.5461 (0.5372–0.5545) | 0.0388 (0.0384–0.0388) | 0.0019 (0.0018–0.0019) |
| Union | 1.3730 (1.3519–1.3954) | 0.0201 (0.0201–0.0202) | 0.0023 (0.0023–0.0024) |
| Difference | 0.5376 (0.5343–0.5433) | 0.3950 (0.3814–0.4138) | 0.0024 (0.0023–0.0025) |
| 10,000 contains | 0.1335 (0.1294–0.1346) | 1.4925 (1.4560–1.5102) | 1.2766 (1.2375–1.2912) |
| Iterate all of A | 0.6875 (0.5962–0.7166) | 9.4814 (9.1607–9.5827) | 2.3369 (2.2710–2.5004) |
| Serialize A | 0.9344 (0.9147–0.9658) | 0.0032 (0.0031–0.0032) | 0.0015 (0.0015–0.0016) |
| Deserialize A | 1.4411 (1.4224–1.4437) | 0.0429 (0.0421–0.0432) | 0.0453 (0.0450–0.0456) |

### Dense IDs, shuffled bulk input — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 169.8568 (159.9700–179.3205) | 1017.4167 (1012.3754–1022.5772) | 315.8669 (308.3531–322.3261) |
| Intersection | 9.7581 (8.3332–10.2618) | 0.8811 (0.8750–0.8923) | 0.0116 (0.0115–0.0118) |
| Union | 18.6061 (16.7064–19.7630) | 0.4733 (0.4688–0.4768) | 0.0202 (0.0200–0.0207) |
| Difference | 9.1196 (8.2065–11.0973) | 4.6534 (4.6262–4.6967) | 0.0222 (0.0219–0.0225) |
| 10,000 contains | 0.1354 (0.1330–0.1380) | 1.5081 (1.4946–1.5183) | 1.2793 (1.2375–1.3135) |
| Iterate all of A | 6.7813 (5.6583–7.4294) | 91.4613 (90.9147–92.8858) | 29.7360 (28.0880–30.7281) |
| Serialize A | 11.7036 (11.3948–11.9558) | 0.0211 (0.0207–0.0215) | 0.0198 (0.0195–0.0201) |
| Deserialize A | 20.5447 (19.3874–23.5792) | 0.3263 (0.3199–0.3327) | 0.3403 (0.3264–0.3450) |

### Dispersed IDs, patterned low bits — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 23.5142 (23.4290–24.2164) | 127.1813 (126.2985–128.2127) | 28.4402 (27.8863–28.6880) |
| Intersection | 3.4305 (3.3680–3.5048) | 12.4055 (12.3126–12.7157) | 0.0751 (0.0736–0.0761) |
| Union | 4.0215 (3.8790–4.2329) | 12.6773 (12.2792–12.9236) | 0.1721 (0.1673–0.1789) |
| Difference | 3.4323 (3.3586–3.4803) | 10.4753 (10.3025–10.6487) | 0.1177 (0.1150–0.1191) |
| 10,000 contains | 0.3840 (0.3817–0.3941) | 4.2428 (4.1651–4.3006) | 1.5021 (1.4664–1.5184) |
| Iterate all of A | 0.7124 (0.5656–0.8035) | 12.3933 (11.8686–12.4597) | 2.7650 (2.6943–3.0222) |
| Serialize A | 0.8685 (0.8274–0.9767) | 0.5106 (0.4866–0.5161) | 0.0223 (0.0217–0.0229) |
| Deserialize A | 3.5787 (3.4765–3.6063) | 5.4167 (5.4009–5.5145) | 5.6273 (5.5142–6.2627) |

### Dispersed IDs, patterned low bits — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 864.4347 (821.3620–901.4511) | 1280.1727 (1279.2772–1282.4076) | 285.5284 (282.1690–294.3268) |
| Intersection | 100.9799 (98.9534–111.6232) | 124.4347 (122.9516–124.8286) | 0.7414 (0.7156–0.7458) |
| Union | 53.3412 (52.7884–55.6825) | 127.9449 (126.7070–128.7330) | 1.7282 (1.6844–1.7500) |
| Difference | 105.3273 (104.3514–108.3598) | 104.9542 (103.4584–106.4604) | 1.1558 (1.1377–1.2029) |
| 10,000 contains | 0.8531 (0.6595–1.2905) | 4.2887 (4.2454–4.3686) | 1.5815 (1.5346–1.6384) |
| Iterate all of A | 6.2302 (5.5224–7.0839) | 123.7678 (118.5272–125.1182) | 65.6880 (64.8124–68.0415) |
| Serialize A | 10.1416 (9.8401–10.6966) | 5.5706 (5.3981–5.7138) | 0.3877 (0.3715–0.4039) |
| Deserialize A | 71.7288 (70.7890–73.5004) | 55.0282 (49.2467–55.1935) | 56.3447 (52.2233–56.9042) |

### Consecutive IDs, range API — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 11.6653 (11.2535–11.9783) | 0.0727 (0.0555–0.0762) | 0.0060 (0.0060–0.0075) |
| Intersection | 0.4805 (0.4657–0.4888) | 0.0343 (0.0341–0.0352) | 0.0009 (0.0009–0.0009) |
| Union | 0.5536 (0.4455–0.5989) | 0.0276 (0.0273–0.0280) | 0.0009 (0.0009–0.0009) |
| Difference | 0.3139 (0.2863–0.3187) | 0.1995 (0.1902–0.2065) | 0.0009 (0.0009–0.0009) |
| 10,000 contains | 0.1261 (0.1218–0.1280) | 1.4695 (1.4475–1.5015) | 1.2642 (1.2613–1.2739) |
| Iterate all of A | 0.7010 (0.6353–0.7845) | 6.0457 (5.9410–6.0836) | 2.2551 (2.1852–2.4181) |
| Serialize A | 0.7918 (0.7859–0.8588) | 0.0008 (0.0008–0.0008) | 0.0003 (0.0003–0.0003) |
| Deserialize A | 1.3218 (1.3084–1.3441) | 0.0279 (0.0266–0.0284) | 0.9543 (0.9417–0.9629) |

### Consecutive IDs, range API — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 120.7084 (119.3067–121.7922) | 0.4144 (0.3579–0.4313) | 0.0088 (0.0069–0.0102) |
| Intersection | 5.4729 (5.3246–5.8163) | 0.1477 (0.1453–0.1489) | 0.0012 (0.0012–0.0012) |
| Union | 6.2148 (6.1529–6.5457) | 0.1251 (0.1239–0.1264) | 0.0016 (0.0016–0.0017) |
| Difference | 3.4421 (3.3205–3.4719) | 1.4994 (1.4920–1.5242) | 0.0013 (0.0012–0.0013) |
| 10,000 contains | 0.1254 (0.1241–0.1272) | 1.4976 (1.4634–1.5024) | 1.2580 (1.2515–1.2947) |
| Iterate all of A | 6.9830 (6.2392–7.4729) | 59.0794 (58.5519–59.8271) | 22.5909 (21.7081–24.2202) |
| Serialize A | 9.1284 (9.0388–9.5435) | 0.0079 (0.0078–0.0081) | 0.0004 (0.0004–0.0004) |
| Deserialize A | 14.6591 (14.3745–15.4090) | 0.2129 (0.2074–0.2146) | 9.3319 (9.2666–9.4701) |

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
python3 benchmarks/run.py --sizes 100000 1000000 --workloads dense shuffled sparse ranges --repetitions 5 --target-ms 25 --output benchmarks/results/new-run
```

Use `--php /absolute/path/to/herd/php84` or `--library /absolute/path/to/libroaring.dylib` to override discovery. The runner requires Python 3.9+ and `getrusage()` support on macOS or Linux. It does not need Python packages.

Files: [raw results](results.json), [summary CSV](summary.csv). All source SHA-256 digests, runtime settings, commands, sample timings, minima/maxima, and standard deviations are retained for auditing.
