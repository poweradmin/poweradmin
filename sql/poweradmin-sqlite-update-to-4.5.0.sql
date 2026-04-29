-- Poweradmin schema update to 4.5.0
-- Add log_record_changes table to capture structured before/after snapshots
-- of record/zone mutations, enabling the diff-style change-log UI and email
-- digest reports. The existing log_zones activity feed is unchanged.

CREATE TABLE IF NOT EXISTS log_record_changes (
    id integer PRIMARY KEY,
    zone_id integer,
    record_id TEXT,
    action VARCHAR(32) NOT NULL,
    user_id integer,
    username VARCHAR(64) NOT NULL,
    before_state TEXT,
    after_state TEXT,
    client_ip VARCHAR(64),
    created_at timestamp DEFAULT current_timestamp NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_log_record_changes_created_at ON log_record_changes(created_at);
CREATE INDEX IF NOT EXISTS idx_log_record_changes_zone_id ON log_record_changes(zone_id);
CREATE INDEX IF NOT EXISTS idx_log_record_changes_action ON log_record_changes(action);
