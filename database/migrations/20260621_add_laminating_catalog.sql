-- Restore or create Laminating without overwriting administrator-edited prices.
-- Non-archived option availability is preserved. Safe after the unified migration.
DO $$
DECLARE
  laminating_id BIGINT;
  lamination_group BIGINT;
  thin_id BIGINT;
  thick_id BIGINT;
  was_archived BOOLEAN := FALSE;
BEGIN
  SELECT id, (archived_at IS NOT NULL) INTO laminating_id, was_archived
  FROM services
  WHERE LOWER(category) = 'printing' AND LOWER(name) LIKE '%laminat%'
  ORDER BY EXISTS (
    SELECT 1 FROM service_option_groups g WHERE g.service_id = services.id
  ) DESC, active DESC, sort_order ASC, id ASC
  LIMIT 1;

  IF laminating_id IS NULL THEN
    INSERT INTO services (
      category, name, description, price, price_range, pricing_json, active, sort_order
    ) VALUES (
      'printing', 'Laminating', 'Laminating service priced by type.',
      20, 'PHP 20.00 - PHP 30.00', '{}'::jsonb, TRUE, 3
    ) RETURNING id INTO laminating_id;
  ELSE
    UPDATE services
    SET name = CASE
          WHEN LOWER(BTRIM(name)) IN ('laminating', 'lamination') THEN name
          ELSE 'Laminating'
        END,
        description = CASE
          WHEN description IS NULL OR BTRIM(description) = ''
            OR LOWER(description) IN (
              'lamination priced by lamination type.',
              'choose thin or thick lamination.',
              'laminating service with thin and thick options.'
            )
          THEN 'Laminating service priced by type.'
          ELSE description
        END,
        active = CASE WHEN was_archived THEN TRUE ELSE active END,
        archived_at = NULL,
        sort_order = 3,
        updated_at = NOW()
    WHERE id = laminating_id;
  END IF;

  UPDATE services
  SET active = FALSE,
      archived_at = COALESCE(archived_at, NOW()),
      updated_at = NOW()
  WHERE LOWER(category) = 'printing'
    AND LOWER(name) LIKE '%laminat%'
    AND id <> laminating_id;

  INSERT INTO service_option_groups (service_id, group_key, name, active, sort_order)
  VALUES (laminating_id, 'lamination_type', 'Type', TRUE, 0)
  ON CONFLICT (service_id, group_key) DO UPDATE
    SET name = EXCLUDED.name, active = TRUE, archived_at = NULL,
        sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO lamination_group;

  INSERT INTO service_option_values (group_id, value_key, label, active, sort_order)
  VALUES (lamination_group, 'thin', 'Thin', TRUE, 1)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET active = CASE WHEN was_archived THEN TRUE ELSE service_option_values.active END,
        archived_at = CASE WHEN was_archived THEN NULL ELSE service_option_values.archived_at END,
        sort_order = CASE WHEN was_archived THEN EXCLUDED.sort_order ELSE service_option_values.sort_order END,
        updated_at = NOW()
  RETURNING id INTO thin_id;

  INSERT INTO service_option_values (group_id, value_key, label, active, sort_order)
  VALUES (lamination_group, 'thick', 'Thick', TRUE, 0)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET active = CASE WHEN was_archived THEN TRUE ELSE service_option_values.active END,
        archived_at = CASE WHEN was_archived THEN NULL ELSE service_option_values.archived_at END,
        sort_order = CASE WHEN was_archived THEN EXCLUDED.sort_order ELSE service_option_values.sort_order END,
        updated_at = NOW()
  RETURNING id INTO thick_id;

  INSERT INTO service_pricing_rules (
    service_id, rule_key, option_value_ids, label, price, price_type, active, sort_order
  ) VALUES (
    laminating_id, 'thin', jsonb_build_object('lamination_type', thin_id),
    'Thin', 20, 'fixed', TRUE, 1
  )
  ON CONFLICT (service_id, rule_key) DO UPDATE
    SET option_value_ids = EXCLUDED.option_value_ids,
        active = CASE WHEN was_archived THEN TRUE ELSE service_pricing_rules.active END,
        archived_at = CASE WHEN was_archived THEN NULL ELSE service_pricing_rules.archived_at END,
        sort_order = CASE WHEN was_archived THEN EXCLUDED.sort_order ELSE service_pricing_rules.sort_order END,
        updated_at = NOW();

  INSERT INTO service_pricing_rules (
    service_id, rule_key, option_value_ids, label, price, price_type, active, sort_order
  ) VALUES (
    laminating_id, 'thick', jsonb_build_object('lamination_type', thick_id),
    'Thick', 30, 'fixed', TRUE, 0
  )
  ON CONFLICT (service_id, rule_key) DO UPDATE
    SET option_value_ids = EXCLUDED.option_value_ids,
        active = CASE WHEN was_archived THEN TRUE ELSE service_pricing_rules.active END,
        archived_at = CASE WHEN was_archived THEN NULL ELSE service_pricing_rules.archived_at END,
        sort_order = CASE WHEN was_archived THEN EXCLUDED.sort_order ELSE service_pricing_rules.sort_order END,
        updated_at = NOW();
END $$;
