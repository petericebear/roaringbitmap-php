#!/usr/bin/env python3
"""Measure membership construction, one later event, and saved-snapshot updates."""

import argparse
import csv
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import sys
from datetime import datetime
from zoneinfo import ZoneInfo

from run import ROOT, digest, stats

LABELS = {"list": "PHP list (no dedup)", "array": "PHP array set", "php": "Package PHP", "native": "Native accelerator"}
WORKLOADS = ["dense32", "sparse32", "dense64", "sparse64"]


def aggregate(samples):
    rows = []
    for workload, size, backend in sorted({(s["workload"], s["size"], s["backend"]) for s in samples}):
        group = [s for s in samples if (s["workload"], s["size"], s["backend"]) == (workload, size, backend)]
        rows.append({"workload": workload, "size": size, "backend": backend, "samples": len(group),
                     "metrics": {key: stats([s["metrics"][key] for s in group]) for key in group[0]["metrics"]}})
    return rows


def render_tables(document, sizes, compact=False):
    lines = [
        "Membership updates: build one set, then add one new ID. In-memory additions are in **microseconds**; "
        "construction and restore → add → save are in **milliseconds**. The saved cycle includes Base64 and rewrites the entire snapshot; "
        "database/network time is excluded.", "",
        "| Workload | Existing IDs | Backend | Build (ms) | Add one in memory (µs) | Restore + add + save (ms) | Saved Base64 (KiB) |",
        "| --- | ---: | --- | ---: | ---: | ---: | ---: |",
    ]
    for workload in document["workloads"]:
        if compact and workload != "dense64":
            continue
        for size in sizes:
            for backend, label in LABELS.items():
                row = next(r for r in document["summary"] if (r["workload"], r["size"], r["backend"]) == (workload, size, backend))
                m = {k: v["median"] for k, v in row["metrics"].items()}
                lines.append(f"| {workload} | {size:,} | {label} | {m['build_ms']:.3f} | {m['add_us']:.3f} | "
                             f"{m['persist_1_total_ms']:.3f} | {m['saved_base64_bytes']/1024:.2f} |")
    lines += ["", "The list baseline uses `$ids[] = $id` and retains duplicates. The array-set baseline uses `$ids[$id] = true` "
              "and deduplicates like a bitmap. Arrays use PHP serialization; bitmaps use their portable format. "
              "These persistence formats are not interchangeable. `dense64` uses positive integer IDs above 2^40, "
              "with the same gaps as `dense32`; `sparse64` similarly offsets `sparse32`. "
              "Single-add timings include timer and benchmark dispatch overhead; see the raw timer floor and sample ranges."]
    return "\n".join(lines)


