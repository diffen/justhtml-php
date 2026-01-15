<?php

declare(strict_types=1);

use JustHTML\CharacterTokens;
use JustHTML\CommentToken;
use JustHTML\Doctype;
use JustHTML\DoctypeToken;
use JustHTML\Encoding;
use JustHTML\FragmentContext;
use JustHTML\InsertionMode;
use JustHTML\JustHTML;
use JustHTML\Serialize;
use JustHTML\Tag;
use JustHTML\Tokenizer;
use JustHTML\TokenizerOpts;
use JustHTML\TreeBuilder;
use JustHTML\TokenSinkResult;
use JustHTML\EOFToken;
use JustHTML\Constants;

$required = [
    'Str.php',
    'Errors.php',
    'Constants.php',
    'Entities.php',
    'Tokens.php',
    'FragmentContext.php',
    'Node.php',
    'Selector.php',
    'Serialize.php',
    'TreeBuilderUtils.php',
    'TreeBuilderModes.php',
    'TokenizerStates.php',
    'Tokenizer.php',
    'TreeBuilder.php',
    'Encoding.php',
    'JustHTML.php',
];

foreach ($required as $file) {
    require_once __DIR__ . '/src/JustHTML/' . $file;
}

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
    $errors = [];
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
        } elseif ($mode === 'errors') {
            $errors[] = $line;
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
            'errors' => $errors,
            'document' => implode("\n", $document),
            'fragment_context' => $fragment_context,
            'script_directive' => $script_directive,
            'xml_coercion' => $xml_coercion,
            'iframe_srcdoc' => $iframe_srcdoc,
        ];
    }

    return null;
}

function run_tree_tests(string $dir, bool $show_failures = false, int $max_failures = 0): array
{
    $files = glob($dir . '/*.dat');
    sort($files);
    $passed = 0;
    $failed = 0;
    $skipped = 0;
    $shown = 0;

    foreach ($files as $file) {
        $tests = parse_dat_file($file);
        foreach ($tests as $index => $test) {
            if ($test['script_directive'] !== null) {
                $skipped += 1;
                continue;
            }
            $opts = new TokenizerOpts(false, true, null, null, $test['xml_coercion']);
            $parser = new JustHTML($test['data'], [
                'fragment_context' => $test['fragment_context'],
                'tokenizer_opts' => $opts,
                'iframe_srcdoc' => $test['iframe_srcdoc'],
                'collect_errors' => true,
            ]);
            $actual = $parser->toTestFormat();
            if (compare_outputs($test['document'], $actual)) {
                $passed += 1;
            } else {
                $failed += 1;
                if ($show_failures && ($max_failures === 0 || $shown < $max_failures)) {
                    $shown += 1;
                    $name = basename($file);
                    echo "\nTREE FAIL {$name} #{$index}\n";
                    echo "Input:\n" . $test['data'] . "\n";
                    echo "Expected:\n" . $test['document'] . "\n";
                    echo "Actual:\n" . $actual . "\n";
                }
            }
        }
    }

    return [$passed, $failed, $skipped];
}

final class RecordingTreeBuilder extends TreeBuilder
{
    /** @var array<int, mixed> */
    public array $tokens = [];

    public function processToken($token): int
    {
        if ($token instanceof Tag) {
            $token_copy = new Tag($token->kind, $token->name, $token->attrs, $token->selfClosing);
            $this->tokens[] = $token_copy;
        } elseif ($token instanceof CharacterTokens) {
            $this->tokens[] = new CharacterTokens($token->data);
        } elseif ($token instanceof CommentToken) {
            $this->tokens[] = new CommentToken($token->data);
        } elseif ($token instanceof DoctypeToken) {
            $d = $token->doctype;
            $this->tokens[] = new DoctypeToken(new Doctype($d->name, $d->publicId, $d->systemId, $d->forceQuirks));
        } elseif ($token instanceof EOFToken) {
            $this->tokens[] = new EOFToken();
        } else {
            $this->tokens[] = $token;
        }
        return parent::processToken($token);
    }

    public function processCharacters(string $data): int
    {
        if ($this->mode === InsertionMode::IN_BODY) {
            $this->tokens[] = new CharacterTokens($data);
        }
        return parent::processCharacters($data);
    }
}

