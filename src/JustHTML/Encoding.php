<?php

declare(strict_types=1);

namespace JustHTML;

final class Encoding
{
    /** @var array<string, array<string, string>> */
    private static array $iconvSingleByteTables = [];

    /** @var array<string, array<int, string>> */
    private const ENCODING_LABELS = [
        'utf-8' => ['unicode-1-1-utf-8', 'unicode11utf8', 'unicode20utf8', 'utf-8', 'utf8', 'x-unicode20utf8'],
        'ibm866' => ['866', 'cp866', 'csibm866', 'ibm866'],
        'iso-8859-2' => ['csisolatin2', 'iso-8859-2', 'iso-ir-101', 'iso8859-2', 'iso88592', 'iso_8859-2', 'iso_8859-2:1987', 'l2', 'latin2'],
        'iso-8859-3' => ['csisolatin3', 'iso-8859-3', 'iso-ir-109', 'iso8859-3', 'iso88593', 'iso_8859-3', 'iso_8859-3:1988', 'l3', 'latin3'],
        'iso-8859-4' => ['csisolatin4', 'iso-8859-4', 'iso-ir-110', 'iso8859-4', 'iso88594', 'iso_8859-4', 'iso_8859-4:1988', 'l4', 'latin4'],
        'iso-8859-5' => ['csisolatincyrillic', 'cyrillic', 'iso-8859-5', 'iso-ir-144', 'iso8859-5', 'iso88595', 'iso_8859-5', 'iso_8859-5:1988'],
        'iso-8859-6' => ['arabic', 'asmo-708', 'csiso88596e', 'csiso88596i', 'csisolatinarabic', 'ecma-114', 'iso-8859-6', 'iso-8859-6-e', 'iso-8859-6-i', 'iso-ir-127', 'iso8859-6', 'iso88596', 'iso_8859-6', 'iso_8859-6:1987'],
        'iso-8859-7' => ['csisolatingreek', 'ecma-118', 'elot_928', 'greek', 'greek8', 'iso-8859-7', 'iso-ir-126', 'iso8859-7', 'iso88597', 'iso_8859-7', 'iso_8859-7:1987', 'sun_eu_greek'],
        'iso-8859-8' => ['csiso88598e', 'csisolatinhebrew', 'hebrew', 'iso-8859-8', 'iso-8859-8-e', 'iso-ir-138', 'iso8859-8', 'iso88598', 'iso_8859-8', 'iso_8859-8:1988', 'visual'],
        'iso-8859-8-i' => ['csiso88598i', 'iso-8859-8-i', 'logical'],
        'iso-8859-10' => ['iso-8859-10', 'iso-ir-157', 'iso8859-10', 'iso885910', 'l6', 'latin6'],
        'iso-8859-13' => ['iso-8859-13', 'iso8859-13', 'iso885913'],
        'iso-8859-14' => ['iso-8859-14', 'iso8859-14', 'iso885914'],
        'iso-8859-15' => ['csisolatin9', 'iso-8859-15', 'iso8859-15', 'iso885915', 'iso_8859-15', 'l9'],
        'iso-8859-16' => ['iso-8859-16'],
        'koi8-r' => ['cskoi8r', 'koi', 'koi8', 'koi8-r', 'koi8_r'],
        'koi8-u' => ['koi8-ru', 'koi8-u'],
        'macintosh' => ['csmacintosh', 'mac', 'macintosh', 'x-mac-roman'],
        'windows-874' => ['dos-874', 'iso-8859-11', 'iso8859-11', 'iso885911', 'tis-620', 'windows-874'],
        'windows-1250' => ['cp1250', 'windows-1250', 'x-cp1250'],
        'windows-1251' => ['cp1251', 'windows-1251', 'x-cp1251'],
        'windows-1252' => ['ansi_x3.4-1968', 'ascii', 'cp1252', 'cp819', 'csisolatin1', 'ibm819', 'iso-8859-1', 'iso-ir-100', 'iso8859-1', 'iso88591', 'iso_8859-1', 'iso_8859-1:1987', 'l1', 'latin-1', 'latin1', 'us-ascii', 'windows-1252', 'windows1252', 'x-cp1252'],
        'windows-1253' => ['cp1253', 'windows-1253', 'x-cp1253'],
        'windows-1254' => ['cp1254', 'csisolatin5', 'iso-8859-9', 'iso-ir-148', 'iso8859-9', 'iso88599', 'iso_8859-9', 'iso_8859-9:1989', 'l5', 'latin5', 'windows-1254', 'x-cp1254'],
        'windows-1255' => ['cp1255', 'windows-1255', 'x-cp1255'],
        'windows-1256' => ['cp1256', 'windows-1256', 'x-cp1256'],
        'windows-1257' => ['cp1257', 'windows-1257', 'x-cp1257'],
        'windows-1258' => ['cp1258', 'windows-1258', 'x-cp1258'],
        'x-mac-cyrillic' => ['x-mac-cyrillic', 'x-mac-ukrainian'],
        'gbk' => ['chinese', 'csgb2312', 'csiso58gb231280', 'gb2312', 'gb_2312', 'gb_2312-80', 'gbk', 'iso-ir-58', 'x-gbk'],
        'gb18030' => ['gb18030'],
        'big5' => ['big5', 'big5-hkscs', 'cn-big5', 'csbig5', 'x-x-big5'],
        'euc-jp' => ['cseucpkdfmtjapanese', 'euc-jp', 'eucjp', 'x-euc-jp'],
        'iso-2022-jp' => ['csiso2022jp', 'iso-2022-jp'],
        'shift_jis' => ['csshiftjis', 'ms932', 'ms_kanji', 'shift-jis', 'shift_jis', 'sjis', 'windows-31j', 'x-sjis'],
        'euc-kr' => ['cseuckr', 'csksc56011987', 'euc-kr', 'iso-ir-149', 'korean', 'ks_c_5601-1987', 'ks_c_5601-1989', 'ksc5601', 'ksc_5601', 'windows-949'],
        'replacement' => ['csiso2022kr', 'hz-gb-2312', 'iso-2022-cn', 'iso-2022-cn-ext', 'iso-2022-kr', 'replacement'],
        'utf-16be' => ['unicodefffe', 'utf-16be', 'utf16be'],
        'utf-16le' => ['csunicode', 'iso-10646-ucs-2', 'unicode', 'unicodefeff', 'utf-16', 'utf-16le', 'utf16', 'utf16le'],
        'x-user-defined' => ['x-user-defined'],
    ];

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

