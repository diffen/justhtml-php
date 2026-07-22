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
    'Markdown.php',
    'SelectCompiler.php',
    'StreamSelect.php',
    'Stream.php',
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
            if ($test['script_directive'] === 'script-on') {
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
        [
            'name' => 'complex selector backtracking',
            'run' => static function (): bool {
                $doc = new JustHTML(
                    '<div class="a"><div class="b"><div><div class="b"><span class="c"></span></div></div></div></div>'
                );
                return count($doc->query('.a > .b .c')) === 1;
            },
        ],
        [
            'name' => 'CSS identifier escapes',
            'run' => static function (): bool {
                $doc = new JustHTML('<div class="foo:bar 123"></div>');
                return count($doc->query('.foo\\:bar')) === 1
                    && count($doc->query('.\\31 23')) === 1
                    && count($doc->query('\\64 iv')) === 1;
            },
        ],
        [
            'name' => 'CSS escape edge cases',
            'run' => static function (): bool {
                $doc = new JustHTML(
                    '<main><div data=")" class="foo) 123"></div><div data="ab"></div></main>'
                );
                return count($doc->query("[data=\"a\\\nb\"]")) === 1
                    && count($doc->query(".\\31\r\n23")) === 1
                    && count($doc->query('div:not([data=")"])')) === 1
                    && count($doc->query('div:not(.foo\\))')) === 1;
            },
        ],
        [
            'name' => 'template selector boundary consistency',
            'run' => static function (): bool {
                $doc = new JustHTML('<template id="x" class="x"><span></span></template>');
                return count($doc->query('#x span')) === 0
                    && count($doc->query('.x span')) === 0
                    && count($doc->query('template#x span')) === 0
                    && count($doc->query('span')) === 1;
            },
        ],
        [
            'name' => 'structural pseudo classes',
            'run' => static function (): bool {
                $doc = new JustHTML('<ul><li id="a"></li><li></li><li id="c"></li><li></li></ul>');
                $odd = $doc->query('li:nth-child(2n+1)');
                return count($odd) === 2
                    && ($odd[0]->attrs['id'] ?? '') === 'a'
                    && ($odd[1]->attrs['id'] ?? '') === 'c'
                    && count($doc->query('li:first-child')) === 1
                    && count($doc->query('li:last-of-type')) === 1;
            },
        ],
        [
            'name' => 'empty rejects whitespace text',
            'run' => static function (): bool {
                $doc = new JustHTML('<main><div> </div><div></div></main>');
                return count($doc->query('main > div:empty')) === 1;
            },
        ],
        [
            'name' => 'invalid functional pseudo classes throw',
            'run' => static function (): bool {
                $doc = new JustHTML('<div><p>x</p></div>');
                foreach ([':not', ':not()', ':nth-child', ':nth-child()', ':nth-child(foo)', ':nth-of-type', ':nth-of-type()'] as $selector) {
                    try {
                        $doc->query($selector);
                        return false;
                    } catch (\JustHTML\SelectorError $e) {
                        // Expected.
                    }
                }
                return true;
            },
        ],
        [
            'name' => 'empty selector-list entries throw',
            'run' => static function (): bool {
                $doc = new JustHTML('<div id="a"></div><p class="b"></p>');
                foreach (['div,', 'div,,', 'div,,p', 'div, ,p', 'p,'] as $selector) {
                    try {
                        $doc->query($selector);
                        return false;
                    } catch (\JustHTML\SelectorError $e) {
                        // Expected.
                    }
                }
                return count($doc->query('div, p')) === 2
                    && count($doc->query('div,p')) === 2
                    && count($doc->query(' #a , .b ')) === 2;
            },
        ],
        [
            'name' => 'foreign selector case sensitivity',
            'run' => static function (): bool {
                $doc = new JustHTML('<DIV></DIV><svg viewBox="0 0 1 1"><linearGradient class="x"/></svg>');
                return count($doc->query('DIV')) === 1
                    && count($doc->query('linearGradient')) === 1
                    && count($doc->query('lineargradient')) === 0
                    && count($doc->query('linearGradient.x')) === 1
                    && count($doc->query('lineargradient.x')) === 0
                    && count($doc->query('svg[viewBox]')) === 1
                    && count($doc->query('svg[viewbox]')) === 0;
            },
        ],
        [
            'name' => 'of-type uses namespace-aware element names',
            'run' => static function (): bool {
                $svg = new \JustHTML\SimpleDomNode('svg', [], null, 'svg');
                $camel = new \JustHTML\SimpleDomNode('linearGradient', null, null, 'svg');
                $lower = new \JustHTML\SimpleDomNode('lineargradient', null, null, 'svg');
                $svg->appendChild($camel);
                $svg->appendChild($lower);
                if (!$camel->matches(':first-of-type')
                    || !$lower->matches(':first-of-type')
                    || !$camel->matches(':only-of-type')
                    || !$lower->matches(':only-of-type')
                    || !$lower->matches(':nth-of-type(1)')
                    || $lower->matches(':nth-of-type(2)')
                    || count($svg->query(':only-of-type')) !== 2
                ) {
                    return false;
                }

                $mixed = new \JustHTML\SimpleDomNode('div');
                $htmlItem = new \JustHTML\SimpleDomNode('item', null, null, 'html');
                $svgItem = new \JustHTML\SimpleDomNode('item', null, null, 'svg');
                $mixed->appendChild($htmlItem);
                $mixed->appendChild($svgItem);
                if (!$htmlItem->matches(':only-of-type')
                    || !$svgItem->matches(':only-of-type')
                    || count($mixed->query(':only-of-type')) !== 2
                ) {
                    return false;
                }

                $html = new \JustHTML\SimpleDomNode('div');
                $upperDiv = new \JustHTML\SimpleDomNode('DIV');
                $lowerDiv = new \JustHTML\SimpleDomNode('div');
                $html->appendChild($upperDiv);
                $html->appendChild($lowerDiv);
                if (!$upperDiv->matches(':first-of-type')
                    || $lowerDiv->matches(':first-of-type')
                    || !$lowerDiv->matches(':nth-of-type(2)')
                ) {
                    return false;
                }

                $parsed = new JustHTML(
                    '<svg><linearGradient/><stop/><linearGradient/></svg>'
                );
                return count($parsed->query('linearGradient:nth-of-type(1)')) === 1
                    && count($parsed->query('linearGradient:nth-of-type(2)')) === 1;
            },
        ],
        [
            'name' => 'boolean attributes match presence selectors',
            'run' => static function (): bool {
                $node = new \JustHTML\SimpleDomNode('span', ['data-x' => null]);
                if (!$node->matches('[data-x]')
                    || !$node->matches('[data-x=""]')
                    || $node->matches('[data-y]')
                    || $node->matches('[data-x^="a"]')
                    || $node->matches('[data-x*="a"]')
                ) {
                    return false;
                }
                $doc = new JustHTML('<input disabled><input>');
                return count($doc->query('[disabled]')) === 1;
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

function run_api_regression_tests(bool $show_failures = false): array
{
    $tests = [
        'template and raw-text serialization' => static function (): bool {
            $doc = new JustHTML('<template><b>x</b></template><script>if (a < b && c > d) {}</script>');
            $html = $doc->toHtml(false);
            return strpos($html, '<template><b>x</b></template>') !== false
                && strpos($html, '<script>if (a < b && c > d) {}</script>') !== false;
        },
        'pretty mixed content preservation' => static function (): bool {
            $doc = new JustHTML('<p>a <em>b</em> c</p>');
            return strpos($doc->toHtml(), '<p>a <em>b</em> c</p>') !== false;
        },
        'pretty inline element text preservation' => static function (): bool {
            $doc = new JustHTML('<p><em>a</em><em>b</em></p>');
            $html = $doc->toHtml();
            $roundtrip = new JustHTML($html);
            $paragraph = $roundtrip->queryFirst('p');
            return $paragraph !== null && $paragraph->toText('', false) === 'ab';
        },
        'foreign namespace serialization' => static function (): bool {
            $doc = new JustHTML(
                '<svg><script><![CDATA[<g></g>]]></script><source><circle></circle></source></svg>'
            );
            $roundtrip = new JustHTML($doc->toHtml(false));
            return $roundtrip->toTestFormat() === $doc->toTestFormat();
        },
        'doctype serialization' => static function (): bool {
            $doc = new JustHTML('<!DOCTYPE HTML PUBLIC "example" "about:legacy-compat"><p>x</p>');
            return strpos($doc->toHtml(false), '<!DOCTYPE html PUBLIC "example" "about:legacy-compat">') === 0;
        },
        'unrepresentable doctype rejected' => static function (): bool {
            $node = new \JustHTML\SimpleDomNode(
                '!doctype',
                null,
                new Doctype('html', 'a"b\'c', null)
            );
            try {
                $node->toHtml(0, 2, false);
            } catch (\InvalidArgumentException $e) {
                return true;
            }
            return false;
        },
        'DOM node move' => static function (): bool {
            $doc = new JustHTML('<main><div><i>x</i></div><section></section></main>');
            $div = $doc->queryFirst('div');
            $section = $doc->queryFirst('section');
            $child = $doc->queryFirst('i');
            $section->appendChild($child);
            return count($div->children) === 0
                && count($section->children) === 1
                && $child->parent === $section;
        },
        'leaf append throws atomically' => static function (): bool {
            $parent = new \JustHTML\SimpleDomNode('div');
            $child = new \JustHTML\TextNode('kept');
            $comment = new \JustHTML\SimpleDomNode('#comment', null, 'comment');
            $parent->appendChild($child);
            try {
                $comment->appendChild($child);
            } catch (\RuntimeException $e) {
                return $child->parent === $parent
                    && count($parent->children) === 1
                    && $parent->children[0] === $child;
            }
            return false;
        },
        'removeChild rejects invalid targets' => static function (): bool {
            $parent = new \JustHTML\SimpleDomNode('div');
            $child = new \JustHTML\SimpleDomNode('span');
            $missing = new \JustHTML\SimpleDomNode('i');
            $parent->appendChild($child);
            try {
                $parent->removeChild($missing);
                return false;
            } catch (\RuntimeException $e) {
                if (count($parent->children) !== 1
                    || $parent->children[0] !== $child
                    || $child->parent !== $parent
                ) {
                    return false;
                }
            }

            $comment = new \JustHTML\SimpleDomNode('#comment', null, 'comment');
            try {
                $comment->removeChild(new \JustHTML\TextNode('x'));
                return false;
            } catch (\RuntimeException $e) {
                // Expected.
            }

            $parent->removeChild($child);
            return count($parent->children) === 0 && $child->parent === null;
        },
        'WHATWG legacy encoding labels' => static function (): bool {
            [$shiftJis, $shiftJisName] = Encoding::decodeHtml("\x82\xA0", 'shift_jis');
            [$gbk, $gbkName] = Encoding::decodeHtml("\xC4\xE3", 'gb2312');
            [$userDefined, $userDefinedName] = Encoding::decodeHtml("\x80\xFF", 'x-user-defined');
            [$metaName] = Encoding::sniffHtmlEncoding('<meta charset="shift_jis">');
            [$metaUserDefined] = Encoding::sniffHtmlEncoding('<meta charset="x-user-defined">');
            return $shiftJisName === 'shift_jis'
                && $shiftJis === 'あ'
                && $gbkName === 'gbk'
                && $gbk === '你'
                && $userDefinedName === 'x-user-defined'
                && $userDefined === "\u{F780}\u{F7FF}"
                && $metaName === 'shift_jis'
                && $metaUserDefined === 'windows-1252';
        },
        'stream select matches full DOM on tree-construction hazards' => static function (): bool {
            $cases = [
                ['<p>one<p>two<ul><li>a<li>b</ul>', 'p, li:nth-child(2)'],
                ['<table><div class=f>x</div><tr><td>c</td></tr></table>', '.f, tbody td'],
                ['<p><b>one<div class=x>two</div></b>', 'b, div.x'],
                ['<p id=p>first</p><div id=d>second</div><body class=x>', 'body.x p, div#d'],
                ['<template><p class=t>tpl</p></template><p class=t>real</p>', 'p.t'],
                ['<svg><foreignObject><p class=z>in</p></foreignObject></svg>', 'svg p'],
                ['<div class=open><p class=also-open>never closed', 'div.open, p.also-open'],
            ];
            foreach ($cases as [$html, $selector]) {
                $doc = new JustHTML($html);
                $expected = array_map(static function ($node): string {
                    return $node->toTestFormat();
                }, $doc->query($selector));
                $actual = [];
                foreach (\JustHTML\Stream::select($html, $selector) as $node) {
                    if (!$node instanceof \JustHTML\ElementNode) {
                        return false;
                    }
                    $actual[] = $node->toTestFormat();
                }
                if ($actual !== $expected) {
                    return false;
                }
            }
            return true;
        },
        'stream select public contract' => static function (): bool {
            $matches = iterator_to_array(\JustHTML\Stream::select(
                '<p class="a b">one</p><p class=a>two</p>',
                '.a, .b, p'
            ), false);
            $first = \JustHTML\Stream::selectFirst('<main><p id=x>ok</p></main>', 'main > p#x');
            $missing = \JustHTML\Stream::selectFirst('<p>x</p>', '.missing');
            return count($matches) === 2
                && $matches[0]->parent === null
                && $matches[0]->toText() === 'one'
                && $matches[1]->toText() === 'two'
                && $first instanceof \JustHTML\ElementNode
                && ($first->attrs['id'] ?? null) === 'x'
                && $missing === null;
        },
        'stream select supports html and body roots' => static function (): bool {
            $html = \JustHTML\Stream::selectFirst('<title>x</title><p>y</p>', 'html');
            $body = \JustHTML\Stream::selectFirst('<title>x</title><p>y</p>', 'body');
            return $html instanceof \JustHTML\SimpleDomNode
                && $html->name === 'html'
                && $body instanceof \JustHTML\SimpleDomNode
                && $body->name === 'body';
        },
        'stream select preserves keys across early and EOF results' => static function (): bool {
            $matches = iterator_to_array(\JustHTML\Stream::select(
                '<p id=a>a</p><selectedcontent></selectedcontent><p id=b>b</p>',
                'p'
            ));
            return array_keys($matches) === [0, 1]
                && ($matches[0]->attrs['id'] ?? null) === 'a'
                && ($matches[1]->attrs['id'] ?? null) === 'b';
        },
        'stream select nested results share detached tree' => static function (): bool {
            $generator = \JustHTML\Stream::select(
                '<div class=m><span><div class=m>x</div></span></div>',
                'div.m'
            );
            $generator->rewind();
            $outer = $generator->current();
            if (!$outer instanceof \JustHTML\ElementNode || $outer->parent !== null) {
                return false;
            }
            $nested = $outer->queryFirst('div.m');
            if (!$nested instanceof \JustHTML\ElementNode) {
                return false;
            }
            $nested->attrs['consumer'] = 'visible';
            $generator->next();
            $inner = $generator->current();
            return $inner === $nested
                && $inner->parent !== null
                && ($inner->attrs['consumer'] ?? null) === 'visible';
        },
        'stream select validates selectors eagerly' => static function (): bool {
            foreach (['div + p', 'p:last-child', 'p:empty', 'div,'] as $selector) {
                try {
                    // Do not iterate: the exception is required at call time.
                    \JustHTML\Stream::select('<p>x</p>', $selector);
                    return false;
                } catch (\JustHTML\SelectorError $e) {
                    // Expected.
                }
            }
            return true;
        },
        'stream select byte decoding mirrors events' => static function (): bool {
            $node = \JustHTML\Stream::selectFirst("<p>\xE9</p>", 'p', 'windows-1252', true);
            $nullInput = \JustHTML\Stream::selectFirst(null, 'p');
            return $node instanceof \JustHTML\ElementNode
                && $node->toText() === 'é'
                && $nullInput === null;
        },
        'invalid DOM move is atomic' => static function (): bool {
            $first = new \JustHTML\SimpleDomNode('first');
            $second = new \JustHTML\SimpleDomNode('second');
            $child = new \JustHTML\SimpleDomNode('child');
            $reference = new \JustHTML\SimpleDomNode('reference');
            $missing = new \JustHTML\SimpleDomNode('missing');
            $first->appendChild($child);
            $second->appendChild($reference);
            try {
                $second->insertBefore($child, $missing);
            } catch (\RuntimeException $e) {
                return count($first->children) === 1
                    && $first->children[0] === $child
                    && $child->parent === $first
                    && count($second->children) === 1;
            }
            return false;
        },
        'invalid DOM replacement is atomic' => static function (): bool {
            $first = new \JustHTML\SimpleDomNode('first');
            $second = new \JustHTML\SimpleDomNode('second');
            $incoming = new \JustHTML\SimpleDomNode('incoming');
            $existing = new \JustHTML\SimpleDomNode('existing');
            $missing = new \JustHTML\SimpleDomNode('missing');
            $first->appendChild($incoming);
            $second->appendChild($existing);
            try {
                $second->replaceChild($incoming, $missing);
            } catch (\RuntimeException $e) {
                if (count($first->children) !== 1
                    || $first->children[0] !== $incoming
                    || $incoming->parent !== $first
                    || $second->children[0] !== $existing
                ) {
                    return false;
                }
                try {
                    $second->replaceChild($incoming, $incoming);
                } catch (\RuntimeException $identityError) {
                    return true;
                }
            }
            return false;
        },
        'events SVG CDATA' => static function (): bool {
            $events = iterator_to_array(\JustHTML\Stream::events('<svg><![CDATA[x<y]]></svg>'));
            return isset($events[1]) && $events[1] === ['text', 'x<y'];
        },
        'events integration-point CDATA' => static function (): bool {
            $svg = iterator_to_array(\JustHTML\Stream::events(
                '<svg><foreignObject><![CDATA[x<y]]></foreignObject></svg>'
            ));
            $math = iterator_to_array(\JustHTML\Stream::events(
                '<math><mi><![CDATA[x<y]]></mi></math>'
            ));
            return isset($svg[2], $math[2])
                && $svg[2] === ['text', 'x<y']
                && $math[2] === ['text', 'x<y'];
        },
        'events end-tag repair respects integration boundaries' => static function (): bool {
            $events = iterator_to_array(\JustHTML\Stream::events(
                '<svg><foreignObject><div></svg></div></foreignObject><![CDATA[x]]></svg>'
            ));
            foreach ($events as $event) {
                if ($event === ['text', 'x']) {
                    return true;
                }
            }
            return false;
        },
        'deprecated stream alias matches events' => static function (): bool {
            $html = '<p>Hello &amp; goodbye</p>';
            return iterator_to_array(\JustHTML\Stream::stream($html))
                === iterator_to_array(\JustHTML\Stream::events($html));
        },
        'DOM integration-point raw text' => static function (): bool {
            $doc = new JustHTML(
                '<svg><foreignObject><script>if(a<b){c>d}</script></foreignObject></svg>'
            );
            $script = $doc->queryFirst('script');
            return $script !== null && $script->toText('', false) === 'if(a<b){c>d}';
        },
        'fragment tokenizer states' => static function (): bool {
            $title = new JustHTML('A&amp;B&#x43;', [
                'fragment_context' => new FragmentContext('title', 'html'),
            ]);
            $xmp = new JustHTML('A<b>B</b>', [
                'fragment_context' => new FragmentContext('xmp'),
            ]);
            return $title->root->toText('', false) === 'A&BC'
                && count($xmp->query('b')) === 0
                && $xmp->root->toText('', false) === 'A<b>B</b>';
        },
        'leading UTF-8 BOM discarded' => static function (): bool {
            $doc = new JustHTML("\xEF\xBB\xBF<p>x</p>");
            return $doc->root->toText('', false) === 'x';
        },
        'CR-only error positions' => static function (): bool {
            $doc = new JustHTML("<div>\r<@>", ['collect_errors' => true]);
            foreach ($doc->errors as $error) {
                if ($error->line === 2) {
                    return true;
                }
            }
            return false;
        },
        'CRLF exact error position' => static function (): bool {
            $doc = new JustHTML("<div/\r\n>", ['collect_errors' => true]);
            foreach ($doc->errors as $error) {
                if ($error->code === 'unexpected-character-after-solidus-in-tag') {
                    return $error->line === 2 && $error->column === 1;
                }
            }
            return false;
        },
        'CDATA EOF exact error position' => static function (): bool {
            $sink = new class {
                /** @var array<int, mixed> */
                public array $openElements = [];
                public function processToken($token): int
                {
                    return TokenSinkResult::Continue;
                }
                public function processCharacters(string $data): void
                {
                }
            };
            $opts = new TokenizerOpts(false, true, Tokenizer::CDATA_SECTION);
            $tokenizer = new Tokenizer($sink, $opts, true);
            $tokenizer->run("\n");
            foreach ($tokenizer->errors as $error) {
                if ($error->code === 'eof-in-cdata') {
                    return $error->line === 2 && $error->column === 1;
                }
            }
            return false;
        },
        'multibyte error columns count characters' => static function (): bool {
            $doc = new JustHTML("é<@>", ['collect_errors' => true]);
            foreach ($doc->errors as $error) {
                if ($error->code === 'invalid-first-character-of-tag-name') {
                    return $error->line === 1 && $error->column === 3;
                }
            }
            return false;
        },
        'long invalid entity remains linear and unchanged' => static function (): bool {
            $name = str_repeat('a', 2000);
            $doc = new JustHTML('<p>&' . $name . ';</p>');
            $paragraph = $doc->queryFirst('p');
            return $paragraph !== null && $paragraph->toText('', false) === '&' . $name . ';';
        },
        'br does not close an open paragraph' => static function (): bool {
            $doc = new JustHTML('<p>a<br>b</p>');
            if ($doc->toHtml(false) !== '<html><head></head><body><p>a<br>b</p></body></html>') {
                return false;
            }
            // hr must still close the paragraph.
            $hr = new JustHTML('<p>a<hr>b</p>');
            return strpos($hr->toHtml(false), '<p>a</p><hr>') !== false;
        },
        'void elements reject children' => static function (): bool {
            $br = new \JustHTML\SimpleDomNode('br');
            try {
                $br->appendChild(new \JustHTML\TextNode('x'));
                return false;
            } catch (\RuntimeException $e) {
                // Expected.
            }
            // Void names outside the HTML namespace are ordinary elements.
            $svgBr = new \JustHTML\SimpleDomNode('br', null, null, 'svg');
            $svgBr->appendChild(new \JustHTML\TextNode('x'));
            return count($svgBr->children) === 1;
        },
        'markdown list items keep block children' => static function (): bool {
            $doc = new JustHTML('<ul><li><p>one</p><p>two</p></li><li>three</li></ul>');
            return $doc->toMarkdown() === "- one\n\n  two\n- three";
        },
        'markdown nested lists stay attached' => static function (): bool {
            $doc = new JustHTML('<ol><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ol>');
            return $doc->toMarkdown() === "1. a\n   - b\n   - c\n2. d";
        },
        'markdown link destinations are escaped' => static function (): bool {
            $doc = new JustHTML('<a href="http://x.com/a(b) c">t</a>');
            return $doc->toMarkdown() === '[t](http://x.com/a\\(b\\)%20c)';
        },
    ];

    $passed = 0;
    foreach ($tests as $name => $test) {
        try {
            if ($test()) {
                $passed += 1;
                continue;
            }
        } catch (\Throwable $e) {
            if ($show_failures) {
                echo "API regression failed: {$name} ({$e->getMessage()})\n";
            }
            continue;
        }
        if ($show_failures) {
            echo "API regression failed: {$name}\n";
        }
    }
    return [$passed, count($tests)];
}

function run_all(bool $show_failures = false, int $max_failures = 0): int
{
    [$tree_passed, $tree_failed, $tree_skipped] = run_tree_tests(__DIR__ . '/html5lib-tests/tree-construction', $show_failures, $max_failures);
    [$tok_passed, $tok_total] = run_tokenizer_tests(__DIR__ . '/html5lib-tests/tokenizer', $show_failures, $max_failures);
    [$ser_passed, $ser_total, $ser_skipped] = run_serializer_tests(__DIR__ . '/html5lib-tests/serializer');
    [$enc_passed, $enc_total, $enc_skipped] = run_encoding_tests(__DIR__ . '/html5lib-tests/encoding');
    [$sel_passed, $sel_total] = run_selector_tests($show_failures, $max_failures);
    [$api_passed, $api_total] = run_api_regression_tests($show_failures);

    $total_passed = $tree_passed + $tok_passed + $ser_passed + $enc_passed + $sel_passed + $api_passed;
    $total_failed = $tree_failed + ($tok_total - $tok_passed) + ($ser_total - $ser_passed - $ser_skipped)
        + ($enc_total - $enc_passed - $enc_skipped) + ($sel_total - $sel_passed) + ($api_total - $api_passed);
    $total_skipped = $tree_skipped + $ser_skipped + $enc_skipped;

    echo "Tree tests: {$tree_passed} passed, {$tree_failed} failed, {$tree_skipped} skipped\n";
    echo "Tokenizer tests: {$tok_passed} passed, " . ($tok_total - $tok_passed) . " failed\n";
    echo "Serializer tests: {$ser_passed} passed, " . ($ser_total - $ser_passed - $ser_skipped) . " failed, {$ser_skipped} skipped\n";
    echo "Encoding tests: {$enc_passed} passed, " . ($enc_total - $enc_passed - $enc_skipped) . " failed, {$enc_skipped} skipped\n";
    echo "Selector tests: {$sel_passed} passed, " . ($sel_total - $sel_passed) . " failed\n";
    echo "API regressions: {$api_passed} passed, " . ($api_total - $api_passed) . " failed\n";
    echo "Total: {$total_passed} passed, {$total_failed} failed, {$total_skipped} skipped\n";
    return $total_failed;
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
$run_api = in_array('--api', $args, true);

$failed = 0;
if ($run_tree || $run_tokenizer || $run_serializer || $run_encoding || $run_selector || $run_api) {
    if ($run_tree) {
        [$tree_passed, $tree_failed, $tree_skipped] = run_tree_tests(__DIR__ . '/html5lib-tests/tree-construction', $show_failures, $max_failures);
        echo "Tree tests: {$tree_passed} passed, {$tree_failed} failed, {$tree_skipped} skipped\n";
        $failed += $tree_failed;
    }
    if ($run_tokenizer) {
        [$tok_passed, $tok_total] = run_tokenizer_tests(__DIR__ . '/html5lib-tests/tokenizer', $show_failures, $max_failures);
        echo "Tokenizer tests: {$tok_passed} passed, " . ($tok_total - $tok_passed) . " failed\n";
        $failed += $tok_total - $tok_passed;
    }
    if ($run_serializer) {
        [$ser_passed, $ser_total, $ser_skipped] = run_serializer_tests(__DIR__ . '/html5lib-tests/serializer');
        echo "Serializer tests: {$ser_passed} passed, " . ($ser_total - $ser_passed - $ser_skipped) . " failed, {$ser_skipped} skipped\n";
        $failed += $ser_total - $ser_passed - $ser_skipped;
    }
    if ($run_encoding) {
        [$enc_passed, $enc_total, $enc_skipped] = run_encoding_tests(__DIR__ . '/html5lib-tests/encoding');
        echo "Encoding tests: {$enc_passed} passed, " . ($enc_total - $enc_passed - $enc_skipped) . " failed, {$enc_skipped} skipped\n";
        $failed += $enc_total - $enc_passed - $enc_skipped;
    }
    if ($run_selector) {
        [$sel_passed, $sel_total] = run_selector_tests($show_failures, $max_failures);
        echo "Selector tests: {$sel_passed} passed, " . ($sel_total - $sel_passed) . " failed\n";
        $failed += $sel_total - $sel_passed;
    }
    if ($run_api) {
        [$api_passed, $api_total] = run_api_regression_tests($show_failures);
        echo "API regressions: {$api_passed} passed, " . ($api_total - $api_passed) . " failed\n";
        $failed += $api_total - $api_passed;
    }
} else {
    $failed = run_all($show_failures, $max_failures);
}

exit($failed > 0 ? 1 : 0);
