<?php

namespace App\Util;

use Symfony\Component\HttpFoundation\HeaderUtils as SfHeaderUtils;

final class HeaderUtils
{
    /** Content-Disposition с UTF-8 именем (кириллица) и ASCII-фолбэком. */
    public static function disposition(string $filename, bool $inline = false): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $fallback = str_replace(['/', '\\', '%'], '_', $fallback);

        return SfHeaderUtils::makeDisposition($inline ? SfHeaderUtils::DISPOSITION_INLINE : SfHeaderUtils::DISPOSITION_ATTACHMENT, str_replace(['/', '\\'], '_', $filename), $fallback);
    }
}
