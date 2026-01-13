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
}
