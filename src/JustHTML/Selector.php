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

    private function readName(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->length) {
            $ch = $this->selector[$this->pos];
            if (!$this->isNameChar($ch)) {
                break;
            }
            $this->pos += 1;
        }
        return substr($this->selector, $start, $this->pos - $start);
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
                $this->pos += 1;
                if ($this->pos < $this->length) {
                    $parts[] = $this->selector[$this->pos];
                    $this->pos += 1;
                    $start = $this->pos;
                } else {
                    $start = $this->pos;
                }
            } else {
                $this->pos += 1;
            }
        }

        throw new SelectorError('Unterminated string in selector: ' . var_export($this->selector, true));
    }

    private function readUnquotedAttrValue(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->length) {
            $ch = $this->selector[$this->pos];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f" || $ch === ']') {
                break;
            }
            $this->pos += 1;
        }
        return substr($this->selector, $start, $this->pos - $start);
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

                    $parenDepth = 1;
                    $argStart = $this->pos;
                    while ($this->pos < $this->length && $parenDepth > 0) {
                        $c = $this->selector[$this->pos];
                        if ($c === '(') {
                            $parenDepth += 1;
                        } elseif ($c === ')') {
                            $parenDepth -= 1;
                        }
                        if ($parenDepth > 0) {
                            $this->pos += 1;
                        }
                    }

                    $arg = trim(substr($this->selector, $argStart, $this->pos - $argStart));
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

            if ($this->isNameStart($ch)) {
                $name = $this->readName();
                $tokens[] = new SelectorToken(SelectorTokenType::TAG, strtolower($name));
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
            if ($selector !== null) {
                $selectors[] = $selector;
            }
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
            return new SelectorSimple(SelectorSimple::TYPE_PSEUDO, $name, null, null, $arg);
        }

        return new SelectorSimple(SelectorSimple::TYPE_PSEUDO, $name);
    }
}

final class SelectorMatcher
{
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
        $parts = $selector->parts;
        if (!$parts) {
            return false;
        }

        $last = $parts[count($parts) - 1];
        if (!$this->matchesCompound($node, $last[1])) {
            return false;
        }

        $current = $node;
        for ($i = count($parts) - 2; $i >= 0; $i--) {
            $combinator = $parts[$i + 1][0];
            $prevCompound = $parts[$i][1];

            if ($combinator === ' ') {
                $found = false;
                $ancestor = $current->parent ?? null;
                while ($ancestor) {
                    if ($this->matchesCompound($ancestor, $prevCompound)) {
                        $current = $ancestor;
                        $found = true;
                        break;
                    }
                    $ancestor = $ancestor->parent ?? null;
                }
                if (!$found) {
                    return false;
                }
            } elseif ($combinator === '>') {
                $parent = $current->parent ?? null;
                if (!$parent || !$this->matchesCompound($parent, $prevCompound)) {
                    return false;
                }
                $current = $parent;
            } elseif ($combinator === '+') {
                $sibling = $this->getPreviousSibling($current);
                if (!$sibling || !$this->matchesCompound($sibling, $prevCompound)) {
                    return false;
                }
                $current = $sibling;
            } else {
                $found = false;
                $sibling = $this->getPreviousSibling($current);
                while ($sibling) {
                    if ($this->matchesCompound($sibling, $prevCompound)) {
                        $current = $sibling;
                        $found = true;
                        break;
                    }
                    $sibling = $this->getPreviousSibling($sibling);
                }
                if (!$found) {
                    return false;
                }
            }
        }

        return true;
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
            return strtolower((string)$node->name) === strtolower($name);
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
        $attrName = strtolower($selector->name ?? '');

        $attrValue = null;
        foreach ($attrs as $name => $value) {
            if (strtolower($name) === $attrName) {
                $attrValue = $value;
                break;
            }
        }

        if ($attrValue === null) {
            return false;
        }

