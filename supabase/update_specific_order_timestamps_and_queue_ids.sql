-- ServiTech targeted order timestamp / Queue ID correction workflow
--
-- Audited schema (repository migrations through 2026-06-23):
--   * public.queues is the canonical table for printing, repair, installation,
--     walk-in, and legacy online-print orders.
--   * public.payments.queue_id, public.queue_status_history.queue_id, and
--     public.uploads.queue_id reference the stable numeric public.queues.id.
--   * public.notifications.reference_id stores that same numeric queue ID.
--   * There are no separate receipt, invoice, order, or analytics fact tables.
--     Payment/receipt screens join payments to queues; dashboards and date filters
--     read queues.created_at. Keeping queues.id unchanged preserves every join.
--
-- Only these canonical submitted-identity fields are changed on an approved row:
--   queues.queue_code       human-facing Queue ID / Order ID
--   queues.created_at       submitted timestamp
--   queues.queue_cycle_date submitted date in Asia/Manila
--   queues.daily_sequence   numeric suffix represented by queue_code
--
-- Existing payment/history/upload timestamps are independent event timestamps and
-- are deliberately NOT rewritten. Customer account timestamps are never changed.
-- Notification message snapshots that contain the old Queue ID are rewritten only
-- when notifications.reference_id matches the selected queues.id. A compensating
-- queue_status_history row records each applied correction without changing status.
--
-- IMPORTANT SQL Editor workflow:
--   1. Paste/select this ENTIRE file and run it. Do not run only the cursor block.
--   2. Initially leave apply_changes = FALSE and the final command as ROLLBACK.
--   3. Fill only the manual staging INSERT below. Run and inspect the preview.
--   4. Do not proceed unless every requested row says SAFE.
--   5. To apply later, set apply_changes = TRUE and change the final ROLLBACK to
--      COMMIT. The conflict guard aborts before updates if any row is not SAFE.
--
-- This file contains no customer/order data and performs a dry run by default.

BEGIN;

SET LOCAL TIME ZONE 'Asia/Manila';
SET LOCAL lock_timeout = '10s';
SET LOCAL statement_timeout = '2min';

-- ============================================================================
-- 0. SAFETY CONFIGURATION -- LEAVE FALSE FOR THE REQUESTED DRY RUN
-- ============================================================================

CREATE TEMP TABLE _servitech_order_correction_config (
  apply_changes BOOLEAN NOT NULL
) ON COMMIT DROP;

INSERT INTO _servitech_order_correction_config (apply_changes)
VALUES (FALSE); -- Change to TRUE only after reviewing a clean dry run.

-- ============================================================================
-- 1. MANUAL STAGING SECTION
-- ============================================================================
-- Required values:
--   old_queue_id             Exact current queues.queue_code.
--   corrected_submitted_at   Correct timestamp with an explicit offset, e.g. +08.
-- Optional values:
--   new_queue_id             NULL/blank = generate by replacing only YYYYMMDD.
--                            If supplied, prefix/date/sequence are validated.
--   notes                    Human-readable reason for the audit history row.
--
-- Add one INSERT row per order. The commented row is FORMAT ONLY, not real data.

CREATE TEMP TABLE _servitech_order_corrections (
  manual_row_no             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  old_queue_id              TEXT NOT NULL,
  corrected_submitted_at    TIMESTAMPTZ NOT NULL,
  requested_new_queue_id    TEXT NULL,
  notes                     TEXT NULL
) ON COMMIT DROP;

-- EXAMPLE FORMAT ONLY -- copy this statement and replace every placeholder:
-- INSERT INTO _servitech_order_corrections
--   (old_queue_id, corrected_submitted_at, requested_new_queue_id, notes)
-- VALUES
--   ('PYYYYMMDD-0001', TIMESTAMPTZ 'YYYY-MM-DD HH24:MI:SS+08', NULL, 'Reason for correction');

-- >>> ADD YOUR MANUAL INSERT STATEMENT(S) DIRECTLY BELOW THIS LINE. <<<


-- >>> END OF MANUAL INPUT. <<<

