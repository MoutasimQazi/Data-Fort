-- Datafort platform — 003_stripe_links.sql
--
-- Adds platform_plans.stripe_payment_link — a Stripe Payment Link URL
-- set per plan from the Stripe Dashboard (no API keys, no webhook, no
-- code integration on this server at all). NULL means sales-assisted
-- only: pricing.html shows "Talk to us" for that plan. 000_platform_schema.sql
-- already has this column built in for a fresh install; this is the
-- incremental patch for an already-live platform database, same
-- pattern as 001_plans.sql / 002_leads.sql.

SET NAMES utf8mb4;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_plans'
      AND COLUMN_NAME = 'stripe_payment_link') = 0,
  'ALTER TABLE platform_plans ADD COLUMN stripe_payment_link VARCHAR(255) NULL AFTER features',
  'SELECT "platform_plans.stripe_payment_link already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT 'stripe_payment_link ready' AS status;
