<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * SEQ Flood Watch settings.
 *
 * Copy this file to config.php (in the same folder) and fill in the database password.
 * config.php must never be inside the public www folder, and never committed to git.
 *
 * Only the database details are needed here. Every other setting has a sensible default in
 * lib/defaults.php; copy a setting into this file only if you want to change it.
 *
 * On YunoHost My Webapp, the database name and user are normally both "my_webapp".
 * The password is shown by: yunohost app setting my_webapp db_pwd
 */

declare(strict_types=1);

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'my_webapp',
        'user'     => 'my_webapp',
        'password' => 'CHANGE_ME',
    ],
];
