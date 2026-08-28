-- Datafort platform — 002_leads.sql
--
-- Adds platform_leads (inbound sales inquiries from pricing.html's
-- contact form). 000_platform_schema.sql already has it built in for
-- a fresh install; this is the incremental patch for an already-live
-- platform database, same reasoning as 001_plans.sql.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS platform_leads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(160) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  company       VARCHAR(160) NULL,
  plan_interest VARCHAR(80) NULL,
  message       TEXT NULL,
  ip            VARCHAR(45) NULL,
  status        ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_pleads_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'platform_leads ready' AS status;
