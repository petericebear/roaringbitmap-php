#!/usr/bin/env python3
"""Compare array round trips and expansion of a saved Base64 bitmap."""

import argparse
import csv
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

from run import ROOT, digest, stats

WORKLOADS = ['dense32', 'sparse32', 'dense64', 'sparse64']
LABELS = {'list': 'PHP array append', 'php': 'Package PHP', 'native': 'Native accelerator'}
CONFIGURATIONS = [('array', 'list'), ('array', 'php'), ('array', 'native'), ('base64', 'php'), ('base64', 'native')]


def microseconds(value):
    # A single inline append can fall below the host clock's resolution.
    return '<0.1' if value < 0.1 else f'{value:.3f}'


def aggregate(samples):
    records = []
    for sample in samples:
        for method in sorted({r['method'] for r in sample['samples']}):
            trials = [r for r in sample['samples'] if r['method'] == method]
            keys = [key for key in trials[0] if key.endswith('_ms') or key.endswith('_us')]
            records.append({**{k: sample[k] for k in ('pipeline', 'backend', 'workload', 'size')},
                            'method': method,
                            'metrics': {key: statistics.median(t[key] for t in trials) for key in keys}})
    rows = []
    fields = ('pipeline', 'backend', 'workload', 'size', 'method')
    for group_key in sorted({tuple(r[k] for k in fields) for r in records}):
        group = [r for r in records if tuple(r[k] for k in fields) == group_key]
        rows.append({**dict(zip(fields, group_key)), 'processes': len(group),
                     'metrics': {key: stats([r['metrics'][key] for r in group]) for key in group[0]['metrics']}})
    return rows


def render_tables(document, sizes):
    lookup = {(r['pipeline'], r['backend'], r['workload'], r['size'], r['method']): r['metrics'] for r in document['summary']}

    def value(pipeline, backend, workload, size, method, metric):
        return lookup[pipeline, backend, workload, size, method][metric]['median']

    lines = [
        'Array → bitmap → add one → array, compared with appending directly to an existing, unshared PHP list. '
        'Input creation is excluded. The new ID is absent and greater than all existing IDs.', '',
        '| Workload | IDs | Array append (µs) | PHP round trip (ms) | Native round trip (ms) |',
        '| --- | ---: | ---: | ---: | ---: |',
    ]
    for workload in document['workloads']:
        for size in sizes:
            direct = value('array', 'list', workload, size, 'append', 'total_ms') * 1000
            php = value('array', 'php', workload, size, 'bitmap_roundtrip', 'total_ms')
            native = value('array', 'native', workload, size, 'bitmap_roundtrip', 'total_ms')
            lines.append(f'| {workload} | {size:,} | {microseconds(direct)} | {php:.3f} | {native:.3f} |')
    lines += ['', 'External input is a **saved Base64 bitmap**, not JSON. Both approaches below decode the same saved value '
              'with the stated backend and return the same typed array. Complete times are milliseconds; storage/network I/O is excluded.', '',
              '| Workload | IDs | Backend | Decode → bitmap add → array (ms) | Decode → array → append (ms) |',
              '| --- | ---: | --- | ---: | ---: |']
    for workload in document['workloads']:
        for size in sizes:
            for backend in ('php', 'native'):
                first = value('base64', backend, workload, size, 'bitmap_first', 'total_ms')
                second = value('base64', backend, workload, size, 'array_first', 'total_ms')
                lines.append(f'| {workload} | {size:,} | {LABELS[backend]} | {first:.3f} | {second:.3f} |')
    lines += ['', '`Bitmap64::toArray()` returns decimal strings; the original PHP-list baseline retains integers. '
              'The full report separately measures conversion back to integers for fixtures within PHP_INT_MAX. '
              'Append permits duplicates; these fixtures add a known-new ID. Medians below 0.1 µs are shown as <0.1, not zero. '
              'Single append timings include the timer floor '
              'and are not throughput measurements. Saved-bitmap timings include public format validation and complete array materialization.']
    return '\n'.join(lines)


