<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;

$html = <<<'HTML'
<p id="lead">
  Earth is a <a href="/planet">planet</a>.
</p>
<template id="card"><strong>Template text</strong></template>
HTML;

$doc = new JustHTML($html);
$lead = $doc->queryFirst('#lead');
$template = $doc->queryFirst('#card');

if ($lead === null || $template === null || $template->templateContent === null) {
    throw new RuntimeException('Expected example nodes were not parsed');
}

echo 'Normalized: ' . $lead->toText() . PHP_EOL;
echo 'DOM text: ' . json_encode($lead->textContent) . PHP_EOL;
echo 'Template element: ' . json_encode($template->textContent) . PHP_EOL;
echo 'Template content: ' . json_encode($template->templateContent->textContent) . PHP_EOL;
