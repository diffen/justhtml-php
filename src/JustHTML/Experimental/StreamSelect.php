<?php

declare(strict_types=1);

namespace JustHTML\Experimental;

use JustHTML\ElementNode;
use JustHTML\Encoding;
use JustHTML\Selector;
use JustHTML\SelectorMatcher;
use JustHTML\Tag;
use JustHTML\TemplateNode;
use JustHTML\Tokenizer;
use JustHTML\TreeBuilder;

/**
 * @internal Milestone 1 spike (docs/proposal-streaming-select.md). Not public API.
 *
 * Approach A: selector observation hooked onto the real TreeBuilder. The
 * engine drives the tokenizer step by step, observes node creation and
 * element completion from the outside (stack diff + watched-parent child
 * scans — no TreeBuilder logic is duplicated), evaluates candidates with the
 * shared SelectorMatcher against the real parent chains, and releases matches
 * through the ordered yield frontier documented in
 * docs/spike-streaming-select/frontier.md. Everything still pending at EOF is
 * emitted by a final query() sweep, so early yields are the only place the
 * frontier soundness matters — the differential oracle checks exactly that.
 */
final class StreamSelectStats
{
    public int $nodesCreated = 0;
    public int $nodesPruned = 0;
    public int $peakLiveNodes = 0;
    public int $liveNodes = 0;
    public int $bytesTotal = 0;
    /** Bytes consumed when the first result crossed the yield frontier. */
    public ?int $firstYieldOffset = null;
    /**
     * Per result: source offset (tokenizer position of the creating tag, null
     * for implicit/reconstructed elements), yield offset, and whether the
     * result crossed the frontier before EOF.
     * @var array<int, array{src:?int,yield:int,early:bool}>
     */
    public array $results = [];

    public function addLive(int $n): void
    {
        $this->liveNodes += $n;
        if ($this->liveNodes > $this->peakLiveNodes) {
            $this->peakLiveNodes = $this->liveNodes;
        }
    }
}

/**
 * @internal Spike-only. Exposes token-boundary activity so the engine only
 * rescans after tokens that actually reached the tree builder, and records
 * whether the last processed token was a start tag (for source-offset
 * attribution under the match-position policy).
 */
final class ObservingTreeBuilder extends TreeBuilder
{
    public bool $dirty = false;
    public ?string $lastStartTagName = null;

    public function processToken($token): int
    {
        $this->dirty = true;
        $this->lastStartTagName = ($token instanceof Tag && $token->kind === Tag::START)
            ? strtolower($token->name)
            : null;
        return parent::processToken($token);
    }

    public function processCharacters(string $data): int
    {
        $this->dirty = true;
        $this->lastStartTagName = null;
        return parent::processCharacters($data);
    }
}

/**
 * @internal Spike-only.
 */
final class StreamSelect
{
    /**
     * Options:
     *  - encoding/bytes: mirror Stream::stream() input handling
     *  - prune: bool (benchmark variant 3 when true, variant 2 when false)
     *  - stats: StreamSelectStats to fill with instrumentation (adds overhead;
     *    keep timing runs and node-count runs separate)
     *
     * @param array<string, mixed> $opts
     */
    public static function select($html, string $selector, array $opts = []): \Generator
    {
        // Compile eagerly so SelectorError throws at call time, not on first
        // iteration (generator bodies do not run until iterated).
        $compiled = SelectCompiler::compile($selector);
        return self::run($html, $compiled, $opts);
    }

    /** @param array<string, mixed> $opts */
    public static function selectFirst($html, string $selector, array $opts = []): ?object
    {
        foreach (self::select($html, $selector, $opts) as $node) {
            return $node;
        }
        return null;
    }

    /** @param array<string, mixed> $opts */
    private static function run($html, CompiledStreamSelector $compiled, array $opts): \Generator
    {
        if ($html === null) {
            $htmlStr = '';
        } elseif (!empty($opts['bytes'])) {
            [$htmlStr, $_] = Encoding::decodeHtml((string)$html, $opts['encoding'] ?? null);
        } else {
            $htmlStr = (string)$html;
        }

        $engine = new StreamSelectEngine($compiled, $opts);
        yield from $engine->parse($htmlStr);
    }
}

/**
 * @internal Spike-only.
 */
