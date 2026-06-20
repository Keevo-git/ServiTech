BEGIN;

DO $$
DECLARE
  rush_id BIGINT;
  addon_group BIGINT;
BEGIN
  SELECT id INTO rush_id
  FROM services
  WHERE category = 'printing'
    AND LOWER(name) LIKE '%rush%'
    AND LOWER(name) LIKE '%id%'
  ORDER BY active DESC, sort_order ASC, id ASC
  LIMIT 1;

  IF rush_id IS NULL THEN
    RAISE EXCEPTION 'Rush ID service not found. Run 20260620_unify_service_catalog_pricing.sql first.';
  END IF;

  INSERT INTO service_option_groups (service_id, group_key, name, active, sort_order)
  VALUES (rush_id, 'addon', 'Add-Ons', TRUE, 1)
  ON CONFLICT (service_id, group_key) DO UPDATE
    SET name = EXCLUDED.name,
        active = TRUE,
        archived_at = NULL,
        sort_order = EXCLUDED.sort_order,
        updated_at = NOW()
  RETURNING id INTO addon_group;

  INSERT INTO service_option_values (
    group_id, value_key, label, description, active, sort_order
  ) VALUES
    (addon_group, 'formal_attire', 'Formal Attire', 'Edit the customer photo into formal attire.', FALSE, 0),
    (addon_group, 'name_at_bottom', 'Name in the bottom of picture', 'Add the customer name at the bottom of the picture.', FALSE, 1)
  ON CONFLICT (group_id, value_key) DO UPDATE
    SET label = EXCLUDED.label,
        description = EXCLUDED.description,
        updated_at = NOW();

  INSERT INTO service_pricing_rules (
    service_id, rule_key, option_value_ids, label, description,
    price, price_type, active, sort_order
  )
  SELECT
    rush_id,
    'addon_' || value_key,
    jsonb_build_object('addon', id),
    label,
    description,
    NULL::numeric,
    'assessment',
    FALSE,
    sort_order
  FROM service_option_values
  WHERE group_id = addon_group
    AND value_key IN ('formal_attire', 'name_at_bottom')
  ON CONFLICT (service_id, rule_key) DO UPDATE
    SET option_value_ids = EXCLUDED.option_value_ids,
        label = EXCLUDED.label,
        description = EXCLUDED.description,
        updated_at = NOW();
END $$;

COMMIT;
