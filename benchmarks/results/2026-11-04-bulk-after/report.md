# PHP sets before adoption, Roaring after adoption

Measured 2026-11-04T10:44:03.229940+01:00 through 2026-11-04T10:45:01.991164+01:00. All times below are medians of 3 independent PHP processes per configuration. These are CLI measurements, not Laravel HTTP throughput.

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
| dense | 100,000 | array | 12.888 | 0.484974 | 38.36 | 1,262,971 |
| dense | 100,000 | php | 45.653 | 0.028613 | 28.56 | 41,008 |
| dense | 100,000 | native | 22.660 | 0.001867 | 28.19 | 41,008 |
| dense | 1,000,000 | array | 136.664 | 6.189592 | 106.88 | 13,629,638 |
| dense | 1,000,000 | php | 459.169 | 0.784116 | 29.48 | 377,208 |
| dense | 1,000,000 | native | 232.303 | 0.010901 | 28.92 | 377,208 |
| shuffled | 100,000 | array | 15.541 | 0.579326 | 38.38 | 1,262,971 |
| shuffled | 100,000 | php | 79.506 | 0.028289 | 27.83 | 41,008 |
| shuffled | 100,000 | native | 26.745 | 0.001869 | 28.28 | 41,008 |
| shuffled | 1,000,000 | array | 195.028 | 10.095250 | 106.83 | 13,629,638 |
| shuffled | 1,000,000 | php | 901.518 | 0.773908 | 29.38 | 377,208 |
| shuffled | 1,000,000 | native | 289.834 | 0.011672 | 30.36 | 377,208 |
| sparse | 100,000 | array | 24.699 | 3.721090 | 38.36 | 1,545,753 |
| sparse | 100,000 | php | 33.914 | 0.275843 | 28.86 | 225,008 |
| sparse | 100,000 | native | 25.339 | 0.075129 | 28.58 | 225,008 |
| sparse | 1,000,000 | array | 914.832 | 104.938334 | 106.94 | 16,457,472 |
| sparse | 1,000,000 | php | 334.196 | 2.851458 | 41.59 | 2,250,008 |
| sparse | 1,000,000 | native | 257.955 | 0.722882 | 34.27 | 2,250,008 |
| sparse_mixed | 100,000 | array | 25.271 | 0.575733 | 38.39 | 1,545,759 |
| sparse_mixed | 100,000 | php | 43.645 | 0.277503 | 28.92 | 225,008 |
| sparse_mixed | 100,000 | native | 35.038 | 0.076766 | 28.59 | 225,008 |
| sparse_mixed | 1,000,000 | array | 301.107 | 10.243875 | 106.88 | 16,457,479 |
| sparse_mixed | 1,000,000 | php | 433.537 | 2.806718 | 41.62 | 2,250,008 |
| sparse_mixed | 1,000,000 | native | 348.380 | 0.743508 | 34.53 | 2,250,008 |
| ranges | 100,000 | array | 12.452 | 0.486743 | 35.78 | 1,188,901 |
| ranges | 100,000 | php | 0.089 | 0.035224 | 27.72 | 16,408 |
| ranges | 100,000 | native | 0.007 | 0.000951 | 27.86 | 25 |
| ranges | 1,000,000 | array | 124.139 | 6.197592 | 83.59 | 12,888,902 |
| ranges | 1,000,000 | php | 0.373 | 0.038834 | 27.86 | 131,208 |
| ranges | 1,000,000 | native | 0.007 | 0.001255 | 27.88 | 230 |

The PHP-array storage column uses PHP `serialize()`; the package columns use standard Roaring portable bytes. These formats are not interchangeable. This compares the respective persistence representations, not compression of identical encoded input.

## Before/after ratios

A ratio above 1 means the denominator took less time. These are ratios of medians, not an application-wide speedup.

| Workload | IDs/input | Array / native build | Array / PHP intersection | Array / native intersection | PHP / native intersection |
| --- | ---: | ---: | ---: | ---: | ---: |
| dense | 100,000 | 0.57× | 16.95× | 259.72× | 15.32× |
| dense | 1,000,000 | 0.59× | 7.89× | 567.82× | 71.93× |
| shuffled | 100,000 | 0.58× | 20.48× | 310.02× | 15.14× |
| shuffled | 1,000,000 | 0.67× | 13.04× | 864.90× | 66.30× |
| sparse | 100,000 | 0.97× | 13.49× | 49.53× | 3.67× |
| sparse | 1,000,000 | 3.55× | 36.80× | 145.17× | 3.94× |
| sparse_mixed | 100,000 | 0.72× | 2.07× | 7.50× | 3.61× |
| sparse_mixed | 1,000,000 | 0.86× | 3.65× | 13.78× | 3.77× |
| ranges | 100,000 | 1747.70× | 13.82× | 511.81× | 37.04× |
| ranges | 1,000,000 | 18056.54× | 159.59× | 4937.83× | 30.94× |

