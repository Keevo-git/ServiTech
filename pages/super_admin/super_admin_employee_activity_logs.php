<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/../admin/_includes/url.php";
require_once __DIR__ . "/../../config/activity_log.php";

function activity_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function activity_format_datetime($value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "-";
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone("Asia/Manila"))
            ->format("M d, Y h:i:s A");
    } catch (Throwable $exception) {
        return "-";
    }
}

function activity_decode_json($value): array
{
    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function activity_employee_name(array $log): string
{
    $name = trim((string)($log["user_name"] ?? ""));
    if ($name !== "") {
        return $name;
    }

    $email = trim((string)($log["user_email"] ?? ""));
    if ($email !== "") {
        return $email;
    }

    return servitech_role_label($log["role"] ?? "admin");
}

function activity_actor_phrase(array $log): string
{
    $name = activity_employee_name($log);
    $role = servitech_normalize_role($log["role"] ?? "admin");
    if ($role === "admin" && !preg_match('/^admin\b/i', $name)) {
        return "Admin " . $name;
    }

    return $name;
}

function activity_status_phrase($value, string $module = ""): string
{
    $status = strtoupper(trim((string)$value));
    if ($status === "") {
        return "";
    }

    if (strtolower(trim($module)) === "queue_management" && $status === "ONGOING") {
        return "Currently Serving";
    }

    return match ($status) {
        "DONE" => "Done",
        "APPROVED" => "Approved",
        "CANCELLED" => "Cancelled",
        "FOR PICK-UP" => "For Pick-Up",
        "ONGOING" => "Ongoing",
        default => ucwords(strtolower(str_replace(["_", "-"], " ", $status))),
    };
}

function activity_record_phrase(array $log, string $fallbackType = "Record"): string
{
    $recordId = trim((string)($log["target_record_id"] ?? ""));
    if ($recordId === "") {
        return strtolower($fallbackType);
    }

    $upper = strtoupper($recordId);
    if (str_starts_with($upper, "R") || str_starts_with($upper, "OP")) {
        return "Order {$recordId}";
    }
    if (str_starts_with($upper, "Q") || str_starts_with($upper, "P")) {
        return "Queue {$recordId}";
    }

    return "{$fallbackType} {$recordId}";
}

function activity_message_recipient(string $description): string
{
    if (preg_match('/\bto\s+(.+?)\.$/i', $description, $matches)) {
        $recipient = trim((string)$matches[1]);
        if ($recipient !== "") {
            return preg_match('/^customer\b/i', $recipient) ? $recipient : "customer {$recipient}";
        }
    }

    return "a customer";
}

function activity_readable_description(array $log): string
{
    $action = trim((string)($log["action_type"] ?? ""));
    $module = trim((string)($log["target_module"] ?? ""));
    $description = trim((string)($log["description"] ?? ""));
    $newValue = activity_decode_json($log["new_value"] ?? null);
    $actor = activity_actor_phrase($log);
    $name = activity_employee_name($log);

    return match ($action) {
        "super_admin_login_success", "admin_login_success" => "{$name} logged in successfully.",
        "logout" => "{$name} logged out from the admin area.",
        "order_mark_done" => "{$actor} marked " . activity_record_phrase($log, "Order") . " as Done.",
        "order_cancel" => "{$actor} cancelled " . activity_record_phrase($log, "Order") . ".",
        "order_reject" => "{$actor} rejected " . activity_record_phrase($log, "Order") . ".",
        "payment_approve" => "{$actor} approved the payment for " . activity_record_phrase($log, "Order") . ".",
        "payment_reject" => "{$actor} rejected the payment for " . activity_record_phrase($log, "Order") . ".",
        "payment_update" => "{$actor} updated payment details for " . activity_record_phrase($log, "Queue") . ".",
        "queue_send_back" => "{$actor} sent " . activity_record_phrase($log, "Queue") . " back to the customer for editing.",
        "queue_status_update", "queue_currently_serving_update", "queue_update", "order_status_update" => activity_readable_status_update($log, $actor, $module, $newValue),
        "customer_message_send" => activity_readable_customer_message($log, $actor, $module, $description),
        "employee_first_time_setup_complete" => "{$name} completed first-time account setup.",
        "admin_password_change" => "{$name} changed their password.",
        "unauthorized_access" => "{$name} was denied access to a Super Admin-only page.",
        "super_admin_wrong_role_login", "admin_wrong_role_login", "customer_wrong_role_login" => "{$name} tried to use the wrong login page and was blocked.",
        "employee_login_before_email_verification" => "{$name} tried to log in before verifying their email.",
        "repeated_failed_login" => "Repeated failed login attempts were detected for {$name}.",
        default => $description !== "" ? $description : "{$actor} completed an employee activity.",
    };
}

function activity_readable_status_update(array $log, string $actor, string $module, array $newValue): string
{
    $recordType = strtolower(trim($module)) === "order_management" ? "Order" : "Queue";
    $record = activity_record_phrase($log, $recordType);
    $newStatus = activity_status_phrase($newValue["status"] ?? "", $module);
    if ($newStatus !== "") {
        return "{$actor} updated {$record} to {$newStatus}.";
    }

    $description = trim((string)($log["description"] ?? ""));
    return $description !== "" ? $description : "{$actor} updated {$record}.";
}

function activity_readable_customer_message(array $log, string $actor, string $module, string $description): string
{
    if (in_array(strtolower(trim($module)), ["queue_messages", "queue_management"], true)) {
        return "{$actor} sent a message for " . activity_record_phrase($log, "Queue") . ".";
    }

    return "{$actor} sent a message to " . activity_message_recipient($description) . ".";
}

$schemaReady = admin_table_has_columns($pdo, "activity_logs", [
    "user_id",
    "user_name",
    "role",
    "action_type",
    "target_module",
    "target_record_id",
    "old_value",
    "new_value",
    "description",
    "created_at",
]);

$allowedActions = servitech_activity_allowed_action_types(true);

$filters = [
    "q" => trim((string)($_GET["q"] ?? "")),
    "user_id" => (int)($_GET["user_id"] ?? 0),
    "date_from" => trim((string)($_GET["date_from"] ?? "")),
    "date_to" => trim((string)($_GET["date_to"] ?? "")),
];

$staffOptions = [];
$logs = [];

if ($schemaReady) {
    $actionPlaceholders = [];
    $actionParams = [];
    foreach ($allowedActions as $index => $action) {
        $placeholder = ":allowed_action_" . $index;
        $actionPlaceholders[] = $placeholder;
        $actionParams[$placeholder] = $action;
    }
    $allowedActionSql = implode(", ", $actionPlaceholders);

    $staffStmt = $pdo->prepare("
        SELECT DISTINCT u.id, COALESCE(NULLIF(u.fullname, ''), u.email, 'Staff #' || u.id::text) AS label
        FROM users u
        JOIN activity_logs l ON l.user_id = u.id
        WHERE LOWER(TRIM(COALESCE(u.role, 'customer'))) IN ('admin', 'super_admin')
          AND l.action_type IN ({$allowedActionSql})
        ORDER BY label ASC
    ");
    $staffStmt->execute($actionParams);
    $staffOptions = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $where = [
        "LOWER(TRIM(COALESCE(l.role, 'customer'))) IN ('admin', 'super_admin')",
        "l.action_type IN ({$allowedActionSql})",
    ];
    $params = $actionParams;
    if ($filters["q"] !== "") {
        $where[] = "(LOWER(l.description) LIKE :q OR LOWER(l.user_name) LIKE :q OR LOWER(COALESCE(l.target_record_id, '')) LIKE :q OR LOWER(COALESCE(u.email, '')) LIKE :q)";
        $params[":q"] = "%" . strtolower($filters["q"]) . "%";
    }
    if ($filters["user_id"] > 0) {
        $where[] = "l.user_id = :user_id";
        $params[":user_id"] = $filters["user_id"];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters["date_from"])) {
        $where[] = "l.created_at >= CAST(:date_from AS date)";
        $params[":date_from"] = $filters["date_from"];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters["date_to"])) {
        $where[] = "l.created_at < (CAST(:date_to AS date) + INTERVAL '1 day')";
        $params[":date_to"] = $filters["date_to"];
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
    $stmt = $pdo->prepare("
        SELECT l.id, l.user_id, l.user_name, COALESCE(u.email, '') AS user_email,
               l.role, l.action_type, l.target_module, l.target_record_id,
               l.old_value::text AS old_value, l.new_value::text AS new_value,
               l.description, l.status, l.created_at
        FROM activity_logs l
        LEFT JOIN users u ON u.id = l.user_id
        {$whereSql}
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Employee Activity Logs | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260626-roles') ?>">
</head>
<body>
<?php require __DIR__ . "/../admin/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Super Admin</span>
    <h1>Employee Activity Logs</h1>
    <p>Review staff login, logout, order, queue, payment, and customer messaging activity.</p>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Activity logs need the 20260626 role migration before this page can be used.</div>
  <?php endif; ?>

  <section class="admin-owner-panel">
    <h2>Filters</h2>
    <form class="admin-owner-filters" method="get">
      <div class="admin-owner-field">
        <label for="q">Search</label>
        <input id="q" name="q" value="<?= activity_h($filters["q"]) ?>" placeholder="Name, description, record ID, email">
      </div>
      <div class="admin-owner-field">
        <label for="user_id">Employee</label>
        <select id="user_id" name="user_id">
          <option value="0">All</option>
          <?php foreach ($staffOptions as $staff): ?>
            <option value="<?= (int)$staff["id"] ?>"<?= $filters["user_id"] === (int)$staff["id"] ? " selected" : "" ?>><?= activity_h($staff["label"] ?? "") ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-owner-field">
        <label for="date_from">From</label>
        <input id="date_from" name="date_from" type="date" value="<?= activity_h($filters["date_from"]) ?>">
      </div>
      <div class="admin-owner-field">
        <label for="date_to">To</label>
        <input id="date_to" name="date_to" type="date" value="<?= activity_h($filters["date_to"]) ?>">
      </div>
      <div class="admin-owner-actions">
        <button class="admin-owner-button" type="submit">Apply</button>
        <a class="admin-owner-button-secondary" href="<?= admin_url('/pages/super_admin/super_admin_employee_activity_logs.php') ?>">Clear</a>
      </div>
    </form>
  </section>

  <section class="admin-owner-panel">
    <h2>Latest Activity</h2>
    <div class="admin-owner-table-wrap">
      <table class="admin-owner-table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Employee</th>
            <th>Description</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="4">No activity logs found.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <?php $status = strtolower((string)($log["status"] ?? "success")); ?>
          <tr>
            <td><?= activity_h(activity_format_datetime($log["created_at"] ?? "")) ?></td>
            <td>
              <strong><?= activity_h($log["user_name"] ?: "Unknown") ?></strong>
              <small><?= activity_h(servitech_role_label($log["role"] ?? "customer")) ?></small>
            </td>
            <td><?= activity_h(activity_readable_description($log)) ?></td>
            <td><span class="admin-owner-pill<?= $status === "failed" ? " admin-owner-pill--danger" : "" ?>"><?= activity_h(ucfirst($status)) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>
