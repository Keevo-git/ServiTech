BEGIN;

ALTER TABLE announcements
  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS idx_announcements_active_not_deleted
  ON announcements (active, updated_at DESC, id DESC)
  WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_announcements_deleted_at
  ON announcements (deleted_at DESC, id DESC)
  WHERE deleted_at IS NOT NULL;

COMMIT;
