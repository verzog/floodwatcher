<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Small HTTP/FTP helpers. Use PHP's curl extension when installed; otherwise fall back to
 * PHP's built-in streams (HTTP) or the curl command-line tool (FTP), so the collectors work
 * on a server without php-curl.
 */

declare(strict_types=1);

/**
 * Fetch a URL and decode the JSON response.
 *
 * @return array<string, mixed>
 */
function sfw_http_get_json(string $url): array
{
    return sfw_decode_json(sfw_http_request('GET', $url, null));
}

/**
 * POST a JSON payload and decode the JSON response.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function sfw_http_post_json(string $url, array $payload): array
{
    return sfw_decode_json(sfw_http_request('POST', $url, json_encode($payload, JSON_UNESCAPED_SLASHES)));
}

/**
 * Fetch a URL (http, https or ftp) and return the raw body.
 */
function sfw_fetch_raw(string $url): string
{
    if (str_starts_with($url, 'ftp://') && !function_exists('curl_init')) {
        return sfw_curl_cli($url);
    }
    return sfw_http_request('GET', $url, null);
}

/**
 * Decode JSON or throw a clear error.
 *
 * @return array<string, mixed>
 */
function sfw_decode_json(string $body): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Source did not return valid JSON. Response starts: ' . substr(trim($body), 0, 200));
    }
    return $data;
}

/**
 * Make a request and return the body. Throws on network errors and non-success responses.
 */
function sfw_http_request(string $method, string $url, ?string $jsonbody): string
{
    $http = sfw_config()['http'];
    $timeout = (int) $http['timeout_seconds'];
    $agent = (string) $http['user_agent'];
    $isftp = str_starts_with($url, 'ftp://');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout * 2,
        ];
        if (!$isftp) {
            $opts[CURLOPT_FOLLOWLOCATION] = true;
            $opts[CURLOPT_USERAGENT] = $agent;
            $opts[CURLOPT_HTTPHEADER] = ['Accept: application/json'];
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $jsonbody;
                $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($body === false) {
            throw new RuntimeException("Request failed: {$error}");
        }
        // FTP success codes are 2xx (e.g. 226 transfer complete); HTTP needs 200.
        if (($isftp && ($status < 200 || $status >= 300)) || (!$isftp && $status !== 200)) {
            throw new RuntimeException("Status {$status} from source. Response starts: " . substr(trim((string) $body), 0, 300));
        }
        return (string) $body;
    }

    $headers = "User-Agent: {$agent}\r\nAccept: application/json\r\n";
    if ($method === 'POST') {
        $headers .= "Content-Type: application/json\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method'        => $method,
            'timeout'       => $timeout,
            'header'        => $headers,
            'content'       => $jsonbody ?? '',
            'ignore_errors' => true, // Return the body on 4xx/5xx so we can report it.
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Request failed (no response). Check the server can reach the internet.');
    }
    // PHP 8.4+ has http_get_last_response_headers(); PHP 8.3 only sets $http_response_header.
    $lines = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $status = 0;
    foreach ($lines as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1]; // Keeps the last status line, i.e. after any redirects.
        }
    }
    if ($status !== 200) {
        throw new RuntimeException("HTTP {$status} from source. Response starts: " . substr(trim($body), 0, 300));
    }
    return $body;
}

/**
 * Fetch a URL with the curl command-line tool (used for FTP when php-curl isn't installed).
 */
function sfw_curl_cli(string $url): string
{
    $timeout = (int) sfw_config()['http']['timeout_seconds'] * 2;
    $cmd = 'curl -s -S --fail -m ' . $timeout . ' ' . escapeshellarg($url) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $body = implode("\n", $output);
    if ($code !== 0) {
        throw new RuntimeException("curl failed ({$code}) for {$url}: " . substr($body, 0, 300));
    }
    return $body . "\n";
}
