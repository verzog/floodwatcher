<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Build the flood-prone mask (lib/floodmask.php) from the downloaded flood maps.
 *
 * Usage: php bin/build_floodmask.php
 *
 * bin/fetch_overlays.php runs this itself after each weekly download; run it by hand only after
 * changing the flood map settings. Writes data/floodmask.json in the public web folder.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/store.php';
require_once dirname(__DIR__) . '/lib/floodmask.php';

ini_set('memory_limit', '1024M'); // The state floodplain layer is large.
$config = sfw_config();
$dir = $config['public_data_dir'];
$cell = (float) ($config['floodmask']['cell_deg'] ?? 0.002);

$features = [];
$used = [];
foreach ($config['overlays']['layers'] as $layer) {
    if (($layer['style'] ?? 'fill') === 'outline') {
        continue; // The catchment outline isn't a flood map.
    }
    $file = "{$dir}/overlays/{$layer['key']}.json";
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data) || empty($data['f'])) {
        continue;
    }
    array_push($features, ...$data['f']);
    $used[] = $layer['key'];
}

$started = microtime(true);
$mask = sfw_floodmask_build($features, $cell);
if ($mask === null) {
    sfw_fail('No flood maps downloaded yet; run bin/fetch_overlays.php first.');
}
$mask['layers'] = $used;
sfw_write_atomic("{$dir}/floodmask.json", json_encode($mask, JSON_UNESCAPED_SLASHES));

$raw = base64_decode($mask['bits']);
$on = 0;
for ($i = 0; $i < strlen($raw); $i++) {
    $on += substr_count(decbin(ord($raw[$i])), '1');
}
sfw_log(sprintf('Flood-prone mask: %d of %d squares can flood (%s), from %d shapes, in %.1f s.',
    $on, $mask['cols'] * $mask['rows'], implode(', ', $used), count($features), microtime(true) - $started));