## All operation timings

All values are milliseconds. Membership is a complete batch of 10,000 probes with 50% hits; iteration visits every ID in A and computes a checksum. Other rows are one operation. Ranges in parentheses are the minimum–maximum sample values.

### Dense IDs, sorted bulk input — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 12.8883 (11.8682–13.0235) | 45.6532 (45.2653–46.3380) | 22.6605 (22.6499–23.8530) |
| Intersection | 0.4850 (0.4720–0.4954) | 0.0286 (0.0285–0.0293) | 0.0019 (0.0018–0.0019) |
| Union | 1.3338 (1.2641–1.3544) | 0.0141 (0.0138–0.0143) | 0.0024 (0.0023–0.0024) |
| Difference | 0.4853 (0.4731–0.5065) | 0.2028 (0.2018–0.2136) | 0.0025 (0.0024–0.0025) |
| 10,000 contains | 0.1316 (0.1310–0.1325) | 1.4816 (1.4672–1.5704) | 1.2802 (1.2800–1.2811) |
| Iterate all of A | 0.7033 (0.6061–0.7420) | 9.4308 (9.2375–9.7329) | 2.2518 (2.2328–2.4853) |
| Serialize A | 0.8535 (0.8502–0.8659) | 0.0033 (0.0033–0.0034) | 0.0015 (0.0015–0.0016) |
| Deserialize A | 1.4202 (1.4193–1.4363) | 0.0445 (0.0443–0.0446) | 0.0468 (0.0464–0.0476) |

### Dense IDs, sorted bulk input — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 136.6643 (132.6534–136.9148) | 459.1686 (457.2592–461.9320) | 232.3025 (227.2061–232.7558) |
| Intersection | 6.1896 (6.1690–6.3053) | 0.7841 (0.7479–1.2140) | 0.0109 (0.0108–0.0110) |
| Union | 15.6064 (13.3180–15.6879) | 0.3512 (0.3372–0.3542) | 0.0204 (0.0197–0.0206) |
| Difference | 5.9219 (5.7625–6.2608) | 0.5402 (0.5028–0.5442) | 0.0217 (0.0215–0.0223) |
| 10,000 contains | 0.1337 (0.1332–0.1384) | 1.4992 (1.4942–1.5524) | 1.2812 (1.2450–1.2902) |
| Iterate all of A | 6.9891 (5.7933–7.3268) | 92.8070 (92.1800–95.2072) | 27.6132 (27.4889–28.1336) |
| Serialize A | 10.0633 (10.0573–10.1059) | 0.0221 (0.0219–0.0223) | 0.0207 (0.0200–0.0207) |
| Deserialize A | 16.3179 (14.9727–16.5150) | 0.3419 (0.3245–0.3434) | 0.3399 (0.3383–0.3446) |

### Dense IDs, shuffled bulk input — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 15.5414 (12.9169–16.3671) | 79.5063 (78.4973–80.5807) | 26.7450 (26.6856–27.6200) |
| Intersection | 0.5793 (0.5781–0.5829) | 0.0283 (0.0280–0.0283) | 0.0019 (0.0019–0.0020) |
| Union | 1.4085 (1.3715–1.5090) | 0.0140 (0.0138–0.0143) | 0.0024 (0.0024–0.0025) |
| Difference | 0.5562 (0.5474–0.5877) | 0.2093 (0.2062–0.2136) | 0.0024 (0.0024–0.0025) |
| 10,000 contains | 0.1348 (0.1345–0.1364) | 1.5246 (1.4994–1.5533) | 1.2567 (1.2521–1.2763) |
| Iterate all of A | 0.6601 (0.6541–0.7441) | 9.3166 (9.2410–9.3954) | 2.2879 (2.2834–2.3201) |
| Serialize A | 0.9297 (0.9268–0.9583) | 0.0032 (0.0032–0.0033) | 0.0016 (0.0014–0.0016) |
| Deserialize A | 1.4671 (1.4325–1.4709) | 0.0445 (0.0444–0.0468) | 0.0477 (0.0473–0.0483) |