function unescape_unicode(string $text): string
{
    return preg_replace_callback('/\\\\u([0-9A-Fa-f]{4})/', static function ($matches) {
        return codepoint_to_utf8(hexdec($matches[1]));
    }, $text) ?? $text;
}

function map_initial_state(string $name): ?array
{
    $mapping = [
        'Data state' => [Tokenizer::DATA, null],
        'PLAINTEXT state' => [Tokenizer::PLAINTEXT, null],
        'RCDATA state' => [Tokenizer::RCDATA, null],
        'RAWTEXT state' => [Tokenizer::RAWTEXT, null],
        'Script data state' => [Tokenizer::RAWTEXT, 'script'],
        'CDATA section state' => [Tokenizer::CDATA_SECTION, null],
    ];
    return $mapping[$name] ?? null;
}

function token_to_list($token): ?array
{
    if ($token instanceof DoctypeToken) {
        $d = $token->doctype;
        return ['DOCTYPE', $d->name, $d->publicId, $d->systemId, !$d->forceQuirks];
    }
    if ($token instanceof CommentToken) {
        return ['Comment', $token->data];
    }
    if ($token instanceof CharacterTokens) {
        return ['Character', $token->data];
    }
    if ($token instanceof Tag) {
        if ($token->kind === Tag::START) {
            $attrs = $token->attrs ?? [];
            $arr = ['StartTag', $token->name, $attrs];
            if ($token->selfClosing) {
                $arr[] = true;
            }
            return $arr;
        }
        return ['EndTag', $token->name];
    }
    if ($token instanceof EOFToken) {
        return null;
    }
    return ['Unknown'];
}

function collapse_characters(array $tokens): array
{
    $collapsed = [];
    foreach ($tokens as $token) {
        if ($token && $token[0] === 'Character' && $collapsed && $collapsed[count($collapsed) - 1][0] === 'Character') {
            $collapsed[count($collapsed) - 1][1] .= $token[1];
        } else {
            $collapsed[] = $token;
        }
    }
    return $collapsed;
}

function run_tokenizer_tests(string $dir, bool $show_failures = false, int $max_failures = 0): array
{
    $files = glob($dir . '/*.test');
    sort($files);
    $passed = 0;
    $total = 0;
    $shown = 0;

    foreach ($files as $file) {
        $json = file_get_contents($file);
        if ($json === false) {
            continue;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            continue;
        }
        $key = isset($data['tests']) ? 'tests' : 'xmlViolationTests';
        $xml_coercion = $key === 'xmlViolationTests';
        $tests = $data[$key] ?? [];

        foreach ($tests as $test) {
            $input_text = $test['input'];
            $expected_tokens = $test['output'];
            if (!is_array($expected_tokens)) {
                continue;
            }
            $test_index = $total;
            if (!empty($test['doubleEscaped'])) {
                $input_text = unescape_unicode($input_text);
                $expected_tokens = unescape_recursive($expected_tokens);
            }
            $initial_states = $test['initialStates'] ?? ['Data state'];
            $last_start_tag = $test['lastStartTag'] ?? null;
            $ok = true;

            foreach ($initial_states as $state_name) {
                $mapped = map_initial_state($state_name);
                if ($mapped === null) {
                    $ok = false;
                    break;
                }
                [$initial_state, $raw_tag] = $mapped;
                if ($last_start_tag) {
                    $raw_tag = $last_start_tag;
                }
                $sink = new RecordingTreeBuilder();
                $discard_bom = !empty($test['discardBom']);
                $opts = new TokenizerOpts(false, $discard_bom, $initial_state, $raw_tag, $xml_coercion);
                $tok = new Tokenizer($sink, $opts);
                $tok->run($input_text);
                $actual = [];
                foreach ($sink->tokens as $token) {
                    $list = token_to_list($token);
                    if ($list !== null) {
                        $actual[] = $list;
                    }
                }
                $actual = collapse_characters($actual);
                if ($actual != $expected_tokens) {
                    $ok = false;
                    if ($show_failures && ($max_failures === 0 || $shown < $max_failures)) {
                        $shown += 1;
                        $name = basename($file);
                        $desc = $test['description'] ?? '';
                        echo "\nTOKENIZER FAIL {$name} #{$test_index} {$desc}\n";
                        echo "State: {$state_name}\n";
                        echo "Input:\n" . $input_text . "\n";
                        echo "Expected:\n" . json_encode($expected_tokens) . "\n";
                        echo "Actual:\n" . json_encode($actual) . "\n";
                    }
                    break;
                }
            }

            $total += 1;
            if ($ok) {
                $passed += 1;
            }
        }
    }

    return [$passed, $total];
}

