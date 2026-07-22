# JustHTML for PHP

PHP HTML5 parser ported from [Emil Stenström's JustHTML](https://github.com/EmilStenstrom/justhtml),
targeting PHP 7.4+. Inspired by [Simon Willison's JavaScript port, justjshtml](https://github.com/simonw/justjshtml).
It is built for correctness and passes the html5lib test suite (tree builder,
tokenizer, serializer, and encoding tests).

## Why use JustHTML?

If you are on PHP 8.4+ and need to process a complete document, start with
PHP's built-in
[`DOM\\HTMLDocument`](https://www.php.net/manual/en/class.dom-htmldocument.php).
Its C implementation is faster and is a better fit for many projects.

Choose JustHTML when you need one or more of these:

- Pure PHP support across PHP 7.4 through 8.5.
- Browser-like handling of malformed HTML.
- CSS selectors plus HTML, text, and Markdown extraction helpers.
- Targeted extraction of a few known elements without always parsing and
  retaining the complete document.

For most tasks, start with the full parser shown below. The
[parsing guide](Streaming.md) explains when targeted extraction is worthwhile.

## Requirements

- PHP 7.4+ (tested with 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5)
- `ext-mbstring`
- `ext-iconv`

## Installation

```sh
composer require diffen/justhtml
```

Non-Composer use: include `src/JustHTML/*.php` with your autoloader and keep `data/` alongside `src/`.

CLI via Homebrew (6.0.0+ requires a one-time trust step, which lets Homebrew run this MIT-licensed formula's install code):

```sh
brew trust --formula diffen/justhtml/justhtml
brew install diffen/justhtml/justhtml
```

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

## CSS selectors

Input HTML:

```html
<main><p>Hello</p><a href="/a">Read</a></main>
```

```php
$nodes = $doc->query('main p'); // CSS selector query; returns matching nodes
$first = $doc->queryFirst('main p'); // First match or null
echo $nodes[0]->toText() . "\n";
echo ($nodes[0]->matches('main > p') ? 'true' : 'false') . "\n"; // CSS selector match on this node
echo ($nodes[0]->matches('article p') ? 'true' : 'false') . "\n"; // true/false based on selector match
```

Expected output:

```text
Hello
true
false
```

Multiple selectors (comma-separated):

```php
$nodes = $doc->query('main p, main a'); // Multiple selectors
echo count($nodes) . "\n";
```

Expected output:

```text
2
```

## Markdown

```php
$markdown = $doc->toMarkdown();
```

Note: `toMarkdown()` is a convenience helper (best-effort conversion) and not
part of the HTML specification.

## Detailed example (Wikipedia Earth)

```sh
php examples/wikipedia_earth_demo.php
```

The script loads `examples/fixtures/wikipedia-earth.html` and prints a
walkthrough of selectors, attributes, classes, inner/outer HTML, text, and
Markdown extraction.

## Choosing a parsing API

For most crawling tasks, use the full parser shown in the quickstart. Choose
`Stream::select()` only when you need one or a few known matches and want to
avoid unnecessary work after finding them. Stop iteration to stop parsing:

```php
use JustHTML\Stream;

foreach (Stream::select($html, '#mw-content-text p') as $paragraph) {
    $text = $paragraph->toText();
    if ($text !== '') {
        echo $text;
        break;
    }
}

$title = Stream::selectFirst($html, 'main > h1');
```

This optimized path trades flexibility for lower work and memory: it supports a
documented subset of CSS selectors, may offer little benefit when a match is
late or absent, and returns nodes that should be treated as read-only while the
generator is active. Like the full parser, it accepts a complete in-memory HTML
string or byte buffer; it does not stream a network response or file in chunks.

See [Streaming.md](Streaming.md) for a task-based decision guide, selector
support, risks, encoding options, and the lower-level event API for specialized
scanners.

## CLI

```sh
php bin/justhtml page.html --selector "main p" --format text
```

For more detailed examples, see [CLI.md](CLI.md).

For basic first-result extractions, the CLI automatically chooses a
significantly faster parsing method when appropriate.

More examples:

```sh
# Extract the first non-empty paragraph from the Wikipedia Earth fixture.
php bin/justhtml examples/fixtures/wikipedia-earth.html \
  --selector '#mw-content-text p:not(:empty)' --format text --first

# Pipe HTML via stdin and extract markdown from an article.
curl -s https://example.com | php bin/justhtml - --selector "article" --format markdown

# Preserve whitespace when extracting text.
php bin/justhtml page.html --selector "pre" --format text --no-strip --separator ""
```

## Comparison to other parsers

Compliance results use the html5lib tree-construction tests. Performance
results use a 1,000-document Common Crawl fixture set and report average
milliseconds per document (lower is better). Compliance was verified and
performance rerun on 2026-07-22 using PHP 8.5.8 with libxml 2.9.13.

| Parser | Spec compliance (html5lib-tests pass rate) | Speed: Avg ms/doc (lower is better) | Selectors | Notes |
|--------|---------------------------------------------|------------------------------------:|-----------|-------|
| **JustHTML** | 1770/1770 (**100%**) | 4.03 | CSS | Full spec compliance |
| DOM\HTMLDocument | 873/1770 (49.3%) | **0.45** | CSS | PHP built-in DOM extension (DOM\\HTMLDocument; HTML5 parser in PHP 8.4+, C implementation) |
| DOMDocument (libxml) | 54/1770 (3.1%) | 0.77 | XPath | Legacy HTML parser (libxml2), not HTML5-correct |
| masterminds/html5 | 75/1770 (4.2%) | 3.50 | XPath | HTML5 parser, low compliance |
| voku/simple_html_dom | 29/1770 (1.6%) | 2.08 | CSS | Lenient DOM wrapper, low compliance |
| symfony/dom-crawler | 54/1770 (3.1%) | 3.38 | CSS/XPath | Wrapper over DOMDocument (libxml) |

See the [benchmark guide](benchmarks/README.md) for dependencies, reproduction
commands, methodology, and historical results.

## Lead paragraph extraction benchmark

This benchmark parses the bundled Wikipedia Earth fixture and extracts its
first non-empty lead paragraph.

Example results (PHP 8.5.8, rerun 2026-07-22, 5-run average; all outputs match
the JustHTML baseline):

| Parser | Average time (milliseconds) | Total time (seconds) | Iterations |
|--------|----------------------------:|---------------------:|-----------:|
| **JustHTML** | 56.44 | 0.28 | 5 |
| **JustHTML (`Stream::select`)** | 25.75 | 0.13 | 5 |
| DOM\HTMLDocument | 6.90 | 0.03 | 5 |
| DOMDocument (libxml) | 9.94 | 0.05 | 5 |
| masterminds/html5 | 45.89 | 0.23 | 5 |
| voku/simple_html_dom | 116.09 | 0.58 | 5 |
| symfony/dom-crawler | 46.64 | 0.23 | 5 |

For this selective early-result workload, `Stream::select()` was 2.19× faster
than JustHTML's full-parser path and used 99.3% fewer retained parser nodes in a
controlled memory proxy. The complete input string remains in memory with both
APIs. These gains are most relevant when a crawler needs a small number of
early matches; late, absent, or broad matches can erase the advantage. See the
[benchmark guide](benchmarks/README.md) for the protocol and detailed results.

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
- Most of this work was done by GPT-5.2-Codex, with a little help from Claude Opus 4.5.
