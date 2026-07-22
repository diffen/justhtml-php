# Ordered yield frontier (Milestone 1 spike output)

Status: implemented in `src/JustHTML/StreamSelect.php` (approach A);
verified against the differential oracle (`spike/differential.php`). This
document is the required Milestone 1 deliverable from
[the proposal](../proposal-streaming-select.md): the rules under which a
completed match may be yielded before EOF while preserving the contract that
`select()` emits exactly the elements final-DOM `query()` returns, in document
order.

## Contract restated

A queued match E is yieldable only when (proposal, "Ordered yield frontier"):

1. E's own subtree and match status are stable under any remaining
   tree-construction operations.
2. Every earlier element's match/nonmatch status is stable.
3. No remaining tree-construction operation can insert a new matching element
   before E in document order.

The engine is conservative: anything not provably yieldable waits, and at EOF
a final `query()` sweep over the finished document emits everything not yet
yielded, in query order, deduplicated by node identity. Early yields are
therefore the only soundness-critical path; delayed yields cost latency, never
correctness.

## Mechanism inventory: what can still change a "finished" fact

Derived from `TreeBuilder.php` / `TreeBuilderModes.php`; each is either
excluded at compile time, held positionally, or held globally.

| # | Mechanism (code path) | What it can change | Frontier treatment |
|---|----------------------|--------------------|--------------------|
| M1 | Late `<html>`/`<body>` start tags merge *missing* attributes onto the existing elements (`_handle_body_start_html`, `_handle_body_start_body`, `_mode_after_*` reprocessing). Possible until EOF. | Match status of any element whose (ancestor-)compound is attribute-dependent and could apply to html/body. Merges only **add** attributes, so matches flip non-match→match only — except under `:not(...)`, where a match can flip to non-match. | Compile-time hazard flags + runtime *rescuables* (below). `:not` case: global EOF hold. |
| M2 | Foster parenting (`_appropriate_insertion_location` with `TABLE_FOSTER_TARGETS`): while a `table` is on the open stack, misnested content is inserted **immediately before that table** — earlier in document order than everything inside it. | Cond. 3 (new earlier matches) and sibling positions (positional pseudos) of the table and later siblings. | Positional blocker at the earliest open `table`'s document position. Candidates strictly before it are unaffected (fosters land between them and the table). |
| M3 | Adoption agency (`_adoption_agency`): reparents stack elements and completed subtrees between a formatting element on the stack and the furthest block. | Document positions and ancestor chains of already-completed elements. | Positional blocker at the earliest active-formatting entry whose node is **on the open stack** (AAA aborts for entries not on the stack; reconstruction clones insert at the current point, which is after every completed candidate). |
| M4 | Formatting reconstruction (`_reconstruct_active_formatting_elements`): clones entries into **new** elements at the current insertion point. | Creates new potential matches — but always at the insertion point, i.e. after every completed candidate in document order. | No hold needed (covered by the insertion-point argument, below). |
| M5 | Implicit elements (`_create_root`, `_insert_body_if_missing`, phantom head/tbody, AAA clones): elements with no source start tag. | Candidate discovery must not assume a source token. | Observation is tree-based (stack diff + watched-parent child scans), so implicit elements are seen like any others. They are flagged "no source offset" for the benchmark's match-position policy. |
| M6 | `<frameset>` in body while `frameset_ok` (`_handle_body_start_frameset`): **removes the body element and its entire subtree**. | Previously "completed" body content vanishes from the final DOM. | Positional blocker at the body element's position while `frameset_ok` is true. Head content (before body) is unaffected and may still yield. |
| M7 | EOF finalization `_populate_selectedcontent` (`TreeBuilder.php:1290`): clones the selected option's children into every `selectedcontent` element after parsing. | Creates new elements (potential matches) inside completed subtrees, at positions possibly before later candidates. | Global hold once any `selectedcontent` element is observed. |
| M8 | Template content (`TemplateNode->templateContent`): a separate fragment whose nodes sit at the template's position in `query()` order but have no main-tree path; content is inserted only while the template is open. | Ordering between main-tree candidates and template-content matches. | Positional blocker at each live template element's position. A closed template that retains no matches/rescuables is released from the blocker set. Template-content candidates themselves never yield early (emitted by the EOF sweep). |
| M9 | AFTER_HEAD head re-push (`_mode_after_head` for base/link/meta/…): inserts into the closed head element. | New elements in the head after `</head>`. | No hold needed: insertions append at the end of head's children — after every completed head candidate, before body. Covered by the insertion-point argument. |
| M10 | Character reprocessing / text merging (`_append_text`): text appends to the current node or fosters. | Subtree text of *open* elements only (append) or foster positions (M2). | Open elements are never yielded; M2 covers fosters. |

