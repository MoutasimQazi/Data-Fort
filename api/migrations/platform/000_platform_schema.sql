-- Datafort — platform (control-plane) schema. MySQL 8 / MariaDB 10.4+.
--
-- THIS IS A SEPARATE DATABASE FROM EVERY TENANT'S DATABASE.
--
-- A tenant's data (leads, users, devices, audit log — everything in
-- api/migrations/001_schema.sql and friends) lives in its own database,
-- one per enterprise customer. This file provisions the ONE database
-- that sits above all of them: the registry of which customers exist,
-- the platform owner's own login, and a platform-level audit trail.
--
-- NEVER apply this file to a tenant database, and never apply a tenant
-- migration to this one. scripts/provision-tenant.php keeps the two
-- lineages separate on purpose — see its whitelist of tenant migration
-- files, which does not include this one.
--
-- WHY THIS DATABASE MUST STAY SMALL IN WHAT IT CAN SEE
--
-- This database is the single highest-value target in the whole
-- system: compromise it and you have the registry of every customer.
-- It is deliberately kept from being the single highest-value target
-- for CUSTOMER DATA too — it stores each tenant's database connection
-- info to provision and administer accounts, never a live connection
-- into a tenant's own database, and no code path here ever queries a
-- tenant's leads/users/devices tables. That boundary is what lets the
-- product be sold on "the platform operator cannot see your data" —
-- see api/platform/README.md for how that boundary is enforced in code.
--
-- db_pass_enc below is ciphertext (AES-256-GCM, api/platform/crypto.php),
-- not a plain password column. The key that decrypts it lives only in
-- the platform vhost's config.php, never in this database — so a dump
-- of this table alone does not hand over every tenant's credentials.

SET NAMES utf8mb4;


-- ══ Tenant registry ═══════════════════════════════════════════════
--
-- One row per enterprise customer. This table is the ONLY place that
-- knows how to reach a tenant's database; api/tenant-resolver.php reads
-- it once per request (when multi_tenant.enabled is true) to decide
-- which database api/db.php should open.

