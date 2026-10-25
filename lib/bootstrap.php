<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Shared setup for SEQ Flood Watch scripts: loads settings and opens the database.
 */

declare(strict_types=1);

/**
 * Merge settings: a later file overrides what came before. Maps are merged key by key. A list
 * replaces the list under it, EXCEPT a list whose every entry is a record with a 'key' (the
 * flood-map layers, the dams and the prediction rules): those merge by that key, so a config file
 * can change — or add — one entry by naming its key, and the order and length of the default list
 * no longer matter. This is the fix for the old merge-by-position trap (CLAUDE.md lesson 8); it
 * replaces array_replace_recursive, which lined lists up by position.
 *
 * @param array<mixed> $base
 * @param array<mixed> $over
 * @return array<mixed>
 */
function sfw_settings_merge(array $base, array $over): array
{
    if (array_is_list($base) && array_is_list($over)) {
        if (sfw_is_keyed_records($base) && sfw_is_keyed_records($over)) {
            return sfw_merge_keyed_records($base, $over);
        }
        return $over; // Any other list is replaced wholesale, never merged by position.
    }
    $out = $base;
    foreach ($over as $k => $v) {
        $out[$k] = (array_key_exists($k, $out) && is_array($out[$k]) && is_array($v))
            ? sfw_settings_merge($out[$k], $v)
            : $v;
    }
    return $out;
}

/**
 * True if $list is a non-empty list whose every entry is an array carrying a 'key'.
 *
 * @param array<mixed> $list
 */
function sfw_is_keyed_records(array $list): bool
{
    if ($list === []) {
        return false;
    }
    foreach ($list as $entry) {
        if (!is_array($entry) || !isset($entry['key'])) {
            return false;
        }
    }
    return true;
}

/**
 * Merge two lists of records by their 'key': an override entry is merged into the default entry
 * with the same key (field by field), or added if the key is new. The default order is kept, with
 * any added entries after it.
 *
 * @param array<int, array<string, mixed>> $base
 * @param array<int, array<string, mixed>> $over
 * @return array<int, array<string, mixed>>
 */
function sfw_merge_keyed_records(array $base, array $over): array
{
    $bykey = [];
    $order = [];
    foreach ($base as $entry) {
        $bykey[(string) $entry['key']] = $entry;
        $order[] = (string) $entry['key'];
    }
    foreach ($over as $entry) {
        $k = (string) $entry['key'];
        if (!isset($bykey[$k])) {
            $order[] = $k;
        }
        $bykey[$k] = isset($bykey[$k]) ? sfw_settings_merge($bykey[$k], $entry) : $entry;
    }
    return array_values(array_map(static fn(string $k): array => $bykey[$k], $order));
}

/**
 * Load the settings: lib/defaults.php, then config.php from the project folder (or the file named
 * by the SFW_CONFIG environment variable), then the optional extra file named by 'local_config'.
 *
 * On a YunoHost install config.php is written by the app package (and rewritten on every
 * upgrade or settings change); personal changes go in the 'local_config' file, which is kept.
 *
 * @return array<string, mixed>
 */
function sfw_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = getenv('SFW_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            sfw_fail('config.php not found. Copy config.example.php to config.php and fill it in.');
        }
        // Settings in config.php override the defaults, so older config files keep working.
        // sfw_settings_merge (not array_replace_recursive) so the flood-map layers, dams and
        // prediction rules merge by their 'key', not by list position (CLAUDE.md lesson 8).
        $config = sfw_settings_merge(require __DIR__ . '/defaults.php', require $path);
        $local = (string) ($config['local_config'] ?? '');
        if ($local !== '' && is_file($local)) {
            $config = sfw_settings_merge($config, require $local);
        }
        // Paths inside the private data folder, unless set on their own.
        $var = rtrim((string) $config['var_dir'], '/');
        $config['var_dir'] = $var;
        $config['lock_file'] ??= $var . '/collector.lock';
        $config['history']['dir'] ??= $var . '/history';
    }
    return $config;
}

/**
 * The private data folder (state files, locks, push keys, history downloads).
 */
function sfw_var_dir(): string
{
    return sfw_config()['var_dir'];
}

/**
 * Open (once) and return the database connection.
 */
function sfw_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db = sfw_config()['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']);
        $pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep the database session in UTC so stored times are never shifted.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/**
 * Current time in UTC as a database-ready string.
 */
function sfw_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Print a timestamped message (shown in cron logs).
 */
function sfw_log(string $message): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $line . PHP_EOL);
    } else {
        error_log('Flood Watcher: ' . $message); // Web requests (push.php): the web server's PHP log.
    }
}

/**
 * Print an error and stop with a failure exit code.
 */
function sfw_fail(string $message): never
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $message . PHP_EOL);
    exit(1);
}