function unescape_recursive($value)
{
    if (is_string($value)) {
        return unescape_unicode($value);
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = unescape_recursive($item);
        }
        return $out;
    }
    return $value;
}

function serializer_attr_list_to_dict($attrs): array
{
    if (is_array($attrs) && array_values($attrs) !== $attrs) {
        return $attrs;
    }
    if (!$attrs) {
        return [];
    }
    $out = [];
    foreach ($attrs as $attr) {
        $name = $attr['name'] ?? null;
        if ($name === null) {
            continue;
        }
        $out[$name] = $attr['value'] ?? null;
    }
    return $out;
}

function escape_text_for_serializer_tests(?string $text): string
{
    if (!$text) {
        return '';
    }
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string)$text);
}

function escape_attr_value_for_serializer_tests($value, string $quote_char, bool $escape_lt_in_attrs): string
{
    if ($value === null) {
        return '';
    }
    $value = str_replace('&', '&amp;', (string)$value);
    if ($escape_lt_in_attrs) {
        $value = str_replace('<', '&lt;', $value);
    }
    if ($quote_char === '"') {
        return str_replace('"', '&quot;', $value);
    }
    return str_replace("'", '&#39;', $value);
}

function choose_attr_quote_for_serializer_tests($value, ?string $forced = null): string
{
    if ($forced === '"' || $forced === "'") {
        return $forced;
    }
    if ($value === null) {
        return '"';
    }
    $value = (string)$value;
    if (strpos($value, '"') !== false && strpos($value, "'") === false) {
        return "'";
    }
    return '"';
}

function can_unquote_attr_value_for_serializer_tests($value): bool
{
    if ($value === null) {
        return false;
    }
    $value = (string)$value;
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $ch = $value[$i];
        if ($ch === '>') {
            return false;
        }
        if ($ch === '"' || $ch === "'" || $ch === '=') {
            return false;
        }
        if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\f" || $ch === "\r") {
            return false;
        }
    }
    return true;
}

function serializer_minimize_attr_value(string $name, $value, bool $minimize): bool
{
    if (!$minimize) {
        return false;
    }
    if ($value === null || $value === '') {
        return true;
    }
    return strtolower((string)$value) === strtolower($name);
}

function serialize_start_tag_for_serializer_tests(string $name, array $attrs, array $options, bool $is_void): string
{
    $quote_attr_values = !empty($options['quote_attr_values']);
    $minimize_boolean = $options['minimize_boolean_attributes'] ?? true;
    $use_trailing_solidus = !empty($options['use_trailing_solidus']);
    $escape_lt_in_attrs = !empty($options['escape_lt_in_attrs']);
    $forced_quote = $options['quote_char'] ?? null;

    $parts = ['<', $name];

    if ($attrs) {
        $keys = array_keys($attrs);
        sort($keys);
        foreach ($keys as $key) {
            $value = $attrs[$key];
            if (serializer_minimize_attr_value($key, $value, (bool)$minimize_boolean)) {
                $parts[] = ' ';
                $parts[] = $key;
                continue;
            }
            if ($value === null) {
                $parts[] = ' ';
                $parts[] = $key;
                $parts[] = '=""';
                continue;
            }
            $value = (string)$value;
            if ($value === '') {
                if ($minimize_boolean) {
                    $parts[] = ' ';
                    $parts[] = $key;
                } else {
                    $parts[] = ' ';
                    $parts[] = $key;
                    $parts[] = '=""';
                }
                continue;
            }
            if (!$quote_attr_values && can_unquote_attr_value_for_serializer_tests($value)) {
                $escaped = str_replace('&', '&amp;', $value);
                if ($escape_lt_in_attrs) {
                    $escaped = str_replace('<', '&lt;', $escaped);
                }
                $parts[] = ' ';
                $parts[] = $key;
                $parts[] = '=';
                $parts[] = $escaped;
            } else {
                $quote = choose_attr_quote_for_serializer_tests($value, $forced_quote);
                $escaped = escape_attr_value_for_serializer_tests($value, $quote, $escape_lt_in_attrs);
                $parts[] = ' ';
                $parts[] = $key;
                $parts[] = '=';
                $parts[] = $quote;
                $parts[] = $escaped;
                $parts[] = $quote;
            }
        }
    }

    if ($use_trailing_solidus && $is_void) {
        $parts[] = ' />';
    } else {
        $parts[] = '>';
    }
    return implode('', $parts);
}

