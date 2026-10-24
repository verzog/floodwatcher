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
    predictive     TINYINT(1)    NOT NULL DEFAULT 1,   -- 1 = send predictive flood alerts (0.15).
    created_utc    DATETIME      NOT NULL,
    updated_utc    DATETIME      NOT NULL,
    last_test_utc  DATETIME      NULL,
    failures       INT           NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
