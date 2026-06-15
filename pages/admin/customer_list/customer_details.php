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
    "online_printorder" => "Printing",
    "printing" => "Printing",
    "repair" => "Repair",
    "installation" => "Installation",
    "walkin", "printing_walkin" => "Printing",
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
  $category = strtolower(trim((string)($row["category"] ?? "")));
  $normalizedLabel = strtolower(trim(preg_replace('/[\s_-]+/', ' ', $label)));
  $legacyPrintingLabels = [
    "online print order",
    "online document printing",
    "online printing",
    "walk in printing",
    "walkin printing",
  ];

  if (
    in_array($normalizedLabel, $legacyPrintingLabels, true)
    || in_array($category, ["online_printorder", "walkin", "printing_walkin"], true)
  ) {
    return "Document Printing";
  }

  return $label !== "" ? $label : cd_category_label($category);
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
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/customer_list/custoL.css?v=20260608-history-table') ?>">
  <style>
    html,
    body{
      max-width:100%;
      overflow-x:hidden;
    }
    .customer-details-container{
      width:min(1180px, 100%);
      max-width:1180px;
      margin-left:auto;
      margin-right:auto;
      box-sizing:border-box;
    }
    .admin-hero.customer-details-container{
      margin-bottom:20px;
    }
    .admin-container.cl-main.customer-details-container{
      padding:0 0 40px;
    }
    .cd-wrap{
      width:100%;
      max-width:none;
    }
    .cd-hero{
      position:relative;
      overflow:hidden;
      padding:34px 38px;
      border-radius:24px;
      background:linear-gradient(135deg, #1e3a5f, #2f6fa8);
      box-shadow:0 18px 40px rgba(15,23,42,.14);
      isolation:isolate;
    }
    .cd-hero::before,
    .cd-hero::after{
      content:"";
      position:absolute;
      z-index:0;
      border-radius:999px;
      pointer-events:none;
    }
    .cd-hero::before{
      right:90px;
      bottom:-115px;
      width:260px;
      height:260px;
      background:rgba(255,255,255,.045);
    }
    .cd-hero::after{
      top:-105px;
      right:-65px;
      width:245px;
      height:245px;
      background:rgba(255,255,255,.08);
    }
    .cd-heroContent{
      position:relative;
      z-index:1;
      min-width:0;
      max-width:760px;
    }
    .cd-hero h1{
      font-size:clamp(1.7rem, 3vw, 2.35rem);
      line-height:1.12;
      overflow-wrap:anywhere;
    }
    .cd-profileShell{
      background:#fff;
      border:1px solid rgba(37,99,235,.14);
      border-radius:24px;
      box-shadow:0 18px 45px rgba(15,23,42,.08);
      padding:30px;
      min-width:0;
    }
    .cd-profileHeader{
      display:grid;
      grid-template-columns:minmax(0, 1.6fr) minmax(320px, .9fr);
      gap:32px;
      align-items:start;
    }
    .cd-profileIdentity{
      min-width:0;
    }
    .cd-profileEyebrow{
      display:block;
      margin-bottom:8px;
      color:#2563eb;
      font-size:12px;
      font-weight:900;
      letter-spacing:.08em;
      text-transform:uppercase;
    }
    .cd-customerName{
      margin:0;
      color:#123f73;
      font-size:clamp(1.55rem, 3vw, 2rem);
      font-weight:900;
      line-height:1.15;
      overflow-wrap:anywhere;
    }
    .cd-customerEmail{
      display:inline-block;
      margin-top:8px;
      color:#52677f;
      font-weight:650;
      line-height:1.45;
      overflow-wrap:anywhere;
    }
    .cd-infoGrid{
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:18px 28px;
      margin-top:26px;
    }
    .cd-infoItem{
      min-width:0;
    }
    .cd-infoItem span{
      display:block;
      margin-bottom:5px;
      color:#64748b;
      font-size:11px;
      font-weight:850;
      letter-spacing:.07em;
      text-transform:uppercase;
    }
    .cd-infoItem strong{
      display:block;
      color:#0f172a;
      font-size:14px;
      font-weight:800;
      line-height:1.4;
      overflow-wrap:anywhere;
    }
    .cd-summaryPanel{
      min-width:0;
      padding-left:28px;
      border-left:1px solid #e5edf7;
    }
    .cd-summaryHeading{
      margin:0 0 14px;
      color:#1f4a8a;
      font-size:15px;
      font-weight:850;
    }
    .cd-summaryGrid{
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:14px;
    }
    .cd-summaryCard{
      display:flex;
      min-width:0;
      min-height:96px;
      padding:16px;
      flex-direction:column;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
      background:#f8fbff;
      border:1px solid #dbeafe;
      border-radius:16px;
      box-shadow:none;
    }
    .cd-summaryCard span{
      font-size:11px;
      line-height:1.35;
    }
    .cd-summaryCard strong{
      color:#1d4ed8;
      font-size:28px;
      line-height:1;
    }
    .cd-summaryCard--pending,
    .cd-summaryCard--submitted,
    .cd-summaryCard--paid,
    .cd-summaryCard--cancelled{
      border-left:1px solid #dbeafe;
    }
    .cd-summaryCard--pending strong{ color:#b56a00; }
    .cd-summaryCard--submitted strong{ color:#16869a; }
    .cd-summaryCard--paid strong{ color:#15803d; }
    .cd-summaryCard--cancelled strong{ color:#c52222; }
    .cd-actionsCard{
      padding:22px 26px;
      background:rgba(255,255,255,.96);
      border-color:rgba(37,99,235,.12);
      border-radius:20px;
      box-shadow:0 12px 30px rgba(15,23,42,.06);
    }
    .cd-actionsCopy{
      min-width:0;
    }
    .cd-actionsCopy p{
      max-width:520px;
    }
    .cd-actionButtons .cl-btn{
      min-height:46px;
      padding:0 22px;
      white-space:nowrap;
    }
    .cd-historyCard{
      min-width:0;
      padding:28px;
      border-color:rgba(37,99,235,.12);
      border-radius:24px;
      box-shadow:0 18px 45px rgba(15,23,42,.08);
      overflow:hidden;
    }
    .cd-sectionHead{
      margin-bottom:20px;
    }
    .cd-sectionHead h2{
      font-size:clamp(1.35rem, 2.5vw, 1.7rem);
    }
    .cd-historyFilters{
      display:grid;
      grid-template-columns:minmax(190px,1fr) minmax(220px,1.12fr) minmax(240px,1.18fr) auto;
      align-items:end;
      gap:16px;
      margin:0 0 20px;
      padding:16px;
      background:#f3f7fc;
      border:1px solid #dbeafe;
      border-radius:18px;
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
    .cd-filterField select{
      appearance:none;
      -webkit-appearance:none;
      padding-right:44px;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='m5 7.5 5 5 5-5' stroke='%231e3f69' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
      background-repeat:no-repeat;
      background-position:right 16px center;
      background-size:14px 14px;
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
      border-radius:18px;
      background:#fff;
    }
    .cd-historyTable{
      width:100%;
      min-width:1240px;
      border-collapse:separate;
      border-spacing:0;
    }
    .cd-historyTable th{
      background:#edf3fb;
      color:#1e3f69;
      padding:18px 20px;
      text-align:left;
      font-size:12px;
      font-weight:800;
      border-bottom:1px solid #dbe5f1;
      white-space:nowrap;
    }
    .cd-historyTable td{
      padding:18px 20px;
      border-bottom:1px solid #e6ecf4;
      background:#fff;
      color:#112338;
      font-size:13px;
      font-weight:650;
      line-height:1.4;
      vertical-align:middle;
      overflow-wrap:anywhere;
    }
    .cd-historyTable th:first-child,
    .cd-historyTable td:first-child{
      padding-left:20px;
    }
    .cd-historyTable th:nth-child(n+4),
    .cd-historyTable td:nth-child(n+4){
      text-align:center;
    }
    .cd-historyTable th:last-child,
    .cd-historyTable td:last-child{
      min-width:160px;
      padding-right:32px;
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
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:8px;
      width:100%;
    }
    .cd-rowBtn{
      width:120px;
      min-width:120px;
      min-height:36px;
      padding:8px 12px;
      border-radius:10px;
      white-space:nowrap;
      font-size:12px;
    }
    .customer-details-page .cl-btn,
    .customer-details-page .cl-modalX,
    .customer-details-page .cd-fileActions a{
      cursor:pointer;
      transition:
        background-color .2s ease,
        color .2s ease,
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease;
    }
    .customer-details-page .cl-btn:focus-visible,
    .customer-details-page .cl-modalX:focus-visible,
    .customer-details-page .cd-fileActions a:focus-visible{
      outline:3px solid rgba(37,99,235,.24);
      outline-offset:2px;
    }
    @media (hover:hover){
      .customer-details-page .cl-btn:hover,
      .customer-details-page .cl-modalX:hover,
      .customer-details-page .cd-fileActions a:hover{
        transform:translateY(-1px);
      }
      .customer-details-page .cl-btn--maroon:hover{
        background:#153f7a;
        border-color:#153f7a;
        box-shadow:0 10px 22px rgba(29,78,216,.24);
      }
      .customer-details-page .cl-btn--light:hover,
      .customer-details-page .cd-fileActions a:hover{
        background:#eff6ff;
        border-color:#2563eb;
        color:#1d4ed8;
        box-shadow:0 8px 18px rgba(15,23,42,.08);
      }
      .customer-details-page .cl-modalX:hover{
        background:#eff6ff;
        border-color:#bfdbfe !important;
        color:#174a7c;
        box-shadow:0 8px 18px rgba(15,23,42,.1);
      }
    }
    .customer-details-page .cl-btn:disabled{
      cursor:not-allowed;
      transform:none;
      opacity:.65;
    }
    .cd-statusBadge{
      width:110px;
      min-width:110px;
      height:34px;
      padding:0 14px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:999px;
      border:1px solid transparent;
      box-sizing:border-box;
      font-weight:700;
      line-height:1;
      white-space:nowrap;
      text-align:center;
    }
    .cd-status--pending{
      background:#fef3c7;
      color:#b45309;
      border-color:#fde68a;
    }
    .cd-status--ongoing{
      background:#dbeafe;
      color:#1d4ed8;
      border-color:#bfdbfe;
    }
    .cd-status--for-pickup{
      background:#f3e8ff;
      color:#7e22ce;
      border-color:#e9d5ff;
    }
    .cd-status--done{
      background:#dcfce7;
      color:#15803d;
      border-color:#bbf7d0;
    }
    .cd-status--cancelled{
      background:#fee2e2;
      color:#b91c1c;
      border-color:#fecaca;
    }
    .cd-filterEmpty[hidden]{ display:none; }
    .cd-detailOverlay{
      overflow-x:hidden;
      background:rgba(15,23,42,.68);
      backdrop-filter:blur(3px);
      padding:clamp(16px, 3vw, 32px);
    }
    .cd-detailModal{
      width:min(960px, 92vw);
      max-height:90vh;
      overflow:hidden;
      background:#fff;
      border:1px solid rgba(219,234,254,.9);
      border-radius:24px;
      box-shadow:0 30px 80px rgba(15,23,42,.28);
    }
    .cd-detailModal .cl-modalBody{
      display:flex;
      flex-direction:column;
      max-height:90vh;
      overflow:hidden;
      padding:0;
    }
    .cd-detailModalHead{
      position:relative;
      z-index:2;
      flex:0 0 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:24px;
      margin:0;
      padding:28px 38px;
      background:#fff;
      border-bottom:1px solid #e5edf7;
    }
    .cd-detailModalHead h3{
      margin:0;
      color:#123f73;
      font-size:clamp(1.4rem, 2.5vw, 1.8rem);
      font-weight:900;
      line-height:1.2;
    }
    .cd-detailModalHead .cl-modalX{
      position:static;
      flex:0 0 44px;
      width:44px !important;
      height:44px !important;
      min-width:44px;
      min-height:44px;
      padding:0 !important;
      border:1px solid #dbe5f3 !important;
      border-radius:50%;
      background:#f8fbff;
      color:#174a7c !important;
      font-family:Arial, sans-serif;
      font-size:0 !important;
      line-height:1 !important;
    }
    .cd-detailModalHead .cl-modalX span{
      display:block;
      font-size:25px;
      font-weight:500;
      line-height:1;
      transform:translateY(-1px);
    }
    .cd-detailModalContent{
      flex:1 1 auto;
      min-height:0;
      max-height:calc(90vh - 101px);
      overflow-y:auto;
      overflow-x:hidden;
      padding:32px 38px 38px;
      scrollbar-width:thin;
      scrollbar-color:#9fb6d1 transparent;
      scrollbar-gutter:stable;
    }
    .cd-detailModalContent::-webkit-scrollbar{
      width:8px;
    }
    .cd-detailModalContent::-webkit-scrollbar-track{
      background:transparent;
    }
    .cd-detailModalContent::-webkit-scrollbar-thumb{
      background:#9fb6d1;
      border:2px solid #fff;
      border-radius:999px;
    }
    .cd-detailOverview{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:20px;
      padding:24px;
      background:linear-gradient(135deg, #eff6ff, #f8fbff);
      border:1px solid #dbeafe;
      border-radius:18px;
      margin-bottom:18px;
    }
    .cd-detailItem{
      min-width:0;
    }
    .cd-detailItem small{
      display:block;
      margin-bottom:8px;
      color:#64748b;
      font-size:11px;
      font-weight:850;
      line-height:1.35;
      letter-spacing:.075em;
      text-transform:uppercase;
    }
    .cd-detailItem strong{
      display:block;
      color:#10233d;
      font-size:15px;
      font-weight:800;
      line-height:1.45;
      overflow-wrap:anywhere;
    }
    .cd-detailOverview .cd-statusBadge{
      max-width:100%;
    }
    .cd-detailSection{
      margin-bottom:18px;
      padding:24px;
      background:#f9fcff;
      border:1px solid #dbe7f3;
      border-radius:18px;
    }
    .cd-detailSection h4{
      margin:0 0 20px;
      color:#174a7c;
      font-size:17px;
      font-weight:900;
      line-height:1.3;
    }
    .cd-detailInfoGrid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:22px 28px;
    }
    .cd-detailPaymentGrid{
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .cd-detailSection p{
      margin:0;
      color:#112338;
      font-weight:650;
      line-height:1.5;
      overflow-wrap:anywhere;
    }
    .cd-detailSection:last-child{
      margin-bottom:0;
    }
    .cd-detailFiles{
      display:grid;
      gap:12px;
    }
    .cd-detailModal .cd-fileItem{
      gap:16px;
      padding:16px 18px;
      background:#fff;
      border:1px solid #dbeafe;
      border-radius:16px;
    }
    .cd-detailModal .cd-fileItem > span:first-child{
      flex:1 1 auto;
      min-width:0;
      color:#0f172a;
      font-weight:800;
      line-height:1.45;
      overflow-wrap:anywhere;
      word-break:break-word;
    }
    .cd-detailModal .cd-fileActions{
      flex:0 0 auto;
      gap:10px;
    }
    .cd-detailModal .cd-fileActions a{
      display:inline-flex;
      min-height:40px;
      padding:0 16px;
      align-items:center;
      justify-content:center;
      background:#fff;
      border-color:#c8dcf6;
    }
    .cd-detailModal .cd-fileUnavailable{
      width:max-content;
      max-width:100%;
      background:#eef2f7;
      color:#64748b;
      border:1px solid #dbe3ec;
      overflow-wrap:anywhere;
      white-space:normal;
    }
    @media (max-width:900px){
      .cd-profileHeader{
        grid-template-columns:1fr;
        gap:26px;
      }
      .cd-summaryPanel{
        padding-top:24px;
        padding-left:0;
        border-top:1px solid #e3ebf5;
        border-left:0;
      }
    }
    @media (max-width:900px){
      .cd-historyFilters{
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:12px;
      }
      .cd-clearFilters{
        width:100%;
      }
      .cd-detailOverview,
      .cd-detailInfoGrid,
      .cd-detailPaymentGrid{
        grid-template-columns:1fr;
      }
      .cd-detailModalHead{
        padding:24px;
      }
      .cd-detailModalContent{
        max-height:calc(90vh - 93px);
        padding:24px;
      }
    }
    @media (max-width:700px){
      .cd-hero{
        padding:26px 22px;
      }
      .cd-profileShell,
      .cd-historyCard{
        padding:20px;
        border-radius:18px;
      }
      .cd-infoGrid,
      .cd-historyFilters{
        grid-template-columns:1fr;
      }
      .cd-actionsCard{
        align-items:stretch;
      }
      .cd-actionButtons{
        flex-direction:column;
        width:100%;
      }
      .cd-actionButtons .cl-btn{
        width:100%;
      }
    }
    @media (max-width:560px){
      .admin-wrapper{
        padding-left:12px;
        padding-right:12px;
      }
      .cd-profileShell,
      .cd-historyCard{
        padding:17px;
      }
      .cd-actionsCard{
        padding:18px;
        border-radius:18px;
      }
      .cd-historyFilters{
        padding:14px;
      }
      .cd-summaryGrid{
        grid-template-columns:1fr;
      }
      .cd-summaryCard{
        min-height:86px;
      }
      .cd-rowBtn{
        margin-bottom:0;
      }
      .cd-detailOverlay{
        padding:12px;
      }
      .cd-detailModal{
        width:94vw;
        max-height:90vh;
        border-radius:20px;
      }
      .cd-detailModal .cl-modalBody{
        max-height:90vh;
      }
      .cd-detailModalHead{
        gap:14px;
        padding:20px 18px;
      }
      .cd-detailModalHead h3{
        font-size:1.3rem;
      }
      .cd-detailModalContent{
        max-height:calc(90vh - 85px);
        padding:18px;
      }
      .cd-detailOverview{
        gap:18px;
        padding:20px;
      }
      .cd-detailSection{
        padding:20px;
      }
      .cd-detailModal .cd-fileItem{
        flex-direction:column;
        align-items:stretch;
      }
      .cd-detailModal .cd-fileActions{
        width:100%;
      }
      .cd-detailModal .cd-fileActions a{
        flex:1;
      }
    }
  </style>
</head>
<body class="customer-details-page">
  <?php
  $adminHeaderMenuId = "admin-customer-details-header-menu";
  $adminHeaderVariant = "special";
  require __DIR__ . "/../_includes/admin_header.php";
  ?>

  <div class="admin-wrapper">
    <section class="admin-hero cd-hero customer-details-container">
      <div class="cd-heroContent">
        <span class="cd-kicker">Customer Details</span>
        <h1><?= $customer ? cd_esc($customer["fullname"] ?: "Customer") : "Customer Not Found" ?></h1>
        <p><?= $customer ? "Profile, service history, payments, and uploaded files." : "The selected customer record is unavailable." ?></p>
      </div>
    </section>

    <main class="admin-container cl-main customer-details-container">
      <div class="cl-wrap cd-wrap">
        <?php if (!$customer): ?>
          <section class="cl-card cd-emptyState">
            <h2>Customer unavailable</h2>
            <p>This account may have been removed or the link is invalid.</p>
            <a class="cl-btn cl-btn--maroon" href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Back to Customer List</a>
          </section>
        <?php else: ?>
          <section class="cd-profileShell" aria-labelledby="customerProfileTitle">
            <div class="cd-profileHeader">
              <div class="cd-profileIdentity">
                <span class="cd-profileEyebrow">Customer Information</span>
                <h2 class="cd-customerName" id="customerProfileTitle"><?= cd_esc($customer["fullname"] ?: "-") ?></h2>
                <span class="cd-customerEmail"><?= cd_esc($customer["email"] ?: "-") ?></span>

                <div class="cd-infoGrid">
                  <div class="cd-infoItem">
                    <span>Customer ID</span>
                    <strong><?= cd_esc(cd_customer_code((int)$customer["id"])) ?></strong>
                  </div>
                  <div class="cd-infoItem">
                    <span>Internal ID</span>
                    <strong><?= (int)$customer["id"] ?></strong>
                  </div>
                  <div class="cd-infoItem">
                    <span>Contact Number</span>
                    <strong><?= cd_esc($customer["contacts"] ?: "-") ?></strong>
                  </div>
                  <div class="cd-infoItem">
                    <span>Account Created</span>
                    <strong><?= cd_esc(cd_format_date($customer["created_at"] ?? "")) ?></strong>
                  </div>
                </div>
              </div>

              <div class="cd-summaryPanel">
                <h3 class="cd-summaryHeading">Order &amp; Payment Summary</h3>
                <div class="cd-summaryGrid">
                  <article class="cd-summaryCard cd-summaryCard--pending"><span>Pending Payment</span><strong><?= $summary["pending_payment"] ?></strong></article>
                  <article class="cd-summaryCard cd-summaryCard--submitted"><span>Payment Submitted</span><strong><?= $summary["payment_submitted"] ?></strong></article>
                  <article class="cd-summaryCard cd-summaryCard--paid"><span>Paid / Verified</span><strong><?= $summary["paid_verified"] ?></strong></article>
                  <article class="cd-summaryCard cd-summaryCard--cancelled"><span>Cancelled Orders</span><strong><?= $summary["cancelled"] ?></strong></article>
                </div>
              </div>
            </div>
          </section>

          <section class="cl-card cd-actionsCard">
            <div class="cd-actionsCopy">
              <h2>Actions</h2>
              <p>Send a direct notification, review files, or return to the customer list.</p>
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
          <div class="cl-modalHead cd-detailModalHead">
            <div>
              <h3 id="historyDetailTitle">Order / Queue Details</h3>
            </div>
            <button class="cl-modalX" type="button" data-history-detail-close aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>

          <div class="cd-detailModalContent">
            <div class="cd-detailOverview">
              <div class="cd-detailItem">
                <small>Order / Queue ID</small>
                <strong data-detail-id>-</strong>
              </div>
              <div class="cd-detailItem">
                <small>Status</small>
                <span class="cd-statusBadge" data-detail-status>-</span>
              </div>
              <div class="cd-detailItem">
                <small>Date Submitted</small>
                <strong data-detail-date>-</strong>
              </div>
            </div>

            <section class="cd-detailSection">
              <h4>Customer &amp; Service</h4>
              <div class="cd-detailInfoGrid">
                <div class="cd-detailItem"><small>Customer</small><strong data-detail-customer>-</strong></div>
                <div class="cd-detailItem"><small>Service Category</small><strong data-detail-category>-</strong></div>
                <div class="cd-detailItem"><small>Service Name</small><strong data-detail-service>-</strong></div>
              </div>
            </section>

            <section class="cd-detailSection">
              <h4>Payment Details</h4>
              <div class="cd-detailInfoGrid cd-detailPaymentGrid">
                <div class="cd-detailItem"><small>Payment Method</small><strong data-detail-payment-method>-</strong></div>
                <div class="cd-detailItem"><small>Total Amount</small><strong data-detail-total>-</strong></div>
                <div class="cd-detailItem"><small>Payment Status</small><strong data-detail-payment-status>-</strong></div>
                <div class="cd-detailItem"><small>GCash Reference</small><strong data-detail-reference>-</strong></div>
              </div>
            </section>

            <section class="cd-detailSection">
              <h4>Attached Files</h4>
              <div class="cd-detailFiles" data-detail-files></div>
            </section>

            <section class="cd-detailSection" data-detail-notes-wrap hidden>
              <h4>Notes</h4>
              <p data-detail-notes></p>
            </section>
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
        let detailModalTrigger = null;
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

        function openDetailModal(payload, trigger = null) {
          detailModalTrigger = trigger;
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
          document.documentElement.classList.add('modal-open');
          document.body.classList.add('modal-open');
          detailModal.querySelector('[data-history-detail-close]')?.focus();
        }

        function closeDetailModal() {
          detailModal.style.display = 'none';
          detailModal.setAttribute('aria-hidden', 'true');
          document.documentElement.classList.remove('modal-open');
          document.body.classList.remove('modal-open');
          detailModalTrigger?.focus();
          detailModalTrigger = null;
        }

        document.querySelectorAll('[data-history-view]').forEach(button => {
          button.addEventListener('click', () => {
            try {
              openDetailModal(JSON.parse(button.dataset.historyDetail || '{}'), button);
            } catch (error) {
              openDetailModal({}, button);
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
