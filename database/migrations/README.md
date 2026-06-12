# Database Migrations

## Supabase Auth foundation

`20260612_add_supabase_auth_rls_foundation.sql` is the additive Supabase Auth,
RLS, role, and upload-metadata foundation for the existing ServiTech schema.
It preserves existing public tables and integer foreign keys.

Before applying it, complete and validate the backup procedure in
`docs/SUPABASE_SECURITY_MIGRATION.md`. Apply it to staging first. Do not enable
`SUPABASE_AUTH_ENABLED` or `SERVITECH_DB_ENFORCE_RLS` until the migration,
admin Auth linkage, and role tests have passed.

## Legacy migration path

The older files below are kept only for maintaining pre-rewrite databases.
Run them in filename order for the legacy schema.

For an existing ServiTech database:

1. Run `20260401_create_baseline_schema.sql`. Its `CREATE TABLE IF NOT EXISTS`
   statements add tables that are completely missing without replacing data.
2. Run the previously dated incremental migrations that have not yet been
   applied.
3. Run `20260602_complete_existing_schema.sql` to add missing columns, indexes,
   foreign keys, and validation constraints.
4. Run `20260602_harden_accounts.sql` to add account-consent, optional email
   verification, and failed-login throttle storage.
5. Run `20260602_seed_default_services.sql` to add catalog rows that do not
   already exist.
6. Run `20260602_add_notification_soft_delete.sql` on databases that were
   already completed before the customer notification-center update.
7. Run `20260602_add_queue_payment_tracking.sql` on databases that were
   already completed before editable queue price and paid-amount tracking.
8. Run `20260612_add_file_retention_policy.sql` to add a stable closure
   timestamp and indexes used by automatic upload retention cleanup.

The application runtime must not create or alter database schema. Apply future
schema changes as new migration files before deploying PHP code that depends on
them.
