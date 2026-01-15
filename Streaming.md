# Streaming

Streaming is the low-level tokenizer interface. It yields events as HTML is tokenized, without building a DOM tree.
This is event streaming, not chunked file reading. You still pass the full HTML string (or byte buffer) in memory.

## When to use streaming

Pros:
- Lower memory use (no DOM)
- Can stop early once you find what you need
- Good for scans, counters, and simple extraction

Cons:
- No CSS selectors
- Manual state tracking
- No tree-builder fixes (implicit end tags are not inserted)

If you need selectors, DOM traversal, or spec-correct tree fixes, use the full parser (`new JustHTML(...)`).

## Quick start

```php
use JustHTML\Stream;

$html = '<p class="lead">Hello <em>world</em>!</p>';

foreach (Stream::stream($html) as [$event, $data]) {
    echo $event . ' -> ' . json_encode($data) . "\n";
}
```

## Event reference

Each yielded value is a two-item array: `[$event, $data]`.

- `start`: `[$tagName, $attrs]`
  - `$tagName` is a lowercase tag name (string)
  - `$attrs` is an associative array of attribute name to value (string) or null for boolean attrs
- `end`: `$tagName`
- `text`: the text content (string). Adjacent text chunks are merged.
- `comment`: the comment body (string, without `<!-- -->`)
- `doctype`: `[$name, $publicId, $systemId]` (strings or null)

Notes:
- Void elements like `img`, `br`, `meta` emit only a `start` event (no `end` event).
- Character references are decoded in text and attribute values.
- The stream does not expose parse errors; it is raw tokenizer output, not the tree builder.

## Example: full event stream

Input:

```html
<!doctype html>
<p class="lead">Hello <em>world</em><!-- note -->!</p>
```

Output (events shown as `event -> data`):

```text
doctype -> ["html",null,null]
start -> ["p",{"class":"lead"}]
text -> "Hello "
start -> ["em",[]]
text -> "world"
end -> "em"
comment -> " note "
text -> "!"
end -> "p"
```

## Example: first non-empty paragraph under #mw-content-text

The full parser version (non-empty means non-whitespace text):

```php
use JustHTML\JustHTML;

$doc = new JustHTML($html);
$leadText = '';
foreach ($doc->query('#mw-content-text p') as $p) {
    $text = $p->toText();
    if ($text !== '') {
        $leadText = $text;
        break;
    }
}
```

A streaming version that matches `toText()` defaults (trim text nodes and join with a space).
Here, \"non-empty\" means at least one non-whitespace text segment:

```php
use JustHTML\Stream;

function append_text_segment(array &$segments, string $data, bool &$hasText): void
{
    $text = trim($data);
    if ($text === '') {
        return;
    }
    $segments[] = $text;
    $hasText = true;
}

$inContainer = false;
$containerDepth = 0;
$capturing = false;
$hasText = false;
$segments = [];
$separator = ' ';

foreach (Stream::stream($html) as [$event, $data]) {
    if ($event === 'start') {
        [$tag, $attrs] = $data;
        $tag = (string)$tag;
        $attrs = is_array($attrs) ? $attrs : [];

        if (!$inContainer) {
            if ($tag === 'div' && (($attrs['id'] ?? '') === 'mw-content-text')) {
                $inContainer = true;
                $containerDepth = 1;
            }
            continue;
        }

        $containerDepth += 1;
        if ($tag === 'p') {
            if ($capturing && $hasText) {
                break;
            }
            $capturing = true;
            $hasText = false;
            $segments = [];
        }
        continue;
    }

    if ($event === 'end') {
        $tag = (string)$data;
        if ($inContainer) {
            $containerDepth -= 1;
            if ($containerDepth <= 0) {
                $inContainer = false;
            }
        }

        if ($capturing && $tag === 'p') {
            if ($hasText) {
                break;
            }
            $capturing = false;
            $hasText = false;
            $segments = [];
        }
        continue;
    }

    if ($event === 'text' && $capturing) {
        append_text_segment($segments, $data, $hasText);
    }
}

$text = $segments ? implode($separator, $segments) : '';
```

This includes a simple heuristic that treats a new `<p>` start tag as the end of the previous paragraph,
since streaming does not insert implicit end tags. If your HTML relies heavily on implicit tag rules,
you may need extra logic.

## Performance comparison (example)

Run this snippet on the Wikipedia fixture to compare streaming vs full parser:

