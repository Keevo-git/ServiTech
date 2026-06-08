<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";
require_once __DIR__ . "/../_includes/queue_files.php";
require_once __DIR__ . "/../../../api/queue_payment.php";

$customerId = (int)($_GET["id"] ?? 0);

function cd_esc($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function cd_customer_code(int $id): string {
  return "C-" . str_pad((string)$id, 3, "0", STR_PAD_LEFT);
}

function cd_format_date($value): string {
  $value = trim((string)$value);
  if ($value === "") return "-";
  try {
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone("Asia/Manila"))->format("M d, Y h:i A");
  } catch (Throwable $exception) {
    return "-";
  }
}

function cd_filter_date($value): string {
  $value = trim((string)$value);
  if ($value === "") return "";
  try {
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone("Asia/Manila"))->format("Y-m-d");
  } catch (Throwable $exception) {
    return "";
  }
}

function cd_money($value): string {
  if (!is_numeric($value)) return "PHP 0.00";
  return "PHP " . number_format((float)$value, 2);
}

function cd_details_array($details): array {
  if (is_array($details)) return $details;
  if (is_string($details) && trim($details) !== "") {
    $decoded = json_decode($details, true);
    return is_array($decoded) ? $decoded : [];
  }
  return [];
}

function cd_category_label(string $category): string {
  $category = strtolower(trim($category));
  return match ($category) {
    "online_printorder" => "Online Printing",
    "printing" => "Printing",
    "repair" => "Repair",
    "installation" => "Installation",
    "walkin" => "Walk-in Printing",
    default => $category !== "" ? ucwords(str_replace("_", " ", $category)) : "-",
  };
}

function cd_detail_value(array $details, array $keys): string {
  foreach ($keys as $key) {
    $value = trim((string)($details[$key] ?? ""));
    if ($value !== "") return $value;
  }
  return "";
}

function cd_service_type(array $row): string {
  $details = cd_details_array($row["details"] ?? null);
  $label = cd_detail_value($details, ["service_label", "service_name", "service", "document_type", "request_type"]);
  return $label !== "" ? $label : cd_category_label((string)($row["category"] ?? ""));
}

function cd_payment_method(array $row): string {
  $details = cd_details_array($row["details"] ?? null);
  $method = strtolower(trim((string)($row["payment_method"] ?? ($details["payment_method"] ?? ""))));
  return match ($method) {
    "gcash" => "GCash",
    "cash" => "Cash",
    default => $method !== "" ? ucwords($method) : "-",
  };
}

function cd_filter_payment_method(array $row): string {
  $method = strtolower(cd_payment_method($row));
  return in_array($method, ["cash", "gcash"], true) ? $method : "";
}

function cd_filter_status(array $row): string {
  $status = strtolower(trim((string)($row["status"] ?? "")));
  $status = str_replace([" ", "-"], "_", $status);

  return match ($status) {
    "ongoing", "in_progress", "processing" => "ongoing",
    "for_pickup", "for_pick_up", "pickup", "ready_for_pickup", "ready_for_pick_up" => "for-pickup",
    "done", "completed", "complete" => "done",
    "cancelled", "canceled" => "cancelled",
    default => "pending",
  };
}

function cd_status_label(array $row): string {
  return match (cd_filter_status($row)) {
    "ongoing" => "Ongoing",
    "for-pickup" => "For Pick-up",
    "done" => "Done",
    "cancelled" => "Cancelled",
    default => "Pending",
  };
}

function cd_status_class(array $row): string {
  return "cd-status--" . cd_filter_status($row);
}

function cd_payment_status(array $row): string {
  $status = strtoupper(trim((string)($row["status"] ?? "")));
  $paymentStatus = trim((string)($row["payment_status"] ?? ""));
  $payment = servitech_queue_payment_values($row);

  if (in_array($status, ["CANCELLED", "CANCELED"], true)) return "Cancelled";
  if (in_array($status, ["DONE", "COMPLETED"], true) || $payment["paid_pending"] <= 0 && $payment["price"] > 0) return "Paid / Verified";
  if ($paymentStatus !== "" && !in_array(strtoupper($paymentStatus), ["PENDING"], true)) return ucwords(strtolower($paymentStatus));
  if (cd_payment_method($row) !== "-" || trim((string)($row["reference_number"] ?? "")) !== "") return "Payment Submitted";
  if ($payment["price"] > 0) return "Pending Payment";
  return "-";
}

