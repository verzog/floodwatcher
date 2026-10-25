<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Write the dashboard's data files from the database.
 *
 * Usage: php bin/build_json.php   (cron runs it after the collectors)
 *
 * Writes into the public web folder:
 *   data/sensors.json         every map marker with its latest reading, trend, flood class or rain totals
 *   data/series/<ID>.json     each marker's main gauge over the last 3 days, for the graph
 *   data/cameras.json         flood cameras from data/cameras.csv (links to the provider's page)
 *   data/alerts.json          current gauge alerts and BoM flood warnings for the live catchments
 *
 * Everything carries its catchment ('catchment', or 'catchments' for a warning), so the page can
 * show one catchment at a time.
 *
 * Gauges from different sources at the same spot (e.g. the council's and BoM's Brisbane City
 * gauge) become one marker. The main reading comes from, in order: a BoM gauge with official
 * flood levels, the council gauge, any other BoM gauge, then the state gauge. The others are
 * listed with the marker, along with any state flow reading.
 *
 * The web page only reads these files, so visitors never touch the database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/alerts.php';
require dirname(__DIR__) . '/lib/potential.php';
require dirname(__DIR__) . '/lib/floodmask.php';
require dirname(__DIR__) . '/lib/network.php';
require dirname(__DIR__) . '/lib/dams.php';
require dirname(__DIR__) . '/lib/catchments.php';
require dirname(__DIR__) . '/lib/wu.php';

$config = sfw_config();
$outdir = $config['public_data_dir'];
$opts = $config['dashboard'];
$lock = sfw_lock('build_json');
$db = sfw_db();

// "Now" for calculations is the newest council or BoM reading (the frequent sources). Using the
// data's own clock means a late feed doesn't make every gauge look unchanged.
$datanow = $db->query(
    "SELECT MAX(r.measured_utc) FROM readings r JOIN sensors s ON s.sensor_id = r.sensor_id
      WHERE s.source IN ('bcc', 'bom')"
)->fetchColumn() ?: $db->query('SELECT MAX(measured_utc) FROM readings')->fetchColumn();
if (!$datanow) {
    sfw_fail('No readings in the database yet. Run a collector first.');
}
$now = strtotime($datanow . ' UTC');
$seriesstart = $now - (int) $opts['series_hours'] * 3600;

$sensors = $db->query(
    'SELECT sensor_id, source, location_id, location_name, sensor_type, unit, datum, latitude, longitude,
            catchment, tidal, flood_minor, flood_moderate, flood_major, quality_note
       FROM sensors WHERE active = 1 ORDER BY sensor_id'
)->fetchAll();

// All readings in the graph window, grouped by sensor, oldest first.
$stmt = $db->prepare(
    'SELECT r.sensor_id, r.measured_utc, r.value FROM readings r
       JOIN sensors s ON s.sensor_id = r.sensor_id AND s.active = 1
      WHERE r.measured_utc >= ? ORDER BY r.sensor_id, r.measured_utc'
);
$stmt->execute([gmdate('Y-m-d H:i:s', $seriesstart)]);
$byid = [];
foreach ($stmt as $row) {
    $byid[$row['sensor_id']][] = [strtotime($row['measured_utc'] . ' UTC'), (float) $row['value']];
}

// Levels and flows only change when reported, so add each one's last value before the window
// as a point at the window start. Graphs and trends are then complete.
$stmt = $db->prepare(
    "SELECT r.sensor_id, r.value FROM readings r
       JOIN sensors s ON s.sensor_id = r.sensor_id AND s.sensor_type IN ('stream', 'flow') AND s.active = 1
       JOIN (SELECT sensor_id, MAX(measured_utc) AS m FROM readings WHERE measured_utc < ? GROUP BY sensor_id) x
         ON x.sensor_id = r.sensor_id AND x.m = r.measured_utc"
);
$stmt->execute([gmdate('Y-m-d H:i:s', $seriesstart)]);
foreach ($stmt as $row) {
    $byid[$row['sensor_id']] ??= [];
    array_unshift($byid[$row['sensor_id']], [$seriesstart, (float) $row['value']]);
}

// Latest reading per sensor (even if older than the window).
$latest = [];
$rows = $db->query(
    'SELECT r.sensor_id, r.measured_utc, r.value FROM readings r
       JOIN (SELECT sensor_id, MAX(measured_utc) AS m FROM readings GROUP BY sensor_id) x
         ON x.sensor_id = r.sensor_id AND x.m = r.measured_utc'
)->fetchAll();
foreach ($rows as $row) {
    $latest[$row['sensor_id']] = [strtotime($row['measured_utc'] . ' UTC'), (float) $row['value']];
}

/**
 * Value of a change-only series at a given time: the last reading at or before it.
 *
 * @param array<int, array{0: int, 1: float}> $points Oldest first.
 */
function sfw_value_at(array $points, int $time): ?float
{
    $value = null;
    foreach ($points as [$t, $v]) {
        if ($t > $time) {
            break;
        }
        $value = $v;
    }
    return $value;
}

/**
 * The latest sudden jump in a river level that the level has stayed up after: a rise of at least
 * $jump metres between two readings no more than $minutes apart. Whether to believe it is decided
 * afterwards (other gauges on the same river, or more readings at the new level).
 *
 * @param array<int, array{0: int, 1: float}> $points Oldest first.
 * @return array{t: int, from: float, rise: float, minutes: int, after: int}|null The jump (with how
 *     many readings came after it), or null if there's none, or the level has come back down.
 */
function sfw_spike_check(array $points, float $jump, int $minutes): ?array
{
    $n = count($points);
    for ($j = $n - 1; $j >= 1; $j--) {
        [$t0, $v0] = $points[$j - 1];
        [$t1, $v1] = $points[$j];
        if ($v1 - $v0 < $jump || $t1 - $t0 > 60 * $minutes) {
            continue;
        }
        // Has the level stayed up (at least half the jump) since?
        for ($k = $j; $k < $n; $k++) {
            if ($points[$k][1] < $v0 + $jump / 2) {
                return null; // It came back down: the jump is over, the latest reading is fine.
            }
        }
        return ['t' => $t1, 'from' => $v0, 'rise' => round($v1 - $v0, 2),
            'minutes' => max(1, intdiv($t1 - $t0, 60)), 'after' => $n - 1 - $j];
    }
    return null;
}

/**
 * How much a gauge rose around a time: its highest reading from 3 hours before to 3 hours after,
 * less its level 3 hours before (0 if unknown).
 *
 * @param array<int, array{0: int, 1: float}> $points Oldest first.
 */
function sfw_rise_around(array $points, int $time): float
{
    $base = sfw_value_at($points, $time - 3 * 3600);
    $top = null;
    foreach ($points as [$t, $v]) {
        if ($t >= $time - 3 * 3600 && $t <= $time + 3 * 3600) {
            $top = $top === null ? $v : max($top, $v);
        }
    }
    return ($base === null || $top === null) ? 0.0 : $top - $base;
}

/**
 * Total rain in readings timed after $since and up to $until.
 *
 * @param array<int, array{0: int, 1: float}> $points
 */
function sfw_rain_between(array $points, int $since, int $until): float
{
    $sum = 0.0;
    foreach ($points as [$t, $v]) {
        if ($t > $since && $t <= $until) {
            $sum += $v;
        }
    }
    return round($sum, 1);
}

/**
 * Flood class of a level against official levels: below, minor, moderate, major (null if none).
 */
function sfw_flood_class(?float $value, ?float $minor, ?float $moderate, ?float $major): ?string
{
    if ($value === null || $minor === null) {
        return null;
    }
    if ($major !== null && $value >= $major) {
        return 'major';
    }
    if ($moderate !== null && $value >= $moderate) {
        return 'moderate';
    }
    return $value >= $minor ? 'minor' : 'below';
}

// BoM's current rain figures (past hour, since 9am), saved by the BoM collector.
$rainnow = sfw_state_read('bom_rain_now');
$nowlimit = (int) $opts['rain_now_minutes'] * 60;

/**
 * Start of the current rain day: the most recent 9am Brisbane time (UTC+10, no daylight saving)
 * at or before $t. This matches BoM's "since 9am" figures.
 */
function sfw_last_9am(int $t): int
{
    $local = $t + 36000;
    $nine = intdiv($local - 9 * 3600, 86400) * 86400 + 9 * 3600;
    return $nine - 36000;
}

// ---------- Per-sensor summaries ----------

$items = [];
foreach ($sensors as $s) {
    $id = $s['sensor_id'];
    $points = $byid[$id] ?? [];
    $last = $latest[$id] ?? null;
    $src = $s['source'];
    // State gauges lag and report hourly, so measure their trends and totals from their own
    // latest reading; council and BoM gauges use the shared "now".
    $ref = ($src === 'qld' && $last) ? $last[0] : $now;
    $num = static fn($v): ?float => $v === null ? null : (float) $v;

    $item = [
        'id'        => $id,
        'source'    => $src,
        'name'      => $s['location_name'],
        'type'      => $s['sensor_type'],
        'unit'      => $s['unit'],
        'datum'     => $s['datum'],
        'lat'       => (float) $s['latitude'],
        'lon'       => (float) $s['longitude'],
        'loc'       => $s['location_id'],
        'catchment' => $s['catchment'],
        'tidal'     => $s['tidal'],
        'note'      => $s['quality_note'],
        'latest'    => $last ? $last[1] : null,
        'latest_t'  => $last ? $last[0] : null,
    ];
    $stalemins = (int) ($opts['stale_minutes'][$src] ?? 120);

    if ($s['sensor_type'] === 'stream' || $s['sensor_type'] === 'flow') {
        $window = (int) ($src === 'qld' ? $opts['trend_minutes_qld'] : $opts['trend_minutes']) * 60;
        $before = sfw_value_at($points, $ref - $window);
        $rate = null;
        $trend = 'unknown';
        if ($last !== null && $before !== null) {
            $rate = round(($last[1] - $before) / ($window / 3600), 3);
            if ($s['sensor_type'] === 'stream') {
                $abs = abs($rate);
                if ($abs < $opts['rising_m_per_hr']) {
                    $trend = 'steady';
                } elseif ($rate > 0) {
                    $trend = $abs >= $opts['fast_m_per_hr'] ? 'rising_fast' : 'rising';
                } else {
                    $trend = 'falling';
                }
            }
        }
        $item['trend'] = $s['sensor_type'] === 'stream' ? $trend : null;
        $item['rate_per_hr'] = $rate;
        if ($points !== []) {
            $values = array_column($points, 1);
            $item['min_72h'] = min($values);
            $item['max_72h'] = max($values);
        }
        $item['flood'] = [
            'minor'    => $num($s['flood_minor']),
            'moderate' => $num($s['flood_moderate']),
            'major'    => $num($s['flood_major']),
        ];
        $item['flood_class'] = sfw_flood_class($item['latest'], $item['flood']['minor'],
            $item['flood']['moderate'], $item['flood']['major']);
        // A sudden jump in the level, checked against other gauges further down this script.
        $item['spike'] = false;
        $item['jump'] = $s['sensor_type'] === 'stream' && $item['note'] === null
            ? sfw_spike_check($points, (float) $config['alerts']['spike_m'], (int) $config['alerts']['spike_minutes'])
            : null;
        // Council and BoM gauges report changes only, so silence is normal; state gauges
        // report on a schedule, so a long silence means a problem.
        $item['stale'] = $src === 'qld' && ($last === null || $last[0] < time() - $stalemins * 60);
        $item['series'] = ['type' => 'stream', 'start' => $seriesstart, 'points' => $points];
    } else {
        $item['rain_1h'] = sfw_rain_between($points, $ref - 3600, $ref);
        $item['rain_3h'] = sfw_rain_between($points, $ref - 3 * 3600, $ref);
        $item['rain_24h'] = sfw_rain_between($points, $ref - 24 * 3600, $ref);
        $item['rain_72h'] = sfw_rain_between($points, $ref - 72 * 3600, $ref);
        $item['since_9am'] = sfw_rain_between($points, sfw_last_9am($ref), $ref);
        $limit = $src === 'bcc' ? (int) $opts['rain_stale_minutes'] : $stalemins;
        $item['stale'] = $last === null || $last[0] < $now - $limit * 60;
        $item['interval'] = ['bcc' => '5 min', 'wu' => '15 min'][$src] ?? '1 hour';
        $item['community'] = $src === 'wu'; // Weather Underground: unofficial, shown on its own layer.
        // The totals above run up to this time (for hourly gauges, the end of the last full hour).
        $item['totals_to'] = $last ? min($last[0], $ref) : null;
        // If collection began less than 3 days ago, say so rather than implying a full 3 days.
        $firstpoint = $points !== [] ? $points[0][0] : null;
        $item['history_from'] = ($firstpoint !== null && $firstpoint > $seriesstart + 2 * 3600) ? $firstpoint : null;
        // BoM gauges: use BoM's current past-hour and since-9am figures when they are fresh.
        // They include rain since the last full hour, which the hourly file doesn't yet.
        $item['past_hour_to'] = null;
        $item['since_9am_to'] = $item['totals_to'];
        if ($src === 'bom') {
            foreach (['past_hour' => 'rain_1h', 'since_9am' => 'since_9am'] as $field => $key) {
                $asat = (int) ($rainnow['as_at'][$field] ?? 0);
                if (isset($rainnow[$field][$id]) && $asat >= time() - $nowlimit) {
                    $item[$key] = (float) $rainnow[$field][$id];
                    $item[$field === 'past_hour' ? 'past_hour_to' : 'since_9am_to'] = $asat;
                    $item['stale'] = false;
                }
            }
        }
        // Hourly totals (72 bars). A reading at 14:00 covers the interval ending at 14:00.
        $hours = [];
        $firsthour = intdiv($seriesstart, 3600) * 3600;
        for ($h = $firsthour; $h <= $now; $h += 3600) {
            $hours[$h] = 0.0;
        }
        foreach ($points as [$t, $v]) {
            $bucket = intdiv($t - 1, 3600) * 3600;
            if (isset($hours[$bucket])) {
                $hours[$bucket] += $v;
            }
        }
        $hourly = [];
        foreach ($hours as $h => $v) {
            $hourly[] = [$h, round($v, 1)];
        }
        $item['series'] = ['type' => 'rain', 'start' => $firsthour, 'hourly' => $hourly];
    }
    $items[$id] = $item;
}

// ---------- Doubt sudden jumps that nothing else backs up ----------

// A river gauge whose level jumps several metres between two readings (see 'spike_m') is believed
// if another gauge on the same river within 'spike_km' rose too around that time. With no such
// neighbour, it's believed once 'spike_confirm' more readings have stayed near the new level.
// Otherwise it's probably a faulty reading: shown with a "?", and no alert.
$spikekm = (float) ($config['alerts']['spike_km'] ?? 25);
$spikeback = (float) ($config['alerts']['spike_backing_m'] ?? 0.3);
foreach ($items as $id => $it) {
    $jump = $it['jump'] ?? null;
    unset($items[$id]['jump']);
    if ($jump === null) {
        continue;
    }
    $river = sfw_waterway($it['name']);
    $neighbours = 0;
    $backed = false;
    foreach ($items as $oid => $o) {
        if ($oid === $id || $o['type'] !== 'stream' || $o['note'] !== null || $o['source'] === 'wu'
            || sfw_waterway($o['name']) !== $river
            || sfw_distance_m($it['lat'], $it['lon'], $o['lat'], $o['lon']) > 1000 * $spikekm) {
            continue;
        }
        $neighbours++;
        if (sfw_rise_around($byid[$oid] ?? [], $jump['t']) >= $spikeback) {
            $backed = true;
            break;
        }
    }
    if ($backed || ($neighbours === 0 && $jump['after'] >= (int) $config['alerts']['spike_confirm'])) {
        continue;
    }
    $items[$id]['spike'] = true;
    // Shown after "This gauge may be faulty: ".
    $items[$id]['note'] = sprintf('it jumped %.2f m in %d minutes at %s, %s. No alerts until that is confirmed.',
        $jump['rise'], $jump['minutes'], gmdate('H:i', $jump['t'] + 36000),
        $neighbours ? 'but other gauges on this river didn\'t rise' : 'and needs more readings to confirm it');
}

// ---------- Check community rain gauges against official ones nearby ----------

// A home gauge that reads far below (or above) the official gauges around it is probably badly
// placed, blocked or faulty: flag it so the map shows it as suspect. The comparison covers only
// the hours the community gauge has been collected (up to 24), so a newly added gauge isn't
// unfairly compared with a full day of official rain.
$wucfg = $config['wu'];
foreach ($items as $id => $it) {
    if ($it['source'] !== 'wu' || $it['note'] || $it['stale'] || $it['latest_t'] === null) {
        continue;
    }
    $mypoints = $byid[$id] ?? [];
    if ($mypoints === []) {
        continue;
    }
    $from = max($now - 24 * 3600, $mypoints[0][0] - 900); // From the first counted reading.
    if ($now - $from < 3 * 3600) {
        continue; // Under 3 hours of data: too soon to judge.
    }
    $mine = sfw_rain_between($mypoints, $from, $now);
    $near = [];
    foreach ($items as $oid => $o) {
        if ($o['type'] === 'rain' && $o['source'] !== 'wu' && !$o['stale'] && !$o['note']
            && sfw_distance_m($it['lat'], $it['lon'], $o['lat'], $o['lon']) <= 1000 * (float) $wucfg['check_km']) {
            $near[] = sfw_rain_between($byid[$oid] ?? [], $from, $now);
        }
    }
    $verdict = sfw_wu_disagreement((float) $mine, $near, (float) $wucfg['check_min_mm'], (float) $wucfg['check_low_share']);
    if ($verdict === null) {
        continue; // Too few official gauges nearby, or it looks fine.
    }
    $hours = (int) round(($now - $from) / 3600);
    $items[$id]['note'] = $verdict['level'] === 'low'
        ? sprintf('reads much less than official gauges nearby (%.0f mm vs about %.0f mm in %d hours)', $mine, $verdict['median'], $hours)
        : sprintf('reads much more than official gauges nearby (%.0f mm vs about %.0f mm in %d hours)', $mine, $verdict['median'], $hours);
}

// ---------- Merge gauges at the same spot into one marker ----------

/**
 * Lower number = preferred as a marker's main reading.
 *
 * @param array<string, mixed> $i
 */
function sfw_priority(array $i): int
{
    $suspect = $i['note'] ? 10 : 0;
    $nodata = $i['latest'] === null ? 20 : 0;
    if ($i['type'] === 'rain') {
        $p = ['bcc' => 0, 'bom' => 1, 'qld' => 2][$i['source']] ?? 3;
    } else {
        $hasLevels = isset($i['flood']['minor']) && $i['flood']['minor'] !== null;
        $p = match (true) {
            $i['source'] === 'bom' && $hasLevels => 0,
            $i['source'] === 'bcc' => 1,
            $i['source'] === 'bom' => 2,
            default => 3,
        };
    }
    return $p + $suspect + $nodata;
}

$mergem = (float) $opts['merge_metres'];
$clusters = [];
$ordered = array_filter($items, static fn(array $i): bool => $i['type'] !== 'flow');
uasort($ordered, static fn(array $a, array $b): int => sfw_priority($a) <=> sfw_priority($b));
/**
 * The waterway part of a gauge name ("Brisbane River at Savages Crossing" -> "brisbane river"),
 * with common abbreviations spelt out, for matching the same gauge across sources.
 */
function sfw_waterway(string $name): string
{
    $w = strtolower(trim(preg_split('/\s+(at|@)\s+/i', $name)[0]));
    $w = preg_replace(['/\bck\b/', '/\br\b/'], ['creek', 'river'], $w);
    return trim(preg_replace('/\s+/', ' ', $w));
}

// State gauge coordinates can sit a few hundred metres from BoM's for the same station, so a
// state gauge also joins a marker up to 1 km away on the same named waterway.
$namedm = 1000.0;
foreach ($ordered as $item) {
    $best = null;
    $bestscore = INF;
    foreach ($clusters as $k => $c) {
        $p = $c['primary'];
        if ($p['type'] !== $item['type'] || isset($c['sources'][$item['source']])
            || $item['source'] === 'wu' || $p['source'] === 'wu') {
            // Community gauges always get their own marker, on their own layer.
            continue; // Different kind, or this source already has a gauge here (e.g. weir HW/TW).
        }
        if ($item['loc'] !== null && $item['loc'] === $p['loc']) {
            $score = -1.0; // Same station number: always the best match.
        } else {
            $score = sfw_distance_m($p['lat'], $p['lon'], $item['lat'], $item['lon']);
            $named = ($item['source'] === 'qld' || $p['source'] === 'qld')
                && $score <= $namedm && sfw_waterway($item['name']) === sfw_waterway($p['name']);
            if ($score > $mergem && !$named) {
                continue;
            }
        }
        if ($score < $bestscore) {
            $bestscore = $score;
            $best = $k;
        }
    }
    if ($best === null) {
        $clusters[] = ['primary' => $item, 'members' => [], 'sources' => [$item['source'] => true]];
    } else {
        $clusters[$best]['members'][] = $item;
        $clusters[$best]['sources'][$item['source']] = true;
    }
}

// Attach flows to the stream marker at the same station (or the nearest within merge distance).
foreach ($items as $flow) {
    if ($flow['type'] !== 'flow') {
        continue;
    }
    $best = null;
    $bestd = INF;
    foreach ($clusters as $k => $c) {
        if ($c['primary']['type'] !== 'stream') {
            continue;
        }
        foreach (array_merge([$c['primary']], $c['members']) as $m) {
            $d = $m['loc'] === $flow['loc'] ? 0 : sfw_distance_m($m['lat'], $m['lon'], $flow['lat'], $flow['lon']);
            if ($d < $bestd) {
                $bestd = $d;
                $best = $k;
            }
        }
    }
    if ($best !== null && $bestd <= $mergem) {
        $clusters[$best]['flow'] = $flow;
    }
}

// ---------- Output ----------

$sourcenames = ['bcc' => 'Brisbane City Council', 'bom' => 'Bureau of Meteorology', 'qld' => 'Queensland Government',
    'wu' => 'Weather Underground community station'];
$tidalrank = ['yes' => 2, 'partial' => 1, 'no' => 0];
$out = [];
$series = [];
foreach ($clusters as $c) {
    $p = $c['primary'];
    $marker = $p;
    unset($marker['series']);
    // A marker is as tidal as its most tidal gauge.
    foreach ($c['members'] as $m) {
        if (($tidalrank[$m['tidal'] ?? 'no'] ?? 0) > ($tidalrank[$marker['tidal'] ?? 'no'] ?? 0)) {
            $marker['tidal'] = $m['tidal'];
        }
    }
    $marker['source_name'] = $sourcenames[$p['source']] ?? $p['source'];
    $marker['others'] = array_map(static fn(array $m): array => [
        'id'          => $m['id'],
        'source_name' => $sourcenames[$m['source']] ?? $m['source'],
        'name'        => $m['name'],
        'latest'      => $m['latest'],
        'latest_t'    => $m['latest_t'],
        'unit'        => $m['unit'],
        'datum'       => $m['datum'],
        'note'        => $m['note'],
    ], $c['members']);
    if (isset($c['flow'])) {
        $marker['flow'] = [
            'latest'      => $c['flow']['latest'],
            'latest_t'    => $c['flow']['latest_t'],
            'rate_per_hr' => $c['flow']['rate_per_hr'],
            'source_name' => $sourcenames[$c['flow']['source']] ?? $c['flow']['source'],
        ];
    }
    $out[] = $marker;
    $series[$p['id']] = $p['series'];
}

// Link each river marker to the rain marker at the same spot (and back), so the panel can offer
// "Rain gauge here" / "River gauge here" instead of the two map markers fighting for clicks.
foreach ($out as $i => $a) {
    $best = null;
    $bestd = INF;
    foreach ($out as $j => $b) {
        if ($a['type'] === $b['type'] || $b['type'] === 'flow' || !empty($a['community']) || !empty($b['community'])) {
            continue;
        }
        $d = ($a['loc'] !== null && $a['loc'] === $b['loc']) ? 0.0
            : sfw_distance_m($a['lat'], $a['lon'], $b['lat'], $b['lon']);
        if ($d < $bestd) {
            $bestd = $d;
            $best = $j;
        }
    }
    $out[$i]['pair'] = ($best !== null && $bestd <= $mergem) ? $out[$best]['id'] : null;
}

// Last run of each collector, so the page can warn if collection has stopped.
$runs = [];
$sourcenames['warnings'] = 'BoM flood warning';
foreach (['bcc', 'bom', 'qld', 'warnings'] as $src) {
    $run = $db->query(
        "SELECT started_utc, status, message FROM collector_runs WHERE source = " . $db->quote($src)
        . ' ORDER BY id DESC LIMIT 1'
    )->fetch();
    if ($run) {
        $runs[$src] = [
            'name'    => $sourcenames[$src],
            'time'    => strtotime($run['started_utc'] . ' UTC'),
            'status'  => $run['status'],
            'message' => $run['message'],
        ];
    }
}

$payload = [
    'generated'  => time(),
    'data_time'  => $now,
    'runs'       => $runs,
    'last_run'   => $runs['bcc'] ?? null, // Kept for pages from version 0.2.
    'thresholds' => [
        'rising_m_per_hr' => $opts['rising_m_per_hr'],
        'fast_m_per_hr'   => $opts['fast_m_per_hr'],
        'trend_minutes'   => $opts['trend_minutes'],
    ],
    'sensors'    => $out,
    // The live catchments (key, name, box), for the page's catchment chooser.
    'catchments' => sfw_catchments_payload($config),
    'default_catchment' => (string) ($config['catchments']['default'] ?? 'brisbane'),
];

// The river network for the "Flood event upstream" notice (data/network.csv, data/travel_times.csv).
$network = sfw_network_load(dirname(__DIR__) . '/data/network.csv', dirname(__DIR__) . '/data/travel_times.csv',
    array_column($out, 'id'));
foreach (array_unique($network['problems']) as $problem) {
    sfw_log('River network: ' . $problem . '.');
}
$payload['network'] = ['nodes' => $network['nodes'], 'travel' => $network['travel']];

// Dams: Seqwater's figures (bin/collect_dams.php) with BoM's lake and tailwater gauges.
$payload['dams'] = sfw_dams_status($config['dams'], $items, $byid, sfw_state_read('seqwater'), $now);
$payload['dams_source'] = $config['dams']['seqwater_url'];

foreach ([$outdir, $outdir . '/series'] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        sfw_fail("Cannot create {$dir}");
    }
}

