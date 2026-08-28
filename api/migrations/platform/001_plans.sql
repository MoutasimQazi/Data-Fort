-- Datafort platform — 001_plans.sql
--
-- Adds the pricing catalog (platform_plans) and links platform_tenants
-- to it. For a database that already has platform_tenants (this repo's
-- live one included) — 000_platform_schema.sql's CREATE TABLE IF NOT
-- EXISTS won't retroactively add plan_id to a table that already
-- exists, so that part happens here instead, guarded the same way
-- api/migrations/006_daily_assignment.sql and 007_alerts_seen.sql
-- guard their own ALTER TABLEs: check information_schema first, so
-- this is safe to run more than once.
--
-- A FRESH platform database never needs this file — 000_platform_schema.sql
-- already has platform_plans and platform_tenants.plan_id built in.

SET NAMES utf8mb4;


-- ══ The table itself ══════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS platform_plans (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80) NOT NULL,
  price_label   VARCHAR(40) NOT NULL,
  max_reps      SMALLINT UNSIGNED NULL,
  features      TEXT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_plans_name (name)
) ENGINE=InnoDB;


-- ══ platform_tenants.plan_id ══════════════════════════════════════

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_tenants'
      AND COLUMN_NAME = 'plan_id') = 0,
  'ALTER TABLE platform_tenants ADD COLUMN plan_id INT UNSIGNED NULL AFTER plan',
  'SELECT "platform_tenants.plan_id already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_tenants'
      AND INDEX_NAME = 'ix_tenants_plan') = 0,
  'ALTER TABLE platform_tenants ADD KEY ix_tenants_plan (plan_id)',
  'SELECT "ix_tenants_plan already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_tenants'
      AND CONSTRAINT_NAME = 'fk_tenants_plan') = 0,
  'ALTER TABLE platform_tenants ADD CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id) REFERENCES platform_plans(id) ON DELETE SET NULL',
  'SELECT "fk_tenants_plan already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ══ Starter set, editable from platform/pricing.html afterwards ══

INSERT INTO platform_plans (name, price_label, max_reps, features, sort_order, is_active) VALUES
  ('Starter', '₹XX,XXX/mo', 5,
   'Masked contact reveal with a per-rep daily quota\nEvery reveal logged in an append-only audit trail\nWatermarked reveal images\nBulk import with automatic de-duplication\nFollow-up email relay',
   10, 1),
  ('Growth', '₹XX,XXX/mo', 25,
   'Everything in Starter\nmTLS device certificates — only enrolled company laptops sign in\nSession bound to the device that created it\nSeeded decoy leads (honeytokens)\nBurst-reveal anomaly alerts\nPriority support',
   20, 1),
  ('Enterprise', 'Custom', NULL,
   'Everything in Growth\nDedicated database — never shared with another customer\nDedicated private certificate authority\nDedicated subdomain and Apache vhost\nCustom device-enforcement policy\nSLA-backed support',
   30, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);


SELECT 'platform_plans ready' AS status, COUNT(*) AS plans FROM platform_plans;
