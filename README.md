# JustHTML for PHP

PHP HTML5 parser ported from [Emil Stenström's JustHTML](https://github.com/EmilStenstrom/justhtml),
targeting PHP 7.4+. Inspired by [Simon Willison's JavaScript port, justjshtml](https://github.com/simonw/justjshtml).
It is built for correctness and passes the html5lib test suite (tree builder,
tokenizer, serializer, and encoding tests).

## Why use JustHTML?

### 1. Correct HTML5 parsing

- Passes the html5lib test suite (tree builder, tokenizer, serializer, encoding).
- Mirrors browser error handling for malformed HTML.

### 2. PHP-native and portable

- Pure PHP 7.4+ with no extensions required.
- Easy to debug and vendor in any PHP project.

### 3. Query and output utilities

- CSS selectors via `query()` and `matches()`.
- HTML, text, and Markdown output helpers for common workflows.

## Features

- HTML5-compliant parsing with html5lib test coverage
- DOM-like tree with HTML serialization and text extraction
- CSS selectors with `query()` and `matches()`
- Markdown conversion via `toMarkdown()`
- Streaming tokenizer API
- Fragment parsing and strict error mode

## Requirements

- PHP 7.4+ (tested with 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5)

## Installation

```sh
composer require diffen/justhtml
```

Non-Composer use: include `src/JustHTML/*.php` with your autoloader and keep `data/` alongside `src/`.

## Quickstart

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use JustHTML\JustHTML;

$html = '<article><h1>Title</h1><p class="lead">Intro</p><a href="/a">Read</a></article>';
$doc = new JustHTML($html);

$title = $doc->query('h1')[0]->toText();
$lead = $doc->query('p.lead')[0]->toText();
$link = $doc->query('a')[0]->attrs['href'] ?? '';

echo $title . "\n";
echo $lead . "\n";
echo $link . "\n";
```

Expected output:

```text
Title
Intro
/a
```

## Detailed example (Wikipedia Earth)

```sh
php examples/wikipedia_earth_demo.php
```

The script loads `benchmarks/fixtures/wikipedia-earth.html` and prints a
walkthrough of selectors, attributes, classes, inner/outer HTML, text, and
Markdown extraction.

## CSS selectors

```php
$nodes = $doc->query('main p'); // Returns an array of nodes
echo $nodes[0]->toText() . "\n";
echo ($nodes[0]->matches('main > p') ? 'true' : 'false') . "\n";
echo ($nodes[0]->matches('article p') ? 'true' : 'false') . "\n";
```

Expected output:

```text
Hello
true
false
```

## Markdown

```php
$markdown = $doc->toMarkdown();
```

Note: `toMarkdown()` is a convenience helper (best-effort conversion) and not
part of the HTML specification.

## Streaming

```php
use JustHTML\Stream;

foreach (Stream::stream($html) as [$event, $data]) {
    // $event is "start", "end", "text", "comment", or "doctype"
}
```

## CLI

```sh
php bin/justhtml page.html --selector "main p" --format text
```

## Comparison to other parsers

Compliance results are based on the html5lib tree-construction tests. Performance
results are from `benchmarks/performance.php` using a Common Crawl 1,000-document
fixture set (avg ms/doc: lower is better). Generate fixtures with
`php benchmarks/fetch_commoncrawl.php` and run with
`--dir benchmarks/fixtures/commoncrawl-1k --iterations 3`.

Benchmarks here were run on PHP 8.5.1 with libxml 2.9.13. Run
`php benchmarks/correctness.php --markdown` and
`php benchmarks/performance.php --dir benchmarks/fixtures/commoncrawl-1k --iterations 3 --markdown`
to regenerate.

| Parser | Spec compliance (html5lib-tests pass rate) | Speed: Avg ms/doc (lower is better) | Selectors | Notes |
|--------|---------------------------------------------|------------------------------------:|-----------|-------|
| **JustHTML** | 1743/1743 (100%) | 7.3 | CSS | Full spec compliance |
| DOM\HTMLDocument | 831/1743 (47.7%) | 0.8 | CSS | Native ext/dom HTML5 DOM (PHP 8.4+) |
| DOMDocument (libxml) | 54/1743 (3.1%) | 1.2 | XPath | Legacy HTML parser (libxml2), not HTML5-correct |
| masterminds/html5 | 75/1743 (4.3%) | 5.8 | XPath | HTML5 parser, low compliance |
| voku/simple_html_dom | 29/1743 (1.7%) | 5.0 | CSS | Lenient DOM wrapper, low compliance |
| symfony/dom-crawler | 54/1743 (3.1%) | 5.5 | CSS/XPath | Wrapper over DOMDocument (libxml) |

See `benchmarks/README.md` for parser install instructions and details.

## Tests

```sh
git submodule update --init --recursive
php run_tests.php
```

Test against multiple PHP versions via Docker:

```sh
scripts/test-matrix-docker.sh
```

## Notes

- This repo includes a copy of html5lib tests in `html5lib-tests`.
- Ported from Emil Stenström's JustHTML (Python) under the MIT License.
- Inspired by Simon Willison's justjshtml port.
