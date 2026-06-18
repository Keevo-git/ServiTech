<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/../_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
$action = strtolower(trim((string)($_POST["action"] ?? "")));
$adminId = (int)($_SESSION["user_id"] ?? 0);

if (!admin_order_recycle_schema_ready($pdo)) {
    http_response_code(503);
    echo json_encode([
        "ok" => false,
        "error" => "Recycle Bin is unavailable until the database migration is applied.",
    ]);
    exit();
}

if ($id <= 0 || !in_array($action, ["soft_delete", "restore", "permanent_delete"], true)) {
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => "Invalid recycle-bin request."]);
    exit();
}

try {
    $pdo->exec("
      DELETE FROM queues
      WHERE UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER'
        AND deleted_at IS NOT NULL
        AND deleted_at <= NOW() - INTERVAL '30 days'
    ");

    $pdo->beginTransaction();

    $select = $pdo->prepare("
      SELECT id, queue_code, lifecycle_stage, deleted_at
      FROM queues
      WHERE id = :id
        AND UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER'
      LIMIT 1
      FOR UPDATE
    ");
    $select->execute([":id" => $id]);
    $queue = $select->fetch(PDO::FETCH_ASSOC);

    if (!is_array($queue)) {
        throw new DomainException("Order not found.");
    }

    if ($action === "soft_delete") {
        if (trim((string)($queue["deleted_at"] ?? "")) !== "") {
            throw new DomainException("This order is already in the Recycle Bin.");
        }

        $update = $pdo->prepare("
          UPDATE queues
          SET deleted_at = NOW(),
              deleted_by = :deleted_by,
              delete_reason = NULL,
              updated_at = NOW()
          WHERE id = :id
        ");
        $update->execute([
            ":deleted_by" => $adminId > 0 ? $adminId : null,
            ":id" => $id,
        ]);
        $message = "Order moved to the Recycle Bin.";
    } elseif ($action === "restore") {
        if (trim((string)($queue["deleted_at"] ?? "")) === "") {
            throw new DomainException("This order is not in the Recycle Bin.");
        }

        $update = $pdo->prepare("
          UPDATE queues
          SET deleted_at = NULL,
              deleted_by = NULL,
              delete_reason = NULL,
              updated_at = NOW()
          WHERE id = :id
        ");
        $update->execute([":id" => $id]);
        $message = "Order restored successfully.";
    } else {
        if (trim((string)($queue["deleted_at"] ?? "")) === "") {
            throw new DomainException("Move this order to the Recycle Bin before deleting it permanently.");
        }

        // Managed uploads use ON DELETE SET NULL, so queue deletion does not erase stored files.
        $delete = $pdo->prepare("DELETE FROM queues WHERE id = :id");
        $delete->execute([":id" => $id]);
        $message = "Order permanently deleted. Uploaded files were preserved.";
    }

    $pdo->commit();
    echo json_encode([
        "ok" => true,
        "message" => $message,
        "queue_code" => (string)($queue["queue_code"] ?? ""),
    ]);
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => $exception->getMessage()]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("order_recycle_action error: " . $exception->getMessage());
    http_response_code(500);
    $message = str_contains(strtolower($exception->getMessage()), "deleted_at")
        ? "Recycle Bin migration has not been applied yet."
        : "Unable to update this order.";
    echo json_encode(["ok" => false, "error" => $message]);
}
