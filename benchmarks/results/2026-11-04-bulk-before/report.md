# PHP sets before adoption, Roaring after adoption

Measured 2026-11-04T10:33:03.349731+01:00 through 2026-11-04T10:34:19.721262+01:00. All times below are medians of 3 independent PHP processes per configuration. These are CLI measurements, not Laravel HTTP throughput.

**Before:** a PHP array keyed by integer ID, with `true` values. **After:** this package forced to `Backend::Php` or `Backend::Native`. This also measures the change from the PHP backend to native acceleration. The package algorithms were not modified for this run.

## Test machine and build

- Hardware details unavailable; see results.json.
- OS: macOS-26.7.1-arm64-arm-64bit-Mach-O; architecture: arm64.
- PHP: 8.4.27, binary `/Users/peter/Library/Application Support/Herd/bin/php84`.
- CRoaring: 5.4.0, compiled locally with `-O3 -std=c11 -dynamiclib -fPIC` on macOS (`-shared` on Linux); verified source hashes and library hash are in results.json.
- Compiler: Apple clang version 21.0.0 (clang-2100.3.34.2).
- Each worker uses `ffi.enable=1`, `opcache.enable_cli=0`, `opcache.jit=disable`, and `memory_limit=1G`. The memory limit permits the large PHP-array baseline. Xdebug state is recorded in raw results.
- The native library is passed via `ROARING_LIBRARY`. No global Herd/PHP configuration changes are required.

## Construction, intersection, and process memory

Each input contains N unique IDs. A and B overlap by exactly 50%. Construction builds both inputs; intersection returns a new set. RSS is the whole process peak captured immediately after construction, before query benchmarks or validation allocate large temporaries. It includes the PHP runtime and native allocations, not just bitmap storage.

| Workload | IDs/input | Backend | Build two (ms) | One intersection (ms) | Build peak RSS (MiB) | Stored A (bytes) |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| dense | 100,000 | array | 13.550 | 0.478144 | 38.36 | 1,262,971 |
| dense | 100,000 | php | 97.636 | 0.039220 | 27.64 | 41,008 |
| dense | 100,000 | native | 25.957 | 0.001812 | 28.11 | 41,008 |
| dense | 1,000,000 | array | 131.644 | 6.068183 | 106.83 | 13,629,638 |
| dense | 1,000,000 | php | 945.471 | 0.876773 | 28.53 | 377,208 |
| dense | 1,000,000 | native | 260.183 | 0.010614 | 28.75 | 377,208 |
| shuffled | 100,000 | array | 15.272 | 0.549938 | 38.38 | 1,262,971 |
| shuffled | 100,000 | php | 106.376 | 0.039175 | 27.66 | 41,008 |
| shuffled | 100,000 | native | 30.356 | 0.001914 | 28.20 | 41,008 |
| shuffled | 1,000,000 | array | 178.827 | 10.116792 | 106.83 | 13,629,638 |
| shuffled | 1,000,000 | php | 1051.294 | 0.886115 | 29.30 | 377,208 |
| shuffled | 1,000,000 | native | 317.326 | 0.011543 | 30.59 | 377,208 |
| sparse | 100,000 | array | 24.660 | 3.606120 | 38.38 | 1,545,753 |
| sparse | 100,000 | php | 130.202 | 12.362375 | 28.84 | 225,008 |
| sparse | 100,000 | native | 28.675 | 0.075507 | 28.47 | 225,008 |
| sparse | 1,000,000 | array | 876.929 | 109.826792 | 106.86 | 16,457,472 |
| sparse | 1,000,000 | php | 1323.343 | 126.285166 | 41.52 | 2,250,008 |
| sparse | 1,000,000 | native | 288.205 | 0.729176 | 34.16 | 2,250,008 |
| sparse_mixed | 100,000 | array | 26.027 | 0.539760 | 38.38 | 1,545,759 |
| sparse_mixed | 100,000 | php | 139.426 | 12.606792 | 28.88 | 225,008 |
| sparse_mixed | 100,000 | native | 37.316 | 0.075989 | 28.50 | 225,008 |
| sparse_mixed | 1,000,000 | array | 299.799 | 9.649278 | 106.86 | 16,457,479 |
| sparse_mixed | 1,000,000 | php | 1416.956 | 126.566791 | 41.52 | 2,250,008 |
| sparse_mixed | 1,000,000 | native | 377.534 | 0.739564 | 34.23 | 2,250,008 |
| ranges | 100,000 | array | 12.347 | 0.483081 | 35.80 | 1,188,901 |
| ranges | 100,000 | php | 0.077 | 0.034626 | 27.61 | 16,408 |
| ranges | 100,000 | native | 0.007 | 0.000931 | 27.89 | 25 |
| ranges | 1,000,000 | array | 124.958 | 5.981283 | 83.53 | 12,888,902 |
| ranges | 1,000,000 | php | 0.418 | 0.153392 | 27.77 | 131,208 |
| ranges | 1,000,000 | native | 0.010 | 0.001204 | 27.89 | 230 |

