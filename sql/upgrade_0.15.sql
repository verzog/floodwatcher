-- Flood Watcher 0.15: a separate choice for predictive flood alert notifications.
ALTER TABLE push_subscriptions ADD COLUMN IF NOT EXISTS predictive TINYINT(1) NOT NULL DEFAULT 1 AFTER warnings;
