ALTER TABLE queues ADD COLUMN IF NOT EXISTS price NUMERIC(12, 2) NULL;
ALTER TABLE queues ADD COLUMN IF NOT EXISTS paid_amount NUMERIC(12, 2) NOT NULL DEFAULT 0;

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
