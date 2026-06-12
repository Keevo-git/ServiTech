-- ServiTech Supabase rewrite foundation.
--
-- WARNING: This is a destructive reset/rebuild script for the public schema
-- tables used by ServiTech. Run only on a fresh project or after an approved
-- data migration/export. It intentionally replaces the legacy custom-auth
-- tables with a Supabase Auth-linked schema.

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;

DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;
DROP TRIGGER IF EXISTS on_auth_user_updated ON auth.users;

DROP TABLE IF EXISTS public.audit_logs CASCADE;
DROP TABLE IF EXISTS public.announcements CASCADE;
DROP TABLE IF EXISTS public.notifications CASCADE;
DROP TABLE IF EXISTS public.request_status_events CASCADE;
DROP TABLE IF EXISTS public.request_edit_submissions CASCADE;
DROP TABLE IF EXISTS public.request_edit_requests CASCADE;
DROP TABLE IF EXISTS public.file_attachments CASCADE;
DROP TABLE IF EXISTS public.payments CASCADE;
DROP TABLE IF EXISTS public.request_financials CASCADE;
DROP TABLE IF EXISTS public.device_service_details CASCADE;
DROP TABLE IF EXISTS public.printing_request_details CASCADE;
DROP TABLE IF EXISTS public.service_requests CASCADE;
DROP TABLE IF EXISTS public.service_price_options CASCADE;
DROP TABLE IF EXISTS public.services CASCADE;
DROP TABLE IF EXISTS public.service_categories CASCADE;
DROP TABLE IF EXISTS public.staff_members CASCADE;
DROP TABLE IF EXISTS public.profiles CASCADE;

DROP TABLE IF EXISTS public.queue_status_history CASCADE;
DROP TABLE IF EXISTS public.uploads CASCADE;
DROP TABLE IF EXISTS public.notifications CASCADE;
DROP TABLE IF EXISTS public.payments CASCADE;
DROP TABLE IF EXISTS public.queues CASCADE;
DROP TABLE IF EXISTS public.services CASCADE;
DROP TABLE IF EXISTS public.announcements CASCADE;
DROP TABLE IF EXISTS public.login_attempts CASCADE;
DROP TABLE IF EXISTS public.users CASCADE;

DROP FUNCTION IF EXISTS public.handle_new_auth_user() CASCADE;
DROP FUNCTION IF EXISTS public.set_updated_at() CASCADE;
DROP FUNCTION IF EXISTS public.protect_customer_profile_fields() CASCADE;
DROP FUNCTION IF EXISTS public.set_request_category_from_service() CASCADE;
DROP FUNCTION IF EXISTS public.set_request_closure_timestamps() CASCADE;
DROP FUNCTION IF EXISTS public.validate_printing_request_detail_kind() CASCADE;
DROP FUNCTION IF EXISTS public.validate_device_service_detail_kind() CASCADE;
DROP FUNCTION IF EXISTS public.set_edit_submission_request_id() CASCADE;
DROP FUNCTION IF EXISTS public.sync_auth_user_profile() CASCADE;
DROP FUNCTION IF EXISTS public.is_staff() CASCADE;
DROP FUNCTION IF EXISTS public.is_admin() CASCADE;
DROP FUNCTION IF EXISTS public.can_manage_services() CASCADE;
DROP FUNCTION IF EXISTS public.can_manage_requests() CASCADE;

DROP TYPE IF EXISTS public.audit_action CASCADE;
DROP TYPE IF EXISTS public.announcement_status CASCADE;
DROP TYPE IF EXISTS public.notification_type CASCADE;
DROP TYPE IF EXISTS public.edit_request_status CASCADE;
DROP TYPE IF EXISTS public.attachment_status CASCADE;
DROP TYPE IF EXISTS public.payment_status CASCADE;
DROP TYPE IF EXISTS public.payment_method CASCADE;
DROP TYPE IF EXISTS public.request_status CASCADE;
DROP TYPE IF EXISTS public.request_lifecycle CASCADE;
DROP TYPE IF EXISTS public.request_channel CASCADE;
DROP TYPE IF EXISTS public.service_kind CASCADE;
DROP TYPE IF EXISTS public.service_category_code CASCADE;
DROP TYPE IF EXISTS public.account_status CASCADE;
DROP TYPE IF EXISTS public.app_role CASCADE;

CREATE TYPE public.app_role AS ENUM (
  'customer',
  'staff',
  'request_manager',
  'service_manager',
  'cashier',
  'admin'
);

CREATE TYPE public.account_status AS ENUM (
  'active',
  'restricted',
  'suspended',
  'deleted'
);

CREATE TYPE public.service_category_code AS ENUM (
  'printing',
  'repair',
  'installation'
);

CREATE TYPE public.service_kind AS ENUM (
  'document_printing',
  'xerox',
  'rush_id',
  'laminating',
  'repair',
  'installation',
  'manual_assessment'
);

CREATE TYPE public.request_channel AS ENUM (
  'online',
  'walk_in'
);

CREATE TYPE public.request_lifecycle AS ENUM (
  'queue',
  'order',
  'archived'
);

CREATE TYPE public.request_status AS ENUM (
  'pending',
  'approved',
  'ongoing',
  'for_pick_up',
  'done',
  'cancelled'
);

CREATE TYPE public.payment_method AS ENUM (
  'cash',
  'gcash'
);

CREATE TYPE public.payment_status AS ENUM (
  'submitted',
  'pending_review',
  'verified',
  'rejected',
  'voided'
);

CREATE TYPE public.attachment_status AS ENUM (
  'uploaded',
  'linked',
  'deleted'
);

CREATE TYPE public.edit_request_status AS ENUM (
  'open',
  'resolved',
  'cancelled'
);

CREATE TYPE public.notification_type AS ENUM (
  'general',
  'status_update',
  'price_update',
  'payment_review',
  'send_back',
  'customer_resubmitted',
  'admin_new_request',
  'admin_cancelled',
  'announcement'
);

CREATE TYPE public.announcement_status AS ENUM (
  'draft',
  'published',
  'scheduled',
  'archived'
);

CREATE TYPE public.audit_action AS ENUM (
  'profile_updated',
  'staff_updated',
  'service_created',
  'service_updated',
  'service_deleted',
  'request_created',
  'request_status_changed',
  'request_payment_updated',
  'request_sent_back',
  'customer_resubmitted',
  'request_cancelled',
  'request_completed',
  'payment_submitted',
  'payment_verified',
  'payment_rejected',
  'file_uploaded',
  'file_deleted',
  'notification_sent',
  'announcement_created',
  'announcement_updated',
  'announcement_archived',
  'security_event'
);

