# ServiTech

## Runtime Folder Layout
- `config/`: app configuration and shared bootstrap files.
- `auth/`: customer auth pages and handlers.
- `pages/`: grouped app routes.
  - `pages/customer/`: customer-facing page routes.
  - `pages/admin/`: admin panel modules and admin includes.
- `components/`: reusable shared UI/auth components.
- `api/`: backend action and JSON endpoints.
- `assets/`: static assets.
- `helpers/`: utility/helper files.
- `legacy/`: archived old mockup/static files.

## Assets Layout
- `assets/css`
- `assets/js`
- `assets/images`

## Notes
- Customer runtime files were moved out of `main/` into root-level folders for clarity.
- Internal includes, links, and redirects were updated to the new structure.
- Admin and customer now share the same Supabase PDO connection from `config/db.php`.
- Login is centralized at `auth/login.php` and routes admins to `pages/admin/` by role.
- The staged Supabase Auth/RLS migration runbook is in `docs/SUPABASE_SECURITY_MIGRATION.md`.
- The current implementation and verification status is in `docs/IMPLEMENTATION_REPORT.md`.

## Environment Variables
- `SUPABASE_DB_HOST`
- `SUPABASE_DB_PORT` (default: `5432`)
- `SUPABASE_DB_NAME` (default: `postgres`)
- `SUPABASE_DB_USER`
- `SUPABASE_DB_PASS`
- `SUPABASE_DB_SSLMODE` (default: `require`)
- `SUPABASE_DB_URL` (exact session-pooler URL used only for backup/audit tooling)
- `SUPABASE_URL`
- `SUPABASE_ANON_KEY`
- `SUPABASE_AUTH_ENABLED` (`0` until the additive migration and staging tests pass)
- `SERVITECH_DB_ENFORCE_RLS` (`0` until Supabase Auth is enabled)
- `SESSION_IDLE_TIMEOUT_SECONDS` (Supabase Auth inactivity limit; default `1800`)
- `AUTH_PROFILE_REBIND_SECONDS` (profile/role refresh interval; default `300`)
- `AUTH_REQUIRE_ADMIN_MFA` (`1` in staging and production)
- `AUTH_ALLOW_ADMIN_MFA_ENROLLMENT` (temporary controlled bootstrap only; normally `0`)
- `SERVITECH_PRIVATE_UPLOAD_DIR` (absolute path ending in `ServiTech_Uploads`)
- `SERVITECH_REQUIRE_PRIVATE_UPLOAD_ROOT` (`1` in production)
- `SERVITECH_CONTACT_EMAIL` (public contact email shown in footers, auth pages, and legal pages)
- `SERVITECH_CONTACT_PHONE` (public contact phone shown in shared footers)
- `SERVITECH_CONTACT_FACEBOOK_URL` (public Facebook link shown in shared footers)
- `SERVITECH_CONTACT_FACEBOOK_LABEL` (public Facebook label shown in shared footers)
- `SMTP_HOST` (default: `smtp.gmail.com`)
- `SMTP_PORT` (default: `587`)
- `SMTP_SECURE` (default: `tls`; `SMTP_ENCRYPTION` is still accepted as a legacy alias)
- `SMTP_USERNAME` (the Gmail account used for SMTP)
- `SMTP_PASSWORD` (Gmail App Password; never commit this)
- `SMTP_FROM_EMAIL` (usually the same Gmail account as `SMTP_USERNAME`)
- `SMTP_FROM_NAME` (default: `ServiTech`)
- `SMTP_REPLY_TO` (optional)
- `SMTP_DEBUG` (`1` only for temporary admin debugging)
- `APP_BASE_PATH` (default: `/ServiTech`; set `/` if app is hosted at domain root)
- `SESSION_LIFETIME_SECONDS` (default: 30 days; server-side Supabase remember-session lifetime)
- `REMEMBER_ME_LIFETIME_SECONDS` (default: 30 days; password-login remember-token lifetime, capped at 365 days)
- `APP_DEBUG` (`1` to enable PHP error display, otherwise disabled)
- `GOOGLE_CLIENT_ID` (required to enable Sign in with Google on the login page)

