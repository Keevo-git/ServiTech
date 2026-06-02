# Database Migrations

Run migration files in filename order for a fresh PostgreSQL database.

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

The application runtime must not create or alter database schema. Apply future
schema changes as new migration files before deploying PHP code that depends on
them.
