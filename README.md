# JustHTML for PHP

PHP HTML5 parser ported from [Emil Stenström's JustHTML](https://github.com/EmilStenstrom/justhtml),
targeting PHP 7.4+. Inspired by [Simon Willison's JavaScript port, justjshtml](https://github.com/simonw/justjshtml).
It is built for correctness and passes the html5lib test suite (tree builder,
tokenizer, serializer, and encoding tests). Scripted fixtures in the suite are
intentionally skipped.

## Requirements

- PHP 7.4+

## Installation

```sh
composer require diffen/justhtml-php
```

## Quickstart

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use JustHTML\JustHTML;

$doc = new JustHTML('<main><p>Hello</p></main>');
echo $doc->toHtml();
```

## Features

- HTML5-compliant parsing with html5lib test coverage
- DOM-like tree with HTML serialization and text extraction
- CSS selectors with `query()` and `matches()`
- Markdown conversion via `toMarkdown()`
- Streaming tokenizer API
- Fragment parsing and strict error mode

## Comparison to other parsers

Compliance results are based on the html5lib tree-construction tests. Run
`php benchmarks/correctness.php --markdown` to regenerate.

| Parser | Tests Passed | Compliance | Notes |
|--------|-------------|------------|-------|
| **JustHTML** | 1743/1743 | **100%** | Full spec compliance |
| DOMDocument (libxml) | 54/1743 | 3.1% | Baseline, not HTML5-correct |
| masterminds/html5 | TBD | TBD | Install dependency and run benchmarks |
| voku/simple_html_dom | TBD | TBD | Install dependency and run benchmarks |
| paquettg/php-html-parser | TBD | TBD | Install dependency and run benchmarks |
| symfony/dom-crawler | TBD | TBD | Wrapper over DOMDocument (libxml) |

Performance benchmarks are in `benchmarks/performance.php` and use HTML fixtures
from `benchmarks/fixtures`. See `benchmarks/README.md` for details.

## CSS selectors

```php
$nodes = $doc->query('main p');
```

## Markdown

```php
$markdown = $doc->toMarkdown();
```

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

## Tests

```sh
git submodule update --init --recursive
php run_tests.php
```

## Notes

- This repo includes a copy of html5lib tests in `html5lib-tests`.
- For non-Composer use, include `src/JustHTML/*.php` with your own autoloader.
- Ported from Emil Stenström's JustHTML (Python) under the MIT License.
- Inspired by Simon Willison's justjshtml port.
