<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Shared helpers for the Queensland Water Monitoring Information Portal (WMIP) web service.
 *
 * Every script that reads WMIP (bin/collect_qld.php, bin/discover_catchment.php and
 * bin/history_fetch.php) builds the same get_ts_traces request and reads the same trace points.
 * These two helpers are the single copy of that, so a change to the request, or to how a reading
 * is read, stays consistent across all three. A past divergence let one caller mishandle the
 * timeouts another handled (CLAUDE.md lesson 5); keeping one copy is how that stays fixed.
 *
 * WMIP times are Brisbane time (UTC+10, no daylight saving); $offset is that in seconds. Times go
 * in and come back converted, so everything stored stays UTC.
 */

declare(strict_types=1);

/**
 * Ask WMIP for one measurement (get_ts_traces) at one or more stations and return the decoded
 * response. $params carries the measurement (datasource, varfrom, varto, data_type); $sites is a
 * single site id, or several comma-separated. Throws on a network or non-200 error (lib/http.php);
 * a WMIP-level problem comes back in the response's 'error_num'/'error_msg' for the caller to read.
 *
 * @param array<string, string> $params
 * @return array<string, mixed>
 */
function sfw_wmip_traces(
    string $url,
    array $params,
    string $sites,
    int $from,
    int $to,
    int $offset,
    string $interval = 'hour'
): array {
    return sfw_http_post_json($url, [
        'function' => 'get_ts_traces',
        'version'  => '2',
        'params'   => $params + [
            'site_list'  => $sites,
            'start_time' => gmdate('YmdHis', $from + $offset),
            'end_time'   => gmdate('YmdHis', $to + $offset),
            'interval'   => $interval,
            'multiplier' => '1',
        ],
    ]);
}

/**
 * The usable points of one trace, as [UTC timestamp => value], oldest first. Blanks, non-numbers
 * and quality 255 ("no data" in Hydstra) are dropped, and each Brisbane-time stamp is converted to
 * UTC with $offset.
 *
 * @param array<string, mixed> $trace One entry from $response['return']['traces'].
 * @return array<int, float>
 */
function sfw_wmip_points(array $trace, int $offset): array
{
    $out = [];
    foreach ($trace['trace'] ?? [] as $point) {
        $value = $point['v'] ?? '';
        if ($value === '' || !is_numeric($value) || (int) ($point['q'] ?? 0) === 255) {
            continue;
        }
        $local = DateTimeImmutable::createFromFormat('YmdHis', (string) ($point['t'] ?? ''), new DateTimeZone('UTC'));
        if ($local !== false) {
            $out[$local->getTimestamp() - $offset] = (float) $value;
        }
    }
    ksort($out);
    return $out;
}
