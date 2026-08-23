-- Datafort - 006_daily_assignment.sql
--
-- Adds what the Users page needs to answer two questions the admin
-- actually asks:
--
--   "How many leads should this rep get each day?"
--   "Did they finish yesterday's?"
--
-- Neither was answerable before. leads.owner_id records WHO holds a
-- lead but not WHEN they got it, so "yesterday's leads" had no meaning
-- and there was nothing to measure completion against.
--
-- Safe to re-run: every statement is guarded.

SET NAMES utf8mb4;
SET @tid = (SELECT id FROM tenants WHERE slug = 'movenetics' LIMIT 1);


-- ══ leads.assigned_at ══════════════════════════════════════════════
--
-- Set every time ownership changes. This is what makes a "daily batch"
-- a real thing rather than a figure of speech.

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
      AND COLUMN_NAME = 'assigned_at') = 0,
  'ALTER TABLE leads ADD COLUMN assigned_at DATETIME NULL AFTER owner_id',
  'SELECT "leads.assigned_at already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- The index the "yesterday" and "today" queries run against.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
      AND INDEX_NAME = 'ix_leads_assigned') = 0,
  'CREATE INDEX ix_leads_assigned ON leads (tenant_id, owner_id, assigned_at)',
  'SELECT "ix_leads_assigned already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- Backfill. Existing assigned leads have no assignment date, so they
-- would show as neither today's nor yesterday's work and quietly vanish
-- from every completion figure. created_at is the closest honest
-- approximation available.
UPDATE leads
   SET assigned_at = created_at
 WHERE owner_id IS NOT NULL AND assigned_at IS NULL;


-- ══ users.daily_lead_target ════════════════════════════════════════
--
-- How many NEW leads this rep should receive per day.
--
-- NOT the same thing as daily_quota, and the difference matters:
--
--   daily_lead_target  how many leads land in their queue        (workload)
--   daily_quota        how many contacts they may unmask         (exposure)
--
-- A rep can hold 200 leads and still be capped at 25 reveals a day.
-- Conflating the two would either starve them of work or hand them the
-- whole book, so they are separate numbers with separate purposes.

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'daily_lead_target') = 0,
  'ALTER TABLE users ADD COLUMN daily_lead_target SMALLINT UNSIGNED NOT NULL DEFAULT 20 AFTER daily_quota',
  'SELECT "users.daily_lead_target already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- Administrators are not given a daily book of leads to work.
UPDATE users SET daily_lead_target = 0 WHERE role = 'admin';


-- ══ Summary ════════════════════════════════════════════════════════

SELECT
  (SELECT COUNT(*) FROM leads
    WHERE tenant_id = @tid AND assigned_at IS NOT NULL)             AS leads_with_assign_date,
  (SELECT COUNT(*) FROM leads
    WHERE tenant_id = @tid AND owner_id IS NOT NULL
      AND DATE(assigned_at) = CURDATE())                            AS assigned_today,
  (SELECT COUNT(*) FROM leads
    WHERE tenant_id = @tid AND owner_id IS NOT NULL
      AND DATE(assigned_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY))  AS assigned_yesterday,
  (SELECT COUNT(*) FROM users
    WHERE tenant_id = @tid AND role = 'rep')                        AS reps;