final class StreamSelectEngine
{
    private CompiledStreamSelector $compiled;
    private bool $prune;
    private ?StreamSelectStats $stats;

    private ObservingTreeBuilder $tb;
    private Tokenizer $tokenizer;
    private SelectorMatcher $matcher;
    private ?RescueMatcher $rescue = null;

    /** @var array<int, bool> spl ids of every node ever observed */
    private array $seen = [];
    /**
     * Strong references to observed nodes. spl_object_id values are recycled
     * once a node is garbage-collected, which would corrupt every id-keyed
     * map here; anchoring prevents that. Pruning removes the anchors (and all
     * per-id state) for the subtree it frees, so variant 3 still releases
     * memory.
     * @var array<int, object>
     */
    private array $anchors = [];
    /** @var array<int, bool> elements whose completion has been processed */
    private array $completed = [];
    /** @var array<int, int> watched parent id => last observed child count */
    private array $watchCounts = [];
    /** @var array<int, object> previous open-element stack */
    private array $prevStack = [];
    /** @var array<int, bool> ids of elements on the stack as of the current scan */
    private array $currentOpenIds = [];
    /** @var array<int, int> element id => source offset at creation */
    private array $srcOffsets = [];
    /** @var array<int, bool> element id => had a plausible source start tag */
    private array $hasSourceTag = [];

    /** @var array<int, object> pending matched results, insertion order */
    private array $queue = [];
    /** @var array<int, bool> */
    private array $queuedIds = [];
    /** @var array<int, bool> */
    private array $yieldedIds = [];
    /**
     * Detached result nodes keyed by the corresponding parser-tree node id.
     * The first result in an overlapping region clones the completed subtree;
     * later nested results reuse nodes from that clone so result aliasing is
     * preserved without exposing the live TreeBuilder document.
     * @var array<int, object>
     */
    private array $materialized = [];
    /** @var array<int, object> non-matches that a late html/body merge could rescue */
    private array $rescuables = [];
    /** Earliest-positioned rescuable (cached; relative order of existing nodes is stable). */
    private ?object $minRescuable = null;
    private bool $rescuableUnpositionable = false;
    /**
     * Candidates already known to sit at/after the active blocker are parked
     * until the blocker changes, so a long-blocked queue costs O(new) per
     * drain instead of O(queue).
     */
    private ?string $activeBlockerKey = null;
    /** @var array<int, bool> */
    private array $parkedIds = [];
    /** @var array<int, bool> nodes that must never be pruned */
    private array $retained = [];
    /** @var array<int, bool> cached "open element matches selector" results */
    private array $openMatchCache = [];
    /**
     * Completed non-matches pending a prune decision. Pruning is deferred to
     * the end of the scan so that every element completing in the same token
     * batch is evaluated (and retention-marked) before anything is dropped —
     * an inner match must protect itself before its non-matching parent is
     * considered, and a matching parent must protect its children.
     * @var array<int, object>
     */
    private array $pruneCandidates = [];

    private ?object $htmlEl = null;
    private ?object $bodyEl = null;
    private int $mergeSig = -1;
    private bool $sawSelectedContent = false;
    /**
     * Template elements block yields positioned after them: their content
     * fragments sit at the template's document position in query() order but
     * content nodes have no computable main-tree path.
     * @var array<int, object>
     */
    private array $templateNodes = [];
    /** @var array<int, object> template-content fragment root id => template element */
    private array $templateOwners = [];
    private bool $eofReached = false;

    /** @param array<string, mixed> $opts */
    public function __construct(CompiledStreamSelector $compiled, array $opts)
    {
        $this->compiled = $compiled;
        $this->prune = !empty($opts['prune']);
        $stats = $opts['stats'] ?? null;
        $this->stats = $stats instanceof StreamSelectStats ? $stats : null;
        $this->matcher = new SelectorMatcher();
        if ($compiled->hazardAttrMerge) {
            $this->rescue = new RescueMatcher();
        }
    }

