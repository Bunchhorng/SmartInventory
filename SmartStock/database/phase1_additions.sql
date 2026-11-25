-- Import AFTER smartstock_schema.sql and smartstock_seed_reference.sql
USE smartstock;
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL;
ALTER TABLE users ADD COLUMN disabled_at DATETIME NULL;
-- Existing users default to requiring a password change.
