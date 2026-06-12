BEGIN;

ALTER TABLE queues
  ADD COLUMN IF NOT EXISTS closed_at TIMESTAMPTZ NULL;

UPDATE queues q
SET closed_at = COALESCE(
  q.closed_at,
  q.completed_at,
  (
    SELECT MAX(h.created_at)
    FROM queue_status_history h
    WHERE h.queue_id = q.id
      AND UPPER(TRIM(COALESCE(h.new_status, ''))) IN (
        'DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED'
      )
  ),
  q.updated_at,
  q.created_at
)
WHERE q.closed_at IS NULL
  AND UPPER(TRIM(COALESCE(q.status, ''))) IN (
    'DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED'
  );

CREATE INDEX IF NOT EXISTS idx_queues_closed_file_retention
  ON queues (closed_at)
  WHERE UPPER(TRIM(COALESCE(status, ''))) IN (
    'DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED'
  );

CREATE INDEX IF NOT EXISTS idx_uploads_linked_retention
  ON uploads (queue_id, deleted_at)
  WHERE queue_id IS NOT NULL AND deleted_at IS NULL;

COMMIT;
