<?php
// Mock of the Opendatasoft records endpoint, built from the uploaded CSV.
$f = fopen(__DIR__ . '/../fixtures/bcc/telemetry-sensors-rainfall-and-stream-heights.csv', 'r');
$hdr = fgetcsv($f, null, ',', '"', '');
$hdr[0] = preg_replace('/^\xEF\xBB\xBF/', '', $hdr[0]);
$rows = [];
while (($r = fgetcsv($f, null, ',', '"', '')) !== false) {
    $rec = [];
    foreach ($hdr as $i => $h) {
        $k = strtolower(str_replace(' ', '_', $h));
        if (!array_key_exists($k, $rec)) { $rec[$k] = $r[$i]; }   // API has unique keys.
    }
    $rec['measured'] = (new DateTimeImmutable($rec['measured']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    $rows[] = $rec;
}
usort($rows, fn($a, $b) => strcmp($a['measured'], $b['measured']));
if (!empty($_GET['where'])) {
    if (!preg_match("/^measured >= date'([^']+)'$/", $_GET['where'], $m)) { http_response_code(400); echo '{"error":"bad where"}'; return; }
    $since = new DateTimeImmutable($m[1]);
    $rows = array_values(array_filter($rows, fn($r) => new DateTimeImmutable($r['measured']) >= $since));
}
$limit = (int)($_GET['limit'] ?? 10); $offset = (int)($_GET['offset'] ?? 0);
if ($limit > 100) { http_response_code(400); echo '{"error":"limit"}'; return; }
header('Content-Type: application/json');
echo json_encode(['total_count' => count($rows), 'results' => array_slice($rows, $offset, $limit)]);
