<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Helpers for downloading polygon layers from ArcGIS REST map services (council and state
 * flood maps) and packing them into small files the web page can draw.
 *
 * Packed format, one file per layer:
 *   {"f": [ {"b": [w, s, e, n], "r": [ring, ring, ...]}, ... ]}
 * Coordinates are whole numbers of 1/100000 degree (about 1 m). Each ring is a flat list
 * [x0, y0, dx1, dy1, dx2, dy2, ...]: the first point, then each point's change from the one
 * before. Rings are filled with the even-odd rule, so holes need no special marking.
 */

declare(strict_types=1);

const SFW_ARC_SCALE = 100000;

/**
 * Ask a layer for the ids of its shapes (all of them, or those matching $where), optionally only
 * those touching a box.
 *
 * @param float[]|null $box [S, W, N, E] in degrees, or null for anywhere.
 * @return int[]
 */
function sfw_arc_ids(string $layerurl, string $where = '1=1', ?array $box = null): array
{
    $url = rtrim($layerurl, '/') . '/query?' . http_build_query([
        'where'         => $where,
        'returnIdsOnly' => 'true',
        'f'             => 'json',
    ] + sfw_arc_box_params($box));
    $data = sfw_http_get_json($url);
    if (isset($data['error'])) {
        throw new RuntimeException('Map service error: ' . json_encode($data['error']));
    }
    $ids = array_map('intval', $data['objectIds'] ?? []);
    sort($ids);
    return $ids;
}

/**
 * The query settings that limit a request to shapes touching a box.
 *
 * @param float[]|null $box [S, W, N, E] in degrees, or null for no limit.
 * @return array<string, string|int>
 */
function sfw_arc_box_params(?array $box): array
{
    if ($box === null) {
        return [];
    }
    [$s, $w, $n, $e] = array_map('floatval', $box);
    return [
        'geometry'     => sprintf('%.5f,%.5f,%.5f,%.5f', $w, $s, $e, $n),
        'geometryType' => 'esriGeometryEnvelope',
        'inSR'         => 4326,
        'spatialRel'   => 'esriSpatialRelIntersects',
    ];
}

/**
 * The attributes of a layer's shapes (no shapes), each with its object id under 'objectid'.
 *
 * @param float[]|null $box [S, W, N, E] in degrees, or null for anywhere.
 * @return array<int, array<string, mixed>>
 */
function sfw_arc_attributes(string $layerurl, string $where = '1=1', string $fields = '*', ?array $box = null): array
{
    $url = rtrim($layerurl, '/') . '/query?' . http_build_query([
        'where'          => $where,
        'outFields'      => $fields,
        'returnGeometry' => 'false',
        'f'              => 'json',
    ] + sfw_arc_box_params($box));
    $data = sfw_http_get_json($url);
    if (isset($data['error'])) {
        throw new RuntimeException('Map service error: ' . json_encode($data['error']));
    }
    $idfield = (string) ($data['objectIdFieldName'] ?? 'OBJECTID');
    $out = [];
    foreach ($data['features'] ?? [] as $f) {
        $a = is_array($f['attributes'] ?? null) ? $f['attributes'] : [];
        $id = $a[$idfield] ?? $a['OBJECTID'] ?? $a['objectid'] ?? null;
        $out[] = $a + ['objectid' => $id === null ? null : (int) $id];
    }
    return $out;
}

/**
 * A layer's description: its fields (name => type) and the name of its object id field.
 *
 * @return array{fields: array<string, string>, idfield: string}
 */
function sfw_arc_layer_info(string $layerurl): array
{
    $data = sfw_http_get_json(rtrim($layerurl, '/') . '?f=json');
    if (isset($data['error'])) {
        throw new RuntimeException('Map service error reading the layer: ' . json_encode($data['error']));
    }
    $fields = [];
    $idfield = (string) ($data['objectIdField'] ?? '');
    foreach ($data['fields'] ?? [] as $f) {
        $name = (string) ($f['name'] ?? '');
        $fields[$name] = (string) ($f['type'] ?? '');
        if ($idfield === '' && ($f['type'] ?? '') === 'esriFieldTypeOID') {
            $idfield = $name;
        }
    }
    return ['fields' => $fields, 'idfield' => $idfield !== '' ? $idfield : 'OBJECTID'];
}

