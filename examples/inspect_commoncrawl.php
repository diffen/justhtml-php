<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;

function usage(): void
{
    $script = basename(__FILE__);
    fwrite(STDERR, "Usage: php {$script} [path-to-html]\n");
}

$path = $argv[1] ?? null;
if ($path === null) {
    $defaultDir = __DIR__ . '/../benchmarks/fixtures/commoncrawl-1k';
    $matches = glob($defaultDir . '/*.html') ?: [];
    if (!$matches) {
        fwrite(STDERR, "No HTML fixtures found in {$defaultDir}\n");
        usage();
        exit(1);
    }
    $path = $matches[0];
}

if (!is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    usage();
    exit(1);
}

$html = file_get_contents($path);
if ($html === false) {
    fwrite(STDERR, "Failed to read: {$path}\n");
    exit(1);
}

$doc = new JustHTML($html);

// Set a breakpoint here to inspect $doc and the nodes below in the debugger.
$titleNodes = $doc->query('title');
$headingNodes = $doc->query('h1, h2, h3');
$linkNodes = $doc->query('a[href]');
$metaDescription = $doc->query('meta[name="description"]');

$title = $titleNodes ? $titleNodes[0]->toText() : '';
$heading = $headingNodes ? $headingNodes[0]->toText() : '';
$description = '';
if ($metaDescription) {
    $description = $metaDescription[0]->attrs['content'] ?? '';
}

echo "File: {$path}\n";
echo "Bytes: " . strlen($html) . "\n";
echo "Title: {$title}\n";
echo "First heading: {$heading}\n";
echo "Description: {$description}\n";
echo "Links: " . count($linkNodes) . "\n";
