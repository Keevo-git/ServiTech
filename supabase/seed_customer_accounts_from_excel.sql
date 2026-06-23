-- ServiTech customer Auth/profile seed generated from the supplied Excel data.
--
-- Run this in the Supabase SQL Editor as the project owner (normally `postgres`),
-- after database/migrations/20260612_add_supabase_auth_rls_foundation.sql.
-- The project schema uses:
--   auth.users.id (UUID) -> public.users.auth_user_id (UUID)
--   public.users(fullname, email, contact, role, created_at, updated_at)
--
-- Safety and rerun behavior:
--   * This script never deletes users and never changes an existing Auth password.
--   * Existing Auth users are reused by case-insensitive email.
--   * Existing linked profiles are left unchanged on reruns.
--   * A conflicting profile/Auth link aborts the whole transaction.
--   * The public.users protection trigger is disabled only while setting a missing
--     auth_user_id. ALTER TABLE takes an exclusive lock, and transaction rollback
--     restores the trigger automatically if any later statement fails.
--   * Plain-text passwords exist only in this seed file/query text. The staging
--     table and auth.users receive only bcrypt hashes produced by pgcrypto crypt().
--
-- Supabase Auth owns the auth schema. Direct inserts should be reserved for
-- controlled migrations like this one; normal application signup should continue
-- to use the Auth API.
-- IMPORTANT: paste/select this entire file in the Supabase SQL Editor and run it as
-- one script. Running only the statement under the cursor skips staging setup.

BEGIN;

SET LOCAL TIME ZONE 'Asia/Manila';
SET LOCAL search_path = public, extensions, auth;

-- Serialize reruns of this specific seed without blocking unrelated advisory locks.
SELECT pg_advisory_xact_lock(hashtextextended('servitech:customer-excel-seed:2026-06', 0));

-- UNLOGGED helpers are intentional: Supabase's SQL runner may change sessions
-- between statements, which makes TEMP tables disappear. These uniquely named
-- helpers are transactionally cleaned up at the bottom of the script.
DROP TABLE IF EXISTS public._servitech_customer_map_202606;
DROP TABLE IF EXISTS public._servitech_customer_seed_202606;

CREATE UNLOGGED TABLE public._servitech_customer_seed_202606 (
  fullname       TEXT        NOT NULL,
  email          TEXT        NOT NULL,
  contact        TEXT        NOT NULL,
  encrypted_password TEXT    NOT NULL,
  role           TEXT        NOT NULL,
  seed_created_at TIMESTAMPTZ NOT NULL
);

INSERT INTO public._servitech_customer_seed_202606
  (fullname, email, contact, encrypted_password, role, seed_created_at)
SELECT
  fullname,
  email,
  contact,
  crypt(seed_password, gen_salt('bf', 10)),
  role,
  seed_created_at
