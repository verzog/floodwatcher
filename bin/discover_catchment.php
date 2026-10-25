<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Find the data sources for a catchment, to help set it up. Changes nothing: it only reads the
 * sources and prints what it finds, ready to copy into the settings and data files.
 *
 * Usage: php bin/discover_catchment.php mary      (a live catchment's key)
 *        php bin/discover_catchment.php b145      (an "under construction" one, by its key)
 *
 * Needs the catchment outlines first (bin/fetch_catchments.php). It reports:
 *   1. BoM map pages with river or rain gauges inside the outline, and those gauges;
 *   2. state (WMIP) stations in the catchment's drainage basin that have recent river heights;
 *   3. the names of the state floodplain map's sub-basins in the catchment's box;
 *   4. the dams on Seqwater's dam levels page (to pick the catchment's own);
 *   5. the councils of current QLDTraffic events inside the outline.
 * The report is also saved as var/discover_<key>.txt.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/wmip.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/bom.php';
require dirname(__DIR__) . '/lib/arcgis.php';
require dirname(__DIR__) . '/lib/catchments.php';

$config = sfw_config();
$key = (string) ($argv[1] ?? '');
$all = sfw_catchments_load();
if ($key === '' || !isset($all[$key])) {
    fwrite(STDERR, $all
        ? "Usage: php bin/discover_catchment.php <key>. Keys: " . implode(', ', array_keys($all)) . "\n"
        : "No catchment outlines yet. Run php bin/fetch_catchments.php first.\n");
    exit(1);
}
$c = $all[$key];
[$south, $west, $north, $east] = $c['bbox'];
$inside = static fn(float $lat, float $lon): bool => $lat >= $south && $lat <= $north && $lon >= $west && $lon <= $east
    && sfw_rings_contain(sfw_catchment_rings($key), $lat, $lon);
$report = [];
$say = static function (string $line = '') use (&$report): void {
    $report[] = $line;
    echo $line, "\n";
};

$say(sprintf('%s (key %s, state basin %s), box S %.3f W %.3f N %.3f E %.3f', $c['name'], $key, $c['basin'],
    $south, $west, $north, $east));
$say();

