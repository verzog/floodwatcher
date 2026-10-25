<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Web Push notifications, using only PHP's built-in OpenSSL (no extra libraries).
 *
 * Standards: RFC 8030 (Web Push), RFC 8291 (message encryption, aes128gcm per RFC 8188) and
 * RFC 8292 (VAPID: the server identifies itself with a signed token).
 *
 * The server's VAPID key pair lives in var/vapid.json (private: keep it out of the web folder
 * and out of git). Browsers subscribe with the public key; their subscriptions are stored in
 * the push_subscriptions table by www/push.php.
 */

declare(strict_types=1);

/** DER prefix that turns a raw P-256 public point into a SubjectPublicKeyInfo structure. */
const SFW_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

/**
 * Base64url without padding.
 */
function sfw_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Decode base64url (padding optional). Returns null if it isn't valid.
 */
function sfw_b64url_decode(string $text): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]*={0,2}$/', $text)) {
        return null;
    }
    $bin = base64_decode(strtr(rtrim($text, '='), '-_', '+/'), true);
    return $bin === false ? null : $bin;
}

/**
 * The raw 65-byte public point (0x04 || X || Y) of an EC key.
 *
 * @param OpenSSLAsymmetricKey $key
 */
function sfw_ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    if (!$d || !isset($d['ec']['x'], $d['ec']['y'])) {
        throw new RuntimeException('Not an EC key.');
    }
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/**
 * Turn a raw 65-byte P-256 public point into an OpenSSL public key.
 *
 * @return OpenSSLAsymmetricKey
 */
function sfw_ec_public_from_raw(string $raw)
{
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
        throw new InvalidArgumentException('Bad public key.');
    }
    $der = hex2bin(SFW_P256_SPKI_PREFIX) . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        throw new InvalidArgumentException('Bad public key.');
    }
    return $key;
}

/**
 * A new P-256 key pair.
 *
 * @return OpenSSLAsymmetricKey
 */
function sfw_ec_new_key()
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($key === false) {
        throw new RuntimeException('Cannot create an EC key: ' . openssl_error_string());
    }
    return $key;
}

/**
 * Load the server's VAPID keys, creating them on first use.
 *
 * @return array{private_pem: string, public: string} public is base64url of the raw point.
 */
function sfw_vapid_keys(): array
{
    $path = sfw_var_dir() . '/vapid.json';
    if (is_file($path)) {
        $keys = json_decode((string) file_get_contents($path), true);
        if (is_array($keys) && isset($keys['private_pem'], $keys['public'])) {
            return $keys;
        }
    }
    $key = sfw_ec_new_key();
    openssl_pkey_export($key, $pem);
    $keys = ['private_pem' => $pem, 'public' => sfw_b64url(sfw_ec_public_raw($key))];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0750, true);
    }
    $old = umask(0077);
    file_put_contents($path, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    umask($old);
    @chmod($path, 0600);
    return $keys;
}

/**
 * Convert an OpenSSL DER ECDSA signature to the raw 64-byte r || s form JWT needs.
 */
function sfw_ecdsa_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f; // Long-form length (not expected for P-256, but harmless).
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$pos]) !== 0x02) {
            throw new RuntimeException('Unexpected signature format.');
        }
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

/**
 * The VAPID Authorization header value for a push service endpoint.
 *
 * @param array{private_pem: string, public: string} $keys
 */
