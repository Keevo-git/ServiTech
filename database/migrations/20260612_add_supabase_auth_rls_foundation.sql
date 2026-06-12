BEGIN;

-- Additive Auth linkage. Existing integer IDs and foreign keys remain intact.
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS auth_user_id UUID NULL REFERENCES auth.users(id) ON DELETE SET NULL;

CREATE UNIQUE INDEX IF NOT EXISTS users_auth_user_id_unique
  ON public.users (auth_user_id)
  WHERE auth_user_id IS NOT NULL;

ALTER TABLE public.uploads
  ADD COLUMN IF NOT EXISTS upload_purpose VARCHAR(64) NOT NULL DEFAULT 'service_request',
  ADD COLUMN IF NOT EXISTS visibility VARCHAR(32) NOT NULL DEFAULT 'private',
  ADD COLUMN IF NOT EXISTS upload_status VARCHAR(32) NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS uploaded_by INTEGER NULL REFERENCES public.users(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS payment_id BIGINT NULL REFERENCES public.payments(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

ALTER TABLE public.payments
  ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS verified_by INTEGER NULL REFERENCES public.users(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS verification_notes TEXT NOT NULL DEFAULT '';

UPDATE public.uploads
SET uploaded_by = user_id
WHERE uploaded_by IS NULL;

CREATE INDEX IF NOT EXISTS idx_users_auth_user_id
  ON public.users (auth_user_id);
CREATE INDEX IF NOT EXISTS idx_uploads_owner_status
  ON public.uploads (user_id, upload_status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_uploads_payment_id
  ON public.uploads (payment_id)
  WHERE payment_id IS NOT NULL;

CREATE FUNCTION public.servitech_current_user_id()
RETURNS INTEGER
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
  SELECT u.id
  FROM public.users u
  WHERE u.auth_user_id = (SELECT auth.uid())
  LIMIT 1
$$;

CREATE FUNCTION public.servitech_is_admin()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
  SELECT COALESCE((
    SELECT LOWER(TRIM(u.role)) = 'admin'
    FROM public.users u
    WHERE u.auth_user_id = (SELECT auth.uid())
    LIMIT 1
  ), FALSE)
$$;

REVOKE ALL ON FUNCTION public.servitech_current_user_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.servitech_is_admin() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_current_user_id() TO authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.servitech_is_admin() TO authenticated, service_role;

CREATE FUNCTION public.servitech_next_queue_identity(requested_prefix TEXT)
RETURNS TABLE(queue_code TEXT, queue_cycle_date DATE, daily_sequence INTEGER)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  normalized_prefix TEXT := UPPER(TRIM(requested_prefix));
  cycle_date DATE := (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::DATE;
  next_sequence INTEGER;
  compact_date TEXT;
BEGIN
  IF normalized_prefix NOT IN ('OP', 'P', 'R', 'I', 'W') THEN
    RAISE EXCEPTION 'Invalid queue prefix.';
  END IF;
  LOCK TABLE public.queues IN EXCLUSIVE MODE;
  compact_date := TO_CHAR(cycle_date, 'YYYYMMDD');
  SELECT COALESCE(MAX(q.daily_sequence), 0) + 1
  INTO next_sequence
  FROM public.queues q
  WHERE q.queue_cycle_date = cycle_date
    AND q.queue_code ~ ('^' || normalized_prefix || compact_date || '-[0-9]+$');

  RETURN QUERY SELECT
    normalized_prefix || compact_date || '-' || LPAD(next_sequence::TEXT, 4, '0'),
    cycle_date,
    next_sequence;
END;
$$;

REVOKE ALL ON FUNCTION public.servitech_next_queue_identity(TEXT) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_next_queue_identity(TEXT) TO authenticated, service_role;

CREATE FUNCTION public.servitech_add_notification_secure(
  target_user_id INTEGER,
  notification_type TEXT,
  notification_reference_id BIGINT,
  notification_message TEXT,
  notification_event_key TEXT,
  include_deleted_in_dedupe BOOLEAN DEFAULT FALSE
)
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  caller_user_id INTEGER := public.servitech_current_user_id();
  normalized_type TEXT := COALESCE(NULLIF(TRIM(notification_type), ''), 'queue');
  normalized_message TEXT := TRIM(COALESCE(notification_message, ''));
  normalized_event_key TEXT := TRIM(COALESCE(notification_event_key, ''));
  event_identity TEXT;
BEGIN
  IF caller_user_id IS NULL OR target_user_id IS NULL OR normalized_message = '' THEN
    RAISE EXCEPTION 'Invalid notification request.';
  END IF;

  IF target_user_id <> caller_user_id AND NOT public.servitech_is_admin() THEN
    IF notification_reference_id IS NULL
       OR NOT EXISTS (
         SELECT 1
         FROM public.queues q
         JOIN public.users target ON target.id = target_user_id
         WHERE q.id = notification_reference_id
           AND q.user_id = caller_user_id
           AND LOWER(TRIM(target.role)) = 'admin'
       ) THEN
      RAISE EXCEPTION 'Notification target is not allowed.';
    END IF;
  END IF;

  event_identity := COALESCE(NULLIF(normalized_event_key, ''), MD5(normalized_message));
  PERFORM pg_advisory_xact_lock(hashtext(CONCAT_WS(
    '|', target_user_id::TEXT, LOWER(normalized_type),
    COALESCE(notification_reference_id, 0)::TEXT, event_identity
  )));

  INSERT INTO public.notifications (
    user_id, type, reference_id, message, event_key, is_read, created_at
  )
  SELECT target_user_id, normalized_type, notification_reference_id,
         normalized_message, NULLIF(normalized_event_key, ''), FALSE, NOW()
  WHERE NOT EXISTS (
    SELECT 1
    FROM public.notifications n
    WHERE n.user_id = target_user_id
      AND LOWER(TRIM(COALESCE(n.type, 'queue'))) = LOWER(normalized_type)
      AND COALESCE(n.reference_id, 0) = COALESCE(notification_reference_id, 0)
      AND COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
        = event_identity
      AND (include_deleted_in_dedupe OR n.deleted_at IS NULL)
  )
  ON CONFLICT DO NOTHING;
END;
$$;

CREATE FUNCTION public.servitech_notify_admin_secure(
  notification_type TEXT,
  notification_reference_id BIGINT,
  notification_message TEXT,
  notification_event_key TEXT,
  include_deleted_in_dedupe BOOLEAN DEFAULT FALSE
)
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  admin_user_id INTEGER;
BEGIN
  SELECT u.id INTO admin_user_id
  FROM public.users u
  WHERE LOWER(TRIM(u.role)) = 'admin'
  ORDER BY u.id
  LIMIT 1;

  IF admin_user_id IS NOT NULL THEN
    PERFORM public.servitech_add_notification_secure(
      admin_user_id,
      notification_type,
      notification_reference_id,
      notification_message,
      notification_event_key,
      include_deleted_in_dedupe
    );
  END IF;
END;
$$;

REVOKE ALL ON FUNCTION public.servitech_add_notification_secure(INTEGER, TEXT, BIGINT, TEXT, TEXT, BOOLEAN) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.servitech_notify_admin_secure(TEXT, BIGINT, TEXT, TEXT, BOOLEAN) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_add_notification_secure(INTEGER, TEXT, BIGINT, TEXT, TEXT, BOOLEAN)
  TO authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.servitech_notify_admin_secure(TEXT, BIGINT, TEXT, TEXT, BOOLEAN)
  TO authenticated, service_role;

CREATE FUNCTION public.servitech_handle_new_auth_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  profile_name TEXT;
  profile_contact TEXT;
BEGIN
  -- Existing legacy emails are linked only by the privileged first-login bridge.
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
    consent_accepted_at, consent_version, created_at, updated_at
  )
  VALUES (
    NEW.id, profile_name, LOWER(NEW.email), profile_contact, NULL, 'customer',
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

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_trigger
    WHERE tgrelid = 'auth.users'::regclass
      AND tgname = 'on_auth_user_created_servitech_profile'
      AND NOT tgisinternal
  ) THEN
    CREATE TRIGGER on_auth_user_created_servitech_profile
      AFTER INSERT ON auth.users
      FOR EACH ROW EXECUTE FUNCTION public.servitech_handle_new_auth_user();
  END IF;
END;
$trigger$;

CREATE FUNCTION public.servitech_protect_user_security_fields()
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

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgrelid = 'public.users'::regclass
      AND tgname = 'protect_users_security_fields'
      AND NOT tgisinternal
  ) THEN
    CREATE TRIGGER protect_users_security_fields
      BEFORE UPDATE ON public.users
      FOR EACH ROW EXECUTE FUNCTION public.servitech_protect_user_security_fields();
  END IF;
END;
$trigger$;

CREATE FUNCTION public.servitech_protect_queue_admin_fields()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
  IF (SELECT auth.uid()) IS NULL
     AND COALESCE(current_setting('request.jwt.claim.role', TRUE), '') <> 'authenticated' THEN
    RETURN NEW;
  END IF;

  IF public.servitech_is_admin() THEN
    RETURN NEW;
  END IF;

  IF current_setting('servitech.internal_operation', TRUE) = 'customer_cancel' THEN
    IF OLD.user_id = public.servitech_current_user_id()
       AND UPPER(TRIM(OLD.status)) IN ('PENDING', 'PENDING PAYMENT')
       AND UPPER(TRIM(NEW.status)) IN ('CANCELLED', 'CANCELED')
       AND NEW.lifecycle_stage = 'ORDER'
       AND NEW.paid_amount = 0 THEN
      RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Invalid customer cancellation transition.';
  END IF;

  IF NEW.user_id IS DISTINCT FROM OLD.user_id
     OR NEW.queue_code IS DISTINCT FROM OLD.queue_code
     OR NEW.category IS DISTINCT FROM OLD.category
     OR NEW.status IS DISTINCT FROM OLD.status
     OR NEW.paid_amount IS DISTINCT FROM OLD.paid_amount
     OR NEW.lifecycle_stage IS DISTINCT FROM OLD.lifecycle_stage
     OR NEW.queue_cycle_date IS DISTINCT FROM OLD.queue_cycle_date
     OR NEW.daily_sequence IS DISTINCT FROM OLD.daily_sequence
     OR NEW.completed_at IS DISTINCT FROM OLD.completed_at
     OR NEW.closed_at IS DISTINCT FROM OLD.closed_at
     OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
    RAISE EXCEPTION 'Administrative queue fields cannot be changed by customers.';
  END IF;

  IF OLD.customer_edit_required IS NOT TRUE THEN
    RAISE EXCEPTION 'This request is not open for customer editing.';
  END IF;

  IF NEW.customer_edit_required IS DISTINCT FROM FALSE
     OR COALESCE(NEW.send_back_message, '') <> ''
     OR NEW.send_back_at IS NOT NULL
     OR NEW.send_back_by IS NOT NULL THEN
    RAISE EXCEPTION 'Invalid customer resubmission state.';
  END IF;

  RETURN NEW;
END;
$$;

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgrelid = 'public.queues'::regclass
      AND tgname = 'protect_queue_admin_fields'
      AND NOT tgisinternal
  ) THEN
    CREATE TRIGGER protect_queue_admin_fields
      BEFORE UPDATE ON public.queues
      FOR EACH ROW EXECUTE FUNCTION public.servitech_protect_queue_admin_fields();
  END IF;
END;
$trigger$;

CREATE FUNCTION public.servitech_customer_cancel_queue(requested_queue_id BIGINT)
RETURNS TABLE(
  id BIGINT,
  user_id INTEGER,
  queue_code VARCHAR,
  category VARCHAR,
  status VARCHAR,
  price NUMERIC,
  paid_amount NUMERIC,
  details JSONB
)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  caller_user_id INTEGER := public.servitech_current_user_id();
  current_status TEXT;
BEGIN
  IF caller_user_id IS NULL THEN
    RAISE EXCEPTION 'Authentication required.';
  END IF;

  SELECT UPPER(TRIM(q.status))
  INTO current_status
  FROM public.queues q
  WHERE q.id = requested_queue_id
    AND q.user_id = caller_user_id
  FOR UPDATE;

  IF current_status IS NULL THEN
    RAISE EXCEPTION 'Queue not found.';
  END IF;
  IF current_status NOT IN ('PENDING', 'PENDING PAYMENT') THEN
    RAISE EXCEPTION 'Only pending requests can be cancelled by the customer.';
  END IF;

  PERFORM set_config('servitech.internal_operation', 'customer_cancel', TRUE);
  UPDATE public.queues q
  SET status = 'CANCELLED',
      lifecycle_stage = 'ORDER',
      paid_amount = 0,
      closed_at = COALESCE(q.closed_at, NOW()),
      updated_at = NOW()
  WHERE q.id = requested_queue_id
    AND q.user_id = caller_user_id;

  RETURN QUERY
  SELECT q.id, q.user_id, q.queue_code, q.category, q.status,
         q.price, q.paid_amount, q.details
  FROM public.queues q
  WHERE q.id = requested_queue_id;
END;
$$;

REVOKE ALL ON FUNCTION public.servitech_customer_cancel_queue(BIGINT) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.servitech_customer_cancel_queue(BIGINT) TO authenticated, service_role;

CREATE FUNCTION public.servitech_protect_payment_admin_fields()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
  IF (SELECT auth.uid()) IS NULL
     AND COALESCE(current_setting('request.jwt.claim.role', TRUE), '') <> 'authenticated' THEN
    RETURN NEW;
  END IF;

  IF public.servitech_is_admin() THEN
    RETURN NEW;
  END IF;

  IF NEW.queue_id IS DISTINCT FROM OLD.queue_id
     OR NEW.user_id IS DISTINCT FROM OLD.user_id
     OR NEW.status IS DISTINCT FROM OLD.status
     OR NEW.verified_at IS DISTINCT FROM OLD.verified_at
     OR NEW.verified_by IS DISTINCT FROM OLD.verified_by
     OR NEW.verification_notes IS DISTINCT FROM OLD.verification_notes THEN
    RAISE EXCEPTION 'Payment verification fields cannot be changed by customers.';
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM public.queues q
    WHERE q.id = OLD.queue_id
      AND q.user_id = public.servitech_current_user_id()
      AND q.customer_edit_required = TRUE
  ) THEN
    RAISE EXCEPTION 'Payment details are not open for customer editing.';
  END IF;

  RETURN NEW;
END;
$$;

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgrelid = 'public.payments'::regclass
      AND tgname = 'protect_payment_admin_fields'
      AND NOT tgisinternal
  ) THEN