def render_report(document):
    lines = ["# Build membership, add an ID, and publish a saved snapshot", "",
             f"Measured {document['started_at']} through {document['finished_at']} on {document['platform']}, "
             f"{document['architecture']}, PHP {document['samples'][0]['runtime']['php']}. "
             f"Each configuration has {document['repetitions']} fresh-process samples; workers run sequentially and backend order rotates.", "",
             "This models collecting selected document IDs and processing a later membership event. "
             "For the small dense32 fixture the transition is exactly `{1, 3, 5}` → `{1, 3, 5, 7}`. "
             "The package implementation is unchanged; this report measures a different workload from set intersections.", "",
             render_tables(document, document["sizes"]), "", "## Duplicate events, insertion position, and batching", "",
             "A duplicate does not grow any of the sets. List duplicates still append and are deliberately shown as n/a. "
             "A gap insertion adds a missing ID among existing members. The first addition includes any lazy cardinality work; "
             "the repeated probes operate on a warmed, unshared set at the original cardinality. "
             "Batching loads once, adds 100 new IDs individually, and saves once.", "",
             "| Workload | IDs | Backend | First add (µs) | Gap insert (µs) | Duplicate (µs) | Save batch of 100 (ms) | Cycle per new ID (µs) |",
             "| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: |"]
    for row in document["summary"]:
        m = {k: v["median"] for k, v in row["metrics"].items()}
        duplicate = "n/a" if row['backend'] == 'list' else f"{m['duplicate_us']:.3f}"
        lines.append(f"| {row['workload']} | {row['size']:,} | {LABELS[row['backend']]} | {m['first_add_us']:.3f} | "
                     f"{m['insert_gap_us']:.3f} | {duplicate} | {m['persist_100_total_ms']:.3f} | {m['persist_100_total_ms']*10:.3f} |")
    lines += ["", "## Where a saved update spends its time", "",
              "Per-phase medians need not add to the median complete cycle. Save includes serializing the updated set and Base64 encoding it. "
              "Payload size refers to the original saved set. RSS is whole-process peak immediately after construction, before later probes.", "",
              "| Workload | IDs | Backend | Restore (ms) | Add (µs) | Save (ms) | Build peak RSS (MiB) |",
              "| --- | ---: | --- | ---: | ---: | ---: | ---: |"]
    for row in document["summary"]:
        m = {k: v["median"] for k, v in row["metrics"].items()}
        lines.append(f"| {row['workload']} | {row['size']:,} | {LABELS[row['backend']]} | {m['persist_1_load_ms']:.3f} | "
                     f"{m['persist_1_add_ms']*1000:.3f} | {m['persist_1_save_ms']:.3f} | {m['build_peak_rss_bytes']/2**20:.2f} |")
    lines += ["", "## Method and limits", "",
              "- Construction streams one set of N unique, ascending IDs: `2*i+1` for dense inputs or "
              "`2048*i + (crc32(pack('V', i)) & 2047)` for sparse inputs. The 64-bit cases add `2^40`. "
              "All IDs fit positive PHP integers; these cases do not model decimal-string parsing or IDs scattered throughout uint64. "
              "Each 64-bit fixture lies within one upper-32-bit bucket.",
              "- Bitmap construction uses the public bulk constructor, array/list construction uses a foreach loop. "
              "ID generation and package validation are included. No range shortcut or prebuilt large input array is used. "
              "Autoload, FFI loading, and tiny-fixture codec initialization are primed outside construction.",
              "- The first new ID is inserted before calling count(), iteration, serialization, or any other operation on the full set. "
              "It is one observation per process, so it is noisier than warmed timings.",
              f"- Each resident metric is the median of {document['probes']} individually timed mutations, then the median across processes. "
              "New IDs above the current maximum and free IDs within existing containers are tested. After each changed membership, "
              "removal restores cardinality outside the timer. No clone or shared PHP array is retained. Reusing allocated capacity "
              "means these warm probes do not characterize allocation spikes, copy-on-write snapshots, or full-container promotion.",
              "- Both hrtime() overhead and a shared benchmark dispatch function are included and not subtracted. "
              "The back-to-back hrtime() baseline is recorded. Sub-microsecond measurements have limited resolution; "
              "they are latency probes, not request-throughput measurements or a guarantee of an individual event's latency.",
              f"- Persistence metrics are medians of {document['cycles']} independent restores of the same saved snapshot per process. "
              "No resident source set is kept. The complete cycle includes strict Base64 decoding, public deserialization/validation, "
              "one or 100 scalar additions, serialization, and Base64 encoding. It excludes result disposal, correctness checks, "
              "storage I/O, queueing, rule matching, network transfer, and concurrency coordination. No chunking is assumed.",
              "- PHP arrays/lists use serialize()/unserialize(allowed_classes=false); bitmaps use toPortableBytes()/fromPortableBytes(). "
              "Native decoding includes PHP format validation. Native mode is explicit and cannot fall back to PHP.",
              "- Every worker checks duplicate semantics, every mutation's cardinality, exact initial/retained membership, "
              "all newly added IDs, and published round trips. Every ID in the first one-ID and 100-ID published snapshot is checked. "
              "All correctness checks run outside timings. Raw resident probes and persistence phases are retained.",
              "- Process RSS includes the interpreter and native allocations. PHP heap deltas exclude native allocation and are not "
              "a comparable bitmap memory metric. Encoded bytes measure storage size, not RAM.",
              "- PHP CLI uses FFI enabled, OPcache/JIT disabled, and a 1 GiB limit. Runtime settings, source snapshots/hashes, library hash, "
              "commands, medians, min/max, and standard deviations are in results.json/summary.csv. Shared-machine thermal/load variation remains.",
              "- This is a package CPU/storage benchmark. It does not test a search-engine plugin, its wire format, or its performance; "
              "the saved bitmap format accepted by a consumer must be verified separately.", "", "## Reproduce", "", "```sh",
              "composer install", "bash tools/build-native.sh",
              "python3 benchmarks/membership.py --sizes " + " ".join(map(str, document['sizes'])) +
              f" --samples {document['repetitions']} --probes {document['probes']} --cycles {document['cycles']} --output .build/membership-new",
              "```", "", "Use `--php /absolute/path/to/herd/php84` or `--library /absolute/path/to/libroaring.dylib` to override discovery.",
              "", "[Raw samples and provenance](results.json) · [Summary with ranges](summary.csv) · Exact measured sources: source/", ""]
    return "\n".join(lines)


