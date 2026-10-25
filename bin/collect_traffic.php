<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Collect flooding and road closure events from QLDTraffic for the live catchments. Each event is
 * tagged with its catchment: the live catchment whose outline it's in, or the Brisbane River for
 * an event in a Brisbane River council just outside the outline (bayside Brisbane, say).
 *
 * Usage: php bin/collect_traffic.php   (cron runs it every 10 minutes)
 *
 * Writes data/traffic.json in the public web folder. Nothing is stored in the database apart
 * from the run log, because only current events matter. If QLDTraffic can't be reached, the
 * previous events are kept, the file notes the problem, and the page says road information is
 * unavailable. If QLDTraffic says the key is over its limit (HTTP 429), runs pause for
 * 'quota_pause_minutes' so we don't keep asking.
 *
 * Data: © State of Queensland (Department of Transport and Main Roads), CC BY 4.0.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/traffic.php';
require dirname(__DIR__) . '/lib/catchments.php';

$config = sfw_config();
$opts = $config['traffic'];
$outdir = $config['public_data_dir'];
$lock = sfw_lock('collect_traffic');
$state = sfw_state_read('traffic');
if (time() < (int) ($state['pause_until'] ?? 0)) {
    exit(0); // Paused after QLDTraffic said the key was over its limit; the file already says so.
}
$runid = sfw_run_start('traffic');
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
// Councils by catchment (a plain list, from version 0.18 settings, is the Brisbane River's).
$councils = $opts['councils'] ?? [];
if (array_is_list($councils)) {
    $councils = ['brisbane' => $councils];
}
$live = sfw_catchments_live($config);
$councils = array_intersect_key($councils, array_flip($live));
$bbox = sfw_catchments_extent($config, $opts['bbox']);

