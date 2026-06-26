-- Replace the email value with the trusted owner account that already exists.
-- Run this only after 20260626_add_super_admin_roles_and_activity_logs.sql.
-- This does not create or expose a password.

BEGIN;

UPDATE users
SET role = 'super_admin',
    account_status = 'active',
    updated_at = NOW()
WHERE LOWER(email) = LOWER('owner@example.com')
  AND LOWER(TRIM(role)) IN ('admin', 'super_admin');

COMMIT;
