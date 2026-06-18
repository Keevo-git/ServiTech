<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/store_availability.php";
servitech_store_send_no_cache_headers();
header("Location: /pages/customer/customer_dash.php", true, 302);
exit;
