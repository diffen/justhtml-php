<?php

declare(strict_types=1);

namespace JustHTML\Experimental;

use JustHTML\CommentToken;
use JustHTML\Constants;
use JustHTML\ElementNode;
use JustHTML\Encoding;
use JustHTML\SelectorComplex;
use JustHTML\SelectorCompound;
use JustHTML\SelectorList;
use JustHTML\SelectorSimple;
use JustHTML\Tag;
use JustHTML\TextNode;
use JustHTML\Tokenizer;
use JustHTML\TokenSinkResult;

/**
 * @internal Milestone 1 spike (docs/proposal-streaming-select.md). Not public API.
 *
 * Approach B: the lexical enriched-stack comparator. Extends the
 * Stream/StreamSink stack model with attributes, namespace-adjusted names,
 * sibling counters and minimal implied-end-tag handling, and matches the
 * compiled selector subset against that stack. This establishes the
 * performance ceiling for streaming selection; its semantic divergence from
 * full-DOM query() (no foster parenting, no adoption agency, no implicit
 * element attribute merging, tokenizer-level implied ends only) is measured
 * by the differential harness and reported — it is exactly the divergence
 * that rules this approach out under the select() DOM-equivalence contract.
 */
final class LexicalFrame
{
    public string $name;
    public string $namespace;
    public string $childMode;
    /** @var array<string, string|null> */
    public array $attrs;
    public ?LexicalFrame $parent;
    /** 1-based position among element siblings. */
    public int $elemPos;
    /** 1-based position among same-name element siblings. */
    public int $typePos;
    /** Counters for this frame's element children. */
    public int $childElemCount = 0;
    /** @var array<string, int> */
    public array $typeCounts = [];
    /** Result node being built, when this frame is inside a captured region. */
    public ?ElementNode $node = null;
    /** True when this frame itself matched the selector (owns a capture level). */
    public bool $matched = false;

    public function __construct(
        string $name,
        string $namespace,
        string $childMode,
        array $attrs,
        ?LexicalFrame $parent,
        int $elemPos,
        int $typePos
    ) {
        $this->name = $name;
        $this->namespace = $namespace;
        $this->childMode = $childMode;
        $this->attrs = $attrs;
        $this->parent = $parent;
        $this->elemPos = $elemPos;
        $this->typePos = $typePos;
    }
}

/**
 * @internal Spike-only.
 */
final class LexicalSelect
{
    /** @param array<string, mixed> $opts */
    public static function select($html, string $selector, array $opts = []): \Generator
    {
        $compiled = SelectCompiler::compile($selector);
        return self::run($html, $compiled, $opts);
    }

    /** @param array<string, mixed> $opts */
    public static function selectFirst($html, string $selector, array $opts = []): ?ElementNode
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

        $stats = $opts['stats'] ?? null;
        $sink = new LexicalSelectSink($compiled, $stats instanceof StreamSelectStats ? $stats : null);
        $tokenizer = new Tokenizer($sink);
        $sink->tokenizer = $tokenizer;
        $tokenizer->initialize($htmlStr);
        if ($sink->stats) {
            $sink->stats->bytesTotal = strlen($htmlStr);
        }

        while (true) {
            $isEof = $tokenizer->step();
            if ($sink->ready) {
                foreach ($sink->takeReady() as $node) {
                    yield $node;
                }
            }
            if ($isEof) {
                break;
            }
        }

        foreach ($sink->finishEof() as $node) {
            yield $node;
        }
    }
}

/**
 * @internal Spike-only.
 */
final class LexicalSelectSink
{
    /** Start tags that close an open <p> in the lexical approximation. */
    private const P_CLOSERS = [
        'address' => true, 'article' => true, 'aside' => true, 'blockquote' => true,
        'center' => true, 'details' => true, 'dialog' => true, 'dir' => true,
        'div' => true, 'dl' => true, 'fieldset' => true, 'figcaption' => true,
        'figure' => true, 'footer' => true, 'form' => true, 'h1' => true,
        'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'header' => true, 'hgroup' => true, 'hr' => true, 'main' => true,
        'menu' => true, 'nav' => true, 'ol' => true, 'p' => true,
        'plaintext' => true, 'pre' => true, 'section' => true, 'summary' => true,
        'table' => true, 'ul' => true, 'listing' => true, 'xmp' => true,
    ];

    public ?Tokenizer $tokenizer = null;
    public ?StreamSelectStats $stats;
    public bool $ready = false;