    public function parse(string $html): \Generator
    {
        $this->tb = new ObservingTreeBuilder();
        $this->tokenizer = new Tokenizer($this->tb);
        $this->tb->tokenizer = $this->tokenizer;
        $this->tokenizer->initialize($html);

        if ($this->stats) {
            $this->stats->bytesTotal = strlen($html);
            $this->stats->nodesCreated++;
            $this->stats->addLive(1); // the document node
        }
        $this->seen[spl_object_id($this->tb->document)] = true;

        while (true) {
            $isEof = $this->tokenizer->step();
            if ($this->tb->dirty) {
                $this->tb->dirty = false;
                $this->scan();
                if ($this->queue && !$isEof) {
                    foreach ($this->drainYieldable() as $node) {
                        yield $node;
                    }
                }
            }
            if ($isEof) {
                break;
            }
        }

        $this->eofReached = true;
        $document = $this->tb->finish();
        yield from $this->finalSweep($document);
    }

    // ------------------------------------------------------------------
    // Observation
    // ------------------------------------------------------------------

    private function scan(): void
    {
        $stack = $this->tb->open_elements;
        $this->currentOpenIds = [];
        foreach ($stack as $el) {
            $this->currentOpenIds[spl_object_id($el)] = true;
        }

        // Stack diff: elements that left the stack completed (or were
        // relocated by the adoption agency — either way their subtree is no
        // longer the insertion point and they are candidates now).
        $prev = $this->prevStack;
        $common = 0;
        $max = min(count($prev), count($stack));
        while ($common < $max && $prev[$common] === $stack[$common]) {
            $common++;
        }
        for ($i = count($prev) - 1; $i >= $common; $i--) {
            $node = $prev[$i];
            $id = spl_object_id($node);
            if (!isset($this->seen[$id])) {
                $this->registerSubtree($node);
            } else {
                // Text flushed in the same tokenizer step as the end tag
                // lands in the element right before it pops; pick up any
                // children observed counts have not seen yet.
                $children = $node->children ?? null;
                if ($children !== null && ($this->watchCounts[$id] ?? 0) !== count($children)) {
                    $this->watchCounts[$id] = count($children);
                    foreach ($children as $child) {
                        if (!isset($this->seen[spl_object_id($child)])) {
                            $this->registerSubtree($child);
                        }
                    }
                }
            }
            $this->completeElement($node);
        }
        for ($i = $common, $n = count($stack); $i < $n; $i++) {
            $node = $stack[$i];
            if (!isset($this->seen[spl_object_id($node)])) {
                $this->registerSubtree($node);
            }
        }
        $this->prevStack = $stack;

        // Watched-parent child scans. All insertions land in an open element,
        // the document, the head element (AFTER_HEAD push-back), html/body
        // (late merges append nothing, but body reopens via reprocessing), or
        // an open template's content fragment.
        $watched = [$this->tb->document];
        foreach ($stack as $el) {
            $watched[] = $el;
            if ($el instanceof TemplateNode && $el->templateContent !== null) {
                $watched[] = $el->templateContent;
            }
        }
        if ($this->tb->head_element !== null) {
            $watched[] = $this->tb->head_element;
        }
        if ($this->htmlEl !== null) {
            $watched[] = $this->htmlEl;
        }
        if ($this->bodyEl !== null) {
            $watched[] = $this->bodyEl;
        }

        foreach ($watched as $parent) {
            $children = $parent->children ?? null;
            if ($children === null) {
                continue;
            }
            $pid = spl_object_id($parent);
            $count = count($children);
            if (($this->watchCounts[$pid] ?? 0) === $count) {
                continue;
            }
            $this->watchCounts[$pid] = $count;
            foreach ($children as $child) {
                if (!isset($this->seen[spl_object_id($child)])) {
                    $this->registerSubtree($child);
                }
            }
        }

        // Deferred prune decisions, after the whole batch is evaluated.
        if ($this->pruneCandidates) {
            foreach ($this->pruneCandidates as $node) {
                $id = spl_object_id($node);
                if (isset($this->seen[$id])) { // skip if a pruned ancestor already freed it
                    $this->maybePrune($node, $id);
                }
            }
            $this->pruneCandidates = [];
        }

        // Late-attribute-merge detection on html/body.
        if ($this->rescue !== null) {
            $sig = ($this->htmlEl !== null ? count($this->htmlEl->attrs ?? []) * 100000 : 0)
                + ($this->bodyEl !== null ? count($this->bodyEl->attrs ?? []) : 0);
            if ($sig !== $this->mergeSig) {
                $wasInitialized = $this->mergeSig !== -1;
                $this->mergeSig = $sig;
                if ($wasInitialized && $this->rescuables) {
                    $this->reevaluateRescuables();
                }
                $this->openMatchCache = [];
            }
        }
    }

