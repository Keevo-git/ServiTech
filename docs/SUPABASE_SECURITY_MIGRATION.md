# ServiTech Supabase Security Migration

## Safety Gate

Do not apply the foundation migration until all of these are complete:

1. Set `SUPABASE_DB_URL` to the exact Supabase session-pooler URL.
2. Set `SERVITECH_PRIVATE_UPLOAD_DIR` to the deployed private upload directory.
3. Temporarily set `SUPABASE_PROJECT_REF` and a Supabase Management API
   `SUPABASE_ACCESS_TOKEN` in the shell used for backup. Do not store the personal
   access token in the website `.env`.
4. Set `SERVITECH_BACKUP_DIR` to an encrypted/private destination outside the
   ServiTech website directory. The backup script now refuses an in-project
   destination.
5. Run:

   ```powershell
   powershell -ExecutionPolicy Bypass -File scripts/backup_supabase.ps1
   ```

   If PostgreSQL tools are not in `PATH`, set their directory for this terminal:

   ```powershell
   $env:POSTGRES_BIN = "C:\Program Files\PostgreSQL\17\bin"
   powershell -ExecutionPolicy Bypass -File scripts/backup_supabase.ps1
   ```

6. Confirm the generated backup directory contains:
   - `servitech-full.dump`
   - `servitech-schema.sql`
   - `servitech-data.sql`
   - `servitech-restore-list.txt`
   - `upload-inventory.csv`, or a clearly reviewed warning
   - `supabase-auth-config.json`
   - `manifest.json` with SHA-256 hashes
7. Restore the custom dump into a disposable PostgreSQL database and run smoke queries.
8. Run both audits and retain their output in the private migration record:

   ```powershell
   psql $env:SUPABASE_DB_URL -f scripts/audit_supabase_catalog.sql
   psql $env:SUPABASE_DB_URL -f scripts/audit_auth_mappings.sql
   ```

No production backup was created from this workspace because the configured direct database
hostname did not resolve and the exact pooler URL was not available.

## Deployment Order

1. Rehearse the backup, migration, and rollback on a staging Supabase project.
2. Run `scripts/verify_security_migration.ps1`.
3. Apply `database/migrations/20260612_add_supabase_auth_rls_foundation.sql`,
   then all later dated migrations except the final cutover migration.
4. In Supabase Auth settings, enable **Confirm email**. Keep secure email-change
   confirmation enabled; the application waits for confirmation before syncing
   `public.users.email`.
5. Add the deployed reset-password URL and Google callback/origin URLs to the
   Auth allow list. Use staging URLs during rehearsal.
6. Configure server-only environment values:
   - `SUPABASE_URL`
   - `SUPABASE_ANON_KEY`
   - `SUPABASE_DB_*`
   - `SERVITECH_PRIVATE_UPLOAD_DIR`
   `SUPABASE_SERVICE_ROLE_KEY` is no longer required by login and should be
   omitted unless a separate reviewed server-only provisioning task needs it.
7. Create `/ServiTech_Uploads` outside `public_html`, owned by the PHP process and not directly web-accessible.
8. Deploy code with `SUPABASE_AUTH_ENABLED=0` and `SERVITECH_DB_ENFORCE_RLS=0`.
9. Create/link Auth identities without copying legacy passwords. Use Supabase
   invitations or password-recovery links. Review every mapping reported by
   `scripts/audit_auth_mappings.sql`; do not link by email alone without proving ownership.
10. Apply `database/migrations/20260624_harden_supabase_auth_cutover.sql`.
    This is the point at which admin RLS authority starts requiring AAL2; the
    MFA setup page remains available because enrollment uses Supabase Auth and
    owner-only profile access, not admin table authority.
11. Set `AUTH_ALLOW_ADMIN_MFA_ENROLLMENT=1`, then enable
    `SUPABASE_AUTH_ENABLED=1` and `SERVITECH_DB_ENFORCE_RLS=1` together. Sign in
    as the known admin, scan the QR at `/auth/mfa.php`, verify a code, then
    immediately restore the enrollment value to `0`. Keep
    `AUTH_REQUIRE_ADMIN_MFA=1`.
12. Test signup confirmation, password login, recovery, token refresh, logout,
    Google login, admin MFA, role downgrade/rebind, two-customer IDOR cases,
    direct Supabase writes, uploads, and browser-back behavior.
13. Repeat the restore rehearsal and tests, then perform the production cutover
    in a maintenance window. Do not apply a production flag change until the
    staging evidence is signed off.

## Legacy Credential Removal

The application no longer contains a first-login password bridge. In Supabase
Auth mode:

1. Only Supabase verifies the submitted password.
2. Login never reads a legacy password and never creates `auth.users`.
3. Existing users receive a controlled invitation/recovery flow.
4. `public.users.auth_user_id` is linked only after identity review.
5. Legacy password columns are retained temporarily for rollback but are not
   accepted by the Supabase login path.
6. After production acceptance and the rollback window, null/remove legacy
   password material in a separately approved destructive migration.

Do not use the tracked seed-account passwords for migration. Treat them as
disclosed, rotate affected credentials, and remove the file from deployed web
roots and repository history through a separately reviewed incident task.

## Manual Versus Repository Work

Already implemented in the repository:

- sensitive project directories are denied by Apache configuration;
- backups require a private destination outside the project;
- login has no plaintext/first-login bridge;
- email-confirmed signup and secure email change are supported;
- profile roles are periodically rebound from `public.users`;
- admin TOTP/AAL2 challenge and enforcement are implemented;
- final RLS hardening and mapping-audit SQL are provided.

Operator-only steps:

- obtain and protect staging/production credentials;
- execute and restore-test backups;
- configure Supabase email, redirects, Google provider, rate limits, and MFA;
- review/link identities and enroll the known admin factor;
- apply migrations and flip both feature flags in the approved window;
- execute browser tests using separate customer/admin accounts.

## Upload Storage

- The required directory basename is `ServiTech_Uploads`.
- In strict mode it must be an absolute path outside `DOCUMENT_ROOT`.
- Stored names are random SHA-like tokens plus an approved extension.
- Supabase stores metadata and relationships only.
- Downloads are served through `api/upload_download.php` after user/admin authorization.
- Existing legacy paths remain readable for compatibility and are not moved or deleted automatically.

## Destructive Changes

This repository work does not delete production tables, rows, buckets, or files.
The final migration replaces named policies but does not remove application data.

Potential later removals requiring separate approval:

- Legacy password/reset/email-verification columns after all active users migrate.
- `login_attempts` after Supabase Auth rate limiting is accepted as the sole mechanism.
- Legacy upload files and compatibility endpoints after checksummed migration.
- Supabase Storage buckets, if the live catalog shows any are unused.

Rollback before those later removals is code/config rollback: set both feature flags to `0`,
deploy the previous application build, and restore the verified dump only if the additive
migration itself must be fully reversed.
