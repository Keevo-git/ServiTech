ALTER TABLE payments ALTER COLUMN reference_number TYPE TEXT;

UPDATE payments
SET status = CASE
  WHEN UPPER(TRIM(COALESCE(status, ''))) IN ('APPROVED', 'VERIFIED') THEN 'APPROVED'
  WHEN UPPER(TRIM(COALESCE(status, ''))) IN ('PAID', 'COMPLETED', 'SUCCESS') THEN 'PAID'
  WHEN UPPER(TRIM(COALESCE(status, ''))) IN ('CANCELLED', 'CANCELED', 'REJECTED') THEN 'CANCELLED'
  ELSE 'PENDING'
END;

ALTER TABLE payments ALTER COLUMN status SET DEFAULT 'PENDING';
ALTER TABLE payments ALTER COLUMN status SET NOT NULL;

DO $$
BEGIN
  ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check;
  ALTER TABLE payments ADD CONSTRAINT payments_status_check
    CHECK (UPPER(TRIM(status)) IN ('PENDING', 'APPROVED', 'PAID', 'CANCELLED')) NOT VALID;
END $$;

CREATE INDEX IF NOT EXISTS idx_payments_gcash_review
  ON payments (status, queue_id)
  WHERE LOWER(TRIM(payment_method)) = 'gcash';