CREATE TRIGGER protect_payment_admin_fields
      BEFORE UPDATE ON public.payments
      FOR EACH ROW EXECUTE FUNCTION public.servitech_protect_payment_admin_fields();
  END IF;
END;
$trigger$;

CREATE FUNCTION public.servitech_protect_notification_fields()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
  IF (SELECT auth.uid()) IS NULL
     AND COALESCE(current_setting('request.jwt.claim.role', TRUE), '') <> 'authenticated' THEN
    RETURN NEW;
  END IF;
  IF public.servitech_is_admin() THEN
    RETURN NEW;
  END IF;
  IF NEW.user_id IS DISTINCT FROM OLD.user_id
     OR NEW.type IS DISTINCT FROM OLD.type
     OR NEW.reference_id IS DISTINCT FROM OLD.reference_id
     OR NEW.message IS DISTINCT FROM OLD.message
     OR NEW.event_key IS DISTINCT FROM OLD.event_key
     OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
    RAISE EXCEPTION 'Notification content cannot be changed by customers.';
  END IF;
  RETURN NEW;
END;
$$;

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgrelid = 'public.notifications'::regclass
      AND tgname = 'protect_notification_fields'
      AND NOT tgisinternal
  ) THEN
    CREATE TRIGGER protect_notification_fields
      BEFORE UPDATE ON public.notifications
      FOR EACH ROW EXECUTE FUNCTION public.servitech_protect_notification_fields();
  END IF;
