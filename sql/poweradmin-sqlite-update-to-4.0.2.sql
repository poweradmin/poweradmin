-- Fix password_reset_tokens used field default value if it exists but lacks proper default
-- SQLite doesn't support ALTER COLUMN, so we check if the table exists and recreate if needed
-- This handles cases where the 4.0.0 migration created the table but the used field doesn't have DEFAULT 0

-- Create a new table with correct schema if password_reset_tokens exists
CREATE TABLE IF NOT EXISTS password_reset_tokens_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used INTEGER NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL
);

-- Copy data from old table if it exists
INSERT OR IGNORE INTO password_reset_tokens_new (id, email, token, expires_at, created_at, used, ip_address)
SELECT id, email, token, expires_at, created_at, COALESCE(used, 0), ip_address 
FROM password_reset_tokens 
WHERE EXISTS (SELECT name FROM sqlite_master WHERE type='table' AND name='password_reset_tokens');

-- Drop old table and rename new one if old table exists
DROP TABLE IF EXISTS password_reset_tokens;
ALTER TABLE password_reset_tokens_new RENAME TO password_reset_tokens;

-- Recreate indexes
CREATE INDEX IF NOT EXISTS idx_prt_email ON password_reset_tokens(email);
CREATE UNIQUE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token);
CREATE INDEX IF NOT EXISTS idx_prt_expires ON password_reset_tokens(expires_at);