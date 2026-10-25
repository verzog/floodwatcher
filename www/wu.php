<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Community rain for the part of the map on screen.
 *
 * GET wu.php?s=-27.6&w=152.2&n=-27.4&e=152.5
 *   -> {"stations": [{"id", "lat", "lon", "today", "rate", "at", "note"}], "in_view": 40, "shown": 25}
 *
 * Picks up to 'max_per_view' working stations spread across the box, reuses any reading under
 * 'cache_minutes' old, and fetches the rest from Weather Underground within the shared daily
 * allowance (lib/wu.php). When the allowance is used up, the last known readings are returned.
 * "today" is the station's own rain total since midnight; "note" flags a station that disagrees
 * badly with the official gauges around it.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * Send a JSON reply and stop.
 *
 * @param array<string, mixed> $data
 */
function wu_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Where the code is: beside the public folder in the YunoHost app (www/ inside the code folder),
// or a "seqflood" folder next to it (the older My Webapp set-up).
$code = getenv('SFW_CODE_DIR')
    ?: (is_file(dirname(__DIR__) . '/lib/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__) . '/seqflood');
if (!is_file($code . '/lib/bootstrap.php')) {
    wu_reply(500, ['error' => 'Server set-up problem.']);
}
require $code . '/lib/bootstrap.php';
require $code . '/lib/http.php';
require $code . '/lib/store.php';
require $code . '/lib/wu.php';

$config = sfw_config();
$cfg = $config['wu'];
if (empty($cfg['enabled']) || trim((string) $cfg['api_key']) === '') {
    wu_reply(503, ['error' => 'Community rain is switched off on this site.']);
}

// The map box, limited to Queensland.
$box = [];
foreach (['s' => [-30.0, -9.0], 'w' => [137.0, 155.0], 'n' => [-30.0, -9.0], 'e' => [137.0, 155.0]] as $k => [$lo, $hi]) {
    $v = filter_input(INPUT_GET, $k, FILTER_VALIDATE_FLOAT);
    if ($v === false || $v === null) {
        wu_reply(400, ['error' => 'Missing map box.']);
    }
    $box[$k] = max($lo, min($hi, $v));
}
if ($box['s'] >= $box['n'] || $box['w'] >= $box['e']) {
    wu_reply(400, ['error' => 'Bad map box.']);
}

try {
    $db = sfw_db();
    $q = $db->prepare('SELECT * FROM wu_stations WHERE active = 1 AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?');
    $q->execute([$box['s'], $box['n'], $box['w'], $box['e']]);
    $rows = $q->fetchAll();
    $inview = count($rows);

    // Spread the choice: start nearest the middle, then keep taking the station furthest from
    // those already taken.
    $max = (int) $cfg['max_per_view'];
    if ($inview > $max) {
        $cl = ($box['s'] + $box['n']) / 2;
        $cn = ($box['w'] + $box['e']) / 2;
        usort($rows, static fn(array $a, array $b): int =>
            (($a['latitude'] - $cl) ** 2 + ($a['longitude'] - $cn) ** 2) <=> (($b['latitude'] - $cl) ** 2 + ($b['longitude'] - $cn) ** 2));
        $chosen = [array_shift($rows)];
        $dist = array_map(static fn(array $r): float => INF, $rows);
        while (count($chosen) < $max && $rows) {
            $last = end($chosen);
            foreach ($rows as $i => $r) {
                $dist[$i] = min($dist[$i], sfw_distance_m((float) $r['latitude'], (float) $r['longitude'],
                    (float) $last['latitude'], (float) $last['longitude']));
            }
            $far = array_keys($dist, max($dist))[0];
            $chosen[] = $rows[$far];
            unset($rows[$far], $dist[$far]);
        }
        $rows = $chosen;
    }

    // Fetch readings older than the cache time (one request each, within the allowance). The
    // lock stops two visitors fetching the same stations at once.
    $lock = fopen(sfw_var_dir() . '/wu_fetch.lock', 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }
    $fresh = time() - 60 * (int) $cfg['cache_minutes'];
    $upd = $db->prepare('UPDATE wu_stations SET obs_epoch = ?, today_mm = ?, rate_mm = ?, fetched_utc = ? WHERE station_id = ?');
    $quiet = $db->prepare('UPDATE wu_stations SET fetched_utc = ? WHERE station_id = ?');
    $out = [];
    foreach ($rows as $r) {
        $id = (string) $r['station_id'];
        // Re-read the row: another visitor may have just fetched it.
        $cur = $db->prepare('SELECT obs_epoch, today_mm, rate_mm, fetched_utc FROM wu_stations WHERE station_id = ?');
        $cur->execute([$id]);
        $c = $cur->fetch() ?: [];
        $fetched = !empty($c['fetched_utc']) ? strtotime($c['fetched_utc'] . ' UTC') : 0;
        if ($fetched < $fresh) {
            $o = wu_current($cfg, $id);
            if (is_array($o)) {
                $upd->execute([$o['epoch'], $o['today'], $o['rate'], sfw_now_utc(), $id]);
                $c = ['obs_epoch' => $o['epoch'], 'today_mm' => $o['today'], 'rate_mm' => $o['rate']];
            } elseif ($o === null) {
                $quiet->execute([sfw_now_utc(), $id]); // Not reporting now; don't ask again until the cache time.
            }
        }
        // Only show readings from today (Brisbane time) and under 2 hours old.
        $epoch = (int) ($c['obs_epoch'] ?? 0);
        $today = gmdate('Y-m-d', $epoch + 36000) === gmdate('Y-m-d', time() + 36000) && $epoch > time() - 7200;
        $out[] = [
            'id'    => $id,
            'lat'   => (float) $r['latitude'],
            'lon'   => (float) $r['longitude'],
            'today' => $today && $c['today_mm'] !== null ? (float) $c['today_mm'] : null,
            'rate'  => $today && $c['rate_mm'] !== null ? (float) $c['rate_mm'] : null,
            'at'    => $today ? $epoch : null,
            'note'  => null,
        ];
    }
    if ($lock !== false) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    // Check each against official rain gauges nearby, since midnight (Brisbane time).
    $midnight = intdiv(time() + 36000, 86400) * 86400 - 36000;
    $official = $db->prepare(
        "SELECT s.latitude, s.longitude, SUM(r.value) AS mm FROM sensors s
           JOIN readings r ON r.sensor_id = s.sensor_id AND r.measured_utc > ?
          WHERE s.active = 1 AND s.sensor_type = 'rain' AND s.source IN ('bcc', 'bom', 'qld')
            AND s.latitude BETWEEN ? AND ? AND s.longitude BETWEEN ? AND ?
          GROUP BY s.sensor_id, s.latitude, s.longitude"
    );
    $pad = 0.1;
    $official->execute([gmdate('Y-m-d H:i:s', $midnight), $box['s'] - $pad, $box['n'] + $pad, $box['w'] - $pad, $box['e'] + $pad]);
    $gauges = $official->fetchAll();
    $hours = (int) round((time() - $midnight) / 3600);
    foreach ($out as &$st) {
        if ($st['today'] === null || $hours < 3) {
            continue;
        }
        $near = [];
        foreach ($gauges as $g) {
            if (sfw_distance_m($st['lat'], $st['lon'], (float) $g['latitude'], (float) $g['longitude']) <= 1000 * (float) $cfg['check_km']) {
                $near[] = (float) $g['mm'];
            }
        }
        $verdict = sfw_wu_disagreement((float) $st['today'], $near, (float) $cfg['check_min_mm'], (float) $cfg['check_low_share']);
        if ($verdict === null) {
            continue;
        }
        $st['note'] = $verdict['level'] === 'low'
            ? sprintf('Reads much less than official gauges nearby today (%.0f mm vs about %.0f mm).', $st['today'], $verdict['median'])
            : sprintf('Reads much more than official gauges nearby today (%.0f mm vs about %.0f mm).', $st['today'], $verdict['median']);
    }
    unset($st);

    wu_reply(200, ['stations' => $out, 'in_view' => $inview, 'shown' => count($out)]);
} catch (Throwable $e) {
    sfw_log('wu.php: ' . $e->getMessage());
    wu_reply(500, ['error' => 'Community rain isn\'t available just now.']);
}
