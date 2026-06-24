<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/admin_notification_center.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
    $data = admin_notification_center_data($pdo);
    ob_start();
    admin_notification_render_items($data["notifications"] ?? []);
    $itemsHtml = (string)ob_get_clean();

    echo json_encode([
        "ok" => true,
        "unread_count" => (int)($data["unread_count"] ?? 0),
        "counts" => $data["counts"] ?? [],
        "signature" => hash("sha256", $itemsHtml),
        "items_html" => $itemsHtml,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log("admin notification snapshot error: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Unable to refresh notifications."]);
}
