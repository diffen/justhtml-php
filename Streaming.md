# Choosing a parsing path

Most users should choose between the full parser and targeted selector
extraction. A lower-level event API for specialized scanners is documented at
the end of this guide.

All of these APIs accept a complete HTML string or byte buffer. “Streaming” here
means that parsing can stop early and avoid retaining unnecessary document
data; it does not mean that JustHTML reads a network response or file in chunks.

## Quick recommendation

If you are unsure, use `new JustHTML(...)` and `query()`.

| Path | Choose it when | Benefits | Costs and risks |
|---|---|---|---|
| **Full parser (default):** `new JustHTML(...)` | You need several fields, multiple or complex selectors, surrounding context, traversal, or mutation | Simplest application code; complete selector and document APIs; reliable handling of malformed pages | Parses and retains the whole document; usually more work than an early targeted match |
| **Targeted extraction:** `Stream::select()` | You need one or a few known elements, especially matches that usually occur early | Same results as normal `query()` for supported selectors; can stop early and retain much less data | Smaller selector set; late, missing, or broad matches may save little or be slower; returned nodes have limited surrounding context |

For common crawling jobs:

- Extracting a title, canonical URL, article body, author, and several metadata
  fields: use the **full parser**.
- Extracting the first product price or lead paragraph from a known location:
  consider **`Stream::select()`**.

### Performance at a glance

The clearest benefit appears when the desired element is selective, occurs
early, and the crawler stops after finding it. For the first non-empty lead
paragraph in the bundled Wikipedia fixture:

| Measure | Full parser | `Stream::select()` | Difference |
|---|---:|---:|---:|
| Parse and extract time¹ | 56.44 ms | 25.75 ms | 2.19× faster (54% less time) |
| Retained parser nodes² | 24,298 | 172 | 141× fewer (99.3% fewer nodes) |

¹ PHP 8.5.8, five-run average, rerun 2026-07-22.

² A memory proxy from the same extraction scenario. It measures parser data,
not total process memory; the complete input string remains in memory with
either API.

Treat these as an example, not a general multiplier. A late or missing match,
a broad selector, or consuming every result can remove the advantage and may
make targeted extraction slower than the full parser.

## Default: parse the complete document

The full parser is the recommended starting point because it keeps all document
context available and supports the complete JustHTML selector API:

```php
use JustHTML\JustHTML;

$doc = new JustHTML($html);
$title = $doc->queryFirst('h1');
$links = $doc->query('main a[href]');
```

Optimize to `Stream::select()` only when profiling shows that full-page parsing
or retention matters and the task fits the supported selector subset.

## Targeted CSS extraction: `Stream::select()`

Use targeted extraction when you need a small part of a document and want the
same malformed-HTML handling as the full parser without always retaining and
finishing the whole page.

```php
use JustHTML\Stream;

foreach (Stream::select($html, '#mw-content-text p') as $paragraph) {
    $text = $paragraph->toText();
    if ($text !== '') {
        echo $text;
        break; // abandoning the generator stops parsing
    }
}

$title = Stream::selectFirst($html, 'main > h1'); // first match or null
```

The targeted APIs accept either text or a byte buffer:

```php
Stream::select($html, string $selector, ?string $encoding = null, bool $bytes = false): Generator
Stream::selectFirst($html, string $selector, ?string $encoding = null, bool $bytes = false): ?SimpleDomNode
```

When `$bytes` is true, the input is decoded to UTF-8 using `$encoding` or HTML
encoding detection. Invalid or unsupported selectors throw `SelectorError`.

### Selector subset

The first release supports:

- type, universal, ID, class, and attribute selectors;
- compound selectors and comma-separated selector lists;
- descendant and child (`>`) combinators;
- `:first-child`, `:nth-child()`, `:first-of-type`, and `:nth-of-type()`;
- `:not()` when its argument uses this same subset.

The attribute grammar and operators are the same as the regular `query()` API.
Sibling combinators (`+`, `~`), `:empty`, `:last-*`, `:only-*`, `:nth-last-*`,
`:has()`, and attribute case flags are not supported by selector streaming.
They are rejected instead of silently using different semantics.

