-- Datafort - 002_test_data.sql
--
-- SAMPLE DATA FOR TESTING. Never run this on a production instance.
--
-- Every contact detail is invented and deliberately unreachable:
-- phones are +91 90000xxxxx and emails are @example.com, a reserved
-- domain that cannot receive mail. A test reveal therefore cannot dial
-- or email a real person. Do NOT swap these for a real purchased list
-- to make the demo look better - that is the exact habit this product
-- exists to break.
--
-- TEST ACCOUNTS
--   admin@moveneticsdigital.com   admin@123   (seeded by 001_schema.sql)
--   priya@moveneticsdigital.com   test@123    quota 40, working normally
--   rahul@moveneticsdigital.com   test@123    quota 40, high reveal count
--   aisha@moveneticsdigital.com   test@123    quota 25, QUOTA EXHAUSTED
--   vikram@moveneticsdigital.com  test@123    quota 40, light usage
--   sneha@moveneticsdigital.com   test@123    quota 30, SUSPENDED
--
-- These passwords are in version control and this repo has a GitHub
-- remote. Fine for a test database, unacceptable anywhere holding a
-- real lead.
--
-- This file is RE-RUNNABLE. It clears its own previous output first,
-- so you can reload it as often as you like while testing.

SET NAMES utf8mb4;

SET @tid = (SELECT id FROM tenants WHERE slug = 'movenetics' LIMIT 1);

-- If 001_schema.sql has not been run, @tid is NULL and the very first
-- INSERT below fails with "Column 'tenant_id' cannot be null". That is
-- the intended failure: loud, immediate, and it names the cause.


-- ==================================================================
-- CLEANUP - removes what a previous run of THIS FILE created
-- ==================================================================
--
-- Scoped to the tenant and to the rep accounts this file creates. The
-- admin account seeded by 001_schema.sql is left alone.
--
-- NOTE: if you applied the append-only grant from api/README.md
-- (REVOKE DELETE ON audit_log), the audit_log line below will fail.
-- That is the grant working as intended. Run this file as the database
-- owner, or drop that one line.

DELETE FROM lead_reveals    WHERE tenant_id = @tid;
DELETE FROM security_events WHERE tenant_id = @tid;
DELETE FROM audit_log       WHERE tenant_id = @tid;
DELETE FROM device_auth_log WHERE tenant_id = @tid;
DELETE FROM sessions        WHERE tenant_id = @tid;
DELETE FROM leads           WHERE tenant_id = @tid;
DELETE FROM lead_sources    WHERE tenant_id = @tid;
DELETE FROM company_devices WHERE tenant_id = @tid;
DELETE FROM users           WHERE tenant_id = @tid AND role = 'rep';


-- ==================================================================
-- SALES REPS
-- ==================================================================

INSERT INTO users (tenant_id, name, email, password_hash, role, daily_quota, status, last_seen_at) VALUES
  (@tid, 'Priya Sharma', 'priya@moveneticsdigital.com', '$2b$10$iPXaWTMFAgjoKbY13DyRT.SkdEXnGsSaYuCgi8qFGczR78dJhPKGa', 'rep', 40, 'active',
   DATE_SUB(NOW(), INTERVAL 18 MINUTE)),
  (@tid, 'Rahul Mehta', 'rahul@moveneticsdigital.com', '$2b$10$iPXaWTMFAgjoKbY13DyRT.SkdEXnGsSaYuCgi8qFGczR78dJhPKGa', 'rep', 40, 'active',
   DATE_SUB(NOW(), INTERVAL 60 MINUTE)),
  (@tid, 'Aisha Khan', 'aisha@moveneticsdigital.com', '$2b$10$iPXaWTMFAgjoKbY13DyRT.SkdEXnGsSaYuCgi8qFGczR78dJhPKGa', 'rep', 25, 'flagged',
   DATE_SUB(NOW(), INTERVAL 6 MINUTE)),
  (@tid, 'Vikram Nair', 'vikram@moveneticsdigital.com', '$2b$10$iPXaWTMFAgjoKbY13DyRT.SkdEXnGsSaYuCgi8qFGczR78dJhPKGa', 'rep', 40, 'active',
   DATE_SUB(NOW(), INTERVAL 300 MINUTE)),
  (@tid, 'Sneha Patil', 'sneha@moveneticsdigital.com', '$2b$10$iPXaWTMFAgjoKbY13DyRT.SkdEXnGsSaYuCgi8qFGczR78dJhPKGa', 'rep', 30, 'suspended',
   DATE_SUB(NOW(), INTERVAL 8640 MINUTE));

-- Resolve the ids ONCE into variables. Everything below reuses them.
-- The earlier version repeated a correlated subquery inside all 240
-- lead rows, which is 480 lookups for data that never changes.

SET @u_priya   = (SELECT id FROM users WHERE tenant_id = @tid AND email = 'priya@moveneticsdigital.com');
SET @u_rahul   = (SELECT id FROM users WHERE tenant_id = @tid AND email = 'rahul@moveneticsdigital.com');
SET @u_aisha   = (SELECT id FROM users WHERE tenant_id = @tid AND email = 'aisha@moveneticsdigital.com');
SET @u_vikram  = (SELECT id FROM users WHERE tenant_id = @tid AND email = 'vikram@moveneticsdigital.com');
SET @u_sneha   = (SELECT id FROM users WHERE tenant_id = @tid AND email = 'sneha@moveneticsdigital.com');
SET @u_admin   = (SELECT id FROM users WHERE tenant_id = @tid AND role = 'admin' LIMIT 1);


-- ==================================================================
-- LEAD SOURCES
-- ==================================================================
--
-- source_destroyed_at is NULL on two rows on purpose. Those are the
-- imports where nobody confirmed the original spreadsheet was deleted,
-- and the dashboard surfaces them as an unprotected copy still out
-- there (requirements section 6).

INSERT INTO lead_sources (tenant_id, name, cost_total, file_name, source_destroyed_at, created_at) VALUES
  (@tid, 'IndiaMART - manufacturing Q3', 5400.00, 'indiamart-q3.csv', DATE_SUB(NOW(), INTERVAL 32 DAY), DATE_SUB(NOW(), INTERVAL 65 DAY)),
  (@tid, 'Purchased list - Q2', 11800.00, 'q2-purchased-list.csv', DATE_SUB(NOW(), INTERVAL 77 DAY), DATE_SUB(NOW(), INTERVAL 78 DAY)),
  (@tid, 'Website form', 0.00, NULL, DATE_SUB(NOW(), INTERVAL 40 DAY), DATE_SUB(NOW(), INTERVAL 28 DAY)),
  (@tid, 'JustDial export', 1900.00, 'justdial-aug.csv', DATE_SUB(NOW(), INTERVAL 21 DAY), DATE_SUB(NOW(), INTERVAL 62 DAY)),
  (@tid, 'Trade show - Auto Expo', 8200.00, 'autoexpo-scans.csv', DATE_SUB(NOW(), INTERVAL 31 DAY), DATE_SUB(NOW(), INTERVAL 32 DAY));

SET @src1 = (SELECT id FROM lead_sources WHERE tenant_id = @tid AND name = 'IndiaMART - manufacturing Q3' LIMIT 1);
SET @src2 = (SELECT id FROM lead_sources WHERE tenant_id = @tid AND name = 'Purchased list - Q2' LIMIT 1);
SET @src3 = (SELECT id FROM lead_sources WHERE tenant_id = @tid AND name = 'Website form' LIMIT 1);
SET @src4 = (SELECT id FROM lead_sources WHERE tenant_id = @tid AND name = 'JustDial export' LIMIT 1);
SET @src5 = (SELECT id FROM lead_sources WHERE tenant_id = @tid AND name = 'Trade show - Auto Expo' LIMIT 1);


-- ==================================================================
-- COMPANY DEVICES
-- ==================================================================
--
-- Serials are stored exactly as api/device.php normaliseSerial() writes
-- them: uppercase hex, no colons, no leading zeros. Registering a serial
-- here does NOT create a certificate - these are placeholders until the
-- CA issues real ones. LAPTOP-003 expires in 41 days so the Devices page
-- has something in its amber 'expiring soon' state.

INSERT INTO company_devices
  (tenant_id, device_code, employee_id, certificate_serial, certificate_subject,
   certificate_issuer, status, issued_at, expires_at, revoked_at, revoked_reason,
   last_seen_at, last_seen_ip)
VALUES
  (@tid, 'LAPTOP-001', @u_priya, '8A91F23B', 'CN=LAPTOP-001', 'Movenetics Digital Device CA', 'active',
   DATE_SUB(NOW(), INTERVAL 97 DAY), DATE_ADD(NOW(), INTERVAL 245 DAY),
   NULL, NULL, DATE_SUB(NOW(), INTERVAL 387 MINUTE), '103.42.18.21'),
  (@tid, 'LAPTOP-002', @u_rahul, '91BC72DA', 'CN=LAPTOP-002', 'Movenetics Digital Device CA', 'active',
   DATE_SUB(NOW(), INTERVAL 292 DAY), DATE_ADD(NOW(), INTERVAL 247 DAY),
   NULL, NULL, DATE_SUB(NOW(), INTERVAL 120 MINUTE), '103.42.18.187'),
  (@tid, 'LAPTOP-003', @u_aisha, '72AB91CD', 'CN=LAPTOP-003', 'Movenetics Digital Device CA', 'active',
   DATE_SUB(NOW(), INTERVAL 166 DAY), DATE_ADD(NOW(), INTERVAL 41 DAY),
   NULL, NULL, DATE_SUB(NOW(), INTERVAL 169 MINUTE), '103.42.18.142'),
  (@tid, 'LAPTOP-004', @u_vikram, 'C4D80E17', 'CN=LAPTOP-004', 'Movenetics Digital Device CA', 'active',
   DATE_SUB(NOW(), INTERVAL 297 DAY), DATE_ADD(NOW(), INTERVAL 275 DAY),
   NULL, NULL, DATE_SUB(NOW(), INTERVAL 284 MINUTE), '103.42.18.221'),
  (@tid, 'LAPTOP-005', @u_sneha, 'F13A9B04', 'CN=LAPTOP-005', 'Movenetics Digital Device CA', 'revoked',
   DATE_SUB(NOW(), INTERVAL 241 DAY), DATE_ADD(NOW(), INTERVAL 165 DAY),
   DATE_SUB(NOW(), INTERVAL 6 DAY), 'Laptop stolen', DATE_SUB(NOW(), INTERVAL 43 MINUTE), '103.42.18.7'),
  (@tid, 'LAPTOP-006', NULL, '2E7F40A9', 'CN=LAPTOP-006', 'Movenetics Digital Device CA', 'pending',
   DATE_SUB(NOW(), INTERVAL 140 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY),
   NULL, NULL, NULL, NULL),
  (@tid, 'LAPTOP-ADMIN', @u_admin, 'A05C1D6E', 'CN=LAPTOP-ADMIN', 'Movenetics Digital Device CA', 'active',
   DATE_SUB(NOW(), INTERVAL 108 DAY), DATE_ADD(NOW(), INTERVAL 225 DAY),
   NULL, NULL, DATE_SUB(NOW(), INTERVAL 588 MINUTE), '103.42.18.73');