CREATE TABLE public.profiles (
  user_id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  email CITEXT NOT NULL UNIQUE,
  full_name TEXT NOT NULL,
  phone TEXT NULL,
  avatar_url TEXT NULL,
  account_status public.account_status NOT NULL DEFAULT 'active',
  consent_accepted_at TIMESTAMPTZ NULL,
  consent_version TEXT NULL,
  last_seen_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT profiles_full_name_not_blank CHECK (LENGTH(BTRIM(full_name)) > 0),
  CONSTRAINT profiles_phone_format CHECK (phone IS NULL OR phone ~ '^\+639[0-9]{9}$')
);

CREATE TABLE public.staff_members (
  user_id UUID PRIMARY KEY REFERENCES public.profiles(user_id) ON DELETE CASCADE,
  staff_role public.app_role NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  invited_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  created_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT staff_members_role_not_customer CHECK (staff_role <> 'customer')
);

CREATE TABLE public.service_categories (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  code public.service_category_code NOT NULL UNIQUE,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT service_categories_name_not_blank CHECK (LENGTH(BTRIM(name)) > 0)
);

CREATE TABLE public.services (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  category_id UUID NOT NULL REFERENCES public.service_categories(id) ON DELETE RESTRICT,
  slug TEXT NOT NULL UNIQUE,
  kind public.service_kind NOT NULL,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  base_price NUMERIC(12, 2) NULL,
  price_min NUMERIC(12, 2) NULL,
  price_max NUMERIC(12, 2) NULL,
  requires_file_upload BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  updated_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT services_slug_format CHECK (slug ~ '^[a-z0-9]+(?:-[a-z0-9]+)*$'),
  CONSTRAINT services_name_not_blank CHECK (LENGTH(BTRIM(name)) > 0),
  CONSTRAINT services_base_price_non_negative CHECK (base_price IS NULL OR base_price >= 0),
  CONSTRAINT services_price_min_non_negative CHECK (price_min IS NULL OR price_min >= 0),
  CONSTRAINT services_price_max_non_negative CHECK (price_max IS NULL OR price_max >= 0),
  CONSTRAINT services_price_range_valid CHECK (
    price_min IS NULL OR price_max IS NULL OR price_max >= price_min
  )
);

CREATE TABLE public.service_price_options (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  service_id UUID NOT NULL REFERENCES public.services(id) ON DELETE CASCADE,
  option_group TEXT NOT NULL,
  option_code TEXT NOT NULL,
  label TEXT NOT NULL,
  unit_price NUMERIC(12, 2) NULL,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT service_price_options_code_format CHECK (option_code ~ '^[a-z0-9_]+$'),
  CONSTRAINT service_price_options_group_not_blank CHECK (LENGTH(BTRIM(option_group)) > 0),
  CONSTRAINT service_price_options_label_not_blank CHECK (LENGTH(BTRIM(label)) > 0),
  CONSTRAINT service_price_options_unit_price_non_negative CHECK (unit_price IS NULL OR unit_price >= 0),
  CONSTRAINT service_price_options_unique_option UNIQUE (service_id, option_group, option_code)
);