## Hostinger SMTP Setup
- Preferred: create a `.env` file in the deployed project root, beside `index.php`, using `.env.example` as the template. Hostinger upload tools can skip hidden files, so confirm the `.env` file exists after deployment or create it directly in File Manager.
- Alternative: copy `config/mail.local.example.php` to `config/mail.local.php` on Hostinger and place the SMTP values there. This fallback is useful if Hostinger does not expose `getenv()`/Apache `SetEnv` values reliably.
- Apache option: set the same `SMTP_*` variables with `SetEnv` in Hostinger's `.htaccess`. Keep the real `.htaccess` out of version control.
- To change Gmail senders, create a Gmail App Password for the new Google account, then update only these server-side values: `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, and optionally `SMTP_REPLY_TO`. Keep `SMTP_FROM_EMAIL` the same as `SMTP_USERNAME` unless Gmail has that sender address configured as an allowed alias.
- Required Gmail values:
  `SMTP_HOST=smtp.gmail.com`
  `SMTP_PORT=587`
  `SMTP_SECURE=tls`
  `SMTP_USERNAME=REPLACE_WITH_NEW_GOOGLE_ACCOUNT@gmail.com`
  `SMTP_PASSWORD=GMAIL_APP_PASSWORD_FROM_ENV`
  `SMTP_FROM_EMAIL=REPLACE_WITH_NEW_GOOGLE_ACCOUNT@gmail.com`
  `SMTP_FROM_NAME=ServiTech`
- The forgot-password mailer reads SMTP values from Hostinger environment variables, `.env`, then `config/mail.local.php`. It logs only whether `SMTP_PASSWORD` is `present` or `missing`.
- Forgot-password requests are rate limited per email and IP address to reduce repeated SMTP/login attempts against Google.
- Super Admin diagnostic page: visit `/pages/super_admin/super_admin_smtp_diagnostics.php` while logged in as a Super Admin to verify SMTP values are loaded without displaying secrets.
- Private SMTP logs are written to `logs/forgot_password_mail.log` and `logs/mail_error.log`; the web server rules deny browser access to log files.

## Google Account Sign-In
- You can enable Google account sign-in with either the `GOOGLE_CLIENT_ID` environment variable or a local config file.
- To use a local config file, copy `config/google.local.example.php` to `config/google.local.php` and paste your Google OAuth Web Client ID into `client_id`.
- Keep `config/google.local.php` out of version control.
- Apply `database/migrations/20260623_require_google_account_completion.sql` before enabling the Google account completion flow with Supabase Auth.
- New and existing Google-linked customers with a missing password or contact number are redirected to the required account setup page after sign-in.

## Local Development
- Preferred: copy `.env.example` to `.env`, set the `SUPABASE_DB_*` values, and keep `.env` private.
- Alternative: create `config/db.local.php` from `config/db.local.example.php` if you prefer file-based local DB config.
- Keep real credentials out of version control.

## Uploaded File Retention
- Files linked to active requests remain available while the request is operationally active, including customer edit/send-back states.
- Files linked to `DONE` or `CANCELLED` requests are deleted 30 days after `queues.closed_at`.
- Failed, cancelled, abandoned, and otherwise unlinked temporary uploads are deleted after 24 hours. Upload cancellation and failed multi-file uploads also attempt immediate cleanup.
- Queue/order records and historical filenames remain after file content expires; customer and admin views show the attachment as unavailable.

Apply `database/migrations/20260612_add_file_retention_policy.sql`, then schedule the CLI job once per hour. Hourly execution promptly enforces the 24-hour temporary-upload threshold while also checking the 30-day closed-request threshold.

Linux/Hostinger cron example:

```text
15 * * * * /usr/bin/php /absolute/path/to/ServiTech/scripts/cleanup_upload_retention.php >> /absolute/path/to/ServiTech/logs/upload_retention.log 2>&1
```

Windows Task Scheduler action:

```text
Program: C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\ServiTech\scripts\cleanup_upload_retention.php
Schedule: Hourly
```

The cleanup script uses a process lock, so overlapping scheduled runs exit without deleting files twice. `scripts/cleanup_orphan_uploads.php` remains as a compatible wrapper and now enforces both temporary and closed-request retention.

## Supabase Migration Verification

Run the local non-destructive checks with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify_security_migration.ps1
```

Do not enable the Auth/RLS feature flags until the backup, staging migration, admin linkage,
and anonymous/customer/admin role tests in the migration runbook have passed.