-- ==================================================================
-- LEADS  (240 rows)
-- ==================================================================
--
-- dedup_key mirrors what api/import-commit.php computes: phone digits
-- first, else lowercased email. The UNIQUE index on it is what makes
-- INSERT IGNORE deduplicate during a real import.
--
-- Three rows are honeytokens (honeytoken = 1). They look exactly like
-- real leads to a rep - that is the entire point - and are marked only
-- in the admin views.

INSERT IGNORE INTO leads
  (tenant_id, ref, name, company, designation, phone, email, city, state,
   industry, company_size, source_id, source_cost, acquired_date,
   status, owner_id, last_contacted, honeytoken, dedup_key)
VALUES
  (@tid, 'L-4200', 'Anjali Kulkarni', 'Corex Systems', 'Purchase Executive', '+91 9000000000', 'anjali.kulkarni0@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 38 DAY), 'new', @u_priya, NULL, 0, '9000000000'),
  (@tid, 'L-4201', 'Anjali Shetty', 'Trident Motors', 'Founder', '+91 9000000001', 'anjali.shetty1@example.com',
   'Hyderabad', 'Telangana', 'IT Services', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 48 DAY), 'new', @u_aisha, NULL, 0, '9000000001'),
  (@tid, 'L-4202', 'Nikhil Verma', 'Meridian Estates', 'Procurement Head', '+91 9000000002', 'nikhil.verma2@example.com',
   'Surat', 'Gujarat', 'Manufacturing', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'new', NULL, NULL, 0, '9000000002'),
  (@tid, 'L-4203', 'Shreya Gupta', 'Lumen Textiles', 'VP Sales', '+91 9000000003', 'shreya.gupta3@example.com',
   'Ahmedabad', 'Gujarat', 'Real Estate', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 37 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 368 HOUR), 0, '9000000003'),
  (@tid, 'L-4204', 'Manish Banerjee', 'Nova Retail', 'Founder', '+91 9000000004', 'manish.banerjee4@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 165 HOUR), 0, '9000000004'),
  (@tid, 'L-4205', 'Deepak Iyer', 'Quanta Labs', 'Procurement Head', '+91 9000000005', 'deepak.iyer5@example.com',
   'Hyderabad', 'Telangana', 'Manufacturing', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 61 DAY), 'lost', @u_rahul, DATE_SUB(NOW(), INTERVAL 190 HOUR), 0, '9000000005'),
  (@tid, 'L-4206', 'Amit Shetty', 'Trident Motors', 'Founder', '+91 9000000006', 'amit.shetty6@example.com',
   'Pune', 'Maharashtra', 'Real Estate', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 463 HOUR), 0, '9000000006'),
  (@tid, 'L-4207', 'Rajesh Reddy', 'Blue Ridge Logistics', 'Purchase Executive', '+91 9000000007', 'rajesh.reddy7@example.com',
   'Mumbai', 'Maharashtra', 'Healthcare', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 366 HOUR), 1, '9000000007'),
  (@tid, 'L-4208', 'Karan Bose', 'Apex Fabricators', 'CTO', '+91 9000000008', 'karan.bose8@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 44 DAY), 'new', NULL, NULL, 0, '9000000008'),
  (@tid, 'L-4209', 'Deepak Bose', 'Apex Fabricators', 'VP Sales', '+91 9000000009', 'deepak.bose9@example.com',
   'Ahmedabad', 'Gujarat', 'Logistics', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'new', @u_sneha, NULL, 0, '9000000009'),
  (@tid, 'L-4210', 'Rajesh Chopra', 'Meridian Estates', 'Purchase Executive', '+91 9000000010', 'rajesh.chopra10@example.com',
   'Delhi', 'Delhi', 'Manufacturing', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 42 DAY), 'working', @u_sneha, DATE_SUB(NOW(), INTERVAL 110 HOUR), 0, '9000000010'),
  (@tid, 'L-4211', 'Nikhil Reddy', 'Vertex Industries', 'CTO', '+91 9000000011', 'nikhil.reddy11@example.com',
   'Bengaluru', 'Karnataka', 'Retail', '11-50', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 47 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 214 HOUR), 0, '9000000011'),
  (@tid, 'L-4212', 'Rajesh Joshi', 'Meridian Estates', 'Procurement Head', '+91 9000000012', 'rajesh.joshi12@example.com',
   'Chennai', 'Tamil Nadu', 'Healthcare', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'new', NULL, NULL, 0, '9000000012'),
  (@tid, 'L-4213', 'Divya Malhotra', 'Vertex Industries', 'Founder', '+91 9000000013', 'divya.malhotra13@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'new', NULL, NULL, 0, '9000000013'),
  (@tid, 'L-4214', 'Suresh Saxena', 'Lumen Textiles', 'VP Sales', '+91 9000000014', 'suresh.saxena14@example.com',
   'Ahmedabad', 'Gujarat', 'IT Services', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 28 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 69 HOUR), 0, '9000000014'),
  (@tid, 'L-4215', 'Anil Banerjee', 'Meridian Estates', 'Operations Manager', '+91 9000000015', 'anil.banerjee15@example.com',
   'Bengaluru', 'Karnataka', 'Real Estate', '201-500', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 43 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 420 HOUR), 0, '9000000015'),
  (@tid, 'L-4216', 'Imran Banerjee', 'Corex Systems', 'CTO', '+91 9000000016', 'imran.banerjee16@example.com',
   'Hyderabad', 'Telangana', 'Retail', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 283 HOUR), 0, '9000000016'),
  (@tid, 'L-4217', 'Preeti Chopra', 'Meridian Estates', 'VP Sales', '+91 9000000017', 'preeti.chopra17@example.com',
   'Mumbai', 'Maharashtra', 'Logistics', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 21 DAY), 'won', @u_sneha, DATE_SUB(NOW(), INTERVAL 216 HOUR), 0, '9000000017'),
  (@tid, 'L-4218', 'Preeti Bose', 'Blue Ridge Logistics', 'Procurement Head', '+91 9000000018', 'preeti.bose18@example.com',
   'Mumbai', 'Maharashtra', 'Logistics', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'new', @u_rahul, NULL, 0, '9000000018'),
  (@tid, 'L-4219', 'Anjali Bose', 'Blue Ridge Logistics', 'Director', '+91 9000000019', 'anjali.bose19@example.com',
   'Chennai', 'Tamil Nadu', 'Manufacturing', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 21 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 477 HOUR), 0, '9000000019'),
  (@tid, 'L-4220', 'Suresh Gupta', 'Vertex Industries', 'CTO', '+91 9000000020', 'suresh.gupta20@example.com',
   'Ahmedabad', 'Gujarat', 'Real Estate', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 70 DAY), 'lost', @u_rahul, DATE_SUB(NOW(), INTERVAL 382 HOUR), 0, '9000000020'),
  (@tid, 'L-4221', 'Suresh Rao', 'Nova Retail', 'Procurement Head', '+91 9000000021', 'suresh.rao21@example.com',
   'Surat', 'Gujarat', 'Healthcare', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'new', @u_priya, NULL, 0, '9000000021'),
  (@tid, 'L-4222', 'Shreya Gupta', 'Quanta Labs', 'Operations Manager', '+91 9000000022', 'shreya.gupta22@example.com',
   'Bengaluru', 'Karnataka', 'Healthcare', '11-50', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 90 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 497 HOUR), 0, '9000000022'),
  (@tid, 'L-4223', 'Sonal Bose', 'Meridian Estates', 'Plant Manager', '+91 9000000023', 'sonal.bose23@example.com',
   'Pune', 'Maharashtra', 'Real Estate', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 84 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 274 HOUR), 0, '9000000023'),
  (@tid, 'L-4224', 'Farah Bose', 'Skyline Packaging', 'Director', '+91 9000000024', 'farah.bose24@example.com',
   'Hyderabad', 'Telangana', 'IT Services', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 336 HOUR), 0, '9000000024'),
  (@tid, 'L-4225', 'Nikhil Kulkarni', 'Nova Retail', 'VP Sales', '+91 9000000025', 'nikhil.kulkarni25@example.com',
   'Bengaluru', 'Karnataka', 'Healthcare', '201-500', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 42 DAY), 'new', @u_vikram, NULL, 0, '9000000025'),
  (@tid, 'L-4226', 'Kavya Banerjee', 'Nova Retail', 'Plant Manager', '+91 9000000026', 'kavya.banerjee26@example.com',
   'Delhi', 'Delhi', 'Real Estate', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 62 DAY), 'working', @u_sneha, DATE_SUB(NOW(), INTERVAL 397 HOUR), 0, '9000000026'),
  (@tid, 'L-4227', 'Amit Malhotra', 'Ironwood Traders', 'Operations Manager', '+91 9000000027', 'amit.malhotra27@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 64 DAY), 'new', @u_sneha, NULL, 0, '9000000027'),
  (@tid, 'L-4228', 'Ritu Shetty', 'Nova Retail', 'Founder', '+91 9000000028', 'ritu.shetty28@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 88 DAY), 'won', @u_sneha, DATE_SUB(NOW(), INTERVAL 12 HOUR), 0, '9000000028'),
  (@tid, 'L-4229', 'Nikhil Joshi', 'Quanta Labs', 'Founder', '+91 9000000029', 'nikhil.joshi29@example.com',
   'Delhi', 'Delhi', 'Retail', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 29 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 487 HOUR), 0, '9000000029'),
  (@tid, 'L-4230', 'Farah Saxena', 'Apex Fabricators', 'Operations Manager', '+91 9000000030', 'farah.saxena30@example.com',
   'Ahmedabad', 'Gujarat', 'IT Services', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 55 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 344 HOUR), 0, '9000000030'),
  (@tid, 'L-4231', 'Sanjay Malhotra', 'Apex Fabricators', 'Procurement Head', '+91 9000000031', 'sanjay.malhotra31@example.com',
   'Bengaluru', 'Karnataka', 'Retail', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 57 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 435 HOUR), 0, '9000000031'),
  (@tid, 'L-4232', 'Nikhil Gupta', 'Corex Systems', 'VP Sales', '+91 9000000032', 'nikhil.gupta32@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'lost', @u_aisha, DATE_SUB(NOW(), INTERVAL 459 HOUR), 0, '9000000032'),
  (@tid, 'L-4233', 'Imran Shetty', 'Ironwood Traders', 'Purchase Executive', '+91 9000000033', 'imran.shetty33@example.com',
   'Pune', 'Maharashtra', 'Manufacturing', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 81 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 246 HOUR), 0, '9000000033'),
  (@tid, 'L-4234', 'Anil Bose', 'Apex Fabricators', 'Founder', '+91 9000000034', 'anil.bose34@example.com',
   'Hyderabad', 'Telangana', 'Healthcare', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 269 HOUR), 0, '9000000034'),
  (@tid, 'L-4235', 'Meera Gupta', 'Quanta Labs', 'Plant Manager', '+91 9000000035', 'meera.gupta35@example.com',
   'Hyderabad', 'Telangana', 'Healthcare', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 23 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 47 HOUR), 0, '9000000035'),
  (@tid, 'L-4236', 'Suresh Saxena', 'Apex Fabricators', 'Director', '+91 9000000036', 'suresh.saxena36@example.com',
   'Hyderabad', 'Telangana', 'Retail', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 44 DAY), 'lost', @u_aisha, DATE_SUB(NOW(), INTERVAL 310 HOUR), 0, '9000000036'),
  (@tid, 'L-4237', 'Pooja Shetty', 'Halcyon Health', 'Operations Manager', '+91 9000000037', 'pooja.shetty37@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 36 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 250 HOUR), 0, '9000000037'),
  (@tid, 'L-4238', 'Anjali Banerjee', 'Halcyon Health', 'Purchase Executive', '+91 9000000038', 'anjali.banerjee38@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 62 DAY), 'new', @u_rahul, NULL, 0, '9000000038'),
  (@tid, 'L-4239', 'Rohan Malhotra', 'Meridian Estates', 'Procurement Head', '+91 9000000039', 'rohan.malhotra39@example.com',
   'Hyderabad', 'Telangana', 'Real Estate', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 69 DAY), 'new', @u_priya, NULL, 0, '9000000039'),
  (@tid, 'L-4240', 'Manish Chopra', 'Vertex Industries', 'Founder', '+91 9000000040', 'manish.chopra40@example.com',
   'Delhi', 'Delhi', 'Healthcare', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 68 DAY), 'working', @u_sneha, DATE_SUB(NOW(), INTERVAL 423 HOUR), 0, '9000000040'),
  (@tid, 'L-4241', 'Rohan Rao', 'Lumen Textiles', 'Operations Manager', '+91 9000000041', 'rohan.rao41@example.com',
   'Mumbai', 'Maharashtra', 'Healthcare', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 44 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 412 HOUR), 0, '9000000041'),
  (@tid, 'L-4242', 'Ritu Joshi', 'Meridian Estates', 'CTO', '+91 9000000042', 'ritu.joshi42@example.com',
   'Bengaluru', 'Karnataka', 'Real Estate', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'new', @u_vikram, NULL, 0, '9000000042'),
  (@tid, 'L-4243', 'Sonal Joshi', 'Skyline Packaging', 'Plant Manager', '+91 9000000043', 'sonal.joshi43@example.com',
   'Chennai', 'Tamil Nadu', 'Real Estate', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 56 DAY), 'new', @u_aisha, NULL, 0, '9000000043'),
  (@tid, 'L-4244', 'Farah Chopra', 'Meridian Estates', 'Plant Manager', '+91 9000000044', 'farah.chopra44@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 29 DAY), 'new', @u_aisha, NULL, 0, '9000000044'),
  (@tid, 'L-4245', 'Divya Joshi', 'Corex Systems', 'Operations Manager', '+91 9000000045', 'divya.joshi45@example.com',
   'Hyderabad', 'Telangana', 'Manufacturing', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 239 HOUR), 0, '9000000045'),
  (@tid, 'L-4246', 'Neha Saxena', 'Halcyon Health', 'Plant Manager', '+91 9000000046', 'neha.saxena46@example.com',
   'Hyderabad', 'Telangana', 'Logistics', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'new', @u_priya, NULL, 0, '9000000046'),
  (@tid, 'L-4247', 'Sonal Kulkarni', 'Corex Systems', 'CTO', '+91 9000000047', 'sonal.kulkarni47@example.com',
   'Hyderabad', 'Telangana', 'Retail', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 54 DAY), 'new', @u_vikram, NULL, 0, '9000000047'),
  (@tid, 'L-4248', 'Divya Verma', 'Apex Fabricators', 'Operations Manager', '+91 9000000048', 'divya.verma48@example.com',
   'Bengaluru', 'Karnataka', 'Real Estate', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 24 DAY), 'new', @u_priya, NULL, 0, '9000000048'),
  (@tid, 'L-4249', 'Anil Rao', 'Trident Motors', 'VP Sales', '+91 9000000049', 'anil.rao49@example.com',
   'Surat', 'Gujarat', 'Manufacturing', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 32 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 403 HOUR), 0, '9000000049'),
  (@tid, 'L-4250', 'Nikhil Verma', 'Skyline Packaging', 'Plant Manager', '+91 9000000050', 'nikhil.verma50@example.com',
   'Bengaluru', 'Karnataka', 'Logistics', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 146 HOUR), 0, '9000000050'),
  (@tid, 'L-4251', 'Vivek Chopra', 'Quanta Labs', 'VP Sales', '+91 9000000051', 'vivek.chopra51@example.com',
   'Surat', 'Gujarat', 'Healthcare', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 46 DAY), 'new', NULL, NULL, 0, '9000000051'),
  (@tid, 'L-4252', 'Suresh Kulkarni', 'Halcyon Health', 'Director', '+91 9000000052', 'suresh.kulkarni52@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 72 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 157 HOUR), 0, '9000000052'),
  (@tid, 'L-4253', 'Tanvi Verma', 'Blue Ridge Logistics', 'Founder', '+91 9000000053', 'tanvi.verma53@example.com',
   'Delhi', 'Delhi', 'Logistics', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 167 HOUR), 0, '9000000053'),
  (@tid, 'L-4254', 'Farah Rao', 'Quanta Labs', 'Plant Manager', '+91 9000000054', 'farah.rao54@example.com',
   'Delhi', 'Delhi', 'Healthcare', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 71 DAY), 'new', @u_sneha, NULL, 0, '9000000054'),
  (@tid, 'L-4255', 'Suresh Rao', 'Vertex Industries', 'Founder', '+91 9000000055', 'suresh.rao55@example.com',
   'Pune', 'Maharashtra', 'IT Services', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'new', @u_rahul, NULL, 0, '9000000055'),
  (@tid, 'L-4256', 'Anjali Malhotra', 'Apex Fabricators', 'Operations Manager', '+91 9000000056', 'anjali.malhotra56@example.com',
   'Ahmedabad', 'Gujarat', 'IT Services', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'new', NULL, NULL, 0, '9000000056'),
  (@tid, 'L-4257', 'Imran Gupta', 'Meridian Estates', 'Procurement Head', '+91 9000000057', 'imran.gupta57@example.com',
   'Pune', 'Maharashtra', 'Manufacturing', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 48 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 262 HOUR), 0, '9000000057'),
  (@tid, 'L-4258', 'Imran Saxena', 'Blue Ridge Logistics', 'CTO', '+91 9000000058', 'imran.saxena58@example.com',
   'Delhi', 'Delhi', 'Real Estate', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 89 DAY), 'new', @u_vikram, NULL, 0, '9000000058'),
  (@tid, 'L-4259', 'Anil Chopra', 'Corex Systems', 'Operations Manager', '+91 9000000059', 'anil.chopra59@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 38 DAY), 'new', @u_aisha, NULL, 0, '9000000059'),
  (@tid, 'L-4260', 'Suresh Pillai', 'Vertex Industries', 'Operations Manager', '+91 9000000060', 'suresh.pillai60@example.com',
   'Hyderabad', 'Telangana', 'Healthcare', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 352 HOUR), 0, '9000000060'),
  (@tid, 'L-4261', 'Karan Verma', 'Apex Fabricators', 'Founder', '+91 9000000061', 'karan.verma61@example.com',
   'Chennai', 'Tamil Nadu', 'IT Services', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 103 HOUR), 0, '9000000061'),
  (@tid, 'L-4262', 'Manish Saxena', 'Lumen Textiles', 'Procurement Head', '+91 9000000062', 'manish.saxena62@example.com',
   'Hyderabad', 'Telangana', 'Retail', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 73 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 435 HOUR), 0, '9000000062'),
  (@tid, 'L-4263', 'Sanjay Pillai', 'Nova Retail', 'Founder', '+91 9000000063', 'sanjay.pillai63@example.com',
   'Hyderabad', 'Telangana', 'Manufacturing', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 50 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 2 HOUR), 0, '9000000063'),
  (@tid, 'L-4264', 'Pooja Joshi', 'Apex Fabricators', 'Founder', '+91 9000000064', 'pooja.joshi64@example.com',
   'Pune', 'Maharashtra', 'Healthcare', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 44 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 375 HOUR), 0, '9000000064'),
  (@tid, 'L-4265', 'Tanvi Saxena', 'Blue Ridge Logistics', 'Operations Manager', '+91 9000000065', 'tanvi.saxena65@example.com',
   'Surat', 'Gujarat', 'Retail', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 52 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 22 HOUR), 0, '9000000065'),
  (@tid, 'L-4266', 'Shreya Gupta', 'Vertex Industries', 'Procurement Head', '+91 9000000066', 'shreya.gupta66@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 86 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 393 HOUR), 0, '9000000066'),
  (@tid, 'L-4267', 'Karan Malhotra', 'Blue Ridge Logistics', 'Founder', '+91 9000000067', 'karan.malhotra67@example.com',
   'Surat', 'Gujarat', 'Real Estate', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 34 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 174 HOUR), 0, '9000000067'),
  (@tid, 'L-4268', 'Karan Chopra', 'Ironwood Traders', 'CTO', '+91 9000000068', 'karan.chopra68@example.com',
   'Surat', 'Gujarat', 'Real Estate', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 158 HOUR), 0, '9000000068'),
  (@tid, 'L-4269', 'Vivek Verma', 'Halcyon Health', 'Purchase Executive', '+91 9000000069', 'vivek.verma69@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 26 DAY), 'working', @u_sneha, DATE_SUB(NOW(), INTERVAL 496 HOUR), 0, '9000000069'),
  (@tid, 'L-4270', 'Karan Kulkarni', 'Ironwood Traders', 'VP Sales', '+91 9000000070', 'karan.kulkarni70@example.com',
   'Pune', 'Maharashtra', 'Retail', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 193 HOUR), 0, '9000000070'),
  (@tid, 'L-4271', 'Manish Rao', 'Meridian Estates', 'Plant Manager', '+91 9000000071', 'manish.rao71@example.com',
   'Pune', 'Maharashtra', 'Logistics', '11-50', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 29 DAY), 'won', @u_aisha, DATE_SUB(NOW(), INTERVAL 209 HOUR), 0, '9000000071'),
  (@tid, 'L-4272', 'Meera Gupta', 'Trident Motors', 'Plant Manager', '+91 9000000072', 'meera.gupta72@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 372 HOUR), 0, '9000000072'),
  (@tid, 'L-4273', 'Pooja Rao', 'Lumen Textiles', 'Procurement Head', '+91 9000000073', 'pooja.rao73@example.com',
   'Pune', 'Maharashtra', 'IT Services', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 38 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 110 HOUR), 0, '9000000073'),
  (@tid, 'L-4274', 'Neha Pillai', 'Halcyon Health', 'CTO', '+91 9000000074', 'neha.pillai74@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 91 HOUR), 0, '9000000074'),
  (@tid, 'L-4275', 'Sonal Reddy', 'Blue Ridge Logistics', 'Plant Manager', '+91 9000000075', 'sonal.reddy75@example.com',
   'Mumbai', 'Maharashtra', 'IT Services', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 52 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 129 HOUR), 0, '9000000075'),
  (@tid, 'L-4276', 'Deepak Chopra', 'Vertex Industries', 'Plant Manager', '+91 9000000076', 'deepak.chopra76@example.com',
   'Bengaluru', 'Karnataka', 'Logistics', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 77 DAY), 'new', @u_priya, NULL, 0, '9000000076'),
  (@tid, 'L-4277', 'Preeti Iyer', 'Vertex Industries', 'Procurement Head', '+91 9000000077', 'preeti.iyer77@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'new', @u_vikram, NULL, 0, '9000000077'),
  (@tid, 'L-4278', 'Farah Pillai', 'Corex Systems', 'Director', '+91 9000000078', 'farah.pillai78@example.com',
   'Delhi', 'Delhi', 'Real Estate', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 59 DAY), 'won', @u_sneha, DATE_SUB(NOW(), INTERVAL 298 HOUR), 0, '9000000078'),
  (@tid, 'L-4279', 'Suresh Verma', 'Corex Systems', 'Founder', '+91 9000000079', 'suresh.verma79@example.com',
   'Ahmedabad', 'Gujarat', 'Logistics', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'new', @u_priya, NULL, 0, '9000000079'),
  (@tid, 'L-4280', 'Rajesh Chopra', 'Blue Ridge Logistics', 'Procurement Head', '+91 9000000080', 'rajesh.chopra80@example.com',
   'Surat', 'Gujarat', 'Real Estate', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 342 HOUR), 0, '9000000080'),
  (@tid, 'L-4281', 'Tanvi Reddy', 'Skyline Packaging', 'Procurement Head', '+91 9000000081', 'tanvi.reddy81@example.com',
   'Delhi', 'Delhi', 'IT Services', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 56 DAY), 'new', @u_vikram, NULL, 0, '9000000081'),
  (@tid, 'L-4282', 'Amit Banerjee', 'Corex Systems', 'VP Sales', '+91 9000000082', 'amit.banerjee82@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 34 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 433 HOUR), 0, '9000000082'),
  (@tid, 'L-4283', 'Sanjay Pillai', 'Apex Fabricators', 'Director', '+91 9000000083', 'sanjay.pillai83@example.com',
   'Hyderabad', 'Telangana', 'IT Services', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 61 DAY), 'won', @u_aisha, DATE_SUB(NOW(), INTERVAL 182 HOUR), 0, '9000000083'),
  (@tid, 'L-4284', 'Shreya Gupta', 'Lumen Textiles', 'Plant Manager', '+91 9000000084', 'shreya.gupta84@example.com',
   'Hyderabad', 'Telangana', 'Retail', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 35 DAY), 'new', @u_vikram, NULL, 0, '9000000084'),
  (@tid, 'L-4285', 'Manish Saxena', 'Halcyon Health', 'Operations Manager', '+91 9000000085', 'manish.saxena85@example.com',
   'Hyderabad', 'Telangana', 'Healthcare', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 83 DAY), 'new', @u_sneha, NULL, 0, '9000000085'),
  (@tid, 'L-4286', 'Pooja Saxena', 'Halcyon Health', 'Director', '+91 9000000086', 'pooja.saxena86@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 82 DAY), 'new', @u_rahul, NULL, 0, '9000000086'),
  (@tid, 'L-4287', 'Amit Iyer', 'Quanta Labs', 'Plant Manager', '+91 9000000087', 'amit.iyer87@example.com',
   'Delhi', 'Delhi', 'Real Estate', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 112 HOUR), 1, '9000000087'),
  (@tid, 'L-4288', 'Sanjay Kulkarni', 'Ironwood Traders', 'VP Sales', '+91 9000000088', 'sanjay.kulkarni88@example.com',
   'Pune', 'Maharashtra', 'Logistics', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 77 DAY), 'new', NULL, NULL, 0, '9000000088'),
  (@tid, 'L-4289', 'Farah Shetty', 'Meridian Estates', 'Operations Manager', '+91 9000000089', 'farah.shetty89@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 66 HOUR), 0, '9000000089'),
  (@tid, 'L-4290', 'Imran Pillai', 'Blue Ridge Logistics', 'Procurement Head', '+91 9000000090', 'imran.pillai90@example.com',
   'Chennai', 'Tamil Nadu', 'IT Services', '201-500', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 34 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 213 HOUR), 0, '9000000090'),
  (@tid, 'L-4291', 'Sonal Chopra', 'Trident Motors', 'Procurement Head', '+91 9000000091', 'sonal.chopra91@example.com',
   'Bengaluru', 'Karnataka', 'Logistics', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'new', @u_priya, NULL, 0, '9000000091'),
  (@tid, 'L-4292', 'Imran Bose', 'Halcyon Health', 'Plant Manager', '+91 9000000092', 'imran.bose92@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 69 DAY), 'working', @u_sneha, DATE_SUB(NOW(), INTERVAL 152 HOUR), 0, '9000000092'),
  (@tid, 'L-4293', 'Anil Reddy', 'Apex Fabricators', 'Purchase Executive', '+91 9000000093', 'anil.reddy93@example.com',
   'Ahmedabad', 'Gujarat', 'IT Services', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 35 DAY), 'new', @u_rahul, NULL, 0, '9000000093'),
  (@tid, 'L-4294', 'Kavya Kulkarni', 'Meridian Estates', 'Plant Manager', '+91 9000000094', 'kavya.kulkarni94@example.com',
   'Hyderabad', 'Telangana', 'Retail', '11-50', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 33 DAY), 'won', @u_vikram, DATE_SUB(NOW(), INTERVAL 181 HOUR), 0, '9000000094'),
  (@tid, 'L-4295', 'Vivek Malhotra', 'Nova Retail', 'CTO', '+91 9000000095', 'vivek.malhotra95@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 56 DAY), 'new', @u_sneha, NULL, 0, '9000000095'),
  (@tid, 'L-4296', 'Neha Saxena', 'Nova Retail', 'Founder', '+91 9000000096', 'neha.saxena96@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '11-50', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 21 DAY), 'won', @u_sneha, DATE_SUB(NOW(), INTERVAL 111 HOUR), 0, '9000000096'),
  (@tid, 'L-4297', 'Sonal Saxena', 'Halcyon Health', 'Procurement Head', '+91 9000000097', 'sonal.saxena97@example.com',
   'Bengaluru', 'Karnataka', 'Healthcare', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 151 HOUR), 0, '9000000097'),
  (@tid, 'L-4298', 'Manish Joshi', 'Quanta Labs', 'Procurement Head', '+91 9000000098', 'manish.joshi98@example.com',
   'Bengaluru', 'Karnataka', 'Logistics', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 55 DAY), 'new', NULL, NULL, 0, '9000000098'),
  (@tid, 'L-4299', 'Anil Gupta', 'Quanta Labs', 'CTO', '+91 9000000099', 'anil.gupta99@example.com',
   'Delhi', 'Delhi', 'Real Estate', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 194 HOUR), 0, '9000000099'),
  (@tid, 'L-4300', 'Sanjay Bose', 'Trident Motors', 'CTO', '+91 9000000100', 'sanjay.bose100@example.com',
   'Mumbai', 'Maharashtra', 'Real Estate', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 44 DAY), 'new', NULL, NULL, 0, '9000000100'),
  (@tid, 'L-4301', 'Shreya Kulkarni', 'Vertex Industries', 'CTO', '+91 9000000101', 'shreya.kulkarni101@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 28 DAY), 'new', @u_sneha, NULL, 0, '9000000101'),
  (@tid, 'L-4302', 'Sonal Kulkarni', 'Trident Motors', 'CTO', '+91 9000000102', 'sonal.kulkarni102@example.com',
   'Mumbai', 'Maharashtra', 'Logistics', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 27 DAY), 'lost', @u_aisha, DATE_SUB(NOW(), INTERVAL 71 HOUR), 0, '9000000102'),
  (@tid, 'L-4303', 'Pooja Gupta', 'Vertex Industries', 'Procurement Head', '+91 9000000103', 'pooja.gupta103@example.com',
   'Bengaluru', 'Karnataka', 'IT Services', '201-500', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 363 HOUR), 0, '9000000103'),
  (@tid, 'L-4304', 'Anil Chopra', 'Lumen Textiles', 'VP Sales', '+91 9000000104', 'anil.chopra104@example.com',
   'Surat', 'Gujarat', 'Retail', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 23 DAY), 'new', @u_sneha, NULL, 0, '9000000104'),
  (@tid, 'L-4305', 'Ritu Shetty', 'Nova Retail', 'Plant Manager', '+91 9000000105', 'ritu.shetty105@example.com',
   'Chennai', 'Tamil Nadu', 'Logistics', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 29 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 329 HOUR), 0, '9000000105'),
  (@tid, 'L-4306', 'Karan Malhotra', 'Meridian Estates', 'VP Sales', '+91 9000000106', 'karan.malhotra106@example.com',
   'Delhi', 'Delhi', 'Logistics', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 42 DAY), 'working', @u_vikram, DATE_SUB(NOW(), INTERVAL 305 HOUR), 0, '9000000106'),
  (@tid, 'L-4307', 'Vivek Saxena', 'Corex Systems', 'Founder', '+91 9000000107', 'vivek.saxena107@example.com',
   'Bengaluru', 'Karnataka', 'IT Services', '11-50', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 71 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 283 HOUR), 0, '9000000107'),
  (@tid, 'L-4308', 'Pooja Verma', 'Ironwood Traders', 'Procurement Head', '+91 9000000108', 'pooja.verma108@example.com',
   'Delhi', 'Delhi', 'Healthcare', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 43 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 72 HOUR), 0, '9000000108'),
  (@tid, 'L-4309', 'Karan Reddy', 'Lumen Textiles', 'Plant Manager', '+91 9000000109', 'karan.reddy109@example.com',
   'Pune', 'Maharashtra', 'IT Services', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 29 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 333 HOUR), 0, '9000000109'),
  (@tid, 'L-4310', 'Karan Chopra', 'Lumen Textiles', 'Operations Manager', '+91 9000000110', 'karan.chopra110@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '201-500', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'new', NULL, NULL, 0, '9000000110'),
  (@tid, 'L-4311', 'Deepak Gupta', 'Trident Motors', 'VP Sales', '+91 9000000111', 'deepak.gupta111@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 405 HOUR), 0, '9000000111'),
  (@tid, 'L-4312', 'Anil Iyer', 'Lumen Textiles', 'Purchase Executive', '+91 9000000112', 'anil.iyer112@example.com',
   'Hyderabad', 'Telangana', 'Retail', '201-500', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 23 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 437 HOUR), 0, '9000000112'),
  (@tid, 'L-4313', 'Ritu Joshi', 'Corex Systems', 'Operations Manager', '+91 9000000113', 'ritu.joshi113@example.com',
   'Surat', 'Gujarat', 'IT Services', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 64 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 38 HOUR), 0, '9000000113'),
  (@tid, 'L-4314', 'Imran Kulkarni', 'Trident Motors', 'Operations Manager', '+91 9000000114', 'imran.kulkarni114@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 448 HOUR), 0, '9000000114'),
  (@tid, 'L-4315', 'Ritu Banerjee', 'Vertex Industries', 'CTO', '+91 9000000115', 'ritu.banerjee115@example.com',
   'Surat', 'Gujarat', 'Healthcare', '11-50', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'new', @u_vikram, NULL, 0, '9000000115'),
  (@tid, 'L-4316', 'Vivek Malhotra', 'Meridian Estates', 'Plant Manager', '+91 9000000116', 'vivek.malhotra116@example.com',
   'Hyderabad', 'Telangana', 'Retail', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 33 DAY), 'new', @u_aisha, NULL, 0, '9000000116'),
  (@tid, 'L-4317', 'Anil Chopra', 'Blue Ridge Logistics', 'CTO', '+91 9000000117', 'anil.chopra117@example.com',
   'Delhi', 'Delhi', 'Manufacturing', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 75 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 185 HOUR), 0, '9000000117'),
  (@tid, 'L-4318', 'Shreya Reddy', 'Quanta Labs', 'Founder', '+91 9000000118', 'shreya.reddy118@example.com',
   'Mumbai', 'Maharashtra', 'Healthcare', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 71 DAY), 'won', @u_vikram, DATE_SUB(NOW(), INTERVAL 43 HOUR), 0, '9000000118'),
  (@tid, 'L-4319', 'Preeti Reddy', 'Blue Ridge Logistics', 'Procurement Head', '+91 9000000119', 'preeti.reddy119@example.com',
   'Surat', 'Gujarat', 'Retail', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 74 DAY), 'new', NULL, NULL, 0, '9000000119'),
  (@tid, 'L-4320', 'Suresh Rao', 'Blue Ridge Logistics', 'Founder', '+91 9000000120', 'suresh.rao120@example.com',
   'Mumbai', 'Maharashtra', 'Healthcare', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 58 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 204 HOUR), 0, '9000000120'),
  (@tid, 'L-4321', 'Meera Malhotra', 'Ironwood Traders', 'VP Sales', '+91 9000000121', 'meera.malhotra121@example.com',
   'Ahmedabad', 'Gujarat', 'Logistics', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 75 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 115 HOUR), 0, '9000000121'),
  (@tid, 'L-4322', 'Sanjay Shetty', 'Quanta Labs', 'CTO', '+91 9000000122', 'sanjay.shetty122@example.com',
   'Surat', 'Gujarat', 'IT Services', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'new', @u_aisha, NULL, 0, '9000000122'),
  (@tid, 'L-4323', 'Neha Banerjee', 'Skyline Packaging', 'Founder', '+91 9000000123', 'neha.banerjee123@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 81 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 298 HOUR), 0, '9000000123'),
  (@tid, 'L-4324', 'Rajesh Verma', 'Halcyon Health', 'VP Sales', '+91 9000000124', 'rajesh.verma124@example.com',
   'Surat', 'Gujarat', 'Real Estate', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 90 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 70 HOUR), 0, '9000000124'),
  (@tid, 'L-4325', 'Nikhil Reddy', 'Trident Motors', 'Director', '+91 9000000125', 'nikhil.reddy125@example.com',
   'Surat', 'Gujarat', 'Retail', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 404 HOUR), 0, '9000000125'),
  (@tid, 'L-4326', 'Ritu Malhotra', 'Quanta Labs', 'Procurement Head', '+91 9000000126', 'ritu.malhotra126@example.com',
   'Hyderabad', 'Telangana', 'Healthcare', '51-200', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 41 DAY), 'won', @u_sneha, DATE_SUB(NOW(), INTERVAL 252 HOUR), 0, '9000000126'),
  (@tid, 'L-4327', 'Anjali Bose', 'Vertex Industries', 'CTO', '+91 9000000127', 'anjali.bose127@example.com',
   'Hyderabad', 'Telangana', 'Retail', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 74 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 170 HOUR), 0, '9000000127'),
  (@tid, 'L-4328', 'Tanvi Reddy', 'Trident Motors', 'Operations Manager', '+91 9000000128', 'tanvi.reddy128@example.com',
   'Ahmedabad', 'Gujarat', 'Logistics', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'lost', @u_rahul, DATE_SUB(NOW(), INTERVAL 33 HOUR), 0, '9000000128'),
  (@tid, 'L-4329', 'Suresh Saxena', 'Halcyon Health', 'Purchase Executive', '+91 9000000129', 'suresh.saxena129@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 35 DAY), 'new', NULL, NULL, 0, '9000000129'),
  (@tid, 'L-4330', 'Kavya Rao', 'Trident Motors', 'Procurement Head', '+91 9000000130', 'kavya.rao130@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 51 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 427 HOUR), 0, '9000000130'),
  (@tid, 'L-4331', 'Ritu Malhotra', 'Vertex Industries', 'Operations Manager', '+91 9000000131', 'ritu.malhotra131@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 23 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 277 HOUR), 0, '9000000131'),
  (@tid, 'L-4332', 'Amit Chopra', 'Trident Motors', 'Director', '+91 9000000132', 'amit.chopra132@example.com',
   'Bengaluru', 'Karnataka', 'Real Estate', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 23 DAY), 'won', @u_vikram, DATE_SUB(NOW(), INTERVAL 467 HOUR), 0, '9000000132'),
  (@tid, 'L-4333', 'Imran Rao', 'Lumen Textiles', 'Purchase Executive', '+91 9000000133', 'imran.rao133@example.com',
   'Bengaluru', 'Karnataka', 'Retail', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 87 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 198 HOUR), 0, '9000000133'),
  (@tid, 'L-4334', 'Tanvi Banerjee', 'Quanta Labs', 'Procurement Head', '+91 9000000134', 'tanvi.banerjee134@example.com',
   'Hyderabad', 'Telangana', 'Retail', '11-50', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 56 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 220 HOUR), 0, '9000000134'),
  (@tid, 'L-4335', 'Suresh Iyer', 'Halcyon Health', 'Director', '+91 9000000135', 'suresh.iyer135@example.com',
   'Surat', 'Gujarat', 'Manufacturing', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'new', @u_rahul, NULL, 0, '9000000135'),
  (@tid, 'L-4336', 'Divya Bose', 'Ironwood Traders', 'Director', '+91 9000000136', 'divya.bose136@example.com',
   'Bengaluru', 'Karnataka', 'Healthcare', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 43 DAY), 'new', NULL, NULL, 0, '9000000136'),
  (@tid, 'L-4337', 'Ritu Reddy', 'Corex Systems', 'Purchase Executive', '+91 9000000137', 'ritu.reddy137@example.com',
   'Delhi', 'Delhi', 'Real Estate', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 75 DAY), 'working', @u_vikram, DATE_SUB(NOW(), INTERVAL 229 HOUR), 0, '9000000137'),
  (@tid, 'L-4338', 'Deepak Reddy', 'Halcyon Health', 'VP Sales', '+91 9000000138', 'deepak.reddy138@example.com',
   'Hyderabad', 'Telangana', 'Manufacturing', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 33 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 360 HOUR), 0, '9000000138'),
  (@tid, 'L-4339', 'Rohan Saxena', 'Skyline Packaging', 'Procurement Head', '+91 9000000139', 'rohan.saxena139@example.com',
   'Surat', 'Gujarat', 'Real Estate', '201-500', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 48 DAY), 'won', @u_vikram, DATE_SUB(NOW(), INTERVAL 60 HOUR), 0, '9000000139'),
  (@tid, 'L-4340', 'Rajesh Malhotra', 'Nova Retail', 'Operations Manager', '+91 9000000140', 'rajesh.malhotra140@example.com',
   'Mumbai', 'Maharashtra', 'Logistics', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'working', @u_vikram, DATE_SUB(NOW(), INTERVAL 37 HOUR), 0, '9000000140'),
  (@tid, 'L-4341', 'Karan Rao', 'Vertex Industries', 'Director', '+91 9000000141', 'karan.rao141@example.com',
   'Mumbai', 'Maharashtra', 'Manufacturing', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 88 DAY), 'won', @u_vikram, DATE_SUB(NOW(), INTERVAL 51 HOUR), 0, '9000000141'),
  (@tid, 'L-4342', 'Manish Gupta', 'Skyline Packaging', 'Purchase Executive', '+91 9000000142', 'manish.gupta142@example.com',
   'Mumbai', 'Maharashtra', 'Healthcare', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 28 DAY), 'won', @u_rahul, DATE_SUB(NOW(), INTERVAL 54 HOUR), 0, '9000000142'),
  (@tid, 'L-4343', 'Preeti Iyer', 'Skyline Packaging', 'Operations Manager', '+91 9000000143', 'preeti.iyer143@example.com',
   'Hyderabad', 'Telangana', 'Real Estate', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 32 DAY), 'new', @u_sneha, NULL, 0, '9000000143'),
  (@tid, 'L-4344', 'Anil Pillai', 'Trident Motors', 'Founder', '+91 9000000144', 'anil.pillai144@example.com',
   'Mumbai', 'Maharashtra', 'IT Services', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 61 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 164 HOUR), 0, '9000000144'),
  (@tid, 'L-4345', 'Nikhil Bose', 'Halcyon Health', 'CTO', '+91 9000000145', 'nikhil.bose145@example.com',
   'Ahmedabad', 'Gujarat', 'Real Estate', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 38 DAY), 'working', @u_vikram, DATE_SUB(NOW(), INTERVAL 384 HOUR), 0, '9000000145'),
  (@tid, 'L-4346', 'Sanjay Saxena', 'Ironwood Traders', 'Purchase Executive', '+91 9000000146', 'sanjay.saxena146@example.com',
   'Pune', 'Maharashtra', 'Retail', '51-200', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 39 DAY), 'new', @u_rahul, NULL, 0, '9000000146'),
  (@tid, 'L-4347', 'Shreya Gupta', 'Corex Systems', 'Procurement Head', '+91 9000000147', 'shreya.gupta147@example.com',
   'Delhi', 'Delhi', 'Manufacturing', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'new', NULL, NULL, 0, '9000000147'),
  (@tid, 'L-4348', 'Karan Joshi', 'Nova Retail', 'Procurement Head', '+91 9000000148', 'karan.joshi148@example.com',
   'Pune', 'Maharashtra', 'Real Estate', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 28 DAY), 'new', @u_sneha, NULL, 0, '9000000148'),
  (@tid, 'L-4349', 'Shreya Reddy', 'Meridian Estates', 'Purchase Executive', '+91 9000000149', 'shreya.reddy149@example.com',
   'Pune', 'Maharashtra', 'Retail', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'new', @u_aisha, NULL, 0, '9000000149'),
  (@tid, 'L-4350', 'Sonal Chopra', 'Ironwood Traders', 'Plant Manager', '+91 9000000150', 'sonal.chopra150@example.com',
   'Delhi', 'Delhi', 'Logistics', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 73 DAY), 'new', @u_rahul, NULL, 0, '9000000150'),
  (@tid, 'L-4351', 'Kavya Kulkarni', 'Meridian Estates', 'Plant Manager', '+91 9000000151', 'kavya.kulkarni151@example.com',
   'Hyderabad', 'Telangana', 'Logistics', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 51 DAY), 'lost', @u_rahul, DATE_SUB(NOW(), INTERVAL 112 HOUR), 0, '9000000151'),
  (@tid, 'L-4352', 'Divya Saxena', 'Trident Motors', 'VP Sales', '+91 9000000152', 'divya.saxena152@example.com',
   'Hyderabad', 'Telangana', 'Logistics', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 77 DAY), 'new', @u_vikram, NULL, 0, '9000000152'),
  (@tid, 'L-4353', 'Rohan Chopra', 'Nova Retail', 'Founder', '+91 9000000153', 'rohan.chopra153@example.com',
   'Bengaluru', 'Karnataka', 'Retail', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 84 DAY), 'new', @u_vikram, NULL, 0, '9000000153'),
  (@tid, 'L-4354', 'Neha Banerjee', 'Corex Systems', 'VP Sales', '+91 9000000154', 'neha.banerjee154@example.com',
   'Pune', 'Maharashtra', 'IT Services', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 73 DAY), 'lost', @u_rahul, DATE_SUB(NOW(), INTERVAL 182 HOUR), 0, '9000000154'),
  (@tid, 'L-4355', 'Preeti Joshi', 'Nova Retail', 'Procurement Head', '+91 9000000155', 'preeti.joshi155@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'working', @u_vikram, DATE_SUB(NOW(), INTERVAL 12 HOUR), 0, '9000000155'),
  (@tid, 'L-4356', 'Tanvi Banerjee', 'Skyline Packaging', 'CTO', '+91 9000000156', 'tanvi.banerjee156@example.com',
   'Delhi', 'Delhi', 'Manufacturing', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 50 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 299 HOUR), 0, '9000000156'),
  (@tid, 'L-4357', 'Rohan Pillai', 'Trident Motors', 'Plant Manager', '+91 9000000157', 'rohan.pillai157@example.com',
   'Hyderabad', 'Telangana', 'Retail', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 72 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 406 HOUR), 0, '9000000157'),
  (@tid, 'L-4358', 'Sanjay Chopra', 'Vertex Industries', 'VP Sales', '+91 9000000158', 'sanjay.chopra158@example.com',
   'Mumbai', 'Maharashtra', 'IT Services', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 27 DAY), 'new', NULL, NULL, 0, '9000000158'),
  (@tid, 'L-4359', 'Shreya Reddy', 'Trident Motors', 'Purchase Executive', '+91 9000000159', 'shreya.reddy159@example.com',
   'Chennai', 'Tamil Nadu', 'Real Estate', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 42 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 465 HOUR), 0, '9000000159'),
  (@tid, 'L-4360', 'Pooja Iyer', 'Nova Retail', 'VP Sales', '+91 9000000160', 'pooja.iyer160@example.com',
   'Surat', 'Gujarat', 'Manufacturing', '11-50', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 29 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 44 HOUR), 0, '9000000160'),
  (@tid, 'L-4361', 'Anjali Reddy', 'Corex Systems', 'Founder', '+91 9000000161', 'anjali.reddy161@example.com',
   'Bengaluru', 'Karnataka', 'Logistics', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 339 HOUR), 0, '9000000161'),
  (@tid, 'L-4362', 'Neha Pillai', 'Corex Systems', 'Purchase Executive', '+91 9000000162', 'neha.pillai162@example.com',
   'Bengaluru', 'Karnataka', 'Logistics', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 412 HOUR), 0, '9000000162'),
  (@tid, 'L-4363', 'Pooja Kulkarni', 'Lumen Textiles', 'Procurement Head', '+91 9000000163', 'pooja.kulkarni163@example.com',
   'Chennai', 'Tamil Nadu', 'Manufacturing', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 81 DAY), 'new', @u_sneha, NULL, 0, '9000000163'),
  (@tid, 'L-4364', 'Rohan Saxena', 'Nova Retail', 'VP Sales', '+91 9000000164', 'rohan.saxena164@example.com',
   'Pune', 'Maharashtra', 'Retail', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 77 DAY), 'lost', @u_priya, DATE_SUB(NOW(), INTERVAL 38 HOUR), 0, '9000000164'),
  (@tid, 'L-4365', 'Imran Shetty', 'Skyline Packaging', 'Plant Manager', '+91 9000000165', 'imran.shetty165@example.com',
   'Surat', 'Gujarat', 'Retail', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 82 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 489 HOUR), 0, '9000000165'),
  (@tid, 'L-4366', 'Amit Reddy', 'Apex Fabricators', 'Plant Manager', '+91 9000000166', 'amit.reddy166@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 86 DAY), 'new', @u_sneha, NULL, 0, '9000000166'),
  (@tid, 'L-4367', 'Neha Malhotra', 'Lumen Textiles', 'VP Sales', '+91 9000000167', 'neha.malhotra167@example.com',
   'Pune', 'Maharashtra', 'Manufacturing', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 33 DAY), 'new', @u_sneha, NULL, 1, '9000000167'),
  (@tid, 'L-4368', 'Divya Shetty', 'Nova Retail', 'Operations Manager', '+91 9000000168', 'divya.shetty168@example.com',
   'Pune', 'Maharashtra', 'Healthcare', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'new', @u_priya, NULL, 0, '9000000168'),
  (@tid, 'L-4369', 'Manish Joshi', 'Trident Motors', 'Purchase Executive', '+91 9000000169', 'manish.joshi169@example.com',
   'Pune', 'Maharashtra', 'Real Estate', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 27 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR), 0, '9000000169'),
  (@tid, 'L-4370', 'Meera Shetty', 'Blue Ridge Logistics', 'Director', '+91 9000000170', 'meera.shetty170@example.com',
   'Pune', 'Maharashtra', 'Logistics', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 84 DAY), 'working', @u_vikram, DATE_SUB(NOW(), INTERVAL 146 HOUR), 0, '9000000170'),
  (@tid, 'L-4371', 'Preeti Iyer', 'Lumen Textiles', 'Purchase Executive', '+91 9000000171', 'preeti.iyer171@example.com',
   'Ahmedabad', 'Gujarat', 'IT Services', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 24 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 14 HOUR), 0, '9000000171'),
  (@tid, 'L-4372', 'Ritu Iyer', 'Halcyon Health', 'Plant Manager', '+91 9000000172', 'ritu.iyer172@example.com',
   'Ahmedabad', 'Gujarat', 'Real Estate', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'new', @u_aisha, NULL, 0, '9000000172'),
  (@tid, 'L-4373', 'Rajesh Gupta', 'Corex Systems', 'Founder', '+91 9000000173', 'rajesh.gupta173@example.com',
   'Delhi', 'Delhi', 'Logistics', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 65 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 192 HOUR), 0, '9000000173'),
  (@tid, 'L-4374', 'Anil Gupta', 'Blue Ridge Logistics', 'Purchase Executive', '+91 9000000174', 'anil.gupta174@example.com',
   'Delhi', 'Delhi', 'Retail', '11-50', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 67 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 320 HOUR), 0, '9000000174'),
  (@tid, 'L-4375', 'Amit Malhotra', 'Nova Retail', 'VP Sales', '+91 9000000175', 'amit.malhotra175@example.com',
   'Surat', 'Gujarat', 'Manufacturing', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 53 DAY), 'new', @u_sneha, NULL, 0, '9000000175'),
  (@tid, 'L-4376', 'Neha Rao', 'Corex Systems', 'CTO', '+91 9000000176', 'neha.rao176@example.com',
   'Hyderabad', 'Telangana', 'Real Estate', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 77 DAY), 'new', @u_rahul, NULL, 0, '9000000176'),
  (@tid, 'L-4377', 'Kavya Verma', 'Lumen Textiles', 'VP Sales', '+91 9000000177', 'kavya.verma177@example.com',
   'Mumbai', 'Maharashtra', 'IT Services', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 27 DAY), 'working', @u_vikram, DATE_SUB(NOW(), INTERVAL 152 HOUR), 0, '9000000177'),
  (@tid, 'L-4378', 'Vivek Kulkarni', 'Trident Motors', 'Founder', '+91 9000000178', 'vivek.kulkarni178@example.com',
   'Hyderabad', 'Telangana', 'IT Services', '201-500', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 181 HOUR), 0, '9000000178'),
  (@tid, 'L-4379', 'Anil Reddy', 'Nova Retail', 'Founder', '+91 9000000179', 'anil.reddy179@example.com',
   'Hyderabad', 'Telangana', 'Manufacturing', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 41 DAY), 'working', @u_sneha, DATE_SUB(NOW(), INTERVAL 125 HOUR), 0, '9000000179'),
  (@tid, 'L-4380', 'Divya Banerjee', 'Blue Ridge Logistics', 'Director', '+91 9000000180', 'divya.banerjee180@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 27 DAY), 'lost', @u_rahul, DATE_SUB(NOW(), INTERVAL 174 HOUR), 0, '9000000180'),
  (@tid, 'L-4381', 'Manish Iyer', 'Nova Retail', 'Purchase Executive', '+91 9000000181', 'manish.iyer181@example.com',
   'Pune', 'Maharashtra', 'IT Services', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 53 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 371 HOUR), 0, '9000000181'),
  (@tid, 'L-4382', 'Tanvi Rao', 'Nova Retail', 'Purchase Executive', '+91 9000000182', 'tanvi.rao182@example.com',
   'Pune', 'Maharashtra', 'Logistics', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'new', @u_sneha, NULL, 0, '9000000182'),
  (@tid, 'L-4383', 'Vivek Kulkarni', 'Nova Retail', 'Plant Manager', '+91 9000000183', 'vivek.kulkarni183@example.com',
   'Hyderabad', 'Telangana', 'Retail', '1-10', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 85 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 87 HOUR), 0, '9000000183'),
  (@tid, 'L-4384', 'Vivek Chopra', 'Apex Fabricators', 'Director', '+91 9000000184', 'vivek.chopra184@example.com',
   'Pune', 'Maharashtra', 'IT Services', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 89 DAY), 'new', NULL, NULL, 0, '9000000184'),
  (@tid, 'L-4385', 'Sanjay Verma', 'Quanta Labs', 'Operations Manager', '+91 9000000185', 'sanjay.verma185@example.com',
   'Pune', 'Maharashtra', 'Logistics', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'new', @u_rahul, NULL, 0, '9000000185'),
  (@tid, 'L-4386', 'Sonal Gupta', 'Blue Ridge Logistics', 'Procurement Head', '+91 9000000186', 'sonal.gupta186@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '51-200', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 52 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 484 HOUR), 0, '9000000186'),
  (@tid, 'L-4387', 'Neha Banerjee', 'Skyline Packaging', 'CTO', '+91 9000000187', 'neha.banerjee187@example.com',
   'Pune', 'Maharashtra', 'Real Estate', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 28 DAY), 'new', NULL, NULL, 0, '9000000187'),
  (@tid, 'L-4388', 'Vivek Gupta', 'Apex Fabricators', 'VP Sales', '+91 9000000188', 'vivek.gupta188@example.com',
   'Delhi', 'Delhi', 'IT Services', '201-500', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 29 DAY), 'new', @u_vikram, NULL, 0, '9000000188'),
  (@tid, 'L-4389', 'Imran Bose', 'Corex Systems', 'Operations Manager', '+91 9000000189', 'imran.bose189@example.com',
   'Surat', 'Gujarat', 'Logistics', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 215 HOUR), 0, '9000000189'),
  (@tid, 'L-4390', 'Suresh Verma', 'Blue Ridge Logistics', 'Procurement Head', '+91 9000000190', 'suresh.verma190@example.com',
   'Hyderabad', 'Telangana', 'IT Services', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 48 DAY), 'new', NULL, NULL, 0, '9000000190'),
  (@tid, 'L-4391', 'Nikhil Reddy', 'Blue Ridge Logistics', 'Plant Manager', '+91 9000000191', 'nikhil.reddy191@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '11-50', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 88 DAY), 'new', @u_rahul, NULL, 0, '9000000191'),
  (@tid, 'L-4392', 'Nikhil Pillai', 'Quanta Labs', 'Operations Manager', '+91 9000000192', 'nikhil.pillai192@example.com',
   'Hyderabad', 'Telangana', 'IT Services', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 61 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 5 HOUR), 0, '9000000192'),
  (@tid, 'L-4393', 'Sanjay Iyer', 'Lumen Textiles', 'Operations Manager', '+91 9000000193', 'sanjay.iyer193@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '11-50', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 40 DAY), 'won', @u_priya, DATE_SUB(NOW(), INTERVAL 277 HOUR), 0, '9000000193'),
  (@tid, 'L-4394', 'Manish Rao', 'Halcyon Health', 'Founder', '+91 9000000194', 'manish.rao194@example.com',
   'Ahmedabad', 'Gujarat', 'Healthcare', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 67 DAY), 'new', @u_sneha, NULL, 0, '9000000194'),
  (@tid, 'L-4395', 'Amit Rao', 'Blue Ridge Logistics', 'Director', '+91 9000000195', 'amit.rao195@example.com',
   'Chennai', 'Tamil Nadu', 'Logistics', '51-200', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 36 DAY), 'new', @u_vikram, NULL, 0, '9000000195'),
  (@tid, 'L-4396', 'Preeti Verma', 'Quanta Labs', 'CTO', '+91 9000000196', 'preeti.verma196@example.com',
   'Bengaluru', 'Karnataka', 'Real Estate', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 79 DAY), 'new', @u_vikram, NULL, 0, '9000000196'),
  (@tid, 'L-4397', 'Karan Reddy', 'Quanta Labs', 'Director', '+91 9000000197', 'karan.reddy197@example.com',
   'Ahmedabad', 'Gujarat', 'Manufacturing', '51-200', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 48 DAY), 'lost', @u_aisha, DATE_SUB(NOW(), INTERVAL 325 HOUR), 0, '9000000197'),
  (@tid, 'L-4398', 'Vivek Saxena', 'Lumen Textiles', 'Procurement Head', '+91 9000000198', 'vivek.saxena198@example.com',
   'Surat', 'Gujarat', 'Logistics', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 100 HOUR), 0, '9000000198'),
  (@tid, 'L-4399', 'Deepak Saxena', 'Apex Fabricators', 'Founder', '+91 9000000199', 'deepak.saxena199@example.com',
   'Surat', 'Gujarat', 'Manufacturing', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 40 DAY), 'new', NULL, NULL, 0, '9000000199'),
  (@tid, 'L-4400', 'Rajesh Shetty', 'Lumen Textiles', 'Plant Manager', '+91 9000000200', 'rajesh.shetty200@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 257 HOUR), 0, '9000000200'),
  (@tid, 'L-4401', 'Suresh Rao', 'Apex Fabricators', 'VP Sales', '+91 9000000201', 'suresh.rao201@example.com',
   'Delhi', 'Delhi', 'Real Estate', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 72 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 45 HOUR), 0, '9000000201'),
  (@tid, 'L-4402', 'Ritu Iyer', 'Blue Ridge Logistics', 'Plant Manager', '+91 9000000202', 'ritu.iyer202@example.com',
   'Hyderabad', 'Telangana', 'IT Services', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 43 DAY), 'new', NULL, NULL, 0, '9000000202'),
  (@tid, 'L-4403', 'Kavya Reddy', 'Trident Motors', 'Plant Manager', '+91 9000000203', 'kavya.reddy203@example.com',
   'Bengaluru', 'Karnataka', 'Retail', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'new', @u_rahul, NULL, 0, '9000000203'),
  (@tid, 'L-4404', 'Manish Pillai', 'Quanta Labs', 'Founder', '+91 9000000204', 'manish.pillai204@example.com',
   'Mumbai', 'Maharashtra', 'Healthcare', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 87 DAY), 'new', @u_aisha, NULL, 0, '9000000204'),
  (@tid, 'L-4405', 'Manish Pillai', 'Apex Fabricators', 'Procurement Head', '+91 9000000205', 'manish.pillai205@example.com',
   'Surat', 'Gujarat', 'IT Services', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'new', NULL, NULL, 0, '9000000205'),
  (@tid, 'L-4406', 'Kavya Gupta', 'Nova Retail', 'Director', '+91 9000000206', 'kavya.gupta206@example.com',
   'Surat', 'Gujarat', 'Healthcare', '201-500', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 15 HOUR), 0, '9000000206'),
  (@tid, 'L-4407', 'Imran Joshi', 'Ironwood Traders', 'Operations Manager', '+91 9000000207', 'imran.joshi207@example.com',
   'Hyderabad', 'Telangana', 'Retail', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 48 DAY), 'new', @u_vikram, NULL, 0, '9000000207'),
  (@tid, 'L-4408', 'Nikhil Chopra', 'Ironwood Traders', 'Founder', '+91 9000000208', 'nikhil.chopra208@example.com',
   'Hyderabad', 'Telangana', 'Retail', '1-10', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'won', @u_vikram, DATE_SUB(NOW(), INTERVAL 239 HOUR), 0, '9000000208'),
  (@tid, 'L-4409', 'Sonal Reddy', 'Meridian Estates', 'Procurement Head', '+91 9000000209', 'sonal.reddy209@example.com',
   'Hyderabad', 'Telangana', 'Real Estate', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 44 DAY), 'new', @u_rahul, NULL, 0, '9000000209'),
  (@tid, 'L-4410', 'Kavya Chopra', 'Quanta Labs', 'Operations Manager', '+91 9000000210', 'kavya.chopra210@example.com',
   'Bengaluru', 'Karnataka', 'Manufacturing', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 73 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 443 HOUR), 0, '9000000210'),
  (@tid, 'L-4411', 'Rajesh Saxena', 'Apex Fabricators', 'VP Sales', '+91 9000000211', 'rajesh.saxena211@example.com',
   'Bengaluru', 'Karnataka', 'Real Estate', '1-10', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 43 DAY), 'lost', NULL, DATE_SUB(NOW(), INTERVAL 107 HOUR), 0, '9000000211'),
  (@tid, 'L-4412', 'Suresh Reddy', 'Corex Systems', 'CTO', '+91 9000000212', 'suresh.reddy212@example.com',
   'Delhi', 'Delhi', 'Healthcare', '11-50', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 40 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 180 HOUR), 0, '9000000212'),
  (@tid, 'L-4413', 'Tanvi Saxena', 'Quanta Labs', 'CTO', '+91 9000000213', 'tanvi.saxena213@example.com',
   'Pune', 'Maharashtra', 'Retail', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 87 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 434 HOUR), 0, '9000000213'),
  (@tid, 'L-4414', 'Anjali Banerjee', 'Lumen Textiles', 'Director', '+91 9000000214', 'anjali.banerjee214@example.com',
   'Bengaluru', 'Karnataka', 'Real Estate', '51-200', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'new', NULL, NULL, 0, '9000000214'),
  (@tid, 'L-4415', 'Divya Malhotra', 'Trident Motors', 'Operations Manager', '+91 9000000215', 'divya.malhotra215@example.com',
   'Chennai', 'Tamil Nadu', 'Logistics', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 74 DAY), 'new', NULL, NULL, 0, '9000000215'),
  (@tid, 'L-4416', 'Divya Joshi', 'Quanta Labs', 'Founder', '+91 9000000216', 'divya.joshi216@example.com',
   'Mumbai', 'Maharashtra', 'Logistics', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 38 DAY), 'won', NULL, DATE_SUB(NOW(), INTERVAL 29 HOUR), 0, '9000000216'),
  (@tid, 'L-4417', 'Preeti Shetty', 'Corex Systems', 'Operations Manager', '+91 9000000217', 'preeti.shetty217@example.com',
   'Delhi', 'Delhi', 'Logistics', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 313 HOUR), 0, '9000000217'),
  (@tid, 'L-4418', 'Tanvi Verma', 'Blue Ridge Logistics', 'Founder', '+91 9000000218', 'tanvi.verma218@example.com',
   'Mumbai', 'Maharashtra', 'Retail', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'new', @u_rahul, NULL, 0, '9000000218'),
  (@tid, 'L-4419', 'Farah Pillai', 'Apex Fabricators', 'VP Sales', '+91 9000000219', 'farah.pillai219@example.com',
   'Delhi', 'Delhi', 'Manufacturing', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'new', @u_vikram, NULL, 0, '9000000219'),
  (@tid, 'L-4420', 'Meera Shetty', 'Ironwood Traders', 'Purchase Executive', '+91 9000000220', 'meera.shetty220@example.com',
   'Pune', 'Maharashtra', 'Logistics', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 26 DAY), 'new', @u_rahul, NULL, 0, '9000000220'),
  (@tid, 'L-4421', 'Rohan Rao', 'Nova Retail', 'Operations Manager', '+91 9000000221', 'rohan.rao221@example.com',
   'Hyderabad', 'Telangana', 'Manufacturing', '500+', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 22 DAY), 'working', @u_priya, DATE_SUB(NOW(), INTERVAL 286 HOUR), 0, '9000000221'),
  (@tid, 'L-4422', 'Meera Joshi', 'Meridian Estates', 'Purchase Executive', '+91 9000000222', 'meera.joshi222@example.com',
   'Delhi', 'Delhi', 'Retail', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 79 DAY), 'won', @u_rahul, DATE_SUB(NOW(), INTERVAL 267 HOUR), 0, '9000000222'),
  (@tid, 'L-4423', 'Pooja Saxena', 'Apex Fabricators', 'Plant Manager', '+91 9000000223', 'pooja.saxena223@example.com',
   'Surat', 'Gujarat', 'Retail', '500+', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 47 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 463 HOUR), 0, '9000000223'),
  (@tid, 'L-4424', 'Rajesh Joshi', 'Skyline Packaging', 'Founder', '+91 9000000224', 'rajesh.joshi224@example.com',
   'Mumbai', 'Maharashtra', 'Real Estate', '500+', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 11 DAY), 'new', @u_vikram, NULL, 0, '9000000224'),
  (@tid, 'L-4425', 'Sonal Gupta', 'Ironwood Traders', 'Director', '+91 9000000225', 'sonal.gupta225@example.com',
   'Surat', 'Gujarat', 'IT Services', '51-200', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 78 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 197 HOUR), 0, '9000000225'),
  (@tid, 'L-4426', 'Kavya Pillai', 'Corex Systems', 'CTO', '+91 9000000226', 'kavya.pillai226@example.com',
   'Surat', 'Gujarat', 'IT Services', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 68 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 472 HOUR), 0, '9000000226'),
  (@tid, 'L-4427', 'Meera Verma', 'Ironwood Traders', 'Director', '+91 9000000227', 'meera.verma227@example.com',
   'Ahmedabad', 'Gujarat', 'Logistics', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 78 DAY), 'lost', @u_sneha, DATE_SUB(NOW(), INTERVAL 323 HOUR), 0, '9000000227'),
  (@tid, 'L-4428', 'Kavya Joshi', 'Nova Retail', 'VP Sales', '+91 9000000228', 'kavya.joshi228@example.com',
   'Ahmedabad', 'Gujarat', 'Retail', '11-50', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 420 HOUR), 0, '9000000228'),
  (@tid, 'L-4429', 'Ritu Verma', 'Meridian Estates', 'Founder', '+91 9000000229', 'ritu.verma229@example.com',
   'Chennai', 'Tamil Nadu', 'Manufacturing', '201-500', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 62 DAY), 'lost', @u_vikram, DATE_SUB(NOW(), INTERVAL 140 HOUR), 0, '9000000229'),
  (@tid, 'L-4430', 'Sanjay Iyer', 'Vertex Industries', 'VP Sales', '+91 9000000230', 'sanjay.iyer230@example.com',
   'Surat', 'Gujarat', 'IT Services', '51-200', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'working', NULL, DATE_SUB(NOW(), INTERVAL 303 HOUR), 0, '9000000230'),
  (@tid, 'L-4431', 'Deepak Rao', 'Meridian Estates', 'Founder', '+91 9000000231', 'deepak.rao231@example.com',
   'Pune', 'Maharashtra', 'Retail', '201-500', @src5, 136.67,
   DATE_SUB(CURDATE(), INTERVAL 36 DAY), 'new', NULL, NULL, 0, '9000000231'),
  (@tid, 'L-4432', 'Ritu Joshi', 'Trident Motors', 'Founder', '+91 9000000232', 'ritu.joshi232@example.com',
   'Surat', 'Gujarat', 'Healthcare', '201-500', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 88 DAY), 'new', NULL, NULL, 0, '9000000232'),
  (@tid, 'L-4433', 'Amit Gupta', 'Vertex Industries', 'Plant Manager', '+91 9000000233', 'amit.gupta233@example.com',
   'Ahmedabad', 'Gujarat', 'Logistics', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 24 DAY), 'working', @u_aisha, DATE_SUB(NOW(), INTERVAL 488 HOUR), 0, '9000000233'),
  (@tid, 'L-4434', 'Farah Bose', 'Halcyon Health', 'Operations Manager', '+91 9000000234', 'farah.bose234@example.com',
   'Delhi', 'Delhi', 'Logistics', '51-200', @src2, 196.67,
   DATE_SUB(CURDATE(), INTERVAL 46 DAY), 'new', NULL, NULL, 0, '9000000234'),
  (@tid, 'L-4435', 'Deepak Rao', 'Halcyon Health', 'VP Sales', '+91 9000000235', 'deepak.rao235@example.com',
   'Pune', 'Maharashtra', 'Retail', '1-10', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 47 DAY), 'new', @u_sneha, NULL, 0, '9000000235'),
  (@tid, 'L-4436', 'Sonal Saxena', 'Quanta Labs', 'Purchase Executive', '+91 9000000236', 'sonal.saxena236@example.com',
   'Surat', 'Gujarat', 'Manufacturing', '500+', @src1, 90.00,
   DATE_SUB(CURDATE(), INTERVAL 64 DAY), 'working', @u_rahul, DATE_SUB(NOW(), INTERVAL 323 HOUR), 0, '9000000236'),
  (@tid, 'L-4437', 'Ritu Kulkarni', 'Quanta Labs', 'Purchase Executive', '+91 9000000237', 'ritu.kulkarni237@example.com',
   'Delhi', 'Delhi', 'Real Estate', '11-50', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 50 DAY), 'working', @u_sneha, DATE_SUB(NOW(), INTERVAL 229 HOUR), 0, '9000000237'),
  (@tid, 'L-4438', 'Vivek Reddy', 'Nova Retail', 'CTO', '+91 9000000238', 'vivek.reddy238@example.com',
   'Bengaluru', 'Karnataka', 'Logistics', '500+', @src4, 31.67,
   DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'new', @u_sneha, NULL, 0, '9000000238'),
  (@tid, 'L-4439', 'Ritu Saxena', 'Vertex Industries', 'Procurement Head', '+91 9000000239', 'ritu.saxena239@example.com',
   'Chennai', 'Tamil Nadu', 'Retail', '1-10', @src3, 0.00,
   DATE_SUB(CURDATE(), INTERVAL 40 DAY), 'new', @u_rahul, NULL, 0, '9000000239');

