<?php

declare(strict_types=1);

namespace RoaringBitmap;

enum Backend: string
{
    case Auto = 'auto';
    case Php = 'php';
    case Native = 'native';
}
