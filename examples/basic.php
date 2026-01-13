<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\JustHTML;

$doc = new JustHTML('<main><p>Hello <strong>world</strong></p></main>');
echo $doc->toHtml() . PHP_EOL;