def render_report(document):
    lines = ['# Array round trips and saved Base64 bitmap expansion', '',
             f"Measured {document['started_at']} through {document['finished_at']} on {document['platform']}, "
             f"PHP {document['samples'][0]['runtime']['php']}. Each configuration uses {document['repetitions']} fresh processes "
             f"and {document['cycles']} trials per process. Tables show medians of each process's trial median.", '',
             render_tables(document, document['sizes']), '', '## Array round-trip phases and integer output', '',
             'The total includes constructing a bitmap from the existing input array, adding one ID, and calling toArray(). '
             'The optional integer-output total adds an explicitly measured array_map("intval", output) for Bitmap64. '
             'All fixture IDs fit PHP_INT_MAX; this conversion is not appropriate for larger unsigned values. '
             'Medians of individual phases need not add to the median total.', '',
             '| Workload | IDs | Backend | Import (ms) | Add (µs) | toArray (ms) | Optional integer conversion (ms) | Integer-output total (ms) |',
             '| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: |']
    for row in document['summary']:
        if row['pipeline'] != 'array':
            continue
        m = {k: v['median'] for k, v in row['metrics'].items()}
        lines.append(f"| {row['workload']} | {row['size']:,} | {LABELS[row['backend']]} | {m['import_ms']:.3f} | "
                     f"{microseconds(m['add_us'])} | {m['export_ms']:.3f} | {m['normalize_int_ms']:.3f} | {m['integer_output_total_ms']:.3f} |")
    lines += ['', '## Saved-bitmap phases', '',
              '| Workload | IDs | Backend | Add location | Base64 (ms) | Restore/validate (ms) | Add (µs) | toArray (ms) |',
              '| --- | ---: | --- | --- | ---: | ---: | ---: | ---: |']
    for row in document['summary']:
        if row['pipeline'] != 'base64':
            continue
        m = {k: v['median'] for k, v in row['metrics'].items()}
        where = 'bitmap' if row['method'] == 'bitmap_first' else 'array'
        lines.append(f"| {row['workload']} | {row['size']:,} | {LABELS[row['backend']]} | {where} | {m['base64_ms']:.3f} | "
                     f"{m['restore_ms']:.3f} | {microseconds(m['add_us'])} | {m['export_ms']:.3f} |")
    lines += ['', '## Method and limits', '',
              '- These are additional workflows for the membership use case. The earlier saved-update benchmark ended with another '
              'compressed snapshot. This benchmark ends with a fully expanded PHP array; the totals measure different work.',
              '- Dense IDs are `2*i+1`; sparse IDs are `2048*i + (crc32(pack("V", i)) & 2047)`. The 64-bit fixtures add `2^40`. '
              'Inputs are unique and sorted, with one upper-32-bit bucket in each 64-bit fixture. Decimal-string input parsing, '
              'randomized full-range uint64 values, duplicate new IDs, and unsorted inputs are not modeled.',
              '- The small dense32 case is exactly `[1,3,5]` becoming `[1,3,5,7]`. Every trial verifies every output ID, '
              'array key, and PHP value type outside its timer. Bitmap mutation and array-only mutation are checked separately.',
              '- A fresh, unshared input array is prepared outside each array-path timer. Direct append mutates that array in place. '
              'The bitmap path imports the same values and replaces the input variable with toArray(); releasing the old input array '
              'is included in that assignment. No retained reference introduces an artificial copy-on-write penalty. '
              'No result cache or previously constructed full-size bitmap is reused.',
              '- Canonical Base64 fixtures are created with Backend::Php outside the timer. Their hashes must match across all '
              'backend processes. Each saved-path trial starts from the original value. Both update orders include strict Base64 '
              'decoding, public fromPortableBytes() validation, and toArray(), including its snapshot copy. '
              'Update order alternates across trials. There is no re-encoding or write-back in this workflow.',
              '- Both saved paths return identical PHP types: ints for Bitmap and decimal strings for Bitmap64. In the existing-array '
              'comparison the direct list retains ints; the bitmap API returns its documented types. Optional integer conversion '
              'is timed separately before verification; integer-output totals are the sum of that phase and the primary trial. '
              'Raw string outputs remain available during conversion; disposal of the resulting arrays/bitmap is excluded.',
              '- Native mode is explicit, without Auto fallback. Tiny fixtures prime codecs, library loading, and autoload before '
              'measurements. PHP CLI enables FFI, disables OPcache/JIT, and uses a 1 GiB memory limit. '
              'The raw results record runtime settings, source/library fingerprints, timer-only samples, every trial, and ranges.',
              '- Workers run sequentially and configuration order rotates between repetitions. Timing includes hrtime overhead '
              'without subtraction. Very small append times are sensitive to timer resolution and caching; these sizes do not test '
              'an array-capacity growth boundary. Shared-machine load/thermal variation remains.',
              '- Storage/database reads, network transfer, search-engine execution, queueing, and publishing a new snapshot are '
              'excluded. No production database or external service is contacted. These are generated package-compatible fixtures, '
              'not a compatibility check of another producer’s bitmap format.', '', '## Reproduce', '', '```sh',
              'composer install', 'bash tools/build-native.sh',
              'python3 benchmarks/roundtrip.py --sizes ' + ' '.join(map(str, document['sizes'])) +
              f" --samples {document['repetitions']} --cycles {document['cycles']} --output .build/roundtrip-new",
              '```', '', 'Use `--php` and `--library` to select a PHP executable and native library.', '',
              '[Raw samples and provenance](results.json) · [CSV with ranges](summary.csv) · Exact measured sources: source/', '']
    return '\n'.join(lines)


