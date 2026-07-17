<?php

declare(strict_types=1);

namespace JustHTML;

final class SelectorError extends \InvalidArgumentException
{
}

final class SelectorTokenType
{
    public const TAG = 'TAG';
    public const ID = 'ID';
    public const CLASS_NAME = 'CLASS';
    public const UNIVERSAL = 'UNIVERSAL';
    public const ATTR_START = 'ATTR_START';
    public const ATTR_END = 'ATTR_END';
    public const ATTR_OP = 'ATTR_OP';
    public const STRING = 'STRING';
    public const COMBINATOR = 'COMBINATOR';
    public const COMMA = 'COMMA';
    public const COLON = 'COLON';
    public const PAREN_OPEN = 'PAREN_OPEN';
    public const PAREN_CLOSE = 'PAREN_CLOSE';
    public const EOF = 'EOF';
}

final class SelectorToken
{
    public string $type;
    public ?string $value;

    public function __construct(string $type, ?string $value = null)
    {
        $this->type = $type;
        $this->value = $value;
    }
}

final class SelectorTokenizer
{
    private string $selector;
    private int $pos = 0;
    private int $length = 0;

    public function __construct(string $selector)
    {
        $this->selector = $selector;
        $this->length = strlen($selector);
    }

    private function peek(int $offset = 0): string
    {
        $pos = $this->pos + $offset;
        if ($pos < $this->length) {
            return $this->selector[$pos];
        }
        return '';
    }

