BEGIN;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users
  ADD CONSTRAINT users_role_check
  CHECK (LOWER(TRIM(role)) IN ('customer', 'admin', 'super_admin'));

ALTER TABLE users ADD COLUMN IF NOT EXISTS account_status VARCHAR(32) NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN IF NOT EXISTS force_password_change BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS deactivated_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS deactivated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_account_status_check;
ALTER TABLE users
  ADD CONSTRAINT users_account_status_check
  CHECK (LOWER(TRIM(account_status)) IN ('active', 'deactivated'));

CREATE INDEX IF NOT EXISTS idx_users_role_status
  ON users (LOWER(TRIM(role)), LOWER(TRIM(account_status)), created_at DESC);
CREATE INDEX IF NOT EXISTS idx_users_created_by
  ON users (created_by);

CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGSERIAL PRIMARY KEY,
  user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  user_name VARCHAR(160) NOT NULL DEFAULT '',
  role VARCHAR(32) NOT NULL DEFAULT 'customer',
  action_type VARCHAR(80) NOT NULL,
  target_module VARCHAR(120) NOT NULL,
  target_record_id VARCHAR(120) NULL,
  old_value JSONB NULL,
  new_value JSONB NULL,
  description TEXT NOT NULL,
  ip_address INET NULL,
  user_agent TEXT NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'success',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT activity_logs_role_check CHECK (LOWER(TRIM(role)) IN ('customer', 'admin', 'super_admin')),
  CONSTRAINT activity_logs_status_check CHECK (LOWER(TRIM(status)) IN ('success', 'failed'))
);

CREATE INDEX IF NOT EXISTS idx_activity_logs_created_at
  ON activity_logs (created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_activity_logs_user_created_at
  ON activity_logs (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_logs_action_module
  ON activity_logs (action_type, target_module, created_at DESC);

COMMIT;
