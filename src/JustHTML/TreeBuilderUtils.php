<?php

declare(strict_types=1);

namespace JustHTML;

final class InsertionMode
{
    public const INITIAL = 0;
    public const BEFORE_HTML = 1;
    public const BEFORE_HEAD = 2;
    public const IN_HEAD = 3;
    public const IN_HEAD_NOSCRIPT = 4;
    public const AFTER_HEAD = 5;
    public const TEXT = 6;
    public const IN_BODY = 7;
    public const AFTER_BODY = 8;
    public const AFTER_AFTER_BODY = 9;
    public const IN_TABLE = 10;
    public const IN_TABLE_TEXT = 11;
    public const IN_CAPTION = 12;
    public const IN_COLUMN_GROUP = 13;
    public const IN_TABLE_BODY = 14;
    public const IN_ROW = 15;
    public const IN_CELL = 16;
    public const IN_FRAMESET = 17;
    public const AFTER_FRAMESET = 18;
    public const AFTER_AFTER_FRAMESET = 19;
    public const IN_SELECT = 20;
    public const IN_TEMPLATE = 21;
}

final class TreeBuilderUtils
{
    public static function isAllWhitespace(string $text): bool
    {
        if ($text === '') {
            return true;
        }
        return strspn($text, "\t\n\f\r ") === strlen($text);
    }

    /** @param array<int, string> $haystack */
    public static function containsPrefix(array $haystack, string $needle): bool
    {
        foreach ($haystack as $prefix) {
            if (strncmp($needle, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0: bool, 1: string} */
    public static function doctypeErrorAndQuirks(Doctype $doctype, bool $iframeSrcdoc = false): array
    {
        $name = $doctype->name !== null ? strtolower($doctype->name) : null;
        $publicId = $doctype->publicId;
        $systemId = $doctype->systemId;

        $acceptable = [
            ["html", null, null],
            ["html", null, "about:legacy-compat"],
            ["html", "-//W3C//DTD HTML 4.0//EN", null],
            ["html", "-//W3C//DTD HTML 4.0//EN", "http://www.w3.org/TR/REC-html40/strict.dtd"],
            ["html", "-//W3C//DTD HTML 4.01//EN", null],
            ["html", "-//W3C//DTD HTML 4.01//EN", "http://www.w3.org/TR/html4/strict.dtd"],
            ["html", "-//W3C//DTD XHTML 1.0 Strict//EN", "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"],
            ["html", "-//W3C//DTD XHTML 1.1//EN", "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd"],
        ];

        $parseError = true;
        foreach ($acceptable as $entry) {
            if ($entry[0] === $name && $entry[1] === $publicId && $entry[2] === $systemId) {
                $parseError = false;
                break;
            }
        }

        $publicLower = $publicId !== null ? strtolower($publicId) : null;
        $systemLower = $systemId !== null ? strtolower($systemId) : null;

        if ($doctype->forceQuirks) {
            $quirksMode = "quirks";
        } elseif ($iframeSrcdoc) {
            $quirksMode = "no-quirks";
        } elseif ($name !== "html") {
            $quirksMode = "quirks";
        } elseif ($publicLower !== null && in_array($publicLower, Constants::QUIRKY_PUBLIC_MATCHES, true)) {
            $quirksMode = "quirks";
        } elseif ($systemLower !== null && in_array($systemLower, Constants::QUIRKY_SYSTEM_MATCHES, true)) {
            $quirksMode = "quirks";
        } elseif ($publicLower !== null && self::containsPrefix(Constants::QUIRKY_PUBLIC_PREFIXES, $publicLower)) {
            $quirksMode = "quirks";
        } elseif ($publicLower !== null && self::containsPrefix(Constants::LIMITED_QUIRKY_PUBLIC_PREFIXES, $publicLower)) {
            $quirksMode = "limited-quirks";
        } elseif ($publicLower !== null && self::containsPrefix(Constants::HTML4_PUBLIC_PREFIXES, $publicLower)) {
            $quirksMode = $systemLower === null ? "quirks" : "limited-quirks";
        } else {
            $quirksMode = "no-quirks";
        }

        return [$parseError, $quirksMode];
    }
}
