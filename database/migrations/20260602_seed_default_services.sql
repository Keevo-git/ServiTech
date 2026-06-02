INSERT INTO services (
  category, name, description, price, price_range, pricing_json, active, sort_order
)
SELECT
  seed.category,
  seed.name,
  seed.description,
  seed.price,
  seed.price_range,
  seed.pricing_json,
  TRUE,
  seed.sort_order
FROM (
  VALUES
    (
      'printing',
      'Document Printing',
      'Long, short, A4, and A3 document printing with full-color and half-color options.',
      5.00::NUMERIC,
      'PHP 5 - PHP 10',
      '{"longFull":10,"longHalf":5,"shortFull":10,"shortHalf":5,"a4Full":10,"a4Half":5,"a3Full":10,"a3Half":5}'::JSONB,
      0
    ),
    (
      'printing',
      'Xerox',
      'Photocopy service for long, short, A4, and A3 paper.',
      3.00::NUMERIC,
      'PHP 3 - PHP 5',
      '{"long":5,"short":3,"a4":3,"a3":5}'::JSONB,
      1
    ),
    (
      'printing',
      'Rush ID',
      'Choose between packages 1-6.',
      30.00::NUMERIC,
      'PHP 30 - PHP 50',
      '{"package1":40,"package2":30,"package3":30,"package4":50,"package5":30,"package6":50}'::JSONB,
      2
    ),
    (
      'printing',
      'Laminating',
      'Choose thin or thick lamination.',
      20.00::NUMERIC,
      'PHP 20 - PHP 30',
      '{"thin":20,"thick":30}'::JSONB,
      3
    ),
    ('repair', 'LCD Replacement', 'For mobile phones and laptops.', 1200.00::NUMERIC, 'PHP 1200 - PHP 5500', NULL::JSONB, 0),
    ('repair', 'Battery Replacement', 'For mobile phones and laptops.', 700.00::NUMERIC, 'PHP 700 - PHP 2500', NULL::JSONB, 1),
    ('repair', 'Charging Pin Replacement', 'For mobile phones and laptops.', 800.00::NUMERIC, 'PHP 800 - PHP 4000', NULL::JSONB, 2),
    ('repair', 'Speaker / Mouthpiece Replacement', 'For mobile phones and laptops.', 700.00::NUMERIC, 'PHP 700 - PHP 1500', NULL::JSONB, 3),
    ('repair', 'Power Button Repair', 'For mobile phones and laptops.', 500.00::NUMERIC, 'PHP 500 - PHP 2000', NULL::JSONB, 4),
    ('repair', 'Volume Repair', 'For mobile phones and laptops.', 1000.00::NUMERIC, 'PHP 1000 - PHP 2000', NULL::JSONB, 5),
    ('repair', 'Camera Repair', 'For mobile phones and laptops.', 1500.00::NUMERIC, 'PHP 1500 - PHP 5000', NULL::JSONB, 6),
    ('installation', 'Reprogram Service', 'Device reprogramming service.', 1000.00::NUMERIC, 'PHP 1000 - PHP 4000', NULL::JSONB, 0),
    ('installation', 'Hang Logo Fix Service', 'Device startup logo troubleshooting.', 1000.00::NUMERIC, 'PHP 1000 - PHP 3500', NULL::JSONB, 1),
    ('installation', 'Boot Loop Fix Service', 'Device boot loop troubleshooting.', 1000.00::NUMERIC, 'PHP 1000 - PHP 5000', NULL::JSONB, 2),
    ('installation', 'Openline Samsung & iPhone', 'Supported device network unlocking service.', 3500.00::NUMERIC, 'PHP 3500 - PHP 6000', NULL::JSONB, 3),
    ('installation', 'Bypass Google Account', 'Supported device account recovery service.', 500.00::NUMERIC, 'PHP 500 - PHP 2000', NULL::JSONB, 4),
    ('installation', 'Bypass Password', 'Supported device access recovery service.', 1000.00::NUMERIC, 'PHP 1000 - PHP 3000', NULL::JSONB, 5)
) AS seed(category, name, description, price, price_range, pricing_json, sort_order)
WHERE NOT EXISTS (
  SELECT 1
  FROM services existing
  WHERE LOWER(TRIM(existing.category)) = LOWER(TRIM(seed.category))
    AND LOWER(TRIM(existing.name)) = LOWER(TRIM(seed.name))
);
