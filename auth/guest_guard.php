<?php
require_once __DIR__ . "/../config/session_check.php";

if (!function_exists("servitech_authenticated_home_path")) {
    function servitech_authenticated_home_path(): string
    {
        return servitech_is_admin()
            ? "/pages/admin/admin_dashboard.php"
            : "/pages/customer/customer_dash.php";
    }
}

if (!function_exists("servitech_send_guest_page_cache_headers")) {
    function servitech_send_guest_page_cache_headers(): void
    {
        header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        header("Vary: Cookie");
    }
}

if (!function_exists("servitech_redirect_authenticated_user")) {
    function servitech_redirect_authenticated_user(): void
    {
        if (!servitech_is_logged_in()) {
            return;
        }

        servitech_send_guest_page_cache_headers();
        header("Location: " . servitech_url(servitech_authenticated_home_path()));
        exit();
    }
}

if (!function_exists("servitech_require_guest_page")) {
    function servitech_require_guest_page(): void
    {
        servitech_send_guest_page_cache_headers();
        servitech_redirect_authenticated_user();
    }
}

if (!function_exists("servitech_render_guest_history_guard")) {
    function servitech_render_guest_history_guard(): void
    {
        ?>
  <script>
    window.addEventListener("pageshow", function (event) {
      if (event.persisted) {
        window.location.reload();
      }
    });
  </script>
<?php
    }
}
