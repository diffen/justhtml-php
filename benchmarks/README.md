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
- `domdocument`
- `dom/html-document`
- `masterminds/html5`
- `voku/simple_html_dom`
- `symfony/dom-crawler`
