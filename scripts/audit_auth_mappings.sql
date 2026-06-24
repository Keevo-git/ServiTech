\pset pager off
\x off

\echo '=== AUTH/PROFILE SUMMARY ==='
SELECT
  (SELECT COUNT(*) FROM auth.users) AS auth_users,
  (SELECT COUNT(*) FROM public.users) AS public_profiles,
  (SELECT COUNT(*) FROM public.users WHERE auth_user_id IS NOT NULL) AS linked_profiles,
  (SELECT COUNT(*) FROM public.users WHERE auth_user_id IS NULL) AS unlinked_profiles,
  (SELECT COUNT(*) FROM auth.users WHERE email_confirmed_at IS NULL) AS unconfirmed_auth_users;

\echo '=== UNLINKED PUBLIC PROFILES (REVIEW EACH ROW) ==='
SELECT id, email, role, created_at
FROM public.users
WHERE auth_user_id IS NULL
ORDER BY id;

\echo '=== AUTH USERS WITHOUT PUBLIC PROFILES (REVIEW EACH ROW) ==='
SELECT au.id AS auth_user_id, au.email, au.created_at, au.email_confirmed_at
FROM auth.users au
LEFT JOIN public.users u ON u.auth_user_id = au.id
WHERE u.id IS NULL
ORDER BY au.created_at, au.id;

\echo '=== LINKED EMAIL MISMATCHES ==='
SELECT u.id AS public_user_id, u.email AS public_email,
       au.id AS auth_user_id, au.email AS auth_email
FROM public.users u
JOIN auth.users au ON au.id = u.auth_user_id
WHERE LOWER(TRIM(u.email)) IS DISTINCT FROM LOWER(TRIM(au.email))
ORDER BY u.id;

\echo '=== DUPLICATE EMAILS ACROSS PUBLIC PROFILES ==='
SELECT LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS profile_count,
       ARRAY_AGG(id ORDER BY id) AS public_user_ids
FROM public.users
GROUP BY LOWER(TRIM(email))
HAVING COUNT(*) > 1
ORDER BY normalized_email;

\echo '=== INVALID OR UNEXPECTED ROLES ==='
SELECT id, email, role
FROM public.users
WHERE LOWER(TRIM(COALESCE(role, ''))) NOT IN ('customer', 'admin')
ORDER BY id;

\echo '=== LEGACY PASSWORD MATERIAL COUNTS (VALUES ARE NEVER PRINTED) ==='
WITH legacy_credentials AS (
  SELECT COALESCE(
    NULLIF(to_jsonb(u) ->> 'password_hash', ''),
    NULLIF(to_jsonb(u) ->> 'password', '')
  ) AS stored_value
  FROM public.users u
)
SELECT
  COUNT(*) FILTER (WHERE stored_value IS NOT NULL AND TRIM(stored_value) <> '') AS legacy_values_present,
  COUNT(*) FILTER (
    WHERE stored_value IS NOT NULL AND TRIM(stored_value) <> ''
      AND stored_value !~ '^\$(2[aby]|argon2(id|i|d))\$'
  ) AS values_not_recognized_as_modern_hashes
FROM legacy_credentials;

\echo '=== ADMIN AUTH AND MFA STATUS ==='
SELECT u.id AS public_user_id, u.email, u.auth_user_id,
       au.email_confirmed_at,
       COUNT(mf.id) FILTER (WHERE mf.status = 'verified') AS verified_mfa_factors
FROM public.users u
LEFT JOIN auth.users au ON au.id = u.auth_user_id
LEFT JOIN auth.mfa_factors mf ON mf.user_id = au.id
WHERE LOWER(TRIM(u.role)) = 'admin'
GROUP BY u.id, u.email, u.auth_user_id, au.email_confirmed_at
ORDER BY u.id;

\echo '=== PUBLIC TABLES WITHOUT RLS ==='
SELECT c.relname AS table_name
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind IN ('r', 'p')
  AND NOT c.relrowsecurity
ORDER BY c.relname;

\echo '=== SERVITECH POLICIES ==='
SELECT tablename, policyname, roles, cmd, qual, with_check
FROM pg_policies
WHERE schemaname = 'public'
ORDER BY tablename, policyname;
