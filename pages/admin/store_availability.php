<?php
require_once __DIR__ . "/_includes/admin_auth.php";
servitech_require_super_admin();

header("Location: " . admin_url_raw("/pages/super_admin/super_admin_store_availability.php"), true, 302);
exit();