        if ($selector->operator === null) {
            return true;
        }

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
            return $this->matchesNthChild($node, $selector->arg);
        }

        if ($name === 'not') {
            if ($selector->arg === null || $selector->arg === '') {
                return true;
            }
            $inner = Selector::parseSelector($selector->arg);
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
                    if ($child->data && trim((string)$child->data) !== '') {
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
            return $this->matchesNthOfType($node, $selector->arg);
        }

        if ($name === 'only-of-type') {
            return $this->isFirstOfType($node) && $this->isLastOfType($node);
        }

        throw new SelectorError('Unsupported pseudo-class: :' . $name);
    }

    /** @return array<int, mixed> */
    private function getElementChildren($parent): array
    {
        if (!$parent || !$parent->hasChildNodes()) {
            return [];
        }
        $elements = [];
        foreach ($parent->children as $child) {
            if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                $elements[] = $child;
            }
        }
        return $elements;
    }

    private function getPreviousSibling($node)
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return null;
        }

        $prev = null;
        foreach ($parent->children as $child) {
            if ($child === $node) {
                return $prev;
            }
            if (property_exists($child, 'name') && !(isset($child->name[0]) && $child->name[0] === '#')) {
                $prev = $child;
            }
        }
        return null;
    }

    private function isFirstChild($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $elements = $this->getElementChildren($parent);
        return $elements && $elements[0] === $node;
    }

    private function isLastChild($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $elements = $this->getElementChildren($parent);
        return $elements && $elements[count($elements) - 1] === $node;
    }

    private function isFirstOfType($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $nodeName = strtolower((string)$node->name);
        foreach ($this->getElementChildren($parent) as $child) {
            if (strtolower((string)$child->name) === $nodeName) {
                return $child === $node;
            }
        }
        return false;
    }

    private function isLastOfType($node): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $nodeName = strtolower((string)$node->name);
        $last = null;
        foreach ($this->getElementChildren($parent) as $child) {
            if (strtolower((string)$child->name) === $nodeName) {
                $last = $child;
            }
        }
        return $last === $node;
    }

    private function parseNthExpression(?string $expr): ?array
    {
        if ($expr === null) {
            return null;
        }
        $expr = strtolower(trim($expr));
        if ($expr === '') {
            return null;
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
                return null;
            }

            $b = 0;
            if ($bPart !== '') {
                if (!preg_match('/^[+-]?\d+$/', $bPart)) {
                    return null;
                }
                $b = (int)$bPart;
            }
        } else {
            if (!preg_match('/^[+-]?\d+$/', $expr)) {
                return null;
            }
            $a = 0;
            $b = (int)$expr;
        }

        return [$a, $b];
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

    private function matchesNthChild($node, ?string $arg): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $parsed = $this->parseNthExpression($arg);
        if ($parsed === null) {
            return false;
        }
        [$a, $b] = $parsed;
        $elements = $this->getElementChildren($parent);
        foreach ($elements as $i => $child) {
            if ($child === $node) {
                return $this->matchesNth($i + 1, $a, $b);
            }
        }
        return false;
    }

    private function matchesNthOfType($node, ?string $arg): bool
    {
        $parent = $node->parent ?? null;
        if (!$parent) {
            return false;
        }
        $parsed = $this->parseNthExpression($arg);
        if ($parsed === null) {
            return false;
        }
        [$a, $b] = $parsed;
        $nodeName = strtolower((string)$node->name);
        $typeIndex = 0;
        foreach ($this->getElementChildren($parent) as $child) {
            if (strtolower((string)$child->name) === $nodeName) {
                $typeIndex += 1;
                if ($child === $node) {
                    return $this->matchesNth($typeIndex, $a, $b);
                }
            }
        }
        return false;
    }
}

final class Selector
{
    private static ?SelectorMatcher $matcher = null;

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
        $parsed = self::parseSelector($selector);
        $results = [];
        self::queryDescendants($root, $parsed, $results);
        return $results;
    }

    public static function matches($node, string $selector): bool
    {
        $parsed = self::parseSelector($selector);
        return self::matcher()->matches($node, $parsed);
    }

    private static function matcher(): SelectorMatcher
    {
        if (self::$matcher === null) {
            self::$matcher = new SelectorMatcher();
        }
        return self::$matcher;
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
