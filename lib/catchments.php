<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Catchments: which part of Queensland each gauge, road event, dam and warning belongs to.
 *
 * bin/fetch_catchments.php downloads every Queensland drainage basin from the state's map service
 * and saves their outlines in var/catchments.json (the packed format of lib/arcgis.php). The
 * catchments set up in 'catchments' => 'live' (Brisbane River, Mary River, ...) are shown in full
 * on the page; the rest are listed as "under construction".
 *
 * var/catchments.json:
 *   {"fetched": <time>, "catchments": {"<key>": {"name", "basin", "status", "bbox": [S, W, N, E],
 *    "state_name", "f": [packed shapes]}}}
 */

declare(strict_types=1);

/**
 * The saved catchments (loaded once): name, basin, status, box, and the packed outline ('f').
 * Use sfw_catchment_rings() for an outline's points.
 *
 * @return array<string, array<string, mixed>> Key => name, basin, status, bbox, f.
 */
function sfw_catchments_load(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = [];
    $file = sfw_var_dir() . '/catchments.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    foreach ((is_array($data) ? $data['catchments'] ?? [] : []) as $key => $c) {
        $cache[(string) $key] = [
            'name'   => (string) ($c['name'] ?? $key),
            'basin'  => (string) ($c['basin'] ?? ''),
            'status' => (string) ($c['status'] ?? 'construction'),
            'bbox'   => $c['bbox'] ?? null,
            'f'      => $c['f'] ?? [],
        ];
    }
    return $cache;
}

/**
 * A catchment's outline as rings of [lat, lon] points (unpacked once per catchment; [] if unknown).
 *
 * @return array<int, array<int, array{0: float, 1: float}>>
 */
function sfw_catchment_rings(string $key): array
{
    static $done = [];
    if (!isset($done[$key])) {
        $rings = [];
        foreach (sfw_catchments_load()[$key]['f'] ?? [] as $f) {
            foreach ($f['r'] ?? [] as $flat) {
                $rings[] = sfw_catchment_ring($flat);
            }
        }
        $done[$key] = $rings;
    }
    return $done[$key];
}

/**
 * Unpack one ring of the packed format: [x0, y0, dx1, dy1, ...] in 1/100000 degree.
 *
 * @param array<int, int> $flat
 * @return array<int, array{0: float, 1: float}> Points as [lat, lon].
 */
function sfw_catchment_ring(array $flat): array
{
    $ring = [];
    $x = 0;
    $y = 0;
    $n = count($flat);
    for ($i = 0; $i + 1 < $n; $i += 2) {
        $x += (int) $flat[$i];
        $y += (int) $flat[$i + 1];
        $ring[] = [$y / 100000, $x / 100000];
    }
    return $ring;
}

/**
 * True if a point is inside the rings (even-odd rule, so holes work).
 *
 * @param array<int, array<int, array{0: float, 1: float}>> $rings
 */
function sfw_rings_contain(array $rings, float $lat, float $lon): bool
{
    $inside = false;
    foreach ($rings as $ring) {
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = $ring[$i];
            [$yj, $xj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }
    }
    return $inside;
}

/**
 * The keys of the live catchments (set up in full), in the order the settings give them.
 *
 * @param array<string, mixed> $config
 * @return array<int, string>
 */
function sfw_catchments_live(array $config): array
{
    return array_map('strval', array_keys($config['catchments']['live'] ?? ['brisbane' => []]));
}

/**
 * Which live catchment a point is in, or null if none (or the outlines aren't downloaded yet).
 *
 * @param array<string, mixed> $config
 * @param array<int, string>|null $only Limit the answer to these catchments.
 */
function sfw_catchment_at(array $config, float $lat, float $lon, ?array $only = null): ?string
{
    $all = sfw_catchments_load();
    foreach ($only ?? sfw_catchments_live($config) as $key) {
        $c = $all[$key] ?? null;
        if ($c === null || !is_array($c['bbox'])) {
            continue;
        }
        [$s, $w, $n, $e] = $c['bbox'];
        if ($lat >= $s && $lat <= $n && $lon >= $w && $lon <= $e && sfw_rings_contain(sfw_catchment_rings($key), $lat, $lon)) {
            return $key;
        }
    }
    return null;
}

/**
 * The area the live catchments cover together, as [west, south, east, north] (the order the
 * traffic and community rain settings use), or $fallback until the outlines are downloaded.
 *
 * @param array<string, mixed> $config
 * @param float[] $fallback
 * @return float[]
 */
function sfw_catchments_extent(array $config, array $fallback): array
{
    $all = sfw_catchments_load();
    $box = null;
    foreach (sfw_catchments_live($config) as $key) {
        $b = $all[$key]['bbox'] ?? null;
        if (!is_array($b)) {
            continue;
        }
        $box = $box === null ? [$b[1], $b[0], $b[3], $b[2]]
            : [min($box[0], $b[1]), min($box[1], $b[0]), max($box[2], $b[3]), max($box[3], $b[2])];
    }
    if ($box === null) {
        return $fallback;
    }
    // Keep the original area too (it covers Brisbane's bayside suburbs outside basin 143).
    return [min($box[0], $fallback[0]), min($box[1], $fallback[1]), max($box[2], $fallback[2]), max($box[3], $fallback[3])];
}

/**
 * The live catchments for the page (sensors.json): key, name and box, in settings order.
 *
 * @param array<string, mixed> $config
 * @return array<int, array<string, mixed>>
 */
function sfw_catchments_payload(array $config): array
{
    $all = sfw_catchments_load();
    $out = [];
    foreach ($config['catchments']['live'] ?? [] as $key => $c) {
        $out[] = [
            'key'  => (string) $key,
            'name' => (string) ($c['name'] ?? $key),
            'bbox' => $all[$key]['bbox'] ?? ($c['bbox'] ?? null),
        ];
    }
    return $out;
}
