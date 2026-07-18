# Streaming select pruning-safety taxonomy

Status: Milestone 1 spike output. This taxonomy describes the conservative
conditions implemented by `Experimental\StreamSelect` for benchmark variant 3.
It is not a public API guarantee.

## Unit of pruning

Pruning operates on a completed, non-matching element subtree. A subtree is
removed only when it cannot affect later tree construction, cannot become or
contain a selector result, and is not needed to preserve selector state. When
sibling-position pseudos are in use, the element is replaced by a childless,
attribute-free tombstone with the same name and namespace instead of being
removed outright.

## Never discard while parser-live

A candidate is not discardable when any of the following holds:

- the element is still on the open-element stack;
- it is `html`, `head`, or `body`, or is the TreeBuilder's head/form pointer;
- it or a descendant is a `select` or `selectedcontent`, because EOF
  `selectedcontent` population reads option subtrees;
- it or an ancestor is a queued match or a late-attribute rescuable;
- an open ancestor currently matches, or could become a match through a late
  `html`/`body` attribute merge;
- an active table can still foster-parent content in a way that changes
  positional-pseudo state;
- a match or rescuable in template content retains the owning template.

These checks cover both positive late-attribute hazards and selectors that also
contain attribute-dependent `:not(...)`. The negated hazard imposes a global
EOF yield hold, but does not disable the positive rescue analysis pruning needs.

## Discardable completed non-matches

Once all vetoes above are clear, a completed non-match is classified as:

- **removable** when the selector has no sibling-position pseudo. The subtree
  is detached and all identity-keyed observer state, including template-owner
  mappings, is released.
- **tombstoned** for `:first-child`, `:nth-child`, `:first-of-type`, and
  `:nth-of-type`. Descendants and attributes are released, while the shell
  preserves later siblings' element and type positions.
- **retained** when any safety proof is unavailable. Conservative retention can
  increase latency or memory but cannot change selector results.

## Result ownership

Parser-tree nodes are never exposed to consumers. At the yield frontier, the
first result in an overlapping completed region deep-clones that region into a
detached retained tree. Original-to-clone identity indexing lets nested results
reuse the same clone. The benchmark's live-node counter includes these clone
nodes in addition to parser-retained nodes.

## Known conservatisms

- Parser pointer and `select` checks retain whole subtrees rather than proving
  which descendants finalization will inspect.
- Positional selectors defer all pruning while a table is open.
- Retention marks are monotonic for the lifetime of the engine; an original
  parser subtree may remain alongside its detached result clone until parsing
  stops or reaches EOF.
- Template-content matches wait for the EOF sweep because their fragment paths
  are not comparable directly with main-document paths.
