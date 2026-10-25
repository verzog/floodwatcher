<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Collect new Brisbane City Council rainfall and stream height readings.
 *
 * Usage: php bin/collect_bcc.php
 * Run from cron every 10 minutes. Each run asks the API for everything since the newest
 * stored reading (minus a safety overlap), saves new readings, and logs the run.
 * On the very first run it loads everything the API holds (about the last 3 days).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/bcc.php';

$config = sfw_config();
$bcc = $config['bcc'];

// Stop two runs overlapping (e.g. if the API is slow and cron starts the next run).
$lock = sfw_lock('collect_bcc');

$db = sfw_db();

// Only sensors we know about are stored, so a new council gauge can't sneak in unlabelled.
$known = $db->query("SELECT sensor_id FROM sensors WHERE source = 'bcc'")->fetchAll(PDO::FETCH_COLUMN);
$known = array_flip($known);
if ($known === []) {
    sfw_fail('No council sensors in the database. Run bin/import_sensors.php first.');
}

$runid = sfw_run_start('bcc');

$fetched = 0;
$inserted = 0;
$skipped = 0;
$unknown = [];
$newest = null;

try {
    // Work out where to start: newest stored council reading, minus the overlap.
    $last = $db->query(
        "SELECT MAX(r.measured_utc) FROM readings r
           JOIN sensors s ON s.sensor_id = r.sensor_id
          WHERE s.source = 'bcc'"
    )->fetchColumn();
    $since = null;
    if ($last) {
        $since = (new DateTimeImmutable($last, new DateTimeZone('UTC')))
            ->modify('-' . (int) $bcc['overlap_minutes'] . ' minutes')
            ->format('Y-m-d H:i:s');
    }
    sfw_log($since === null ? 'First run: loading all available records.' : "Fetching records since {$since} UTC.");

    $insert = $db->prepare('INSERT IGNORE INTO readings (sensor_id, measured_utc, value) VALUES (?, ?, ?)');
    $pagesize = min(100, max(1, (int) $bcc['page_size']));
    $offset = 0;

    do {
        $url = sfw_bcc_page_url($bcc['records_url'], $since, $pagesize, $offset);
        $data = sfw_http_get_json($url);
        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            throw new RuntimeException('API response has no "results" list.');
        }
        $total = (int) ($data['total_count'] ?? 0);

        $db->beginTransaction();
        foreach ($results as $record) {
            $unpacked = sfw_bcc_unpack_record($record);
            $fetched++;
            $skipped += $unpacked['skipped'];
            if ($newest === null || $unpacked['measured_utc'] > $newest) {
                $newest = $unpacked['measured_utc'];
            }
            foreach ($unpacked['readings'] as $sensorid => $value) {
                if (!isset($known[$sensorid])) {
                    $unknown[$sensorid] = true;
                    continue;
                }
                $insert->execute([$sensorid, $unpacked['measured_utc'], $value]);
                $inserted += $insert->rowCount(); // 1 if new, 0 if already stored.
            }
        }
        $db->commit();

        $offset += count($results);
    } while (count($results) === $pagesize && $offset < $total);

    // Build a short note for the run log.
    $notes = [];
    if ($skipped > 0) {
        $notes[] = "{$skipped} non-numeric values skipped";
    }
    if ($unknown !== []) {
        $notes[] = 'Unknown sensors (add to bcc_sensors.csv): ' . implode(', ', array_keys($unknown));
    }
    if ($newest !== null) {
        $age = (time() - strtotime($newest . ' UTC')) / 60;
        if ($age > 60) {
            $notes[] = sprintf('Newest reading is %d minutes old: source may be stale', (int) $age);
        }
    } else {
        $notes[] = 'No records returned';
    }
    $message = $notes === [] ? null : implode('; ', $notes);

    sfw_run_finish($runid, 'ok', $fetched, $inserted, $newest, $message);

    sfw_log("Done: {$fetched} records, {$inserted} new readings" . ($message ? " ({$message})" : '') . '.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    sfw_run_finish($runid, 'error', $fetched, $inserted, $newest, $e->getMessage());
    sfw_fail($e->getMessage());
}
