# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and the project adheres to
Semantic Versioning.

## [Unreleased]

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