UPDATE lead_sources s
   SET lead_count = (SELECT COUNT(*) FROM leads l WHERE l.source_id = s.id)
 WHERE s.tenant_id = @tid;


-- ==================================================================
-- REVEAL LEDGER
-- ==================================================================
--
-- THIS TABLE IS THE QUOTA. api/auth.php counts rows here for today
-- rather than reading a counter column, because a counter can drift and
-- a ledger cannot.
--
-- Aisha is seeded at exactly her 25/day limit, so the quota-exhausted
-- path can be tested without spending 25 clicks: sign in as her and
-- every reveal button is already disabled.
--
-- One INSERT per rep rather than a UNION. MySQL and MariaDB reject a
-- bare 'SELECT ... LIMIT n UNION ALL SELECT ...' - each branch would
-- have to be wrapped in its own parentheses. Separate statements are
-- clearer than getting that punctuation right.

INSERT INTO lead_reveals (tenant_id, user_id, lead_id, field, ip, reveal_date, at)
SELECT @tid, @u_priya, l.id, 'phone', '103.42.18.7', CURDATE(),
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 400) MINUTE)
  FROM leads l
 WHERE l.tenant_id = @tid AND l.owner_id = @u_priya
 LIMIT 31;

INSERT INTO lead_reveals (tenant_id, user_id, lead_id, field, ip, reveal_date, at)
SELECT @tid, @u_rahul, l.id, 'phone', '103.42.18.7', CURDATE(),
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 400) MINUTE)
  FROM leads l
 WHERE l.tenant_id = @tid AND l.owner_id = @u_rahul
 LIMIT 38;

