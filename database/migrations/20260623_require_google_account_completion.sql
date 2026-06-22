BEGIN;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS local_password_set_at TIMESTAMPTZ NULL;

COMMENT ON COLUMN public.users.local_password_set_at IS
  'Records that a Google-linked user has created a usable password in the active authentication system. Never stores the password itself.';

CREATE OR REPLACE FUNCTION public.servitech_protect_user_security_fields()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
  IF (SELECT auth.uid()) IS NULL
     AND COALESCE(current_setting('request.jwt.claim.role', TRUE), '') <> 'authenticated' THEN
    NEW.updated_at := NOW();
    RETURN NEW;
  END IF;

  IF NOT public.servitech_is_admin() THEN
    IF NEW.role IS DISTINCT FROM OLD.role
       OR NEW.auth_user_id IS DISTINCT FROM OLD.auth_user_id
       OR NEW.password_hash IS DISTINCT FROM OLD.password_hash
       OR NEW.google_id IS DISTINCT FROM OLD.google_id
       OR NEW.local_password_set_at IS DISTINCT FROM OLD.local_password_set_at
       OR NEW.reset_token IS DISTINCT FROM OLD.reset_token
       OR NEW.reset_token_expires IS DISTINCT FROM OLD.reset_token_expires
       OR NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at
       OR NEW.email_verification_token IS DISTINCT FROM OLD.email_verification_token
       OR NEW.email_verification_expires IS DISTINCT FROM OLD.email_verification_expires
       OR NEW.email_verification_sent_at IS DISTINCT FROM OLD.email_verification_sent_at
       OR NEW.consent_accepted_at IS DISTINCT FROM OLD.consent_accepted_at
       OR NEW.consent_version IS DISTINCT FROM OLD.consent_version
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
      RAISE EXCEPTION 'Security-sensitive profile fields cannot be changed.';
    END IF;
  END IF;
  NEW.updated_at := NOW();
  RETURN NEW;
END;
$$;

COMMIT;
