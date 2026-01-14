<?php

declare(strict_types=1);

namespace JustHTML;

class SimpleDomNode
{
    public string $name;
    public ?SimpleDomNode $parent = null;
    /** @var array<string, string|null>|null */
    public ?array $attrs = null;
    /** @var array<int, mixed>|null */
    public ?array $children = null;
    /** @var string|Doctype|null */
    public $data = null;
    public ?string $namespace = null;

    /** @param array<string, string|null>|null $attrs */
    public function __construct(string $name, ?array $attrs = null, $data = null, ?string $namespace = null)
    {
        $this->name = $name;
        $this->data = $data;

        if (isset($name[0]) && $name[0] === '#' || $name === '!doctype') {
            $this->namespace = $namespace;
            if ($name === '#comment' || $name === '!doctype') {
                $this->children = null;
                $this->attrs = null;
            } else {
                $this->children = [];
                $this->attrs = $attrs ?? [];
            }
        } else {
            $this->namespace = $namespace ?? 'html';
            $this->children = [];
            $this->attrs = $attrs ?? [];
        }
    }

    public function appendChild($node): void
    {
        if ($this->children !== null) {
            $this->children[] = $node;
            $node->parent = $this;
        }
    }

    public function removeChild($node): void
    {
        if ($this->children === null) {
            return;
        }
        $index = array_search($node, $this->children, true);
        if ($index !== false) {
            array_splice($this->children, (int)$index, 1);
            $node->parent = null;
        }
    }

    public function insertBefore($node, $referenceNode): void
    {
        if ($this->children === null) {
            throw new \RuntimeException("Node {$this->name} cannot have children");
        }

        if ($referenceNode === null) {
            $this->appendChild($node);
            return;
        }

        $index = array_search($referenceNode, $this->children, true);
        if ($index === false) {
            throw new \RuntimeException('Reference node is not a child of this node');
        }

        array_splice($this->children, (int)$index, 0, [$node]);
        $node->parent = $this;
    }

    public function replaceChild($newNode, $oldNode)
    {
        if ($this->children === null) {
            throw new \RuntimeException("Node {$this->name} cannot have children");
        }

        $index = array_search($oldNode, $this->children, true);
        if ($index === false) {
            throw new \RuntimeException('The node to be replaced is not a child of this node');
        }

        $this->children[$index] = $newNode;
        $newNode->parent = $this;
        $oldNode->parent = null;
        return $oldNode;
    }

    public function hasChildNodes(): bool
    {
        return !empty($this->children);
    }

    public function cloneNode(bool $deep = false): SimpleDomNode
    {
        $clone = new SimpleDomNode(
            $this->name,
            $this->attrs !== null ? $this->attrs : null,
            $this->data,
            $this->namespace
        );
        if ($deep && $this->children) {
            foreach ($this->children as $child) {
                $clone->appendChild($child->cloneNode(true));
            }
        }
        return $clone;
    }

    public function getText(): string
    {
        if ($this->name === '#text') {
            return is_string($this->data) ? $this->data : '';
        }
        return '';
    }

    public function __get(string $name)
    {
        if ($name === 'text') {
            return $this->getText();
        }
        trigger_error('Undefined property: ' . get_class($this) . '::$' . $name, E_USER_NOTICE);
        return null;
    }

    public function toHtml(int $indent = 0, int $indentSize = 2, bool $pretty = true): string
    {
        return Serialize::toHtml($this, $indent, $indentSize, $pretty);
    }

    /** @return array<int, mixed> */
    public function query(string $selector): array
    {
        return Selector::query($this, $selector);
    }

    public function matches(string $selector): bool
    {
        return Selector::matches($this, $selector);
    }

    public function toTestFormat(int $indent = 0): string
    {
        return Serialize::toTestFormat($this, $indent);
    }

    public function toText(string $separator = ' ', bool $strip = true): string
    {
        $parts = [];
        $this->collectText($this, $parts, $strip);
        if (!$parts) {
            return '';
        }
        return implode($separator, $parts);
    }

