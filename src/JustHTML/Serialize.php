<?php

declare(strict_types=1);

namespace JustHTML;

final class Serialize
{
    private const RAW_TEXT_ELEMENTS = [
        'script' => true,
        'style' => true,
        'xmp' => true,
        'iframe' => true,
        'noembed' => true,
        'noframes' => true,
        'plaintext' => true,
    ];

    // Pretty printing is only allowed in containers whose content model can
    // safely contain block-level children. Phrasing and preformatted elements
    // stay compact so indentation never becomes part of their text.
    private const PRETTY_CONTAINERS = [
        'address' => true,
        'article' => true,
        'aside' => true,
        'blockquote' => true,
        'body' => true,
        'caption' => true,
        'colgroup' => true,
        'dd' => true,
        'details' => true,
        'dialog' => true,
        'div' => true,
        'dl' => true,
        'dt' => true,
        'fieldset' => true,
        'figcaption' => true,
        'figure' => true,
        'footer' => true,
        'form' => true,
        'head' => true,
        'header' => true,
        'hgroup' => true,
        'html' => true,
        'li' => true,
        'main' => true,
        'menu' => true,
        'nav' => true,
        'noframes' => true,
        'noscript' => true,
        'ol' => true,
        'search' => true,
        'section' => true,
        'table' => true,
        'tbody' => true,
        'td' => true,
        'template' => true,
        'tfoot' => true,
        'th' => true,
        'thead' => true,
        'tr' => true,
        'ul' => true,
    ];

    // Line breaks around these HTML elements do not alter inline phrasing.
    private const BLOCK_ELEMENTS = [
        'address' => true,
        'article' => true,
        'aside' => true,
        'base' => true,
        'basefont' => true,
        'bgsound' => true,
        'blockquote' => true,
        'body' => true,
        'caption' => true,
        'col' => true,
        'colgroup' => true,
        'dd' => true,
        'details' => true,
        'dialog' => true,
        'dir' => true,
        'div' => true,
        'dl' => true,
        'dt' => true,
        'fieldset' => true,
        'figcaption' => true,
        'figure' => true,
        'footer' => true,
        'form' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'head' => true,
        'header' => true,
        'hgroup' => true,
        'hr' => true,
        'html' => true,
        'legend' => true,
        'li' => true,
        'link' => true,
        'main' => true,
        'menu' => true,
        'meta' => true,
        'nav' => true,
        'noframes' => true,
        'noscript' => true,
        'ol' => true,
        'p' => true,
        'pre' => true,
        'script' => true,
        'search' => true,
        'section' => true,
        'style' => true,
        'summary' => true,
        'table' => true,
        'tbody' => true,
        'td' => true,
        'template' => true,
        'tfoot' => true,
        'th' => true,
        'thead' => true,
        'title' => true,
        'tr' => true,
        'ul' => true,
        'xmp' => true,
    ];

    private static function escapeText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private static function chooseAttrQuote(?string $value): string
    {
        if ($value === null) {
            return '"';
        }
        if (strpos($value, '"') !== false && strpos($value, "'") === false) {
            return "'";
        }
        return '"';
    }

    private static function escapeAttrValue(?string $value, string $quoteChar): string
    {
        if ($value === null) {
            return '';
        }
        $escaped = str_replace('&', '&amp;', $value);
        if ($quoteChar === '"') {
            return str_replace('"', '&quot;', $escaped);
        }
        return str_replace("'", '&#39;', $escaped);
    }

