<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

try {
  $notificationId = (int)($_POST["id"] ?? 0);
  $ids = [];
  if (isset($_POST["ids"])) {
    $decodedIds = json_decode((string)$_POST["ids"], true);
    if (!is_array($decodedIds)) {
      $decodedIds = explode(",", (string)$_POST["ids"]);
    }
    foreach ($decodedIds as $id) {
      if (is_numeric($id) && (int)$id > 0) {
        $ids[(int)$id] = (int)$id;
      }
    }
    $ids = array_values($ids);
  }

  if (!empty($_POST["mark_all"])) {
    $stmt = $pdo->prepare("
      UPDATE notifications
      SET is_read = TRUE
      WHERE user_id IN (
        SELECT id
        FROM users
        WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
      )
        AND is_read = FALSE
        AND deleted_at IS NULL
    ");
    $stmt->execute();

    echo json_encode(["ok" => true, "marked" => $stmt->rowCount()]);
    exit();
  }

  if ($notificationId > 0) {
    $stmt = $pdo->prepare("
      UPDATE notifications
      SET is_read = TRUE
      WHERE id = :id
        AND deleted_at IS NULL
        AND (
          user_id = :user_id
          OR user_id IN (
            SELECT id
            FROM users
            WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
          )
        )
    ");
    $stmt->execute([
      ":id" => $notificationId,
      ":user_id" => $_SESSION["user_id"] ?? 0,
    ]);

    echo json_encode(["ok" => true, "marked" => $stmt->rowCount()]);
    exit();
  }

  if ($ids) {
    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare("
      UPDATE notifications
      SET is_read = TRUE
      WHERE id IN ({$placeholders})
        AND deleted_at IS NULL
        AND (
          user_id = ?
          OR user_id IN (
            SELECT id
            FROM users
            WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
          )
        )
    ");
    $stmt->execute(array_merge($ids, [$_SESSION["user_id"] ?? 0]));

    echo json_encode(["ok" => true, "marked" => $stmt->rowCount()]);
    exit();
  }

  echo json_encode(["ok" => false, "error" => "Invalid request"]);
  exit();
} catch (Throwable $e) {
  error_log("admin_notification_mark_read error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "Database error"]);
  exit();
}
?>
