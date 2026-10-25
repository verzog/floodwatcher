<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Collect Queensland Government (WMIP) stream heights, flows and rainfall for the stations listed
 * in data/qld_sites.csv (site id, name, catchment; a missing catchment means the Brisbane River).
 *
 * Usage: php bin/collect_qld.php      (cron: every 30 minutes)
 *
 * Height, flow and rain are fetched separately, so a problem with one never blocks the others.
 * The WMIP service rejects a whole request if any station in it lacks the measurement asked for,
 * so the first time (and once a day after) each measurement is checked station by station, and
 * the stations that have it are remembered in var/qld.json. Normal runs then make one request
 * per measurement covering only those stations.
 *
 * WMIP times are Brisbane time; they are stored in UTC like everything else.
 * Data: © State of Queensland, CC BY 4.0.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/wmip.php';
require dirname(__DIR__) . '/lib/store.php';

$lock = sfw_lock('collect_qld');
$cfg = sfw_config()['qld'];
$offset = (int) $cfg['tz_offset_hours'] * 3600;
$state = sfw_state_read('qld');
$recheckseconds = 86400; // Re-check which stations have each measurement once a day.

// Station list, and each station's catchment.
$sites = [];
$catchmentof = [];
$handle = fopen($cfg['sites_file'], 'r');
if ($handle === false) {
    sfw_fail('Cannot open ' . $cfg['sites_file']);
}
fgetcsv($handle, null, ',', '"', '');
while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
    if (isset($row[0]) && trim($row[0]) !== '' && !str_starts_with(trim($row[0]), '#')) {
        $site = strtoupper(trim($row[0]));
        $sites[] = $site;
        $catchmentof[$site] = trim((string) ($row[2] ?? '')) ?: 'brisbane';
    }
}
fclose($handle);
if ($sites === []) {
    sfw_fail('No stations in ' . $cfg['sites_file']);
}

// What to fetch. Each measurement is a separate request.
$kinds = [
    'H' => ['type' => 'stream', 'unit' => 'm', 'datum' => 'LGH',
        'params' => ['datasource' => 'AT', 'varfrom' => '100.00', 'varto' => '100.00', 'data_type' => 'point']],
    'F' => ['type' => 'flow', 'unit' => 'm3/s', 'datum' => null,
        'params' => null], // Chosen from $flowcandidates below, by asking the service which it accepts.
    'R' => ['type' => 'rain', 'unit' => 'mm', 'datum' => null,
        'params' => ['datasource' => 'AT', 'varfrom' => '10.00', 'varto' => '10.00', 'data_type' => 'tot']],
];

// Flow (cubic metres per second) can be requested several ways and the documentation doesn't
// say which the service accepts. Each is tried once a day on a station known to measure flow,
// and the first that returns readings is used.
$flowcandidates = [
    ['datasource' => 'AT', 'varfrom' => '100.00', 'varto' => '140.00', 'data_type' => 'mean'],
    ['datasource' => 'ATQ', 'varfrom' => '100.00', 'varto' => '140.00', 'data_type' => 'mean'],
    ['datasource' => 'A', 'varfrom' => '100.00', 'varto' => '140.00', 'data_type' => 'mean'],
    ['datasource' => 'AT', 'varfrom' => '140.00', 'varto' => '140.00', 'data_type' => 'mean'],
    ['datasource' => 'ATQ', 'varfrom' => '140.00', 'varto' => '140.00', 'data_type' => 'mean'],
];
$flowprobe = (string) ($cfg['flow_probe_site'] ?? '143001C'); // Brisbane River at Savages Crossing.

$db = sfw_db();
$runid = sfw_run_start('qld');
$fetched = 0;
$inserted = 0;
$newest = null;
$notes = [];
$failed = [];

