# Proposal: Streaming CSS selectors (`Stream::select()`)

> Historical design record. The A-versus-B spike is complete; approach A was
> selected and approach B was archived. See the
> [spike report](spike-streaming-select/report.md) for the definition of a
> spike, measured results, and final decision.
>
> Naming note: after this work, the low-level event API was renamed to
> `Stream::events()` to avoid implying incremental file or network I/O.
> Historical passages below retain the original `Stream::stream()` name.
> The final public `selectFirst()` return type is `?SimpleDomNode`, the common node
> base, because the parser represents the `html` and `body` elements with that
> base class. Historical passages retain the originally proposed
> `?ElementNode` signature.

- **Status:** Implemented. The spike selected approach A, the public API is
  stabilized, and the remaining test, benchmark, and documentation milestones
  are complete. See the [spike report](spike-streaming-select/report.md).
- **Date:** 2026-07-18
- **Scope:** JustHTML PHP library — streaming API, benchmarks, docs

## Outcome

The proposal shipped as `Stream::select()` and `Stream::selectFirst()`. The
product is deliberately positioned between two existing paths:

- Unlike a full JustHTML DOM, selector streaming can release stable selective
  matches early and avoid retaining the whole document tree.
- Unlike raw tokenizer-event streaming, it preserves final-DOM `query()`
  semantics for the documented selector subset, including malformed-HTML tree
  recovery.

At completion, the public lead-extraction benchmark measured 25.63 ms for
selector streaming versus 56.10 ms for the full JustHTML DOM. The controlled spike
measured 172 versus 24,298 peak live nodes. The lexical comparator was about
three times faster than approach A overall, but its semantic divergences made
it unsuitable for the public exactness contract.

## Original proposal

Add a selector-driven streaming API, `Stream::select($html, $selector)`, that yields
matched elements as completed `ElementNode` results in a detached retained tree —
without retaining a DOM for the whole document. This turns JustHTML's existing
streaming speed advantage (currently locked behind a hand-rolled event API) into
something users reach in 4 lines, with early exit before the document is fully parsed
where the contract permits it.

**Semantic contract (decided):** `Stream::select()` is intended to return the same
selector results, in the same document order, as full-DOM `query()` for its documented
selector subset. Before this contract is committed to a public API, we will prototype
both a TreeBuilder-integrated and a lexical-stack implementation; the spike determines
whether exact semantics preserves enough first-result latency and memory benefit to be
worth shipping, judged against the operational go/no-go gate predeclared below. If it
does not, lexical selection would only be considered as an explicitly distinct API
with a distinct, documented contract — it will not be shipped under `select()`'s
DOM-equivalence promise.

## Background: where we stand on performance

Measured on PHP 8.5.8 (Xdebug off), July 2026. Fixtures are the Common Crawl 1k set
and the Wikipedia "Earth" page; regeneration commands in `benchmarks/README.md`.

**Raw full-document parse (1,000 Common Crawl docs, avg ms/doc):**

| Parser | ms/doc |
|--------|-------:|
| justhtml | 4.43 |
| masterminds/html5 (pure PHP, 4.2% html5lib compliance) | 3.57 |
| domdocument (libxml2, C) | 0.69 |
| dom/html-document (Lexbor, C, PHP 8.4+) | 0.43 |

**End-to-end task — lead paragraph extraction (Wikipedia fixture, 5-run avg):**

| Approach | ms |
|----------|---:|
| justhtml full DOM + `query()` | 59.8 |
| justhtml/stream (hand-written event loop) | 7.2 |
| dom/html-document (C) | 7.0 |
| domdocument (C) | 9.2 |

Two conclusions drive this proposal:

1. **We cannot win raw full-document parsing in pure PHP.** Our tokenizer alone costs
   ~1.2 ms/doc on the Common Crawl sample — roughly 3× domdocument's *entire* parse.
2. **The streaming path ties C parsers on a real extraction task** — but only via
   ~70 lines of hand-written state machine (`Streaming.md`). The equivalent DOM version
   is 8 lines. The ergonomics make the fast path unreachable for most users.

**Caveat on these numbers (per review):** the 7.2 ms figure is from a specialized text
extractor that does no general selector matching and allocates no element subtrees. It
motivates the direction but does **not** establish `Stream::select()` performance.
All performance positioning is gated on benchmarking the actual prototypes (see
Benchmark plan).

