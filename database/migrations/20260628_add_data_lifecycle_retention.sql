BEGIN;

ALTER TABLE queues
  ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS archived_by INTEGER NULL,
  ADD COLUMN IF NOT EXISTS archive_reason TEXT NULL,
  ADD COLUMN IF NOT EXISTS archive_batch_id CHAR(32) NULL;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conname = 'queues_archived_by_fkey'
      AND conrelid = 'queues'::regclass
  ) THEN
    ALTER TABLE queues
      ADD CONSTRAINT queues_archived_by_fkey
      FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL;
  END IF;
END
$$;

CREATE TABLE IF NOT EXISTS data_lifecycle_runs (
  id BIGSERIAL PRIMARY KEY,
  run_token CHAR(32) NOT NULL UNIQUE,
  mode VARCHAR(24) NOT NULL DEFAULT 'full',
  dry_run BOOLEAN NOT NULL DEFAULT FALSE,
  force_run BOOLEAN NOT NULL DEFAULT FALSE,
  status VARCHAR(24) NOT NULL DEFAULT 'running',
  started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  finished_at TIMESTAMPTZ NULL,
  retention_policy JSONB NOT NULL DEFAULT '{}'::jsonb,
  report JSONB NOT NULL DEFAULT '{}'::jsonb,
  error_message TEXT NOT NULL DEFAULT '',
  CONSTRAINT data_lifecycle_runs_mode_check CHECK (mode IN ('full', 'uploads')),
  CONSTRAINT data_lifecycle_runs_status_check CHECK (status IN ('running', 'success', 'dry_run', 'failed', 'skipped'))
);

CREATE INDEX IF NOT EXISTS idx_data_lifecycle_runs_started
  ON data_lifecycle_runs (started_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_data_lifecycle_runs_status
  ON data_lifecycle_runs (status, started_at DESC);

CREATE INDEX IF NOT EXISTS idx_queues_live_queue_lookup
  ON queues (category, lifecycle_stage, created_at, id)
  WHERE deleted_at IS NULL
    AND permanently_hidden_at IS NULL
    AND archived_at IS NULL
    AND UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'QUEUE';

CREATE INDEX IF NOT EXISTS idx_queues_live_order_lookup
  ON queues (category, lifecycle_stage, created_at, id)
  WHERE deleted_at IS NULL
    AND permanently_hidden_at IS NULL
    AND archived_at IS NULL
    AND UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER';

CREATE INDEX IF NOT EXISTS idx_queues_archive_eligible
  ON queues (closed_at, id)
  WHERE archived_at IS NULL
    AND deleted_at IS NULL
    AND permanently_hidden_at IS NULL
    AND UPPER(TRIM(COALESCE(status, ''))) IN ('DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED');

CREATE INDEX IF NOT EXISTS idx_queues_archived_history
  ON queues (user_id, closed_at DESC, id DESC)
  WHERE archived_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_queues_recycle_bin_retention
  ON queues (deleted_at, id)
  WHERE deleted_at IS NOT NULL
    AND permanently_hidden_at IS NULL
    AND UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER';

CREATE INDEX IF NOT EXISTS idx_notifications_soft_deleted_retention
  ON notifications (deleted_at)
  WHERE deleted_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_notifications_read_retention
  ON notifications (created_at)
  WHERE deleted_at IS NULL AND is_read = TRUE;

CREATE INDEX IF NOT EXISTS idx_notifications_unread_queue_retention
  ON notifications (reference_id, created_at)
  WHERE deleted_at IS NULL AND is_read = FALSE AND reference_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_uploads_closed_file_retention
  ON uploads (queue_id, created_at)
  WHERE queue_id IS NOT NULL AND deleted_at IS NULL;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    REVOKE ALL ON TABLE data_lifecycle_runs FROM anon;
    REVOKE ALL ON SEQUENCE data_lifecycle_runs_id_seq FROM anon;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    REVOKE ALL ON TABLE data_lifecycle_runs FROM authenticated;
    REVOKE ALL ON SEQUENCE data_lifecycle_runs_id_seq FROM authenticated;
  END IF;
END
$$;

COMMIT;