    private function advance(): string
    {
        $ch = $this->peek();
        $this->pos += 1;
        return $ch;
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->selector[$this->pos];
            if ($ch !== ' ' && $ch !== "\t" && $ch !== "\n" && $ch !== "\r" && $ch !== "\f") {
                break;
            }
            $this->pos += 1;
        }
    }

    private function isNameStart(string $ch): bool
    {
        $code = ord($ch);
        return ($code >= 65 && $code <= 90)
            || ($code >= 97 && $code <= 122)
            || $ch === '_'
            || $ch === '-'
            || $code > 127;
    }

    private function isNameChar(string $ch): bool
    {
        $code = ord($ch);
        return $this->isNameStart($ch) || ($code >= 48 && $code <= 57);
    }

    private function readEscape(): string
    {
        // The leading backslash has not been consumed yet.
        $this->pos += 1;
        if ($this->pos >= $this->length) {
            throw new SelectorError('Trailing escape in selector: ' . var_export($this->selector, true));
        }

        $ch = $this->selector[$this->pos];
        if ($ch === "\n" || $ch === "\r" || $ch === "\f") {
            throw new SelectorError('Invalid newline escape in selector: ' . var_export($this->selector, true));
        }

        if (ctype_xdigit($ch)) {
            $hex = '';
            while ($this->pos < $this->length && strlen($hex) < 6 && ctype_xdigit($this->selector[$this->pos])) {
                $hex .= $this->selector[$this->pos];
                $this->pos += 1;
            }
            if ($this->pos < $this->length) {
                $ws = $this->selector[$this->pos];
                if ($ws === ' ' || $ws === "\t" || $ws === "\n" || $ws === "\r" || $ws === "\f") {
                    $this->pos += 1;
                    if ($ws === "\r" && $this->pos < $this->length && $this->selector[$this->pos] === "\n") {
                        $this->pos += 1;
                    }
                }
            }
            $codepoint = hexdec($hex);
            if ($codepoint === 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                return "\u{FFFD}";
            }
            return Str::codepointToUtf8($codepoint);
        }

        $this->pos += 1;
        return $ch;
    }

    private function readName(): string
    {
        $parts = [];
        $start = $this->pos;
        while ($this->pos < $this->length) {
            $ch = $this->selector[$this->pos];
            if ($ch === '\\') {
                if ($this->pos > $start) {
                    $parts[] = substr($this->selector, $start, $this->pos - $start);
                }
                $parts[] = $this->readEscape();
                $start = $this->pos;
                continue;
            }
            if (!$this->isNameChar($ch)) {
                break;
            }
            $this->pos += 1;
        }
        if ($this->pos > $start) {
            $parts[] = substr($this->selector, $start, $this->pos - $start);
        }
        return implode('', $parts);
    }

    private function readString(string $quote): string
    {
        $this->pos += 1;
        $start = $this->pos;
        $parts = [];

        while ($this->pos < $this->length) {
            $ch = $this->selector[$this->pos];
            if ($ch === $quote) {
                if ($this->pos > $start) {
                    $parts[] = substr($this->selector, $start, $this->pos - $start);
                }
                $this->pos += 1;
                return implode('', $parts);
            }
            if ($ch === '\\') {
                if ($this->pos > $start) {
                    $parts[] = substr($this->selector, $start, $this->pos - $start);
                }
                $next = $this->peek(1);
                if ($next === "\n" || $next === "\r" || $next === "\f") {
                    $this->pos += 2;
                    if ($next === "\r" && $this->pos < $this->length && $this->selector[$this->pos] === "\n") {
                        $this->pos += 1;
                    }
                    $start = $this->pos;
                    continue;
                }
                $parts[] = $this->readEscape();
                $start = $this->pos;
            } else {
                if ($ch === "\n" || $ch === "\r" || $ch === "\f") {
                    throw new SelectorError('Unescaped newline in selector string: ' . var_export($this->selector, true));
                }
                $this->pos += 1;
            }
        }

        throw new SelectorError('Unterminated string in selector: ' . var_export($this->selector, true));
    }

    private function advancePastArgumentEscape(): void
    {
        // The leading backslash has not been consumed yet. For finding the
        // functional pseudo's closing parenthesis, it is enough to skip the
        // escaped source character; hexadecimal escapes contain no literal
        // parentheses to confuse the depth counter.
        $this->pos += 1;
        if ($this->pos >= $this->length) {
            return;
        }
        $ch = $this->selector[$this->pos];
        $this->pos += 1;
        if ($ch === "\r" && $this->pos < $this->length && $this->selector[$this->pos] === "\n") {
            $this->pos += 1;
        }
    }

    private function readPseudoArgument(): string
    {
        $depth = 1;
        $start = $this->pos;
        $quote = null;

        while ($this->pos < $this->length && $depth > 0) {
            $ch = $this->selector[$this->pos];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $this->advancePastArgumentEscape();
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                $this->pos += 1;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $this->pos += 1;
                continue;
            }
            if ($ch === '\\') {
                $this->advancePastArgumentEscape();
                continue;
            }
            if ($ch === '(') {
                $depth += 1;
                $this->pos += 1;
                continue;
            }
            if ($ch === ')') {
                $depth -= 1;
                if ($depth === 0) {
                    break;
                }
            }
            $this->pos += 1;
        }

        return trim(substr($this->selector, $start, $this->pos - $start));
    }

    private function readUnquotedAttrValue(): string
    {
        $parts = [];
        $start = $this->pos;
        while ($this->pos < $this->length) {
            $ch = $this->selector[$this->pos];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f" || $ch === ']') {
                break;
            }
            if ($ch === '\\') {
                if ($this->pos > $start) {
                    $parts[] = substr($this->selector, $start, $this->pos - $start);
                }
                $parts[] = $this->readEscape();
                $start = $this->pos;
                continue;
            }
            $this->pos += 1;
        }
        if ($this->pos > $start) {
            $parts[] = substr($this->selector, $start, $this->pos - $start);
        }
        return implode('', $parts);
    }

    /** @return array<int, SelectorToken> */
    public function tokenize(): array
    {
        $tokens = [];
        $pendingWhitespace = false;

        while ($this->pos < $this->length) {
            $ch = $this->selector[$this->pos];

            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f") {
                $pendingWhitespace = true;
                $this->skipWhitespace();
                continue;
            }

            if ($ch === '>' || $ch === '+' || $ch === '~') {
                $pendingWhitespace = false;
                $this->pos += 1;
                $this->skipWhitespace();
                $tokens[] = new SelectorToken(SelectorTokenType::COMBINATOR, $ch);
                continue;
            }

            if ($pendingWhitespace && $tokens && $ch !== ',') {
                $tokens[] = new SelectorToken(SelectorTokenType::COMBINATOR, ' ');
            }
            $pendingWhitespace = false;

            if ($ch === '*') {
                $this->pos += 1;
                $tokens[] = new SelectorToken(SelectorTokenType::UNIVERSAL);
                continue;
            }

            if ($ch === '#') {
                $this->pos += 1;
                $name = $this->readName();
                if ($name === '') {
                    throw new SelectorError('Expected identifier after # at position ' . $this->pos);
                }
                $tokens[] = new SelectorToken(SelectorTokenType::ID, $name);
                continue;
            }

            if ($ch === '.') {
                $this->pos += 1;
                $name = $this->readName();
                if ($name === '') {
                    throw new SelectorError('Expected identifier after . at position ' . $this->pos);
                }
                $tokens[] = new SelectorToken(SelectorTokenType::CLASS_NAME, $name);
                continue;
            }

            if ($ch === '[') {
                $this->pos += 1;
                $tokens[] = new SelectorToken(SelectorTokenType::ATTR_START);
                $this->skipWhitespace();

                $attrName = $this->readName();
                if ($attrName === '') {
                    throw new SelectorError('Expected attribute name at position ' . $this->pos);
                }
                $tokens[] = new SelectorToken(SelectorTokenType::TAG, $attrName);
                $this->skipWhitespace();

                $next = $this->peek();
                if ($next === ']') {
                    $this->pos += 1;
                    $tokens[] = new SelectorToken(SelectorTokenType::ATTR_END);
                    continue;
                }

                if ($next === '=') {
                    $this->pos += 1;
                    $tokens[] = new SelectorToken(SelectorTokenType::ATTR_OP, '=');
                } elseif ($next === '~' || $next === '|' || $next === '^' || $next === '$' || $next === '*') {
                    $opChar = $next;
                    $this->pos += 1;
                    if ($this->peek() !== '=') {
                        throw new SelectorError('Expected = after ' . $opChar . ' at position ' . $this->pos);
                    }
                    $this->pos += 1;
                    $tokens[] = new SelectorToken(SelectorTokenType::ATTR_OP, $opChar . '=');
                } else {
                    throw new SelectorError('Unexpected character in attribute selector: ' . var_export($next, true));
                }

                $this->skipWhitespace();
                $valueStart = $this->peek();
                if ($valueStart === '"' || $valueStart === "'") {
                    $value = $this->readString($valueStart);
                } else {
                    $value = $this->readUnquotedAttrValue();
                }
                $tokens[] = new SelectorToken(SelectorTokenType::STRING, $value);

                $this->skipWhitespace();
                if ($this->peek() !== ']') {
                    throw new SelectorError('Expected ] at position ' . $this->pos);
                }
                $this->pos += 1;
                $tokens[] = new SelectorToken(SelectorTokenType::ATTR_END);
                continue;
            }

            if ($ch === ',') {
                $this->pos += 1;
                $this->skipWhitespace();
                $tokens[] = new SelectorToken(SelectorTokenType::COMMA);
                continue;
            }

            if ($ch === ':') {
                $this->pos += 1;
                $tokens[] = new SelectorToken(SelectorTokenType::COLON);
                $name = $this->readName();
                if ($name === '') {
                    throw new SelectorError('Expected pseudo-class name after : at position ' . $this->pos);
                }
                $tokens[] = new SelectorToken(SelectorTokenType::TAG, $name);

                if ($this->peek() === '(') {
                    $this->pos += 1;
                    $tokens[] = new SelectorToken(SelectorTokenType::PAREN_OPEN);
                    $this->skipWhitespace();

                    $arg = $this->readPseudoArgument();
                    if ($arg !== '') {
                        $tokens[] = new SelectorToken(SelectorTokenType::STRING, $arg);
                    }

                    if ($this->peek() !== ')') {
                        throw new SelectorError('Expected ) at position ' . $this->pos);
                    }
                    $this->pos += 1;
                    $tokens[] = new SelectorToken(SelectorTokenType::PAREN_CLOSE);
                }
                continue;
            }

            if ($ch === '\\' || $this->isNameStart($ch)) {
                $name = $this->readName();
                $tokens[] = new SelectorToken(SelectorTokenType::TAG, $name);
                continue;
            }

            throw new SelectorError('Unexpected character ' . var_export($ch, true) . ' at position ' . $this->pos);
        }

        $tokens[] = new SelectorToken(SelectorTokenType::EOF);
        return $tokens;
    }
}

