BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS fullname VARCHAR(160);
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS contact VARCHAR(40);
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_hash TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(32) NOT NULL DEFAULT 'customer';
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS consent_accepted_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS consent_version VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_token CHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_expires TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_sent_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

DO $$
BEGIN
  IF EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'users'
      AND column_name = 'contacts'
  ) THEN
    EXECUTE '
      UPDATE users
      SET contact = COALESCE(NULLIF(contact, ''''), NULLIF(contacts, ''''))
      WHERE contact IS NULL OR contact = ''''
    ';
  END IF;
END $$;

ALTER TABLE queues ADD COLUMN IF NOT EXISTS user_id INTEGER;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS queue_code VARCHAR(64);
ALTER TABLE queues ADD COLUMN IF NOT EXISTS category VARCHAR(64);
ALTER TABLE queues ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'PENDING';
ALTER TABLE queues ADD COLUMN IF NOT EXISTS details JSONB NOT NULL DEFAULT '{}'::jsonb;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS completed_at TIMESTAMPTZ NULL;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS lifecycle_stage VARCHAR(16);
ALTER TABLE queues ADD COLUMN IF NOT EXISTS queue_cycle_date DATE;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS daily_sequence INTEGER;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE queues ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

UPDATE queues
SET queue_cycle_date = COALESCE(
  (created_at AT TIME ZONE 'Asia/Manila')::date,
  (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
)
WHERE queue_cycle_date IS NULL;

UPDATE queues
SET daily_sequence = COALESCE(
  NULLIF(SUBSTRING(queue_code FROM '([0-9]+)$'), '')::INTEGER,
  0
)
WHERE daily_sequence IS NULL;

UPDATE queues
SET lifecycle_stage = CASE
  WHEN queue_cycle_date < (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
    OR UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED')
  THEN 'ORDER'
  ELSE 'QUEUE'
END
WHERE lifecycle_stage IS NULL
   OR UPPER(TRIM(lifecycle_stage)) NOT IN ('QUEUE', 'ORDER');

ALTER TABLE queues ALTER COLUMN lifecycle_stage SET DEFAULT 'QUEUE';
ALTER TABLE queues ALTER COLUMN lifecycle_stage SET NOT NULL;
ALTER TABLE queues ALTER COLUMN queue_cycle_date SET DEFAULT ((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date);
ALTER TABLE queues ALTER COLUMN queue_cycle_date SET NOT NULL;
ALTER TABLE queues ALTER COLUMN daily_sequence SET DEFAULT 0;
ALTER TABLE queues ALTER COLUMN daily_sequence SET NOT NULL;

CREATE TABLE IF NOT EXISTS payments (
  id BIGSERIAL PRIMARY KEY,
  queue_id BIGINT NOT NULL,
  user_id INTEGER NOT NULL,
  amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(32) NOT NULL,
  reference_number VARCHAR(120) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE payments ADD COLUMN IF NOT EXISTS queue_id BIGINT;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS user_id INTEGER;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS amount NUMERIC(12, 2) NOT NULL DEFAULT 0;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_method VARCHAR(32);
ALTER TABLE payments ADD COLUMN IF NOT EXISTS reference_number VARCHAR(120) NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'PENDING';
ALTER TABLE payments ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE payments ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS notifications (
  id BIGSERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL,
  type TEXT NOT NULL DEFAULT 'queue',
  reference_id BIGINT NULL,
  message TEXT NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  deleted_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS user_id INTEGER;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type TEXT NOT NULL DEFAULT 'queue';
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_id BIGINT NULL;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS message TEXT;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

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
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE services ADD COLUMN IF NOT EXISTS category TEXT;
ALTER TABLE services ADD COLUMN IF NOT EXISTS name VARCHAR(120);
ALTER TABLE services ADD COLUMN IF NOT EXISTS description VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE services ADD COLUMN IF NOT EXISTS price NUMERIC(10, 2) NULL;
ALTER TABLE services ADD COLUMN IF NOT EXISTS price_range VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE services ADD COLUMN IF NOT EXISTS pricing_json JSONB NULL;
ALTER TABLE services ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE services ADD COLUMN IF NOT EXISTS sort_order INTEGER NOT NULL DEFAULT 0;
ALTER TABLE services ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE services ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS announcements (
  id BIGSERIAL PRIMARY KEY,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE announcements ADD COLUMN IF NOT EXISTS title TEXT;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS message TEXT;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS queue_status_history (
  id BIGSERIAL PRIMARY KEY,
  queue_id BIGINT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  old_status TEXT NULL,
  new_status TEXT NOT NULL,
  admin_id INTEGER NULL,
  admin_name VARCHAR(160) NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS queue_id BIGINT;
ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS category TEXT NOT NULL DEFAULT '';
ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS old_status TEXT NULL;
ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS new_status TEXT;
ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS admin_id INTEGER NULL;
ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS admin_name VARCHAR(160) NOT NULL DEFAULT '';
ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS notes TEXT NOT NULL DEFAULT '';
ALTER TABLE queue_status_history ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS uploads (
  id BIGSERIAL PRIMARY KEY,
  upload_token VARCHAR(64) NOT NULL UNIQUE,
  user_id INTEGER NOT NULL,
  queue_id BIGINT NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_key VARCHAR(255) NOT NULL UNIQUE,
  file_extension VARCHAR(16) NOT NULL,
  mime_type VARCHAR(160) NOT NULL,
  byte_size BIGINT NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  linked_at TIMESTAMPTZ NULL,
  deleted_at TIMESTAMPTZ NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGSERIAL PRIMARY KEY,
  attempt_key CHAR(64) NOT NULL,
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE uploads ADD COLUMN IF NOT EXISTS upload_token VARCHAR(64);
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS user_id INTEGER;
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS queue_id BIGINT NULL;
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS original_name VARCHAR(255);
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS storage_key VARCHAR(255);
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS file_extension VARCHAR(16);
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS mime_type VARCHAR(160);
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS byte_size BIGINT;
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS checksum_sha256 CHAR(64);
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS linked_at TIMESTAMPTZ NULL;
ALTER TABLE uploads ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;

CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (LOWER(email));
CREATE UNIQUE INDEX IF NOT EXISTS users_google_id_unique ON users (google_id) WHERE google_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS users_reset_token_idx ON users (reset_token) WHERE reset_token IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS users_email_verification_token_unique ON users (email_verification_token) WHERE email_verification_token IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_login_attempts_attempt_key_time ON login_attempts (attempt_key, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_email_hash_time ON login_attempts (email_hash, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_attempted_at ON login_attempts (attempted_at);
CREATE INDEX IF NOT EXISTS idx_queues_user_created_at ON queues (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_queues_queue_code ON queues (queue_code);
CREATE INDEX IF NOT EXISTS idx_queues_lifecycle_stage ON queues (lifecycle_stage);
CREATE INDEX IF NOT EXISTS idx_queues_cycle_date_code ON queues (queue_cycle_date, queue_code);
CREATE INDEX IF NOT EXISTS idx_queues_category_lifecycle_created ON queues (category, lifecycle_stage, created_at);
CREATE INDEX IF NOT EXISTS idx_payments_queue_id_id ON payments (queue_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_payments_user_created_at ON payments (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_created_at ON notifications (user_id, created_at DESC, id DESC);
DROP INDEX IF EXISTS idx_notifications_user_unread;
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread ON notifications (user_id, created_at DESC) WHERE is_read = FALSE AND deleted_at IS NULL;
WITH duplicate_notifications AS (
  SELECT id,
         ROW_NUMBER() OVER (
           PARTITION BY user_id, LOWER(TRIM(COALESCE(type, 'queue'))), COALESCE(reference_id, 0), MD5(TRIM(COALESCE(message, '')))
           ORDER BY created_at DESC, id DESC
         ) AS duplicate_rank
  FROM notifications
  WHERE deleted_at IS NULL
)
UPDATE notifications
SET deleted_at = NOW()
WHERE id IN (
  SELECT id
  FROM duplicate_notifications
  WHERE duplicate_rank > 1
);
CREATE UNIQUE INDEX IF NOT EXISTS notifications_active_event_unique
  ON notifications (user_id, LOWER(TRIM(COALESCE(type, 'queue'))), COALESCE(reference_id, 0), MD5(TRIM(COALESCE(message, ''))))
  WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_services_category ON services (category);
CREATE INDEX IF NOT EXISTS idx_services_active ON services (active);
CREATE INDEX IF NOT EXISTS idx_services_catalog_order ON services (category, active, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_announcements_active_updated ON announcements (active, updated_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_queue_status_history_queue_id ON queue_status_history (queue_id, created_at);
CREATE INDEX IF NOT EXISTS idx_uploads_user_id ON uploads (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_uploads_queue_id ON uploads (queue_id);
CREATE INDEX IF NOT EXISTS idx_uploads_orphan_cleanup ON uploads (created_at) WHERE queue_id IS NULL AND deleted_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uploads_upload_token_unique ON uploads (upload_token);
CREATE UNIQUE INDEX IF NOT EXISTS uploads_storage_key_unique ON uploads (storage_key);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queues_user_id_fkey') THEN
    ALTER TABLE queues ADD CONSTRAINT queues_user_id_fkey
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'payments_queue_id_fkey') THEN
    ALTER TABLE payments ADD CONSTRAINT payments_queue_id_fkey
      FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'payments_user_id_fkey') THEN
    ALTER TABLE payments ADD CONSTRAINT payments_user_id_fkey
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'notifications_user_id_fkey') THEN
    ALTER TABLE notifications ADD CONSTRAINT notifications_user_id_fkey
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queue_status_history_queue_id_fkey') THEN
    ALTER TABLE queue_status_history ADD CONSTRAINT queue_status_history_queue_id_fkey
      FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queue_status_history_admin_id_fkey') THEN
    ALTER TABLE queue_status_history ADD CONSTRAINT queue_status_history_admin_id_fkey
      FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploads_user_id_fkey') THEN
    ALTER TABLE uploads ADD CONSTRAINT uploads_user_id_fkey
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploads_queue_id_fkey') THEN
    ALTER TABLE uploads ADD CONSTRAINT uploads_queue_id_fkey
      FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE SET NULL NOT VALID;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_role_check') THEN
    ALTER TABLE users ADD CONSTRAINT users_role_check
      CHECK (LOWER(TRIM(role)) IN ('customer', 'admin')) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queues_status_check') THEN
    ALTER TABLE queues ADD CONSTRAINT queues_status_check
      CHECK (
        UPPER(TRIM(status)) IN (
          'PENDING', 'PENDING PAYMENT', 'APPROVED', 'ONGOING',
          'FOR PICK-UP', 'FOR PICK UP', 'DONE', 'COMPLETED',
          'CANCELLED', 'CANCELED'
        )
      ) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queues_lifecycle_stage_check') THEN
    ALTER TABLE queues ADD CONSTRAINT queues_lifecycle_stage_check
      CHECK (lifecycle_stage IN ('QUEUE', 'ORDER')) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queues_daily_sequence_check') THEN
    ALTER TABLE queues ADD CONSTRAINT queues_daily_sequence_check
      CHECK (
        daily_sequence IS NULL
        OR CASE
          WHEN TRIM(daily_sequence::TEXT) ~ '^[0-9]+$'
          THEN TRIM(daily_sequence::TEXT)::NUMERIC >= 0
          ELSE FALSE
        END
      ) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'payments_amount_check') THEN
    ALTER TABLE payments ADD CONSTRAINT payments_amount_check
      CHECK (
        amount IS NULL
        OR CASE
          WHEN TRIM(amount::TEXT) ~ '^[0-9]+([.][0-9]+)?$'
          THEN TRIM(amount::TEXT)::NUMERIC >= 0
          ELSE FALSE
        END
      ) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'payments_method_check') THEN
    ALTER TABLE payments ADD CONSTRAINT payments_method_check
      CHECK (LOWER(TRIM(payment_method)) IN ('cash', 'gcash')) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'services_category_check') THEN
    ALTER TABLE services ADD CONSTRAINT services_category_check
      CHECK (category IN ('printing', 'repair', 'installation')) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'services_price_check') THEN
    ALTER TABLE services ADD CONSTRAINT services_price_check
      CHECK (
        price IS NULL
        OR CASE
          WHEN TRIM(price::TEXT) ~ '^[0-9]+([.][0-9]+)?$'
          THEN TRIM(price::TEXT)::NUMERIC >= 0
          ELSE FALSE
        END
      ) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploads_byte_size_check') THEN
    ALTER TABLE uploads ADD CONSTRAINT uploads_byte_size_check
      CHECK (
        byte_size IS NULL
        OR CASE
          WHEN TRIM(byte_size::TEXT) ~ '^[0-9]+$'
          THEN TRIM(byte_size::TEXT)::NUMERIC >= 0
          ELSE FALSE
        END
      ) NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploads_token_check') THEN
    ALTER TABLE uploads ADD CONSTRAINT uploads_token_check
      CHECK (upload_token::TEXT ~ '^[0-9a-f]{64}$') NOT VALID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploads_checksum_sha256_check') THEN
    ALTER TABLE uploads ADD CONSTRAINT uploads_checksum_sha256_check
      CHECK (checksum_sha256::TEXT ~ '^[0-9a-f]{64}$') NOT VALID;
  END IF;
END $$;

COMMIT;