## Problem statement

The streaming API is our fastest path for the most common real-world use case
("parse a page, extract a few things"), but:

- No selector support — users hand-roll tag/attribute/depth matching.
- The event stream reflects tokenizer output, so users must handle implied end tags
  and error recovery themselves — documented as a user-facing caveat.
- Result: the benchmark-winning path is effectively expert-only, and the README
  currently concedes the headline ("use `Dom\HTMLDocument` if you're on 8.4+").

## Semantic contract

**`Stream::select()` promises DOM-equivalent results.** For every selector in the
documented subset, `Stream::select($html, $s, ...)` yields exactly the elements that
full-DOM `query($s)` returns **after applying equivalent input decoding options**
(the `$encoding`/`$bytes` handling below), in document order, as completed nodes in
a detached retained tree.

Why not lexical (token-stream) semantics: a tokenizer-level stack diverges from the
real DOM on *ordinary* HTML, not just adversarial input — foster parenting moves
table-misnested content before the table; implicit `html`/`head`/`body`/`tbody`
elements have no source token; the adoption agency reparents formatting elements
(see `TreeBuilder.php`, `TreeBuilderModes.php`). A library whose identity is 100%
html5lib compliance should not ship a selector API that silently disagrees with its
own `query()`.

DOM equivalence is the **acceptance criterion** for the implementation, verified by
the spike and the test oracle — it is not assumed to hold "by construction" of any
particular mechanism. Where exactness and early exit conflict, **exactness wins**.

## Proposed API

```php
use JustHTML\Stream;

foreach (Stream::select($html, '#mw-content-text p') as $p) {
    $text = $p->toText();
    if ($text !== '') {
        break; // abandoning the generator stops parsing
    }
}

$first = Stream::selectFirst($html, '#mw-content-text p'); // ?ElementNode
```

Signatures mirror `Stream::stream()`'s input handling:

```php
Stream::select($html, string $selector, ?string $encoding = null, bool $bytes = false): \Generator
Stream::selectFirst($html, string $selector, ?string $encoding = null, bool $bytes = false): ?ElementNode
```

### Result type and node surface

- Each yielded value is a **completed `ElementNode` in a detached retained tree**.
  (Not called a "snapshot": the objects are mutable and may alias other results —
  see below.)
- **Retained-tree sharing:** results from overlapping matched regions may share a
  detached retained tree. A top-level retained root has `parent === null`; a nested
  result may have a parent **within** that retained tree, but never above it.
- **Aliasing, documented:** because overlapping results share nodes, mutating a
  yielded result can be visible in other results that share its retained tree
  (including queued ones not yet yielded). Yielded nodes should be treated as
  read-only during iteration. Independent detached copies were considered and
  rejected: cloning nested subtrees can become quadratic for deeply nested matches.
- Supported on yielded nodes: attribute access, `toText()`, `toMarkdown()`,
  serialization, and descendant `query()`/`queryFirst()` within the retained tree.
- **Not supported** (documented): ancestor-dependent operations above the retained
  root — `->parent` navigation past it, and `matches()` against selectors that depend
  on ancestors outside the retained tree.

### Ordering, nesting, memory, and EOF

- **Document order.** Matches are yielded in document order. When matches nest, the
  outer subtree is retained until it completes; outer is yielded before inner.
- **Deduplication.** An element matched by multiple selector-list branches is yielded
  once.
- **EOF.** Matches still open at end of input are completed by EOF processing (same
  tree the full parser would produce) and then yielded.
- **Memory expectation, stated honestly:** peak memory is proportional to the largest
  retained matched subtree plus whatever the tree builder must keep live (see
  Retention and pruning). Selective selectors get the streaming memory win; broad
  selectors (`*`, `body *`) can approach full-DOM memory and delay the first yield.
  The docs will say so, and the benchmark plan measures a broad-selector case.

### Ordered yield frontier (stability — v3 re-review issue 1)

Element completion does not by itself make a match final: later input can still
change whether an already-completed element matches. Example: in
`<p id=p>x</p><body class=x>`, the late `body` start tag merges its attributes onto
the existing implicit `body`, so final-DOM `query('body.x p')` matches the
already-closed paragraph. EOF finalization can also touch completed subtrees
(`TreeBuilder::_populate_selectedcontent()`, `TreeBuilder.php:1290`, scans the
finished document to populate `selectedcontent`).

