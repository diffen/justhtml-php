# CLI

The `justhtml` CLI parses HTML, optionally selects nodes with a CSS selector, and outputs HTML, text, or Markdown.
It accepts either a file path or `-` for stdin.

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
  <p class="ws">  Hello <span> world </span> ! </p>
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

Use `--separator` to change the field separator:

```sh
php bin/justhtml sample.html --selector "p" --attr class --attr id --separator ","
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

`--count` cannot be combined with `--first`, `--limit`, `--format`, or `--attr`.

## --separator

Join text nodes with a custom separator (text output only). In `--attr` mode,
this controls the field separator (default: tab).

```sh
php bin/justhtml whitespace.html --selector ".sep" --format text
```

Output:

```text
Alpha Beta Gamma
```

```sh
php bin/justhtml whitespace.html --selector ".sep" --format text --separator ""
```

Output:

```text
AlphaBetaGamma
```

## --strip / --no-strip

By default, each text node is trimmed and empty nodes are dropped (`--strip`).
Use `--no-strip` to preserve the original whitespace within text nodes.

Default (strip on):

```sh
php bin/justhtml whitespace.html --selector ".ws" --format text
```

Output:

```text
Hello world !
```

Preserve whitespace:

```sh
php bin/justhtml whitespace.html --selector ".ws" --format text --no-strip
```

Output (spaces shown between `|` markers):

```text
|  Hello   world   ! |
```

## Stdin

Read from stdin by passing `-` as the path:

```sh
cat sample.html | php bin/justhtml - --selector "p.lead" --format text
```

Output:

```text
Hello world!
```

## Piping examples (real pages)

These examples use a live page and pipe HTML into `justhtml`.

```sh
# Extract the first non-empty paragraph as text
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "#mw-content-text p:not(:empty)" --format text --first

# Extract links from the lead section (first 10 hrefs)
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "#mw-content-text p a" --attr href --limit 10 --separator "\n"

# Get the lead section as Markdown
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "#mw-content-text" --format markdown --first

# Count images on the page
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "img" --count

# Output the infobox as HTML (outer HTML)
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "table.infobox" --format html --outer --first

# Preserve whitespace and separate paragraphs
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "#mw-content-text p" --format text --no-strip --separator "\n\n" --limit 3

# Build a quick table of contents from headings
curl -s https://en.wikipedia.org/wiki/Earth | \
  php bin/justhtml - --selector "#mw-content-text h2, #mw-content-text h3" --format text --separator "\n"
```

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