END;
$trigger$;

CREATE FUNCTION public.servitech_protect_upload_fields()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
  IF (SELECT auth.uid()) IS NULL
     AND COALESCE(current_setting('request.jwt.claim.role', TRUE), '') <> 'authenticated' THEN
    RETURN NEW;
  END IF;
  IF public.servitech_is_admin() THEN
    RETURN NEW;
  END IF;
  IF NEW.user_id IS DISTINCT FROM OLD.user_id
     OR NEW.uploaded_by IS DISTINCT FROM OLD.uploaded_by
     OR NEW.upload_token IS DISTINCT FROM OLD.upload_token
     OR NEW.original_name IS DISTINCT FROM OLD.original_name
     OR NEW.storage_key IS DISTINCT FROM OLD.storage_key
     OR NEW.file_extension IS DISTINCT FROM OLD.file_extension
     OR NEW.mime_type IS DISTINCT FROM OLD.mime_type
     OR NEW.byte_size IS DISTINCT FROM OLD.byte_size
     OR NEW.checksum_sha256 IS DISTINCT FROM OLD.checksum_sha256
     OR NEW.upload_purpose IS DISTINCT FROM OLD.upload_purpose
     OR NEW.visibility IS DISTINCT FROM OLD.visibility
     OR NEW.upload_status IS DISTINCT FROM OLD.upload_status
     OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
    RAISE EXCEPTION 'Stored upload metadata cannot be changed by customers.';
  END IF;
  IF OLD.queue_id IS NOT NULL AND NEW.queue_id IS DISTINCT FROM OLD.queue_id THEN
    RAISE EXCEPTION 'Linked uploads cannot be detached by customers.';
  END IF;
  IF NEW.queue_id IS DISTINCT FROM OLD.queue_id
     AND NEW.queue_id IS NOT NULL
     AND NOT EXISTS (
       SELECT 1 FROM public.queues q
       WHERE q.id = NEW.queue_id AND q.user_id = public.servitech_current_user_id()
     ) THEN
    RAISE EXCEPTION 'Uploads can only be linked to the customer''s own request.';
  END IF;
  IF OLD.payment_id IS NOT NULL AND NEW.payment_id IS DISTINCT FROM OLD.payment_id THEN
    RAISE EXCEPTION 'Linked payment uploads cannot be detached by customers.';
  END IF;
  IF NEW.payment_id IS DISTINCT FROM OLD.payment_id
     AND NEW.payment_id IS NOT NULL
     AND NOT EXISTS (
       SELECT 1 FROM public.payments p
       WHERE p.id = NEW.payment_id AND p.user_id = public.servitech_current_user_id()
     ) THEN
    RAISE EXCEPTION 'Uploads can only be linked to the customer''s own payment.';
  END IF;
  RETURN NEW;