function sfw_vapid_header(string $endpoint, array $keys, string $subject): string
{
    $parts = parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $head = sfw_b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = sfw_b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    $input = $head . '.' . $claims;
    $priv = openssl_pkey_get_private($keys['private_pem']);
    if ($priv === false || !openssl_sign($input, $der, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Cannot sign the VAPID token.');
    }
    return 'vapid t=' . $input . '.' . sfw_b64url(sfw_ecdsa_der_to_raw($der)) . ', k=' . $keys['public'];
}

/**
 * Encrypt a message for one browser (RFC 8291, aes128gcm).
 *
 * @param string $uapublic The browser's raw 65-byte public key (p256dh).
 * @param string $authsecret The browser's 16-byte auth secret.
 * @param string|null $salt For tests only; normally random.
 * @param OpenSSLAsymmetricKey|null $askey For tests only; normally a fresh key each message.
 */
function sfw_push_encrypt(string $plaintext, string $uapublic, string $authsecret, ?string $salt = null, $askey = null): string
{
    $askey = $askey ?? sfw_ec_new_key();
    $aspublic = sfw_ec_public_raw($askey);
    $secret = openssl_pkey_derive(sfw_ec_public_from_raw($uapublic), $askey, 32);
    if ($secret === false) {
        throw new RuntimeException('Key agreement failed.');
    }
    $salt = $salt ?? random_bytes(16);

    $prkkey = hash_hmac('sha256', $secret, $authsecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $uapublic . $aspublic . "\x01", $prkkey, true);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

    $tag = '';
    $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    // Header: salt, record size, key id length, key id (our public key), then the record.
    return $salt . pack('N', 4096) . chr(65) . $aspublic . $cipher . $tag;
}

/**
 * True if an endpoint is an https address at a known push service (so the server never
 * posts to arbitrary addresses someone submits).
 *
 * @param string[] $allowed Host names or suffixes starting with a dot.
 */
function sfw_push_endpoint_ok(string $endpoint, array $allowed): bool
{
    $p = parse_url($endpoint);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        return in_array('*insecure-test*', $allowed, true) && ($p['scheme'] ?? '') === 'http';
    }
    $host = strtolower($p['host']);
    foreach ($allowed as $a) {
        $a = strtolower($a);
        if ($host === $a || ($a[0] === '.' && str_ends_with($host, $a))) {
            return true;
        }
    }
    return false;
}

/**
 * Send one notification. Returns the HTTP status (201 = accepted; 404 or 410 = the browser has
 * unsubscribed, so the subscription should be deleted).
 *
 * @param array<string, mixed> $sub Row from push_subscriptions.
 * @param array<string, mixed> $message title, body, url, tag.
 * @param array<string, mixed> $opts The 'push' settings.
 */
function sfw_push_send(array $sub, array $message, array $opts): int
{
    $uapublic = sfw_b64url_decode((string) $sub['p256dh']);
    $auth = sfw_b64url_decode((string) $sub['auth']);
    if ($uapublic === null || $auth === null) {
        return 410; // Unusable: treat as gone.
    }
    $body = sfw_push_encrypt(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $uapublic, $auth);
    $headers = [
        'Authorization: ' . sfw_vapid_header((string) $sub['endpoint'], sfw_vapid_keys(), (string) $opts['subject']),
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'TTL: ' . (int) $opts['ttl_seconds'],
        'Urgency: high',
    ];
    $ch = curl_init((string) $sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return $status;
}

/**
 * Send a message to each subscription; delete the ones the push service says are gone.
 *
 * @param array<int, array<string, mixed>> $subs
 * @param array<string, mixed> $message
 * @param array<string, mixed> $opts
 * @return int Number delivered.
 */
function sfw_push_send_many(PDO $db, array $subs, array $message, array $opts): int
{
    $sent = 0;
    foreach ($subs as $sub) {
        try {
            $status = sfw_push_send($sub, $message, $opts);
        } catch (Throwable $e) {
            sfw_log('Push failed for subscription ' . $sub['id'] . ': ' . $e->getMessage());
            continue;
        }
        if ($status >= 200 && $status < 300) {
            $sent++;
            $db->prepare('UPDATE push_subscriptions SET failures = 0 WHERE id = ?')->execute([$sub['id']]);
        } elseif ($status === 404 || $status === 410) {
            $db->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$sub['id']]);
        } else {
            // Temporary problem (rate limit, outage): count it, and give up after many in a row.
            $db->prepare('UPDATE push_subscriptions SET failures = failures + 1 WHERE id = ?')->execute([$sub['id']]);
            $db->exec('DELETE FROM push_subscriptions WHERE failures >= 50');
        }
    }
    return $sent;
}

/**
 * Who should hear about something at a place, and under what name.
 *
 * A subscription matches if it watches the gauge, or if one of its saved areas contains the
 * point (or overlaps the box, for warnings and predictions, on land that can flood). The area's name is put in front of the
 * notification title so people with several areas know which one it's about.
 *
 * @param array<int, array<string, mixed>> $subs
 * @param string|null $gaugeid Gauge id, or null for a warning.
 * @param float[]|null $point [lat, lon] of the gauge.
 * @param float[]|null $box [S, W, N, E] of a warning's area; null means unknown (matches any area).
 * @param bool $allwarnings True for warnings: also everyone who chose all BoM flood warnings for one
 *     of the warning's catchments ($catchments), or for every catchment.
 * @param array<string, mixed>|null $mask Flood-prone mask (lib/floodmask.php); null to skip that check.
 * @param array<string, mixed>|null $area The warning's or prediction's own flood-prone land, if worked out
 *     (sfw_floodmask_region); a saved area must include some of it.
 * @param string[]|null $catchments The warning's catchments; null for any.
 * @return array<int, array{sub: array<string, mixed>, prefix: string}>
 */
