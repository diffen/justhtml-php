<?php

declare(strict_types=1);

/**
 * TreeBuilder streaming-select benchmark worker. One (fixture, selector, task,
 * variant) case per process, per the operational protocol in
 * docs/proposal-streaming-select.md: 3 warm-up iterations, 10 measured,
 * median reported; node counts and offsets come from a separate instrumented
 * pass so timing runs carry no instrumentation overhead.
 *
 * Variants:
 *   1 dom        full parse + cheapest workload-equivalent query operation
 *   2 noprune    incremental TreeBuilder, early stop, no pruning
 *   3 prune      incremental TreeBuilder, selective retention + safe pruning
 *
 * The rejected lexical ceiling is preserved at tag
 * stream-select-spike-2026-07-18 and documented in the spike report.
 *
 * Tasks:
 *   first          first structural match (selectFirst vs parse+queryFirst)
 *   all            all matches, full iteration (select vs parse+query)
 *   first_nonempty first result with non-empty text (predicate scenario:
 *                  each implementation iterates until the predicate holds)
 *
 * Input (argv): --file= --selector= --task= --variant= [--warmup=3] [--iters=10]
 * Output: one JSON object on stdout.
 */

require_once __DIR__ . '/../benchmarks/bootstrap.php';
use JustHTML\JustHTML;
use JustHTML\StreamSelect;
use JustHTML\StreamSelectStats;

$opts = [
    'file' => null,
    'selector' => null,
    'task' => 'first',
    'variant' => null,
    'warmup' => 3,
    'iters' => 10,
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = $m[2];
    }
}
$html = file_get_contents((string)$opts['file']);
if ($html === false) {
    fwrite(STDERR, "cannot read {$opts['file']}\n");
    exit(1);
}
$selector = (string)$opts['selector'];
$task = (string)$opts['task'];
$variant = (string)$opts['variant'];
$warmup = max(0, (int)$opts['warmup']);
$iters = max(1, (int)$opts['iters']);
if (!in_array($variant, ['1', '2', '3'], true)) {
    fwrite(STDERR, "unknown variant: {$variant}\n");
    exit(1);
}

/**
 * Runs one iteration; returns [resultCount, retainedResults].
 * Retained results stay live until the caller drops them (the same
 * ownership policy across variants, per the memory-metric protocol).
 */
function run_case(string $variant, string $html, string $selector, string $task, ?StreamSelectStats $stats): array
{
    $retained = [];

    if ($variant === '1') {
        $doc = new JustHTML($html);
        if ($task === 'first') {
            $node = $doc->queryFirst($selector);
            if ($node !== null) {
                $retained[] = $node;
            }
        } elseif ($task === 'all') {
            $retained = $doc->query($selector);
        } else { // first_nonempty: query() + iteration is the cheapest exact equivalent
            foreach ($doc->query($selector) as $node) {
                if (trim($node->toText()) !== '') {
                    $retained[] = $node;
                    break;
                }
            }
        }
        if ($stats) {
            $stats->peakLiveNodes = count_tree_nodes($doc->root);
            $stats->nodesCreated = $stats->peakLiveNodes;
            $stats->bytesTotal = strlen($html);
            $stats->firstYieldOffset = strlen($html);
        }
        return [count($retained), $retained];
    }

    $engineOpts = $variant === '3' ? ['prune' => true] : [];
    if ($stats) {
        $engineOpts['stats'] = $stats;
    }
    $gen = StreamSelect::select($html, $selector, $engineOpts);

    foreach ($gen as $node) {
        if ($task === 'first') {
            $retained[] = $node;
            break;
        }
        if ($task === 'first_nonempty') {
            if (trim($node->toText()) !== '') {
                $retained[] = $node;
                break;
            }
            continue;
        }
        $retained[] = $node;
    }
    return [count($retained), $retained];
}

function count_tree_nodes($node): int
{
    $count = 1;
    foreach ($node->children ?? [] as $child) {
        $count += count_tree_nodes($child);
    }
    if ($node instanceof \JustHTML\ElementNode && $node->templateContent !== null) {
        $count += count_tree_nodes($node->templateContent);
    }
    return $count;
}

// --- timing runs (no instrumentation) --------------------------------
for ($i = 0; $i < $warmup; $i++) {
    run_case($variant, $html, $selector, $task, null);
}

$times = [];
$memBaseline = memory_get_usage(false);
$peakIncremental = 0;
for ($i = 0; $i < $iters; $i++) {
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $t0 = hrtime(true);
    [$count, $retained] = run_case($variant, $html, $selector, $task, null);
    $t1 = hrtime(true);
    $times[] = ($t1 - $t0) / 1e6;
    $peak = memory_get_peak_usage(false) - $memBaseline;
    if ($peak > $peakIncremental) {
        $peakIncremental = $peak;
    }
    unset($retained);
}
sort($times);
$median = $times[intdiv(count($times), 2)];
if (count($times) % 2 === 0) {
    $median = ($times[count($times) / 2 - 1] + $times[count($times) / 2]) / 2;
}

// --- instrumented pass (node counts, offsets) -------------------------
$stats = new StreamSelectStats();
[$resultCount, $retained] = run_case($variant, $html, $selector, $task, $stats);

echo json_encode([
    'variant' => $variant,
    'selector' => $selector,
    'task' => $task,
    'file' => basename((string)$opts['file']),
    'median_ms' => round($median, 4),
    'min_ms' => round($times[0], 4),
    'max_ms' => round($times[count($times) - 1], 4),
    'iters' => $iters,
    'results' => $resultCount,
    'peak_live_nodes' => $stats->peakLiveNodes,
    'nodes_created' => $stats->nodesCreated,
    'nodes_pruned' => $stats->nodesPruned,
    'peak_incremental_bytes' => $peakIncremental,
    'bytes_total' => strlen($html),
    'first_yield_offset' => $stats->firstYieldOffset,
    'first_src_offset' => $stats->results[0]['src'] ?? null,
    'first_early' => $stats->results[0]['early'] ?? null,
]) . "\n";
