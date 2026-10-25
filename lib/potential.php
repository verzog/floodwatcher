<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Flood potential rules worked out from history (see README, "Flood potential rules").
 *
 * Each rule watches upstream gauges: when any reaches its trigger height, flooding downstream
 * is "likely" (with the lead time and track record found in the history). If none has, heavy
 * rain at the rule's rain gauges gives an earlier, less certain "watch". The watch ends once it
 * has been dry for 'dry_hours' with none of the trigger gauges rising: in the history, floods
 * came during the rain or soon after it stopped (at most 21 hours after, at Rifle Range Rd).
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
            $hourly = [];   // Hour => rain at each gauge, for when the rain stopped.
            $latest = 0;    // The rain gauges' newest reading (state gauges lag an hour or two).
            foreach ($r['rain']['ids'] as $id) {
                $pts = $byid[$id] ?? [];
                $it = $items[$id] ?? null;
                if ($pts && $it && empty($it['stale']) && empty($it['note'])) {
                    $sum = 0.0;
                    foreach ($pts as [$t, $v]) {
                        if ($t > $now - 72 * 3600 && $t <= $now) {
                            $sum += $v;
                            $hour = intdiv($t - 1, 3600) * 3600;
                            $hourly[$hour] = ($hourly[$hour] ?? 0.0) + $v;
                            $latest = max($latest, $t);
                        }
                    }
                    $totals[] = $sum;
                }
            }
            $mean = $totals ? array_sum($totals) / count($totals) : 0.0;
            if ($totals && $mean >= (float) $r['rain']['mm_72h']) {
                // How long it has been dry: hours since the last hour with at least 'wet_mm' on
                // average across the gauges.
                $wetmm = (float) ($r['rain']['wet_mm'] ?? 1.0);
                $lastwet = null;
                foreach ($hourly as $hour => $mm) {
                    if ($mm / count($totals) >= $wetmm) {
                        $lastwet = max($lastwet ?? 0, $hour + 3600);
                    }
                }
                $dry = $lastwet === null ? 72.0 : max(0.0, (min($now, $latest) - $lastwet) / 3600);
                // Any trigger gauge rising keeps the watch on, however long it has been dry.
                $rising = [];
                foreach ($r['triggers'] as $t) {
                    foreach ($t['ids'] as $id) {
                        $it = $items[$id] ?? null;
                        if ($it && empty($it['note']) && in_array($it['trend'] ?? '', ['rising', 'rising_fast'], true)) {
                            $rising[$t['label']] = true;
                        }
                    }
                }
                $dryhours = (float) ($r['rain']['dry_hours'] ?? 30);
                if ($dry < $dryhours || $rising) {
                    $after = '';
                    if ($rising) {
                        $after = ' ' . implode(' and ', array_keys($rising)) . (count($rising) > 1 ? ' are' : ' is') . ' rising.';
                    } elseif ($dry >= 3) {
                        $after = sprintf(' The rain stopped about %d hours ago; this watch ends %d hours after the rain '
                            . 'stops if the creeks above stay steady.', (int) round($dry), (int) $dryhours);
                    }
                    $found = [
                        'kind'  => 'potential_rain',
                        'level' => 'watch',
                        'text'  => sprintf('heavy rain in the catchment above %s: %.0f mm in 72 hours (average of %d gauges).%s %s',
                            $r['place'], $mean, count($totals), $after, $r['rain']['record']),
                    ];
                }
            }
        }
        $out[] = [
            'id'        => 'P-' . $r['key'],
            'type'      => 'potential',
            'name'      => ucfirst($r['name']),
            'catchment' => (string) ($r['catchment'] ?? 'brisbane'),
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
