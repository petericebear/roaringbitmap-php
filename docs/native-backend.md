# Native backend setup

[Back to the package overview](../README.md)

The PHP backend works with Composer alone. Native acceleration adds CRoaring through PHP FFI while keeping the same public API. The supplied native build also accelerates repeated sparse 64-bit rank/select queries.

## Requirements and build

Enable `ext-ffi` and provide a CRoaring shared library. This package is tested with **CRoaring 5.4.0**. Other versions must expose the public CRoaring symbols declared for the selected bitmap width in `src/Internal/NativeLibrary.php`; they are not part of the tested compatibility claim. Package-specific index symbols are optional: stock CRoaring remains compatible.

The build helper downloads a pinned release, verifies SHA-256 hashes for its sources, and compiles it together with the package's C index into a local shared library. Verified cached sources are reused. It requires `curl`, PHP, and a C11 compiler. It supports Linux and macOS and does not install anything globally or modify `php.ini`. The addon uses CRoaring internals within the same compiled source unit and is tied to that pinned release. `bash tools/build-native.sh --stock` builds an additional unmodified `libroaring-stock` library for comparisons.

Run the commands below from the library directory: `vendor/peter/roaring-bitmap` in a Composer application, or the repository root in a source checkout. PHP examples use `RoaringBitmap\Bitmap` and `RoaringBitmap\Backend`.

```sh
bash tools/build-native.sh

# macOS (on Linux, use libroaring.so):
export ROARING_LIBRARY="$PWD/.build/croaring/libroaring.dylib"
```

For deployment, copy the resulting shared library to a stable location in your application image or server. A Composer update can replace files under `vendor/`.

## Application configuration

In an application, either set `ROARING_LIBRARY` to an absolute shared-library path or pass `libraryPath:`:

```php
use RoaringBitmap\Backend;
use RoaringBitmap\Bitmap;

$bitmap = new Bitmap(
    backend: Backend::Native,
    libraryPath: '/path/to/libroaring.so',
);
```

`Backend::Native` throws `NativeBackendUnavailable` when FFI is disabled, the library is missing, or required symbols cannot load. `Backend::Auto` falls back only for those availability failures; input, allocation, and operation errors are not hidden. Successful and failed library probes are cached for the current PHP process; restart workers after installing or replacing a library.

Without an explicit path, the loader checks `ROARING_LIBRARY`, then platform library names (and common Homebrew paths on macOS). Windows can supply a separately built compatible DLL; the included build helper and native CI cover Linux and macOS.

## PHP-FPM and web deployments

For CLI scripts, use `php -d ffi.enable=1 your-script.php`. For PHP-FPM/web deployments, configure FFI for that SAPI; the CLI option only affects that CLI process. This version uses `FFI::cdef()` and requires permission to call it; `ffi.enable=preload` alone does not enable this loader in web requests. A preloaded `FFI::scope()` loader is not implemented yet.

## Native order index

See [index design and ownership](../native/README.md) for the activation threshold, cache lifetime, memory costs, and stock CRoaring compatibility. The [benchmark guide](../benchmarks/README.md) explains how to compare the indexed and stock builds.
