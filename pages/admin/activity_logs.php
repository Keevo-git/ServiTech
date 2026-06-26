<?php
require_once __DIR__ . "/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";

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

$schemaReady = admin_table_has_columns($pdo, "activity_logs", [
    "user_id",
    "user_name",
    "role",
    "action_type",
    "target_module",
    "target_record_id",
    "description",
    "created_at",
]);

$filters = [
    "q" => trim((string)($_GET["q"] ?? "")),
    "user_id" => (int)($_GET["user_id"] ?? 0),
    "action_type" => trim((string)($_GET["action_type"] ?? "")),
    "module" => trim((string)($_GET["module"] ?? "")),
    "date_from" => trim((string)($_GET["date_from"] ?? "")),
    "date_to" => trim((string)($_GET["date_to"] ?? "")),
];

$staffOptions = [];
$actionOptions = [];
$moduleOptions = [];
$logs = [];

if ($schemaReady) {
    $staffOptions = $pdo->query("
        SELECT DISTINCT u.id, COALESCE(NULLIF(u.fullname, ''), u.email, 'Staff #' || u.id::text) AS label
        FROM users u
        JOIN activity_logs l ON l.user_id = u.id
        WHERE LOWER(TRIM(COALESCE(u.role, 'customer'))) IN ('admin', 'super_admin')
        ORDER BY label ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $actionOptions = $pdo->query("
        SELECT DISTINCT action_type
        FROM activity_logs
        WHERE action_type <> ''
        ORDER BY action_type ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $moduleOptions = $pdo->query("
        SELECT DISTINCT target_module
        FROM activity_logs
        WHERE target_module <> ''
        ORDER BY target_module ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];
    if ($filters["q"] !== "") {
        $where[] = "(LOWER(l.description) LIKE :q OR LOWER(l.user_name) LIKE :q OR LOWER(COALESCE(l.target_record_id, '')) LIKE :q)";
        $params[":q"] = "%" . strtolower($filters["q"]) . "%";
    }
    if ($filters["user_id"] > 0) {
        $where[] = "l.user_id = :user_id";
        $params[":user_id"] = $filters["user_id"];
    }
    if ($filters["action_type"] !== "") {
        $where[] = "l.action_type = :action_type";
        $params[":action_type"] = $filters["action_type"];
    }
    if ($filters["module"] !== "") {
        $where[] = "l.target_module = :module";
        $params[":module"] = $filters["module"];
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
        SELECT l.id, l.user_id, l.user_name, l.role, l.action_type, l.target_module,
               l.target_record_id, l.description, l.ip_address::text AS ip_address,
               l.status, l.created_at
        FROM activity_logs l
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
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Super Admin</span>
    <h1>Employee Activity Logs</h1>
    <p>Review staff login, account management, order status, payment, and customer messaging activity.</p>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Activity logs need the 20260626 role migration before this page can be used.</div>
  <?php endif; ?>

  <section class="admin-owner-panel">
    <h2>Filters</h2>
    <form class="admin-owner-filters" method="get">
      <div class="admin-owner-field">
        <label for="q">Search</label>
        <input id="q" name="q" value="<?= activity_h($filters["q"]) ?>" placeholder="Name, description, record ID">
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
        <label for="action_type">Action</label>
        <select id="action_type" name="action_type">
          <option value="">All</option>
          <?php foreach ($actionOptions as $action): ?>
            <option value="<?= activity_h($action) ?>"<?= $filters["action_type"] === $action ? " selected" : "" ?>><?= activity_h($action) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-owner-field">
        <label for="module">Module</label>
        <select id="module" name="module">
          <option value="">All</option>
          <?php foreach ($moduleOptions as $module): ?>
            <option value="<?= activity_h($module) ?>"<?= $filters["module"] === $module ? " selected" : "" ?>><?= activity_h($module) ?></option>
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
        <a class="admin-owner-button-secondary" href="<?= admin_url('/pages/admin/activity_logs.php') ?>">Clear</a>
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
            <th>Action</th>
            <th>Module</th>
            <th>Description</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="6">No activity logs found.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <?php $status = strtolower((string)($log["status"] ?? "success")); ?>
          <tr>
            <td><?= activity_h(activity_format_datetime($log["created_at"] ?? "")) ?></td>
            <td>
              <strong><?= activity_h($log["user_name"] ?: "Unknown") ?></strong>
              <small><?= activity_h(servitech_role_label($log["role"] ?? "customer")) ?></small>
            </td>
            <td><?= activity_h($log["action_type"] ?? "") ?></td>
            <td>
              <?= activity_h($log["target_module"] ?? "") ?><br>
              <small><?= activity_h($log["target_record_id"] ?? "") ?></small>
            </td>
            <td>
              <?= activity_h($log["description"] ?? "") ?><br>
              <small>IP: <?= activity_h($log["ip_address"] ?? "-") ?></small>
            </td>
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
