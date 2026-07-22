# Benchmarks

This directory contains PHP benchmarks modeled after the JustHTML Python suite.

## What the JustHTML benchmark labels mean

| Label | What it represents | What it does not represent |
|---|---|---|
| `justhtml` | The recommended full parser followed by a normal CSS query | An early-exit path |
| `justhtml/select` | The public targeted-extraction API, stopped after the first acceptable result | The full selector API or a good fit for every workload |
| `justhtml/events` | A custom `Stream::events()` scanner written specifically for this lead-paragraph benchmark | A general selector or replacement for parsing |

The lead-paragraph benchmark is the product-relevant comparison. On the PHP
8.5.8 run from 2026-07-22, `justhtml/select` averaged 25.75 ms versus 56.44 ms
for the full JustHTML parser; the benchmark-specific event scanner averaged
6.75 ms. All produced the same output on that fixture. The relevant product
claim is deliberately narrow: targeted extraction can save time and parser
memory when a crawler needs a few early matches, while returning the same
result as a normal query for the supported selector set.

## Correctness (html5lib tree tests)

Runs the html5lib tree-construction tests and reports pass rate per parser.

```sh
git submodule update --init --recursive
php benchmarks/correctness.php --markdown
```

Options:

- `--parser <name>` (repeatable)
- `--limit <n>` (limit total tests)
- `--dir <path>` (override html5lib test path)
- If a parser is very slow, use `--limit` and note the sample size.

## Performance

Runs a simple parse-time benchmark on HTML fixtures in `benchmarks/fixtures`.

```sh
php -dxdebug.mode=off -dopcache.enable_cli=1 \
  -d 'error_reporting=E_ALL & ~E_DEPRECATED' \
  benchmarks/performance.php --iterations 3 --markdown
```

Options:

- `--parser <name>` (repeatable)
- `--iterations <n>`
- `--no-rss` (skip max RSS measurement)
- `--dir <path>` (override fixture path)
- Note: `masterminds/html5` emits PHP 8.5 deprecation warnings (ord on empty
  string); suppress with `php -d error_reporting='E_ALL & ~E_DEPRECATED'`.
- Max RSS uses `getrusage()` from the child process (maximum resident set size)
  and may show `n/a` if the metric is unavailable on the host OS.

## Lead paragraph extraction (Wikipedia fixture)

Benchmarks parse + extract of the first non-empty paragraph under
`#mw-content-text` from the Wikipedia Earth fixture:

```sh
php -dxdebug.mode=off -dopcache.enable_cli=1 \
  benchmarks/lead_paragraph.php --iterations 5 --markdown
```

Options:

- `--parser <name>` (repeatable)
- `--iterations <n>`
- `--file <path>` (override fixture path)
- The script exits non-zero if any parser output does not match the baseline.
- Use `--parser justhtml/events` to include the custom, benchmark-specific event
  scanner.
- Use `--parser justhtml/select` to benchmark targeted extraction.

### Historical labels and design-spike data

The rejected approach B prototype is not included in current README results or
current benchmark labels. Its measurements and archived source location remain
in the historical
[design-spike report](../docs/spike-streaming-select/report.md).

The historical per-version results below predate `Stream::select()` and compare
the full DOM with the event extractor. They retain the old `justhtml/stream`
label used when those measurements were recorded; the current label is
`justhtml/events`. Current public selector results are shown in the main README
and can be regenerated with the command above.

Additional historical results (5-run average; PHP 8.4 results live in the main README):

### PHP 7.4

| Parser | Average time (milliseconds) | Total time (seconds) | Iterations |
|--------|----------------------------:|---------------------:|-----------:|
| justhtml | 119.96 | 0.60 | 5 |
| justhtml/stream | 14.52 | 0.07 | 5 |
| dom/html-document | not installed | - | - |
| domdocument | 17.78 | 0.09 | 5 |
| masterminds/html5 | 601.78 | 3.01 | 5 |
| voku/simple_html_dom | 179.34 | 0.90 | 5 |
| symfony/dom-crawler | 87.23 | 0.44 | 5 |

### PHP 8.0

