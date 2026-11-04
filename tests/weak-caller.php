<?php

// Deliberately no strict_types: large float IDs must be rejected even in weak callers.
function weakAdd64(\RoaringBitmap\Bitmap64 $bitmap, mixed $value): bool
{
    return $bitmap->add($value);
}
