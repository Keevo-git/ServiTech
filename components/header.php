<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/db.php";

if (!function_exists("servitech_notification_bool")) {
    function servitech_notification_bool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ["1", "t", "true", "y", "yes", "on"], true);
    }
}

if (!function_exists("servitech_notification_user_id")) {
    function servitech_notification_user_id(): int
    {
        return (int)($_SESSION["user_id"] ?? 0);
    }
}

if (!function_exists("servitech_notification_format_timestamp")) {
    function servitech_notification_format_timestamp(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === "") {
            return "";
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date("M d, Y h:i A", $timestamp);
    }
}

if (!function_exists("servitech_notification_normalize_row")) {
    function servitech_notification_normalize_row(array $row): array
    {
        return [
            "id" => (int)($row["id"] ?? 0),
            "user_id" => (int)($row["user_id"] ?? 0),
            "type" => trim((string)($row["type"] ?? "")),
            "reference_id" => isset($row["reference_id"]) ? (int)$row["reference_id"] : null,
            "message" => trim((string)($row["message"] ?? "")),
            "is_read" => servitech_notification_bool($row["is_read"] ?? false),
            "created_at" => trim((string)($row["created_at"] ?? "")),
            "created_at_label" => servitech_notification_format_timestamp((string)($row["created_at"] ?? "")),
        ];
    }
}

if (!function_exists("servitech_notification_fetch_all")) {
    function servitech_notification_fetch_all(PDO $pdo, int $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));

        $stmt = $pdo->prepare("
            SELECT id, user_id, type, reference_id, message, is_read, created_at
            FROM notifications
            WHERE user_id = :user_id
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([":user_id" => $userId]);

        $notifications = [];
        foreach ($stmt->fetchAll() as $row) {
            $notifications[] = servitech_notification_normalize_row($row);
        }

        return $notifications;
    }
}

