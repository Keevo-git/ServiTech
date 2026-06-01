ALTER TABLE queues ADD COLUMN IF NOT EXISTS completed_at TIMESTAMPTZ NULL;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS lifecycle_stage VARCHAR(16);
ALTER TABLE queues ADD COLUMN IF NOT EXISTS queue_cycle_date DATE;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS daily_sequence INTEGER;

UPDATE queues
SET lifecycle_stage = CASE
  WHEN UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED') THEN 'ORDER'
  ELSE 'QUEUE'
END
WHERE lifecycle_stage IS NULL
   OR UPPER(TRIM(lifecycle_stage)) NOT IN ('QUEUE', 'ORDER')
   OR (
     UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED')
     AND UPPER(TRIM(lifecycle_stage)) <> 'ORDER'
   )
   OR (
     UPPER(TRIM(COALESCE(status, 'PENDING'))) NOT IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED')
     AND UPPER(TRIM(lifecycle_stage)) <> 'QUEUE'
   );

UPDATE queues
SET queue_cycle_date = COALESCE(
  (created_at AT TIME ZONE 'Asia/Manila')::date,
  (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
)
WHERE queue_cycle_date IS NULL;

UPDATE queues
SET daily_sequence = COALESCE(
  NULLIF(SUBSTRING(queue_code FROM '([0-9]+)$'), '')::INTEGER,
  0
)
WHERE daily_sequence IS NULL;

ALTER TABLE queues ALTER COLUMN lifecycle_stage SET DEFAULT 'QUEUE';
ALTER TABLE queues ALTER COLUMN lifecycle_stage SET NOT NULL;
ALTER TABLE queues ALTER COLUMN queue_cycle_date SET DEFAULT ((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date);
ALTER TABLE queues ALTER COLUMN queue_cycle_date SET NOT NULL;
ALTER TABLE queues ALTER COLUMN daily_sequence SET DEFAULT 0;
ALTER TABLE queues ALTER COLUMN daily_sequence SET NOT NULL;

CREATE INDEX IF NOT EXISTS idx_queues_lifecycle_stage ON queues(lifecycle_stage);
CREATE INDEX IF NOT EXISTS idx_queues_cycle_date_code ON queues(queue_cycle_date, queue_code);