// ---------- 1. BoM map pages ----------
$say('1. BoM map pages with gauges in this catchment');
$base = rtrim((string) $config['bom']['base_url'], '/') . '/';
$gauges = [];
$rain = [];
try {
    $listing = sfw_fetch_raw($base);
    preg_match_all('/\b(IDQ65[3-4]\d\d\.html)\b/', $listing, $m);
    $pages = array_values(array_unique($m[1]));
    sort($pages);
    $say('   Checking ' . count($pages) . ' BoM pages (IDQ653xx and IDQ654xx)…');
    $riverpages = [];
    $rainpages = [];
    foreach ($pages as $page) {
        try {
            $html = sfw_fetch_raw($base . $page);
        } catch (Throwable $e) {
            continue;
        }
        $rv = array_filter(sfw_bom_parse_river_map($html), static fn(array $g): bool => $inside($g['lat'], $g['lon']));
        $rn = $rv ? [] : array_filter(sfw_bom_parse_rain_map($html), static fn(array $g): bool => $inside($g['lat'], $g['lon']));
        if ($rv) {
            $groups = array_count_values(array_map(static fn(array $g): string => (string) $g['group'], $rv));
            $riverpages[] = $page;
            $title = preg_match('#<title>\s*([^<]{1,120})#i', $html, $tm) ? ' "' . trim($tm[1]) . '"' : '';
            $say(sprintf('   %s%s: %d river gauges inside (BoM groups: %s)', $page, $title, count($rv), implode(', ', array_map(
                static fn(string $g, int $n): string => "{$g} {$n}", array_keys($groups), $groups))));
            foreach ($rv as $g) {
                $gauges[$g['id']] ??= $g + ['page' => $page];
            }
        } elseif ($rn) {
            $rainpages[] = $page;
            $title = preg_match('#<title>\s*([^<]{1,120})#i', $html, $tm) ? ' "' . trim($tm[1]) . '"' : '';
            $say(sprintf('   %s%s: %d rain gauges inside', $page, $title, count($rn)));
            foreach ($rn as $g) {
                $rain[$g['id']] ??= $g + ['page' => $page];
            }
        }
        usleep(200000);
    }
    $say();
    $say('   River gauges (BoM id, name, flood levels minor/moderate/major, latitude, longitude, page):');
    uasort($gauges, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    foreach ($gauges as $g) {
        $fmt = static fn(?float $v): string => $v === null ? '–' : sprintf('%.2f', $v);
        $lv = $g['minor'] === null ? 'no flood levels'
            : $fmt($g['minor']) . ' / ' . $fmt($g['moderate']) . ' / ' . $fmt($g['major']);
        $say(sprintf('   B%s-H  %-48s %-22s %9.4f %9.4f  %s', $g['id'], $g['name'], $lv, $g['lat'], $g['lon'], $g['page']));
    }
    $say(sprintf('   Rain gauges inside: %d', count($rain) ?: count(array_filter(
        sfw_bom_parse_rain_map(sfw_fetch_raw($base . $config['bom']['rain_maps'][0])),
        static fn(array $g): bool => $inside($g['lat'], $g['lon'])
    ))));
    $say();
    $say("   Suggested settings (config.local.php, or lib/defaults.php 'bom'):");
    $say("     'river_maps' => ['" . implode("', '", array_values(array_unique(array_merge(
        (array) $config['bom']['river_maps'], $riverpages)))) . "'],");
    if ($rainpages) {
        $say("     'rain_maps'  => ['" . implode("', '", array_values(array_unique(array_merge(
            (array) $config['bom']['rain_maps'], $rainpages)))) . "'],  (check which are past-hour and since-9am pages)");
    }
} catch (Throwable $e) {
    $say('   BoM unavailable: ' . $e->getMessage());
}
$say();

// ---------- 2. State stations (WMIP) ----------
$say('2. State stations with river heights in the last 7 days (lines for data/qld_sites.csv):');
$qurl = (string) $config['qld']['url'];
$offset = (int) $config['qld']['tz_offset_hours'] * 3600;
try {
    $list = sfw_http_post_json($qurl, ['function' => 'get_site_list', 'version' => '1',
        'params' => ['site_list' => 'MATCH(' . preg_replace('/[^0-9A-Za-z]/', '', $c['basin']) . '*)']]);
    if ((int) ($list['error_num'] ?? 0) !== 0) {
        throw new RuntimeException((string) ($list['error_msg'] ?? 'error ' . $list['error_num']));
    }
    $sites = array_values(array_unique(array_filter(array_map(
        static fn($s): string => strtoupper((string) (is_array($s) ? ($s['site'] ?? '') : $s)),
        $list['return']['sites'] ?? []
    ))));
    $say('   ' . count($sites) . ' stations in basin ' . $c['basin'] . '; checking which report heights…');
    $found = 0;
    // One station at a time (the service rejects a whole request if any station in it has no
    // heights). A station the service times out on is noted and skipped.
    $failed = [];
    foreach (array_chunk($sites, 20) as $chunk) {
        foreach ($chunk as $site) {
            try {
                $data = sfw_wmip_traces($qurl,
                    ['datasource' => 'AT', 'varfrom' => '100.00', 'varto' => '100.00', 'data_type' => 'point'],
                    $site, time() - 7 * 86400, time(), $offset);
            } catch (Throwable $e) {
                $failed[] = $site;
                if (count($failed) >= 15) {
                    throw new RuntimeException('too many stations failed (last: ' . $e->getMessage() . ')');
                }
                sleep(2);
                continue;
            }
            foreach ((int) ($data['error_num'] ?? 1) === 0 ? $data['return']['traces'] ?? [] : [] as $t) {
                if ((int) ($t['error_num'] ?? 0) !== 0 || empty($t['trace'])) {
                    continue;
                }
                $d = $t['site_details'] ?? [];
                $name = str_replace(',', ' ', (string) ($d['name'] ?? $site));
                $say(sprintf('   %s,%s,%s', $site, $name, $key));
                $found++;
            }
            usleep(200000);
        }
    }
    if (!$found) {
        $say('   None found.');
    }
    if ($failed) {
        $say('   The service didn\'t answer for: ' . implode(', ', $failed));
    }
} catch (Throwable $e) {
    $say('   State service unavailable or didn\'t understand the station search: ' . $e->getMessage());
}
$say();

// ---------- 3. State floodplain map ----------
$say('3. State floodplain map (FloodCheck) sub-basins in this box:');
try {
    $fp = null;
    foreach ($config['overlays']['layers'] as $l) {
        if (str_starts_with((string) $l['key'], 'floodplain')) {
            $fp = (string) $l['url'];
            break;
        }
    }
    if ($fp === null) {
        throw new RuntimeException('no FloodCheck layer in the settings');
    }
    $names = [];
    foreach (sfw_arc_attributes($fp, '1=1', 'sub_name', $c['bbox']) as $a) {
        $n = trim((string) ($a['sub_name'] ?? ''));
        if ($n !== '') {
            $names[$n] = ($names[$n] ?? 0) + 1;
        }
    }
    ksort($names);
    $say('   ' . ($names ? implode(', ', array_keys($names)) : 'none'));
    $say("   The catchment's own ones go in its floodplain layer: 'where' => \"sub_name IN ('…', '…')\".");
} catch (Throwable $e) {
    $say('   Floodplain map unavailable: ' . $e->getMessage());
}
$say();

// ---------- 4. Seqwater dams ----------
$say('4. Dams on Seqwater\'s dam levels page (south-east Queensland only):');
try {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . sfw_fetch_raw((string) $config['dams']['seqwater_url']));
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $dams = [];
    // Tables whose first heading is "Dam": the first cell of each row is a dam's name.
    foreach ($xp->query('//table') ?: [] as $table) {
        $first = true;
        foreach ($xp->query('.//tr', $table) ?: [] as $tr) {
            $cell = $xp->query('./th|./td', $tr)->item(0);
            $text = $cell ? trim((string) preg_replace('/\s+/u', ' ', $cell->textContent)) : '';
            if ($first) {
                $first = false;
                if (strcasecmp($text, 'Dam') !== 0) {
                    continue 2;
                }
                continue;
            }
            $name = trim((string) preg_replace('/\s*(Historical levels|On \d.*|[A-Z][a-z]+ Dam is .*)$/u', '', $text));
            if ($name !== '' && mb_strlen($name) < 40) {
                $dams[$name] = true;
            }
        }
    }
    $known = array_map(static fn(array $d): string => (string) $d['seqwater'], $config['dams']['list']);
    $others = array_filter(array_keys($dams), static function (string $n) use ($known): bool {
        foreach ($known as $k) {
            if (stripos($n, $k) === 0) {
                return false;
            }
        }
        return true;
    });
    $say('   Already set up: ' . implode(', ', $known));
    $say('   Others: ' . implode(', ', $others));
} catch (Throwable $e) {
    $say('   Seqwater unavailable: ' . $e->getMessage());
}
$say();

