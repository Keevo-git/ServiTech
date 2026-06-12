<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../api/upload_helpers.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$user_id = (int)($_SESSION["user_id"] ?? 0);

$existingPrintDraft = $_SESSION["print_order_draft"] ?? null;
if (is_array($existingPrintDraft) && !empty($existingPrintDraft["uploaded_files"]) && is_array($existingPrintDraft["uploaded_files"])) {
  servitech_upload_delete_owned_orphans($pdo, $user_id, $existingPrintDraft["uploaded_files"]);
}
unset($_SESSION["print_order_draft"], $_SESSION["print_order_flash_error"], $_SESSION["print_order_form"], $_SESSION["print_order_confirmation"]);

$stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = :id LIMIT 1");
$stmt->execute([":id" => $user_id]);
$user = $stmt->fetch();

$fullname = $user["fullname"] ?? "Customer";

function format_fullname($name) {
  $name = trim((string)$name);
  if ($name === "") return "Customer";
  $name = preg_replace('/\s+/', ' ', $name);
  return ucwords(strtolower($name));
}

$display_name = format_fullname($fullname);
$dashboardNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Customer Dashboard</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260612header-brand-hit-area">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260526status-badges">
  <style>
    body.customer-layout.customer-page--dashboard .main-container {
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
      box-sizing: border-box;
    }

    body.customer-layout.customer-page--dashboard .dashboard-content {
      display: flex;
      flex-direction: column;
      gap: 25px;
    }

    body.customer-layout.customer-page--dashboard .customer-dashboard.cards-row {
      display: grid;
      grid-template-columns: 1fr 1fr !important;
      gap: 20px;
      align-items: stretch;
      width: 100% !important;
      margin: 0 !important;
      padding: 0 !important;
    }

    body.customer-layout.customer-page--dashboard .customer-dashboard.cards-row > .dashboard-card {
      height: 100%;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    body.customer-layout.customer-page--dashboard .quick-access.quick-access-section {
      width: 100% !important;
      max-width: none !important;
      margin: 0 0 80px !important;
      padding: 0 !important;
    }

    body.customer-layout.customer-page--dashboard .quick-grid.quick-access-grid {
      display: grid !important;
      grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
      gap: 20px !important;
      align-items: stretch !important;
      margin-left: 0 !important;
      padding-left: 0 !important;
    }

    body.customer-layout.customer-page--dashboard .quick-card-link {
      display: flex !important;
      min-width: 230px !important;
      max-width: none !important;
      align-self: stretch !important;
    }

    body.customer-layout.customer-page--dashboard .quick-card {
      display: flex !important;
      flex-direction: column !important;
      justify-content: flex-start !important;
      align-items: center !important;
      gap: 10px !important;
      width: 100% !important;
      height: 100% !important;
      min-height: 178px !important;
      padding: 18px 16px !important;
      border: 1px solid rgba(175, 108, 9, 0.14);
      border-radius: 16px;
      background: #ffffff;
      box-shadow: 0 10px 24px rgba(74, 5, 5, 0.08);
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
      box-sizing: border-box;
    }

    body.customer-layout.customer-page--dashboard .quick-card-link {
      color: inherit;
      text-decoration: none;
    }

    body.customer-layout.customer-page--dashboard .quick-card-link:hover .quick-card,
    body.customer-layout.customer-page--dashboard .quick-card-link:focus-visible .quick-card {
      transform: translateY(-4px);
      border-color: rgba(255, 139, 44, 0.56);
      box-shadow: 0 18px 32px rgba(74, 5, 5, 0.13);
    }

    body.customer-layout.customer-page--dashboard .quick-card-link:focus-visible {
      outline: 2px solid #ff8b2c;
      outline-offset: 4px;
      border-radius: 16px;
    }

    body.customer-layout.customer-page--dashboard .quick-icon-box {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 52px;
      height: 52px;
      flex: 0 0 52px;
      border-radius: 12px;
      background: linear-gradient(145deg, #4A0505, #7a0f0f);
      box-shadow:
        inset 0 -2px 0 rgba(0, 0, 0, 0.18),
        0 9px 18px rgba(74, 5, 5, 0.18);
      overflow: hidden;
      transition: background 0.22s ease, box-shadow 0.22s ease, transform 0.22s ease, filter 0.22s ease;
    }

    body.customer-layout.customer-page--dashboard .quick-card-link:hover .quick-icon-box,
    body.customer-layout.customer-page--dashboard .quick-card-link:focus-visible .quick-icon-box {
      background: linear-gradient(145deg, #5a0808, #8a1717);
      box-shadow:
        inset 0 -2px 0 rgba(0, 0, 0, 0.16),
        0 12px 22px rgba(74, 5, 5, 0.24);
      filter: brightness(1.04);
    }

    body.customer-layout.customer-page--dashboard .quick-icon,
    body.customer-layout.customer-page--dashboard .quick-access-icon-img,
    body.customer-layout.customer-page--dashboard .quick-access-icon-symbol {
      display: block;
      width: 32px;
      height: 32px;
      flex: 0 0 auto;
    }

    body.customer-layout.customer-page--dashboard .quick-icon,
    body.customer-layout.customer-page--dashboard .quick-access-icon-img {
      object-fit: contain;
      filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.24));
    }

    body.customer-layout.customer-page--dashboard .quick-access-icon-symbol {
      color: #ffffff;
      font-size: 22px;
      line-height: 32px;
      text-align: center;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.28);
    }

    body.customer-layout.customer-page--dashboard .quick-card h4 {
      margin: 2px 0 0;
      color: #4A0505;
      font-size: 16px;
      font-weight: 800;
      line-height: 1.25;
      text-align: center;
    }

    body.customer-layout.customer-page--dashboard .quick-card p {
      margin: 0;
      color: #6b5a4a;
      font-size: 13px;
      line-height: 1.45;
      text-align: center;
      max-width: 190px;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-section {
      width: 100%;
      margin: 0;
      padding: 0;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-card {
      width: 100%;
      padding: 26px;
      border: 1px solid rgba(175, 108, 9, 0.16);
      border-radius: 16px;
      background: linear-gradient(180deg, #ffffff 0%, #fff9ef 100%);
      box-shadow: 0 14px 30px rgba(74, 5, 5, 0.09);
      box-sizing: border-box;
    }

    body.customer-layout.customer-page--dashboard .dashboard-section-heading {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 20px;
    }

    body.customer-layout.customer-page--dashboard .dashboard-section-heading h3 {
      margin: 0;
      color: #4A0505;
      font-size: 24px;
      line-height: 1.2;
    }

    body.customer-layout.customer-page--dashboard .dashboard-section-heading p {
      margin: 0;
      color: #6b5a4a;
      font-size: 15px;
      line-height: 1.5;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-options {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 18px;
      width: 100%;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      min-width: 0;
      min-height: 190px;
      width: 100%;
      padding: 22px 18px;
      border: 1px solid #f3dcc2;
      border-top: 5px solid #f4a940;
      border-radius: 18px;
      background: #ffffff;
      color: #4A0505;
      cursor: pointer;
      box-shadow: 0 12px 28px rgba(120, 55, 15, 0.08);
      text-decoration: none;
      box-sizing: border-box;
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
      touch-action: manipulation;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card:hover,
    body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card:focus-visible {
      transform: translateY(-6px);
      border-color: #f4a940;
      box-shadow: 0 18px 36px rgba(244, 169, 64, 0.22);
      outline: none;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card:focus-visible {
      outline: 2px solid #4A0505;
      outline-offset: 4px;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card__media {
      display: flex;
      align-items: center;
      justify-content: center;
      width: min(100%, 150px);
      aspect-ratio: 1;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card__media img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card__label {
      color: #4A0505;
      font-size: 18px;
      font-weight: 800;
      line-height: 1.2;
      text-align: center;
    }

    body.customer-layout.customer-page--dashboard .customer-hero.hero-wrapper {
      width: 100%;
      margin: 0;
      padding: 0 !important;
      padding-top: 30px !important;
      background: transparent;
    }

    body.customer-layout.customer-page--dashboard .customer-hero__inner.hero-container {
      width: 100%;
      margin: 0;
      padding: 40px 20px;
      box-sizing: border-box;
      border-radius: 16px;
      background:
        linear-gradient(135deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.05)),
        linear-gradient(135deg, #ff7a18, #ffb347);
      backdrop-filter: blur(6px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }

    body.customer-layout.customer-page--dashboard .customer-hero,
    body.customer-layout.customer-page--dashboard .customer-hero h2,
    body.customer-layout.customer-page--dashboard .customer-hero p,
    body.customer-layout.customer-page--dashboard .customer-hero__time {
      color: #fff;
    }

    body.customer-layout.customer-page--dashboard .customer-hero h2 {
      margin-bottom: 8px;
      font-weight: 700;
      text-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
    }

    body.customer-layout.customer-page--dashboard .customer-hero p {
      margin-bottom: 0;
      text-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    }

    body.customer-layout.customer-page--dashboard .customer-hero__time {
      display: inline-block;
      margin-top: 12px;
      padding: 6px 14px;
      border: 1px solid rgba(255, 255, 255, 0.28);
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.15);
      font-size: 0.85rem;
      font-weight: 500;
      line-height: 1.3;
      backdrop-filter: blur(6px);
      box-shadow: 0 8px 18px rgba(92, 42, 4, 0.13);
    }

    .queue-carousel-card {
      overflow: hidden;
      height: 306px;
      min-height: 306px;
      border: 1px solid rgba(232, 199, 123, 0.30);
    }

    .queue-carousel-card--mine {
      background: linear-gradient(180deg, #fffdf8 0%, #fff9ef 100%);
      box-shadow: 0 12px 26px rgba(153, 96, 16, 0.11);
    }

    .queue-carousel-card--latest {
      background: linear-gradient(180deg, #fbfcff 0%, #f3f8ff 100%);
      box-shadow: 0 12px 26px rgba(37, 99, 235, 0.09);
      border-color: rgba(147, 197, 253, 0.34);
    }

    .queue-carousel {
      display: grid;
      grid-template-rows: 52px 18px 126px;
      gap: 10px;
      flex: 1;
      min-height: 0;
      height: 100%;
      align-content: start;
    }

    .queue-carousel__topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      min-height: 52px;
    }

    .queue-carousel__nav {
      border: 0;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease;
      flex-shrink: 0;
    }

    .queue-carousel-card--mine .queue-carousel__nav {
      background: #f7e6bf;
      color: #4A0505;
    }

    .queue-carousel-card--mine .queue-carousel__nav:hover {
      background: #FAB12F;
      transform: translateY(-1px);
    }

    .queue-carousel-card--latest .queue-carousel__nav {
      background: #dbeafe;
      color: #1d4ed8;
    }

    .queue-carousel-card--latest .queue-carousel__nav:hover {
      background: #bfdbfe;
      color: #1e40af;
      transform: translateY(-1px);
    }

    .queue-carousel__nav:focus-visible,
    .queue-carousel__dot:focus-visible {
      outline: 2px solid #4A0505;
      outline-offset: 2px;
    }

    .queue-carousel__category {
      display: flex;
      align-items: center;
      justify-content: center;
      align-self: stretch;
      text-align: center;
      font-size: 20px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      flex: 1;
      min-width: 0;
      line-height: 1.1;
    }

    .queue-carousel-card--mine .queue-carousel__category {
      color: #4A0505;
    }

    .queue-carousel-card--latest .queue-carousel__category {
      color: #143b7a;
    }

    .queue-carousel__dots {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 8px;
      min-height: 18px;
    }

    .queue-carousel__dot {
      width: 9px;
      height: 9px;
      border-radius: 999px;
      border: 0;
      padding: 0;
      cursor: pointer;
      background: #ead4a5;
      transition: transform 0.18s ease, background 0.18s ease;
    }

    .queue-carousel__dot.is-active {
      background: #FAB12F;
      transform: scale(1.15);
    }

    .queue-carousel-card--mine .queue-carousel__dot {
      background: #ead4a5;
    }

    .queue-carousel-card--mine .queue-carousel__dot.is-active {
      background: #FAB12F;
      transform: scale(1.15);
    }

    .queue-carousel-card--latest .queue-carousel__dot {
      background: #bfdbfe;
    }

    .queue-carousel-card--latest .queue-carousel__dot.is-active {
      background: #3b82f6;
      transform: scale(1.15);
    }

    .queue-carousel__list {
      display: flex;
      align-items: flex-start;
      min-height: 126px;
      height: 126px;
      padding: 0 8px;
      box-sizing: border-box;
      overflow: hidden;
      opacity: 1;
      transition: opacity 0.2s ease;
      margin-top: 0;
    }

    .queue-carousel__list.is-fading {
      opacity: 0.35;
    }

    .queue-item {
      border: 1px solid #e7d5ad;
      background: #fffaf0;
      border-radius: 14px;
      padding: 14px 16px;
      height: 112px;
      min-height: 112px;
      display: grid;
      grid-template-rows: 34px 24px 20px;
      align-content: start;
      width: 100%;
      box-sizing: border-box;
      transition: none;
    }

    .queue-carousel-card--mine .queue-item {
      border: 1px solid #f0dfbe;
      background: #fffaf0;
    }


    .queue-carousel-card--latest .queue-item {
      border: 1px solid #c8dcfb;
      background: #f7faff;
    }


    .queue-item__head {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 112px;
      align-items: start;
      column-gap: 12px;
      min-height: 34px;
    }

    .queue-item__code {
      margin-left: 0;
      font-size: 22px;
      font-weight: 700;
      line-height: 1.1;
      word-break: break-word;
    }

    .queue-carousel-card--mine .queue-item__code {
      color: #13274a;
    }

    .queue-carousel-card--latest .queue-item__code {
      color: #163d73;
    }

    .queue-item__badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      justify-self: end;
      align-self: start;
      width: 112px;
      min-width: 112px;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.2;
      border: 1px solid transparent;
      white-space: nowrap;
      box-sizing: border-box;
      text-align: center;
    }

    .queue-item__badge--pending {
      background: #fef3c7;
      color: #b45309;
      border-color: transparent;
    }

    .queue-item__badge--ongoing {
      background: #dbeafe;
      color: #1d4ed8;
      border-color: transparent;
    }

    .queue-item__badge--pickup {
      background: #ede9fe;
      color: #7c3aed;
      border-color: transparent;
    }

    .queue-item__badge--done {
      background: #dcfce7;
      color: #15803d;
      border-color: transparent;
    }

    .queue-item__badge--cancelled {
      background: #fee2e2;
      color: #b91c1c;
      border-color: transparent;
    }

    .queue-item__label {
      margin-top: 6px;
      min-height: 24px;
      font-size: 15px;
      font-weight: 700;
      display: flex;
      align-items: center;
      width: 100%;
      max-width: 100%;
      padding-right: 124px;
      box-sizing: border-box;
    }

    .queue-carousel-card--mine .queue-item__label {
      color: #4A0505;
    }

    .queue-carousel-card--latest .queue-item__label {
      color: #163d73;
    }

    .queue-item__details,
    .queue-item__meta {
      margin-top: 2px;
      font-size: 13px;
      line-height: 20px;
      min-height: 20px;
      width: 100%;
      max-width: 100%;
      padding-right: 124px;
      box-sizing: border-box;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
      display: block;
      align-self: start;
    }

    .queue-carousel-card--mine .queue-item__details,
    .queue-carousel-card--mine .queue-item__meta {
      color: #6b5a3b;
    }

    .queue-carousel-card--latest .queue-item__details,
    .queue-carousel-card--latest .queue-item__meta {
      color: #4f6485;
    }

    .queue-carousel__empty {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 112px;
      min-height: 112px;
      border-radius: 14px;
      text-align: center;
      padding: 14px 16px;
      width: 100%;
      box-sizing: border-box;
    }

    .queue-carousel-card--mine .queue-carousel__empty {
      border: 1px dashed #e0c991;
      background: #fffaf0;
      color: #7a6b4f;
    }

    .queue-carousel-card--latest .queue-carousel__empty {
      border: 1px dashed #bfdbfe;
      background: #f8fbff;
      color: #4f6485;
    }

    @media (max-width: 900px) {
      body.customer-layout.customer-page--dashboard .quick-grid.quick-access-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }

      body.customer-layout.customer-page--dashboard .dashboard-service-options {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      body.customer-layout.customer-page--dashboard .customer-dashboard.cards-row > .dashboard-card {
        height: auto;
      }

      .queue-carousel-card {
        height: 306px;
        min-height: 306px;
      }
    }

    @media (max-width: 640px) {
      body.customer-layout.customer-page--dashboard .main-container {
        padding: 16px;
      }

      body.customer-layout.customer-page--dashboard .dashboard-service-card {
        padding: 20px 16px;
      }

      body.customer-layout.customer-page--dashboard .dashboard-service-options {
        grid-template-columns: 1fr;
        gap: 14px;
      }

      body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card {
        min-height: 156px;
      }

      body.customer-layout.customer-page--dashboard .dashboard-service-options .queue-service-card__media {
        width: 118px;
      }

      body.customer-layout.customer-page--dashboard .quick-card-link {
        min-width: 0 !important;
      }

      body.customer-layout.customer-page--dashboard .quick-grid.quick-access-grid {
        grid-template-columns: 1fr !important;
      }

      body.customer-layout.customer-page--dashboard .quick-card {
        min-height: 160px !important;
      }

      body.customer-layout.customer-page--dashboard .quick-access.quick-access-section {
        margin-bottom: 48px !important;
      }

      .queue-carousel-card {
        height: auto;
        min-height: 320px;
      }

      .queue-carousel {
        grid-template-rows: 44px 18px auto;
      }

      .queue-carousel__topbar {
        gap: 8px;
        min-height: 44px;
      }

      .queue-carousel__nav {
        width: 32px;
        height: 32px;
        font-size: 14px;
      }

      .queue-carousel__category {
        font-size: 16px;
        letter-spacing: 0.02em;
      }

      .queue-item__head {
        grid-template-columns: 1fr;
        row-gap: 8px;
        min-height: auto;
      }

      .queue-carousel__list {
        height: auto;
        min-height: 138px;
        padding: 0 4px;
      }

      .queue-item {
        height: auto;
        min-height: 138px;
        grid-template-rows: auto auto auto;
        padding: 12px 14px;
      }

      .queue-carousel__empty {
        height: auto;
        min-height: 138px;
      }

      .queue-item__code {
        font-size: 19px;
      }

      .queue-item__badge {
        width: auto;
        min-width: 0;
        justify-self: start;
        white-space: normal;
      }

      .queue-item__label,
      .queue-item__details,
      .queue-item__meta {
        padding-right: 0;
      }

      .queue-item__details,
      .queue-item__meta {
        min-height: 20px;
        line-height: 20px;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--dashboard has-fixed-site-header">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="main-container dashboard-content">
<section class="customer-hero hero-wrapper">
  <div class="customer-hero__inner hero-container">
    <h2>Welcome, <span id="customerName"><?php echo htmlspecialchars($display_name); ?></span>!</h2>
    <p>Manage your queue, request status and print orders.</p>
    <time class="customer-hero__time live-datetime" id="customerNow" datetime="<?php echo htmlspecialchars($dashboardNow->format(DateTimeInterface::ATOM), ENT_QUOTES, "UTF-8"); ?>">
      <?php echo htmlspecialchars($dashboardNow->format("M d, Y, h:i:s A"), ENT_QUOTES, "UTF-8"); ?>
    </time>
  </div>
</section>

<section class="dashboard-service-section" aria-labelledby="dashboardChooseServiceTitle">
  <div class="dashboard-service-card">
    <div class="dashboard-section-heading">
      <h3 id="dashboardChooseServiceTitle">Choose a Service</h3>
      <p>Select what you need today and continue to the queue form.</p>
    </div>

    <div class="queue-service-options dashboard-service-options" role="group" aria-label="Choose a service">
      <a href="/pages/customer/custo1_printing_option.php" class="queue-service-card">
        <span class="queue-service-card__media">
          <img src="/assets/images/CARD_PRINTING.png" alt="" aria-hidden="true">
        </span>
        <span class="queue-service-card__label">Printing</span>
      </a>

      <a href="/pages/customer/custo1_repair_option.php" class="queue-service-card">
        <span class="queue-service-card__media">
          <img src="/assets/images/CARD_REPAIR.png" alt="" aria-hidden="true">
        </span>
        <span class="queue-service-card__label">Repair</span>
      </a>

      <a href="/pages/customer/custo1_installation_option.php" class="queue-service-card">
        <span class="queue-service-card__media">
          <img src="/assets/images/CARD_INSTALLATION.png" alt="" aria-hidden="true">
        </span>
        <span class="queue-service-card__label">Installation</span>
      </a>
    </div>
  </div>
</section>

<section class="quick-access quick-access-section">
  <h3>Quick Access</h3>
  <div class="divider"></div>

  <div class="quick-grid quick-access-grid">
    <a href="/pages/customer/custo_place_queueing.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <span class="quick-access-icon-symbol" aria-hidden="true">&#x23F3;</span>
        </div>
        <h4>Join Queue</h4>
        <p>Join the line to place your request.</p>
      </div>
    </a>

    <a href="/pages/customer/custo_service_status.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img class="quick-access-icon-img" src="/assets/images/QUICK-ACCESS_SERVICE-STATUS.png" alt="Service Status">
        </div>
        <h4>Service Status</h4>
        <p>Check your requested service or queue status.</p>
      </div>
    </a>

    <a href="/pages/customer/custo_edit_profile.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img class="quick-access-icon-img" src="/assets/images/QUICK-ACCESS_EDIT-PROFILE.png" alt="Edit Profile">
        </div>
        <h4>Edit Profile</h4>
        <p>Edit your personal information.</p>
      </div>
    </a>

    <a href="/pages/customer/custo_queue_monitor.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img class="quick-access-icon-img" src="/assets/images/QUICK-ACCESS_QUEUE-MONITOR.png" alt="Queue Monitor">
        </div>
        <h4>Queue Monitor</h4>
        <p>View your latest queue updates and now serving.</p>
      </div>
    </a>
  </div>
</section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const customerNowEl = document.getElementById("customerNow");
  if (customerNowEl) {
    const customerClockFormatter = new Intl.DateTimeFormat("en-US", {
      timeZone: "Asia/Manila",
      year: "numeric",
      month: "long",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: true
    });

    const updateCustomerClock = () => {
      const now = new Date();
      customerNowEl.textContent = customerClockFormatter.format(now);
      customerNowEl.dateTime = now.toISOString();
    };

    updateCustomerClock();
    window.setInterval(updateCustomerClock, 1000);
  }
</script>

</body>
</html>