if (!function_exists("servitech_notification_unread_count")) {
    function servitech_notification_unread_count(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = :user_id
              AND COALESCE(is_read, FALSE) = FALSE
        ");
        $stmt->execute([":user_id" => $userId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists("servitech_notification_json_response")) {
    function servitech_notification_json_response(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
        exit();
    }
}

if (!function_exists("servitech_notification_require_write_request")) {
    function servitech_notification_require_write_request(): void
    {
        if (strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET")) !== "POST") {
            servitech_notification_json_response(["ok" => false, "error" => "Method not allowed"], 405);
        }

        servitech_enforce_same_origin(true);
        servitech_enforce_csrf_token(true);
    }
}

if (!function_exists("servitech_notification_supabase_url")) {
    function servitech_notification_supabase_url(string $dbHost): string
    {
        $envUrl = trim((string)getenv("SUPABASE_URL"));
        if ($envUrl !== "") {
            return $envUrl;
        }

        if (preg_match('/^db\.([a-z0-9]+)\.supabase\.co$/i', trim($dbHost), $matches)) {
            return "https://" . $matches[1] . ".supabase.co";
        }

        return "";
    }
}

if (!function_exists("servitech_notification_supabase_anon_key")) {
    function servitech_notification_supabase_anon_key(): string
    {
        $candidates = [
            getenv("SUPABASE_ANON_KEY"),
            $_SERVER["SUPABASE_ANON_KEY"] ?? null,
            $_ENV["SUPABASE_ANON_KEY"] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== "") {
                return $candidate;
            }
        }

        return "";
    }
}

$notificationAction = strtolower(trim((string)($_REQUEST["notifications_action"] ?? "")));
if ($notificationAction !== "") {
    $notificationUserId = servitech_notification_user_id();
    if ($notificationUserId <= 0) {
        servitech_notification_json_response(["ok" => false, "error" => "Not logged in"], 401);
    }

    try {
        switch ($notificationAction) {
            case "get_notifications":
                servitech_notification_json_response([
                    "ok" => true,
                    "notifications" => servitech_notification_fetch_all($pdo, $notificationUserId),
                ]);

            case "get_unread_count":
                servitech_notification_json_response([
                    "ok" => true,
                    "unread_count" => servitech_notification_unread_count($pdo, $notificationUserId),
                ]);

            case "mark_all_read":
                servitech_notification_require_write_request();

                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = TRUE
                    WHERE user_id = :user_id
                      AND COALESCE(is_read, FALSE) = FALSE
                ");
                $stmt->execute([":user_id" => $notificationUserId]);

                servitech_notification_json_response([
                    "ok" => true,
                    "unread_count" => 0,
                ]);

            case "clear_all":
                servitech_notification_require_write_request();

                $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = :user_id");
                $stmt->execute([":user_id" => $notificationUserId]);

                servitech_notification_json_response([
                    "ok" => true,
                    "unread_count" => 0,
                ]);

            case "mark_read":
                servitech_notification_require_write_request();

                $notificationId = (int)($_POST["id"] ?? 0);
                if ($notificationId <= 0) {
                    servitech_notification_json_response(["ok" => false, "error" => "Invalid notification"], 422);
                }

                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = TRUE
                    WHERE id = :id
                      AND user_id = :user_id
                ");
                $stmt->execute([
                    ":id" => $notificationId,
                    ":user_id" => $notificationUserId,
                ]);

                servitech_notification_json_response([
                    "ok" => true,
                    "unread_count" => servitech_notification_unread_count($pdo, $notificationUserId),
                ]);

            default:
                servitech_notification_json_response(["ok" => false, "error" => "Unknown action"], 400);
        }
    } catch (Throwable $exception) {
        error_log("notification header error: " . $exception->getMessage());
        servitech_notification_json_response(["ok" => false, "error" => "Notification request failed"], 500);
    }
}

$notificationUserId = servitech_notification_user_id();
$notificationEndpoint = servitech_url("/components/header.php");
$notificationCsrfToken = (string)($_SESSION["csrf_token"] ?? "");
if ($notificationCsrfToken === "" && !headers_sent()) {
    $notificationCsrfToken = servitech_csrf_token();
}
$notificationSupabaseUrl = servitech_notification_supabase_url((string)($host ?? ""));
$notificationSupabaseAnonKey = servitech_notification_supabase_anon_key();
$notificationRoutes = [
    "printing" => servitech_url("/pages/customer/custo_service_status.php"),
    "online_printorder" => servitech_url("/pages/customer/custo_service_status.php"),
    "repair" => servitech_url("/pages/customer/custo_service_status.php"),
    "installation" => servitech_url("/pages/customer/custo_service_status.php"),
    "fallback" => servitech_url("/pages/customer/custo_service_status.php"),
];
?>
<header class="navbar has-nav-menu navbar--notifications">
  <a href="/index.php" class="logo">
    <img src="/assets/images/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
    <h1>ServiTech</h1>
  </a>

  <div class="header-utility">
    <?php if ($notificationUserId > 0): ?>
      <div class="notification-menu" data-notification-menu>
        <button
          class="notification-btn"
          type="button"
          aria-label="Notifications"
          aria-expanded="false"
          aria-controls="header-notification-dropdown"
          data-notification-toggle
        >
          <span class="notification-btn__icon-wrap">
            <img src="/assets/images/notification.png" alt="" class="notification-btn__icon" width="22" height="22">
            <span class="notification-badge" data-notification-badge hidden>0</span>
          </span>
        </button>

        <div
          id="header-notification-dropdown"
          class="notification-dropdown"
          data-notification-dropdown
          aria-hidden="true"
        >
          <div class="notification-dropdown__header">
            <div>
              <h2>Notifications</h2>
              <p>Real-time queue updates</p>
            </div>
          </div>

          <div class="notification-dropdown__actions">
            <button type="button" class="notification-action-btn" data-notification-mark-all>
              Mark all as read
            </button>
            <button type="button" class="notification-action-btn notification-action-btn--danger" data-notification-clear>
              Clear all
            </button>
          </div>

          <div class="notification-list" data-notification-list>
            <div class="notification-empty" data-notification-empty>No notifications</div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <a href="/auth/logout.php" class="header-utility__link">Logout</a>

    <button
      class="nav-toggle"
      type="button"
      aria-label="Toggle navigation menu"
      aria-expanded="false"
      aria-controls="customer-header-menu"
    >
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
    </button>
  </div>

  <nav id="customer-header-menu" data-collapsible-menu>
    <a href="/pages/customer/customer_dash.php">Dashboard</a>
    <a href="/index.php">Services</a>
  </nav>
</header>

<style>
  .navbar.has-nav-menu.navbar--notifications {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 12px;
  }

  .navbar.has-nav-menu.navbar--notifications nav[data-collapsible-menu] {
    grid-column: 2;
    grid-row: 1;
    margin-left: 0;
    justify-content: flex-end;
    min-width: 0;
  }

  .navbar.has-nav-menu.navbar--notifications .header-utility {
    grid-column: 3;
    grid-row: 1;
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    position: relative;
    z-index: 1200;
  }

  .navbar.has-nav-menu.navbar--notifications .header-utility__link,
  .navbar.has-nav-menu.navbar--notifications .header-utility__link:visited {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    padding: 11px 22px;
    border: 1px solid rgba(74, 5, 5, 0.22);
    border-radius: 14px;
    background-color: #ffffff;
    color: #4A0505;
    text-decoration: none;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.2;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
    transition: background-color 0.28s ease, color 0.28s ease, box-shadow 0.28s ease, transform 0.18s ease;
  }

  .navbar.has-nav-menu.navbar--notifications .header-utility__link:hover {
    background-color: #ff8b2c;
    color: #ffffff;
    border-color: rgba(255, 139, 44, 0.92);
    box-shadow: 0 7px 16px rgba(0, 0, 0, 0.24);
    transform: translateY(-1px);
  }

  .navbar.has-nav-menu.navbar--notifications .header-utility__link:active {
    transform: translateY(0);
    box-shadow: 0 3px 9px rgba(0, 0, 0, 0.2);
  }

  .navbar.has-nav-menu.navbar--notifications .header-utility__link:focus-visible {
    outline: 2px solid #ff8b2c;
    outline-offset: 2px;
  }

  .navbar.has-nav-menu.navbar--notifications .notification-menu {
    position: relative;
    display: inline-flex;
    align-items: center;
  }

  .navbar.has-nav-menu.navbar--notifications .notification-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    padding: 0;
    border: 1px solid rgba(74, 5, 5, 0.22);
    border-radius: 14px;
    background: #ffffff;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
    cursor: pointer;
    transition: background-color 0.28s ease, color 0.28s ease, box-shadow 0.28s ease, transform 0.18s ease;
  }

  .navbar.has-nav-menu.navbar--notifications .notification-btn:hover {
    background: #ff8b2c;
    box-shadow: 0 7px 16px rgba(0, 0, 0, 0.24);
    transform: translateY(-1px);
  }

  .navbar.has-nav-menu.navbar--notifications .notification-btn:active {
    transform: translateY(0);
    box-shadow: 0 3px 9px rgba(0, 0, 0, 0.2);
  }

  .navbar.has-nav-menu.navbar--notifications .notification-btn:focus-visible {
    outline: 2px solid #ff8b2c;
    outline-offset: 2px;
  }

  .notification-btn__icon-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .notification-btn__icon {
    display: block;
    width: 22px;
    height: 22px;
    object-fit: contain;
  }

  .notification-badge {
    position: absolute;
    top: -8px;
    right: -10px;
    min-width: 19px;
    height: 19px;
    padding: 0 5px;
    border-radius: 999px;
    background: #d72638;
    color: #ffffff;
    font-size: 11px;
    font-weight: 800;
    line-height: 19px;
    text-align: center;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.22);
  }

  .notification-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: min(380px, calc(100vw - 24px));
    max-height: min(70vh, 540px);
    padding: 14px;
    border: 1px solid rgba(74, 5, 5, 0.14);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.98);
    box-shadow: 0 20px 44px rgba(23, 16, 12, 0.24);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: translateY(-8px) scale(0.98);
    transform-origin: top right;
    transition: opacity 0.2s ease, transform 0.22s ease, visibility 0.22s ease;
    backdrop-filter: blur(12px);
  }

  .notification-dropdown.is-open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transform: translateY(0) scale(1);
  }

  .notification-dropdown__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }

  .notification-dropdown__header h2 {
    margin: 0;
    color: #4A0505;
    font-size: 1rem;
    line-height: 1.2;
  }

  .notification-dropdown__header p {
    margin: 4px 0 0;
    color: #7a5b44;
    font-size: 0.82rem;
  }

  .notification-dropdown__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
  }

  .notification-action-btn {
    flex: 1 1 0;
    min-height: 38px;
    padding: 8px 12px;
    border: 1px solid rgba(74, 5, 5, 0.14);
    border-radius: 12px;
    background: #fff7ed;
    color: #7a3a00;
    font-size: 0.86rem;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 0.2s ease, color 0.2s ease, transform 0.18s ease;
  }

  .notification-action-btn:hover:not(:disabled) {
    background: #ffecd9;
    transform: translateY(-1px);
  }

  .notification-action-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .notification-action-btn--danger {
    background: #fff1f2;
    color: #b42318;
  }

  .notification-action-btn--danger:hover:not(:disabled) {
    background: #ffe4e8;
  }

  .notification-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: min(52vh, 420px);
    overflow-y: auto;
    padding-right: 4px;
  }

  .notification-empty {
    padding: 18px 14px;
    border: 1px dashed rgba(122, 91, 68, 0.28);
    border-radius: 14px;
    background: #fffaf5;
    color: #7a5b44;
    text-align: center;
    font-size: 0.95rem;
  }

  .notification-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    padding: 12px 14px;
    border: 1px solid rgba(74, 5, 5, 0.1);
    border-radius: 14px;
    background: #ffffff;
    color: #3d2014;
    text-align: left;
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.18s ease, box-shadow 0.2s ease;
  }

  .notification-item:hover {
    background: #fff5ea;
    border-color: rgba(255, 139, 44, 0.35);
    box-shadow: 0 10px 18px rgba(123, 79, 21, 0.12);
    transform: translateY(-1px);
  }

  .notification-item:focus-visible {
    outline: 2px solid #ff8b2c;
    outline-offset: 2px;
  }

  .notification-item.is-unread {
    background: #fff9ef;
    border-color: rgba(255, 177, 71, 0.4);
  }

  .notification-item__content {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 5px;
    flex: 1 1 auto;
  }

  .notification-item__message {
    color: #4A0505;
    font-size: 0.95rem;
    font-weight: 600;
    line-height: 1.4;
    word-break: break-word;
  }

  .notification-item__time {
    color: #7a5b44;
    font-size: 0.78rem;
    line-height: 1.3;
  }

  .notification-item__indicator {
    flex: 0 0 auto;
    width: 10px;
    height: 10px;
    margin-top: 6px;
    border-radius: 999px;
    background: #d72638;
    box-shadow: 0 0 0 4px rgba(215, 38, 56, 0.12);
  }

  .notification-item:not(.is-unread) .notification-item__indicator {
    opacity: 0;
  }

  @media (max-width: 900px) {
    .navbar.has-nav-menu.navbar--notifications {
      grid-template-columns: minmax(0, 1fr) auto;
      row-gap: 10px;
    }

    .navbar.has-nav-menu.navbar--notifications .logo {
      grid-column: 1;
      grid-row: 1;
      min-width: 0;
    }

    .navbar.has-nav-menu.navbar--notifications .header-utility {
      grid-column: 2;
      grid-row: 1;
      justify-self: end;
      margin-left: 0;
    }

    .navbar.has-nav-menu.navbar--notifications nav[data-collapsible-menu] {
      grid-column: 1 / -1;
      grid-row: 2;
      width: 100%;
      margin-top: 0;
    }
  }

  @media (max-width: 520px) {
    .navbar.has-nav-menu.navbar--notifications {
      grid-template-columns: minmax(112px, 1fr) auto;
      column-gap: 8px;
      padding-left: 20px;
      padding-right: 20px;
    }

    .navbar.has-nav-menu.navbar--notifications .logo {
      gap: 8px;
    }

    .navbar.has-nav-menu.navbar--notifications .servitech-logo {
      width: 34px;
      height: 34px;
    }

    .navbar.has-nav-menu.navbar--notifications .logo h1 {
      font-size: 1.2rem;
      line-height: 1.1;
    }

    .navbar.has-nav-menu.navbar--notifications .header-utility {
      gap: 6px;
      min-width: 0;
    }

    .navbar.has-nav-menu.navbar--notifications .notification-btn,
    .navbar.has-nav-menu.navbar--notifications .header-utility .nav-toggle {
      width: 40px;
      height: 40px;
      border-radius: 11px;
    }

    .navbar.has-nav-menu.navbar--notifications .header-utility__link,
    .navbar.has-nav-menu.navbar--notifications .header-utility__link:visited {
      min-height: 40px;
      padding: 9px 13px;
      border-radius: 11px;
      font-size: 14px;
    }

    .notification-dropdown {
      position: fixed;
      top: 76px;
      left: 10px;
      right: 10px;
      width: auto;
      max-height: calc(100vh - 96px);
      border-radius: 16px;
      transform-origin: top center;
      z-index: 2000;
    }

    .notification-dropdown__actions {
      flex-direction: column;
    }

    .notification-action-btn {
      width: 100%;
    }

    .notification-list {
      max-height: calc(100vh - 250px);
    }

    .notification-item {
      padding: 11px 12px;
      gap: 10px;
    }

    .notification-item__message {
      font-size: 0.9rem;
      line-height: 1.35;
    }

    .notification-item__time {
      font-size: 0.76rem;
    }
  }