INSERT INTO lead_reveals (tenant_id, user_id, lead_id, field, ip, reveal_date, at)
SELECT @tid, @u_aisha, l.id, 'phone', '103.42.18.7', CURDATE(),
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 400) MINUTE)
  FROM leads l
 WHERE l.tenant_id = @tid AND l.owner_id = @u_aisha
 LIMIT 25;

INSERT INTO lead_reveals (tenant_id, user_id, lead_id, field, ip, reveal_date, at)
SELECT @tid, @u_vikram, l.id, 'phone', '103.42.18.7', CURDATE(),
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 400) MINUTE)
  FROM leads l
 WHERE l.tenant_id = @tid AND l.owner_id = @u_vikram
 LIMIT 12;


-- ==================================================================
-- AUDIT LOG
-- ==================================================================
--
-- Backfilled so the audit page and the 14-day dashboard trend are not
-- empty on a fresh install. Real rows are written by the endpoints as
-- things actually happen.

-- One row per reveal, mirroring what lead-reveal.php writes.
-- The detail names the FIELD, never the value: an audit log that
-- recorded what was revealed would be a second copy of the lead list,
-- sitting in the one table nobody is allowed to delete.
INSERT INTO audit_log (tenant_id, actor_id, actor_name, action, subject, detail, ip, at)
SELECT @tid, r.user_id, u.name, 'reveal', l.ref, 'Revealed phone', '103.42.18.7', r.at
  FROM lead_reveals r
  JOIN users u ON u.id = r.user_id
  JOIN leads l ON l.id = r.lead_id
 WHERE r.tenant_id = @tid;