    /**
     * Registers a newly observed node and its current subtree. Elements not
     * on the open stack are already complete (void elements, subtrees built
     * and closed within a single token) and are completed post-order.
     * Openness is decided per node against the current stack — a watched
     * parent's recursion may reach nodes that are themselves open.
     */
    private function registerSubtree($node): void
    {
        $id = spl_object_id($node);
        $this->seen[$id] = true;
        $this->anchors[$id] = $node;
        $isOpen = isset($this->currentOpenIds[$id]);
        if ($this->stats) {
            $this->stats->nodesCreated++;
            $this->stats->addLive(1);
        }

        $name = (string)($node->name ?? '');
        $isElement = $name !== '' && $name[0] !== '#' && $name !== '!doctype';

        if ($isElement) {
            $this->srcOffsets[$id] = $this->tokenizer->pos;
            $lower = ($node->namespace ?? 'html') === 'html' ? strtolower($name) : $name;
            $this->hasSourceTag[$id] = $this->tb->lastStartTagName !== null
                && strtolower($name) === $this->tb->lastStartTagName;

            if ($lower === 'selectedcontent') {
                $this->sawSelectedContent = true;
            }
            if ($lower === 'body' && $this->bodyEl === null
                && ($node->parent !== null && $node->parent->name === 'html')) {
                $this->bodyEl = $node;
            }
            if ($lower === 'html' && $this->htmlEl === null
                && ($node->parent !== null && $node->parent->name === '#document')) {
                $this->htmlEl = $node;
            }
            if ($node instanceof TemplateNode) {
                $this->templateNodes[$id] = $node;
            }
        }

        foreach ($node->children ?? [] as $child) {
            if (!isset($this->seen[spl_object_id($child)])) {
                $this->registerSubtree($child);
            }
        }
        if ($node instanceof ElementNode && $node->templateContent !== null) {
            $cid = spl_object_id($node->templateContent);
            $this->templateOwners[$cid] = $node;
            if (!isset($this->seen[$cid])) {
                $this->registerSubtree($node->templateContent);
            }
        }

        if ($isElement && !$isOpen) {
            $this->completeElement($node);
        }

        if ($isElement) {
            $pid = spl_object_id($node);
            if (($node->children ?? null) !== null) {
                $this->watchCounts[$pid] = count($node->children);
            }
        }
    }

    // ------------------------------------------------------------------
    // Candidate evaluation
    // ------------------------------------------------------------------

    private function completeElement($node): void
    {
        $id = spl_object_id($node);
        if (isset($this->completed[$id])) {
            return;
        }
        $this->completed[$id] = true;
        unset($this->openMatchCache[$id]);

        $name = (string)($node->name ?? '');
        if ($name === '' || $name[0] === '#' || $name === '!doctype') {
            return;
        }

        if ($node instanceof TemplateNode && !isset($this->retained[$id])) {
            // A closed template whose content holds no matches and no
            // rescuables (nothing retain-marked it) imposes no ordering
            // constraint on later candidates.
            unset($this->templateNodes[$id]);
        }

        if ($this->passesPrefilter($node)) {
            if ($this->exactMatch($node)) {
                $this->queue[] = $node;
                $this->queuedIds[$id] = true;
                $this->markRetained($node);
                return;
            }

            if ($this->rescue !== null && $this->rescue->couldEverMatch($node, $this->compiled)) {
                $this->rescuables[$id] = $node;
                $this->markRetained($node);
                $this->noteRescuable($node);
                return;
            }
        }

        if ($this->prune) {
            $this->pruneCandidates[] = $node;
        }
    }

    /** Keeps the earliest-positioned rescuable cached for the drain blocker. */
    private function noteRescuable($node): void
    {
        if ($this->rescuableUnpositionable) {
            return;
        }
        $path = $this->pathOf($node);
        if ($path === null) {
            $this->rescuableUnpositionable = true;
            $this->minRescuable = null;
            return;
        }
        if ($this->minRescuable === null) {
            $this->minRescuable = $node;
            return;
        }
        $minPath = $this->pathOf($this->minRescuable);
        if ($minPath === null || self::cmpPath($path, $minPath) < 0) {
            $this->minRescuable = $node;
        }
    }