        // HTML deliberately treats UTF-7 labels as Windows-1252.
        if (in_array($s, ['utf-7', 'utf7', 'x-utf-7'], true)) {
            return 'windows-1252';
        }

        foreach (self::ENCODING_LABELS as $encoding => $labels) {
            if (in_array($s, $labels, true)) {
                return $encoding;
            }
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

        if ($enc === 'x-user-defined') {
            return 'windows-1252';
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

        $payload = $bomLen ? substr($data, $bomLen) : $data;

        $encodingMap = [
            'windows-1252' => 'CP1252',
            'ibm866' => 'CP866',
            'iso-8859-2' => 'ISO-8859-2',
            'iso-8859-3' => 'ISO-8859-3',
            'iso-8859-4' => 'ISO-8859-4',
            'iso-8859-5' => 'ISO-8859-5',
            'iso-8859-6' => 'ISO-8859-6',
            'iso-8859-7' => 'ISO-8859-7',
            'iso-8859-8' => 'ISO-8859-8',
            'iso-8859-8-i' => 'ISO-8859-8',
            'iso-8859-10' => 'ISO-8859-10',
            'iso-8859-13' => 'ISO-8859-13',
            'iso-8859-14' => 'ISO-8859-14',
            'iso-8859-15' => 'ISO-8859-15',
            'iso-8859-16' => 'ISO-8859-16',
            'koi8-r' => 'KOI8-R',
            'koi8-u' => 'KOI8-U',
            'windows-874' => 'Windows-874',
            'windows-1250' => 'Windows-1250',
            'windows-1251' => 'Windows-1251',
            'windows-1253' => 'Windows-1253',
            'windows-1254' => 'Windows-1254',
            'windows-1255' => 'Windows-1255',
            'windows-1256' => 'Windows-1256',
            'windows-1257' => 'Windows-1257',
            'windows-1258' => 'Windows-1258',
            'gbk' => 'CP936',
            'gb18030' => 'GB18030',
            'big5' => 'BIG-5',
            'euc-jp' => 'EUC-JP',
            'iso-2022-jp' => 'ISO-2022-JP',
            'shift_jis' => 'SJIS-win',
            'euc-kr' => 'UHC',
            'utf-16le' => 'UTF-16LE',
            'utf-16be' => 'UTF-16BE',
            'utf-16' => 'UTF-16',
            'utf-8' => 'UTF-8',
        ];

        if ($enc === 'x-user-defined') {
            return [self::decodeUserDefined($payload), $enc];
        }

        if ($enc === 'replacement') {
            return [$payload === '' ? '' : "\u{FFFD}", $enc];
        }

        if ($enc === 'macintosh' || $enc === 'x-mac-cyrillic') {
            $candidates = $enc === 'macintosh'
                ? ['MACINTOSH', 'MAC', 'MACROMAN']
                : ['MAC-CYRILLIC', 'MACCYRILLIC'];
            $decoded = self::decodeSingleByteWithIconv($payload, $candidates, $enc);
            return [$decoded, $enc];
        }

        $src = $encodingMap[$enc] ?? null;
        if ($src === null) {
            throw new \RuntimeException('No decoder is configured for HTML encoding ' . $enc);
        }

        if (!function_exists('mb_convert_encoding')) {
            throw new \RuntimeException('JustHTML byte decoding requires the mbstring extension');
        }

        $iconvSingleByte = in_array($enc, [
            'windows-874',
            'windows-1250',
            'windows-1253',
            'windows-1255',
            'windows-1256',
            'windows-1257',
            'windows-1258',
        ], true);
        if (!$iconvSingleByte) {
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            try {
                $decoded = mb_convert_encoding($payload, 'UTF-8', $src);
            } finally {
                mb_substitute_character($prev);
            }
        } else {
            $decoded = self::decodeSingleByteWithIconv(
                $payload,
                [$src, 'CP' . substr($enc, strlen('windows-'))],
                $enc
            );
        }

        return [$decoded, $enc];
    }

    private static function decodeUserDefined(string $data): string
    {
        $out = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            $codepoint = $byte <= 0x7F ? $byte : 0xF780 + $byte - 0x80;
            $out .= self::codepointToUtf8($codepoint);
        }
        return $out;
    }

    /** @param array<int, string> $encodingCandidates */
    private static function decodeSingleByteWithIconv(
        string $data,
        array $encodingCandidates,
        string $canonicalEncoding
    ): string
    {
        if (isset(self::$iconvSingleByteTables[$canonicalEncoding])) {
            return strtr($data, self::$iconvSingleByteTables[$canonicalEncoding]);
        }

        $backend = null;
        foreach ($encodingCandidates as $candidate) {
            if (@iconv($candidate, 'UTF-8', 'A') === 'A') {
                $backend = $candidate;
                break;
            }
        }
        if ($backend === null) {
            throw new \RuntimeException(
                'The installed iconv does not support HTML encoding ' . $canonicalEncoding
            );
        }

        $table = [];
        for ($byte = 0; $byte <= 0xFF; $byte++) {
            $source = chr($byte);
            $converted = @iconv($backend, 'UTF-8', $source);
            $table[$source] = $converted === false ? "\u{FFFD}" : $converted;
        }
        self::$iconvSingleByteTables[$canonicalEncoding] = $table;
        return strtr($data, $table);
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
        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }
}