-- Serialize this maintenance workflow. A real apply also locks queues before its
-- plan is built, preventing a new queue from taking a checked target code.
SELECT pg_advisory_xact_lock(
  hashtextextended('servitech:targeted-order-timestamp-correction', 0)
);

DO $schema_guard$
DECLARE
  missing_columns TEXT;
BEGIN
  IF TO_REGCLASS('public.queues') IS NULL
     OR TO_REGCLASS('public.users') IS NULL
     OR TO_REGCLASS('public.payments') IS NULL
     OR TO_REGCLASS('public.notifications') IS NULL
     OR TO_REGCLASS('public.queue_status_history') IS NULL
     OR TO_REGCLASS('public.uploads') IS NULL THEN
    RAISE EXCEPTION 'Required ServiTech tables are missing. Apply current migrations first.';
  END IF;

  SELECT STRING_AGG(required.column_name, ', ' ORDER BY required.column_name)
  INTO missing_columns
  FROM (
    VALUES
      ('id'), ('user_id'), ('queue_code'), ('category'), ('status'), ('details'),
      ('price'), ('paid_amount'), ('lifecycle_stage'), ('queue_cycle_date'),
      ('daily_sequence'), ('created_at'), ('updated_at')
  ) AS required(column_name)
  WHERE NOT EXISTS (
    SELECT 1
    FROM information_schema.columns c
    WHERE c.table_schema = 'public'
      AND c.table_name = 'queues'
      AND c.column_name = required.column_name
  );

  IF missing_columns IS NOT NULL THEN
    RAISE EXCEPTION 'public.queues is missing required columns: %', missing_columns;
  END IF;

  IF (SELECT apply_changes FROM _servitech_order_correction_config) THEN
    LOCK TABLE public.queues IN SHARE ROW EXCLUSIVE MODE;
  END IF;
END;
$schema_guard$;

