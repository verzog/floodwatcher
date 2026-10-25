<?php
// Mock ArcGIS layer queries for testing fetch_overlays.php and the page's likelihood lookup.
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/seq/levels') { header('Content-Type: text/html'); readfile(__DIR__ . '/../fixtures/seqwater/dam-levels.html'); exit; }
if ($path === '/seq/release') { header('Content-Type: text/html'); readfile(__DIR__ . '/../fixtures/seqwater/dam-release-update.html'); exit; }
if (str_starts_with($path, '/v2/events')) {
  if (($_GET['apikey'] ?? '') === 'over') { http_response_code(429); echo '{"message":"You have exceeded the request quota"}'; exit; }
  if (($_GET['apikey'] ?? '') === '') { http_response_code(403); echo '{"message":"Forbidden"}'; exit; }
  $f = function ($id, $type, $sub, $imp, $impsub, $road, $loc, $lga, $geom, $desc, $adv) {
    return ['type' => 'Feature', 'geometry' => $geom, 'properties' => [
      'id' => $id, 'status' => 'Published', 'event_type' => $type, 'event_subtype' => $sub,
      'impact' => ['direction' => 'All directions', 'impact_type' => $imp, 'impact_subtype' => $impsub],
      'duration' => ['start' => '2026-10-22T14:30:00+10:00', 'end' => null],
      'description' => $desc, 'advice' => $adv, 'information' => null,
      'road_summary' => ['road_name' => $road, 'locality' => $loc, 'local_government_area' => $lga],
      'source' => ['provided_by' => 'Department of Transport and Main Roads'],
      'web_link' => 'https://qldtraffic.qld.gov.au/', 'last_updated' => '2026-10-22T15:40:00+10:00']];
  };
  $line = ['type' => 'MultiLineString', 'coordinates' => [[[152.700,-27.462],[152.705,-27.468],[152.712,-27.474],[152.716,-27.481]]]];
  echo json_encode(['type' => 'FeatureCollection', 'features' => [
    $f(1, 'Flooding', 'Flash flooding', 'Closures', 'Road closed to all traffic', 'Brisbane Valley Highway', 'Fernvale', 'Somerset Regional',
       ['type' => 'GeometryCollection', 'geometries' => [['type' => 'Point', 'coordinates' => [152.6535,-27.4485]]]],
       'Water over road at <b>Fernvale</b>', 'Do not drive in flood waters'),
    $f(2, 'Flooding', 'Long-term flooding', 'Lanes affected', 'Caution', 'Mount Crosby Road', 'Chuwar', 'Ipswich City',
       ['type' => 'MultiPoint', 'coordinates' => [[152.795,-27.553]]], 'Water on road edge', 'Drive with caution'),
    $f(3, 'Hazard', 'Road damage', 'Closures', 'Road closed to through traffic', 'Wivenhoe Somerset Road', 'Wivenhoe Pocket', 'Somerset Regional',
       $line, 'Road damaged after flooding', 'Use alternative route'),
    $f(4, 'Roadworks', 'Planned roadworks', 'Closures', 'Road closed to all traffic', 'Some Street', 'Toowong', 'Brisbane City',
       ['type' => 'Point', 'coordinates' => [152.99,-27.48]], 'Planned works', ''),
    $f(5, 'Flooding', 'Flash flooding', 'Closures', 'Road closed to all traffic', 'Duck Creek Road', 'Kerry', 'Scenic Rim Regional',
       ['type' => 'Point', 'coordinates' => [153.06,-28.16]], 'Outside the box', ''),
    $f(6, 'Flooding', 'Flash flooding', 'Closures', 'Road closed to all traffic', 'Dugandan Road', 'Bunya', 'Moreton Bay Regional',
       ['type' => 'Point', 'coordinates' => [152.948,-27.355]], 'Wrong council', ''),
    $f(7, 'Flooding', 'Flash flooding', 'Closures', 'Road closed to all traffic', 'Brooloo Road', 'Imbil', 'Gympie Regional',
       ['type' => 'Point', 'coordinates' => [152.676,-26.46]], 'Water over road at Yabba Creek', 'Do not drive in flood waters'),
    $f(8, 'Flooding', 'Flash flooding', 'Lanes affected', 'Caution', 'Kin Kin Road', 'Kin Kin', 'Noosa Shire',
       ['type' => 'Point', 'coordinates' => [152.879,-26.263]], 'Noosa catchment, not the Mary', ''),
  ]]);
  exit;
}