function strip_whitespace_for_serializer_tests(string $text): string
{
    if ($text === '') {
        return '';
    }
    $out = [];
    $last_space = false;
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];
        $mapped = ($ch === "\t" || $ch === "\r" || $ch === "\n" || $ch === "\f") ? ' ' : $ch;
        if ($mapped === ' ') {
            if ($last_space) {
                continue;
            }
            $last_space = true;
            $out[] = ' ';
        } else {
            $last_space = false;
            $out[] = $mapped;
        }
    }
    return implode('', $out);
}

function update_meta_content_type_charset($content, ?string $encoding)
{
    if ($content === null || !$encoding) {
        return $content;
    }
    $s = (string)$content;
    $lower = strtolower($s);
    $idx = strpos($lower, 'charset=');
    if ($idx === false) {
        return $s;
    }
    $start = $idx + strlen('charset=');
    $end = $start;
    $len = strlen($s);
    while ($end < $len) {
        $ch = $s[$end];
        if ($ch === ';' || $ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n" || $ch === "\f") {
            break;
        }
        $end++;
    }
    return substr($s, 0, $start) . $encoding . substr($s, $end);
}

function apply_inject_meta_charset(array $tokens, string $encoding): array
{
    $saw_head = false;
    $in_head = false;
    $content_tokens = [];
    foreach ($tokens as $tok) {
        $kind = $tok[0];
        if (!$in_head) {
            if ($kind === 'StartTag' && $tok[2] === 'head') {
                $saw_head = true;
                $in_head = true;
            }
            continue;
        }
        if ($kind === 'EndTag' && $tok[2] === 'head') {
            break;
        }
        $content_tokens[] = $tok;
    }

    if (!$saw_head) {
        $content_tokens = $tokens;
    }

    $processed = [];
    $found_charset = false;
    foreach ($content_tokens as $tok) {
        if ($tok[0] === 'EmptyTag' && $tok[1] === 'meta') {
            $attrs = serializer_attr_list_to_dict($tok[2] ?? []);
            if (array_key_exists('charset', $attrs)) {
                $attrs['charset'] = $encoding;
                $found_charset = true;
            } elseif (strtolower((string)($attrs['http-equiv'] ?? '')) === 'content-type' && array_key_exists('content', $attrs)) {
                $attrs['content'] = update_meta_content_type_charset($attrs['content'], $encoding);
                $found_charset = true;
            }
            $processed[] = ['EmptyTag', 'meta', $attrs];
        } else {
            $processed[] = $tok;
        }
    }

    if (!$found_charset) {
        array_unshift($processed, ['EmptyTag', 'meta', ['charset' => $encoding]]);
    }

    return $processed;
}

function serializer_tok_name($tok): ?string
{
    if ($tok === null) {
        return null;
    }
    $kind = $tok[0];
    if ($kind === 'StartTag') {
        return $tok[2];
    }
    if ($kind === 'EndTag') {
        return $tok[2];
    }
    if ($kind === 'EmptyTag') {
        return $tok[1];
    }
    return null;
}

function serializer_tok_is_space_chars($tok): bool
{
    return $tok !== null && $tok[0] === 'Characters' && strpos($tok[1], ' ') === 0;
}

