<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Download the outline of every Queensland drainage basin (catchment) from the state's map
 * service, for the catchment chooser on the page and for working out which catchment each gauge,
 * road event and community rain gauge is in (lib/catchments.php).
 *
 * Usage: php bin/fetch_catchments.php             (cron runs it weekly)
 *        php bin/fetch_catchments.php if-missing  (only if never downloaded; cron runs this hourly)
 *
 * Writes:
 *   var/catchments.json                    every outline, for the collectors (private)
 *   <public>/catchments.json               the list for the page: key, name, status, box
 *   <public>/catchments/<key>.json         each catchment's outline for the page
 *
 * The catchments in 'catchments' => 'live' are matched by basin number and keep their own keys
 * (brisbane, mary, ...), with a detailed outline. The rest are listed as "under construction"
 * with a simpler outline, keyed b<basin number> (b145 for the Logan-Albert, say).
 * If the download fails, the previous files are kept.
 * Data: © State of Queensland (Department of Resources), drainage basins, CC BY 4.0.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/arcgis.php';

$config = sfw_config();
$cfg = $config['catchments'];
$public = $config['public_data_dir'];
$private = sfw_var_dir() . '/catchments.json';
if (($argv[1] ?? '') === 'if-missing' && is_file($private) && is_file("{$public}/catchments.json")) {
    exit(0); // Already downloaded; the weekly run keeps them up to date.
}
$lock = sfw_lock('fetch_catchments');
$runid = sfw_run_start('basins'); // Run log names are at most 8 characters.

/**
 * Tidy a basin name from the map service: "BRISBANE RIVER" becomes "Brisbane River".
 */
function sfw_basin_name(string $name): string
{
    $name = trim((string) preg_replace('/\s+/', ' ', $name));
    if ($name !== '' && strtoupper($name) === $name) {
        $name = ucwords(strtolower($name), " -/(");
    }
    return $name;
}

