<?php

declare(strict_types=1);

use JustHTML\FragmentContext;

function codepoint_to_utf8(int $codepoint): string
{
    if ($codepoint <= 0x7F) {
        return chr($codepoint);
    }
    if ($codepoint <= 0x7FF) {
        return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
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
    return "\u{FFFD}";
}

function normalize_output(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $lines = preg_split("/\r?\n/", $text);
    $lines = array_map(static function ($line) {
        return rtrim($line);
    }, $lines);
    return implode("\n", $lines);
}

function compare_outputs(string $expected, string $actual): bool
{
    return normalize_output($expected) === normalize_output($actual);
}

function decode_escapes(string $text): string
{
    if (strpos($text, '\\x') === false && strpos($text, '\\u') === false) {
        return $text;
    }
    $out = [];
    $len = strlen($text);
    $i = 0;
    while ($i < $len) {
        $ch = $text[$i];
        if ($ch === '\\' && $i + 1 < $len) {
            $next = $text[$i + 1];
            if ($next === 'x' && $i + 3 < $len) {
                $hex = substr($text, $i + 2, 2);
                if (ctype_xdigit($hex)) {
                    $out[] = chr(hexdec($hex));
                    $i += 4;
                    continue;
                }
            }
            if ($next === 'u' && $i + 5 < $len) {
                $hex = substr($text, $i + 2, 4);
                if (ctype_xdigit($hex)) {
                    $out[] = codepoint_to_utf8(hexdec($hex));
                    $i += 6;
                    continue;
                }
            }
        }
        $out[] = $ch;
        $i += 1;
    }
    return implode('', $out);
}

function parse_dat_file(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $lines = preg_split("/\n/", $content);
    $tests = [];
    $current = [];
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $line = rtrim($lines[$i], "\r");
        $current[] = $line;
        if ($i + 1 >= $count || rtrim($lines[$i + 1], "\r") === '#data') {
            $has_content = false;
            foreach ($current as $entry) {
                if (trim($entry) !== '') {
                    $has_content = true;
                    break;
                }
            }
            if ($has_content) {
                $test = parse_single_test($current);
                if ($test !== null) {
                    $tests[] = $test;
                }
            }
            $current = [];
        }
    }
    return $tests;
}

function parse_single_test(array $lines): ?array
{
    $data = [];
    $document = [];
    $fragment_context = null;
    $script_directive = null;
    $xml_coercion = false;
    $iframe_srcdoc = false;
    $mode = null;

    foreach ($lines as $line) {
        $line = rtrim($line, "\r");
        if (isset($line[0]) && $line[0] === '#') {
            $directive = rtrim(substr($line, 1), "\r");
            if ($directive === 'script-on' || $directive === 'script-off') {
                $script_directive = $directive;
            } elseif ($directive === 'xml-coercion') {
                $xml_coercion = true;
            } elseif ($directive === 'iframe-srcdoc') {
                $iframe_srcdoc = true;
            } else {
                $mode = $directive;
            }
            continue;
        }

        if ($mode === 'data') {
            $data[] = $line;
        } elseif ($mode === 'document') {
            $document[] = $line;
        } elseif ($mode === 'document-fragment') {
            $fragment = trim($line);
            if ($fragment !== '') {
                if (strpos($fragment, ' ') !== false) {
                    [$namespace, $tag_name] = explode(' ', $fragment, 2);
                    $fragment_context = new FragmentContext($tag_name, $namespace);
                } else {
                    $fragment_context = new FragmentContext($fragment);
                }
            }
        }
    }

    if ($data || $document) {
        $raw = implode("\n", $data);
        return [
            'data' => decode_escapes($raw),
            'document' => implode("\n", $document),
            'fragment_context' => $fragment_context,
            'script_directive' => $script_directive,
            'xml_coercion' => $xml_coercion,
            'iframe_srcdoc' => $iframe_srcdoc,
        ];
    }

    return null;
}
