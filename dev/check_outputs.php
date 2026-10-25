<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Dev-only smoke check: assert bin/build_json.php produced sensible dashboard JSON.
 *
 * Used by CI (.github/workflows/ci.yml) after the mock pipeline, and runnable by hand after the
 * dev run-through (dev/README.md). Reads the data folder from the loaded settings, so run it with
 * the same SFW_CONFIG the pipeline used. Exits non-zero, listing every problem, on any failure.
 * Never run on a real server.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';

$dir = rtrim((string) sfw_config()['public_data_dir'], '/');
$problems = [];

/**
 * Load a JSON file and run a check over it, collecting any problem message.
 *
 * @param callable(array<string, mixed>): ?string $check Returns a problem, or null if fine.
 * @param string[] $problems
 */
function sfw_check_json(string $path, callable $check, array &$problems): void
{
    if (!is_file($path)) {
        $problems[] = 'missing file: ' . $path;
        return;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        $problems[] = 'not valid JSON: ' . $path;
        return;
    }
    $msg = $check($data);
    if ($msg !== null) {
        $problems[] = basename($path) . ': ' . $msg;
    }
}

sfw_check_json("{$dir}/sensors.json", static function (array $d): ?string {
    if (empty($d['sensors']) || !is_array($d['sensors'])) {
        return 'no sensors';
    }
    if (count($d['sensors']) < 10) {
        return 'only ' . count($d['sensors']) . ' markers (expected the mock set to be larger)';
    }
    $keys = array_column($d['catchments'] ?? [], 'key');
    foreach (['brisbane', 'mary'] as $c) {
        if (!in_array($c, $keys, true)) {
            return "catchment '{$c}' missing from the chooser";
        }
    }
    return null;
}, $problems);

sfw_check_json("{$dir}/alerts.json", static function (array $d): ?string {
    if (!isset($d['alerts']) || !is_array($d['alerts'])) {
        return 'no alerts array';
    }
    if (!isset($d['warnings']) || !is_array($d['warnings'])) {
        return 'no warnings array';
    }
    return null;
}, $problems);

sfw_check_json("{$dir}/cameras.json", static function (array $d): ?string {
    return isset($d['cameras']) && is_array($d['cameras']) ? null : 'no cameras array';
}, $problems);

$series = glob("{$dir}/series/*.json") ?: [];
if ($series === []) {
    $problems[] = 'no series files were written';
} elseif (json_decode((string) file_get_contents($series[0]), true) === null) {
    $problems[] = 'series file is not valid JSON: ' . $series[0];
}

if ($problems !== []) {
    fwrite(STDERR, "Dashboard JSON check failed:\n - " . implode("\n - ", $problems) . "\n");
    exit(1);
}

$markers = count(json_decode((string) file_get_contents("{$dir}/sensors.json"), true)['sensors']);
echo "Dashboard JSON OK: {$markers} markers, " . count($series) . " series files.\n";
