<?php
// Central auth gate + session settings
require_once __DIR__ . "/../config/session_check.php";

if (!isset($_SESSION["user_id"]) || (int)$_SESSION["user_id"] <= 0) {
    header("Location: " . servitech_url("/auth/log_in.html"));
    exit();
}