FROM (VALUES
  ('Althea Marielle Santos', 'althea.santos27@gmail.com', '09528999294', 'ServiTech@202601', 'customer', TIMESTAMPTZ '2026-06-08 15:17:52+08'),
  ('Bea Cassandra Tuazon', 'beatuazon37@gmail.com', '09606191123', 'ServiTech@202602', 'customer', TIMESTAMPTZ '2026-06-08 15:34:37+08'),
  ('Camille Ysabel Navarro', 'camille_navarro@gmail.com', '09781737112', 'ServiTech@202603', 'customer', TIMESTAMPTZ '2026-06-08 16:15:21+08'),
  ('Dianne Rochelle Mercado', 'dmercado176@yahoo.com', '09829059267', 'ServiTech@202604', 'customer', TIMESTAMPTZ '2026-06-08 16:22:29+08'),
  ('Ezekiel Rafael Dizon', 'ezekiel.r.dizon@gmail.com', '09505095060', 'ServiTech@202605', 'customer', TIMESTAMPTZ '2026-06-08 16:30:45+08'),
  ('Francesca Mae Valerio', 'valerio.francesca@gmail.com', '09112878553', 'ServiTech@202606', 'customer', TIMESTAMPTZ '2026-06-08 16:33:21+08'),
  ('Gian Carlo Mendoza', 'gian1679@gmail.com', '09464415661', 'ServiTech@202607', 'customer', TIMESTAMPTZ '2026-06-09 15:14:52+08'),
  ('Hannah Elise Bautista', 'hannah-bautista79@gmail.com', '09382548148', 'ServiTech@202608', 'customer', TIMESTAMPTZ '2026-06-09 15:20:21+08'),
  ('Ivan Gabriel Soriano', 'ivangsoriano@gmail.com', '09916238125', 'ServiTech@202609', 'customer', TIMESTAMPTZ '2026-06-09 15:36:00+08'),
  ('Janelle Mikaela Villarama', 'j.villarama93@gmail.com', '09149026667', 'ServiTech@202610', 'customer', TIMESTAMPTZ '2026-06-09 15:39:08+08'),
  ('Katrina Denise Uy', 'katrina.uy29@gmail.com', '09959068357', 'ServiTech@202611', 'customer', TIMESTAMPTZ '2026-06-09 15:41:29+08'),
  ('Lance Adrian Caballero', 'lancecaballero17@gmail.com', '09768024059', 'ServiTech@202612', 'customer', TIMESTAMPTZ '2026-06-09 15:43:21+08'),
  ('Mikaela Therese Ramos', 'mikaela_ramos@gmail.com', '09909587598', 'ServiTech@202613', 'customer', TIMESTAMPTZ '2026-06-09 15:45:14+08'),
  ('Nico Emmanuel Salcedo', 'nsalcedo366@gmail.com', '09847874778', 'ServiTech@202614', 'customer', TIMESTAMPTZ '2026-06-09 15:55:21+08'),
  ('Olivia Samira Reyes', 'olivia.s.reyes@gmail.com', '09249121834', 'ServiTech@202615', 'customer', TIMESTAMPTZ '2026-06-09 16:17:21+08'),
  ('Paolo Miguel Villanueva', 'villanueva.paolo@yahoo.com', '09867589631', 'ServiTech@202616', 'customer', TIMESTAMPTZ '2026-06-09 16:18:21+08'),
  ('Quinn Elijah Manalo', 'quinn2649@gmail.com', '09893003220', 'ServiTech@202617', 'customer', TIMESTAMPTZ '2026-06-09 16:21:14+08'),
  ('Rhea Dominique Castillo', 'rhea-castillo59@gmail.com', '09659418360', 'ServiTech@202618', 'customer', TIMESTAMPTZ '2026-06-10 15:03:14+08'),
  ('Samantha Louise Alcantara', 'samanthalalcantara@gmail.com', '09741326854', 'ServiTech@202619', 'customer', TIMESTAMPTZ '2026-06-10 15:31:21+08'),
  ('Theo Nathaniel Garcia', 't.garcia73@gmail.com', '09689677369', 'ServiTech@202620', 'customer', TIMESTAMPTZ '2026-06-10 15:34:52+08'),
  ('Uma Patricia Lim', 'uma.lim27@outlook.com', '09719462818', 'ServiTech@202621', 'customer', TIMESTAMPTZ '2026-06-10 15:50:21+08'),
  ('Victor Angelo Rosales', 'victorrosales87@gmail.com', '09558257158', 'ServiTech@202622', 'customer', TIMESTAMPTZ '2026-06-10 15:56:21+08'),
  ('Winona Clarisse Tan', 'winona_tan@gmail.com', '09108804845', 'ServiTech@202623', 'customer', TIMESTAMPTZ '2026-06-10 16:07:14+08'),
  ('Xavier Luis Enriquez', 'xenriquez556@gmail.com', '09401723345', 'ServiTech@202624', 'customer', TIMESTAMPTZ '2026-06-10 16:29:29+08'),
  ('Yna Gabrielle Morales', 'yna.g.morales@yahoo.com', '09468935018', 'ServiTech@202625', 'customer', TIMESTAMPTZ '2026-06-11 15:07:45+08'),
  ('Zachary Sean De Leon', 'leon.zachary@gmail.com', '09689530216', 'ServiTech@202626', 'customer', TIMESTAMPTZ '2026-06-11 15:08:37+08'),
  ('Aira Nicole Baltazar', 'aira3619@gmail.com', '09102173700', 'ServiTech@202627', 'customer', TIMESTAMPTZ '2026-06-11 15:32:08+08'),
  ('Bryan Mateo Evangelista', 'bryan-evangelista39@gmail.com', '09659839797', 'ServiTech@202628', 'customer', TIMESTAMPTZ '2026-06-11 15:44:52+08'),
  ('Cheska Noelle Ignacio', 'cheskanignacio@gmail.com', '09478721102', 'ServiTech@202629', 'customer', TIMESTAMPTZ '2026-06-11 16:04:37+08'),
  ('Daryl Joaquin Ferrer', 'd.ferrer53@gmail.com', '09529033789', 'ServiTech@202630', 'customer', TIMESTAMPTZ '2026-06-11 16:06:21+08'),
  ('Elaine Sophia Magtibay', 'elaine.magtibay29@gmail.com', '09859059856', 'ServiTech@202631', 'customer', TIMESTAMPTZ '2026-06-11 16:08:14+08'),
  ('Francine Alexa Mendoza', 'francinemendoza67@gmail.com', '09596608633', 'ServiTech@202632', 'customer', TIMESTAMPTZ '2026-06-11 16:17:29+08'),
  ('Harvey Sebastian Aquino', 'harvey_aquino@gmail.com', '09902509126', 'ServiTech@202633', 'customer', TIMESTAMPTZ '2026-06-11 16:18:21+08'),
  ('Ivy Marjorie Paredes', 'iparedes746@gmail.com', '09348584124', 'ServiTech@202634', 'customer', TIMESTAMPTZ '2026-06-11 16:24:29+08'),
  ('Jasper Lorenzo Cruzado', 'jasper.l.cruzado@gmail.com', '09968831894', 'ServiTech@202635', 'customer', TIMESTAMPTZ '2026-06-11 16:26:21+08'),
  ('Kyla Bernadette Flores', 'flores.kyla@gmail.com', '09864580815', 'ServiTech@202636', 'customer', TIMESTAMPTZ '2026-06-11 16:31:00+08'),
  ('Leandro Marcus Santiago', 'leandro4589@outlook.com', '09367040616', 'ServiTech@202637', 'customer', TIMESTAMPTZ '2026-06-11 16:32:14+08'),
  ('Mara Isabelle Angeles', 'mara-angeles19@gmail.com', '09801089515', 'ServiTech@202638', 'customer', TIMESTAMPTZ '2026-06-12 15:03:37+08'),
  ('Noah Vincent Domingo', 'noahvdomingo@gmail.com', '09354743900', 'ServiTech@202639', 'customer', TIMESTAMPTZ '2026-06-12 15:09:21+08'),
  ('Patricia Anika Estrella', 'p.estrella33@gmail.com', '09572765878', 'ServiTech@202640', 'customer', TIMESTAMPTZ '2026-06-12 15:29:37+08'),
  ('Renz Christian Mallari', 'renz.mallari27@yahoo.com', '09852735622', 'ServiTech@202641', 'customer', TIMESTAMPTZ '2026-06-12 16:11:52+08'),
  ('Shaira Camille Del Rosario', 'shairarosario47@gmail.com', '09402599136', 'ServiTech@202642', 'customer', TIMESTAMPTZ '2026-06-12 16:28:14+08'),
  ('Tristan Caleb Mendoza', 'tristan_mendoza@gmail.com', '09104269484', 'ServiTech@202643', 'customer', TIMESTAMPTZ '2026-06-13 15:02:08+08'),
  ('Veronica Gail Magno', 'vmagno936@gmail.com', '09355536354', 'ServiTech@202644', 'customer', TIMESTAMPTZ '2026-06-13 15:05:29+08'),
  ('Warren Elijah Cortez', 'warren.e.cortez@gmail.com', '09869368343', 'ServiTech@202645', 'customer', TIMESTAMPTZ '2026-06-13 15:10:14+08'),
  ('Yasmine Andrea Macaraig', 'macaraig.yasmine@gmail.com', '09762017774', 'ServiTech@202646', 'customer', TIMESTAMPTZ '2026-06-13 15:18:14+08'),
  ('Cedric Jovan Bautista', 'cedric5559@gmail.com', '09233913775', 'ServiTech@202647', 'customer', TIMESTAMPTZ '2026-06-13 15:31:14+08'),
  ('Danica Faye Robles', 'danica-robles89@gmail.com', '09362579695', 'ServiTech@202648', 'customer', TIMESTAMPTZ '2026-06-13 15:49:37+08'),
  ('Erika Lorraine Manrique', 'erikalmanrique@yahoo.com', '09471554613', 'ServiTech@202649', 'customer', TIMESTAMPTZ '2026-06-13 16:18:00+08'),
  ('Felix Andre Villafuerte', 'f.villafuerte13@gmail.com', '09398550397', 'ServiTech@202650', 'customer', TIMESTAMPTZ '2026-06-13 16:31:29+08')
) AS seed_source(fullname, email, contact, seed_password, role, seed_created_at);

