-- Upgrade a version 0.1/0.2 database to 0.3. Safe to run more than once (MariaDB).
-- Usage: mysql my_webapp < sql/upgrade_0.3.sql

ALTER TABLE sensors
    MODIFY sensor_type ENUM('rain', 'stream', 'flow') NOT NULL,
    ADD COLUMN IF NOT EXISTS catchment      VARCHAR(32)  NOT NULL DEFAULT 'brisbane' AFTER longitude,
    ADD COLUMN IF NOT EXISTS flood_minor    DECIMAL(9,3) NULL AFTER tidal,
    ADD COLUMN IF NOT EXISTS flood_moderate DECIMAL(9,3) NULL AFTER flood_minor,
    ADD COLUMN IF NOT EXISTS flood_major    DECIMAL(9,3) NULL AFTER flood_moderate,
    ADD INDEX IF NOT EXISTS idx_sensors_source (source);

-- Flow values (cubic metres per second) can exceed the old column's range in big floods.
ALTER TABLE readings MODIFY value DECIMAL(10,3) NOT NULL;
