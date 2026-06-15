BEGIN;

CREATE TABLE IF NOT EXISTS store_availability_settings (
  id SMALLINT PRIMARY KEY DEFAULT 1,
  store_status VARCHAR(24) NOT NULL DEFAULT 'open',
  queue_cutoff_time TIME NOT NULL DEFAULT '16:30',
  updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT store_availability_singleton_check CHECK (id = 1),
  CONSTRAINT store_availability_status_check CHECK (
    store_status IN ('open', 'closed', 'paused', 'fully_booked')
  )
);

CREATE TABLE IF NOT EXISTS store_hours (
  day_of_week SMALLINT PRIMARY KEY,
  is_open BOOLEAN NOT NULL DEFAULT TRUE,
  opens_at TIME NULL,
  closes_at TIME NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT store_hours_day_check CHECK (day_of_week BETWEEN 0 AND 6),
  CONSTRAINT store_hours_time_check CHECK (
    (is_open = FALSE)
    OR (opens_at IS NOT NULL AND closes_at IS NOT NULL AND closes_at > opens_at)
  )
);

CREATE TABLE IF NOT EXISTS store_holidays (
  id BIGSERIAL PRIMARY KEY,
  holiday_date DATE NOT NULL UNIQUE,
  title VARCHAR(120) NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_store_holidays_date
  ON store_holidays (holiday_date);

INSERT INTO store_availability_settings (id, store_status, queue_cutoff_time)
VALUES (1, 'open', '16:30')
ON CONFLICT (id) DO NOTHING;

INSERT INTO store_hours (day_of_week, is_open, opens_at, closes_at)
VALUES
  (0, FALSE, NULL, NULL),
  (1, TRUE, '08:00', '17:00'),
  (2, TRUE, '08:00', '17:00'),
  (3, TRUE, '08:00', '17:00'),
  (4, TRUE, '08:00', '17:00'),
  (5, TRUE, '08:00', '17:00'),
  (6, TRUE, '08:00', '17:00')
ON CONFLICT (day_of_week) DO NOTHING;

ALTER TABLE store_availability_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE store_hours ENABLE ROW LEVEL SECURITY;
ALTER TABLE store_holidays ENABLE ROW LEVEL SECURITY;

CREATE POLICY servitech_store_settings_public_select
  ON store_availability_settings FOR SELECT TO anon, authenticated
  USING (TRUE);

CREATE POLICY servitech_store_settings_admin_all
  ON store_availability_settings FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

CREATE POLICY servitech_store_hours_public_select
  ON store_hours FOR SELECT TO anon, authenticated
  USING (TRUE);

CREATE POLICY servitech_store_hours_admin_all
  ON store_hours FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

CREATE POLICY servitech_store_holidays_public_select
  ON store_holidays FOR SELECT TO anon, authenticated
  USING (TRUE);

CREATE POLICY servitech_store_holidays_admin_all
  ON store_holidays FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

GRANT SELECT ON store_availability_settings, store_hours, store_holidays TO anon;
GRANT SELECT, INSERT, UPDATE, DELETE ON
  store_availability_settings,
  store_hours,
  store_holidays
TO authenticated;
GRANT USAGE, SELECT ON SEQUENCE store_holidays_id_seq TO authenticated;

COMMIT;
