# CLI

The `justhtml` CLI parses HTML, optionally selects nodes with a CSS selector, and outputs HTML, text, or Markdown.
It accepts either a file path or `-` for stdin.

For basic first-result extractions, the CLI automatically chooses a
significantly faster parsing method when appropriate.

Run it:

- From this repo: `php bin/justhtml`
- From a Composer install: `vendor/bin/justhtml`

## Sample input used below

Create a small input file:

```sh
cat > sample.html <<'HTML'
<!doctype html>
<html>
  <body>
    <article id="post">
      <h1>Title</h1>
      <p class="lead">Hello <em>world</em>!</p>
      <p>Second <span>para</span>.</p>
    </article>
  </body>
</html>
HTML
```

Create a whitespace-focused file:

```sh
cat > whitespace.html <<'HTML'
<!doctype html>
<html><body>
  <p class="sep">Alpha<span>Beta</span>Gamma</p>
  <pre class="ws">
  Hello
    world</pre>
</body></html>
HTML
```

## --selector

Select matching nodes (single selector):

```sh
php bin/justhtml sample.html --selector "p.lead" --format text
```

Output:

```text
Hello world!
```

Select multiple selectors with a comma-separated list:

```sh
php bin/justhtml sample.html --selector "h1, p.lead" --format text
```

Output:

```text
Title
Hello world!
```

## --format

Choose output format: `html`, `text`, or `markdown`.

HTML output:

```sh
php bin/justhtml sample.html --selector "p.lead" --format html
```

Output:

```html
<p class="lead">
  Hello
  <em>world</em>
  !
</p>
```

Text output:

```sh
php bin/justhtml sample.html --selector "p.lead" --format text
```

Output:

```text
Hello world!
```

Markdown output:

```sh
php bin/justhtml sample.html --selector "p.lead" --format markdown
```

Output:

```text
Hello *world*!
```

## --outer / --inner

HTML output uses outer HTML by default. Use `--inner` to print only the
matched node's children (inner HTML). `--outer` is a no-op that makes the
default explicit. These flags only affect `--format html`.

```sh
php bin/justhtml sample.html --selector "p.lead" --format html --inner
```

Output:

```html
Hello
<em>world</em>
!
```

## --attr / --missing

Extract attribute values from matched nodes. Repeat `--attr` to output multiple
attributes per node (tab-separated by default). Missing attributes are replaced
with `__MISSING__` by default; override with `--missing`.

```sh
php bin/justhtml sample.html --selector "p" --attr class --attr id
```

Output (tab-separated):

```text
lead	__MISSING__
__MISSING__	__MISSING__
```

Use `--field-separator` to change the field separator:

```sh
php bin/justhtml sample.html --selector "p" --attr class --attr id --field-separator ","
```

Output:

```text
lead,__MISSING__
__MISSING__,__MISSING__
```

`--attr` cannot be combined with `--format`, `--inner`, `--outer`, or `--count`.

## --first

Limit to the first match:

```sh
php bin/justhtml sample.html --selector "p" --format text
```

Output:

```text
Hello world!
Second para.
```

```sh
php bin/justhtml sample.html --selector "p" --format text --first
```

Output:

```text
Hello world!
```

`--first` is equivalent to `--limit 1` and cannot be combined with `--limit`.

## --limit

Limit to the first N matches. This is equivalent to `--first` when N is 1.

```sh
php bin/justhtml sample.html --selector "p" --format text --limit 2
```

Output:

```text
Hello world!
Second para.
```

## --count

Print the number of matching nodes:

```sh
php bin/justhtml sample.html --selector "p" --count
```

Output:

```text
2
```

`--count` cannot be combined with `--first`, `--limit`, `--format`, `--attr`,
or `--separator`.

## --separator

Join separate matched output records. It is never inserted between descendant
text nodes. The default is a newline for HTML, text, and attribute rows, and a
blank line for Markdown.

```sh
php bin/justhtml sample.html --selector "p" --format text --separator '\n\n'
```

Output:

```text
Hello world!

Second para.
```

Separator values decode `\n`, `\r`, `\t`, `\\`, and `\0`. Unknown escapes
are rejected. Use single shell quotes so the escape reaches `justhtml`; a
literal backslash must itself be escaped.