### What results contain

For supported selectors, matches have the same order, attributes, text, and
descendants that regular `query()` would return, including on malformed HTML.

Each result contains the selected element and its descendants, but not ancestors
outside the match. Attribute access, serialization, `toText()`, `toMarkdown()`,
and descendant `query()`/`queryFirst()` calls work. Treat results as read-only
while iterating; changing one result can affect another overlapping result.

### Performance characteristics

Selector streaming helps most when a selective match occurs early and the
consumer stops early. Some selectors and malformed structures must wait for an
enclosing element or EOF before a match becomes final. Broad selectors such as
`*` or `body *`, all-result workloads, and absent selectors can approach or
exceed full-parser work.

The benchmark above is workload- and machine-specific. Targeted extraction is
not a universal replacement for the full parser. Its benefit is reducing work
and parser memory without changing the selected result. See the
[benchmark guide](benchmarks/README.md) for the protocol and broader diagnostics.

## Advanced event scanning: `Stream::events()`

Use `Stream::events()` only when tokenizer events are sufficient and you are
prepared to implement the task-specific logic yourself. It reports what the
tokenizer encounters; it does not provide CSS selectors or repair the page into
the document structure a browser would expose.

```php
use JustHTML\Stream;

foreach (Stream::events($html) as [$event, $data]) {
    if ($event === 'start' && $data[0] === 'a') {
        $href = $data[1]['href'] ?? null;
    }
}
```

Each yielded value is `[$event, $data]`:

- `start`: `[$tagName, $attrs]`
- `end`: `$tagName`
- `text`: decoded text; adjacent text chunks are merged
- `comment`: the comment body without delimiters
- `doctype`: `[$name, $publicId, $systemId]`

HTML void elements such as `img`, `br`, and `meta` emit only a `start` event.
The event iterator does not expose parse errors.

### What “events” means

This is lazy **HTML tokenization**, similar to a pull parser. It is not a stream
of file or network bytes, does not accept a PHP stream resource, and does not
read input incrementally. The complete HTML string or byte buffer must already
be in memory:

```php
Stream::events($html, ?string $encoding = null, bool $bytes = false): Generator
```

The generator tokenizes lazily, so abandoning iteration stops further parsing.
It avoids allocating a DOM, but it does not eliminate the memory occupied by
the input string. When `$bytes` is true, JustHTML decodes the supplied byte
buffer using `$encoding` or HTML encoding detection; it still receives that
buffer in full.

### What value it adds

This API provides a small public interface to JustHTML's HTML tokenizer without
requiring callers to depend on tokenizer internals. It is useful for narrowly
defined jobs such as counting tags, collecting token attributes, observing
comments or doctypes, or stopping after a specific source token. It also avoids
DOM allocation and can stop earlier than either selector-based path.

In the 2026-07-22 lead-paragraph benchmark, a hand-written `Stream::events()`
scanner took 6.75 ms, compared with 25.75 ms for `Stream::select()` and 56.44 ms
for the full parser. This shows the potential speed of specialized code, but it
is not an apples-to-apples replacement: the event version implements the
extraction as a custom state machine and does not promise the same document
structure as the parser-based APIs.

### Correctness and maintenance risks

Start and end events describe tokens found in the source. They do not describe
the corrected document structure that a browser or the full JustHTML parser
would produce. In particular, implied elements, omitted end tags, and
misnested or otherwise malformed markup can make a hand-written nesting model
disagree with the parsed document without raising an error.

That makes event scanning a poor fit for CSS selection, extracting surrounding
document context, or any task where browser-like structure matters. Custom
state machines also add application code that must be tested and maintained.
For CSS selection, use `Stream::select()`. When you need several values,
complex selectors, ancestors, traversal, or mutation, use the full parser.

### Compatibility alias

`Stream::stream()` remains as a deprecated alias for `Stream::events()` so
existing callers do not break. New code should use `Stream::events()`. The new
name deliberately describes the values being produced without suggesting
incremental file or network I/O.
