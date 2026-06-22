BEGIN;

UPDATE services
SET archived_at = NULL
WHERE archived_at IS NOT NULL;

DO $$
BEGIN
  IF to_regclass('public.service_option_groups') IS NOT NULL THEN
    UPDATE service_option_groups
    SET archived_at = NULL
    WHERE archived_at IS NOT NULL;
  END IF;

  IF to_regclass('public.service_option_values') IS NOT NULL THEN
    UPDATE service_option_values
    SET archived_at = NULL
    WHERE archived_at IS NOT NULL;
  END IF;

  IF to_regclass('public.service_pricing_rules') IS NOT NULL THEN
    UPDATE service_pricing_rules
    SET archived_at = NULL
    WHERE archived_at IS NOT NULL;
  END IF;
END $$;

COMMIT;
