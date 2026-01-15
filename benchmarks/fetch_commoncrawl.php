<?php

declare(strict_types=1);

const DEFAULT_INDEX = 'CC-MAIN-2024-10-index';
const DEFAULT_OUT_DIR = 'benchmarks/fixtures/commoncrawl-1k';
const DEFAULT_PATTERNS = '*.com/*,*.org/*,*.net/*,*.edu/*,*.gov/*,*.io/*,*.co/*';

function usage(): void
{
    $script = basename(__FILE__);
    echo "Usage: php {$script} [--index NAME] [--count N] [--out-dir PATH] [--seed N] [--patterns CSV]\n";
}

/** @return array<string, string> */
function parse_args(array $argv): array
{
    $opts = getopt('', ['index:', 'count:', 'out-dir:', 'seed:', 'patterns:', 'help']);
    if (isset($opts['help'])) {
        usage();
        exit(0);
    }
    return $opts === false ? [] : $opts;
}

/** @param list<string> $headers */
function http_get(string $url, array $headers = [], int $timeout = 30): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $headers[] = 'User-Agent: justhtml-bench/1.0';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        return is_string($data) ? $data : '';
    }

    $headerLines = array_merge($headers, ['User-Agent: justhtml-bench/1.0']);
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => implode("\r\n", $headerLines),
        ],
    ]);
    $data = @file_get_contents($url, false, $context);
    return is_string($data) ? $data : '';
}

function build_index_url(string $index, string $pattern, ?int $page = null, bool $show_pages = false): string
{
    $base = "https://index.commoncrawl.org/{$index}";
    $query = 'url=' . rawurlencode($pattern)
        . '&output=json'
        . '&filter=status:200'
        . '&filter=mime:text/html';
    if ($page !== null) {
        $query .= '&page=' . $page;
    }
    if ($show_pages) {
        $query .= '&showNumPages=true';
    }
    return $base . '?' . $query;
}

function fetch_num_pages(string $index, string $pattern): int
{
    $url = build_index_url($index, $pattern, null, true);
    $data = http_get($url);
    $meta = json_decode($data, true);
    $pages = is_array($meta) ? (int)($meta['pages'] ?? 1) : 1;
    return max(1, $pages);
}

/** @return array<int, array<string, string>> */
function fetch_index_records(string $index, string $pattern, int $page): array
{
    $url = build_index_url($index, $pattern, $page, false);
    $data = http_get($url);
    if ($data === '') {
        return [];
    }
    $records = [];
    foreach (preg_split('/\r?\n/', trim($data)) as $line) {
        if ($line === '') {
            continue;
        }
        $item = json_decode($line, true);
        if (!is_array($item)) {
            continue;
        }
        $records[] = $item;
    }
    return $records;
}

function gzdecode_safe(string $data): ?string
{
    if ($data === '') {
        return null;
    }
    $decoded = @gzdecode($data);
    if ($decoded !== false) {
        return $decoded;
    }
    $decoded = @gzinflate($data);
    return $decoded === false ? null : $decoded;
}

function decode_chunked(string $body): string
{
    $out = '';
    $pos = 0;
    $len = strlen($body);
    while ($pos < $len) {
        $line_end = strpos($body, "\r\n", $pos);
        if ($line_end === false) {
            return $body;
        }
        $hex = trim(substr($body, $pos, $line_end - $pos));
        if ($hex === '') {
            return $out;
        }
        $chunk_len = hexdec($hex);
        if ($chunk_len === 0) {
            return $out;
        }
        $pos = $line_end + 2;
        if ($pos + $chunk_len > $len) {
            return $body;
        }
        $out .= substr($body, $pos, $chunk_len);
        $pos += $chunk_len + 2;
    }
    return $out;
}

/** @return array{headers:array<string,string>,body:string}|null */
function extract_http_response(string $warc_record): ?array
{
    $sep = strpos($warc_record, "\r\n\r\n");
    $sep_len = 4;
    if ($sep === false) {
        $sep = strpos($warc_record, "\n\n");
        $sep_len = 2;
    }
    if ($sep === false) {
        return null;
    }
    $http = substr($warc_record, $sep + $sep_len);
    $sep2 = strpos($http, "\r\n\r\n");
    $sep2_len = 4;
    if ($sep2 === false) {
        $sep2 = strpos($http, "\n\n");
        $sep2_len = 2;
    }
    if ($sep2 === false) {
        return null;
    }
    $header_block = substr($http, 0, $sep2);
    $body = substr($http, $sep2 + $sep2_len);
    $headers = [];
    foreach (preg_split('/\r?\n/', $header_block) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        $headers[$name] = $value;
    }
    return ['headers' => $headers, 'body' => $body];
}

