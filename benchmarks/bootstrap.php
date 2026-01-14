<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
    return;
}

$required = [
    'Str.php',
    'Errors.php',
    'Constants.php',
    'Entities.php',
    'Tokens.php',
    'FragmentContext.php',
    'Markdown.php',
    'Selector.php',
    'Stream.php',
    'Node.php',
    'Serialize.php',
    'TreeBuilderUtils.php',
    'TreeBuilderModes.php',
    'TokenizerStates.php',
    'Tokenizer.php',
    'TreeBuilder.php',
    'Encoding.php',
    'JustHTML.php',
];

foreach ($required as $file) {
    require_once $root . '/src/JustHTML/' . $file;
}
