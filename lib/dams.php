<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * The state of each dam in the catchment, for the map and the "water coming here" score.
 *
 * Put together from:
 *   - Seqwater's dam levels page (saved by bin/collect_dams.php): % full, flood storage in use,
 *     spilling, and Seqwater's notices of flood releases;
 *   - BoM's gauge on the lake (live level and trend), compared with full supply level;
 *   - BoM's gauge just below the dam, where there is one: a quick rise there shows water is
 *     being let out, even before Seqwater's notice.
 *
 * States, mildest first (coloured like the legend on the map):
 *   normal         below full
 *   full           full or spilling over (ungated dams spill on their own)
 *   flood_storage  holding flood water above full supply level (Wivenhoe and Somerset)
 *   releasing      flood releases from the dam
 * and 'unknown' when there's nothing recent to go by.
 */

declare(strict_types=1);

/** Dam states, mildest first. */
const SFW_DAM_STATES = ['unknown', 'normal', 'full', 'flood_storage', 'releasing'];

/**
 * How much a gauge has risen over the last few hours: latest reading less the lowest since.
 *
 * @param array<int, array{0: int, 1: float}> $points Oldest first.
 */
function sfw_dam_rise(array $points, int $now, int $hours): ?float
{
    $low = null;
    $last = null;
    foreach ($points as [$t, $v]) {
        if ($t >= $now - 3600 * $hours && $t <= $now) {
            $low = $low === null ? $v : min($low, $v);
            $last = $v;
        }
    }
    return ($low === null || $last === null) ? null : $last - $low;
}

/**
 * The state of each configured dam.
 *
 * @param array<string, mixed> $cfg The 'dams' settings.
 * @param array<string, array<string, mixed>> $items Per-gauge summaries from build_json.php.
 * @param array<string, array<int, array{0: int, 1: float}>> $byid Recent readings per gauge.
 * @param array<string, mixed> $seq Saved Seqwater figures (var/seqwater.json), or [].
 * @return array<int, array<string, mixed>>
 */
function sfw_dams_status(array $cfg, array $items, array $byid, array $seq, int $now): array
{
    $fresh = static fn($t): bool => is_int($t) && $t >= $now - 3600 * (int) $cfg['stale_hours'];
    // Seqwater's release update: flood releases, operational releases (e.g. Somerset into Lake
    // Wivenhoe, not downstream), and ungated dams spilling.
    $releasing = [];
    $operational = [];
    $spilling = [];
    if ($fresh($seq['releases']['fetched'] ?? null)) {
        foreach (['dams' => &$releasing, 'operational' => &$operational, 'spilling' => &$spilling] as $field => &$set) {
            foreach ($seq['releases'][$field] ?? [] as $name) {
                $set[strtolower((string) $name)] = true;
            }
        }
        unset($set);
    }
    $out = [];
    foreach ($cfg['list'] as $d) {
        $why = [];
        $state = 'unknown';
        $raise = static function (string $s) use (&$state): void {
            if (array_search($s, SFW_DAM_STATES, true) > array_search($state, SFW_DAM_STATES, true)) {
                $state = $s;
            }
        };

        // Seqwater's figures.
        $sq = $seq['dams'][strtolower($d['seqwater'])] ?? null;
        $pct = null;
        $floodpct = null;
        $comment = null;
        if ($sq && $fresh($seq['fetched'] ?? null)) {
            $pct = isset($sq['pct']) ? (float) $sq['pct'] : null;
            $floodpct = isset($sq['flood_storage_pct']) ? (float) $sq['flood_storage_pct'] : null;
            $comment = $sq['comment'] ?? null;
            $c = strtolower((string) $comment);
            if ($pct !== null) {
                $raise('normal');
            }
            if (($pct !== null && $pct >= 100.0) || str_contains($c, 'spill')) {
                $raise('full');
                $why[] = str_contains($c, 'spill') ? 'spilling (Seqwater)' : 'full (Seqwater)';
            }
            if (($floodpct !== null && $floodpct > 0.0) || str_contains($c, 'flood storage')) {
                $raise('flood_storage');
                $why[] = 'holding flood water above full supply level'
                    . ($floodpct !== null && $floodpct > 0
                        ? sprintf(' (%.1f%% of flood storage, Seqwater)', $floodpct) : ' (Seqwater)');
            }
        }
        $sname = strtolower($d['seqwater']);
        if (isset($spilling[$sname])) {
            $raise('full');
            $why[] = 'spilling (Seqwater release update)';
        }
        if (isset($operational[$sname])) {
            $raise('normal');
            $why[] = 'operational releases (Seqwater release update)';
        }
        if (isset($releasing[$sname])) {
            $raise('releasing');
            $why[] = 'flood releases (Seqwater release update)';
        }

        // The lake gauge.
        $lake = $items[$d['level']] ?? null;
        $level = null;
        if ($lake && $lake['latest'] !== null && empty($lake['note']) && (int) $lake['latest_t'] >= $now - 6 * 3600) {
            $level = (float) $lake['latest'];
            $raise('normal');
            if ($d['fsl'] !== null && $level >= (float) $d['fsl'] + 0.05) {
                $raise('flood_storage');
                $why[] = sprintf('lake %.2f m, %.2f m above full supply level (BoM gauge)', $level, $level - (float) $d['fsl']);
            }
        }

        // The gauge below the dam: a quick rise means water is being let out.
        $tailrise = null;
        if (!empty($d['tail'])) {
            $tailrise = sfw_dam_rise($byid[$d['tail']] ?? [], $now, 6);
            if ($tailrise !== null && $tailrise >= (float) $cfg['tail_rise_m']) {
                $raise('releasing');
                $why[] = sprintf('river below the dam up %.2f m in 6 hours (BoM gauge)', $tailrise);
            }
        }

        $out[] = [
            'key'         => $d['key'],
            'name'        => $d['name'],
            'lat'         => (float) $d['lat'],
            'lon'         => (float) $d['lon'],
            'node'        => $d['node'] ?? null,
            'down'        => $d['down'] ?? null,
            'into'        => $d['into'] ?? null,
            'catchment'   => (string) ($d['catchment'] ?? 'brisbane'),
            'state'       => $state,
            'why'         => array_values(array_unique($why)),
            'pct'         => $pct,
            'flood_pct'   => $floodpct,
            'comment'     => $comment,
            'observed'    => $sq['observed'] ?? null,
            'updated'     => $seq['releases']['updated'] ?? null,
            'level'       => $level,
            'level_id'    => $d['level'],
            'level_trend' => $lake['trend'] ?? null,
            'fsl'         => $d['fsl'],
            'tail_id'     => $d['tail'] ?? null,
            'tail_rise'   => $tailrise === null ? null : round($tailrise, 2),
        ];
    }
    return $out;
}
