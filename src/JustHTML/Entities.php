<?php

declare(strict_types=1);

namespace JustHTML;

final class Entities
{
    private const REPLACEMENT_CHAR = "\u{FFFD}";

    /** @var array<string, string>|null */
    private static ?array $namedEntities = null;

    /**
     * Fast-path lookup for most common entities.
     * These are checked first before loading the full entity map.
     * @var array<string, string>
     */
    private static array $commonEntities = [
        'amp' => '&',
        'lt' => '<',
        'gt' => '>',
        'quot' => '"',
        'apos' => "'",
        'nbsp' => "\u{00A0}",
        'AMP' => '&',
        'LT' => '<',
        'GT' => '>',
        'QUOT' => '"',
    ];

    /** @var array<string, bool> */
    private static array $legacyEntities = [
        "gt" => true,
        "lt" => true,
        "amp" => true,
        "quot" => true,
        "nbsp" => true,
        "AMP" => true,
        "QUOT" => true,
        "GT" => true,
        "LT" => true,
        "COPY" => true,
        "REG" => true,
        "AElig" => true,
        "Aacute" => true,
        "Acirc" => true,
        "Agrave" => true,
        "Aring" => true,
        "Atilde" => true,
        "Auml" => true,
        "Ccedil" => true,
        "ETH" => true,
        "Eacute" => true,
        "Ecirc" => true,
        "Egrave" => true,
        "Euml" => true,
        "Iacute" => true,
        "Icirc" => true,
        "Igrave" => true,
        "Iuml" => true,
        "Ntilde" => true,
        "Oacute" => true,
        "Ocirc" => true,
        "Ograve" => true,
        "Oslash" => true,
        "Otilde" => true,
        "Ouml" => true,
        "THORN" => true,
        "Uacute" => true,
        "Ucirc" => true,
        "Ugrave" => true,
        "Uuml" => true,
        "Yacute" => true,
        "aacute" => true,
        "acirc" => true,
        "acute" => true,
        "aelig" => true,
        "agrave" => true,
        "aring" => true,
        "atilde" => true,
        "auml" => true,
        "brvbar" => true,
        "ccedil" => true,
        "cedil" => true,
        "cent" => true,
        "copy" => true,
        "curren" => true,
        "deg" => true,
        "divide" => true,
        "eacute" => true,
        "ecirc" => true,
        "egrave" => true,
        "eth" => true,
        "euml" => true,
        "frac12" => true,
        "frac14" => true,
        "frac34" => true,
        "iacute" => true,
        "icirc" => true,
        "iexcl" => true,
        "igrave" => true,
        "iquest" => true,
        "iuml" => true,
        "laquo" => true,
        "macr" => true,
        "micro" => true,
        "middot" => true,
        "not" => true,
        "ntilde" => true,
        "oacute" => true,
        "ocirc" => true,
        "ograve" => true,
        "ordf" => true,
        "ordm" => true,
        "oslash" => true,
        "otilde" => true,
        "ouml" => true,
        "para" => true,
        "plusmn" => true,
        "pound" => true,
        "raquo" => true,
        "reg" => true,
        "sect" => true,
        "shy" => true,
        "sup1" => true,
        "sup2" => true,
        "sup3" => true,
        "szlig" => true,
        "thorn" => true,
        "times" => true,
        "uacute" => true,
        "ucirc" => true,
        "ugrave" => true,
        "uml" => true,
        "uuml" => true,
        "yacute" => true,
        "yen" => true,
        "yuml" => true,
    ];

    /** @var array<int, string> */
    private static array $numericReplacements = [
        0x00 => "\u{FFFD}",
        0x80 => "\u{20AC}",
        0x82 => "\u{201A}",
        0x83 => "\u{0192}",
        0x84 => "\u{201E}",
        0x85 => "\u{2026}",
        0x86 => "\u{2020}",
        0x87 => "\u{2021}",
        0x88 => "\u{02C6}",
        0x89 => "\u{2030}",
        0x8A => "\u{0160}",
        0x8B => "\u{2039}",
        0x8C => "\u{0152}",
        0x8E => "\u{017D}",
        0x91 => "\u{2018}",
        0x92 => "\u{2019}",
        0x93 => "\u{201C}",
        0x94 => "\u{201D}",
        0x95 => "\u{2022}",
        0x96 => "\u{2013}",
        0x97 => "\u{2014}",
        0x98 => "\u{02DC}",
        0x99 => "\u{2122}",
        0x9A => "\u{0161}",
        0x9B => "\u{203A}",
        0x9C => "\u{0153}",
        0x9E => "\u{017E}",
        0x9F => "\u{0178}",
    ];