## --field-separator

Join fields produced by repeated `--attr` options. The default is a tab.
`--field-separator` requires at least one `--attr` option.

```sh
php bin/justhtml sample.html --selector "p" \
  --attr class --attr id --field-separator ','
```

## --whitespace

Choose `normalize` or `preserve` for text output. The default, `normalize`,
concatenates DOM text first, collapses HTML whitespace runs to one space, and
trims the complete result. Inline markup therefore does not create spaces
before punctuation or remove required word spacing.
`--whitespace` can only be used with `--format text`.

```sh
php bin/justhtml whitespace.html --selector ".sep" --format text
```

Output:

```text
AlphaBetaGamma
```

No space is inserted between `Alpha`, `Beta`, and `Gamma` because the source
contains no whitespace at those inline-element boundaries.

Use `preserve` for parsed DOM whitespace:

```sh
php bin/justhtml whitespace.html --selector ".ws" \
  --format text --whitespace preserve
```

Output:

```text
  Hello
    world
```

Preservation refers to the parsed DOM, not the original source bytes. HTML
parsing has already decoded character references, normalized carriage returns,
and removed the initial line feed after `pre` and `textarea`.

## Stdin

Read from stdin by passing `-` as the path:

```sh
cat sample.html | php bin/justhtml - --selector "p.lead" --format text
```

Output:

```text
Hello world!
```

## Wikipedia fixture examples

These examples use the committed Wikipedia snapshot, so they are deterministic
and work without a network connection:

```sh
# Extract Earth's lead paragraph
php bin/justhtml examples/fixtures/wikipedia-earth.html \
  --selector "#mw-content-text p:not(.mw-empty-elt)" --first --format text

# Extract links from the lead section (first 10 hrefs)
php bin/justhtml examples/fixtures/wikipedia-earth.html \
  --selector "#mw-content-text p:not(.mw-empty-elt) a" --attr href --limit 10

# Get the lead paragraph as Markdown
php bin/justhtml examples/fixtures/wikipedia-earth.html \
  --selector "#mw-content-text p:not(.mw-empty-elt)" --format markdown --first
```

## Piping examples (live pages)

Pass `-` as the input path to pipe a downloaded page into `justhtml`:

```sh
# Extract the first paragraph in Wikipedia's main content
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "#mw-content-text p" --first --format text

# Extract the first 10 links found in main-content paragraphs
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "#mw-content-text p a" --attr href --limit 10

# Count images on the page
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "img" --count

# Build a quick table of contents from the first five headings
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "h2, h3" --format text --limit 5
```

Live sites change their markup. If an example stops matching, inspect the
current page and adjust its selector.

## --version and --help

```sh
php bin/justhtml --version
```

Output:

```text
justhtml dev
```

```sh
php bin/justhtml --help
```

Output: prints the full usage/help text.

## Implementation notes: automatic parsing path

The command-line interface and output do not depend on which parser path is
chosen. With `--first` or `--limit 1`, the CLI uses `Stream::select()` when the
selector is supported and selective, allowing parsing to stop after the first
completed match. Unsupported selectors transparently fall back to the full
`query()` path.

For this optimization, “selective” means that every branch of the selector ends
in a tag, ID, or class that can narrow candidates. Broad selectors such as `*`,
attribute-only selectors, and `html` or `body` targets stay on the full parser.
Counts, requests for more than one result, selectors without a limit, and
document-root output also use the full parser.

This choice changes performance only; selector results and exit behavior remain
the same. A selector that looks selective but is absent may still be slower on
the targeted path because absence cannot be known before reaching the end of
the document.

When an ID or class selector targets a known element type, include the tag:

```sh
php bin/justhtml tests/fixtures/text-inline-boundaries.html \
  --selector "p#mwpw" --first --format text
```

Tag qualification can allow the result to be released earlier. A bare
attribute-dependent selector such as `#lead` could still match an earlier
`html` or `body` element whose missing attributes are supplied by a later
start tag, so the parser may need to hold that result until the end of input.

Passing HTML on stdin changes only where input comes from. The CLI reads the
complete input into memory before either parsing path starts.
