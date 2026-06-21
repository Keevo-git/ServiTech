-- Keep one canonical Photocopy/Xerox service without deleting historical rows.
BEGIN;

DO $$
DECLARE
  photocopy_id BIGINT;
  had_active_service BOOLEAN := FALSE;
BEGIN
  SELECT COALESCE(BOOL_OR(active AND archived_at IS NULL), FALSE)
  INTO had_active_service
  FROM services
  WHERE LOWER(category) = 'printing'
    AND (LOWER(BTRIM(name)) = 'xerox' OR LOWER(name) LIKE '%photocopy%');

  SELECT id INTO photocopy_id
  FROM services
  WHERE LOWER(category) = 'printing'
    AND (LOWER(BTRIM(name)) = 'xerox' OR LOWER(name) LIKE '%photocopy%')
  ORDER BY EXISTS (
    SELECT 1 FROM service_option_groups g WHERE g.service_id = services.id
  ) DESC, active DESC, archived_at NULLS FIRST, sort_order ASC, id ASC
  LIMIT 1;

  IF photocopy_id IS NOT NULL THEN
    UPDATE services
    SET name = 'Photocopy',
        active = CASE WHEN had_active_service THEN TRUE ELSE active END,
        archived_at = NULL,
        updated_at = NOW()
    WHERE id = photocopy_id;

    UPDATE services
    SET active = FALSE,
        archived_at = COALESCE(archived_at, NOW()),
        updated_at = NOW()
    WHERE LOWER(category) = 'printing'
      AND (LOWER(BTRIM(name)) = 'xerox' OR LOWER(name) LIKE '%photocopy%')
      AND id <> photocopy_id;
  END IF;
END $$;

COMMIT;