The PHP-array storage column uses PHP `serialize()`; the package columns use standard Roaring portable bytes. These formats are not interchangeable. This compares the respective persistence representations, not compression of identical encoded input.

## Before/after ratios

A ratio above 1 means the denominator took less time. These are ratios of medians, not an application-wide speedup.

| Workload | IDs/input | Array / native build | Array / PHP intersection | Array / native intersection | PHP / native intersection |
| --- | ---: | ---: | ---: | ---: | ---: |
| dense | 100,000 | 0.52× | 12.19× | 263.92× | 21.65× |
| dense | 1,000,000 | 0.51× | 6.92× | 571.73× | 82.61× |
| shuffled | 100,000 | 0.50× | 14.04× | 287.30× | 20.47× |
| shuffled | 1,000,000 | 0.56× | 11.42× | 876.46× | 76.77× |
| sparse | 100,000 | 0.86× | 0.29× | 47.76× | 163.73× |
| sparse | 1,000,000 | 3.04× | 0.87× | 150.62× | 173.19× |
| sparse_mixed | 100,000 | 0.70× | 0.04× | 7.10× | 165.90× |
| sparse_mixed | 1,000,000 | 0.79× | 0.08× | 13.05× | 171.14× |
| ranges | 100,000 | 1693.21× | 13.95× | 518.61× | 37.17× |
| ranges | 1,000,000 | 12982.63× | 38.99× | 4969.52× | 127.45× |

## All operation timings

All values are milliseconds. Membership is a complete batch of 10,000 probes with 50% hits; iteration visits every ID in A and computes a checksum. Other rows are one operation. Ranges in parentheses are the minimum–maximum sample values.

### Dense IDs, sorted bulk input — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 13.5503 (12.8080–13.6469) | 97.6357 (97.2289–98.0901) | 25.9573 (25.1486–27.3355) |
| Intersection | 0.4781 (0.4661–0.4947) | 0.0392 (0.0392–0.0407) | 0.0018 (0.0018–0.0019) |
| Union | 1.3192 (1.3152–1.4077) | 0.0193 (0.0191–0.0194) | 0.0022 (0.0022–0.0023) |
| Difference | 0.4617 (0.4606–0.4880) | 0.3872 (0.3578–0.3908) | 0.0024 (0.0024–0.0024) |
| 10,000 contains | 0.1318 (0.1310–0.1319) | 1.4790 (1.4739–1.4801) | 1.2582 (1.2544–1.2709) |
| Iterate all of A | 0.6132 (0.5996–0.6552) | 9.6382 (9.2814–9.6786) | 2.4325 (2.2550–2.4681) |
| Serialize A | 0.8413 (0.8383–0.8639) | 0.0026 (0.0024–0.0026) | 0.0016 (0.0015–0.0017) |
| Deserialize A | 1.4071 (1.3737–1.4446) | 0.0459 (0.0438–0.0464) | 0.0459 (0.0457–0.0470) |

