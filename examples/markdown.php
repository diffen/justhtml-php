<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;

$doc = new JustHTML('<h1>Title</h1><p><em>Hello</em> world.</p>');
echo $doc->toMarkdown() . PHP_EOL;