def run_benchmark(destination, sizes, repetitions, php, library, cycles):
    if not library.is_file():
        raise RuntimeError('Build the native library first.')
    destination.mkdir(parents=True, exist_ok=False)
    source = destination / 'source'
    for directory in ('src', 'native'):
        shutil.copytree(ROOT / directory, source / directory)
    for name in ('composer.json', 'LICENSE', 'tools/build-native.sh', 'benchmarks/run.py',
                 'benchmarks/roundtrip.py', 'benchmarks/roundtrip-worker.php'):
        target = source / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, target)
    zone = ZoneInfo('Europe/Amsterdam')
    document = {
        'schema_version': 1, 'started_at': datetime.now(zone).isoformat(), 'platform': platform.platform(),
        'source_base_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
        'source_sha256': {str(p.relative_to(source)): digest(p) for p in sorted(source.rglob('*')) if p.is_file()},
        'native_library': str(library), 'native_library_sha256': digest(library),
        'sizes': sizes, 'workloads': WORKLOADS, 'repetitions': repetitions, 'cycles': cycles, 'samples': [],
    }
    env = dict(os.environ, ROARING_LIBRARY=str(library), ROARING_REQUIRE_NATIVE='1')
    total = len(WORKLOADS) * len(sizes) * repetitions * len(CONFIGURATIONS)
    fixture_hashes = {}
    for workload in WORKLOADS:
        for size in sizes:
            for repetition in range(repetitions):
                offset = repetition % len(CONFIGURATIONS)
                for pipeline, backend in CONFIGURATIONS[offset:] + CONFIGURATIONS[:offset]:
                    command = [php, '-d', 'ffi.enable=1', '-d', 'opcache.enable_cli=0', '-d', 'opcache.jit=disable',
                               '-d', 'memory_limit=1G', str(ROOT / 'benchmarks/roundtrip-worker.php'),
                               f'--pipeline={pipeline}', f'--backend={backend}', f'--workload={workload}',
                               f'--size={size}', f'--cycles={cycles}']
                    result = subprocess.run(command, cwd=ROOT, env=env, capture_output=True, text=True, timeout=240)
                    if result.returncode or result.stderr:
                        raise RuntimeError(f'Worker failed: {command}\n{result.stdout}\n{result.stderr}')
                    sample = json.loads(result.stdout)
                    if (not sample['verified'] or (sample['pipeline'], sample['backend'], sample['workload'], sample['size'])
                            != (pipeline, backend, workload, size)):
                        raise RuntimeError('Invalid or unverified worker result.')
                    if pipeline == 'base64':
                        key = workload, size
                        expected = fixture_hashes.setdefault(key, sample['fixture_base64_sha256'])
                        if sample['fixture_base64_sha256'] != expected:
                            raise RuntimeError('Backends received different Base64 fixtures.')
                    sample.update(repetition=repetition + 1, command=command)
                    document['samples'].append(sample)
                    (destination / 'partial.json').write_text(json.dumps(document, indent=2) + '\n')
                    print(f"[{len(document['samples'])}/{total}] {pipeline} {workload} N={size:,} {backend} "
                          f"first={sample['samples'][0]['total_ms']:.3f}ms", flush=True)
    document['finished_at'] = datetime.now(zone).isoformat()
    document['summary'] = aggregate(document['samples'])
    (destination / 'results.json').write_text(json.dumps(document, indent=2) + '\n')
    with (destination / 'summary.csv').open('w', newline='') as file:
        keys = ['pipeline', 'backend', 'workload', 'size', 'method']
        writer = csv.DictWriter(file, fieldnames=keys + ['metric', 'median', 'min', 'max', 'mean', 'stdev'])
        writer.writeheader()
        for row in document['summary']:
            for metric, values in row['metrics'].items():
                writer.writerow({k: row[k] for k in keys} | {'metric': metric} | values)
    (destination / 'report.md').write_text(render_report(document))
    (destination / 'partial.json').unlink()
    print(f'Report: {destination / "report.md"}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--library', type=Path)
    parser.add_argument('--sizes', type=int, nargs='+', default=[3, 100000, 1000000], choices=[3, 10000, 100000, 1000000])
    parser.add_argument('--samples', type=int, default=3)
    parser.add_argument('--cycles', type=int, default=3)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not args.php or not 3 <= args.samples <= 10 or not 3 <= args.cycles <= 10 or len(set(args.sizes)) != len(args.sizes):
        parser.error('Provide PHP, unique sizes, and 3..10 samples/cycles.')
    suffix = 'dylib' if platform.system() == 'Darwin' else 'so'
    library = (args.library or ROOT / f'.build/croaring/libroaring.{suffix}').resolve()
    run_benchmark(args.output.resolve(), args.sizes, args.samples, args.php, library, args.cycles)


if __name__ == '__main__':
    main()
