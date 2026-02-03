-- Add missing locking column for older DBs.
-- For fresh DBs, 001_init.sql already includes locked_at.
ALTER TABLE jobs ADD COLUMN locked_at TEXT;
