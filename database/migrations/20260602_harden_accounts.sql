BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS consent_accepted_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS consent_version VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_token CHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_expires TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_sent_at TIMESTAMPTZ NULL;

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

COMMIT;
