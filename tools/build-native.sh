#!/usr/bin/env bash
set -eu

# A local development build only: no installation or php.ini changes.
root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
build="$root/.build/croaring"
version="5.4.0"
stock=0
if [[ "${1:-}" == "--stock" && "$#" == 1 ]]; then
    stock=1
elif [[ "$#" != 0 ]]; then
    echo "Usage: bash tools/build-native.sh [--stock]" >&2
    exit 1
fi
mkdir -p "$build"
for file in roaring.c roaring.h; do
    case "$file" in
        roaring.c) hash=cdbd007b635b9bb2dc2074c69cb9f768e8aaf2dbe68f622c8643689b4e975dbc ;;
        roaring.h) hash=b74054da2965baa17393c5ffa02bea587374fe33afcfff2c2d24dcf75fed419b ;;
    esac
    if ! php -r 'exit(is_file($argv[1]) && hash_file("sha256", $argv[1]) === $argv[2] ? 0 : 1);' "$build/$file" "$hash"; then
        curl -fsSL --max-time 120 "https://github.com/RoaringBitmap/CRoaring/releases/download/v$version/$file" -o "$build/$file"
    fi
done
php -r '
    $expected = [
        "roaring.c" => "cdbd007b635b9bb2dc2074c69cb9f768e8aaf2dbe68f622c8643689b4e975dbc",
        "roaring.h" => "b74054da2965baa17393c5ffa02bea587374fe33afcfff2c2d24dcf75fed419b",
    ];
    foreach ($expected as $file => $hash) {
        if (hash_file("sha256", $argv[1] . "/" . $file) !== $hash) {
            fwrite(STDERR, "Checksum mismatch: " . $file . PHP_EOL);
            exit(1);
        }
    }
' "$build"
if [[ ! -f "$build/LICENSE" ]]; then
    curl -fsSL --max-time 30 "https://raw.githubusercontent.com/RoaringBitmap/CRoaring/v$version/LICENSE" -o "$build/LICENSE"
fi
case "$(uname -s)" in
    Darwin) suffix=dylib; shared=-dynamiclib ;;
    Linux) suffix=so; shared=-shared ;;
    *) echo "This helper supports Linux and macOS. Build CRoaring separately on Windows." >&2; exit 1 ;;
esac
source="$root/native/roaring-index.c"
output="$build/libroaring.$suffix"
if [[ "$stock" == 1 ]]; then
    source="$build/roaring.c"
    output="$build/libroaring-stock.$suffix"
fi
"${CC:-cc}" -O3 -std=c11 "$shared" -fPIC -I"$build" "$source" -o "$output"
printf 'Built %s\n' "$output"
