<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;
use JustHTML\SimpleDomNode;

// Fixture source: https://en.wikipedia.org/wiki/Earth (CC BY-SA 4.0).
$fixture = __DIR__ . '/fixtures/wikipedia-earth.html';
$html = file_get_contents($fixture);
if ($html === false) {
    fwrite(STDERR, "Failed to read fixture: {$fixture}\n");
    exit(1);
}

$doc = new JustHTML($html, ['collect_errors' => true]);

function class_list(?SimpleDomNode $node): array
{
    if ($node === null || $node->attrs === null) {
        return [];
    }
    $classAttr = $node->attrs['class'] ?? '';
    $classAttr = trim($classAttr ?? '');
    if ($classAttr === '') {
        return [];
    }
    return preg_split('/\s+/', $classAttr, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function inner_html(?SimpleDomNode $node): string
{
    if ($node === null || empty($node->children)) {
        return '';
    }
    $parts = [];
    foreach ($node->children as $child) {
        if (method_exists($child, 'toHtml')) {
            $parts[] = $child->toHtml(0, 2, false);
        }
    }
    return implode('', $parts);
}

function truncate(string $text, int $limit = 200): string
{
    if (strlen($text) <= $limit) {
        return $text;
    }
    return substr($text, 0, $limit) . '...';
}

$heading = $doc->queryFirst('#firstHeading');
$infobox = $doc->queryFirst('table.infobox');

$lead = null;
foreach ($doc->query('#mw-content-text p') as $paragraph) {
    if ($paragraph->toText() !== '') {
        $lead = $paragraph;
        break;
    }
}

$firstLink = $lead !== null ? $lead->queryFirst('a') : null;

echo "Fixture: {$fixture}\n";
echo "Bytes: " . strlen($html) . "\n";
echo "Parse errors: " . count($doc->errors) . "\n";
echo "\n";

echo "== Heading ==\n";
if ($heading !== null) {
    echo "Tag: {$heading->name}\n";
    $parentName = $heading->parent ? $heading->parent->name : '(none)';
    echo "Parent: {$parentName}\n";
    echo "Children: " . count($heading->children ?? []) . "\n";
    echo "Normalized text: " . $heading->toText() . "\n";
    echo "Classes: " . implode(', ', class_list($heading)) . "\n";
    echo "Matches h1#firstHeading: " . ($heading->matches('h1#firstHeading') ? 'true' : 'false') . "\n";
    echo "Outer HTML: " . truncate($heading->toHtml(0, 2, false), 160) . "\n";
    echo "Inner HTML: " . truncate(inner_html($heading), 160) . "\n";
} else {
    echo "Heading not found.\n";
}
echo "\n";

echo "== Lead paragraph ==\n";
if ($lead !== null) {
    echo "Normalized text: " . truncate($lead->toText(), 200) . "\n";
    echo "Markdown: " . truncate($lead->toMarkdown(), 200) . "\n";
    echo "Outer HTML: " . truncate($lead->toHtml(0, 2, false), 200) . "\n";
    echo "Inner HTML: " . truncate(inner_html($lead), 200) . "\n";
} else {
    echo "Lead paragraph not found.\n";
}
echo "\n";

echo "== First link in lead ==\n";
if ($firstLink !== null) {
    $href = $firstLink->attrs['href'] ?? '';
    echo "Normalized text: " . $firstLink->toText() . "\n";
    echo "Href: {$href}\n";
    echo "Matches a[href]: " . ($firstLink->matches('a[href]') ? 'true' : 'false') . "\n";
    echo "Attributes: " . json_encode($firstLink->attrs ?? [], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "First link not found.\n";
}
echo "\n";

echo "== Infobox ==\n";
if ($infobox !== null) {
    $rows = $infobox->query('tr');
    $caption = $infobox->queryFirst('caption');
    echo "Rows: " . count($rows) . "\n";
    echo "Classes: " . implode(', ', class_list($infobox)) . "\n";
    echo "Caption: " . ($caption !== null ? $caption->toText() : '(none)') . "\n";
    echo "Outer HTML (truncated): " . truncate($infobox->toHtml(0, 2, false), 200) . "\n";
} else {
    echo "Infobox not found.\n";
}