```php
use JustHTML\JustHTML;
use JustHTML\Stream;

$html = file_get_contents('examples/fixtures/wikipedia-earth.html');

function full_parse(string $html): string
{
    $doc = new JustHTML($html);
    foreach ($doc->query('#mw-content-text p') as $p) {
        $text = $p->toText();
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function stream_parse(string $html): string
{
    $inContainer = false;
    $containerDepth = 0;
    $capturing = false;
    $hasText = false;
    $segments = [];

    foreach (Stream::stream($html) as [$event, $data]) {
        if ($event === 'start') {
            [$tag, $attrs] = $data;
            $tag = (string)$tag;
            $attrs = is_array($attrs) ? $attrs : [];

            if (!$inContainer) {
                if ($tag === 'div' && (($attrs['id'] ?? '') === 'mw-content-text')) {
                    $inContainer = true;
                    $containerDepth = 1;
                }
                continue;
            }

            $containerDepth += 1;
            if ($tag === 'p') {
                if ($capturing && $hasText) {
                    break;
                }
                $capturing = true;
                $hasText = false;
                $segments = [];
            }
            continue;
        }

        if ($event === 'end') {
            $tag = (string)$data;
            if ($inContainer) {
                $containerDepth -= 1;
                if ($containerDepth <= 0) {
                    $inContainer = false;
                }
            }

            if ($capturing && $tag === 'p') {
                if ($hasText) {
                    break;
                }
                $capturing = false;
                $hasText = false;
                $segments = [];
            }
            continue;
        }

        if ($event === 'text' && $capturing) {
            $text = trim($data);
            if ($text === '') {
                continue;
            }
            $segments[] = $text;
            $hasText = true;
        }
    }

    return $segments ? implode(' ', $segments) : '';
}

function bench(callable $fn, int $iterations): float
{
    $total = 0.0;
    for ($i = 0; $i < $iterations; $i++) {
        $t0 = hrtime(true);
        $fn();
        $t1 = hrtime(true);
        $total += ($t1 - $t0) / 1e6;
    }
    return $total / $iterations;
}

$iterations = 5;
full_parse($html);
stream_parse($html);

$avgFull = bench(fn() => full_parse($html), $iterations);
$avgStream = bench(fn() => stream_parse($html), $iterations);

printf("Full parser avg: %.2f ms\n", $avgFull);
printf("Stream avg: %.2f ms\n", $avgStream);
```

Example results on one machine for lead paragraph extraction
(PHP 8.5.1, 5-run average, Wikipedia fixture):

```text
Full parser avg: 120.06 ms
Stream avg: 13.09 ms
```

Your results will vary by machine and PHP version.

## Common streaming patterns

### 1) Fast link extraction

```php
$links = [];
foreach (Stream::stream($html) as [$event, $data]) {
    if ($event !== 'start') {
        continue;
    }
    [$tag, $attrs] = $data;
    if ($tag === 'a' && isset($attrs['href'])) {
        $links[] = $attrs['href'];
    }
}
```

### 2) Skip script/style content

```php
$skipDepth = 0;

foreach (Stream::stream($html) as [$event, $data]) {
    if ($event === 'start') {
        [$tag, $_] = $data;
        if ($tag === 'script' || $tag === 'style') {
            $skipDepth += 1;
        }
        continue;
    }

    if ($event === 'end') {
        $tag = $data;
        if ($skipDepth > 0 && ($tag === 'script' || $tag === 'style')) {
            $skipDepth -= 1;
        }
        continue;
    }

    if ($event === 'text' && $skipDepth === 0) {
        // Process visible text here.
    }
}
```

### 3) Subtree-limited extraction

```php
$inArticle = false;
$depth = 0;
$text = [];

foreach (Stream::stream($html) as [$event, $data]) {
    if ($event === 'start') {
        [$tag, $attrs] = $data;
        if (!$inArticle && $tag === 'article') {
            $inArticle = true;
            $depth = 1;
            continue;
        }
        if ($inArticle) {
            $depth += 1;
        }
        continue;
    }

    if ($event === 'end') {
        if ($inArticle) {
            $depth -= 1;
            if ($depth <= 0) {
                break;
            }
        }
        continue;
    }

    if ($event === 'text' && $inArticle) {
        $text[] = $data;
    }
}

$articleText = trim(implode('', $text));
```

### 4) Build your own text extractor

```php
$parts = [];
foreach (Stream::stream($html) as [$event, $data]) {
    if ($event === 'text') {
        $parts[] = $data;
    }
}
$plain = preg_replace('/\s+/', ' ', trim(implode('', $parts)));
```

## Tips and limitations

- Streaming does not support CSS selectors. You must implement matching logic using tags and attributes.
- The stream reflects tokenizer output. It does not fix malformed HTML or insert implicit end tags.
- For very large files, streaming avoids DOM memory use, but the input still has to fit in memory.

## Encoding

`Stream::stream($html, $encoding = null, $bytes = false)` accepts either text or raw bytes.
If `$bytes` is true, the input is decoded to UTF-8 using the provided encoding or HTML sniffing.
