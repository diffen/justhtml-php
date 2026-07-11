<?php

declare(strict_types=1);

namespace JustHTML;

final class Str
{
    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }

    public static function startsWith(string $haystack, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        return strncmp($haystack, $prefix, strlen($prefix)) === 0;
    }

    public static function endsWith(string $haystack, string $suffix): bool
    {
        $len = strlen($suffix);
        if ($len === 0) {
            return true;
        }
        if ($len > strlen($haystack)) {
            return false;
        }
        return substr($haystack, -$len) === $suffix;
    }

    /**
     * Encode a Unicode scalar value as UTF-8. The caller must have already
     * validated the codepoint: 0..0x10FFFF and not a surrogate.
     */
    public static function codepointToUtf8(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return chr(0xC0 | ($codepoint >> 6))
                . chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }
        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }
}
