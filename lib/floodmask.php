<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * The "flood-prone mask": every flood map layer (floodplain, council flood areas, past flood
 * extents) merged into one grid of small squares, each either "can flood" or not.
 *
 * Used to keep warnings and predictions to the land that can actually flood:
 *   - an alert's area (its box) is shrunk to the flood-prone land inside it;
 *   - a saved notification area is only told about an alert if the two overlap on flood-prone land;
 *   - the map draws only the flood-prone squares inside an alert's area.
 *
 * Built by bin/build_floodmask.php (run at the end of bin/fetch_overlays.php) into
 * data/floodmask.json: {"cell", "w", "s", "cols", "rows", "bits", "built"}. "bits" is base64; square
 * (row r, column c) is bit (r * cols + c), lowest bit first in each byte. Row 0 is the south edge.
 * A square counts as flood-prone if its centre is inside a flood shape or a shape's edge passes
 * through it, so thin creek lines are kept.
 */

declare(strict_types=1);

/**
 * Build the mask from packed flood map shapes (lib/arcgis.php format).
 *
 * @param array<int, array<string, mixed>> $features Each {"b": [w,s,e,n], "r": [packed rings]}.
 * @param float $cell Square size in degrees (0.002 is about 200 m).
 * @return array<string, mixed>|null The mask, or null if there are no shapes.
 */
function sfw_floodmask_build(array $features, float $cell): ?array
{
    if (!$features) {
        return null;
    }
    // Work in whole numbers of 1/100000 degree (as the shapes are stored), doubled so a row's
    // centre line is a whole number too. No rounding, so a corner lying exactly on a row's centre
    // line is counted correctly (getting that wrong drew long stripes across the map).
    $size = (int) round($cell * 1e5);   // Square size, 1/100000 degree.
    $w = PHP_INT_MAX;
    $s = PHP_INT_MAX;
    $e = PHP_INT_MIN;
    $n = PHP_INT_MIN;
    foreach ($features as $f) {
        $w = min($w, (int) $f['b'][0]);
        $s = min($s, (int) $f['b'][1]);
        $e = max($e, (int) $f['b'][2]);
        $n = max($n, (int) $f['b'][3]);
    }
    $w -= (($w % $size) + $size) % $size; // Round down to a whole square.
    $s -= (($s % $size) + $size) % $size;
    $cols = intdiv($e - $w, $size) + 1;
    $rows = intdiv($n - $s, $size) + 1;
    $bits = str_repeat("\0", intdiv($cols * $rows + 7, 8));
    $set = static function (int $r, int $c) use (&$bits, $cols, $rows): void {
        if ($r < 0 || $c < 0 || $r >= $rows || $c >= $cols) {
            return;
        }
        $i = $r * $cols + $c;
        $bits[$i >> 3] = chr(ord($bits[$i >> 3]) | (1 << ($i & 7)));
    };
    // Smallest whole number >= a / b, for b > 0.
    $ceildiv = static function (int $a, int $b): int {
        $q = intdiv($a, $b);
        return $q * $b < $a ? $q + 1 : $q;
    };
    $s2 = 2 * $s;
    $size2 = 2 * $size;

    foreach ($features as $f) {
        // Where each ring's edges cross the centre line of each row of squares.
        $cross = [];
        foreach ($f['r'] as $flat) {
            $pts = [];
            $x = 0;
            $y = 0;
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $x += (int) $flat[$i];
                $y += (int) $flat[$i + 1];
                $pts[] = [$x, $y];
            }
            $count = count($pts);
            for ($i = 0; $i < $count; $i++) {
                [$x1, $y1] = $pts[$i];
                [$x2, $y2] = $pts[($i + 1) % $count];
                // The squares the edge passes through (sampled at half-square steps).
                $steps = max(1, $ceildiv(2 * max(abs($x2 - $x1), abs($y2 - $y1)), $size));
                for ($k = 0; $k <= $steps; $k++) {
                    $set(
                        (int) floor(($y1 + ($y2 - $y1) * $k / $steps - $s) / $size),
                        (int) floor(($x1 + ($x2 - $x1) * $k / $steps - $w) / $size)
                    );
                }
                if ($y1 === $y2) {
                    continue;
                }
                // Rows whose centre line (doubled: s2 + (2r + 1) * size) is in [lo, hi), so a
                // corner on a centre line is counted once.
                $lo = 2 * min($y1, $y2);
                $hi = 2 * max($y1, $y2);
                $r0 = $ceildiv($lo - $s2 - $size, $size2);
                $r1 = $ceildiv($hi - $s2 - $size, $size2) - 1;
                for ($r = $r0; $r <= $r1; $r++) {
                    $yc2 = $s2 + (2 * $r + 1) * $size;
                    $cross[$r][] = $x1 + ($yc2 / 2 - $y1) * ($x2 - $x1) / ($y2 - $y1);
                }
            }
        }
        // Fill between pairs of crossings (even-odd, so holes stay empty).
        foreach ($cross as $r => $xs) {
            sort($xs);
            for ($i = 0; $i + 1 < count($xs); $i += 2) {
                $c0 = (int) ceil(($xs[$i] - $w) / $size - 0.5);
                $c1 = (int) floor(($xs[$i + 1] - $w) / $size - 0.5);
                for ($c = $c0; $c <= $c1; $c++) {
                    $set($r, $c);
                }
            }
        }
    }

    return [
        'cell'  => $size / 1e5,
        'w'     => $w / 1e5,
        's'     => $s / 1e5,
        'cols'  => $cols,
        'rows'  => $rows,
        'bits'  => base64_encode($bits),
        'built' => time(),
    ];
}

