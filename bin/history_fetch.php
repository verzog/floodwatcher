<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Download historical rain, river height and flow for one region from the Queensland
 * Government's water monitoring service (WMIP), for working out flood potential rules.
 *
 * Usage: sudo -u my_webapp php8.3 bin/history_fetch.php lockyer
 *
 * Steps (each one is saved as it goes, so the script can be stopped and run again to carry on):
 *   1. Hourly rainfall for every station, for every year since 'from_year'.
 *   2. Heavy-rain events found from that rainfall (see 'event_station_24h_mm' and
 *      'event_mean_72h_mm'), written to events.csv.
 *   3. Hourly river height (highest in each hour) and flow (average) around each event.
 *   4. Daily highest height and average flow for the whole record, so floods are seen even
 *      where rainfall records have gaps.
 *
 * Files go in var/history/<region>/ (rain/, events/, daily/, events.csv, log.txt).
 * Times in the files are UTC ("2011-01-10 04:00"). Brisbane time is UTC + 10.
 * Data: © State of Queensland (Department of Natural Resources and Mines, Manufacturing and
 * Regional and Rural Development), CC BY 4.0.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/wmip.php';
require dirname(__DIR__) . '/lib/store.php';

$config = sfw_config();
$hist = $config['history'];
$qld = $config['qld'];
$region = $argv[1] ?? 'lockyer';
if (!isset($hist['regions'][$region]) || !preg_match('/^[a-z0-9_]+$/', $region)) {
    sfw_fail("Unknown region '{$region}'. Regions: " . implode(', ', array_keys($hist['regions'])));
}
$sites = $hist['regions'][$region];
$dir = rtrim($hist['dir'], '/') . '/' . $region;
$lock = sfw_lock('history_' . $region);
$offset = (int) $qld['tz_offset_hours'] * 3600; // WMIP times are Brisbane time.
$pause = (int) $hist['pause_ms'] * 1000;
foreach (['', '/rain', '/events', '/daily'] as $sub) {
    if (!is_dir($dir . $sub) && !mkdir($dir . $sub, 0750, true) && !is_dir($dir . $sub)) {
        sfw_fail("Cannot create {$dir}{$sub}");
    }
}

$params = [
    'rain'  => ['datasource' => 'AT', 'varfrom' => '10.00', 'varto' => '10.00', 'data_type' => 'tot'],
    'hmax'  => ['datasource' => 'AT', 'varfrom' => '100.00', 'varto' => '100.00', 'data_type' => 'max'],
    'hpt'   => ['datasource' => 'AT', 'varfrom' => '100.00', 'varto' => '100.00', 'data_type' => 'point'],
    'flow'  => ['datasource' => 'AT', 'varfrom' => '100.00', 'varto' => '140.00', 'data_type' => 'mean'],
];

/**
 * Write a line to the screen and to the region's log.
 */
function hist_log(string $dir, string $message): void
{
    sfw_log($message);
    file_put_contents($dir . '/log.txt', '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL, FILE_APPEND);
}

/**
 * Ask WMIP for one measurement at one station. Returns [UTC time => value], or null if the
 * station has no such data for the period. Throws on network or service errors.
 *
 * @param array<string, string> $p
 * @return array<int, float>|null
 */
function hist_get(string $url, string $site, array $p, int $from, int $to, string $interval, int $offset): ?array
{
    $data = sfw_wmip_traces($url, $p, $site, $from, $to, $offset, $interval);
    $err = (int) ($data['error_num'] ?? 0);
    if ($err !== 0) {
        $msg = (string) ($data['error_msg'] ?? '');
        if (stripos($msg, 'no data') !== false || stripos($msg, 'no records') !== false || stripos($msg, 'not found') !== false) {
            return null;
        }
        throw new RuntimeException("WMIP error {$err}: {$msg}");
    }
    $out = [];
    foreach ($data['return']['traces'] ?? [] as $trace) {
        if ((int) ($trace['error_num'] ?? 0) !== 0) {
            return null;
        }
        foreach (sfw_wmip_points($trace, $offset) as $ts => $v) {
            $out[$ts] = $v; // A later trace's reading for the same time wins, as before.
        }
    }
    ksort($out);
    return $out;
}

/**
 * Save a series as CSV (time UTC, value). A file holding only "# none" means "asked; no data".
 *
 * @param array<int, float>|null $series
 */
function hist_save(string $path, ?array $series, bool $skipzero = false): int
{
    $lines = [];
    if ($series === null) {
        $lines[] = '# none';
    } else {
        $lines[] = 'time_utc,value';
        foreach ($series as $t => $v) {
            if ($skipzero && $v == 0.0) {
                continue;
            }
            $lines[] = gmdate('Y-m-d H:i', $t) . ',' . rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        }
    }
    sfw_write_atomic($path, implode("\n", $lines) . "\n");
    return max(0, count($lines) - 1);
}

/**
 * Read a saved series back.
 *
 * @return array<int, float>
 */
function hist_read(string $path): array
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || str_starts_with($line, 'time_utc')) {
            continue;
        }
        [$t, $v] = explode(',', $line);
        $out[strtotime($t . ' UTC')] = (float) $v;
    }
    return $out;
}

