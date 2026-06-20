BEGIN;

ALTER TABLE services ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ NULL;

CREATE TABLE IF NOT EXISTS service_option_groups (
  id BIGSERIAL PRIMARY KEY,
  service_id BIGINT NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  group_key TEXT NOT NULL,
  name TEXT NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  archived_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT service_option_groups_unique_key UNIQUE (service_id, group_key)
);

CREATE TABLE IF NOT EXISTS service_option_values (
  id BIGSERIAL PRIMARY KEY,
  group_id BIGINT NOT NULL REFERENCES service_option_groups(id) ON DELETE CASCADE,
  value_key TEXT NOT NULL,
  label TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  archived_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT service_option_values_unique_key UNIQUE (group_id, value_key)
);

CREATE TABLE IF NOT EXISTS service_pricing_rules (
  id BIGSERIAL PRIMARY KEY,
  service_id BIGINT NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  rule_key TEXT NOT NULL,
  option_value_ids JSONB NOT NULL DEFAULT '{}'::jsonb,
  label TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  price NUMERIC(12, 2) NULL,
  price_type TEXT NOT NULL DEFAULT 'fixed',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  archived_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT service_pricing_rules_unique_key UNIQUE (service_id, rule_key),
  CONSTRAINT service_pricing_rules_price_type_check CHECK (price_type IN ('fixed', 'assessment'))
);

CREATE INDEX IF NOT EXISTS idx_service_option_groups_service ON service_option_groups (service_id, active, sort_order);
CREATE INDEX IF NOT EXISTS idx_service_option_values_group ON service_option_values (group_id, active, sort_order);
CREATE INDEX IF NOT EXISTS idx_service_pricing_rules_service ON service_pricing_rules (service_id, active, sort_order);

UPDATE services
SET name = 'Photocopy'
WHERE category = 'printing'
  AND LOWER(TRIM(name)) = 'xerox';

DO $$
DECLARE
  document_id BIGINT;
  photocopy_id BIGINT;
  rush_id BIGINT;
  repair_id BIGINT;
  installation_id BIGINT;
  paper_group BIGINT;
  color_group BIGINT;
  package_group BIGINT;
  device_group BIGINT;
  repair_group BIGINT;
  install_group BIGINT;
  letter_id BIGINT;
  long_id BIGINT;
  a4_id BIGINT;
  full_id BIGINT;
  half_id BIGINT;
  bw_id BIGINT;
  colored_id BIGINT;
  phone_id BIGINT;
  laptop_id BIGINT;
  desktop_id BIGINT;
  package_id BIGINT;
  value_id BIGINT;