function serializer_should_omit_start_tag(string $name, array $attrs, $prev_tok, $next_tok): bool
{
    if ($attrs) {
        return false;
    }
    if ($name === 'html') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'Comment' || serializer_tok_is_space_chars($next_tok)) {
            return false;
        }
        if ($next_tok[0] === 'Characters' && $next_tok[1] === '') {
            return false;
        }
        return true;
    }
    if ($name === 'head') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'Comment' || $next_tok[0] === 'Characters') {
            return false;
        }
        if ($next_tok[0] === 'EndTag' && serializer_tok_name($next_tok) === 'head') {
            return true;
        }
        if (in_array($next_tok[0], ['StartTag', 'EmptyTag', 'EndTag'], true)) {
            return true;
        }
        return false;
    }
    if ($name === 'body') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'Comment' || serializer_tok_is_space_chars($next_tok)) {
            return false;
        }
        return true;
    }
    if ($name === 'colgroup') {
        if ($prev_tok !== null && $prev_tok[0] === 'StartTag' && serializer_tok_name($prev_tok) === 'table') {
            if ($next_tok !== null && in_array($next_tok[0], ['StartTag', 'EmptyTag'], true) && serializer_tok_name($next_tok) === 'col') {
                return true;
            }
        }
        return false;
    }
    if ($name === 'tbody') {
        if ($prev_tok !== null && $prev_tok[0] === 'StartTag' && serializer_tok_name($prev_tok) === 'table') {
            if ($next_tok !== null && $next_tok[0] === 'StartTag' && serializer_tok_name($next_tok) === 'tr') {
                return true;
            }
        }
        return false;
    }
    return false;
}

function serializer_should_omit_end_tag(string $name, $next_tok): bool
{
    if (in_array($name, ['html', 'head', 'body', 'colgroup'], true)) {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'Comment' || serializer_tok_is_space_chars($next_tok)) {
            return false;
        }
        if (in_array($next_tok[0], ['StartTag', 'EmptyTag', 'EndTag'], true)) {
            return true;
        }
        if ($next_tok[0] === 'Characters') {
            return strpos($next_tok[1], ' ') !== 0;
        }
        return true;
    }
    if ($name === 'li') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && serializer_tok_name($next_tok) === 'li') {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    if ($name === 'dt') {
        if ($next_tok === null) {
            return false;
        }
        if ($next_tok[0] === 'StartTag' && in_array(serializer_tok_name($next_tok), ['dt', 'dd'], true)) {
            return true;
        }
        return false;
    }
    if ($name === 'dd') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && in_array(serializer_tok_name($next_tok), ['dd', 'dt'], true)) {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    if ($name === 'p') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        if (in_array($next_tok[0], ['StartTag', 'EmptyTag'], true)) {
            $next_name = serializer_tok_name($next_tok);
            if (in_array($next_name, [
                'address',
                'article',
                'aside',
                'blockquote',
                'datagrid',
                'dialog',
                'dir',
                'div',
                'dl',
                'fieldset',
                'footer',
                'form',
                'h1',
                'h2',
                'h3',
                'h4',
                'h5',
                'h6',
                'header',
                'hr',
                'menu',
                'nav',
                'ol',
                'p',
                'pre',
                'section',
                'table',
                'ul',
            ], true)) {
                return true;
            }
        }
        return false;
    }
    if ($name === 'optgroup') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && serializer_tok_name($next_tok) === 'optgroup') {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    if ($name === 'option') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && in_array(serializer_tok_name($next_tok), ['option', 'optgroup'], true)) {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    if ($name === 'tbody') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && in_array(serializer_tok_name($next_tok), ['tbody', 'tfoot'], true)) {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    if ($name === 'tfoot') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && serializer_tok_name($next_tok) === 'tbody') {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    if ($name === 'thead') {
        if ($next_tok !== null && $next_tok[0] === 'StartTag' && in_array(serializer_tok_name($next_tok), ['tbody', 'tfoot'], true)) {
            return true;
        }
        return false;
    }
    if ($name === 'tr') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && serializer_tok_name($next_tok) === 'tr') {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    if ($name === 'td' || $name === 'th') {
        if ($next_tok === null) {
            return true;
        }
        if ($next_tok[0] === 'StartTag' && in_array(serializer_tok_name($next_tok), ['td', 'th'], true)) {
            return true;
        }
        if ($next_tok[0] === 'EndTag') {
            return true;
        }
        return false;
    }
    return false;
}

