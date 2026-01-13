<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use JustHTML\Stream;

$html = '<div><span>Hello</span></div>';

foreach (Stream::stream($html) as [$event, $data]) {
    echo $event . ' ';
    if (is_array($data)) {
        echo json_encode($data);
    } else {
        echo (string)$data;
    }
    echo PHP_EOL;
}