/**
 * Split shape ids into groups for requests: at most $max ids, and short enough that the
 * request address stays under $maxchars (some servers, such as Brisbane City Council's,
 * refuse addresses longer than about 2,000 characters).
 *
 * @param int[] $ids
 * @return array<int, int[]>
 */
function sfw_arc_chunks(array $ids, int $max, int $maxchars = 1200): array
{
    $chunks = [];
    $current = [];
    $len = 0;
    foreach ($ids as $id) {
        $add = strlen((string) $id) + 3; // The id plus an encoded comma.
        if ($current && (count($current) >= $max || $len + $add > $maxchars)) {
            $chunks[] = $current;
            $current = [];
            $len = 0;
        }
        $current[] = $id;
        $len += $add;
    }
    if ($current) {
        $chunks[] = $current;
    }
    return $chunks;
}

/**
 * Download the shapes with the given ids, simplified, in longitude/latitude, with the given
 * fields (none by default).
 *
 * @param int[] $ids
 * @return array<int, mixed> Esri JSON features.
 */
function sfw_arc_features(string $layerurl, array $ids, float $offset, string $fields = ''): array
{
    $url = rtrim($layerurl, '/') . '/query?' . http_build_query([
        'objectIds'          => implode(',', $ids),
        'outFields'          => $fields,
        'returnGeometry'     => 'true',
        'outSR'              => 4326,
        'geometryPrecision'  => 6,
        'f'                  => 'json',
    ] + ($offset > 0 ? ['maxAllowableOffset' => $offset] : []));
    $data = sfw_http_get_json($url);
    if (isset($data['error'])) {
        throw new RuntimeException('Map service error: ' . json_encode($data['error']));
    }
    return $data['features'] ?? [];
}

/**
 * Approximate area of a ring in square metres (good enough near Brisbane for dropping specks).
 *
 * @param array<int, array<int, float>> $ring Points as [lon, lat].
 */
function sfw_arc_ring_m2(array $ring): float
{
    $sum = 0.0;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $sum += ($ring[$j][0] * $ring[$i][1]) - ($ring[$i][0] * $ring[$j][1]);
    }
    $lat = deg2rad((float) ($ring[0][1] ?? -27.5));
    return abs($sum / 2) * 111320.0 * 111320.0 * cos($lat);
}

/**
 * Pack one Esri polygon into the compact format, or return null if nothing is left after
 * dropping rings smaller than $minm2.
 *
 * @param array<string, mixed> $geometry Esri polygon geometry with "rings".
 * @return array<string, mixed>|null
 */
function sfw_arc_pack(array $geometry, float $minm2): ?array
{
    $rings = [];
    $w = PHP_INT_MAX;
    $s = PHP_INT_MAX;
    $e = PHP_INT_MIN;
    $n = PHP_INT_MIN;
    foreach ($geometry['rings'] ?? [] as $ring) {
        if (count($ring) < 4 || sfw_arc_ring_m2($ring) < $minm2) {
            continue;
        }
        $flat = [];
        $px = 0;
        $py = 0;
        foreach ($ring as $k => $pt) {
            $x = (int) round($pt[0] * SFW_ARC_SCALE);
            $y = (int) round($pt[1] * SFW_ARC_SCALE);
            if ($k > 0 && $x === $px && $y === $py) {
                continue; // Repeated point after rounding.
            }
            $flat[] = $k === 0 ? $x : $x - $px;
            $flat[] = $k === 0 ? $y : $y - $py;
            $px = $x;
            $py = $y;
            $w = min($w, $x);
            $e = max($e, $x);
            $s = min($s, $y);
            $n = max($n, $y);
        }
        if (count($flat) >= 8) {
            $rings[] = $flat;
        }
    }
    if (!$rings) {
        return null;
    }
    return ['b' => [$w, $s, $e, $n], 'r' => $rings];
}
