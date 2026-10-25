<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Read Bureau of Meteorology flood warnings (Queensland products IDQ2xxxx on BoM's FTP site).
 *
 * Each warning has a short machine-readable summary (<id>.amoc.xml: severity, areas, expiry)
 * and a text version (<id>.txt: the title and the capitalised key messages).
 */

declare(strict_types=1);

/**
 * Parse a warning's .amoc.xml summary.
 *
 * @return array<string, mixed>|null Null if it isn't readable.
 */
function sfw_warning_parse_amoc(string $xml): ?array
{
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($doc === false) {
        return null;
    }
    $rank = ['MIN' => 1, 'MOD' => 2, 'MAJ' => 3];
    $names = [1 => 'minor', 2 => 'moderate', 3 => 'major'];
    $worst = 0;
    $areas = [];
    $headlines = [];
    $parents = [];
    $phase = strtoupper((string) $doc->phase);
    foreach ($doc->hazard as $h) {
        $worst = max($worst, $rank[strtoupper((string) $h['severity'])] ?? 0);
        $parents[] = (string) $h['parent-aac'];
        foreach ($h->{'area-list'}->area ?? [] as $a) {
            $areas[] = (string) $a['description'];
        }
        if ((string) $h->headline !== '') {
            $headlines[] = trim((string) $h->headline);
        }
    }
    $issued = strtotime((string) $doc->{'issue-time-utc'});
    $expires = strtotime((string) $doc->{'expiry-time'});
    return [
        'id'        => (string) $doc->identifier,
        'level'     => $names[$worst] ?? 'warning', // No flood class given (e.g. an initial warning).
        'phase'     => $phase,                       // NEW, UPD, ... CAN means cancelled.
        'issued'    => $issued === false ? null : $issued,
        'expires'   => $expires === false ? null : $expires,
        'areas'     => array_values(array_unique($areas)),
        'summary'   => $headlines,
        'parents'   => array_values(array_unique(array_filter($parents))),
    ];
}

/**
 * Pull the title and the capitalised key messages out of a warning's .txt version.
 *
 * @return array{title: string, headlines: string[]}
 */
function sfw_warning_parse_txt(string $text): array
{
    // Blank lines are kept: they separate one key message from the next.
    $lines = array_map('trim', preg_split('/\R/', $text));
    $title = '';
    $headlines = [];
    $current = '';
    foreach ($lines as $i => $line) {
        if ($title === '' && $i > 0 && stripos($line, 'warning') !== false && stripos($line, 'Bureau') === false) {
            $title = $line;
            continue;
        }
        // Key messages are written in capitals and may wrap onto a second line.
        $caps = strlen($line) >= 8 && preg_match('/[A-Z]{3}/', $line) && strtoupper($line) === $line
            && !preg_match('/^(IDQ\d+|FLOOD WARNING NUMBER)/i', $line);
        if ($caps) {
            $current = $current === '' ? $line : $current . ' ' . $line;
        } elseif ($current !== '') {
            $headlines[] = $current;
            $current = '';
        }
        if (count($headlines) >= 4 || $i > 40) {
            break;
        }
    }
    if ($current !== '') {
        $headlines[] = $current;
    }
    return ['title' => $title, 'headlines' => $headlines];
}

/**
 * True if a warning mentions any of the keywords in its title, area names or key messages.
 *
 * @param array<string, mixed> $w Parsed warning.
 * @param string[] $keywords
 */
function sfw_warning_is_ours(array $w, array $keywords): bool
{
    $hay = strtolower(($w['title'] ?? '') . ' | ' . implode(' | ', $w['areas'] ?? []) . ' | ' . implode(' | ', $w['summary'] ?? []));
    foreach ($keywords as $k) {
        if ($k !== '' && str_contains($hay, strtolower((string) $k))) {
            return true;
        }
    }
    return false;
}

/**
 * The catchments a warning belongs to: those whose keywords it mentions. The keywords setting is
 * keyed by catchment; a plain list (settings from version 0.18) counts as the Brisbane River's.
 *
 * @param array<string, mixed> $w Parsed warning.
 * @param array<int|string, mixed> $keywords
 * @return string[]
 */
function sfw_warning_catchments(array $w, array $keywords): array
{
    if (array_is_list($keywords)) {
        $keywords = ['brisbane' => $keywords];
    }
    $out = [];
    foreach ($keywords as $catchment => $list) {
        if (is_array($list) && sfw_warning_is_ours($w, $list)) {
            $out[] = (string) $catchment;
        }
    }
    return $out;
}