// Flood cameras: published from data/cameras.csv so edits show up on the next run.
$cameras = [];
$camfile = $config['cameras_file'] ?? '';
if ($camfile !== '' && is_readable($camfile) && ($fh = fopen($camfile, 'r')) !== false) {
    $head = fgetcsv($fh, null, ',', '"', '');
    while ($head && ($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if (count($row) !== count($head)) {
            continue;
        }
        $c = array_combine($head, $row);
        $lat = filter_var($c['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
        $lon = filter_var($c['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
        $url = trim($c['url'] ?? '');
        if ($lat === false || $lon === false || !preg_match('#^https://#i', $url)) {
            continue; // Needs a position and a secure link.
        }
        $camcatchment = trim((string) ($c['catchment'] ?? ''));
        $cameras[] = [
            'catchment' => $camcatchment !== '' ? $camcatchment
                : (sfw_catchment_at($config, $lat, $lon) ?? 'brisbane'),
            'name'     => trim($c['name'] ?? ''),
            'provider' => trim($c['provider'] ?? ''),
            'lat'      => $lat,
            'lon'      => $lon,
            // Where the position came from: exact, gauge (the river gauge at the same bridge) or approx.
            'position' => in_array(trim($c['position'] ?? ''), ['exact', 'gauge'], true) ? trim($c['position']) : 'approx',
            'url'      => $url,
        ];
    }
    fclose($fh);
}

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
foreach ($series as $id => $data) {
    sfw_write_atomic("{$outdir}/series/{$id}.json", json_encode($data, $flags));
}
sfw_write_atomic("{$outdir}/sensors.json", json_encode($payload, $flags));
sfw_write_atomic("{$outdir}/cameras.json", json_encode(['cameras' => $cameras], $flags));

// Alerts: gauge alerts worked out here, plus current BoM flood warnings for the live catchments.
$potentials = sfw_potential_markers($items, $byid, $now, $config['potential'] ?? []);
// Keep each prediction's and warning's area to the flood-prone land joined to the gauges it's
// about (lib/floodmask.php), so separate rivers in the same box are left out. Without the mask
// (flood maps not downloaded yet) the areas stay as set.
$floodmask = sfw_floodmask_load("{$outdir}/floodmask.json");
$joined = static function (?array $box, array $seeds) use ($floodmask): array {
    if ($box === null || $floodmask === null) {
        return [$box, null];
    }
    $area = $seeds ? sfw_floodmask_region($floodmask, $box, $seeds) : null;
    if ($area !== null) {
        return [$area['bbox'], $area];
    }
    return [sfw_floodmask_tighten($floodmask, $box) ?? $box, null];
};
foreach ($potentials as &$pm) {
    $rule = null;
    foreach ($config['potential'] ?? [] as $r) {
        if ('P-' . $r['key'] === $pm['id']) {
            $rule = $r;
        }
    }
    [$pm['bbox'], $pm['area']] = $joined($pm['bbox'] ?? null, $rule['seeds'] ?? [[$pm['lat'], $pm['lon']]]);
}
unset($pm);
$alertresult = sfw_alerts_update(array_merge($out, $potentials), sfw_state_read('alerts'), $now, $config['alerts']);
sfw_state_write('alerts', $alertresult['state']);
$warnstate = sfw_state_read('bom_warnings');
// Link each warning to the gauges on the rivers and creeks it names, so the page can show a
// warning only when that area is on screen. A warning that names none of ours has no area and
// is always shown.
// Only rivers and creeks in the warning's own catchments count (there's a Deep Creek in more than
// one catchment, for example).
$waterways = [];
foreach ($out as $m) {
    if ($m['type'] === 'stream') {
        $ww = sfw_waterway($m['name']);
        if (strlen($ww) >= 6 && preg_match('/\b(river|creek|brook)\b/', $ww)) {
            $waterways[(string) ($m['catchment'] ?? 'brisbane')][$ww][] = [$m['lat'], $m['lon']];
        }
    }
}
$warnings = array_map(static function (array $w) use ($waterways, $joined): array {
    $text = strtolower($w['title'] . ' | ' . implode(' | ', $w['areas'] ?? []) . ' | ' . implode(' | ', $w['headlines'] ?? []));
    $catchments = $w['catchments'] ?? ['brisbane']; // Warnings saved before 0.19 were the Brisbane River's.
    $pts = [];
    foreach ($catchments as $catchment) {
        foreach ($waterways[$catchment] ?? [] as $ww => $coords) {
            if (preg_match('/\b' . preg_quote($ww, '/') . '\b/', $text)) {
                $pts = array_merge($pts, $coords);
            }
        }
    }
    $bbox = null;
    $area = null;
    if ($pts) {
        $lats = array_column($pts, 0);
        $lons = array_column($pts, 1);
        [$bbox, $area] = $joined([min($lats) - 0.05, min($lons) - 0.05, max($lats) + 0.05, max($lons) + 0.05], $pts); // S, W, N, E.
    }
    return [
        'id'        => $w['id'],
        'title'     => $w['title'],
        'level'     => $w['level'],
        'final'     => !empty($w['final']),
        'issued'    => $w['issued'],
        'headlines' => $w['headlines'],
        'url'       => $w['url'],
        'catchments' => $catchments,
        'bbox'      => $bbox,
        'area'      => $area, // For notifications only; not written to alerts.json (see below).
    ];
}, $warnstate['warnings'] ?? []);
sfw_write_atomic("{$outdir}/alerts.json", json_encode([
    'generated'         => time(),
    'data_time'         => $now,
    'warnings'          => array_map(static fn(array $w): array => array_diff_key($w, ['area' => true]), $warnings),
    'warnings_checked'  => $warnstate['fetched'] ?? null,
    'alerts'            => $alertresult['alerts'],
    'cleared'           => $alertresult['cleared'],
], $flags));

// Push notifications for anything new (never stops the dashboard update if it fails).
if (!empty($config['push']['enabled'])) {
    try {
        require_once dirname(__DIR__) . '/lib/push.php';
        $pushed = sfw_push_dispatch($db, array_merge($out, $potentials), $alertresult['alerts'], $alertresult['cleared'], $warnings,
            $config['push'] + ['floodmask' => $floodmask]);
        if ($pushed > 0) {
            sfw_log("Sent {$pushed} push notifications.");
        }
    } catch (Throwable $e) {
        sfw_log('Push notifications skipped: ' . $e->getMessage());
    }
}

sfw_log(sprintf('Wrote dashboard data: %d markers from %d gauges (data time %s UTC).',
    count($out), count($items), $datanow));
