<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;

$doc = new JustHTML(
    '<main><p class="intro">Hello <strong>world</strong>!</p><p>Second paragraph.</p></main>'
);

$intro = $doc->queryFirst('main > p.intro');
$paragraphs = $doc->query('main p');

if ($intro === null) {
    throw new RuntimeException('Intro paragraph was not found');
}

echo 'Intro: ' . $intro->toText() . PHP_EOL;
echo 'Paragraphs: ' . count($paragraphs) . PHP_EOL;
echo 'Matches main > p.intro: '
    . ($intro->matches('main > p.intro') ? 'true' : 'false')
    . PHP_EOL;
