<?php

declare(strict_types=1);

/**
 * Differential oracle for the selected TreeBuilder implementation of
 * Stream::select (docs/proposal-streaming-select.md).
 *
 * The oracle is DOM equivalence: for every (input, selector) case, approach A
 * (TreeBuilder-integrated, with and without pruning) must yield exactly the
 * elements full-DOM query() returns, in the same document order, with equal
 * subtree serializations. The rejected lexical comparator and its measurements
 * are preserved at tag stream-select-spike-2026-07-18 and summarized in
 * docs/spike-streaming-select/report.md.
 *
 * Usage:
 *   php -dxdebug.mode=off spike/differential.php                 # targeted cases
 *   php -dxdebug.mode=off spike/differential.php --corpus [--limit=N]
 */

require_once __DIR__ . '/../benchmarks/bootstrap.php';
require_once __DIR__ . '/../src/JustHTML/Experimental/SelectCompiler.php';
require_once __DIR__ . '/../src/JustHTML/Experimental/StreamSelect.php';

use JustHTML\JustHTML;
use JustHTML\Experimental\StreamSelect;

/**
 * Predeclared selector battery (fixed before any benchmark measurement; the
 * benchmark harness imports it from here).
 */
function spike_selector_battery(): array
{
    return [
        'title',
        'h1',
        'p',
        'a[href]',
        'meta[charset]',
        'img[src]',
        '#content',
        'main p',
        'ul li',
        'table td',
        'div > p',
        'body *',
        'p:first-child',
        'li:nth-child(2)',
        'article a[href]',
        'div.footer',
        'no-such-tag',
        '.no-such-class p',
    ];
}