/** @param array<string, string> $record */
function fetch_html_from_record(array $record): ?string
{
    $filename = $record['filename'] ?? null;
    $offset = isset($record['offset']) ? (int)$record['offset'] : null;
    $length = isset($record['length']) ? (int)$record['length'] : null;
    if (!$filename || $offset === null || $length === null) {
        return null;
    }
    $start = $offset;
    $end = $offset + $length - 1;
    $url = 'https://data.commoncrawl.org/' . $filename;
    $range = 'Range: bytes=' . $start . '-' . $end;
    $compressed = http_get($url, [$range], 60);
    $decoded = gzdecode_safe($compressed);
    if ($decoded === null) {
        return null;
    }
    $http = extract_http_response($decoded);
    if ($http === null) {
        return null;
    }
    $headers = $http['headers'];
    $body = $http['body'];

    if (isset($headers['transfer-encoding']) && stripos($headers['transfer-encoding'], 'chunked') !== false) {
        $body = decode_chunked($body);
    }
    if (isset($headers['content-encoding']) && stripos($headers['content-encoding'], 'gzip') !== false) {
        $decompressed = gzdecode_safe($body);
        if ($decompressed !== null) {
            $body = $decompressed;
        }
    }

    return $body;
}

$opts = parse_args($argv);
$index = $opts['index'] ?? DEFAULT_INDEX;
$count = isset($opts['count']) ? (int)$opts['count'] : 1000;
$out_dir = $opts['out-dir'] ?? DEFAULT_OUT_DIR;
$seed = isset($opts['seed']) ? (int)$opts['seed'] : 1337;
$patterns_csv = $opts['patterns'] ?? DEFAULT_PATTERNS;

$patterns = array_values(array_filter(array_map('trim', explode(',', $patterns_csv))));
if ($count <= 0 || !$patterns) {
    usage();
    exit(1);
}

mt_srand($seed);
if (!is_dir($out_dir) && !mkdir($out_dir, 0775, true) && !is_dir($out_dir)) {
    fwrite(STDERR, "Failed to create output directory: {$out_dir}\n");
    exit(1);
}

$existing_files = glob($out_dir . '/*.html');
$start_index = 0;
if ($existing_files) {
    $indices = [];
    foreach ($existing_files as $file) {
        $name = basename($file, '.html');
        if (ctype_digit($name)) {
            $indices[] = (int)$name;
        }
    }
    if ($indices) {
        $start_index = max($indices) + 1;
    }
}

$pages_by_pattern = [];
$used_pages = [];
$records = [];
$seen_urls = [];
$max_requests = 40;

while (count($records) < $count && $max_requests > 0) {
    foreach ($patterns as $pattern) {
        if (count($records) >= $count || $max_requests <= 0) {
            break;
        }
        if (!isset($pages_by_pattern[$pattern])) {
            $pages_by_pattern[$pattern] = fetch_num_pages($index, $pattern);
            $used_pages[$pattern] = [];
        }
        $pages = $pages_by_pattern[$pattern];
        $page = mt_rand(0, max(0, $pages - 1));
        if (isset($used_pages[$pattern][$page]) && count($used_pages[$pattern]) < $pages) {
            continue;
        }
        $used_pages[$pattern][$page] = true;
        $max_requests--;

        $items = fetch_index_records($index, $pattern, $page);
        foreach ($items as $item) {
            $url = $item['url'] ?? null;
            if (!$url || isset($seen_urls[$url])) {
                continue;
            }
            $seen_urls[$url] = true;
            $records[] = $item;
            if (count($records) >= $count) {
                break;
            }
        }
    }
}

if (!$records) {
    fwrite(STDERR, "No Common Crawl index records found.\n");
    exit(1);
}

$urls_path = $out_dir . '/urls.txt';
$urls_handle = fopen($urls_path, $start_index > 0 ? 'ab' : 'wb');
if ($urls_handle === false) {
    fwrite(STDERR, "Failed to open {$urls_path}\n");
    exit(1);
}

$written = $start_index;
foreach ($records as $record) {
    if ($written >= $count) {
        break;
    }
    $html = fetch_html_from_record($record);
    if ($html === null || $html === '') {
        continue;
    }
    $path = sprintf('%s/%06d.html', $out_dir, $written);
    file_put_contents($path, $html);
    $url = $record['url'] ?? '';
    fwrite($urls_handle, sprintf("%06d\t%s\n", $written, $url));
    $written++;
    if ($written % 50 === 0) {
        echo "Fetched {$written}/{$count}\n";
    }
}

fclose($urls_handle);
echo "Wrote {$written} HTML fixtures to {$out_dir}\n";
if ($written < $count) {
    fwrite(STDERR, "Warning: only {$written} fixtures were collected.\n");
    exit(2);
}
