<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Set up push notifications: create the server's VAPID key pair (once) and check the
 * subscriptions table exists.
 *
 * Usage: sudo -u my_webapp php8.3 bin/push_setup.php
 *
 * The private key is saved in var/vapid.json (readable only by the app). Keep it: if it's
 * lost or changed, every browser has to sign up for notifications again.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/store.php';
require dirname(__DIR__) . '/lib/push.php';

$existed = is_file(sfw_var_dir() . '/vapid.json');
$keys = sfw_vapid_keys();
sfw_log(($existed ? 'Using the existing' : 'Created a new') . ' push key pair. Public key: ' . $keys['public']);

try {
    $count = (int) sfw_db()->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
    sfw_log("Subscriptions table is ready ({$count} browsers signed up).");
} catch (Throwable $e) {
    sfw_fail('The push_subscriptions table is missing. Run: mysql my_webapp < sql/upgrade_0.9.sql');
}
