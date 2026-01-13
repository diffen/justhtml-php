<?php

declare(strict_types=1);

namespace JustHTML;

final class Encoding
{
    /** @var array<int, bool> */
    private static array $asciiWhitespace = [
        0x09 => true,
        0x0A => true,
        0x0C => true,
        0x0D => true,
        0x20 => true,
    ];

    private static function asciiLower(int $b): int
    {
        if ($b >= 0x41 && $b <= 0x5A) {
            return $b | 0x20;
        }
        return $b;
    }

    private static function isAsciiAlpha(int $b): bool
    {
        $b = self::asciiLower($b);
        return $b >= 0x61 && $b <= 0x7A;
    }

    private static function skipAsciiWhitespace(string $data, int $i): int
    {
        $n = strlen($data);
        while ($i < $n) {
            $b = ord($data[$i]);
            if (!isset(self::$asciiWhitespace[$b])) {
                break;
            }
            $i++;
        }
        return $i;
    }

    private static function stripAsciiWhitespace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $start = 0;
        $end = strlen($value);
        while ($start < $end && isset(self::$asciiWhitespace[ord($value[$start])])) {
            $start++;
        }
        while ($end > $start && isset(self::$asciiWhitespace[ord($value[$end - 1])])) {
            $end--;
        }
        return substr($value, $start, $end - $start);
    }

    public static function normalizeEncodingLabel($label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }

        if (is_string($label)) {
            $s = trim($label);
        } else {
            $s = trim((string)$label);
        }

        if ($s === '') {
            return null;
        }

        $s = strtolower($s);

        if ($s === 'utf-7' || $s === 'utf7' || $s === 'x-utf-7') {
            return 'windows-1252';
        }

        if ($s === 'utf-8' || $s === 'utf8') {
            return 'utf-8';
        }

        if (in_array($s, ['iso-8859-1', 'iso8859-1', 'latin1', 'latin-1', 'l1', 'cp819', 'ibm819'], true)) {
            return 'windows-1252';
        }

        if (in_array($s, ['windows-1252', 'windows1252', 'cp1252', 'x-cp1252'], true)) {
            return 'windows-1252';
        }

        if (in_array($s, ['iso-8859-2', 'iso8859-2', 'latin2', 'latin-2'], true)) {
            return 'iso-8859-2';
        }

        if ($s === 'euc-jp' || $s === 'eucjp') {
            return 'euc-jp';
        }

        if ($s === 'utf-16' || $s === 'utf16') {
            return 'utf-16';
        }
        if ($s === 'utf-16le' || $s === 'utf16le') {
            return 'utf-16le';
        }
        if ($s === 'utf-16be' || $s === 'utf16be') {
            return 'utf-16be';
        }

        return null;
    }

    private static function normalizeMetaDeclaredEncoding(?string $label): ?string
    {
        $enc = self::normalizeEncodingLabel($label);
        if ($enc === null) {
            return null;
        }

        if (in_array($enc, ['utf-16', 'utf-16le', 'utf-16be', 'utf-32', 'utf-32le', 'utf-32be'], true)) {
            return 'utf-8';
        }

        return $enc;
    }

    /** @return array{0: string|null, 1: int} */
    private static function sniffBom(string $data): array
    {
        if (strlen($data) >= 3 && substr($data, 0, 3) === "\xEF\xBB\xBF") {
            return ['utf-8', 3];
        }
        if (strlen($data) >= 2 && substr($data, 0, 2) === "\xFF\xFE") {
            return ['utf-16le', 2];
        }
        if (strlen($data) >= 2 && substr($data, 0, 2) === "\xFE\xFF") {
            return ['utf-16be', 2];
        }
        return [null, 0];
    }

    private static function extractCharsetFromContent(?string $contentBytes): ?string
    {
        if ($contentBytes === null || $contentBytes === '') {
            return null;
        }

        $b = '';
        $len = strlen($contentBytes);
        for ($i = 0; $i < $len; $i++) {
            $ch = ord($contentBytes[$i]);
            if (isset(self::$asciiWhitespace[$ch])) {
                $b .= chr(0x20);
            } else {
                $b .= chr(self::asciiLower($ch));
            }
        }

        $idx = strpos($b, 'charset');
        if ($idx === false) {
            return null;
        }

        $i = $idx + strlen('charset');
        $n = strlen($b);
        while ($i < $n && isset(self::$asciiWhitespace[ord($b[$i])])) {
            $i++;
        }
        if ($i >= $n || $b[$i] !== '=') {
            return null;
        }
        $i++;
        while ($i < $n && isset(self::$asciiWhitespace[ord($b[$i])])) {
            $i++;
        }
        if ($i >= $n) {
            return null;
        }

        $quote = null;
        if ($b[$i] === '"' || $b[$i] === "'") {
            $quote = $b[$i];
            $i++;
        }

        $start = $i;
        while ($i < $n) {
            $ch = $b[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    break;
                }
            } else {
                if (isset(self::$asciiWhitespace[ord($ch)]) || $ch === ';') {
                    break;
                }
            }
            $i++;
        }

        if ($quote !== null && ($i >= $n || $b[$i] !== $quote)) {
            return null;
        }

        return substr($b, $start, $i - $start);
    }

    private static function prescanForMetaCharset(string $data): ?string
    {
        $maxNonComment = 1024;
        $maxTotalScan = 65536;
        $n = strlen($data);
        $i = 0;
        $nonComment = 0;

        while ($i < $n && $i < $maxTotalScan && $nonComment < $maxNonComment) {
            if (ord($data[$i]) !== 0x3C) { // '<'
                $i++;
                $nonComment++;
                continue;
            }

            if ($i + 3 < $n && substr($data, $i + 1, 3) === '!--') {
                $end = strpos($data, '-->', $i + 4);
                if ($end === false) {
                    return null;
                }
                $i = $end + 3;
                continue;
            }

            $j = $i + 1;
            if ($j < $n && $data[$j] === '/') {
                $k = $i;
                $quote = null;
                while ($k < $n && $k < $maxTotalScan && $nonComment < $maxNonComment) {
                    $ch = $data[$k];
                    if ($quote === null) {
                        if ($ch === '"' || $ch === "'") {
                            $quote = $ch;
                        } elseif ($ch === '>') {
                            $k++;
                            $nonComment++;
                            break;
                        }
                    } else {
                        if ($ch === $quote) {
                            $quote = null;
                        }
                    }
                    $k++;
                    $nonComment++;
                }
                $i = $k;
                continue;
            }

            if ($j >= $n || !self::isAsciiAlpha(ord($data[$j]))) {
                $i++;
                $nonComment++;
                continue;
            }

            $nameStart = $j;
            while ($j < $n && self::isAsciiAlpha(ord($data[$j]))) {
                $j++;
            }

            $tagName = strtolower(substr($data, $nameStart, $j - $nameStart));
            if ($tagName !== 'meta') {
                $k = $i;
                $quote = null;
                while ($k < $n && $k < $maxTotalScan && $nonComment < $maxNonComment) {
                    $ch = $data[$k];
                    if ($quote === null) {
                        if ($ch === '"' || $ch === "'") {
                            $quote = $ch;
                        } elseif ($ch === '>') {
                            $k++;
                            $nonComment++;
                            break;
                        }
                    } else {
                        if ($ch === $quote) {
                            $quote = null;
                        }
                    }
                    $k++;
                    $nonComment++;
                }
                $i = $k;
                continue;
            }

            $charset = null;
            $httpEquiv = null;
            $content = null;

            $k = $j;
            $sawGt = false;
            $startI = $i;
            while ($k < $n && $k < $maxTotalScan) {
                $ch = ord($data[$k]);
                if ($ch === 0x3E) {
                    $sawGt = true;
                    $k++;
                    break;
                }

                if ($ch === 0x3C) {
                    break;
                }

                if (isset(self::$asciiWhitespace[$ch]) || $ch === 0x2F) {
                    $k++;
                    continue;
                }

                $attrStart = $k;
                while ($k < $n) {
                    $ch = ord($data[$k]);
                    if (isset(self::$asciiWhitespace[$ch]) || in_array($ch, [0x3D, 0x3E, 0x2F, 0x3C], true)) {
                        break;
                    }
                    $k++;
                }
                $attrName = strtolower(substr($data, $attrStart, $k - $attrStart));
                $k = self::skipAsciiWhitespace($data, $k);

                $value = null;
                if ($k < $n && ord($data[$k]) === 0x3D) {
                    $k++;
                    $k = self::skipAsciiWhitespace($data, $k);
                    if ($k >= $n) {
                        break;
                    }

                    $quote = null;
                    if ($data[$k] === '"' || $data[$k] === "'") {
                        $quote = $data[$k];
                        $k++;
                        $valStart = $k;
                        $endQuote = strpos($data, $quote, $k);
                        if ($endQuote === false) {
                            $i++;
                            $nonComment++;
                            $charset = null;
                            $httpEquiv = null;
                            $content = null;
                            $sawGt = false;
                            break;
                        }
                        $value = substr($data, $valStart, $endQuote - $valStart);
                        $k = $endQuote + 1;
                    } else {
                        $valStart = $k;
                        while ($k < $n) {
                            $ch = ord($data[$k]);
                            if (isset(self::$asciiWhitespace[$ch]) || in_array($ch, [0x3E, 0x3C], true)) {
                                break;
                            }
                            $k++;
                        }
                        $value = substr($data, $valStart, $k - $valStart);
                    }
                }

                if ($attrName === 'charset') {
                    $charset = self::stripAsciiWhitespace($value);
                } elseif ($attrName === 'http-equiv') {
                    $httpEquiv = $value;
                } elseif ($attrName === 'content') {
                    $content = $value;
                }
            }

            if ($sawGt) {
                if ($charset !== null) {
                    $enc = self::normalizeMetaDeclaredEncoding($charset);
                    if ($enc !== null) {
                        return $enc;
                    }
                }

                if ($httpEquiv !== null && strtolower($httpEquiv) === 'content-type' && $content !== null) {
                    $extracted = self::extractCharsetFromContent($content);
                    if ($extracted !== null) {
                        $enc = self::normalizeMetaDeclaredEncoding($extracted);
                        if ($enc !== null) {
                            return $enc;
                        }
                    }
                }

                $i = $k;
                $consumed = $i - $startI;
                $nonComment += $consumed;
            } else {
                $i++;
                $nonComment++;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: int} */
    public static function sniffHtmlEncoding(string $data, ?string $transportEncoding = null): array
    {
        $transport = self::normalizeEncodingLabel($transportEncoding);
        if ($transport !== null) {
            return [$transport, 0];
        }

        [$bomEnc, $bomLen] = self::sniffBom($data);
        if ($bomEnc !== null) {
            return [$bomEnc, $bomLen];
        }

        $metaEnc = self::prescanForMetaCharset($data);
        if ($metaEnc !== null) {
            return [$metaEnc, 0];
        }

        return ['windows-1252', 0];
    }

    /** @return array{0: string, 1: string} */
    public static function decodeHtml(string $data, ?string $transportEncoding = null): array
    {
        [$enc, $bomLen] = self::sniffHtmlEncoding($data, $transportEncoding);

        $allow = ['utf-8', 'windows-1252', 'iso-8859-2', 'euc-jp', 'utf-16', 'utf-16le', 'utf-16be'];
        if (!in_array($enc, $allow, true)) {
            $enc = 'windows-1252';
            $bomLen = 0;
        }

        $payload = $bomLen ? substr($data, $bomLen) : $data;

        $encodingMap = [
            'windows-1252' => 'CP1252',
            'iso-8859-2' => 'ISO-8859-2',
            'euc-jp' => 'EUC-JP',
            'utf-16le' => 'UTF-16LE',
            'utf-16be' => 'UTF-16BE',
            'utf-16' => 'UTF-16',
            'utf-8' => 'UTF-8',
        ];

        $src = $encodingMap[$enc] ?? 'UTF-8';
        $prev = null;
        if (function_exists('mb_substitute_character')) {
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD);
        }

        if (function_exists('mb_convert_encoding')) {
            $decoded = mb_convert_encoding($payload, 'UTF-8', $src);
        } else {
            $decoded = iconv($src, 'UTF-8//IGNORE', $payload);
        }

        if ($prev !== null && function_exists('mb_substitute_character')) {
            mb_substitute_character($prev);
        }

        if ($decoded === false) {
            $decoded = '';
        }

        return [$decoded, $enc];
    }
}