END;
$$;

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgrelid = 'public.uploads'::regclass
      AND tgname = 'protect_upload_fields'
      AND NOT tgisinternal
  ) THEN
    CREATE TRIGGER protect_upload_fields
      BEFORE UPDATE ON public.uploads
      FOR EACH ROW EXECUTE FUNCTION public.servitech_protect_upload_fields();
  END IF;
END;
$trigger$;

CREATE FUNCTION public.servitech_protect_customer_history()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  queue_owner INTEGER;
  queue_status TEXT;
BEGIN
  IF (SELECT auth.uid()) IS NULL
     AND COALESCE(current_setting('request.jwt.claim.role', TRUE), '') <> 'authenticated' THEN
    RETURN NEW;
  END IF;
  IF public.servitech_is_admin() THEN
    RETURN NEW;
  END IF;

  SELECT q.user_id, UPPER(TRIM(q.status))
  INTO queue_owner, queue_status
  FROM public.queues q
  WHERE q.id = NEW.queue_id;

  IF queue_owner IS DISTINCT FROM public.servitech_current_user_id()
     OR NEW.admin_id IS NOT NULL
     OR COALESCE(NEW.admin_name, '') <> ''
     OR UPPER(TRIM(NEW.new_status)) IS DISTINCT FROM queue_status
     OR NEW.action_type NOT IN ('status_change', 'customer_resubmitted') THEN
    RAISE EXCEPTION 'Invalid customer history entry.';
  END IF;
  RETURN NEW;
