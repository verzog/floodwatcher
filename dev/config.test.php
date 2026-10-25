<?php
// Settings for testing against the mock services in dev/mock (see dev/README.md). Used through
// the SFW_CONFIG environment variable, which dev/start.sh sets. Never on a real server.

declare(strict_types=1);

$tmp = getenv('FW_TEST_DIR') ?: sys_get_temp_dir() . '/floodwatcher-test';

return [
    'db' => [
        'host'     => getenv('FW_DB_HOST') ?: 'localhost',
        'name'     => getenv('FW_DB_NAME') ?: 'floodwatcher_test',
        'user'     => getenv('FW_DB_USER') ?: 'root',
        'password' => getenv('FW_DB_PASSWORD') ?: '',
    ],
    'public_data_dir' => $tmp . '/site/data',
    'var_dir'         => $tmp . '/var',
    'bcc'      => ['records_url' => 'http://127.0.0.1:8099/'],
    'qld'      => ['url' => 'http://127.0.0.1:8101/'],
    'bom'      => ['base_url' => 'ftp://127.0.0.1:2121/'],
    // Any key but "over" (which the mock answers with "over the limit", HTTP 429).
    'traffic'  => ['events_url' => 'http://127.0.0.1:8103/v2/events', 'api_key' => 'test'],
    'push'     => ['allowed_hosts' => ['*insecure-test*'], 'site_url' => 'http://127.0.0.1:8102'],
    'wu'       => ['enabled' => true, 'api_key' => 'testkey', 'base_url' => 'http://127.0.0.1:8106', 'pause_ms' => 0,
        'grid_step' => 0.5],
    'catchments' => ['basins_url' => 'http://127.0.0.1:8103/x/basins'],
    'dams'     => ['seqwater_url' => 'http://127.0.0.1:8103/seq/levels', 'releases_url' => 'http://127.0.0.1:8103/seq/release'],
    // Settings are merged by position for lists, so this list must match lib/defaults.php's
    // 'overlays' => 'layers' one for one, in the same order.
    'overlays' => [
        'batch'          => 50,
        'likelihood_url' => 'http://127.0.0.1:8103/x/Flood_Risk_Overall',
        'layers'         => [
            ['url' => 'http://127.0.0.1:8103/x/floodplain'],
            ['url' => 'http://127.0.0.1:8103/x/floodplain_mary', 'where' => '1=1'],
            ['url' => 'http://127.0.0.1:8103/x/areas'],
            ['url' => 'http://127.0.0.1:8103/x/flood2011'],
            ['url' => 'http://127.0.0.1:8103/x/flood2022'],
            ['url' => 'http://127.0.0.1:8103/x/flood1974'],
        ],
    ],
];
