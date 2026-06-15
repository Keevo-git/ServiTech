BEGIN;

ALTER TABLE queues
  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL,
  ADD COLUMN IF NOT EXISTS delete_reason TEXT NULL;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conname = 'queues_deleted_by_fkey'
      AND conrelid = 'queues'::regclass
  ) THEN
    ALTER TABLE queues
      ADD CONSTRAINT queues_deleted_by_fkey
      FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;
  END IF;
END
$$;

CREATE INDEX IF NOT EXISTS idx_queues_order_recycle_bin
  ON queues (deleted_at DESC, id DESC)
  WHERE deleted_at IS NOT NULL
    AND UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER';

COMMIT;
