# Database Migrations

## Supabase Auth rewrite

`20260608_rewrite_supabase_schema.sql` is the new full Supabase Auth-based
foundation. It is a destructive reset/rebuild script for the public ServiTech
tables and should be run only on a fresh Supabase project or after an approved
data export/migration.

Do not run the legacy incremental migrations before this rewrite script. The
rewrite intentionally replaces the old custom-auth `users`, `queues`,
`payments`, `uploads`, and related tables with normalized Auth-linked tables,
RLS policies, helper functions, and seed data.

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
