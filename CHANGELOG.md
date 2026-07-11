# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and the project adheres to
Semantic Versioning.

## [Unreleased]

## [0.1.4] - 2026-07-10

- Fix foreign-content (SVG/MathML) serialization and integration-point
  tokenization in both the full parser and the streaming API.
- Preserve document text exactly in pretty-printed HTML output.
- Fix CSS selector escape parsing, functional pseudo-class arguments, and
  descendant-combinator backtracking correctness.
- Report parse error positions as 1-based character line/columns, consistent
  across LF/CR/CRLF and multibyte input; strip a leading UTF-8 BOM.
- Fix fragment-context tokenizer states (RCDATA/rawtext) and DOM mutation
  validation ordering.
- Performance: bounded entity lookahead, memoized selector matching,
  per-parent structural pseudo-class indexes, linear fragment finalization,
  faster streaming and compact serialization.
- Run script-off html5lib tree tests (1770 passing); `run_tests.php` now
  exits nonzero on failure.

## [0.1.3] - 2026-02-03

- Document Homebrew install and expanded CLI examples (piping, selectors).
- Add Docker CLI matrix smoke tests.

## [0.1.2] - 2026-02-02

- Add `--count` to print the number of matching nodes.
- Add `--inner`/`--outer` to control HTML output.
- Add `--limit` to return the first N matches.
- Add `--attr`/`--missing` for attribute extraction.

## [0.1.1] - 2026-02-02

- CLI --version now reports the package version (Composer or VERSION file).

## [0.1.0] - 2025-01-13

Initial release.

- HTML5 parser ported from JustHTML (Python), targeting PHP 7.4+
- html5lib tree, tokenizer, serializer, and encoding tests passing
- CSS selector engine with `query()` and `matches()`
- Markdown conversion helpers
- Streaming tokenizer API
- CLI entrypoint
- Composer packaging and examples