## The insertion-point argument

All node creation inserts into an element on the open stack, the document, the
head element (M9), or an open template's content (M8) — at the **end** of the
target's child list, except foster parenting (M2). After a candidate E
completes, every open element is either an ancestor of E (whose child-list
tail is after E) or an element opened after E completed (positioned after E,
absent M2/M3). Hence, with the M2 and M3 blockers clear for positions before
E, no remaining operation can create or move a node to a position before E.
This is the justification for condition 3 and for the stability of sibling
positions (positional pseudos) of completed elements.

## Compile-time hazard analysis (`SelectCompiler`)

For every compound in the selector (including inside `:not`):

- *admits-html/body*: no type selector, or type `html`/`body`.
- *attribute-dependent*: contains id/class/attribute simple selectors.

Flags:

- **hazardAttrMerge** — some compound outside `:not` is admits-html/body ∧
  attribute-dependent. M1 can flip earlier non-matches to matches; enables the
  rescuable machinery. Positive matches stay stable (merges only add).
- **hazardNotAttrMerge** — such a compound occurs under `:not`. A current
  *match* can be un-matched by M1; **no early yield is sound** → global EOF
  hold.
- **usesPositionalPseudos** — restricts pruning (see the
  [pruning-safety taxonomy](pruning-taxonomy.md)) and interacts with M2.

Key practical consequence, measured by the benchmarks: `#content p` carries
hazardAttrMerge (a bare `#content` could match a body that later gains that
id), while `div#content p` does not — the type selector excludes html/body,
killing the hazard. Tag-qualifying attribute compounds is the user-facing
advice that restores maximal streaming.

## Runtime machinery

**Completion detection.** The engine steps the tokenizer and, after each token
that reached the tree builder, diffs the open-element stack (common prefix;
everything popped is complete — innermost first) and rescans watched parents
(document, stack elements, head, html, body, open template contents) whose
child counts changed. Elements discovered outside the stack (voids, subtrees
closed within one token) complete post-order at registration. Nodes are
anchored by strong reference because `spl_object_id` values recycle after GC.

**Candidate evaluation** happens once, at completion, with the shared
`SelectorMatcher` against the real parent chain — attributes of completed
elements and non-html/body ancestors are immutable, and sibling positions are
stable for positions before the M2/M3 blockers, so the verdict is stable
except for the M1 rescue path.

**Rescuables** (only when hazardAttrMerge ∧ ¬hazardNotAttrMerge): a completed
non-match that `RescueMatcher` says could still match if html/body gain
attributes (optimistic exactly where `_add_missing_attributes` could still
supply a currently-absent attribute; a present attribute can never change).
The earliest rescuable is a positional blocker: nothing at/after it yields
early. On every observed html/body attribute-count change, rescuables are
re-evaluated — exact matches move to the queue (this is how the proposal's
`body.x p` example yields correctly), dead ones are dropped.

**Yield release.** A queued match E (with a computable main-tree path) is
released when:

- no global hold (hazardNotAttrMerge, `selectedcontent` seen, unpositionable
  rescuable);
- E is strictly before the minimum positional blocker (earliest open table,
  earliest on-stack formatting entry, live template elements, body-if-
  frameset-possible, earliest rescuable);
- every open element positioned before E is a stable non-match (an open
  element that currently matches will complete and yield first — outer before
  inner; under hazardAttrMerge, html/body are additionally checked with the
  rescue matcher).

Blocked candidates are parked keyed by the active blocker, so a long-blocked
queue costs O(new candidates) per token, not O(queue).

**Document-order comparison** uses root paths (child indexes up to
`#document`), computed fresh at release time because foster insertions shift
indexes; relative order of existing nodes changes only under M3, which is
blocked.

## Known conservatisms (measured, not correctness issues)

- Any open table/template/formatting entry holds *all* candidates positioned
  after it, even those a deeper analysis could clear.
- Template-content matches never yield early.
- `body *`-style selectors that match `html`/`body` cannot yield anything
  before those elements complete (EOF) — inherent to document-order exactness,
  not to this design.
- A selector-wide `:not(html/body-attr)` hazard suppresses all early yields.

## Residual risks accepted for the spike (oracle-guarded)

- The cached earliest-rescuable and parked-queue optimizations assume relative
  document order of existing nodes is stable while their blockers are absent;
  M3 sequences that also involve rescuables could in principle invalidate the
  cache. The differential oracle (targeted + Common Crawl) is the guard; no
  divergence observed.
- Source-offset attribution ("element created by the current start tag") is
  heuristic when reconstruction clones share the token's tag name; affects
  benchmark grouping only, never results.