final class SelectorSimple
{
    public const TYPE_TAG = 'tag';
    public const TYPE_ID = 'id';
    public const TYPE_CLASS = 'class';
    public const TYPE_UNIVERSAL = 'universal';
    public const TYPE_ATTR = 'attr';
    public const TYPE_PSEUDO = 'pseudo';

    public string $type;
    public ?string $name;
    public ?string $operator;
    public ?string $value;
    public ?string $arg;
    public $parsedArg = null;

    public function __construct(
        string $type,
        ?string $name = null,
        ?string $operator = null,
        ?string $value = null,
        ?string $arg = null
    ) {
        $this->type = $type;
        $this->name = $name;
        $this->operator = $operator;
        $this->value = $value;
        $this->arg = $arg;
    }
}

final class SelectorCompound
{
    /** @var array<int, SelectorSimple> */
    public array $selectors;

    /** @param array<int, SelectorSimple>|null $selectors */
    public function __construct(?array $selectors = null)
    {
        $this->selectors = $selectors ?? [];
    }
}

final class SelectorComplex
{
    /** @var array<int, array{0:?string,1:SelectorCompound}> */
    public array $parts = [];
}

final class SelectorList
{
    /** @var array<int, SelectorComplex> */
    public array $selectors;

    /** @param array<int, SelectorComplex>|null $selectors */
    public function __construct(?array $selectors = null)
    {
        $this->selectors = $selectors ?? [];
    }
}

final class SelectorParser
{
    /** @var array<int, SelectorToken> */
    private array $tokens;
    private int $pos = 0;

