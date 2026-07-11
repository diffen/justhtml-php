# Bug fixes — 2026-07-11

Four bugs found during a code review and fixed in this change. The full test
suite (8,922 tests: html5lib tree construction, tokenizer, serializer,
encoding, selector, and API regressions) passes before and after; each fix
below also includes its own reproduction, now verified fixed.

## 1. `JustHTML` mutated the caller's `TokenizerOpts`

**File:** `src/JustHTML/JustHTML.php`
**Severity:** high (silent data corruption on reuse)

When parsing a fragment whose context element implies a non-default tokenizer
state (`textarea`, `title`, `script`, `style`, `xmp`, `iframe`, `noembed`,
`noframes`, `plaintext`), the constructor wrote `initialState` and
`initialRawtextTag` directly onto the user-supplied `TokenizerOpts` object.
Any later parse that reused the same opts object then started the tokenizer in
RCDATA/RAWTEXT/PLAINTEXT state, so ordinary markup was swallowed as text:

```php
$opts = new TokenizerOpts();
new JustHTML('x', [
    'fragment_context' => new FragmentContext('textarea'),
    'tokenizer_opts'   => $opts,
]);

$doc = new JustHTML('<p>hello</p>', ['tokenizer_opts' => $opts]);
// Before the fix: <body> contained the literal text "<p>hello</p>"
```

**Fix:** the constructor now clones the provided `TokenizerOpts` before
applying fragment-context adjustments, so the caller's object is never
modified.

## 2. Scoped `#id tag` queries missed ids above the queried root

**File:** `src/JustHTML/Selector.php`
**Severity:** high (wrong query results)

`query()`/`queryFirst()` have a fast path for selectors of the exact shape
`#id tag`. It tracked "am I inside the id element" only within the queried
subtree, while the generic matcher (used for every other selector) walks the
real ancestor chain — which may extend above the queried root. Standard
`querySelectorAll` semantics evaluate the selector against the whole tree and
filter results to descendants, i.e. the generic behavior. The two paths
therefore disagreed:

```php
$doc     = new JustHTML('<div id="outer"><section><p class="x">hi</p></section></div>');
$section = $doc->queryFirst('section');

$section->query('#outer p');    // Before the fix: 0 results (fast path)
$section->query('#outer p.x');  // 1 result (generic path — correct)
```

**Fix:** before taking the fast path, a new `hasAncestorWithId()` helper walks
the root's real ancestor chain; if the id element sits above the queried root,
the descendant scan starts with `insideId = true`. The fast path now returns
the same results as the generic matcher. Template contents remain excluded
from ancestor tracking, matching the generic matcher (a template's content
fragment has no parent link to the template element).

## 3. Noah's Ark clause compared attributes with loose `==`

**File:** `src/JustHTML/TreeBuilder.php`
**Severity:** medium (spec deviation in tree construction, rare inputs)

`_find_active_formatting_duplicate()` — the "Noah's Ark" rule that caps the
active-formatting list at three identical entries — compared attribute
signatures with `==`. PHP compares numeric strings numerically, so values such
as `"10"` and `"1e1"` (or `"0"` and `"-0"`, `"1"` and `"01"`) were treated as
equal, and a formatting element with *distinct* attributes could be wrongly
evicted from the list. The dropped entry was then missing when formatting
elements were reconstructed:

```php
$doc = new JustHTML('<p><font size="10"><font size="1e1"><font size="10"><font size="1e1">x<p>y');
// Before the fix: the second <p> reconstructed only 3 nested <font> elements.
// After the fix: 4, matching the spec (no three entries are identical).
```

**Fix:** `_attrs_signature()` now normalizes names and values to strings
(numeric attribute names arrive as int array keys) and sorts with `strcmp`
instead of `<=>` (which also compares numeric strings numerically); the
duplicate check uses strict `===`. Genuinely identical entries are still
capped at three.

## 4. Typo in the "in row" end-tag ignore list

**File:** `src/JustHTML/TreeBuilderModes.php`
**Severity:** low (wrong parse-error code only; resulting tree was identical)

The list of end tags to ignore in the "in row" insertion mode was
`['caption', 'col', 'group', 'td', 'th']` — `'colgroup'` had been split into
`'col', 'group'`, and the spec's `body` and `html` entries were missing. The
mistyped tags fell through to the in-body fallback, which happened to leave
the tree unchanged (hence no test failures), but recorded a misleading
`...-implies-table-voodoo` parse error instead of `unexpected-end-tag`.

**Fix:** the list now matches the HTML spec:
`['body', 'caption', 'col', 'colgroup', 'html', 'td', 'th']`.

## Verification

- `php run_tests.php`: 8,922 passed, 0 failed, 8 skipped (unchanged).
- Targeted reproductions for all four bugs pass (including regression checks:
  fragment RCDATA parsing still works, ids inside the queried subtree still
  match, identical formatting entries are still capped at three, stray end
  tags in table rows still produce the same tree).
- Serializer fixed-point differential over the 1,578 html5lib
  tree-construction documents is unchanged: `parse(serialize(x))` is a fixed
  point everywhere except the 31 cases involving `<plaintext>`, whose content
  is not serializable in HTML by design.
