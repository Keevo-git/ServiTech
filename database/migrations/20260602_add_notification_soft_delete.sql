ALTER TABLE notifications ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;

DROP INDEX IF EXISTS idx_notifications_user_unread;
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
  ON notifications (user_id, created_at DESC)
  WHERE is_read = FALSE AND deleted_at IS NULL;

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