    /** @param array<int, SelectorToken> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    private function peek(): SelectorToken
    {
        if ($this->pos < count($this->tokens)) {
            return $this->tokens[$this->pos];
        }
        return new SelectorToken(SelectorTokenType::EOF);
    }

    private function advance(): SelectorToken
    {
        $token = $this->peek();
        $this->pos += 1;
        return $token;
    }

    private function expect(string $type): SelectorToken
    {
        $token = $this->peek();
        if ($token->type !== $type) {
            throw new SelectorError('Expected ' . $type . ', got ' . $token->type);
        }
        return $this->advance();
    }

    public function parse()
    {
        $selectors = [];
        $first = $this->parseComplexSelector();
        if ($first === null) {
            throw new SelectorError('Empty selector');
        }
        $selectors[] = $first;

        while ($this->peek()->type === SelectorTokenType::COMMA) {
            $this->advance();
            $selector = $this->parseComplexSelector();
            if ($selector === null) {
                throw new SelectorError('Expected selector after comma');
            }
            $selectors[] = $selector;
        }

        if ($this->peek()->type !== SelectorTokenType::EOF) {
            $token = $this->peek();
            throw new SelectorError('Unexpected token: ' . $token->type);
        }

        if (count($selectors) === 1) {
            return $selectors[0];
        }
        return new SelectorList($selectors);
    }

    private function parseComplexSelector(): ?SelectorComplex
    {
        $complex = new SelectorComplex();
        $compound = $this->parseCompoundSelector();
        if ($compound === null) {
            return null;
        }
        $complex->parts[] = [null, $compound];

        while ($this->peek()->type === SelectorTokenType::COMBINATOR) {
            $combinator = $this->advance()->value;
            $compound = $this->parseCompoundSelector();
            if ($compound === null) {
                throw new SelectorError('Expected selector after combinator');
            }
            $complex->parts[] = [$combinator, $compound];
        }

        return $complex;
    }

    private function parseCompoundSelector(): ?SelectorCompound
    {
        $simple = [];

        while (true) {
            $token = $this->peek();

            if ($token->type === SelectorTokenType::TAG) {
                $this->advance();
                $simple[] = new SelectorSimple(SelectorSimple::TYPE_TAG, $token->value);
                continue;
            }

            if ($token->type === SelectorTokenType::UNIVERSAL) {
                $this->advance();
                $simple[] = new SelectorSimple(SelectorSimple::TYPE_UNIVERSAL);
                continue;
            }

            if ($token->type === SelectorTokenType::ID) {
                $this->advance();
                $simple[] = new SelectorSimple(SelectorSimple::TYPE_ID, $token->value);
                continue;
            }

            if ($token->type === SelectorTokenType::CLASS_NAME) {
                $this->advance();
                $simple[] = new SelectorSimple(SelectorSimple::TYPE_CLASS, $token->value);
                continue;
            }

            if ($token->type === SelectorTokenType::ATTR_START) {
                $simple[] = $this->parseAttributeSelector();
                continue;
            }

            if ($token->type === SelectorTokenType::COLON) {
                $simple[] = $this->parsePseudoSelector();
                continue;
            }

            break;
        }

        if (!$simple) {
            return null;
        }
        return new SelectorCompound($simple);
    }

    private function parseAttributeSelector(): SelectorSimple
    {
        $this->expect(SelectorTokenType::ATTR_START);
        $attrName = $this->expect(SelectorTokenType::TAG)->value;

        $token = $this->peek();
        if ($token->type === SelectorTokenType::ATTR_END) {
            $this->advance();
            return new SelectorSimple(SelectorSimple::TYPE_ATTR, $attrName);
        }

        $operator = $this->expect(SelectorTokenType::ATTR_OP)->value;
        $value = $this->expect(SelectorTokenType::STRING)->value;
        $this->expect(SelectorTokenType::ATTR_END);

        return new SelectorSimple(SelectorSimple::TYPE_ATTR, $attrName, $operator, $value);
    }

    private function parsePseudoSelector(): SelectorSimple
    {
        $this->expect(SelectorTokenType::COLON);
        $name = $this->expect(SelectorTokenType::TAG)->value;

        if ($this->peek()->type === SelectorTokenType::PAREN_OPEN) {
            $this->advance();
            $arg = null;
            if ($this->peek()->type === SelectorTokenType::STRING) {
                $arg = $this->advance()->value;
            }
            $this->expect(SelectorTokenType::PAREN_CLOSE);
            $pseudo = new SelectorSimple(SelectorSimple::TYPE_PSEUDO, $name, null, null, $arg);
            $lowerName = strtolower($name);
            if ($lowerName === 'not') {
                if ($arg === null || trim($arg) === '') {
                    throw new SelectorError(':not() requires a non-empty selector');
                }
                $pseudo->parsedArg = Selector::parseSelector($arg);
            } elseif ($lowerName === 'nth-child' || $lowerName === 'nth-of-type') {
                $pseudo->parsedArg = self::parseNthExpression($arg);
                if ($pseudo->parsedArg === false) {
                    throw new SelectorError('Invalid argument for :' . $lowerName . '()');
                }
            }
            return $pseudo;
        }

        $lowerName = strtolower($name);
        if ($lowerName === 'not' || $lowerName === 'nth-child' || $lowerName === 'nth-of-type') {
            throw new SelectorError(':' . $lowerName . ' requires an argument');
        }

        return new SelectorSimple(SelectorSimple::TYPE_PSEUDO, $name);
    }

    /** @return array{0:int,1:int}|false */
    private static function parseNthExpression(?string $expr)
    {
        if ($expr === null) {
            return false;
        }
        $expr = strtolower(trim($expr));
        if ($expr === '') {
            return false;
        }
        if ($expr === 'odd') {
            return [2, 1];
        }
        if ($expr === 'even') {
            return [2, 0];
        }

        $expr = str_replace(' ', '', $expr);
        if (strpos($expr, 'n') !== false) {
            $parts = explode('n', $expr, 2);
            $aPart = $parts[0];
            $bPart = $parts[1] ?? '';

            if ($aPart === '' || $aPart === '+') {
                $a = 1;
            } elseif ($aPart === '-') {
                $a = -1;
            } elseif (preg_match('/^[+-]?\d+$/', $aPart)) {
                $a = (int)$aPart;
            } else {
                return false;
            }

            $b = 0;
            if ($bPart !== '') {
                if (!preg_match('/^[+-]?\d+$/', $bPart)) {
                    return false;
                }
                $b = (int)$bPart;
            }
        } else {
            if (!preg_match('/^[+-]?\d+$/', $expr)) {
                return false;
            }
            $a = 0;
            $b = (int)$expr;
        }

        return [$a, $b];
    }
}

final class SelectorMatcher
{
    /**
     * Lazily built per-parent indexes for a query operation. The `previous`
     * entries deliberately hold node objects and therefore must be released
     * when the public operation finishes.
     *
     * @var array<int, array{
     *     positions:array<int,int>,
     *     count:int,
     *     typePositions:array<int,int>,
     *     typeCounts:array<string,int>,
     *     previous:array<int,mixed>
     * }>
     */
    private array $parentIndexes = [];
    /** @var array<int, array<int, array<int, bool>>> */
    private array $complexMatchMemo = [];
    /** @var array<int, bool> */
    private array $standaloneSiblingScans = [];
    private bool $standalone = false;

    public function beginOperation(bool $standalone): void
    {
        $this->releaseOperation();
        $this->standalone = $standalone;
    }

    public function releaseOperation(): void
    {
        $this->parentIndexes = [];
        $this->complexMatchMemo = [];
        $this->standaloneSiblingScans = [];
        $this->standalone = false;
    }

    public function matches($node, $selector): bool
    {
        if ($selector instanceof SelectorList) {
            foreach ($selector->selectors as $sel) {
                if ($this->matches($node, $sel)) {
                    return true;
                }
            }
            return false;
        }
        if ($selector instanceof SelectorComplex) {
            return $this->matchesComplex($node, $selector);
        }
        if ($selector instanceof SelectorCompound) {
            return $this->matchesCompound($node, $selector);
        }
        if ($selector instanceof SelectorSimple) {
            return $this->matchesSimple($node, $selector);
        }
        return false;
    }

    private function matchesComplex($node, SelectorComplex $selector): bool
    {
        if (!$selector->parts) {
            return false;
        }

        return $this->matchesComplexAt($node, $selector, count($selector->parts) - 1);
    }

