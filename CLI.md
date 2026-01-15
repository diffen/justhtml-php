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

Select matching nodes:

```sh
php bin/justhtml sample.html --selector "p.lead" --format text
```

Output:

```text
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

## --separator

Join text nodes with a custom separator (text output only):

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
