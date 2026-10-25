<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Collect Bureau of Meteorology river heights and rainfall for the live catchments (Brisbane River,
 * Mary River, ...), from BoM's anonymous FTP service.
 *
 * Usage: php bin/collect_bom.php           (cron: every 15 minutes)
 *        php bin/collect_bom.php refresh   (also re-read the gauge list now, after changing settings)
 *
 * Each run:
 *  1. About hourly, refreshes the gauge list from BoM's map pages ('river_maps', 'rain_maps'):
 *     names, positions and official flood levels. Brisbane River gauges are BoM's Brisbane/Moreton
 *     group less data/bom_exclude.csv; other live catchments take the gauges inside their outline
 *     (bin/fetch_catchments.php). Each gauge is saved with its catchment.
 *  2. Loads any new reading files: IDZ65910 (latest levels, ~15 min), IDZ65911 (all readings,
 *     last ~3 hours, hourly) and IDZ65901 (hourly rainfall). File names already processed are
 *     remembered in var/bom.json so nothing is downloaded twice.
 *
 * Data: Bureau of Meteorology. River height and rainfall observations are provided under the
 * Water Regulations 2008; many are Creative Commons Attribution licensed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/bom.php';
require dirname(__DIR__) . '/lib/catchments.php';

$lock = sfw_lock('collect_bom');
$config = sfw_config();
$cfg = $config['bom'];
$base = rtrim($cfg['base_url'], '/') . '/';
$state = sfw_state_read('bom');
$db = sfw_db();

/**
 * Read the first column of a small CSV (with header) into a set.
 *
 * @return array<string, string> First column => second column.
 */
function sfw_csv_map(string $path): array
{
    $map = [];
    if (!is_file($path) || ($h = fopen($path, 'r')) === false) {
        return $map;
    }
    fgetcsv($h, null, ',', '"', '');
    while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
        if (isset($row[0]) && trim($row[0]) !== '') {
            $map[trim($row[0])] = trim($row[1] ?? '');
        }
    }
    fclose($h);
    return $map;
}

/**
 * The map pages of one kind, from the list setting (or the single-page setting of version 0.18).
 *
 * @param array<string, mixed> $cfg
 * @return string[]
 */
function sfw_bom_pages(array $cfg, string $list, string $single): array
{
    $pages = array_values(array_filter(array_map('strval', (array) ($cfg[$list] ?? []))));
    return $pages ?: [(string) $cfg[$single]];
}

/**
 * Read several map pages with the given parser, one entry per gauge id (the first page wins).
 * A page that can't be read is noted and skipped, unless it's the first (the main one).
 *
 * @param string[] $pages
 * @param string[] $notes
 * @return array<int, array<string, mixed>>
 */
function sfw_bom_read_pages(string $base, array $pages, callable $parse, array &$notes): array
{
    $byid = [];
    foreach ($pages as $i => $page) {
        try {
            foreach ($parse(sfw_fetch_raw($base . $page)) as $g) {
                $byid[$g['id']] ??= $g;
            }
        } catch (Throwable $e) {
            if ($i === 0) {
                throw $e;
            }
            $notes[] = "{$page} unavailable (" . $e->getMessage() . ')';
        }
    }
    return array_values($byid);
}

$runid = sfw_run_start('bom');
$fetched = 0;
$inserted = 0;
$newest = null;
$notes = [];

