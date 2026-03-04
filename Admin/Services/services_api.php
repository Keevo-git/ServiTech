<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");

// ensure table exists
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS `services` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `category` ENUM('printing','repair','installation') NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    `price` DECIMAL(10,2) NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_services_category` (`category`),
    KEY `idx_services_active` (`active`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {}

$action = $_POST["action"] ?? $_GET["action"] ?? "";

function respond($arr){ echo json_encode($arr); exit(); }

if ($action === "list") {
  $cat = $_GET["category"] ?? "";
  $params = [];
  $where = "";
  if ($cat === "printing" || $cat === "repair" || $cat === "installation") {
    $where = "WHERE category = :cat";
    $params[":cat"] = $cat;
  }
  $stmt = $pdo->prepare("SELECT id, category, name, description, price, active, sort_order FROM services $where ORDER BY category ASC, sort_order ASC, id ASC");
  $stmt->execute($params);
  respond(["ok"=>true, "services"=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === "save") {
  $id = (int)($_POST["id"] ?? 0);
  $category = trim((string)($_POST["category"] ?? ""));
  $name = trim((string)($_POST["name"] ?? ""));
  $description = trim((string)($_POST["description"] ?? ""));
  $priceRaw = trim((string)($_POST["price"] ?? ""));
  $active = isset($_POST["active"]) ? (int)($_POST["active"]) : 1;
  $sort_order = isset($_POST["sort_order"]) ? (int)($_POST["sort_order"]) : 0;

  if (!in_array($category, ["printing","repair","installation"], true)) respond(["ok"=>false,"error"=>"Invalid category"]);
  if ($name === "") respond(["ok"=>false,"error"=>"Service name required"]);

  $price = null;
  if ($priceRaw !== "") {
    if (!is_numeric($priceRaw)) respond(["ok"=>false,"error"=>"Price must be a number"]);
    $price = (float)$priceRaw;
  }

  if ($id > 0) {
    $stmt = $pdo->prepare("UPDATE services SET category=:category, name=:name, description=:description, price=:price, active=:active, sort_order=:sort_order WHERE id=:id");
    $stmt->execute([
      ":category"=>$category,
      ":name"=>$name,
      ":description"=>$description,
      ":price"=>$price,
      ":active"=>$active ? 1 : 0,
      ":sort_order"=>$sort_order,
      ":id"=>$id
    ]);
    respond(["ok"=>true, "id"=>$id]);
  } else {
    $stmt = $pdo->prepare("INSERT INTO services (category,name,description,price,active,sort_order) VALUES (:category,:name,:description,:price,:active,:sort_order)");
    $stmt->execute([
      ":category"=>$category,
      ":name"=>$name,
      ":description"=>$description,
      ":price"=>$price,
      ":active"=>$active ? 1 : 0,
      ":sort_order"=>$sort_order
    ]);
    respond(["ok"=>true, "id"=>(int)$pdo->lastInsertId()]);
  }
}

if ($action === "delete") {
  $id = (int)($_POST["id"] ?? 0);
  if ($id <= 0) respond(["ok"=>false,"error"=>"Invalid id"]);
  $stmt = $pdo->prepare("DELETE FROM services WHERE id=?");
  $stmt->execute([$id]);
  respond(["ok"=>true]);
}

respond(["ok"=>false, "error"=>"Unknown action"]);
