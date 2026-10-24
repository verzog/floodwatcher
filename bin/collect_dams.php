<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Read Seqwater's dam levels page and dam release update page, and save what they say about the
 * catchment's dams for bin/build_json.php (lib/dams.php).
 *
 * Usage: php bin/collect_dams.php            (cron runs it every 30 minutes)
 *        php bin/collect_dams.php <file.html> [<release-file.html>]   (read saved pages, for testing)
 *
 * Saves var/seqwater.json:
 *   {"fetched": <time>, "dams": {"wivenhoe": {"pct", "flood_storage_pct", "observed", "comment"}, ...},
 *    "releases": {"fetched": <time>, "updated": "...", "dams": ["Wivenhoe", ...]}}
 *
 * Seqwater publishes no data feed, so this reads the web pages. If the pages change layout and
 * nothing can be read, the previous figures are kept and an error is logged; the map then goes
 * by BoM's lake gauges alone.
 * Data: © Seqwater, https://www.seqwater.com.au
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/http.php';
require dirname(__DIR__) . '/lib/store.php';

$config = sfw_config();
$cfg = $config['dams'];
$lock = sfw_lock('collect_dams');

/**
 * The visible text of an element, with spaces tidied.
 */
function sfw_dams_text(DOMNode $node): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
}

/**
 * Parse the dam levels page: one entry per dam we know, keyed by lower-case Seqwater name.
 *
 * The page has a main table (Dam | Full supply volume | Current volume | % Full | Latest
 * observation | Comment) and, for Wivenhoe and Somerset, a flood storage table (Dam | Total
 * Flood Storage Volume | Percentage of Flood Storage Volume in use | ... available). Columns are
 * found by their headings. A row belongs to a dam only if it starts with the dam's name (the
 * Somerset row's note mentions Wivenhoe).
 *
 * @param array<int, string> $names The Seqwater names of our dams.
 * @return array<string, array<string, mixed>>
 */
function sfw_dams_parse_levels(string $html, array $names): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $found = [];
    foreach ($xp->query('//table') ?: [] as $table) {
        $cols = null;
        foreach ($xp->query('.//tr', $table) ?: [] as $tr) {
            $cells = [];
            foreach ($xp->query('./th|./td', $tr) ?: [] as $cell) {
                $cells[] = sfw_dams_text($cell);
            }
            if ($cols === null) {
                // The heading row: which column holds what.
                $cols = [];
                foreach ($cells as $i => $h) {
                    $h = strtolower($h);
                    $kind = match (true) {
                        str_contains($h, 'in use')               => 'flood_storage_pct',
                        str_contains($h, '% full')               => 'pct',
                        str_contains($h, 'latest observation')   => 'observed',
                        $h === 'comment' || str_contains($h, 'comment') => 'comment',
                        default                                  => null,
                    };
                    if ($kind !== null) {
                        $cols[$kind] = $i;
                    }
                }
                continue;
            }
            if (!$cols || $cells === []) {
                continue;
            }
            foreach ($names as $name) {
                if (!preg_match('/^' . preg_quote($name, '/') . '\b/i', $cells[0])) {
                    continue;
                }
                $key = strtolower($name);
                $entry = $found[$key] ?? ['pct' => null, 'flood_storage_pct' => null, 'observed' => null, 'comment' => null];
                foreach ($cols as $kind => $i) {
                    $c = $cells[$i] ?? '';
                    if ($entry[$kind] !== null || $c === '') {
                        continue;
                    }
                    if ($kind === 'pct' || $kind === 'flood_storage_pct') {
                        if (preg_match('/^(\d{1,3}(?:\.\d+)?)\s*%$/', $c, $m)) {
                            $entry[$kind] = (float) $m[1];
                        }
                    } else {
                        $entry[$kind] = mb_substr($c, 0, 200);
                    }
                }
                $found[$key] = $entry;
            }
        }
    }
    return array_filter($found, static fn(array $e): bool => $e['pct'] !== null);
}

/**
 * Parse the dam release update page.
 *
 * Each gated dam has a statement ("Wivenhoe Dam - Flood releases from Wivenhoe Dam are not
 * expected.", "North Pine Dam - Flood releases began at 11pm ... and will continue today.",
 * "Somerset Dam - Operational releases from Somerset into Wivenhoe Dam began ..."), followed by
 * lists of ungated dams spilling or with increased outflows.
 *
 * @param array<int, string> $names
 * @return array{updated: string|null, dams: array<int, string>, operational: array<int, string>,
 *     spilling: array<int, string>}
 */