    private function matchesComplexAt($node, SelectorComplex $selector, int $index): bool
    {
        if (!is_object($node)) {
            return false;
        }
        $selectorId = spl_object_id($selector);
        $nodeId = spl_object_id($node);
        if (isset($this->complexMatchMemo[$selectorId][$index])
            && array_key_exists($nodeId, $this->complexMatchMemo[$selectorId][$index])) {
            return $this->complexMatchMemo[$selectorId][$index][$nodeId];
        }

        $result = $this->matchesComplexAtUncached($node, $selector, $index);
        $this->complexMatchMemo[$selectorId][$index][$nodeId] = $result;
        return $result;
    }

    private function matchesComplexAtUncached($node, SelectorComplex $selector, int $index): bool
    {
        $parts = $selector->parts;
        if (!$this->matchesCompound($node, $parts[$index][1])) {
            return false;
        }
        if ($index === 0) {
            return true;
        }

        $combinator = $parts[$index][0];
        if ($combinator === '>') {
            $parent = $node->parent ?? null;
            return $parent !== null && $this->matchesComplexAt($parent, $selector, $index - 1);
        }
        if ($combinator === '+') {
            $sibling = $this->getPreviousSibling($node);
            return $sibling !== null && $this->matchesComplexAt($sibling, $selector, $index - 1);
        }
        if ($combinator === ' ') {
            $candidate = $node->parent ?? null;
            while ($candidate) {
                if ($this->matchesComplexAt($candidate, $selector, $index - 1)) {
                    return true;
                }
                $candidate = $candidate->parent ?? null;
            }
            return false;
        }

        // A general-sibling search performs repeated predecessor lookups, so
        // even for standalone matches it is cheaper to build this operation's
        // parent index once than to rescan from the start for every sibling.
        $candidate = $this->getPreviousSibling($node, false);
        while ($candidate) {
            if ($this->matchesComplexAt($candidate, $selector, $index - 1)) {
                return true;
            }
            $candidate = $this->getPreviousSibling($candidate, false);
        }
        return false;
    }

    private function matchesCompound($node, SelectorCompound $compound): bool
    {
        foreach ($compound->selectors as $simple) {
            if (!$this->matchesSimple($node, $simple)) {
                return false;
            }
        }
        return true;
    }

    private function matchesSimple($node, SelectorSimple $selector): bool
    {
        if (!is_object($node) || !property_exists($node, 'name')) {
            return false;
        }
        $nodeName = (string)$node->name;
        if (isset($nodeName[0]) && $nodeName[0] === '#') {
            return false;
        }

        if ($selector->type === SelectorSimple::TYPE_UNIVERSAL) {
            return true;
        }

        if ($selector->type === SelectorSimple::TYPE_TAG) {
            $name = $selector->name ?? '';
            if (($node->namespace ?? 'html') === 'html') {
                return strtolower((string)$node->name) === strtolower($name);
            }
            return (string)$node->name === $name;
        }

        if ($selector->type === SelectorSimple::TYPE_ID) {
            $attrs = $node->attrs ?? [];
            $nodeId = $attrs['id'] ?? '';
            return $nodeId === $selector->name;
        }

        if ($selector->type === SelectorSimple::TYPE_CLASS) {
            $attrs = $node->attrs ?? [];
            $classAttr = $attrs['class'] ?? '';
            $classes = $classAttr !== '' ? preg_split('/\s+/', $classAttr, -1, PREG_SPLIT_NO_EMPTY) : [];
            return $selector->name !== null && in_array($selector->name, $classes ?? [], true);
        }

        if ($selector->type === SelectorSimple::TYPE_ATTR) {
            return $this->matchesAttribute($node, $selector);
        }

        if ($selector->type === SelectorSimple::TYPE_PSEUDO) {
            return $this->matchesPseudo($node, $selector);
        }

        return false;
    }

    private function matchesAttribute($node, SelectorSimple $selector): bool
    {
        $attrs = $node->attrs ?? [];
        $attrName = $selector->name ?? '';
        $html = ($node->namespace ?? 'html') === 'html';

        $found = false;
        $attrValue = null;
        foreach ($attrs as $name => $value) {
            if (($html && strtolower((string)$name) === strtolower($attrName)) || (!$html && (string)$name === $attrName)) {
                $found = true;
                $attrValue = $value;
                break;
            }
        }

        if (!$found) {
            return false;
        }

        if ($selector->operator === null) {
            return true;
        }

        // A null value is a boolean attribute; it compares as the empty string.
        $attrValue = (string)($attrValue ?? '');

        $value = $selector->value ?? '';
        $op = $selector->operator;

        if ($op === '=') {
            return $attrValue === $value;
        }

        if ($op === '~=') {
            $words = $attrValue !== '' ? preg_split('/\s+/', $attrValue, -1, PREG_SPLIT_NO_EMPTY) : [];
            return in_array($value, $words ?? [], true);
        }

        if ($op === '|=') {
            $prefix = $value . '-';
            return $attrValue === $value || (strlen($attrValue) >= strlen($prefix) && strncmp($attrValue, $prefix, strlen($prefix)) === 0);
        }

        if ($op === '^=') {
            return $value !== '' && strlen($attrValue) >= strlen($value) && strncmp($attrValue, $value, strlen($value)) === 0;
        }

        if ($op === '$=') {
            $len = strlen($value);
            return $value !== '' && $len <= strlen($attrValue) && substr($attrValue, -$len) === $value;
        }

        if ($op === '*=') {
            return $value !== '' && strpos($attrValue, $value) !== false;
        }

        return false;
    }