CREATE TABLE public.service_requests (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  request_number TEXT NOT NULL UNIQUE,
  customer_id UUID NOT NULL REFERENCES public.profiles(user_id) ON DELETE RESTRICT,
  category_id UUID NOT NULL REFERENCES public.service_categories(id) ON DELETE RESTRICT,
  service_id UUID NOT NULL REFERENCES public.services(id) ON DELETE RESTRICT,
  channel public.request_channel NOT NULL,
  lifecycle public.request_lifecycle NOT NULL DEFAULT 'queue',
  status public.request_status NOT NULL DEFAULT 'pending',
  queue_date DATE NOT NULL DEFAULT ((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date),
  daily_sequence INTEGER NOT NULL,
  customer_edit_required BOOLEAN NOT NULL DEFAULT FALSE,
  submitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  approved_at TIMESTAMPTZ NULL,
  completed_at TIMESTAMPTZ NULL,
  cancelled_at TIMESTAMPTZ NULL,
  closed_at TIMESTAMPTZ NULL,
  cancelled_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  cancellation_reason TEXT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT service_requests_number_format CHECK (request_number ~ '^[A-Z]{1,3}[0-9]{8}-[0-9]{4}$'),
  CONSTRAINT service_requests_sequence_positive CHECK (daily_sequence > 0),
  CONSTRAINT service_requests_cancel_reason_required CHECK (
    status <> 'cancelled' OR LENGTH(BTRIM(COALESCE(cancellation_reason, ''))) > 0
  ),
  CONSTRAINT service_requests_daily_unique UNIQUE (queue_date, category_id, channel, daily_sequence)
);

CREATE TABLE public.printing_request_details (
  request_id UUID PRIMARY KEY REFERENCES public.service_requests(id) ON DELETE CASCADE,
  print_kind public.service_kind NOT NULL,
  paper_size TEXT NULL,
  color_option TEXT NULL,
  quantity INTEGER NOT NULL DEFAULT 1,
  package_label TEXT NULL,
  lamination_type TEXT NULL,
  total_files INTEGER NOT NULL DEFAULT 0,
  total_images INTEGER NOT NULL DEFAULT 0,
  total_pages INTEGER NOT NULL DEFAULT 0,
  unit_price NUMERIC(12, 2) NULL,
  estimated_total NUMERIC(12, 2) NULL,
  file_analysis JSONB NOT NULL DEFAULT '[]'::jsonb,
  notes TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT printing_request_details_kind_check CHECK (
    print_kind IN ('document_printing', 'xerox', 'rush_id', 'laminating')
  ),
  CONSTRAINT printing_request_details_quantity_positive CHECK (quantity > 0),
  CONSTRAINT printing_request_details_file_counts_non_negative CHECK (
    total_files >= 0 AND total_images >= 0 AND total_pages >= 0
  ),
  CONSTRAINT printing_request_details_money_non_negative CHECK (
    (unit_price IS NULL OR unit_price >= 0) AND
    (estimated_total IS NULL OR estimated_total >= 0)
  ),
  CONSTRAINT printing_request_details_document_fields CHECK (
    print_kind <> 'document_printing'
    OR (
      LENGTH(BTRIM(COALESCE(paper_size, ''))) > 0
      AND LENGTH(BTRIM(COALESCE(color_option, ''))) > 0
      AND total_pages > 0
    )
  ),
  CONSTRAINT printing_request_details_xerox_fields CHECK (
    print_kind <> 'xerox'
    OR LENGTH(BTRIM(COALESCE(paper_size, ''))) > 0
  ),
  CONSTRAINT printing_request_details_rush_id_fields CHECK (
    print_kind <> 'rush_id'
    OR LENGTH(BTRIM(COALESCE(package_label, ''))) > 0
  ),
  CONSTRAINT printing_request_details_laminating_fields CHECK (
    print_kind <> 'laminating'
    OR LOWER(BTRIM(COALESCE(lamination_type, ''))) IN ('thin', 'thick')
  )
);

CREATE TABLE public.device_service_details (
  request_id UUID PRIMARY KEY REFERENCES public.service_requests(id) ON DELETE CASCADE,
  device_type TEXT NOT NULL,
  device_brand TEXT NULL,
  device_model TEXT NULL,
  issue_description TEXT NULL,
  notes TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT device_service_details_device_type_not_blank CHECK (LENGTH(BTRIM(device_type)) > 0)
);

CREATE TABLE public.request_financials (
  request_id UUID PRIMARY KEY REFERENCES public.service_requests(id) ON DELETE CASCADE,
  quoted_amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
  paid_amount NUMERIC(12, 2) NOT NULL DEFAULT 0,
  pending_amount NUMERIC(12, 2) GENERATED ALWAYS AS (
    GREATEST(quoted_amount - paid_amount, 0::numeric)
  ) STORED,
  currency CHAR(3) NOT NULL DEFAULT 'PHP',
  quoted_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  quoted_at TIMESTAMPTZ NULL,
  updated_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT request_financials_amounts_valid CHECK (
    quoted_amount >= 0 AND paid_amount >= 0 AND paid_amount <= quoted_amount
  ),
  CONSTRAINT request_financials_currency_upper CHECK (currency = UPPER(currency))
);

CREATE TABLE public.payments (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  request_id UUID NOT NULL REFERENCES public.service_requests(id) ON DELETE CASCADE,
  customer_id UUID NOT NULL REFERENCES public.profiles(user_id) ON DELETE RESTRICT,
  method public.payment_method NOT NULL,
  amount NUMERIC(12, 2) NOT NULL,
  gcash_reference TEXT NULL,
  status public.payment_status NOT NULL DEFAULT 'submitted',
  submitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  verified_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  verified_at TIMESTAMPTZ NULL,
  rejected_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  rejected_at TIMESTAMPTZ NULL,
  rejection_reason TEXT NULL,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT payments_amount_positive CHECK (amount > 0),
  CONSTRAINT payments_gcash_reference_required CHECK (
    method <> 'gcash' OR gcash_reference ~ '^[0-9]{13}$'
  ),
  CONSTRAINT payments_rejection_reason_required CHECK (
    status <> 'rejected' OR LENGTH(BTRIM(COALESCE(rejection_reason, ''))) > 0
  )
);

CREATE TABLE public.file_attachments (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  owner_id UUID NOT NULL REFERENCES public.profiles(user_id) ON DELETE RESTRICT,
  request_id UUID NULL REFERENCES public.service_requests(id) ON DELETE SET NULL,
  upload_token TEXT NOT NULL UNIQUE DEFAULT ENCODE(gen_random_bytes(32), 'hex'),
  original_filename TEXT NOT NULL,
  storage_bucket TEXT NOT NULL DEFAULT 'private_uploads',
  storage_path TEXT NOT NULL UNIQUE,
  file_extension TEXT NOT NULL,
  mime_type TEXT NOT NULL,
  byte_size BIGINT NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  status public.attachment_status NOT NULL DEFAULT 'uploaded',
  uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  linked_at TIMESTAMPTZ NULL,
  deleted_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT file_attachments_filename_not_blank CHECK (LENGTH(BTRIM(original_filename)) > 0),
  CONSTRAINT file_attachments_extension_format CHECK (file_extension ~ '^[a-z0-9]+$'),
  CONSTRAINT file_attachments_byte_size_positive CHECK (byte_size > 0),
  CONSTRAINT file_attachments_token_format CHECK (upload_token ~ '^[0-9a-f]{64}$'),
  CONSTRAINT file_attachments_checksum_format CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$')
);

CREATE TABLE public.request_edit_requests (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  request_id UUID NOT NULL REFERENCES public.service_requests(id) ON DELETE CASCADE,
  requested_by UUID NOT NULL REFERENCES public.profiles(user_id) ON DELETE RESTRICT,
  message TEXT NOT NULL,
  status public.edit_request_status NOT NULL DEFAULT 'open',
  requested_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  resolved_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  resolved_at TIMESTAMPTZ NULL,
  cancelled_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  cancelled_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT request_edit_requests_message_not_blank CHECK (LENGTH(BTRIM(message)) > 0)
);

CREATE TABLE public.request_edit_submissions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  edit_request_id UUID NOT NULL REFERENCES public.request_edit_requests(id) ON DELETE CASCADE,
  request_id UUID NOT NULL REFERENCES public.service_requests(id) ON DELETE CASCADE,
  submitted_by UUID NOT NULL REFERENCES public.profiles(user_id) ON DELETE RESTRICT,
  change_summary TEXT NOT NULL,
  before_details JSONB NOT NULL DEFAULT '{}'::jsonb,
  after_details JSONB NOT NULL DEFAULT '{}'::jsonb,
  submitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT request_edit_submissions_summary_not_blank CHECK (LENGTH(BTRIM(change_summary)) > 0)
);