try {
    if (trim((string) $opts['api_key']) === '') {
        throw new RuntimeException('No QLDTraffic API key set.');
    }
    // The key goes in the address; error messages never include the address, so it isn't logged.
    $data = sfw_http_get_json($opts['events_url'] . '?apikey=' . rawurlencode((string) $opts['api_key']));
    $features = $data['features'] ?? null;
    if (!is_array($features)) {
        throw new RuntimeException('QLDTraffic replied without a list of events.');
    }

    $events = [];
    foreach ($features as $f) {
        $p = $f['properties'] ?? [];
        $type = (string) ($p['event_type'] ?? '');
        $impact = is_array($p['impact'] ?? null) ? $p['impact'] : [];
        $closed = stripos((string) ($impact['impact_type'] ?? ''), 'closure') !== false;
        $wanted = in_array($type, $opts['types_all'], true)
            || ($closed && in_array($type, $opts['types_closed'], true));
        if (!$wanted) {
            continue;
        }

        $road = is_array($p['road_summary'] ?? null) ? $p['road_summary'] : [];
        $council = sfw_traffic_text($road['local_government_area'] ?? '');
        $candidates = [];
        foreach ($councils as $catchment => $names) {
            foreach ((array) $names as $name) {
                if (stripos($council, (string) $name) !== false) {
                    $candidates[] = (string) $catchment;
                    break;
                }
            }
        }
        if (!$candidates) {
            continue;
        }

        $shapes = sfw_traffic_shapes($f['geometry'] ?? null);
        if (!sfw_traffic_in_box($shapes, $bbox)) {
            continue;
        }
        // Which catchment: the first point inside one of the candidates' outlines.
        $first = $shapes['points'][0] ?? ($shapes['lines'][0][0] ?? null);
        $catchment = $first === null ? null : sfw_catchment_at($config, $first[0], $first[1], $candidates);
        if ($catchment === null) {
            if (!in_array('brisbane', $candidates, true)) {
                continue; // In a shared council (Sunshine Coast, say) but outside the catchment.
            }
            $catchment = 'brisbane';
        }
        $shapes['lines'] = array_values(array_filter(array_map(
            static fn(array $l): array => sfw_traffic_thin($l, (int) $opts['max_points']),
            $shapes['lines']
        ), static fn(array $l): bool => count($l) >= 2));

        $duration = is_array($p['duration'] ?? null) ? $p['duration'] : [];
        $source = is_array($p['source'] ?? null) ? $p['source'] : [];
        $link = (string) ($p['web_link'] ?? '');
        $roadname = sfw_traffic_text($road['road_name'] ?? '');
        $locality = sfw_traffic_text($road['locality'] ?? '');
        $events[] = [
            'id'          => (string) ($p['id'] ?? ''),
            'type'        => sfw_traffic_text($type),
            'subtype'     => sfw_traffic_text($p['event_subtype'] ?? ''),
            'closed'      => $closed,
            'impact'      => sfw_traffic_text($impact['impact_subtype'] ?? ($impact['impact_type'] ?? '')),
            'road'        => sfw_traffic_name_ok($roadname) ? $roadname : '',
            'locality'    => sfw_traffic_name_ok($locality) ? $locality : '',
            // A name that doesn't look like a Queensland one is kept here, shown only as a note.
            'road_given'  => sfw_traffic_name_ok($roadname . $locality) ? null
                : trim($roadname . ($locality !== '' ? ', ' . $locality : '')),
            'council'     => $council,
            'catchment'   => $catchment,
            'description' => sfw_traffic_text($p['description'] ?? ''),
            'advice'      => sfw_traffic_text($p['advice'] ?? ''),
            'information' => sfw_traffic_text($p['information'] ?? ''),
            'start'       => sfw_traffic_time($duration['start'] ?? null),
            'end'         => sfw_traffic_time($duration['end'] ?? null),
            'updated'     => sfw_traffic_time($p['last_updated'] ?? null),
            'source'      => sfw_traffic_text($source['provided_by'] ?? ($source['source_name'] ?? '')),
            'link'        => preg_match('#^https://#i', $link) ? $link : null,
            'points'      => $shapes['points'],
            'lines'       => $shapes['lines'],
        ];
    }

    // Closures first, then flooding, newest first within each.
    usort($events, static fn(array $a, array $b): int =>
        [$b['closed'], $b['updated'] ?? 0] <=> [$a['closed'], $a['updated'] ?? 0]);

    $payload = [
        'fetched'       => time(),
        'stale_minutes' => (int) $opts['stale_minutes'],
        'problem'       => null,
        'events'        => $events,
    ];
    if (!is_dir($outdir) && !mkdir($outdir, 0755, true) && !is_dir($outdir)) {
        throw new RuntimeException("Cannot create {$outdir}");
    }
    sfw_write_atomic("{$outdir}/traffic.json", json_encode($payload, $flags));
    sfw_state_write('traffic', []);
    $closedcount = count(array_filter($events, static fn(array $e): bool => $e['closed']));
    $message = sprintf('%d events in our catchments (%d road closures) from %d statewide.',
        count($events), $closedcount, count($features));
    sfw_run_finish($runid, 'ok', count($features), count($events), null, $message);
    sfw_log('QLDTraffic: ' . $message);
} catch (Throwable $e) {
    $overlimit = str_contains($e->getMessage(), 'Status 429') || str_contains($e->getMessage(), 'HTTP 429');
    if ($overlimit) {
        sfw_state_write('traffic', ['pause_until' => time() + 60 * (int) $opts['quota_pause_minutes']]);
    }
    // Keep the last good events, but tell the page they may be out of date and why.
    $file = "{$outdir}/traffic.json";
    $old = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $old += ['fetched' => null, 'stale_minutes' => (int) $opts['stale_minutes'], 'events' => []];
    $old['problem'] = [
        'time'    => time(),
        'message' => $overlimit
            ? 'QLDTraffic\'s request limit has been reached for now'
            : 'QLDTraffic couldn\'t be reached',
    ];
    try {
        if (is_dir($outdir)) {
            sfw_write_atomic($file, json_encode($old, $flags));
        }
    } catch (Throwable $ignored) {
        unset($ignored); // The run is already failing; the log line below says why.
    }
    sfw_run_finish($runid, 'error', 0, 0, null, $e->getMessage());
    sfw_fail('QLDTraffic failed: ' . $e->getMessage() . ($overlimit
        ? sprintf(' Pausing for %d minutes. Ask TMR for your own key (see README).', (int) $opts['quota_pause_minutes'])
        : ''));
}