    private CompiledStreamSelector $compiled;
    private LexicalFrame $root;
    /** @var array<int, LexicalFrame> */
    private array $stack = [];
    /** Number of open captured (matched) frames enclosing the current point. */
    private int $captureDepth = 0;
    /** @var array<int, array{node:ElementNode,closed:bool,src:int}> FIFO by start order */
    private array $pending = [];
    private int $liveResultNodes = 0;
    private int $frameCount = 0;
    private bool $firstYieldRecorded = false;

    public function __construct(CompiledStreamSelector $compiled, ?StreamSelectStats $stats)
    {
        $this->compiled = $compiled;
        $this->stats = $stats;
        $this->root = new LexicalFrame('#root', 'html', 'html', [], null, 0, 0);
    }

    // ------------------------------------------------------------------
    // Token sink
    // ------------------------------------------------------------------

    public function processToken($token): int
    {
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START) {
                $this->startTag($token);
            } else {
                $this->endTag(strtolower($token->name));
            }
        } elseif ($token instanceof CommentToken) {
            if ($this->captureDepth > 0) {
                $this->appendToCapture(new \JustHTML\SimpleDomNode('#comment', null, $token->data));
            }
        }
        return TokenSinkResult::Continue;
    }

    public function processCharacters(string $data): void
    {
        if ($this->captureDepth <= 0 || $data === '') {
            return;
        }
        $parentNode = $this->currentBuildNode();
        if ($parentNode === null || $parentNode->children === null) {
            return;
        }
        $count = count($parentNode->children);
        if ($count > 0 && $parentNode->children[$count - 1] instanceof TextNode) {
            $last = $parentNode->children[$count - 1];
            $last->data = ($last->data ?? '') . $data;
            return;
        }
        $textNode = new TextNode($data);
        $parentNode->children[] = $textNode;
        $textNode->parent = $parentNode;
        $this->liveResultNodes++;
        $this->trackLive();
    }

    // ------------------------------------------------------------------
    // Stack maintenance (StreamSink logic, enriched)
    // ------------------------------------------------------------------

    private function startTag(Tag $token): void
    {
        $name = strtolower($token->name);
        $attrs = $token->attrs ?: [];
        $htmlMode = $this->startTagUsesHtmlRules($name, $attrs);
        $current = $this->currentFrame();

        if ($htmlMode) {
            if ($name === 'svg') {
                $namespace = 'svg';
            } elseif ($name === 'math') {
                $namespace = 'math';
            } else {
                $namespace = 'html';
            }
        } else {
            $namespace = $current !== null ? $current->namespace : 'html';
        }

        if ($namespace === 'html') {
            $this->generateImpliedEnds($name);
            $current = $this->currentFrame();
        }

        $adjustedName = $name;
        if ($namespace === 'svg') {
            $adjustedName = Constants::SVG_TAG_NAME_ADJUSTMENTS[$name] ?? $name;
        }

        $parentFrame = $current ?? $this->root;
        $parentFrame->childElemCount++;
        $typeKey = $namespace . "\0" . $adjustedName;
        $parentFrame->typeCounts[$typeKey] = ($parentFrame->typeCounts[$typeKey] ?? 0) + 1;

        $isHtmlVoid = $namespace === 'html' && isset(Constants::VOID_ELEMENTS[$name]);
        $selfClosingForeign = $namespace !== 'html' && $token->selfClosing;

        if ($namespace === 'html' || $this->isHtmlIntegrationPoint($namespace, $adjustedName, $attrs)) {
            $childMode = 'html';
        } elseif ($this->isMathmlTextIntegrationPoint($namespace, $adjustedName)) {
            $childMode = 'math-text';
        } else {
            $childMode = 'foreign';
        }

        $frame = new LexicalFrame(
            $adjustedName,
            $namespace,
            $childMode,
            $attrs,
            $parentFrame,
            $parentFrame->childElemCount,
            $parentFrame->typeCounts[$typeKey],
            );
        $this->frameCount++;
        $this->trackLive();

        $matched = $this->matches($frame);
        $capturing = $this->captureDepth > 0;

        if ($matched || $capturing) {
            $node = new ElementNode($adjustedName, $attrs, $namespace);
            $frame->node = $node;
            $this->liveResultNodes++;
            $this->trackLive();
            if ($capturing) {
                $this->appendToCapture($node);
            }
            if ($matched) {
                $this->pending[] = [
                    'node' => $node,
                    'closed' => $isHtmlVoid || $selfClosingForeign,
                    'src' => $this->tokenizer !== null ? $this->tokenizer->pos : 0,
                ];
                if ($isHtmlVoid || $selfClosingForeign) {
                    $this->flushPending();
                }
            }
        }

        if ($isHtmlVoid || $selfClosingForeign) {
            return;
        }

        $frame->matched = $matched;
        $this->stack[] = $frame;
        if ($matched) {
            $this->captureDepth++;
        }
    }

    private function endTag(string $name): void
    {
        $count = count($this->stack);
        if ($count === 0) {
            return;
        }

        if ($this->stack[$count - 1]->name === $name) {
            $this->popFrame();
            return;
        }

        $topIsHtml = $this->stack[$count - 1]->namespace === 'html';
        if (!$topIsHtml && ($name === 'br' || $name === 'p')) {
            $this->popUntilHtmlOrIntegrationPoint();
            $count = count($this->stack);
            if ($count === 0) {
                return;
            }
            $topIsHtml = $this->stack[$count - 1]->namespace === 'html';
        }

        for ($i = $count - 2; $i >= 0; $i--) {
            $frameIsHtml = $this->stack[$i]->namespace === 'html';
            if ($frameIsHtml !== $topIsHtml) {
                return;
            }
            if ($this->stack[$i]->name === $name) {
                while (count($this->stack) > $i) {
                    $this->popFrame();
                }
                return;
            }
        }
    }

    private function popFrame(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return;
        }
        $this->frameCount--;
        if ($frame->matched) {
            $this->captureDepth--;
            $this->markClosed($frame->node);
            $this->flushPending();
        }
    }

    private function markClosed(ElementNode $node): void
    {
        foreach ($this->pending as $i => $entry) {
            if ($entry['node'] === $node) {
                $this->pending[$i]['closed'] = true;
                return;
            }
        }
    }

    private function flushPending(): void
    {
        if ($this->pending && $this->pending[0]['closed']) {
            $this->ready = true;
        }
    }

    /** @return array<int, ElementNode> */
    public function takeReady(): array
    {
        $this->ready = false;
        $out = [];
        while ($this->pending && $this->pending[0]['closed']) {
            $entry = array_shift($this->pending);
            $out[] = $entry['node'];
            if ($this->stats) {
                $offset = $this->tokenizer !== null ? $this->tokenizer->pos : 0;
                if (!$this->firstYieldRecorded) {
                    $this->firstYieldRecorded = true;
                    $this->stats->firstYieldOffset = $offset;
                }
                $this->stats->results[] = ['src' => $entry['src'], 'yield' => $offset, 'early' => true];
            }
        }
        return $out;
    }

    /** @return array<int, ElementNode> */
    public function finishEof(): array
    {
        while ($this->stack) {
            $this->popFrame();
        }
        foreach ($this->pending as $i => $entry) {
            $this->pending[$i]['closed'] = true;
        }
        return $this->takeReady();
    }

    private function currentFrame(): ?LexicalFrame
    {
        $count = count($this->stack);
        return $count > 0 ? $this->stack[$count - 1] : null;
    }

    private function currentBuildNode(): ?ElementNode
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]->node !== null) {
                return $this->stack[$i]->node;
            }
        }
        return null;
    }

    private function appendToCapture($node): void
    {
        $parent = $this->currentBuildNode();
        if ($parent === null || $parent->children === null) {
            return;
        }
        $parent->children[] = $node;
        $node->parent = $parent;
        if (!$node instanceof ElementNode) {
            $this->liveResultNodes++;
            $this->trackLive();
        }
    }

    private function trackLive(): void
    {
        if ($this->stats) {
            $live = $this->frameCount + $this->liveResultNodes;
            $this->stats->nodesCreated++;
            if ($live > $this->stats->peakLiveNodes) {
                $this->stats->peakLiveNodes = $live;
            }
        }
    }

    // ------------------------------------------------------------------
    // Implied end tags (lexical approximation)
    // ------------------------------------------------------------------

    private function generateImpliedEnds(string $name): void
    {
        $current = $this->currentFrame();
        if ($current === null || $current->namespace !== 'html') {
            return;
        }

        if (isset(self::P_CLOSERS[$name]) && $this->hasOpenInScope('p')) {
            $this->popUntilInclusive('p');
            return;
        }

        $cur = $current->name;
        if ($name === 'li' && $cur === 'li') {
            $this->popUntilInclusive('li');
        } elseif (($name === 'dd' || $name === 'dt') && ($cur === 'dd' || $cur === 'dt')) {
            $this->popUntilAnyInclusive(['dd' => true, 'dt' => true]);
        } elseif ($name === 'option' && $cur === 'option') {
            $this->popUntilInclusive('option');
        } elseif ($name === 'optgroup' && ($cur === 'option' || $cur === 'optgroup')) {
            $this->popUntilAnyInclusive(['option' => true, 'optgroup' => true]);
        } elseif ($name === 'tr' && ($cur === 'tr' || $cur === 'td' || $cur === 'th')) {
            $this->popUntilInclusive('tr');
        } elseif (($name === 'td' || $name === 'th') && ($cur === 'td' || $cur === 'th')) {
            $this->popUntilAnyInclusive(['td' => true, 'th' => true]);
        } elseif (($name === 'tbody' || $name === 'thead' || $name === 'tfoot')
            && ($cur === 'tr' || $cur === 'td' || $cur === 'th' || $cur === 'tbody' || $cur === 'thead' || $cur === 'tfoot')
        ) {
            $this->popUntilAnyInclusive(['tbody' => true, 'thead' => true, 'tfoot' => true]);
        }
    }

    private function hasOpenInScope(string $name): bool
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $frame = $this->stack[$i];
            if ($frame->namespace !== 'html') {
                return false;
            }
            if ($frame->name === $name) {
                return true;
            }
            if (isset(Constants::BUTTON_SCOPE_TERMINATORS[$frame->name])) {
                return false;
            }
        }
        return false;
    }

    private function popUntilInclusive(string $name): void
    {
        while ($this->stack) {
            $top = $this->stack[count($this->stack) - 1]->name;
            $this->popFrame();
            if ($top === $name) {
                return;
            }
        }
    }

    /** @param array<string, bool> $names */
    private function popUntilAnyInclusive(array $names): void
    {
        while ($this->stack) {
            $top = $this->stack[count($this->stack) - 1]->name;
            $this->popFrame();
            if (isset($names[$top])) {
                return;
            }
        }
    }

    private function popUntilHtmlOrIntegrationPoint(): void
    {
        while ($this->stack) {
            $current = $this->currentFrame();
            if ($current === null || $current->namespace === 'html' || $current->childMode === 'html') {
                return;
            }
            $this->popFrame();
        }
    }

    // ------------------------------------------------------------------
    // Foreign-content bookkeeping (as StreamSink)
    // ------------------------------------------------------------------

    /** @param array<string, string|null> $attrs */
    private function startTagUsesHtmlRules(string $name, array $attrs): bool
    {
        $current = $this->currentFrame();
        if ($current === null || $current->namespace === 'html') {
            return true;
        }
        if ($current->childMode === 'html') {
            return true;
        }
        if ($current->childMode === 'math-text' && $name !== 'mglyph' && $name !== 'malignmark') {
            return true;
        }
        if ($current->namespace === 'math' && $current->name === 'annotation-xml' && $name === 'svg') {
            return true;
        }
        if ($this->isForeignBreakout($name, $attrs)) {
            $this->popUntilHtmlOrIntegrationPoint();
            return true;
        }
        return false;
    }

    /** @param array<string, string|null> $attrs */
    private function isHtmlIntegrationPoint(string $namespace, string $name, array $attrs): bool
    {
        if ($namespace === 'math' && $name === 'annotation-xml') {
            $encoding = '';
            foreach ($attrs as $attrName => $attrValue) {
                if (strtolower((string)$attrName) === 'encoding') {
                    $encoding = strtolower((string)$attrValue);
                    break;
                }
            }
            return $encoding === 'text/html' || $encoding === 'application/xhtml+xml';
        }
        return isset(Constants::HTML_INTEGRATION_POINT_SET[$namespace . '|' . $name]);
    }

    private function isMathmlTextIntegrationPoint(string $namespace, string $name): bool
    {
        return isset(Constants::MATHML_TEXT_INTEGRATION_POINT_SET[$namespace . '|' . $name]);
    }

    /** @param array<string, string|null> $attrs */
    private function isForeignBreakout(string $name, array $attrs): bool
    {
        if (isset(Constants::FOREIGN_BREAKOUT_ELEMENTS[$name])) {
            return true;
        }
        if ($name !== 'font') {
            return false;
        }
        foreach ($attrs as $attrName => $_) {
            $lower = strtolower((string)$attrName);
            if ($lower === 'color' || $lower === 'face' || $lower === 'size') {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Frame-chain matching for the compiled subset
    // ------------------------------------------------------------------

    private function matches(LexicalFrame $frame): bool
    {
        foreach ($this->compiled->branches as $branch) {
            if ($this->matchesComplex($frame, $branch, count($branch->parts) - 1)) {
                return true;
            }
        }
        return false;
    }

    private function matchesComplex(LexicalFrame $frame, SelectorComplex $branch, int $index): bool
    {
        if (!$this->matchesCompound($frame, $branch->parts[$index][1])) {
            return false;
        }
        if ($index === 0) {
            return true;
        }
        $combinator = $branch->parts[$index][0];
        if ($combinator === '>') {
            $parent = $frame->parent;
            return $parent !== null && $parent->parent !== null // exclude #root
                && $this->matchesComplex($parent, $branch, $index - 1);
        }
        $candidate = $frame->parent;
        while ($candidate !== null && $candidate->parent !== null) {
            if ($this->matchesComplex($candidate, $branch, $index - 1)) {
                return true;
            }
            $candidate = $candidate->parent;
        }
        return false;
    }

    private function matchesCompound(LexicalFrame $frame, SelectorCompound $compound): bool
    {
        foreach ($compound->selectors as $simple) {
            if (!$this->matchesSimple($frame, $simple)) {
                return false;
            }
        }
        return true;
    }

    private function matchesSimple(LexicalFrame $frame, SelectorSimple $simple): bool
    {
        switch ($simple->type) {
            case SelectorSimple::TYPE_UNIVERSAL:
                return true;
            case SelectorSimple::TYPE_TAG:
                $name = (string)$simple->name;
                if ($frame->namespace === 'html') {
                    return $frame->name === strtolower($name);
                }
                return $frame->name === $name;
            case SelectorSimple::TYPE_ID:
                return ($frame->attrs['id'] ?? null) === $simple->name;
            case SelectorSimple::TYPE_CLASS:
                $classAttr = (string)($frame->attrs['class'] ?? '');
                if ($classAttr === '' || $simple->name === null) {
                    return false;
                }
                $classes = preg_split('/\s+/', $classAttr, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                return in_array($simple->name, $classes, true);
            case SelectorSimple::TYPE_ATTR:
                return $this->matchesAttr($frame, $simple);
            case SelectorSimple::TYPE_PSEUDO:
                return $this->matchesPseudo($frame, $simple);
        }
        return false;
    }

    private function matchesAttr(LexicalFrame $frame, SelectorSimple $simple): bool
    {
        $target = strtolower((string)$simple->name);
        $found = false;
        $value = null;
        foreach ($frame->attrs as $attrName => $attrValue) {
            $name = (string)$attrName;
            if (($frame->namespace === 'html' && strtolower($name) === $target) || $name === $simple->name) {
                $found = true;
                $value = $attrValue;
                break;
            }
        }
        if (!$found) {
            return false;
        }
        if ($simple->operator === null) {
            return true;
        }
        $attrValue = (string)($value ?? '');
        $needle = (string)($simple->value ?? '');
        switch ($simple->operator) {
            case '=':
                return $attrValue === $needle;
            case '~=':
                $words = $attrValue !== '' ? (preg_split('/\s+/', $attrValue, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
                return in_array($needle, $words, true);
            case '|=':
                return $attrValue === $needle
                    || strncmp($attrValue, $needle . '-', strlen($needle) + 1) === 0;
            case '^=':
                return $needle !== '' && strncmp($attrValue, $needle, strlen($needle)) === 0;
            case '$=':
                $len = strlen($needle);
                return $needle !== '' && $len <= strlen($attrValue) && substr($attrValue, -$len) === $needle;
            case '*=':
                return $needle !== '' && strpos($attrValue, $needle) !== false;
        }
        return false;
    }

    private function matchesPseudo(LexicalFrame $frame, SelectorSimple $simple): bool
    {
        $name = strtolower((string)$simple->name);
        switch ($name) {
            case 'first-child':
                return $frame->elemPos === 1;
            case 'nth-child':
                return $this->matchesNth($frame->elemPos, $simple->parsedArg);
            case 'first-of-type':
                return $frame->typePos === 1;
            case 'nth-of-type':
                return $this->matchesNth($frame->typePos, $simple->parsedArg);
            case 'not':
                $inner = $simple->parsedArg;
                $branches = $inner instanceof SelectorList ? $inner->selectors : [$inner];
                foreach ($branches as $branch) {
                    if ($branch instanceof SelectorComplex
                        && $this->matchesComplex($frame, $branch, count($branch->parts) - 1)
                    ) {
                        return false;
                    }
                }
                return true;
        }
        return false;
    }

    private function matchesNth(int $position, $parsed): bool
    {
        if (!is_array($parsed) || count($parsed) !== 2 || $position < 1) {
            return false;
        }
        [$a, $b] = $parsed;
        if ($a === 0) {
            return $position === $b;
        }
        $diff = $position - $b;
        if ($a > 0) {
            return $diff >= 0 && $diff % $a === 0;
        }
        return $diff <= 0 && $diff % $a === 0;
    }
}
