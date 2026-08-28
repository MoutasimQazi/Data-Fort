-- Datafort platform — 004_orders.sql
--
-- Adds platform_orders (paid Stripe Checkout sessions, recorded by the
-- webhook) and platform_plans.stripe_price_id. 000_platform_schema.sql
-- already has both built in for a fresh install; this is the
-- incremental patch for an already-live platform database, same
-- pattern as 001_plans.sql / 002_leads.sql / 003_stripe_links.sql.
--
-- stripe_payment_link (added in 003) is left in place, unused —
-- superseded by stripe_price_id, not dropped.

SET NAMES utf8mb4;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_plans'
      AND COLUMN_NAME = 'stripe_price_id') = 0,
  'ALTER TABLE platform_plans ADD COLUMN stripe_price_id VARCHAR(80) NULL AFTER stripe_payment_link',
  'SELECT "platform_plans.stripe_price_id already exists"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS platform_orders (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stripe_session_id      VARCHAR(80) NOT NULL,
  stripe_event_id        VARCHAR(80) NULL,
  stripe_customer_id     VARCHAR(80) NULL,
  stripe_subscription_id VARCHAR(80) NULL,

  plan_id                INT UNSIGNED NULL,
  plan_name_snapshot     VARCHAR(80) NULL,

  customer_email         VARCHAR(190) NULL,
  amount_total           INT UNSIGNED NULL,
  currency               VARCHAR(10) NULL,
  payment_status         VARCHAR(40) NULL,
  livemode                TINYINT(1) NOT NULL DEFAULT 0,

  status                  ENUM('paid','provisioned') NOT NULL DEFAULT 'paid',

  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_orders_session (stripe_session_id),
  KEY ix_orders_status (status, created_at),
  CONSTRAINT fk_orders_plan FOREIGN KEY (plan_id) REFERENCES platform_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'platform_orders ready' AS status;