DO $validate_seed$
BEGIN
  IF (SELECT COUNT(*) FROM public._servitech_customer_seed_202606) <> 50 THEN
    RAISE EXCEPTION 'Expected 50 seed rows.';
  END IF;

  IF EXISTS (
    SELECT LOWER(email)
    FROM public._servitech_customer_seed_202606
    GROUP BY LOWER(email)
    HAVING COUNT(*) > 1
  ) THEN
    RAISE EXCEPTION 'The seed contains duplicate email addresses.';
  END IF;

  IF EXISTS (
    SELECT LOWER(email)
    FROM auth.users
    WHERE LOWER(email) IN (SELECT LOWER(email) FROM public._servitech_customer_seed_202606)
    GROUP BY LOWER(email)
    HAVING COUNT(*) > 1
  ) THEN
    RAISE EXCEPTION 'auth.users already contains duplicate rows for a seed email; resolve that conflict manually.';
  END IF;
END;
$validate_seed$;

-- Resolve each email to its existing Auth UUID or allocate one for a new Auth row.
CREATE UNLOGGED TABLE public._servitech_customer_map_202606 AS
SELECT
  s.*,
  COALESCE(a.id, gen_random_uuid()) AS auth_user_id,
  (a.id IS NOT NULL) AS auth_already_existed,
  EXISTS (
    SELECT 1 FROM public.users u WHERE LOWER(u.email) = LOWER(s.email)
  ) AS profile_already_existed
