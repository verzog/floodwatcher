<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Shared database helpers for collectors: sensor records, readings and the run log.
 */

declare(strict_types=1);

/**
 * Add or update a sensor. Missing keys keep their defaults; quality_note and tidal set by hand
 * in the database are not overwritten unless supplied.
 *
 * @param array<string, mixed> $s Keys: sensor_id, source, location_id, location_name, sensor_type,
 *                                unit, datum, latitude, longitude, catchment, tidal,
 *                                flood_minor, flood_moderate, flood_major.
 */
function sfw_upsert_sensor(array $s): void
{
    static $stmt = null;
    if ($stmt === null) {
        $stmt = sfw_db()->prepare(
            'INSERT INTO sensors
                (sensor_id, source, location_id, location_name, sensor_type, unit, datum,
                 latitude, longitude, catchment, tidal, flood_minor, flood_moderate, flood_major,
                 active, updated_utc)
             VALUES
                (:sensor_id, :source, :location_id, :location_name, :sensor_type, :unit, :datum,
                 :latitude, :longitude, :catchment, :tidal, :flood_minor, :flood_moderate, :flood_major,
                 1, :updated_utc)
             ON DUPLICATE KEY UPDATE
                location_name = VALUES(location_name),
                unit = VALUES(unit),
                datum = COALESCE(VALUES(datum), datum),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                catchment = VALUES(catchment),
                tidal = COALESCE(VALUES(tidal), tidal),
                flood_minor = VALUES(flood_minor),
                flood_moderate = VALUES(flood_moderate),
                flood_major = VALUES(flood_major),
                active = 1,
                updated_utc = VALUES(updated_utc)'
        );
    }
    $stmt->execute([
        ':sensor_id'      => $s['sensor_id'],
        ':source'         => $s['source'],
        ':location_id'    => $s['location_id'] ?? null,
        ':location_name'  => $s['location_name'],
        ':sensor_type'    => $s['sensor_type'],
        ':unit'           => $s['unit'],
        ':datum'          => $s['datum'] ?? null,
        ':latitude'       => $s['latitude'],
        ':longitude'      => $s['longitude'],
        ':catchment'      => $s['catchment'] ?? 'brisbane',
        ':tidal'          => $s['tidal'] ?? null,
        ':flood_minor'    => $s['flood_minor'] ?? null,
        ':flood_moderate' => $s['flood_moderate'] ?? null,
        ':flood_major'    => $s['flood_major'] ?? null,
        ':updated_utc'    => sfw_now_utc(),
    ]);
}

/**
 * Save one reading; returns 1 if it was new, 0 if already stored.
 */
function sfw_insert_reading(string $sensorid, string $measuredutc, float $value): int
{
    static $stmt = null;
    if ($stmt === null) {
        $stmt = sfw_db()->prepare('INSERT IGNORE INTO readings (sensor_id, measured_utc, value) VALUES (?, ?, ?)');
    }
    $stmt->execute([$sensorid, $measuredutc, $value]);
    return $stmt->rowCount();
}

/**
 * Start a collector run log entry and return its id.
 */
function sfw_run_start(string $source): int
{
    // Any earlier run of this source still marked 'running' is dead: the caller holds the
    // single-instance lock, so no real run of it is in progress. Close it as an error, so a
    // process killed mid-run (out of memory, execution timeout) isn't shown as "still running"
    // forever on the dashboard.
    sfw_db()->prepare(
        "UPDATE collector_runs SET status = 'error', finished_utc = ?,
                message = 'Run did not finish (the process stopped).'
          WHERE source = ? AND status = 'running'"
    )->execute([sfw_now_utc(), $source]);
    sfw_db()->prepare('INSERT INTO collector_runs (source, started_utc) VALUES (?, ?)')
        ->execute([$source, sfw_now_utc()]);
    return (int) sfw_db()->lastInsertId();
}

/**
 * Finish a collector run log entry.
 */
function sfw_run_finish(int $runid, string $status, int $fetched, int $inserted, ?string $newest, ?string $message): void
{
    sfw_db()->prepare(
        'UPDATE collector_runs
            SET finished_utc = ?, status = ?, records_fetched = ?, readings_inserted = ?,
                newest_reading_utc = ?, message = ?
          WHERE id = ?'
    )->execute([sfw_now_utc(), $status, $fetched, $inserted, $newest,
        $message === null ? null : substr($message, 0, 1000), $runid]);
}

/**
 * Take an exclusive lock for a named job, or exit quietly if another run holds it.
 *
 * @return resource The open lock file; keep it until the script ends.
 */
function sfw_lock(string $name)
{
    $dir = sfw_var_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        sfw_fail("Cannot create {$dir}");
    }
    $lock = fopen("{$dir}/{$name}.lock", 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        sfw_log("Another {$name} run is still going; skipping this one.");
        exit(0);
    }
    return $lock;
}

/**
 * Read a small JSON state file from var/ (empty array if missing).
 *
 * @return array<string, mixed>
 */
function sfw_state_read(string $name): array
{
    $path = sfw_var_dir() . "/{$name}.json";
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Write a small JSON state file to var/.
 *
 * @param array<string, mixed> $data
 */
function sfw_state_write(string $name, array $data): void
{
    $path = sfw_var_dir() . "/{$name}.json";
    file_put_contents($path . '.tmp', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($path . '.tmp', $path);
}

/**
 * Distance in metres between two points (haversine).
 */
function sfw_distance_m(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000.0;
    $dlat = deg2rad($lat2 - $lat1);
    $dlon = deg2rad($lon2 - $lon1);
    $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/**
 * Write a file atomically (temp file then rename), so visitors never see a half-written file.
 */
function sfw_write_atomic(string $path, string $content): void
{
    $tmp = $path . '.tmp' . getmypid();
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Cannot write {$path}");
    }
    @chmod($path, 0644);
}