### Dense IDs, shuffled bulk input — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 195.0279 (187.4835–198.6247) | 901.5180 (886.7435–906.2948) | 289.8338 (288.9881–295.6180) |
| Intersection | 10.0953 (9.4913–10.2322) | 0.7739 (0.7684–0.7848) | 0.0117 (0.0116–0.0117) |
| Union | 20.3879 (19.1830–22.3199) | 0.3513 (0.3471–0.3623) | 0.0204 (0.0203–0.0206) |
| Difference | 11.2034 (10.5914–11.8042) | 0.5374 (0.5230–0.5452) | 0.0222 (0.0219–0.0227) |
| 10,000 contains | 0.1362 (0.1358–0.1364) | 1.5371 (1.5220–1.5439) | 1.2915 (1.2738–1.2924) |
| Iterate all of A | 6.5739 (6.1785–7.2064) | 91.2924 (91.2158–92.7469) | 27.9999 (27.1580–29.1296) |
| Serialize A | 11.5873 (11.4364–12.0706) | 0.0214 (0.0213–0.0230) | 0.0205 (0.0200–0.0208) |
| Deserialize A | 23.8794 (23.5828–25.0772) | 0.3398 (0.3243–0.3918) | 0.3388 (0.3329–0.3494) |

### Dispersed IDs, patterned low bits — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 24.6986 (24.6430–25.0491) | 33.9138 (33.2964–34.4492) | 25.3392 (23.8838–26.0368) |
| Intersection | 3.7211 (3.6584–3.7348) | 0.2758 (0.2752–0.2816) | 0.0751 (0.0740–0.0758) |
| Union | 4.3574 (4.0507–4.3980) | 0.4927 (0.4896–0.4966) | 0.1727 (0.1699–0.1731) |
| Difference | 3.6316 (3.4736–3.6805) | 0.3908 (0.3896–0.3955) | 0.1167 (0.1146–0.1192) |
| 10,000 contains | 0.4008 (0.3926–0.4619) | 4.2845 (4.2365–4.3798) | 1.4945 (1.4911–1.5131) |
| Iterate all of A | 0.6966 (0.6152–0.7710) | 12.2990 (12.2406–12.4693) | 2.8154 (2.7555–2.8300) |
| Serialize A | 0.8602 (0.8462–0.9007) | 0.5058 (0.4897–0.5184) | 0.0219 (0.0215–0.0223) |
| Deserialize A | 3.7127 (3.7031–3.7198) | 5.5110 (5.4682–5.6054) | 5.6850 (5.6284–5.7275) |

### Dispersed IDs, patterned low bits — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 914.8315 (900.6349–938.1883) | 334.1963 (333.5632–336.9380) | 257.9552 (252.4417–258.9180) |
| Intersection | 104.9383 (104.8469–107.4432) | 2.8515 (2.8293–2.8848) | 0.7229 (0.7202–0.7543) |
| Union | 56.9820 (56.5130–58.5009) | 5.5489 (5.1444–5.7339) | 1.7311 (1.7272–1.7407) |
| Difference | 110.8558 (110.2006–111.2687) | 3.9898 (3.9720–4.0921) | 1.1938 (1.1755–1.1966) |
| 10,000 contains | 1.3326 (0.7172–1.8695) | 4.3417 (4.3342–4.4041) | 1.5830 (1.5624–1.5929) |
| Iterate all of A | 7.2151 (6.9106–7.3986) | 126.1927 (124.4647–127.1957) | 66.8336 (66.4425–68.8881) |
| Serialize A | 10.2218 (10.0671–10.2843) | 5.7186 (5.6782–5.7228) | 0.3903 (0.3878–0.4053) |
| Deserialize A | 76.0294 (74.4180–76.1992) | 55.4559 (54.2845–55.5913) | 56.8057 (56.5824–57.6541) |

### Dispersed IDs, mixed low bits — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 25.2706 (24.9141–27.1935) | 43.6453 (42.6061–45.3629) | 35.0384 (34.8121–35.3520) |
| Intersection | 0.5757 (0.5461–0.5843) | 0.2775 (0.2758–0.2818) | 0.0768 (0.0753–0.0810) |
| Union | 1.4811 (1.4471–1.7286) | 0.4978 (0.4929–0.5062) | 0.1761 (0.1748–0.1761) |
| Difference | 0.5354 (0.5271–0.7294) | 0.4026 (0.4020–0.4039) | 0.1161 (0.1151–0.1185) |
| 10,000 contains | 0.1496 (0.1417–0.1593) | 4.2941 (4.2523–4.3354) | 1.5003 (1.4935–1.6231) |
| Iterate all of A | 0.7355 (0.5888–0.7586) | 12.6603 (12.3863–12.7328) | 2.8782 (2.8183–3.3092) |
| Serialize A | 0.8686 (0.8614–0.8720) | 0.5099 (0.5053–0.5290) | 0.0223 (0.0220–0.0230) |
| Deserialize A | 1.6929 (1.6456–1.7068) | 5.6285 (5.5968–5.6982) | 5.6994 (5.3952–5.7459) |