    private function collectText($node, array &$parts, bool $strip): void
    {
        $name = $node->name;
        if ($name === '#text') {
            $data = $node->data;
            if (!is_string($data) || $data === '') {
                return;
            }
            $text = $strip ? trim($data) : $data;
            if ($text === '') {
                return;
            }
            $parts[] = $text;
            return;
        }

        if (!empty($node->children)) {
            foreach ($node->children as $child) {
                $this->collectText($child, $parts, $strip);
            }
        }

        if ($node instanceof ElementNode && $node->templateContent !== null) {
            $this->collectText($node->templateContent, $parts, $strip);
        }
    }

    public function toMarkdown(): string
    {
        return Markdown::toMarkdown($this);
    }
}

class ElementNode extends SimpleDomNode
{
    public ?SimpleDomNode $templateContent = null;

    /** @param array<string, string|null>|null $attrs */
    public function __construct(string $name, ?array $attrs, ?string $namespace)
    {
        $this->name = $name;
        $this->parent = null;
        $this->data = null;
        $this->namespace = $namespace;
        $this->children = [];
        $this->attrs = $attrs ?? [];
        $this->templateContent = null;
    }

    public function cloneNode(bool $deep = false): ElementNode
    {
        $clone = new ElementNode($this->name, $this->attrs ?? [], $this->namespace);
        if ($deep && $this->children) {
            foreach ($this->children as $child) {
                $clone->appendChild($child->cloneNode(true));
            }
        }
        return $clone;
    }
}

final class TemplateNode extends ElementNode
{
    /** @param array<string, string|null>|null $attrs */
    public function __construct(string $name, ?array $attrs = null, ?string $namespace = null)
    {
        parent::__construct($name, $attrs, $namespace);
        if ($this->namespace === 'html') {
            $this->templateContent = new SimpleDomNode('#document-fragment');
        }
    }

    public function cloneNode(bool $deep = false): TemplateNode
    {
        $clone = new TemplateNode($this->name, $this->attrs ?? [], $this->namespace);
        if ($deep) {
            if ($this->templateContent) {
                $clone->templateContent = $this->templateContent->cloneNode(true);
            }
            if ($this->children) {
                foreach ($this->children as $child) {
                    $clone->appendChild($child->cloneNode(true));
                }
            }
        }
        return $clone;
    }
}

final class TextNode
{
    public ?string $data;
    public string $name = '#text';
    public ?string $namespace = null;
    public ?SimpleDomNode $parent = null;
    /** @var array<int, mixed> */
    public array $children = [];

    public function __construct(?string $data)
    {
        $this->data = $data;
    }

    public function getText(): string
    {
        return $this->data ?? '';
    }

    public function __get(string $name)
    {
        if ($name === 'text') {
            return $this->getText();
        }
        trigger_error('Undefined property: ' . get_class($this) . '::$' . $name, E_USER_NOTICE);
        return null;
    }

    public function toText(string $separator = ' ', bool $strip = true): string
    {
        if ($this->data === null) {
            return '';
        }
        return $strip ? trim($this->data) : $this->data;
    }

    public function toHtml(int $indent = 0, int $indentSize = 2, bool $pretty = true): string
    {
        return Serialize::toHtml($this, $indent, $indentSize, $pretty);
    }

    public function toTestFormat(int $indent = 0): string
    {
        return Serialize::toTestFormat($this, $indent);
    }

    public function toMarkdown(): string
    {
        return Markdown::toMarkdown($this);
    }

    public function hasChildNodes(): bool
    {
        return false;
    }

    public function cloneNode(bool $deep = false): TextNode
    {
        return new TextNode($this->data);
    }

    /** @return array<int, mixed> */
    public function children(): array
    {
        return [];
    }

    public function matches(string $selector): bool
    {
        return Selector::matches($this, $selector);
    }
}
