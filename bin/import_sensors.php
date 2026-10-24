<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Load (or refresh) the council sensor list from data/bcc_sensors.csv into the sensors table.
 *
 * Usage: php bin/import_sensors.php
 * Safe to re-run: existing sensors are updated, new ones are added, nothing is deleted.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';

$csvpath = dirname(__DIR__) . '/data/bcc_sensors.csv';
$handle = fopen($csvpath, 'r');
if ($handle === false) {
    sfw_fail("Cannot open {$csvpath}");
}

$header = fgetcsv($handle, null, ',', '"', '');
$expected = ['sensor_id', 'location_id', 'location_name', 'sensor_type', 'unit', 'datum',
    'latitude', 'longitude', 'tidal', 'quality_note'];
if ($header !== $expected) {
    sfw_fail('Unexpected columns in bcc_sensors.csv: ' . implode(', ', (array) $header));
}

$sql = <<<SQL
INSERT INTO sensors
    (sensor_id, source, location_id, location_name, sensor_type, unit, datum,
     latitude, longitude, tidal, quality_note, active, updated_utc)
VALUES
    (:sensor_id, 'bcc', :location_id, :location_name, :sensor_type, :unit, :datum,
     :latitude, :longitude, :tidal, :quality_note, 1, :updated_utc)
ON DUPLICATE KEY UPDATE
    location_id = VALUES(location_id),
    location_name = VALUES(location_name),
    sensor_type = VALUES(sensor_type),
    unit = VALUES(unit),
    datum = VALUES(datum),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    tidal = VALUES(tidal),
    quality_note = VALUES(quality_note),
    updated_utc = VALUES(updated_utc)
SQL;

$db = sfw_db();
$stmt = $db->prepare($sql);
$now = sfw_now_utc();
$count = 0;

$db->beginTransaction();
while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
    if ($row === [null] || count($row) !== count($expected)) {
        continue; // Skip blank or malformed lines.
    }
    $r = array_combine($expected, $row);
    $stmt->execute([
        ':sensor_id'     => strtoupper(trim($r['sensor_id'])),
        ':location_id'   => $r['location_id'] !== '' ? $r['location_id'] : null,
        ':location_name' => $r['location_name'],
        ':sensor_type'   => $r['sensor_type'],
        ':unit'          => $r['unit'],
        ':datum'         => $r['datum'] !== '' ? $r['datum'] : null,
        ':latitude'      => $r['latitude'],
        ':longitude'     => $r['longitude'],
        ':tidal'         => $r['tidal'] !== '' ? $r['tidal'] : null,
        ':quality_note'  => $r['quality_note'] !== '' ? $r['quality_note'] : null,
        ':updated_utc'   => $now,
    ]);
    $count++;
}
$db->commit();
fclose($handle);

sfw_log("Imported {$count} sensors.");