    private function recomputeMinRescuable(): void
    {
        $this->minRescuable = null;
        $this->rescuableUnpositionable = false;
        foreach ($this->rescuables as $node) {
            $this->noteRescuable($node);
            if ($this->rescuableUnpositionable) {
                return;
            }
        }
    }

    private function passesPrefilter($node): bool
    {
        $isHtmlNs = ($node->namespace ?? 'html') === 'html';
        $name = $isHtmlNs ? strtolower((string)$node->name) : (string)$node->name;
        $attrs = $node->attrs ?? [];
        $mergeTarget = $this->rescue !== null && ($name === 'html' || $name === 'body') && $isHtmlNs;

        foreach ($this->compiled->prefilters as $filter) {
            if ($filter['tag'] !== null && $isHtmlNs && $name !== $filter['tag']) {
                continue;
            }
            if (!$mergeTarget) {
                // Subject id/class prefilters are only safe on elements whose
                // attributes can no longer change.
                if ($filter['id'] !== null && (($attrs['id'] ?? null) !== $filter['id'])) {
                    continue;
                }
                if ($filter['class'] !== null) {
                    $classAttr = (string)($attrs['class'] ?? '');
                    if ($classAttr === ''
                        || !in_array($filter['class'], preg_split('/\s+/', $classAttr, -1, PREG_SPLIT_NO_EMPTY) ?: [], true)
                    ) {
                        continue;
                    }
                }
            }
            return true;
        }
        return false;
    }

    private function exactMatch($node): bool
    {
        $this->matcher->beginOperation(true);
        try {
            return $this->matcher->matches($node, $this->compiled->parsed);
        } finally {
            $this->matcher->releaseOperation();
        }
    }

