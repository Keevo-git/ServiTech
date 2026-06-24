BEGIN;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id BIGSERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  selector CHAR(32) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMPTZ NOT NULL,
  last_used_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT remember_tokens_selector_check CHECK (selector ~ '^[a-f0-9]{32}$'),
  CONSTRAINT remember_tokens_hash_check CHECK (token_hash ~ '^[a-f0-9]{64}$')
);

CREATE INDEX IF NOT EXISTS idx_remember_tokens_user_id
  ON remember_tokens (user_id);
CREATE INDEX IF NOT EXISTS idx_remember_tokens_expires_at
  ON remember_tokens (expires_at);

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    REVOKE ALL ON TABLE remember_tokens FROM anon;
    REVOKE ALL ON SEQUENCE remember_tokens_id_seq FROM anon;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    REVOKE ALL ON TABLE remember_tokens FROM authenticated;
    REVOKE ALL ON SEQUENCE remember_tokens_id_seq FROM authenticated;
  END IF;
END
$$;

COMMIT;
