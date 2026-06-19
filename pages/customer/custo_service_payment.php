<?php
require_once __DIR__ . "/../../components/auth_guard.php";

unset($_SESSION["service_payment_draft"], $_SESSION["service_payment_confirmation"], $_SESSION["service_payment_flash_error"], $_SESSION["service_payment_form"]);
header("Location: /pages/customer/custo1_printing_option.php?new_queue=1");
exit();
