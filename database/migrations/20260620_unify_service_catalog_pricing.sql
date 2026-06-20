BEGIN;

ALTER TABLE services ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ NULL;

UPDATE services
SET name = 'Photocopy'
WHERE category = 'printing'
  AND LOWER(TRIM(name)) = 'xerox';

UPDATE services
SET pricing_json = COALESCE(pricing_json, '{}'::jsonb)
  || jsonb_build_object(
    'letterFull', COALESCE((pricing_json->>'letterFull')::numeric, (pricing_json->>'shortFull')::numeric, 10),
    'letterHalf', COALESCE((pricing_json->>'letterHalf')::numeric, (pricing_json->>'shortHalf')::numeric, 5),
    'letterBw', COALESCE((pricing_json->>'letterBw')::numeric, (pricing_json->>'shortHalf')::numeric, 5),
    'longFull', COALESCE((pricing_json->>'longFull')::numeric, 10),
    'longHalf', COALESCE((pricing_json->>'longHalf')::numeric, 5),
    'longBw', COALESCE((pricing_json->>'longBw')::numeric, (pricing_json->>'longHalf')::numeric, 5),
    'a4Full', COALESCE((pricing_json->>'a4Full')::numeric, 10),
    'a4Half', COALESCE((pricing_json->>'a4Half')::numeric, 5),
    'a4Bw', COALESCE((pricing_json->>'a4Bw')::numeric, (pricing_json->>'a4Half')::numeric, 5)
  ),
  price_range = CASE WHEN NULLIF(TRIM(price_range), '') IS NULL THEN 'PHP 5 - PHP 10' ELSE price_range END,
  updated_at = NOW()
WHERE category = 'printing'
  AND LOWER(name) LIKE '%document%'
  AND (LOWER(name) LIKE '%printing%' OR LOWER(name) LIKE '%print%');

UPDATE services
SET pricing_json = COALESCE(pricing_json, '{}'::jsonb)
  || jsonb_build_object(
    'letterColored', COALESCE((pricing_json->>'letterColored')::numeric, (pricing_json->>'short')::numeric, 3),
    'letterBw', COALESCE((pricing_json->>'letterBw')::numeric, (pricing_json->>'short')::numeric, 3),
    'longColored', COALESCE((pricing_json->>'longColored')::numeric, (pricing_json->>'long')::numeric, 5),
    'longBw', COALESCE((pricing_json->>'longBw')::numeric, (pricing_json->>'long')::numeric, 5),
    'a4Colored', COALESCE((pricing_json->>'a4Colored')::numeric, (pricing_json->>'a4')::numeric, 3),
    'a4Bw', COALESCE((pricing_json->>'a4Bw')::numeric, (pricing_json->>'a4')::numeric, 3)
  ),
  price_range = CASE WHEN NULLIF(TRIM(price_range), '') IS NULL THEN 'PHP 3 - PHP 5' ELSE price_range END,
  updated_at = NOW()
WHERE category = 'printing'
  AND (LOWER(name) LIKE '%photocopy%' OR LOWER(name) LIKE '%xerox%');

INSERT INTO services (category, name, description, price, price_range, pricing_json, active, sort_order)
SELECT seed.category, seed.name, seed.description, seed.price, seed.price_range, seed.pricing_json, TRUE, seed.sort_order
FROM (
  VALUES
    ('repair', 'Keyboard Replacement', 'Laptop keyboard replacement.', NULL::numeric, 'For assessment', NULL::jsonb, 7),
    ('repair', 'Upgrade parts', 'Phone, laptop, or desktop parts upgrade.', NULL::numeric, 'For assessment', NULL::jsonb, 8),
    ('repair', 'No Power', 'Laptop no-power diagnosis and repair.', NULL::numeric, 'For assessment', NULL::jsonb, 9),
    ('repair', 'Water Damaged', 'Laptop water-damage diagnosis and repair.', NULL::numeric, 'For assessment', NULL::jsonb, 10),
    ('repair', 'Shorted', 'Laptop shorted-board diagnosis and repair.', NULL::numeric, 'For assessment', NULL::jsonb, 11),
    ('repair', 'Others', 'Describe the repair request for staff assessment.', NULL::numeric, 'For assessment', NULL::jsonb, 12),
    ('installation', 'Bypass Apple ID & Activation Lock', 'Apple ID and activation-lock bypass assessment.', NULL::numeric, 'For assessment', NULL::jsonb, 6),
    ('installation', 'iPhone Restoration & Update iOS Version', 'iPhone restore and iOS update service.', NULL::numeric, 'For assessment', NULL::jsonb, 7),
    ('installation', 'Windows Installation Laptop & Desktop', 'Windows installation for laptop and desktop.', NULL::numeric, 'For assessment', NULL::jsonb, 8),
    ('installation', 'MS Office Installation Latest Version', 'Latest MS Office installation service.', NULL::numeric, 'For assessment', NULL::jsonb, 9),
    ('installation', 'Others', 'Describe the installation request for staff assessment.', NULL::numeric, 'For assessment', NULL::jsonb, 10)
) AS seed(category, name, description, price, price_range, pricing_json, sort_order)
WHERE NOT EXISTS (
  SELECT 1
  FROM services existing
  WHERE existing.category = seed.category
    AND LOWER(TRIM(existing.name)) = LOWER(TRIM(seed.name))
);

COMMIT;
