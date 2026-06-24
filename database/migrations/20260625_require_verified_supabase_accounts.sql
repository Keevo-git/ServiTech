BEGIN;

-- A public.users row may be created at signup so metadata remains consistent,
-- but it must not resolve to an application identity until Auth confirms it.
CREATE OR REPLACE FUNCTION public.servitech_current_user_id()
RETURNS INTEGER
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
  SELECT u.id
  FROM public.users u
  WHERE u.auth_user_id = (SELECT auth.uid())
    AND u.email_verified_at IS NOT NULL
  LIMIT 1
$$;

REVOKE ALL ON FUNCTION public.servitech_current_user_id() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_current_user_id() TO authenticated, service_role;

-- Preserve role ownership and the existing AAL2 boundary while also requiring
-- the Auth-linked application profile to be activated.
CREATE OR REPLACE FUNCTION public.servitech_is_admin()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
  SELECT COALESCE((
    SELECT LOWER(TRIM(u.role)) = 'admin'
      AND u.email_verified_at IS NOT NULL
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

-- New Auth profiles start pending even if project email confirmation is
-- accidentally disabled. Password signup is also rejected by the PHP flow in
-- that configuration. Google is activated only after its verified token is
-- validated by the Google login flow.
CREATE OR REPLACE FUNCTION public.servitech_handle_new_auth_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  profile_name TEXT;
  profile_contact TEXT;
BEGIN
  IF EXISTS (
    SELECT 1 FROM public.users u WHERE LOWER(u.email) = LOWER(NEW.email)
  ) THEN
    RETURN NEW;
  END IF;

  profile_name := COALESCE(
    NULLIF(TRIM(NEW.raw_user_meta_data ->> 'fullname'), ''),
    NULLIF(TRIM(NEW.raw_user_meta_data ->> 'full_name'), ''),
    NULLIF(SPLIT_PART(NEW.email, '@', 1), ''),
    'ServiTech Customer'
  );
  profile_contact := NULLIF(TRIM(NEW.raw_user_meta_data ->> 'contact'), '');

  INSERT INTO public.users (
    auth_user_id, fullname, email, contact, password_hash, role,
    email_verified_at, consent_accepted_at, consent_version,
    created_at, updated_at
  )
  VALUES (
    NEW.id, profile_name, LOWER(NEW.email), profile_contact, NULL, 'customer',
    NULL,
    CASE
      WHEN NEW.raw_user_meta_data ->> 'privacy_consent' = '1' THEN NOW()
      ELSE NULL
    END,
    NULLIF(TRIM(NEW.raw_user_meta_data ->> 'consent_version'), ''),
    NOW(), NOW()
  );

  RETURN NEW;
END;
$$;

REVOKE ALL ON FUNCTION public.servitech_handle_new_auth_user() FROM PUBLIC;

-- Confirmation is the only Auth-side event that activates an email account.
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

-- Unverified callers cannot read or update even their pending profile directly
-- through the Supabase data API. All other customer policies already resolve
-- ownership through servitech_current_user_id().
DROP POLICY IF EXISTS servitech_users_select ON public.users;
CREATE POLICY servitech_users_select
  ON public.users FOR SELECT TO authenticated
  USING (
    (
      auth_user_id = (SELECT auth.uid())
      AND email_verified_at IS NOT NULL
    )
    OR public.servitech_is_admin()
  );

DROP POLICY IF EXISTS servitech_users_update ON public.users;
CREATE POLICY servitech_users_update
  ON public.users FOR UPDATE TO authenticated
  USING (
    (
      auth_user_id = (SELECT auth.uid())
      AND email_verified_at IS NOT NULL
    )
    OR public.servitech_is_admin()
  )
  WITH CHECK (
    (
      auth_user_id = (SELECT auth.uid())
      AND email_verified_at IS NOT NULL
    )
    OR public.servitech_is_admin()
  );

COMMIT;