### Dispersed IDs, mixed low bits — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 301.1067 (300.6639–302.2781) | 433.5369 (431.8492–434.4309) | 348.3800 (346.7582–350.2143) |
| Intersection | 10.2439 (9.7448–10.2644) | 2.8067 (2.7862–2.8223) | 0.7435 (0.7399–0.7440) |
| Union | 18.5923 (17.7907–18.9149) | 5.6232 (5.1768–5.6901) | 1.7601 (1.6984–1.7701) |
| Difference | 9.7888 (8.3940–10.7044) | 4.0525 (3.9759–4.0608) | 1.1910 (1.1676–1.2104) |
| 10,000 contains | 0.1380 (0.1377–0.1387) | 4.1892 (4.0960–4.2305) | 1.6046 (1.5893–1.6066) |
| Iterate all of A | 7.1946 (6.8912–8.0495) | 125.6786 (124.6870–127.7641) | 65.9141 (65.8437–66.9044) |
| Serialize A | 10.1012 (10.0282–10.3535) | 5.7211 (5.6495–5.8465) | 0.4110 (0.4104–0.4273) |
| Deserialize A | 23.1513 (20.4886–24.5127) | 56.0168 (54.6873–56.3320) | 57.4752 (57.1159–57.9677) |

### Consecutive IDs, range API — 100,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 12.4524 (12.4114–12.5938) | 0.0892 (0.0598–0.0910) | 0.0071 (0.0066–0.0105) |
| Intersection | 0.4867 (0.4835–0.4895) | 0.0352 (0.0347–0.0371) | 0.0010 (0.0009–0.0010) |
| Union | 0.4672 (0.4567–0.4676) | 0.0286 (0.0285–0.0292) | 0.0010 (0.0010–0.0010) |
| Difference | 0.3064 (0.2978–0.3073) | 0.2106 (0.1994–0.2115) | 0.0009 (0.0009–0.0009) |
| 10,000 contains | 0.1290 (0.1272–0.1312) | 1.5073 (1.4971–1.5212) | 1.2763 (1.2680–1.3049) |
| Iterate all of A | 0.7833 (0.7567–0.7865) | 6.1813 (6.1290–6.1954) | 2.2577 (2.2504–2.3521) |
| Serialize A | 0.8439 (0.7934–0.8498) | 0.0008 (0.0008–0.0009) | 0.0003 (0.0003–0.0003) |
| Deserialize A | 1.3553 (1.3259–1.3849) | 0.0296 (0.0290–0.0299) | 0.9697 (0.9559–0.9812) |

### Consecutive IDs, range API — 1,000,000 IDs/input

| Operation | PHP array | Package PHP | Package native |
| --- | ---: | ---: | ---: |
| Build A+B | 124.1387 (122.8051–124.7549) | 0.3728 (0.3570–0.3776) | 0.0069 (0.0065–0.0070) |
| Intersection | 6.1976 (6.0418–6.2952) | 0.0388 (0.0381–0.0391) | 0.0013 (0.0013–0.0013) |
| Union | 7.0468 (6.9666–7.5357) | 0.0335 (0.0330–0.0346) | 0.0018 (0.0017–0.0018) |
| Difference | 3.6282 (3.5602–3.6496) | 0.2150 (0.2098–0.2164) | 0.0014 (0.0013–0.0014) |
| 10,000 contains | 0.1295 (0.1293–0.1315) | 1.5257 (1.5006–1.5433) | 1.3078 (1.2901–1.3122) |
| Iterate all of A | 6.7871 (6.6682–7.6116) | 60.7125 (60.6476–61.1649) | 20.9573 (20.8408–21.1261) |
| Serialize A | 9.6641 (9.5046–9.6692) | 0.0082 (0.0081–0.0083) | 0.0004 (0.0004–0.0004) |
| Deserialize A | 16.2095 (14.7314–16.7248) | 0.2202 (0.2172–0.2223) | 9.8381 (9.6367–9.9465) |

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
