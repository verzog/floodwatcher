<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Download the flood map shading (council flood areas and past flood extents) and save it with
 * the web site, so visitors' browsers never depend on the council or state map servers.
 *
 * Usage: php bin/fetch_overlays.php            (all layers; cron runs it weekly)
 *        php bin/fetch_overlays.php flood2011  (one layer, by its key in lib/defaults.php)
 *        php bin/fetch_overlays.php if-missing (only layers never downloaded, such as after install
 *                                               or when a catchment's layers are added; cron runs it)
 *
 * Writes into the public web folder:
 *   data/overlays/<key>.json   one packed file per layer (format described in lib/arcgis.php)
 *   data/overlays/index.json   the list of layers the page should offer
 *
 * If a layer fails to download, its previous file is kept and the others carry on. Each layer is
 * listed with its catchment, and the page offers it only while that catchment is shown.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/arcgis.php';
require dirname(__DIR__) . '/lib/catchments.php';

$config = sfw_config();
$opts = $config['overlays'];
$outdir = $config['public_data_dir'] . '/overlays';
$only = $argv[1] ?? null;
$missingonly = $only === 'if-missing';
if ($missingonly) {
    $only = null;
    $missing = array_filter($opts['layers'], static fn(array $l): bool => !is_file("{$outdir}/{$l['key']}.json"));
    if (is_file("{$outdir}/index.json") && !$missing) {
        exit(0); // All downloaded; the weekly run keeps them up to date.
    }
}
$lock = sfw_lock('fetch_overlays');

if (!is_dir($outdir) && !mkdir($outdir, 0755, true) && !is_dir($outdir)) {
    sfw_fail("Cannot create {$outdir}");
}

$state = sfw_state_read('overlays');
$failed = 0;

foreach ($opts['layers'] as $layer) {
    $key = (string) $layer['key'];
    if (!preg_match('/^[a-z0-9_]+$/', $key)) {
        sfw_log("Skipping overlay with unusable key '{$key}'.");
        continue;
    }
    if (($only !== null && $only !== $key) || ($missingonly && is_file("{$outdir}/{$key}.json"))) {
        continue;
    }
    try {
        // A layer for one catchment's box ('box_of'), and perhaps only the shapes centred inside its
        // outline ('clip'): needs the outlines from bin/fetch_catchments.php.
        $box = null;
        $rings = null;
        if (!empty($layer['box_of'])) {
            $c = sfw_catchments_load()[(string) $layer['box_of']] ?? null;
            if ($c === null || !is_array($c['bbox'])) {
                throw new RuntimeException('the ' . $layer['box_of'] . ' catchment outline isn\'t downloaded yet '
                    . '(bin/fetch_catchments.php); will try again');
            }
            $box = $c['bbox'];
            $rings = !empty($layer['clip']) ? sfw_catchment_rings((string) $layer['box_of']) : null;
        }
        $ids = sfw_arc_ids($layer['url'], (string) ($layer['where'] ?? '1=1'), $box);
        $batch = max(1, (int) ($layer['batch'] ?? $opts['batch']));
        if (!$ids) {
            throw new RuntimeException('the service returned no shapes');
        }
        $features = [];
        $dropped = 0;
        foreach (sfw_arc_chunks($ids, $batch) as $i => $chunk) {
            if ($i > 0) {
                usleep(200000); // Be gentle with the map server.
            }
            foreach (sfw_arc_features($layer['url'], $chunk, (float) $layer['offset']) as $f) {
                $packed = sfw_arc_pack($f['geometry'] ?? [], (float) $layer['min_m2']);
                if ($packed !== null && $rings !== null) {
                    // Keep only shapes centred inside the catchment.
                    $mid = [($packed['b'][1] + $packed['b'][3]) / 200000, ($packed['b'][0] + $packed['b'][2]) / 200000];
                    if (!sfw_rings_contain($rings, $mid[0], $mid[1])) {
                        continue;
                    }
                }
                if ($packed === null) {
                    $dropped++;
                } else {
                    $features[] = $packed;
                }
            }
        }
        if (!$features) {
            throw new RuntimeException('no usable shapes after simplifying');
        }
        $json = json_encode(['f' => $features], JSON_UNESCAPED_SLASHES);
        sfw_write_atomic("{$outdir}/{$key}.json", $json);
        $state[$key] = ['fetched' => time(), 'count' => count($features), 'bytes' => strlen($json)];
        sfw_log(sprintf(
            'Flood map %s: %d shapes saved (%d tiny ones left out), %.1f MB.',
            $key,
            count($features),
            $dropped,
            strlen($json) / 1048576
        ));
    } catch (Throwable $e) {
        $failed++;
        sfw_log("Flood map {$key} failed: " . $e->getMessage() . ' (keeping the previous file, if any).');
    }
}

sfw_state_write('overlays', $state);

// The list the page reads: every configured layer that has a file.
$index = [
    'generated'       => time(),
    'likelihood_url'  => $opts['likelihood_url'],
    'likelihood_link' => $opts['likelihood_link'],
    'layers'          => [],
];
foreach ($opts['layers'] as $layer) {
    $key = (string) $layer['key'];
    if (!preg_match('/^[a-z0-9_]+$/', $key) || !is_file("{$outdir}/{$key}.json")) {
        continue;
    }
    $index['layers'][] = [
        'key'       => $key,
        'catchment' => (string) ($layer['catchment'] ?? 'brisbane'),
        'label'   => $layer['label'],
        'hover'   => $layer['hover'],
        'source'  => $layer['source'],
        'on'      => (bool) $layer['on'],
        'style'   => ($layer['style'] ?? 'fill') === 'outline' ? 'outline' : 'fill',
        'fetched' => $state[$key]['fetched'] ?? filemtime("{$outdir}/{$key}.json"),
        'bytes'   => $state[$key]['bytes'] ?? filesize("{$outdir}/{$key}.json"),
    ];
}
sfw_write_atomic("{$outdir}/index.json", json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
sfw_log(sprintf('Flood maps ready: %d layers listed%s.', count($index['layers']), $failed ? ", {$failed} failed" : ''));

// Rebuild the flood-prone mask that keeps warnings to land that can flood (lib/floodmask.php).
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build_floodmask.php'), $maskstatus);
exit($failed || $maskstatus ? 1 : 0);