CREATE TABLE public.request_status_events (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  request_id UUID NOT NULL REFERENCES public.service_requests(id) ON DELETE CASCADE,
  actor_id UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  action public.audit_action NOT NULL,
  old_status public.request_status NULL,
  new_status public.request_status NULL,
  notes TEXT NOT NULL DEFAULT '',
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE public.notifications (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  recipient_id UUID NOT NULL REFERENCES public.profiles(user_id) ON DELETE CASCADE,
  actor_id UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  request_id UUID NULL REFERENCES public.service_requests(id) ON DELETE CASCADE,
  type public.notification_type NOT NULL DEFAULT 'general',
  event_key TEXT NULL,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  read_at TIMESTAMPTZ NULL,
  archived_at TIMESTAMPTZ NULL,
  deleted_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT notifications_title_not_blank CHECK (LENGTH(BTRIM(title)) > 0),
  CONSTRAINT notifications_message_not_blank CHECK (LENGTH(BTRIM(message)) > 0)
);

CREATE TABLE public.announcements (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  status public.announcement_status NOT NULL DEFAULT 'draft',
  published_by UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  published_at TIMESTAMPTZ NULL,
  starts_at TIMESTAMPTZ NULL,
  ends_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT announcements_title_not_blank CHECK (LENGTH(BTRIM(title)) > 0),
  CONSTRAINT announcements_message_not_blank CHECK (LENGTH(BTRIM(message)) > 0),
  CONSTRAINT announcements_schedule_order CHECK (
    starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at
  )
);

CREATE TABLE public.audit_logs (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_id UUID NULL REFERENCES public.profiles(user_id) ON DELETE SET NULL,
  action public.audit_action NOT NULL,
  entity_table TEXT NOT NULL,
  entity_id UUID NULL,
  request_id UUID NULL REFERENCES public.service_requests(id) ON DELETE SET NULL,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  ip_address INET NULL,
  user_agent TEXT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  CONSTRAINT audit_logs_entity_table_not_blank CHECK (LENGTH(BTRIM(entity_table)) > 0)
);

CREATE INDEX idx_profiles_account_status ON public.profiles (account_status);
CREATE INDEX idx_staff_members_role_active ON public.staff_members (staff_role, is_active);
CREATE INDEX idx_service_categories_active_sort ON public.service_categories (is_active, sort_order, code);
CREATE INDEX idx_services_category_active_sort ON public.services (category_id, is_active, sort_order, name);
CREATE INDEX idx_services_kind_active ON public.services (kind, is_active);
CREATE INDEX idx_service_price_options_service_sort ON public.service_price_options (service_id, is_active, sort_order);
CREATE INDEX idx_service_requests_customer_created ON public.service_requests (customer_id, created_at DESC);
CREATE INDEX idx_service_requests_ops_queue ON public.service_requests (lifecycle, status, category_id, queue_date, daily_sequence);
CREATE INDEX idx_service_requests_service_created ON public.service_requests (service_id, created_at DESC);
CREATE INDEX idx_service_requests_closed_file_retention ON public.service_requests (closed_at)
  WHERE status IN ('done', 'cancelled');
CREATE INDEX idx_printing_request_details_kind ON public.printing_request_details (print_kind);
CREATE INDEX idx_request_financials_pending ON public.request_financials (pending_amount) WHERE pending_amount > 0;
CREATE INDEX idx_payments_request_created ON public.payments (request_id, created_at DESC);
CREATE INDEX idx_payments_status_created ON public.payments (status, created_at DESC);
CREATE INDEX idx_file_attachments_owner_created ON public.file_attachments (owner_id, created_at DESC);
CREATE INDEX idx_file_attachments_request_created ON public.file_attachments (request_id, created_at DESC);
CREATE INDEX idx_file_attachments_orphan_cleanup ON public.file_attachments (created_at)
  WHERE request_id IS NULL AND deleted_at IS NULL;
CREATE INDEX idx_file_attachments_linked_retention ON public.file_attachments (request_id, deleted_at)
  WHERE request_id IS NOT NULL AND deleted_at IS NULL;
CREATE UNIQUE INDEX idx_request_edit_requests_one_open
  ON public.request_edit_requests (request_id)
  WHERE status = 'open';
CREATE INDEX idx_request_edit_submissions_request ON public.request_edit_submissions (request_id, submitted_at DESC);
CREATE INDEX idx_request_status_events_request_created ON public.request_status_events (request_id, created_at DESC);
CREATE INDEX idx_notifications_recipient_created ON public.notifications (recipient_id, created_at DESC);
CREATE INDEX idx_notifications_recipient_unread ON public.notifications (recipient_id, created_at DESC)
  WHERE is_read = FALSE AND deleted_at IS NULL;
CREATE UNIQUE INDEX idx_notifications_active_event_key
  ON public.notifications (recipient_id, event_key)
  WHERE event_key IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX idx_announcements_public ON public.announcements (status, starts_at, ends_at, created_at DESC);
CREATE INDEX idx_audit_logs_entity ON public.audit_logs (entity_table, entity_id, created_at DESC);
CREATE INDEX idx_audit_logs_request ON public.audit_logs (request_id, created_at DESC);
CREATE INDEX idx_audit_logs_actor ON public.audit_logs (actor_id, created_at DESC);

CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$;

CREATE TRIGGER set_profiles_updated_at BEFORE UPDATE ON public.profiles
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_staff_members_updated_at BEFORE UPDATE ON public.staff_members
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_service_categories_updated_at BEFORE UPDATE ON public.service_categories
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_services_updated_at BEFORE UPDATE ON public.services
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_service_price_options_updated_at BEFORE UPDATE ON public.service_price_options
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_service_requests_updated_at BEFORE UPDATE ON public.service_requests
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_printing_request_details_updated_at BEFORE UPDATE ON public.printing_request_details
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_device_service_details_updated_at BEFORE UPDATE ON public.device_service_details
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_request_financials_updated_at BEFORE UPDATE ON public.request_financials
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_payments_updated_at BEFORE UPDATE ON public.payments
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_file_attachments_updated_at BEFORE UPDATE ON public.file_attachments
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_request_edit_requests_updated_at BEFORE UPDATE ON public.request_edit_requests
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE TRIGGER set_announcements_updated_at BEFORE UPDATE ON public.announcements
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION public.set_request_closure_timestamps()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
  IF NEW.status = 'done' THEN
    NEW.completed_at = COALESCE(NEW.completed_at, NOW());
    NEW.closed_at = COALESCE(NEW.closed_at, NEW.completed_at, NOW());
  ELSIF NEW.status = 'cancelled' THEN
    NEW.cancelled_at = COALESCE(NEW.cancelled_at, NOW());
    NEW.closed_at = COALESCE(NEW.closed_at, NEW.cancelled_at, NOW());
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER set_request_closure_timestamps
  BEFORE INSERT OR UPDATE OF status ON public.service_requests
  FOR EACH ROW EXECUTE FUNCTION public.set_request_closure_timestamps();

CREATE OR REPLACE FUNCTION public.is_staff()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT EXISTS (
    SELECT 1
    FROM public.staff_members sm
    WHERE sm.user_id = auth.uid()
      AND sm.is_active = TRUE
  );
$$;

CREATE OR REPLACE FUNCTION public.is_admin()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT EXISTS (
    SELECT 1
    FROM public.staff_members sm
    WHERE sm.user_id = auth.uid()
      AND sm.is_active = TRUE
      AND sm.staff_role = 'admin'
  );
$$;

CREATE OR REPLACE FUNCTION public.can_manage_services()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT EXISTS (
    SELECT 1
    FROM public.staff_members sm
    WHERE sm.user_id = auth.uid()
      AND sm.is_active = TRUE
      AND sm.staff_role IN ('admin', 'service_manager')
  );
$$;

CREATE OR REPLACE FUNCTION public.can_manage_requests()
RETURNS BOOLEAN
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT EXISTS (
    SELECT 1
    FROM public.staff_members sm
    WHERE sm.user_id = auth.uid()
      AND sm.is_active = TRUE
      AND sm.staff_role IN ('admin', 'staff', 'request_manager', 'cashier')
  );
$$;

CREATE OR REPLACE FUNCTION public.protect_customer_profile_fields()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  IF auth.uid() IS NULL OR public.is_staff() THEN
    RETURN NEW;
  END IF;

  IF OLD.user_id = auth.uid() THEN
    IF NEW.email IS DISTINCT FROM OLD.email
       OR NEW.account_status IS DISTINCT FROM OLD.account_status
       OR NEW.consent_accepted_at IS DISTINCT FROM OLD.consent_accepted_at
       OR NEW.consent_version IS DISTINCT FROM OLD.consent_version THEN
      RAISE EXCEPTION 'Only profile display fields may be updated by the customer.';
    END IF;
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER protect_customer_profile_fields
  BEFORE UPDATE ON public.profiles
  FOR EACH ROW EXECUTE FUNCTION public.protect_customer_profile_fields();

CREATE OR REPLACE FUNCTION public.set_request_category_from_service()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  resolved_category_id UUID;
BEGIN
  SELECT s.category_id
    INTO resolved_category_id
  FROM public.services s
  WHERE s.id = NEW.service_id;

  IF resolved_category_id IS NULL THEN
    RAISE EXCEPTION 'Selected service does not exist.';
  END IF;

  NEW.category_id = resolved_category_id;
  RETURN NEW;
END;
$$;

CREATE TRIGGER set_request_category_from_service
  BEFORE INSERT OR UPDATE OF service_id ON public.service_requests
  FOR EACH ROW EXECUTE FUNCTION public.set_request_category_from_service();

CREATE OR REPLACE FUNCTION public.validate_printing_request_detail_kind()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  resolved_kind public.service_kind;
BEGIN
  SELECT s.kind
    INTO resolved_kind
  FROM public.service_requests sr
  JOIN public.services s ON s.id = sr.service_id
  WHERE sr.id = NEW.request_id;

  IF resolved_kind IS NULL THEN
    RAISE EXCEPTION 'Request does not exist.';
  END IF;

  IF resolved_kind NOT IN ('document_printing', 'xerox', 'rush_id', 'laminating') THEN
    RAISE EXCEPTION 'Printing details can only be attached to printing service requests.';
  END IF;

  IF NEW.print_kind <> resolved_kind THEN
    RAISE EXCEPTION 'Printing detail type must match the selected service.';
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER validate_printing_request_detail_kind
  BEFORE INSERT OR UPDATE OF request_id, print_kind ON public.printing_request_details
  FOR EACH ROW EXECUTE FUNCTION public.validate_printing_request_detail_kind();

CREATE OR REPLACE FUNCTION public.validate_device_service_detail_kind()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  resolved_kind public.service_kind;
BEGIN
  SELECT s.kind
    INTO resolved_kind
  FROM public.service_requests sr
  JOIN public.services s ON s.id = sr.service_id
  WHERE sr.id = NEW.request_id;

  IF resolved_kind IS NULL THEN
    RAISE EXCEPTION 'Request does not exist.';
  END IF;

  IF resolved_kind NOT IN ('repair', 'installation', 'manual_assessment') THEN
    RAISE EXCEPTION 'Device details can only be attached to repair or installation requests.';
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER validate_device_service_detail_kind
  BEFORE INSERT OR UPDATE OF request_id ON public.device_service_details
  FOR EACH ROW EXECUTE FUNCTION public.validate_device_service_detail_kind();

CREATE OR REPLACE FUNCTION public.set_edit_submission_request_id()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  resolved_request_id UUID;
BEGIN
  SELECT er.request_id
    INTO resolved_request_id
  FROM public.request_edit_requests er
  WHERE er.id = NEW.edit_request_id;

  IF resolved_request_id IS NULL THEN
    RAISE EXCEPTION 'Edit request does not exist.';
  END IF;

  IF NEW.request_id IS NOT NULL AND NEW.request_id <> resolved_request_id THEN
    RAISE EXCEPTION 'Edit submission request does not match the edit request.';
  END IF;

  NEW.request_id = resolved_request_id;
  RETURN NEW;
END;
$$;

CREATE TRIGGER set_edit_submission_request_id
  BEFORE INSERT OR UPDATE OF edit_request_id, request_id ON public.request_edit_submissions
  FOR EACH ROW EXECUTE FUNCTION public.set_edit_submission_request_id();

CREATE OR REPLACE FUNCTION public.handle_new_auth_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  INSERT INTO public.profiles (
    user_id,
    email,
    full_name,
    phone
  )
  VALUES (
    NEW.id,
    COALESCE(NEW.email, NEW.id::text || '@missing-email.local')::citext,
    COALESCE(NULLIF(NEW.raw_user_meta_data->>'full_name', ''), NULLIF(NEW.raw_user_meta_data->>'name', ''), 'ServiTech Customer'),
    NULLIF(NEW.raw_user_meta_data->>'phone', '')
  )
  ON CONFLICT (user_id) DO NOTHING;

  RETURN NEW;
END;
$$;

CREATE TRIGGER on_auth_user_created
  AFTER INSERT ON auth.users
  FOR EACH ROW EXECUTE FUNCTION public.handle_new_auth_user();

CREATE OR REPLACE FUNCTION public.sync_auth_user_profile()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  IF NEW.email IS DISTINCT FROM OLD.email AND NEW.email IS NOT NULL THEN
    UPDATE public.profiles
    SET email = NEW.email::citext,
        updated_at = NOW()
    WHERE user_id = NEW.id;
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER on_auth_user_updated
  AFTER UPDATE OF email ON auth.users
  FOR EACH ROW EXECUTE FUNCTION public.sync_auth_user_profile();

CREATE OR REPLACE FUNCTION public.request_prefix(
  category_code public.service_category_code,
  request_channel public.request_channel
)
RETURNS TEXT
LANGUAGE sql
IMMUTABLE
AS $$
  SELECT CASE
    WHEN category_code = 'printing' AND request_channel = 'online' THEN 'OP'
    WHEN category_code = 'printing' AND request_channel = 'walk_in' THEN 'P'
    WHEN category_code = 'repair' THEN 'R'
    WHEN category_code = 'installation' THEN 'I'
    ELSE 'Q'
  END;
$$;

CREATE OR REPLACE FUNCTION public.next_service_request_number(
  category_code public.service_category_code,
  request_channel public.request_channel
)
RETURNS TABLE (
  request_number TEXT,
  queue_date DATE,
  daily_sequence INTEGER
)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  target_category_id UUID;
  cycle_date DATE := (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date;
  next_sequence INTEGER;
  prefix TEXT;
BEGIN
  SELECT sc.id
    INTO target_category_id
  FROM public.service_categories sc
  WHERE sc.code = category_code;

  IF target_category_id IS NULL THEN
    RAISE EXCEPTION 'Unknown service category.';
  END IF;

  LOCK TABLE public.service_requests IN EXCLUSIVE MODE;

  SELECT COALESCE(MAX(sr.daily_sequence), 0) + 1
    INTO next_sequence
  FROM public.service_requests sr
  WHERE sr.category_id = target_category_id
    AND sr.channel = request_channel
    AND sr.queue_date = cycle_date;

  prefix := public.request_prefix(category_code, request_channel);

  request_number := prefix || TO_CHAR(cycle_date, 'YYYYMMDD') || '-' || LPAD(next_sequence::text, 4, '0');
  queue_date := cycle_date;
  daily_sequence := next_sequence;
  RETURN NEXT;
END;
$$;

ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.staff_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.service_categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.services ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.service_price_options ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.service_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.printing_request_details ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.device_service_details ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.request_financials ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.file_attachments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.request_edit_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.request_edit_submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.request_status_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.announcements ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.audit_logs ENABLE ROW LEVEL SECURITY;

CREATE POLICY profiles_select_self_or_staff ON public.profiles
  FOR SELECT TO authenticated
  USING (user_id = auth.uid() OR public.is_staff());

CREATE POLICY profiles_insert_self ON public.profiles
  FOR INSERT TO authenticated
  WITH CHECK (user_id = auth.uid());

CREATE POLICY profiles_update_self_or_staff ON public.profiles
  FOR UPDATE TO authenticated
  USING (user_id = auth.uid() OR public.is_staff())
  WITH CHECK (user_id = auth.uid() OR public.is_staff());

CREATE POLICY staff_select_self_or_admin ON public.staff_members
  FOR SELECT TO authenticated
  USING (user_id = auth.uid() OR public.is_admin());

CREATE POLICY staff_admin_manage ON public.staff_members
  FOR ALL TO authenticated
  USING (public.is_admin())
  WITH CHECK (public.is_admin());

CREATE POLICY service_categories_public_read ON public.service_categories
  FOR SELECT TO anon, authenticated
  USING (is_active = TRUE OR public.can_manage_services());

CREATE POLICY service_categories_staff_manage ON public.service_categories
  FOR ALL TO authenticated
  USING (public.can_manage_services())
  WITH CHECK (public.can_manage_services());

CREATE POLICY services_public_read ON public.services
  FOR SELECT TO anon, authenticated
  USING (is_active = TRUE OR public.can_manage_services());

CREATE POLICY services_staff_manage ON public.services
  FOR ALL TO authenticated
  USING (public.can_manage_services())
  WITH CHECK (public.can_manage_services());

CREATE POLICY service_price_options_public_read ON public.service_price_options
  FOR SELECT TO anon, authenticated
  USING (
    is_active = TRUE
    AND EXISTS (
      SELECT 1 FROM public.services s
      WHERE s.id = service_price_options.service_id
        AND s.is_active = TRUE
    )
    OR public.can_manage_services()
  );

CREATE POLICY service_price_options_staff_manage ON public.service_price_options
  FOR ALL TO authenticated
  USING (public.can_manage_services())
  WITH CHECK (public.can_manage_services());

CREATE POLICY service_requests_customer_select ON public.service_requests
  FOR SELECT TO authenticated
  USING (customer_id = auth.uid() OR public.can_manage_requests());

CREATE POLICY service_requests_customer_insert ON public.service_requests
  FOR INSERT TO authenticated
  WITH CHECK (
    customer_id = auth.uid()
    AND EXISTS (
      SELECT 1 FROM public.services s
      WHERE s.id = service_requests.service_id
        AND s.is_active = TRUE
    )
  );

CREATE POLICY service_requests_staff_manage ON public.service_requests
  FOR ALL TO authenticated
  USING (public.can_manage_requests())
  WITH CHECK (public.can_manage_requests());

CREATE POLICY printing_details_customer_select ON public.printing_request_details
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = printing_request_details.request_id
        AND (sr.customer_id = auth.uid() OR public.can_manage_requests())
    )
  );