FROM public._servitech_customer_seed_202606 s
LEFT JOIN auth.users a ON LOWER(a.email) = LOWER(s.email);

-- Never take over a profile that is already linked to a different Auth account.
DO $validate_links$
BEGIN
  IF EXISTS (
    SELECT 1
    FROM public._servitech_customer_map_202606 m
    JOIN public.users u ON LOWER(u.email) = LOWER(m.email)
    WHERE u.auth_user_id IS NOT NULL
      AND u.auth_user_id <> m.auth_user_id
  ) THEN
    RAISE EXCEPTION 'A public.users seed email is linked to a different auth.users row; no changes were committed.';
  END IF;

  -- An unlinked legacy profile may be reused only when its identifying Excel data
  -- already matches. This prevents silently overwriting an unrelated local user.
  IF EXISTS (
    SELECT 1
    FROM public._servitech_customer_map_202606 m
    JOIN public.users u ON LOWER(u.email) = LOWER(m.email)
    WHERE u.auth_user_id IS NULL
      AND (
        u.fullname IS DISTINCT FROM m.fullname
        OR u.contact IS DISTINCT FROM m.contact
        OR LOWER(TRIM(u.role)) IS DISTINCT FROM m.role
        OR u.created_at IS DISTINCT FROM m.seed_created_at
      )
  ) THEN
    RAISE EXCEPTION 'An unlinked public.users row conflicts with the Excel data; no existing profile was overwritten.';
  END IF;