| Parser | Average time (milliseconds) | Total time (seconds) | Iterations |
|--------|----------------------------:|---------------------:|-----------:|
| justhtml | 110.70 | 0.55 | 5 |
| justhtml/stream | 13.03 | 0.07 | 5 |
| dom/html-document | not installed | - | - |
| domdocument | 17.75 | 0.09 | 5 |
| masterminds/html5 | 565.59 | 2.83 | 5 |
| voku/simple_html_dom | 188.97 | 0.94 | 5 |
| symfony/dom-crawler | 86.80 | 0.43 | 5 |

### PHP 8.1

| Parser | Average time (milliseconds) | Total time (seconds) | Iterations |
|--------|----------------------------:|---------------------:|-----------:|
| justhtml | 108.82 | 0.54 | 5 |
| justhtml/stream | 12.32 | 0.06 | 5 |
| dom/html-document | not installed | - | - |
| domdocument | 16.11 | 0.08 | 5 |
| masterminds/html5 | 613.56 | 3.07 | 5 |
| voku/simple_html_dom | 359.35 | 1.80 | 5 |
| symfony/dom-crawler | 103.70 | 0.52 | 5 |

### PHP 8.2

| Parser | Average time (milliseconds) | Total time (seconds) | Iterations |
|--------|----------------------------:|---------------------:|-----------:|
| justhtml | 107.85 | 0.54 | 5 |
| justhtml/stream | 12.63 | 0.06 | 5 |
| dom/html-document | not installed | - | - |
| domdocument | 15.66 | 0.08 | 5 |
| masterminds/html5 | 557.65 | 2.79 | 5 |
| voku/simple_html_dom | 357.04 | 1.79 | 5 |
| symfony/dom-crawler | 81.40 | 0.41 | 5 |

### PHP 8.3

| Parser | Average time (milliseconds) | Total time (seconds) | Iterations |
|--------|----------------------------:|---------------------:|-----------:|
| justhtml | 105.02 | 0.53 | 5 |
| justhtml/stream | 12.07 | 0.06 | 5 |
| dom/html-document | not installed | - | - |
| domdocument | 15.73 | 0.08 | 5 |
| masterminds/html5 | 82.82 | 0.41 | 5 |
| voku/simple_html_dom | 356.29 | 1.78 | 5 |
| symfony/dom-crawler | 81.52 | 0.41 | 5 |

### PHP 8.5

| Parser | Average time (milliseconds) | Total time (seconds) | Iterations |
|--------|----------------------------:|---------------------:|-----------:|
| justhtml | 101.05 | 0.51 | 5 |
| justhtml/stream | 11.34 | 0.06 | 5 |
| dom/html-document | 11.14 | 0.06 | 5 |
| domdocument | 15.35 | 0.08 | 5 |
| masterminds/html5 | 75.40 | 0.38 | 5 |
| voku/simple_html_dom | 361.09 | 1.81 | 5 |
| symfony/dom-crawler | 72.98 | 0.36 | 5 |

## Optional parser dependencies

Install any of these to include them in benchmark results:

```sh
composer require --dev masterminds/html5 voku/simple_html_dom symfony/dom-crawler
```

## Common Crawl fixtures (real-world, 1k docs)

Use Common Crawl index + WARC range requests to build a 1,000-document HTML
fixture set:

```sh
php benchmarks/fetch_commoncrawl.php --count 1000 --out-dir benchmarks/fixtures/commoncrawl-1k
```

Run performance benchmarks against the extracted dataset:

```sh
php benchmarks/performance.php --dir benchmarks/fixtures/commoncrawl-1k --iterations 1 --markdown
```

Notes:

- Requires network access and can take a few minutes.
- Uses `curl` if available; otherwise falls back to `file_get_contents`.
- Re-running the script resumes from the next numeric fixture ID.
- Results depend on the Common Crawl index and may vary by crawl.

Available parser labels in scripts:

- `justhtml`
- `justhtml/select` (lead paragraph benchmark only)
- `justhtml/events` (lead paragraph benchmark only)
- `justhtml/stream` (deprecated alias for `justhtml/events`)
- `domdocument`
- `dom/html-document`
- `masterminds/html5`
- `voku/simple_html_dom`
- `symfony/dom-crawler`
