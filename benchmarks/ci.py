#!/usr/bin/env python3
"""Produce one complete, reproducible CI benchmark bundle."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import sys
from datetime import datetime
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
BACKEND_LABELS = {"array": "PHP array", "php": "Package PHP", "native": "Native accelerator"}


def load(path):
    return json.loads(path.read_text())


def render_tables(summary, sizes):
    sets = {(r["size"], r["workload"], r["backend"]): r["metrics"] for r in summary["sets"]}
    lines = [
        "Median CLI timings; build creates both input sets. RSS is whole-process peak after construction.",
        "",
        "| IDs/input | Workload | Backend | Build (ms) | Intersection (ms) | Peak RSS (MiB) |",
        "| ---: | --- | --- | ---: | ---: | ---: |",
    ]
    for size in sizes:
        for workload in summary["workloads"]:
            for backend, label in BACKEND_LABELS.items():
                m = sets[size, workload, backend]
                lines.append(f"| {size:,} | {workload} | {label} | {m['build_pair_ms']['median']:.2f} | "
                             f"{m['intersection_ms']['median']:.4f} | {m['build_peak_rss_bytes']['median'] / 2**20:.2f} |")
    lines += [
        "", "Sparse unsigned 64-bit order queries: milliseconds per 1,000 warm queries. "
        "The accelerator column includes the package's C index; stock CRoaring uses direct scans.", "",
        "| IDs | Query | Package PHP | Stock CRoaring | Native accelerator | Stock / accelerator |",
        "| ---: | --- | ---: | ---: | ---: | ---: |",
    ]
    for size in sizes:
        indexed = {(r["workload"], r["backend"]): r["metrics"] for r in summary["order"][str(size)]["indexed"]}
        stock = {(r["workload"], r["backend"]): r["metrics"] for r in summary["order"][str(size)]["stock"]}
        for label, metric in [("rank", "warm_rank_batch_ms"), ("select", "warm_select_batch_ms")]:
            php = indexed["64-sparse", "php"][metric]["median"]
            native = indexed["64-sparse", "native"][metric]["median"]
            direct = stock["64-sparse", "native"][metric]["median"]
            lines.append(f"| {size:,} | {label} | {php:.4f} | {direct:.4f} | {native:.4f} | {direct/native:.2f}× |")
    return "\n".join(lines)


def run_bundle(destination, sizes, repetitions, workloads, php):
    suffix = "dylib" if platform.system() == "Darwin" else "so"
    indexed_library = ROOT / f".build/croaring/libroaring.{suffix}"
    stock_library = ROOT / f".build/croaring/libroaring-stock.{suffix}"
    if not indexed_library.is_file() or not stock_library.is_file():
        raise RuntimeError("Build both libraries with tools/build-native.sh and its --stock option.")
    destination.mkdir(parents=True, exist_ok=False)
    env = dict(os.environ, ROARING_LIBRARY=str(indexed_library), ROARING_REQUIRE_NATIVE="1")

    def run(command):
        subprocess.run(command, cwd=ROOT, env=env, check=True)

    run([sys.executable, str(ROOT / "benchmarks/run.py"), "--php", php,
         "--sizes", *map(str, sizes), "--workloads", *workloads, "--repetitions", str(repetitions),
         "--library", str(indexed_library), "--output", str(destination / "sets")])
    sets = load(destination / "sets/results.json")
    orders = {}
    flags = ["-d", "ffi.enable=1", "-d", "opcache.enable_cli=0", "-d", "opcache.jit=disable", "-d", "memory_limit=1G"]
    for size in sizes:
        orders[str(size)] = {}
        # Both native modes use the same public API and deterministic inputs.
        for mode, library, backends in [("indexed", indexed_library, "php,native"), ("stock", stock_library, "native")]:
            target = destination / f"order-{mode}-{size}"
            run([php, *flags, str(ROOT / "benchmarks/order.php"), f"--size={size}", "--queries=1000",
                 f"--samples={repetitions}", f"--library={library}", f"--backends={backends}", f"--output={target}"])
            result = load(target / "results.json")
            if result["native_order_index"] != (mode == "indexed") or result["backends"] != backends.split(","):
                raise RuntimeError(f"Wrong native capabilities/backends in {mode} measurement.")
            orders[str(size)][mode] = result["summary"]

    repository = os.environ.get("GITHUB_REPOSITORY", "petericebear/roaringbitmap-php")
    sha = os.environ.get("GITHUB_SHA") or subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    run_id = os.environ.get("GITHUB_RUN_ID")
    attempt = os.environ.get("GITHUB_RUN_ATTEMPT", "1")
    server = os.environ.get("GITHUB_SERVER_URL", "https://github.com")
    summary = {
        "schema_version": 1,
        "repository": repository, "commit": sha, "run_id": run_id, "run_attempt": attempt,
        "run_url": f"{server}/{repository}/actions/runs/{run_id}" if run_id else None,
        "artifact_name": f"benchmark-{run_id}-{attempt}" if run_id else None,
        "measured_at": datetime.now(ZoneInfo("Europe/Amsterdam")).isoformat(),
        "php": sets["samples"][0]["runtime"]["php"],
        "environment": sets["environment"],
        "runner": {key: os.environ.get(key) for key in ("RUNNER_OS", "RUNNER_ARCH", "ImageOS", "ImageVersion")},
        "sizes": sizes, "repetitions": repetitions, "workloads": workloads,
        "sets": sets["summary"], "order": orders,
        "set_samples": len(sets["samples"]),
        "order_samples": len(sizes) * 4 * 3 * repetitions,
        "source_sha256": {},
    }
    # Include exact sources so an artifact remains useful after later package changes.
    source_dir = destination / "source"
    for directory in ("src", "native"):
        shutil.copytree(ROOT / directory, source_dir / directory)
    for name in ("composer.json", "LICENSE", "tools/build-native.sh", "benchmarks/ci.py",
                 "benchmarks/run.py", "benchmarks/worker.php", "benchmarks/order.php"):
        target = source_dir / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, target)
    for path in sorted(source_dir.rglob("*")):
        if path.is_file():
            summary["source_sha256"][str(path.relative_to(source_dir))] = hashlib.sha256(path.read_bytes()).hexdigest()
    (destination / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")

    intro = (f"Measured {summary['measured_at']} with PHP {summary['php']} on "
             f"{summary['environment']['platform']}; {repetitions} samples per configuration. "
             f"Source: [{sha[:7]}]({server}/{repository}/commit/{sha}).")
    notes = ("These are synthetic CLI workloads on shared hardware. Set-algebra samples use fresh processes; "
             "order samples use fresh bitmaps in one process per mode/size. Input generation is included in set construction "
             "and excluded in order construction. Native mode is required; exact-result checks run outside the timers. "
             "Cold queries, mutation costs, other operations, settings, hashes, and sample ranges are retained in the full reports. "
             "Compare backends within the same run; CI host variation can affect comparisons between runs.")
    readme = intro + "\n\n" + render_tables(summary, [max(sizes)]) + "\n\n" + notes + "\n"
    (destination / "readme.md").write_text(readme)
    report = ["# CI package and native accelerator benchmark", "", intro, "", render_tables(summary, sizes), "", notes, "",
              "## Full reports and data", "", "- [Set algebra, construction, memory, and persistence](sets/report.md)",
              "- [Set samples](sets/results.json) and [CSV](sets/summary.csv)",
              "- [Combined summary and provenance](summary.json)", "- Exact implementation and harnesses: source/", ""]
    for size in sizes:
        for mode in ("indexed", "stock"):
            report.append(f"- [{mode} order queries, {size:,} IDs](order-{mode}-{size}/report.md) "
                          f"([raw samples](order-{mode}-{size}/results.json))")
    report += ["", "## Reproduce", "", "Build both native libraries, then run:", "", chr(96) * 3 + "sh",
               "composer install", "bash tools/build-native.sh", "bash tools/build-native.sh --stock",
               "python3 benchmarks/ci.py --sizes " + " ".join(map(str, sizes)) +
               " --workloads " + " ".join(workloads) + f" --samples {repetitions} --output .build/ci-benchmark-new",
               chr(96) * 3, ""]
    (destination / "report.md").write_text("\n".join(report))
    print(f"Complete CI benchmark bundle: {destination}", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--php", default=shutil.which("php"))
    parser.add_argument("--sizes", type=int, nargs="+", default=[100000, 1000000], choices=[10000, 100000, 1000000])
    parser.add_argument("--samples", type=int, default=3)
    parser.add_argument("--workloads", nargs="+", default=["dense", "sparse_mixed", "ranges"], choices=["dense", "sparse_mixed", "ranges"])
    args = parser.parse_args()
    if not args.php or not 3 <= args.samples <= 10 or len(set(args.sizes)) != len(args.sizes):
        parser.error("Use a PHP binary, unique sizes, and 3..10 samples.")
    run_bundle(args.output.resolve(), args.sizes, args.samples, args.workloads, args.php)


if __name__ == "__main__":
    main()