### Dense IDs, sorted bulk input — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 131.6445 (130.4691–133.5660) | 945.4705 (938.2022–948.3565) | 260.1832 (259.4692–261.5896) |
| Intersection | 6.0682 (5.9724–6.0965) | 0.8768 (0.8689–0.9105) | 0.0106 (0.0105–0.0107) |
| Union | 14.6679 (12.6674–14.9890) | 0.4708 (0.4672–0.4744) | 0.0202 (0.0199–0.0203) |
| Difference | 5.8771 (5.4549–6.0416) | 4.5382 (4.4847–4.6799) | 0.0215 (0.0213–0.0217) |
| 10,000 contains | 0.1354 (0.1346–0.1377) | 1.5089 (1.4961–1.5192) | 1.2581 (1.2522–1.2622) |
| Iterate all of A | 7.6103 (7.2177–8.1148) | 91.1205 (90.3944–92.0502) | 26.8042 (26.6893–27.6469) |
| Serialize A | 9.9685 (9.7966–10.1138) | 0.0218 (0.0216–0.0220) | 0.0204 (0.0195–0.0208) |
| Deserialize A | 15.7456 (14.6189–15.9611) | 0.3335 (0.3269–0.3429) | 0.3363 (0.3305–0.3447) |

### Dense IDs, shuffled bulk input — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 15.2723 (15.0352–15.2933) | 106.3758 (106.0600–109.0440) | 30.3559 (30.3168–31.3420) |
| Intersection | 0.5499 (0.5452–0.5641) | 0.0392 (0.0388–0.0395) | 0.0019 (0.0018–0.0020) |
| Union | 1.4521 (1.4292–1.4552) | 0.0199 (0.0196–0.0200) | 0.0023 (0.0023–0.0024) |
| Difference | 0.5541 (0.5528–0.5554) | 0.3973 (0.3937–0.4035) | 0.0024 (0.0024–0.0025) |
| 10,000 contains | 0.1366 (0.1353–0.1393) | 1.5089 (1.4760–1.5228) | 1.2296 (1.2033–1.2552) |
| Iterate all of A | 0.6818 (0.6354–0.6869) | 9.2662 (9.0511–9.5433) | 2.3797 (2.2493–2.4116) |
| Serialize A | 0.9247 (0.9077–0.9278) | 0.0031 (0.0031–0.0032) | 0.0015 (0.0015–0.0015) |
| Deserialize A | 1.4452 (1.4417–1.4768) | 0.0442 (0.0441–0.0470) | 0.0474 (0.0468–0.0475) |

### Dense IDs, shuffled bulk input — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 178.8268 (171.9895–189.5970) | 1051.2939 (1047.3622–1052.3304) | 317.3265 (313.2287–318.8379) |
| Intersection | 10.1168 (9.3216–10.6661) | 0.8861 (0.8843–0.9068) | 0.0115 (0.0115–0.0117) |
| Union | 20.7029 (18.8086–22.0155) | 0.4788 (0.4746–0.4923) | 0.0201 (0.0199–0.0209) |
| Difference | 9.5691 (9.4403–9.7734) | 4.6836 (4.6486–4.8042) | 0.0217 (0.0214–0.0224) |
| 10,000 contains | 0.1332 (0.1332–0.1343) | 1.5022 (1.4909–1.6063) | 1.2783 (1.2415–1.2795) |
| Iterate all of A | 6.8493 (5.9392–7.0933) | 92.1743 (91.6403–92.4354) | 28.3401 (27.6340–28.9210) |
| Serialize A | 11.7694 (11.2741–12.0950) | 0.0206 (0.0204–0.0209) | 0.0202 (0.0201–0.0204) |
| Deserialize A | 23.1443 (21.1595–27.2014) | 0.3275 (0.3273–0.3442) | 0.3388 (0.3319–0.3389) |

