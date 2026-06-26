BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_completed BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS first_login_completed_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS address TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(160) NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS emergency_contact_relationship VARCHAR(80) NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS emergency_contact_address TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS emergency_contact_number VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS position_title VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_notes TEXT NOT NULL DEFAULT '';

UPDATE users
SET profile_completed = TRUE,
    force_password_change = COALESCE(force_password_change, FALSE),
    updated_at = COALESCE(updated_at, NOW())
WHERE LOWER(TRIM(COALESCE(role, 'customer'))) IN ('admin', 'super_admin')
  AND profile_completed IS DISTINCT FROM TRUE;

CREATE INDEX IF NOT EXISTS idx_users_employee_setup_status
  ON users (LOWER(TRIM(role)), profile_completed, force_password_change)
  WHERE LOWER(TRIM(role)) = 'admin';

COMMIT;
