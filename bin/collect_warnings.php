<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Collect current Bureau of Meteorology flood warnings for the live catchments.
 *
 * Usage: php bin/collect_warnings.php   (cron runs it with the BoM collector, every 15 minutes)
 *
 * Reads BoM's Queensland warning products (IDQ2xxxx) from the anonymous FTP site, keeps those
 * whose title or areas name one of the rivers or creeks in a live catchment ('bom' =>
 * 'warning_keywords'), tags each with its catchments, drops cancelled and expired ones, and saves var/bom_warnings.json for build_json.php, which adds
 * them to the page's alerts. Only changed warnings are downloaded again.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/bom.php';
require dirname(__DIR__) . '/lib/warnings.php';

$config = sfw_config();
$cfg = $config['bom'];
$base = rtrim($cfg['base_url'], '/') . '/';
$lock = sfw_lock('collect_warnings');
$runid = sfw_run_start('warnings');
$state = sfw_state_read('bom_warnings');
$cache = $state['cache'] ?? [];   // id => ['listed' => time, 'warning' => parsed]

try {
    $listing = sfw_fetch_raw($base);
    preg_match_all('/\b(IDQ2\d{4})\.amoc\.xml\b/', $listing, $m);
    $ids = array_values(array_unique($m[1]));
    $now = time();
    $current = [];
    $fetched = 0;
    foreach ($ids as $id) {
        $listed = sfw_bom_listing_time($listing, "{$id}.amoc.xml");
        if (!isset($cache[$id]) || $cache[$id]['listed'] !== $listed) {
            $w = sfw_warning_parse_amoc(sfw_fetch_raw("{$base}{$id}.amoc.xml"));
            if ($w === null) {
                continue;
            }
            try {
                $txt = sfw_warning_parse_txt(sfw_fetch_raw("{$base}{$id}.txt"));
            } catch (Throwable $e) {
                $txt = ['title' => '', 'headlines' => []]; // The summary alone is enough.
            }
            $w['title'] = $txt['title'] !== '' ? $txt['title'] : ($w['summary'][0] ?? $id);
            $w['headlines'] = $txt['headlines'];
            $w['url'] = 'https://www.bom.gov.au/cgi-bin/wrap_fwo.pl?' . $id . '.html';
            $cache[$id] = ['listed' => $listed, 'warning' => $w];
            $fetched++;
        }
        $current[$id] = true;
    }
    // Forget warnings BoM has removed.
    $cache = array_intersect_key($cache, $current);

    $ours = [];
    foreach ($cache as $id => $c) {
        $w = $c['warning'];
        if ($w['phase'] === 'CAN' || ($w['expires'] !== null && $w['expires'] < $now)) {
            continue;
        }
        $w['catchments'] = array_values(array_intersect(
            sfw_warning_catchments($w, $cfg['warning_keywords']),
            array_map('strval', array_keys($config['catchments']['live']))
        ));
        if ($w['catchments']) {
            $w['final'] = stripos($w['title'], 'final') === 0;
            $ours[] = $w;
        }
    }
    $rank = ['warning' => 0, 'minor' => 1, 'moderate' => 2, 'major' => 3];
    usort($ours, static fn(array $a, array $b): int => [$rank[$b['level']], $b['issued']] <=> [$rank[$a['level']], $a['issued']]);

    sfw_state_write('bom_warnings', ['cache' => $cache, 'fetched' => $now, 'warnings' => $ours]);
    $message = sprintf('%d current BoM flood warnings for our catchments (of %d in Queensland); %d downloaded.',
        count($ours), count($ids), $fetched);
    sfw_run_finish($runid, 'ok', count($ids), count($ours), null, $message);
    sfw_log('Warnings: ' . $message . ($ours ? ' ' . implode('; ', array_column($ours, 'title')) : ''));
} catch (Throwable $e) {
    sfw_run_finish($runid, 'error', 0, 0, null, $e->getMessage());
    sfw_fail('BoM warnings failed: ' . $e->getMessage());
}