try {
    // ---------- 1. Gauge list (names, positions, flood levels) ----------
    $hasgauges = (int) $db->query("SELECT COUNT(*) FROM sensors WHERE source = 'bom' AND active = 1")->fetchColumn();
    $metaage = time() - (int) ($state['meta_time'] ?? 0);
    $force = in_array('refresh', $argv ?? [], true);
    if ($force || $hasgauges === 0 || $metaage > (int) $cfg['metadata_minutes'] * 60) {
        $before = array_flip($db->query("SELECT sensor_id FROM sensors WHERE source = 'bom' AND active = 1")
            ->fetchAll(PDO::FETCH_COLUMN));
        $exclude = sfw_csv_map($cfg['exclude_file']);
        $tidal = sfw_csv_map($cfg['tidal_file']);
        $river = sfw_bom_read_pages($base, sfw_bom_pages($cfg, 'river_maps', 'river_map'), 'sfw_bom_parse_river_map', $notes);
        if (count($river) < 50) {
            throw new RuntimeException('River map page had only ' . count($river) . ' gauges; layout may have changed.');
        }

        $keep = [];      // Sensor IDs that should be active after this refresh.
        $included = [];  // Included river gauges, for choosing rain gauges.
        $allriver = [];  // Every river gauge on the map, included or not.
        // Catchments other than the Brisbane River go by their outline.
        $others = array_values(array_diff(sfw_catchments_live($config), ['brisbane']));
        $counts = [];
        $db->beginTransaction();
        foreach ($river as $g) {
            $catchment = null;
            if (!isset($exclude[$g['id']])) {
                $catchment = $g['group'] === $cfg['river_group'] ? 'brisbane'
                    : ($others ? sfw_catchment_at($config, $g['lat'], $g['lon'], $others) : null);
            }
            $allriver[] = $g + ['in' => $catchment !== null, 'catchment' => $catchment];
            if ($catchment === null) {
                continue;
            }
            $counts[$catchment] = ($counts[$catchment] ?? 0) + 1;
            $sid = 'B' . $g['id'] . '-H';
            sfw_upsert_sensor([
                'sensor_id'      => $sid,
                'source'         => 'bom',
                'location_id'    => $g['id'],
                'location_name'  => $g['name'],
                'sensor_type'    => 'stream',
                'unit'           => 'm',
                'latitude'       => $g['lat'],
                'longitude'      => $g['lon'],
                'tidal'          => in_array($tidal[$g['id']] ?? '', ['yes', 'partial'], true) ? $tidal[$g['id']] : 'no',
                'flood_minor'    => $g['minor'],
                'flood_moderate' => $g['moderate'],
                'flood_major'    => $g['major'],
                'catchment'      => $catchment,
            ]);
            $keep[$sid] = true;
            $included[] = $g;
        }

        // Rain gauges: keep those whose nearest river gauge is one we included (and close by).
        $rain = sfw_bom_read_pages($base, sfw_bom_pages($cfg, 'rain_maps', 'rain_map'), 'sfw_bom_parse_rain_map', $notes);
        $maxm = (float) $cfg['rain_max_km'] * 1000;
        $raincount = 0;
        foreach ($rain as $r) {
            $best = null;
            $bestd = INF;
            foreach ($allriver as $g) {
                $d = sfw_distance_m($r['lat'], $r['lon'], $g['lat'], $g['lon']);
                if ($d < $bestd) {
                    $bestd = $d;
                    $best = $g;
                }
            }
            if ($best === null || !$best['in'] || $bestd > $maxm) {
                continue;
            }
            $sid = 'B' . $r['id'] . '-R';
            sfw_upsert_sensor([
                'sensor_id'     => $sid,
                'source'        => 'bom',
                'location_id'   => $r['id'],
                'location_name' => $r['name'],
                'sensor_type'   => 'rain',
                'unit'          => 'mm',
                'latitude'      => $r['lat'],
                'longitude'     => $r['lon'],
                'catchment'     => $best['catchment'],
            ]);
            $keep[$sid] = true;
            $raincount++;
        }

        // Switch off BoM gauges that are no longer included (e.g. after editing the exclusions).
        $active = $db->query("SELECT sensor_id FROM sensors WHERE source = 'bom' AND active = 1")->fetchAll(PDO::FETCH_COLUMN);
        $off = $db->prepare('UPDATE sensors SET active = 0 WHERE sensor_id = ?');
        foreach ($active as $sid) {
            if (!isset($keep[$sid])) {
                $off->execute([$sid]);
            }
        }
        $db->commit();
        $state['meta_time'] = time();
        // New gauges (a catchment just added, say): read the last few hours' files again, so they
        // start with some history instead of none. Readings already stored are skipped.
        $new = count(array_diff_key($keep, $before));
        if ($new > 0 && $before !== []) {
            unset($state['done']['IDZ65911'], $state['done']['IDZ65901']);
            $notes[] = "{$new} new gauges: reloading the last few hours";
        }
        sfw_state_write('bom', $state);
        ksort($counts);
        $notes[] = sprintf('gauge list refreshed: %d river (%s), %d rain', count($included),
            implode(', ', array_map(static fn(string $k, int $n): string => "{$k} {$n}", array_keys($counts), $counts)), $raincount);
    }

    // ---------- 2. Readings ----------
    $wanted = array_flip($db->query("SELECT sensor_id FROM sensors WHERE source = 'bom' AND active = 1")
        ->fetchAll(PDO::FETCH_COLUMN));
    $listing = sfw_fetch_raw($base);
    $datumupdate = $db->prepare("UPDATE sensors SET datum = ? WHERE sensor_id = ? AND (datum IS NULL OR datum <> ?)");
    $datums = [];

    // Product => [sensor type in file, sensor ID suffix, files to load at most per run].
    $products = [
        'IDZ65911' => ['WL', 'H', 4],  // All readings, last ~3 h (hourly). Several on first run = backfill.
        'IDZ65910' => ['WL', 'H', 1],  // Latest reading per gauge (~15 min). Newest file only.
        'IDZ65901' => ['RN', 'R', 6],  // Hourly rainfall totals.
    ];
    foreach ($products as $product => [$type, $suffix, $maxfiles]) {
        $files = sfw_bom_list_files($listing, $product);
        $done = (string) ($state['done'][$product] ?? '');
        $todo = array_values(array_filter($files, static fn(string $f): bool => $f > $done));
        $todo = array_slice($todo, -$maxfiles); // Only the newest few.
        foreach ($todo as $file) {
            $rows = sfw_bom_parse_hcs(sfw_fetch_raw($base . $file));
            $db->beginTransaction();
            foreach ($rows as $row) {
                if ($row['SensorType'] !== $type) {
                    continue;
                }
                $sid = 'B' . $row['SiteId'] . '-' . $suffix;
                if (!isset($wanted[$sid]) || !is_numeric($row['RealValue'])) {
                    continue;
                }
                // Rain: only hourly totals (3600 seconds).
                if ($type === 'RN' && (int) $row['SensorParam2'] !== 3600) {
                    continue;
                }
                $utc = sfw_bom_hcs_time($row['ObservationTimestamp']);
                if ($utc === null) {
                    continue;
                }
                $fetched++;
                $inserted += sfw_insert_reading($sid, $utc, (float) $row['RealValue']);
                if ($newest === null || $utc > $newest) {
                    $newest = $utc;
                }
                if ($type === 'WL' && in_array($row['SensorParam1'], ['AHD', 'LGH'], true)) {
                    $datums[$sid] = $row['SensorParam1'];
                }
            }
            $db->commit();
            $state['done'][$product] = $file;
            sfw_state_write('bom', $state);
        }
    }
    foreach ($datums as $sid => $datum) {
        $datumupdate->execute([$datum, $sid, $datum]);
    }

    // ---------- 3. Current rain figures ----------
    // BoM's hourly rain file only covers complete hours and appears after each hour ends, so
    // recent heavy rain can be missing for up to an hour. BoM's rain map pages are kept current
    // (past hour, and since 9am), so their latest figures are saved for the dashboard.
    // The time saved with each figure is the page's update time from the FTP listing.
    $rainnow = sfw_state_read('bom_rain_now');
    // With several pages, the figures and their time come from the main (first) page, and the
    // other pages add their gauges' figures when they've been updated since.
    $pages = ['past_hour' => sfw_bom_pages($cfg, 'rain_maps', 'rain_map'),
        'since_9am' => sfw_bom_pages($cfg, 'rain_9am_maps', 'rain_9am_map')];
    foreach ($pages as $field => $list) {
        $mtime = sfw_bom_listing_time($listing, $list[0]);
        if ($mtime === null || $mtime === ($rainnow['as_at'][$field] ?? null)) {
            continue; // Not listed, or unchanged since the last run.
        }
        $values = [];
        foreach ($list as $i => $page) {
            $ptime = $i === 0 ? $mtime : sfw_bom_listing_time($listing, $page);
            if ($ptime === null || $ptime < $mtime - 3600) {
                continue; // Missing, or too old to go with the main page's time.
            }
            try {
                foreach (sfw_bom_parse_rain_map(sfw_fetch_raw($base . $page)) as $r) {
                    $sid = 'B' . $r['id'] . '-R';
                    if (isset($wanted[$sid]) && $r['amount'] !== null) {
                        $values[$sid] ??= $r['amount'];
                    }
                }
            } catch (Throwable $e) {
                if ($i === 0) {
                    throw $e;
                }
            }
        }
        if ($values !== []) {
            $rainnow['as_at'][$field] = $mtime;
            $rainnow[$field] = $values;
        }
    }
    sfw_state_write('bom_rain_now', $rainnow);

    $message = $notes === [] ? null : implode('; ', $notes);
    sfw_run_finish($runid, 'ok', $fetched, $inserted, $newest, $message);
    sfw_log("BoM done: {$fetched} values, {$inserted} new readings" . ($message ? " ({$message})" : '') . '.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sfw_run_finish($runid, 'error', $fetched, $inserted, $newest, $e->getMessage());
    sfw_fail($e->getMessage());
}
