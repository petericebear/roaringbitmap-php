#!/usr/bin/env python3
"""Run isolated PHP workers sequentially and preserve every measurement."""

import argparse
import csv
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import statistics
import subprocess
import sys
from datetime import datetime
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
BACKENDS = ["array", "php", "native"]
WORKLOADS = ["dense", "shuffled", "sparse", "ranges"]
LABELS = {
    "dense": "Dense IDs, sorted bulk input",
    "shuffled": "Dense IDs, shuffled bulk input",
    "sparse": "Dispersed IDs, sorted bulk input",
    "ranges": "Consecutive IDs, range API",
}
METRICS = ["build_pair_ms", "intersection_ms", "union_ms", "difference_ms", "contains_10000_ms",
           "iterate_all_ms", "serialize_ms", "deserialize_ms", "build_peak_rss_bytes", "serialized_bytes"]


def output(command):
    completed = subprocess.run(command, text=True, capture_output=True)
    return completed.stdout.strip() if completed.returncode == 0 else None


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def stats(values):
    return {"median": statistics.median(values), "min": min(values), "max": max(values),
            "mean": statistics.mean(values), "stdev": statistics.stdev(values) if len(values) > 1 else 0}


def aggregate(samples):
    results = []
    for workload, size, backend in sorted({(s["workload"], s["size"], s["backend"]) for s in samples}):
        group = [s for s in samples if (s["workload"], s["size"], s["backend"]) == (workload, size, backend)]
        record = {"workload": workload, "size": size, "backend": backend, "samples": len(group), "metrics": {}}
        for metric in METRICS + ["initialization_ms", "build_php_heap_delta_bytes"]:
            values = [s["timings"][metric[:-3]]["ms"] if metric[:-3] in s["timings"] else s[metric] for s in group]
            record["metrics"][metric] = stats(values)
        results.append(record)
    return results