$thisyear = (int) gmdate('Y');
$from = (int) $hist['from_year'];
$errors = 0;

// ---------- 1. Hourly rainfall, a year at a time ----------
hist_log($dir, "Step 1: hourly rainfall for {$region} (" . count($sites) . " stations, {$from}-{$thisyear}).");
foreach ($sites as $site) {
    $got = 0;
    for ($y = $from; $y <= $thisyear; $y++) {
        $file = "{$dir}/rain/{$site}_{$y}.csv";
        if (is_file($file) && $y < $thisyear) {
            continue; // Past years don't change; already saved.
        }
        try {
            $series = hist_get($qld['url'], $site, $params['rain'], gmmktime(0, 0, 0, 1, 1, $y) - $offset,
                gmmktime(0, 0, 0, 1, 1, $y + 1) - $offset - 1, 'hour', $offset);
            $got += hist_save($file, $series, true);
        } catch (Throwable $e) {
            $errors++;
            hist_log($dir, "  {$site} rain {$y}: " . $e->getMessage() . ' (will retry next run)');
        }
        usleep($pause);
    }
    hist_log($dir, "  {$site}: rainfall done ({$got} new rainy hours saved).");
}

// ---------- 2. Find heavy-rain events ----------
hist_log($dir, 'Step 2: finding heavy-rain events.');
$rain = [];      // site => [t => mm]
$years = [];     // year => number of stations with rainfall records that year
foreach ($sites as $site) {
    $rain[$site] = [];
    for ($y = $from; $y <= $thisyear; $y++) {
        $file = "{$dir}/rain/{$site}_{$y}.csv";
        $series = hist_read($file);
        if ($series !== []) { // Only years with a rainfall record count towards the average.
            $years[$y] = ($years[$y] ?? 0) + 1;
            $rain[$site] += $series;
        }
    }
}
$hits = [];
$hour = 3600;
foreach ($rain as $site => $series) {
    // Running 24-hour total at each rainy hour.
    $times = array_keys($series);
    $sum = 0.0;
    $start = 0;
    foreach ($times as $i => $t) {
        $sum += $series[$t];
        while ($times[$start] <= $t - 24 * $hour) {
            $sum -= $series[$times[$start]];
            $start++;
        }
        if ($sum >= (float) $hist['event_station_24h_mm']) {
            $hits[$t] = true;
        }
    }
}
// Average of the stations with records that year, over 72 hours.
$all = [];
foreach ($rain as $site => $series) {
    foreach ($series as $t => $mm) {
        $all[$t] = ($all[$t] ?? 0.0) + $mm;
    }
}
ksort($all);
$times = array_keys($all);
$sum = 0.0;
$start = 0;
foreach ($times as $t) {
    $sum += $all[$t];
    while ($times[$start] <= $t - 72 * $hour) {
        $sum -= $all[$times[$start]];
        $start++;
    }
    $n = $years[(int) gmdate('Y', $t)] ?? 1;
    if ($sum / max(1, $n) >= (float) $hist['event_mean_72h_mm']) {
        $hits[$t] = true;
    }
}
ksort($hits);
// Merge trigger hours into windows.
$before = (int) $hist['window_before_days'] * 86400;
$after = (int) $hist['window_after_days'] * 86400;
$events = [];
foreach (array_keys($hits) as $t) {
    $s = $t - $before;
    $e = $t + $after;
    $last = count($events) - 1;
    if ($last >= 0 && $s <= $events[$last]['end']) {
        $events[$last]['end'] = max($events[$last]['end'], $e);
        $events[$last]['trigger_last'] = $t;
    } else {
        $events[] = ['start' => $s, 'end' => $e, 'trigger_first' => $t, 'trigger_last' => $t];
    }
}
$lines = ['event,start_utc,end_utc,first_trigger_utc,last_trigger_utc'];
foreach ($events as $i => &$ev) {
    $ev['id'] = gmdate('Ymd', $ev['trigger_first']);
    $lines[] = implode(',', [$ev['id'], gmdate('Y-m-d H:i', $ev['start']), gmdate('Y-m-d H:i', $ev['end']),
        gmdate('Y-m-d H:i', $ev['trigger_first']), gmdate('Y-m-d H:i', $ev['trigger_last'])]);
}
unset($ev);
sfw_write_atomic("{$dir}/events.csv", implode("\n", $lines) . "\n");
hist_log($dir, '  Found ' . count($events) . ' heavy-rain events since ' . $from . '.');