function cd_notes(array $details): string {
  return cd_detail_value($details, ["notes", "note", "additional_info", "additional_information", "other_request", "message"]);
}

function cd_history_payload(array $row, array $files, array $payment, string $reference, array $customer): string {
  $details = cd_details_array($row["details"] ?? null);
  $filePayload = [];
  foreach ($files as $file) {
    $url = trim((string)($file["url"] ?? ""));
    $filePayload[] = [
      "label" => trim((string)($file["label"] ?? "File")),
      "url" => $url,
      "downloadUrl" => $url !== "" ? str_replace("disposition=inline", "disposition=attachment", $url) : "",
    ];
  }

  return json_encode([
    "id" => (string)($row["queue_code"] ?: ("Order #" . $row["id"])),
    "customerName" => (string)($customer["fullname"] ?? "-"),
    "serviceCategory" => cd_category_label((string)($row["category"] ?? "")),
    "serviceName" => cd_service_type($row),
    "dateSubmitted" => cd_format_date($row["created_at"] ?? ""),
    "status" => cd_status_label($row),
    "statusClass" => cd_status_class($row),
    "paymentMethod" => cd_payment_method($row),
    "totalAmount" => cd_money($payment["price"] ?: ($row["amount"] ?? 0)),
    "paymentStatus" => cd_payment_status($row),
    "gcashReference" => $reference !== "" ? $reference : "-",
    "notes" => cd_notes($details),
    "files" => $filePayload,
  ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}

$customer = null;
if ($customerId > 0) {
  $stmt = $pdo->prepare("
    SELECT
      id,
      fullname,
      email,
      COALESCE(NULLIF(to_jsonb(users)->>'contacts', ''), NULLIF(to_jsonb(users)->>'contact', '')) AS contacts,
      to_jsonb(users)->>'created_at' AS created_at
    FROM users
    WHERE id = :id
      AND LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'customer'
    LIMIT 1
  ");
  $stmt->execute([":id" => $customerId]);
  $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$customer) {
  http_response_code(404);
}

$history = [];
$summary = [
  "pending_payment" => 0,
  "payment_submitted" => 0,
  "paid_verified" => 0,
  "cancelled" => 0,
];
$hasAttachedFiles = false;

if ($customer) {
  $historyStmt = $pdo->prepare("
    SELECT
      q.id,
      q.queue_code,
      q.category,
      q.status,
      q.details,
      q.price,
      q.paid_amount,
      q.created_at,
      q.completed_at,
      p.payment_method,
      p.reference_number,
      p.amount,
      p.status AS payment_status
    FROM queues q
    LEFT JOIN LATERAL (
      SELECT payment_method, reference_number, amount, status
      FROM payments
      WHERE queue_id = q.id
      ORDER BY id DESC
      LIMIT 1
    ) p ON TRUE
    WHERE q.user_id = :user_id
    ORDER BY q.created_at DESC, q.id DESC
  ");
  $historyStmt->execute([":user_id" => $customerId]);
  $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($history as $row) {
    $paymentStatus = cd_payment_status($row);
    if ($paymentStatus === "Cancelled") $summary["cancelled"]++;
    elseif ($paymentStatus === "Paid / Verified") $summary["paid_verified"]++;
    elseif ($paymentStatus === "Payment Submitted") $summary["payment_submitted"]++;
    elseif ($paymentStatus === "Pending Payment") $summary["pending_payment"]++;
    if (!$hasAttachedFiles && admin_queue_file_items($row["details"] ?? null)) {
      $hasAttachedFiles = true;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Customer Details</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260604-admin-mobile-nav') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/customer_list/custoL.css?v=20260608-history-table') ?>">
  <style>
    .cd-historyFilters{
      display:grid;
      grid-template-columns:minmax(190px,1fr) minmax(220px,1.12fr) minmax(240px,1.18fr) auto;
      align-items:end;
      gap:16px;
      margin:0 0 18px;
      padding:16px;
      background:#f5f8fc;
      border:1px solid #d9e4f2;
      border-radius:16px;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.8);
    }
    .cd-filterField{
      display:flex;
      flex-direction:column;
      gap:8px;
      min-width:0;
    }
    .cd-filterField label{
      color:#52677f;
      font-size:13px;
      font-weight:800;
    }
    .cd-filterField input,
    .cd-filterField select{
      width:100%;
      height:48px;
      border:1px solid #cbd8e7;
      border-radius:12px;
      background:#fff;
      color:#112338;
      padding:0 14px;
      font-size:14px;
      font-weight:600;
      outline:none;
      box-sizing:border-box;
      box-shadow:0 8px 18px rgba(19,45,75,.05);
    }
    .cd-filterField input:focus,
    .cd-filterField select:focus{
      border-color:#1f4a8a;
      box-shadow:0 0 0 3px rgba(31,74,138,.12);
    }
    .cd-clearFilters{
      height:48px;
      min-width:142px;
      border-radius:12px;
      border-color:#c4d2e2;
      color:#1d334f;
    }
    .cd-historyTableWrap{
      width:100%;
      max-width:100%;
      overflow-x:auto;
      -webkit-overflow-scrolling:touch;
      border:1px solid #dbe5f1;
      border-radius:16px;
      background:#fff;
    }
    .cd-historyTable{
      width:100%;
      min-width:1120px;
      border-collapse:separate;
      border-spacing:0;
    }
    .cd-historyTable th{
      background:#edf3fb;
      color:#1e3f69;
      padding:14px 12px;
      text-align:left;
      font-size:12px;
      font-weight:800;
      border-bottom:1px solid #dbe5f1;
      white-space:nowrap;
    }
    .cd-historyTable td{
      padding:14px 12px;
      border-bottom:1px solid #e6ecf4;
      background:#fff;
      color:#112338;
      font-size:13px;
      font-weight:650;
      vertical-align:middle;
    }
    .cd-historyTable tbody tr:last-child td{ border-bottom:none; }
    .cd-historyTable tbody tr:hover td{ background:#f8fbff; }
    .cd-serviceName{
      display:block;
      min-width:170px;
      max-width:260px;
      color:#112338;
      font-size:14px;
      line-height:1.3;
      font-weight:800;
      overflow-wrap:anywhere;
    }
    .cd-fileChip,
    .cd-mutedText{
      display:inline-flex;
      align-items:center;
      white-space:nowrap;
      font-size:12px;
      font-weight:800;
    }
    .cd-fileChip{
      justify-content:center;
      border-radius:999px;
      padding:7px 10px;
      background:#eef6ff;
      border:1px solid #cfe0f4;
      color:#1f4a8a;
    }
    .cd-mutedText{ color:#5e6f85; }
    .cd-rowActions{
      display:flex;
      align-items:center;
      gap:8px;
      flex-wrap:nowrap;
    }
    .cd-rowBtn{
      min-height:36px;
      padding:8px 12px;
      border-radius:10px;
      white-space:nowrap;
      font-size:12px;
    }
    .cd-statusBadge{
      border:1px solid transparent;
    }
    .cd-status--pending{
      background:#fff7e6;
      color:#a15c00;
      border-color:#f6d99a;
    }
    .cd-status--ongoing{
      background:#e8f1ff;
      color:#1f4a8a;
      border-color:#c8dcf6;
    }
    .cd-status--for-pickup{
      background:#f2ecff;
      color:#6d3bbd;
      border-color:#d9c7ff;
    }
    .cd-status--done{
      background:#e9f8ef;
      color:#0f7a3a;
      border-color:#bfe8cc;
    }
    .cd-status--cancelled{
      background:#feecec;
      color:#b42318;
      border-color:#f9c7c7;
    }
    .cd-filterEmpty[hidden]{ display:none; }
    .cd-detailOverlay{ overflow-x:hidden; }
    .cd-detailModal{
      width:min(760px, calc(100vw - 32px));
      max-height:88vh;
      overflow:hidden;
    }
    .cd-detailModal .cl-modalBody{
      max-height:88vh;
      overflow-y:auto;
      overflow-x:hidden;
    }
    .cd-detailGrid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:12px;
    }
    .cd-detailGrid > div,
    .cd-detailSection{
      background:#f8fbff;
      border:1px solid #dbe5f1;
      border-radius:14px;
      padding:14px;
      min-width:0;
    }
    .cd-detailGrid small{
      display:block;
      margin-bottom:6px;
      color:#5e6f85;
      font-size:12px;
      font-weight:800;
    }
    .cd-detailGrid strong{
      color:#112338;
      font-size:14px;
      line-height:1.35;
      overflow-wrap:anywhere;
    }
    .cd-detailSection{
      margin-top:14px;
    }
    .cd-detailSection h4{
      margin:0 0 10px;
      color:#1f4a8a;
    }
    .cd-detailSection p{
      margin:0;
      color:#112338;
      font-weight:650;
      line-height:1.5;
      overflow-wrap:anywhere;
    }
    .cd-detailFiles{
      display:grid;
      gap:10px;
    }
    @media (max-width:900px){
      .cd-historyFilters{
        grid-template-columns:1fr;
        gap:12px;
      }
      .cd-clearFilters{
        width:100%;
      }
      .cd-detailGrid{
        grid-template-columns:1fr;
      }
    }
    @media (max-width:560px){
      .cd-rowActions{
        flex-direction:column;
        align-items:stretch;
      }
      .cd-rowBtn{
        width:100%;
        margin-bottom:0;
      }
    }
  </style>
</head>
<body>
  <?php
  $adminHeaderMenuId = "admin-customer-details-header-menu";
  $adminHeaderVariant = "special";
  require __DIR__ . "/../_includes/admin_header.php";
  ?>

  <div class="admin-wrapper">
    <section class="admin-hero cd-hero">
      <div>
        <span class="cd-kicker">Customer Details</span>
        <h1><?= $customer ? cd_esc($customer["fullname"] ?: "Customer") : "Customer Not Found" ?></h1>
        <p><?= $customer ? "Profile, service history, payments, and uploaded files." : "The selected customer record is unavailable." ?></p>
      </div>
      <a class="cl-btn cl-btn--light cd-backTop" href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Back to Customer List</a>
    </section>

    <main class="admin-container cl-main">
      <div class="cl-wrap cd-wrap">
        <?php if (!$customer): ?>
          <section class="cl-card cd-emptyState">
            <h2>Customer unavailable</h2>
            <p>This account may have been removed or the link is invalid.</p>
            <a class="cl-btn cl-btn--maroon" href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Back to Customer List</a>
          </section>
        <?php else: ?>
          <section class="cd-profileGrid">
            <article class="cl-card cd-profileCard">
              <span class="cd-cardLabel">Customer ID</span>
              <strong><?= cd_esc(cd_customer_code((int)$customer["id"])) ?></strong>
              <small>Internal ID: <?= (int)$customer["id"] ?></small>
            </article>
            <article class="cl-card cd-profileCard">
              <span class="cd-cardLabel">Full Name</span>
              <strong><?= cd_esc($customer["fullname"] ?: "-") ?></strong>
              <small><?= cd_esc($customer["email"] ?: "-") ?></small>
            </article>
            <article class="cl-card cd-profileCard">
              <span class="cd-cardLabel">Contact</span>
              <strong><?= cd_esc($customer["contacts"] ?: "-") ?></strong>
              <small>Account created: <?= cd_esc(cd_format_date($customer["created_at"] ?? "")) ?></small>
            </article>
          </section>

          <section class="cd-summaryGrid">
            <article class="cd-summaryCard cd-summaryCard--pending"><span>Pending Payment</span><strong><?= $summary["pending_payment"] ?></strong></article>
            <article class="cd-summaryCard cd-summaryCard--submitted"><span>Payment Submitted</span><strong><?= $summary["payment_submitted"] ?></strong></article>
            <article class="cd-summaryCard cd-summaryCard--paid"><span>Paid / Verified</span><strong><?= $summary["paid_verified"] ?></strong></article>
            <article class="cd-summaryCard cd-summaryCard--cancelled"><span>Cancelled Orders</span><strong><?= $summary["cancelled"] ?></strong></article>
          </section>

          <section class="cl-card cd-actionsCard">
            <div>
              <h2>Actions</h2>
              <p>Send a direct notification or return to the customer list.</p>
            </div>
            <div class="cd-actionButtons">
              <button
                class="cl-btn cl-btn--maroon"
                type="button"
                data-detail-message
                data-customer-id="<?= (int)$customer["id"] ?>"
                data-customer-code="<?= cd_esc(cd_customer_code((int)$customer["id"])) ?>"
                data-customer-name="<?= cd_esc($customer["fullname"] ?? "") ?>"
                data-customer-email="<?= cd_esc($customer["email"] ?? "") ?>"
              >Message Customer</button>
              <?php if ($hasAttachedFiles): ?>
                <a class="cl-btn cl-btn--light" href="#customerAttachedFiles">View Attached Files</a>
              <?php endif; ?>
              <a class="cl-btn cl-btn--light" href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Back to Customer List</a>
            </div>
          </section>

          <section class="cl-card cd-historyCard">
            <div class="cd-sectionHead">
              <div>
                <h2>Order / Queue History</h2>
                <p><?= count($history) ?> service record<?= count($history) === 1 ? "" : "s" ?> found.</p>
              </div>
            </div>

            <?php if (!$history): ?>
              <div class="cd-noHistory">No queue or order history yet.</div>
            <?php else: ?>
              <div class="cd-historyFilters" data-history-filters>
                <div class="cd-filterField">
                  <label for="historyFilterDate">Queued Date</label>
                  <input id="historyFilterDate" type="date" data-history-date>
                </div>
                <div class="cd-filterField">
                  <label for="historyFilterStatus">Status</label>
                  <select id="historyFilterStatus" data-history-status>
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="for-pickup">For Pick-up</option>
                    <option value="done">Done</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>
                <div class="cd-filterField">
                  <label for="historyFilterPayment">Mode of Payment</label>
                  <select id="historyFilterPayment" data-history-payment>
                    <option value="">All payment modes</option>
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                  </select>
                </div>
                <button class="cl-btn cl-btn--light cd-clearFilters" type="button" data-history-clear>Clear Filters</button>
              </div>

              <div class="cd-historyTableWrap">
                <table class="cd-historyTable">
                  <thead>
                    <tr>
                      <th>Order / Queue ID</th>
                      <th>Service</th>
                      <th>Date Submitted</th>
                      <th>Status</th>
                      <th>Payment Method</th>
                      <th>Total Amount</th>
                      <th>Payment Status</th>
                      <th>Files</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                <?php $fileAnchorRendered = false; ?>
                <?php foreach ($history as $row): ?>
                  <?php
                    $files = admin_queue_file_items($row["details"] ?? null);
                    $payment = servitech_queue_payment_values($row);
                    $reference = trim((string)($row["reference_number"] ?? ""));
                    $details = cd_details_array($row["details"] ?? null);
                    if ($reference === "") $reference = trim((string)($details["reference_number"] ?? ""));
                    $fileCount = count($files);
                    $fileBlockId = "";
                    if (!$fileAnchorRendered && $files) {
                      $fileBlockId = ' id="customerAttachedFiles"';
                      $fileAnchorRendered = true;
                    }
                  ?>
                  <tr
                    data-history-item
                    data-history-date="<?= cd_esc(cd_filter_date($row["created_at"] ?? "")) ?>"
                    data-history-status="<?= cd_esc(cd_filter_status($row)) ?>"
                    data-history-payment="<?= cd_esc(cd_filter_payment_method($row)) ?>"
                  >
                    <td><span class="cl-idPill"><?= cd_esc($row["queue_code"] ?: ("Order #" . $row["id"])) ?></span></td>
                    <td>
                      <span class="cd-serviceName"><?= cd_esc(cd_service_type($row)) ?></span>
                    </td>
                    <td><?= cd_esc(cd_format_date($row["created_at"] ?? "")) ?></td>
                    <td><span class="cd-statusBadge <?= cd_esc(cd_status_class($row)) ?>"><?= cd_esc(cd_status_label($row)) ?></span></td>
                    <td><?= cd_esc(cd_payment_method($row)) ?></td>
                    <td><?= cd_esc(cd_money($payment["price"] ?: ($row["amount"] ?? 0))) ?></td>
                    <td><?= cd_esc(cd_payment_status($row)) ?></td>
                    <td<?= $fileBlockId ?>>
                      <?php if ($fileCount > 0): ?>
                        <span class="cd-fileChip"><?= $fileCount ?> file<?= $fileCount === 1 ? "" : "s" ?></span>
                      <?php else: ?>
                        <span class="cd-mutedText">No files</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="cd-rowActions">
                        <button
                          class="cl-btn cl-btn--light cd-rowBtn"
                          type="button"
                          data-history-view
                          data-history-detail="<?= cd_esc(cd_history_payload($row, $files, $payment, $reference, $customer)) ?>"
                        >View Details</button>
                        <button
                          class="cl-btn cl-btn--maroon cd-rowBtn"
                          type="button"
                          data-detail-message
                          data-customer-id="<?= (int)$customer["id"] ?>"
                          data-customer-code="<?= cd_esc(cd_customer_code((int)$customer["id"])) ?>"
                          data-customer-name="<?= cd_esc($customer["fullname"] ?? "") ?>"
                          data-customer-email="<?= cd_esc($customer["email"] ?? "") ?>"
                        >Message</button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="cd-noHistory cd-filterEmpty" data-history-empty hidden>No order or queue history matches the selected filters.</div>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

  <?php if ($customer): ?>
    <div class="cl-modalOverlay cd-detailOverlay" id="historyDetailModal" aria-hidden="true">
      <div class="cl-modalCard cd-detailModal" role="dialog" aria-modal="true" aria-labelledby="historyDetailTitle">
        <div class="cl-modalBody">
          <div class="cl-modalHead">
            <div>
              <h3 id="historyDetailTitle">Order / Queue Details</h3>
              <span class="cl-pill cl-pill--inline" data-detail-id>-</span>
            </div>
            <button class="cl-modalX" type="button" data-history-detail-close aria-label="Close">&times;</button>
          </div>

          <div class="cd-detailGrid">
            <div><small>Customer</small><strong data-detail-customer>-</strong></div>
            <div><small>Service Category</small><strong data-detail-category>-</strong></div>
            <div><small>Service Name</small><strong data-detail-service>-</strong></div>
            <div><small>Date Submitted</small><strong data-detail-date>-</strong></div>
            <div><small>Status</small><span class="cd-statusBadge" data-detail-status>-</span></div>
            <div><small>Payment Method</small><strong data-detail-payment-method>-</strong></div>
            <div><small>Total Amount</small><strong data-detail-total>-</strong></div>
            <div><small>Payment Status</small><strong data-detail-payment-status>-</strong></div>
            <div><small>GCash Reference</small><strong data-detail-reference>-</strong></div>
          </div>

          <div class="cd-detailSection">
            <h4>Attached Files</h4>
            <div class="cd-detailFiles" data-detail-files></div>
          </div>

          <div class="cd-detailSection" data-detail-notes-wrap hidden>
            <h4>Notes</h4>
            <p data-detail-notes></p>
          </div>
        </div>
      </div>
    </div>

    <div class="cl-modalOverlay" id="customerMessageModal" aria-hidden="true">
      <div class="cl-modalCard" role="dialog" aria-modal="true" aria-labelledby="customerMessageTitle">
        <div class="cl-modalBody">
          <div class="cl-modalHead">
            <div>
              <h3 id="customerMessageTitle">Message Customer</h3>
              <span class="cl-pill cl-pill--inline" id="messageCustomerCode"><?= cd_esc(cd_customer_code((int)$customer["id"])) ?></span>
            </div>
            <button class="cl-modalX" type="button" id="customerMessageClose" aria-label="Close">&times;</button>
          </div>
          <div class="cl-infoCard">
            <p class="cl-infoTitle">Customer</p>
            <div class="cl-infoGrid">
              <div><small>Name</small><div class="cl-infoVal" id="messageCustomerName"><?= cd_esc($customer["fullname"] ?? "-") ?></div></div>
              <div><small>Email</small><div class="cl-infoVal" id="messageCustomerEmail"><?= cd_esc($customer["email"] ?? "-") ?></div></div>
            </div>
          </div>
          <div class="cl-section">
            <label class="cl-sectionTitle" for="customerMessageText">Message</label>
            <textarea class="cl-textarea" id="customerMessageText" rows="6" placeholder="Type your message to this customer..."></textarea>
            <p class="cl-msgStatus" id="customerMessageStatus" aria-live="polite"></p>
          </div>
          <div class="cl-actions">
            <button class="cl-btn cl-btn--light" type="button" id="customerMessageCancel">Cancel</button>
            <button class="cl-btn cl-btn--maroon" type="button" id="customerMessageSend">Send Message</button>
          </div>
        </div>
      </div>
    </div>

    <script>
      const modal = document.getElementById('customerMessageModal');
      const messageText = document.getElementById('customerMessageText');
      const messageStatus = document.getElementById('customerMessageStatus');
      const sendBtn = document.getElementById('customerMessageSend');
      const endpoint = <?= json_encode(admin_url_raw('/pages/admin/customer_list/send_customer_message.php')) ?>;
      const customerId = <?= (int)$customer["id"] ?>;

      function setStatus(text, tone = '') {
        messageStatus.textContent = text || '';
        messageStatus.dataset.tone = tone;
      }
      function openModal() {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        setStatus('');
        setTimeout(() => messageText.focus(), 40);
      }
      function closeModal() {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        messageText.value = '';
      }

      document.querySelectorAll('[data-detail-message]').forEach(button => {
        button.addEventListener('click', openModal);
      });
      document.getElementById('customerMessageClose')?.addEventListener('click', closeModal);
      document.getElementById('customerMessageCancel')?.addEventListener('click', closeModal);
      modal?.addEventListener('click', event => { if (event.target === modal) closeModal(); });
      document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal?.getAttribute('aria-hidden') === 'false') closeModal(); });

      sendBtn?.addEventListener('click', async () => {
        const message = (messageText.value || '').trim();
        if (!message) {
          setStatus('Please type a message before sending.', 'error');
          messageText.focus();
          return;
        }
        const formData = new FormData();
        formData.append('customer_id', String(customerId));
        formData.append('message', message);
        formData.append('csrf_token', typeof window.servitechCsrfToken === 'function' ? window.servitechCsrfToken() : '');
        sendBtn.disabled = true;
        setStatus('Sending message...', 'pending');
        try {
          const response = await fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': typeof window.servitechCsrfToken === 'function' ? window.servitechCsrfToken() : '' },
            credentials: 'same-origin'
          });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || !data.ok) throw new Error(data.error || 'Message could not be sent.');
          setStatus(data.message || 'Message added to customer notifications.', 'success');
          setTimeout(closeModal, 900);
        } catch (error) {
          setStatus(error.message || 'Message could not be sent.', 'error');
        } finally {
          sendBtn.disabled = false;
        }
      });

      const historyFilters = document.querySelector('[data-history-filters]');
      if (historyFilters) {
        const dateInput = historyFilters.querySelector('[data-history-date]');
        const statusSelect = historyFilters.querySelector('[data-history-status]');
        const paymentSelect = historyFilters.querySelector('[data-history-payment]');
        const clearFilters = historyFilters.querySelector('[data-history-clear]');
        const historyItems = Array.from(document.querySelectorAll('[data-history-item]'));
        const emptyState = document.querySelector('[data-history-empty]');

        function applyHistoryFilters() {
          const selectedDate = dateInput?.value || '';
          const selectedStatus = statusSelect?.value || '';
          const selectedPayment = paymentSelect?.value || '';
          let visibleCount = 0;

          historyItems.forEach(item => {
            const matchesDate = !selectedDate || item.dataset.historyDate === selectedDate;
            const matchesStatus = !selectedStatus || item.dataset.historyStatus === selectedStatus;
            const matchesPayment = !selectedPayment || item.dataset.historyPayment === selectedPayment;
            const shouldShow = matchesDate && matchesStatus && matchesPayment;
            item.hidden = !shouldShow;
            if (shouldShow) visibleCount++;
          });

          if (emptyState) emptyState.hidden = visibleCount !== 0;
        }

        [dateInput, statusSelect, paymentSelect].forEach(control => {
          control?.addEventListener('input', applyHistoryFilters);
          control?.addEventListener('change', applyHistoryFilters);
        });

        clearFilters?.addEventListener('click', () => {
          if (dateInput) dateInput.value = '';
          if (statusSelect) statusSelect.value = '';
          if (paymentSelect) paymentSelect.value = '';
          applyHistoryFilters();
        });
      }

      const detailModal = document.getElementById('historyDetailModal');
      if (detailModal) {
        const detailStatus = detailModal.querySelector('[data-detail-status]');
        const detailFiles = detailModal.querySelector('[data-detail-files]');
        const detailNotesWrap = detailModal.querySelector('[data-detail-notes-wrap]');
        const detailNotes = detailModal.querySelector('[data-detail-notes]');
        const statusClasses = ['cd-status--pending', 'cd-status--ongoing', 'cd-status--for-pickup', 'cd-status--done', 'cd-status--cancelled'];

        function setDetailText(selector, value) {
          const target = detailModal.querySelector(selector);
          if (target) target.textContent = value || '-';
        }

        function renderDetailFiles(files) {
          detailFiles.textContent = '';
          if (!Array.isArray(files) || files.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'cd-fileUnavailable';
            empty.textContent = 'No files';
            detailFiles.appendChild(empty);
            return;
          }

          files.forEach(file => {
            const item = document.createElement('div');
            item.className = 'cd-fileItem';

            const label = document.createElement('span');
            label.textContent = file.label || 'File';
            item.appendChild(label);

            if (file.url) {
              const actions = document.createElement('span');
              actions.className = 'cd-fileActions';

              const openLink = document.createElement('a');
              openLink.href = file.url;
              openLink.target = '_blank';
              openLink.rel = 'noopener noreferrer';
              openLink.textContent = 'Open';
              actions.appendChild(openLink);

              const downloadLink = document.createElement('a');
              downloadLink.href = file.downloadUrl || file.url;
              downloadLink.setAttribute('download', '');
              downloadLink.textContent = 'Download';
              actions.appendChild(downloadLink);

              item.appendChild(actions);
            } else {
              const unavailable = document.createElement('span');
              unavailable.className = 'cd-fileUnavailable';
              unavailable.textContent = 'File unavailable';
              item.appendChild(unavailable);
            }

            detailFiles.appendChild(item);
          });
        }

        function openDetailModal(payload) {
          setDetailText('[data-detail-id]', payload.id);
          setDetailText('[data-detail-customer]', payload.customerName);
          setDetailText('[data-detail-category]', payload.serviceCategory);
          setDetailText('[data-detail-service]', payload.serviceName);
          setDetailText('[data-detail-date]', payload.dateSubmitted);
          setDetailText('[data-detail-payment-method]', payload.paymentMethod);
          setDetailText('[data-detail-total]', payload.totalAmount);
          setDetailText('[data-detail-payment-status]', payload.paymentStatus);
          setDetailText('[data-detail-reference]', payload.gcashReference);

          if (detailStatus) {
            detailStatus.classList.remove(...statusClasses);
            if (payload.statusClass) detailStatus.classList.add(payload.statusClass);
            detailStatus.textContent = payload.status || '-';
          }

          renderDetailFiles(payload.files);

          const notes = (payload.notes || '').trim();
          if (detailNotesWrap && detailNotes) {
            detailNotesWrap.hidden = notes === '';
            detailNotes.textContent = notes;
          }

          detailModal.style.display = 'flex';
          detailModal.setAttribute('aria-hidden', 'false');
        }

        function closeDetailModal() {
          detailModal.style.display = 'none';
          detailModal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('[data-history-view]').forEach(button => {
          button.addEventListener('click', () => {
            try {
              openDetailModal(JSON.parse(button.dataset.historyDetail || '{}'));
            } catch (error) {
              openDetailModal({});
            }
          });
        });

        detailModal.querySelector('[data-history-detail-close]')?.addEventListener('click', closeDetailModal);
        detailModal.addEventListener('click', event => { if (event.target === detailModal) closeDetailModal(); });
        document.addEventListener('keydown', event => {
          if (event.key === 'Escape' && detailModal.getAttribute('aria-hidden') === 'false') closeDetailModal();
        });
      }
    </script>
  <?php endif; ?>
  <script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
