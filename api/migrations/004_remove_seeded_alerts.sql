-- Remove alert rows created by the old development seed data.
-- Run this only on the development/test database.

SET NAMES utf8mb4;

SET @tid = (SELECT id FROM tenants WHERE slug = 'movenetics' LIMIT 1);

DELETE FROM device_auth_log
WHERE tenant_id = @tid
  AND path = '/api/auth-login.php'
  AND ip IN ('49.36.180.22', '103.42.20.88', '103.42.18.31',
             '103.42.18.44', '157.44.9.201', '103.42.18.7', '103.42.18.9');

DELETE FROM security_events
WHERE tenant_id = @tid
  AND ip = '103.42.18.9'
  AND page IN ('/my-leads.html', '/lead.html')
  AND type IN ('devtools_opened', 'clipboard_blocked', 'printscreen_pressed');

UPDATE lead_sources
SET source_destroyed_at = COALESCE(source_destroyed_at, created_at)
WHERE tenant_id = @tid
  AND name IN ('Purchased list - Q2', 'Trade show - Auto Expo');