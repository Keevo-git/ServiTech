<?php
// queue_list.php (PDO version)

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
    echo json_encode(["ok" => false, "error" => "Not logged in"]);
    exit();
}

$sql = "
SELECT
  id, queue_code, category, service_label,
  paper_size, quantity, color_option,
  package_label, lamination_type, device_type,
  notes, file_name, status, created_at
FROM queues
WHERE user_id = :user_id
ORDER BY created_at DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":user_id" => $user_id]);
    $queues = $stmt->fetchAll();

    echo json_encode([
        "ok" => true,
        "queues" => $queues
    ]);
    exit();

} catch (PDOException $e) {
    error_log("queue_list error: " . $e->getMessage());
    echo json_encode([
        "ok" => false,
        "error" => "DB error"
    ]);
    exit();
}