// ---------- 3. Hourly height and flow around each event ----------
hist_log($dir, 'Step 3: hourly river height and flow around each event.');
$hmode = [];  // site => 'hmax' or 'hpt' (whichever WMIP accepts)
foreach ($events as $n => $ev) {
    foreach ($sites as $site) {
        foreach (['H', 'F'] as $kind) {
            $file = "{$dir}/events/{$ev['id']}_{$site}_{$kind}.csv";
            if (is_file($file) && $ev['end'] < time() - 86400) {
                continue;
            }
            try {
                if ($kind === 'F') {
                    $series = hist_get($qld['url'], $site, $params['flow'], $ev['start'], $ev['end'], 'hour', $offset);
                } else {
                    $series = null;
                    foreach ($hmode[$site] ?? ['hmax', 'hpt'] as $mode) {
                        try {
                            $series = hist_get($qld['url'], $site, $params[$mode], $ev['start'], $ev['end'], 'hour', $offset);
                            $hmode[$site] = [$mode];
                            break;
                        } catch (RuntimeException $e) {
                            if ($mode === 'hpt') {
                                throw $e;
                            }
                        }
                    }
                }
                hist_save($file, $series);
            } catch (Throwable $e) {
                $errors++;
                hist_log($dir, "  {$ev['id']} {$site} {$kind}: " . $e->getMessage() . ' (will retry next run)');
            }
            usleep($pause);
        }
    }
    if (($n + 1) % 10 === 0) {
        hist_log($dir, '  ' . ($n + 1) . ' of ' . count($events) . ' events done.');
    }
}

// ---------- 4. Daily highest height and average flow, whole record ----------
hist_log($dir, 'Step 4: daily highest height and average flow for the whole record.');
foreach ($sites as $site) {
    foreach (['H' => ['hmax', 'day'], 'F' => ['flow', 'day']] as $kind => [$p, $interval]) {
        $file = "{$dir}/daily/{$site}_{$kind}.csv";
        if (is_file($file) && filemtime($file) > time() - 86400) {
            continue;
        }
        $series = [];
        $any = false;
        for ($y = $from; $y <= $thisyear; $y += 10) {
            try {
                $part = hist_get($qld['url'], $site, $params[$p], gmmktime(0, 0, 0, 1, 1, $y) - $offset,
                    gmmktime(0, 0, 0, 1, 1, min($y + 10, $thisyear + 1)) - $offset - 1, $interval, $offset);
                if ($part !== null) {
                    $series += $part;
                    $any = true;
                }
            } catch (Throwable $e) {
                $errors++;
                hist_log($dir, "  {$site} daily {$kind} {$y}s: " . $e->getMessage());
            }
            usleep($pause);
        }
        ksort($series);
        hist_save($file, $any ? $series : null);
    }
    hist_log($dir, "  {$site}: daily record done.");
}

hist_log($dir, $errors
    ? "Finished with {$errors} problems (see above). Run again to retry them."
    : "Finished. Zip the folder and send it: cd {$dir}/.. && zip -r {$region}.zip {$region}");
exit($errors ? 1 : 0);
