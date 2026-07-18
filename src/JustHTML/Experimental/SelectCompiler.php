<?php

declare(strict_types=1);

namespace JustHTML\Experimental;

use JustHTML\Selector;
use JustHTML\SelectorComplex;
use JustHTML\SelectorCompound;
use JustHTML\SelectorError;
use JustHTML\SelectorList;
use JustHTML\SelectorMatcher;
use JustHTML\SelectorSimple;

/**
 * @internal Milestone 1 spike (docs/proposal-streaming-select.md). Not public API.
 *
 * Compiles a selector for streaming evaluation: parses via the shared
 * Selector grammar, enforces the documented v1 subset with a recursive
 * semantic-validation pass, and derives the compile-time hazard flags the
 * ordered yield frontier depends on (see docs/spike-streaming-select/frontier.md).
 */
final class CompiledStreamSelector
{
    public string $source;
    /** @var array<int, SelectorComplex> Normalized selector-list branches. */
    public array $branches;
    /** The parsed selector as the DOM matcher consumes it (list or complex). */
    public $parsed;

    /**
     * Hazard A: some compound outside :not() could match html/body by tag and
     * depends on id/class/attributes, so a late <html>/<body> start tag merging
     * missing attributes can turn earlier non-matches into matches. Requires
     * rescuability tracking; positive matches remain stable (merges only add).
     */
    public bool $hazardAttrMerge = false;

    /**
     * Hazard A': an attribute-dependent compound that could match html/body
     * sits under :not(), so a late merge can flip a current *match* to a
     * non-match. No early yield is sound; everything waits for EOF.
     */
    public bool $hazardNotAttrMerge = false;

    /** Selector uses sibling-position pseudos (pruning must tombstone). */
    public bool $usesPositionalPseudos = false;

    /**
     * Per-branch subject prefilter: ['tag' => ?string, 'id' => ?string,
     * 'class' => ?string]. Cheap reject before full matcher evaluation.
     * @var array<int, array{tag:?string,id:?string,class:?string}>
     */
    public array $prefilters = [];
}

/**
 * @internal Spike-only.
 */
final class SelectCompiler
{
    private const SUPPORTED_PSEUDOS = [
        'first-child' => true,
        'nth-child' => true,
        'first-of-type' => true,
        'nth-of-type' => true,
        'not' => true,
    ];

    /**
     * Deliberately deferred (decidable in principle) vs genuinely
     * future-dependent — both rejected in v1, with distinct messages so the
     * scope decision is visible at the API edge.
     */
    private const DEFERRED_PSEUDOS = [
        'empty' => true,
    ];
    private const FUTURE_DEPENDENT_PSEUDOS = [
        'last-child' => true,
        'last-of-type' => true,
        'only-child' => true,
        'only-of-type' => true,
        'nth-last-child' => true,
        'nth-last-of-type' => true,
        'has' => true,
    ];

    public static function compile(string $selector): CompiledStreamSelector
    {
        $parsed = Selector::parseSelector($selector);

        $compiled = new CompiledStreamSelector();
        $compiled->source = $selector;
        $compiled->parsed = $parsed;
        $compiled->branches = $parsed instanceof SelectorList ? $parsed->selectors : [$parsed];

        foreach ($compiled->branches as $branch) {
            if (!$branch instanceof SelectorComplex) {
                throw new SelectorError('Unsupported selector shape for streaming: ' . $selector);
            }
            self::validateComplex($branch, $compiled, false);
            $compiled->prefilters[] = self::prefilterFor($branch);
        }

        return $compiled;
    }

    private static function validateComplex(SelectorComplex $complex, CompiledStreamSelector $compiled, bool $insideNot): void
    {
        foreach ($complex->parts as $part) {
            $combinator = $part[0];
            if ($combinator === '+' || $combinator === '~') {
                throw new SelectorError(
                    "Sibling combinator '{$combinator}' is not supported by streaming select in v1 (deliberately deferred)"
                );
            }
            if ($combinator !== null && $combinator !== ' ' && $combinator !== '>') {
                throw new SelectorError("Unsupported combinator '{$combinator}' for streaming select");
            }
            self::validateCompound($part[1], $compiled, $insideNot);
        }
    }

    private static function validateCompound(SelectorCompound $compound, CompiledStreamSelector $compiled, bool $insideNot): void
    {
        $tagName = null;
        $attrDependent = false;

        foreach ($compound->selectors as $simple) {
            switch ($simple->type) {
                case SelectorSimple::TYPE_TAG:
                    $tagName = strtolower((string)$simple->name);
                    break;
                case SelectorSimple::TYPE_UNIVERSAL:
                    break;
                case SelectorSimple::TYPE_ID:
                case SelectorSimple::TYPE_CLASS:
                case SelectorSimple::TYPE_ATTR:
                    $attrDependent = true;
                    break;
                case SelectorSimple::TYPE_PSEUDO:
                    self::validatePseudo($simple, $compiled, $insideNot);
                    break;
                default:
                    throw new SelectorError('Unsupported simple selector for streaming select');
            }
        }

        // A compound with no tag, or tagged html/body, can apply to the html
        // or body element, whose attribute sets can still grow via late
        // <html>/<body> start-tag merges (TreeBuilderModes::_handle_body_start_html/_body).
        $admitsHtmlBody = $tagName === null || $tagName === 'html' || $tagName === 'body';
        if ($admitsHtmlBody && $attrDependent) {
            if ($insideNot) {
                $compiled->hazardNotAttrMerge = true;
            } else {
                $compiled->hazardAttrMerge = true;
            }
        }
    }