Per-node stability is **not sufficient** to preserve document order: an earlier
*nonmatching* element can become a match later. For selector `body.x p, div#d` over

```html
<p id="p">first</p>
<div id="d">second</div>
<body class="x">
```

the `div` is complete and individually stable before the late `body` token, but the
final DOM places the paragraph first. Yielding the `div` when it stabilizes would
violate document order.

Contract — a result is yieldable only when:

1. Its own subtree and match status are stable under any remaining
   tree-construction operations.
2. Every earlier element's match/nonmatch status is stable.
3. No remaining tree-construction operation can insert a new matching element
   before it in document order.

`selectFirst()` stops after the first document-order match becomes yieldable under
these rules. Some selectors or HTML structures may require parsing an enclosing
element — or the entire document — before yieldability can be established;
conservative rules that delay yields are acceptable. Exactness beats early exit.

The spike must produce an **ordered yield frontier** (rules for when conditions 1–3
provably hold), superseding the earlier per-node stability frontier, and the
API-contract tests must cover ordered-yield cases like the example above.

### Errors

Invalid or out-of-subset selectors throw `SelectorError` (the existing type,
`Selector.php:7`, which extends `InvalidArgumentException` — consistent with the DOM
selector API) **when `select()` is called**, not on first iteration. Because PHP
generator bodies do not execute until iterated, selector compilation happens in a
non-generator wrapper that validates eagerly and returns the generator.

## Selector subset and compiler

Supported in v1:

- Type, `#id`, `.class`, attribute selectors with **the operators `query()` already
  supports** (the streaming subset inherits the current `query()` grammar — no new
  attribute syntax; v3 re-review issue 5), compound selectors, selector lists,
  descendant and child combinators.
- Positional pseudos decidable from past siblings: `:first-child`, `:nth-child(...)`,
  `:first-of-type`, `:nth-of-type(...)`. These are evaluated against **actual
  TreeBuilder sibling state** and are subject to the ordered-yield rules — late
  insertions (e.g., foster parenting) can change sibling positions, so counter
  values are not assumed permanently valid from start-time observation alone
  (v3 re-review issue 4).
- `:not(...)` restricted to the supported subset, validated recursively.

Not in v1, in two distinct categories (v3 re-review issue 4):

- **Genuinely future-dependent** (need later-sibling or whole-tree knowledge at
  match time): `:last-child`, `:nth-last-*`, `:has()`. Rejected at compile time.
- **Deliberately deferred, decidable in principle:** `+` and `~` combinators
  (they inspect *previous* siblings — the DOM matcher already evaluates them
  backward from the candidate), `:empty` (decidable at element completion), and CSS
  attribute-selector case flags (`[... i]` — not currently in the `query()` grammar;
  adding them would be a separate shared-engine enhancement for both APIs). Also
  rejected at compile time in v1, but documented as scope decisions, not
  infeasibility.

Implementation notes:

- Reuse `Selector.php` for parsing, followed by a **recursive semantic-validation
  pass** that enforces the subset — the existing parser accepts more than we support.
- Define HTML vs SVG/MathML name/attribute case behavior to match `query()` exactly
  (the contract requires it).

## Implementation approaches (both spiked in Milestone 1)

**A. TreeBuilder-integrated (the north star).** Hook selector observation into the
real `TreeBuilder`. Candidates are evaluated using the inserted node's **actual
parent chain after the TreeBuilder determines its insertion location** — not the
open-element stack, which diverges from the DOM ancestor chain under foster
parenting. The observation mechanism must also see elements created without a
corresponding source start tag: implicit `html`/`head`/`body`/`tbody`,
reconstructed or cloned formatting elements, and elements created or populated
during finalization.

**Retention and pruning.** Discarding non-matching subtrees is a hypothesis to
validate, not an assumption: nodes can remain live through active-formatting state,
form/template pointers, open ancestors, tree-recovery algorithms, or EOF
finalization (`_populate_selectedcontent()` scans the finished document today). The
implementation goal is:

> Retain the minimum tree and metadata required by TreeBuilder liveness, selector
> evaluation, matched results, and finalization; prune nodes only after proving they
> cannot participate in later tree-construction behavior.