-- Normalize input, resolve the canonical queue row, generate/parse the proposed
-- ID, count related rows, and assign one deterministic safety status per entry.
CREATE TEMP TABLE _servitech_order_correction_plan ON COMMIT DROP AS
WITH normalized AS (
  SELECT
    s.manual_row_no,
    UPPER(TRIM(s.old_queue_id)) AS old_queue_id,
    s.corrected_submitted_at,
    NULLIF(UPPER(TRIM(COALESCE(s.requested_new_queue_id, ''))), '') AS requested_new_queue_id,
    NULLIF(TRIM(COALESCE(s.notes, '')), '') AS notes
  FROM _servitech_order_corrections s
),
matched AS (
  SELECT
    n.*,
    q.id AS queue_numeric_id,
    q.user_id,
    q.queue_code AS stored_old_queue_id,
    q.category,
    q.status AS order_status,
    q.lifecycle_stage,
    q.details,
    q.price,
    q.paid_amount,
    q.created_at AS old_submitted_at,
    q.updated_at AS old_updated_at,
    q.queue_cycle_date AS old_queue_cycle_date,
    q.daily_sequence AS old_daily_sequence,
    REGEXP_MATCH(n.old_queue_id, '^([A-Z]+)([0-9]{8})-([0-9]+)$') AS old_parts
  FROM normalized n
  LEFT JOIN public.queues q
    ON UPPER(TRIM(q.queue_code)) = n.old_queue_id
),
derived AS (
  SELECT
    m.*,
    COALESCE(
      m.requested_new_queue_id,
      CASE
        WHEN m.old_parts IS NOT NULL THEN
          m.old_parts[1]
          || TO_CHAR(m.corrected_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYYMMDD')
          || '-'
          || m.old_parts[3]
        ELSE NULL
      END
    ) AS proposed_new_queue_id
  FROM matched m
),
parsed AS (
  SELECT
    d.*,
    REGEXP_MATCH(d.proposed_new_queue_id, '^([A-Z]+)([0-9]{8})-([0-9]+)$') AS new_parts
  FROM derived d
),
assessed AS (
  SELECT
    p.*,
    u.fullname AS customer_name,
    u.email AS customer_email,
    COALESCE(
      NULLIF(TRIM(p.details ->> 'service_label'), ''),
      NULLIF(TRIM(p.details ->> 'service_type'), ''),
      INITCAP(REPLACE(COALESCE(p.category, ''), '_', ' ')),
      'Unknown'
    ) AS service_type,
    latest_payment.amount AS payment_amount,
    latest_payment.created_at AS payment_created_at,
    latest_payment.payment_method,
    (SELECT COUNT(*) FROM public.payments pay WHERE pay.queue_id = p.queue_numeric_id) AS payment_count,
    (SELECT COUNT(*) FROM public.notifications n WHERE n.reference_id = p.queue_numeric_id) AS notification_count,
    (
      SELECT COUNT(*)
      FROM public.notifications n
      WHERE n.reference_id = p.queue_numeric_id
        AND POSITION(p.old_queue_id IN UPPER(COALESCE(n.message, ''))) > 0
    ) AS linked_notification_message_hits,
    (
      SELECT COUNT(*)
      FROM public.notifications n
      WHERE POSITION(p.old_queue_id IN UPPER(COALESCE(n.message, ''))) > 0
        AND n.reference_id IS DISTINCT FROM p.queue_numeric_id
    ) AS unlinked_notification_message_hits,
    (
      SELECT COUNT(*)
      FROM public.notifications n
      WHERE n.reference_id = p.queue_numeric_id
        AND POSITION(p.old_queue_id IN UPPER(COALESCE(n.event_key, ''))) > 0
    ) AS notification_event_key_hits,
    (SELECT COUNT(*) FROM public.queue_status_history h WHERE h.queue_id = p.queue_numeric_id) AS history_count,
    (
      SELECT COUNT(*)
      FROM public.queue_status_history h
      WHERE h.queue_id = p.queue_numeric_id
        AND POSITION(p.old_queue_id IN UPPER(COALESCE(h.notes, ''))) > 0
    ) AS history_note_hits,
    (SELECT COUNT(*) FROM public.uploads up WHERE up.queue_id = p.queue_numeric_id) AS upload_count,
    CASE
      WHEN p.queue_numeric_id IS NOT NULL
       AND POSITION(p.old_queue_id IN UPPER(COALESCE(p.details::TEXT, ''))) > 0
      THEN TRUE ELSE FALSE
    END AS details_contains_old_id,
    (
      SELECT COUNT(*)
      FROM normalized duplicate_old
      WHERE duplicate_old.old_queue_id = p.old_queue_id
    ) AS duplicate_old_input_count,
    (
      SELECT COUNT(*)
      FROM parsed duplicate_new
      WHERE duplicate_new.proposed_new_queue_id = p.proposed_new_queue_id
    ) AS duplicate_new_input_count,
    (
      SELECT target.id
      FROM public.queues target
      WHERE UPPER(TRIM(target.queue_code)) = p.proposed_new_queue_id
        AND target.id IS DISTINCT FROM p.queue_numeric_id
      LIMIT 1
    ) AS conflicting_queue_numeric_id
  FROM parsed p
  LEFT JOIN public.users u ON u.id = p.user_id
  LEFT JOIN LATERAL (
    SELECT pay.amount, pay.created_at, pay.payment_method
    FROM public.payments pay
    WHERE pay.queue_id = p.queue_numeric_id
    ORDER BY pay.id DESC
    LIMIT 1
  ) latest_payment ON TRUE
),
classified AS (
  SELECT
    a.*,
    CASE
      WHEN a.queue_numeric_id IS NULL THEN 'missing'
      WHEN a.duplicate_old_input_count > 1
        OR a.duplicate_new_input_count > 1
        OR a.conflicting_queue_numeric_id IS NOT NULL THEN 'conflict'
      WHEN a.old_parts IS NULL
        OR a.new_parts IS NULL
        OR LENGTH(a.new_parts[3]) > 10
        OR a.new_parts[3]::NUMERIC > 2147483647
        OR a.new_parts[3]::NUMERIC < 0
        OR a.new_parts[1] <> a.old_parts[1]
        OR a.new_parts[2] <> TO_CHAR(a.corrected_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYYMMDD')
        OR (a.proposed_new_queue_id = a.old_queue_id AND a.corrected_submitted_at = a.old_submitted_at)
        OR a.details_contains_old_id
        OR a.unlinked_notification_message_hits > 0
        OR a.notification_event_key_hits > 0
        OR a.history_note_hits > 0 THEN 'needs manual review'
      ELSE 'safe'
    END AS update_status,
    CONCAT_WS('; ',
      CASE WHEN a.queue_numeric_id IS NULL THEN 'old Queue ID was not found' END,
      CASE WHEN a.duplicate_old_input_count > 1 THEN 'old Queue ID appears more than once in manual input' END,
      CASE WHEN a.duplicate_new_input_count > 1 THEN 'multiple input rows produce the same new Queue ID' END,
      CASE WHEN a.conflicting_queue_numeric_id IS NOT NULL THEN 'new Queue ID already belongs to another queues row' END,
      CASE WHEN a.old_parts IS NULL THEN 'old Queue ID does not match PREFIXYYYYMMDD-SEQUENCE' END,
      CASE WHEN a.new_parts IS NULL THEN 'new Queue ID does not match PREFIXYYYYMMDD-SEQUENCE' END,
      CASE WHEN a.new_parts IS NOT NULL AND LENGTH(a.new_parts[3]) > 10 THEN 'new sequence is too long' END,
      CASE WHEN a.new_parts IS NOT NULL AND a.new_parts[3]::NUMERIC > 2147483647 THEN 'new sequence exceeds integer range' END,
      CASE WHEN a.new_parts IS NOT NULL AND a.new_parts[3]::NUMERIC < 0 THEN 'new sequence cannot be negative' END,
      CASE WHEN a.old_parts IS NOT NULL AND a.new_parts IS NOT NULL AND a.new_parts[1] <> a.old_parts[1] THEN 'prefix changes are not automatic' END,
      CASE
        WHEN a.new_parts IS NOT NULL
         AND a.new_parts[2] <> TO_CHAR(a.corrected_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYYMMDD')
        THEN 'new Queue ID date does not match corrected Philippine date'
      END,
      CASE
        WHEN a.proposed_new_queue_id = a.old_queue_id
         AND a.corrected_submitted_at = a.old_submitted_at
        THEN 'requested correction is a no-op'
      END,
      CASE WHEN a.details_contains_old_id THEN 'queues.details contains an old-ID text snapshot' END,
      CASE WHEN a.unlinked_notification_message_hits > 0 THEN 'old-ID notification text exists with a different/missing reference_id' END,
      CASE WHEN a.notification_event_key_hits > 0 THEN 'notification event_key contains the old ID' END,
      CASE WHEN a.history_note_hits > 0 THEN 'history notes contain the old ID' END
    ) AS status_reason
  FROM assessed a
)
SELECT
  c.*,
  CASE
    WHEN c.new_parts IS NOT NULL
     AND LENGTH(c.new_parts[3]) <= 10
     AND c.new_parts[3]::NUMERIC BETWEEN 0 AND 2147483647
    THEN c.new_parts[3]::INTEGER
    ELSE NULL
  END AS proposed_daily_sequence,
  (c.corrected_submitted_at AT TIME ZONE 'Asia/Manila')::DATE AS proposed_queue_cycle_date
FROM classified c;

-- ============================================================================
-- 2. DRY-RUN PREVIEW
-- ============================================================================
-- Every manually listed row appears once. Only SAFE rows are eligible to update.

SELECT
  p.manual_row_no,
  p.old_queue_id,
  p.proposed_new_queue_id AS new_queue_id,
  TO_CHAR(p.old_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS old_submitted_at,
  TO_CHAR(p.corrected_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS new_submitted_at,
  p.customer_name,
  p.customer_email,
  p.service_type,
  p.payment_amount,
  TO_CHAR(p.payment_created_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS payment_created_at_unchanged,
  FORMAT(
    'queues(1 identity/timestamp row); notifications(%s linked, %s text rewrites); payments(%s numeric links unchanged); queue_status_history(%s numeric links unchanged, +1 audit on apply); uploads(%s numeric links unchanged)',
    p.notification_count,
    p.linked_notification_message_hits,
    p.payment_count,
    p.history_count,
    p.upload_count
  ) AS affected_related_tables,
  p.update_status,
  NULLIF(p.status_reason, '') AS status_reason,
  p.notes
FROM _servitech_order_correction_plan p
ORDER BY p.manual_row_no;

-- Summary: requested_rows must equal safe_rows before an apply is allowed.
SELECT
  COUNT(*) AS requested_rows,
  COUNT(*) FILTER (WHERE update_status = 'safe') AS safe_rows,
  COUNT(*) FILTER (WHERE update_status = 'conflict') AS conflict_rows,
  COUNT(*) FILTER (WHERE update_status = 'missing') AS missing_rows,
  COUNT(*) FILTER (WHERE update_status = 'needs manual review') AS manual_review_rows,
  (SELECT apply_changes FROM _servitech_order_correction_config) AS apply_changes
FROM _servitech_order_correction_plan;

-- Runtime schema inventory for ID/timestamp columns requested in the audit.
SELECT
  c.table_schema,
  c.table_name,
  c.column_name,
  c.data_type
FROM information_schema.columns c
WHERE c.table_schema = 'public'
  AND (
    c.column_name IN (
      'queue_id', 'queue_code', 'order_id', 'reference_id', 'submitted_at',
      'date_submitted', 'created_at', 'updated_at', 'payment_created_at'
    )
    OR c.column_name LIKE '%receipt%'
    OR c.column_name LIKE '%audit%'
  )
ORDER BY c.table_name, c.ordinal_position;

-- ============================================================================
-- 3. CONFLICT GUARD
-- ============================================================================
-- In dry-run mode this reports only a notice. In apply mode it aborts the entire
-- transaction before any update unless at least one row exists and all are SAFE.

DO $apply_guard$
DECLARE
  wants_apply BOOLEAN := (SELECT apply_changes FROM _servitech_order_correction_config);
  requested_count INTEGER := (SELECT COUNT(*) FROM _servitech_order_correction_plan);
  unsafe_count INTEGER := (
    SELECT COUNT(*) FROM _servitech_order_correction_plan WHERE update_status <> 'safe'
  );
BEGIN
  IF NOT wants_apply THEN
    RAISE NOTICE 'DRY RUN ONLY: no rows will be updated and the transaction will be rolled back.';
    RETURN;
  END IF;

  IF requested_count = 0 THEN
    RAISE EXCEPTION 'Apply blocked: the manual staging section is empty.';
  END IF;

  IF unsafe_count > 0 THEN
    RAISE EXCEPTION 'Apply blocked: % row(s) are conflict, missing, or need manual review.', unsafe_count;
  END IF;
END;
$apply_guard$;

-- ============================================================================
-- 4. GATED UPDATE SECTION
-- ============================================================================
-- With apply_changes = FALSE these statements match zero rows.

UPDATE public.queues q
SET
  queue_code = p.proposed_new_queue_id,
  created_at = p.corrected_submitted_at,
  queue_cycle_date = p.proposed_queue_cycle_date,
  daily_sequence = p.proposed_daily_sequence
FROM _servitech_order_correction_plan p
CROSS JOIN _servitech_order_correction_config cfg
WHERE cfg.apply_changes
  AND p.update_status = 'safe'
  AND q.id = p.queue_numeric_id;

-- Rewrite only linked notification text snapshots. Numeric reference_id remains
-- unchanged, so notification ownership/deduplication relationships are preserved.
UPDATE public.notifications n
SET message = REGEXP_REPLACE(
  n.message,
  p.old_queue_id,
  p.proposed_new_queue_id,
  'gi'
)
FROM _servitech_order_correction_plan p
CROSS JOIN _servitech_order_correction_config cfg
WHERE cfg.apply_changes
  AND p.update_status = 'safe'
  AND n.reference_id = p.queue_numeric_id
  AND POSITION(p.old_queue_id IN UPPER(COALESCE(n.message, ''))) > 0;

-- Append a compensating audit event. Existing history rows/timestamps are retained.
INSERT INTO public.queue_status_history (
  queue_id,
  category,
  old_status,
  new_status,
  admin_id,
  admin_name,
  notes,
  action_type,
  created_at
)
SELECT
  p.queue_numeric_id,
  p.category,
  p.order_status,
  p.order_status,
  NULL,
  'SQL maintenance',
  CONCAT_WS(
    ' | ',
    'Submitted timestamp / Queue ID correction',
    p.old_queue_id || ' -> ' || p.proposed_new_queue_id,
    TO_CHAR(p.old_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08'
      || ' -> '
      || TO_CHAR(p.corrected_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08',
    p.notes
  ),
  'manual_timestamp_correction',
  CURRENT_TIMESTAMP
FROM _servitech_order_correction_plan p
CROSS JOIN _servitech_order_correction_config cfg
WHERE cfg.apply_changes
  AND p.update_status = 'safe';

-- ============================================================================
-- 5. VERIFICATION
-- ============================================================================

SELECT
  p.manual_row_no,
  p.update_status AS preflight_status,
  p.old_queue_id,
  p.proposed_new_queue_id AS expected_new_queue_id,
  q.queue_code AS actual_queue_id,
  TO_CHAR(p.corrected_submitted_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS expected_submitted_at,
  TO_CHAR(q.created_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS actual_submitted_at,
  q.queue_cycle_date AS actual_queue_cycle_date,
  q.daily_sequence AS actual_daily_sequence,
  q.status AS unchanged_order_status,
  latest_payment.status AS unchanged_payment_status,
  u.fullname AS unchanged_customer_name,
  u.email AS unchanged_customer_email,
  CASE
    WHEN cfg.apply_changes
     AND q.queue_code = p.proposed_new_queue_id
     AND q.created_at = p.corrected_submitted_at
     AND q.queue_cycle_date = p.proposed_queue_cycle_date
     AND q.daily_sequence = p.proposed_daily_sequence
    THEN 'updated and verified inside transaction'
    WHEN NOT cfg.apply_changes THEN 'dry run: database intentionally unchanged'
    ELSE 'verification failed'
  END AS verification_result
FROM _servitech_order_correction_plan p
CROSS JOIN _servitech_order_correction_config cfg
LEFT JOIN public.queues q ON q.id = p.queue_numeric_id
LEFT JOIN public.users u ON u.id = q.user_id
LEFT JOIN LATERAL (
  SELECT pay.status
  FROM public.payments pay
  WHERE pay.queue_id = q.id
  ORDER BY pay.id DESC
  LIMIT 1
) latest_payment ON TRUE
ORDER BY p.manual_row_no;

-- Must return zero rows after a real apply.
SELECT p.manual_row_no, p.proposed_new_queue_id AS duplicate_queue_id
FROM _servitech_order_correction_plan p
JOIN public.queues q ON UPPER(q.queue_code) = p.proposed_new_queue_id
WHERE q.id <> p.queue_numeric_id;

-- Save this result before a real COMMIT. It contains exactly the values needed to
-- make a non-destructive compensating rollback with this same workflow.
SELECT
  p.proposed_new_queue_id AS rollback_old_queue_id,
  p.old_submitted_at AS rollback_corrected_submitted_at,
  p.old_queue_id AS rollback_requested_new_queue_id,
  'Rollback: ' || COALESCE(p.notes, 'timestamp / Queue ID correction') AS rollback_notes
FROM _servitech_order_correction_plan p
WHERE p.update_status = 'safe'
ORDER BY p.manual_row_no;

-- ============================================================================
-- 6. ROLLBACK INSTRUCTIONS
-- ============================================================================
-- Before COMMIT: execute ROLLBACK; (the default final command already does this).
-- After a COMMIT: copy the rollback result above into the manual staging section,
-- run a new dry run, then apply it normally. This preserves history by appending a
-- compensating audit row; it does not delete any records.

-- DRY-RUN DEFAULT: this guarantees the file cannot persist changes as delivered.
-- For a later approved apply, change apply_changes to TRUE above, review again,
-- and replace this final ROLLBACK with COMMIT.
ROLLBACK;
