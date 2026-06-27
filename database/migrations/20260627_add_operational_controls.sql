BEGIN;

CREATE TABLE IF NOT EXISTS operational_control_settings (
  id SMALLINT PRIMARY KEY DEFAULT 1,
  all_services_closed BOOLEAN NOT NULL DEFAULT FALSE,
  all_services_closure_reason TEXT NOT NULL DEFAULT '',
  updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT operational_control_settings_singleton_check CHECK (id = 1)
);

CREATE TABLE IF NOT EXISTS operational_service_settings (
  service_id INTEGER PRIMARY KEY REFERENCES services(id) ON DELETE CASCADE,
  manual_status VARCHAR(16) NOT NULL DEFAULT 'open',
  closure_reason TEXT NOT NULL DEFAULT '',
  updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT operational_service_status_check CHECK (manual_status IN ('open', 'closed'))
);

CREATE TABLE IF NOT EXISTS operational_payment_method_settings (
  payment_method_key VARCHAR(32) PRIMARY KEY,
  payment_method_name VARCHAR(80) NOT NULL,
  is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
  disabled_reason TEXT NOT NULL DEFAULT '',
  updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT operational_payment_method_key_check CHECK (
    LOWER(TRIM(payment_method_key)) IN ('cash', 'gcash')
  )
);

CREATE OR REPLACE FUNCTION public.servitech_is_super_admin()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
  SELECT COALESCE((
    SELECT LOWER(TRIM(u.role)) = 'super_admin'
      AND COALESCE(
        COALESCE(NULLIF(current_setting('request.jwt.claims', TRUE), ''), '{}')::jsonb
          ->> 'aal',
        'aal1'
      ) = 'aal2'
    FROM public.users u
    WHERE u.auth_user_id = (SELECT auth.uid())
    LIMIT 1
  ), FALSE)
$$;

REVOKE ALL ON FUNCTION public.servitech_is_super_admin() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_is_super_admin() TO authenticated, service_role;

INSERT INTO operational_control_settings (id, all_services_closed, all_services_closure_reason)
VALUES (1, FALSE, '')
ON CONFLICT (id) DO NOTHING;

INSERT INTO operational_payment_method_settings (payment_method_key, payment_method_name, is_enabled)
VALUES
  ('cash', 'Cash', TRUE),
  ('gcash', 'GCash / Online Payment', TRUE)
ON CONFLICT (payment_method_key) DO NOTHING;

CREATE INDEX IF NOT EXISTS idx_operational_service_settings_status
  ON operational_service_settings (manual_status, service_id);

CREATE INDEX IF NOT EXISTS idx_operational_payment_method_settings_enabled
  ON operational_payment_method_settings (is_enabled, payment_method_key);

ALTER TABLE operational_control_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE operational_service_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE operational_payment_method_settings ENABLE ROW LEVEL SECURITY;

CREATE POLICY servitech_operational_controls_public_select
  ON operational_control_settings FOR SELECT TO anon, authenticated
  USING (TRUE);

CREATE POLICY servitech_operational_controls_admin_all
  ON operational_control_settings FOR ALL TO authenticated
  USING (public.servitech_is_super_admin())
  WITH CHECK (public.servitech_is_super_admin());

CREATE POLICY servitech_operational_service_public_select
  ON operational_service_settings FOR SELECT TO anon, authenticated
  USING (TRUE);

CREATE POLICY servitech_operational_service_admin_all
  ON operational_service_settings FOR ALL TO authenticated
  USING (public.servitech_is_super_admin())
  WITH CHECK (public.servitech_is_super_admin());

CREATE POLICY servitech_operational_payment_public_select
  ON operational_payment_method_settings FOR SELECT TO anon, authenticated
  USING (TRUE);

CREATE POLICY servitech_operational_payment_admin_all
  ON operational_payment_method_settings FOR ALL TO authenticated
  USING (public.servitech_is_super_admin())
  WITH CHECK (public.servitech_is_super_admin());

GRANT SELECT ON
  operational_control_settings,
  operational_service_settings,
  operational_payment_method_settings
TO anon;

GRANT SELECT, INSERT, UPDATE ON
  operational_control_settings,
  operational_service_settings,
  operational_payment_method_settings
TO authenticated;

COMMIT;
