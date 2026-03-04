<?php
require_once __DIR__ . "/session_check.php";

$_SESSION = [];
session_destroy();

header("Location: /ServiTech/main/log_in.html?logout=1");
exit();