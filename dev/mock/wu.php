<?php
// Mock Weather Underground API (real clock): four stations in the catchment; one hasn't reported
// for 3 hours (not active); one reads far too low.
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_GET['apiKey'] ?? '') !== 'testkey') { http_response_code(401); echo '{"errors":[]}'; exit; }
$st = [
  ['IBRISB101', 'Smith Family Weather', -27.4451, 152.9853, 1.0, 0],
  ['IBRISB202', '12 Rose St', -27.4153, 152.9254, 1.0, 0],
  ['IBADLOW1', 'Under a tree', -27.4702, 152.9601, 0.03, 0],
  ['IDEAD77', 'Old station', -27.43, 152.95, 1.0, 3 * 3600],
  ['IFARAWAY9', 'Outside', -26.0, 151.0, 1.0, 0],
];
if ($path === '/v3/location/near') {
  $o = ['stationId' => [], 'stationName' => [], 'latitude' => [], 'longitude' => [], 'qcStatus' => []];
  foreach ($st as $s) { $o['stationId'][] = $s[0]; $o['stationName'][] = $s[1]; $o['latitude'][] = $s[2]; $o['longitude'][] = $s[3]; $o['qcStatus'][] = 1; }
  echo json_encode(['location' => $o]); exit;
}
if ($path === '/v2/pws/observations/current') {
  $f = sys_get_temp_dir() . '/floodwatcher-wu-mock-runs'; $run = (int) @file_get_contents($f);
  foreach ($st as $s) {
    if ($s[0] !== $_GET['stationId']) continue;
    $total = (5 + 10 * $run) * $s[4];
    echo json_encode(['observations' => [['stationID' => $s[0], 'epoch' => time() - 120 - $s[5] + $run, 'metric' => ['precipTotal' => $total]]]]); exit;
  }
  http_response_code(204); exit;
}
http_response_code(404);