if ($path === '/x/basins') {
  echo json_encode(['objectIdField' => 'OBJECTID', 'fields' => [['name' => 'OBJECTID', 'type' => 'esriFieldTypeOID'],
    ['name' => 'basin_number', 'type' => 'esriFieldTypeString'], ['name' => 'basin_name', 'type' => 'esriFieldTypeString'],
    ['name' => 'SHAPE', 'type' => 'esriFieldTypeGeometry']]]); exit;
}
preg_match('#/(\w+)/query$#', $path, $m);
$layer = $m[1] ?? '';
mt_srand(crc32($layer));
// River path from Wivenhoe through Ipswich to the city (lon, lat).
$path_pts = [[152.62,-27.39],[152.66,-27.47],[152.72,-27.53],[152.80,-27.56],[152.86,-27.59],[152.90,-27.60],
             [152.95,-27.55],[152.99,-27.52],[153.01,-27.49],[153.03,-27.47],[153.06,-27.46],[153.10,-27.43]];
function blob($cx, $cy, $r, $n = 18) { $ring = [];
  for ($i = $n; $i >= 0; $i--) { $a = 2*M_PI*$i/$n; $rr = $r*(0.8+0.4*sin($a*3)); $ring[] = [$cx+$rr*cos($a)*1.1, $cy+$rr*sin($a)]; }
  return $ring; }
