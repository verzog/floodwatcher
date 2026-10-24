-- Flood Watcher 0.12: saved notification areas (name and map box) per browser.
ALTER TABLE push_subscriptions ADD COLUMN IF NOT EXISTS areas TEXT NULL AFTER watch;