</style>

<?php if ($notificationUserId > 0): ?>
  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  <script>
    (function () {
      if (window.__servitechNotificationHeaderInit) {
        return;
      }
      window.__servitechNotificationHeaderInit = true;

      var root = document.querySelector("[data-notification-menu]");
      if (!root) {
        return;
      }

      var config = {
        endpoint: <?= json_encode($notificationEndpoint) ?>,
        csrfToken: <?= json_encode($notificationCsrfToken) ?>,
        userId: <?= json_encode($notificationUserId) ?>,
        supabaseUrl: <?= json_encode($notificationSupabaseUrl) ?>,
        supabaseAnonKey: <?= json_encode($notificationSupabaseAnonKey) ?>,
        routes: <?= json_encode($notificationRoutes) ?>,
      };

      var toggleButton = root.querySelector("[data-notification-toggle]");
      var dropdown = root.querySelector("[data-notification-dropdown]");
      var badge = root.querySelector("[data-notification-badge]");
      var list = root.querySelector("[data-notification-list]");
      var emptyState = root.querySelector("[data-notification-empty]");
      var markAllButton = root.querySelector("[data-notification-mark-all]");
      var clearAllButton = root.querySelector("[data-notification-clear]");
      var unreadCount = 0;
      var notificationPollTimer = null;
      var notificationRefreshInFlight = false;
      var realtimeConnected = false;
      var notificationPollMs = 4000;

      function formatDate(value) {
        if (!value) {
          return "";
        }

        var date = new Date(value);
        if (isNaN(date.getTime())) {
          return value;
        }

        return new Intl.DateTimeFormat("en-US", {
          month: "short",
          day: "2-digit",
          year: "numeric",
          hour: "2-digit",
          minute: "2-digit"
        }).format(date);
      }

      function normalizeNotification(data) {
        var normalized = data || {};
        return {
          id: Number(normalized.id || 0),
          user_id: Number(normalized.user_id || 0),
          type: normalized.type || "",
          reference_id: normalized.reference_id == null ? null : Number(normalized.reference_id),
          message: normalized.message || "New notification",
          is_read: normalized.is_read === true || normalized.is_read === "true" || normalized.is_read === "t" || normalized.is_read === 1 || normalized.is_read === "1",
          created_at: normalized.created_at || "",
          created_at_label: normalized.created_at_label || formatDate(normalized.created_at || "")
        };
      }

      function normalizeNotificationType(type) {
        return String(type || "")
          .trim()
          .toLowerCase()
          .replace(/[\s-]+/g, "_");
      }

      function buildNotificationUrl(notification) {
        var normalizedType = normalizeNotificationType(notification.type);
        var referenceId = Number(notification.reference_id || 0);
        var route = config.routes.fallback;

        if (normalizedType === "printing" || normalizedType === "online_printorder") {
          route = config.routes.printing;
        } else if (normalizedType === "repair") {
          route = config.routes.repair;
        } else if (normalizedType === "installation") {
          route = config.routes.installation;
        }

        if (referenceId <= 0) {
          return route;
        }

        var url = new URL(route, window.location.origin);
        url.searchParams.set("queue_id", String(referenceId));
        url.searchParams.set("open", "notification");
        return url.toString();
      }

      function setBadgeCount(count) {
        unreadCount = Math.max(0, Number(count) || 0);
        badge.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
        badge.hidden = unreadCount <= 0;
        syncActionButtons();
      }

      function incrementBadge() {
        setBadgeCount(unreadCount + 1);
      }

      function getNotificationItem(id) {
        return list.querySelector('[data-notification-id="' + String(id) + '"]');
      }

      function syncEmptyState() {
        var hasItems = list.querySelectorAll(".notification-item").length > 0;
        emptyState.hidden = hasItems;
      }

      function syncActionButtons() {
        var hasItems = list.querySelectorAll(".notification-item").length > 0;
        clearAllButton.disabled = !hasItems;
        markAllButton.disabled = !hasItems || unreadCount <= 0;
      }

      function createNotificationItem(notification) {
        var item = document.createElement("button");
        item.type = "button";
        item.className = "notification-item" + (notification.is_read ? "" : " is-unread");
        item.dataset.notificationId = String(notification.id);
        item.dataset.notificationRead = notification.is_read ? "true" : "false";
        item.dataset.notificationType = notification.type || "";
        item.dataset.notificationReferenceId = notification.reference_id == null ? "" : String(notification.reference_id);
        item.dataset.notificationUrl = buildNotificationUrl(notification);

        var content = document.createElement("span");
        content.className = "notification-item__content";

        var message = document.createElement("span");
        message.className = "notification-item__message";
        message.textContent = notification.message;

        var time = document.createElement("span");
        time.className = "notification-item__time";
        time.textContent = notification.created_at_label;

        var indicator = document.createElement("span");
        indicator.className = "notification-item__indicator";
        indicator.setAttribute("aria-hidden", "true");

        content.appendChild(message);
        content.appendChild(time);
        item.appendChild(content);
        item.appendChild(indicator);

        return item;
      }

      function addNotificationToUI(data) {
        var notification = normalizeNotification(data);
        if (!notification.id) {
          return null;
        }

        var existing = getNotificationItem(notification.id);
        if (existing) {
          existing.remove();
        }

        var item = createNotificationItem(notification);
        list.prepend(item);
        syncEmptyState();
        syncActionButtons();
        return item;
      }

      function applyReadState(notificationId, isRead) {
        var item = getNotificationItem(notificationId);
        if (!item) {
          return;
        }

        item.dataset.notificationRead = isRead ? "true" : "false";
        item.classList.toggle("is-unread", !isRead);
      }

      function openDropdown() {
        dropdown.classList.add("is-open");
        dropdown.setAttribute("aria-hidden", "false");
        toggleButton.setAttribute("aria-expanded", "true");
      }

      function closeDropdown() {
        dropdown.classList.remove("is-open");
        dropdown.setAttribute("aria-hidden", "true");
        toggleButton.setAttribute("aria-expanded", "false");
      }

      function toggleDropdown() {
        if (dropdown.classList.contains("is-open")) {
          closeDropdown();
        } else {
          openDropdown();
        }
      }

      async function fetchJson(url, options) {
        var response = await fetch(url, options || {
          credentials: "same-origin",
          headers: {
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest"
          }
        });
        var text = await response.text();
        var data = {};

        try {
          data = text ? JSON.parse(text) : {};
        } catch (error) {
          throw new Error("Invalid server response");
        }

        if (!response.ok || !data.ok) {
          throw new Error(data.error || "Notification request failed");
        }

        return data;
      }

      async function postAction(action, payload) {
        var params = new URLSearchParams();
        params.set("notifications_action", action);

        Object.keys(payload || {}).forEach(function (key) {
          if (payload[key] != null) {
            params.set(key, String(payload[key]));
          }
        });

        return fetchJson(config.endpoint, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Accept": "application/json",
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            "X-CSRF-Token": config.csrfToken,
            "X-Requested-With": "XMLHttpRequest"
          },
          body: params.toString()
        });
      }

      async function loadNotifications() {
        var url = new URL(config.endpoint, window.location.origin);
        url.searchParams.set("notifications_action", "get_notifications");

        var data = await fetchJson(url.toString());
        list.querySelectorAll(".notification-item").forEach(function (item) {
          item.remove();
        });

        (data.notifications || []).slice().reverse().forEach(function (notification) {
          addNotificationToUI(notification);
        });

        syncEmptyState();
        syncActionButtons();
      }

      async function loadUnreadCount() {
        var url = new URL(config.endpoint, window.location.origin);
        url.searchParams.set("notifications_action", "get_unread_count");

        var data = await fetchJson(url.toString());
        setBadgeCount(data.unread_count || 0);
      }

      async function refreshNotifications() {
        if (notificationRefreshInFlight || document.hidden) {
          return;
        }

        notificationRefreshInFlight = true;

        try {
          await Promise.all([loadNotifications(), loadUnreadCount()]);
        } catch (error) {
          console.error(error);
        } finally {
          notificationRefreshInFlight = false;
        }
      }

      function startNotificationPolling() {
        if (notificationPollTimer) {
          return;
        }

        notificationPollTimer = window.setInterval(refreshNotifications, notificationPollMs);
      }

      async function markNotificationAsRead(notificationId) {
        var item = getNotificationItem(notificationId);
        if (!item || item.dataset.notificationRead === "true") {
          return;
        }

        var data = await postAction("mark_read", { id: notificationId });
        applyReadState(notificationId, true);
        setBadgeCount(data.unread_count || 0);
      }

      async function handleNotificationClick(item) {
        if (!item) {
          return;
        }

        var notificationId = Number(item.dataset.notificationId || 0);
        var targetUrl = item.dataset.notificationUrl || buildNotificationUrl({
          type: item.dataset.notificationType || "",
          reference_id: item.dataset.notificationReferenceId || ""
        });

        try {
          if (notificationId > 0 && item.dataset.notificationRead !== "true") {
            await markNotificationAsRead(notificationId);
          }
        } catch (error) {
          console.error(error);
        }

        if (targetUrl) {
          window.location.href = targetUrl;
        }
      }

      async function markAllAsRead() {
        var data = await postAction("mark_all_read");
        list.querySelectorAll(".notification-item").forEach(function (item) {
          item.dataset.notificationRead = "true";
          item.classList.remove("is-unread");
        });
        setBadgeCount(data.unread_count || 0);
      }

      async function clearAllNotifications() {
        var data = await postAction("clear_all");
        list.querySelectorAll(".notification-item").forEach(function (item) {
          item.remove();
        });
        setBadgeCount(data.unread_count || 0);
        syncEmptyState();
        syncActionButtons();
      }

      function initRealtime() {
        if (!config.supabaseUrl || !config.supabaseAnonKey || !window.supabase || !window.supabase.createClient) {
          console.warn("Supabase realtime was not initialized. Missing client config.");
          startNotificationPolling();
          return;
        }

        var supabaseClient = window.supabase.createClient(config.supabaseUrl, config.supabaseAnonKey);

        supabaseClient
          .channel("notifications")
          .on(
            "postgres_changes",
            {
              event: "INSERT",
              schema: "public",
              table: "notifications",
              filter: "user_id=eq." + config.userId
            },
            function (payload) {
              var notification = normalizeNotification(payload.new);
              var item = addNotificationToUI(notification);
              if (item && !notification.is_read) {
                incrementBadge();
              }
            }
          )
          .subscribe(function (status) {
            realtimeConnected = status === "SUBSCRIBED";
            if (!realtimeConnected) {
              startNotificationPolling();
            }
          });
      }

      toggleButton.addEventListener("click", function (event) {
        event.stopPropagation();
        toggleDropdown();
      });

      document.addEventListener("click", function (event) {
        if (!root.contains(event.target)) {
          closeDropdown();
        }
      });

      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeDropdown();
        }
      });

      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
          refreshNotifications();
        }
      });

      list.addEventListener("click", function (event) {
        var item = event.target.closest(".notification-item");
        if (!item) {
          return;
        }

        handleNotificationClick(item);
      });

      markAllButton.addEventListener("click", function () {
        markAllAsRead().catch(function (error) {
          console.error(error);
        });
      });

      clearAllButton.addEventListener("click", function () {
        clearAllNotifications().catch(function (error) {
          console.error(error);
        });
      });

      window.addNotificationToUI = addNotificationToUI;
      window.incrementBadge = incrementBadge;
      window.markAllAsRead = markAllAsRead;
      window.clearAllNotifications = clearAllNotifications;

      refreshNotifications()
        .finally(function () {
          initRealtime();
          startNotificationPolling();
        });
    })();
  </script>
<?php endif; ?>

<script src="/assets/js/header-menu.js" defer></script>