END;
$validate_links$;

-- Insert profiles first. The project's AFTER INSERT trigger on auth.users sees the
-- email and deliberately does nothing, allowing the exact Excel timestamps below.
INSERT INTO public.users (
  fullname,
  email,
  contact,
  password_hash,
  role,
  consent_accepted_at,
  consent_version,
  email_verified_at,
  email_verification_token,
  email_verification_expires,
  email_verification_sent_at,
  created_at,
  updated_at
)
SELECT
  m.fullname,
  LOWER(m.email),
  m.contact,
  NULL,
  'customer',
  m.seed_created_at,
  '2026-06-13',
  m.seed_created_at,
  NULL,
  NULL,
  NULL,
  m.seed_created_at,
  m.seed_created_at
FROM public._servitech_customer_map_202606 m
WHERE NOT EXISTS (
  SELECT 1 FROM public.users u WHERE LOWER(u.email) = LOWER(m.email)
)
ON CONFLICT DO NOTHING;

-- Required Auth fields used by current Supabase/GoTrue email-password accounts:
-- instance_id, aud/role, bcrypt encrypted_password, confirmation time, provider
-- metadata, and stable timestamps. Token columns are explicitly blank for
-- compatibility with Auth schemas where they are NOT NULL without useful defaults.
INSERT INTO auth.users (
  instance_id,
  id,
  aud,
  role,
  email,
  encrypted_password,
  email_confirmed_at,
  raw_app_meta_data,
  raw_user_meta_data,
  created_at,
  updated_at,
  confirmation_token,
  recovery_token,
  email_change_token_new,
  email_change
)
SELECT
  '00000000-0000-0000-0000-000000000000'::UUID,
  m.auth_user_id,
  'authenticated',
  'authenticated',
  LOWER(m.email),
  m.encrypted_password,
  m.seed_created_at,
  jsonb_build_object(
    'provider', 'email',
    'providers', jsonb_build_array('email'),
    'servitech_seed', 'customer_accounts_from_excel_202606'
  ),
  jsonb_build_object(
    'fullname', m.fullname,
    'full_name', m.fullname,
    'contact', m.contact,
    'role', 'customer',
    'privacy_consent', '1',
    'consent_version', '2026-06-13'
  ),
  m.seed_created_at,
  m.seed_created_at,
  '',
  '',
  '',
  ''
FROM public._servitech_customer_map_202606 m
WHERE NOT m.auth_already_existed
  AND NOT EXISTS (
    SELECT 1 FROM auth.users a WHERE LOWER(a.email) = LOWER(m.email)
  )
ON CONFLICT DO NOTHING;

-- Refresh UUIDs after insertion. This also handles a concurrent signup that won an
-- email conflict between the preflight and INSERT without producing a duplicate.
UPDATE public._servitech_customer_map_202606 m
SET auth_user_id = a.id,
    auth_already_existed = m.auth_already_existed OR a.id <> m.auth_user_id
FROM auth.users a
WHERE LOWER(a.email) = LOWER(m.email);

