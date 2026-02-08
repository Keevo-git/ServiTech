\
<?php
// /ServiTech/main/includes/auth.php
// Central auth gate + session settings (must match login.php + logout.php)

require_once __DIR__ . "/../session_check.php";

if (!isset($_SESSION["user_id"]) || (int)$_SESSION["user_id"] <= 0) {
    header("Location: /ServiTech/main/log_in.html");
    exit();
}
