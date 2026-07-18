# Streaming selector design spike report

Date: 2026-07-18
Decision: adopt approach A (TreeBuilder-integrated selection); do not retain
approach B (lexical selection) in the maintained source tree.

## What “spike” means

A **spike** is a temporary experimental implementation built to answer uncertain
design questions. It is used to learn about feasibility, correctness,
performance, and tradeoffs; it is not intended to become the final maintained
feature unchanged.

This spike implemented two competing streaming-selector designs, tested both,
and measured their performance before choosing one. The complete experiment,
including the rejected lexical implementation, is preserved at the annotated
Git tag:

```text
stream-select-spike-2026-07-18
```

The maintained branch removes the lexical implementation after that tagged
snapshot. To inspect it without changing the current branch:

```bash
git show stream-select-spike-2026-07-18:src/JustHTML/Experimental/LexicalSelect.php
```

## Designs evaluated

### Approach A: TreeBuilder-integrated

Approach A observes the real HTML5 TreeBuilder, evaluates selectors against the
actual DOM ancestor chain, and releases completed matches through a conservative
ordered-yield frontier. Its pruning variant removes subtrees only when they are
proven irrelevant to later tree construction, selector evaluation, results, and
EOF finalization.

This approach can reproduce final-DOM `query()` semantics, including implicit
elements, foster parenting, formatting reconstruction, the adoption agency,
late `html`/`body` attribute merges, templates, and `selectedcontent`
finalization.

### Approach B: enriched lexical stack

Approach B matches against an enriched tokenizer-level stack with attributes,
namespace-adjusted names, sibling counters, and limited implied-end handling.
It avoids the full TreeBuilder and establishes the performance ceiling for a
PHP streaming selector, but it does not reproduce all HTML5 tree-construction
recovery behavior.

## Correctness results

The oracle compares result order, result count, and complete subtree
serialization with final-DOM `query()`.

| Implementation | Targeted/adversarial cases | Common Crawl 1k |
|---|---:|---:|
| A, no pruning | 95/95 (100%) | 18,000/18,000 (100%) |
| A, pruning | 95/95 (100%) | 18,000/18,000 (100%) |
| B, lexical | 58/95 (61.1%) | 17,998/18,000 (99.9889%) |

Both real-world lexical divergences occurred in `000943.html`:

- `#content` returned one element in both engines, but its subtree differed.
- `body *` returned 1,353 lexical results instead of the DOM's 1,357.

The divergence appears around misnested `b`/`em`/`a` formatting, where the
HTML5 adoption-agency algorithm restructures the DOM. That behavior is absent
from the lexical stack by design. The high corpus agreement therefore does not
support an exact-DOM contract; the adversarial suite demonstrates the semantic
gap directly.

The existing project suite also passed with 8,935 tests passing, zero failures,
and eight skips.

## Performance results

Environment: PHP 8.5.8, Xdebug disabled, CLI opcache enabled. Each benchmark
case ran in a fresh subprocess with three warm-ups and ten measured iterations;
the median is reported. Common Crawl aggregate ratios are geometric means over
100 documents and seven selectors (700 document-selector cases).

### Common Crawl: direct A-versus-B comparison

| Workload group | Cases | B speedup vs A/no-prune | B speedup vs A/prune | A/prune peak nodes relative to B |
|---|---:|---:|---:|---:|
| Early match | 204 | 2.20x | 2.15x | 0.77x |
| Mid-document match | 196 | 3.17x | 3.59x | 1.33x |
| No match | 300 | 3.01x | 3.38x | 0.48x |
| Overall | 700 | 2.78x | **3.01x** | **0.73x** |

Pruning added about 8% CPU overhead to approach A overall. Despite B's speed,
pruned A retained 27% fewer peak live nodes overall by geometric mean.

### Wikipedia lead fixture

| Scenario | DOM baseline | A/prune | B/lexical | B speedup vs A/prune |
|---|---:|---:|---:|---:|
| First non-empty lead paragraph | 58.27 ms | 24.96 ms | 7.19 ms | 3.47x |
| First `p` | 56.04 ms | 16.05 ms | 5.41 ms | 2.97x |
| All 90 selected paragraphs | 57.82 ms | 150.19 ms | 39.12 ms | 3.84x |
| First table cell | 55.88 ms | 25.76 ms | 5.48 ms | 4.70x |
| Broad `body *` (11,980 results) | 70.16 ms | 2,943.11 ms | 1,306.58 ms | 2.25x |

On the headline scenario, pruned A is 2.33x faster than full-DOM parsing and
reduces peak live nodes from 24,298 to 172, so it passes the predeclared lead
gate. Broad all-results selection is a poor streaming workload for both designs
and remains a diagnostic rather than a gate.

## Decision

Approach A is the implementation path for `Stream::select()` because exact DOM
equivalence is a hard requirement. Approach B is approximately three times
faster and is useful as a measured performance ceiling, but even rare silent
divergence is incompatible with the promised contract. A future lexical API
would require a separate name and an explicitly different semantic contract.

## Supporting records

- [Proposal and benchmark protocol](../proposal-streaming-select.md)
- [Ordered-yield frontier](frontier.md)
- [Pruning-safety taxonomy](pruning-taxonomy.md)
- [Result-file notes](../../spike/results/README.md)
- `spike/results/lead-20260718-062311.jsonl`
  - SHA-256: `1c428f9844bd80d36c9f2eb076512fa45c24ebf2416b8f10c1f7566185ea1fb4`
- `spike/results/cc-20260718-062533.jsonl`
  - SHA-256: `7f1e52db769bf1e8cb7576aed7c751c70ab21b14cda5eb64fbe86e800b5eae25`

The raw result metadata names base commit `2ed62dd` because measurements were
made before the experimental files were committed. The archival tag above is
the authoritative source snapshot for the measured A and B implementations.
