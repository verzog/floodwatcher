<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Brisbane City Council telemetry: building API requests and unpacking the records.
 *
 * The council API is "wide": each record is one point in time, with one column per sensor
 * (e.g. "e1580": "1.56"). A value of "-" means the sensor sent nothing for that time; for
 * stream gauges that usually means "level unchanged". These functions turn each record into
 * one reading per sensor and skip anything that isn't a number.
 */

declare(strict_types=1);

/**
 * Build the API URL for one page of records.
 *
 * @param string      $baseurl  Records endpoint from config.
 * @param string|null $sinceutc Only records at or after this UTC time ('Y-m-d H:i:s'), or null for all.
 * @param int         $limit    Page size (max 100).
 * @param int         $offset   Records to skip.
 */
function sfw_bcc_page_url(string $baseurl, ?string $sinceutc, int $limit, int $offset): string
{
    $params = [
        'order_by' => 'measured asc',
        'limit'    => $limit,
        'offset'   => $offset,
        'timezone' => 'UTC',
    ];
    if ($sinceutc !== null) {
        $iso = (new DateTimeImmutable($sinceutc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $params['where'] = "measured >= date'{$iso}'";
    }
    return $baseurl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Convert one API record into readings.
 *
 * @param array<string, mixed> $record One element of the API's "results" list.
 * @return array{measured_utc: string, readings: array<string, float>, skipped: int}
 *         readings maps upper-case sensor ID (e.g. E1580) to its numeric value.
 */
function sfw_bcc_unpack_record(array $record): array
{
    if (!isset($record['measured']) || !is_string($record['measured'])) {
        throw new RuntimeException('Record has no "measured" time.');
    }
    $measured = (new DateTimeImmutable($record['measured']))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    $readings = [];
    $skipped = 0;
    foreach ($record as $key => $value) {
        // Sensor columns look like e1580. Ignore "measured", "measured_hour" and the *_sum columns.
        if (!preg_match('/^e(\d+)$/i', (string) $key, $m)) {
            continue;
        }
        if ($value === null || $value === '' || $value === '-') {
            continue; // No reading at this time.
        }
        if (!is_numeric($value)) {
            $skipped++; // Unexpected text; count it so odd data shows up in the run log.
            continue;
        }
        $readings['E' . $m[1]] = (float) $value;
    }

    return ['measured_utc' => $measured, 'readings' => $readings, 'skipped' => $skipped];
}
