BEGIN;

CREATE TABLE IF NOT EXISTS users (
  id SERIAL PRIMARY KEY,
  fullname VARCHAR(160) NOT NULL,
  email VARCHAR(255) NOT NULL,
  contact VARCHAR(40) NULL,
  password_hash TEXT NULL,
  google_id VARCHAR(255) NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'customer',
  reset_token TEXT NULL,
  reset_token_expires TIMESTAMPTZ NULL,
  consent_accepted_at TIMESTAMPTZ NULL,
  consent_version VARCHAR(64) NULL,
  email_verified_at TIMESTAMPTZ NULL,
  email_verification_token CHAR(64) NULL,
  email_verification_expires TIMESTAMPTZ NULL,
  email_verification_sent_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT users_role_check CHECK (LOWER(TRIM(role)) IN ('customer', 'admin'))
);

CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique
  ON users (LOWER(email));
CREATE UNIQUE INDEX IF NOT EXISTS users_google_id_unique
  ON users (google_id)
  WHERE google_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS users_reset_token_idx
  ON users (reset_token)
  WHERE reset_token IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS users_email_verification_token_unique
  ON users (email_verification_token)
  WHERE email_verification_token IS NOT NULL;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGSERIAL PRIMARY KEY,
  attempt_key CHAR(64) NOT NULL,
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_attempt_key_time
  ON login_attempts (attempt_key, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_email_hash_time
  ON login_attempts (email_hash, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_attempted_at
  ON login_attempts (attempted_at);

CREATE TABLE IF NOT EXISTS queues (
  id BIGSERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  queue_code VARCHAR(64) NOT NULL UNIQUE,
  category VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  details JSONB NOT NULL DEFAULT '{}'::jsonb,
  price NUMERIC(12, 2) NULL,
  paid_amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
  lifecycle_stage VARCHAR(16) NOT NULL DEFAULT 'QUEUE',
  queue_cycle_date DATE NOT NULL DEFAULT ((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date),
  daily_sequence INTEGER NOT NULL DEFAULT 0,
  completed_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT queues_status_check CHECK (
    UPPER(TRIM(status)) IN (
      'PENDING', 'PENDING PAYMENT', 'APPROVED', 'ONGOING',
      'FOR PICK-UP', 'FOR PICK UP', 'DONE', 'COMPLETED',
      'CANCELLED', 'CANCELED'
    )
  ),
  CONSTRAINT queues_price_check CHECK (price IS NULL OR price >= 0),
  CONSTRAINT queues_paid_amount_check CHECK (paid_amount >= 0 AND (price IS NULL OR paid_amount <= price)),
  CONSTRAINT queues_lifecycle_stage_check CHECK (lifecycle_stage IN ('QUEUE', 'ORDER')),
  CONSTRAINT queues_daily_sequence_check CHECK (daily_sequence >= 0)
);

CREATE INDEX IF NOT EXISTS idx_queues_user_created_at
  ON queues (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_queues_queue_code
  ON queues (queue_code);
CREATE INDEX IF NOT EXISTS idx_queues_lifecycle_stage
  ON queues (lifecycle_stage);
CREATE INDEX IF NOT EXISTS idx_queues_cycle_date_code
  ON queues (queue_cycle_date, queue_code);
CREATE INDEX IF NOT EXISTS idx_queues_category_lifecycle_created
  ON queues (category, lifecycle_stage, created_at);

CREATE TABLE IF NOT EXISTS payments (
  id BIGSERIAL PRIMARY KEY,
  queue_id BIGINT NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(32) NOT NULL,
  reference_number VARCHAR(120) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT payments_amount_check CHECK (amount >= 0),
  CONSTRAINT payments_method_check CHECK (LOWER(TRIM(payment_method)) IN ('cash', 'gcash'))
);

CREATE INDEX IF NOT EXISTS idx_payments_queue_id_id
  ON payments (queue_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_payments_user_created_at
  ON payments (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS notifications (
  id BIGSERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type TEXT NOT NULL DEFAULT 'queue',
  reference_id BIGINT NULL,
  message TEXT NOT NULL,
  event_key TEXT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  deleted_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_created_at
  ON notifications (user_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
  ON notifications (user_id, created_at DESC)
  WHERE is_read = FALSE AND deleted_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS notifications_active_event_unique
  ON notifications (user_id, LOWER(TRIM(COALESCE(type, 'queue'))), COALESCE(reference_id, 0), COALESCE(NULLIF(TRIM(event_key), ''), MD5(TRIM(COALESCE(message, '')))))
  WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS services (
  id BIGSERIAL PRIMARY KEY,
  category TEXT NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  price NUMERIC(10, 2) NULL,
  price_range VARCHAR(255) NOT NULL DEFAULT '',
  pricing_json JSONB NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT services_category_check CHECK (category IN ('printing', 'repair', 'installation')),
  CONSTRAINT services_price_check CHECK (price IS NULL OR price >= 0)
);

CREATE INDEX IF NOT EXISTS idx_services_category
  ON services (category);
CREATE INDEX IF NOT EXISTS idx_services_active
  ON services (active);
CREATE INDEX IF NOT EXISTS idx_services_catalog_order
  ON services (category, active, sort_order, id);

CREATE TABLE IF NOT EXISTS announcements (
  id BIGSERIAL PRIMARY KEY,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_announcements_active_updated
  ON announcements (active, updated_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS queue_status_history (
  id BIGSERIAL PRIMARY KEY,
  queue_id BIGINT NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
  category TEXT NOT NULL DEFAULT '',
  old_status TEXT NULL,
  new_status TEXT NOT NULL,
  admin_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  admin_name VARCHAR(160) NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_queue_status_history_queue_id
  ON queue_status_history (queue_id, created_at);

CREATE TABLE IF NOT EXISTS uploads (
  id BIGSERIAL PRIMARY KEY,
  upload_token VARCHAR(64) NOT NULL UNIQUE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  queue_id BIGINT NULL REFERENCES queues(id) ON DELETE SET NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_key VARCHAR(255) NOT NULL UNIQUE,
  file_extension VARCHAR(16) NOT NULL,
  mime_type VARCHAR(160) NOT NULL,
  byte_size BIGINT NOT NULL CHECK (byte_size >= 0),
  checksum_sha256 CHAR(64) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  linked_at TIMESTAMPTZ NULL,
  deleted_at TIMESTAMPTZ NULL,
  CONSTRAINT uploads_token_check CHECK (upload_token ~ '^[0-9a-f]{64}$'),
  CONSTRAINT uploads_checksum_sha256_check CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$')
);

CREATE INDEX IF NOT EXISTS idx_uploads_user_id
  ON uploads (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_uploads_queue_id
  ON uploads (queue_id);
CREATE INDEX IF NOT EXISTS idx_uploads_orphan_cleanup
  ON uploads (created_at)
  WHERE queue_id IS NULL AND deleted_at IS NULL;

COMMIT;