A required spike output is a **taxonomy of when nodes become safely discardable**.

**B. Lexical enriched stack (spike-only comparator).** Extend `StreamSink`'s stack
with attributes, namespace-adjusted names, sibling counters, and implied-end-tag
handling, and match against it. Establishes the performance ceiling and quantifies
the real-world divergence rate from `query()` on the fixture corpus. Ships publicly
only if (a) approach A fails the gate **and** (b) a separate proposal defines it as
a distinct API with lexical semantics. (The current `StreamDummyNode` retains no
attributes, so even this spike requires stack enrichment.)

**Equivalent comparison (v3 re-review issue 3):** both prototypes compile the same
selectors, materialize completed `ElementNode` results, preserve nested-result
buffering **within their respective tree models**, and perform equivalent
serialization/extraction work — so measured differences reflect tree semantics, not
workload differences. The lexical variant is *not* required to reproduce full-DOM
ordering or subtree structure (those are exactly the semantics expected to diverge);
its semantic and ordering divergence from full-DOM `query()` is measured separately
and reported alongside its timings.

## Benchmark plan (gates all performance claims)

Four variants, isolating each cost:

1. Full DOM baseline, using the **cheapest existing operation that satisfies the
   scenario** (comparator equivalence below).
2. Incremental TreeBuilder with early stopping, **no pruning** — isolates the CPU
   cost of exact tree construction.
3. Incremental TreeBuilder with selective retention and safe pruning (approach A).
4. Enriched lexical stack (approach B) — the performance ceiling.

**Comparator equivalence (v4 re-review issue 1).** The baseline operation is defined
per workload so streaming is never compared against an API asked to do more work:

- *First matching element:* `selectFirst()` vs full parse + `queryFirst()`
  (external: `Dom\HTMLDocument::querySelector()`).
- *All matches:* complete `select()` iteration vs `query()`
  (external: `querySelectorAll()`, domdocument+XPath).
- *Predicate scenarios* (e.g., first **non-empty** paragraph): each implementation
  performs the semantically equivalent operation — for the current DOM API that may
  be `query()` plus iteration, because the first structural match is not necessarily
  the first acceptable result.
- Parse time and selector time are recorded separately as diagnostics in addition
  to end-to-end time.

**Match-position policy (v4 re-review issue 3).** Two byte offsets are recorded per
result, because they answer different questions:

- *Source offset* — the tokenizer position of the start tag that created the
  element. Foster-parented elements keep their source-token position even though
  their DOM position differs. Implicit, reconstructed, or cloned elements have no
  source start tag: they are **excluded from offset-based gate grouping** and
  reported separately.
- *Yield offset* — bytes consumed when the result crosses the ordered yield
  frontier. This is the measurement that says how much parsing early exit actually
  saved; a large gap between source and yield offsets is itself a key spike finding
  about how conservative the frontier is.

Gate grouping uses source offsets; yield offsets are reported for every gated
scenario.

Protocol:

- Report **first-result latency** and **total iteration time**, plus source/yield
  offsets, matched-subtree size, and the memory metrics defined below.
- Scenarios: single match, many matches, nested matches, broad selector (`body *`),
  and absent selector (worst case: full parse with no early exit).
- Record commit hash, fixture hashes, PHP version/configuration, and exact
  invocation commands with the results.
- Timing and live-node instrumentation runs identically across compared variants;
  where that is impossible, the instrumentation overhead is quantified separately.
- Fixtures: lead-paragraph (Wikipedia) and Common Crawl 1k.

Positioning claims are narrowed accordingly: early exit is an advantage over
*full-parse-then-query DOM APIs*, not over native streaming processors — Cloudflare's
lol_html is a native streaming CSS-selector engine and prior art for this design.

## Go/no-go gate (predeclared and operational — v3 re-review issue 2)

Thresholds confirmed at re-review: **≥2× lower first-result latency** and **≥50%
lower peak live-node count** than variant 1 (the workload-equivalent full-DOM
baseline), with correctness and maintainability hard requirements. Operational
definitions, fixed before any data is collected:

**Measurement procedure.** Each scenario runs in a fresh isolated subprocess per
variant: 3 warm-up iterations, then 10 measured iterations; the reported statistic
is the **median** (outlier handling is implicit in the median; min/max reported as
diagnostics).

