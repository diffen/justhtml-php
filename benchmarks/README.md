# Benchmarks

This directory contains PHP benchmarks modeled after the JustHTML Python suite.

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
php benchmarks/performance.php --iterations 3 --markdown
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
php benchmarks/lead_paragraph.php --iterations 5 --markdown
```

Options:

- `--parser <name>` (repeatable)
- `--iterations <n>`
- `--file <path>` (override fixture path)
- The script exits non-zero if any parser output does not match the baseline.
- Use `--parser justhtml/stream` to include the event-streaming extractor.

Additional results for other PHP versions (5-run average; PHP 8.4 results live in the main README):

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
- `justhtml/stream` (lead paragraph benchmark only)
- `domdocument`
- `dom/html-document`
- `masterminds/html5`
- `voku/simple_html_dom`
- `symfony/dom-crawler`
