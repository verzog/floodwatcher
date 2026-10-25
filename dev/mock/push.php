<?php
// Mock push service: records each delivery; /gone/* answers 410 like an expired subscription.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$h = getallheaders();
file_put_contents(sys_get_temp_dir() . '/floodwatcher-push-mock.jsonl', json_encode(['path' => $path, 'auth' => substr($h['Authorization'] ?? '', 0, 20), 'enc' => $h['Content-Encoding'] ?? '', 'ttl' => $h['TTL'] ?? '', 'bytes' => strlen(file_get_contents('php://input'))]) . "\n", FILE_APPEND);
http_response_code(str_starts_with($path, '/gone/') ? 410 : 201);
