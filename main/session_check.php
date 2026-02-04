<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

header("Content-Type: text/plain; charset=utf-8");

echo "OK SESSION_CHECK\n";
echo "URL: " . ($_SERVER["REQUEST_URI"] ?? "") . "\n";
echo "SID: " . session_id() . "\n";
echo "PHPSESSID cookie: " . ($_COOKIE["PHPSESSID"] ?? "NONE") . "\n\n";

echo "SESSION:\n";
print_r($_SESSION);

echo "\nCOOKIE:\n";
print_r($_COOKIE);
