BEGIN;

-- Server-side PHP marks its authenticated PDO transaction with this private
-- claim. A normal Supabase JWT sent directly to PostgREST does not contain it.
CREATE OR REPLACE FUNCTION public.servitech_is_trusted_backend()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
  SELECT COALESCE(
    (COALESCE(NULLIF(current_setting('request.jwt.claims', TRUE), ''), '{}')::jsonb
      ->> 'servitech_backend') = 'true',
    FALSE
  )
$$;

REVOKE ALL ON FUNCTION public.servitech_is_trusted_backend() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_is_trusted_backend()
  TO authenticated, service_role;

-- Admin RLS authority requires both the mapped public.users role and a
-- Supabase AAL2 session produced by a verified MFA challenge.
CREATE OR REPLACE FUNCTION public.servitech_is_admin()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
  SELECT COALESCE((
    SELECT LOWER(TRIM(u.role)) = 'admin'
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

REVOKE ALL ON FUNCTION public.servitech_is_admin() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_is_admin() TO authenticated, service_role;

-- Secure email change can remain enabled. public.users follows auth.users only
-- after Supabase has accepted/confirmed the Auth-side address change.
CREATE OR REPLACE FUNCTION public.servitech_sync_auth_user_email()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
  UPDATE public.users
  SET email = LOWER(NEW.email),
      email_verified_at = CASE
        WHEN NEW.email_confirmed_at IS NOT NULL
          THEN COALESCE(email_verified_at, NEW.email_confirmed_at)
        ELSE email_verified_at
      END,
      updated_at = NOW()
  WHERE auth_user_id = NEW.id;
  RETURN NEW;
END;
$$;

REVOKE ALL ON FUNCTION public.servitech_sync_auth_user_email() FROM PUBLIC;

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgrelid = 'auth.users'::regclass
      AND tgname = 'on_auth_user_email_changed_servitech_profile'
      AND NOT tgisinternal
  ) THEN
    CREATE TRIGGER on_auth_user_email_changed_servitech_profile
      AFTER UPDATE OF email, email_confirmed_at ON auth.users
      FOR EACH ROW EXECUTE FUNCTION public.servitech_sync_auth_user_email();
  END IF;
END;
$trigger$;

-- Catalog tables introduced after the original RLS foundation must inherit the
-- same public-read/admin-write boundary as public.services.
ALTER TABLE public.service_option_groups ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.service_option_values ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.service_pricing_rules ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS servitech_service_option_groups_public_select ON public.service_option_groups;
DROP POLICY IF EXISTS servitech_service_option_groups_authenticated_select ON public.service_option_groups;
DROP POLICY IF EXISTS servitech_service_option_groups_admin_all ON public.service_option_groups;
CREATE POLICY servitech_service_option_groups_public_select
  ON public.service_option_groups FOR SELECT TO anon
  USING (
    active = TRUE AND archived_at IS NULL
    AND EXISTS (
      SELECT 1 FROM public.services s
      WHERE s.id = service_id AND s.active = TRUE AND s.archived_at IS NULL
    )
  );
CREATE POLICY servitech_service_option_groups_authenticated_select
  ON public.service_option_groups FOR SELECT TO authenticated
  USING (
    public.servitech_is_admin()
    OR (
      active = TRUE AND archived_at IS NULL
      AND EXISTS (
        SELECT 1 FROM public.services s
        WHERE s.id = service_id AND s.active = TRUE AND s.archived_at IS NULL
      )
    )
  );
CREATE POLICY servitech_service_option_groups_admin_all
  ON public.service_option_groups FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

DROP POLICY IF EXISTS servitech_service_option_values_public_select ON public.service_option_values;
DROP POLICY IF EXISTS servitech_service_option_values_authenticated_select ON public.service_option_values;
DROP POLICY IF EXISTS servitech_service_option_values_admin_all ON public.service_option_values;
CREATE POLICY servitech_service_option_values_public_select
  ON public.service_option_values FOR SELECT TO anon
  USING (
    active = TRUE AND archived_at IS NULL
    AND EXISTS (
      SELECT 1
      FROM public.service_option_groups g
      JOIN public.services s ON s.id = g.service_id
      WHERE g.id = group_id
        AND g.active = TRUE AND g.archived_at IS NULL
        AND s.active = TRUE AND s.archived_at IS NULL
    )
  );
CREATE POLICY servitech_service_option_values_authenticated_select
  ON public.service_option_values FOR SELECT TO authenticated
  USING (
    public.servitech_is_admin()
    OR (
      active = TRUE AND archived_at IS NULL
      AND EXISTS (
        SELECT 1
        FROM public.service_option_groups g
        JOIN public.services s ON s.id = g.service_id
        WHERE g.id = group_id
          AND g.active = TRUE AND g.archived_at IS NULL
          AND s.active = TRUE AND s.archived_at IS NULL
      )
    )
  );
CREATE POLICY servitech_service_option_values_admin_all
  ON public.service_option_values FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

DROP POLICY IF EXISTS servitech_service_pricing_rules_public_select ON public.service_pricing_rules;
DROP POLICY IF EXISTS servitech_service_pricing_rules_authenticated_select ON public.service_pricing_rules;
DROP POLICY IF EXISTS servitech_service_pricing_rules_admin_all ON public.service_pricing_rules;
CREATE POLICY servitech_service_pricing_rules_public_select
  ON public.service_pricing_rules FOR SELECT TO anon
  USING (
    active = TRUE AND archived_at IS NULL
    AND EXISTS (
      SELECT 1 FROM public.services s
      WHERE s.id = service_id AND s.active = TRUE AND s.archived_at IS NULL
    )
  );
CREATE POLICY servitech_service_pricing_rules_authenticated_select
  ON public.service_pricing_rules FOR SELECT TO authenticated
  USING (
    public.servitech_is_admin()
    OR (
      active = TRUE AND archived_at IS NULL
      AND EXISTS (
        SELECT 1 FROM public.services s
        WHERE s.id = service_id AND s.active = TRUE AND s.archived_at IS NULL
      )
    )
  );
CREATE POLICY servitech_service_pricing_rules_admin_all
  ON public.service_pricing_rules FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

GRANT SELECT ON public.service_option_groups,
  public.service_option_values, public.service_pricing_rules TO anon, authenticated;
GRANT INSERT, UPDATE ON public.service_option_groups,
  public.service_option_values, public.service_pricing_rules TO authenticated;
REVOKE DELETE ON public.service_option_groups,
  public.service_option_values, public.service_pricing_rules FROM anon, authenticated;

-- Local remember-token rows contain authentication material and are never part
-- of the browser-facing Supabase data API.
ALTER TABLE public.remember_tokens ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.remember_tokens FROM anon, authenticated;

-- A signed-in browser may read only its own business records. Creation and
-- mutation must pass through the PHP backend, where catalog prices, lifecycle
-- transitions, payment requirements, and upload validation are enforced.
DROP POLICY IF EXISTS servitech_queues_insert ON public.queues;
DROP POLICY IF EXISTS servitech_queues_update ON public.queues;
CREATE POLICY servitech_queues_insert
  ON public.queues FOR INSERT TO authenticated
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
    )
  );
