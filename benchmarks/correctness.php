<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/html5lib.php';
require_once __DIR__ . '/dom_serializer.php';

use JustHTML\FragmentContext;
use JustHTML\JustHTML;
use JustHTML\TokenizerOpts;

function parser_available(string $name): bool
{
    switch ($name) {
        case 'justhtml':
        case 'domdocument':
        case 'dom/html-document':
            return true;
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

/** @return array{0:string,1:?string} */
function parser_note(string $name): array
{
    if (parser_available($name)) {
        return ['', null];
    }
    return ['not installed', 'not installed'];
}

function parse_with_justhtml(string $html, ?FragmentContext $fragment_context, bool $xml_coercion, bool $iframe_srcdoc): string
{
    $opts = new TokenizerOpts(false, true, null, null, $xml_coercion);
    $parser = new JustHTML($html, [
        'fragment_context' => $fragment_context,
        'tokenizer_opts' => $opts,
        'iframe_srcdoc' => $iframe_srcdoc,
    ]);
    return $parser->toTestFormat();
}

function domdocument_from_html(string $html, ?FragmentContext $fragment_context): \DOMNode
{
    $doc = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    if ($fragment_context !== null) {
        $tag = $fragment_context->tagName;
        $wrapped = '<' . $tag . '>' . $html . '</' . $tag . '>';
        $doc->loadHTML($wrapped);
        $container = $doc->getElementsByTagName($tag)->item(0);
        $fragment = $doc->createDocumentFragment();
        if ($container) {
            foreach ($container->childNodes as $child) {
                $fragment->appendChild($child->cloneNode(true));
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $fragment;
    }
    $doc->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

function parse_with_domdocument(string $html, ?FragmentContext $fragment_context): string
{
    $node = domdocument_from_html($html, $fragment_context);
    return DomTestSerializer::toTestFormat($node);
}

function dom_html_document_from_html(string $html, ?FragmentContext $fragment_context)
{
    if (!class_exists('DOM\\HTMLDocument')) {
        return domdocument_from_html($html, $fragment_context);
    }
    $prev = libxml_use_internal_errors(true);
    if ($fragment_context !== null) {
        $tag = $fragment_context->tagName;
        $wrapped = '<' . $tag . '>' . $html . '</' . $tag . '>';
        $doc = \DOM\HTMLDocument::createFromString($wrapped);
        $container = $doc->getElementsByTagName($tag)->item(0);
        $fragment = $doc->createDocumentFragment();
        if ($container) {
            foreach ($container->childNodes as $child) {
                $fragment->appendChild($child->cloneNode(true));
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $fragment;
    }
    $doc = \DOM\HTMLDocument::createFromString($html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

function parse_with_dom_html_document(string $html, ?FragmentContext $fragment_context): string
{
    $node = dom_html_document_from_html($html, $fragment_context);
    return DomTestSerializer::toTestFormat($node);
}

function parse_with_masterminds(string $html, ?FragmentContext $fragment_context): string
{
    $html5 = new \Masterminds\HTML5();
    if ($fragment_context !== null) {
        if (method_exists($html5, 'loadHTMLFragment')) {
            $fragment = $html5->loadHTMLFragment($html);
            return DomTestSerializer::toTestFormat($fragment);
        }
        if (method_exists($html5, 'parseFragment')) {
            $fragment = $html5->parseFragment($html);
            return DomTestSerializer::toTestFormat($fragment);
        }
    }
    $doc = $html5->loadHTML($html);
    return DomTestSerializer::toTestFormat($doc);
}

function parse_with_domcrawler(string $html, ?FragmentContext $fragment_context): string
{
    if (!class_exists('Symfony\\Component\\DomCrawler\\Crawler')) {
        return parse_with_domdocument($html, $fragment_context);
    }
    $crawler = new \Symfony\Component\DomCrawler\Crawler();
    $crawler->addHtmlContent($html, 'UTF-8');
    $node = $crawler->getNode(0);
    if ($fragment_context !== null) {
        return parse_with_domdocument($html, $fragment_context);
    }
    if ($node && $node->ownerDocument instanceof \DOMDocument) {
        return DomTestSerializer::toTestFormat($node->ownerDocument);
    }
    return parse_with_domdocument($html, null);
}

function parse_with_voku(string $html, ?FragmentContext $fragment_context): string
{
    $dom = \voku\helper\HtmlDomParser::str_get_html($html);
    if (is_object($dom)) {
        if (method_exists($dom, 'getDomDocument')) {
            $doc = $dom->getDomDocument();
            if ($doc instanceof \DOMDocument) {
                return DomTestSerializer::toTestFormat($doc);
            }
        }
        if (method_exists($dom, 'getDocument')) {
            $doc = $dom->getDocument();
            if ($doc instanceof \DOMDocument) {
                return DomTestSerializer::toTestFormat($doc);
            }
            if ($doc instanceof \DOMNode) {
                return DomTestSerializer::toTestFormat($doc);
            }
        }
        if ($dom instanceof \DOMNode) {
            return DomTestSerializer::toTestFormat($dom);
        }
    }
    throw new RuntimeException('voku/simple_html_dom adapter could not access a DOM document');
}

function parser_list(): array
{
    return [
        'justhtml',
        'domdocument',
        'dom/html-document',
        'masterminds/html5',
        'voku/simple_html_dom',
        'symfony/dom-crawler',
    ];
}

/** @return array{passed:int,failed:int,skipped:int,errors:int} */
function run_correctness(string $parser_name, string $dir, ?int $limit = null): array
{
    $files = glob($dir . '/*.dat');
    sort($files);
    $passed = 0;
    $failed = 0;
    $skipped = 0;
    $errors = 0;
    $seen = 0;

    foreach ($files as $file) {
        $tests = parse_dat_file($file);
        foreach ($tests as $test) {
            if ($test['script_directive'] !== null) {
                $skipped += 1;
                continue;
            }
            if ($limit !== null && $seen >= $limit) {
                break 2;
            }
            $seen += 1;
            try {
                if ($parser_name === 'justhtml') {
                    $actual = parse_with_justhtml($test['data'], $test['fragment_context'], $test['xml_coercion'], $test['iframe_srcdoc']);
                } elseif ($parser_name === 'domdocument') {
                    $actual = parse_with_domdocument($test['data'], $test['fragment_context']);
                } elseif ($parser_name === 'dom/html-document') {
                    $actual = parse_with_dom_html_document($test['data'], $test['fragment_context']);
                } elseif ($parser_name === 'masterminds/html5') {
                    $actual = parse_with_masterminds($test['data'], $test['fragment_context']);
                } elseif ($parser_name === 'symfony/dom-crawler') {
                    $actual = parse_with_domcrawler($test['data'], $test['fragment_context']);
                } elseif ($parser_name === 'voku/simple_html_dom') {
                    $actual = parse_with_voku($test['data'], $test['fragment_context']);
                } else {
                    throw new RuntimeException('Unknown parser: ' . $parser_name);
                }
            } catch (\Throwable $e) {
                $failed += 1;
                $errors += 1;
                continue;
            }
            if (compare_outputs($test['document'], $actual)) {
                $passed += 1;
            } else {
                $failed += 1;
            }
        }
    }

    return [
        'passed' => $passed,
        'failed' => $failed,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

function format_percent(int $passed, int $total): string
{
    if ($total === 0) {
        return '0%';
    }
    $value = ($passed / $total) * 100;
    return sprintf('%.1f%%', $value);
}

function render_markdown(array $results): void
{
    echo "| Parser | Tests Passed | Compliance | Notes |\n";
    echo "|--------|-------------|------------|-------|\n";
    foreach ($results as $name => $data) {
        if (isset($data['note']) && $data['note'] !== '') {
            echo '| ' . $name . ' | - | - | ' . $data['note'] . " |\n";
            continue;
        }
        $total = $data['passed'] + $data['failed'];
        $passed = $data['passed'];
        $percent = format_percent($passed, $total);
        $notes = [];
        if ($data['skipped'] > 0) {
            $notes[] = 'skipped ' . $data['skipped'];
        }
        if ($data['errors'] > 0) {
            $notes[] = 'errors ' . $data['errors'];
        }
        $noteText = $notes ? implode(', ', $notes) : '';
        echo '| ' . $name . ' | ' . $passed . '/' . $total . ' | ' . $percent . ' | ' . $noteText . " |\n";
    }
}

$args = $argv;
array_shift($args);
$selected = [];
$limit = null;
$markdown = false;
$dir = __DIR__ . '/../html5lib-tests/tree-construction';

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
    if ($arg === '--limit') {
        $limit = isset($args[$i + 1]) ? (int)$args[$i + 1] : null;
        $i += 1;
        continue;
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, strlen('--limit='));
        continue;
    }
    if ($arg === '--markdown') {
        $markdown = true;
        continue;
    }
    if (strpos($arg, '--dir=') === 0) {
        $dir = substr($arg, strlen('--dir='));
        continue;
    }
    if ($arg === '--dir') {
        $dir = $args[$i + 1] ?? $dir;
        $i += 1;
        continue;
    }
}

$parsers = $selected ?: parser_list();
$results = [];

foreach ($parsers as $parser) {
    if ($parser === '') {
        continue;
    }
    if (!parser_available($parser)) {
        $results[$parser] = ['note' => 'not installed'];
        continue;
    }
    $results[$parser] = run_correctness($parser, $dir, $limit);
}

if ($markdown) {
    render_markdown($results);
    exit(0);
}

foreach ($results as $name => $data) {
    if (isset($data['note'])) {
        echo $name . ': ' . $data['note'] . "\n";
        continue;
    }
    $total = $data['passed'] + $data['failed'];
    $percent = format_percent($data['passed'], $total);
    echo $name . ': ' . $data['passed'] . '/' . $total . ' (' . $percent . ")\n";
}
