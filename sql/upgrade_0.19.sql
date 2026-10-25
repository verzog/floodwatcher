-- Flood Watcher 0.19: catchments. "All BoM flood warnings" in a push sign-up is for one catchment
-- (or "all"); sign-ups made before 0.19 were for the Brisbane River.
ALTER TABLE push_subscriptions
    ADD COLUMN IF NOT EXISTS warn_catchment VARCHAR(32) NOT NULL DEFAULT 'brisbane' AFTER warnings;