    private function matchesPseudo($node, SelectorSimple $selector): bool
    {
        $name = strtolower($selector->name ?? '');

        if ($name === 'first-child') {
            return $this->isFirstChild($node);
        }

        if ($name === 'last-child') {
            return $this->isLastChild($node);
        }

        if ($name === 'nth-child') {
            return $this->matchesNthChild($node, $selector->parsedArg);
        }

        if ($name === 'not') {
            $inner = $selector->parsedArg ?? Selector::parseSelector($selector->arg);
            return !$this->matches($node, $inner);
        }

        if ($name === 'only-child') {
            return $this->isFirstChild($node) && $this->isLastChild($node);
        }

        if ($name === 'empty') {
            if (!$node->hasChildNodes()) {
                return true;
            }
            foreach ($node->children as $child) {
                if (!property_exists($child, 'name')) {
                    continue;
                }
                if ($child->name === '#text') {
                    if ((string)$child->data !== '') {
                        return false;
                    }
                } elseif (!(isset($child->name[0]) && $child->name[0] === '#')) {
                    return false;
                }
            }
            return true;
        }

        if ($name === 'root') {
            $parent = $node->parent ?? null;
            if ($parent && property_exists($parent, 'name')) {
                return $parent->name === '#document' || $parent->name === '#document-fragment';
            }
            return false;
        }

        if ($name === 'first-of-type') {
            return $this->isFirstOfType($node);
        }

        if ($name === 'last-of-type') {
            return $this->isLastOfType($node);
        }

        if ($name === 'nth-of-type') {
            return $this->matchesNthOfType($node, $selector->parsedArg);
        }

        if ($name === 'only-of-type') {
            return $this->isFirstOfType($node) && $this->isLastOfType($node);
        }

        throw new SelectorError('Unsupported pseudo-class: :' . $name);
    }

    private function isElementChild($node): bool
    {
        return is_object($node)
            && property_exists($node, 'name')
            && !(isset($node->name[0]) && $node->name[0] === '#');
    }

    private function elementTypeKey($node): string
    {
        $namespace = (string)($node->namespace ?? 'html');
        $name = (string)$node->name;
        if ($namespace === 'html') {
            $name = strtolower($name);
        }
        return $namespace . "\0" . $name;
    }

    private function ensureParentIndex($parent): int
    {
        $parentId = spl_object_id($parent);
        if (isset($this->parentIndexes[$parentId])) {
            return $parentId;
        }

        $positions = [];
        $typePositions = [];
        $typeCounts = [];
        $previous = [];
        $position = 0;
        $previousElement = null;
        foreach ($parent->children ?? [] as $child) {
            if (!$this->isElementChild($child)) {
                continue;
            }
            $childId = spl_object_id($child);
            $position += 1;
            $positions[$childId] = $position;
            $previous[$childId] = $previousElement;
            $typeKey = $this->elementTypeKey($child);
            $typeCounts[$typeKey] = ($typeCounts[$typeKey] ?? 0) + 1;
            $typePositions[$childId] = $typeCounts[$typeKey];
            $previousElement = $child;
        }

        $this->parentIndexes[$parentId] = [
            'positions' => $positions,
            'count' => $position,
            'typePositions' => $typePositions,
            'typeCounts' => $typeCounts,
            'previous' => $previous,
        ];
        return $parentId;
    }

    private function getPreviousSibling($node, bool $allowStandaloneScan = true)
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return null;
        }

        $parentId = spl_object_id($parent);
        if ($this->standalone && $allowStandaloneScan && !isset($this->standaloneSiblingScans[$parentId])) {
            $this->standaloneSiblingScans[$parentId] = true;
            $previous = null;
            foreach ($parent->children ?? [] as $child) {
                if ($child === $node) {
                    return $previous;
                }
                if ($this->isElementChild($child)) {
                    $previous = $child;
                }
            }
            return null;
        }

        $parentId = $this->ensureParentIndex($parent);
        return $this->parentIndexes[$parentId]['previous'][spl_object_id($node)] ?? null;
    }

    private function isFirstChild($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        if ($this->standalone) {
            foreach ($parent->children ?? [] as $child) {
                if ($this->isElementChild($child)) {
                    return $child === $node;
                }
            }
            return false;
        }

        $parentId = $this->ensureParentIndex($parent);
        return ($this->parentIndexes[$parentId]['positions'][spl_object_id($node)] ?? 0) === 1;
    }

    private function isLastChild($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        if ($this->standalone) {
            $children = $parent->children ?? [];
            for ($i = count($children) - 1; $i >= 0; $i--) {
                if ($this->isElementChild($children[$i])) {
                    return $children[$i] === $node;
                }
            }
            return false;
        }

        $parentId = $this->ensureParentIndex($parent);
        $position = $this->parentIndexes[$parentId]['positions'][spl_object_id($node)] ?? 0;
        return $position !== 0 && $position === $this->parentIndexes[$parentId]['count'];
    }

    private function isFirstOfType($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $typeKey = $this->elementTypeKey($node);
        if ($this->standalone) {
            foreach ($parent->children ?? [] as $child) {
                if ($this->isElementChild($child) && $this->elementTypeKey($child) === $typeKey) {
                    return $child === $node;
                }
            }
            return false;
        }

        $parentId = $this->ensureParentIndex($parent);
        return ($this->parentIndexes[$parentId]['typePositions'][spl_object_id($node)] ?? 0) === 1;
    }

    private function isLastOfType($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $typeKey = $this->elementTypeKey($node);
        if ($this->standalone) {
            $children = $parent->children ?? [];
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $child = $children[$i];
                if ($this->isElementChild($child) && $this->elementTypeKey($child) === $typeKey) {
                    return $child === $node;
                }
            }
            return false;
        }

        $parentId = $this->ensureParentIndex($parent);
        $nodeId = spl_object_id($node);
        $position = $this->parentIndexes[$parentId]['typePositions'][$nodeId] ?? 0;
        return $position !== 0 && $position === ($this->parentIndexes[$parentId]['typeCounts'][$typeKey] ?? 0);
    }

    private function matchesNth(int $index, int $a, int $b): bool
    {
        if ($a === 0) {
            return $index === $b;
        }
        $diff = $index - $b;
        if ($a > 0) {
            return $diff >= 0 && $diff % $a === 0;
        }
        return $diff <= 0 && $diff % $a === 0;
    }

    private function matchesNthChild($node, $parsed): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent || !is_array($parsed) || count($parsed) !== 2) {
            return false;
        }
        [$a, $b] = $parsed;
        if ($this->standalone) {
            $position = 0;
            foreach ($parent->children ?? [] as $child) {
                if (!$this->isElementChild($child)) {
                    continue;
                }
                $position += 1;
                if ($child === $node) {
                    return $this->matchesNth($position, $a, $b);
                }
            }
            return false;
        }

        $parentId = $this->ensureParentIndex($parent);
        $position = $this->parentIndexes[$parentId]['positions'][spl_object_id($node)] ?? 0;
        return $position !== 0 && $this->matchesNth($position, $a, $b);
    }

    private function matchesNthOfType($node, $parsed): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent || !is_array($parsed) || count($parsed) !== 2) {
            return false;
        }
        [$a, $b] = $parsed;
        $typeKey = $this->elementTypeKey($node);
        if ($this->standalone) {
            $position = 0;
            foreach ($parent->children ?? [] as $child) {
                if ($this->isElementChild($child) && $this->elementTypeKey($child) === $typeKey) {
                    $position += 1;
                    if ($child === $node) {
                        return $this->matchesNth($position, $a, $b);
                    }
                }
            }
            return false;
        }

        $parentId = $this->ensureParentIndex($parent);
        $position = $this->parentIndexes[$parentId]['typePositions'][spl_object_id($node)] ?? 0;
        return $position !== 0 && $this->matchesNth($position, $a, $b);
    }
}

