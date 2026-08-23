-- Datafort — initial schema (MySQL 8 / MariaDB 10.4+)
--
-- NOTE ON TENANT ISOLATION
-- The requirements call for Postgres row-level security so a query
-- cannot cross tenants even with a bug in the app layer. This runs on
-- MySQL under cPanel, and MySQL has no RLS. Isolation is therefore
-- enforced in application code: EVERY query below is written to carry
-- tenant_id, and api/db.php provides tenantScope() so it is hard to
-- forget. That is weaker than RLS — a missing WHERE clause is a
-- cross-tenant leak rather than an empty result — so it must be
-- covered by review and by the tests in api/README.md.

SET NAMES utf8mb4;


-- ══ Tenants ═══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS tenants (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(160) NOT NULL,
  slug          VARCHAR(80) NOT NULL UNIQUE,

  -- Policy, editable from settings.html
  default_quota      SMALLINT UNSIGNED NOT NULL DEFAULT 25,
  max_assigned       INT UNSIGNED NOT NULL DEFAULT 250,
  mask_phone         TINYINT(1) NOT NULL DEFAULT 1,
  mask_email         TINYINT(1) NOT NULL DEFAULT 1,
  bake_watermark     TINYINT(1) NOT NULL DEFAULT 1,
  honeytokens_per_rep TINYINT UNSIGNED NOT NULL DEFAULT 3,
  burst_alert_limit  SMALLINT UNSIGNED NOT NULL DEFAULT 15,

  -- off | log | enforce   (see api/device.php)
  device_enforcement ENUM('off','log','enforce') NOT NULL DEFAULT 'off',

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ══ Users ═════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT UNSIGNED NOT NULL,
  name          VARCHAR(160) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','rep') NOT NULL DEFAULT 'rep',

  -- The containment control. Reveals per calendar day, admin-set.
  daily_quota   SMALLINT UNSIGNED NOT NULL DEFAULT 25,

  status        ENUM('active','flagged','suspended') NOT NULL DEFAULT 'active',
  totp_secret   VARCHAR(64) NULL,          -- optional 2FA (base32)
  last_seen_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_users_email (tenant_id, email),
  KEY ix_users_tenant (tenant_id),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;


-- ══ Company devices — mTLS ════════════════════════════════════════
--
-- One row per company laptop. certificate_serial is the join key
-- between Apache's verified client certificate and this ERP.
--
-- certificate_serial is stored NORMALISED: uppercase hex, no leading
-- zeros, no colons. api/device.php::normaliseSerial() is the single
-- place that decides that format — if it changes, every stored row
-- must be rewritten or every device stops matching.

CREATE TABLE IF NOT EXISTS company_devices (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id           INT UNSIGNED NOT NULL,

  device_code         VARCHAR(64) NOT NULL,      -- LAPTOP-001, matches cert CN
  employee_id         INT UNSIGNED NULL,         -- users.id, NULL = unassigned

  certificate_serial  VARCHAR(80) NOT NULL,
  certificate_subject VARCHAR(255) NOT NULL,
  certificate_issuer  VARCHAR(255) NULL,
  certificate_fingerprint VARCHAR(95) NULL,      -- SHA-256, colon-separated

  status              ENUM('pending','active','disabled','revoked') NOT NULL DEFAULT 'pending',

  issued_at           DATETIME NULL,
  expires_at          DATETIME NULL,
  revoked_at          DATETIME NULL,
  revoked_reason      VARCHAR(255) NULL,
  last_seen_at        DATETIME NULL,
  last_seen_ip        VARCHAR(45) NULL,

  note                VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Serial must be globally unique, not per-tenant: the certificate is
  -- presented before we know which tenant is being addressed.
  UNIQUE KEY uq_device_serial (certificate_serial),
  UNIQUE KEY uq_device_code (tenant_id, device_code),
  KEY ix_devices_tenant (tenant_id),
  KEY ix_devices_employee (employee_id),
  CONSTRAINT fk_devices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_devices_employee FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- Every device authentication attempt, success or failure.
-- Separate from audit_log because it is written before we know who the
-- user is — an unknown certificate has no user to attribute it to.
CREATE TABLE IF NOT EXISTS device_auth_log (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id          INT UNSIGNED NULL,
  device_id          INT UNSIGNED NULL,
  device_code        VARCHAR(64) NULL,
  certificate_serial VARCHAR(80) NULL,
  certificate_subject VARCHAR(255) NULL,

  verify_result      VARCHAR(64) NOT NULL,       -- raw SSL_CLIENT_VERIFY
  outcome            ENUM('allowed','denied') NOT NULL,
  reason             VARCHAR(120) NOT NULL,      -- no_certificate, unknown_serial, revoked, expired, ok…

  ip                 VARCHAR(45) NULL,
  user_agent         VARCHAR(255) NULL,
  path               VARCHAR(190) NULL,
  at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_dal_at (at),
  KEY ix_dal_serial (certificate_serial),
  KEY ix_dal_outcome (outcome, at)
) ENGINE=InnoDB;


-- ══ Sessions ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS sessions (
  id            CHAR(64) PRIMARY KEY,           -- random, sent as a cookie
  tenant_id     INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,

  -- The session is bound to the device that created it. If a session
  -- cookie is lifted onto another laptop, its certificate serial will
  -- not match and the session is refused. This is what stops a stolen
  -- cookie from being useful even inside the company.
  device_id     INT UNSIGNED NULL,
  device_serial VARCHAR(80) NULL,

  ip            VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  device_fp     VARCHAR(32) NULL,               -- browser fingerprint from login.js

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  revoked_at    DATETIME NULL,

  KEY ix_sessions_user (user_id),
  KEY ix_sessions_expiry (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  ok         TINYINT(1) NOT NULL DEFAULT 0,
  at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_attempts_email (email, at),
  KEY ix_attempts_ip (ip, at)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS password_resets (
  token_hash CHAR(64) PRIMARY KEY,              -- sha256 of the emailed token
  user_id    INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ══ Leads ═════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS lead_sources (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(160) NOT NULL,
  cost_total  DECIMAL(12,2) NOT NULL DEFAULT 0,
  lead_count  INT UNSIGNED NOT NULL DEFAULT 0,
  imported_by INT UNSIGNED NULL,
  file_name   VARCHAR(255) NULL,

  -- Requirements section 6: the import is not finished until the
  -- original spreadsheet is gone. NULL here means a live source file
  -- is still out there.
  source_destroyed_at DATETIME NULL,
  source_destroyed_by INT UNSIGNED NULL,

  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sources_tenant (tenant_id),
  CONSTRAINT fk_sources_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS leads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT UNSIGNED NOT NULL,
  ref           VARCHAR(24) NOT NULL,            -- L-4200, shown in the UI

  name          VARCHAR(160) NULL,
  company       VARCHAR(190) NULL,
  designation   VARCHAR(120) NULL,

  -- Stored in full. They leave the server masked unless a reveal is
  -- paid for; see api/lead-reveal.php.
  phone         VARCHAR(40) NULL,
  alt_phone     VARCHAR(40) NULL,
  email         VARCHAR(190) NULL,

  city          VARCHAR(90) NULL,
  state         VARCHAR(90) NULL,
  industry      VARCHAR(120) NULL,
  company_size  VARCHAR(40) NULL,
  website       VARCHAR(190) NULL,
  linkedin      VARCHAR(190) NULL,
  notes         TEXT NULL,

  source_id     INT UNSIGNED NULL,
  source_cost   DECIMAL(10,2) NOT NULL DEFAULT 0,
  acquired_date DATE NULL,

  status        ENUM('new','working','won','lost') NOT NULL DEFAULT 'new',
  owner_id      INT UNSIGNED NULL,
  last_contacted DATETIME NULL,

  -- Seeded decoy. Never surfaced to a rep — that is the whole point.
  honeytoken    TINYINT(1) NOT NULL DEFAULT 0,

  -- Dedup key: normalised phone digits, else lowercased email.
  dedup_key     VARCHAR(190) NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_leads_ref (tenant_id, ref),
  UNIQUE KEY uq_leads_dedup (tenant_id, dedup_key),
  KEY ix_leads_owner (tenant_id, owner_id),
  KEY ix_leads_status (tenant_id, status),
  CONSTRAINT fk_leads_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_leads_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- ══ Reveals — the quota ledger ════════════════════════════════════
--
-- One row per unmasked contact field. This table IS the quota: the
-- daily count is a COUNT(*) over it, never a counter column, because a
-- counter can drift and a ledger cannot. It is also the first thing
-- read when investigating where a list went.

CREATE TABLE IF NOT EXISTS lead_reveals (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  lead_id    BIGINT UNSIGNED NOT NULL,
  field      ENUM('phone','alt_phone','email') NOT NULL,

  device_id  INT UNSIGNED NULL,
  ip         VARCHAR(45) NULL,
  reveal_date DATE NOT NULL,                    -- denormalised for the daily count
  at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_reveals_quota (tenant_id, user_id, reveal_date),
  KEY ix_reveals_lead (lead_id),
  UNIQUE KEY uq_reveal_once (tenant_id, user_id, lead_id, field),
  CONSTRAINT fk_reveals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reveals_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ══ Audit log ═════════════════════════════════════════════════════
--
-- Append-only. No UPDATE and no DELETE is issued against this table
-- anywhere in the codebase. Grant the application's MySQL user only
-- INSERT and SELECT on it:
--   GRANT SELECT, INSERT ON datafort.audit_log TO 'datafort_app'@'%';
-- Without that grant, "append-only" is a promise rather than a control.

CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT UNSIGNED NOT NULL,
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(160) NULL,                -- snapshot; survives user deletion

  action      VARCHAR(40) NOT NULL,             -- reveal, view, status, assign, login, blocked, import, email
  subject     VARCHAR(120) NULL,                -- lead ref, file name, device code
  detail      VARCHAR(500) NULL,

  device_id   INT UNSIGNED NULL,
  device_code VARCHAR(64) NULL,
  ip          VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,

  at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_audit_tenant_at (tenant_id, at),
  KEY ix_audit_actor (actor_id, at),
  KEY ix_audit_action (tenant_id, action, at)
) ENGINE=InnoDB;


-- Client-side security signals from guard.js. Kept separate from
-- audit_log because they are noisy, untrusted, and lower value — a
-- blocked copy is an indicator, not a record of what happened.
CREATE TABLE IF NOT EXISTS security_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  type       VARCHAR(48) NOT NULL,
  detail     VARCHAR(255) NULL,
  page       VARCHAR(190) NULL,
  ip         VARCHAR(45) NULL,
  at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_sec_tenant_at (tenant_id, at),
  KEY ix_sec_user (user_id, at)
) ENGINE=InnoDB;


-- ══ Seed ══════════════════════════════════════════════════════════

INSERT INTO tenants (name, slug, default_quota, device_enforcement)
VALUES ('Movenetics Digital', 'movenetics', 25, 'off')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ── First administrator ──
--
--   admin@moveneticsdigital.com  /  admin@123
--
-- ⚠ THIS IS A DEVELOPMENT CREDENTIAL AND IT IS IN VERSION CONTROL.
--
-- This repo has a GitHub remote, so anyone who can read the repo knows
-- this password. It is also far below the policy the app enforces on
-- every other password (12 chars, upper + lower + digit + symbol) —
-- auth-reset.php would refuse to set it. It works only because login
-- checks the hash rather than re-checking the policy.
--
-- Before this instance holds one real lead, do BOTH:
--   1. Sign in and change the password (or delete this row and use
--      api/setup.php, which enforces the real policy)
--   2. Confirm the change:
--      SELECT email, LEFT(password_hash,7) FROM users WHERE role='admin';
--
-- Hash below is bcrypt cost 10, generated and round-trip verified
-- against 'admin@123'. PHP's password_verify() accepts it.

INSERT INTO users (tenant_id, name, email, password_hash, role, daily_quota, status)
SELECT t.id, 'Administrator', 'admin@moveneticsdigital.com',
  '$2b$10$7Bk/l5sK5pPB5U46wFoIS.EqqnhtK6/aNo8COFGaGgHQKkTmImT5u',
       'admin', 0, 'active'
FROM tenants t WHERE t.slug = 'movenetics'
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);
