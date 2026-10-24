<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Shared setup for SEQ Flood Watch scripts: loads settings and opens the database.
 */

declare(strict_types=1);

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
        $config = array_replace_recursive(require __DIR__ . '/defaults.php', require $path);
        $local = (string) ($config['local_config'] ?? '');
        if ($local !== '' && is_file($local)) {
            $config = array_replace_recursive($config, require $local);
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