function serialize_serializer_token_stream(array $tokens, array $options = []): string
{
    $parts = [];
    $rawtext = null;

    if (!empty($options['inject_meta_charset'])) {
        $encoding = $options['encoding'] ?? null;
        if (!$encoding) {
            return '';
        }
        $tokens = apply_inject_meta_charset($tokens, (string)$encoding);
    }

    $open_elements = [];
    $strip_ws = !empty($options['strip_whitespace']);
    $escape_rcdata = !empty($options['escape_rcdata']);
    $ws_preserve = ['pre' => true, 'textarea' => true, 'script' => true, 'style' => true];

    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        $prev_tok = $i > 0 ? $tokens[$i - 1] : null;
        $next_tok = $i + 1 < $count ? $tokens[$i + 1] : null;

        $kind = $tok[0];
        if ($kind === 'StartTag') {
            $name = $tok[2];
            $attrs = serializer_attr_list_to_dict($tok[3] ?? []);
            $open_elements[] = $name;

            if (serializer_should_omit_start_tag($name, $attrs, $prev_tok, $next_tok)) {
                continue;
            }

            $parts[] = serialize_start_tag_for_serializer_tests($name, $attrs, $options, isset(Constants::VOID_ELEMENTS[$name]));
            if (($name === 'script' || $name === 'style') && !$escape_rcdata) {
                $rawtext = $name;
            }
        } elseif ($kind === 'EndTag') {
            $name = $tok[2];
            if ($open_elements) {
                $last = $open_elements[count($open_elements) - 1];
                if ($last === $name) {
                    array_pop($open_elements);
                } else {
                    for ($j = count($open_elements) - 1; $j >= 0; $j--) {
                        if ($open_elements[$j] === $name) {
                            $open_elements = array_slice($open_elements, 0, $j);
                            break;
                        }
                    }
                }
            }

            if (serializer_should_omit_end_tag($name, $next_tok)) {
                continue;
            }
            $parts[] = Serialize::serializeEndTag($name);
            if ($rawtext === $name) {
                $rawtext = null;
            }
        } elseif ($kind === 'EmptyTag') {
            $name = $tok[1];
            $attrs = serializer_attr_list_to_dict($tok[2] ?? []);
            $parts[] = serialize_start_tag_for_serializer_tests($name, $attrs, $options, true);
        } elseif ($kind === 'Characters') {
            if ($rawtext !== null) {
                $parts[] = $tok[1];
            } else {
                $text = $tok[1];
                if ($strip_ws) {
                    $has_preserve = false;
                    foreach ($open_elements as $elem) {
                        if (isset($ws_preserve[$elem])) {
                            $has_preserve = true;
                            break;
                        }
                    }
                    if (!$has_preserve) {
                        $text = strip_whitespace_for_serializer_tests($text);
                    }
                }
                $parts[] = escape_text_for_serializer_tests($text);
            }
        } elseif ($kind === 'Comment') {
            $parts[] = '<!--' . $tok[1] . '-->';
        } elseif ($kind === 'Doctype') {
            $name = $tok[1] ?? '';
            $public_id = $tok[2] ?? null;
            $system_id = $tok[3] ?? null;
            if ($public_id === null && $system_id === null) {
                $parts[] = '<!DOCTYPE ' . $name . '>';
            } else {
                $has_public = $public_id !== null && $public_id !== '';
                $has_system = $system_id !== null && $system_id !== '';
                if ($has_public) {
                    if ($has_system) {
                        $parts[] = '<!DOCTYPE ' . $name . ' PUBLIC "' . $public_id . '" "' . $system_id . '">';
                    } else {
                        $parts[] = '<!DOCTYPE ' . $name . ' PUBLIC "' . $public_id . '">';
                    }
                } elseif ($has_system) {
                    $parts[] = '<!DOCTYPE ' . $name . ' SYSTEM "' . $system_id . '">';
                } else {
                    $parts[] = '<!DOCTYPE ' . $name . '>';
                }
            }
        } else {
            return '';
        }
    }

    return implode('', $parts);
}

function run_serializer_tests(string $dir): array
{
    $files = glob($dir . '/*.test');
    sort($files);
    $passed = 0;
    $total = 0;
    $skipped = 0;

    foreach ($files as $file) {
        $json = file_get_contents($file);
        if ($json === false) {
            continue;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['tests'])) {
            continue;
        }
        $tests = $data['tests'];
        foreach ($tests as $test) {
            $input = $test['input'] ?? [];
            $expected_list = $test['expected'] ?? ($test['xhtml'] ?? []);
            if (!$expected_list) {
                $skipped += 1;
                continue;
            }
            $options = $test['options'] ?? [];
            $actual = serialize_serializer_token_stream($input, $options);
            $ok = false;
            foreach ($expected_list as $expected) {
                if ($actual === $expected) {
                    $ok = true;
                    break;
                }
            }
            $total += 1;
            if ($ok) {
                $passed += 1;
            }
        }
    }

    return [$passed, $total, $skipped];
}

