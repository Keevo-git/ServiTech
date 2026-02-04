<?php
// queue_create.php (PDO version)

require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");

$user_id = $_SESSION["user_id"] ?? 0;
if ($user_id <= 0) {
    echo json_encode(["ok" => false, "error" => "Not logged in"]);
    exit();
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(["ok" => false, "error" => "Invalid request"]);
    exit();
}

$category      = strtolower(trim($data["category"] ?? "general"));
$service_label = trim($data["service_label"] ?? "");
$paper_size    = $data["paper_size"] ?? null;
$quantity      = max(1, (int)($data["quantity"] ?? 1));
$color_option  = $data["color_option"] ?? null;
$package_label = $data["package_label"] ?? null;
$lam_type      = $data["lamination_type"] ?? null;
$device_type   = $data["device_type"] ?? null;
$notes         = $data["notes"] ?? null;
$file_name     = $data["file_name"] ?? null;

if ($service_label === "") {
    echo json_encode(["ok" => false, "error" => "Service label required"]);
    exit();
}

// Queue prefix
$prefix = "P";
if ($category === "repair") $prefix = "R";
else if ($category === "installation") $prefix = "I";
else if ($category === "xerox") $prefix = "X";
else if ($category === "laminating") $prefix = "L";
else if ($category === "rush-id") $prefix = "ID";

$queue_code = $prefix . "-" . rand(100, 999) . "-" . substr(time(), -4);

$sql = "
INSERT INTO queues
(queue_code, user_id, category, service_label, paper_size, quantity,
 color_option, package_label, lamination_type, device_type, notes, file_name)
VALUES
(:queue_code, :user_id, :category, :service_label, :paper_size, :quantity,
 :color_option, :package_label, :lamination_type, :device_type, :notes, :file_name)
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":queue_code"      => $queue_code,
        ":user_id"         => $user_id,
        ":category"        => $category,
        ":service_label"   => $service_label,
        ":paper_size"      => $paper_size,
        ":quantity"        => $quantity,
        ":color_option"    => $color_option,
        ":package_label"   => $package_label,
        ":lamination_type" => $lam_type,
        ":device_type"     => $device_type,
        ":notes"           => $notes,
        ":file_name"       => $file_name
    ]);

    echo json_encode([
        "ok" => true,
        "queue_code" => $queue_code
    ]);
} catch (PDOException $e) {
    error_log("queue_create error: " . $e->getMessage());
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
