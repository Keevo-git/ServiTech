BEGIN;

ALTER TABLE queues
  ADD COLUMN IF NOT EXISTS request_created_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS pending_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS ongoing_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS for_pickup_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS done_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS request_source VARCHAR(64) NOT NULL DEFAULT 'online';

CREATE INDEX IF NOT EXISTS idx_queues_analytics_request_created
  ON queues (request_created_at, id)
  WHERE deleted_at IS NULL
    AND permanently_hidden_at IS NULL
    AND archived_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_queues_analytics_service_filters
  ON queues (request_source, status, category, id)
  WHERE deleted_at IS NULL
    AND permanently_hidden_at IS NULL
    AND archived_at IS NULL;

CREATE TABLE IF NOT EXISTS queue_status_events (
  id BIGSERIAL PRIMARY KEY,
  queue_id BIGINT NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
  queue_code VARCHAR(64) NOT NULL,
  customer_name_snapshot VARCHAR(160) NOT NULL DEFAULT '',
  service_type VARCHAR(120) NOT NULL DEFAULT '',
  payment_method VARCHAR(32) NOT NULL DEFAULT '',
  transition_no INTEGER NOT NULL,
  previous_status VARCHAR(32) NULL,
  status VARCHAR(32) NOT NULL,
  entered_at TIMESTAMPTZ NOT NULL,
  exited_at TIMESTAMPTZ NULL,
  duration_minutes NUMERIC(10, 2) NULL,
  next_status VARCHAR(32) NULL,
  updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  updated_by_name VARCHAR(160) NOT NULL DEFAULT '',
  remarks TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT queue_status_events_transition_no_check CHECK (transition_no >= 0),
  CONSTRAINT queue_status_events_duration_check CHECK (duration_minutes IS NULL OR duration_minutes >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS queue_status_events_import_unique
  ON queue_status_events (queue_id, transition_no, status, entered_at);

CREATE INDEX IF NOT EXISTS idx_queue_status_events_queue_timeline
  ON queue_status_events (queue_id, entered_at, transition_no);

CREATE INDEX IF NOT EXISTS idx_queue_status_events_status_duration
  ON queue_status_events (status, entered_at);

ALTER TABLE queue_status_events
  ADD COLUMN IF NOT EXISTS previous_status VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS updated_by INTEGER NULL,
  ADD COLUMN IF NOT EXISTS updated_by_name VARCHAR(160) NOT NULL DEFAULT '';

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conname = 'queue_status_events_updated_by_fkey'
      AND conrelid = 'queue_status_events'::regclass
  ) THEN
    ALTER TABLE queue_status_events
      ADD CONSTRAINT queue_status_events_updated_by_fkey
      FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
  END IF;
END
$$;

CREATE TABLE IF NOT EXISTS analytics_cycles (
  id BIGSERIAL PRIMARY KEY,
  cycle_key CHAR(7) NOT NULL UNIQUE,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  last_warning_at TIMESTAMPTZ NULL,
  last_warning_days_remaining INTEGER NULL,
  snapshot_created_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT analytics_cycles_status_check CHECK (status IN ('active', 'archived')),
  CONSTRAINT analytics_cycles_date_check CHECK (end_date >= start_date)
);

CREATE UNIQUE INDEX IF NOT EXISTS analytics_cycles_active_unique
  ON analytics_cycles ((status))
  WHERE status = 'active';

CREATE TABLE IF NOT EXISTS analytics_monthly_snapshots (
  id BIGSERIAL PRIMARY KEY,
  cycle_id BIGINT NOT NULL REFERENCES analytics_cycles(id) ON DELETE CASCADE,
  cycle_key CHAR(7) NOT NULL,
  snapshot_json JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS analytics_monthly_snapshots_cycle_unique
  ON analytics_monthly_snapshots (cycle_id);

CREATE TABLE IF NOT EXISTS analytics_export_logs (
  id BIGSERIAL PRIMARY KEY,
  cycle_id BIGINT NULL REFERENCES analytics_cycles(id) ON DELETE SET NULL,
  export_type VARCHAR(16) NOT NULL,
  exported_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  exported_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  filters JSONB NOT NULL DEFAULT '{}'::jsonb,
  row_count INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT analytics_export_logs_type_check CHECK (export_type IN ('csv', 'excel', 'pdf')),
  CONSTRAINT analytics_export_logs_row_count_check CHECK (row_count >= 0)
);

CREATE INDEX IF NOT EXISTS idx_analytics_export_logs_cycle
  ON analytics_export_logs (cycle_id, exported_at DESC);

INSERT INTO analytics_cycles (cycle_key, start_date, end_date, status)
VALUES (
  TO_CHAR((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date, 'YYYY-MM'),
  DATE_TRUNC('month', (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date)::date,
  (DATE_TRUNC('month', (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date)::date + INTERVAL '1 month - 1 day')::date,
  'active'
)
ON CONFLICT (cycle_key) DO NOTHING;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    REVOKE ALL ON TABLE queue_status_events FROM anon;
    REVOKE ALL ON SEQUENCE queue_status_events_id_seq FROM anon;
    REVOKE ALL ON TABLE analytics_cycles FROM anon;
    REVOKE ALL ON TABLE analytics_monthly_snapshots FROM anon;
    REVOKE ALL ON TABLE analytics_export_logs FROM anon;
    REVOKE ALL ON SEQUENCE analytics_cycles_id_seq FROM anon;
    REVOKE ALL ON SEQUENCE analytics_monthly_snapshots_id_seq FROM anon;
    REVOKE ALL ON SEQUENCE analytics_export_logs_id_seq FROM anon;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    REVOKE ALL ON TABLE analytics_cycles FROM authenticated;
    REVOKE ALL ON TABLE analytics_monthly_snapshots FROM authenticated;
    REVOKE ALL ON TABLE analytics_export_logs FROM authenticated;
    REVOKE ALL ON SEQUENCE analytics_cycles_id_seq FROM authenticated;
    REVOKE ALL ON SEQUENCE analytics_monthly_snapshots_id_seq FROM authenticated;
    REVOKE ALL ON SEQUENCE analytics_export_logs_id_seq FROM authenticated;
  END IF;
END
$$;

COMMIT;
