<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use JustHTML\JustHTML;

function perf_parser_available(string $name): bool
{
    switch ($name) {
        case 'justhtml':
        case 'domdocument':
            return true;
        case 'masterminds/html5':
            return class_exists('Masterminds\\HTML5');
        case 'symfony/dom-crawler':
            return class_exists('Symfony\\Component\\DomCrawler\\Crawler');
        case 'voku/simple_html_dom':
            return class_exists('voku\\helper\\HtmlDomParser');
        case 'paquettg/php-html-parser':
            return class_exists('PHPHtmlParser\\Dom');
        default:
            return false;
    }
}

function perf_parser_list(): array
{
    return [
        'justhtml',
        'domdocument',
        'masterminds/html5',
        'voku/simple_html_dom',
        'paquettg/php-html-parser',
        'symfony/dom-crawler',
    ];
}

function load_fixtures(string $dir): array
{
    $files = glob($dir . '/*.html');
    sort($files);
    $fixtures = [];
    foreach ($files as $file) {
        $data = file_get_contents($file);
        if ($data !== false) {
            $fixtures[] = $data;
        }
    }
    return $fixtures;
}

function perf_parse_justhtml(string $html): void
{
    new JustHTML($html);
}

function perf_parse_domdocument(string $html): void
{
    $doc = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
}

function perf_parse_masterminds(string $html): void
{
    $html5 = new \Masterminds\HTML5();
    $html5->loadHTML($html);
}

function perf_parse_domcrawler(string $html): void
{
    if (!class_exists('Symfony\\Component\\DomCrawler\\Crawler')) {
        perf_parse_domdocument($html);
        return;
    }
    $crawler = new \Symfony\Component\DomCrawler\Crawler();
    $crawler->addHtmlContent($html, 'UTF-8');
}

function perf_parse_voku(string $html): void
{
    \voku\helper\HtmlDomParser::str_get_html($html);
}

function perf_parse_paquet(string $html): void
{
    $dom = new \PHPHtmlParser\Dom();
    if (method_exists($dom, 'loadStr')) {
        $dom->loadStr($html);
        return;
    }
    if (method_exists($dom, 'load')) {
        $dom->load($html);
    }
}

/** @return array{items:int,seconds:float,avg_ms:float,peak_bytes:?int} */
function run_perf(string $parser, array $fixtures, int $iterations, bool $measure_mem): array
{
    gc_collect_cycles();
    $items = count($fixtures) * $iterations;
    $peak_before = memory_get_peak_usage(true);
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        foreach ($fixtures as $html) {
            if ($parser === 'justhtml') {
                perf_parse_justhtml($html);
            } elseif ($parser === 'domdocument') {
                perf_parse_domdocument($html);
            } elseif ($parser === 'masterminds/html5') {
                perf_parse_masterminds($html);
            } elseif ($parser === 'symfony/dom-crawler') {
                perf_parse_domcrawler($html);
            } elseif ($parser === 'voku/simple_html_dom') {
                perf_parse_voku($html);
            } elseif ($parser === 'paquettg/php-html-parser') {
                perf_parse_paquet($html);
            }
        }
    }

    $elapsed = microtime(true) - $start;
    $peak_after = memory_get_peak_usage(true);
    $peak_delta = $measure_mem ? max(0, $peak_after - $peak_before) : null;
    $avg_ms = $items > 0 ? ($elapsed * 1000) / $items : 0.0;

    return [
        'items' => $items,
        'seconds' => $elapsed,
        'avg_ms' => $avg_ms,
        'peak_bytes' => $peak_delta,
    ];
}

function format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '-';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024) {
            return sprintf('%.1f %s', $value, $unit);
        }
        $value /= 1024;
    }
    return sprintf('%.1f TB', $value);
}

function render_perf_markdown(array $results): void
{
    echo "| Parser | Avg ms/doc | Total s | Items | Peak delta |\n";
    echo "|--------|-----------:|--------:|------:|-----------:|\n";
    foreach ($results as $name => $data) {
        if (isset($data['note'])) {
            echo '| ' . $name . ' | - | - | - | ' . $data['note'] . " |\n";
            continue;
        }
        echo '| ' . $name . ' | ' . sprintf('%.2f', $data['avg_ms']) . ' | '
            . sprintf('%.2f', $data['seconds']) . ' | ' . $data['items'] . ' | '
            . format_bytes($data['peak_bytes']) . " |\n";
    }
}

$args = $argv;
array_shift($args);
$selected = [];
$iterations = 3;
$markdown = false;
$measure_mem = true;
$dir = __DIR__ . '/fixtures';

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--parser') {
        $selected[] = $args[$i + 1] ?? '';
        $i += 1;
        continue;
    }
    if (strpos($arg, '--parser=') === 0) {
        $selected[] = substr($arg, strlen('--parser='));
        continue;
    }
    if ($arg === '--iterations') {
        $iterations = isset($args[$i + 1]) ? (int)$args[$i + 1] : $iterations;
        $i += 1;
        continue;
    }
    if (strpos($arg, '--iterations=') === 0) {
        $iterations = (int)substr($arg, strlen('--iterations='));
        continue;
    }
    if ($arg === '--markdown') {
        $markdown = true;
        continue;
    }
    if ($arg === '--no-mem') {
        $measure_mem = false;
        continue;
    }
    if (strpos($arg, '--dir=') === 0) {
        $dir = substr($arg, strlen('--dir='));
        continue;
    }
    if ($arg === '--dir') {
        $dir = $args[$i + 1] ?? $dir;
        $i += 1;
        continue;
    }
}

$fixtures = load_fixtures($dir);
if (!$fixtures) {
    fwrite(STDERR, "No fixtures found in {$dir}\n");
    exit(1);
}

$parsers = $selected ?: perf_parser_list();
$results = [];

foreach ($parsers as $parser) {
    if ($parser === '') {
        continue;
    }
    if (!perf_parser_available($parser)) {
        $results[$parser] = ['note' => 'not installed'];
        continue;
    }
    $results[$parser] = run_perf($parser, $fixtures, $iterations, $measure_mem);
}

if ($markdown) {
    render_perf_markdown($results);
    exit(0);
}

foreach ($results as $name => $data) {
    if (isset($data['note'])) {
        echo $name . ': ' . $data['note'] . "\n";
        continue;
    }
    echo $name . ': ' . sprintf('%.2f', $data['avg_ms']) . ' ms/doc, '
        . sprintf('%.2f', $data['seconds']) . " s total\n";
}