CREATE POLICY printing_details_customer_insert ON public.printing_request_details
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = printing_request_details.request_id
        AND sr.customer_id = auth.uid()
    )
  );

CREATE POLICY printing_details_customer_update_after_send_back ON public.printing_request_details
  FOR UPDATE TO authenticated
  USING (
    EXISTS (
      SELECT 1
      FROM public.service_requests sr
      JOIN public.request_edit_requests er ON er.request_id = sr.id
      WHERE sr.id = printing_request_details.request_id
        AND sr.customer_id = auth.uid()
        AND er.status = 'open'
        AND sr.status IN ('pending', 'approved')
    )
    OR public.can_manage_requests()
  )
  WITH CHECK (
    EXISTS (
      SELECT 1
      FROM public.service_requests sr
      JOIN public.request_edit_requests er ON er.request_id = sr.id
      WHERE sr.id = printing_request_details.request_id
        AND sr.customer_id = auth.uid()
        AND er.status = 'open'
        AND sr.status IN ('pending', 'approved')
    )
    OR public.can_manage_requests()
  );

CREATE POLICY device_details_customer_select ON public.device_service_details
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = device_service_details.request_id
        AND (sr.customer_id = auth.uid() OR public.can_manage_requests())
    )
  );

CREATE POLICY device_details_customer_insert ON public.device_service_details
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = device_service_details.request_id
        AND sr.customer_id = auth.uid()
    )
  );

