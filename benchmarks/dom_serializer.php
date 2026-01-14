<?php

declare(strict_types=1);

use JustHTML\Constants;

final class DomTestSerializer
{
    public static function toTestFormat($node): string
    {
        if (self::isDocumentNode($node)) {
            $parts = [];
            foreach ($node->childNodes as $child) {
                $line = self::nodeToTestFormat($child, 0);
                if ($line !== '') {
                    $parts[] = $line;
                }
            }
            return implode("\n", $parts);
        }
        return self::nodeToTestFormat($node, 0);
    }

    private static function nodeToTestFormat($node, int $indent): string
    {
        switch ($node->nodeType) {
            case XML_TEXT_NODE:
                return '| ' . str_repeat(' ', $indent) . '"' . $node->nodeValue . '"';
            case XML_COMMENT_NODE:
                return '| ' . str_repeat(' ', $indent) . '<!-- ' . $node->nodeValue . ' -->';
            case XML_DOCUMENT_TYPE_NODE:
                return self::doctypeToTestFormat($node);
            case XML_ELEMENT_NODE:
                return self::elementToTestFormat($node, $indent);
            default:
                return '';
        }
    }

    private static function elementToTestFormat($element, int $indent): string
    {
        $line = '| ' . str_repeat(' ', $indent) . '<' . self::qualifiedName($element) . '>';
        $attributeLines = self::attrsToTestFormat($element, $indent);

        $childLines = [];
        foreach ($element->childNodes as $child) {
            $childLines[] = self::nodeToTestFormat($child, $indent + 2);
        }

        $sections = [$line];
        if ($attributeLines) {
            $sections = array_merge($sections, $attributeLines);
        }
        foreach ($childLines as $childLine) {
            if ($childLine !== '') {
                $sections[] = $childLine;
            }
        }
        return implode("\n", $sections);
    }

    private static function qualifiedName($element): string
    {
        $namespace = $element->namespaceURI;
        if ($namespace === 'http://www.w3.org/2000/svg') {
            return 'svg ' . $element->localName;
        }
        if ($namespace === 'http://www.w3.org/1998/Math/MathML') {
            return 'math ' . $element->localName;
        }
        if ($namespace === null || $namespace === 'http://www.w3.org/1999/xhtml') {
            return strtolower((string)$element->tagName);
        }
        return (string)$element->tagName;
    }

    /** @return array<int, string> */
    private static function attrsToTestFormat($element, int $indent): array
    {
        if (!$element->hasAttributes()) {
            return [];
        }

        $formatted = [];
        $padding = str_repeat(' ', $indent + 2);
        $displayAttrs = [];
        $namespace = $element->namespaceURI;
        $isHtml = $namespace === null || $namespace === 'http://www.w3.org/1999/xhtml';

        foreach ($element->attributes as $attr) {
            $name = $attr->nodeName;
            $value = $attr->nodeValue ?? '';
            $displayName = $isHtml ? strtolower($name) : $name;
            if (!$isHtml) {
                $lowerName = strtolower($name);
                if (isset(Constants::FOREIGN_ATTRIBUTE_ADJUSTMENTS[$lowerName])) {
                    $displayName = str_replace(':', ' ', $name);
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
        $name = $node->name ?? '';
        $publicId = property_exists($node, 'publicId') ? $node->publicId : null;
        $systemId = property_exists($node, 'systemId') ? $node->systemId : null;

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

    private static function isDocumentNode($node): bool
    {
        if ($node instanceof \DOMDocument || $node instanceof \DOMDocumentFragment) {
            return true;
        }
        if (class_exists('Dom\\Document') && $node instanceof \Dom\Document) {
            return true;
        }
        if (class_exists('Dom\\DocumentFragment') && $node instanceof \Dom\DocumentFragment) {
            return true;
        }
        return false;
    }
}