function parse_encoding_dat_file(string $path): array
{
    $data = file_get_contents($path);
    if ($data === false) {
        return [];
    }
    $tests = [];
    $mode = null;
    $current_data = [];
    $current_encoding = null;

    $flush = static function () use (&$tests, &$current_data, &$current_encoding) {
        if ($current_data === null || $current_encoding === null) {
            return;
        }
        $tests[] = [implode('', $current_data), $current_encoding];
        $current_data = [];
        $current_encoding = null;
    };

    $lines = preg_split("/(?<=\n)/", $data) ?: [];
    foreach ($lines as $line) {
        $stripped = rtrim($line, "\r\n");
        if ($stripped === '#data') {
            $flush();
            $mode = 'data';
            continue;
        }
        if ($stripped === '#encoding') {
            $mode = 'encoding';
            continue;
        }
        if ($mode === 'data') {
            $current_data[] = $line;
        } elseif ($mode === 'encoding') {
            if ($current_encoding === null && trim($stripped) !== '') {
                $current_encoding = $stripped;
            }
        }
    }
    $flush();
    return $tests;
}

function run_encoding_tests(string $dir): array
{
    $files = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $fileinfo) {
        if ($fileinfo->isFile() && $fileinfo->getExtension() === 'dat') {
            $files[] = $fileinfo->getPathname();
        }
    }
    sort($files);
    $passed = 0;
    $total = 0;
    $skipped = 0;

    foreach ($files as $file) {
        if (strpos($file, 'scripted') !== false) {
            continue;
        }
        $tests = parse_encoding_dat_file($file);
        foreach ($tests as $test) {
            [$data, $expected_label] = $test;
            $expected = Encoding::normalizeEncodingLabel($expected_label);
            if ($expected === null) {
                $skipped += 1;
                continue;
            }
            [$actual, $bom_len] = Encoding::sniffHtmlEncoding($data);
            $total += 1;
            if ($actual === $expected) {
                $passed += 1;
            }
        }
    }
    return [$passed, $total, $skipped];
}

function run_selector_tests(bool $show_failures = false, int $max_failures = 0): array
{
    $tests = [
        [
            'name' => 'queryFirst id + tag',
            'run' => static function (): bool {
                $html = '<div id="mw-content-text"><p>First</p><section><p>Second</p></section></div>'
                    . '<p>Outside</p>';
                $doc = new JustHTML($html);

                $first = $doc->queryFirst('#mw-content-text p');
                if ($first === null || $first->toText() !== 'First') {
                    return false;
                }

                $nodes = $doc->query('#mw-content-text p');
                if (count($nodes) !== 2) {
                    return false;
                }
                if ($nodes[0]->toText() !== 'First' || $nodes[1]->toText() !== 'Second') {
                    return false;
                }

                return true;
            },
        ],
        [
            'name' => 'queryFirst id only',
            'run' => static function (): bool {
                $html = '<div id="mw-content-text"><p>First</p></div><p>Outside</p>';
                $doc = new JustHTML($html);

                $node = $doc->queryFirst('#mw-content-text');
                if ($node === null) {
                    return false;
                }
                return $node->name === 'div';
            },
        ],
        [
            'name' => 'queryFirst tag only',
            'run' => static function (): bool {
                $html = '<div id="mw-content-text"><p>First</p></div><p>Outside</p>';
                $doc = new JustHTML($html);

                $node = $doc->queryFirst('p');
                return $node !== null && $node->toText() === 'First';
            },
        ],
        [
            'name' => 'queryFirst missing selector',
            'run' => static function (): bool {
                $doc = new JustHTML('<div></div>');
                return $doc->queryFirst('#missing') === null;
            },
        ],
    ];

    $passed = 0;
    $failed = 0;

    foreach ($tests as $test) {
        $ok = false;
        try {
            $ok = (bool)$test['run']();
        } catch (\Throwable $e) {
            $ok = false;
            if ($show_failures) {
                echo "Selector test failed: {$test['name']} ({$e->getMessage()})\n";
            }
        }

        if ($ok) {
            $passed += 1;
            continue;
        }

        $failed += 1;
        if ($show_failures) {
            echo "Selector test failed: {$test['name']}\n";
        }
        if ($max_failures > 0 && $failed >= $max_failures) {
            break;
        }
    }

    return [$passed, $passed + $failed];
}

