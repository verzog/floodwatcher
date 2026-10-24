<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Helpers for QLDTraffic road events (flooding and closures).
 * Licence of the data: CC BY 4.0, State of Queensland (Department of Transport and Main Roads).
 */

declare(strict_types=1);

/**
 * Split a GeoJSON geometry into points and lines, as [lat, lon] pairs rounded to about 1 m.
 *
 * @param array<string, mixed>|null $geometry
 * @return array{points: array<int, array<int, float>>, lines: array<int, array<int, array<int, float>>>}
 */
function sfw_traffic_shapes(?array $geometry): array
{
    $out = ['points' => [], 'lines' => []];
    if (!$geometry) {
        return $out;
    }
    $pt = static fn(array $c): array => [round((float) $c[1], 5), round((float) $c[0], 5)];
    $line = static fn(array $cs): array => array_map($pt, $cs);
    $c = $geometry['coordinates'] ?? [];
    switch ($geometry['type'] ?? '') {
        case 'Point':
            $out['points'][] = $pt($c);
            break;
        case 'MultiPoint':
            foreach ($c as $p) {
                $out['points'][] = $pt($p);
            }
            break;
        case 'LineString':
            $out['lines'][] = $line($c);
            break;
        case 'MultiLineString':
        case 'Polygon':
            foreach ($c as $l) {
                $out['lines'][] = $line($l);
            }
            break;
        case 'MultiPolygon':
            foreach ($c as $poly) {
                foreach ($poly as $l) {
                    $out['lines'][] = $line($l);
                }
            }
            break;
        case 'GeometryCollection':
            foreach ($geometry['geometries'] ?? [] as $g) {
                $sub = sfw_traffic_shapes($g);
                $out['points'] = array_merge($out['points'], $sub['points']);
                $out['lines'] = array_merge($out['lines'], $sub['lines']);
            }
            break;
    }
    return $out;
}

/**
 * True if any point of the shapes is inside the box [west, south, east, north].
 *
 * @param array{points: array<int, array<int, float>>, lines: array<int, array<int, array<int, float>>>} $shapes
 * @param float[] $bbox
 */
function sfw_traffic_in_box(array $shapes, array $bbox): bool
{
    [$w, $s, $e, $n] = $bbox;
    $all = $shapes['points'];
    foreach ($shapes['lines'] as $l) {
        $all = array_merge($all, $l);
    }
    foreach ($all as [$lat, $lon]) {
        if ($lon >= $w && $lon <= $e && $lat >= $s && $lat <= $n) {
            return true;
        }
    }
    return false;
}

/**
 * Thin a long line to roughly $max points (keeping both ends), and drop repeated points.
 *
 * @param array<int, array<int, float>> $line
 * @return array<int, array<int, float>>
 */
function sfw_traffic_thin(array $line, int $max): array
{
    $clean = [];
    foreach ($line as $p) {
        if (!$clean || $clean[count($clean) - 1] !== $p) {
            $clean[] = $p;
        }
    }
    $n = count($clean);
    if ($n <= $max || $max < 2) {
        return $clean;
    }
    $step = ($n - 1) / ($max - 1);
    $out = [];
    for ($i = 0; $i < $max; $i++) {
        $out[] = $clean[(int) round($i * $step)];
    }
    return $out;
}

/**
 * Turn a QLDTraffic time string into a Unix time, or null.
 */
function sfw_traffic_time(mixed $value): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $t = strtotime($value);
    return $t === false ? null : $t;
}

/**
 * Plain text from a feed field (strips any markup and squashes whitespace).
 */
function sfw_traffic_text(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/**
 * True if a road or place name looks like a Queensland one: plain letters plus the accented
 * letters of Western European names. QLDTraffic has sent at least one Ipswich event with a
 * Vietnamese street name ("Đường Vân Nội"), most likely from a faulty automatic address lookup;
 * names with letters like that are not shown as the heading.
 */
function sfw_traffic_name_ok(string $name): bool
{
    return preg_match('/[^\x{0000}-\x{017F}\x{2018}\x{2019}]/u', $name) !== 1;
}