END;
$$;

DO $trigger$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgrelid = 'public.queue_status_history'::regclass
      AND tgname = 'protect_customer_history'
      AND NOT tgisinternal
  ) THEN
    CREATE TRIGGER protect_customer_history
      BEFORE INSERT ON public.queue_status_history
      FOR EACH ROW EXECUTE FUNCTION public.servitech_protect_customer_history();
  END IF;
END;
$trigger$;

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.queues ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.services ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.announcements ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.queue_status_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.uploads ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.login_attempts ENABLE ROW LEVEL SECURITY;

-- Preserve any legacy customers table but remove it from API/runtime access.
DO $legacy_customers$
BEGIN
  IF to_regclass('public.customers') IS NOT NULL THEN
    EXECUTE 'ALTER TABLE public.customers ENABLE ROW LEVEL SECURITY';
    EXECUTE 'REVOKE ALL ON public.customers FROM anon, authenticated';
  END IF;
END;
$legacy_customers$;

-- Policies are intentionally additive and use stable ServiTech-specific names.
CREATE POLICY servitech_users_select
  ON public.users FOR SELECT TO authenticated
  USING (
    auth_user_id = (SELECT auth.uid())
    OR public.servitech_is_admin()
  );

CREATE POLICY servitech_users_update
  ON public.users FOR UPDATE TO authenticated
  USING (auth_user_id = (SELECT auth.uid()) OR public.servitech_is_admin())
  WITH CHECK (auth_user_id = (SELECT auth.uid()) OR public.servitech_is_admin());

