<?php
require_once __DIR__ . "/config/session_check.php"; // use your consistent session setup
require_once __DIR__ . "/config/app.php";

$landingRecoveryType = strtolower(trim((string)($_GET["type"] ?? "")));
$landingRecoveryQuery = (string)($_SERVER["QUERY_STRING"] ?? "");
if (
  $landingRecoveryType === "recovery"
  && $landingRecoveryQuery !== ""
  && (
    isset($_GET["token_hash"])
    || isset($_GET["error"])
    || isset($_GET["error_code"])
    || isset($_GET["error_description"])
  )
) {
  header("Location: " . servitech_url("/auth/reset_password.php") . "?" . $landingRecoveryQuery, true, 303);
  exit();
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/store_availability.php";
require_once __DIR__ . "/api/service_catalog.php";
servitech_store_send_no_cache_headers();

$is_logged_in = servitech_is_logged_in();
$is_admin = servitech_is_admin();
$queue_url = $is_admin
  ? "/pages/admin/queue_list/printing.php"
  : ($is_logged_in ? "/pages/customer/customer_dash.php" : "/auth/log_in.php");
$status_url = $is_admin
  ? "/pages/admin/order_management/printM.php"
  : ($is_logged_in ? "/pages/customer/custo_service_status.php" : "/auth/log_in.php");
$print_url = $is_admin
  ? "/pages/admin/order_management/printM.php"
  : ($is_logged_in ? "/pages/customer/custo2_docu_printing.php" : "/auth/log_in.php");

$landingAnnouncement = null;
$storeAvailability = servitech_store_current_availability($pdo);
$landingUpcomingHoliday = $storeAvailability["upcoming_holidays"][0] ?? null;
$landingDocumentPrintingAvailable = servitech_catalog_fetch_customer_catalog_by_kind($pdo, "document_printing") !== null;
if ($is_logged_in && !$is_admin && !$landingDocumentPrintingAvailable) {
  $print_url = "/pages/customer/custo1_printing_option.php";
}
try {
  $announcementStmt = $pdo->query("
    SELECT title, message
    FROM announcements
    WHERE active = TRUE
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
  ");
  $landingAnnouncement = $announcementStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $exception) {
  $landingAnnouncement = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: JC Repair Shop</title>
  <?= servitech_favicon_link() ?>
  <script>
    (() => {
      try {
        if (!window.location.hash || window.location.hash.length <= 1) {
          return;
        }

        const recoveryParams = new URLSearchParams(window.location.hash.slice(1));
        const recoveryType = (recoveryParams.get("type") || "").toLowerCase();
        const hasRecoveryResult = Boolean(
          recoveryParams.get("access_token")
          || recoveryParams.get("refresh_token")
          || recoveryParams.get("error")
          || recoveryParams.get("error_code")
          || recoveryParams.get("error_description")
        );

        if (recoveryType === "recovery" && hasRecoveryResult) {
          window.location.replace(<?= json_encode(servitech_url("/auth/reset_password.php"), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + window.location.hash);
        }
      } catch (error) {
        // If parsing fails, leave the normal public homepage untouched.
      }
    })();
  </script>
  <link rel="stylesheet" href="<?= htmlspecialchars(servitech_url('/assets/css/style.css?v=20260623-landing-modal-close'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(servitech_url('/assets/css/landing-store-details.css?v=20260615'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="has-fixed-site-header landing-page">

  <!-- NAVBAR -->
  <header class="navbar has-nav-menu site-header public-header">
    <a href="<?= htmlspecialchars(servitech_brand_home_url(), ENT_QUOTES, 'UTF-8') ?>" class="logo">
      <img src="<?= htmlspecialchars(servitech_url('/assets/images/LOGO_SERVITECH.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech</h1>
    </a>
    <button
      class="nav-toggle"
      type="button"
      aria-label="Toggle navigation menu"
      aria-expanded="false"
      aria-controls="landing-header-menu"
    >
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
    </button>
    <nav id="landing-header-menu" data-collapsible-menu>
      <?php if ($is_logged_in): ?>
        <?php if ($is_admin): ?>
          <a href="<?= htmlspecialchars(servitech_url('/pages/admin/admin_dashboard.php'), ENT_QUOTES, 'UTF-8') ?>">Admin Dashboard</a>
          <a href="<?= htmlspecialchars(servitech_url('/pages/admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">Logout</a>
        <?php else: ?>
          <a href="<?= htmlspecialchars(servitech_url('/pages/customer/customer_dash.php'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
          <a href="<?= htmlspecialchars(servitech_url('/auth/logout.php'), ENT_QUOTES, 'UTF-8') ?>">Logout</a>
        <?php endif; ?>
      <?php else: ?>
        <a href="<?= htmlspecialchars(servitech_url('/auth/regis.php'), ENT_QUOTES, 'UTF-8') ?>">Register</a>
        <a href="<?= htmlspecialchars(servitech_url('/auth/log_in.php'), ENT_QUOTES, 'UTF-8') ?>">Login</a>
      <?php endif; ?>
    </nav>
  </header>

  <?php if (!empty($landingAnnouncement["title"])): ?>
    <section class="announcement-section" role="status" aria-label="Announcement">
      <div class="announcement-container">
        <span class="announcement-tag">Announcement</span>
        <span class="announcement-title">
          <?= htmlspecialchars((string)($landingAnnouncement["title"] ?? ""), ENT_QUOTES, "UTF-8") ?>
        </span>
        <span class="announcement-message">
          <?= htmlspecialchars((string)($landingAnnouncement["message"] ?? ""), ENT_QUOTES, "UTF-8") ?>
        </span>
      </div>
    </section>
  <?php endif; ?>

  <!-- HERO -->
  <section class="hero">
    <h2>Welcome to ServiTech: JC Repair Shop</h2>
    <p>Offering printing, repairing, and installation services</p>

    <div class="hero-cards">
      <a href="<?= htmlspecialchars(servitech_url($queue_url), ENT_QUOTES, 'UTF-8') ?>" class="hero-card">
        <img src="<?= htmlspecialchars(servitech_url('/assets/images/LANDING_QUEUEING.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Queueing" class="hero-icon">
        <h4>QUEUEING</h4>
      </a>

      <a href="<?= htmlspecialchars(servitech_url($status_url), ENT_QUOTES, 'UTF-8') ?>" class="hero-card">
        <img src="<?= htmlspecialchars(servitech_url('/assets/images/LANDING_SERVICE-STAT.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Service Status" class="hero-icon">
        <h4>SERVICE STATUS</h4>
      </a>

      <a href="<?= htmlspecialchars(servitech_url($print_url), ENT_QUOTES, 'UTF-8') ?>" class="hero-card">
        <img src="<?= htmlspecialchars(servitech_url('/assets/images/LANDING_PRINT-ORD.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Print Order" class="hero-icon">
        <h4>PRINT ORDER</h4>
      </a>
    </div>
  </section>

  <!-- SERVICES -->
  <section class="services">
    <h2>JC Repair Shop's Services</h2>
    <p>(Note: these are only the common requested services, you may contact us for inquiry of other services)</p>

    <div class="service-type-cards">
      <div
        class="service-type-card"
        data-service-modal="printing"
        role="button"
        tabindex="0"
        aria-label="Open Print Service details"
      >
        <img src="/assets/images/CARD_PRINTING.png" alt="Print Service">
        <h3>Print Service</h3>
      </div>

      <div
        class="service-type-card"
        data-service-modal="repair"
        role="button"
        tabindex="0"
        aria-label="Open Device Repair Service details"
      >
        <img src="/assets/images/CARD_REPAIR.png" alt="Device Repair">
        <h3>Device Repair Service</h3>
      </div>

      <div
        class="service-type-card"
        data-service-modal="installation"
        role="button"
        tabindex="0"
        aria-label="Open Installation and Software details"
      >
        <img src="/assets/images/CARD_INSTALLATION.png" alt="Installation Service">
        <h3>Installation / Software</h3>
      </div>
    </div>

  </section>

  <section class="landing-store-details" aria-labelledby="landingStoreDetailsTitle">
    <div class="landing-store-details__inner">
      <header class="landing-store-details__heading">
        <div>
          <span class="landing-store-details__eyebrow">Plan Your Visit</span>
          <h2 id="landingStoreDetailsTitle">Store Availability</h2>
        </div>
        <p><?= htmlspecialchars($storeAvailability["customer_message"], ENT_QUOTES, "UTF-8") ?></p>
      </header>

      <div class="landing-store-details__grid">
        <div class="landing-store-detail landing-store-detail--status">
          <span class="landing-store-detail__label">Store status</span>
          <strong class="landing-store-detail__status landing-store-detail__status--<?= htmlspecialchars($storeAvailability["effective_status"], ENT_QUOTES, "UTF-8") ?>">
            <?= htmlspecialchars($storeAvailability["status_label"], ENT_QUOTES, "UTF-8") ?>
          </strong>
        </div>

        <div class="landing-store-detail">
          <span class="landing-store-detail__label">Today's shop hours</span>
          <strong><?= htmlspecialchars($storeAvailability["today_hours"], ENT_QUOTES, "UTF-8") ?></strong>
        </div>

        <div class="landing-store-detail">
          <span class="landing-store-detail__label">Queue cutoff</span>
          <strong><?= htmlspecialchars($storeAvailability["queue_cutoff_label"], ENT_QUOTES, "UTF-8") ?></strong>
        </div>

        <div class="landing-store-detail landing-store-detail--printing">
          <span class="landing-store-detail__label">Document Printing</span>
          <strong><?= $landingDocumentPrintingAvailable ? "Available" : "Unavailable" ?></strong>
        </div>

        <?php if (is_array($landingUpcomingHoliday)): ?>
          <div class="landing-store-detail landing-store-detail--holiday">
            <span class="landing-store-detail__label">Upcoming closed date</span>
            <strong>
              <?= htmlspecialchars(date("M j, Y", strtotime((string)$landingUpcomingHoliday["holiday_date"])), ENT_QUOTES, "UTF-8") ?>
              &middot;
              <?= htmlspecialchars($landingUpcomingHoliday["title"] ?? "Store closed", ENT_QUOTES, "UTF-8") ?>
            </strong>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div id="service-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal modal-content service-modal" role="dialog" aria-modal="true" aria-labelledby="service-modal-title">
      <button class="btn-close close-btn service-modal__close" type="button" aria-label="Close" onclick="closeServiceModal()"><span aria-hidden="true">&times;</span></button>
      <div class="service-modal__header">
        <div class="service-modal__eyebrow">Service Overview</div>
        <h3 id="service-modal-title" class="service-modal__title">Service Details</h3>
        <p id="service-modal-description" class="service-modal__description">Browse the available options and view more details when available.</p>
      </div>
      <div class="modal-divider service-modal__divider"></div>
      <div id="service-modal-body" class="modal-body service-modal__body"></div>
      <div class="service-modal__footer">
        <button type="button" class="service-modal__action service-modal__action--secondary" onclick="closeServiceModal()">Close</button>
      </div>
    </div>
  </div>

  <div id="service-detail-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal modal-content service-modal" role="dialog" aria-modal="true" aria-labelledby="service-detail-modal-title">
      <button class="btn-close close-btn service-modal__close" type="button" aria-label="Close" onclick="closeServiceDetailModal()"><span aria-hidden="true">&times;</span></button>
      <div class="service-modal__header">
        <div class="service-modal__eyebrow">Detailed View</div>
        <h3 id="service-detail-modal-title" class="service-modal__title">More Details</h3>
        <p id="service-detail-modal-description" class="service-modal__description">Review the selected service details and pricing information.</p>
      </div>
      <div class="modal-divider service-modal__divider"></div>
      <div id="service-detail-modal-body" class="modal-body service-modal__body"></div>
      <div class="service-modal__footer">
        <button type="button" class="service-modal__action service-modal__action--ghost" onclick="closeServiceDetailModal()">Back</button>
        <button type="button" class="service-modal__action service-modal__action--secondary" onclick="closeServiceModal()">Close All</button>
      </div>
    </div>
  </div>

  <?php include __DIR__ . "/components/footer.php"; ?>

  <script src="/assets/js/csrf.js"></script>
  <script src="/assets/js/main.js?v=20260623-live-service-catalog"></script>
  <script src="/assets/js/header-menu.js?v=20260613-customer-logout-gap" defer></script>
</body>
</html>