-- Status changes over the last 14 days. The dashboard plots these as
-- the 'contacted' series against 'reveals' - the gap between the two
-- lines is the signal an admin watches for.
INSERT INTO audit_log (tenant_id, actor_id, actor_name, action, subject, detail, ip, at)
SELECT @tid, u.id, u.name, 'status', l.ref, CONCAT('new -> ', l.status), '103.42.18.9',
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 14) DAY)
  FROM leads l
  JOIN users u ON u.id = l.owner_id
 WHERE l.tenant_id = @tid AND l.status <> 'new'
 LIMIT 120;

INSERT INTO audit_log (tenant_id, actor_id, actor_name, action, subject, detail, ip, at)
SELECT @tid, u.id, u.name, 'login', NULL, 'Signed in', '103.42.18.7',
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 14) DAY)
  FROM users u
 WHERE u.tenant_id = @tid
 ORDER BY RAND()
 LIMIT 30;


-- ==================================================================
-- SUMMARY - what this file just inserted
-- ==================================================================

SELECT
  (SELECT COUNT(*) FROM users           WHERE tenant_id = @tid) AS users,
  (SELECT COUNT(*) FROM leads           WHERE tenant_id = @tid) AS leads,
  (SELECT COUNT(*) FROM leads           WHERE tenant_id = @tid AND owner_id IS NULL) AS unassigned,
  (SELECT COUNT(*) FROM leads           WHERE tenant_id = @tid AND honeytoken = 1) AS decoys,
  (SELECT COUNT(*) FROM lead_sources    WHERE tenant_id = @tid) AS sources,
  (SELECT COUNT(*) FROM company_devices WHERE tenant_id = @tid) AS devices,
  (SELECT COUNT(*) FROM lead_reveals    WHERE tenant_id = @tid) AS reveals_today,
  (SELECT COUNT(*) FROM audit_log       WHERE tenant_id = @tid) AS audit_rows;
