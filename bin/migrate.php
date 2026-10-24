<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Bring the database up to date: creates the tables on a new install, then applies any
 * sql/upgrade_<version>.sql files not applied before, oldest first, remembering each one in the
 * schema_migrations table. Safe to run any number of times; the YunoHost package runs it on
 * install and on every upgrade.
 *
 * Usage: php bin/migrate.php
 *
 * An existing database from before 0.17 (set up by hand, e.g. in My Webapp) is recognised: the
 * slow 0.3 change is skipped if it's already there, and the later ones (which are all safe to
 * repeat) are run once and recorded.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';

$db = sfw_db();
$sqldir = dirname(__DIR__) . '/sql';

/**
 * Run the statements in an SQL file one at a time (comment lines skipped).
 */
function sfw_migrate_file(PDO $db, string $file): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
        $db->exec($statement);
    }
}

/**
 * True if a table exists in the current database.
 */
function sfw_migrate_has_table(PDO $db, string $table): bool
{
    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $q->execute([$table]);
    return (int) $q->fetchColumn() > 0;
}

/**
 * True if a column exists in a table in the current database.
 */
function sfw_migrate_has_column(PDO $db, string $table, string $column): bool
{
    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $q->execute([$table, $column]);
    return (int) $q->fetchColumn() > 0;
}

// The upgrade files, oldest first.
$upgrades = [];
foreach (glob($sqldir . '/upgrade_*.sql') ?: [] as $file) {
    if (preg_match('/upgrade_([0-9.]+)\.sql$/', $file, $m)) {
        $upgrades[$m[1]] = $file;
    }
}
uksort($upgrades, 'version_compare');

$fresh = !sfw_migrate_has_table($db, 'sensors');
$adopting = !$fresh && !sfw_migrate_has_table($db, 'schema_migrations');
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version      VARCHAR(16) NOT NULL,
    applied_utc  DATETIME    NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$done = array_flip($db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
$record = $db->prepare('INSERT IGNORE INTO schema_migrations (version, applied_utc) VALUES (?, ?)');

if ($fresh) {
    // A new database: schema.sql is already the latest design, so every upgrade counts as done.
    sfw_migrate_file($db, $sqldir . '/schema.sql');
    foreach (array_keys($upgrades) as $version) {
        $record->execute([$version, sfw_now_utc()]);
    }
    sfw_log('Database tables created (' . count($upgrades) . ' upgrades marked as included).');
    exit(0);
}

if ($adopting && isset($upgrades['0.3']) && sfw_migrate_has_column($db, 'sensors', 'catchment')) {
    // Set up by hand before 0.17, already past 0.3: skip that one (it rebuilds the readings table).
    $record->execute(['0.3', sfw_now_utc()]);
    $done['0.3'] = true;
}

$applied = 0;
foreach ($upgrades as $version => $file) {
    if (isset($done[$version])) {
        continue;
    }
    sfw_log("Applying database upgrade {$version}…");
    sfw_migrate_file($db, $file);
    $record->execute([$version, sfw_now_utc()]);
    $applied++;
}
sfw_log($applied ? "Database up to date ({$applied} upgrades applied)." : 'Database already up to date.');
