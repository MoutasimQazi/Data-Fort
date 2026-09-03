-- 009_utc_timestamps.sql
--
-- Converts DATETIME columns written in server-local time to UTC.
--
-- ══ READ BEFORE RUNNING ═══════════════════════════════════════════
--
-- Run this ONCE, and only if api/timecheck.php told you to. If that
-- page said "this MySQL already runs in UTC", running this WILL shift
-- correct rows and make them wrong. There is no way for the script to
-- tell afterwards whether it has already been applied, so:
--
--   1. Take a database backup first.
--   2. Set @shift below to the value timecheck.php printed.
--   3. Run it once. Re-run timecheck.php to confirm.
--
-- ══ WHY IT IS NEEDED ══════════════════════════════════════════════
--
-- Every timestamp column here is DATETIME, which stores a literal wall
-- clock with no zone conversion. NOW() therefore wrote whatever zone
-- the MySQL session happened to be in — the DB host's local time.
--
-- api/db.php now pins the session to '+00:00', so from the deploy
-- onward every row is UTC. Rows written before it are not, and the API
-- labels everything it sends with a Z. Without this migration old rows
-- get labelled UTC while holding local time, and read wrong by exactly
-- the server's offset.
--
-- ══ THE SHIFT ═════════════════════════════════════════════════════
--
-- @shift is the number of seconds to ADD to each stored value to turn
-- server-local into UTC. It is the negative of the server's offset:
-- a server at UTC+3 stored times 3h ahead of UTC, so subtract 3h.
--
--   UTC+3  ->  @shift = -10800
--   UTC+5:30 -> @shift = -19800
--   UTC-5  ->  @shift =  18000
--
-- timecheck.php prints the exact number for this server. Put it here.

SET @shift = 0;   -- ← CHANGE THIS. 0 is a deliberate no-op safety default.

-- Guard: refuse to do anything if the value was left at 0, rather than
-- running every UPDATE below for no reason and touching updated_at.
-- (A real 0 means the server is already UTC and there is nothing to do.)

UPDATE users SET
  last_seen_at   = IF(last_seen_at   IS NULL, NULL, last_seen_at   + INTERVAL @shift SECOND),
  alerts_seen_at = IF(alerts_seen_at IS NULL, NULL, alerts_seen_at + INTERVAL @shift SECOND),
  created_at     = created_at + INTERVAL @shift SECOND
WHERE @shift <> 0;

UPDATE leads SET
  last_contacted = IF(last_contacted IS NULL, NULL, last_contacted + INTERVAL @shift SECOND),
  assigned_at    = IF(assigned_at    IS NULL, NULL, assigned_at    + INTERVAL @shift SECOND),
  created_at     = created_at + INTERVAL @shift SECOND
WHERE @shift <> 0;

UPDATE audit_log SET at = at + INTERVAL @shift SECOND WHERE @shift <> 0;

UPDATE sessions SET
  created_at   = created_at   + INTERVAL @shift SECOND,
  last_seen_at = last_seen_at + INTERVAL @shift SECOND,
  expires_at   = expires_at   + INTERVAL @shift SECOND,
  revoked_at   = IF(revoked_at IS NULL, NULL, revoked_at + INTERVAL @shift SECOND)
WHERE @shift <> 0;

UPDATE company_devices SET
  issued_at    = IF(issued_at    IS NULL, NULL, issued_at    + INTERVAL @shift SECOND),
  expires_at   = IF(expires_at   IS NULL, NULL, expires_at   + INTERVAL @shift SECOND),
  revoked_at   = IF(revoked_at   IS NULL, NULL, revoked_at   + INTERVAL @shift SECOND),
  last_seen_at = IF(last_seen_at IS NULL, NULL, last_seen_at + INTERVAL @shift SECOND),
  created_at   = created_at + INTERVAL @shift SECOND
WHERE @shift <> 0;

UPDATE login_attempts     SET at = at + INTERVAL @shift SECOND WHERE @shift <> 0;
UPDATE security_events    SET at = at + INTERVAL @shift SECOND WHERE @shift <> 0;
UPDATE device_auth_log    SET at = at + INTERVAL @shift SECOND WHERE @shift <> 0;

UPDATE password_resets SET
  expires_at = expires_at + INTERVAL @shift SECOND,
  created_at = created_at + INTERVAL @shift SECOND,
  used_at    = IF(used_at IS NULL, NULL, used_at + INTERVAL @shift SECOND)
WHERE @shift <> 0;

UPDATE lead_sources SET
  created_at          = created_at + INTERVAL @shift SECOND,
  source_destroyed_at = IF(source_destroyed_at IS NULL, NULL,
                           source_destroyed_at + INTERVAL @shift SECOND)
WHERE @shift <> 0;

-- lead_reveals.reveal_date is a DATE, not a DATETIME: it is the
-- calendar day a quota was spent, compared against CURDATE(). Shifting
-- it by hours would move reveals across day boundaries and corrupt the
-- quota ledger, so it is deliberately left alone.