function sfw_push_targets(
    array $subs,
    ?string $gaugeid,
    ?array $point,
    ?array $box,
    bool $allwarnings,
    ?array $mask = null,
    ?array $area = null,
    ?array $catchments = null
): array
{
    $out = [];
    foreach ($subs as $s) {
        $names = [];
        foreach (json_decode((string) ($s['areas'] ?? ''), true) ?: [] as $a) {
            if (!isset($a['s'], $a['w'], $a['n'], $a['e'])) {
                continue;
            }
            $hit = false;
            if ($point !== null) {
                $hit = $point[0] >= $a['s'] && $point[0] <= $a['n'] && $point[1] >= $a['w'] && $point[1] <= $a['e'];
            } elseif ($box !== null) {
                // The saved area and the alert's area must overlap on land that can flood.
                $both = [max($box[0], $a['s']), max($box[1], $a['w']), min($box[2], $a['n']), min($box[3], $a['e'])];
                $hit = $both[0] <= $both[2] && $both[1] <= $both[3] && match (true) {
                    $area !== null => sfw_floodmask_area_hits($area, $both),
                    $mask !== null => sfw_floodmask_any($mask, $both),
                    default        => true,
                };
            } else {
                // A warning with no known area: everyone with an area that has flood-prone land.
                $hit = $gaugeid === null && ($mask === null || sfw_floodmask_any($mask, [$a['s'], $a['w'], $a['n'], $a['e']]));
            }
            if ($hit) {
                $names[] = (string) $a['name'];
            }
        }
        $watch = json_decode((string) $s['watch'], true);
        $watching = $gaugeid !== null && is_array($watch) && in_array($gaugeid, $watch, true);
        $mine = (string) ($s['warn_catchment'] ?? 'brisbane');
        $everything = $allwarnings && (int) $s['warnings'] === 1
            && ($catchments === null || $mine === 'all' || in_array($mine, $catchments, true));
        if ($names || $watching || $everything) {
            $out[] = ['sub' => $s, 'prefix' => $names ? implode(', ', array_unique($names)) . ': ' : ''];
        }
    }
    return $out;
}

/**
 * Send one message to each target, with that target's area name in front of the title.
 *
 * @param array<int, array{sub: array<string, mixed>, prefix: string}> $targets
 * @param array<string, mixed> $message
 * @param array<string, mixed> $opts
 */
function sfw_push_send_targets(PDO $db, array $targets, array $message, array $opts): int
{
    $sent = 0;
    foreach ($targets as $t) {
        $m = $message;
        $m['title'] = $t['prefix'] . $message['title'];
        $sent += sfw_push_send_many($db, [$t['sub']], $m, $opts);
    }
    return $sent;
}

/**
 * Work out what's new since the last run and notify the people who asked for it:
 * - a gauge alert that's new or worse: those watching that gauge or with an area around it;
 * - an all clear: the same people;
 * - a new or updated BoM flood warning: everyone who chose all catchment warnings, plus those
 *   with an area the warning's rivers run through.
 *
 * @param array<int, array<string, mixed>> $markers Dashboard markers (for gauge positions).
 * @param array<int, array<string, mixed>> $alerts
 * @param array<int, array<string, mixed>> $cleared
 * @param array<int, array<string, mixed>> $warnings As written to alerts.json (with 'bbox').
 * @param array<string, mixed> $opts The 'push' settings.
 * @return int Notifications delivered.
 */
