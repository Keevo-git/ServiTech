<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/../../config/csrf.php";

header("Content-Type: application/json; charset=utf-8");

servitech_enforce_same_origin(true);
servitech_enforce_csrf_token(true);

$data = json_decode((string)file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Invalid request."]);
    exit();
}

$id = (int)($data["id"] ?? 0);
$status = trim((string)($data["status"] ?? ""));
$announcementSoftDeleteReady = admin_table_has_columns($pdo, "announcements", ["deleted_at"]);
$notDeletedPredicate = $announcementSoftDeleteReady ? " AND deleted_at IS NULL" : "";

if ($id <= 0 || !in_array($status, ["active", "hidden"], true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Invalid announcement status."]);
    exit();
}

try {
    if ($status === "active") {
        $pdo->beginTransaction();
        $pdo->exec("UPDATE announcements SET active = FALSE, updated_at = NOW() WHERE 1 = 1{$notDeletedPredicate}");

        $stmt = $pdo->prepare("UPDATE announcements SET active = TRUE, updated_at = NOW() WHERE id = :id{$notDeletedPredicate}");
        $stmt->execute([":id" => $id]);

        if ($stmt->rowCount() < 1) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(["ok" => false, "error" => "Announcement not found."]);
            exit();
        }

        $pdo->commit();
    } else {
        $stmt = $pdo->prepare("UPDATE announcements SET active = FALSE, updated_at = NOW() WHERE id = :id{$notDeletedPredicate}");
        $stmt->execute([":id" => $id]);

        if ($stmt->rowCount() < 1) {
            http_response_code(404);
            echo json_encode(["ok" => false, "error" => "Announcement not found."]);
            exit();
        }
    }

    echo json_encode(["ok" => true]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Unable to update announcement."]);
}
