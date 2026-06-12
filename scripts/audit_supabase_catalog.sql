\pset pager off
\x off

\echo '=== TABLES AND RLS ==='
SELECT n.nspname AS schema_name,
       c.relname AS table_name,
       c.relrowsecurity AS rls_enabled,
       c.relforcerowsecurity AS rls_forced,
       pg_total_relation_size(c.oid) AS total_bytes
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p')
  AND n.nspname IN ('public', 'auth', 'storage')
ORDER BY n.nspname, c.relname;

\echo '=== COLUMNS ==='
SELECT table_schema, table_name, ordinal_position, column_name,
       data_type, udt_name, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema IN ('public', 'auth', 'storage')
ORDER BY table_schema, table_name, ordinal_position;

\echo '=== CONSTRAINTS ==='
SELECT n.nspname AS schema_name,
       c.relname AS table_name,
       con.conname,
       con.contype,
       con.convalidated,
       pg_get_constraintdef(con.oid, true) AS definition
FROM pg_constraint con
JOIN pg_class c ON c.oid = con.conrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname IN ('public', 'auth', 'storage')
ORDER BY n.nspname, c.relname, con.conname;

\echo '=== INDEXES ==='
SELECT schemaname, tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname IN ('public', 'auth', 'storage')
ORDER BY schemaname, tablename, indexname;

\echo '=== POLICIES ==='
SELECT schemaname, tablename, policyname, permissive, roles, cmd, qual, with_check
FROM pg_policies
WHERE schemaname IN ('public', 'auth', 'storage')
ORDER BY schemaname, tablename, policyname;

\echo '=== TRIGGERS ==='
SELECT event_object_schema, event_object_table, trigger_name,
       action_timing, event_manipulation, action_statement
FROM information_schema.triggers
WHERE event_object_schema IN ('public', 'auth', 'storage')
ORDER BY event_object_schema, event_object_table, trigger_name, event_manipulation;

\echo '=== PUBLIC FUNCTIONS ==='
SELECT n.nspname AS schema_name,
       p.proname AS function_name,
       pg_get_function_identity_arguments(p.oid) AS arguments,
       pg_get_function_result(p.oid) AS result_type,
       p.prosecdef AS security_definer,
       l.lanname AS language
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
JOIN pg_language l ON l.oid = p.prolang
WHERE n.nspname = 'public'
ORDER BY p.proname, arguments;

\echo '=== STORAGE BUCKETS ==='
SELECT id, name, public, file_size_limit, allowed_mime_types, created_at
FROM storage.buckets
ORDER BY id;