CREATE POLICY servitech_queues_select
  ON public.queues FOR SELECT TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_queues_insert
  ON public.queues FOR INSERT TO authenticated
  WITH CHECK (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_queues_update
  ON public.queues FOR UPDATE TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin())
  WITH CHECK (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_payments_select
  ON public.payments FOR SELECT TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_payments_insert
  ON public.payments FOR INSERT TO authenticated
  WITH CHECK (
    (user_id = public.servitech_current_user_id()
      AND EXISTS (
        SELECT 1 FROM public.queues q
        WHERE q.id = queue_id AND q.user_id = public.servitech_current_user_id()
      ))
    OR public.servitech_is_admin()
  );

CREATE POLICY servitech_payments_update
  ON public.payments FOR UPDATE TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin())
  WITH CHECK (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_notifications_select
  ON public.notifications FOR SELECT TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_notifications_insert
  ON public.notifications FOR INSERT TO authenticated
  WITH CHECK (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_notifications_update
  ON public.notifications FOR UPDATE TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin())
  WITH CHECK (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_services_public_select
  ON public.services FOR SELECT TO anon
  USING (active = TRUE);

CREATE POLICY servitech_services_authenticated_select
  ON public.services FOR SELECT TO authenticated
  USING (active = TRUE OR public.servitech_is_admin());

CREATE POLICY servitech_services_admin_all
  ON public.services FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

CREATE POLICY servitech_announcements_public_select
  ON public.announcements FOR SELECT TO anon
  USING (active = TRUE);

CREATE POLICY servitech_announcements_authenticated_select
  ON public.announcements FOR SELECT TO authenticated
  USING (active = TRUE OR public.servitech_is_admin());

CREATE POLICY servitech_announcements_admin_all
  ON public.announcements FOR ALL TO authenticated
  USING (public.servitech_is_admin())
  WITH CHECK (public.servitech_is_admin());

CREATE POLICY servitech_queue_history_select
  ON public.queue_status_history FOR SELECT TO authenticated
  USING (
    public.servitech_is_admin()
    OR EXISTS (
      SELECT 1 FROM public.queues q
      WHERE q.id = queue_id AND q.user_id = public.servitech_current_user_id()
    )
  );

CREATE POLICY servitech_queue_history_insert
  ON public.queue_status_history FOR INSERT TO authenticated
  WITH CHECK (
    public.servitech_is_admin()
    OR (
      admin_id IS NULL
      AND EXISTS (
        SELECT 1 FROM public.queues q
        WHERE q.id = queue_id AND q.user_id = public.servitech_current_user_id()
      )
    )
  );

CREATE POLICY servitech_uploads_select
  ON public.uploads FOR SELECT TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin());

CREATE POLICY servitech_uploads_insert
  ON public.uploads FOR INSERT TO authenticated
  WITH CHECK (
    user_id = public.servitech_current_user_id()
    AND COALESCE(uploaded_by, user_id) = public.servitech_current_user_id()
    AND (queue_id IS NULL OR EXISTS (
      SELECT 1 FROM public.queues q
      WHERE q.id = queue_id AND q.user_id = public.servitech_current_user_id()
    ))
    AND (payment_id IS NULL OR EXISTS (
      SELECT 1 FROM public.payments p
      WHERE p.id = payment_id AND p.user_id = public.servitech_current_user_id()
    ))
  );

CREATE POLICY servitech_uploads_update
  ON public.uploads FOR UPDATE TO authenticated
  USING (user_id = public.servitech_current_user_id() OR public.servitech_is_admin())
  WITH CHECK (
    user_id = public.servitech_current_user_id()
    OR public.servitech_is_admin()
  );

REVOKE ALL ON public.login_attempts FROM anon, authenticated;

GRANT SELECT ON public.services, public.announcements TO anon;
GRANT SELECT, INSERT, UPDATE ON
  public.users,
  public.queues,
  public.payments,
  public.notifications,
  public.queue_status_history,
  public.uploads
TO authenticated;
GRANT SELECT ON public.services, public.announcements TO authenticated;
GRANT INSERT, UPDATE, DELETE ON public.services, public.announcements TO authenticated;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO authenticated;

COMMIT;