DO $validate_auth_resolution$
BEGIN
  IF (
       SELECT COUNT(*)
       FROM public._servitech_customer_map_202606 m
       JOIN auth.users a
         ON a.id = m.auth_user_id
        AND LOWER(a.email) = LOWER(m.email)
     ) <> 50
     OR (SELECT COUNT(DISTINCT auth_user_id) FROM public._servitech_customer_map_202606) <> 50 THEN
    RAISE EXCEPTION 'Could not resolve exactly one distinct auth.users UUID for every seed row.';
  END IF;

  IF EXISTS (
    SELECT LOWER(a.email)
    FROM auth.users a
    JOIN public._servitech_customer_seed_202606 s ON LOWER(s.email) = LOWER(a.email)
    GROUP BY LOWER(a.email)
    HAVING COUNT(*) > 1
  ) THEN
    RAISE EXCEPTION 'A duplicate auth.users email appeared while seeding; no changes were committed.';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM auth.identities i
    JOIN public._servitech_customer_map_202606 m
      ON i.provider = 'email'
     AND LOWER(i.identity_data ->> 'email') = LOWER(m.email)
    WHERE i.user_id <> m.auth_user_id
  ) THEN
    RAISE EXCEPTION 'An email identity already belongs to a different Auth user; no changes were committed.';
  END IF;
END;
$validate_auth_resolution$;

-- Link only profiles that are not yet linked. The trigger normally protects this
-- security-sensitive field and also replaces updated_at with the current time, so
-- it is paused for this one locked statement to preserve the Excel timestamp.
ALTER TABLE public.users DISABLE TRIGGER protect_users_security_fields;

UPDATE public.users u
SET auth_user_id = m.auth_user_id,
    updated_at = m.seed_created_at
FROM public._servitech_customer_map_202606 m
WHERE LOWER(u.email) = LOWER(m.email)
  AND u.auth_user_id IS NULL;

ALTER TABLE public.users ENABLE TRIGGER protect_users_security_fields;

DO $validate_profiles$
BEGIN
  IF (
       SELECT COUNT(*)
       FROM public._servitech_customer_map_202606 m
       JOIN public.users u
         ON LOWER(u.email) = LOWER(m.email)
        AND u.auth_user_id = m.auth_user_id
     ) <> 50 THEN
    RAISE EXCEPTION 'Could not resolve one linked public.users profile for every seed row.';
  END IF;
END;
$validate_profiles$;

-- auth.identities changed shape across GoTrue releases. Current Supabase projects
-- use provider_id; the fallback supports older projects where the identity key was
-- stored directly in the text id column.
DO $insert_email_identities$
BEGIN
  IF EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = 'auth'
      AND table_name = 'identities'
      AND column_name = 'provider_id'
  ) THEN
    EXECUTE $identity_sql$
      INSERT INTO auth.identities (
        provider_id, user_id, identity_data, provider, created_at, updated_at
      )
      SELECT
        m.auth_user_id::TEXT,
        m.auth_user_id,
        jsonb_build_object(
          'sub', m.auth_user_id::TEXT,
          'email', LOWER(m.email),
          'email_verified', TRUE,
          'phone_verified', FALSE
        ),
        'email',
        m.seed_created_at,
        m.seed_created_at
      FROM public._servitech_customer_map_202606 m
      WHERE NOT EXISTS (
        SELECT 1
        FROM auth.identities i
        WHERE i.user_id = m.auth_user_id AND i.provider = 'email'
      )
      ON CONFLICT DO NOTHING
    $identity_sql$;
  ELSE
    EXECUTE $identity_sql$
      INSERT INTO auth.identities (
        id, user_id, identity_data, provider, created_at, updated_at
      )
      SELECT
        m.auth_user_id::TEXT,
        m.auth_user_id,
        jsonb_build_object(
          'sub', m.auth_user_id::TEXT,
          'email', LOWER(m.email),
          'email_verified', TRUE,
          'phone_verified', FALSE
        ),
        'email',
        m.seed_created_at,
        m.seed_created_at
      FROM public._servitech_customer_map_202606 m
      WHERE NOT EXISTS (
        SELECT 1
        FROM auth.identities i
        WHERE i.user_id = m.auth_user_id AND i.provider = 'email'
      )
      ON CONFLICT DO NOTHING
    $identity_sql$;
  END IF;
END;
$insert_email_identities$;

DO $validate_identities$
BEGIN
  IF (
       SELECT COUNT(DISTINCT i.user_id)
       FROM public._servitech_customer_map_202606 m
       JOIN auth.identities i
         ON i.user_id = m.auth_user_id
        AND i.provider = 'email'
     ) <> 50 THEN
    RAISE EXCEPTION 'Could not resolve one email identity for every seed Auth user.';
  END IF;