// ---------- 5. Councils (QLDTraffic) ----------
$say('5. Councils of current QLDTraffic events inside the catchment:');
try {
    $t = $config['traffic'];
    $data = sfw_http_get_json($t['events_url'] . '?apikey=' . rawurlencode((string) $t['api_key']));
    $councils = [];
    foreach ($data['features'] ?? [] as $f) {
        $coords = $f['geometry']['coordinates'] ?? null;
        $pt = ($f['geometry']['type'] ?? '') === 'Point' ? $coords : ($coords[0] ?? null);
        while (is_array($pt) && is_array($pt[0] ?? null)) {
            $pt = $pt[0];
        }
        if (!is_array($pt) || !$inside((float) $pt[1], (float) $pt[0])) {
            continue;
        }
        $lga = trim((string) ($f['properties']['road_summary']['local_government_area'] ?? ''));
        if ($lga !== '') {
            $councils[$lga] = ($councils[$lga] ?? 0) + 1;
        }
    }
    arsort($councils);
    $say('   ' . ($councils ? implode(', ', array_map(static fn(string $k, int $n): string => "{$k} ({$n})",
        array_keys($councils), $councils)) : 'no events inside just now'));
} catch (Throwable $e) {
    $say('   QLDTraffic unavailable: ' . $e->getMessage());
}

$file = sfw_var_dir() . "/discover_{$key}.txt";
file_put_contents($file, implode("\n", $report) . "\n");
echo "\nSaved as {$file}\n";
