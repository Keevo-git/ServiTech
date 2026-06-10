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
            "category" => trim((string)($row["notification_category"] ?? "")),
            "queue_code" => trim((string)($row["queue_code"] ?? "")),
            "queue_status" => trim((string)($row["queue_status"] ?? "")),
            "created_at" => trim((string)($row["created_at"] ?? "")),
            "created_at_label" => servitech_notification_format_timestamp((string)($row["created_at"] ?? "")),
        ];
    }
}

if (!function_exists("servitech_notification_fetch_all")) {
    function servitech_notification_fetch_all(PDO $pdo, int $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        $stmt = $pdo->prepare("
            WITH ranked_notifications AS (
                SELECT
                    n.id,
                    n.user_id,
                    n.type,
                    n.reference_id,
                    n.message,
                    n.event_key,
                    n.is_read,
                    n.created_at,
                    q.category AS notification_category,
                    q.queue_code,
                    q.status AS queue_status,
                    ROW_NUMBER() OVER (
                        PARTITION BY n.user_id, LOWER(TRIM(COALESCE(n.type, 'queue'))), COALESCE(n.reference_id, 0),
                          COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
                        ORDER BY n.created_at DESC, n.id DESC
                    ) AS duplicate_rank
                FROM notifications n
                LEFT JOIN queues q ON q.id = n.reference_id
                WHERE n.user_id = :user_id
                  AND n.deleted_at IS NULL
            )
            SELECT id, user_id, type, reference_id, message, is_read, created_at,
                   notification_category, queue_code, queue_status
            FROM ranked_notifications
            WHERE duplicate_rank = 1
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
            WITH ranked_notifications AS (
                SELECT
                    is_read,
                    event_key,
                    ROW_NUMBER() OVER (
                        PARTITION BY user_id, LOWER(TRIM(COALESCE(type, 'queue'))), COALESCE(reference_id, 0),
                          COALESCE(NULLIF(TRIM(event_key), ''), MD5(TRIM(COALESCE(message, ''))))
                        ORDER BY created_at DESC, id DESC
                    ) AS duplicate_rank
                FROM notifications
                WHERE user_id = :user_id
                  AND deleted_at IS NULL
            )
            SELECT COUNT(*)
            FROM ranked_notifications
            WHERE duplicate_rank = 1
              AND COALESCE(is_read, FALSE) = FALSE
        ");
        $stmt->execute([":user_id" => $userId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists("servitech_notification_requested_ids")) {
    function servitech_notification_requested_ids(): array
    {
        $rawIds = $_POST["ids"] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = explode(",", (string)$rawIds);
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = (int)$rawId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_slice(array_values($ids), 0, 100);
    }
}

if (!function_exists("servitech_notification_id_placeholders")) {
    function servitech_notification_id_placeholders(array $ids): array
    {
        $placeholders = [];
        $parameters = [];

        foreach (array_values($ids) as $index => $id) {
            $placeholder = ":notification_id_" . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = (int)$id;
        }

        return [implode(", ", $placeholders), $parameters];
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

if (!function_exists("servitech_notification_realtime_enabled")) {
    function servitech_notification_realtime_enabled(): bool
    {
        $value = strtolower(trim((string)getenv("SERVITECH_ENABLE_SUPABASE_REALTIME")));
        return in_array($value, ["1", "true", "yes", "on"], true);
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
                      AND deleted_at IS NULL
                      AND COALESCE(is_read, FALSE) = FALSE
                ");
                $stmt->execute([":user_id" => $notificationUserId]);

                servitech_notification_json_response([
                    "ok" => true,
                    "unread_count" => 0,
                ]);

            case "clear_all":
                servitech_notification_require_write_request();

                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET deleted_at = NOW()
                    WHERE user_id = :user_id
                      AND deleted_at IS NULL
                ");
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
                      AND deleted_at IS NULL
                ");
                $stmt->execute([
                    ":id" => $notificationId,
                    ":user_id" => $notificationUserId,
                ]);

                servitech_notification_json_response([
                    "ok" => true,
                    "unread_count" => servitech_notification_unread_count($pdo, $notificationUserId),
                ]);

            case "mark_selected_read":
                servitech_notification_require_write_request();

                $notificationIds = servitech_notification_requested_ids();
                if ($notificationIds === []) {
                    servitech_notification_json_response(["ok" => false, "error" => "Select at least one notification"], 422);
                }

                [$notificationPlaceholders, $notificationParameters] = servitech_notification_id_placeholders($notificationIds);
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = TRUE
                    WHERE user_id = :user_id
                      AND deleted_at IS NULL
                      AND id IN ({$notificationPlaceholders})
                ");
                $stmt->execute(array_merge([":user_id" => $notificationUserId], $notificationParameters));

                servitech_notification_json_response([
                    "ok" => true,
                    "unread_count" => servitech_notification_unread_count($pdo, $notificationUserId),
                ]);

            case "delete_selected":
                servitech_notification_require_write_request();

                $notificationIds = servitech_notification_requested_ids();
                if ($notificationIds === []) {
                    servitech_notification_json_response(["ok" => false, "error" => "Select at least one notification"], 422);
                }

                [$notificationPlaceholders, $notificationParameters] = servitech_notification_id_placeholders($notificationIds);
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET deleted_at = NOW()
                    WHERE user_id = :user_id
                      AND deleted_at IS NULL
                      AND id IN ({$notificationPlaceholders})
                ");
                $stmt->execute(array_merge([":user_id" => $notificationUserId], $notificationParameters));

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
$notificationRealtimeEnabled = servitech_notification_realtime_enabled();
$notificationSupabaseUrl = $notificationRealtimeEnabled
    ? servitech_notification_supabase_url((string)($host ?? ""))
    : "";
$notificationSupabaseAnonKey = $notificationRealtimeEnabled
    ? servitech_notification_supabase_anon_key()
    : "";
$notificationRoutes = [
    "printing" => servitech_url("/pages/customer/custo_service_status.php"),
    "online_printorder" => servitech_url("/pages/customer/custo_service_status.php"),
    "repair" => servitech_url("/pages/customer/custo_service_status.php"),
    "installation" => servitech_url("/pages/customer/custo_service_status.php"),
    "fallback" => servitech_url("/pages/customer/custo_service_status.php"),
];
?>
<header class="navbar has-nav-menu navbar--notifications customer-shared-header">
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
          aria-controls="notificationPanel"
          data-notification-toggle
        >
          <span class="notification-btn__icon-wrap">
            <img src="/assets/images/notification.png" alt="" class="notification-btn__icon" width="22" height="22">
            <span class="notification-badge" data-notification-badge hidden>0</span>
          </span>
        </button>

        <div class="notification-backdrop" data-notification-backdrop aria-hidden="true"></div>

        <section
          id="notificationPanel"
          class="notification-dropdown"
          data-notification-dropdown
          role="dialog"
          aria-labelledby="notificationPanelTitle"
          aria-modal="false"
          aria-hidden="true"
          tabindex="-1"
        >
          <div class="notification-dropdown__header">
            <div>
              <h2 id="notificationPanelTitle">Notification Center</h2>
              <p><strong data-notification-summary>0 unread</strong> queue updates and messages</p>
            </div>
            <button
              type="button"
              class="notification-close-btn"
              aria-label="Close notifications"
              data-notification-close
            ></button>
          </div>

          <div class="notification-center__body">
            <aside class="notification-filters" aria-label="Notification categories">
              <p class="notification-filters__title">Categories</p>
              <div class="notification-filters__list">
                <button type="button" class="notification-filter is-active" data-notification-filter="all">
                  <span>All</span><strong data-notification-filter-count="all">0</strong>
                </button>
                <button type="button" class="notification-filter" data-notification-filter="printing">
                  <span>Printing</span>
                </button>
                <button type="button" class="notification-filter" data-notification-filter="repair">
                  <span>Repair</span>
                </button>
                <button type="button" class="notification-filter" data-notification-filter="installation">
                  <span>Installation</span>
                </button>
                <button type="button" class="notification-filter" data-notification-filter="updates">
                  <span>Other updates</span>
                </button>
              </div>
            </aside>

            <main class="notification-center__main">
              <div class="notification-dropdown__actions">
                <label class="notification-select-all">
                  <input type="checkbox" data-notification-select-all>
                  <span>Select visible</span>
                </label>
                <span class="notification-selection-summary" data-notification-selection-summary>0 selected</span>
                <button type="button" class="notification-action-btn" data-notification-mark-selected>
                  Mark as Read
                </button>
                <button type="button" class="notification-action-btn notification-action-btn--danger" data-notification-delete-selected>
                  Delete
                </button>
              </div>

              <div class="notification-list" data-notification-list>
                <div class="notification-empty" data-notification-empty>
                  <strong>No notifications yet.</strong>
                  <span>Queue updates will appear here.</span>
                </div>
              </div>
            </main>
          </div>
        </section>
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
    <a href="/pages/customer/customer_dash.php">Home</a>
    <a href="/index.php">Services</a>
  </nav>
</header>

<style>
  .customer-shared-header {
    position: sticky;
    top: 0;
    z-index: 1000;
  }

  .navbar.has-nav-menu.navbar--notifications {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: 12px;
    width: 100%;
    padding: 20px 60px;
  }

  .navbar.has-nav-menu.navbar--notifications nav[data-collapsible-menu] {
    order: 2;
    flex: 0 1 auto;
    margin-left: 0;
    justify-content: flex-end;
    min-width: 0;
  }

  .navbar.has-nav-menu.navbar--notifications .header-utility {
    order: 3;
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    position: relative;
    z-index: 1200;
  }

  .navbar.has-nav-menu.navbar--notifications .logo {
    order: 1;
    flex: 1 1 auto;
    min-width: 0;
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

  body.notifications-open {
    overflow: hidden;
  }

  .notification-backdrop {
    position: fixed;
    inset: 0;
    z-index: 4900;
    background: rgba(45, 21, 15, 0.48);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, visibility 0.2s ease;
  }

  .notification-backdrop.is-open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
  }

  .notification-dropdown {
    position: fixed;
    top: 50%;
    left: 50%;
    z-index: 5000;
    display: flex;
    flex-direction: column;
    width: min(1080px, calc(100vw - 48px));
    height: min(720px, calc(100dvh - 48px));
    padding: 20px;
    border: 1px solid rgba(74, 5, 5, 0.14);
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.99);
    box-shadow: 0 26px 70px rgba(23, 16, 12, 0.3);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: translate(-50%, -48%) scale(0.98);
    transition: opacity 0.2s ease, transform 0.22s ease, visibility 0.22s ease;
    backdrop-filter: blur(12px);
  }

  .notification-dropdown.is-open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transform: translate(-50%, -50%) scale(1);
  }

  .notification-dropdown__header {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 16px;
    border-bottom: 1px solid rgba(74, 5, 5, 0.12);
  }

  .notification-dropdown__header h2 {
    margin: 0;
    color: #4A0505;
    font-size: 1.35rem;
    line-height: 1.2;
  }

  .notification-dropdown__header p {
    margin: 5px 0 0;
    color: #7a5b44;
    font-size: 0.86rem;
  }

  .notification-dropdown__header p strong {
    color: #b42318;
  }

  .notification-close-btn {
    display: inline-flex;
    flex: 0 0 auto;
    position: relative;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    padding: 0;
    border: 1px solid #e7cdbd;
    border-radius: 14px;
    background: #f9efe7;
    color: #6b0000;
    font-size: 0;
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
  }

  .notification-close-btn::before,
  .notification-close-btn::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 17px;
    height: 2.5px;
    border-radius: 999px;
    background: currentColor;
  }

  .notification-close-btn::before {
    transform: translate(-50%, -50%) rotate(45deg);
  }

  .notification-close-btn::after {
    transform: translate(-50%, -50%) rotate(-45deg);
  }

  .notification-close-btn:hover {
    border-color: #dfbda9;
    background: #f4e6dc;
    box-shadow: 0 8px 18px rgba(74, 5, 5, 0.1);
    transform: scale(1.02);
  }

  .notification-close-btn:focus-visible,
  .notification-filter:focus-visible,
  .notification-action-btn:focus-visible,
  .notification-item__open:focus-visible {
    outline: 2px solid #ff8b2c;
    outline-offset: 2px;
  }

  .notification-center__body {
    display: grid;
    grid-template-columns: 190px minmax(0, 1fr);
    flex: 1 1 auto;
    min-height: 0;
    gap: 18px;
    padding-top: 16px;
  }

  .notification-filters {
    min-width: 0;
    padding-right: 16px;
    border-right: 1px solid rgba(74, 5, 5, 0.1);
  }

  .notification-filters__title {
    margin: 0 0 10px;
    color: #7a5b44;
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .notification-filters__list {
    display: flex;
    flex-direction: column;
    gap: 7px;
  }

  .notification-filter {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    min-height: 42px;
    padding: 9px 10px;
    border: 1px solid transparent;
    border-radius: 11px;
    background: transparent;
    color: #70452e;
    font-size: 0.84rem;
    font-weight: 700;
    text-align: left;
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease;
  }

  .notification-filter:hover,
  .notification-filter.is-active {
    border-color: rgba(255, 139, 44, 0.25);
    background: #fff3e6;
    color: #7a3a00;
  }

  .notification-filter strong {
    min-width: 22px;
    padding: 2px 6px;
    border-radius: 999px;
    background: rgba(74, 5, 5, 0.08);
    color: #6b0000;
    font-size: 0.72rem;
    text-align: center;
  }

  .notification-center__main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
  }

  .notification-dropdown__actions {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 9px;
    padding-bottom: 12px;
  }

  .notification-select-all {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #603622;
    font-size: 0.84rem;
    font-weight: 700;
    cursor: pointer;
  }

  .notification-select-all input,
  .notification-item__select input {
    width: 17px;
    height: 17px;
    margin: 0;
    accent-color: #b64c00;
    cursor: pointer;
  }

  .notification-selection-summary {
    margin-right: auto;
    color: #7a5b44;
    font-size: 0.8rem;
    white-space: nowrap;
  }

  .notification-action-btn {
    min-height: 40px;
    padding: 8px 13px;
    border: 1px solid rgba(74, 5, 5, 0.14);
    border-radius: 11px;
    background: #fff7ed;
    color: #7a3a00;
    font-size: 0.82rem;
    font-weight: 800;
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.18s ease;
  }

  .notification-action-btn:hover:not(:disabled) {
    background: #ffecd9;
    transform: translateY(-1px);
  }

  .notification-action-btn:disabled {
    opacity: 0.5;
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
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
    gap: 10px;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 2px 5px 5px 1px;
  }

  .notification-empty {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 30px 14px;
    border: 1px dashed rgba(122, 91, 68, 0.28);
    border-radius: 14px;
    background: #fffaf5;
    color: #7a5b44;
    text-align: center;
    font-size: 0.95rem;
  }

  .notification-empty[hidden],
  .notification-item[hidden] {
    display: none;
  }

  .notification-empty strong {
    color: #4A0505;
    font-size: 0.98rem;
  }

  .notification-empty span {
    color: #7a5b44;
    font-size: 0.84rem;
  }

  .notification-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 11px;
    width: 100%;
    padding: 13px 14px;
    border: 1px solid rgba(74, 5, 5, 0.1);
    border-radius: 14px;
    background: #ffffff;
    color: #3d2014;
    transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.18s ease, box-shadow 0.2s ease;
  }

  .notification-item:hover {
    border-color: rgba(255, 139, 44, 0.35);
    box-shadow: 0 10px 18px rgba(123, 79, 21, 0.1);
    transform: translateY(-1px);
  }

  .notification-item.is-unread {
    background: #fff9ef;
    border-color: rgba(255, 177, 71, 0.45);
  }

  .notification-item__select {
    display: flex;
    padding-top: 4px;
  }

  .notification-item__open {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 9px;
    min-width: 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .notification-item__content {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 6px;
  }

  .notification-item__topline,
  .notification-item__details {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 12px;
  }

  .notification-item__category {
    padding: 3px 7px;
    border-radius: 999px;
    background: #f9eee6;
    color: #7a3a00;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .notification-item__message {
    color: #4A0505;
    font-size: 0.92rem;
    font-weight: 500;
    line-height: 1.4;
    word-break: break-word;
  }

  .notification-item__message strong {
    color: #4A0505;
    font-weight: 800;
  }

  .notification-item__details {
    color: #6d4430;
    font-size: 0.78rem;
    line-height: 1.35;
  }

  .notification-item__details strong {
    color: #4A0505;
  }

  .notification-item__time {
    color: #7a5b44;
    font-size: 0.75rem;
    line-height: 1.3;
  }

  .notification-item__indicator {
    width: 10px;
    height: 10px;
    margin-top: 7px;
    border-radius: 999px;
    background: #d72638;
    box-shadow: 0 0 0 4px rgba(215, 38, 56, 0.12);
  }

  .notification-item:not(.is-unread) .notification-item__indicator {
    opacity: 0;
  }

  @media (max-width: 900px) {
    .navbar.has-nav-menu.navbar--notifications {
      display: grid !important;
      grid-template-columns: minmax(0, 1fr) auto !important;
      grid-template-areas:
        "brand utility"
        "menu menu" !important;
      row-gap: 10px !important;
      padding: 18px 24px !important;
    }

    .navbar.has-nav-menu.navbar--notifications .logo {
      grid-area: brand !important;
      min-width: 0 !important;
    }

    .navbar.has-nav-menu.navbar--notifications .header-utility {
      grid-area: utility !important;
      justify-self: end !important;
      margin-left: 0 !important;
    }

    .navbar.has-nav-menu.navbar--notifications nav[data-collapsible-menu] {
      grid-area: menu !important;
      width: 100% !important;
      margin-top: 0 !important;
    }
  }

  @media (max-width: 768px) {
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
      top: 0;
      left: 0;
      width: 100%;
      height: 100vh;
      height: 100dvh;
      padding: max(18px, env(safe-area-inset-top)) 16px max(24px, env(safe-area-inset-bottom));
      border: 0;
      border-radius: 0;
      background: #fffaf5;
      box-shadow: none;
      transform: translateY(12px);
    }

    .notification-dropdown.is-open {
      transform: none;
    }

    .notification-dropdown__header {
      align-items: center;
      margin-bottom: 14px;
      padding-bottom: 12px;
      border-bottom: 1px solid rgba(74, 5, 5, 0.12);
    }

    .notification-dropdown__header h2 {
      font-size: 1.2rem;
    }

    .notification-dropdown__header p {
      font-size: 0.88rem;
    }

    .notification-center__body {
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding-top: 12px;
    }

    .notification-filters {
      padding: 0 0 10px;
      border-right: 0;
      border-bottom: 1px solid rgba(74, 5, 5, 0.1);
    }

    .notification-filters__title {
      margin-bottom: 7px;
    }

    .notification-filters__list {
      flex-direction: row;
      gap: 6px;
      overflow-x: auto;
      padding-bottom: 2px;
    }

    .notification-filter {
      flex: 0 0 auto;
      width: auto;
      min-height: 38px;
      gap: 7px;
      white-space: nowrap;
    }

    .notification-list {
      padding: 2px 2px 24px;
    }

    .notification-item {
      padding: 13px 14px;
      gap: 10px;
      border-radius: 14px;
    }

    .notification-item__message {
      font-size: 0.9rem;
      line-height: 1.35;
    }

    .notification-item__time {
      font-size: 0.76rem;
    }
  }

  @media (max-width: 420px) {
    .notification-dropdown__actions {
      flex-wrap: wrap;
    }

    .notification-select-all {
      flex: 1 1 auto;
    }

    .notification-selection-summary {
      margin-right: 0;
    }

    .notification-action-btn {
      flex: 1 1 calc(50% - 5px);
    }
  }

  /* Responsive QA pass: shared customer header/top navigation */
  .customer-shared-header {
    position: relative;
    z-index: 2200;
    overflow: visible;
  }

  .customer-shared-header .logo,
  .customer-shared-header .header-utility,
  .customer-shared-header nav[data-collapsible-menu] {
    min-width: 0;
  }

  .customer-shared-header .logo h1 {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .customer-shared-header nav[data-collapsible-menu] a {
    flex: 0 0 auto;
  }

  @media (min-width: 901px) {
    .customer-shared-header {
      flex-wrap: nowrap;
    }

    .customer-shared-header nav[data-collapsible-menu] {
      display: flex !important;
      width: auto;
      align-items: center;
      justify-content: flex-end;
      flex-wrap: nowrap;
      overflow: visible;
    }
  }

  @media (max-width: 900px) {
    .customer-shared-header {
      display: grid !important;
      grid-template-columns: minmax(0, 1fr) auto !important;
      grid-template-areas:
        "brand utility"
        "menu menu" !important;
      align-items: center !important;
      gap: 10px 12px !important;
      overflow: visible;
    }

    .customer-shared-header .logo {
      grid-area: brand !important;
      width: auto !important;
      min-width: 0 !important;
    }

    .customer-shared-header .header-utility {
      grid-area: utility !important;
      display: inline-flex !important;
      width: auto !important;
      max-width: 100%;
      justify-self: end !important;
      align-items: center !important;
      gap: 8px;
    }

    .customer-shared-header .header-utility .nav-toggle {
      display: inline-flex !important;
      flex: 0 0 42px;
      margin: 0 !important;
      z-index: 2;
    }

    .customer-shared-header nav[data-collapsible-menu] {
      grid-area: menu !important;
      display: none !important;
      width: 100% !important;
      max-width: 100%;
      margin: 0 !important;
      padding: 10px !important;
      flex-direction: column !important;
      align-items: stretch !important;
      justify-content: flex-start !important;
      gap: 10px !important;
      overflow: visible;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.32);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
    }

    .customer-shared-header.is-menu-open nav[data-collapsible-menu] {
      display: flex !important;
    }

    .customer-shared-header nav[data-collapsible-menu] a,
    .customer-shared-header nav[data-collapsible-menu] a:visited {
      width: 100% !important;
      min-width: 0 !important;
      min-height: 46px;
      margin: 0 !important;
      align-items: center;
      justify-content: center;
      white-space: normal;
      overflow-wrap: anywhere;
    }
  }

  @media (max-width: 520px) {
    .customer-shared-header {
      padding-inline: 14px !important;
      gap: 9px 10px !important;
    }

    .customer-shared-header .servitech-logo {
      width: 32px !important;
      height: 32px !important;
    }

    .customer-shared-header .logo h1 {
      font-size: 1.08rem !important;
    }

    .customer-shared-header .header-utility {
      gap: 6px;
    }

    .customer-shared-header .notification-btn,
    .customer-shared-header .header-utility .nav-toggle {
      width: 40px !important;
      min-width: 40px !important;
      height: 40px !important;
      min-height: 40px !important;
    }

    .customer-shared-header .header-utility__link,
    .customer-shared-header .header-utility__link:visited {
      min-height: 40px;
      padding-inline: 12px;
      font-size: 14px;
    }
  }

  @media (max-width: 360px) {
    .customer-shared-header {
      padding-inline: 12px !important;
    }

    .customer-shared-header .logo h1 {
      max-width: 118px;
    }

    .customer-shared-header .header-utility__link,
    .customer-shared-header .header-utility__link:visited {
      padding-inline: 10px;
    }
  }
</style>

<?php if ($notificationUserId > 0): ?>
  <?php if ($notificationRealtimeEnabled): ?>
  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  <?php endif; ?>
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
      var backdrop = root.querySelector("[data-notification-backdrop]");
      var badge = root.querySelector("[data-notification-badge]");
      var list = root.querySelector("[data-notification-list]");
      var emptyState = root.querySelector("[data-notification-empty]");
      var summary = root.querySelector("[data-notification-summary]");
      var selectionSummary = root.querySelector("[data-notification-selection-summary]");
      var selectAllCheckbox = root.querySelector("[data-notification-select-all]");
      var markSelectedButton = root.querySelector("[data-notification-mark-selected]");
      var deleteSelectedButton = root.querySelector("[data-notification-delete-selected]");
      var filterButtons = Array.from(root.querySelectorAll("[data-notification-filter]"));
      var closeButton = root.querySelector("[data-notification-close]");
      var unreadCount = 0;
      var activeFilter = "all";
      var selectedNotificationIds = new Set();
      var notificationPollTimer = null;
      var notificationRefreshInFlight = false;
      var realtimeConnected = false;
      var notificationPollMs = 4000;

      function syncPanelMode() {
        var isOpen = dropdown.classList.contains("is-open");
        dropdown.setAttribute("aria-modal", isOpen ? "true" : "false");
        document.body.classList.toggle("notifications-open", isOpen);
        backdrop.classList.toggle("is-open", isOpen);
        backdrop.setAttribute("aria-hidden", isOpen ? "false" : "true");
      }

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
          category: normalized.category || "",
          queue_code: normalized.queue_code || "",
          queue_status: normalized.queue_status || "",
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

      function notificationCategory(notification) {
        var category = normalizeNotificationType(notification.category || notification.type);
        if (category === "online_printorder" || category === "printing_online" || category === "printing") {
          return "printing";
        }
        if (category === "repair" || category === "installation") {
          return category;
        }
        return "updates";
      }

      function categoryLabel(category) {
        if (category === "printing") return "Printing";
        if (category === "repair") return "Repair";
        if (category === "installation") return "Installation";
        return "Update";
      }

      function appendHighlightedNotificationMessage(target, text) {
        var remainingMessage = String(text || "");
        var importantDetailPattern = /(\(Queue ID:\s*[A-Z0-9-]+\))|(Queue ID:\s*)([A-Z0-9-]+)|(is now\s+)(APPROVED|ONGOING|FOR PICK-UP|DONE|CANCELLED)|(Status:\s*)(APPROVED|ONGOING|FOR PICK-UP|DONE|CANCELLED)/gi;
        var match;
        var previousIndex = 0;

        while ((match = importantDetailPattern.exec(remainingMessage)) !== null) {
          target.appendChild(document.createTextNode(remainingMessage.slice(previousIndex, match.index)));

          if (match[1]) {
            var queueText = document.createElement("strong");
            queueText.textContent = match[1];
            target.appendChild(queueText);
          } else if (match[2]) {
            target.appendChild(document.createTextNode(match[2]));
            var queueValue = document.createElement("strong");
            queueValue.textContent = match[3];
            target.appendChild(queueValue);
          } else if (match[4]) {
            target.appendChild(document.createTextNode(match[4]));
            var nowStatus = document.createElement("strong");
            nowStatus.textContent = match[5];
            target.appendChild(nowStatus);
          } else if (match[6]) {
            target.appendChild(document.createTextNode(match[6]));
            var statusValue = document.createElement("strong");
            statusValue.textContent = match[7];
            target.appendChild(statusValue);
          }

          previousIndex = importantDetailPattern.lastIndex;
        }

        target.appendChild(document.createTextNode(remainingMessage.slice(previousIndex)));
      }

      function setBadgeCount(count) {
        unreadCount = Math.max(0, Number(count) || 0);
        badge.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
        badge.hidden = unreadCount <= 0;
        summary.textContent = unreadCount + (unreadCount === 1 ? " unread" : " unread");
        syncActionButtons();
        syncFilterCounts();
      }

      function incrementBadge() {
        setBadgeCount(unreadCount + 1);
      }

      function getNotificationItem(id) {
        return list.querySelector('[data-notification-id="' + String(id) + '"]');
      }

      function visibleNotificationItems() {
        return Array.from(list.querySelectorAll(".notification-item")).filter(function (item) {
          return !item.hidden;
        });
      }

      function syncEmptyState() {
        var hasVisibleItems = visibleNotificationItems().length > 0;
        emptyState.hidden = hasVisibleItems;
        emptyState.querySelector("strong").textContent = hasVisibleItems
          ? ""
          : activeFilter === "all"
            ? "No notifications yet."
            : "No notifications in this category.";
        emptyState.querySelector("span").textContent = activeFilter === "all"
          ? "Queue updates will appear here."
          : "Try another filter to see more updates.";
      }

      function syncActionButtons() {
        var selectedItems = Array.from(list.querySelectorAll(".notification-item")).filter(function (item) {
          return selectedNotificationIds.has(Number(item.dataset.notificationId || 0));
        });
        var visibleItems = visibleNotificationItems();
        var selectedVisibleCount = visibleItems.filter(function (item) {
          return selectedNotificationIds.has(Number(item.dataset.notificationId || 0));
        }).length;
        var hasUnreadSelection = selectedItems.some(function (item) {
          return item.dataset.notificationRead !== "true";
        });

        selectionSummary.textContent = selectedNotificationIds.size + " selected";
        markSelectedButton.disabled = !hasUnreadSelection;
        deleteSelectedButton.disabled = selectedNotificationIds.size === 0;
        selectAllCheckbox.disabled = visibleItems.length === 0;
        selectAllCheckbox.checked = visibleItems.length > 0 && selectedVisibleCount === visibleItems.length;
        selectAllCheckbox.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < visibleItems.length;
      }

      function syncFilterCounts() {
        var unreadTotal = 0;

        list.querySelectorAll(".notification-item").forEach(function (item) {
          if (item.dataset.notificationRead !== "true") {
            unreadTotal += 1;
          }
        });

        var allCount = root.querySelector('[data-notification-filter-count="all"]');
        if (allCount) allCount.textContent = String(unreadTotal);
      }

      function applyFilter() {
        list.querySelectorAll(".notification-item").forEach(function (item) {
          var isVisible = activeFilter === "all"
            || item.dataset.notificationCategory === activeFilter;
          item.hidden = !isVisible;
        });

        filterButtons.forEach(function (button) {
          var isActive = button.dataset.notificationFilter === activeFilter;
          button.classList.toggle("is-active", isActive);
          button.setAttribute("aria-pressed", isActive ? "true" : "false");
        });

        syncEmptyState();
        syncActionButtons();
      }

      function createNotificationItem(notification) {
        var item = document.createElement("article");
        var category = notificationCategory(notification);
        item.className = "notification-item" + (notification.is_read ? "" : " is-unread");
        item.dataset.notificationId = String(notification.id);
        item.dataset.notificationRead = notification.is_read ? "true" : "false";
        item.dataset.notificationType = notification.type || "";
        item.dataset.notificationCategory = category;
        item.dataset.notificationReferenceId = notification.reference_id == null ? "" : String(notification.reference_id);
        item.dataset.notificationUrl = buildNotificationUrl(notification);

        var selectLabel = document.createElement("label");
        selectLabel.className = "notification-item__select";
        selectLabel.setAttribute("aria-label", "Select notification");

        var checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.checked = selectedNotificationIds.has(notification.id);
        checkbox.dataset.notificationSelect = "";

        var openButton = document.createElement("button");
        openButton.type = "button";
        openButton.className = "notification-item__open";
        openButton.dataset.notificationOpen = "";

        var content = document.createElement("span");
        content.className = "notification-item__content";

        var topline = document.createElement("span");
        topline.className = "notification-item__topline";

        var categoryBadge = document.createElement("span");
        categoryBadge.className = "notification-item__category";
        categoryBadge.textContent = categoryLabel(category);

        var message = document.createElement("span");
        message.className = "notification-item__message";
        if (normalizeNotificationType(notification.type) === "price_update") {
          var remainingMessage = String(notification.message || "");
          var importantDetailPattern = /(Queue ID\s+[A-Z0-9-]+)|(New price:\s*)(PHP\s+[0-9,]+(?:\.[0-9]{2})?)/gi;
          var match;
          var previousIndex = 0;
          while ((match = importantDetailPattern.exec(remainingMessage)) !== null) {
            message.appendChild(document.createTextNode(remainingMessage.slice(previousIndex, match.index)));
            if (match[2]) {
              message.appendChild(document.createTextNode(match[2]));
            }
            var importantDetail = document.createElement("strong");
            importantDetail.textContent = match[1] || match[3];
            message.appendChild(importantDetail);
            previousIndex = importantDetailPattern.lastIndex;
          }
          message.appendChild(document.createTextNode(remainingMessage.slice(previousIndex)));
        } else {
          appendHighlightedNotificationMessage(message, notification.message);
        }

        var details = document.createElement("span");
        details.className = "notification-item__details";

        var queueId = notification.queue_code || (notification.reference_id ? String(notification.reference_id) : "");
        if (queueId) {
          var queueDetail = document.createElement("span");
          var queueLabel = document.createElement("strong");
          queueLabel.textContent = "Queue ID: ";
          queueDetail.appendChild(queueLabel);
          queueDetail.appendChild(document.createTextNode(queueId));
          details.appendChild(queueDetail);
        }

        if (notification.queue_status) {
          var statusDetail = document.createElement("span");
          var statusLabel = document.createElement("strong");
          statusLabel.textContent = "Status: ";
          statusDetail.appendChild(statusLabel);
          statusDetail.appendChild(document.createTextNode(notification.queue_status));
          details.appendChild(statusDetail);
        }

        var time = document.createElement("span");
        time.className = "notification-item__time";
        time.textContent = notification.created_at_label;

        var indicator = document.createElement("span");
        indicator.className = "notification-item__indicator";
        indicator.setAttribute("aria-hidden", "true");

        selectLabel.appendChild(checkbox);
        topline.appendChild(categoryBadge);
        topline.appendChild(time);
        content.appendChild(topline);
        content.appendChild(message);
        if (details.childNodes.length > 0) {
          content.appendChild(details);
        }
        openButton.appendChild(content);
        openButton.appendChild(indicator);
        item.appendChild(selectLabel);
        item.appendChild(openButton);

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
        applyFilter();
        syncFilterCounts();
        return item;
      }

      function applyReadState(notificationId, isRead) {
        var item = getNotificationItem(notificationId);
        if (!item) {
          return;
        }

        item.dataset.notificationRead = isRead ? "true" : "false";
        item.classList.toggle("is-unread", !isRead);
        applyFilter();
        syncFilterCounts();
      }

      function openDropdown() {
        dropdown.classList.add("is-open");
        dropdown.setAttribute("aria-hidden", "false");
        toggleButton.setAttribute("aria-expanded", "true");
        syncPanelMode();
        window.setTimeout(function () {
          dropdown.focus({ preventScroll: true });
        }, 0);
      }

      function closeDropdown(options) {
        if (!dropdown.classList.contains("is-open")) {
          syncPanelMode();
          return;
        }

        var shouldRestoreFocus = !options || options.restoreFocus !== false;
        dropdown.classList.remove("is-open");
        dropdown.setAttribute("aria-hidden", "true");
        toggleButton.setAttribute("aria-expanded", "false");
        syncPanelMode();

        if (shouldRestoreFocus) {
          toggleButton.focus({ preventScroll: true });
        }
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

        selectedNotificationIds.forEach(function (id) {
          if (!getNotificationItem(id)) {
            selectedNotificationIds.delete(id);
          }
        });
        applyFilter();
        syncFilterCounts();
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

      async function markSelectedAsRead() {
        var ids = Array.from(selectedNotificationIds);
        if (ids.length === 0) {
          return;
        }

        var data = await postAction("mark_selected_read", { ids: ids.join(",") });
        ids.forEach(function (id) {
          applyReadState(id, true);
        });
        setBadgeCount(data.unread_count || 0);
      }

      async function deleteSelectedNotifications() {
        var ids = Array.from(selectedNotificationIds);
        if (ids.length === 0 || !window.confirm("Delete " + ids.length + " selected notification(s)?")) {
          return;
        }

        var data = await postAction("delete_selected", { ids: ids.join(",") });
        ids.forEach(function (id) {
          var item = getNotificationItem(id);
          if (item) item.remove();
          selectedNotificationIds.delete(id);
        });
        setBadgeCount(data.unread_count || 0);
        applyFilter();
        syncFilterCounts();
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
              refreshNotifications();
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
          closeDropdown({ restoreFocus: false });
        }
      });

      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeDropdown();
        }
      });

      if (closeButton) {
        closeButton.addEventListener("click", function () {
          closeDropdown();
        });
      }

      backdrop.addEventListener("click", function () {
        closeDropdown();
      });

      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
          refreshNotifications();
        }
      });

      list.addEventListener("click", function (event) {
        var item = event.target.closest(".notification-item");
        if (!item || !event.target.closest("[data-notification-open]")) {
          return;
        }

        handleNotificationClick(item);
      });

      list.addEventListener("change", function (event) {
        var checkbox = event.target.closest("[data-notification-select]");
        var item = event.target.closest(".notification-item");
        if (!item) {
          return;
        }

        var notificationId = Number(item.dataset.notificationId || 0);
        if (!checkbox || notificationId <= 0) {
          return;
        }

        if (checkbox.checked) {
          selectedNotificationIds.add(notificationId);
        } else {
          selectedNotificationIds.delete(notificationId);
        }
        syncActionButtons();
      });

      selectAllCheckbox.addEventListener("change", function () {
        visibleNotificationItems().forEach(function (item) {
          var notificationId = Number(item.dataset.notificationId || 0);
          var checkbox = item.querySelector("[data-notification-select]");
          if (notificationId <= 0 || !checkbox) {
            return;
          }

          checkbox.checked = selectAllCheckbox.checked;
          if (selectAllCheckbox.checked) {
            selectedNotificationIds.add(notificationId);
          } else {
            selectedNotificationIds.delete(notificationId);
          }
        });
        syncActionButtons();
      });

      filterButtons.forEach(function (button) {
        button.addEventListener("click", function () {
          activeFilter = button.dataset.notificationFilter || "all";
          applyFilter();
        });
      });

      markSelectedButton.addEventListener("click", function () {
        markSelectedAsRead().catch(function (error) {
          console.error(error);
        });
      });

      deleteSelectedButton.addEventListener("click", function () {
        deleteSelectedNotifications().catch(function (error) {
          console.error(error);
        });
      });

      window.addNotificationToUI = addNotificationToUI;
      window.incrementBadge = incrementBadge;
      window.markSelectedNotificationsAsRead = markSelectedAsRead;
      window.deleteSelectedNotifications = deleteSelectedNotifications;

      if (new URL(window.location.href).searchParams.get("notifications") === "open") {
        openDropdown();
      }

      refreshNotifications()
        .finally(function () {
          initRealtime();
          startNotificationPolling();
        });
    })();
  </script>
<?php endif; ?>

<script src="/assets/js/header-menu.js?v=20260608-logout-confirm-global" defer></script>