function run_all(bool $show_failures = false, int $max_failures = 0): void
{
    [$tree_passed, $tree_failed, $tree_skipped] = run_tree_tests(__DIR__ . '/html5lib-tests/tree-construction', $show_failures, $max_failures);
    [$tok_passed, $tok_total] = run_tokenizer_tests(__DIR__ . '/html5lib-tests/tokenizer', $show_failures, $max_failures);
    [$ser_passed, $ser_total, $ser_skipped] = run_serializer_tests(__DIR__ . '/html5lib-tests/serializer');
    [$enc_passed, $enc_total, $enc_skipped] = run_encoding_tests(__DIR__ . '/html5lib-tests/encoding');
    [$sel_passed, $sel_total] = run_selector_tests($show_failures, $max_failures);

    $total_passed = $tree_passed + $tok_passed + $ser_passed + $enc_passed + $sel_passed;
    $total_failed = $tree_failed + ($tok_total - $tok_passed) + ($ser_total - $ser_passed - $ser_skipped)
        + ($enc_total - $enc_passed - $enc_skipped) + ($sel_total - $sel_passed);
    $total_skipped = $tree_skipped + $ser_skipped + $enc_skipped;

    echo "Tree tests: {$tree_passed} passed, {$tree_failed} failed, {$tree_skipped} skipped\n";
    echo "Tokenizer tests: {$tok_passed} passed, " . ($tok_total - $tok_passed) . " failed\n";
    echo "Serializer tests: {$ser_passed} passed, " . ($ser_total - $ser_passed - $ser_skipped) . " failed, {$ser_skipped} skipped\n";
    echo "Encoding tests: {$enc_passed} passed, " . ($enc_total - $enc_passed - $enc_skipped) . " failed, {$enc_skipped} skipped\n";
    echo "Selector tests: {$sel_passed} passed, " . ($sel_total - $sel_passed) . " failed\n";
    echo "Total: {$total_passed} passed, {$total_failed} failed, {$total_skipped} skipped\n";
}

$args = array_slice($argv, 1);
$show_failures = in_array('--show-failures', $args, true);
$max_failures = 0;
foreach ($args as $arg) {
    if (strpos($arg, '--max-failures=') === 0) {
        $value = substr($arg, strlen('--max-failures='));
        $max_failures = max(0, (int)$value);
        break;
    }
}
$run_tree = in_array('--tree', $args, true);
$run_tokenizer = in_array('--tokenizer', $args, true);
$run_serializer = in_array('--serializer', $args, true);
$run_encoding = in_array('--encoding', $args, true);
$run_selector = in_array('--selector', $args, true);

if ($run_tree || $run_tokenizer || $run_serializer || $run_encoding || $run_selector) {
    if ($run_tree) {
        [$tree_passed, $tree_failed, $tree_skipped] = run_tree_tests(__DIR__ . '/html5lib-tests/tree-construction', $show_failures, $max_failures);
        echo "Tree tests: {$tree_passed} passed, {$tree_failed} failed, {$tree_skipped} skipped\n";
    }
    if ($run_tokenizer) {
        [$tok_passed, $tok_total] = run_tokenizer_tests(__DIR__ . '/html5lib-tests/tokenizer', $show_failures, $max_failures);
        echo "Tokenizer tests: {$tok_passed} passed, " . ($tok_total - $tok_passed) . " failed\n";
    }
    if ($run_serializer) {
        [$ser_passed, $ser_total, $ser_skipped] = run_serializer_tests(__DIR__ . '/html5lib-tests/serializer');
        echo "Serializer tests: {$ser_passed} passed, " . ($ser_total - $ser_passed - $ser_skipped) . " failed, {$ser_skipped} skipped\n";
    }
    if ($run_encoding) {
        [$enc_passed, $enc_total, $enc_skipped] = run_encoding_tests(__DIR__ . '/html5lib-tests/encoding');
        echo "Encoding tests: {$enc_passed} passed, " . ($enc_total - $enc_passed - $enc_skipped) . " failed, {$enc_skipped} skipped\n";
    }
    if ($run_selector) {
        [$sel_passed, $sel_total] = run_selector_tests($show_failures, $max_failures);
        echo "Selector tests: {$sel_passed} passed, " . ($sel_total - $sel_passed) . " failed\n";
    }
} else {
    run_all($show_failures, $max_failures);
}
