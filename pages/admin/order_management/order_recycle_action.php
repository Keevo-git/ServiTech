<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
servitech_require_super_admin();

header("Location: " . admin_url_raw("/pages/super_admin/super_admin_order_recycle_action.php"), true, 307);
exit();