CREATE POLICY servitech_queues_update
  ON public.queues FOR UPDATE TO authenticated
  USING (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
    )
  )
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
    )
  );

DROP POLICY IF EXISTS servitech_payments_insert ON public.payments;
DROP POLICY IF EXISTS servitech_payments_update ON public.payments;
CREATE POLICY servitech_payments_insert
  ON public.payments FOR INSERT TO authenticated
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
      AND EXISTS (
        SELECT 1 FROM public.queues q
        WHERE q.id = queue_id AND q.user_id = public.servitech_current_user_id()
      )
    )
  );
CREATE POLICY servitech_payments_update
  ON public.payments FOR UPDATE TO authenticated
  USING (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
    )
  )
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
    )
  );

DROP POLICY IF EXISTS servitech_notifications_insert ON public.notifications;
DROP POLICY IF EXISTS servitech_notifications_update ON public.notifications;
CREATE POLICY servitech_notifications_insert
  ON public.notifications FOR INSERT TO authenticated
  WITH CHECK (
    public.servitech_is_admin()
    OR (public.servitech_is_trusted_backend() AND user_id = public.servitech_current_user_id())
  );
CREATE POLICY servitech_notifications_update
  ON public.notifications FOR UPDATE TO authenticated
  USING (
    public.servitech_is_admin()
    OR (public.servitech_is_trusted_backend() AND user_id = public.servitech_current_user_id())
  )
  WITH CHECK (
    public.servitech_is_admin()
    OR (public.servitech_is_trusted_backend() AND user_id = public.servitech_current_user_id())
  );

DROP POLICY IF EXISTS servitech_queue_history_insert ON public.queue_status_history;
CREATE POLICY servitech_queue_history_insert
  ON public.queue_status_history FOR INSERT TO authenticated
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND admin_id IS NULL
      AND EXISTS (
        SELECT 1 FROM public.queues q
        WHERE q.id = queue_id AND q.user_id = public.servitech_current_user_id()
      )
    )
  );

DROP POLICY IF EXISTS servitech_uploads_insert ON public.uploads;
DROP POLICY IF EXISTS servitech_uploads_update ON public.uploads;
CREATE POLICY servitech_uploads_insert
  ON public.uploads FOR INSERT TO authenticated
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
      AND COALESCE(uploaded_by, user_id) = public.servitech_current_user_id()
      AND (queue_id IS NULL OR EXISTS (
        SELECT 1 FROM public.queues q
        WHERE q.id = queue_id AND q.user_id = public.servitech_current_user_id()
      ))
      AND (payment_id IS NULL OR EXISTS (
        SELECT 1 FROM public.payments p
        WHERE p.id = payment_id AND p.user_id = public.servitech_current_user_id()
      ))
    )
  );
CREATE POLICY servitech_uploads_update
  ON public.uploads FOR UPDATE TO authenticated
  USING (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
    )
  )
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      public.servitech_is_trusted_backend()
      AND user_id = public.servitech_current_user_id()
    )
  );

REVOKE DELETE ON public.users, public.queues, public.payments,
  public.notifications, public.queue_status_history, public.uploads
FROM anon, authenticated;

COMMIT;
