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
- `--no-mem` (skip peak memory delta)
- `--dir <path>` (override fixture path)
- Some dependencies emit PHP 8.5 deprecation warnings; suppress with
  `php -d error_reporting='E_ALL & ~E_DEPRECATED'`.

## Optional parser dependencies

Install any of these to include them in benchmark results:

```sh
composer require --dev masterminds/html5 voku/simple_html_dom symfony/dom-crawler
```

Available parser labels in scripts:

- `justhtml`
- `domdocument`
- `dom/html-document`
- `masterminds/html5`
- `voku/simple_html_dom`
- `symfony/dom-crawler`