    private static function canUnquoteAttrValue(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];
            if ($ch === '>') {
                return false;
            }
            if ($ch === '"' || $ch === "'" || $ch === '=') {
                return false;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\f" || $ch === "\r") {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, string|null>|null $attrs */
    public static function serializeStartTag(string $name, ?array $attrs): string
    {
        $attrs = $attrs ?? [];
        $parts = ['<', $name];
        foreach ($attrs as $key => $value) {
            if ($value === null || $value === '') {
                $parts[] = ' ';
                $parts[] = $key;
                continue;
            }
            if (self::canUnquoteAttrValue($value)) {
                $escaped = str_replace('&', '&amp;', $value);
                $parts[] = ' ';
                $parts[] = $key;
                $parts[] = '=';
                $parts[] = $escaped;
            } else {
                $quote = self::chooseAttrQuote($value);
                $escaped = self::escapeAttrValue($value, $quote);
                $parts[] = ' ';
                $parts[] = $key;
                $parts[] = '=';
                $parts[] = $quote;
                $parts[] = $escaped;
                $parts[] = $quote;
            }
        }
        $parts[] = '>';
        return implode('', $parts);
    }

    public static function serializeEndTag(string $name): string
    {
        return "</{$name}>";
    }

    public static function toHtml($node, int $indent = 0, int $indentSize = 2, bool $pretty = true): string
    {
        return self::nodeToHtml($node, $indent, $indentSize, $pretty, null, null);
    }

    private static function nodeToHtml(
        $node,
        int $indent,
        int $indentSize,
        bool $pretty,
        ?string $parentName,
        ?string $parentNamespace
    ): string {
        $prefix = $pretty ? str_repeat(' ', $indent * $indentSize) : '';
        $name = $node->name;

        if ($name === '#text') {
            $text = (string)($node->data ?? '');
            if (
                $parentName !== null
                && self::isHtmlNamespace($parentNamespace)
                && isset(self::RAW_TEXT_ELEMENTS[strtolower($parentName)])
            ) {
                return ($pretty ? $prefix : '') . $text;
            }
            return $prefix . self::escapeText($text);
        }

        if ($name === '#comment') {
            return $prefix . '<!--' . ($node->data ?? '') . '-->';
        }

        if ($name === '!doctype') {
            return $prefix . self::serializeDoctype($node);
        }

        if ($name === '#document' || $name === '#document-fragment') {
            return self::serializeContainerChildren(
                $node->children ?? [],
                $indent,
                $indentSize,
                $pretty
            );
        }

        $attrs = $node->attrs ?? [];
        $openTag = self::serializeStartTag($name, $attrs);
        $namespace = $node->namespace ?? null;
        $isHtml = self::isHtmlNamespace($namespace);
        $htmlName = strtolower((string)$name);

        if ($isHtml && isset(Constants::VOID_ELEMENTS[$htmlName])) {
            return $prefix . $openTag;
        }

        $children = $node->children ?? [];
        if (
            $isHtml
            && $htmlName === 'template'
            && $node instanceof ElementNode
            && $node->templateContent !== null
        ) {
            $children = $node->templateContent->children ?? [];
        }
        if (!$children) {
            return $prefix . $openTag . self::serializeEndTag($name);
        }

        // The compact path never classifies or re-walks children.
        if (!$pretty) {
            $parts = [$openTag];
            foreach ($children as $child) {
                $parts[] = self::nodeToHtml($child, 0, $indentSize, false, $name, $namespace);
            }
            $parts[] = self::serializeEndTag($name);
            return implode('', $parts);
        }

        $canFormat = $isHtml
            && isset(self::PRETTY_CONTAINERS[$htmlName])
            && self::canPrettyPrintChildren($children);
        if (!$canFormat) {
            $parts = [$prefix . $openTag];
            foreach ($children as $child) {
                $parts[] = self::nodeToHtml($child, 0, $indentSize, false, $name, $namespace);
            }
            $parts[] = self::serializeEndTag($name);
            return implode('', $parts);
        }

        $parts = [$prefix . $openTag];
        foreach ($children as $child) {
            if ($child->name === '#text' && self::isFormattingWhitespace((string)($child->data ?? ''))) {
                continue;
            }
            $childHtml = self::nodeToHtml(
                $child,
                $indent + 1,
                $indentSize,
                true,
                $name,
                $namespace
            );
            if ($childHtml !== '') {
                $parts[] = $childHtml;
            }
        }
        $parts[] = $prefix . self::serializeEndTag($name);
        return implode("\n", $parts);
    }

    /** @param array<int, mixed> $children */
    private static function serializeContainerChildren(
        array $children,
        int $indent,
        int $indentSize,
        bool $pretty
    ): string {
        if (!$children) {
            return '';
        }

        $canFormat = $pretty && self::canPrettyPrintChildren($children);
        $parts = [];
        foreach ($children as $child) {
            if (
                $canFormat
                && $child->name === '#text'
                && self::isFormattingWhitespace((string)($child->data ?? ''))
            ) {
                continue;
            }
            $parts[] = self::nodeToHtml(
                $child,
                $canFormat ? $indent : 0,
                $indentSize,
                $canFormat,
                null,
                null
            );
        }
        return $canFormat ? implode("\n", $parts) : implode('', $parts);
    }

    /** @param array<int, mixed> $children */
    private static function canPrettyPrintChildren(array $children): bool
    {
        $hasStructuralChild = false;
        foreach ($children as $child) {
            $childName = (string)$child->name;
            if ($childName === '#text') {
                if (!self::isFormattingWhitespace((string)($child->data ?? ''))) {
                    return false;
                }
                continue;
            }
            if ($childName === '#comment' || $childName === '!doctype') {
                $hasStructuralChild = true;
                continue;
            }
            if (isset($childName[0]) && $childName[0] === '#') {
                return false;
            }
            if (
                !self::isHtmlNamespace($child->namespace ?? null)
                || !isset(self::BLOCK_ELEMENTS[strtolower($childName)])
            ) {
                return false;
            }
            $hasStructuralChild = true;
        }
        return $hasStructuralChild;
    }

    private static function isFormattingWhitespace(string $text): bool
    {
        return $text === '' || strspn($text, "\t\n\f\r ") === strlen($text);
    }

    private static function isHtmlNamespace(?string $namespace): bool
    {
        return $namespace === null || $namespace === 'html';
    }

    private static function serializeDoctype($node): string
    {
        $doctype = $node->data;
        $name = $doctype instanceof Doctype && $doctype->name !== null ? $doctype->name : 'html';
        $publicId = $doctype instanceof Doctype ? $doctype->publicId : null;
        $systemId = $doctype instanceof Doctype ? $doctype->systemId : null;
        $result = '<!DOCTYPE ' . $name;
        if ($publicId !== null) {
            $result .= ' PUBLIC ' . self::quoteDoctypeIdentifier($publicId);
            if ($systemId !== null) {
                $result .= ' ' . self::quoteDoctypeIdentifier($systemId);
            }
        } elseif ($systemId !== null) {
            $result .= ' SYSTEM ' . self::quoteDoctypeIdentifier($systemId);
        }
        return $result . '>';
    }

    private static function quoteDoctypeIdentifier(string $value): string
    {
        $hasDoubleQuote = strpos($value, '"') !== false;
        $hasSingleQuote = strpos($value, "'") !== false;
        if ($hasDoubleQuote && $hasSingleQuote) {
            throw new \InvalidArgumentException('Doctype identifiers cannot contain both quote characters');
        }
        if ($hasDoubleQuote) {
            return "'" . $value . "'";
        }
        return '"' . $value . '"';
    }

    public static function toTestFormat($node, int $indent = 0): string
    {
        if ($node->name === '#document' || $node->name === '#document-fragment') {
            $parts = [];
            if (!empty($node->children)) {
                foreach ($node->children as $child) {
                    $parts[] = self::nodeToTestFormat($child, 0);
                }
            }
            return implode("\n", $parts);
        }
        return self::nodeToTestFormat($node, $indent);
    }

    private static function nodeToTestFormat($node, int $indent): string
    {
        if ($node->name === '#comment') {
            $comment = $node->data ?? '';
            return '| ' . str_repeat(' ', $indent) . '<!-- ' . $comment . ' -->';
        }

        if ($node->name === '!doctype') {
            return self::doctypeToTestFormat($node);
        }

        if ($node->name === '#text') {
            $text = $node->data ?? '';
            return '| ' . str_repeat(' ', $indent) . '"' . $text . '"';
        }

        $line = '| ' . str_repeat(' ', $indent) . '<' . self::qualifiedName($node) . '>';
        $attributeLines = self::attrsToTestFormat($node, $indent);

        if ($node->name === 'template' && ($node->namespace === null || $node->namespace === 'html') && $node->templateContent) {
            $sections = [$line];
            if ($attributeLines) {
                $sections = array_merge($sections, $attributeLines);
            }
            $sections[] = '| ' . str_repeat(' ', $indent + 2) . 'content';
            foreach ($node->templateContent->children as $child) {
                $sections[] = self::nodeToTestFormat($child, $indent + 4);
            }
            return implode("\n", $sections);
        }

        $childLines = [];
        if (!empty($node->children)) {
            foreach ($node->children as $child) {
                $childLines[] = self::nodeToTestFormat($child, $indent + 2);
            }
        }

        $sections = [$line];
        if ($attributeLines) {
            $sections = array_merge($sections, $attributeLines);
        }
        if ($childLines) {
            $sections = array_merge($sections, $childLines);
        }
        return implode("\n", $sections);
    }

    private static function qualifiedName($node): string
    {
        if ($node->namespace !== null && $node->namespace !== 'html') {
            return $node->namespace . ' ' . $node->name;
        }
        return (string)$node->name;
    }

    /** @return array<int, string> */
    private static function attrsToTestFormat($node, int $indent): array
    {
        if (empty($node->attrs)) {
            return [];
        }

        $formatted = [];
        $padding = str_repeat(' ', $indent + 2);
        $displayAttrs = [];
        $namespace = $node->namespace;

        foreach ($node->attrs as $attrName => $attrValue) {
            $value = $attrValue ?? '';
            $attrName = (string)$attrName;
            $displayName = $attrName;
            if ($namespace !== null && $namespace !== 'html') {
                $lowerName = strtolower($attrName);
                if (isset(Constants::FOREIGN_ATTRIBUTE_ADJUSTMENTS[$lowerName])) {
                    $displayName = str_replace(':', ' ', $attrName);
                }
            }
            $displayAttrs[] = [$displayName, $value];
        }

        usort($displayAttrs, static function ($a, $b) {
            return strcmp($a[0], $b[0]);
        });

        foreach ($displayAttrs as [$displayName, $value]) {
            $formatted[] = '| ' . $padding . $displayName . '="' . $value . '"';
        }

        return $formatted;
    }

    private static function doctypeToTestFormat($node): string
    {
        $doctype = $node->data;
        $name = $doctype->name ?? '';
        $publicId = $doctype->publicId;
        $systemId = $doctype->systemId;

        $parts = ['| <!DOCTYPE'];
        if ($name !== '') {
            $parts[] = ' ' . $name;
        } else {
            $parts[] = ' ';
        }

        if ($publicId !== null || $systemId !== null) {
            $pub = $publicId ?? '';
            $sys = $systemId ?? '';
            $parts[] = ' "' . $pub . '"';
            $parts[] = ' "' . $sys . '"';
        }

        $parts[] = '>';
        return implode('', $parts);
    }
}
