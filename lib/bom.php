<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Bureau of Meteorology file parsers: the regional map pages (gauge names, positions and flood
 * levels) and the HCS data files (readings). Pure functions, so they can be tested on samples.
 */

declare(strict_types=1);

/**
 * Pull the quoted arguments out of a JavaScript call like PopupRiver("Name",'1','2',...).
 *
 * @return string[] Name first, then each single-quoted argument.
 */
function sfw_bom_popup_args(string $args): array
{
    $args = html_entity_decode($args, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('/^\s*"(.*?)"\s*,(.*)$/s', $args, $m)) {
        return [];
    }
    preg_match_all("/'([^']*)'/", $m[2], $rest);
    return array_merge([trim($m[1])], $rest[1]);
}

/**
 * Parse a BoM river map page (e.g. IDQ65412.html).
 *
 * @return array<int, array<string, mixed>> One entry per gauge: id, name, lat, lon, group, height,
 *         class, tendency, minor, moderate, major (levels null when not classified).
 */
function sfw_bom_parse_river_map(string $html): array
{
    $out = [];
    preg_match_all('/<area\b[^>]*>/i', $html, $tags);
    foreach ($tags[0] as $tag) {
        if (!preg_match('/PopupRiver\((.*?)\)\s*"/s', $tag, $call)) {
            continue;
        }
        $a = sfw_bom_popup_args($call[1]);
        if (count($a) < 11 || !is_numeric($a[2]) || !is_numeric($a[3])) {
            continue;
        }
        $group = preg_match('#href="/fwo/([A-Z]+\d+)/#', $tag, $g) ? $g[1] : null;
        $level = static fn(string $v): ?float => is_numeric($v) ? (float) $v : null;
        $out[] = [
            'id'       => $a[1],
            'name'     => $a[0],
            'lat'      => (float) $a[2],
            'lon'      => (float) $a[3],
            'height'   => $level($a[4]),
            'class'    => $a[5],
            'tendency' => $a[6],
            'minor'    => $level($a[8]),
            'moderate' => $level($a[9]),
            'major'    => $level($a[10]),
            'group'    => $group,
        ];
    }
    return $out;
}

/**
 * Parse a BoM rain map page (e.g. IDQ65415.html).
 *
 * @return array<int, array<string, mixed>> id, name, lat, lon, amount, hours.
 */
function sfw_bom_parse_rain_map(string $html): array
{
    $out = [];
    preg_match_all('/PopupRain\((.*?)\)\s*"/s', $html, $calls);
    foreach ($calls[1] as $call) {
        $a = sfw_bom_popup_args($call);
        if (count($a) < 6 || !is_numeric($a[2]) || !is_numeric($a[3])) {
            continue;
        }
        $out[] = [
            'id'     => $a[1],
            'name'   => $a[0],
            'lat'    => (float) $a[2],
            'lon'    => (float) $a[3],
            'amount' => is_numeric($a[4]) ? (float) $a[4] : null,
            'hours'  => (int) $a[5],
        ];
    }
    return $out;
}

/**
 * Parse a BoM HCS file (e.g. IDZ65910_*.hcs) into rows keyed by field name.
 *
 * @return array<int, array<string, string>>
 */
function sfw_bom_parse_hcs(string $text): array
{
    $fields = ['IndexNo', 'SensorType', 'SensorDataType', 'SiteIdType', 'SiteId', 'ObservationTimestamp',
        'RealValue', 'Unit', 'SensorParam1', 'SensorParam2', 'Quality', 'Comment'];
    $rows = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        if ($line === '') {
            continue;
        }
        if ($line[0] === '#') {
            // Use the file's own field list if it gives one.
            if (preg_match('/Data Fields:\s*(.+)$/', $line, $m)) {
                $fields = array_map('trim', explode(',', $m[1]));
            }
            continue;
        }
        $values = str_getcsv($line, ',', '"', '');
        if (count($values) < count($fields)) {
            continue;
        }
        $rows[] = array_combine($fields, array_slice($values, 0, count($fields)));
    }
    return $rows;
}

/**
 * Convert an HCS timestamp like 2026-10-22T08:33:19Z to 'Y-m-d H:i:s' UTC (null if invalid).
 */
function sfw_bom_hcs_time(string $stamp): ?string
{
    $t = strtotime($stamp);
    return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
}

/**
 * Find HCS file names for a product in an FTP directory listing, oldest first.
 *
 * @return string[]
 */
function sfw_bom_list_files(string $listing, string $product): array
{
    preg_match_all('/\b(' . preg_quote($product, '/') . '_\d{14}\.hcs)\b/', $listing, $m);
    $names = array_values(array_unique($m[1]));
    sort($names); // Names contain the timestamp, so alphabetical = chronological.
    return $names;
}

/**
 * Last-modified time (Unix, UTC) of a file in an FTP directory listing, or null if not listed.
 * BoM's FTP server lists times in UTC as "Oct 22 08:40" (no year), so the current year is
 * assumed, stepping back a year if that would put the time in the future.
 */
function sfw_bom_listing_time(string $listing, string $name): ?int
{
    $pattern = '/([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{1,2}:\d{2})\s+' . preg_quote($name, '/') . '\s*$/m';
    if (!preg_match($pattern, $listing, $m)) {
        return null;
    }
    $year = (int) gmdate('Y');
    $t = strtotime("{$m[1]} {$m[2]} {$year} {$m[3]} UTC");
    if ($t !== false && $t > time() + 86400) {
        $t = strtotime("{$m[1]} {$m[2]} " . ($year - 1) . " {$m[3]} UTC");
    }
    return $t === false ? null : $t;
}
