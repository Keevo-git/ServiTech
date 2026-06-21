-- Convert the existing Scan/Scanning service into the normalized catalog.
-- Existing service IDs and administrator-edited catalog records are preserved.
BEGIN;

DO $$
DECLARE
  scanning_id BIGINT;
  paper_group BIGINT;
  letter_id BIGINT;
  long_id BIGINT;
  a4_id BIGINT;
  legacy_price NUMERIC(12, 2);
  legacy_pricing JSONB;
  letter_price NUMERIC(12, 2);
  long_price NUMERIC(12, 2);
  a4_price NUMERIC(12, 2);
  was_archived BOOLEAN := FALSE;
BEGIN
  SELECT id, archived_at IS NOT NULL, price, COALESCE(pricing_json, '{}'::jsonb)
  INTO scanning_id, was_archived, legacy_price, legacy_pricing
  FROM services
  WHERE LOWER(category) = 'printing'
    AND LOWER(name) LIKE '%scan%'
  ORDER BY EXISTS (
    SELECT 1 FROM service_option_groups g WHERE g.service_id = services.id
  ) DESC, active DESC, sort_order ASC, id ASC
  LIMIT 1;

  IF scanning_id IS NULL THEN
    INSERT INTO services (
      category, name, description, price, price_range, pricing_json, active, sort_order
    ) VALUES (
      'printing', 'Scanning', 'Scanning service priced by paper size.',
      NULL, 'For assessment', '{}'::jsonb, TRUE, 4
    ) RETURNING id INTO scanning_id;
    legacy_pricing := '{}'::jsonb;
  ELSE
    UPDATE services
    SET name = CASE
          WHEN LOWER(BTRIM(name)) IN ('scan', 'scanning') THEN name
          ELSE 'Scanning'
        END,
        description = CASE
          WHEN description IS NULL OR BTRIM(description) = ''
          THEN 'Scanning service priced by paper size.'
          ELSE description
        END,
        active = CASE WHEN was_archived THEN TRUE ELSE active END,
        archived_at = NULL,
        sort_order = CASE WHEN was_archived THEN 4 ELSE sort_order END,
        updated_at = NOW()
    WHERE id = scanning_id;
  END IF;

  UPDATE services
  SET active = FALSE,
      archived_at = COALESCE(archived_at, NOW()),
      updated_at = NOW()
  WHERE LOWER(category) = 'printing'
    AND LOWER(name) LIKE '%scan%'
    AND id <> scanning_id;

  letter_price := CASE
    WHEN COALESCE(legacy_pricing->>'letter', '') ~ '^[0-9]+([.][0-9]+)?$' THEN (legacy_pricing->>'letter')::numeric
    WHEN COALESCE(legacy_pricing->>'short', '') ~ '^[0-9]+([.][0-9]+)?$' THEN (legacy_pricing->>'short')::numeric
    ELSE legacy_price
  END;
  long_price := CASE
    WHEN COALESCE(legacy_pricing->>'8_5x13', '') ~ '^[0-9]+([.][0-9]+)?$' THEN (legacy_pricing->>'8_5x13')::numeric
    WHEN COALESCE(legacy_pricing->>'long', '') ~ '^[0-9]+([.][0-9]+)?$' THEN (legacy_pricing->>'long')::numeric
    ELSE legacy_price
  END;
  a4_price := CASE
    WHEN COALESCE(legacy_pricing->>'a4', '') ~ '^[0-9]+([.][0-9]+)?$' THEN (legacy_pricing->>'a4')::numeric
    ELSE legacy_price
  END;

  INSERT INTO service_option_groups (service_id, group_key, name, active, sort_order)
  VALUES (scanning_id, 'paper_size', 'Paper Size', TRUE, 0)
  ON CONFLICT (service_id, group_key) DO UPDATE
    SET name = EXCLUDED.name,
        active = TRUE,
        archived_at = NULL,
        updated_at = NOW()
  RETURNING id INTO paper_group;

  INSERT INTO service_option_values (group_id, value_key, label, active, sort_order)
  VALUES (paper_group, 'letter', 'Letter', TRUE, 0)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET active = CASE WHEN was_archived THEN TRUE ELSE service_option_values.active END,
        archived_at = CASE WHEN was_archived THEN NULL ELSE service_option_values.archived_at END,
        updated_at = NOW()
  RETURNING id INTO letter_id;

  INSERT INTO service_option_values (group_id, value_key, label, active, sort_order)
  VALUES (paper_group, '8_5x13', '8.5x13', TRUE, 1)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET active = CASE WHEN was_archived THEN TRUE ELSE service_option_values.active END,
        archived_at = CASE WHEN was_archived THEN NULL ELSE service_option_values.archived_at END,
        updated_at = NOW()
  RETURNING id INTO long_id;

  INSERT INTO service_option_values (group_id, value_key, label, active, sort_order)
  VALUES (paper_group, 'a4', 'A4', TRUE, 2)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET active = CASE WHEN was_archived THEN TRUE ELSE service_option_values.active END,
        archived_at = CASE WHEN was_archived THEN NULL ELSE service_option_values.archived_at END,
        updated_at = NOW()
  RETURNING id INTO a4_id;

  INSERT INTO service_pricing_rules (
    service_id, rule_key, option_value_ids, label, price, price_type, active, sort_order
  ) VALUES
    (scanning_id, 'letter', jsonb_build_object('paper_size', letter_id), 'Letter', letter_price,
      CASE WHEN letter_price IS NULL THEN 'assessment' ELSE 'fixed' END, TRUE, 0),
    (scanning_id, '8_5x13', jsonb_build_object('paper_size', long_id), '8.5x13', long_price,
      CASE WHEN long_price IS NULL THEN 'assessment' ELSE 'fixed' END, TRUE, 1),
    (scanning_id, 'a4', jsonb_build_object('paper_size', a4_id), 'A4', a4_price,
      CASE WHEN a4_price IS NULL THEN 'assessment' ELSE 'fixed' END, TRUE, 2)
  ON CONFLICT (service_id, rule_key) DO UPDATE
    SET option_value_ids = EXCLUDED.option_value_ids,
        updated_at = NOW();

  UPDATE services
  SET price = NULL,
      price_range = COALESCE((
        SELECT CASE
          WHEN MIN(r.price) IS NULL THEN 'For assessment'
          WHEN MIN(r.price) = MAX(r.price) THEN 'PHP ' || TO_CHAR(MIN(r.price), 'FM999999990.00')
          ELSE 'PHP ' || TO_CHAR(MIN(r.price), 'FM999999990.00') || ' - PHP ' || TO_CHAR(MAX(r.price), 'FM999999990.00')
        END
        FROM service_pricing_rules r
        WHERE r.service_id = scanning_id
          AND r.active = TRUE
          AND r.archived_at IS NULL
          AND r.price_type = 'fixed'
      ), 'For assessment'),
      pricing_json = '{}'::jsonb,
      updated_at = NOW()
  WHERE id = scanning_id;
END $$;

COMMIT;