/** Targeted tree-construction cases from the proposal's testing plan. */
function spike_targeted_cases(): array
{
    $cases = [
        'implicit html/head/body' => [
            '<p>hello</p>',
            ['p', 'body p', 'html > body > p', '*'],
        ],
        'implied end tags' => [
            '<div><p>one<p>two<ul><li>a<li>b</ul></div>',
            ['div > p', 'p', 'li', 'li:nth-child(2)', 'ul li:first-child'],
        ],
        'foster parenting' => [
            '<table><div class=f>fostered</div><tr><td>cell</td></tr></table>',
            ['.f', 'td', '.f, td', 'table td', 'div'],
        ],
        'foster parenting with match after table' => [
            '<div><table><div class=f>x</div><tr><td>c</td></tr></table><p class=after>tail</p></div>',
            ['p.after', '.f, p.after', 'td, p.after'],
        ],
        'adoption agency' => [
            '<p><b>bold<div class=x>block</div></b></p>',
            ['b', '.x', 'p b', 'div.x, b'],
        ],
        'adoption agency nested formatting' => [
            '<a href=1>one<div><a href=2>two</a></div></a>',
            ['a[href]', 'div a', 'a'],
        ],
        'late body attribute merge' => [
            '<p id="p">x</p><body class="x">',
            ['body.x p', 'p', 'body.x p, p'],
        ],
        'ordered-yield frontier example (v3 review)' => [
            '<p id="p">first</p><div id="d">second</div><body class="x">',
            ['body.x p, div#d', 'div#d', 'p, div'],
        ],
        'late html attribute merge' => [
            '<p>x</p><html lang="en">',
            ['html[lang] p', 'p'],
        ],
        'ignored duplicate body attrs' => [
            '<body id="first"><p>x</p><body id="second" class="late">',
            ['#first p', '#second p', 'body.late p'],
        ],
        'templates' => [
            '<template><p class=t>tpl</p></template><p class=t>real</p>',
            ['p.t', 'p', 'template p'],
        ],
        'nested templates' => [
            '<template><template><span class=deep>x</span></template></template><span class=deep>y</span>',
            ['span.deep', 'span'],
        ],
        'selectedcontent finalization' => [
            '<select><selectedcontent></selectedcontent><option><b class=inner>pick</b></option></select>',
            ['b.inner', 'option', 'selectedcontent b'],
        ],
        'selectedcontent with selected option' => [
            '<select><selectedcontent></selectedcontent><option>one</option><option selected><i class=s>two</i></option></select>',
            ['i.s', 'option i'],
        ],
        'EOF closure' => [
            '<div class=open><p class=also-open>never closed',
            ['div.open', 'p.also-open', 'div p'],
        ],
        'frameset replaces body' => [
            '<html><head></head><body><frameset><frame></frameset>',
            ['frame', 'body *', 'frameset'],
        ],
        'frameset after benign head content' => [
            '<link rel=x><frameset><frame src=a></frameset>',
            ['link', 'frame[src]'],
        ],
        'svg integration points' => [
            '<svg><foreignObject><p class=z>in</p></foreignObject><text>t</text></svg><p class=z>out</p>',
            ['p.z', 'svg p', 'foreignObject p'],
        ],
        'mathml integration points' => [
            '<math><annotation-xml encoding="text/html"><p class=m>x</p></annotation-xml></math>',
            ['p.m', 'annotation-xml p'],
        ],
        'foreign self-closing' => [
            '<svg><circle r=1/><rect/></svg><br><img src=i>',
            ['circle', 'img[src]', '*'],
        ],
        'void elements' => [
            '<p>a<br>b<hr><input type=text></p>',
            ['br', 'hr', 'input[type=text]', 'p'],
        ],
        'nested matches share structure' => [
            '<div class=m><span><div class=m><div class=m>deep</div></div></span></div>',
            ['div.m', 'div.m div.m'],
        ],
        'selector list dedupe' => [
            '<p class="a b">x</p><p class=a>y</p>',
            ['.a, .b, p', '.a, .b'],
        ],
        'tables with tbody' => [
            '<table><thead><tr><th>h</th></tr></thead><tr><td>a</td></tr><tr><td>b</td></tr></table>',
            ['td', 'tbody td', 'tr:first-child th', 'table *'],
        ],
        'misnested tables' => [
            '<table><tr><td><table><tr><td>inner</td></tr></table></td></tr></table>',
            ['td', 'table table td'],
        ],
        'formatting reconstruction' => [
            '<p><b>one<p>two</p><p>three</p>',
            ['b', 'p b', 'p'],
        ],
        'positional under late insertion' => [
            '<table><tr><td>c</td></tr><div class=f1>1</div><div class=f2>2</div></table>',
            ['div:nth-child(1)', 'div:nth-child(2)', 'div:first-child', 'td'],
        ],
        ':not cases' => [
            '<div><p class=a>1</p><p>2</p><p class=b>3</p></div>',
            ['p:not(.a)', 'p:not(.a):not(.b)', 'div :not(p)'],
        ],
        ':not with body-taggable inner (hazard)' => [
            '<p class=k>1</p><body class=x><p class=k>2</p>',
            ['p:not(.x)', ':not(.x) p'],
        ],
        'combined positive and negated late body hazards' => [
            '<body><div><p id=x>x</p></div><body class=foo>',
            ['.foo:not(.bar) p', 'body.foo:not(.bar) p'],
        ],
        'plaintext and rawtext' => [
            '<style>p{}</style><script>var a="<p>";</script><p class=real>x</p><xmp><p>not real</p></xmp>',
            ['p.real', 'p', 'style, script'],
        ],
        'headings and blocks' => [
            '<h1>a</h1><h2>b<h3>c</h3>',
            ['h1, h2, h3', 'h2 h3', 'h3:first-child'],
        ],
    ];
    return $cases;
}

function serialize_result($node): string
{
    return $node->toTestFormat(0);
}

/** @return array{0:array<int,string>,1:?string} signatures and error */
function run_variant(callable $producer): array
{
    $signatures = [];
    try {
        foreach ($producer() as $node) {
            $signatures[] = serialize_result($node);
        }
    } catch (\Throwable $e) {
        return [[], get_class($e) . ': ' . $e->getMessage()];
    }
    return [$signatures, null];
}

