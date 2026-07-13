# Bug report — 2026-07-12

This report documents five bugs found during a code review of JustHTML 0.1.5.
All five fixes have now been implemented, with focused regression coverage in
`run_tests.php`. The descriptions below retain the original reproductions and
the implementation approach used to resolve them.

## Summary

| # | Severity | Area | Problem | Status |
|---|---|---|---|---|
| 1 | High | Encoding | Common WHATWG encoding labels fall back to Windows-1252 | Fixed |
| 2 | High | Packaging/encoding | Decoding can fatal when both `mbstring` and `iconv` are unavailable | Fixed |
| 3 | Medium | Selectors | Empty functional pseudo-classes are accepted as valid CSS | Fixed |
| 4 | Medium | Selectors | SVG and MathML type selectors are incorrectly case-insensitive | Fixed |
| 5 | Low | DOM mutation | `appendChild()` silently discards children on leaf nodes | Fixed |

## 1. Common encoding labels fall back to Windows-1252

**Files:** `src/JustHTML/Encoding.php` (`normalizeEncodingLabel()`,
`decodeHtml()`)

**Severity:** high — valid non-Western HTML can be silently corrupted.

### Current behavior

`normalizeEncodingLabel()` recognizes only UTF-8, Windows-1252,
ISO-8859-2, EUC-JP, and UTF-16 variants. Common encodings recognized by the
WHATWG Encoding Standard, including Shift_JIS, Big5, and GBK, return `null`.
`sniffHtmlEncoding()` consequently selects the Windows-1252 default.

The problem affects both a caller-provided transport encoding and encodings
declared in a `<meta>` element.

```php
use JustHTML\Encoding;

[$text, $encoding] = Encoding::decodeHtml("\x82\xA0", 'shift_jis');

// Current:
// $encoding === 'windows-1252'
// $text === "‚ "

// Expected:
// $encoding === 'shift_jis'
// $text === "あ"
```

### Proposed fix

1. Replace the hand-written subset in `normalizeEncodingLabel()` with a
   complete WHATWG label-to-canonical-name table.
2. Add every supported canonical encoding to the conversion map in
   `decodeHtml()`.
3. Keep the HTML-specific remappings required by the standard, such as
   ISO-8859-1 labels mapping to Windows-1252 and meta-declared UTF-16 mapping
   to UTF-8.
4. If the installed conversion backend cannot decode an otherwise recognized
   encoding, throw a descriptive exception instead of silently treating it as
   Windows-1252.

### Regression tests

Add cases for transport and `<meta charset>` declarations covering at least:

- Shift_JIS (`82 A0` to `あ`)
- Big5
- GBK
- aliases whose spelling differs from the canonical encoding name
- `x-user-defined` boundary bytes (`80` to U+F780 and `FF` to U+F7FF)
- an unknown label, which should retain the specified HTML fallback behavior

## 2. Decoding can fatal without `mbstring` and `iconv`

**Files:** `composer.json`, `src/JustHTML/Encoding.php` (`decodeHtml()`)

**Severity:** high — an installation satisfying the declared Composer
requirements can fail at runtime.

### Current behavior

The package requires only PHP 7.4 or newer. `decodeHtml()` checks whether
`mb_convert_encoding()` exists, but otherwise calls `iconv()` without checking
that the iconv extension is available:

```php
if (function_exists('mb_convert_encoding')) {
    $decoded = mb_convert_encoding($payload, 'UTF-8', $src);
} else {
    $decoded = iconv($src, 'UTF-8//IGNORE', $payload);
}
```

A PHP build with neither extension therefore fails with an undefined-function
error when byte decoding is requested. The `//IGNORE` fallback also drops
invalid input bytes rather than producing the replacement character used by
the mbstring path, so results vary by installed extensions.

### Proposed fix

Choose and document one of these dependency policies:

1. Require `ext-mbstring` in `composer.json` and use it as the single decoding
   backend; or
2. Allow either extension, explicitly guard both with `function_exists()`, and
   throw a clear `RuntimeException` when neither is installed.

If iconv remains supported, add replacement handling so malformed input is
converted consistently to U+FFFD rather than silently discarded.

### Regression tests

- Run decoding in a PHP image with mbstring only.
- Run decoding in a PHP image with iconv only and compare malformed-input
  behavior with the mbstring result.
- Run in an image with neither extension and verify either Composer rejects
  installation or the library raises the documented exception.

## 3. Empty functional pseudo-classes are accepted

