# ServiTech Supabase Security Migration

## Safety Gate

Do not apply the foundation migration until all of these are complete:

1. Set `SUPABASE_DB_URL` to the exact Supabase session-pooler URL.
2. Set `SERVITECH_PRIVATE_UPLOAD_DIR` to the deployed private upload directory.
3. Temporarily set `SUPABASE_PROJECT_REF` and a Supabase Management API
   `SUPABASE_ACCESS_TOKEN` in the shell used for backup. Do not store the personal
   access token in the website `.env`.
4. Run:

   ```powershell
   powershell -ExecutionPolicy Bypass -File scripts/backup_supabase.ps1
   ```

   If PostgreSQL tools are not in `PATH`, set their directory for this terminal:

   ```powershell
   $env:POSTGRES_BIN = "C:\Program Files\PostgreSQL\17\bin"
   powershell -ExecutionPolicy Bypass -File scripts/backup_supabase.ps1
   ```

5. Confirm the generated backup directory contains:
   - `servitech-full.dump`
   - `servitech-schema.sql`
   - `servitech-data.sql`
   - `servitech-restore-list.txt`
   - `upload-inventory.csv`, or a clearly reviewed warning
   - `supabase-auth-config.json`
   - `manifest.json` with SHA-256 hashes
6. Restore the custom dump into a disposable PostgreSQL database and run smoke queries.
7. Run the catalog audit and retain its output:

   ```powershell
   psql $env:SUPABASE_DB_URL -f scripts/audit_supabase_catalog.sql
   ```

No production backup was created from this workspace because the configured direct database
hostname did not resolve and the exact pooler URL was not available.

## Deployment Order

1. Rehearse the backup, migration, and rollback on a staging Supabase project.
2. Run `scripts/verify_security_migration.ps1`.
3. Apply `database/migrations/20260612_add_supabase_auth_rls_foundation.sql`.
4. In Supabase Auth settings, keep **Confirm email** disabled during the requested testing phase.
5. Disable secure email-change confirmation during testing if immediate profile email changes are required.
6. Add `https://servitech.store/auth/reset_password.php` to allowed Auth redirect URLs.
7. Configure server-only environment values:
   - `SUPABASE_URL`
   - `SUPABASE_ANON_KEY`
   - `SUPABASE_SERVICE_ROLE_KEY`
   - `SUPABASE_DB_*`
   - `SERVITECH_PRIVATE_UPLOAD_DIR`
8. Create `/ServiTech_Uploads` outside `public_html`, owned by the PHP process and not directly web-accessible.
9. Deploy code with `SUPABASE_AUTH_ENABLED=0` and `SERVITECH_DB_ENFORCE_RLS=0`.
10. Verify the migration and bootstrap/link an admin Auth identity.
11. Enable `SUPABASE_AUTH_ENABLED=1` and `SERVITECH_DB_ENFORCE_RLS=1` together.
12. Run customer, second-customer, admin, anonymous, upload, and direct-file-access tests.

## First-Login Bridge

For an existing password account:

1. Normal Supabase password sign-in is attempted.
2. If it fails, the server checks the legacy password through a privileged server-only connection.
3. A confirmed Supabase Auth identity is created with the submitted password.
4. `users.auth_user_id` is linked to that identity.
5. That profile's legacy `password_hash` is set to `NULL`.
6. The user signs in through Supabase Auth and receives a server-side PHP session.

The service-role key is never rendered into HTML or JavaScript.

## Upload Storage

- The required directory basename is `ServiTech_Uploads`.
- In strict mode it must be an absolute path outside `DOCUMENT_ROOT`.
- Stored names are random SHA-like tokens plus an approved extension.
- Supabase stores metadata and relationships only.
- Downloads are served through `api/upload_download.php` after user/admin authorization.
- Existing legacy paths remain readable for compatibility and are not moved or deleted automatically.

## Destructive Changes

This implementation does not remove or rename production tables, columns, rows, functions,
triggers, policies, buckets, or files. The foundation migration contains no `DROP`, `DELETE`,
`TRUNCATE`, or rename statements.

Potential later removals requiring separate approval:

- Legacy password/reset/email-verification columns after all active users migrate.
- `login_attempts` after Supabase Auth rate limiting is accepted as the sole mechanism.
- Legacy upload files and compatibility endpoints after checksummed migration.
- Supabase Storage buckets, if the live catalog shows any are unused.

Rollback before those later removals is code/config rollback: set both feature flags to `0`,
deploy the previous application build, and restore the verified dump only if the additive
migration itself must be fully reversed.