**Memory metrics (v4 re-review issue 2).** The gate is a retention metric, named as
such — it is **not** memory in bytes (1,000 empty `<div>`s and 1,000 text nodes with
large payloads count the same):

- *Gating metric:* **peak live-node count** — the maximum number of simultaneously
  retained DOM nodes, instrumented in the tree builder. The counter includes **all
  retained node kinds**: element, text, comment, doctype, document/fragment, and
  template-content nodes. Yielded and queued results count as live; the harness
  retains yielded results for the duration of the scenario under the **same
  ownership/consumption policy in variants 1–3** so the comparison is fair.
- *Reported metric:* peak incremental process memory measured from a post-input
  baseline in the isolated subprocess — the actual byte-oriented measurement. The
  spike report includes both metrics and discusses any case where they disagree.

**Workload grouping.** Common Crawl documents are grouped by the first full-DOM
match's **source offset** under the match-position policy above (early = first
quartile of input length; late = last quartile) so early and late matches do not
obscure each other. Documents whose first match has no source offset (implicit or
reconstructed elements) are excluded from gate grouping and reported separately.

**The gate.** Approach A (variant 3) passes if all of the following hold; it does
not need to beat the lexical ceiling (variant 4):

1. **Correctness (hard):** zero divergence from full-DOM `query()` across the entire
   test oracle for the supported selector subset.
2. **Lead-paragraph scenario passes independently:** median first-result latency
   ≥2× better and peak live-node count ≥50% lower than variant 1.
3. **Common Crawl early-match aggregate passes:** the selector battery is
   predefined before any measurement, and the aggregation unit is **one ratio per
   document–selector case**. Over cases with an early match, the **geometric mean**
   of (variant 1 / variant 3) first-result latency ratios is ≥2, and the geometric
   mean of peak live-node-count ratios is ≥2 (i.e., ≥50% lower).
4. **Maintainability (hard):** no duplication of substantial TreeBuilder logic; all
   existing html5lib-compliance tests pass unchanged.

Broad, late, and absent matches are **reported diagnostics, not gates** (early exit
cannot help there by construction).

## Testing plan

Differential testing against Common Crawl 1k is a realism check, not the correctness
oracle. The oracle is DOM equivalence (under equivalent input decoding options):

- **Targeted tree-construction tests:** implicit `html`/`head`/`body`/`tbody`;
  optional/implied end tags; foster parenting; adoption-agency and nested formatting
  cases; ignored/reprocessed start tags; late-attribute merging onto `html`/`body`;
  templates; SVG/MathML integration points and adjusted names; void and foreign
  self-closing elements; EOF closure and finalization (`selectedcontent`).
- **Selector comparisons over html5lib tree-construction inputs**: run the supported
  selector subset over targeted html5lib cases and assert `select()` ≡ `query()`.
- **API-contract tests:** nested matches and document order, **ordered-yield cases**
  (selector lists where a late token changes an earlier element's match status, as
  in the frontier example), retained-tree sharing and parent behavior, selector-list
  deduplication, positional pseudos under late insertion, early break and generator
  cleanup, `SelectorError` thrown before iteration, and **mutation safety**:
  mutating a yielded node during iteration must not corrupt parser state (aliasing
  may intentionally be visible in queued results sharing the retained tree, but the
  parser itself must be unaffected).
- **Differential run over Common Crawl 1k** with a battery of representative
  selectors as the real-world regression net.

Fragment contexts are out of v1 scope: the proposed API accepts documents only.
Fragment selection is future work alongside a fragment-input API.

## Docs and positioning (completed, measurement-backed)

- Added `justhtml/select` rows to `benchmarks/lead_paragraph.php` (baseline-checked)
  and the performance suite.
- Rewrote `Streaming.md` with `Stream::select()` as the primary interface and
  kept the low-level event API documented for advanced use.
- Refreshed the README comparison table, which had shown
  12.6 ms/doc against a measured 4.43, underselling the library ~3×. (Standalone
  value independent of the streaming feature.)
- Repositioned the README around streaming selection using the measured results.

## Future work (out of scope)

- **Chunked/resource input** (parse-while-downloading): requires incremental
  tokenizer feeding; sequence after `select()` ships.
- **Fragment-context selection** (paired with a fragment-input API).
- **Deferred selector features:** `+`/`~` combinators, `:empty`, attribute case
  flags (the latter as a shared-engine enhancement benefiting `query()` too).
