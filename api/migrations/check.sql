-- Datafort — login diagnostic
--
-- Run this when a sign-in fails with "Email or password is incorrect".
--
-- ONE query, ONE row, every answer. Written this way on purpose:
-- phpMyAdmin displays only one result set at a time, so a file of six
-- separate SELECTs shows you the first one and hides the rest.
--
-- Paste ONLY the query below (not these comments) into the SQL tab.


SELECT
  /* Did 001_schema.sql run? NULL here means no, and nothing else
     below will make sense. */
  (SELECT id FROM tenants WHERE slug = 'movenetics')               AS tenant_id,

  /* Should be 'off' until the private CA exists. If this reads
     'enforce' with no certificate installed, login is refused — but
     with a device error, not "Email or password is incorrect". */
  (SELECT device_enforcement FROM tenants WHERE slug = 'movenetics') AS device_mode,

  /* Did 002_test_data.sql run? reps = 0 means no. */
  (SELECT COUNT(*) FROM users)                                      AS users_total,
  (SELECT COUNT(*) FROM users WHERE role = 'rep')                   AS reps,
  (SELECT COUNT(*) FROM users WHERE role = 'admin')                 AS admins,

  /* Does Priya exist, and is her hash intact?
       priya_exists     must be 1
       priya_status     must be 'active'
       priya_hash_pre   must be $2y$ or $2b$
       priya_hash_len   must be 60
     A length other than 60, or a prefix that is not $2y$/$2b$, means
     the hash was mangled getting into the database — usually a client
     that ate the $ signs. */
  (SELECT COUNT(*) FROM users WHERE email = 'priya@moveneticsdigital.com')       AS priya_exists,
  (SELECT status   FROM users WHERE email = 'priya@moveneticsdigital.com')       AS priya_status,
  (SELECT LEFT(password_hash, 4) FROM users WHERE email = 'priya@moveneticsdigital.com') AS priya_hash_pre,
  (SELECT LENGTH(password_hash)  FROM users WHERE email = 'priya@moveneticsdigital.com') AS priya_hash_len,

  /* Same for the admin seeded by 001_schema.sql. */
  (SELECT COUNT(*) FROM users WHERE email = 'admin@moveneticsdigital.com')       AS admin_exists,
  (SELECT LENGTH(password_hash) FROM users WHERE email = 'admin@moveneticsdigital.com') AS admin_hash_len,

  /* Did the rest of the test data land? */
  (SELECT COUNT(*) FROM leads)                                      AS leads,
  (SELECT COUNT(*) FROM lead_sources)                               AS sources,
  (SELECT COUNT(*) FROM company_devices)                            AS devices,

  /* Six failures in 15 minutes locks an email. That returns 429, not
     the generic message — but worth seeing while debugging. */
  (SELECT COUNT(*) FROM login_attempts
     WHERE ok = 0 AND at > DATE_SUB(NOW(), INTERVAL 15 MINUTE))     AS recent_failures;


-- ══════════════════════════════════════════════════════════════════
-- READING THE RESULT
-- ══════════════════════════════════════════════════════════════════
--
--   tenant_id IS NULL
--       001_schema.sql never ran. Run it.
--
--   reps = 0
--       002_test_data.sql never ran, or errored before the users
--       INSERT. Run it. Priya does not exist, which is why the login
--       says the password is wrong — the endpoint deliberately gives
--       the same answer for "no such account" as for "wrong password",
--       so it cannot be used to discover which emails are real.
--
--   priya_hash_len <> 60
--       The hash was truncated or mangled. Repair it: open
--       /api/setup.php and use "Set a password".
--
--   priya_status = 'suspended'
--       Login is refused with 423 "This account is locked" — a
--       different message, so this is not your cause.
--
--   Everything above looks right and login still fails
--       The hashes in the migrations were generated with Python's
--       bcrypt, not PHP's. They SHOULD verify — $2y$ and $2b$ are the
--       same algorithm for ASCII passwords — but that was never tested
--       against PHP. Open /api/setup.php and use "Set a password",
--       which writes the hash with this server's own password_hash()
--       and is therefore guaranteed to work.


-- ── Extra queries, run individually if you need them ──────────────

-- Every account and the state of its hash:
--   SELECT id, email, role, status, LEFT(password_hash,4) AS pre,
--          LENGTH(password_hash) AS len
--     FROM users ORDER BY role, email;

-- Clear the login throttle while testing:
--   DELETE FROM login_attempts;

-- Who has been failing to sign in:
--   SELECT email, ip, COUNT(*) AS failures, MAX(at) AS last_try
--     FROM login_attempts WHERE ok = 0
--    GROUP BY email, ip ORDER BY failures DESC;
