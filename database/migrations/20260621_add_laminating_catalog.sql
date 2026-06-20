-- Canonicalize Laminating without overwriting prices or availability already
-- configured by an administrator. Safe to run after the unified catalog migration.
DO $$
DECLARE
  laminating_id BIGINT;
  lamination_group BIGINT;
  thin_id BIGINT;
  thick_id BIGINT;
BEGIN
  SELECT id INTO laminating_id
  FROM services
  WHERE category = 'printing' AND LOWER(name) LIKE '%laminat%'
  ORDER BY active DESC, sort_order ASC, id ASC
  LIMIT 1;

  IF laminating_id IS NULL THEN
    INSERT INTO services (
      category, name, description, price, price_range, pricing_json, active, sort_order
    ) VALUES (
      'printing', 'Laminating', 'Laminating priced by lamination type.',
      20, 'PHP 20.00 - PHP 30.00', '{}'::jsonb, TRUE, 3
    ) RETURNING id INTO laminating_id;
  ELSE
    UPDATE services
    SET name = 'Laminating',
        description = CASE
          WHEN description IS NULL OR BTRIM(description) = ''
            OR LOWER(description) IN (
              'lamination priced by lamination type.',
              'choose thin or thick lamination.'
            )
          THEN 'Laminating priced by lamination type.'
          ELSE description
        END,
        updated_at = NOW()
    WHERE id = laminating_id;
  END IF;

  INSERT INTO service_option_groups (service_id, group_key, name, active, sort_order)
  VALUES (laminating_id, 'lamination_type', 'Lamination Type', TRUE, 0)
  ON CONFLICT (service_id, group_key) DO UPDATE
    SET name = EXCLUDED.name, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO lamination_group;

  INSERT INTO service_option_values (group_id, value_key, label, active, sort_order)
  VALUES (lamination_group, 'thin', 'Thin / Manipis', TRUE, 0)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET label = EXCLUDED.label, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO thin_id;

  INSERT INTO service_option_values (group_id, value_key, label, active, sort_order)
  VALUES (lamination_group, 'thick', 'Thick / Makapal', TRUE, 1)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET label = EXCLUDED.label, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO thick_id;

  INSERT INTO service_pricing_rules (
    service_id, rule_key, option_value_ids, label, price, price_type, active, sort_order
  ) VALUES (
    laminating_id, 'thin', jsonb_build_object('lamination_type', thin_id),
    'Thin / Manipis', 20, 'fixed', TRUE, 0
  )
  ON CONFLICT (service_id, rule_key) DO UPDATE
    SET option_value_ids = EXCLUDED.option_value_ids,
        label = EXCLUDED.label,
        sort_order = EXCLUDED.sort_order,
        updated_at = NOW();

  INSERT INTO service_pricing_rules (
    service_id, rule_key, option_value_ids, label, price, price_type, active, sort_order
  ) VALUES (
    laminating_id, 'thick', jsonb_build_object('lamination_type', thick_id),
    'Thick / Makapal', 30, 'fixed', TRUE, 1
  )
  ON CONFLICT (service_id, rule_key) DO UPDATE
    SET option_value_ids = EXCLUDED.option_value_ids,
        label = EXCLUDED.label,
        sort_order = EXCLUDED.sort_order,
        updated_at = NOW();
END $$;