- **Tree-builder optimization** (separate agreed track): profile `TreeBuilder`/`Node`
  to close the gap with masterminds/html5. Independent; parallelizable — and any
  wins directly benefit approach A.
- **Lexical-semantics streaming API**: only through a separate proposal with a
  distinct name and explicitly different contract.

## Non-goals

- Competing with C parsers on raw full-document parse throughput.
- Full CSS selector support in streaming mode (subset only, enforced at compile time).
- Any change to the full-DOM parser's public API or its html5lib compliance.
  (Attribute-flag support, if ever added, would be a separately proposed shared
  enhancement, not part of this work.)

## Milestones

| # | Deliverable | Gate |
|---|-------------|------|
| 0 | Semantic contract decided; implementation-readiness, pre-benchmark clarifications, and benchmark-design details addressed | **Complete** — approved as spike spec at v4 re-review; benchmark tightening incorporated in v5 |
| 1 | Spike: prototype approaches A and B (private/experimental, no public API); required outputs: **ordered yield frontier**, pruning-safety taxonomy, 4-variant benchmark per the operational protocol | **Complete** — both approaches measured; design records and lexical source archived at `stream-select-spike-2026-07-18` |
| 2 | Go/no-go per the predeclared operational gate | **Complete** — approach A passed correctness, lead latency/retention, and maintainability gates; see the spike report |
| 3 | Finalize contract details in this doc: emission order, node ownership/aliasing, yield-frontier rules, EOF, exact selector grammar | **Complete** |
| 4 | Targeted tree-construction + differential + contract tests | **Complete** — targeted oracle, Common Crawl 1k oracle, and public API regressions pass |
| 5 | Public `Stream::select()` / `selectFirst()` stabilized | **Complete** |
| 6 | Benchmarks published, `Streaming.md` rewrite, README repositioning from measured results | **Complete** |

The independent README performance-table refresh landed with the public API.

---

## Appendix A: response to initial review (2026-07-17)

| Review point | Disposition |
|--------------|-------------|
| 1. Streaming stack ≠ DOM ancestor chain; choose a contract | **Accepted.** Contract decided: DOM equivalence as the north star, TreeBuilder-integrated implementation, validated by a dual-implementation spike. Lexical semantics only ever as a separate, explicitly-contracted API. The v1 claim that the stack was "exactly the ancestor chain" was wrong and is withdrawn. |
| 2. Stack lacks attributes and other match state | **Accepted.** Moot for approach A; called out as required enrichment for the spike-only lexical comparator. |
| 3. Completion timing, ordering, nesting, memory | **Accepted.** Contract specifies document order, shared-structure nested results, dedupe, EOF completion, honest memory expectations. (Refined in v3/v4 — see Appendices B/C.) |
| 4. Detached-node surface overpromise | **Accepted.** API promises completed `ElementNode` results with documented limits on ancestor-dependent operations. "Full existing surface" wording withdrawn. |
| 5. Performance positioning premature; lol_html mischaracterized | **Accepted.** All positioning gated on benchmarking real prototypes; early-exit claim narrowed; lol_html cited as prior art. |
| Compiler details, testing recommendations, milestone order | **Accepted** as proposed. |

## Appendix B: response to v2 re-review (2026-07-17)

| Re-review issue | Disposition |
|-----------------|-------------|
| 1. Detached-node contract contradicted nested-result sharing | **Accepted.** Adopted the reviewer's wording; aliasing documented; deep copies rejected as potentially quadratic. |
| 2. Completion ≠ finality | **Accepted.** Stability-based contract adopted. (Extended to ordered-yield in v4 — see Appendix C.) |
| 3. TreeBuilder stack ≠ DOM ancestor chain under foster parenting | **Accepted.** Approach A evaluates against the inserted node's actual parent chain; observation sees implicit/reconstructed/finalization-created elements; "by construction" withdrawn. |
| 4. Safe pruning is a hypothesis | **Accepted.** Minimum-retention framing; discardability taxonomy required from spike. |
| 5. Make the comparison equivalent | **Accepted.** Same selectors and result materialization across prototypes; 4-variant benchmark matrix. (Wording refined in v4 — see Appendix C.) |
| 6. Predeclare the go/no-go threshold | **Accepted.** Gate predeclared: correctness hard-required; ≥2× first-result latency and ≥50% peak-memory improvement on selective workloads; no TreeBuilder destabilization. (Operationalized in v4.) |
| 7. Smaller API details | **Accepted.** `Stream::stream()`-style encoding/bytes options; existing `SelectorError`; fragment contexts deferred; `:empty` reclassified as deliberately deferred. |

