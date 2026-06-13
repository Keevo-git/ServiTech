<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/url.php";

header("Location: " . admin_url_raw("/pages/admin/queue_list/printing.php"));
exit();