$feats = [];
$id = 1;
if ($layer === 'Flood_Risk_Overall') {
  $g = $_GET['geometry'] ?? '';
  [$x, $y] = array_map('floatval', explode(',', $g) + [0, 0]);
  $out = [];
  if ($y > -27.5 && $y < -27.44) { $out[] = ['attributes' => ['FLOOD_RISK' => 'Medium', 'FLOOD_TYPE' => 'Creek River Storm Tide']]; }
  echo json_encode(['features' => $out]); exit;
}
if ($layer === 'basins') {
  // Mock drainage basins: Brisbane (143), Mary (138) and a few others (test shapes only).
  $bris = [[152.05,-26.75],[152.6,-26.65],[152.95,-27.0],[153.05,-27.3],[153.2,-27.42],[153.12,-27.55],[152.95,-27.75],[152.6,-27.85],[152.25,-27.8],[152.0,-27.6],[151.95,-27.3],[152.05,-26.75]];
  $mary = [[152.62,-26.80],[152.88,-26.80],[152.95,-26.55],[152.97,-26.42],[152.88,-26.30],[152.80,-26.22],[152.86,-26.00],[152.95,-25.85],[152.80,-25.55],[152.70,-25.45],[152.45,-25.60],[152.20,-25.95],[152.15,-26.15],[152.35,-26.40],[152.50,-26.60],[152.62,-26.80]];
  $box = fn($w,$s,$e,$n) => [[$w,$s],[$e,$s],[$e,$n],[$w,$n],[$w,$s]];
  $list = [
    1 => ['143', 'BRISBANE RIVER', $bris], 2 => ['138', 'MARY RIVER', $mary],
    3 => ['145', 'LOGAN-ALBERT RIVERS', $box(152.6,-28.4,153.3,-27.65)], 4 => ['142', 'PINE RIVER', $box(152.75,-27.45,153.1,-27.15)],
    5 => ['136', 'BURNETT RIVER', $box(150.5,-26.9,152.3,-24.6)], 6 => ['140', 'NOOSA RIVER', $box(152.88,-26.5,153.15,-25.9)],
    7 => ['130', 'FITZROY RIVER (QLD)', $box(146.5,-26.5,151.0,-21.5)], 8 => ['146', 'SOUTH COAST', $box(153.1,-28.3,153.55,-27.75)],
    9 => ['141', 'MAROOCHY RIVER', $box(152.85,-26.75,153.1,-26.45)], 10 => ['137', 'BURRUM RIVER', $box(152.3,-25.6,152.9,-25.0)],
    11 => ['139', 'BAFFLE CREEK', $box(151.4,-24.9,152.0,-24.1)],
  ];
  // Like the real service: only "just the number and name" attribute queries work.
  if (($_GET['returnGeometry'] ?? '') === 'false') {
    if (($_GET['outFields'] ?? '') !== 'basin_number,basin_name') { echo json_encode(['error' => ['code' => 500, 'message' => 'Error performing query operation', 'details' => []]]); exit; }
    $out = [];
    foreach ($list as $oid => [$num, $name]) { $out[] = ['attributes' => ['basin_number' => $num, 'basin_name' => $name]]; }
    echo json_encode(['objectIdFieldName' => 'OBJECTID', 'features' => $out]); exit;
  }
  // Like the real service: shape 5 always fails, and any filter fails.
  if (in_array('5', explode(',', $_GET['objectIds'] ?? ''), true)) { echo json_encode(['error' => ['code' => 500, 'message' => 'Error performing query operation', 'details' => []]]); exit; }
  // Like the real service: any filter fails.
  if (isset($_GET['where']) && $_GET['where'] !== '1=1') { echo json_encode(['error' => ['code' => 500, 'message' => 'Error performing query operation', 'details' => []]]); exit; }
  foreach ($list as $oid => [$num, $name, $ring]) { $feats[$oid] = ['attributes' => ['basin_number' => $num, 'basin_name' => $name], 'geometry' => ['rings' => [array_reverse($ring)]]]; }
} elseif ($layer === 'floodplain_mary') {
  // Along the Mary from Conondale to Maryborough, plus one blob in the Noosa (should be clipped off).
  $pts = [[152.71,-26.73],[152.70,-26.63],[152.73,-26.58],[152.74,-26.53],[152.74,-26.37],[152.66,-26.19],[152.60,-26.17],[152.55,-25.95],[152.60,-25.73],[152.70,-25.54],[153.05,-26.10]];
  foreach ($pts as $i => [$cx, $cy]) { $feats[$i + 1] = ['attributes' => ['OBJECTID' => $i + 1], 'geometry' => ['rings' => [blob($cx, $cy, 0.03)]]]; }
} elseif ($layer === 'catchment') {
  $ring = [[152.05,-26.75],[152.6,-26.65],[152.95,-27.0],[153.05,-27.3],[153.2,-27.42],[153.12,-27.55],[152.95,-27.75],[152.6,-27.85],[152.25,-27.8],[152.0,-27.6],[151.95,-27.3],[152.05,-26.75]];
  $feats[1] = ['attributes' => ['OBJECTID' => 1], 'geometry' => ['rings' => [array_reverse($ring)]]];
} else {
$segs = count($path_pts) - 1;
for ($s = 0; $s < $segs; $s++) {
  [$x0,$y0] = $path_pts[$s]; [$x1,$y1] = $path_pts[$s+1];
  $k = ['areas' => 40, 'flood2011' => 6, 'flood2022' => 4, 'flood1974' => 2][$layer] ?? 3;
  for ($j = 0; $j < $k; $j++) {
    $t = $j / $k; $cx = $x0 + ($x1-$x0)*$t; $cy = $y0 + ($y1-$y0)*$t;
    if ($layer === 'areas' && $cx < 152.9) { continue; }  // Council area only.
    if (!in_array($layer, ['flood2011', 'areas', 'floodplain'], true) && $cx < 152.9) { continue; }
    $r = ['areas' => 0.004 + mt_rand(0, 40) / 10000, 'flood2011' => 0.018, 'flood2022' => 0.012, 'flood1974' => 0.02, 'floodplain' => 0.03][$layer] ?? 0.01;
    $rings = [blob($cx + (mt_rand(-20,20)/10000), $cy + (mt_rand(-20,20)/10000), $r)];
    if ($layer === 'flood2011' && $j === 0) { $rings[] = array_reverse(blob($cx, $cy, $r/4, 10)); } // A hole.
    $rings[] = blob($cx + 0.03, $cy + 0.03, 0.00005, 6); // A speck that should be dropped.
    $feats[$id] = ['attributes' => ['OBJECTID' => $id], 'geometry' => ['rings' => $rings]];
    $id++;
  }
}
}
if (($_GET['returnIdsOnly'] ?? '') === 'true') { echo json_encode(['objectIdFieldName' => 'OBJECTID', 'objectIds' => array_keys($feats)]); exit; }
$want = array_map('intval', explode(',', $_GET['objectIds'] ?? ''));
$out = []; foreach ($want as $w) { if (isset($feats[$w])) { $out[] = $feats[$w]; } }
echo json_encode(['features' => $out]);