## Appendix C: response to v3 re-review (2026-07-17)

| Re-review item | Disposition |
|----------------|-------------|
| 1. Stability must cover earlier nonmatches | **Accepted.** The contract now defines an **ordered yield frontier** with the reviewer's three conditions (own stability, earlier-element stability, no earlier insertion possible); `selectFirst()` stops at first *yieldable* match. Added as a required Milestone 1 output (superseding the per-node frontier) and to the API-contract tests, including the selector-list example. |
| 2. Operationalize the numeric gate | **Accepted.** Gate now specifies: fresh-subprocess runs, 3 warm-up + 10 measured iterations, median statistic; lead-paragraph must pass independently; Common Crawl early-match aggregate (first-quartile match offset) passes via geometric mean of per-document ratios; broad/late/absent are diagnostics. Memory gate is **maximum simultaneously-live DOM node count** (structural); peak incremental process memory from a post-input baseline is reported, not gating. |
| 3. Clarify "equivalent comparison" for the lexical spike | **Accepted.** Adopted the reviewer's wording: same selector compilation, result materialization, nested-result buffering *within each tree model*, and equivalent extraction work; the lexical variant is not required to reproduce full-DOM ordering/structure — its divergence is measured and reported separately. |
| 4. Sibling-combinator rationale wrong | **Accepted — v3 was in error.** `+`/`~` inspect previous siblings and the DOM matcher already evaluates them backward; they are now "deliberately deferred," not future-dependent. Positional pseudos are evaluated against actual TreeBuilder sibling state and fall under the ordered-yield rules rather than start-time counters alone. |
| 5. Attribute flags vs DOM parity | **Accepted — option 1.** The streaming subset inherits the current `query()` attribute grammar; "standard attribute operators" now means exactly the operators `query()` supports. Flags (`[... i]`) are deferred and, if ever added, would be a separately proposed shared-engine enhancement (also noted under Non-goals). |
| 6. Small contract polish | **Accepted.** The equivalence oracle is defined as `query()` under equivalent input decoding options; "snapshot" terminology replaced with "completed nodes in a detached retained tree" throughout. |

## Appendix D: response to v4 re-review (2026-07-17)

| Re-review item | Disposition |
|----------------|-------------|
| 1. Compare equivalent first-result operations | **Accepted.** The baseline is now defined per workload: `selectFirst()` vs `queryFirst()`/`querySelector()`; full `select()` iteration vs `query()`/`querySelectorAll()`; predicate scenarios (first *non-empty* paragraph) use the semantically equivalent operation per implementation, which for the current DOM API may be `query()` plus iteration. Variant 1 uses the cheapest existing operation satisfying the scenario; parse and selector time are recorded separately as diagnostics. |
| 2. Name the memory gate what it measures | **Accepted.** The gate is renamed **≥50% lower peak live-node count** and explicitly documented as a retention metric, not bytes. The counter includes all retained node kinds (element, text, comment, doctype, document/fragment, template content); yielded and queued results count as live under the same ownership policy in variants 1–3. Incremental process memory remains the reported byte-oriented metric, and the spike report must discuss any disagreement between the two. |
| 3. Define source and yield-frontier offsets | **Accepted.** Two offsets are recorded: *source offset* (tokenizer position of the creating start tag; foster-parented elements keep it; implicit/reconstructed/cloned elements have none and are excluded from gate grouping, reported separately) and *yield offset* (bytes consumed at ordered-yield-frontier crossing — what early exit actually saved). Gate grouping uses source offsets; yield offsets are reported for every gated scenario, and large source/yield gaps are flagged as a frontier-conservatism finding. |
| Minor spike-report details | **Accepted.** The selector battery and aggregation unit (one ratio per document–selector case) are predefined before measurement; instrumentation runs identically across compared variants or its overhead is quantified separately; mutation-during-iteration safety (parser state must survive yielded-node mutation) is added to the contract tests. |
