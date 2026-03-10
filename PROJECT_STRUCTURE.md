# Project Structure

## Root Application Folders
- `config/`
  - `db.php`
  - `session_check.php`
- `auth/`
  - `login.php`, `logout.php`, `register.php`
  - `log_in.html`, `regis.html`
- `pages/`
  - `customer/`: customer page routes (`customer_dash.php`, `custo_*`)
  - `admin/`: admin runtime modules, queues, orders, services, and `_includes`
- `components/`
  - Shared includes (`header.php`, `footer.php`, `auth_guard.php`, `queue_modal.php`)
- `api/`
  - Action/API endpoints (`queue_create.php`, `queue_list.php`, `profile_update.php`, etc.)
- `assets/`
  - `css/style.css`
  - `js/main.js`
  - `images/*`
- `helpers/`
  - Utility/helper folder (currently scaffolded)

## Other Folders
- `legacy/`: archived prototype/static files kept for reference.

## Route Convention
- Public entry: `index.php`
- Customer pages: `/ServiTech/pages/customer/...`
- Admin pages: `/ServiTech/pages/admin/...`
- Unified auth (customer + admin by role): `/ServiTech/auth/...`
- Customer API: `/ServiTech/api/...`