try {
    $url = (string) $cfg['basins_url'];
    // The layer's fields: the basin number and name (basin_number and basin_name at the time of
    // writing), found by name so a renamed field is reported rather than guessed.
    $info = sfw_arc_layer_info($url);
    $fields = array_keys($info['fields']);
    $pick = static function (array $want) use ($fields): ?string {
        foreach ($want as $w) {
            foreach ($fields as $f) {
                if (strcasecmp($f, $w) === 0) {
                    return $f;
                }
            }
        }
        return null;
    };
    $numfield = $pick(['basin_number', 'basin_no', 'basin_num', 'basin']);
    $namefield = $pick(['basin_name', 'name', 'basin_nam']);
    if ($numfield === null || $namefield === null) {
        throw new RuntimeException('no basin number or name field (fields: ' . implode(', ', $fields) . ')');
    }
    // The number and name of every basin. This service refuses some requests ("Error performing
    // query operation"): asking for every field, or for the shape ids, with a filter. Asking for
    // just these two fields works, and so does finding a basin's shapes by its number (below).
    $rows = sfw_arc_attributes($url, '1=1', "{$numfield},{$namefield}");
    if (!$rows) {
        throw new RuntimeException('the map service returned no drainage basins');
    }

    // Live catchments by basin number.
    $livebybasin = [];
    foreach ($cfg['live'] as $key => $c) {
        $livebybasin[(string) $c['basin']] = (string) $key;
    }

    // One entry per basin (a basin can be several shapes, such as islands), then its shapes' ids.
    $basins = [];
    foreach ($rows as $r) {
        $num = trim((string) ($r[$numfield] ?? ''));
        if ($num !== '' && preg_match('/^[A-Za-z0-9]{1,10}$/', $num)) {
            $basins[$num]['name'] ??= sfw_basin_name((string) ($r[$namefield] ?? $num));
        }
    }
    foreach (array_keys($basins) as $num) {
        $num = (string) $num;
        try {
            $basins[$num]['ids'] = sfw_arc_ids($url, "{$numfield} = '{$num}'");
        } catch (Throwable $e) {
            throw new RuntimeException("shapes of {$basins[$num]['name']} (basin {$num}): " . $e->getMessage(), 0, $e);
        }
        usleep(200000);
    }
    $basins = array_filter($basins, static fn(array $b): bool => !empty($b['ids']));

    $out = [];
    $foundlive = [];
    foreach ($basins as $num => $b) {
        $num = (string) $num;
        $key = $livebybasin[$num] ?? 'b' . preg_replace('/[^a-z0-9]/', '', strtolower($num));
        $live = isset($livebybasin[$num]);
        $offset = (float) ($live ? $cfg['outline_offset'] : $cfg['list_offset']);
        $minm2 = (float) ($live ? 100000 : 2000000); // Leave out tiny islands from the simple outlines.
        $packed = [];
        foreach (sfw_arc_chunks($b['ids'], $live ? 1 : 5) as $chunk) {
            try {
                $got = sfw_arc_features($url, $chunk, $offset);
            } catch (Throwable $e) {
                throw new RuntimeException("outline of {$b['name']} (basin {$num}): " . $e->getMessage(), 0, $e);
            }
            foreach ($got as $f) {
                $p = sfw_arc_pack($f['geometry'] ?? [], $minm2);
                if ($p !== null) {
                    $packed[] = $p;
                }
            }
            usleep(200000); // Be gentle with the map server.
        }
        if (!$packed) {
            continue;
        }
        $w = min(array_map(static fn(array $p): int => $p['b'][0], $packed));
        $s = min(array_map(static fn(array $p): int => $p['b'][1], $packed));
        $e = max(array_map(static fn(array $p): int => $p['b'][2], $packed));
        $n = max(array_map(static fn(array $p): int => $p['b'][3], $packed));
        $out[$key] = [
            'name'   => $live ? (string) ($cfg['live'][$key]['name'] ?? $b['name']) : $b['name'],
            'basin'  => $num,
            'status' => $live ? 'live' : 'construction',
            // S, W, N, E in degrees.
            'bbox'   => [round($s / 100000, 4), round($w / 100000, 4), round($n / 100000, 4), round($e / 100000, 4)],
            'state_name' => $b['name'], // The name on the state's map, to check the basin number is right.
            'f'      => $packed,
        ];
        if ($live) {
            $foundlive[$key] = $b['name'];
        }
    }
    if (count($out) < 10) {
        throw new RuntimeException('only ' . count($out) . ' catchments came back; keeping the previous files');
    }
    uasort($out, static fn(array $a, array $b): int => [$a['status'] !== 'live', $a['name']] <=> [$b['status'] !== 'live', $b['name']]);

    // Private copy for the collectors, then the page's list and outlines.
    sfw_write_atomic($private, json_encode(['fetched' => time(), 'catchments' => $out], JSON_UNESCAPED_SLASHES));
    $dir = "{$public}/catchments";
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create {$dir}");
    }
    $list = [];
    foreach ($out as $key => $c) {
        sfw_write_atomic("{$dir}/{$key}.json", json_encode(['f' => $c['f']], JSON_UNESCAPED_SLASHES));
        $list[] = ['key' => $key, 'name' => $c['name'], 'status' => $c['status'], 'bbox' => $c['bbox']];
    }
    sfw_write_atomic("{$public}/catchments.json", json_encode([
        'generated' => time(),
        'default'   => (string) $cfg['default'],
        'source'    => '© State of Queensland (Department of Resources), drainage basins',
        'catchments' => $list,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $missing = array_diff(array_keys($cfg['live']), array_keys($foundlive));
    $message = sprintf('%d catchments saved; live: %s', count($out), implode(', ', array_map(
        static fn(string $k, string $n): string => "{$k} = basin " . $out[$k]['basin'] . " ({$n})",
        array_keys($foundlive),
        $foundlive
    )));
    if ($missing) {
        $message .= '; not found on the state map (check their basin numbers): ' . implode(', ', $missing);
    }
    sfw_run_finish($runid, $missing ? 'error' : 'ok', count($rows), count($out), null, $message);
    sfw_log('Catchments: ' . $message . '.');
} catch (Throwable $e) {
    sfw_run_finish($runid, 'error', 0, 0, null, $e->getMessage());
    sfw_fail('Catchment outlines failed: ' . $e->getMessage());
}