final class Selector
{
    private static ?SelectorMatcher $matcher = null;
    /** @var array<string|int, mixed> */
    private static array $parseCache = [];
    private const PARSE_CACHE_LIMIT = 128;

    public static function parseSelector(string $selector)
    {
        if (trim($selector) === '') {
            throw new SelectorError('Empty selector');
        }
        $tokenizer = new SelectorTokenizer(trim($selector));
        $tokens = $tokenizer->tokenize();
        $parser = new SelectorParser($tokens);
        return $parser->parse();
    }

    public static function query($root, string $selector): array
    {
        $parsed = self::cachedSelector($selector);
        $matcher = self::matcher();
        $matcher->beginOperation(false);
        try {
            $fast = self::fastQuery($root, $parsed);
            if ($fast !== null) {
                return $fast;
            }
            $results = [];
            self::queryDescendants($root, $parsed, $results);
            return $results;
        } finally {
            $matcher->releaseOperation();
        }
    }

    public static function queryFirst($root, string $selector)
    {
        $parsed = self::cachedSelector($selector);
        $matcher = self::matcher();
        $matcher->beginOperation(false);
        try {
            [$handled, $fastResult] = self::fastQueryFirst($root, $parsed);
            if ($handled) {
                return $fastResult;
            }
            return self::queryFirstDescendant($root, $parsed);
        } finally {
            $matcher->releaseOperation();
        }
    }

    public static function matches($node, string $selector): bool
    {
        $parsed = self::cachedSelector($selector);
        $matcher = self::matcher();
        $matcher->beginOperation(true);
        try {
            return $matcher->matches($node, $parsed);
        } finally {
            $matcher->releaseOperation();
        }
    }

    private static function cachedSelector(string $selector)
    {
        $key = trim($selector);
        if (array_key_exists($key, self::$parseCache)) {
            return self::$parseCache[$key];
        }
        $parsed = self::parseSelector($key);
        if (count(self::$parseCache) >= self::PARSE_CACHE_LIMIT) {
            $oldest = array_key_first(self::$parseCache);
            if ($oldest !== null) {
                unset(self::$parseCache[$oldest]);
            }
        }
        self::$parseCache[$key] = $parsed;
        return $parsed;
    }

    private static function matcher(): SelectorMatcher
    {
        if (self::$matcher === null) {
            self::$matcher = new SelectorMatcher();
        }
        return self::$matcher;
    }

    private static function fastQuery($root, $selector): ?array
    {
        if ($selector instanceof SelectorComplex) {
            $parts = $selector->parts;
            if (count($parts) === 1) {
                $compound = $parts[0][1];
                $id = self::compoundIdOnly($compound);
                if ($id !== null) {
                    $results = [];
                    self::queryDescendantsById($root, $id, $results);
                    return $results;
                }
                $tag = self::compoundTagOnly($compound);
                if ($tag !== null) {
                    $results = [];
                    self::queryDescendantsByTag($root, $tag, $results);
                    return $results;
                }
            }
            if (count($parts) === 2 && $parts[1][0] === ' ') {
                $id = self::compoundIdOnly($parts[0][1]);
                $tag = self::compoundTagOnly($parts[1][1]);
                if ($id !== null && $tag !== null) {
                    $results = [];
                    // The id element may sit above the queried root; the
                    // generic path walks real ancestor chains, so seed the
                    // fast path with the same information.
                    $insideId = self::hasAncestorWithId($root, $id);
                    self::queryDescendantsByIdTag($root, $id, $tag, $results, $insideId, false);
                    return $results;
                }
            }
        }
        return null;
    }