CREATE POLICY device_details_customer_update_after_send_back ON public.device_service_details
  FOR UPDATE TO authenticated
  USING (
    EXISTS (
      SELECT 1
      FROM public.service_requests sr
      JOIN public.request_edit_requests er ON er.request_id = sr.id
      WHERE sr.id = device_service_details.request_id
        AND sr.customer_id = auth.uid()
        AND er.status = 'open'
        AND sr.status IN ('pending', 'approved')
    )
    OR public.can_manage_requests()
  )
  WITH CHECK (
    EXISTS (
      SELECT 1
      FROM public.service_requests sr
      JOIN public.request_edit_requests er ON er.request_id = sr.id
      WHERE sr.id = device_service_details.request_id
        AND sr.customer_id = auth.uid()
        AND er.status = 'open'
        AND sr.status IN ('pending', 'approved')
    )
    OR public.can_manage_requests()
  );

CREATE POLICY request_financials_customer_select ON public.request_financials
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = request_financials.request_id
        AND (sr.customer_id = auth.uid() OR public.can_manage_requests())
    )
  );

CREATE POLICY request_financials_staff_manage ON public.request_financials
  FOR ALL TO authenticated
  USING (public.can_manage_requests())
  WITH CHECK (public.can_manage_requests());

CREATE POLICY payments_customer_select ON public.payments
  FOR SELECT TO authenticated
  USING (customer_id = auth.uid() OR public.can_manage_requests());

CREATE POLICY payments_customer_insert ON public.payments
  FOR INSERT TO authenticated
  WITH CHECK (
    customer_id = auth.uid()
    AND EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = payments.request_id
        AND sr.customer_id = auth.uid()
        AND sr.status <> 'cancelled'
    )
  );

