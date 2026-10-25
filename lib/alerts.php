<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Work out the current gauge alerts from the dashboard markers, remembering when each began so
 * an alert is raised once (not on every run), and noting an "all clear" when one ends.
 *
 * Alert kinds, for river and creek gauges:
 *   flood   at or above a BoM flood level (level: minor, moderate or major)
 *   near    rising and within 'near_minor_m' of the minor flood level (level: watch)
 *   rising  rising fast (level: watch); never for fully tidal gauges, which do that every tide
 *   potential, potential_rain   flood potential rules (lib/potential.php): likely, or watch
 *
 * A gauge that stops reporting keeps its alert, marked as not reporting, rather than being
 * called all clear: silence is not good news.
 *
 * A gauge whose level has just jumped implausibly ('spike', see sfw_spike_check in
 * build_json.php) has no alert, and an alert it already had is dropped without an "all clear":
 * there was never a flood to clear.
 */

declare(strict_types=1);

/** Alert levels, lowest first. */
const SFW_ALERT_LEVELS = ['watch', 'likely', 'minor', 'moderate', 'major'];

/**
 * Format a height for alert text.
 */
function sfw_alert_m(float $value): string
{
    return number_format($value, 2) . ' m';
}

/**
 * The alert (if any) for one marker right now.
 *
 * @param array<string, mixed> $m Marker from build_json.php.
 * @param array<string, mixed> $opts The 'alerts' settings.
 * @return array<string, mixed>|null
 */
function sfw_alert_for(array $m, array $opts): ?array
{
    if (($m['type'] ?? '') === 'potential') {
        return $m['potential'] ?? null;
    }
    if (($m['type'] ?? '') !== 'stream' || !isset($m['latest'])) {
        return null;
    }
    $value = (float) $m['latest'];
    $flood = $m['flood'] ?? null;
    $trend = (string) ($m['trend'] ?? 'unknown');
    $rising = $trend === 'rising' || $trend === 'rising_fast';
    $class = (string) ($m['flood_class'] ?? '');

    if (in_array($class, ['minor', 'moderate', 'major'], true)) {
        $text = sprintf('%s, at or above the %s flood level of %s', sfw_alert_m($value), $class, sfw_alert_m((float) $flood[$class]));
        if ($rising) {
            $text .= ' and rising';
        }
        return ['kind' => 'flood', 'level' => $class, 'text' => $text];
    }
    $near = (float) $opts['near_minor_m'];
    if (is_array($flood) && isset($flood['minor']) && $rising && $value >= (float) $flood['minor'] - $near) {
        return [
            'kind'  => 'near',
            'level' => 'watch',
            'text'  => sprintf('%s and rising, %s below the minor flood level', sfw_alert_m($value),
                sfw_alert_m(max(0.0, (float) $flood['minor'] - $value))),
        ];
    }
    if (!empty($opts['rising_fast']) && $trend === 'rising_fast' && ($m['tidal'] ?? '') !== 'yes') {
        return [
            'kind'  => 'rising',
            'level' => 'watch',
            'text'  => sprintf('%s and rising fast (%s an hour)', sfw_alert_m($value), sfw_alert_m(abs((float) ($m['rate_per_hr'] ?? 0)))),
        ];
    }
    return null;
}

/**
 * Compare this run's alerts with the last run's.
 *
 * @param array<int, array<string, mixed>> $markers Dashboard markers.
 * @param array<string, mixed> $state Saved state from the last run.
 * @param array<string, mixed> $opts The 'alerts' settings.
 * @return array{alerts: array<int, array<string, mixed>>, cleared: array<int, array<string, mixed>>, state: array<string, mixed>}
 */
function sfw_alerts_update(array $markers, array $state, int $now, array $opts): array
{
    $active = $state['active'] ?? [];   // Keyed by marker id.
    $cleared = $state['cleared'] ?? [];
    $byid = [];
    foreach ($markers as $m) {
        $byid[$m['id']] = $m;
    }

    $next = [];
    $doubted = []; // Gauges with an unconfirmed jump: their alerts end without an all clear.
    foreach ($markers as $m) {
        if (!empty($m['spike'])) {
            $doubted[$m['id']] = true;
            continue;
        }
        $quiet = !empty($m['stale']) || !empty($m['note']);
        $prev = $active[$m['id']] ?? null;
        $found = $quiet ? null : sfw_alert_for($m, $opts);
        if ($found === null && $quiet && $prev !== null) {
            // Not reporting: keep the old alert, flagged, instead of announcing an all clear.
            $prev['not_reporting'] = true;
            $next[$m['id']] = $prev;
            continue;
        }
        if ($found === null) {
            continue;
        }
        $rank = array_search($found['level'], SFW_ALERT_LEVELS, true);
        $prevrank = $prev ? array_search($prev['level'], SFW_ALERT_LEVELS, true) : -1;
        $next[$m['id']] = [
            'id'            => $m['id'],
            'name'          => $m['name'],
            'kind'          => $found['kind'],
            'level'         => $found['level'],
            'text'          => $found['text'],
            'since'         => $prev['since'] ?? $now,
            // When the alert last got worse (a new or higher level): what push and email will send.
            'changed'       => ($prev === null || $rank > $prevrank) ? $now : ($prev['changed'] ?? $now),
            'peak_level'    => $prev && $prevrank > $rank ? $prev['peak_level'] ?? $prev['level'] : $found['level'],
            'value'         => (float) ($m['latest'] ?? 0),
            'lat'           => (float) $m['lat'],
            'lon'           => (float) $m['lon'],
            'bbox'          => $m['bbox'] ?? null, // Flood potential rules: the area they cover.
            'gauges'        => $m['gauges'] ?? null, // Flood potential rules: their trigger gauges.
            'area'          => $m['area'] ?? null,   // Flood potential rules: the flood-prone land they cover.
            'catchment'     => (string) ($m['catchment'] ?? 'brisbane'),
            'not_reporting' => false,
        ];
    }

    // Alerts that have ended become "all clear" notes for a few hours.
    foreach ($active as $id => $prev) {
        if (!isset($next[$id]) && !isset($doubted[$id])) {
            $cleared[] = [
                'id'    => $id,
                'name'  => $prev['name'],
                'catchment' => (string) ($prev['catchment'] ?? 'brisbane'),
                'at'    => $now,
                'text'  => match ($prev['kind']) {
                    'flood'          => 'back below the minor flood level', // No alert left means below minor.
                    'near'           => 'no longer rising towards the minor flood level',
                    'potential'      => 'no longer expected: upstream gauges are below their trigger levels',
                    'potential_rain' => 'the heavy rain has eased',
                    default          => 'no longer rising fast',
                },
            ];
        }
    }
    $keep = $now - 3600 * (int) $opts['cleared_hours'];
    $cleared = array_values(array_filter($cleared, static fn(array $c): bool =>
        $c['at'] >= $keep && !isset($next[$c['id']]) && isset($byid[$c['id']])));

    // Worst first, then the most recent change.
    $alerts = array_values($next);
    usort($alerts, static fn(array $a, array $b): int =>
        [array_search($b['level'], SFW_ALERT_LEVELS, true), $b['changed']]
        <=> [array_search($a['level'], SFW_ALERT_LEVELS, true), $a['changed']]);

    return [
        'alerts'  => $alerts,
        'cleared' => $cleared,
        'state'   => ['active' => $next, 'cleared' => $cleared],
    ];
}
