<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;

$html = '<main><p class="greeting">Hello <strong>world</strong>!</p></main>';
$doc = new JustHTML($html);
$paragraph = $doc->queryFirst('main > p.greeting');

if ($paragraph === null) {
    throw new RuntimeException('Greeting paragraph was not found');
}

echo $paragraph->toText() . PHP_EOL;
echo $paragraph->toHtml(0, 2, false) . PHP_EOL;
