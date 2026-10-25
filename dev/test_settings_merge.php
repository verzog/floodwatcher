<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Dev-only test of sfw_settings_merge() (lib/bootstrap.php): pure logic, no database or config.
 * Run by hand (php dev/test_settings_merge.php) or by CI (.github/workflows/ci.yml). Exits
 * non-zero, listing every failure, if the merge ever stops behaving as below. Never run on a
 * real server.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';

$failures = 0;

/**
 * Assert that merging $over onto $base gives exactly $want.
 *
 * @param array<mixed> $base
 * @param array<mixed> $over
 * @param array<mixed> $want
 */
function check(string $name, array $base, array $over, array $want): void
{
    global $failures;
    $got = sfw_settings_merge($base, $over);
    if ($got === $want) {
        echo "ok: {$name}\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: {$name}\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n");
}

// A keyed-record list: an override patches the entry with the same key, field by field, keeping
// the rest and the order.
check(
    'keyed list: patch one entry by key',
    [['key' => 'a', 'url' => 'A', 'on' => true], ['key' => 'b', 'url' => 'B', 'on' => true]],
    [['key' => 'b', 'url' => 'B2']],
    [['key' => 'a', 'url' => 'A', 'on' => true], ['key' => 'b', 'url' => 'B2', 'on' => true]]
);

// A new key is appended after the defaults.
check(
    'keyed list: add a new entry',
    [['key' => 'a', 'url' => 'A']],
    [['key' => 'c', 'url' => 'C']],
    [['key' => 'a', 'url' => 'A'], ['key' => 'c', 'url' => 'C']]
);

// Order of the override does not matter: entries patch by key, default order is kept.
check(
    'keyed list: override order does not matter',
    [['key' => 'a', 'v' => 1], ['key' => 'b', 'v' => 2], ['key' => 'c', 'v' => 3]],
    [['key' => 'c', 'v' => 30], ['key' => 'a', 'v' => 10]],
    [['key' => 'a', 'v' => 10], ['key' => 'b', 'v' => 2], ['key' => 'c', 'v' => 30]]
);

// An empty override clears a keyed-record list.
check(
    'keyed list: empty override clears it',
    ['layers' => [['key' => 'a'], ['key' => 'b']]],
    ['layers' => []],
    ['layers' => []]
);

// A plain (non-keyed) list is replaced wholesale, never merged by position.
check(
    'plain list: replaced wholesale',
    ['river_maps' => ['A.html', 'B.html', 'C.html']],
    ['river_maps' => ['Z.html']],
    ['river_maps' => ['Z.html']]
);

// A bounding box (list of numbers) is replaced, not merged element by element.
check(
    'bbox: replaced, not merged by index',
    ['bbox' => [1.0, 2.0, 3.0, 4.0]],
    ['bbox' => [5.0, 6.0, 7.0, 8.0]],
    ['bbox' => [5.0, 6.0, 7.0, 8.0]]
);

// Maps merge key by key (the overlays case: change batch and patch a layer, keep siblings).
check(
    'map: recurse, keyed sublist patched',
    ['batch' => 250, 'layers' => [['key' => 'a', 'url' => 'A', 'on' => true]]],
    ['batch' => 50, 'layers' => [['key' => 'a', 'url' => 'A2']]],
    ['batch' => 50, 'layers' => [['key' => 'a', 'url' => 'A2', 'on' => true]]]
);

// A scalar override wins over a scalar.
check('scalar: overridden', ['n' => 1, 'keep' => 'x'], ['n' => 2], ['n' => 2, 'keep' => 'x']);

// A per-catchment map gains a new catchment while the existing one is kept; overriding one
// catchment's keyword list replaces that list (not merged by position).
check(
    'warning_keywords: add a catchment, keep the other',
    ['wk' => ['brisbane' => ['Brisbane River', 'Bremer']]],
    ['wk' => ['mary' => ['Mary River']]],
    ['wk' => ['brisbane' => ['Brisbane River', 'Bremer'], 'mary' => ['Mary River']]]
);
check(
    'warning_keywords: override a catchment list replaces it',
    ['wk' => ['brisbane' => ['Brisbane River', 'Bremer']]],
    ['wk' => ['brisbane' => ['Brisbane River only']]],
    ['wk' => ['brisbane' => ['Brisbane River only']]]
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}
echo "\nsfw_settings_merge: all checks passed.\n";
