<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;

$doc = new JustHTML('<main><p class="intro">Hello</p><p>World</p></main>');
$nodes = $doc->query('main p.intro');
foreach ($nodes as $node) {
    echo $node->toText() . PHP_EOL;
}
