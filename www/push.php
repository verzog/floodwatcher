<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Push notification sign-up for the dashboard page.
 *
 * GET  push.php                      -> {"publicKey": "..."} (the server's VAPID public key)
 * POST push.php  (JSON body)
 *   {"action": "subscribe", "subscription": {...}, "watch": ["B540063-H"], "warnings": true,
 *    "warn_catchment": "mary", "areas": [{"name": "Gatton", "s": -27.6, "w": 152.2, "n": -27.5, "e": 152.3}]}
 *   {"action": "unsubscribe", "endpoint": "https://..."}
 *   {"action": "test", "endpoint": "https://..."}   (at most once a minute per browser)
 *
 * Stores only what the browser's push service issued (an address and two keys), the watched
 * gauge ids, the saved areas (a name and a map box each) and the warnings choice. No cookies, names, emails or IP addresses.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * Send a JSON reply and stop.
 *
 * @param array<string, mixed> $data
 */
function sfw_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Where the code is: beside the public folder in the YunoHost app (www/ inside the code folder),
// or a "seqflood" folder next to it (the older My Webapp set-up).
$code = getenv('SFW_CODE_DIR')
    ?: (is_file(dirname(__DIR__) . '/lib/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__) . '/seqflood');
if (!is_file($code . '/lib/bootstrap.php')) {
    sfw_reply(500, ['error' => 'Server set-up problem.']);
}
require $code . '/lib/bootstrap.php';
require $code . '/lib/store.php';
require $code . '/lib/push.php';

$config = sfw_config();
$opts = $config['push'];
if (empty($opts['enabled'])) {
    sfw_reply(503, ['error' => 'Notifications are switched off on this site.']);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        sfw_reply(200, ['publicKey' => sfw_vapid_keys()['public']]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sfw_reply(405, ['error' => 'Use GET or POST.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $req = json_decode((string) $raw, true);
    if (!is_array($req)) {
        sfw_reply(400, ['error' => 'Expected JSON.']);
    }
    $db = sfw_db();
    $action = (string) ($req['action'] ?? '');
    $endpoint = (string) ($req['subscription']['endpoint'] ?? $req['endpoint'] ?? '');
    if ($endpoint === '' || strlen($endpoint) > 1024 || !sfw_push_endpoint_ok($endpoint, $opts['allowed_hosts'])) {
        sfw_reply(400, ['error' => 'This browser\'s push service isn\'t supported.']);
    }
    $hash = hash('sha256', $endpoint);

    if ($action === 'subscribe') {
        $p256dh = (string) ($req['subscription']['keys']['p256dh'] ?? '');
        $auth = (string) ($req['subscription']['keys']['auth'] ?? '');
        $rawkey = sfw_b64url_decode($p256dh);
        $rawauth = sfw_b64url_decode($auth);
        if ($rawkey === null || strlen($rawkey) !== 65 || $rawkey[0] !== "\x04" || $rawauth === null || strlen($rawauth) !== 16) {
            sfw_reply(400, ['error' => 'Bad subscription keys.']);
        }
        $watch = [];
        foreach ((array) ($req['watch'] ?? []) as $id) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9-]{1,24}$/', $id)) {
                $watch[] = $id;
            }
        }
        $watch = array_slice(array_values(array_unique($watch)), 0, (int) $opts['max_watch']);
        $warnings = !empty($req['warnings']) ? 1 : 0;
        // Which catchment "all BoM flood warnings" is for: a live catchment, or every one ("all").
        $warncatchment = (string) ($req['warn_catchment'] ?? '');
        $livekeys = array_map('strval', array_keys($config['catchments']['live'] ?? []));
        if ($warncatchment !== 'all' && !in_array($warncatchment, $livekeys, true)) {
            $warncatchment = (string) ($config['catchments']['default'] ?? 'brisbane');
        }
        $predictive = !array_key_exists('predictive', $req) || !empty($req['predictive']) ? 1 : 0;
        // Saved areas: a name and a box, inside Queensland, at most five.
        $areas = [];
        foreach ((array) ($req['areas'] ?? []) as $a) {
            if (!is_array($a) || count($areas) >= 5) {
                continue;
            }
            $name = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', (string) ($a['name'] ?? '')) ?? '');
            $box = [];
            foreach (['s', 'w', 'n', 'e'] as $k) {
                $box[$k] = filter_var($a[$k] ?? null, FILTER_VALIDATE_FLOAT);
            }
            if ($name === '' || mb_strlen($name) > 40 || in_array(false, $box, true)
                || $box['s'] >= $box['n'] || $box['w'] >= $box['e']
                || $box['s'] < -30.0 || $box['n'] > -9.0 || $box['w'] < 137.0 || $box['e'] > 155.0) {
                continue;
            }
            $areas[] = ['name' => $name] + array_map(static fn(float $v): float => round($v, 4), $box);
        }
        $exists = $db->prepare('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?');
        $exists->execute([$hash]);
        if (!$exists->fetchColumn()) {
            $count = (int) $db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
            if ($count >= (int) $opts['max_subscriptions']) {
                sfw_reply(503, ['error' => 'Sign-ups are full for now. Please try again later.']);
            }
        }
        $now = sfw_now_utc();
        $db->prepare(
            'INSERT INTO push_subscriptions (endpoint_hash, endpoint, p256dh, auth, watch, areas, warnings,
                 warn_catchment, predictive, created_utc, updated_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), watch = VALUES(watch),
                 areas = VALUES(areas), warnings = VALUES(warnings), warn_catchment = VALUES(warn_catchment),
                 predictive = VALUES(predictive), updated_utc = VALUES(updated_utc), failures = 0'
        )->execute([$hash, $endpoint, $p256dh, $auth, json_encode($watch),
            json_encode($areas, JSON_UNESCAPED_UNICODE), $warnings, $warncatchment, $predictive, $now, $now]);
        sfw_reply(200, ['ok' => true, 'watching' => count($watch), 'areas' => count($areas), 'warnings' => (bool) $warnings]);
    }

    if ($action === 'unsubscribe') {
        $db->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?')->execute([$hash]);
        sfw_reply(200, ['ok' => true]);
    }

    if ($action === 'test') {
        $row = $db->prepare('SELECT * FROM push_subscriptions WHERE endpoint_hash = ?');
        $row->execute([$hash]);
        $sub = $row->fetch();
        if (!$sub) {
            sfw_reply(404, ['error' => 'Not signed up on this browser.']);
        }
        if ($sub['last_test_utc'] !== null && strtotime($sub['last_test_utc'] . ' UTC') > time() - 60) {
            sfw_reply(429, ['error' => 'Please wait a minute between tests.']);
        }
        $db->prepare('UPDATE push_subscriptions SET last_test_utc = ? WHERE id = ?')->execute([sfw_now_utc(), $sub['id']]);
        $sent = sfw_push_send_many($db, [$sub], [
            'title' => 'Flood Watcher test',
            'body'  => 'Notifications are working on this device.',
            'url'   => rtrim((string) $opts['site_url'], '/') . '/',
            'tag'   => 'test',
        ], $opts);
        sfw_reply($sent ? 200 : 502, $sent ? ['ok' => true] : ['error' => 'The push service didn\'t accept the test.']);
    }

    sfw_reply(400, ['error' => 'Unknown action.']);
} catch (Throwable $e) {
    sfw_log('push.php: ' . $e->getMessage());
    sfw_reply(500, ['error' => 'Something went wrong. Please try again later.']);
}
