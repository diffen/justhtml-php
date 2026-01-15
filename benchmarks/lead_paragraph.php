<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use JustHTML\JustHTML;
use JustHTML\Stream;

function lead_parser_available(string $name): bool
{
    switch ($name) {
        case 'justhtml':
        case 'justhtml/stream':
        case 'domdocument':
            return true;
        case 'dom/html-document':
            return class_exists('DOM\\HTMLDocument');
        case 'masterminds/html5':
            return class_exists('Masterminds\\HTML5');
        case 'symfony/dom-crawler':
            return class_exists('Symfony\\Component\\DomCrawler\\Crawler');
        case 'voku/simple_html_dom':
            return class_exists('voku\\helper\\HtmlDomParser');
        default:
            return false;
    }
}

function lead_parser_list(): array
{
    return [
        'justhtml',
        'justhtml/stream',
        'dom/html-document',
        'domdocument',
        'masterminds/html5',
        'voku/simple_html_dom',
        'symfony/dom-crawler',
    ];
}

function normalize_text(string $text): string
{
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text));
    $text = preg_replace('/\s+([.,;:!?])/u', '$1', $text);
    return $text ?? '';
}

function find_element_by_id($doc, string $id)
{
    if (method_exists($doc, 'getElementById')) {
        $el = $doc->getElementById($id);
        if ($el !== null) {
            return $el;
        }
    }
    if (!method_exists($doc, 'getElementsByTagName')) {
        return null;
    }
    $all = $doc->getElementsByTagName('*');
    foreach ($all as $node) {
        if (!is_object($node) || !method_exists($node, 'getAttribute')) {
            continue;
        }
        if (method_exists($node, 'hasAttribute') && $node->hasAttribute('id') && $node->getAttribute('id') === $id) {
            return $node;
        }
        if ($node->getAttribute('id') === $id) {
            return $node;
        }
    }
    return null;
}