    private static function loadNamedEntities(): array
    {
        if (self::$namedEntities !== null) {
            return self::$namedEntities;
        }

        // Use pre-compiled PHP array for faster loading (no JSON parsing)
        $path = dirname(__DIR__, 2) . '/data/entities.php';
        if (file_exists($path)) {
            $data = require $path;
            if (is_array($data)) {
                self::$namedEntities = $data;
                return $data;
            }
        }

        // Fallback to JSON if PHP file doesn't exist
        $jsonPath = dirname(__DIR__, 2) . '/data/entities.json';
        $json = file_get_contents($jsonPath);
        if ($json === false) {
            throw new \RuntimeException('Failed to load entity map at ' . $jsonPath);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Entity map is not a JSON object');
        }

        self::$namedEntities = $data;
        return $data;
    }

    private static function codepointToUtf8(int $codepoint): string
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
        if ($codepoint <= 0x10FFFF) {
            return chr(0xF0 | ($codepoint >> 18))
                . chr(0x80 | (($codepoint >> 12) & 0x3F))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }
        return self::REPLACEMENT_CHAR;
    }

    public static function decodeNumericEntity(string $text, bool $isHex = false): string
    {
        $base = $isHex ? 16 : 10;
        $codepoint = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($isHex) {
                if ($ch >= '0' && $ch <= '9') {
                    $digit = ord($ch) - 48;
                } elseif ($ch >= 'a' && $ch <= 'f') {
                    $digit = 10 + ord($ch) - 97;
                } elseif ($ch >= 'A' && $ch <= 'F') {
                    $digit = 10 + ord($ch) - 65;
                } else {
                    break;
                }
            } else {
                if ($ch < '0' || $ch > '9') {
                    break;
                }
                $digit = ord($ch) - 48;
            }

            if ($codepoint > intdiv(0x10FFFF, $base)) {
                $codepoint = 0x110000;
                break;
            }
            $codepoint = ($codepoint * $base) + $digit;
            if ($codepoint > 0x10FFFF) {
                $codepoint = 0x110000;
                break;
            }
        }

        if (array_key_exists($codepoint, self::$numericReplacements)) {
            return self::$numericReplacements[$codepoint];
        }

        if ($codepoint > 0x10FFFF) {
            return self::REPLACEMENT_CHAR;
        }
        if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
            return self::REPLACEMENT_CHAR;
        }

        return self::codepointToUtf8($codepoint);
    }

    public static function decodeEntitiesInText(string $text, bool $inAttribute = false): string
    {
        // Fast path: if no ampersand, return as-is
        if (strpos($text, '&') === false) {
            return $text;
        }

        $namedEntities = null;  // Lazy-load only if needed
        $commonEntities = self::$commonEntities;
        $legacyEntities = self::$legacyEntities;

        $result = [];
        $i = 0;
        $length = strlen($text);
        while ($i < $length) {
            $nextAmp = strpos($text, '&', $i);
            if ($nextAmp === false) {
                $result[] = substr($text, $i);
                break;
            }

            if ($nextAmp > $i) {
                $result[] = substr($text, $i, $nextAmp - $i);
            }

            $i = $nextAmp;
            $j = $i + 1;

            if ($j < $length && $text[$j] === '#') {
                $j += 1;
                $isHex = false;

                if ($j < $length && ($text[$j] === 'x' || $text[$j] === 'X')) {
                    $isHex = true;
                    $j += 1;
                }

                $digitStart = $j;
                if ($isHex) {
                    while ($j < $length) {
                        $ch = $text[$j];
                        if (!(($ch >= '0' && $ch <= '9')
                            || ($ch >= 'a' && $ch <= 'f')
                            || ($ch >= 'A' && $ch <= 'F')
                        )) {
                            break;
                        }
                        $j += 1;
                    }
                } else {
                    while ($j < $length) {
                        $ch = $text[$j];
                        if ($ch < '0' || $ch > '9') {
                            break;
                        }
                        $j += 1;
                    }
                }

                $hasSemicolon = $j < $length && $text[$j] === ';';
                $digitText = substr($text, $digitStart, $j - $digitStart);

                if ($digitText !== '') {
                    $result[] = self::decodeNumericEntity($digitText, $isHex);
                    $i = $hasSemicolon ? $j + 1 : $j;
                    continue;
                }

                $result[] = substr($text, $i, $hasSemicolon ? ($j - $i + 1) : ($j - $i));
                $i = $hasSemicolon ? $j + 1 : $j;
                continue;
            }

            while ($j < $length) {
                $ch = $text[$j];
                if (!(($ch >= 'a' && $ch <= 'z')
                    || ($ch >= 'A' && $ch <= 'Z')
                    || ($ch >= '0' && $ch <= '9')
                )) {
                    break;
                }
                $j += 1;
            }

            $entityName = substr($text, $i + 1, $j - ($i + 1));
            $hasSemicolon = $j < $length && $text[$j] === ';';

            if ($entityName === '') {
                $result[] = '&';
                $i += 1;
                continue;
            }

            // Fast-path: check common entities first (covers >90% of real-world cases)
            if ($hasSemicolon && isset($commonEntities[$entityName])) {
                $result[] = $commonEntities[$entityName];
                $i = $j + 1;
                continue;
            }

            // Lazy-load full entity map only when needed
            if ($namedEntities === null) {
                $namedEntities = self::loadNamedEntities();
            }

            if ($hasSemicolon && isset($namedEntities[$entityName])) {
                $result[] = $namedEntities[$entityName];
                $i = $j + 1;
                continue;
            }

            if ($hasSemicolon && !$inAttribute) {
                $bestMatch = null;
                $bestMatchLen = 0;
                for ($k = strlen($entityName); $k > 0; $k--) {
                    $prefix = substr($entityName, 0, $k);
                    if (isset($legacyEntities[$prefix])) {
                        if (isset($commonEntities[$prefix])) {
                            $bestMatch = $commonEntities[$prefix];
                            $bestMatchLen = $k;
                            break;
                        }
                        if (isset($namedEntities[$prefix])) {
                            $bestMatch = $namedEntities[$prefix];
                            $bestMatchLen = $k;
                            break;
                        }
                    }
                }
                if ($bestMatch !== null) {
                    $result[] = $bestMatch;
                    $i = $i + 1 + $bestMatchLen;
                    continue;
                }
            }

            if (isset($legacyEntities[$entityName])) {
                $entityValue = $commonEntities[$entityName] ?? ($namedEntities[$entityName] ?? null);
                if ($entityValue !== null) {
                    $nextChar = $j < $length ? $text[$j] : null;
                    if ($inAttribute && $nextChar !== null && ((($nextChar >= 'a' && $nextChar <= 'z')
                        || ($nextChar >= 'A' && $nextChar <= 'Z')
                        || ($nextChar >= '0' && $nextChar <= '9')
                        || $nextChar === '=')
                    )) {
                        $result[] = '&';
                        $i += 1;
                        continue;
                    }

                    $result[] = $entityValue;
                    $i = $j;
                    continue;
                }
            }

            $bestMatch = null;
            $bestMatchLen = 0;
            for ($k = strlen($entityName); $k > 0; $k--) {
                $prefix = substr($entityName, 0, $k);
                if (isset($legacyEntities[$prefix])) {
                    if (isset($commonEntities[$prefix])) {
                        $bestMatch = $commonEntities[$prefix];
                        $bestMatchLen = $k;
                        break;
                    }
                    if (isset($namedEntities[$prefix])) {
                        $bestMatch = $namedEntities[$prefix];
                        $bestMatchLen = $k;
                        break;
                    }
                }
            }

            if ($bestMatch !== null) {
                if ($inAttribute) {
                    $result[] = '&';
                    $i += 1;
                    continue;
                }

                $result[] = $bestMatch;
                $i = $i + 1 + $bestMatchLen;
                continue;
            }

            if ($hasSemicolon) {
                $result[] = substr($text, $i, $j - $i + 1);
                $i = $j + 1;
            } else {
                $result[] = '&';
                $i += 1;
            }
        }

        return implode('', $result);
    }
}
