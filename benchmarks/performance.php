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
        case 'dom/html-document':
            return class_exists('DOM\\HTMLDocument');
        case 'masterminds/html5':
            return class_exists('Masterminds\\HTML5');
        case 'symfony/dom-crawler':
            return class_exists('Symfony\\Component\\DomCrawler\\Crawler');
        case 'voku/simple_html_dom':
            return class_exists('voku\\helper\\HtmlDomParser');
        default:
            return false;
    }
}

function perf_parser_list(): array
{
    return [
        'justhtml',
        'domdocument',
        'dom/html-document',
        'masterminds/html5',
        'voku/simple_html_dom',
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

function perf_parse_dom_html_document(string $html): void
{
    if (!class_exists('DOM\\HTMLDocument')) {
        perf_parse_domdocument($html);
        return;
    }
    $prev = libxml_use_internal_errors(true);
    \DOM\HTMLDocument::createFromString($html);
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

/** @return array{items:int,seconds:float,avg_ms:float} */
function run_perf(string $parser, array $fixtures, int $iterations): array
{
    gc_collect_cycles();
    $items = count($fixtures) * $iterations;
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        foreach ($fixtures as $html) {
            if ($parser === 'justhtml') {
                perf_parse_justhtml($html);
            } elseif ($parser === 'domdocument') {
                perf_parse_domdocument($html);
            } elseif ($parser === 'dom/html-document') {
                perf_parse_dom_html_document($html);
            } elseif ($parser === 'masterminds/html5') {
                perf_parse_masterminds($html);
            } elseif ($parser === 'symfony/dom-crawler') {
                perf_parse_domcrawler($html);
            } elseif ($parser === 'voku/simple_html_dom') {
                perf_parse_voku($html);
            }
        }
    }

    $elapsed = microtime(true) - $start;
    $avg_ms = $items > 0 ? ($elapsed * 1000) / $items : 0.0;

    return [
        'items' => $items,
        'seconds' => $elapsed,
        'avg_ms' => $avg_ms,
    ];
}

function get_max_rss_bytes(): ?int
{
    if (!function_exists('getrusage')) {
        return null;
    }
    $usage = getrusage();
    if (!isset($usage['ru_maxrss'])) {
        return null;
    }
    $rss = (int)$usage['ru_maxrss'];
    if (PHP_OS_FAMILY === 'Darwin') {
        return $rss;
    }
    return $rss * 1024;
}

/** @return array{items:int,seconds:float,avg_ms:float,rss_bytes:?int}|array{note:string} */
function run_perf_with_rss(
    string $parser,
    array $fixtures,
    string $dir,
    int $iterations,
    bool $measure_rss
): array {
    if (!$measure_rss) {
        $data = run_perf($parser, $fixtures, $iterations);
        $data['rss_bytes'] = null;
        return $data;
    }

    $cmd_parts = [
        PHP_BINARY,
        __FILE__,
        '--child',
        '--parser',
        $parser,
        '--iterations',
        (string)$iterations,
        '--dir',
        $dir,
    ];
    $cmd = implode(' ', array_map('escapeshellarg', $cmd_parts));

    $descriptor_spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptor_spec, $pipes);
    if (!is_resource($proc)) {
        $data = run_perf($parser, $fixtures, $iterations);
        $data['rss_bytes'] = null;
        return $data;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    $data = json_decode(trim((string)$stdout), true);
    if ($status !== 0 || !is_array($data)) {
        $fallback = run_perf($parser, $fixtures, $iterations);
        $fallback['rss_bytes'] = null;
        return $fallback;
    }
    $data['rss_bytes'] = isset($data['rss_bytes']) ? (int)$data['rss_bytes'] : null;
    if ($data['rss_bytes'] <= 0) {
        $data['rss_bytes'] = null;
    }
    return $data;
}

function format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return 'n/a';
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
    echo "| Parser | Avg ms/doc | Total s | Items | Max RSS |\n";
    echo "|--------|-----------:|--------:|------:|--------:|\n";
    foreach ($results as $name => $data) {
        if (isset($data['note'])) {
            echo '| ' . $name . ' | - | - | - | ' . $data['note'] . " |\n";
            continue;
        }
        echo '| ' . $name . ' | ' . sprintf('%.2f', $data['avg_ms']) . ' | '
            . sprintf('%.2f', $data['seconds']) . ' | ' . $data['items'] . ' | '
            . format_bytes($data['rss_bytes']) . " |\n";
    }
}

$args = $argv;
array_shift($args);
$selected = [];
$iterations = 3;
$markdown = false;
$measure_rss = true;
$child = false;
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
    if ($arg === '--no-rss') {
        $measure_rss = false;
        continue;
    }
    if ($arg === '--child') {
        $child = true;
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

$parser_arg = $selected[0] ?? '';
if ($child) {
    if ($parser_arg === '') {
        fwrite(STDERR, "Child mode requires --parser\n");
        exit(1);
    }
    if (!perf_parser_available($parser_arg)) {
        fwrite(STDERR, "Parser not available: {$parser_arg}\n");
        exit(1);
    }
    $fixtures = load_fixtures($dir);
    if (!$fixtures) {
        fwrite(STDERR, "No fixtures found in {$dir}\n");
        exit(1);
    }
    $result = run_perf($parser_arg, $fixtures, $iterations);
    $result['rss_bytes'] = get_max_rss_bytes();
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
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
    $results[$parser] = run_perf_with_rss(
        $parser,
        $fixtures,
        $dir,
        $iterations,
        $measure_rss
    );
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
    $line = $name . ': ' . sprintf('%.2f', $data['avg_ms']) . ' ms/doc, '
        . sprintf('%.2f', $data['seconds']) . ' s total';
    if (array_key_exists('rss_bytes', $data) && $data['rss_bytes'] !== null) {
        $line .= ', max RSS ' . format_bytes($data['rss_bytes']);
    }
    echo $line . "\n";
}
