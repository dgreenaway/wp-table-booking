CREATE TABLE IF NOT EXISTS pings (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    plugin_version   TEXT NOT NULL,
    wp_version       TEXT NOT NULL,
    php_version      TEXT NOT NULL,
    booking_mode     TEXT NOT NULL DEFAULT 'simple',
    table_count      TEXT NOT NULL DEFAULT '0',
    reservation_total TEXT NOT NULL DEFAULT '0',
    locale           TEXT NOT NULL DEFAULT 'en_US',
    is_multisite     TEXT NOT NULL DEFAULT '0',
    created_at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_plugin_version ON pings (plugin_version);
CREATE INDEX IF NOT EXISTS idx_created_at     ON pings (created_at);
