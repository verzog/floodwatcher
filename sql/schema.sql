-- SEQ Flood Watch: database tables (MariaDB / MySQL). Version 0.3.
-- All times are stored in UTC. The dashboard converts to Brisbane time for display.
-- Safe to run more than once: tables are only created if they don't already exist.
-- Upgrading an existing 0.1/0.2 database? Run sql/upgrade_0.3.sql instead.

CREATE TABLE IF NOT EXISTS sensors (
    sensor_id      VARCHAR(16)   NOT NULL,            -- E1580 (council), B540198-H (BoM), Q143207B-F (state).
    source         VARCHAR(8)    NOT NULL,            -- 'bcc' council, 'bom' Bureau of Meteorology, 'qld' state.
    location_id    VARCHAR(16)   NULL,                -- Station number shared by sensors at the same site.
    location_name  VARCHAR(128)  NOT NULL,
    sensor_type    ENUM('rain', 'stream', 'flow') NOT NULL,
    unit           VARCHAR(8)    NOT NULL,            -- 'mm' rain, 'm' height, 'm3/s' flow.
    datum          VARCHAR(8)    NULL,                -- 'AHD' or 'LGH' (local gauge height) for heights.
    latitude       DECIMAL(9,6)  NOT NULL,
    longitude      DECIMAL(9,6)  NOT NULL,
    catchment      VARCHAR(32)   NOT NULL DEFAULT 'brisbane',
    tidal          ENUM('yes', 'partial', 'no') NULL, -- Stream gauges only.
    flood_minor    DECIMAL(9,3)  NULL,                -- Official BoM flood classification levels,
    flood_moderate DECIMAL(9,3)  NULL,                -- in the same datum as the gauge's readings.
    flood_major    DECIMAL(9,3)  NULL,
    quality_note   VARCHAR(255)  NULL,                -- Known problems, e.g. a gauge that looks stuck.
    active         TINYINT(1)    NOT NULL DEFAULT 1,
    updated_utc    DATETIME      NOT NULL,
    PRIMARY KEY (sensor_id),
    KEY idx_sensors_location (location_id),
    KEY idx_sensors_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per sensor per reading time.
-- Rain: millimetres in the interval ending at that time (5 minutes for council, 1 hour for
-- BoM and state gauges). Stream: water level in metres. Flow: cubic metres per second.
CREATE TABLE IF NOT EXISTS readings (
    sensor_id      VARCHAR(16)   NOT NULL,
    measured_utc   DATETIME      NOT NULL,
    value          DECIMAL(10,3) NOT NULL,
    PRIMARY KEY (sensor_id, measured_utc),
    KEY idx_readings_time (measured_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A log of every collector run, so problems (source down, no new data) are easy to spot.
CREATE TABLE IF NOT EXISTS collector_runs (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    source             VARCHAR(8)    NOT NULL,
    started_utc        DATETIME      NOT NULL,
    finished_utc       DATETIME      NULL,
    status             ENUM('running', 'ok', 'error') NOT NULL DEFAULT 'running',
    records_fetched    INT UNSIGNED  NOT NULL DEFAULT 0,
    readings_inserted  INT UNSIGNED  NOT NULL DEFAULT 0,
    newest_reading_utc DATETIME      NULL,
    message            VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY idx_runs_source_time (source, started_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flood Watcher 0.9: push notification subscriptions.
-- Each row is one browser that asked for notifications: its push address and encryption keys
-- (issued by the browser's push service; no name, email or IP address is stored), the gauges
-- it watches, and whether it wants BoM flood warnings.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    endpoint_hash  CHAR(64)      NOT NULL,             -- SHA-256 of the endpoint, for lookups.
    endpoint       VARCHAR(1024) NOT NULL,
    p256dh         VARCHAR(128)  NOT NULL,
    auth           VARCHAR(64)   NOT NULL,
    watch          TEXT          NOT NULL,             -- JSON list of gauge ids.
    areas          TEXT          NULL,                 -- JSON list of {name, s, w, n, e} boxes (0.12).
    warnings       TINYINT(1)    NOT NULL DEFAULT 1,   -- 1 = also send BoM flood warnings.
    warn_catchment VARCHAR(32)   NOT NULL DEFAULT 'brisbane', -- Which catchment's warnings, or 'all' (0.19).
    predictive     TINYINT(1)    NOT NULL DEFAULT 1,   -- 1 = send predictive flood alerts (0.15).
    created_utc    DATETIME      NOT NULL,
    updated_utc    DATETIME      NOT NULL,
    last_test_utc  DATETIME      NULL,
    failures       INT           NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wu_stations (
    station_id   VARCHAR(24)   NOT NULL,
    latitude     DECIMAL(8,4)  NOT NULL,
    longitude    DECIMAL(8,4)  NOT NULL,
    active       TINYINT(1)    NOT NULL DEFAULT 0,   -- 1 = reporting rainfall at the last check.
    checked_utc  DATETIME      NULL,                 -- Last weekly check.
    obs_epoch    INT           NULL,                 -- Time of the latest observation fetched.
    today_mm     DECIMAL(7,2)  NULL,                 -- Rain since local midnight, as the station reports it.
    rate_mm      DECIMAL(7,2)  NULL,                 -- Current rain rate, mm per hour.
    fetched_utc  DATETIME      NULL,                 -- When that observation was fetched.
    PRIMARY KEY (station_id),
    KEY idx_wu_pos (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

