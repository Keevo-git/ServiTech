ALTER TABLE queues ADD COLUMN IF NOT EXISTS price NUMERIC(12, 2) NULL;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS paid_amount NUMERIC(12, 2) NOT NULL DEFAULT 0;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS event_key TEXT NULL;

UPDATE queues q
SET price = source.price
FROM (
  SELECT queues.id,
         COALESCE(
           CASE
             WHEN TRIM(COALESCE(queues.details->>'estimated_total', '')) ~ '^[0-9]+([.][0-9]+)?$'
             THEN TRIM(queues.details->>'estimated_total')::NUMERIC
             ELSE NULL
           END,
           (
             SELECT payments.amount
             FROM payments
             WHERE payments.queue_id = queues.id
             ORDER BY payments.id DESC
             LIMIT 1
           )
         ) AS price
  FROM queues
) source
WHERE q.id = source.id
  AND q.price IS NULL
  AND source.price IS NOT NULL;

UPDATE queues
SET paid_amount = CASE
  WHEN UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('DONE', 'COMPLETED') THEN COALESCE(price, 0)
  WHEN UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('CANCELLED', 'CANCELED') THEN 0
  WHEN price IS NOT NULL THEN LEAST(GREATEST(COALESCE(paid_amount, 0), 0), price)
  ELSE 0
END
WHERE UPPER(TRIM(COALESCE(status, 'PENDING'))) IN ('DONE', 'COMPLETED', 'CANCELLED', 'CANCELED')
   OR paid_amount IS NULL
   OR paid_amount < 0
   OR (price IS NOT NULL AND paid_amount > price);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queues_price_check') THEN
    ALTER TABLE queues ADD CONSTRAINT queues_price_check
      CHECK (price IS NULL OR price >= 0);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'queues_paid_amount_check') THEN
    ALTER TABLE queues ADD CONSTRAINT queues_paid_amount_check
      CHECK (paid_amount >= 0 AND (price IS NULL OR paid_amount <= price));
  END IF;
END $$;

WITH duplicate_notifications AS (
  SELECT id,
         ROW_NUMBER() OVER (
           PARTITION BY user_id, LOWER(TRIM(COALESCE(type, 'queue'))), COALESCE(reference_id, 0), COALESCE(NULLIF(TRIM(event_key), ''), MD5(TRIM(COALESCE(message, ''))))
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

DROP INDEX IF EXISTS notifications_active_event_unique;
CREATE UNIQUE INDEX IF NOT EXISTS notifications_active_event_unique
  ON notifications (user_id, LOWER(TRIM(COALESCE(type, 'queue'))), COALESCE(reference_id, 0), COALESCE(NULLIF(TRIM(event_key), ''), MD5(TRIM(COALESCE(message, '')))))
  WHERE deleted_at IS NULL;