def render_report(document, destination):
    environment = document["environment"]
    rows = document["summary"]
    index = {(r["workload"], r["size"], r["backend"]): r["metrics"] for r in rows}

    def value(workload, size, backend, metric):
        return index[(workload, size, backend)][metric]["median"]

    def ratio(workload, size, numerator, denominator, metric):
        return value(workload, size, numerator, metric) / value(workload, size, denominator, metric)

    lines = [
        "# Herd benchmark: PHP sets before adoption, Roaring after adoption",
        "",
        f"Measured {document['started_at']} through {document['finished_at']}. All times below are medians of "
        f"{document['repetitions']} independent PHP processes per configuration. These are local CLI measurements, not Laravel HTTP throughput.",
        "",
        "**Before:** a PHP array keyed by integer ID, with `true` values. **After:** this package forced to `Backend::Php` or `Backend::Native`. "
        "This also measures the change from the PHP backend to native acceleration. The package algorithms were not modified for this run.",
        "",
        "## Test machine and build",
        "",
        f"- CPU: {environment.get('cpu') or 'unavailable'}; {environment.get('logical_cpus') or 'unknown'} logical CPUs; "
        f"{int(environment['ram_bytes']) / 2**30:.0f} GiB RAM." if environment.get('ram_bytes') else "- Hardware details unavailable; see results.json.",
        f"- OS: {environment['platform']}; architecture: {environment['architecture']}.",
        f"- PHP: {document['samples'][0]['runtime']['php']}, Laravel Herd binary `{document['samples'][0]['runtime']['binary']}`.",
        "- CRoaring: 5.4.0, compiled locally with `-O3 -std=c11 -dynamiclib -fPIC` on macOS "
        "(`-shared` on Linux); verified source hashes and library hash are in results.json.",
        f"- Compiler: {environment['compiler'].splitlines()[0] if environment.get('compiler') else 'unknown'}.",
        "- Each worker uses `ffi.enable=1`, `opcache.enable_cli=0`, `opcache.jit=disable`, "
        "and `memory_limit=1G`. Herd's default CLI memory limit was 128M; the override permits the large PHP-array baseline. Xdebug state is recorded in raw results.",
        "- The native library is passed via `ROARING_LIBRARY`. No global Herd/PHP configuration changes are required.",
        "",
        "## Construction, intersection, and process memory",
        "",
        "Each input contains N unique IDs. A and B overlap by exactly 50%. Construction builds both inputs; intersection returns a new set. "
        "RSS is the whole process peak captured immediately after construction, before query benchmarks or validation allocate large temporaries. "
        "It includes the PHP runtime and native allocations, not just bitmap storage.",
        "",
        "| Workload | IDs/input | Backend | Build two (ms) | One intersection (ms) | Build peak RSS (MiB) | Stored A (bytes) |",
        "| --- | ---: | --- | ---: | ---: | ---: | ---: |",
    ]
    for workload in document["workloads"]:
        for size in document["sizes"]:
            for backend in BACKENDS:
                m = index[(workload, size, backend)]
                lines.append(f"| {workload} | {size:,} | {backend} | {m['build_pair_ms']['median']:.3f} | "
                             f"{m['intersection_ms']['median']:.6f} | {m['build_peak_rss_bytes']['median'] / 2**20:.2f} | "
                             f"{m['serialized_bytes']['median']:,.0f} |")
    lines += ["", "The PHP-array storage column uses PHP `serialize()`; the package columns use standard Roaring portable bytes. "
              "These formats are not interchangeable. This compares the respective persistence representations, not compression of identical encoded input.",
              "", "## Before/after ratios", "",
              "A ratio above 1 means the denominator took less time. These are ratios of medians, not an application-wide speedup.", "",
              "| Workload | IDs/input | Array / native build | Array / PHP intersection | Array / native intersection | PHP / native intersection |",
              "| --- | ---: | ---: | ---: | ---: | ---: |"]
    for workload in document["workloads"]:
        for size in document["sizes"]:
            lines.append(f"| {workload} | {size:,} | {ratio(workload, size, 'array', 'native', 'build_pair_ms'):.2f}× | "
                         f"{ratio(workload, size, 'array', 'php', 'intersection_ms'):.2f}× | "
                         f"{ratio(workload, size, 'array', 'native', 'intersection_ms'):.2f}× | "
                         f"{ratio(workload, size, 'php', 'native', 'intersection_ms'):.2f}× |")
    lines += ["", "## All operation timings", "",
              "All values are milliseconds. Membership is a complete batch of 10,000 probes with 50% hits; iteration visits every ID in A "
              "and computes a checksum. Other rows are one operation. Ranges in parentheses are the minimum–maximum sample values."]
    labels = {"build_pair_ms": "Build A+B", "intersection_ms": "Intersection", "union_ms": "Union", "difference_ms": "Difference",
              "contains_10000_ms": "10,000 contains", "iterate_all_ms": "Iterate all of A", "serialize_ms": "Serialize A", "deserialize_ms": "Deserialize A"}
    for workload in document["workloads"]:
        for size in document["sizes"]:
            lines += ["", f"### {LABELS[workload]} — {size:,} IDs/input", "",
                      "| Operation | PHP array | Package PHP | Package native |", "| --- | ---: | ---: | ---: |"]
            for metric, label in labels.items():
                cells = []
                for backend in BACKENDS:
                    s = index[(workload, size, backend)][metric]
                    cells.append(f"{s['median']:.4f} ({s['min']:.4f}–{s['max']:.4f})")
                lines.append(f"| {label} | " + " | ".join(cells) + " |")
    lines += ["", "## Method", "",
              "- Workers run sequentially, never concurrently. Backend order rotates between repetitions to reduce ordering bias. Every sample starts a fresh PHP process.",
              "- Tiny-set warmup primes autoloading and library loading before timing construction. Input generation and validation by the public API are included in construction; a prebuilt input array is not retained.",
              "- Query operations get one warmup and one calibration call, followed by an adaptive batch targeting "
              f"{document['target_ms']:g} ms (1–100,000 iterations). Result destruction is included. Each process contributes one per-operation batch average; the report takes the median across processes.",
              "- Construction is measured once per fresh process. Initialization timing and every query batch size/duration are preserved in results.json.",
              "- Dataset mapping: `dense` and `shuffled` use ID `3*i`; `sparse` uses `2048*i + (i % 31)`; `ranges` uses `i`. "
              "A uses indices `[0,N)`, B uses `[N/2,3N/2)`. Shuffled inputs permute local indices as `(i*48271) % N`, a bijection for the tested sizes.",
              "- Range construction uses `addRange()` for the package; PHP arrays enumerate the same IDs. This measures the benefit when range information is already available, not the ingestion of a supplied list.",
              "- The array baseline uses `array_intersect_key`, array union (`+`), `array_diff_key`, and `isset`, rather than value-scanning set functions. Array iteration preserves insertion order; package iteration additionally guarantees sorted snapshots.",
              "- All samples check cardinalities, membership hits, iteration checksums, and round-trip cardinalities. The first repetition additionally checks each algebra result and restored set against independent closed-form sums.",
              "- `getrusage().ru_maxrss` is normalized to bytes (bytes on macOS, KiB on Linux). Full-process RSS is reported without subtracting unrelated high-water marks. "
              "The separately recorded PHP allocator delta excludes native C allocations and must not be interpreted as native bitmap size.",
              "", "## Interpretation and limits", "",
              "- Compare construction as well as queries. Repeated operations on retained bitmaps can amortize ingestion cost; one-shot queries may not. Membership, iteration, and decoding must be assessed separately from bulk algebra.",
              "- Native decoding still performs the package's PHP validation pass. It is not a measurement of raw CRoaring deserialization alone.",
              "- Native range containers and PHP array/bitset containers have different memory and persistence costs. No run optimization is applied to bulk-loaded native bitmaps in this benchmark.",
              "- These are deterministic synthetic datasets with 50% overlap. They do not cover every cardinality distribution, mutation pattern, real request, or concurrency level.",
              "- This is a single shared developer laptop with no CPU pinning or controlled thermal state. Sample ranges are disclosed; very short timings and large ratios should be treated accordingly.",
              "- The native adapter is explicitly selected. Automatic fallback is tested in the package suite but does not participate in the native timing measurements.",
              "", "## Reproduce", "", "```sh", "composer install", "bash tools/build-native.sh",
              "python3 benchmarks/run.py --sizes 100000 1000000 --repetitions 5 --output benchmarks/results/new-run",
              "```", "", "Use `--php /absolute/path/to/herd/php84` or `--library /absolute/path/to/libroaring.dylib` to override discovery. "
              "The runner requires Python 3.9+ and `getrusage()` support on macOS or Linux. It does not need Python packages.",
              "", "Files: [raw results](results.json), [summary CSV](summary.csv). All source SHA-256 digests, runtime settings, "
              "commands, sample timings, minima/maxima, and standard deviations are retained for auditing.", ""]
    (destination / "report.md").write_text("\n".join(lines))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=shutil.which("php"))
    parser.add_argument("--library")
    parser.add_argument("--sizes", type=int, nargs="+", default=[100000, 1000000], choices=[10000, 100000, 1000000])
    parser.add_argument("--workloads", nargs="+", default=WORKLOADS, choices=WORKLOADS)
    parser.add_argument("--repetitions", type=int, default=5)
    parser.add_argument("--target-ms", type=float, default=25)
    parser.add_argument("--output", type=Path, default=ROOT / "benchmarks/results/latest")
    args = parser.parse_args()
    if not args.php or args.repetitions < 3 or not 1 <= args.target_ms <= 1000:
        parser.error("Provide a PHP binary, at least three repetitions, and target-ms between 1 and 1000.")
    if platform.system() not in ["Darwin", "Linux"]:
        parser.error("The RSS measurement supports macOS and Linux.")
    suffix = "dylib" if platform.system() == "Darwin" else "so"
    library = Path(args.library or ROOT / f".build/croaring/libroaring.{suffix}").resolve()
    if not library.is_file():
        parser.error("Native library missing; run bash tools/build-native.sh first.")
    destination = args.output.resolve()
    destination.mkdir(parents=True, exist_ok=True)
    if (destination / "results.json").exists():
        parser.error("Output already contains results.json; choose a new directory to preserve prior results.")
    timezone = ZoneInfo("Europe/Amsterdam")
    environment = {
        "platform": platform.platform(), "architecture": platform.machine(),
        "cpu": output(["sysctl", "-n", "machdep.cpu.brand_string"]) if platform.system() == "Darwin" else platform.processor(),
        "logical_cpus": os.cpu_count(),
        "ram_bytes": output(["sysctl", "-n", "hw.memsize"]) if platform.system() == "Darwin" else None,
        "compiler": output([os.environ.get("CC", "cc"), "--version"]),
    }
    # Unavailable restricted hardware queries do not invalidate timing or process RSS.
    sources = list((ROOT / "src").rglob("*.php")) + [ROOT / "benchmarks/worker.php", Path(__file__).resolve(), ROOT / "tools/build-native.sh"]
    hashes = {str(p.relative_to(ROOT)): digest(p) for p in sorted(sources)}
    for name in ["roaring.c", "roaring.h"]:
        path = ROOT / ".build/croaring" / name
        if path.exists():
            hashes[str(path.relative_to(ROOT))] = digest(path)
    document = {"started_at": datetime.now(timezone).isoformat(), "environment": environment,
                "repetitions": args.repetitions, "sizes": args.sizes, "workloads": args.workloads,
                "target_ms": args.target_ms, "native_library": str(library), "native_library_sha256": digest(library),
                "source_sha256": hashes, "runner_command": sys.argv, "samples": []}
    env = os.environ.copy()
    env["ROARING_LIBRARY"] = str(library)
    total = len(args.workloads) * len(args.sizes) * args.repetitions * len(BACKENDS)
    for workload in args.workloads:
        for size in args.sizes:
            for repetition in range(args.repetitions):
                order = BACKENDS[repetition % 3:] + BACKENDS[:repetition % 3]
                for backend in order:
                    command = [args.php, "-d", "ffi.enable=1", "-d", "opcache.enable_cli=0", "-d", "opcache.jit=disable",
                               "-d", "memory_limit=1G", str(ROOT / "benchmarks/worker.php"), f"--backend={backend}",
                               f"--workload={workload}", f"--size={size}", f"--target-ms={args.target_ms}", f"--verify={int(repetition == 0)}"]
                    completed = subprocess.run(command, cwd=ROOT, env=env, text=True, capture_output=True, timeout=180)
                    if completed.returncode != 0 or completed.stderr:
                        raise RuntimeError(f"Worker failed: {command}\n{completed.stdout}\n{completed.stderr}")
                    sample = json.loads(completed.stdout)
                    sample.update({"repetition": repetition + 1, "command": command})
                    document["samples"].append(sample)
                    (destination / "partial.json").write_text(json.dumps(document, indent=2) + "\n")
                    print(f"[{len(document['samples']):3}/{total}] {workload:8} N={size:7,} {backend:6} "
                          f"sample={repetition + 1} build={sample['build_pair_ms']:.2f}ms "
                          f"intersect={sample['timings']['intersection']['ms']:.4f}ms "
                          f"RSS={sample['build_peak_rss_bytes'] / 2**20:.1f}MiB", flush=True)
    document["finished_at"] = datetime.now(timezone).isoformat()
    document["summary"] = aggregate(document["samples"])
    (destination / "results.json").write_text(json.dumps(document, indent=2) + "\n")
    with (destination / "summary.csv").open("w", newline="") as file:
        fields = ["workload", "size", "backend", "metric", "median", "min", "max", "mean", "stdev"]
        writer = csv.DictWriter(file, fieldnames=fields)
        writer.writeheader()
        for row in document["summary"]:
            for metric, values in row["metrics"].items():
                writer.writerow({"workload": row["workload"], "size": row["size"], "backend": row["backend"], "metric": metric, **values})
    render_report(document, destination)
    (destination / "partial.json").unlink()
    print(f"Report: {destination / 'report.md'}")


if __name__ == "__main__":
    main()