**File:** `src/JustHTML/Selector.php` (`SelectorParser`, `matchesPseudo()`)

**Severity:** medium — malformed selectors produce plausible but incorrect
results instead of reporting an error.

### Current behavior

The matcher treats an empty `:not()` argument as true. Thus invalid CSS can
match every descendant:

```php
use JustHTML\JustHTML;

$doc = new JustHTML('<div><p>x</p></div>');
$matches = $doc->query(':not()');

// Current: returns all five generated HTML elements.
// Expected: throws JustHTML\SelectorError.
```

Empty or invalid `:nth-child()` expressions such as `:nth-child()` and
`:nth-child(foo)` are likewise accepted and silently match nothing.

### Proposed fix

Validate functional pseudo-class arguments while parsing, before constructing
the selector AST:

- `:not()` must contain a valid, non-empty selector list.
- `:nth-child()` and `:nth-of-type()` must contain a valid `An+B` expression.
- Invalid arguments must throw `SelectorError` consistently from `query()`,
  `queryFirst()`, and `matches()`.

Remove the `return true` fallback for an empty `:not()` argument from the
matcher. The matcher should only receive a validated parsed argument.

### Regression tests

Assert that the following throw `SelectorError`:

```text
:not()
:not
:nth-child()
:nth-child
:nth-child(foo)
:nth-of-type()
:nth-of-type
```

Also retain positive tests for valid forms such as `:not(.x)`,
`:nth-child(2n + 1)`, `odd`, and `-n+3`.

## 4. Foreign element selectors are incorrectly case-insensitive

**File:** `src/JustHTML/Selector.php` (`matchesSimple()` and tag-only query
fast paths)

**Severity:** medium — selectors can return the wrong SVG or MathML elements.

### Current behavior

All element names and type selectors are lowercased before comparison. That is
appropriate for elements in the HTML namespace, but SVG and MathML element
names are case-sensitive.

```php
use JustHTML\JustHTML;

$doc = new JustHTML('<svg><linearGradient id="g"/></svg>');

count($doc->query('linearGradient')); // 1, correct
count($doc->query('lineargradient')); // 1, incorrect
```

Attribute-name matching has the same unconditional lowercasing and should be
reviewed under the same namespace rules.

### Proposed fix

1. In the general matcher, compare ASCII-case-insensitively only when the node
   belongs to the HTML namespace; use an exact comparison for foreign nodes.
2. Preserve the selector's original case in tag-only fast paths. The current
   fast-path representation lowercases the tag before node namespaces are
   known.
3. Apply namespace-aware casing rules to attribute names as well.
4. Ensure fast and generic selector paths return identical results.

### Regression tests

- Verify `linearGradient` matches an SVG `linearGradient` element and
  `lineargradient` does not.
- Add a mixed HTML/SVG tree to confirm HTML type selectors remain
  case-insensitive.
- Cover camel-cased SVG names and attributes.
- Run the same assertions through selectors that use fast and generic paths.

## 5. `appendChild()` silently discards children on leaf nodes

**File:** `src/JustHTML/Node.php` (`SimpleDomNode::appendChild()`)

**Severity:** low — invalid mutations are hidden and can cause silent data
loss in caller code.

### Current behavior

Comments and doctypes use `children === null`. `appendChild()` simply returns
without inserting or reporting an error, while `insertBefore()` and
`replaceChild()` throw when invoked on the same kind of node.

```php
use JustHTML\SimpleDomNode;
use JustHTML\TextNode;

$comment = new SimpleDomNode('#comment', null, 'comment');
$child = new TextNode('lost');

$comment->appendChild($child); // Silently ignored.
```

This leaves the caller believing a mutation succeeded even though the child
was discarded.

### Proposed fix

Make `appendChild()` consistent with the other mutation methods:

```php
if ($this->children === null) {
    throw new \RuntimeException("Node {$this->name} cannot have children");
}
```

Consider making `removeChild()` throw when the target is not a child as well,
if the intended API is to follow DOM mutation semantics. That is a separate
compatibility decision and is not required to fix the silent append.

### Regression tests

- Appending to comments and doctypes throws.
- Appending to elements and document/document-fragment nodes still works.
- A failed append does not detach the incoming node from its existing parent.

## Recommended implementation order

1. Fix encoding label coverage and declare a reliable conversion backend.
2. Enforce functional pseudo-class validation.
3. Make selector casing namespace-aware in both generic and optimized paths.
4. Make leaf-node mutation failure explicit.
5. Run the full suite and add each reproduction above as a focused regression
   test.