def run_benchmark(destination, sizes, repetitions, php, library, probes=256, cycles=3):
    if not library.is_file():
        raise RuntimeError("Build the native library with bash tools/build-native.sh first.")
    destination.mkdir(parents=True, exist_ok=False)
    source = destination / "source"
    for directory in ("src", "native"):
        shutil.copytree(ROOT / directory, source / directory)
    for name in ("composer.json", "LICENSE", "tools/build-native.sh", "benchmarks/run.py",
                 "benchmarks/membership.py", "benchmarks/membership-worker.php"):
        target = source / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, target)
    timezone = ZoneInfo("Europe/Amsterdam")
    document = {
        "schema_version": 1, "started_at": datetime.now(timezone).isoformat(),
        "platform": platform.platform(), "architecture": platform.machine(),
        "source_base_commit": subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
        "source_sha256": {str(p.relative_to(source)): digest(p) for p in sorted(source.rglob('*')) if p.is_file()},
        "native_library": str(library), "native_library_sha256": digest(library),
        "sizes": sizes, "workloads": WORKLOADS, "repetitions": repetitions, "probes": probes, "cycles": cycles,
        "samples": [],
    }
    env = dict(os.environ, ROARING_LIBRARY=str(library), ROARING_REQUIRE_NATIVE="1")
    backends = list(LABELS)
    total = len(WORKLOADS) * len(sizes) * repetitions * len(backends)
    for workload in WORKLOADS:
        for size in sizes:
            for repetition in range(repetitions):
                order = backends[repetition % 4:] + backends[:repetition % 4]
                for backend in order:
                    command = [php, '-d', 'ffi.enable=1', '-d', 'opcache.enable_cli=0', '-d', 'opcache.jit=disable',
                               '-d', 'memory_limit=1G', str(ROOT / 'benchmarks/membership-worker.php'),
                               f'--backend={backend}', f'--workload={workload}', f'--size={size}',
                               f'--probes={probes}', f'--cycles={cycles}']
                    result = subprocess.run(command, cwd=ROOT, env=env, capture_output=True, text=True, timeout=240)
                    if result.returncode or result.stderr:
                        raise RuntimeError(f"Worker failed: {command}\n{result.stdout}\n{result.stderr}")
                    sample = json.loads(result.stdout)
                    if not sample['verified'] or (sample['backend'], sample['workload'], sample['size']) != (backend, workload, size):
                        raise RuntimeError('Invalid or unverified worker result.')
                    sample.update(repetition=repetition + 1, command=command)
                    document['samples'].append(sample)
                    (destination / 'partial.json').write_text(json.dumps(document, indent=2) + '\n')
                    m = sample['metrics']
                    print(f"[{len(document['samples'])}/{total}] {workload} N={size:,} {backend} "
                          f"build={m['build_ms']:.2f}ms add={m['add_us']:.3f}us snapshot={m['persist_1_total_ms']:.2f}ms", flush=True)
    document['finished_at'] = datetime.now(timezone).isoformat()
    document['summary'] = aggregate(document['samples'])
    (destination / 'results.json').write_text(json.dumps(document, indent=2) + '\n')
    with (destination / 'summary.csv').open('w', newline='') as file:
        writer = csv.DictWriter(file, fieldnames=['workload', 'size', 'backend', 'metric', 'median', 'min', 'max', 'mean', 'stdev'])
        writer.writeheader()
        for row in document['summary']:
            for metric, values in row['metrics'].items():
                writer.writerow({k: row[k] for k in ('workload', 'size', 'backend')} | {'metric': metric} | values)
    (destination / 'report.md').write_text(render_report(document))
    (destination / 'partial.json').unlink()
    return document


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--library', type=Path)
    parser.add_argument('--sizes', type=int, nargs='+', default=[3, 100000, 1000000], choices=[3, 10000, 100000, 1000000])
    parser.add_argument('--samples', type=int, default=3)
    parser.add_argument('--probes', type=int, default=256)
    parser.add_argument('--cycles', type=int, default=3)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if (not args.php or not 3 <= args.samples <= 10 or not 16 <= args.probes <= 4096
            or not 3 <= args.cycles <= 10 or len(set(args.sizes)) != len(args.sizes)):
        parser.error('Provide a PHP binary, unique sizes, 3..10 samples/cycles, and 16..4096 probes.')
    suffix = 'dylib' if platform.system() == 'Darwin' else 'so'
    library = (args.library or ROOT / f'.build/croaring/libroaring.{suffix}').resolve()
    run_benchmark(args.output.resolve(), args.sizes, args.samples, args.php, library, args.probes, args.cycles)
    print(f'Report: {args.output / "report.md"}')


if __name__ == '__main__':
    main()