function extract_from_dom($doc): string
{
    $container = find_element_by_id($doc, 'mw-content-text');
    if (!$container) {
        return '';
    }
    $paras = $container->getElementsByTagName('p');
    foreach ($paras as $p) {
        $text = normalize_text($p->textContent ?? '');
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function extract_justhtml(string $html): string
{
    $doc = new JustHTML($html);
    foreach ($doc->query('#mw-content-text p') as $p) {
        $text = normalize_text($p->toText());
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function extract_stream(string $html): string
{
    $inContainer = false;
    $containerDepth = 0;
    $capturing = false;
    $hasText = false;
    $segments = [];

    foreach (Stream::stream($html) as [$event, $data]) {
        if ($event === 'start') {
            [$tag, $attrs] = $data;
            $tag = (string)$tag;
            $attrs = is_array($attrs) ? $attrs : [];

            if (!$inContainer) {
                if ($tag === 'div' && (($attrs['id'] ?? '') === 'mw-content-text')) {
                    $inContainer = true;
                    $containerDepth = 1;
                }
                continue;
            }

            $containerDepth += 1;
            if ($tag === 'p') {
                if ($capturing && $hasText) {
                    break;
                }
                $capturing = true;
                $hasText = false;
                $segments = [];
            }
            continue;
        }

        if ($event === 'end') {
            $tag = (string)$data;
            if ($inContainer) {
                $containerDepth -= 1;
                if ($containerDepth <= 0) {
                    $inContainer = false;
                }
            }

            if ($capturing && $tag === 'p') {
                if ($hasText) {
                    break;
                }
                $capturing = false;
                $hasText = false;
                $segments = [];
            }
            continue;
        }

        if ($event === 'text' && $capturing) {
            $text = trim($data);
            if ($text === '') {
                continue;
            }
            $segments[] = $text;
            $hasText = true;
        }
    }

    if (!$segments) {
        return '';
    }

    return normalize_text(implode(' ', $segments));
}

function extract_domdocument(string $html): string
{
    $doc = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return extract_from_dom($doc);
}

function extract_dom_html_document(string $html): string
{
    $prev = libxml_use_internal_errors(true);
    $doc = \DOM\HTMLDocument::createFromString($html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return extract_from_dom($doc);
}

function extract_masterminds(string $html): string
{
    $parser = new \Masterminds\HTML5();
    $doc = $parser->loadHTML($html);
    return extract_from_dom($doc);
}

function extract_domcrawler(string $html): string
{
    $crawler = new \Symfony\Component\DomCrawler\Crawler();
    $crawler->addHtmlContent($html, 'UTF-8');
    $nodes = $crawler->filter('#mw-content-text p');
    foreach ($nodes as $node) {
        $text = normalize_text($node->textContent ?? '');
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function extract_voku(string $html): string
{
    $dom = \voku\helper\HtmlDomParser::str_get_html($html);
    if (!$dom) {
        return '';
    }
    $container = $dom->find('#mw-content-text', 0);
    if ($container === null) {
        return '';
    }
    $nodes = $container->find('p');
    if ($nodes === null) {
        return '';
    }
    if (is_array($nodes)) {
        $iter = $nodes;
    } elseif ($nodes instanceof \Traversable) {
        $iter = $nodes;
    } else {
        $iter = [$nodes];
    }
    foreach ($iter as $node) {
        if ($node === null) {
            continue;
        }
        $raw = $node->plaintext;
        if (is_array($raw)) {
            $raw = implode(' ', $raw);
        }
        $text = normalize_text((string)$raw);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function extract_lead(string $parser, string $html): string
{
    switch ($parser) {
        case 'justhtml':
            return extract_justhtml($html);
        case 'justhtml/stream':
            return extract_stream($html);
        case 'domdocument':
            return extract_domdocument($html);
        case 'dom/html-document':
            return extract_dom_html_document($html);
        case 'masterminds/html5':
            return extract_masterminds($html);
        case 'symfony/dom-crawler':
            return extract_domcrawler($html);
        case 'voku/simple_html_dom':
            return extract_voku($html);
        default:
            return '';
    }
}

/** @return array{avg_ms:float,items:int,seconds:float,text:string} */
function bench_parser(string $parser, string $html, int $iterations): array
{
    $times = [];
    $lastText = '';
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $t0 = microtime(true);
        $lastText = extract_lead($parser, $html);
        $t1 = microtime(true);
        $times[] = ($t1 - $t0) * 1000;
    }

    $elapsed = microtime(true) - $start;
    $avg = array_sum($times) / count($times);

    return [
        'avg_ms' => $avg,
        'items' => $iterations,
        'seconds' => $elapsed,
        'text' => $lastText,
    ];
}

function render_markdown(array $results): void
{
    echo "| Parser | Average time (milliseconds) | Total time (seconds) | Iterations | Test matches with justhtml baseline? |\n";
    echo "|--------|----------------------------:|---------------------:|-----------:|:------------------------------------|\n";
    foreach ($results as $name => $data) {
        if (isset($data['note'])) {
            echo '| ' . $name . ' | - | - | - | ' . $data['note'] . " |\n";
            continue;
        }
        echo '| ' . $name . ' | ' . sprintf('%.2f', $data['avg_ms']) . ' | '
            . sprintf('%.2f', $data['seconds']) . ' | ' . $data['items'] . ' | '
            . ($data['match'] ? 'yes' : 'no') . " |\n";
    }
}

$args = $argv;
array_shift($args);
$selected = [];
$iterations = 5;
$markdown = false;
$file = __DIR__ . '/../examples/fixtures/wikipedia-earth.html';

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--parser') {
        $selected[] = $args[$i + 1] ?? '';
        $i += 1;
        continue;
    }
    if (strpos($arg, '--parser=') === 0) {
        $selected[] = substr($arg, strlen('--parser='));
        continue;
    }
    if ($arg === '--iterations') {
        $iterations = isset($args[$i + 1]) ? (int)$args[$i + 1] : $iterations;
        $i += 1;
        continue;
    }
    if (strpos($arg, '--iterations=') === 0) {
        $iterations = (int)substr($arg, strlen('--iterations='));
        continue;
    }
    if ($arg === '--markdown') {
        $markdown = true;
        continue;
    }
    if ($arg === '--file') {
        $file = $args[$i + 1] ?? $file;
        $i += 1;
        continue;
    }
    if (strpos($arg, '--file=') === 0) {
        $file = substr($arg, strlen('--file='));
        continue;
    }
}

$html = file_get_contents($file);
if ($html === false) {
    fwrite(STDERR, "Failed to read fixture: {$file}\n");
    exit(1);
}

$baseline = extract_justhtml($html);
if ($baseline === '') {
    fwrite(STDERR, "Failed to extract baseline lead paragraph.\n");
    exit(1);
}

$parsers = $selected ?: lead_parser_list();
$results = [];
$mismatches = [];

foreach ($parsers as $parser) {
    if ($parser === '') {
        continue;
    }
    if (!lead_parser_available($parser)) {
        $results[$parser] = ['note' => 'not installed'];
        continue;
    }
    $result = bench_parser($parser, $html, $iterations);
    $text = normalize_text($result['text']);
    $result['match'] = ($text === $baseline);
    $results[$parser] = $result;
    if (!$result['match']) {
        $mismatches[$parser] = $text;
    }
}

if ($markdown) {
    render_markdown($results);
} else {
    foreach ($results as $name => $data) {
        if (isset($data['note'])) {
            echo $name . ': ' . $data['note'] . "\n";
            continue;
        }
        $line = $name . ': ' . sprintf('%.2f', $data['avg_ms']) . ' ms/lead, '
            . sprintf('%.2f', $data['seconds']) . ' s total, '
            . ($data['match'] ? 'match' : 'mismatch');
        echo $line . "\n";
    }
}

if ($mismatches) {
    fwrite(STDERR, "Lead paragraph mismatches detected:\n");
    foreach ($mismatches as $parser => $text) {
        $preview = strlen($text) > 160 ? (substr($text, 0, 160) . '...') : $text;
        fwrite(STDERR, "- {$parser}: {$preview}\n");
    }
    exit(2);
}
