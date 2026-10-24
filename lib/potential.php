<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Flood potential rules worked out from history (see README, "Flood potential rules").
 *
 * Each rule watches upstream gauges: when any reaches its trigger height, flooding downstream
 * is "likely" (with the lead time and track record found in the history). If none has, heavy
 * rain at the rule's rain gauges gives an earlier, less certain "watch".
 *
 * Rules are turned into marker-like entries so the alert code (start times, all clear,
 * notifications, map-area filtering) treats them like gauge alerts.
 */

declare(strict_types=1);

/**
 * The latest usable reading of the first available gauge in a list (fresh within 6 hours).
 *
 * @param string[] $ids
 * @param array<string, array<string, mixed>> $items Per-gauge summaries from build_json.php.
 * @return array{0: float, 1: string}|null [height, gauge id]
 */
function sfw_potential_reading(array $ids, array $items, int $now): ?array
{
    foreach ($ids as $id) {
        $it = $items[$id] ?? null;
        if ($it && $it['latest'] !== null && empty($it['note']) && (int) $it['latest_t'] >= $now - 6 * 3600) {
            return [(float) $it['latest'], $id];
        }
    }
    return null;
}

/**
 * Marker-like entries, one per rule, with 'potential' set when the rule is met.
 *
 * @param array<string, array<string, mixed>> $items
 * @param array<string, array<int, array{0: int, 1: float}>> $byid Recent readings per gauge.
 * @param array<int, array<string, mixed>> $rules The 'potential' settings.
 * @return array<int, array<string, mixed>>
 */
function sfw_potential_markers(array $items, array $byid, int $now, array $rules): array
{
    $out = [];
    foreach ($rules as $r) {
        $found = null;
        $hits = [];
        foreach ($r['triggers'] as $t) {
            $reading = sfw_potential_reading($t['ids'], $items, $now);
            if ($reading !== null && $reading[0] >= (float) $t['m']) {
                $hits[] = sprintf('%s is at %.2f m (trigger %.1f m)', $t['label'], $reading[0], $t['m']);
            }
        }
        if ($hits) {
            $found = [
                'kind'  => 'potential',
                'level' => 'likely',
                'text'  => sprintf('minor flooding likely at %s within %s: %s. %s',
                    $r['place'], $r['lead'], implode('; ', $hits), $r['record']),
            ];
        } elseif (!empty($r['rain'])) {
            $totals = [];
            foreach ($r['rain']['ids'] as $id) {
                $pts = $byid[$id] ?? [];
                $it = $items[$id] ?? null;
                if ($pts && $it && empty($it['stale']) && empty($it['note'])) {
                    $sum = 0.0;
                    foreach ($pts as [$t, $v]) {
                        if ($t > $now - 72 * 3600 && $t <= $now) {
                            $sum += $v;
                        }
                    }
                    $totals[] = $sum;
                }
            }
            if ($totals && array_sum($totals) / count($totals) >= (float) $r['rain']['mm_72h']) {
                $found = [
                    'kind'  => 'potential_rain',
                    'level' => 'watch',
                    'text'  => sprintf('heavy rain in the catchment above %s: %.0f mm in 72 hours (average of %d gauges). %s',
                        $r['place'], array_sum($totals) / count($totals), count($totals), $r['rain']['record']),
                ];
            }
        }
        $out[] = [
            'id'        => 'P-' . $r['key'],
            'type'      => 'potential',
            'name'      => ucfirst($r['name']),
            'lat'       => (float) $r['lat'],
            'lon'       => (float) $r['lon'],
            'bbox'      => $r['bbox'] ?? null,
            // The trigger gauges: the page shows the prediction at gauges downstream of these.
            'gauges'    => array_values(array_merge(
                ...array_map(static fn(array $t): array => $t['ids'], $r['triggers'] ?? [])
            )),
            'latest'    => null,
            'stale'     => false,
            'note'      => null,
            'potential' => $found,
        ];
    }
    return $out;
}
