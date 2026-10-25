<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE.

/**
 * Default settings. config.php only needs the database details; anything set there overrides
 * the matching value here, so older config files keep working after an upgrade.
 */

declare(strict_types=1);

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'my_webapp',
        'user'     => 'my_webapp',
        'password' => '',
    ],

    // Brisbane City Council open data (Opendatasoft Explore API v2.1).
    'bcc' => [
        'records_url'     => 'https://data.brisbane.qld.gov.au/api/explore/v2.1/catalog/datasets/'
            . 'telemetry-sensors-rainfall-and-stream-heights/records',
        'overlap_minutes' => 60,
        'page_size'       => 100,
    ],

    // Queensland Government Water Monitoring Information Portal (WMIP) web service.
    'qld' => [
        'url'            => 'https://water-monitoring.information.qld.gov.au/cgi/webservice.exe',
        'sites_file'     => dirname(__DIR__) . '/data/qld_sites.csv',
        'backfill_days'  => 3,    // How far back the first run loads.
        'overlap_hours'  => 3,    // Re-check this far back on each run.
        'tz_offset_hours' => 10,  // WMIP times are Brisbane time (UTC+10); converted to UTC.
        // During the daily station-capability recheck, skip a station that times out (HTTP 408)
        // and carry on; if this many fail, treat the service as down and error the run (lesson 5).
        'max_station_timeouts' => 15,
    ],

    // Bureau of Meteorology anonymous FTP products.
    'bom' => [
        'base_url'          => 'ftp://ftp.bom.gov.au/anon/gen/fwo/',
        // River map pages (names, positions, flood levels) and rain map pages (names, positions,
        // past-hour totals; totals since 9am). The first of each is BoM's south-east Queensland map;
        // add the pages bin/discover_catchment.php finds for other catchments.
        // IDQ65420: the Wide Bay and Burnett map, with the whole Mary River (checked 25/10/2026).
        'river_maps'        => ['IDQ65412.html', 'IDQ65420.html'],
        // Rain pages come in threes: 24 hours, since 9am and past hour (checked 25/10/2026 from the
        // period each gauge's figure covers). South-east Queensland: IDQ65413-15; Wide Bay and
        // Burnett, with the Mary River: IDQ65421-23.
        'rain_maps'         => ['IDQ65415.html', 'IDQ65423.html'],
        'rain_9am_maps'     => ['IDQ65414.html', 'IDQ65422.html'],
        // Brisbane River gauges: BoM's Brisbane/Moreton gauge group, less data/bom_exclude.csv. Gauges
        // for the other live catchments are those inside the catchment's outline (lib/catchments.php).
        'river_group'       => 'IDQ65389',
        'exclude_file'      => dirname(__DIR__) . '/data/bom_exclude.csv',
        'tidal_file'        => dirname(__DIR__) . '/data/bom_tidal.csv',
        'metadata_minutes'  => 60,              // Refresh names/levels this often.
        'rain_max_km'       => 15,              // Rain gauge must be this close to an included river gauge.
        // A BoM flood warning belongs to a catchment if its title or areas mention any of these
        // (not case-sensitive). One warning can belong to several catchments.
        'warning_keywords'  => [
            'brisbane' => [
                'Brisbane River', 'Stanley River', 'Lockyer', 'Laidley', 'Tenthill', 'Ma Ma Creek',
                'Bremer', 'Warrill', 'Purga', 'Western Creek', 'Bundamba', 'Woogaroo', 'Oxley Creek',
                'Cressbrook', 'Emu Creek', 'Wivenhoe', 'Somerset Dam', 'Brisbane Metropolitan',
                'Brisbane Creeks', 'Kedron Brook', 'Enoggera', 'Moggill', 'Breakfast Creek', 'Norman Creek',
                'Bulimba Creek', 'Wynnum',
            ],
            'mary' => [
                'Mary River', 'Gympie', 'Maryborough', 'Tiaro', 'Six Mile Creek', 'Yabba Creek', 'Obi Obi',
                'Kandanga', 'Amamoor', 'Tinana Creek', 'Wide Bay Creek', 'Glastonbury Creek', 'Munna Creek',
                'Borumba', 'Baroon Pocket', 'Kenilworth', 'Conondale',
            ],
        ],
    ],

    // Catchments. The live ones are shown in full; every other Queensland catchment is listed on the
    // page as "under construction". 'basin' is the state's drainage basin number (the outline
    // comes from the state's drainage basins map, bin/fetch_catchments.php).
    'catchments' => [
        'default'        => 'brisbane',
        'basins_url'     => 'https://spatial-gis.information.qld.gov.au/arcgis/rest/services/InlandWaters/'
            . 'DrainageBasins/MapServer/1',
        'outline_offset' => 0.0003, // Simplification of the live catchments' outlines (about 30 m).
        'list_offset'    => 0.003,  // And of the others (about 300 m), to keep the page quick.
        'live'           => [
            'brisbane' => ['name' => 'Brisbane River', 'basin' => '143'],
            'mary'     => ['name' => 'Mary River', 'basin' => '138'],
        ],
    ],

    'http' => [
        'timeout_seconds' => 30,
        'user_agent'      => 'SEQFloodWatch/' . (require dirname(__DIR__) . '/version.php') . ' (community flood dashboard)',
    ],

    // Flood map shading, downloaded weekly by bin/fetch_overlays.php and served with the site.
    // offset = simplification in degrees (0.00008 is about 8 m); min_m2 drops specks smaller than this.
    'overlays' => [
        'batch'          => 250,  // Shapes per request.
        'likelihood_url' => 'https://services2.arcgis.com/dEKgZETqwmDAh1rP/ArcGIS/rest/services/'
            . 'Flood_Awareness_Flood_Risk_Overall/FeatureServer/0',
        'likelihood_link' => 'https://www.brisbane.qld.gov.au/community-and-safety/community-safety/'
            . 'disasters-and-emergencies/be-prepared/flooding-in-brisbane/flood-awareness-map',
        // Layers are drawn in this order (later ones on top). Each belongs to one catchment and is
        // offered only while that catchment is shown. Optional per layer: 'where' (which shapes to
        // take), 'box_of' (only shapes touching that catchment's box), 'clip' (then only shapes
        // centred inside its outline) and 'batch' (shapes per request, for very large shapes).
        // Catchment outlines come from bin/fetch_catchments.php, not from here.
        'layers' => [
            [
                'key'    => 'floodplain',
                'label'  => 'Possible floodplain, whole catchment (state, broad-scale)',
                'hover'  => 'Possible floodplain (state broad-scale map)',
                'url'    => 'https://spatial-gis.information.qld.gov.au/arcgis/rest/services/FloodCheck/'
                    . 'RapidHazardAssessment/MapServer/0',
                // The Brisbane River catchment's sub-basins in this layer.
                'where'  => "sub_name IN ('Brisbane River','Bremer River','Lockyer Creek','Stanley River')",
                'batch'  => 1,
                'offset' => 0.0002,
                'min_m2' => 2000,
                'on'     => true,
                'source' => '© State of Queensland (Department of Resources), FloodCheck Rapid Hazard Assessment',
                'catchment' => 'brisbane',
            ],
            [
                'key'    => 'floodplain_mary',
                'label'  => 'Possible floodplain, whole catchment (state, broad-scale)',
                'hover'  => 'Possible floodplain (state broad-scale map)',
                'url'    => 'https://spatial-gis.information.qld.gov.au/arcgis/rest/services/FloodCheck/'
                    . 'RapidHazardAssessment/MapServer/0',
                // The Mary's sub-basins in this layer (bin/discover_catchment.php mary, 25/10/2026).
                'where'  => "sub_name IN ('Upper Mary River','Lower Mary River')",
                'batch'  => 1,
                'offset' => 0.0002,
                'min_m2' => 2000,
                'on'     => true,
                'source' => '© State of Queensland (Department of Resources), FloodCheck Rapid Hazard Assessment',
                'catchment' => 'mary',
            ],
            [
                'key'    => 'areas',
                'label'  => 'Areas that can flood (Brisbane)',
                'hover'  => 'Council flood awareness area',
                'url'    => 'https://services2.arcgis.com/dEKgZETqwmDAh1rP/ArcGIS/rest/services/'
                    . 'Flood_Awareness_Extents/FeatureServer/0',
                'offset' => 0.00008,
                'min_m2' => 400,
                'on'     => true,
                'source' => '© Brisbane City Council, Flood Awareness Map',
                'catchment' => 'brisbane',
            ],
            [
                'key'    => 'flood2011',
                'label'  => 'January 2011 flood (Brisbane and Ipswich)',
                'hover'  => 'In the January 2011 flood extent',
                'url'    => 'https://gisservices.qra.qld.gov.au/arcgis/rest/services/RESILIENT_QLD/'
                    . 'Resilient_Queensland_Case_Studies/MapServer/1',
                'offset' => 0.00005,
                'min_m2' => 200,
                'on'     => true,
                'source' => '© State of Queensland (Queensland Reconstruction Authority)',
                'catchment' => 'brisbane',
            ],
            [
                'key'    => 'flood2022',
                'label'  => 'February 2022 flood (Brisbane)',
                'hover'  => 'In the February 2022 flood extent',
                'url'    => 'https://services2.arcgis.com/dEKgZETqwmDAh1rP/ArcGIS/rest/services/'
                    . 'Flood_Awareness_Historic_Brisbane_River_and_Creek_Floods_Feb2022/FeatureServer/0',
                'offset' => 0.00005,
                'min_m2' => 200,
                'on'     => false,
                'source' => '© Brisbane City Council',
                'catchment' => 'brisbane',
            ],
            [
                'key'    => 'flood1974',
                'label'  => 'January 1974 flood (Brisbane)',
                'hover'  => 'In the January 1974 flood extent',
                'url'    => 'https://services2.arcgis.com/dEKgZETqwmDAh1rP/ArcGIS/rest/services/'
                    . 'Flood_Awareness_Historic_Brisbane_River_Floods_Jan1974/FeatureServer/0',
                'offset' => 0.00005,
                'min_m2' => 200,
                'on'     => false,
                'source' => '© Brisbane City Council',
                'catchment' => 'brisbane',
            ],
        ],
    ],

    // QLDTraffic (Department of Transport and Main Roads) road events: flooding and closures.
    'traffic' => [
        'events_url'   => 'https://api.qldtraffic.qld.gov.au/v2/events',
        // QLDTraffic's shared public key (100 requests a minute shared by everyone). When TMR issues
        // your own key, put it in config.php: 'traffic' => ['api_key' => '...'].
        'api_key'      => '3e83add325cbb69ac4d8e5bf433d770b',
        // West, south, east, north: the Brisbane River area. Widened to take in the other live
        // catchments once their outlines are downloaded (bin/fetch_catchments.php).
        'bbox'         => [151.85, -27.95, 153.25, -26.55],
        // Councils in each live catchment (matched against the event's council name). An event in
        // another live catchment is kept only if it's inside that catchment's outline.
        'councils'     => [
            'brisbane' => ['Brisbane City', 'Ipswich City', 'Lockyer Valley', 'Somerset', 'Toowoomba', 'Scenic Rim'],
            'mary'     => ['Gympie', 'Sunshine Coast', 'Noosa', 'Fraser Coast', 'South Burnett'],
        ],
        'types_all'    => ['Flooding'],          // Always shown.
        'types_closed' => ['Hazard', 'Crash'],   // Shown only when the road is closed.
        'max_points'   => 400,                   // Thin long road lines to about this many points.
        'stale_minutes' => 60,                   // Page warns if road data is older than this.
        'quota_pause_minutes' => 60,             // After "over the limit" (HTTP 429), wait this long.
    ],

    // Community rain gauges from Weather Underground (unofficial; a separate map layer only, never
    // used for alerts or flood rules). bin/collect_wu.php checks weekly which stations work;
    // www/wu.php looks up rain for the stations in the map view. Needs a Weather Underground API
    // key, issued free to people whose own station uploads to Weather Underground. In config.php:
    // 'wu' => ['enabled' => true, 'api_key' => '...'].
    'wu' => [
        'enabled'         => false,
        'api_key'         => '',
        'base_url'        => 'https://api.weather.com',
        'daily_limit'     => 1300, // Requests a day, shared by the weekly check and map lookups.
        'per_minute'      => 25,   // The free key allows about 30 a minute.
        'discover_days'   => 7,    // Check which stations are working this often.
        'grid_step'       => 0.2,  // Degrees between search points across the live catchments.
        // West, south, east, north; widened to all the live catchments once their outlines are in.
        'bbox'            => [151.85, -27.95, 153.25, -26.55],
        'pause_ms'        => 2500, // Between requests during the weekly check.
        'cache_minutes'   => 15,   // Map lookups reuse a station's reading for this long.
        'max_per_view'    => 25,   // At most this many stations looked up per map view.
        // Flag a station as suspect if its rain today is below this share of the official gauges
        // within 'check_km' (when they've had at least 'check_min_mm'), or far above them.
        'check_km'        => 10,
        'check_min_mm'    => 15,
        'check_low_share' => 0.3,
    ],

    // Flood cameras shown on the map (edit this list; build_json.php publishes it).
    'cameras_file' => dirname(__DIR__) . '/data/cameras.csv',

    'lock_file' => null, // Default: collector.lock in 'var_dir'.

    // Where the dashboard's data files are written (My Webapp's public www/data folder).
    'public_data_dir' => dirname(__DIR__, 2) . '/www/data',
    // Private data: state files, locks, push notification keys, history downloads. Never inside
    // the public web folder. On YunoHost the package puts this in /home/yunohost.app/<app>/var.
    'var_dir'         => dirname(__DIR__) . '/var',
    // An optional extra settings file loaded after config.php (YunoHost: your own changes).
    'local_config'    => '',

    // Push notifications (browser notifications even when the page is closed).
    'push' => [
        'enabled'           => true,
        'site_url'          => 'https://floodwatcher.thefeaturecreep.noho.st', // Links in notifications.
        'subject'           => 'https://floodwatcher.thefeaturecreep.noho.st', // Who push services contact.
        'ttl_seconds'       => 6 * 3600, // Push services drop a message not delivered by then.
        'quiet_minutes'     => 60,       // An alert that ends and comes back within this isn't re-sent.
        'max_subscriptions' => 1000,
        'max_watch'         => 50,
        // The server only ever posts to these push services (host, or a suffix starting with ".").
        'allowed_hosts'     => [
            'fcm.googleapis.com', '.push.services.mozilla.com', '.notify.windows.com', '.push.apple.com',
        ],
    ],

    // Historical downloads for working out flood potential rules (bin/history_fetch.php).
    'history' => [
        'dir'                  => null,  // Default: history/ in 'var_dir'.
        'from_year'            => 1990,
        'pause_ms'             => 400,   // Wait between requests to the state service.
        'regions'              => [
            // Lockyer Valley: state stations from Helidon down to Rifle Range Road, and tributaries.
            'lockyer' => ['143203C', '143207B', '143209B', '143210B', '143212A', '143213C', '143229A', '143233A'],
            // Bremer River and Warrill, Purga and Western creeks (Ipswich).
            'bremer' => ['143107A', '143108A', '143110A', '143113A', '143121A'],
            // Brisbane River below Wivenhoe: Savages Crossing, with the lower Lockyer gauges that
            // feed it. Wivenhoe Dam release history isn't in this service.
            'brisbane_mid' => ['143001C', '143210B', '143207B'],
            // Stanley River down to Lake Somerset.
            'stanley' => ['143303A', '143306B', '143312A', '143901A'],
            // Upper Brisbane River down to Lake Wivenhoe.
            'upper_brisbane' => ['143007A', '143009A', '143010B', '143015B', '143016A', '143017A', '143921A'],
        ],
        // A heavy-rain event: any station with this much in 24 hours, or the average of the
        // stations with this much in 72 hours. Hourly river data is fetched around each one.
        'event_station_24h_mm' => 50,
        'event_mean_72h_mm'    => 40,
        'window_before_days'   => 3,
        'window_after_days'    => 7,
    ],

    // Flood potential rules, worked out from 30 years of state gauge history (see README).
    // Heights use BoM's gauge zero at the same sites (checked against BoM on 22/10/2026).
    // Dams in the live catchments, shown on the map and counted in the "water coming here"
    // score. Status comes from Seqwater's dam levels page (bin/collect_dams.php) and BoM's gauges:
    //   'level'  the lake gauge (live level and trend); 'tail' the gauge just below the dam, if any
    //   (a quick rise there means water is being released); 'fsl' full supply level in m AHD, where
    //   known and current (a lake above it is holding flood water); 'node' the dam's place on the
    //   river network (data/network.csv) or, for dams not on it, 'down' the gauge its water
    //   reaches first. Wivenhoe and Somerset run at reduced full supply levels (about 80%) during
    //   Seqwater's dam improvement work, so their 'fsl' is left empty: whether they're holding flood
    //   water comes from Seqwater's flood storage figures instead. 'into': the dam whose lake this
    //   one empties into (Somerset and Cressbrook into Lake Wivenhoe); below that dam, this one
    //   only counts through it.
    'dams' => [
        'seqwater_url'   => 'https://www.seqwater.com.au/water-supply/dam-levels',
        'releases_url'   => 'https://www.seqwater.com.au/dam-release-update',
        'stale_hours'    => 6,    // Seqwater figures older than this aren't used.
        'tail_rise_m'    => 0.5,  // Rise below the dam (over 6 hours) that shows water is being let out.
        'list'           => [
            ['key' => 'wivenhoe', 'name' => 'Wivenhoe Dam', 'seqwater' => 'Wivenhoe', 'lat' => -27.3944, 'lon' => 152.6097,
                'level' => 'B540177-H', 'tail' => 'B540178-H', 'fsl' => null, 'node' => 'B540177-H', 'catchment' => 'brisbane'],
            ['key' => 'somerset', 'name' => 'Somerset Dam', 'seqwater' => 'Somerset', 'lat' => -27.1169, 'lon' => 152.555,
                'level' => 'B040820-H', 'tail' => null, 'fsl' => null, 'node' => 'B040820-H', 'into' => 'B540177-H',
                'catchment' => 'brisbane'],
            ['key' => 'cressbrook', 'name' => 'Cressbrook Dam', 'seqwater' => 'Cressbrook', 'lat' => -27.2633,
                'lon' => 152.2089, 'level' => 'B540750-H', 'tail' => null, 'fsl' => null, 'node' => 'B540750-H',
                'into' => 'B540177-H', 'catchment' => 'brisbane'],
            // Atkinson Dam is filled from Buaraba Creek; check: where its overflow reaches the river.
            ['key' => 'atkinson', 'name' => 'Atkinson Dam', 'seqwater' => 'Atkinson', 'lat' => -27.4319, 'lon' => 152.4642,
                'level' => 'B540547-H', 'tail' => null, 'fsl' => null, 'down' => 'B540182-H', 'catchment' => 'brisbane'],
            // Moogerah Dam is on Reynolds Creek, which joins Warrill Creek above Kalbar.
            ['key' => 'moogerah', 'name' => 'Moogerah Dam', 'seqwater' => 'Moogerah', 'lat' => -28.0314, 'lon' => 152.55,
                'level' => 'B540474-H', 'tail' => 'B540513-H', 'fsl' => null, 'down' => 'B540058-H', 'catchment' => 'brisbane'],
            // Lake Manchester is on Cabbage Tree Creek, which meets the Brisbane River at Mt Crosby.
            ['key' => 'manchester', 'name' => 'Lake Manchester', 'seqwater' => 'Lake Manchester', 'lat' => -27.4883,
                'lon' => 152.7515, 'level' => 'B540606-H', 'tail' => null, 'fsl' => null, 'down' => 'B540199-H',
                'catchment' => 'brisbane'],
            // Mary River catchment. Borumba Dam is on Yabba Creek, Baroon Pocket on Obi Obi Creek,
            // Lake Macdonald on Six Mile Creek and Cedar Pocket on Deep Creek near Gympie. All four
            // spill over an ungated spillway. Lake Macdonald runs at a reduced level during
            // Seqwater's dam upgrade.
            ['key' => 'borumba', 'name' => 'Borumba Dam', 'seqwater' => 'Borumba', 'lat' => -26.5078, 'lon' => 152.5828,
                'level' => 'B540575-H', 'tail' => 'B540514-H', 'fsl' => null, 'node' => 'B540575-H', 'catchment' => 'mary'],
            ['key' => 'baroon', 'name' => 'Baroon Pocket Dam', 'seqwater' => 'Baroon Pocket', 'lat' => -26.712,
                'lon' => 152.8804, 'level' => 'B540426-H', 'tail' => null, 'fsl' => null, 'node' => 'B540426-H',
                'catchment' => 'mary'],
            ['key' => 'macdonald', 'name' => 'Lake Macdonald', 'seqwater' => 'Lake Macdonald', 'lat' => -26.3814,
                'lon' => 152.9297, 'level' => 'B540463-H', 'tail' => null, 'fsl' => null, 'node' => 'B540463-H',
                'catchment' => 'mary'],
            ['key' => 'cedarpocket', 'name' => 'Cedar Pocket Dam', 'seqwater' => 'Cedar Pocket', 'lat' => -26.2114,
                'lon' => 152.7927, 'level' => 'B540476-H', 'tail' => 'B540618-H', 'fsl' => null, 'node' => 'B540476-H',
                'catchment' => 'mary'],
        ],
    ],

    'potential' => [
        [
            'key'      => 'lockyer_lower',
            'catchment' => 'brisbane',
            'name'     => 'lower Lockyer (Rifle Range Rd, Glenore Grove, Lyons Bridge)',
            'place'    => 'Rifle Range Rd',
            'lat'      => -27.4667,
            'lon'      => 152.5333,
            // The area the rule is about (S, W, N, E); the alert shows when any of it is on screen.
            // The stretch it's about: from just above Glenore Grove to where Lockyer Creek meets the
            // Brisbane River. The shaded area is the flood-prone land in this box joined to the
            // 'seeds' (the gauges named above), so other creeks and rivers in the box are left out.
            'bbox'     => [-27.585, 152.36, -27.395, 152.592],
            'seeds'    => [[-27.4667, 152.5333], [-27.5231, 152.4092], [-27.4201, 152.5308]],
            'triggers' => [
                ['label' => 'Helidon', 'ids' => ['B540143-H', 'Q143203C-H'], 'm' => 4.0],
                ['label' => 'Tenthill Creek at Tenthill', 'ids' => ['B540152-H', 'Q143212A-H'], 'm' => 3.5],
                ['label' => 'Laidley Creek at the Warrego Highway', 'ids' => ['B540529-H', 'Q143229A-H'], 'm' => 6.0],
            ],
            'lead'     => 'about 6 to 28 hours',
            'record'   => 'Since 1995 this came before all 14 minor floods there, with 2 false alarms',
            'rain'     => [
                'ids'    => ['Q143203C-R', 'Q143212A-R'], // Helidon and Tenthill (state gauges).
                'mm_72h' => 70,
                // The watch ends after this many dry hours (under 'wet_mm' an hour on average)
                // with no trigger gauge rising. Since 1995, floods at Rifle Range Rd came at most
                // 21 hours after the rain stopped; 30 leaves a margin.
                'dry_hours' => 30,
                'wet_mm'    => 1.0,
                'record' => 'Since 1995, 12 of 14 floods at Rifle Range Rd followed this much rain; it also happened 24 times without a flood there',
            ],
        ],
    ],

    // Gauge alerts shown on the page (and later sent by push and email).
    'alerts' => [
        'near_minor_m'  => 0.5,   // "Approaching": rising and within this of the minor flood level.
        'rising_fast'   => true,  // Also alert when a (non-tidal) gauge is rising fast.
        'cleared_hours' => 6,     // Show "all clear" notes for this long.
        // A river level that jumps at least 'spike_m' between two readings no more than
        // 'spike_minutes' apart is treated as a possibly faulty reading (no alert, shown with a
        // "?") unless another gauge on the same river within 'spike_km' rose too, or (with no
        // such gauge) 'spike_confirm' more readings stay near the new level. Stops one bad
        // reading (such as Brassall's 13 m jump on 24/10/2026) from raising a flood alert.
        'spike_m'       => 3.0,
        'spike_minutes' => 90,
        'spike_km'      => 25,
        'spike_confirm' => 2,
        'spike_backing_m' => 0.3, // A same-river neighbour must have risen at least this much to back a jump.
    ],

    'dashboard' => [
        'series_hours'       => 72,
        'trend_minutes'      => 60,   // Council and BoM gauges: compare with this long ago.
        'trend_minutes_qld'  => 120,  // State gauges report less often, so use a longer window.
        'rising_m_per_hr'    => 0.05,
        'fast_m_per_hr'      => 0.30,
        'rain_stale_minutes' => 30,
        'stale_minutes'      => ['bcc' => 30, 'bom' => 120, 'qld' => 180, 'wu' => 60],
        'merge_metres'       => 150,  // Gauges this close together become one map marker.
        'rain_now_minutes'   => 45,   // Use BoM's current rain figures if no older than this.
    ],
];
