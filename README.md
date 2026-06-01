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

## Environment Variables
- `SUPABASE_DB_HOST`
- `SUPABASE_DB_PORT` (default: `5432`)
- `SUPABASE_DB_NAME` (default: `postgres`)
- `SUPABASE_DB_USER`
- `SUPABASE_DB_PASS`
- `SUPABASE_DB_SSLMODE` (default: `require`)
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
- `SESSION_LIFETIME_SECONDS` (default: 30 days)
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
- Admin diagnostic page: visit `/pages/admin/smtp_diagnostics.php` while logged in as an admin to verify SMTP values are loaded without displaying secrets.
- Private SMTP logs are written to `logs/forgot_password_mail.log` and `logs/mail_error.log`; the web server rules deny browser access to log files.

## Google Account Sign-In
- You can enable Google account sign-in with either the `GOOGLE_CLIENT_ID` environment variable or a local config file.
- To use a local config file, copy `config/google.local.example.php` to `config/google.local.php` and paste your Google OAuth Web Client ID into `client_id`.
- Keep `config/google.local.php` out of version control.

## Local Development
- Preferred: copy `.env.example` to `.env`, set the `SUPABASE_DB_*` values, and keep `.env` private.
- Alternative: create `config/db.local.php` from `config/db.local.example.php` if you prefer file-based local DB config.
- Keep real credentials out of version control.
