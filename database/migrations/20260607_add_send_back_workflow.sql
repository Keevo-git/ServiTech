BEGIN;

ALTER TABLE queues
  ADD COLUMN IF NOT EXISTS customer_edit_required BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS send_back_message TEXT NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS send_back_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS send_back_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_queues_customer_edit_required
  ON queues (customer_edit_required)
  WHERE customer_edit_required = TRUE;

ALTER TABLE queue_status_history
  ADD COLUMN IF NOT EXISTS action_type TEXT NOT NULL DEFAULT 'status_change';

CREATE INDEX IF NOT EXISTS idx_queue_status_history_action_type
  ON queue_status_history (queue_id, action_type, created_at DESC);

COMMIT;