    private function reevaluateRescuables(): void
    {
        $changed = false;
        foreach ($this->rescuables as $id => $node) {
            if ($this->exactMatch($node)) {
                unset($this->rescuables[$id]);
                $this->queue[] = $node;
                $this->queuedIds[$id] = true;
                $changed = true;
                // Already retain-marked when it became rescuable.
            } elseif (!$this->rescue->couldEverMatch($node, $this->compiled)) {
                // The merge filled the attribute with a non-matching value;
                // the element can no longer be rescued.
                unset($this->rescuables[$id]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->recomputeMinRescuable();
        }
    }

    private function markRetained($node): void
    {
        $cur = $node;
        while ($cur !== null) {
            $id = spl_object_id($cur);
            if (isset($this->retained[$id])) {
                return;
            }
            $this->retained[$id] = true;
            $parent = $cur->parent ?? null;
            if ($parent === null) {
                // A template-content fragment root has no parent; its owning
                // template element must be retained for the content to be.
                $parent = $this->templateOwners[$id] ?? null;
            }
            $cur = $parent;
        }
    }

    // ------------------------------------------------------------------
    // Pruning (benchmark variant 3)
    // ------------------------------------------------------------------

    private function maybePrune($node, int $id): void
    {
        if (!$this->prune || $this->eofReached) {
            return;
        }
        if (isset($this->retained[$id]) || isset($this->currentOpenIds[$id])) {
            return;
        }
        $name = strtolower((string)$node->name);
        if ($name === 'html' || $name === 'body' || $name === 'head') {
            return;
        }
        if ($node === $this->tb->head_element || $node === $this->tb->form_element) {
            return;
        }
        $parent = $node->parent ?? null;
        if ($parent === null || ($parent->children ?? null) === null) {
            return;
        }

        // Sibling positions of open ancestors can still shift while a table
        // is open (foster parenting inserts before it); a currently
        // non-matching positional ancestor could become a match. Defer all
        // pruning in that window.
        if ($this->compiled->usesPositionalPseudos) {
            foreach ($this->tb->open_elements as $el) {
                if ($el->name === 'table' && ($el->namespace === null || $el->namespace === 'html')) {
                    return;
                }
            }
        }

        // If an ancestor is retained (a match or rescuable somewhere above),
        // or an open ancestor may still become a match, the subtree must stay
        // intact. Ancestors with a 'select' in the chain are also off-limits:
        // EOF finalization (_populate_selectedcontent) reads option subtrees.
        $stackIds = [];
        foreach ($this->tb->open_elements as $el) {
            $stackIds[spl_object_id($el)] = true;
        }
        $ancestor = $parent;
        while ($ancestor !== null) {
            $aid = spl_object_id($ancestor);
            $aname = strtolower((string)($ancestor->name ?? ''));
            if ($aname === 'select' || $aname === 'selectedcontent') {
                return;
            }
            if (isset($this->queuedIds[$aid]) || isset($this->rescuables[$aid])) {
                // The ancestor is (or may yet become) a result whose yielded
                // subtree must include this node.
                return;
            }
            if (isset($stackIds[$aid]) && $this->openElementCouldMatch($ancestor, $aid)) {
                return;
            }
            $ancestor = ($ancestor->parent ?? null) ?? ($this->templateOwners[$aid] ?? null);
        }

        // First walk: veto if EOF finalization or the parser can still reach
        // into the subtree (selects, parser pointers).
        $veto = false;
        $this->inspectSubtree($node, $veto);
        if ($veto) {
            return;
        }
        // Second walk: drop every per-id record for the freed subtree so
        // recycled spl_object_ids cannot collide with future nodes.
        $pruned = $this->cleanupSubtree($node);
        if ($this->compiled->usesPositionalPseudos) {
            // Sibling positions must survive for :nth-child/:nth-of-type on
            // later elements: leave a childless, attribute-free element with
            // the same name and namespace in place.
            $tombstone = new ElementNode($node->name, [], $node->namespace);
            $parent->replaceChild($tombstone, $node);
            $tid = spl_object_id($tombstone);
            $this->seen[$tid] = true;
            $this->anchors[$tid] = $tombstone;
            $this->completed[$tid] = true;
            $pruned -= 1;
        } else {
            $parent->removeChild($node);
        }
        $this->watchCounts[spl_object_id($parent)] = count($parent->children);

        if ($this->stats) {
            $this->stats->nodesPruned += $pruned;
            $this->stats->addLive(-$pruned);
        }
    }

    private function openElementCouldMatch($node, int $id): bool
    {
        if ($this->rescue !== null) {
            $lower = strtolower((string)$node->name);
            if ($lower === 'html' || $lower === 'body') {
                return $this->rescue->couldEverMatch($node, $this->compiled);
            }
        }
        if (!array_key_exists($id, $this->openMatchCache)) {
            $this->openMatchCache[$id] = $this->passesPrefilter($node) && $this->exactMatch($node);
        }
        return $this->openMatchCache[$id];
    }

    private function cleanupSubtree($node): int
    {
        $id = spl_object_id($node);
        unset(
            $this->seen[$id],
            $this->anchors[$id],
            $this->completed[$id],
            $this->watchCounts[$id],
            $this->srcOffsets[$id],
            $this->hasSourceTag[$id],
            $this->retained[$id],
            $this->openMatchCache[$id],
            $this->parkedIds[$id],
            $this->templateNodes[$id],
            $this->templateOwners[$id]
        );
        $count = 1;
        foreach ($node->children ?? [] as $child) {
            $count += $this->cleanupSubtree($child);
        }
        if ($node instanceof ElementNode && $node->templateContent !== null) {
            $count += $this->cleanupSubtree($node->templateContent);
        }
        return $count;
    }

    private function inspectSubtree($node, bool &$veto): int
    {
        $name = strtolower((string)($node->name ?? ''));
        if ($name === 'select' || $name === 'selectedcontent'
            || $node === $this->tb->form_element || $node === $this->tb->head_element
        ) {
            $veto = true;
            return 1;
        }
        $count = 1;
        foreach ($node->children ?? [] as $child) {
            $count += $this->inspectSubtree($child, $veto);
            if ($veto) {
                return $count;
            }
        }
        if ($node instanceof ElementNode && $node->templateContent !== null) {
            $count += $this->inspectSubtree($node->templateContent, $veto);
        }
        return $count;
    }

    // ------------------------------------------------------------------
    // Ordered yield frontier
    // ------------------------------------------------------------------

    /** @return array<int, object> */
    private function drainYieldable(): array
    {
        if ($this->compiled->hazardNotAttrMerge || $this->sawSelectedContent || $this->rescuableUnpositionable) {
            return [];
        }

        $paths = [];
        $pathOf = function ($node) use (&$paths): ?array {
            $id = spl_object_id($node);
            if (!array_key_exists($id, $paths)) {
                $paths[$id] = $this->pathOf($node);
            }
            return $paths[$id];
        };

        // Blockers: document positions before which no insertion or
        // reordering can still happen. A candidate is only released when it
        // sits strictly before every blocker.
        $blocker = null;
        $blockerKey = null;
        $consider = function ($node, string $kind) use (&$blocker, &$blockerKey, $pathOf): bool {
            $p = $pathOf($node);
            if ($p === null) {
                return false;
            }
            if ($blocker === null || self::cmpPath($p, $blocker) < 0) {
                $blocker = $p;
                $blockerKey = $kind . ':' . spl_object_id($node);
            }
            return true;
        };

        foreach ($this->templateNodes as $template) {
            // Templates nested inside another template's content have no
            // main-tree path; the outermost template covers them.
            $consider($template, 'tpl');
        }
        $stack = $this->tb->open_elements;
        $formattingOnStack = $this->formattingEntriesOnStack($stack);
        foreach ($stack as $el) {
            $isTable = $el->name === 'table' && ($el->namespace === null || $el->namespace === 'html');
            if ($isTable || isset($formattingOnStack[spl_object_id($el)])) {
                if (!$consider($el, 'stk')) {
                    // An open table/formatting element inside template
                    // content: hold everything.
                    return [];
                }
            }
        }
        if ($this->tb->frameset_ok && $this->bodyEl !== null) {
            $consider($this->bodyEl, 'fs');
        }
        if ($this->minRescuable !== null) {
            if (!$consider($this->minRescuable, 'rsc')) {
                return [];
            }
        }

        // A currently matching open element precedes any completed
        // descendant and must itself be emitted first. Treat it as a normal
        // positional blocker so queues under broad selectors (for example
        // `body *`) can be parked instead of re-sorted after every token.
        foreach ($stack as $el) {
            if ($this->openElementCouldMatch($el, spl_object_id($el))) {
                if (!$consider($el, 'open')) {
                    return [];
                }
            }
        }

        // A blocked candidate stays blocked while the same blocker is in
        // place; park it so long-blocked queues cost O(new) per drain.
        if ($blockerKey !== $this->activeBlockerKey) {
            $this->activeBlockerKey = $blockerKey;
            $this->parkedIds = [];
        }

        $sortable = [];
        foreach ($this->queue as $node) {
            if (isset($this->parkedIds[spl_object_id($node)])) {
                continue;
            }
            $sortable[] = [$pathOf($node), $node];
        }
        if (!$sortable) {
            return [];
        }
        usort($sortable, static function ($a, $b) {
            if ($a[0] === null || $b[0] === null) {
                return $a[0] === null ? ($b[0] === null ? 0 : 1) : -1;
            }
            return self::cmpPath($a[0], $b[0]);
        });

        $released = [];
        $releasedIds = [];
        foreach ($sortable as [$path, $node]) {
            if ($path === null) {
                // Template-content candidates yield at EOF in query() order.
                $this->parkedIds[spl_object_id($node)] = true;
                continue;
            }
            if ($blocker !== null && self::cmpPath($blocker, $path) <= 0) {
                $this->parkedIds[spl_object_id($node)] = true;
                continue;
            }
            if (!$this->earlierOpenElementsStable($stack, $path, $pathOf)) {
                // Resolves when the open element completes; do not park.
                break;
            }
            $id = spl_object_id($node);
            $released[] = $this->materializeResult($node);
            $releasedIds[$id] = true;
            $this->yieldedIds[$id] = true;
            if ($this->stats) {
                $offset = $this->tokenizer->pos;
                if ($this->stats->firstYieldOffset === null) {
                    $this->stats->firstYieldOffset = $offset;
                }
                $this->stats->results[] = [
                    'src' => !empty($this->hasSourceTag[$id]) ? ($this->srcOffsets[$id] ?? null) : null,
                    'yield' => $offset,
                    'early' => true,
                ];
            }
        }

        if ($released) {
            $remaining = [];
            foreach ($this->queue as $node) {
                if (!isset($releasedIds[spl_object_id($node)])) {
                    $remaining[] = $node;
                }
            }
            $this->queue = $remaining;
        }
        return $released;
    }

    /**
     * Condition 2/3 against open elements: every open element positioned
     * before the candidate must be a stable non-match (an open match will
     * yield first once complete; an open rescuable could become one).
     */
    private function earlierOpenElementsStable(array $stack, array $candidatePath, callable $pathOf): bool
    {
        foreach ($stack as $el) {
            $p = $pathOf($el);
            if ($p === null) {
                return false;
            }
            if (self::cmpPath($p, $candidatePath) < 0 && $this->openElementCouldMatch($el, spl_object_id($el))) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int, bool> ids of active-formatting nodes currently on the stack */
    private function formattingEntriesOnStack(array $stack): array
    {
        if (!$this->tb->active_formatting) {
            return [];
        }
        $stackIds = [];
        foreach ($stack as $el) {
            $stackIds[spl_object_id($el)] = true;
        }
        $result = [];
        foreach ($this->tb->active_formatting as $entry) {
            if (is_array($entry) && isset($entry['node'])) {
                $id = spl_object_id($entry['node']);
                if (isset($stackIds[$id])) {
                    $result[$id] = true;
                }
            }
        }
        return $result;
    }

    private function pathOf($node): ?array
    {
        $path = [];
        $cur = $node;
        while (true) {
            $parent = $cur->parent ?? null;
            if ($parent === null) {
                return ($cur->name ?? '') === '#document' ? array_reverse($path) : null;
            }
            $idx = array_search($cur, $parent->children ?? [], true);
            if ($idx === false) {
                return null;
            }
            $path[] = $idx;
            $cur = $parent;
        }
    }

    /** @param array<int, int> $a @param array<int, int> $b */
    private static function cmpPath(array $a, array $b): int
    {
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        // A prefix is an ancestor: ancestors precede descendants.
        return count($a) <=> count($b);
    }

    /**
     * Returns a detached result node. A completed subtree is cloned only when
     * no enclosing result has materialized it already; indexing the whole
     * clone lets later nested matches share the same retained tree.
     */
    private function materializeResult($node)
    {
        $id = spl_object_id($node);
        if (isset($this->materialized[$id])) {
            return $this->materialized[$id];
        }

        $clone = $node->cloneNode(true);
        $added = 0;
        $this->indexMaterializedTree($node, $clone, $added);
        if ($this->stats && $added > 0) {
            $this->stats->nodesCreated += $added;
            $this->stats->addLive($added);
        }
        return $clone;
    }

    private function indexMaterializedTree($source, $clone, int &$added): void
    {
        $id = spl_object_id($source);
        if (!isset($this->materialized[$id])) {
            $this->materialized[$id] = $clone;
            $added++;
        }

        $sourceChildren = $source->children ?? [];
        $cloneChildren = $clone->children ?? [];
        $count = min(count($sourceChildren), count($cloneChildren));
        for ($i = 0; $i < $count; $i++) {
            $this->indexMaterializedTree($sourceChildren[$i], $cloneChildren[$i], $added);
        }

        if ($source instanceof ElementNode && $source->templateContent !== null
            && $clone instanceof ElementNode && $clone->templateContent !== null
        ) {
            $this->indexMaterializedTree($source->templateContent, $clone->templateContent, $added);
        }
    }

    // ------------------------------------------------------------------
    // EOF
    // ------------------------------------------------------------------

    private function finalSweep($document): \Generator
    {
        $bytes = $this->tokenizer->pos;
        foreach (Selector::query($document, $this->compiled->source) as $node) {
            $id = spl_object_id($node);
            if (isset($this->yieldedIds[$id])) {
                continue;
            }
            $this->yieldedIds[$id] = true;
            if ($this->stats) {
                if ($this->stats->firstYieldOffset === null) {
                    $this->stats->firstYieldOffset = $bytes;
                }
                $this->stats->results[] = [
                    'src' => !empty($this->hasSourceTag[$id]) ? ($this->srcOffsets[$id] ?? null) : null,
                    'yield' => $bytes,
                    'early' => false,
                ];
            }
            yield $this->materializeResult($node);
        }
    }
}
