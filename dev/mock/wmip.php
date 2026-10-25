<?php
// Mock of the WMIP get_ts_traces web service, POST JSON only (like the real one).
$req = json_decode(file_get_contents('php://input'), true);
if (!$req || ($req['function'] ?? '') !== 'get_ts_traces') { http_response_code(408); echo 'The page cannot be displayed'; return; }
$p = $req['params']; $meta = json_decode(file_get_contents(__DIR__ . '/wmip_meta.json'), true);
$tz = new DateTimeZone('UTC');
$start = DateTimeImmutable::createFromFormat('YmdHis', $p['start_time'], $tz);
$end = min(DateTimeImmutable::createFromFormat('YmdHis', $p['end_time'], $tz), new DateTimeImmutable('2026-10-22 15:00:00', $tz)); // data lag
$traces = [];
foreach (explode(',', $p['site_list']) as $site) {
  $m = $meta[$site] ?? null;
  if ($p['varto'] === '140.00' && !($p['datasource'] === 'AT' && $p['varfrom'] === '100.00')) {
    header('Content-Type: application/json'); echo json_encode(['error_num' => 125, 'error_msg' => 'No data for specified variable in file']); return;
  }
  $nodata = ($p['varto'] === '140.00' && in_array($site, ['143233A', '143016A'])) || ($p['varto'] === '10.00' && in_array($site, ['143303A']));
  if (!$m || $nodata) {
    // Like the real service: one station without the variable fails the whole request.
    header('Content-Type: application/json');
    echo json_encode(['error_num' => 125, 'error_msg' => 'No data for specified variable in file']);
    return;
  }
  $pts = [];
  for ($t = $start->setTime((int)$start->format('H'), 0); $t <= $end; $t = $t->modify('+1 hour')) {
    $hrs = ($t->getTimestamp() - 1792591200) / 3600;
    $v = match ($p['varto']) { '100.00' => $m['h'] + 0.03 * sin($hrs / 6), '140.00' => 2.5 + 0.2 * sin($hrs / 5), default => ($hrs % 7 == 0 ? 1.0 : 0.0) };
    $pts[] = ['v' => number_format($v, 3, '.', ''), 't' => (int)$t->format('YmdHis'), 'q' => 130, 'd' => 1];
  }
  $traces[] = ['error_num' => 0, 'compressed' => '0', 'site' => $site,
    'site_details' => ['timezone' => '10.0', 'short_name' => $m['name'], 'longitude' => (string)$m['lon'], 'name' => $m['name'], 'latitude' => (string)$m['lat'], 'org_name' => 'DNR - Hydrographic'],
    'quality_codes' => ['130' => 'Not coded value'], 'varfrom_details' => [], 'varto_details' => [], 'trace' => $pts];
}
header('Content-Type: application/json');
echo json_encode(['error_num' => 0, 'disclaimer' => 'x', 'return' => ['traces' => $traces]]);