CREATE POLICY payments_staff_manage ON public.payments
  FOR ALL TO authenticated
  USING (public.can_manage_requests())
  WITH CHECK (public.can_manage_requests());

CREATE POLICY file_attachments_customer_select ON public.file_attachments
  FOR SELECT TO authenticated
  USING (
    owner_id = auth.uid()
    OR public.can_manage_requests()
    OR EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = file_attachments.request_id
        AND sr.customer_id = auth.uid()
    )
  );

CREATE POLICY file_attachments_customer_insert ON public.file_attachments
  FOR INSERT TO authenticated
  WITH CHECK (
    owner_id = auth.uid()
    AND (
      request_id IS NULL
      OR EXISTS (
        SELECT 1 FROM public.service_requests sr
        WHERE sr.id = file_attachments.request_id
          AND sr.customer_id = auth.uid()
      )
    )
  );

CREATE POLICY file_attachments_customer_update ON public.file_attachments
  FOR UPDATE TO authenticated
  USING (owner_id = auth.uid() OR public.can_manage_requests())
  WITH CHECK (
    public.can_manage_requests()
    OR (
      owner_id = auth.uid()
      AND (
        request_id IS NULL
        OR EXISTS (
          SELECT 1 FROM public.service_requests sr
          WHERE sr.id = file_attachments.request_id
            AND sr.customer_id = auth.uid()
        )
      )
    )
  );

CREATE POLICY request_edit_requests_customer_select ON public.request_edit_requests
  FOR SELECT TO authenticated
  USING (
    public.can_manage_requests()
    OR EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = request_edit_requests.request_id
        AND sr.customer_id = auth.uid()
    )
  );

CREATE POLICY request_edit_requests_staff_manage ON public.request_edit_requests
  FOR ALL TO authenticated
  USING (public.can_manage_requests())
  WITH CHECK (public.can_manage_requests());

CREATE POLICY request_edit_submissions_customer_select ON public.request_edit_submissions
  FOR SELECT TO authenticated
  USING (
    submitted_by = auth.uid()
    OR public.can_manage_requests()
    OR EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = request_edit_submissions.request_id
        AND sr.customer_id = auth.uid()
    )
  );

CREATE POLICY request_edit_submissions_customer_insert ON public.request_edit_submissions
  FOR INSERT TO authenticated
  WITH CHECK (
    submitted_by = auth.uid()
    AND EXISTS (
      SELECT 1
      FROM public.request_edit_requests er
      JOIN public.service_requests sr ON sr.id = er.request_id
      WHERE er.id = request_edit_submissions.edit_request_id
        AND er.request_id = request_edit_submissions.request_id
        AND er.status = 'open'
        AND sr.customer_id = auth.uid()
        AND sr.status IN ('pending', 'approved')
    )
  );

CREATE POLICY request_edit_submissions_staff_manage ON public.request_edit_submissions
  FOR ALL TO authenticated
  USING (public.can_manage_requests())
  WITH CHECK (public.can_manage_requests());

CREATE POLICY request_status_events_customer_select ON public.request_status_events
  FOR SELECT TO authenticated
  USING (
    public.can_manage_requests()
    OR EXISTS (
      SELECT 1 FROM public.service_requests sr
      WHERE sr.id = request_status_events.request_id
        AND sr.customer_id = auth.uid()
    )
  );

CREATE POLICY request_status_events_staff_insert ON public.request_status_events
  FOR INSERT TO authenticated
  WITH CHECK (public.can_manage_requests());

CREATE POLICY notifications_customer_select ON public.notifications
  FOR SELECT TO authenticated
  USING (recipient_id = auth.uid() OR public.can_manage_requests());

CREATE POLICY notifications_customer_update ON public.notifications
  FOR UPDATE TO authenticated
  USING (recipient_id = auth.uid() OR public.can_manage_requests())
  WITH CHECK (recipient_id = auth.uid() OR public.can_manage_requests());

CREATE POLICY notifications_staff_insert ON public.notifications
  FOR INSERT TO authenticated
  WITH CHECK (public.can_manage_requests());

CREATE POLICY announcements_public_read ON public.announcements
  FOR SELECT TO anon, authenticated
  USING (
    (
      status = 'published'
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at IS NULL OR ends_at > NOW())
    )
    OR public.is_staff()
  );

CREATE POLICY announcements_staff_manage ON public.announcements
  FOR ALL TO authenticated
  USING (public.is_staff())
  WITH CHECK (public.is_staff());

CREATE POLICY audit_logs_staff_select ON public.audit_logs
  FOR SELECT TO authenticated
  USING (public.is_staff());

CREATE POLICY audit_logs_staff_insert ON public.audit_logs
  FOR INSERT TO authenticated
  WITH CHECK (public.is_staff());

GRANT USAGE ON SCHEMA public TO anon, authenticated;
GRANT SELECT ON public.service_categories, public.services, public.service_price_options, public.announcements TO anon;
GRANT SELECT, INSERT, UPDATE ON public.profiles TO authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.staff_members TO authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON
  public.service_categories,
  public.services,
  public.service_price_options,
  public.service_requests,
  public.printing_request_details,
  public.device_service_details,
  public.request_financials,
  public.payments,
  public.file_attachments,
  public.request_edit_requests,
  public.request_edit_submissions,
  public.request_status_events,
  public.notifications,
  public.announcements,
  public.audit_logs
TO authenticated;