/**
 * Read the mask file, or null if it hasn't been built yet (everything then works as before).
 *
 * @return array<string, mixed>|null The mask, with 'raw' holding the decoded bits.
 */
function sfw_floodmask_load(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $mask = json_decode((string) file_get_contents($file), true);
    if (!is_array($mask) || !isset($mask['bits'], $mask['cols'], $mask['rows'], $mask['cell'])) {
        return null;
    }
    $mask['raw'] = base64_decode((string) $mask['bits'], true);
    return $mask['raw'] === false ? null : $mask;
}

/**
 * The rows and columns of the mask inside a box, or null if the box misses the mask.
 *
 * @param array<string, mixed> $mask
 * @param array<int, float> $box [south, west, north, east]
 * @return array<int, int>|null [first row, last row, first column, last column]
 */
function sfw_floodmask_range(array $mask, array $box): ?array
{
    $cell = (float) $mask['cell'];
    $r0 = max(0, (int) floor(($box[0] - $mask['s']) / $cell));
    $r1 = min($mask['rows'] - 1, (int) floor(($box[2] - $mask['s']) / $cell));
    $c0 = max(0, (int) floor(($box[1] - $mask['w']) / $cell));
    $c1 = min($mask['cols'] - 1, (int) floor(($box[3] - $mask['w']) / $cell));
    return ($r0 > $r1 || $c0 > $c1) ? null : [$r0, $r1, $c0, $c1];
}

/**
 * True if the square at (row, column) can flood.
 *
 * @param array<string, mixed> $mask
 */
function sfw_floodmask_at(array $mask, int $r, int $c): bool
{
    $i = $r * $mask['cols'] + $c;
    return (ord($mask['raw'][$i >> 3]) & (1 << ($i & 7))) !== 0;
}

/**
 * True if any flood-prone land lies inside the box.
 *
 * @param array<string, mixed> $mask
 * @param array<int, float> $box [south, west, north, east]
 */
