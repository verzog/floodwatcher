<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Weekly check of community weather stations on Weather Underground (unofficial rain).
 *
 * Usage: php bin/collect_wu.php              (cron runs it every 15 minutes; it only works when due)
 *        php bin/collect_wu.php rediscover   (check again now, instead of waiting a week)
 *
 * It searches the catchment for stations, then checks each one (one request each) and keeps
 * those inside the catchment outline that are reporting rainfall. The working stations are
 * published to data/community.json for the map. Rain readings are then looked up only for the
 * stations in view on the map (www/wu.php), so the free key's daily allowance stretches to
 * every working station. A long check is spread over several runs if the allowance runs short.
 *
 * Privacy: station names are not stored or shown; positions are rounded to about 1 km.
 * Data: Weather Underground (wunderground.com), from stations run by members of the public.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/wu.php';
require dirname(__DIR__) . '/lib/catchments.php';

$config = sfw_config();
$cfg = $config['wu'];
if (empty($cfg['enabled'])) {
    exit(0); // Switched off (the default until an API key is set).
}
if (trim((string) $cfg['api_key']) === '') {
    sfw_fail('Weather Underground is switched on but no API key is set in config.php.');
}
$lock = sfw_lock('collect_wu');
$state = sfw_state_read('wu_check');
$db = sfw_db();
$pause = (int) $cfg['pause_ms'] * 1000;
$base = rtrim((string) $cfg['base_url'], '/');
$key = rawurlencode((string) $cfg['api_key']);

/**
 * Publish the working stations for the map (data/community.json), each with its catchment.
 *
 * @param array<string, mixed> $config
 */
function wu_publish(PDO $db, string $outdir, array $config): int
{
    $rows = $db->query('SELECT station_id, latitude, longitude FROM wu_stations WHERE active = 1 ORDER BY station_id')->fetchAll();
    $list = array_map(static fn(array $r): array => [
        'id'        => $r['station_id'],
        'lat'       => (float) $r['latitude'],
        'lon'       => (float) $r['longitude'],
        'catchment' => sfw_catchment_at($config, (float) $r['latitude'], (float) $r['longitude']) ?? 'brisbane',
    ], $rows);
    sfw_write_atomic("{$outdir}/community.json", json_encode(['checked' => time(), 'stations' => $list], JSON_UNESCAPED_SLASHES));
    return count($list);
}

$force = in_array('rediscover', $argv, true);
$due = time() - (int) ($state['finished_at'] ?? 0) > 86400 * (int) $cfg['discover_days'];
$pending = $state['pending'] ?? [];
if (!$force && !$due && !$pending) {
    exit(0); // Nothing to do this run.
}

$runid = sfw_run_start('wu');
$messages = [];
$calls = 0;
try {
    // The live catchments' outlines (bin/fetch_catchments.php).
    $rings = [];
    foreach (sfw_catchments_live($config) as $ckey) {
        array_push($rings, ...sfw_catchment_rings($ckey));
    }
    if (!$rings) {
        throw new RuntimeException('The catchment outlines are missing. Run bin/fetch_catchments.php first, then this again.');
    }
    $inside = static fn(float $lat, float $lon): bool => sfw_catchment_at($config, $lat, $lon) !== null;

    // ---------- 1. Search the catchment (start of a new check) ----------
    if ($force || ($due && !$pending)) {
        [$w, $s, $e, $n] = sfw_catchments_extent($config, $cfg['bbox']);
        $step = (float) $cfg['grid_step'];
        $found = [];
        for ($lat = $s + $step / 2; $lat < $n; $lat += $step) {
            for ($lon = $w + $step / 2; $lon < $e; $lon += $step) {
                if (!$inside($lat, $lon)) {
                    continue;
                }
                if (!wu_budget_take($cfg)) {
                    throw new RuntimeException('Daily request allowance used up during the search; will try again tomorrow.');
                }
                $calls++;
                $near = wu_get(sprintf('%s/v3/location/near?geocode=%.3f,%.3f&product=pws&format=json&apiKey=%s',
                    $base, $lat, $lon, $key));
                $loc = $near['location'] ?? [];
                foreach (($loc['stationId'] ?? []) as $i => $sid) {
                    $slat = (float) ($loc['latitude'][$i] ?? 0);
                    $slon = (float) ($loc['longitude'][$i] ?? 0);
                    if (is_string($sid) && preg_match('/^[A-Z0-9]{3,20}$/', $sid)
                        && (int) ($loc['qcStatus'][$i] ?? 0) !== -1 && $inside($slat, $slon)) {
                        $found[$sid] = [round($slat, 2), round($slon, 2)]; // About 1 km.
                    }
                }
                usleep($pause);
            }
        }
        $pending = $found;
        $state['found'] = count($found);
        $state['active_now'] = [];
        $state['started_at'] = time();
        $messages[] = count($found) . ' stations found in the catchments';
    }

    // ---------- 2. Check each station is reporting rainfall ----------
    $active = $state['active_now'] ?? [];
    $upsert = $db->prepare(
        'INSERT INTO wu_stations (station_id, latitude, longitude, active, checked_utc, obs_epoch, today_mm, rate_mm, fetched_utc)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), active = VALUES(active),
             checked_utc = VALUES(checked_utc), obs_epoch = COALESCE(VALUES(obs_epoch), obs_epoch),
             today_mm = COALESCE(VALUES(today_mm), today_mm), rate_mm = VALUES(rate_mm),
             fetched_utc = COALESCE(VALUES(fetched_utc), fetched_utc)'
    );
    foreach ($pending as $sid => [$lat, $lon]) {
        $o = wu_current($cfg, (string) $sid);
        if ($o === false) {
            $messages[] = 'allowance used up; the check carries on in a later run';
            break;
        }
        $calls++;
        $now = sfw_now_utc();
        $upsert->execute([$sid, $lat, $lon, $o ? 1 : 0, $now, $o['epoch'] ?? null, $o['today'] ?? null,
            $o['rate'] ?? null, $o ? $now : null]);
        if ($o) {
            $active[$sid] = true;
        }
        unset($pending[$sid]);
        // Publish as we go, so working stations reach the map within a minute or two.
        if (count($active) % 20 === 1 && $o) {
            wu_publish($db, $config['public_data_dir'], $config);
        }
        usleep($pause);
    }
    $state['pending'] = $pending;
    $state['active_now'] = $active;

    // ---------- 3. Finished: publish the working stations for the map ----------
    if (!$pending) {
        $ids = array_keys($active);
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE wu_stations SET active = 0 WHERE station_id NOT IN ({$place})")->execute($ids);
        }
        $published = wu_publish($db, $config['public_data_dir'], $config);
        $state['finished_at'] = time();
        $state['active_now'] = [];
        $messages[] = sprintf('%d of %d stations are reporting rainfall and are on the map', $published, (int) ($state['found'] ?? 0));
    } else {
        $messages[] = count($pending) . ' stations still to check';
    }
    sfw_state_write('wu_check', $state);
    $message = ucfirst(implode('; ', $messages)) . '.';
    sfw_run_finish($runid, 'ok', $calls, 0, null, $message);
    sfw_log('Weather Underground: ' . $message);
} catch (Throwable $e) {
    $state['pending'] = $pending;
    sfw_state_write('wu_check', $state);
    sfw_run_finish($runid, 'error', $calls, 0, null, $e->getMessage());
    sfw_fail('Weather Underground failed: ' . $e->getMessage());
}
