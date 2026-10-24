<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Shared code for community rain from Weather Underground (unofficial).
 *
 * The free API key allows roughly 1,500 requests a day and 30 a minute, one station per
 * request, so every request goes through a shared allowance (var/wu_budget.json) that both the
 * weekly station check (bin/collect_wu.php) and the map's on-demand lookups (www/wu.php) use.
 */

declare(strict_types=1);

/**
 * GET a Weather Underground API address. Returns null for "no current data" (HTTP 204).
 * The API key is in the address; error messages never include the address.
 *
 * @return array<string, mixed>|null
 */
function wu_get(string $url): ?array
{
    try {
        $body = sfw_http_request('GET', $url, null);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), ' 204 ')) {
            return null;
        }
        if (preg_match('/ (401|403) /', $e->getMessage())) {
            throw new RuntimeException('Weather Underground refused the API key (check it in config.php).');
        }
        throw $e;
    }
    return trim($body) === '' ? null : sfw_decode_json($body);
}

/**
 * Take $n requests from the shared allowance. Returns false (taking nothing) if that would go
 * over the daily or per-minute limit.
 *
 * @param array<string, mixed> $cfg The 'wu' settings.
 */
function wu_budget_take(array $cfg, int $n = 1): bool
{
    $path = sfw_var_dir() . '/wu_budget.json';
    $fh = fopen($path, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        return false;
    }
    $b = json_decode((string) stream_get_contents($fh), true) ?: [];
    $day = gmdate('Y-m-d', time() + 36000); // Brisbane day.
    $minute = intdiv(time(), 60);
    if (($b['day'] ?? '') !== $day) {
        $b['day'] = $day;
        $b['calls'] = 0;
    }
    if (($b['minute'] ?? 0) !== $minute) {
        $b['minute'] = $minute;
        $b['minute_calls'] = 0;
    }
    $ok = $b['calls'] + $n <= (int) $cfg['daily_limit'] && $b['minute_calls'] + $n <= (int) $cfg['per_minute'];
    if ($ok) {
        $b['calls'] += $n;
        $b['minute_calls'] += $n;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($b));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/**
 * Requests left today from the shared allowance.
 *
 * @param array<string, mixed> $cfg
 */
function wu_budget_left(array $cfg): int
{
    $b = json_decode((string) @file_get_contents(sfw_var_dir() . '/wu_budget.json'), true) ?: [];
    $used = ($b['day'] ?? '') === gmdate('Y-m-d', time() + 36000) ? (int) $b['calls'] : 0;
    return max(0, (int) $cfg['daily_limit'] - $used);
}

/**
 * A station's current observation, or null if it isn't reporting rainfall right now (no
 * observation, no rain figure, or nothing in the last hour). Uses one request from the allowance;
 * returns false if the allowance is used up.
 *
 * @param array<string, mixed> $cfg
 * @return array{epoch: int, today: float, rate: float|null}|null|false
 */
function wu_current(array $cfg, string $id): array|null|false
{
    if (!wu_budget_take($cfg)) {
        return false;
    }
    $obs = wu_get(sprintf('%s/v2/pws/observations/current?stationId=%s&format=json&units=m&numericPrecision=decimal&apiKey=%s',
        rtrim((string) $cfg['base_url'], '/'), rawurlencode($id), rawurlencode((string) $cfg['api_key'])));
    $o = $obs['observations'][0] ?? null;
    if (!is_array($o) || !isset($o['epoch'], $o['metric']['precipTotal']) || !is_numeric($o['metric']['precipTotal'])) {
        return null;
    }
    if ((int) $o['epoch'] < time() - 3600) {
        return null; // Last report is over an hour old: not active.
    }
    $rate = $o['metric']['precipRate'] ?? null;
    return [
        'epoch' => (int) $o['epoch'],
        'today' => (float) $o['metric']['precipTotal'],
        'rate'  => is_numeric($rate) ? (float) $rate : null,
    ];
}

/**
 * The catchment outline saved by fetch_overlays.php, as lat, lon rings.
 *
 * @return array<int, array<int, array<int, float>>>
 */
function wu_catchment(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('The catchment outline is missing. Run bin/fetch_overlays.php first, then this again.');
    }
    $packed = json_decode((string) file_get_contents($file), true);
    $rings = [];
    foreach ($packed['f'] ?? [] as $f) {
        foreach ($f['r'] as $flat) {
            $ring = [];
            $x = 0;
            $y = 0;
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $x += $flat[$i];
                $y += $flat[$i + 1];
                $ring[] = [$y / 100000, $x / 100000];
            }
            $rings[] = $ring;
        }
    }
    if (!$rings) {
        throw new RuntimeException('The catchment outline file is empty. Run bin/fetch_overlays.php catchment.');
    }
    return $rings;
}

/**
 * True if a point is inside the catchment outline (even-odd rule).
 *
 * @param array<int, array<int, array<int, float>>> $rings
 */
function wu_in_catchment(float $lat, float $lon, array $rings): bool
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