    private static function validatePseudo(SelectorSimple $simple, CompiledStreamSelector $compiled, bool $insideNot): void
    {
        $name = strtolower((string)$simple->name);

        if (isset(self::FUTURE_DEPENDENT_PSEUDOS[$name])) {
            throw new SelectorError(
                ":{$name} needs later-sibling or whole-tree knowledge and is not supported by streaming select"
            );
        }
        if (isset(self::DEFERRED_PSEUDOS[$name])) {
            throw new SelectorError(
                ":{$name} is not supported by streaming select in v1 (deliberately deferred)"
            );
        }
        if (!isset(self::SUPPORTED_PSEUDOS[$name])) {
            throw new SelectorError(":{$name} is not supported by streaming select");
        }

        if ($name === 'not') {
            $inner = $simple->parsedArg;
            if ($inner === null) {
                throw new SelectorError(':not() requires a selector argument');
            }
            $innerBranches = $inner instanceof SelectorList ? $inner->selectors : [$inner];
            foreach ($innerBranches as $innerBranch) {
                if (!$innerBranch instanceof SelectorComplex) {
                    throw new SelectorError('Unsupported :not() argument for streaming select');
                }
                self::validateComplex($innerBranch, $compiled, true);
            }
            return;
        }

        // first-child / nth-child / first-of-type / nth-of-type are decidable
        // from past siblings but sensitive to late insertions before the
        // element; the frontier's foster-parenting hold covers that.
        $compiled->usesPositionalPseudos = true;
    }

    /** @return array{tag:?string,id:?string,class:?string} */
    private static function prefilterFor(SelectorComplex $branch): array
    {
        $subject = $branch->parts[count($branch->parts) - 1][1];
        $filter = ['tag' => null, 'id' => null, 'class' => null];
        foreach ($subject->selectors as $simple) {
            if ($simple->type === SelectorSimple::TYPE_TAG && $filter['tag'] === null) {
                $filter['tag'] = strtolower((string)$simple->name);
            } elseif ($simple->type === SelectorSimple::TYPE_ID && $filter['id'] === null) {
                $filter['id'] = (string)$simple->name;
            } elseif ($simple->type === SelectorSimple::TYPE_CLASS && $filter['class'] === null) {
                $filter['class'] = (string)$simple->name;
            }
        }
        return $filter;
    }
}

/**
 * @internal Spike-only.
 *
 * Answers "could this currently non-matching element become a match if the
 * html/body elements gain attributes via late start-tag merges?" — the
 * rescuability question in the ordered yield frontier. Optimistic in exactly
 * one place: an attribute-dependent simple evaluated against html/body is
 * treated as satisfied when the merge could still supply it (the attribute is
 * absent; a present attribute can never change). Everything else delegates to
 * the shared SelectorMatcher so exact semantics stay in one implementation.
 */
final class RescueMatcher
{
    private SelectorMatcher $matcher;

    public function __construct()
    {
        $this->matcher = new SelectorMatcher();
    }

    public function couldEverMatch($node, CompiledStreamSelector $compiled): bool
    {
        $this->matcher->beginOperation(true);
        try {
            foreach ($compiled->branches as $branch) {
                if ($this->complexCouldMatch($node, $branch, count($branch->parts) - 1)) {
                    return true;
                }
            }
            return false;
        } finally {
            $this->matcher->releaseOperation();
        }
    }

    private function complexCouldMatch($node, SelectorComplex $branch, int $index): bool
    {
        if (!is_object($node)) {
            return false;
        }
        if (!$this->compoundCouldMatch($node, $branch->parts[$index][1])) {
            return false;
        }
        if ($index === 0) {
            return true;
        }

        $combinator = $branch->parts[$index][0];
        if ($combinator === '>') {
            $parent = $node->parent ?? null;
            return $parent !== null && $this->complexCouldMatch($parent, $branch, $index - 1);
        }

        $candidate = $node->parent ?? null;
        while ($candidate !== null) {
            if ($this->complexCouldMatch($candidate, $branch, $index - 1)) {
                return true;
            }
            $candidate = $candidate->parent ?? null;
        }
        return false;
    }

    private function compoundCouldMatch($node, SelectorCompound $compound): bool
    {
        $name = (string)($node->name ?? '');
        if (isset($name[0]) && $name[0] === '#') {
            return false;
        }
        $mergeTarget = ($name === 'html' || $name === 'body')
            && (($node->namespace ?? 'html') === 'html');

        foreach ($compound->selectors as $simple) {
            if ($mergeTarget && $this->optimisticOnMergeTarget($node, $simple)) {
                continue;
            }
            if (!$this->matcher->matches($node, $simple)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the simple selector is attribute-dependent and the html/body
     * node could still come to satisfy it through _add_missing_attributes:
     * the attribute is currently absent. Present attributes are immutable, so
     * those fall through to exact matching.
     */
    private function optimisticOnMergeTarget($node, SelectorSimple $simple): bool
    {
        $attrs = $node->attrs ?? [];
        if ($simple->type === SelectorSimple::TYPE_ID) {
            return !array_key_exists('id', $attrs);
        }
        if ($simple->type === SelectorSimple::TYPE_CLASS) {
            return !array_key_exists('class', $attrs);
        }
        if ($simple->type === SelectorSimple::TYPE_ATTR) {
            $target = strtolower((string)$simple->name);
            foreach ($attrs as $attrName => $_) {
                if (strtolower((string)$attrName) === $target) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
