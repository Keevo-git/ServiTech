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
- `APP_BASE_PATH` (default: `/ServiTech`; set `/` if app is hosted at domain root)
- `SESSION_LIFETIME_SECONDS` (default: 30 days)
- `APP_DEBUG` (`1` to enable PHP error display, otherwise disabled)

## Local Development
- Create `config/db.local.php` from `config/db.local.example.php` if you prefer file-based local DB config.
- Keep real credentials out of version control.
