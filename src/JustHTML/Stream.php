<?php

declare(strict_types=1);

namespace JustHTML;

final class StreamDummyNode
{
    public string $name;
    public string $namespace;
    public string $childMode;

    public function __construct(string $name, string $namespace, string $childMode)
    {
        $this->name = $name;
        $this->namespace = $namespace;
        $this->childMode = $childMode;
    }
}

final class StreamSink
{
    /** @var array<int, array{0:string,1:mixed}> */
    public array $tokens = [];
    /** @var array<int, StreamDummyNode> */
    public array $openElements = [];

    public function processToken($token): int
    {
        if ($token instanceof Tag) {
            if ($token->kind === Tag::START) {
                $attrs = $token->attrs ? $token->attrs : [];
                $this->tokens[] = ['start', [$token->name, $attrs]];
                $this->processStartTag($token->name, $attrs, $token->selfClosing);
            } else {
                $this->tokens[] = ['end', $token->name];
                $this->processEndTag($token->name);
            }
            return TokenSinkResult::Continue;
        }

        if ($token instanceof CommentToken) {
            $this->tokens[] = ['comment', $token->data];
            return TokenSinkResult::Continue;
        }

        if ($token instanceof DoctypeToken) {
            $dt = $token->doctype;
            $this->tokens[] = ['doctype', [$dt->name, $dt->publicId, $dt->systemId]];
            return TokenSinkResult::Continue;
        }

        return TokenSinkResult::Continue;
    }

    /** @param array<string, string|null> $attrs */
    private function processStartTag(string $name, array $attrs, bool $selfClosing): void
    {
        $name = strtolower($name);
        $htmlMode = $this->startTagUsesHtmlRules($name, $attrs);
        $current = $this->currentNode();

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

        $isHtmlVoid = $namespace === 'html' && isset(Constants::VOID_ELEMENTS[$name]);
        $acknowledgeSelfClosing = $namespace !== 'html' && $selfClosing;
        if ($isHtmlVoid || $acknowledgeSelfClosing) {
            return;
        }

        if ($namespace === 'html' || $this->isHtmlIntegrationPoint($namespace, $name, $attrs)) {
            $childMode = 'html';
        } elseif ($this->isMathmlTextIntegrationPoint($namespace, $name)) {
            $childMode = 'math-text';
        } else {
            $childMode = 'foreign';
        }

        $this->openElements[] = new StreamDummyNode($name, $namespace, $childMode);
    }

    /** @param array<string, string|null> $attrs */
    private function startTagUsesHtmlRules(string $name, array $attrs): bool
    {
        $current = $this->currentNode();
        if ($current === null || $current->namespace === 'html') {
            return true;
        }

        if ($current->childMode === 'html') {
            return true;
        }
        if ($current->childMode === 'math-text' && !in_array($name, ['mglyph', 'malignmark'], true)) {
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

    private function processEndTag(string $name): void
    {
        $name = strtolower($name);
        $count = count($this->openElements);
        if ($count === 0) {
            return;
        }

        // Well-formed input takes this constant-time path.
        if ($this->openElements[$count - 1]->name === $name) {
            array_pop($this->openElements);
            return;
        }

        $topIsHtml = $this->openElements[$count - 1]->namespace === 'html';
        if (!$topIsHtml && ($name === 'br' || $name === 'p')) {
            $this->popUntilHtmlOrIntegrationPoint();
            $count = count($this->openElements);
            if ($count === 0) {
                return;
            }
            $topIsHtml = $this->openElements[$count - 1]->namespace === 'html';
        }

        for ($i = $count - 2; $i >= 0; $i--) {
            $nodeIsHtml = $this->openElements[$i]->namespace === 'html';
            if ($nodeIsHtml !== $topIsHtml) {
                return;
            }
            if ($this->openElements[$i]->name === $name) {
                array_splice($this->openElements, $i);
                return;
            }
        }
    }

    private function currentNode(): ?StreamDummyNode
    {
        if (!$this->openElements) {
            return null;
        }
        return $this->openElements[count($this->openElements) - 1];
    }

    private function popUntilHtmlOrIntegrationPoint(): void
    {
        while ($this->openElements) {
            $current = $this->currentNode();
            if ($current === null || $current->namespace === 'html' || $current->childMode === 'html') {
                return;
            }
            array_pop($this->openElements);
        }
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

        $canonicalName = $name;
        if ($namespace === 'svg' && isset(Constants::SVG_TAG_NAME_ADJUSTMENTS[$name])) {
            $canonicalName = Constants::SVG_TAG_NAME_ADJUSTMENTS[$name];
        }
        return isset(Constants::HTML_INTEGRATION_POINT_SET[$namespace . '|' . $canonicalName]);
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
            if (in_array(strtolower((string)$attrName), ['color', 'face', 'size'], true)) {
                return true;
            }
        }
        return false;
    }

    public function processCharacters(string $data): void
    {
        $this->tokens[] = ['text', $data];
    }
}

final class Stream
{
    /**
     * Yields completed elements matching the supported streaming selector
     * subset, in document order. Results are detached from parser state.
     *
     * Selector compilation is eager: invalid or unsupported selectors throw
     * SelectorError when this method is called, before iteration starts.
     *
     * @return \Generator<int, SimpleDomNode>
     */
    public static function select(
        $html,
        string $selector,
        ?string $encoding = null,
        bool $bytes = false
    ): \Generator {
        return StreamSelect::select($html, $selector, [
            'encoding' => $encoding,
            'bytes' => $bytes,
            'prune' => true,
        ]);
    }

    /**
     * Returns the first document-order match, or null when none exists.
     */
    public static function selectFirst(
        $html,
        string $selector,
        ?string $encoding = null,
        bool $bytes = false
    ): ?SimpleDomNode {
        foreach (self::select($html, $selector, $encoding, $bytes) as $node) {
            return $node;
        }
        return null;
    }

    /**
     * Lazily yields tokenizer events from a complete in-memory HTML input.
     *
     * This is event iteration, not incremental file or network I/O.
     *
     * @return \Generator<int, array{0:string,1:mixed}>
     */
    public static function events($html, ?string $encoding = null, bool $bytes = false): \Generator
    {
        if ($html === null) {
            $htmlStr = '';
        } elseif ($bytes) {
            [$htmlStr, $_] = Encoding::decodeHtml((string)$html, $encoding);
        } else {
            $htmlStr = (string)$html;
        }

        $sink = new StreamSink();
        $tokenizer = new Tokenizer($sink);
        $tokenizer->initialize($htmlStr);

        while (true) {
            $isEof = $tokenizer->step();

            if ($sink->tokens) {
                $textBuffer = [];
                foreach ($sink->tokens as $entry) {
                    $event = $entry[0];
                    $data = $entry[1];
                    if ($event === 'text') {
                        $textBuffer[] = $data;
                        continue;
                    }
                    if ($textBuffer) {
                        yield ['text', implode('', $textBuffer)];
                        $textBuffer = [];
                    }
                    yield [$event, $data];
                }
                if ($textBuffer) {
                    yield ['text', implode('', $textBuffer)];
                }
                $sink->tokens = [];
            }

            if ($isEof) {
                break;
            }
        }
    }

    /**
     * @deprecated Use Stream::events() instead.
     */
    public static function stream($html, ?string $encoding = null, bool $bytes = false): \Generator
    {
        return self::events($html, $encoding, $bytes);
    }
}
