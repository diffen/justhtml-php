<?php

declare(strict_types=1);

/**
 * Milestone 1 spike — 4-variant benchmark orchestrator
 * (docs/proposal-streaming-select.md, "Benchmark plan" + go/no-go gate).
 *
 * Each (fixture, selector, task, variant) case runs in a fresh subprocess
 * (Xdebug off, opcache on) via bench_worker.php: 3 warm-ups, 10 measured
 * iterations, median reported. Instrumented node counts / offsets come from
 * a separate in-worker pass so timing excludes instrumentation.
 *
 * Usage:
 *   php spike/bench.php --scenario=lead              # Wikipedia lead-paragraph scenarios
 *   php spike/bench.php --scenario=cc [--cc-limit=100]
 *
 * Results go to spike/results/<scenario>-<timestamp>.jsonl plus a summary on
 * stdout. Commit, PHP version, fixture hashes and exact commands are recorded.
 * A run is written as *.partial and becomes *.jsonl only after all cases finish.
 */

function run_worker(array $params, int $warmup, int $iters): ?array
{
    $cmd = [
        PHP_BINARY,
        '-dxdebug.mode=off',
        '-dopcache.enable_cli=1',
        __DIR__ . '/bench_worker.php',
        '--file=' . $params['file'],
        '--selector=' . $params['selector'],
        '--task=' . $params['task'],
        '--variant=' . $params['variant'],
        '--warmup=' . $warmup,
        '--iters=' . $iters,
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        fwrite(STDERR, "worker failed (exit {$code}): {$params['selector']} v{$params['variant']}\n{$err}\n");
        return null;
    }
    $data = json_decode((string)$out, true);
    if (!is_array($data)) {
        fwrite(STDERR, "worker produced no JSON: {$params['selector']} v{$params['variant']}\n{$err}\n");
        return null;
    }
    $data['command'] = implode(' ', array_map('escapeshellarg', $cmd));
    return $data;
}

function geomean(array $values): ?float
{
    $values = array_filter($values, static fn($v) => $v > 0);
    if (!$values) {
        return null;
    }
    $sum = 0.0;
    foreach ($values as $v) {
        $sum += log($v);
    }
    return exp($sum / count($values));
}

// ---------------------------------------------------------------------
$args = array_slice($argv, 1);
$scenario = 'lead';
$ccLimit = 0;
$iters = null;
$warmup = null;
foreach ($args as $arg) {
    if (strpos($arg, '--scenario=') === 0) {
        $scenario = substr($arg, 11);
    } elseif (strpos($arg, '--cc-limit=') === 0) {
        $ccLimit = (int)substr($arg, 11);
    } elseif (strpos($arg, '--iters=') === 0) {
        $iters = (int)substr($arg, 8);
    } elseif (strpos($arg, '--warmup=') === 0) {
        $warmup = (int)substr($arg, 9);
    }
}

$commit = trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse --short HEAD'));
$meta = [
    'commit' => $commit,
    'php' => PHP_VERSION,
    'scenario' => $scenario,
    'date' => date('c'),
];

@mkdir(__DIR__ . '/results', 0777, true);
$finalOutFile = __DIR__ . '/results/' . $scenario . '-' . date('Ymd-His') . '.jsonl';
$outFile = $finalOutFile . '.partial';
$outHandle = fopen($outFile, 'w');
fwrite($outHandle, json_encode(['meta' => $meta]) . "\n");
$writtenRows = 0;
$expectedRows = 0;

$variants = ['1', '2', '3', '4'];
$variantNames = ['1' => 'dom-baseline', '2' => 'A/no-prune', '3' => 'A/prune', '4' => 'B/lexical'];