foreach ($kinds as $suffix => $kind) {
    try {
        // Start from the newest stored reading of this kind (minus an overlap), or backfill.
        $stmt = $db->prepare(
            "SELECT MAX(r.measured_utc) FROM readings r
               JOIN sensors s ON s.sensor_id = r.sensor_id
              WHERE s.source = 'qld' AND s.sensor_type = ?"
        );
        $stmt->execute([$kind['type']]);
        $last = $stmt->fetchColumn();
        $startutc = $last
            ? strtotime($last . ' UTC') - (int) $cfg['overlap_hours'] * 3600
            : time() - (int) $cfg['backfill_days'] * 86400;
        $endutc = time() + 3600;

        // Flow: find (or re-check daily) which request settings the service accepts.
        $params = $kind['params'];
        if ($suffix === 'F') {
            $checkedf = (int) ($state['checked']['F'] ?? 0);
            if (!array_key_exists('flow_params', $state) || time() - $checkedf >= $recheckseconds) {
                $state['flow_params'] = null;
                foreach ($flowcandidates as $candidate) {
                    $probe = sfw_wmip_traces($cfg['url'], $candidate, $flowprobe, time() - 12 * 3600, $endutc, $offset);
                    $ptrace = $probe['return']['traces'][0]['trace'] ?? [];
                    if ((int) ($probe['error_num'] ?? 0) === 0 && $ptrace !== []) {
                        $state['flow_params'] = $candidate;
                        break;
                    }
                    usleep(200000);
                }
                if ($state['flow_params'] === null) {
                    // Nothing accepted: remember that, so the next day's re-check tries again.
                    $state['capable']['F'] = [];
                    $state['checked']['F'] = time();
                    sfw_state_write('qld', $state);
                    $notes[] = 'flow: the service accepted none of the request settings tried';
                    continue;
                }
                unset($state['capable']['F']); // Re-check stations with the new settings.
                sfw_state_write('qld', $state);
            }
            if ($state['flow_params'] === null) {
                continue; // Known not to work until the next daily re-check.
            }
            $params = $state['flow_params'];
        }

        // Which stations to ask: the remembered list, unless it's missing or due for a re-check.
        $known = $state['capable'][$suffix] ?? null;
        $checkedat = (int) ($state['checked'][$suffix] ?? 0);
        $traces = [];
        $rechecked = false;
        if (is_array($known) && time() - $checkedat < $recheckseconds) {
            if ($known !== []) {
                $data = sfw_wmip_traces($cfg['url'], $params, implode(',', $known), $startutc, $endutc, $offset);
                if ((int) ($data['error_num'] ?? 0) === 0) {
                    $traces = $data['return']['traces'] ?? [];
                } else {
                    $known = null; // The remembered list no longer works; check station by station.
                }
            }
        } else {
            $known = null;
        }

        if ($known === null) {
            // First try all stations at once; if WMIP rejects that, ask one station at a time.
            $data = sfw_wmip_traces($cfg['url'], $params, implode(',', $sites), $startutc, $endutc, $offset);
            if ((int) ($data['error_num'] ?? 0) === 0) {
                $traces = $data['return']['traces'] ?? [];
                $capable = [];
                foreach ($traces as $t) {
                    if ((int) ($t['error_num'] ?? 0) === 0 && !empty($t['trace']) && isset($t['site'])) {
                        $capable[] = strtoupper((string) $t['site']);
                    }
                }
            } else {
                $capable = [];
                $skipped = [];
                foreach ($sites as $site) {
                    try {
                        $one = sfw_wmip_traces($cfg['url'], $params, $site, $startutc, $endutc, $offset);
                    } catch (Throwable $e) {
                        // One station can time out (WMIP returns HTTP 408, "client took too long");
                        // skip it and carry on rather than failing the whole recheck (lesson 5). If
                        // many fail, the service itself is down, so let the run error.
                        $skipped[] = $site;
                        if (count($skipped) >= (int) ($cfg['max_station_timeouts'] ?? 15)) {
                            throw new RuntimeException(sprintf('%d stations failed (last %s: %s)',
                                count($skipped), $site, $e->getMessage()));
                        }
                        usleep(200000);
                        continue;
                    }
                    if ((int) ($one['error_num'] ?? 0) !== 0) {
                        continue;
                    }
                    foreach ($one['return']['traces'] ?? [] as $t) {
                        $traces[] = $t;
                        if ((int) ($t['error_num'] ?? 0) === 0 && !empty($t['trace'])) {
                            $capable[] = $site;
                        }
                    }
                    usleep(200000); // Be gentle with the state's server: 0.2 s between requests.
                }
                if ($skipped !== []) {
                    sfw_log(sprintf('collect_qld: %s recheck skipped %d station(s) that timed out: %s',
                        $kind['type'], count($skipped), implode(', ', $skipped)));
                }
            }
            $state['capable'][$suffix] = array_values(array_unique($capable));
            $state['checked'][$suffix] = time();
            sfw_state_write('qld', $state);
            $rechecked = true;
        }

        $db->beginTransaction();
        foreach ($traces as $trace) {
            $site = strtoupper((string) ($trace['site'] ?? ''));
            if ($site === '' || (int) ($trace['error_num'] ?? 0) !== 0 || empty($trace['trace'])) {
                continue;
            }
            $details = $trace['site_details'] ?? [];
            $sensorid = 'Q' . $site . '-' . $suffix;
            sfw_upsert_sensor([
                'sensor_id'     => $sensorid,
                'source'        => 'qld',
                'location_id'   => $site,
                'location_name' => (string) ($details['name'] ?? $site),
                'sensor_type'   => $kind['type'],
                'unit'          => $kind['unit'],
                'datum'         => $kind['datum'],
                'latitude'      => (float) ($details['latitude'] ?? 0),
                'longitude'     => (float) ($details['longitude'] ?? 0),
                'tidal'         => $kind['type'] === 'stream' ? 'no' : null,
                'catchment'     => $catchmentof[$site] ?? 'brisbane',
            ]);
            $fetched += count($trace['trace']);
            foreach (sfw_wmip_points($trace, $offset) as $ts => $value) {
                $utc = gmdate('Y-m-d H:i:s', $ts);
                $inserted += sfw_insert_reading($sensorid, $utc, $value);
                if ($newest === null || $utc > $newest) {
                    $newest = $utc;
                }
            }
        }
        $db->commit();

        if ($rechecked) {
            $notes[] = sprintf('%s available at %d of %d stations', $kind['type'],
                count($state['capable'][$suffix]), count($sites));
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $failed[] = $kind['type'] . ': ' . $e->getMessage();
    }
}

// The run counts as an error only if heights failed; flow or rain problems are noted.
$heightsfailed = (bool) array_filter($failed, static fn(string $f): bool => str_starts_with($f, 'stream:'));
$message = implode('; ', array_merge($notes, $failed)) ?: null;
sfw_run_finish($runid, $heightsfailed ? 'error' : 'ok', $fetched, $inserted, $newest, $message);
if ($heightsfailed) {
    sfw_fail('State gauges failed: ' . $message);
}
sfw_log("State gauges done: {$fetched} values, {$inserted} new readings" . ($message ? " ({$message})" : '') . '.');
