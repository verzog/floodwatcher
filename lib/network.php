<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * The river network: which gauge flows into which (data/network.csv) and how long flood peaks
 * take between them (data/travel_times.csv). Published in sensors.json as "network" for the
 * "Flood event upstream" notice on the page:
 *
 *   {"nodes": {"B540143-H": {"ids": ["B540143-H"], "down": "B540156-H", "dam": false}, ...},
 *    "travel": [["B540143-H", "B040817-H", 22, 18, 26], ...]}
 *
 * A node is one spot on a river; its key is the first id listed for it. "down" is the next node
 * downstream; "dam" is true when that downstream node is a dam.
 */

declare(strict_types=1);

/**
 * Read a small CSV file with a header row, skipping blank lines and lines starting with #.
 *
 * @return array<int, array<string, string>>
 */
function sfw_network_csv(string $file): array
{
    $rows = [];
    $head = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        $cells = array_map('trim', str_getcsv($line, ',', '"', ''));
        if ($head === null) {
            $head = $cells;
            continue;
        }
        $rows[] = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), ''));
    }
    return $rows;
}

/**
 * Load the network and travel times.
 *
 * @param array<int, string> $known Marker ids on the map; ids not on the map are dropped.
 * @return array{nodes: array<string, array<string, mixed>>, travel: array<int, array<int, mixed>>, problems: array<int, string>}
 */
function sfw_network_load(string $netfile, string $travelfile, array $known): array
{
    $isknown = array_flip($known);
    $problems = [];
    $nodes = [];
    $keyof = []; // Any id -> its node key.
    $ids = static fn(string $cell): array => array_values(array_filter(array_map('trim', explode('|', $cell)),
        static fn(string $id): bool => preg_match('/^[A-Za-z0-9-]{1,24}$/', $id) === 1));

    $rows = is_file($netfile) ? sfw_network_csv($netfile) : [];
    // First pass: every spot named anywhere, keyed by its first id.
    foreach ($rows as $row) {
        foreach ([$row['id'] ?? '', $row['downstream'] ?? ''] as $cell) {
            $list = $ids($cell);
            if (!$list) {
                continue;
            }
            $key = $list[0];
            foreach ($list as $id) {
                $keyof[$id] ??= $key;
            }
            $nodes[$key] ??= ['ids' => [], 'down' => null, 'dam' => false];
            $nodes[$key]['ids'] = array_values(array_unique(array_merge($nodes[$key]['ids'], $list)));
        }
    }
    // Second pass: the links downstream.
    foreach ($rows as $row) {
        $from = $ids($row['id'] ?? '');
        $to = $ids($row['downstream'] ?? '');
        if (!$from) {
            continue;
        }
        $key = $keyof[$from[0]];
        if ($to) {
            if ($nodes[$key]['down'] !== null && $nodes[$key]['down'] !== $keyof[$to[0]]) {
                $problems[] = "{$key} is given two different downstream gauges";
            }
            $nodes[$key]['down'] = $keyof[$to[0]];
            $nodes[$key]['dam'] = strtolower($row['dam'] ?? '') === 'dam';
        }
    }
    // Stop loops (a gauge that would end up downstream of itself).
    foreach (array_keys($nodes) as $start) {
        $seen = [$start => true];
        for ($at = $nodes[$start]['down']; $at !== null; $at = $nodes[$at]['down'] ?? null) {
            if (isset($seen[$at])) {
                $problems[] = "loop in the network at {$at}";
                $nodes[$start]['down'] = null;
                break;
            }
            $seen[$at] = true;
        }
    }
    // Keep only ids that are on the map (a node with none left still links its neighbours).
    foreach ($nodes as &$n) {
        $n['ids'] = array_values(array_filter($n['ids'], static fn(string $id): bool => isset($isknown[$id])));
    }
    unset($n);

    $travel = [];
    foreach (is_file($travelfile) ? sfw_network_csv($travelfile) : [] as $row) {
        $from = $keyof[$row['from'] ?? ''] ?? null;
        $to = $keyof[$row['to'] ?? ''] ?? null;
        $hours = filter_var($row['hours'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($from === null || $to === null || $hours === false || $hours < 0) {
            $problems[] = 'travel time ' . ($row['from'] ?? '?') . ' to ' . ($row['to'] ?? '?')
                . ' skipped (unknown gauge or bad hours)';
            continue;
        }
        $low = filter_var($row['low'] ?? '', FILTER_VALIDATE_FLOAT);
        $high = filter_var($row['high'] ?? '', FILTER_VALIDATE_FLOAT);
        $travel[] = [$from, $to, (float) $hours, $low === false ? (float) $hours : (float) $low,
            $high === false ? (float) $hours : (float) $high];
    }

    return ['nodes' => $nodes, 'travel' => $travel, 'problems' => $problems];
}