if ($scenario === 'lead') {
    $file = realpath(__DIR__ . '/../examples/fixtures/wikipedia-earth.html');
    $fixtureSha256 = hash_file('sha256', $file);
    $cases = [
        // The headline use case: first non-empty lead paragraph.
        ['selector' => '#mw-content-text p', 'task' => 'first_nonempty', 'note' => 'lead paragraph (gate scenario)'],
        // Tag-qualified variant: quantifies what the html/body attr-merge hazard costs.
        ['selector' => 'div#mw-content-text p', 'task' => 'first_nonempty', 'note' => 'lead paragraph, tag-qualified'],
        ['selector' => 'title', 'task' => 'first', 'note' => 'single early match'],
        ['selector' => 'p', 'task' => 'first', 'note' => 'first structural match'],
        ['selector' => '#mw-content-text p', 'task' => 'all', 'note' => 'many matches'],
        ['selector' => 'div.reflist', 'task' => 'first', 'note' => 'late match'],
        ['selector' => 'table td', 'task' => 'first', 'note' => 'match inside table'],
        ['selector' => 'body *', 'task' => 'all', 'note' => 'broad selector (diagnostic)'],
        ['selector' => '#no-such-id p', 'task' => 'all', 'note' => 'absent selector (diagnostic)'],
    ];
    $w = $warmup ?? 3;
    $n = $iters ?? 10;
    $expectedRows = count($cases) * count($variants);

    $rows = [];
    foreach ($cases as $case) {
        foreach ($variants as $variant) {
            $params = ['file' => $file, 'selector' => $case['selector'], 'task' => $case['task'], 'variant' => $variant];
            $result = run_worker($params, $w, $n);
            if ($result === null) {
                continue;
            }
            $result['note'] = $case['note'];
            $result['fixture_sha256'] = $fixtureSha256;
            fwrite($outHandle, json_encode($result) . "\n");
            $writtenRows++;
            $rows[$case['note'] . ' :: ' . $case['selector'] . ' [' . $case['task'] . ']'][$variant] = $result;
            fwrite(STDERR, '.');
        }
    }
    fwrite(STDERR, "\n\n");

    echo "# Lead-paragraph scenario (wikipedia-earth.html, " . round(filesize($file) / 1024) . " KB)\n";
    echo "commit {$meta['commit']}, PHP {$meta['php']}, fixture sha256 " . substr((string)$fixtureSha256, 0, 12) . "…\n";
    echo "protocol: fresh subprocess per case, {$w} warm-up + {$n} measured, median; node counts from separate instrumented pass\n\n";

    foreach ($rows as $label => $variantsData) {
        echo "## {$label}\n";
        printf("%-14s %10s %10s %10s %12s %12s %14s %12s\n",
            'variant', 'median ms', 'min ms', 'max ms', 'results', 'peak nodes', 'mem KB', 'yield@');
        foreach ($variants as $v) {
            if (!isset($variantsData[$v])) {
                continue;
            }
            $r = $variantsData[$v];
            printf("%-14s %10.2f %10.2f %10.2f %12d %12d %14d %12s\n",
                $variantNames[$v], $r['median_ms'], $r['min_ms'], $r['max_ms'], $r['results'],
                $r['peak_live_nodes'], (int)round($r['peak_incremental_bytes'] / 1024),
                $r['first_yield_offset'] === null ? '-'
                    : sprintf('%.0f%%', 100.0 * $r['first_yield_offset'] / max(1, $r['bytes_total'])));
        }
        if (isset($variantsData['1'], $variantsData['3'])) {
            $t = $variantsData['1']['median_ms'] / max(1e-9, $variantsData['3']['median_ms']);
            $nodes = $variantsData['3']['peak_live_nodes'] > 0
                ? $variantsData['1']['peak_live_nodes'] / $variantsData['3']['peak_live_nodes'] : INF;
            printf("gate check (v1/v3): latency ratio %.2fx (need >=2), peak-node ratio %.2fx (need >=2)\n", $t, $nodes);
        }
        echo "\n";
    }
} elseif ($scenario === 'cc') {
    $dir = realpath(__DIR__ . '/../benchmarks/fixtures/commoncrawl-1k');
    $files = glob($dir . '/*.html') ?: [];
    sort($files);
    if ($ccLimit > 0) {
        $files = array_slice($files, 0, $ccLimit);
    }
    // Predeclared CC battery (subset of the oracle battery with a realistic
    // early/late/absent mix; declared before measurement).
    $selectors = ['title', 'p', 'a[href]', '#content', 'main p', 'table td', 'no-such-tag'];
    $task = 'first';
    $w = $warmup ?? 3;
    $n = $iters ?? 10;
    $expectedRows = count($files) * count($selectors) * count($variants);

    $perCase = [];
    $done = 0;
    foreach ($files as $file) {
        $fixtureSha256 = hash_file('sha256', $file);
        foreach ($selectors as $selector) {
            $caseResults = [];
            foreach ($variants as $variant) {
                $params = ['file' => $file, 'selector' => $selector, 'task' => $task, 'variant' => $variant];
                $result = run_worker($params, $w, $n);
                if ($result === null) {
                    continue 2;
                }
                $result['fixture_sha256'] = $fixtureSha256;
                fwrite($outHandle, json_encode($result) . "\n");
                $writtenRows++;
                $caseResults[$variant] = $result;
            }
            $perCase[] = $caseResults;
        }
        $done++;
        if ($done % 10 === 0) {
            fwrite(STDERR, "  {$done}/" . count($files) . " docs\n");
        }
    }

    // Gate aggregation: one ratio per document–selector case; grouping by
    // the first full-DOM match's source offset (quartiles of input length).
    $groups = ['early' => [], 'mid' => [], 'late' => [], 'no-source-offset' => [], 'no-match' => []];
    foreach ($perCase as $case) {
        if (!isset($case['1'], $case['2'], $case['3'], $case['4'])) {
            continue;
        }
        $src = $case['2']['first_src_offset'];
        $bytes = max(1, $case['2']['bytes_total']);
        if ($case['1']['results'] === 0) {
            $group = 'no-match';
        } elseif ($src === null) {
            $group = 'no-source-offset';
        } else {
            $frac = $src / $bytes;
            $group = $frac <= 0.25 ? 'early' : ($frac >= 0.75 ? 'late' : 'mid');
        }
        $groups[$group][] = $case;
    }

    echo "# Common Crawl first-match aggregate ({$done} docs × " . count($selectors) . " selectors, task={$task})\n";
    echo "commit {$meta['commit']}, PHP {$meta['php']}, {$w} warm-up + {$n} measured per case\n\n";
    foreach ($groups as $name => $cases) {
        if (!$cases) {
            continue;
        }
        $latRatios = [];
        $nodeRatios = [];
        $latRatiosV4 = [];
        foreach ($cases as $case) {
            $latRatios[] = $case['1']['median_ms'] / max(1e-9, $case['3']['median_ms']);
            if ($case['3']['peak_live_nodes'] > 0) {
                $nodeRatios[] = $case['1']['peak_live_nodes'] / $case['3']['peak_live_nodes'];
            }
            $latRatiosV4[] = $case['1']['median_ms'] / max(1e-9, $case['4']['median_ms']);
        }
        printf("%-18s cases=%4d  v1/v3 latency geomean=%6.2fx  v1/v3 peak-node geomean=%6.2fx  v1/v4 latency geomean=%6.2fx\n",
            $name, count($cases), geomean($latRatios) ?? 0, geomean($nodeRatios) ?? 0, geomean($latRatiosV4) ?? 0);
    }
    echo "\ngate (early group): v1/v3 latency geomean >= 2 and v1/v3 peak-node geomean >= 2\n";
} else {
    fwrite(STDERR, "unknown scenario: {$scenario}\n");
    exit(1);
}

fwrite($outHandle, json_encode([
    'summary' => [
        'complete' => $writtenRows === $expectedRows,
        'rows' => $writtenRows,
        'expected_rows' => $expectedRows,
        'scenario' => $scenario,
    ],
]) . "\n");
fclose($outHandle);
if ($writtenRows !== $expectedRows) {
    fwrite(STDERR, "benchmark incomplete: wrote {$writtenRows}/{$expectedRows} rows; partial output: {$outFile}\n");
    exit(1);
}
if (!rename($outFile, $finalOutFile)) {
    fwrite(STDERR, "could not finalize result file: {$outFile}\n");
    exit(1);
}
echo "raw results: {$finalOutFile}\n";