function compare_case(string $html, string $selector, array &$counters, string $label, bool $verbose): void
{
    $doc = new JustHTML($html);
    $expected = array_map('serialize_result', $doc->query($selector));

    $variants = [
        'A/no-prune' => static function () use ($html, $selector) {
            return StreamSelect::select($html, $selector);
        },
        'A/prune' => static function () use ($html, $selector) {
            return StreamSelect::select($html, $selector, ['prune' => true]);
        },
    ];

    foreach ($variants as $name => $producer) {
        [$actual, $error] = run_variant($producer);
        $counters['total']++;
        if ($error !== null || $actual !== $expected) {
            $counters['fail']++;
            echo "MISMATCH [{$name}] {$label} :: {$selector}\n";
            if ($error !== null) {
                echo "  error: {$error}\n";
            } elseif ($verbose) {
                echo "  expected (" . count($expected) . "): " . json_encode($expected) . "\n";
                echo "  actual   (" . count($actual) . "): " . json_encode($actual) . "\n";
            } else {
                echo '  expected ' . count($expected) . ' results, got ' . count($actual) . "\n";
            }
        }
    }
}

/** Contract checks that need consumer interaction rather than final serialization. */
function check_result_contracts(array &$counters, bool $verbose): void
{
    $counters['total']++;
    $ids = [];
    $firstWasDetached = false;
    foreach (StreamSelect::select('<p id=a>A</p><p id=b>B</p>', 'p') as $node) {
        $ids[] = $node->attrs['id'] ?? '';
        if (count($ids) !== 1) {
            continue;
        }
        $firstWasDetached = $node->parent === null;
        if (!$firstWasDetached) {
            // Exercise the exact failure mode the detached-tree contract must
            // prevent: a consumer reaching into and mutating parser state.
            $parent = $node->parent;
            if (($parent->parent ?? null) !== null) {
                $parent->parent->removeChild($parent);
            }
        } else {
            $node->attrs['consumer-mutation'] = 'safe';
            $node->children = [];
        }
    }
    if (!$firstWasDetached || $ids !== ['a', 'b']) {
        $counters['fail']++;
        echo "MISMATCH [A/contracts] detached result mutation isolation\n";
        if ($verbose) {
            echo '  detached=' . ($firstWasDetached ? 'yes' : 'no')
                . ', ids=' . json_encode($ids) . "\n";
        }
    }

    $counters['total']++;
    $nested = iterator_to_array(StreamSelect::select(
        '<div class=m><span><div class=m>x</div></span></div>',
        'div.m'
    ), false);
    $sharesTree = count($nested) === 2
        && $nested[0]->parent === null
        && $nested[1]->parent !== null
        && $nested[0]->queryFirst('div.m') === $nested[1];
    if (!$sharesTree) {
        $counters['fail']++;
        echo "MISMATCH [A/contracts] nested retained-tree sharing\n";
    }
}

// ---------------------------------------------------------------------
$args = array_slice($argv, 1);
$corpus = in_array('--corpus', $args, true);
$verbose = in_array('--verbose', $args, true);
$limit = 0;
foreach ($args as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, strlen('--limit='));
    }
}

$counters = ['total' => 0, 'fail' => 0];
$start = microtime(true);

if (!$corpus) {
    foreach (spike_targeted_cases() as $label => [$html, $selectors]) {
        foreach ($selectors as $selector) {
            compare_case($html, $selector, $counters, $label, $verbose);
        }
    }
    check_result_contracts($counters, $verbose);
} else {
    $dir = __DIR__ . '/../benchmarks/fixtures/commoncrawl-1k';
    $files = glob($dir . '/*.html') ?: [];
    sort($files);
    if ($limit > 0) {
        $files = array_slice($files, 0, $limit);
    }
    $battery = spike_selector_battery();
    $done = 0;
    foreach ($files as $file) {
        $html = file_get_contents($file);
        if ($html === false) {
            continue;
        }
        foreach ($battery as $selector) {
            compare_case($html, $selector, $counters, basename($file), $verbose);
        }
        $done++;
        if ($done % 25 === 0) {
            fwrite(STDERR, "  ...{$done}/" . count($files) . " docs, {$counters['fail']} failures\n");
        }
    }
}

$elapsed = microtime(true) - $start;
echo "\n=== differential oracle summary ===\n";
echo "approach A cases: {$counters['total']}, mismatches: {$counters['fail']}\n";
printf("elapsed: %.1fs\n", $elapsed);
exit($counters['fail'] > 0 ? 1 : 0);
