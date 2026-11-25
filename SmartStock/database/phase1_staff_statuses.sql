-- Import once AFTER phase1_additions.sql.
-- Adds a reversible operational suspension state without deleting Staff history.
USE smartstock;
ALTER TABLE users
    MODIFY COLUMN status ENUM('active', 'suspended', 'disabled') NOT NULL DEFAULT 'active';
