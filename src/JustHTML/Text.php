<?php

declare(strict_types=1);

namespace JustHTML;

/**
 * Shared DOM text-content and human-readable text helpers.
 */
final class Text
{
    /**
     * Return the DOM Node.textContent getter value for the supported node
     * representations.
     */
    public static function domTextContent($node): ?string
    {
        $name = (string)($node->name ?? '');

        if ($name === '#text' || $name === '#comment') {
            return is_string($node->data ?? null) ? $node->data : '';
        }

        if ($name === '#document' || $name === '!doctype') {
            return null;
        }

        if ($name === '#document-fragment' || ($name !== '' && $name[0] !== '#')) {
            return self::descendantTextContent($node);
        }

        return null;
    }

    /**
     * Return raw text suitable for conversion. Unlike DOM textContent, the
     * library's document root includes its descendant text.
     */
    public static function extractableRawText($node): string
    {
        if (($node->name ?? null) === '#document') {
            return self::descendantTextContent($node);
        }
        return self::domTextContent($node) ?? '';
    }

    public static function normalizeHtmlWhitespace(string $text): string
    {
        $normalized = preg_replace('/[\\x09\\x0A\\x0C\\x0D\\x20]+/', ' ', $text);
        if ($normalized === null) {
            return '';
        }
        return trim($normalized, " ");
    }

    private static function descendantTextContent($node): string
    {
        $parts = [];
        self::collectDescendantText($node, $parts);
        return implode('', $parts);
    }

    /** @param array<int, string> $parts */
    private static function collectDescendantText($node, array &$parts): void
    {
        foreach ($node->children ?? [] as $child) {
            $name = (string)($child->name ?? '');
            if ($name === '#text') {
                if (is_string($child->data ?? null)) {
                    $parts[] = $child->data;
                }
                continue;
            }
            if ($name === '#comment' || $name === '!doctype') {
                continue;
            }
            self::collectDescendantText($child, $parts);
        }
    }
}