WITH categories AS (
  INSERT INTO public.service_categories (code, name, description, sort_order)
  VALUES
    ('printing', 'Printing', 'Document printing, photocopying, Rush ID, and laminating services.', 10),
    ('repair', 'Repair', 'Device repair and part replacement services.', 20),
    ('installation', 'Installation', 'Device software, unlock, bypass, and reprogramming services.', 30)
  RETURNING id, code
),
seed_services AS (
  INSERT INTO public.services (
    category_id, slug, kind, name, description, base_price, price_min, price_max,
    requires_file_upload, sort_order
  )
  SELECT c.id, seed.slug, seed.kind::public.service_kind, seed.name, seed.description,
         seed.base_price, seed.price_min, seed.price_max, seed.requires_file_upload, seed.sort_order
  FROM (
    VALUES
      ('printing'::public.service_category_code, 'document-printing', 'document_printing', 'Document Printing',
       'Long, short, A4, and A3 document printing with full-color and half-color options.',
       5.00::numeric, 5.00::numeric, 10.00::numeric, TRUE, 10),
      ('printing', 'xerox', 'xerox', 'Xerox',
       'Photocopy service for long, short, A4, and A3 paper.',
       3.00::numeric, 3.00::numeric, 5.00::numeric, FALSE, 20),
      ('printing', 'rush-id', 'rush_id', 'Rush ID',
       'Rush ID photo package printing.',
       30.00::numeric, 30.00::numeric, 50.00::numeric, TRUE, 30),
      ('printing', 'laminating', 'laminating', 'Laminating',
       'Thin and thick lamination service.',
       20.00::numeric, 20.00::numeric, 30.00::numeric, FALSE, 40),
      ('repair', 'lcd-replacement', 'repair', 'LCD Replacement',
       'LCD replacement for supported mobile phones and laptops.',
       1200.00::numeric, 1200.00::numeric, 5500.00::numeric, FALSE, 10),
      ('repair', 'battery-replacement', 'repair', 'Battery Replacement',
       'Battery replacement for supported mobile phones and laptops.',
       700.00::numeric, 700.00::numeric, 2500.00::numeric, FALSE, 20),
      ('repair', 'charging-pin-replacement', 'repair', 'Charging Pin Replacement',
       'Charging pin replacement for supported mobile phones and laptops.',
       800.00::numeric, 800.00::numeric, 4000.00::numeric, FALSE, 30),
      ('repair', 'speaker-mouthpiece-replacement', 'repair', 'Speaker / Mouthpiece Replacement',
       'Speaker or mouthpiece replacement for supported mobile phones and laptops.',
       700.00::numeric, 700.00::numeric, 1500.00::numeric, FALSE, 40),
      ('repair', 'power-button-repair', 'repair', 'Power Button Repair',
       'Power button repair for supported mobile phones and laptops.',
       500.00::numeric, 500.00::numeric, 2000.00::numeric, FALSE, 50),
      ('repair', 'volume-repair', 'repair', 'Volume Repair',
       'Volume button repair for supported mobile phones and laptops.',
       1000.00::numeric, 1000.00::numeric, 2000.00::numeric, FALSE, 60),
      ('repair', 'camera-repair', 'repair', 'Camera Repair',
       'Camera repair for supported mobile phones and laptops.',
       1500.00::numeric, 1500.00::numeric, 5000.00::numeric, FALSE, 70),
      ('repair', 'parts-upgrade', 'manual_assessment', 'Part(s) Upgrade',
       'Manual assessment for parts upgrade requests.',
       NULL::numeric, NULL::numeric, NULL::numeric, FALSE, 80),
      ('repair', 'other-repair-request', 'manual_assessment', 'Other Repair Request',
       'Manual assessment for repair requests not listed in the catalog.',
       NULL::numeric, NULL::numeric, NULL::numeric, FALSE, 90),
      ('installation', 'reprogram-service', 'installation', 'Reprogram Service',
       'Device reprogramming service.',
       1000.00::numeric, 1000.00::numeric, 4000.00::numeric, FALSE, 10),
      ('installation', 'hang-logo-fix-service', 'installation', 'Hang Logo Fix Service',
       'Device startup logo troubleshooting.',
       1000.00::numeric, 1000.00::numeric, 3500.00::numeric, FALSE, 20),
      ('installation', 'boot-loop-fix-service', 'installation', 'Boot Loop Fix Service',
       'Device boot loop troubleshooting.',
       1000.00::numeric, 1000.00::numeric, 5000.00::numeric, FALSE, 30),
      ('installation', 'openline-samsung-iphone', 'installation', 'Openline Samsung & iPhone',
       'Supported device network unlocking service.',
       3500.00::numeric, 3500.00::numeric, 6000.00::numeric, FALSE, 40),
      ('installation', 'bypass-google-account', 'installation', 'Bypass Google Account',
       'Supported device account recovery service.',
       500.00::numeric, 500.00::numeric, 2000.00::numeric, FALSE, 50),
      ('installation', 'bypass-password', 'installation', 'Bypass Password',
       'Supported device access recovery service.',
       1000.00::numeric, 1000.00::numeric, 3000.00::numeric, FALSE, 60),
      ('installation', 'other-installation-request', 'manual_assessment', 'Other Installation Request',
       'Manual assessment for installation requests not listed in the catalog.',
       NULL::numeric, NULL::numeric, NULL::numeric, FALSE, 70)
  ) AS seed(category_code, slug, kind, name, description, base_price, price_min, price_max, requires_file_upload, sort_order)
  JOIN categories c ON c.code = seed.category_code
  RETURNING id, slug
)
INSERT INTO public.service_price_options (service_id, option_group, option_code, label, unit_price, sort_order)
SELECT s.id, seed.option_group, seed.option_code, seed.label, seed.unit_price, seed.sort_order
FROM (
  VALUES
    ('document-printing', 'paper_color', 'long_full', 'Long Bond - Full Color', 10.00::numeric, 10),
    ('document-printing', 'paper_color', 'long_half', 'Long Bond - Half Color', 5.00::numeric, 20),
    ('document-printing', 'paper_color', 'short_full', 'Short Bond - Full Color', 10.00::numeric, 30),
    ('document-printing', 'paper_color', 'short_half', 'Short Bond - Half Color', 5.00::numeric, 40),
    ('document-printing', 'paper_color', 'a4_full', 'A4 - Full Color', 10.00::numeric, 50),
    ('document-printing', 'paper_color', 'a4_half', 'A4 - Half Color', 5.00::numeric, 60),
    ('document-printing', 'paper_color', 'a3_full', 'A3 - Full Color', 10.00::numeric, 70),
    ('document-printing', 'paper_color', 'a3_half', 'A3 - Half Color', 5.00::numeric, 80),
    ('xerox', 'paper', 'long', 'Long Bond', 5.00::numeric, 10),
    ('xerox', 'paper', 'short', 'Short Bond', 3.00::numeric, 20),
    ('xerox', 'paper', 'a4', 'A4', 3.00::numeric, 30),
    ('xerox', 'paper', 'a3', 'A3', 5.00::numeric, 40),
    ('rush-id', 'package', 'package1', 'Package 1', 40.00::numeric, 10),
    ('rush-id', 'package', 'package2', 'Package 2', 30.00::numeric, 20),
    ('rush-id', 'package', 'package3', 'Package 3', 30.00::numeric, 30),
    ('rush-id', 'package', 'package4', 'Package 4', 50.00::numeric, 40),
    ('rush-id', 'package', 'package5', 'Package 5', 30.00::numeric, 50),
    ('rush-id', 'package', 'package6', 'Package 6', 50.00::numeric, 60),
    ('laminating', 'thickness', 'thin', 'Thin', 20.00::numeric, 10),
    ('laminating', 'thickness', 'thick', 'Thick', 30.00::numeric, 20)
) AS seed(service_slug, option_group, option_code, label, unit_price, sort_order)
JOIN seed_services s ON s.slug = seed.service_slug;

COMMIT;