END;
$validate_identities$;

-- Verification 1: the available/exact counts should be 50 on the first run;
-- duplicate_auth_email_groups must be 0. Inserted-this-run counts become 0 on rerun.
SELECT
  (SELECT COUNT(*) FROM public._servitech_customer_seed_202606) AS expected_seed_rows,
  (SELECT COUNT(*) FROM public._servitech_customer_map_202606 WHERE NOT auth_already_existed) AS auth_users_inserted_this_run,
  (SELECT COUNT(*) FROM public._servitech_customer_map_202606 WHERE NOT profile_already_existed) AS profiles_inserted_this_run,
  COUNT(DISTINCT a.id) AS available_auth_users,
  COUNT(DISTINCT u.id) AS available_public_profiles,
  COUNT(DISTINCT i.user_id) FILTER (WHERE i.provider = 'email') AS available_email_identities,
  (
    SELECT COUNT(*)
    FROM public._servitech_customer_seed_202606 exact_seed
    JOIN auth.users exact_auth ON LOWER(exact_auth.email) = LOWER(exact_seed.email)
    JOIN public.users exact_profile
      ON LOWER(exact_profile.email) = LOWER(exact_seed.email)
     AND exact_profile.auth_user_id = exact_auth.id
    WHERE exact_auth.created_at = exact_seed.seed_created_at
      AND exact_auth.updated_at = exact_seed.seed_created_at
      AND exact_auth.email_confirmed_at = exact_seed.seed_created_at
      AND exact_profile.created_at = exact_seed.seed_created_at
      AND exact_profile.updated_at = exact_seed.seed_created_at
  ) AS exact_timestamp_matches,
  (
    SELECT COUNT(*)
    FROM (
      SELECT LOWER(a2.email)
      FROM auth.users a2
      JOIN public._servitech_customer_seed_202606 s2 ON LOWER(s2.email) = LOWER(a2.email)
      GROUP BY LOWER(a2.email)
      HAVING COUNT(*) > 1
    ) duplicate_groups
  ) AS duplicate_auth_email_groups
FROM public._servitech_customer_seed_202606 s
LEFT JOIN auth.users a ON LOWER(a.email) = LOWER(s.email)
LEFT JOIN public.users u
  ON LOWER(u.email) = LOWER(s.email)
 AND u.auth_user_id = a.id
LEFT JOIN auth.identities i
  ON i.user_id = a.id
 AND i.provider = 'email';

-- Verification 2: requested account/profile fields. Timestamps are rendered in
-- Philippine time explicitly because timestamptz stores an absolute UTC instant.
SELECT
  u.email,
  u.fullname AS full_name,
  u.contact AS contact_number,
  u.role,
  TO_CHAR(u.created_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS created_at,
  TO_CHAR(u.updated_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS updated_at,
  TO_CHAR(a.created_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS auth_created_at,
  TO_CHAR(a.email_confirmed_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || '+08' AS email_confirmed_at
FROM public._servitech_customer_seed_202606 s
JOIN auth.users a ON LOWER(a.email) = LOWER(s.email)
JOIN public.users u
  ON LOWER(u.email) = LOWER(s.email)
 AND u.auth_user_id = a.id
ORDER BY s.seed_created_at, u.email;

-- Verification 3: this must return zero rows.
SELECT
  LOWER(a.email) AS duplicate_email,
  COUNT(*) AS auth_account_count
FROM auth.users a
JOIN public._servitech_customer_seed_202606 s ON LOWER(s.email) = LOWER(a.email)
GROUP BY LOWER(a.email)
HAVING COUNT(*) > 1;

-- Remove the bcrypt-only helper tables after verification.
DROP TABLE public._servitech_customer_map_202606;
DROP TABLE public._servitech_customer_seed_202606;

COMMIT;