    /** @return array{0:bool,1:mixed} */
    private static function fastQueryFirst($root, $selector): array
    {
        if ($selector instanceof SelectorComplex) {
            $parts = $selector->parts;
            if (count($parts) === 1) {
                $compound = $parts[0][1];
                $id = self::compoundIdOnly($compound);
                if ($id !== null) {
                    return [true, self::queryFirstDescendantById($root, $id)];
                }
                $tag = self::compoundTagOnly($compound);
                if ($tag !== null) {
                    return [true, self::queryFirstDescendantByTag($root, $tag)];
                }
            }
            if (count($parts) === 2 && $parts[1][0] === ' ') {
                $id = self::compoundIdOnly($parts[0][1]);
                $tag = self::compoundTagOnly($parts[1][1]);
                if ($id !== null && $tag !== null) {
                    $insideId = self::hasAncestorWithId($root, $id);
                    return [true, self::queryFirstDescendantByIdTag($root, $id, $tag, $insideId, false)];
                }
            }
        }
        return [false, null];
    }

    private static function hasAncestorWithId($node, string $id): bool
    {
        $ancestor = $node->parent ?? null;
        while ($ancestor !== null) {
            if (
                property_exists($ancestor, 'name')
                && !(isset($ancestor->name[0]) && $ancestor->name[0] === '#')
            ) {
                $attrs = $ancestor->attrs ?? [];
                if (($attrs['id'] ?? '') === $id) {
                    return true;
                }
            }
            $ancestor = $ancestor->parent ?? null;
        }
        return false;
    }

    private static function compoundIdOnly(SelectorCompound $compound): ?string
    {
        if (count($compound->selectors) !== 1) {
            return null;
        }
        $simple = $compound->selectors[0];
        if ($simple->type !== SelectorSimple::TYPE_ID || $simple->name === null) {
            return null;
        }
        return $simple->name;
    }

    private static function compoundTagOnly(SelectorCompound $compound): ?string
    {
        if (count($compound->selectors) !== 1) {
            return null;
        }
        $simple = $compound->selectors[0];
        if ($simple->type !== SelectorSimple::TYPE_TAG || $simple->name === null) {
            return null;
        }
        return $simple->name;
    }

    private static function queryDescendantsById($node, string $id, array &$results): void
    {
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                    $attrs = $child->attrs ?? [];
                    if (($attrs['id'] ?? '') === $id) {
                        $results[] = $child;
                    }
                }
                self::queryDescendantsById($child, $id, $results);
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            self::queryDescendantsById($node->templateContent, $id, $results);
        }
    }

    private static function queryFirstDescendantById($node, string $id)
    {
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                    $attrs = $child->attrs ?? [];
                    if (($attrs['id'] ?? '') === $id) {
                        return $child;
                    }
                }
                $found = self::queryFirstDescendantById($child, $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            return self::queryFirstDescendantById($node->templateContent, $id);
        }

        return null;
    }

    private static function queryDescendantsByTag($node, string $tag, array &$results): void
    {
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                    if (self::tagMatches($child, $tag)) {
                        $results[] = $child;
                    }
                }
                self::queryDescendantsByTag($child, $tag, $results);
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            self::queryDescendantsByTag($node->templateContent, $tag, $results);
        }
    }

    private static function queryFirstDescendantByTag($node, string $tag)
    {
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                    if (self::tagMatches($child, $tag)) {
                        return $child;
                    }
                }
                $found = self::queryFirstDescendantByTag($child, $tag);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            return self::queryFirstDescendantByTag($node->templateContent, $tag);
        }

        return null;
    }

    private static function queryDescendantsByIdTag(
        $node,
        string $id,
        string $tag,
        array &$results,
        bool $insideId,
        bool $matchSelf
    ): void {
        $hasId = false;
        if (property_exists($node, 'name') && !(isset($node->name[0]) && $node->name[0] === '#')) {
            $attrs = $node->attrs ?? [];
            $hasId = ($attrs['id'] ?? '') === $id;
            if ($matchSelf && $insideId && self::tagMatches($node, $tag)) {
                $results[] = $node;
            }
        }

        $nextInside = $insideId || $hasId;

        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                self::queryDescendantsByIdTag($child, $id, $tag, $results, $nextInside, true);
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            // Template contents are a separate document fragment; the
            // template element and its ancestors are not ancestors of nodes
            // inside that fragment.
            self::queryDescendantsByIdTag($node->templateContent, $id, $tag, $results, false, true);
        }
    }

    private static function queryFirstDescendantByIdTag(
        $node,
        string $id,
        string $tag,
        bool $insideId,
        bool $matchSelf
    ) {
        $hasId = false;
        if (property_exists($node, 'name') && !(isset($node->name[0]) && $node->name[0] === '#')) {
            $attrs = $node->attrs ?? [];
            $hasId = ($attrs['id'] ?? '') === $id;
            if ($matchSelf && $insideId && self::tagMatches($node, $tag)) {
                return $node;
            }
        }

        $nextInside = $insideId || $hasId;

        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                $found = self::queryFirstDescendantByIdTag($child, $id, $tag, $nextInside, true);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            return self::queryFirstDescendantByIdTag($node->templateContent, $id, $tag, false, true);
        }

        return null;
    }

    private static function tagMatches($node, string $tag): bool
    {
        if (($node->namespace ?? 'html') === 'html') {
            return strtolower((string)$node->name) === strtolower($tag);
        }
        return (string)$node->name === $tag;
    }

    private static function queryFirstDescendant($node, $selector)
    {
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                    if (self::matcher()->matches($child, $selector)) {
                        return $child;
                    }
                }
                $found = self::queryFirstDescendant($child, $selector);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            return self::queryFirstDescendant($node->templateContent, $selector);
        }

        return null;
    }

    private static function queryDescendants($node, $selector, array &$results): void
    {
        if ($node->hasChildNodes()) {
            foreach ($node->children as $child) {
                if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                    if (self::matcher()->matches($child, $selector)) {
                        $results[] = $child;
                    }
                }
                self::queryDescendants($child, $selector, $results);
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            self::queryDescendants($node->templateContent, $selector, $results);
        }
    }
}