function sfw_dams_parse_releases(string $html, array $names): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//script|//style') ?: [] as $junk) {
        $junk->parentNode?->removeChild($junk);
    }
    $text = sfw_dams_text($xp->query('//body')->item(0) ?? $doc);
    $updated = null;
    if (preg_match('/latest update:\s*(.{6,60}?\d{4})/i', $text, $m)) {
        $updated = trim($m[1]);
    } elseif (preg_match('#last updated[:\s-]*(\d{1,2}/\d{1,2}/\d{4}[\s-]*\d{1,2}:\d{2})#i', $text, $m)) {
        $updated = trim($m[1]);
    }
    $flood = [];
    $operational = [];
    $spilling = [];
    $ended = '/\b(not expected|ceased|ended|stopped|finished|no longer|not releasing|completed|have concluded)\b/i';
    foreach ($names as $name) {
        // The dam's own statement: from "<Name> Dam - " up to the next "<Something> Dam - " or list.
        $next = '(?=\b[A-Z][A-Za-z ()]{2,40} Dam\s+[-–]\s|UNGATED DAMS|$)';
        if (preg_match('/\b' . preg_quote($name, '/') . ' Dam\s+[-–]\s+(.*?)' . $next . '/u', $text, $m)) {
            $say = $m[1];
            if (!preg_match($ended, $say)) {
                if (preg_match('/flood releases?/i', $say)) {
                    $flood[$name] = true;
                } elseif (preg_match('/releases?/i', $say)) {
                    $operational[$name] = true;
                }
            }
        }
        // The ungated dams' lists ("... are spilling excess water: A Dam B Dam ...").
        $lists = [];
        $listre = '/(?:spilling excess water|outflows have increased from the following dams):\s*([^.]*)/i';
        if (preg_match_all($listre, $text, $lists)) {
            foreach ($lists[1] as $list) {
                if (preg_match('/\b' . preg_quote($name, '/') . ' Dam\b/i', $list)) {
                    $spilling[$name] = true;
                }
            }
        }
    }
    return ['updated' => $updated, 'dams' => array_keys($flood), 'operational' => array_keys($operational),
        'spilling' => array_keys($spilling)];
}

$names = array_map(static fn(array $d): string => $d['seqwater'], $cfg['list']);
$runid = sfw_run_start('dams');
$state = sfw_state_read('seqwater');
try {
    $levels = isset($argv[1]) ? (string) file_get_contents($argv[1]) : sfw_fetch_raw($cfg['seqwater_url']);
    $dams = sfw_dams_parse_levels($levels, $names);
    if (!$dams) {
        throw new RuntimeException('No dam levels found on the Seqwater page (has its layout changed?).');
    }
    $state['fetched'] = time();
    $state['dams'] = $dams;
    $message = sprintf('%d of %d dams read', count($dams), count($names));

    try {
        $rel = isset($argv[2]) ? (string) file_get_contents($argv[2])
            : (isset($argv[1]) ? '' : sfw_fetch_raw($cfg['releases_url']));
        if ($rel !== '') {
            $state['releases'] = ['fetched' => time()] + sfw_dams_parse_releases($rel, $names);
            $r = $state['releases'];
            $message .= '; flood releases: ' . ($r['dams'] ? implode(', ', $r['dams']) : 'none')
                . ($r['operational'] ? '; operational releases: ' . implode(', ', $r['operational']) : '')
                . ($r['spilling'] ? '; spilling: ' . implode(', ', $r['spilling']) : '');
        }
    } catch (Throwable $e) {
        $message .= '; release page unavailable (' . $e->getMessage() . ')';
    }
    sfw_state_write('seqwater', $state);
    sfw_run_finish($runid, 'ok', count($dams), 0, null, $message);
    sfw_log('Seqwater dams: ' . $message . '.');
} catch (Throwable $e) {
    sfw_run_finish($runid, 'error', 0, 0, null, $e->getMessage());
    sfw_fail('Seqwater dams failed: ' . $e->getMessage());
}
