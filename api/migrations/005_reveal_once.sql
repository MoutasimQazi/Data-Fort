-- Ensure a user can be charged only once for each lead contact field.
-- Run this on an existing database after backing it up.

SET NAMES utf8mb4;

DELETE duplicate_row
FROM lead_reveals AS duplicate_row
JOIN lead_reveals AS original_row
  ON original_row.tenant_id = duplicate_row.tenant_id
 AND original_row.user_id = duplicate_row.user_id
 AND original_row.lead_id = duplicate_row.lead_id
 AND original_row.field = duplicate_row.field
 AND original_row.id < duplicate_row.id;

ALTER TABLE lead_reveals
  ADD UNIQUE KEY uq_reveal_once (tenant_id, user_id, lead_id, field);