### Dispersed IDs, patterned low bits — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 24.6602 (24.2908–27.7977) | 130.2022 (128.7827–131.5548) | 28.6753 (28.4621–29.5425) |
| Intersection | 3.6061 (3.5514–3.7306) | 12.3624 (12.3006–12.5372) | 0.0755 (0.0752–0.0776) |
| Union | 4.2835 (4.1844–4.3465) | 12.7991 (12.5098–12.8074) | 0.1765 (0.1714–0.1856) |
| Difference | 3.5816 (3.4865–3.7697) | 10.5147 (10.4421–10.5172) | 0.1209 (0.1176–0.1225) |
| 10,000 contains | 0.4023 (0.3844–0.4142) | 4.2824 (4.2789–4.3065) | 1.4779 (1.4679–1.5075) |
| Iterate all of A | 0.7927 (0.7690–0.8041) | 12.0740 (11.9849–12.6796) | 2.8036 (2.7153–2.8143) |
| Serialize A | 0.8287 (0.8159–0.8356) | 0.5094 (0.5053–0.5184) | 0.0208 (0.0202–0.0210) |
| Deserialize A | 3.6141 (3.5993–3.7797) | 5.4964 (5.4728–5.6456) | 5.6474 (5.6410–5.6871) |

### Dispersed IDs, patterned low bits — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 876.9293 (874.2031–922.8982) | 1323.3434 (1313.9149–1324.4618) | 288.2054 (286.3244–293.5991) |
| Intersection | 109.8268 (105.5533–111.0855) | 126.2852 (125.6757–126.4030) | 0.7292 (0.7241–0.7416) |
| Union | 58.1837 (57.4877–59.8727) | 131.3522 (128.2816–131.9657) | 1.7383 (1.7123–1.7417) |
| Difference | 108.7058 (108.4728–115.2318) | 108.1773 (106.1555–109.4892) | 1.1820 (1.1499–1.2126) |
| 10,000 contains | 1.5947 (1.0376–1.7087) | 4.3695 (4.2357–4.4504) | 1.6029 (1.6007–1.6708) |
| Iterate all of A | 7.0341 (6.9243–7.1301) | 125.0843 (123.7925–129.8790) | 66.7030 (66.3906–66.8869) |
| Serialize A | 10.2518 (9.9233–11.3328) | 5.6690 (5.6106–5.9075) | 0.3937 (0.3860–0.4348) |
| Deserialize A | 75.2012 (74.4557–78.3275) | 54.1987 (53.9887–56.3453) | 57.0008 (55.9333–57.2707) |

### Dispersed IDs, mixed low bits — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 26.0270 (25.0015–26.9740) | 139.4257 (139.1222–139.7685) | 37.3161 (37.0601–38.2733) |
| Intersection | 0.5398 (0.5376–0.5661) | 12.6068 (12.5744–12.7290) | 0.0760 (0.0751–0.0789) |
| Union | 1.4001 (1.3930–1.4371) | 12.7490 (12.6804–13.2636) | 0.1785 (0.1716–0.1835) |
| Difference | 0.5132 (0.5099–0.5156) | 10.5998 (10.5574–10.6978) | 0.1208 (0.1169–0.1223) |
| 10,000 contains | 0.1388 (0.1387–0.1473) | 4.3473 (4.3292–4.4068) | 1.5224 (1.5054–1.5316) |
| Iterate all of A | 0.6598 (0.6563–0.7360) | 12.3730 (12.0977–12.8319) | 2.8794 (2.7926–2.9110) |
| Serialize A | 0.8623 (0.8488–0.8707) | 0.5063 (0.5008–0.5118) | 0.0215 (0.0210–0.0223) |
| Deserialize A | 1.6365 (1.6195–1.6545) | 5.4870 (5.4234–5.5298) | 5.7368 (5.7280–5.7938) |

### Dispersed IDs, mixed low bits — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 299.7989 (291.8948–300.6180) | 1416.9556 (1403.4419–1423.0725) | 377.5345 (373.1837–383.0481) |
| Intersection | 9.6493 (9.4650–10.3762) | 126.5668 (126.2971–126.7233) | 0.7396 (0.7297–0.7503) |
| Union | 19.6863 (19.2137–19.8335) | 130.2426 (128.6118–130.6012) | 1.7329 (1.6900–1.7593) |
| Difference | 9.1395 (9.0290–9.7220) | 107.9578 (105.1132–112.2388) | 1.2007 (1.1677–1.2016) |
| 10,000 contains | 0.1410 (0.1359–0.1414) | 4.1393 (4.1107–4.2129) | 1.6301 (1.5972–1.6438) |
| Iterate all of A | 6.7391 (6.5469–8.2036) | 124.9511 (123.2639–126.8910) | 66.4686 (66.2860–66.6890) |
| Serialize A | 10.0631 (9.8216–10.2127) | 5.8087 (5.5964–5.8281) | 0.4008 (0.3837–0.4181) |
| Deserialize A | 21.7483 (21.4363–22.4389) | 55.7135 (55.6790–56.6678) | 56.8388 (53.3703–57.2873) |

