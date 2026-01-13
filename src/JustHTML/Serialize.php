<?php

declare(strict_types=1);

namespace JustHTML;

final class Serialize
{
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
        if (Str::contains($value, '"') && !Str::contains($value, "'")) {
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
        if ($node->name === '#document') {
            $parts = [];
            if (!empty($node->children)) {
                foreach ($node->children as $child) {
                    $parts[] = self::nodeToHtml($child, $indent, $indentSize, $pretty);
                }
            }
            return $pretty ? implode("\n", $parts) : implode('', $parts);
        }
        return self::nodeToHtml($node, $indent, $indentSize, $pretty);
    }

    private static function nodeToHtml($node, int $indent, int $indentSize, bool $pretty): string
    {
        $prefix = $pretty ? str_repeat(' ', $indent * $indentSize) : '';
        $newline = $pretty ? "\n" : '';
        $name = $node->name;

        if ($name === '#text') {
            $text = $node->data ?? '';
            if ($pretty) {
                $text = trim($text);
                if ($text === '') {
                    return '';
                }
                return $prefix . self::escapeText($text);
            }
            return self::escapeText($text);
        }

        if ($name === '#comment') {
            return $prefix . '<!--' . ($node->data ?? '') . '-->';
        }

        if ($name === '!doctype') {
            return $prefix . '<!DOCTYPE html>';
        }

        if ($name === '#document-fragment') {
            $parts = [];
            if (!empty($node->children)) {
                foreach ($node->children as $child) {
                    $childHtml = self::nodeToHtml($child, $indent, $indentSize, $pretty);
                    if ($childHtml !== '') {
                        $parts[] = $childHtml;
                    }
                }
            }
            return $pretty ? implode($newline, $parts) : implode('', $parts);
        }

        $attrs = $node->attrs ?? [];
        $openTag = self::serializeStartTag($name, $attrs);

        if (isset(Constants::VOID_ELEMENTS[$name])) {
            return $prefix . $openTag;
        }

        $children = $node->children ?? [];
        if (!$children) {
            return $prefix . $openTag . self::serializeEndTag($name);
        }

        $allText = true;
        foreach ($children as $child) {
            if ($child->name !== '#text') {
                $allText = false;
                break;
            }
        }

        if ($allText && $pretty) {
            $text = $node->toText('', false);
            return $prefix . $openTag . self::escapeText($text) . self::serializeEndTag($name);
        }

        $parts = [$prefix . $openTag];
        foreach ($children as $child) {
            $childHtml = self::nodeToHtml($child, $indent + 1, $indentSize, $pretty);
            if ($childHtml !== '') {
                $parts[] = $childHtml;
            }
        }
        $parts[] = $prefix . self::serializeEndTag($name);
        return $pretty ? implode($newline, $parts) : implode('', $parts);
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
            return $a[0] <=> $b[0];
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
