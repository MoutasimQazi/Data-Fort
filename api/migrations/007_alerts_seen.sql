-- Datafort - 007_alerts_seen.sql
--
-- Lets the "Needs attention" badge clear once an administrator has
-- actually looked at it.
--
-- Without this the count is simply "anomalies in the last 24 hours",
-- which never goes down no matter how many times you read it. A badge
-- that is always red is a badge people stop looking at - and the whole
-- point of that number is to be noticed on the one day it matters.
--
-- Safe to re-run.

SET NAMES utf8mb4;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'alerts_seen_at') = 0,
  'ALTER TABLE users ADD COLUMN alerts_seen_at DATETIME NULL AFTER last_seen_at',
  'SELECT "users.alerts_seen_at already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT 'alerts_seen_at ready' AS status;