### Consecutive IDs, range API — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 12.3469 (11.8877–12.6082) | 0.0769 (0.0757–0.0830) | 0.0073 (0.0069–0.0087) |
| Intersection | 0.4831 (0.4771–0.4894) | 0.0346 (0.0346–0.0350) | 0.0009 (0.0009–0.0009) |
| Union | 0.4888 (0.4868–0.4925) | 0.0285 (0.0285–0.0302) | 0.0010 (0.0010–0.0010) |
| Difference | 0.3041 (0.2991–0.3152) | 0.2137 (0.2118–0.2186) | 0.0009 (0.0009–0.0009) |
| 10,000 contains | 0.1283 (0.1282–0.1306) | 1.5400 (1.4979–1.5585) | 1.2732 (1.2377–1.3086) |
| Iterate all of A | 0.7170 (0.5898–0.7339) | 6.1279 (6.1140–6.1864) | 2.1928 (2.1777–2.3298) |
| Serialize A | 0.7967 (0.7751–0.8208) | 0.0008 (0.0008–0.0008) | 0.0003 (0.0003–0.0003) |
| Deserialize A | 1.4014 (1.3593–1.4423) | 0.0291 (0.0283–0.0293) | 0.9527 (0.9333–0.9802) |

### Consecutive IDs, range API — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 124.9578 (124.5970–127.6536) | 0.4181 (0.3772–0.4204) | 0.0096 (0.0076–0.0097) |
| Intersection | 5.9813 (5.8827–6.0018) | 0.1534 (0.1521–0.1573) | 0.0012 (0.0012–0.0012) |
| Union | 6.6315 (6.3951–7.0805) | 0.1303 (0.1295–0.1356) | 0.0017 (0.0017–0.0017) |
| Difference | 3.6256 (3.6039–4.0236) | 1.5568 (1.5426–1.5576) | 0.0013 (0.0013–0.0013) |
| 10,000 contains | 0.1326 (0.1313–0.1333) | 1.5321 (1.5054–1.5538) | 1.3034 (1.2897–1.3070) |
| Iterate all of A | 7.5602 (6.1884–8.0847) | 60.7523 (60.4150–61.8332) | 21.4036 (21.1174–22.6807) |
| Serialize A | 9.6615 (9.4418–9.8911) | 0.0082 (0.0081–0.0083) | 0.0004 (0.0004–0.0004) |
| Deserialize A | 15.9936 (15.8525–16.1418) | 0.2216 (0.2194–0.2231) | 9.4981 (9.4899–9.7523) |

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
- These measurements run on a shared machine without CPU pinning or controlled thermal state. Sample ranges are disclosed; very short timings and large ratios should be treated accordingly.
- The native adapter is explicitly selected. Automatic fallback is tested in the package suite but does not participate in the native timing measurements.

## Reproduce

```sh
composer install
bash tools/build-native.sh
python3 benchmarks/run.py --sizes 100000 1000000 --workloads dense shuffled sparse sparse_mixed ranges --repetitions 3 --target-ms 25 --output benchmarks/results/new-run
```

Use `--php /absolute/path/to/herd/php84` or `--library /absolute/path/to/libroaring.dylib` to override discovery. The runner requires Python 3.9+ and `getrusage()` support on macOS or Linux. It does not need Python packages.

Files: [raw results](results.json), [summary CSV](summary.csv). All source SHA-256 digests, runtime settings, commands, sample timings, minima/maxima, and standard deviations are retained for auditing.
