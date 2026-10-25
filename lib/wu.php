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
 * Whether a community gauge's rain disagrees badly with the official gauges around it, from the
 * gauge's own total $mine and the nearby official totals $near (same period, already chosen by
 * distance). Returns ['level' => 'low'|'high', 'median' => float] for a bad disagreement, or null
 * when it looks fine or there are fewer than two official gauges to judge against. Shared by
 * bin/build_json.php (the daily pre-build check) and www/wu.php (live map lookups) so both judge
 * the same way; each writes its own wording.
 *
 * @param float[] $near
 * @return array{level: string, median: float}|null
 */
function sfw_wu_disagreement(float $mine, array $near, float $checkminmm, float $checklowshare): ?array
{
    if (count($near) < 2) {
        return null;
    }
    sort($near);
    $median = (float) $near[intdiv(count($near), 2)];
    if ($median >= $checkminmm && $mine < $checklowshare * $median) {
        return ['level' => 'low', 'median' => $median];
    }
    if ($mine >= 20.0 && $mine > 3 * $median + 10) {
        return ['level' => 'high', 'median' => $median];
    }
    return null;
}