-- ══ Pricing catalog ═══════════════════════════════════════════════
--
-- Plans the platform owner actually manages (create/edit/retire) from
-- platform/pricing.html — not the same thing as the public pricing.html
-- marketing page at the project root, which is static copy for
-- prospects. This table is what a tenant is actually assigned to.
--
-- max_reps is CONTRACTUAL, not enforced. Enforcing it would mean this
-- database counting rows in a tenant's own `users` table — exactly the
-- boundary this product is sold on never crossing (see
-- tenants-save.php's header). It is shown on the tenant's registry
-- page as what they're entitled to; actually capping it would need a
-- column and a check inside the TENANT's own database instead,
-- written once at provisioning time the same deliberate way
-- db_provisioned_at etc. already are. Not built yet — see the
-- platform pricing page for the honest todo list this mirrors.

CREATE TABLE IF NOT EXISTS platform_plans (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80) NOT NULL,
  price_label   VARCHAR(40) NOT NULL,       -- display text: '₹25,000/mo', 'Custom'
  max_reps      SMALLINT UNSIGNED NULL,     -- NULL = unlimited (Enterprise-style)
  features      TEXT NULL,                  -- one bullet per line

  -- stripe_payment_link: an earlier, deliberately-simpler design
  -- (a static Stripe Payment Link, no API keys touching this server
  -- at all). Superseded by stripe_price_id below once server-side
  -- Checkout Session creation + a verified webhook replaced it — left
  -- here unused rather than dropped, since nothing destructive was
  -- needed to retire it.
  stripe_payment_link VARCHAR(255) NULL,

  -- A Stripe Price id (from a Product + recurring Price created in the
  -- Stripe Dashboard — "price_..."). NULL means this plan is
  -- sales-assisted only: pricing.html shows "Talk to us" and opens the
  -- lead-capture form. Set: pricing.html's button creates a real
  -- Checkout Session server-side (api/platform/checkout-start.php) and
  -- sends the visitor to Stripe's own hosted page.
  stripe_price_id VARCHAR(80) NULL,

  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,   -- inactive = kept for tenants already on it, hidden from new assignment
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Backs the seed block's ON DUPLICATE KEY UPDATE below, and stops
  -- the platform admin creating two plans that only differ by case.
  UNIQUE KEY uq_plans_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS platform_tenants (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  name            VARCHAR(160) NOT NULL,
  subdomain_slug  VARCHAR(80) NOT NULL UNIQUE,     -- {slug}.{base_domain}

  status          ENUM('pending','provisioning','active','suspended','deprovisioned')
                    NOT NULL DEFAULT 'pending',

  -- plan is a free-text fallback label (kept for a custom/negotiated
  -- deal with no catalog entry); plan_id is the real link once the
  -- tenant is on an actual platform_plans row. Prefer plan_id's name
  -- for display when set.
  plan            VARCHAR(60) NULL,
  plan_id         INT UNSIGNED NULL,

  contact_name    VARCHAR(160) NULL,
  contact_email   VARCHAR(190) NULL,

  -- Where and how to reach this tenant's own database. Never a live
  -- connection — just enough for provision-tenant.php and a future
  -- admin action to open one deliberately.
  db_host         VARCHAR(190) NOT NULL DEFAULT 'localhost',
  db_port         VARCHAR(10)  NOT NULL DEFAULT '3306',
  db_name         VARCHAR(80)  NOT NULL,
  db_user         VARCHAR(80)  NOT NULL,
  db_pass_enc     VARBINARY(512) NOT NULL,         -- ciphertext, see header

  ca_name         VARCHAR(190) NULL,               -- e.g. "Acme Corp Device CA"

  -- Provisioning checklist. Each column is written once, in order, by
  -- scripts/provision-tenant.php, and read back by the platform panel
  -- to render a checklist instead of a single opaque "pending" state.
  db_provisioned_at  DATETIME NULL,
  admin_seeded_at    DATETIME NULL,
  ca_scaffolded_at   DATETIME NULL,
  vhost_live_at      DATETIME NULL,   -- set manually once the Apache vhost is confirmed live

  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY ix_tenants_status (status),
  KEY ix_tenants_plan (plan_id),
  CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id) REFERENCES platform_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══ Platform admins ═══════════════════════════════════════════════
--
-- Deliberately NOT the tenant `users` table with an extra role value —
-- a platform owner account must not be reachable by anything that
-- authenticates against a tenant's database, and a tenant admin must
-- never be promotable into this table by mistake. Two separate tables,
-- two separate login surfaces, on purpose.

CREATE TABLE IF NOT EXISTS platform_admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(160) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,

  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  totp_secret   VARCHAR(64) NULL,

  last_seen_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══ Platform sessions ═════════════════════════════════════════════
--
-- Same device-binding shape as the tenant `sessions` table
-- (api/migrations/001_schema.sql) and the same reasoning: a cookie
-- lifted off the platform owner's laptop is worthless without the
-- certificate that created it. See api/platform/device.php.

CREATE TABLE IF NOT EXISTS platform_admin_sessions (
  id            CHAR(64) PRIMARY KEY,
  admin_id      INT UNSIGNED NOT NULL,

  device_id     INT UNSIGNED NULL,
  device_serial VARCHAR(80) NULL,

  ip            VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  device_fp     VARCHAR(32) NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  revoked_at    DATETIME NULL,

  KEY ix_padmin_sessions_admin (admin_id),
  KEY ix_padmin_sessions_expiry (expires_at),
  CONSTRAINT fk_padmin_sessions_admin FOREIGN KEY (admin_id) REFERENCES platform_admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS platform_login_attempts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  ok         TINYINT(1) NOT NULL DEFAULT 0,
  at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pattempts_email (email, at),
  KEY ix_pattempts_ip (ip, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS platform_password_resets (
  token_hash CHAR(64) PRIMARY KEY,
  admin_id   INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_preset_admin FOREIGN KEY (admin_id) REFERENCES platform_admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══ Platform devices — mTLS for the platform owner's own laptops ══
--
-- Same shape and purpose as company_devices/device_auth_log in
-- api/migrations/001_schema.sql, scoped to the platform's own CA
-- instead of any tenant's. Launches in 'log' mode, same staged
-- off -> log -> enforce rollout device.php already documents.

CREATE TABLE IF NOT EXISTS platform_devices (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  device_code         VARCHAR(64) NOT NULL UNIQUE,
  admin_id            INT UNSIGNED NULL,

  certificate_serial  VARCHAR(80) NOT NULL UNIQUE,
  certificate_subject VARCHAR(255) NOT NULL,
  certificate_issuer  VARCHAR(255) NULL,
  certificate_fingerprint VARCHAR(95) NULL,

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

  CONSTRAINT fk_pdevices_admin FOREIGN KEY (admin_id) REFERENCES platform_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS platform_device_auth_log (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id          INT UNSIGNED NULL,
  device_code        VARCHAR(64) NULL,
  certificate_serial VARCHAR(80) NULL,
  certificate_subject VARCHAR(255) NULL,

  verify_result      VARCHAR(64) NOT NULL,
  outcome            ENUM('allowed','denied') NOT NULL,
  reason             VARCHAR(120) NOT NULL,

  ip                 VARCHAR(45) NULL,
  user_agent         VARCHAR(255) NULL,
  path               VARCHAR(190) NULL,
  at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_pdal_at (at),
  KEY ix_pdal_serial (certificate_serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══ Platform audit log ════════════════════════════════════════════
--
-- Append-only, same discipline as api/migrations/001_schema.sql's
-- audit_log: no UPDATE and no DELETE is issued against this table
-- anywhere in the codebase. Grant the platform app's MySQL user only
-- INSERT and SELECT on it:
--   GRANT SELECT, INSERT ON datafort_platform.platform_audit_log TO 'datafort_platform_app'@'%';
--
-- tenant_id is nullable so an entry can be global (a new admin added)
-- or scoped to one customer (that customer suspended) — the platform
-- panel's per-tenant audit view filters on it.

CREATE TABLE IF NOT EXISTS platform_audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT UNSIGNED NULL,

  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(160) NULL,

  action      VARCHAR(40) NOT NULL,       -- tenant_create, tenant_suspend, tenant_reactivate, login, blocked...
  subject     VARCHAR(120) NULL,          -- tenant slug, admin email
  detail      VARCHAR(500) NULL,

  device_id   INT UNSIGNED NULL,
  device_code VARCHAR(64) NULL,
  ip          VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,

  at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_paudit_tenant_at (tenant_id, at),
  KEY ix_paudit_actor (actor_id, at),
  KEY ix_paudit_action (action, at),
  CONSTRAINT fk_paudit_tenant FOREIGN KEY (tenant_id) REFERENCES platform_tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══ Inbound sales leads ═══════════════════════════════════════════
--
-- Submitted from the public pricing.html's contact form via the one
-- other unauthenticated endpoint besides public-plans.php — see
-- api/platform/leads-capture.php. Stored here as the durable copy;
-- an email notification is also attempted but never relied on alone —
-- SERVER-REQUIREMENTS.md section 5 already flags this domain's mail
-- deliverability as unresolved, so an inquiry landing only in an
-- inbox that never receives it would mean losing it silently.
--
-- Not the same thing as a tenant's own `leads` table (the CRM data
-- this product exists to protect) — this is a handful of rows about
-- people asking about buying Datafort itself, small enough that no
-- masking/quota/audit machinery is warranted.

CREATE TABLE IF NOT EXISTS platform_leads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(160) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  company       VARCHAR(160) NULL,
  plan_interest VARCHAR(80) NULL,     -- which plan's "Talk to us" they clicked, if any
  message       TEXT NULL,
  ip            VARCHAR(45) NULL,
  status        ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_pleads_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══ Paid orders ═══════════════════════════════════════════════════
--
-- Written by the one webhook this codebase has — see
-- api/platform/stripe-webhook.php. uq_orders_session is what makes
-- that webhook idempotent: Stripe delivers webhooks at-least-once and
-- may redeliver the same event, and INSERT ... ON DUPLICATE KEY
-- UPDATE against this key means a redelivery updates the same row
-- instead of creating a second one, safely even under concurrent
-- delivery (MySQL takes the row/gap lock on the unique index for the
-- statement's duration — a SELECT-then-INSERT pattern would not be
-- safe here, there is a window between the two where a second
-- concurrent delivery passes the same check).
--
-- `status` is OUR OWN fulfillment workflow (paid -> provisioned), not
-- Stripe's payment_status — this is deliberately what turns "someone
-- paid" into a todo list the platform admin can act on and check off,
-- rather than something that only ever lived in the Stripe Dashboard.
-- Auto-provisioning on payment is explicitly out of scope: CA
-- generation and the Apache vhost step are already deliberately
-- manual elsewhere in this project (CERTIFICATES.md) — the admin
-- still provisions via tenant.html, using this row's email and plan.

CREATE TABLE IF NOT EXISTS platform_orders (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stripe_session_id      VARCHAR(80) NOT NULL,
  stripe_event_id        VARCHAR(80) NULL,
  stripe_customer_id     VARCHAR(80) NULL,
  stripe_subscription_id VARCHAR(80) NULL,

  plan_id                INT UNSIGNED NULL,
  plan_name_snapshot     VARCHAR(80) NULL,   -- survives the plan being edited/deleted later

  customer_email         VARCHAR(190) NULL,
  amount_total           INT UNSIGNED NULL,  -- cents, exactly as Stripe sends it — never a float
  currency               VARCHAR(10) NULL,
  payment_status         VARCHAR(40) NULL,   -- Stripe's own string, e.g. 'paid'
  livemode                TINYINT(1) NOT NULL DEFAULT 0,

  status                  ENUM('paid','provisioned') NOT NULL DEFAULT 'paid',

  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_orders_session (stripe_session_id),
  KEY ix_orders_status (status, created_at),
  CONSTRAINT fk_orders_plan FOREIGN KEY (plan_id) REFERENCES platform_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══ Seed ══════════════════════════════════════════════════════════
--
-- Deliberately NO platform_admins row here. The mistake already made
-- once in this project (api/migrations/003_repair_seed_passwords.sql:
-- a real password in a file this repo's own comments admit is public)
-- does not get repeated for the single account that can see every
-- customer. Create the first platform admin with:
--
--   php scripts/create-platform-admin.php --email=you@yourcompany.com
--
-- which prompts for a password interactively and never writes it to
-- disk, a log, or version control.
--
-- Starter plan rows ARE seeded below, unlike the admin account above —
-- these hold no secret, and platform/pricing.html would otherwise be
-- an empty page on a fresh install. Edit or delete them from that page
-- once real figures are decided; nothing else in the app depends on
-- these specific rows existing.

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