function sfw_floodmask_any(array $mask, array $box): bool
{
    $range = sfw_floodmask_range($mask, $box);
    if ($range === null) {
        return false;
    }
    [$r0, $r1, $c0, $c1] = $range;
    for ($r = $r0; $r <= $r1; $r++) {
        for ($c = $c0; $c <= $c1; $c++) {
            if (sfw_floodmask_at($mask, $r, $c)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Shrink a box to the outer edges of the flood-prone land inside it.
 *
 * @param array<string, mixed> $mask
 * @param array<int, float> $box [south, west, north, east]
 * @return array<int, float>|null The smaller box, or null if no flood-prone land is inside.
 */
function sfw_floodmask_tighten(array $mask, array $box): ?array
{
    $range = sfw_floodmask_range($mask, $box);
    if ($range === null) {
        return null;
    }
    [$r0, $r1, $c0, $c1] = $range;
    $found = [PHP_INT_MAX, -1, PHP_INT_MAX, -1];
    for ($r = $r0; $r <= $r1; $r++) {
        for ($c = $c0; $c <= $c1; $c++) {
            if (sfw_floodmask_at($mask, $r, $c)) {
                $found = [min($found[0], $r), max($found[1], $r), min($found[2], $c), max($found[3], $c)];
            }
        }
    }
    if ($found[1] < 0) {
        return null;
    }
    $cell = (float) $mask['cell'];
    return [
        round(max($box[0], $mask['s'] + $found[0] * $cell), 4),
        round(max($box[1], $mask['w'] + $found[2] * $cell), 4),
        round(min($box[2], $mask['s'] + ($found[1] + 1) * $cell), 4),
        round(min($box[3], $mask['w'] + ($found[3] + 1) * $cell), 4),
    ];
}

/**
 * The flood-prone land inside a box that is joined to some starting points (for example the
 * gauges a warning or prediction is about), so separate creeks and rivers that happen to fall in
 * the same box are left out. Squares touching at a corner count as joined.
 *
 * @param array<string, mixed> $mask
 * @param array<int, float> $box [south, west, north, east]
 * @param array<int, array<int, float>> $seeds Starting points, [lat, lon] each. A point that isn't
 *     on flood-prone land starts from the nearest flood-prone square within $snap squares.
 * @return array<string, mixed>|null {"cell", "w", "s", "runs": [[row, first column, last column], ...],
 *     "bbox": [S, W, N, E]}, or null if no starting point is near flood-prone land.
 */
function sfw_floodmask_region(array $mask, array $box, array $seeds, int $snap = 3): ?array
{
    $range = sfw_floodmask_range($mask, $box);
    if ($range === null) {
        return null;
    }
    [$r0, $r1, $c0, $c1] = $range;
    $cell = (float) $mask['cell'];
    $cols = (int) $mask['cols'];
    $seen = [];
    $queue = [];
    foreach ($seeds as [$lat, $lon]) {
        $sr = (int) floor(($lat - $mask['s']) / $cell);
        $sc = (int) floor(($lon - $mask['w']) / $cell);
        // The nearest flood-prone square inside the box.
        $best = null;
        for ($dr = -$snap; $dr <= $snap; $dr++) {
            for ($dc = -$snap; $dc <= $snap; $dc++) {
                $r = $sr + $dr;
                $c = $sc + $dc;
                if ($r < $r0 || $r > $r1 || $c < $c0 || $c > $c1 || !sfw_floodmask_at($mask, $r, $c)) {
                    continue;
                }
                $d = $dr * $dr + $dc * $dc;
                if ($best === null || $d < $best[0]) {
                    $best = [$d, $r, $c];
                }
            }
        }
        if ($best !== null && !isset($seen[$best[1] * $cols + $best[2]])) {
            $seen[$best[1] * $cols + $best[2]] = true;
            $queue[] = [$best[1], $best[2]];
        }
    }
    if (!$queue) {
        return null;
    }
    while ($queue) {
        [$r, $c] = array_pop($queue);
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                $nr = $r + $dr;
                $nc = $c + $dc;
                if ($nr < $r0 || $nr > $r1 || $nc < $c0 || $nc > $c1 || isset($seen[$nr * $cols + $nc])
                    || !sfw_floodmask_at($mask, $nr, $nc)) {
                    continue;
                }
                $seen[$nr * $cols + $nc] = true;
                $queue[] = [$nr, $nc];
            }
        }
    }

    // Join the squares into runs along each row.
    $byrow = [];
    foreach (array_keys($seen) as $i) {
        $byrow[intdiv($i, $cols)][] = $i % $cols;
    }
    ksort($byrow);
    $runs = [];
    $ext = [PHP_INT_MAX, -1, PHP_INT_MAX, -1];
    foreach ($byrow as $r => $list) {
        sort($list);
        $start = $list[0];
        $prev = $list[0];
        foreach (array_slice($list, 1) as $c) {
            if ($c !== $prev + 1) {
                $runs[] = [$r, $start, $prev];
                $start = $c;
            }
            $prev = $c;
        }
        $runs[] = [$r, $start, $prev];
        $ext = [min($ext[0], $r), max($ext[1], $r), min($ext[2], $list[0]), max($ext[3], $prev)];
    }
    return [
        'cell' => $cell,
        'w'    => (float) $mask['w'],
        's'    => (float) $mask['s'],
        'runs' => $runs,
        'bbox' => [
            round($mask['s'] + $ext[0] * $cell, 4),
            round($mask['w'] + $ext[2] * $cell, 4),
            round($mask['s'] + ($ext[1] + 1) * $cell, 4),
            round($mask['w'] + ($ext[3] + 1) * $cell, 4),
        ],
    ];
}

/**
 * True if any square of an area (from sfw_floodmask_region) lies inside the box.
 *
 * @param array<string, mixed> $area
 * @param array<int, float> $box [south, west, north, east]
 */
function sfw_floodmask_area_hits(array $area, array $box): bool
{
    $cell = (float) $area['cell'];
    $r0 = (int) floor(($box[0] - $area['s']) / $cell);
    $r1 = (int) floor(($box[2] - $area['s']) / $cell);
    $c0 = (int) floor(($box[1] - $area['w']) / $cell);
    $c1 = (int) floor(($box[3] - $area['w']) / $cell);
    foreach ($area['runs'] as [$r, $a, $b]) {
        if ($r >= $r0 && $r <= $r1 && $a <= $c1 && $b >= $c0) {
            return true;
        }
    }
    return false;
}
