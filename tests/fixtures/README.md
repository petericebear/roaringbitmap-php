# Upstream interoperability fixtures

`bitmapwithruns.bin` and `bitmapwithoutruns.bin` are unchanged Java-generated
fixtures from [RoaringFormatSpec at commit e1b736158c29d077f37bd8a969bcc78995f59377](https://github.com/RoaringBitmap/RoaringFormatSpec/tree/e1b736158c29d077f37bd8a969bcc78995f59377/testdata).
They are distributed under Apache-2.0; see `LICENSE.upstream`.

The expected 200,100 values are generated independently in `tests/run.php`
from the construction loops documented by that upstream directory.

SHA-256:

```text
d719ae2e0150a362ef7cf51c361527585891f01460b1a92bcfb6a7257282a442  bitmapwithoutruns.bin
1f1909bfdd354fa2f0694fe88b8076833ca5383ad9fc3f68f2709c84a2ab70e3  bitmapwithruns.bin
```

`bitmap64.bin` and `portable_bitmap64.bin` are unchanged C++/C fixtures from
[the same pinned revision's testdata64 directory](https://github.com/RoaringBitmap/RoaringFormatSpec/tree/e1b736158c29d077f37bd8a969bcc78995f59377/testdata64).
They retain the same Apache-2.0 license. Tests reproduce the documented generators,
including the C fixture's inclusive range endpoints.

```text
a0f752256dbbc2ca67659c4bedb0ac5b67f18fbef76d65e0cc95bfa442eb0a6a  bitmap64.bin
b5a553a759167f5f9ccb3fa21552d943b4c73235635b753376f4faf62067d178  portable_bitmap64.bin
```

`uint64-vectors.json` is original test data under the package's MIT license.
It was generated with Python's arbitrary-precision integers: boundary values
at 16, 32, 53, 63, and 64 bits plus 100 outputs of
`random.Random(641632).getrandbits(64)`, deduplicated and sorted. Each integer's
decimal text and `value.to_bytes(8, 'little').hex()` are checked independently
against the PHP conversion code.
