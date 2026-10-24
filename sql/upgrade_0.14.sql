-- Flood Watcher 0.14: community rain stations (Weather Underground), looked up for the map view.
-- Positions are rounded to about 1 km; station names are not stored.
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

-- The 0.13 approach (12 stations stored as gauges) is retired.
UPDATE sensors SET active = 0, location_name = CONCAT('Community gauge ', location_id) WHERE source = 'wu';
