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
    foreach ($xp->query('//tr') ?: [] as $tr) {
        $cells = [];
        foreach ($xp->query('./th|./td', $tr) ?: [] as $cell) {
            $cells[] = sfw_dams_text($cell);
        }
        if (count($cells) < 3) {
            continue;
        }
        $row = implode(' | ', $cells);
        foreach ($names as $name) {
            $key = strtolower($name);
            if (isset($found[$key]) || stripos($cells[0], $name) === false) {
                continue;
            }
            $entry = ['pct' => null, 'flood_storage_pct' => null, 'observed' => null, 'comment' => null];
            foreach ($cells as $i => $c) {
                if ($i > 0 && $entry['pct'] === null && preg_match('/^(\d{1,3}(?:\.\d+)?)\s*%$/', $c, $m)) {
                    $entry['pct'] = (float) $m[1];
                }
                if ($entry['observed'] === null && preg_match('#\d{1,2}:\d{2}\s*[ap]m\s+\d{1,2}/\d{1,2}/\d{4}#i', $c, $m)) {
                    $entry['observed'] = $m[0];
                }
            }
            if (preg_match('/flood storage[^|%]*?(\d{1,3}(?:\.\d+)?)\s*%/i', $row, $m)
                || preg_match('/(\d{1,3}(?:\.\d+)?)\s*%[^|]*flood storage/i', $row, $m)) {
                $entry['flood_storage_pct'] = (float) $m[1];
            }
            // The comment: the last cell that isn't a number, percentage or time.
            for ($i = count($cells) - 1; $i > 0; $i--) {
                $c = $cells[$i];
                if ($c !== '' && !preg_match('#^[\d.,\s%]+$#', $c) && !preg_match('#^\d{1,2}:\d{2}\s*[ap]m#i', $c)) {
                    $entry['comment'] = mb_substr($c, 0, 200);
                    break;
                }
            }
            if ($entry['pct'] !== null) {
                $found[$key] = $entry;
            }
        }
    }
    return $found;
}

/**
 * Parse the release update page: which of our dams are releasing now, and when it was updated.
 *
 * @param array<int, string> $names
 * @return array{updated: string|null, dams: array<int, string>}
 */
function sfw_dams_parse_releases(string $html, array $names): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $text = sfw_dams_text($xp->query('//body')->item(0) ?? $doc);
    $updated = preg_match('#last updated[:\s-]*(\d{1,2}/\d{1,2}/\d{4}[\s-]*\d{1,2}:\d{2}(?:\s*[ap]m)?)#i', $text, $m)
        ? trim($m[1]) : null;
    $releasing = [];
    // Sentence by sentence: a dam named with a release, and no word that it has ended.
    foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $sentence) {
        $ended = '/\b(ceased|ended|stopped|finished|no longer|not releasing|completed)\b/i';
        if (!preg_match('/releas/i', $sentence) || preg_match($ended, $sentence)) {
            continue;
        }
        foreach ($names as $name) {
            if (stripos($sentence, $name) !== false) {
                $releasing[$name] = true;
            }
        }
    }
    return ['updated' => $updated, 'dams' => array_keys($releasing)];
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
            $message .= '; releasing: ' . ($state['releases']['dams'] ? implode(', ', $state['releases']['dams']) : 'none');
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
