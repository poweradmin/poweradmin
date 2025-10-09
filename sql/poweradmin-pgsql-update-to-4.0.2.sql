-- Fix password_reset_tokens used field default value if it exists but lacks proper default
-- This handles cases where the 4.0.0 migration created the table but the used field doesn't have DEFAULT FALSE
ALTER TABLE password_reset_tokens ALTER COLUMN used SET DEFAULT FALSE;