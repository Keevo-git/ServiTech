CREATE TABLE IF NOT EXISTS uploads (
  id BIGSERIAL PRIMARY KEY,
  upload_token VARCHAR(64) NOT NULL UNIQUE,
  user_id INTEGER NOT NULL,
  queue_id BIGINT NULL REFERENCES queues(id) ON DELETE SET NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_key VARCHAR(255) NOT NULL UNIQUE,
  file_extension VARCHAR(16) NOT NULL,
  mime_type VARCHAR(160) NOT NULL,
  byte_size BIGINT NOT NULL CHECK (byte_size >= 0),
  checksum_sha256 CHAR(64) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  linked_at TIMESTAMPTZ NULL,
  deleted_at TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_uploads_user_id ON uploads(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_uploads_queue_id ON uploads(queue_id);
CREATE INDEX IF NOT EXISTS idx_uploads_orphan_cleanup
  ON uploads(created_at)
  WHERE queue_id IS NULL AND deleted_at IS NULL;