function sfw_push_dispatch(PDO $db, array $markers, array $alerts, array $cleared, array $warnings, array $opts): int
{
    $subs = $db->query('SELECT id, endpoint, p256dh, auth, watch, areas, warnings, warn_catchment, predictive
        FROM push_subscriptions')->fetchAll();
    $state = sfw_state_read('push_sent');
    $first = $state === [];
    $sentgauges = $state['gauges'] ?? [];
    $sentwarnings = $state['warnings'] ?? [];
    $clearsent = $state['cleared'] ?? [];
    $now = time();
    $levels = ['watch' => 0, 'likely' => 1, 'minor' => 2, 'moderate' => 3, 'major' => 4];
    $labels = ['watch' => 'Watch', 'likely' => 'Flooding likely', 'minor' => 'Minor flooding',
        'moderate' => 'Moderate flooding', 'major' => 'Major flooding'];
    $base = rtrim((string) $opts['site_url'], '/') . '/';
    $note = ' Not an official warning: check BoM.';
    $delivered = 0;
    $mask = $opts['floodmask'] ?? null; // Flood-prone land (lib/floodmask.php), if built.
    $where = [];
    foreach ($markers as $m) {
        $where[$m['id']] = [(float) $m['lat'], (float) $m['lon']]; // Includes flood potential rules.
    }

    // Gauge alerts: new, or a higher level than last notified.
    $active = [];
    foreach ($alerts as $a) {
        $active[$a['id']] = true;
        $prev = $sentgauges[$a['id']] ?? null;
        $rank = $levels[$a['level']] ?? 0;
        $prevrank = $prev ? ($levels[$prev['level']] ?? 0) : -1;
        // Flap guard: the same alert coming back within the quiet period isn't sent again.
        $recent = $prev && $rank <= $prevrank;
        if (!$recent && !$first && !$a['not_reporting']) {
            // Predictive alerts (flood potential rules) cover an area: saved areas that overlap it,
            // for people who kept "Predictive flood alerts" ticked.
            $predictive = str_starts_with((string) $a['kind'], 'potential');
            $targets = $predictive
                ? array_values(array_filter(
                    sfw_push_targets($subs, $a['id'], null, $a['bbox'] ?? null, false, $mask, $a['area'] ?? null),
                    static fn(array $t): bool => (int) ($t['sub']['predictive'] ?? 1) === 1
                ))
                : sfw_push_targets($subs, $a['id'], $where[$a['id']] ?? null, null, false);
            $title = $predictive
                ? 'Predictive flood alert (' . ($a['level'] === 'likely' ? 'flooding likely' : 'watch') . '), ' . $a['name']
                : ($labels[$a['level']] ?? 'Alert') . ', ' . $a['name'];
            $delivered += sfw_push_send_targets($db, $targets, [
                'title' => $title,
                'body'  => ucfirst($a['text']) . '.' . $note,
                'url'   => $base . '#' . rawurlencode($a['id']),
                'tag'   => 'gauge-' . $a['id'],
            ], $opts);
        }
        if ($recent) {
            $sentgauges[$a['id']]['at'] = $now; // Still active: the quiet period starts when it ends.
        } else {
            $sentgauges[$a['id']] = ['level' => $a['level'], 'at' => $now];
        }
    }
    // Forget ended alerts after the quiet period, so a later rise is announced again.
    foreach ($sentgauges as $id => $g) {
        if (!isset($active[$id]) && $now - (int) $g['at'] > 60 * (int) $opts['quiet_minutes']) {
            unset($sentgauges[$id]);
        }
    }

    // All clear, once per ending.
    foreach ($cleared as $c) {
        $key = $c['id'] . '@' . $c['at'];
        if (isset($clearsent[$key]) || $first) {
            $clearsent[$key] = $now;
            continue;
        }
        $clearsent[$key] = $now;
        $targets = sfw_push_targets($subs, $c['id'], $where[$c['id']] ?? null, null, false);
        $delivered += sfw_push_send_targets($db, $targets, [
            'title' => 'All clear, ' . $c['name'],
            'body'  => ucfirst($c['text']) . '.' . $note,
            'url'   => $base . '#' . rawurlencode($c['id']),
            'tag'   => 'gauge-' . $c['id'],
        ], $opts);
    }
    $clearsent = array_filter($clearsent, static fn(int $t): bool => $now - $t < 86400);

    // BoM flood warnings: new ones, and each new issue of an existing one.
    $current = [];
    foreach ($warnings as $w) {
        $current[$w['id']] = $w['issued'];
        if (($sentwarnings[$w['id']] ?? null) === $w['issued'] || $first) {
            continue;
        }
        $targets = sfw_push_targets($subs, null, null, $w['bbox'] ?? null, true, $mask, $w['area'] ?? null,
            $w['catchments'] ?? null);
        $delivered += sfw_push_send_targets($db, $targets, [
            'title' => 'BoM: ' . $w['title'],
            'body'  => ($w['headlines'][0] ?? 'New flood warning issued.') . ' Tap to read the warning.',
            'url'   => $w['url'],
            'tag'   => 'bom-' . $w['id'],
        ], $opts);
    }

    sfw_state_write('push_sent', ['gauges' => $sentgauges, 'warnings' => $current, 'cleared' => $clearsent, 'run' => $now]);
    return $delivered;
}