BEGIN
  INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
  SELECT 'printing', 'Document Printing', 'Document printing priced by paper size and color option.', 5, 'PHP 5 - PHP 10', '{}'::jsonb, TRUE, 0
  WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'printing' AND LOWER(name) LIKE '%document%' AND (LOWER(name) LIKE '%print%' OR LOWER(name) LIKE '%printing%')
  );
  SELECT id INTO document_id FROM services
  WHERE category = 'printing' AND LOWER(name) LIKE '%document%' AND (LOWER(name) LIKE '%print%' OR LOWER(name) LIKE '%printing%')
  ORDER BY active DESC, sort_order ASC, id ASC LIMIT 1;
  UPDATE services SET active = TRUE, name = 'Document Printing', description = 'Document printing priced by paper size and color option.', updated_at = NOW() WHERE id = document_id;

  INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
  SELECT 'printing', 'Photocopy', 'Photocopy priced by paper size and color option.', 3, 'PHP 3 - PHP 5', '{}'::jsonb, TRUE, 1
  WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'printing' AND (LOWER(name) LIKE '%photocopy%' OR LOWER(name) LIKE '%xerox%')
  );
  SELECT id INTO photocopy_id FROM services
  WHERE category = 'printing' AND (LOWER(name) LIKE '%photocopy%' OR LOWER(name) LIKE '%xerox%')
  ORDER BY active DESC, sort_order ASC, id ASC LIMIT 1;
  UPDATE services SET active = TRUE, name = 'Photocopy', description = 'Photocopy priced by paper size and color option.', updated_at = NOW() WHERE id = photocopy_id;

  INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
  SELECT 'printing', 'Rush ID', 'Rush ID packages.', 30, 'PHP 30 - PHP 50', '{}'::jsonb, TRUE, 2
  WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'printing' AND LOWER(name) LIKE '%rush%' AND LOWER(name) LIKE '%id%'
  );
  SELECT id INTO rush_id FROM services
  WHERE category = 'printing' AND LOWER(name) LIKE '%rush%' AND LOWER(name) LIKE '%id%'
  ORDER BY active DESC, sort_order ASC, id ASC LIMIT 1;
  UPDATE services SET active = TRUE, name = 'Rush ID', description = 'Rush ID packages.', updated_at = NOW() WHERE id = rush_id;

  UPDATE services
  SET active = FALSE, archived_at = COALESCE(archived_at, NOW()), updated_at = NOW()
  WHERE category = 'printing'
    AND LOWER(name) LIKE '%laminat%';

  INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
  SELECT 'repair', 'Device Repair', 'Repair priced by device type and repair type.', NULL, 'For assessment', '{}'::jsonb, TRUE, 0
  WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'repair' AND (LOWER(name) LIKE '%device%repair%' OR LOWER(name) = 'repair' OR LOWER(name) = 'device repair')
  );
  SELECT id INTO repair_id FROM services
  WHERE category = 'repair' AND (LOWER(name) LIKE '%device%repair%' OR LOWER(name) = 'repair' OR LOWER(name) = 'device repair')
  ORDER BY active DESC, sort_order ASC, id ASC LIMIT 1;
  IF repair_id IS NULL THEN
    INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
    VALUES ('repair', 'Device Repair', 'Repair priced by device type and repair type.', NULL, 'For assessment', '{}'::jsonb, TRUE, 0)
    RETURNING id INTO repair_id;
  END IF;
  UPDATE services SET active = TRUE, name = 'Device Repair', description = 'Repair priced by device type and repair type.', price = NULL, price_range = 'For assessment', updated_at = NOW() WHERE id = repair_id;

  INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
  SELECT 'installation', 'Installation Services', 'Installation priced by installation type.', NULL, 'For assessment', '{}'::jsonb, TRUE, 0
  WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'installation' AND (LOWER(name) LIKE '%installation services%' OR LOWER(name) = 'installation')
  );
  SELECT id INTO installation_id FROM services
  WHERE category = 'installation' AND (LOWER(name) LIKE '%installation services%' OR LOWER(name) = 'installation')
  ORDER BY active DESC, sort_order ASC, id ASC LIMIT 1;
  IF installation_id IS NULL THEN
    INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
    VALUES ('installation', 'Installation Services', 'Installation priced by installation type.', NULL, 'For assessment', '{}'::jsonb, TRUE, 0)
    RETURNING id INTO installation_id;
  END IF;
  UPDATE services SET active = TRUE, name = 'Installation Services', description = 'Installation priced by installation type.', price = NULL, price_range = 'For assessment', updated_at = NOW() WHERE id = installation_id;

  UPDATE services
  SET active = FALSE, archived_at = COALESCE(archived_at, NOW()), updated_at = NOW()
  WHERE category = 'repair' AND id <> repair_id;

  UPDATE services
  SET active = FALSE, archived_at = COALESCE(archived_at, NOW()), updated_at = NOW()
  WHERE category = 'installation' AND id <> installation_id;

  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (document_id, 'paper_size', 'Paper Size', 0)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO paper_group;
  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (document_id, 'color_option', 'Color Option', 1)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO color_group;

  INSERT INTO service_option_values (group_id, value_key, label, sort_order) VALUES
    (paper_group, 'letter', 'Letter', 0),
    (paper_group, '8_5x13', '8.5x13', 1),
    (paper_group, 'a4', 'A4', 2)
  ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();
  SELECT id INTO letter_id FROM service_option_values WHERE group_id = paper_group AND value_key = 'letter';
  SELECT id INTO long_id FROM service_option_values WHERE group_id = paper_group AND value_key = '8_5x13';
  SELECT id INTO a4_id FROM service_option_values WHERE group_id = paper_group AND value_key = 'a4';

  INSERT INTO service_option_values (group_id, value_key, label, sort_order) VALUES
    (color_group, 'half_colored', 'Half Colored', 0),
    (color_group, 'full_colored', 'Full Colored', 1),
    (color_group, 'black_and_white', 'Black and White', 2)
  ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();
  SELECT id INTO half_id FROM service_option_values WHERE group_id = color_group AND value_key = 'half_colored';
  SELECT id INTO full_id FROM service_option_values WHERE group_id = color_group AND value_key = 'full_colored';
  SELECT id INTO bw_id FROM service_option_values WHERE group_id = color_group AND value_key = 'black_and_white';

  INSERT INTO service_pricing_rules (service_id, rule_key, option_value_ids, label, price, price_type, sort_order) VALUES
    (document_id, 'letter_half_colored', jsonb_build_object('paper_size', letter_id, 'color_option', half_id), 'Letter / Half Colored', COALESCE((SELECT (pricing_json->>'letterHalf')::numeric FROM services WHERE id = document_id), 5), 'fixed', 0),
    (document_id, 'letter_full_colored', jsonb_build_object('paper_size', letter_id, 'color_option', full_id), 'Letter / Full Colored', COALESCE((SELECT (pricing_json->>'letterFull')::numeric FROM services WHERE id = document_id), 10), 'fixed', 1),
    (document_id, 'letter_black_and_white', jsonb_build_object('paper_size', letter_id, 'color_option', bw_id), 'Letter / Black and White', COALESCE((SELECT (pricing_json->>'letterBw')::numeric FROM services WHERE id = document_id), 5), 'fixed', 2),
    (document_id, '8_5x13_half_colored', jsonb_build_object('paper_size', long_id, 'color_option', half_id), '8.5x13 / Half Colored', COALESCE((SELECT (pricing_json->>'longHalf')::numeric FROM services WHERE id = document_id), 5), 'fixed', 3),
    (document_id, '8_5x13_full_colored', jsonb_build_object('paper_size', long_id, 'color_option', full_id), '8.5x13 / Full Colored', COALESCE((SELECT (pricing_json->>'longFull')::numeric FROM services WHERE id = document_id), 10), 'fixed', 4),
    (document_id, '8_5x13_black_and_white', jsonb_build_object('paper_size', long_id, 'color_option', bw_id), '8.5x13 / Black and White', COALESCE((SELECT (pricing_json->>'longBw')::numeric FROM services WHERE id = document_id), 5), 'fixed', 5),
    (document_id, 'a4_half_colored', jsonb_build_object('paper_size', a4_id, 'color_option', half_id), 'A4 / Half Colored', COALESCE((SELECT (pricing_json->>'a4Half')::numeric FROM services WHERE id = document_id), 5), 'fixed', 6),
    (document_id, 'a4_full_colored', jsonb_build_object('paper_size', a4_id, 'color_option', full_id), 'A4 / Full Colored', COALESCE((SELECT (pricing_json->>'a4Full')::numeric FROM services WHERE id = document_id), 10), 'fixed', 7),
    (document_id, 'a4_black_and_white', jsonb_build_object('paper_size', a4_id, 'color_option', bw_id), 'A4 / Black and White', COALESCE((SELECT (pricing_json->>'a4Bw')::numeric FROM services WHERE id = document_id), 5), 'fixed', 8)
  ON CONFLICT (service_id, rule_key) DO UPDATE SET option_value_ids = EXCLUDED.option_value_ids, label = EXCLUDED.label, price = EXCLUDED.price, price_type = EXCLUDED.price_type, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();

  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (photocopy_id, 'paper_size', 'Paper Size', 0)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO paper_group;
  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (photocopy_id, 'color_option', 'Color Option', 1)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO color_group;
  INSERT INTO service_option_values (group_id, value_key, label, sort_order) VALUES
    (paper_group, 'letter', 'Letter', 0),
    (paper_group, '8_5x13', '8.5x13', 1),
    (paper_group, 'a4', 'A4', 2)
  ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();
  SELECT id INTO letter_id FROM service_option_values WHERE group_id = paper_group AND value_key = 'letter';
  SELECT id INTO long_id FROM service_option_values WHERE group_id = paper_group AND value_key = '8_5x13';
  SELECT id INTO a4_id FROM service_option_values WHERE group_id = paper_group AND value_key = 'a4';
  INSERT INTO service_option_values (group_id, value_key, label, sort_order) VALUES
    (color_group, 'colored', 'Colored', 0),
    (color_group, 'black_and_white', 'Black and White', 1)
  ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();
  SELECT id INTO colored_id FROM service_option_values WHERE group_id = color_group AND value_key = 'colored';
  SELECT id INTO bw_id FROM service_option_values WHERE group_id = color_group AND value_key = 'black_and_white';
  INSERT INTO service_pricing_rules (service_id, rule_key, option_value_ids, label, price, price_type, sort_order) VALUES
    (photocopy_id, 'letter_colored', jsonb_build_object('paper_size', letter_id, 'color_option', colored_id), 'Letter / Colored', COALESCE((SELECT (pricing_json->>'letterColored')::numeric FROM services WHERE id = photocopy_id), 3), 'fixed', 0),
    (photocopy_id, 'letter_black_and_white', jsonb_build_object('paper_size', letter_id, 'color_option', bw_id), 'Letter / Black and White', COALESCE((SELECT (pricing_json->>'letterBw')::numeric FROM services WHERE id = photocopy_id), 3), 'fixed', 1),
    (photocopy_id, '8_5x13_colored', jsonb_build_object('paper_size', long_id, 'color_option', colored_id), '8.5x13 / Colored', COALESCE((SELECT (pricing_json->>'longColored')::numeric FROM services WHERE id = photocopy_id), 5), 'fixed', 2),
    (photocopy_id, '8_5x13_black_and_white', jsonb_build_object('paper_size', long_id, 'color_option', bw_id), '8.5x13 / Black and White', COALESCE((SELECT (pricing_json->>'longBw')::numeric FROM services WHERE id = photocopy_id), 5), 'fixed', 3),
    (photocopy_id, 'a4_colored', jsonb_build_object('paper_size', a4_id, 'color_option', colored_id), 'A4 / Colored', COALESCE((SELECT (pricing_json->>'a4Colored')::numeric FROM services WHERE id = photocopy_id), 3), 'fixed', 4),
    (photocopy_id, 'a4_black_and_white', jsonb_build_object('paper_size', a4_id, 'color_option', bw_id), 'A4 / Black and White', COALESCE((SELECT (pricing_json->>'a4Bw')::numeric FROM services WHERE id = photocopy_id), 3), 'fixed', 5)
  ON CONFLICT (service_id, rule_key) DO UPDATE SET option_value_ids = EXCLUDED.option_value_ids, label = EXCLUDED.label, price = EXCLUDED.price, price_type = EXCLUDED.price_type, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();

  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (rush_id, 'package', 'Package', 0)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO package_group;
  FOR value_id IN 1..6 LOOP
    INSERT INTO service_option_values (group_id, value_key, label, description, sort_order)
    VALUES (
      package_group,
      'package_' || value_id,
      'Package ' || value_id,
      CASE value_id
        WHEN 1 THEN '1x1 (4pcs), 2x2 (2pcs)'
        WHEN 2 THEN '1x1 (6pcs)'
        WHEN 3 THEN '2x2 (4pcs)'
        WHEN 4 THEN '2x2 (4pcs), 1x1 (4pcs)'
        WHEN 5 THEN 'Passport size (4pcs)'
        ELSE '1x1 (10pcs)'
      END,
      value_id - 1
    )
    ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, description = EXCLUDED.description, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
    RETURNING id INTO package_id;

    INSERT INTO service_pricing_rules (service_id, rule_key, option_value_ids, label, description, price, price_type, sort_order)
    VALUES (
      rush_id,
      'package_' || value_id,
      jsonb_build_object('package', package_id),
      'Package ' || value_id,
      (SELECT description FROM service_option_values WHERE id = package_id),
      COALESCE((SELECT (pricing_json->>('package' || value_id))::numeric FROM services WHERE id = rush_id), CASE WHEN value_id IN (1, 4, 6) THEN CASE WHEN value_id = 1 THEN 40 ELSE 50 END ELSE 30 END),
      'fixed',
      value_id - 1
    )
    ON CONFLICT (service_id, rule_key) DO UPDATE SET option_value_ids = EXCLUDED.option_value_ids, label = EXCLUDED.label, description = EXCLUDED.description, price = EXCLUDED.price, price_type = EXCLUDED.price_type, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();
  END LOOP;

  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (repair_id, 'device_type', 'Device Type', 0)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO device_group;
  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (repair_id, 'repair_type', 'Repair Type', 1)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO repair_group;
  INSERT INTO service_option_values (group_id, value_key, label, sort_order) VALUES
    (device_group, 'phone', 'Phone', 0),
    (device_group, 'laptop', 'Laptop', 1),
    (device_group, 'desktop', 'Desktop', 2)
  ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();
  SELECT id INTO phone_id FROM service_option_values WHERE group_id = device_group AND value_key = 'phone';
  SELECT id INTO laptop_id FROM service_option_values WHERE group_id = device_group AND value_key = 'laptop';
  SELECT id INTO desktop_id FROM service_option_values WHERE group_id = device_group AND value_key = 'desktop';

  INSERT INTO service_option_values (group_id, value_key, label, sort_order)
  SELECT repair_group, value_key, label, sort_order
  FROM (VALUES
    ('lcd_replacement', 'LCD Replacement', 0),
    ('battery_replacement', 'Battery Replacement', 1),
    ('charging_pin_replacement', 'Charging Pin Replacement', 2),
    ('speaker_mouthpiece_replacement', 'Speaker/Mouthpiece Replacement', 3),
    ('power_button', 'Power Button', 4),
    ('volume', 'Volume', 5),
    ('camera_replacement', 'Camera Replacement', 6),
    ('keyboard_replacement', 'Keyboard Replacement', 7),
    ('upgrade_parts', 'Upgrade parts', 8),
    ('no_power', 'No Power', 9),
    ('water_damaged', 'Water Damaged', 10),
    ('shorted', 'Shorted', 11),
    ('others', 'Others', 12)
  ) AS seed(value_key, label, sort_order)
  ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();

  INSERT INTO service_pricing_rules (service_id, rule_key, option_value_ids, label, price, price_type, sort_order)
  SELECT repair_id, device_key || '_' || repair_key,
         jsonb_build_object('device_type', device_id, 'repair_type', repair_type_id),
         device_label || ' / ' || repair_label,
         NULL::numeric,
         'assessment',
         row_number() OVER () - 1
  FROM (
    SELECT 'phone' AS device_key, 'Phone' AS device_label, phone_id AS device_id, value_key AS repair_key, label AS repair_label, id AS repair_type_id
    FROM service_option_values WHERE group_id = repair_group AND value_key IN ('lcd_replacement','battery_replacement','charging_pin_replacement','speaker_mouthpiece_replacement','power_button','volume','camera_replacement','others')
    UNION ALL
    SELECT 'laptop', 'Laptop', laptop_id, value_key, label, id
    FROM service_option_values WHERE group_id = repair_group AND value_key IN ('lcd_replacement','battery_replacement','charging_pin_replacement','speaker_mouthpiece_replacement','power_button','volume','camera_replacement','keyboard_replacement','upgrade_parts','no_power','water_damaged','shorted','others')
    UNION ALL
    SELECT 'desktop', 'Desktop', desktop_id, value_key, label, id
    FROM service_option_values WHERE group_id = repair_group AND value_key IN ('upgrade_parts','others')
  ) AS combos(device_key, device_label, device_id, repair_key, repair_label, repair_type_id)
  ON CONFLICT (service_id, rule_key) DO UPDATE SET option_value_ids = EXCLUDED.option_value_ids, label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();

  INSERT INTO service_option_groups (service_id, group_key, name, sort_order)
  VALUES (installation_id, 'installation_type', 'Installation Type', 0)
  ON CONFLICT (service_id, group_key) DO UPDATE SET name = EXCLUDED.name, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW()
  RETURNING id INTO install_group;

  INSERT INTO service_option_values (group_id, value_key, label, sort_order)
  SELECT install_group, value_key, label, sort_order
  FROM (VALUES
    ('reprogram', 'Reprogram', 0),
    ('hang_logo', 'Hang Logo', 1),
    ('bootloop', 'Bootloop', 2),
    ('openline_samsung_iphone', 'Openline Samsung & iPhone', 3),
    ('bypass_google_account', 'Bypass Google Account', 4),
    ('bypass_password', 'Bypass Password', 5),
    ('bypass_apple_id_activation_lock', 'Bypass Apple ID & Activation Lock', 6),
    ('iphone_restoration_update_ios', 'iPhone Restoration & Update iOS Version', 7),
    ('windows_installation_laptop_desktop', 'Windows Installation Laptop & Desktop', 8),
    ('ms_office_installation_latest', 'MS Office Installation Latest Version', 9),
    ('others', 'Others', 10)
  ) AS seed(value_key, label, sort_order)
  ON CONFLICT (group_id, value_key) DO UPDATE SET label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();

  INSERT INTO service_pricing_rules (service_id, rule_key, option_value_ids, label, price, price_type, sort_order)
  SELECT installation_id, value_key, jsonb_build_object('installation_type', id), label, NULL::numeric, 'assessment', sort_order
  FROM service_option_values
  WHERE group_id = install_group
  ON CONFLICT (service_id, rule_key) DO UPDATE SET option_value_ids = EXCLUDED.option_value_ids, label = EXCLUDED.label, active = TRUE, archived_at = NULL, sort_order = EXCLUDED.sort_order, updated_at = NOW();
END $$;

COMMIT;
