<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/account.php";
require_once __DIR__ . "/../../config/input_limits.php";
require_once __DIR__ . "/../../config/customer_profile_feedback.php";

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function sanitizeInput($value): string
{
    $value = trim((string)$value);
    return preg_replace("/[\x00-\x1F\x7F]/u", "", $value) ?? "";
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function normalizePhilippineMobileNumber(string $phone): string
{
    $phone = sanitizeInput($phone);

    if (preg_match("/^09\d{9}$/", $phone)) {
        return "+63" . substr($phone, 1);
    }

    return $phone;
}

function comparablePhilippineMobileNumber(string $phone): string
{
    $phone = sanitizeInput($phone);

    if (preg_match("/^\+639\d{9}$/", $phone)) {
        return $phone;
    }

    if (preg_match("/^09\d{9}$/", $phone)) {
        return "+63" . substr($phone, 1);
    }

    if (preg_match("/^9\d{9}$/", $phone)) {
        return "+63" . $phone;
    }

    return $phone;
}

function isValidPhilippineMobileNumber(string $phone): bool
{
    return (bool)preg_match("/^\+639\d{9}$/", $phone);
}

function philippineMobileNationalPart(string $phone): string
{
    $phone = comparablePhilippineMobileNumber($phone);

    if (preg_match("/^\+63(9\d{9})$/", $phone, $matches)) {
        return $matches[1];
    }

    if (preg_match("/^(9\d{9})$/", $phone, $matches)) {
        return $matches[1];
    }

    return "";
}

function getInitials(string $name): string
{
    $parts = preg_split("/\s+/", trim($name)) ?: [];
    $initials = "";

    foreach ($parts as $part) {
        if ($part === "") {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== "" ? $initials : "CU";
}

function quoteIdentifier(string $identifier): string
{
    if (!preg_match("/^[a-zA-Z_][a-zA-Z0-9_]*$/", $identifier)) {
        throw new InvalidArgumentException("Invalid SQL identifier.");
    }

    return '"' . $identifier . '"';
}

function getTableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table
    ");
    $stmt->execute([":table" => $table]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function resolveProfileSchema(PDO $pdo): array
{
    $userCandidate = [
        "table" => "users",
        "name" => ["fullname", "name"],
        "email" => ["email"],
        "phone" => ["phone", "contact", "contacts"],
        "password" => ["password_hash", "password"],
        "updated_at" => ["updated_at"],
    ];
    $candidates = servitech_supabase_auth_enabled() ? [$userCandidate] : [
        [
            "table" => "customers",
            "name" => ["name"],
            "email" => ["email"],
            "phone" => ["phone"],
            "password" => ["password_hash", "password"],
            "updated_at" => ["updated_at"],
        ],
        $userCandidate,
    ];

    foreach ($candidates as $candidate) {
        $columns = getTableColumns($pdo, $candidate["table"]);
        if (!$columns) {
            continue;
        }

        $resolved = [
            "table" => $candidate["table"],
            "nameColumn" => null,
            "emailColumn" => null,
            "phoneColumn" => null,
            "passwordColumn" => null,
            "updatedAtColumn" => null,
        ];

        foreach ($candidate["name"] as $column) {
            if (in_array($column, $columns, true)) {
                $resolved["nameColumn"] = $column;
                break;
            }
        }

        foreach ($candidate["email"] as $column) {
            if (in_array($column, $columns, true)) {
                $resolved["emailColumn"] = $column;
                break;
            }
        }

        foreach ($candidate["phone"] as $column) {
            if (in_array($column, $columns, true)) {
                $resolved["phoneColumn"] = $column;
                break;
            }
        }

        foreach ($candidate["password"] as $column) {
            if (in_array($column, $columns, true)) {
                $resolved["passwordColumn"] = $column;
                break;
            }
        }

        foreach ($candidate["updated_at"] as $column) {
            if (in_array($column, $columns, true)) {
                $resolved["updatedAtColumn"] = $column;
                break;
            }
        }

        if ($resolved["nameColumn"] && $resolved["emailColumn"] && $resolved["passwordColumn"]) {
            return $resolved;
        }
    }

    throw new RuntimeException("Customer profile schema could not be resolved.");
}

function fetchProfile(PDO $pdo, array $schema, int $userId): ?array
{
    $select = [
        quoteIdentifier("id"),
        quoteIdentifier($schema["nameColumn"]) . " AS profile_name",
        quoteIdentifier($schema["emailColumn"]) . " AS profile_email",
        $schema["phoneColumn"] ? quoteIdentifier($schema["phoneColumn"]) . " AS profile_phone" : "NULL AS profile_phone",
        quoteIdentifier($schema["passwordColumn"]) . " AS profile_password",
    ];

    $sql = "SELECT " . implode(", ", $select) .
        " FROM " . quoteIdentifier($schema["table"]) .
        " WHERE " . quoteIdentifier("id") . " = :id LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":id" => $userId]);

    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    return $profile ?: null;
}

function emailExists(PDO $pdo, array $schema, string $email, int $excludeId): bool
{
    $sql = "SELECT " . quoteIdentifier("id") .
        " FROM " . quoteIdentifier($schema["table"]) .
        " WHERE LOWER(" . quoteIdentifier($schema["emailColumn"]) . ") = LOWER(:email)" .
        " AND " . quoteIdentifier("id") . " <> :id LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":email" => $email,
        ":id" => $excludeId,
    ]);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function wantsPasswordChange(array $formData): bool
{
    return $formData["new_password"] !== ""
        || $formData["confirm_password"] !== "";
}

function verifyStoredPassword(string $storedPassword, string $submittedPassword): bool
{
    if ($storedPassword === "" || $submittedPassword === "") {
        return false;
    }

    $hashInfo = password_get_info($storedPassword);
    $isHash = (int)($hashInfo["algo"] ?? 0) !== 0;

    return $isHash && password_verify($submittedPassword, $storedPassword);
}

function profileErrorDefaults(): array
{
    return [
        "name" => "",
        "email" => "",
        "phone" => "",
        "current_password" => "",
        "new_password" => "",
        "confirm_password" => "",
        "general" => "",
    ];
}

function validateProfileInput(array $formData, array $changedFields): array
{
    $errors = profileErrorDefaults();

    if (!empty($changedFields["name"])) {
        $errors["name"] = servitech_person_name_validation_error($formData["name"], "Full name");
    }

    if (!empty($changedFields["email"])) {
        $errors["email"] = servitech_email_validation_error($formData["email"]);
    }

    if (!empty($changedFields["phone"]) && $formData["phone"] === "") {
        $errors["phone"] = "Phone number is required.";
    } elseif (!empty($changedFields["phone"]) && !isValidPhilippineMobileNumber($formData["phone"])) {
        $errors["phone"] = "Enter a valid 10-digit Philippine mobile number after +63, starting with 9.";
    }

    if ($formData["current_password"] === "") {
        $errors["current_password"] = "Enter your current password.";
    }

    if (wantsPasswordChange($formData)) {
        if ($formData["new_password"] === "") {
            $errors["new_password"] = "Enter a new password.";
        } elseif (($passwordError = servitech_password_validation_error($formData["new_password"])) !== "") {
            $errors["new_password"] = $passwordError;
        }
        if ($formData["confirm_password"] === "") {
            $errors["confirm_password"] = "Confirm your new password.";
        } elseif ($formData["new_password"] !== $formData["confirm_password"]) {
            $errors["confirm_password"] = "New password and confirmation do not match.";
        }
    }

    return $errors;
}

$userId = (int)($_SESSION["user_id"] ?? 0);
$csrfToken = servitech_csrf_token();
$flash = $_SESSION["profile_flash"] ?? null;
unset($_SESSION["profile_flash"]);

$errors = profileErrorDefaults();

$formData = [
    "name" => "",
    "email" => "",
    "phone" => "",
    "current_password" => "",
    "new_password" => "",
    "confirm_password" => "",
];

$schema = null;
$profile = null;

try {
    $schema = resolveProfileSchema($pdo);
    $profile = fetchProfile($pdo, $schema, $userId);

    if (!$profile) {
        $errors["general"] = "We could not load your profile right now.";
    } else {
        $formData["name"] = sanitizeInput($profile["profile_name"] ?? "");
        $formData["email"] = normalizeEmail((string)($profile["profile_email"] ?? ""));
        $formData["phone"] = comparablePhilippineMobileNumber((string)($profile["profile_phone"] ?? ""));
    }
} catch (Throwable $exception) {
    error_log("profile page load error: " . $exception->getMessage());
    $errors["general"] = "Profile settings are temporarily unavailable.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $currentName = $profile ? sanitizeInput($profile["profile_name"] ?? "") : "";
    $currentEmail = $profile ? normalizeEmail((string)($profile["profile_email"] ?? "")) : "";
    $currentPhone = $profile ? comparablePhilippineMobileNumber((string)($profile["profile_phone"] ?? "")) : "";

    $nameWasSubmitted = array_key_exists("name", $_POST) || array_key_exists("fullname", $_POST);
    $emailWasSubmitted = array_key_exists("email", $_POST);
    $phoneWasSubmitted = array_key_exists("phone", $_POST) || array_key_exists("contact", $_POST) || array_key_exists("contacts", $_POST);

    $formData["name"] = $nameWasSubmitted ? sanitizeInput($_POST["name"] ?? $_POST["fullname"] ?? "") : $currentName;
    $formData["email"] = $emailWasSubmitted ? normalizeEmail(sanitizeInput($_POST["email"] ?? "")) : $currentEmail;
    $formData["phone"] = $phoneWasSubmitted
        ? normalizePhilippineMobileNumber((string)($_POST["phone"] ?? $_POST["contact"] ?? $_POST["contacts"] ?? ""))
        : $currentPhone;
    $formData["current_password"] = (string)($_POST["current_password"] ?? "");
    $formData["new_password"] = (string)($_POST["new_password"] ?? "");
    $formData["confirm_password"] = (string)($_POST["confirm_password"] ?? "");
    $changedFields = [
        "name" => $nameWasSubmitted && $formData["name"] !== $currentName,
        "email" => $emailWasSubmitted && $formData["email"] !== $currentEmail,
        "phone" => $phoneWasSubmitted
            && comparablePhilippineMobileNumber($formData["phone"]) !== $currentPhone
            && !($formData["phone"] === "" && philippineMobileNationalPart($currentPhone) === ""),
    ];

    $errors = validateProfileInput($formData, $changedFields);

    if (!$schema || !$profile) {
        $errors["general"] = "We could not load your profile for updating.";
    }

    if (!empty($changedFields["email"]) && $errors["email"] === "" && $schema) {
        $emailLookupPdo = servitech_supabase_auth_enabled()
            ? servitech_db_connect_privileged()
            : $pdo;
        if (emailExists($emailLookupPdo, $schema, $formData["email"], $userId)) {
            $errors["email"] = "That email address is already in use.";
        }
    }

    $emailChangePending = false;
    $passwordUpdated = false;
    if ($errors["current_password"] === "" && $schema && $profile) {
        if (servitech_supabase_auth_enabled()) {
            try {
                $reauth = servitech_supabase_sign_in($currentEmail, $formData["current_password"]);
                servitech_supabase_store_auth_session($reauth);
            } catch (Throwable $exception) {
                $errors["current_password"] = "Current password is incorrect.";
            }
        } elseif (!verifyStoredPassword((string)($profile["profile_password"] ?? ""), $formData["current_password"])) {
            $errors["current_password"] = "Current password is incorrect.";
        }
    }

    $hasErrors = (bool)array_filter($errors);

    if (!$hasErrors && $schema && $profile) {
        if (servitech_supabase_auth_enabled()) {
            try {
                $authChanges = [];
                if (!empty($changedFields["email"])) {
                    $authChanges["email"] = $formData["email"];
                }
                if (wantsPasswordChange($formData)) {
                    $authChanges["password"] = $formData["new_password"];
                }
                if ($authChanges) {
                    $updatedAuth = servitech_supabase_update_user(
                        (string)($_SESSION["supabase_access_token"] ?? ""),
                        $authChanges
                    );
                    $passwordUpdated = array_key_exists("password", $authChanges);
                    $returnedEmail = normalizeEmail((string)($updatedAuth["email"] ?? $currentEmail));
                    if (isset($authChanges["email"]) && $returnedEmail !== $formData["email"]) {
                        $emailChangePending = true;
                    }
                }
            } catch (Throwable $exception) {
                error_log("Supabase profile auth update error: " . $exception->getMessage());
                $errors["general"] = $exception instanceof DomainException
                    ? $exception->getMessage()
                    : "We could not update your authentication details right now.";
            }
        }

        if ($errors["general"] === "") {
        $changes = [];
        $changedLabels = [];

        if (!empty($changedFields["name"])) {
            $changes[$schema["nameColumn"]] = $formData["name"];
            $changedLabels[] = "Full name";
        }

        if (!empty($changedFields["email"])) {
            if (servitech_supabase_auth_enabled() && $emailChangePending) {
                $changedLabels[] = "Email verification request";
            } else {
                $changes[$schema["emailColumn"]] = $formData["email"];
                $changedLabels[] = "Email address";
            }
            if ($schema["table"] === "users" && !servitech_supabase_auth_enabled()) {
                $changes["email_verified_at"] = null;
                $changes["email_verification_token"] = null;
                $changes["email_verification_expires"] = null;
                $changes["email_verification_sent_at"] = null;
            }
        }

        $newPhoneValue = $formData["phone"];
        if (!empty($changedFields["phone"]) && $schema["phoneColumn"]) {
            $changes[$schema["phoneColumn"]] = $newPhoneValue;
            $changedLabels[] = "Phone number";
        }

        if (wantsPasswordChange($formData) && !servitech_supabase_auth_enabled()) {
            $changes[$schema["passwordColumn"]] = password_hash($formData["new_password"], PASSWORD_DEFAULT);
            $changedLabels[] = "Password";
        } elseif (wantsPasswordChange($formData)) {
            $changedLabels[] = "Password";
        }

        $profileUpdated = false;
        if ($changes) {
            $assignments = [];
            $params = [":id" => $userId];
            $counter = 0;

            foreach ($changes as $column => $value) {
                $param = ":value_" . $counter++;
                $assignments[] = quoteIdentifier($column) . " = " . $param;
                $params[$param] = $value;
            }

            if (!empty($schema["updatedAtColumn"])) {
                $assignments[] = quoteIdentifier($schema["updatedAtColumn"]) . " = NOW()";
            }

            $sql = "UPDATE " . quoteIdentifier($schema["table"]) .
                " SET " . implode(", ", $assignments) .
                " WHERE " . quoteIdentifier("id") . " = :id";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $profileUpdated = true;
                if (wantsPasswordChange($formData) && !servitech_supabase_auth_enabled()) {
                    $passwordUpdated = true;
                }
            } catch (PDOException $exception) {
                error_log("profile update error: " . $exception->getMessage());
                $errors["general"] = "We could not save your changes right now.";
            }
        }

        if ($errors["general"] === "") {
            $_SESSION["profile_flash"] = servitech_customer_profile_update_feedback(
                $changedLabels,
                $profileUpdated,
                $passwordUpdated,
                $emailChangePending
            );
            header("Location: /pages/customer/custo_edit_profile.php");
            exit();
        }
        }
    }

    $formData["current_password"] = "";
    $formData["new_password"] = "";
    $formData["confirm_password"] = "";
}

$avatarInitials = getInitials($formData["name"]);
$flashType = is_array($flash) ? (string)($flash["type"] ?? "") : "";
$flashMessage = is_array($flash) ? (string)($flash["message"] ?? "") : "";
$openConfirmModal = $_SERVER["REQUEST_METHOD"] === "POST" && $errors["current_password"] !== "";
$openPasswordModal = $_SERVER["REQUEST_METHOD"] === "POST"
    && !$openConfirmModal
    && ($errors["new_password"] !== "" || $errors["confirm_password"] !== "");
$toastType = $flashType;
$toastMessage = $flashMessage;
$phoneNationalValue = philippineMobileNationalPart($formData["phone"]);
$storedNameValue = $profile ? sanitizeInput($profile["profile_name"] ?? "") : $formData["name"];
$storedEmailValue = $profile ? normalizeEmail((string)($profile["profile_email"] ?? "")) : $formData["email"];
$storedPhoneValue = $profile ? comparablePhilippineMobileNumber((string)($profile["profile_phone"] ?? "")) : comparablePhilippineMobileNumber($formData["phone"]);
$storedPhoneNationalValue = philippineMobileNationalPart($storedPhoneValue);

if ($_SERVER["REQUEST_METHOD"] === "POST" && $toastMessage === "" && (bool)array_filter($errors)) {
    if ($errors["current_password"] !== "") {
        $toastType = "error";
        $toastMessage = $errors["current_password"];
    } elseif ($errors["general"] !== "") {
        $toastType = "error";
        $toastMessage = $errors["general"];
    } else {
        $toastType = "warning";
        $toastMessage = "Please fix the highlighted fields before confirming.";
    }
}

$phoneStatusLabel = $formData["phone"] !== "" ? "Ready for queue and service updates." : "Add a phone number for faster service updates.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Edit Profile</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260621-global-ui-polish">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h17">
  <style>
    .profile-edit-page {
      padding: 2rem 1rem 3.5rem;
      background:
        radial-gradient(circle at top center, rgba(255, 186, 73, 0.30), transparent 34%),
        radial-gradient(circle at bottom left, rgba(255, 126, 46, 0.18), transparent 30%),
        linear-gradient(180deg, #fff7eb 0%, #fff0db 54%, #ffe5c2 100%);
    }

    .profile-shell {
      max-width: 1080px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 320px minmax(0, 1fr);
      gap: 1.5rem;
      align-items: start;
    }

    .profile-summary,
    .profile-panel {
      background: rgba(255, 255, 255, 0.98);
      border: 1px solid rgba(74, 5, 5, 0.10);
      border-radius: 24px;
      box-shadow: 0 18px 42px rgba(74, 5, 5, 0.12);
    }

    .profile-summary {
      padding: 1.85rem;
      position: relative;
      top: auto;
      overflow: hidden;
      background:
        linear-gradient(180deg, rgba(74, 5, 5, 0.98) 0%, rgba(105, 18, 8, 0.96) 44%, rgba(158, 65, 12, 0.94) 100%);
      color: #fff6ee;
    }

    .profile-summary::after {
      content: "";
      position: absolute;
      inset: auto -44px -52px auto;
      width: 168px;
      height: 168px;
      border-radius: 999px;
      background: linear-gradient(135deg, rgba(255, 213, 128, 0.55), rgba(255, 123, 0, 0.18));
      filter: blur(10px);
    }

    .profile-summary::before {
      content: "";
      position: absolute;
      inset: 0;
      background:
        linear-gradient(155deg, rgba(255, 255, 255, 0.12), transparent 42%),
        linear-gradient(180deg, transparent 0%, rgba(0, 0, 0, 0.08) 100%);
      pointer-events: none;
    }

    .profile-avatar {
      width: 84px;
      height: 84px;
      border-radius: 26px;
      display: grid;
      place-items: center;
      font-size: 1.7rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      color: #5c1d00;
      background: linear-gradient(135deg, #fff7de, #ffcf73);
      margin-bottom: 1.15rem;
      position: relative;
      z-index: 1;
      box-shadow: 0 14px 28px rgba(0, 0, 0, 0.18);
      border: 1px solid rgba(255, 255, 255, 0.35);
    }

    .profile-summary h1 {
      margin: 0;
      font-size: 1.65rem;
      color: #ffffff;
      position: relative;
      z-index: 1;
    }

    .profile-summary p {
      margin: 0.5rem 0 0;
      color: rgba(255, 245, 236, 0.86);
      line-height: 1.6;
      position: relative;
      z-index: 1;
    }

    .profile-meta {
      margin-top: 1.5rem;
      display: grid;
      gap: 0.9rem;
      position: relative;
      z-index: 1;
    }

    .profile-meta-item {
      padding: 0.95rem 1rem;
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 234, 209, 0.18);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
    }

    .profile-meta-item span {
      display: block;
      margin-bottom: 0.3rem;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(255, 225, 194, 0.82);
    }

    .profile-meta-item strong {
      color: #ffffff;
      font-size: 1rem;
      font-weight: 700;
    }

    .profile-panel {
      padding: 0;
      overflow: hidden;
    }

    .profile-panel::before {
      content: "";
      display: block;
      height: 10px;
      background: linear-gradient(90deg, #4A0505 0%, #ff8b2c 52%, #FAB12F 100%);
    }

    .panel-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.5rem;
      padding: 1.7rem 1.8rem 0;
    }

    .panel-topbar h2 {
      margin: 0;
      font-size: 1.6rem;
      color: #4A0505;
    }

    .panel-topbar p {
      margin: 0.35rem 0 0;
      color: #7a5845;
    }

    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.7rem 1rem;
      border-radius: 999px;
      text-decoration: none;
      color: #4A0505;
      font-weight: 700;
      white-space: nowrap;
      background: #fff4e7;
      border: 1px solid rgba(74, 5, 5, 0.12);
      box-shadow: 0 10px 20px rgba(74, 5, 5, 0.08);
      transition: transform 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
    }

    .back-link:hover {
      transform: translateY(-1px);
      background: #ffe9d0;
      box-shadow: 0 14px 24px rgba(74, 5, 5, 0.12);
    }

    body.modal-open {
      overflow: hidden;
    }

    .modal-overlay,
    .alert-modal,
    .status-banner {
      border-radius: 16px;
      border: 1px solid transparent;
      line-height: 1.55;
    }

    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.4);
      z-index: 999;
    }

    .alert-modal {
      display: none;
      position: fixed;
      top: 50%;
      left: 50%;
      width: min(calc(100% - 2rem), 420px);
      padding: 1.35rem 1.25rem 1.2rem;
      margin: 0;
      z-index: 1000;
      transform: translate(-50%, -50%);
      text-align: center;
      background: #fffdf9;
      box-shadow: 0 22px 50px rgba(34, 18, 8, 0.28);
    }

    .modal-overlay.active,
    .alert-modal.active {
      display: block;
    }

    .confirm-modal {
      display: none;
      position: fixed;
      top: 50%;
      left: 50%;
      width: min(calc(100% - 2rem), 460px);
      padding: 1.45rem;
      z-index: 1000;
      transform: translate(-50%, -50%);
      background: #fffdf9;
      border: 1px solid rgba(74, 5, 5, 0.12);
      border-radius: 20px;
      box-shadow: 0 24px 60px rgba(34, 18, 8, 0.32);
    }

    .confirm-modal.active {
      display: grid;
      gap: 1.15rem;
    }

    .confirm-modal__close {
      position: absolute;
      top: 0.9rem;
      right: 0.9rem;
      width: 44px;
      height: 44px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid rgba(122, 47, 0, 0.12);
      border-radius: 14px;
      background: #fff7ec;
      color: #7a2f00;
      cursor: pointer;
      font-size: 1.25rem;
      font-weight: 800;
      line-height: 1;
      padding: 0;
      transition: background-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }

    .confirm-modal__close::before,
    .confirm-modal__close::after {
      content: "";
      position: absolute;
      width: 16px;
      height: 2px;
      border-radius: 999px;
      background: currentColor;
      top: 50%;
      left: 50%;
      transform-origin: center;
    }

    .confirm-modal__close::before {
      transform: translate(-50%, -50%) rotate(45deg);
    }

    .confirm-modal__close::after {
      transform: translate(-50%, -50%) rotate(-45deg);
    }

    .confirm-modal__close:hover,
    .confirm-modal__close:focus-visible {
      background: #ffe9d0;
      box-shadow: 0 0 0 4px rgba(255, 139, 44, 0.14);
      outline: none;
      transform: translateY(-1px);
    }

    .confirm-modal__close span,
    .confirm-modal__close svg,
    .confirm-modal__close i {
      display: none;
      line-height: 1;
      margin: 0;
    }

    .confirm-modal__header {
      display: grid;
      gap: 0.45rem;
      padding-right: 2.8rem;
    }

    .confirm-modal__header h2 {
      margin: 0;
      color: #4A0505;
      font-size: 1.35rem;
      line-height: 1.2;
    }

    .confirm-modal__header p {
      margin: 0;
      color: #7d6658;
      line-height: 1.6;
    }

    .confirm-modal__field {
      padding: 1rem;
      border: 1px solid rgba(74, 5, 5, 0.08);
      border-radius: 16px;
      background: #fff8ef;
    }

    .confirm-modal__actions {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 0.8rem;
    }

    .confirm-modal__actions .btn-secondary,
    .confirm-modal__actions .btn-primary {
      width: 100%;
      min-height: 48px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
    }

    .alert-modal h2 {
      margin: 0 0 0.45rem;
      font-size: 1.35rem;
      line-height: 1.2;
    }

    .alert-modal p {
      margin: 0;
      font-size: 0.98rem;
    }

    .alert-modal__actions {
      margin-top: 1rem;
      display: flex;
      justify-content: center;
    }

    .alert-modal__actions .btn-primary,
    .alert-modal__actions .btn-secondary {
      min-width: 120px;
    }

    .status-success {
      background: #eefbf1;
      border-color: #b8e0b8;
      color: #245d24;
    }

    .status-info {
      background: #fff4df;
      border-color: #f3cb85;
      color: #8a4f00;
    }

    .status-error {
      background: #fff0ef;
      border-color: #efb8b4;
      color: #9d2b23;
    }

    .profile-form {
      display: grid;
      gap: 1.5rem;
      padding: 0 1.8rem 1.8rem;
    }

    .form-section {
      display: grid;
      gap: 1rem;
      padding: 1.35rem;
      border-radius: 20px;
      background: linear-gradient(180deg, #fffdfa 0%, #fff5e8 100%);
      border: 1px solid rgba(74, 5, 5, 0.08);
      box-shadow: 0 10px 20px rgba(74, 5, 5, 0.05);
    }

    .form-section h3 {
      margin: 0;
      font-size: 1.05rem;
      color: #4A0505;
    }

    .form-section p {
      margin: 0;
      color: #7d6658;
    }

    .field-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem;
    }

    .field-grid .field.full-width {
      grid-column: 1 / -1;
    }

    .field {
      display: grid;
      gap: 0.45rem;
    }

    .field-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }

    .field label {
      font-weight: 600;
      color: #4A0505;
    }

    .field label .required {
      color: #c81e1e;
    }

    .field input {
      width: 100%;
      padding: 0.9rem 1rem;
      border-radius: 14px;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #4A0505;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }

    .field input:focus {
      outline: none;
      border-color: #ff8b2c;
      box-shadow: 0 0 0 4px rgba(255, 139, 44, 0.16);
      background: #ffffff;
    }

    .field input.is-invalid {
      border-color: #dc2626;
      background: #fff7f7;
      box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.08);
    }

    .field input[readonly] {
      cursor: default;
      background: #fff8ef;
      color: #694636;
      border-color: #e5d4bf;
      box-shadow: none;
    }

    .field input[readonly]:focus {
      border-color: #e5d4bf;
      box-shadow: none;
      background: #fff8ef;
    }

    .field-edit-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 42px;
      min-height: 38px;
      padding: 0.45rem 0.7rem;
      border-radius: 10px;
      border: 1px solid rgba(74, 5, 5, 0.22);
      background: #4A0505;
      color: #ffffff;
      font-size: 0.8rem;
      font-weight: 800;
      line-height: 1;
      cursor: pointer;
      box-shadow: 0 8px 18px rgba(74, 5, 5, 0.18);
      transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, color 0.2s ease, transform 0.2s ease;
    }

    .field-edit-btn:hover,
    .field-edit-btn:focus-visible {
      border-color: #6b1414;
      background: #6b1414;
      box-shadow: 0 0 0 4px rgba(74, 5, 5, 0.14), 0 10px 20px rgba(74, 5, 5, 0.18);
      outline: none;
    }

    .field-edit-btn.is-active {
      border-color: rgba(74, 5, 5, 0.18);
      background: #ffffff;
      color: #4A0505;
      box-shadow: 0 6px 14px rgba(74, 5, 5, 0.08);
    }

    .field-edit-btn.is-active:hover,
    .field-edit-btn.is-active:focus-visible {
      border-color: rgba(255, 139, 44, 0.72);
      background: #fff8ef;
      box-shadow: 0 0 0 4px rgba(255, 139, 44, 0.14), 0 8px 18px rgba(74, 5, 5, 0.1);
    }

    .contact-number-control {
      display: flex;
      align-items: stretch;
      width: 100%;
      min-height: 52px;
      overflow: hidden;
      border: 1px solid #cbd5e1;
      border-radius: 14px;
      background: #ffffff;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }

    .contact-number-control:focus-within {
      border-color: #ff8b2c;
      box-shadow: 0 0 0 4px rgba(255, 139, 44, 0.16);
      background: #ffffff;
    }

    .contact-number-control.is-invalid {
      border-color: #dc2626;
      background: #fff7f7;
      box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.08);
    }

    .contact-number-control.is-readonly {
      background: #fff8ef;
      border-color: #e5d4bf;
      box-shadow: none;
    }

    .contact-number-control.is-readonly .contact-number-prefix {
      background: #ffecd4;
    }

    .contact-number-prefix {
      display: inline-flex;
      align-items: center;
      padding: 0 1rem;
      border-right: 1px solid #dbc6ae;
      background: #fff3e3;
      color: #4A0505;
      font-size: 0.95rem;
      font-weight: 800;
    }

    .field .contact-number-control input[type="tel"],
    body.customer-page--profile .field .contact-number-control input[type="tel"] {
      min-height: 50px;
      padding: 0.85rem 0.9rem;
      border: 0;
      border-radius: 0;
      background: transparent;
      box-shadow: none;
    }

    .field .contact-number-control input[type="tel"]:focus,
    body.customer-page--profile .field .contact-number-control input[type="tel"]:focus {
      background: transparent;
      box-shadow: none;
    }

    .password-mini-card {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: center;
      gap: 1rem;
      padding: 1rem;
      border-radius: 16px;
      border: 1px solid rgba(74, 5, 5, 0.08);
      background: #fff8ef;
    }

    .password-mini-card p {
      margin: 0;
      line-height: 1.55;
    }

    .password-mini-card .btn-secondary {
      min-height: 46px;
      white-space: nowrap;
      background: #4A0505;
      border-color: #4A0505;
      color: #ffffff;
      box-shadow: 0 12px 22px rgba(74, 5, 5, 0.18);
    }

    .password-mini-card .btn-secondary:hover,
    .password-mini-card .btn-secondary:focus-visible {
      background: #6b1414;
      border-color: #6b1414;
      box-shadow: 0 0 0 4px rgba(74, 5, 5, 0.14), 0 14px 24px rgba(74, 5, 5, 0.2);
      outline: none;
    }

    .password-modal__field-grid {
      display: grid;
      gap: 1rem;
    }

    .password-modal__actions {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 0.8rem;
    }

    .password-modal__actions .btn-secondary,
    .password-modal__actions .btn-primary {
      width: 100%;
      min-height: 48px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
    }

    .field-note {
      color: #7b675d;
      font-size: 0.92rem;
      line-height: 1.5;
    }

    .field-error {
      min-height: 1.1rem;
      font-size: 0.88rem;
      color: #c81e1e;
    }

    .form-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.85rem;
      flex-wrap: wrap;
    }

    .btn-secondary,
    .btn-primary {
      border: 0;
      border-radius: 999px;
      padding: 0.9rem 1.3rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
    }

    .btn-secondary {
      background: #fff3e6;
      color: #7a2f00;
      border: 1px solid rgba(122, 47, 0, 0.16);
      box-shadow: 0 10px 18px rgba(74, 5, 5, 0.08);
    }

    .btn-primary {
      background: linear-gradient(135deg, #ffb347, #ff8a2d 58%, #d45a0a 100%);
      color: #fff;
      box-shadow: 0 14px 24px rgba(122, 47, 0, 0.22);
    }

    .btn-primary:hover,
    .btn-secondary:hover {
      transform: translateY(-1px);
    }

    .btn-primary[disabled] {
      cursor: wait;
      opacity: 0.75;
      transform: none;
    }

    @media (max-width: 900px) {
      .profile-shell {
        grid-template-columns: 1fr;
      }

      .profile-summary {
        position: static;
      }
    }

    @media (max-width: 640px) {
      .profile-edit-page {
        padding: 1.25rem 0.85rem 2.5rem;
      }

      .profile-panel,
      .profile-summary {
        padding: 1.2rem;
        border-radius: 20px;
      }

      .panel-topbar {
        flex-direction: column;
        align-items: flex-start;
        padding: 1.2rem 1.2rem 0;
      }

      .field-grid {
        grid-template-columns: 1fr;
      }

      .form-actions {
        justify-content: stretch;
      }

      .btn-secondary,
      .btn-primary {
        width: 100%;
        text-align: center;
      }

      .confirm-modal {
        padding: 1.2rem;
        border-radius: 18px;
      }

      .confirm-modal__actions {
        grid-template-columns: 1fr;
      }

      .field-heading {
        align-items: flex-start;
      }

      .password-mini-card,
      .password-modal__actions {
        grid-template-columns: 1fr;
      }
    }
    .summary-kicker,
    .panel-kicker,
    .section-kicker {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.74rem;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }

    .summary-kicker {
      margin-bottom: 0.9rem;
      padding: 0.4rem 0.72rem;
      border-radius: 999px;
      color: #fff5de;
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 235, 205, 0.22);
      position: relative;
      z-index: 1;
    }










    .panel-kicker {
      margin-bottom: 0.45rem;
      color: #b85a11;
    }

    .status-banner {
      padding: 0.95rem 1rem;
      margin-bottom: 1rem;
      margin: 0 1.8rem 1rem;
    }

    .section-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
    }

    .section-kicker {
      margin-bottom: 0.4rem;
      color: #b85a11;
    }

    .section-tag {
      display: inline-flex;
      align-items: center;
      padding: 0.5rem 0.85rem;
      border-radius: 999px;
      background: #fff7ec;
      border: 1px solid rgba(184, 90, 17, 0.16);
      color: #914108;
      font-size: 0.82rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .form-actions {
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid rgba(74, 5, 5, 0.08);
      padding-top: 0.35rem;
    }

    .action-copy {
      margin: 0;
      max-width: 420px;
      color: #7d6658;
      line-height: 1.6;
      font-size: 0.95rem;
    }

    .action-buttons {
      display: flex;
      align-items: center;
      gap: 0.85rem;
      flex-wrap: wrap;
    }

    @media (max-width: 640px) {
      .section-head,
      .form-actions {
        flex-direction: column;
        align-items: flex-start;
      }

      .action-buttons {
        width: 100%;
      }
    }
    .summary-support {
      margin-top: 1rem;
      color: rgba(255, 244, 232, 0.88);
      font-size: 0.96rem;
    }

    .profile-form,
    .field,
    .password-field,
    .action-buttons,
    .profile-panel,
    .profile-summary {
      box-sizing: border-box;
    }

    .status-banner {
      margin: 0 1.8rem 1rem;
    }

    .field-grid {
      align-items: start;
    }

    .field input {
      min-height: 52px;
    }

    .password-field {
      position: relative;
      display: block;
    }

    .password-field input {
      width: 100%;
      padding-right: 44px;
    }

    .password-toggle {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      width: 34px;
      height: 34px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      background: transparent;
      color: #a14b10;
      font-weight: 700;
      cursor: pointer;
      padding: 0;
      margin: 0;
      line-height: 1;
      z-index: 4;
      pointer-events: auto;
      opacity: 1;
      visibility: visible;
      transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
    }

    .password-field input::-ms-reveal,
    .password-field input::-ms-clear {
      display: none;
    }

    .password-field input::-webkit-credentials-auto-fill-button,
    .password-field input::-webkit-caps-lock-indicator {
      visibility: hidden;
      display: none !important;
      pointer-events: none;
    }

    .password-toggle svg,
    .password-toggle i {
      display: block;
      width: 18px;
      height: 18px;
      line-height: 1;
      margin: 0;
      pointer-events: none;
    }

    .password-toggle::before {
      display: none;
    }

    .password-toggle:hover,
    .password-toggle:focus-visible {
      background: rgba(120, 53, 15, 0.08);
      border-radius: 999px;
      color: #6d2d05;
      outline: none;
    }

    .form-actions {
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid rgba(74, 5, 5, 0.08);
      padding-top: 0.35rem;
    }

    .action-copy {
      margin: 0;
      max-width: 420px;
      color: #7d6658;
      line-height: 1.6;
      font-size: 0.95rem;
    }

    .action-buttons {
      display: flex;
      gap: 0.85rem;
      flex-wrap: wrap;
    }

    @media (max-width: 900px) {
      .profile-shell {
        grid-template-columns: 1fr;
      }

      .profile-summary {
        position: static;
      }
    }

    @media (max-width: 640px) {
      .profile-form {
        padding: 0 1.2rem 1.2rem;
      }

      .status-banner {
        margin: 0 1.2rem 1rem;
      }

      .panel-topbar {
        padding: 1.2rem 1.2rem 0;
      }

      .section-head,
      .form-actions {
        flex-direction: column;
        align-items: flex-start;
      }

      .action-buttons {
        width: 100%;
      }

      .action-buttons .btn-secondary,
      .action-buttons .btn-primary {
        width: 100%;
        text-align: center;
      }
    }
    .form-section {
      gap: 0.85rem;
    }

    .field-grid {
      column-gap: 1rem;
      row-gap: 0.85rem;
    }

    .field {
      align-content: start;
    }

    .field-note {
      margin-top: -0.1rem;
    }

    .field-error {
      min-height: 0;
      margin-top: -0.05rem;
    }

    .field-error:empty {
      display: none;
    }

    .password-toggle {
      z-index: 1;
    }

    @media (max-width: 1100px) {
      .profile-shell {
        grid-template-columns: 280px minmax(0, 1fr);
      }
    }

    @media (max-width: 860px) {
      .profile-shell {
        grid-template-columns: 1fr;
      }

      .profile-summary {
        position: static;
      }

      .field-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .profile-edit-page {
        padding: 1rem 0.75rem 2rem;
      }

      .profile-panel,
      .profile-summary {
        border-radius: 18px;
      }

      .form-section {
        padding: 1.1rem;
      }

      .password-toggle {
        right: 8px;
      }
    }
    .profile-summary {
      isolation: isolate;
    }

    .profile-summary::before,
    .profile-summary::after {
      z-index: 0;
      pointer-events: none;
    }

    .profile-summary::after {
      inset: auto -22px -22px auto;
      width: 128px;
      height: 128px;
      opacity: 0.34;
      filter: blur(16px);
    }

    .profile-summary > * {
      position: relative;
      z-index: 1;
    }

    .profile-meta,
    .profile-meta-item {
      position: relative;
      z-index: 2;
    }

    body.customer-page--profile {
      width: 100%;
      max-width: 100vw;
      position: relative;
      overflow-x: hidden !important;
    }

    body.customer-page--profile header,
    body.customer-page--profile main,
    body.customer-page--profile footer {
      width: 100%;
      max-width: 100vw;
    }

    body.customer-page--profile main,
    body.customer-page--profile footer {
      overflow-x: hidden;
    }

    body.customer-page--profile .panel-topbar {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: start;
    }

    body.customer-page--profile .back-link {
      justify-self: end;
      max-width: 100%;
      min-height: 46px;
      padding-inline: 1rem;
      overflow-wrap: anywhere;
    }

    body.customer-page--profile .profile-form .form-section:nth-of-type(2) .field-grid {
      grid-template-columns: minmax(0, 1fr);
    }

    body.customer-page--profile .profile-form .form-section:nth-of-type(2) .field {
      width: 100%;
    }

    body.customer-page--profile .password-field input::placeholder {
      font-size: 0.95rem;
    }

    body.customer-page--profile .action-buttons {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 0.7rem;
      width: 100%;
    }

    body.customer-page--profile .form-actions {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      align-items: start;
      width: 100%;
    }

    body.customer-page--profile .action-buttons .btn-secondary,
    body.customer-page--profile .action-buttons .btn-primary {
      width: 100%;
      min-height: 48px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      font-size: 0.94rem;
    }

    body.customer-page--profile .btn-primary {
      background: #4A0505;
      color: #ffffff;
      box-shadow: 0 12px 22px rgba(74, 5, 5, 0.18);
    }

    body.customer-page--profile .btn-primary:hover,
    body.customer-page--profile .btn-primary:focus-visible {
      background: #6b1414;
      box-shadow: 0 0 0 4px rgba(74, 5, 5, 0.14), 0 14px 24px rgba(74, 5, 5, 0.2);
    }

    @media (max-width: 1180px) {
      body.customer-page--profile .panel-topbar {
        grid-template-columns: minmax(0, 1fr);
      }

      body.customer-page--profile .back-link {
        justify-self: start;
      }
    }

    html,
    body.customer-page--profile {
      max-width: 100%;
      overflow-x: hidden;
      -webkit-text-size-adjust: 100%;
      text-size-adjust: 100%;
    }

    body.customer-page--profile *,
    body.customer-page--profile *::before,
    body.customer-page--profile *::after {
      box-sizing: border-box;
      min-width: 0;
    }

    body.customer-page--profile .navbar.has-nav-menu.navbar--notifications,
    body.customer-page--profile .profile-edit-page,
    body.customer-page--profile .profile-shell,
    body.customer-page--profile .profile-summary,
    body.customer-page--profile .profile-panel,
    body.customer-page--profile .profile-form,
    body.customer-page--profile .form-section,
    body.customer-page--profile .field-grid,
    body.customer-page--profile .field,
    body.customer-page--profile .password-field,
    body.customer-page--profile .form-actions,
    body.customer-page--profile .action-buttons {
      max-width: 100%;
    }

    body.customer-page--profile .profile-edit-page {
      width: 100%;
      overflow-x: hidden;
      overflow-x: clip;
    }

    body.customer-page--profile .profile-shell {
      width: min(100%, 1080px);
      align-items: start;
    }

    body.customer-page--profile .profile-summary,
    body.customer-page--profile .profile-panel {
      width: 100%;
      align-self: start;
      margin-top: 0;
    }

    body.customer-page--profile .profile-summary h1,
    body.customer-page--profile .profile-summary p,
    body.customer-page--profile .profile-meta-item strong,
    body.customer-page--profile .panel-topbar p,
    body.customer-page--profile .action-copy {
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    body.customer-page--profile .field input {
      width: 100%;
      min-width: 0;
      font-size: 1rem;
    }

    body.customer-page--profile .password-field input {
      padding-right: 44px !important;
    }

    body.customer-page--profile .password-toggle {
      position: absolute !important;
      right: 8px !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
      width: 34px;
      height: 34px;
      max-width: none;
      min-height: 0;
      line-height: 1;
      white-space: normal;
      z-index: 4 !important;
      opacity: 1 !important;
      visibility: visible !important;
      color: #111111 !important;
      cursor: pointer !important;
      pointer-events: auto !important;
    }

    body.customer-page--profile .password-toggle svg,
    body.customer-page--profile .password-toggle i {
      display: block !important;
    }

    @media (max-width: 1100px) {
      body.customer-page--profile .profile-edit-page {
        padding-inline: clamp(1rem, 3vw, 1.5rem);
      }

      body.customer-page--profile .profile-shell {
        grid-template-columns: minmax(250px, 0.42fr) minmax(0, 1fr);
      }
    }

    @media (max-width: 900px) {
      body.customer-page--profile .profile-shell {
        grid-template-columns: minmax(0, 1fr);
        gap: 1.25rem;
      }

      body.customer-page--profile .profile-summary {
        position: static;
      }

      body.customer-page--profile .field-grid {
        grid-template-columns: minmax(0, 1fr);
      }
    }

    @media (max-width: 640px) {
      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications {
        grid-template-columns: minmax(0, 1fr) auto !important;
        column-gap: 8px !important;
        padding: 16px 14px !important;
        overflow-x: hidden;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .logo {
        width: auto !important;
        min-width: 0 !important;
        gap: 6px !important;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .logo h1 {
        font-size: clamp(1rem, 5vw, 1.35rem) !important;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .servitech-logo {
        width: 34px !important;
        height: 34px !important;
        flex: 0 0 auto;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility {
        gap: 5px !important;
        max-width: 100%;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .notification-btn,
      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility .nav-toggle {
        width: 40px !important;
        height: 40px !important;
        flex: 0 0 40px;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility__link,
      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility__link:visited {
        min-height: 40px !important;
        padding: 8px 12px !important;
        font-size: 0.88rem !important;
      }

      body.customer-page--profile .profile-edit-page {
        padding: 1rem 0.85rem 2.25rem;
      }

      body.customer-page--profile .profile-summary {
        padding: 1.35rem;
        border-radius: 20px;
      }

      body.customer-page--profile .profile-avatar {
        width: 76px;
        height: 76px;
        border-radius: 22px;
        font-size: 1.45rem;
      }

      body.customer-page--profile .profile-summary h1 {
        font-size: clamp(1.65rem, 8vw, 2rem);
        line-height: 1.15;
      }

      body.customer-page--profile .profile-summary p {
        font-size: 1rem;
        line-height: 1.6;
      }

      body.customer-page--profile .profile-meta-item {
        padding: 0.9rem;
      }

      body.customer-page--profile .profile-panel {
        padding: 0;
        border-radius: 20px;
      }

      body.customer-page--profile .panel-topbar {
        padding: 1.25rem 1rem 0;
        gap: 1rem;
      }

      body.customer-page--profile .panel-topbar h2 {
        font-size: clamp(1.8rem, 9vw, 2.2rem);
        line-height: 1.1;
      }

      body.customer-page--profile .panel-topbar p {
        font-size: 1rem;
        line-height: 1.55;
      }

      body.customer-page--profile .back-link {
        width: 100%;
        justify-content: center;
        min-height: 46px;
        white-space: normal;
        text-align: center;
      }

      body.customer-page--profile .profile-form {
        padding: 0 1rem 1.2rem;
        gap: 1.1rem;
      }

      body.customer-page--profile .status-banner {
        margin: 0 1rem 1rem;
      }

      body.customer-page--profile .form-section {
        padding: 1rem;
        border-radius: 16px;
      }

      body.customer-page--profile .section-head,
      body.customer-page--profile .form-actions {
        gap: 0.8rem;
      }

      body.customer-page--profile .section-tag {
        white-space: normal;
      }

      body.customer-page--profile .field input {
        min-height: 52px;
        padding: 0.85rem 0.9rem;
      }

      body.customer-page--profile .password-field input {
        padding-right: 44px !important;
      }

      body.customer-page--profile .action-buttons {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        width: 100%;
      }

      body.customer-page--profile .action-buttons .btn-secondary,
      body.customer-page--profile .action-buttons .btn-primary {
        width: 100%;
        min-height: 48px;
      }
    }

    @media (max-width: 380px) {
      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications {
        padding-inline: 10px !important;
        column-gap: 6px !important;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .servitech-logo {
        width: 30px !important;
        height: 30px !important;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .logo h1 {
        font-size: 1rem !important;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility {
        gap: 4px !important;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .notification-btn,
      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility .nav-toggle {
        width: 36px !important;
        height: 36px !important;
        flex-basis: 36px;
      }

      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility__link,
      body.customer-page--profile .navbar.has-nav-menu.navbar--notifications .header-utility__link:visited {
        min-height: 36px !important;
        padding: 7px 9px !important;
        font-size: 0.82rem !important;
      }

      body.customer-page--profile .profile-edit-page {
        padding-inline: 0.65rem;
      }

      body.customer-page--profile .profile-summary,
      body.customer-page--profile .form-section {
        padding-inline: 0.9rem;
      }

      body.customer-page--profile .profile-form,
      body.customer-page--profile .panel-topbar {
        padding-left: 0.9rem;
        padding-right: 0.9rem;
      }
    }

    @media (max-width: 1024px) {
      body.customer-page--profile .profile-shell {
        width: min(100%, 920px);
        grid-template-columns: minmax(230px, 0.36fr) minmax(0, 1fr);
      }

      body.customer-page--profile .profile-summary {
        padding: 1.45rem;
      }
    }

    @media (max-width: 880px) {
      body.customer-page--profile .profile-shell {
        grid-template-columns: minmax(0, 1fr);
      }

      body.customer-page--profile .profile-summary {
        position: static;
      }

      body.customer-page--profile .profile-meta {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      body.customer-page--profile .field-grid {
        grid-template-columns: minmax(0, 1fr);
      }
    }

    @media (max-width: 760px) {
      body.customer-page--profile .profile-edit-page {
        padding-inline: 1rem;
      }

      body.customer-page--profile .panel-topbar {
        grid-template-columns: minmax(0, 1fr);
      }

      body.customer-page--profile .back-link {
        justify-self: stretch;
        justify-content: center;
      }

      body.customer-page--profile .password-mini-card {
        grid-template-columns: minmax(0, 1fr);
      }

      body.customer-page--profile .password-mini-card .btn-secondary {
        width: 100%;
      }

      body.customer-page--profile .confirm-modal {
        width: min(calc(100vw - 1.5rem), 460px);
        max-height: calc(100dvh - 1.5rem);
        overflow-y: auto;
      }

      body.customer-page--profile .confirm-modal__actions,
      body.customer-page--profile .password-modal__actions {
        grid-template-columns: minmax(0, 1fr);
      }
    }

    @media (max-width: 560px) {
      body.customer-page--profile .profile-edit-page {
        padding: 0.8rem 0.7rem 1.8rem;
      }

      body.customer-page--profile .profile-summary,
      body.customer-page--profile .profile-panel {
        border-radius: 16px;
      }

      body.customer-page--profile .profile-summary {
        padding: 1.05rem;
      }

      body.customer-page--profile .profile-meta {
        grid-template-columns: minmax(0, 1fr);
      }

      body.customer-page--profile .panel-topbar,
      body.customer-page--profile .profile-form {
        padding-left: 0.85rem;
        padding-right: 0.85rem;
      }

      body.customer-page--profile .panel-topbar h2 {
        font-size: 1.7rem;
      }

      body.customer-page--profile .form-section {
        padding: 0.9rem;
        border-radius: 14px;
      }

      body.customer-page--profile .field-heading {
        gap: 0.55rem;
      }

      body.customer-page--profile .field-heading label {
        line-height: 1.25;
        overflow-wrap: anywhere;
      }

      body.customer-page--profile .field-edit-btn {
        min-width: 54px;
        min-height: 36px;
        padding-inline: 0.65rem;
      }

      body.customer-page--profile .contact-number-prefix {
        padding-inline: 0.75rem;
      }

      body.customer-page--profile .field .contact-number-control input[type="tel"] {
        padding-inline: 0.75rem;
      }

      body.customer-page--profile .password-mini-card {
        padding: 0.9rem;
      }

      body.customer-page--profile .form-actions {
        gap: 0.85rem;
      }
    }

    @media (max-width: 360px) {
      body.customer-page--profile .profile-edit-page {
        padding-inline: 0.55rem;
      }

      body.customer-page--profile .panel-topbar,
      body.customer-page--profile .profile-form {
        padding-left: 0.7rem;
        padding-right: 0.7rem;
      }

      body.customer-page--profile .form-section {
        padding-inline: 0.72rem;
      }

      body.customer-page--profile .field-heading {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
      }

      body.customer-page--profile .contact-number-prefix {
        padding-inline: 0.62rem;
        font-size: 0.9rem;
      }

      body.customer-page--profile .field input,
      body.customer-page--profile .field .contact-number-control input[type="tel"] {
        font-size: 0.95rem;
      }

      body.customer-page--profile .confirm-modal {
        width: calc(100vw - 1rem);
        padding: 1rem;
        border-radius: 16px;
      }

      body.customer-page--profile .confirm-modal__header {
        padding-right: 2.35rem;
      }
    }

    @media (min-width: 881px) {
      body.customer-page--profile .profile-shell {
        display: grid;
        align-items: start !important;
        align-content: start !important;
        place-items: start stretch;
      }

      body.customer-page--profile .profile-summary {
        grid-column: 1;
        grid-row: 1;
        align-self: start !important;
        position: relative !important;
        top: auto !important;
        transform: none !important;
        margin-top: 0 !important;
        margin-block-start: 0 !important;
      }

      body.customer-page--profile .profile-panel {
        grid-column: 2;
        grid-row: 1;
        align-self: start !important;
        position: relative;
        top: auto;
        transform: none;
        margin-top: 0 !important;
        margin-block-start: 0 !important;
      }
    }

    body.customer-page--profile #profileConfirmOverlay {
      z-index: 20000 !important;
    }

    body.customer-page--profile #profileConfirmModal {
      z-index: 20020 !important;
    }

    body.customer-page--profile #passwordModalOverlay {
      z-index: 20100 !important;
    }

    body.customer-page--profile #passwordChangeModal {
      z-index: 20120 !important;
      pointer-events: auto;
    }

    html.customer-profile-page-root {
      height: auto;
      min-height: 100%;
      overflow-x: hidden;
      overflow-y: auto;
    }

    body.customer-page--profile {
      height: auto;
      min-height: 100dvh;
      overflow-x: hidden !important;
      overflow-y: visible;
      overscroll-behavior-y: auto;
    }

    body.customer-page--profile.modal-open {
      overflow: hidden;
    }

    body.customer-page--profile .profile-edit-page {
      flex: 0 0 auto;
      height: auto;
      min-height: 0;
      max-height: none;
      overflow-y: visible;
      padding-top: calc(2rem + var(--profile-header-extra-offset, 0px));
    }

    body.customer-page--profile .profile-shell {
      height: auto;
      min-height: 0;
      max-height: none;
      overflow: visible;
    }

    body.customer-page--profile .profile-summary,
    body.customer-page--profile .profile-panel,
    body.customer-page--profile .profile-form,
    body.customer-page--profile .form-section {
      min-height: 0;
      max-height: none;
    }

    @media (max-width: 900px) {
      body.customer-page--profile .profile-shell {
        grid-auto-rows: auto;
        align-items: start;
        overflow: visible !important;
      }

      body.customer-page--profile .profile-summary {
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        align-self: start !important;
        position: static !important;
        overflow: visible !important;
        overflow-y: visible !important;
        overscroll-behavior: auto !important;
        -webkit-overflow-scrolling: auto;
      }

      body.customer-page--profile .profile-summary::after {
        inset: auto 0.35rem 0.35rem auto;
      }

      body.customer-page--profile .profile-edit-page {
        padding-top: calc(1.25rem + var(--profile-header-extra-offset, 0px));
      }
    }

    @media (max-width: 560px) {
      body.customer-page--profile .profile-edit-page {
        padding-top: calc(1rem + var(--profile-header-extra-offset, 0px));
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--profile<?php echo ($openConfirmModal || $openPasswordModal) ? " modal-open" : ""; ?>">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="profile-edit-page">
  <div class="profile-shell">
    <aside class="profile-summary" aria-label="Profile summary">
      <span class="summary-kicker">Customer Account</span>
      <div class="profile-avatar"><?php echo e($avatarInitials); ?></div>
      <h1><?php echo e($formData["name"] !== "" ? $formData["name"] : "Customer"); ?></h1>
      <p>Keep your account details current so we can contact you about queue updates, orders, and service progress.</p>

      <p class="summary-support">Update the details below so your queue, order, and service notices always reach you on time.</p>

      <div class="profile-meta">
        <div class="profile-meta-item">
          <span>Email</span>
          <strong><?php echo e($formData["email"] !== "" ? $formData["email"] : "Not set"); ?></strong>
        </div>
        <div class="profile-meta-item">
          <span>Phone</span>
          <strong><?php echo e($formData["phone"] !== "" ? $formData["phone"] : "Required"); ?></strong>
        </div>
      </div>
    </aside>

    <section class="profile-panel">
      <div class="panel-topbar">
        <div>
          <span class="panel-kicker">Profile Settings</span>
          <h2>Edit Profile</h2>
          <p>Update only what has changed. Password fields can be left blank if you do not want to change them.</p>
        </div>
        <a href="/pages/customer/customer_dash.php" class="back-link" aria-label="Back to dashboard">
          <span>Back to dashboard</span>
        </a>
      </div>

      <?php if ($errors["general"] !== ""): ?>
        <div class="status-banner status-error" role="alert">
          <?php echo e($errors["general"]); ?>
        </div>
      <?php endif; ?>

      <form id="editProfileForm" class="profile-form" action="/pages/customer/custo_edit_profile.php" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input id="profileUpdateScope" type="hidden" name="update_scope" value="profile">

        <div class="form-section">
          <div class="section-head">
            <div>
              <span class="section-kicker">Basic Details</span>
              <h3>Personal Information</h3>
            </div>
          </div>
          <p>Fields marked with an asterisk are required.</p>

          <div class="field-grid">
            <div class="field full-width">
              <div class="field-heading">
                <label for="name">Full Name <span class="required">*</span></label>
                <button type="button" class="field-edit-btn" data-edit-field="name" aria-controls="name" aria-pressed="false">Edit</button>
              </div>
              <input
                id="name"
                name="name"
                type="text"
                value="<?php echo e($formData["name"]); ?>"
                placeholder="Enter your full name"
                autocomplete="name"
                maxlength="<?php echo SERVITECH_LIMIT_FULLNAME; ?>"
                required
                readonly
                data-profile-field="name"
                aria-invalid="<?php echo $errors["name"] !== "" ? "true" : "false"; ?>"
                aria-describedby="name-error"
                class="<?php echo $errors["name"] !== "" ? "is-invalid" : ""; ?>"
              >
              <div id="name-error" class="field-error"><?php echo e($errors["name"]); ?></div>
            </div>

            <div class="field">
              <div class="field-heading">
                <label for="email">Email Address <span class="required">*</span></label>
                <button type="button" class="field-edit-btn" data-edit-field="email" aria-controls="email" aria-pressed="false">Edit</button>
              </div>
              <input
                id="email"
                name="email"
                type="email"
                value="<?php echo e($formData["email"]); ?>"
                placeholder="name@example.com"
                autocomplete="email"
                maxlength="<?php echo SERVITECH_LIMIT_EMAIL; ?>"
                required
                readonly
                data-profile-field="email"
                aria-invalid="<?php echo $errors["email"] !== "" ? "true" : "false"; ?>"
                aria-describedby="email-error"
                class="<?php echo $errors["email"] !== "" ? "is-invalid" : ""; ?>"
              >
              <div id="email-error" class="field-error"><?php echo e($errors["email"]); ?></div>
            </div>

            <div class="field">
              <div class="field-heading">
                <label for="phone">Phone Number <span class="required">*</span></label>
                <button type="button" class="field-edit-btn" data-edit-field="phone" aria-controls="phone" aria-pressed="false">Edit</button>
              </div>
              <div id="phoneControl" class="contact-number-control is-readonly <?php echo $errors["phone"] !== "" ? "is-invalid" : ""; ?>">
                <span class="contact-number-prefix" aria-label="Philippine country code">+63</span>
                <input
                  id="phone"
                  type="tel"
                  value="<?php echo e($phoneNationalValue); ?>"
                  placeholder="9XXXXXXXXX"
                  autocomplete="tel-national"
                  inputmode="numeric"
                  pattern="9[0-9]{9}"
                  maxlength="10"
                  title="Enter the 10-digit Philippine mobile number after +63, starting with 9."
                  required
                  readonly
                  data-profile-field="phone"
                  aria-invalid="<?php echo $errors["phone"] !== "" ? "true" : "false"; ?>"
                  aria-describedby="phone-note phone-error"
                  class="<?php echo $errors["phone"] !== "" ? "is-invalid" : ""; ?>"
                >
              </div>
              <input id="phoneFull" name="phone" type="hidden" value="<?php echo e($formData["phone"]); ?>">
              <div id="phone-note" class="field-note">Enter the 10-digit mobile number after +63, starting with 9.</div>
              <div id="phone-error" class="field-error"><?php echo e($errors["phone"]); ?></div>
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="section-head">
            <div>
              <span class="section-kicker">Security</span>
              <h3>Change Password</h3>
            </div>
          </div>
          <div class="password-mini-card">
            <p>Keep your current password or open the secure password form when you need to change it.</p>
            <button id="openPasswordModal" type="button" class="btn-secondary">Change Password</button>
          </div>
        </div>

        <div class="form-actions">
          <p class="action-copy">For security, saving changes requires a quick password confirmation.</p>
          <div class="action-buttons">
            <button id="saveProfileBtn" type="submit" class="btn-primary">Save Changes</button>
            <a href="/pages/customer/customer_dash.php" class="btn-secondary">Cancel</a>
          </div>
        </div>

        <div
          id="profileConfirmOverlay"
          class="modal-overlay<?php echo $openConfirmModal ? " active" : ""; ?>"
          aria-hidden="true"
          <?php echo $openConfirmModal ? "" : "hidden"; ?>
        ></div>
        <div
          id="profileConfirmModal"
          class="confirm-modal<?php echo $openConfirmModal ? " active" : ""; ?>"
          role="dialog"
          aria-modal="true"
          aria-labelledby="profileConfirmTitle"
          aria-describedby="profileConfirmMessage"
          <?php echo $openConfirmModal ? "" : "hidden"; ?>
        >
          <button id="profileConfirmClose" class="confirm-modal__close" type="button" aria-label="Close confirmation"><span aria-hidden="true">&times;</span></button>
          <div class="confirm-modal__header">
            <span class="section-kicker">Secure Confirmation</span>
            <h2 id="profileConfirmTitle">Confirm Profile Update</h2>
            <p id="profileConfirmMessage">Please enter your current password to continue updating your profile.</p>
          </div>
          <div class="field confirm-modal__field">
            <label for="current_password">Current Password</label>
            <div class="password-field">
              <input
                id="current_password"
                name="current_password"
                type="password"
                value=""
                placeholder="Enter your current password"
                autocomplete="current-password"
                aria-invalid="<?php echo $errors["current_password"] !== "" ? "true" : "false"; ?>"
                aria-describedby="current-password-error"
                class="<?php echo $errors["current_password"] !== "" ? "is-invalid" : ""; ?>"
              >
              <button type="button" class="password-toggle" data-password-toggle data-target="current_password" data-toggle-password="current_password" aria-controls="current_password" aria-label="Show current password" aria-pressed="false">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
              </button>
            </div>
            <div id="current-password-error" class="field-error"><?php echo e($errors["current_password"]); ?></div>
          </div>
          <div class="confirm-modal__actions">
            <button id="profileConfirmCancel" type="button" class="btn-secondary">Cancel</button>
            <button id="profileConfirmSubmit" type="button" class="btn-primary">Confirm Update</button>
          </div>
        </div>

        <div
          id="passwordModalOverlay"
          class="modal-overlay<?php echo $openPasswordModal ? " active" : ""; ?>"
          aria-hidden="true"
          <?php echo $openPasswordModal ? "" : "hidden"; ?>
        ></div>
        <div
          id="passwordChangeModal"
          class="confirm-modal password-modal<?php echo $openPasswordModal ? " active" : ""; ?>"
          role="dialog"
          aria-modal="true"
          aria-labelledby="passwordModalTitle"
          aria-describedby="passwordModalMessage"
          <?php echo $openPasswordModal ? "" : "hidden"; ?>
        >
          <button id="passwordModalClose" class="confirm-modal__close" type="button" aria-label="Close password change"><span aria-hidden="true">&times;</span></button>
          <div class="confirm-modal__header">
            <span class="section-kicker">Password</span>
            <h2 id="passwordModalTitle">Change Password</h2>
            <p id="passwordModalMessage">Enter a new password now. You will confirm your current password when you save all changes.</p>
          </div>
          <div class="password-modal__field-grid">
            <div class="field">
              <label for="new_password">New Password</label>
              <div class="password-field">
                <input
                  id="new_password"
                  name="new_password"
                  type="password"
                  value=""
                  placeholder="Minimum <?php echo SERVITECH_PASSWORD_MIN_LENGTH; ?> characters"
                  autocomplete="new-password"
                  minlength="<?php echo SERVITECH_PASSWORD_MIN_LENGTH; ?>"
                  maxlength="<?php echo SERVITECH_PASSWORD_MAX_BYTES; ?>"
                  aria-invalid="<?php echo $errors["new_password"] !== "" ? "true" : "false"; ?>"
                  aria-describedby="new-password-note new-password-error"
                  class="<?php echo $errors["new_password"] !== "" ? "is-invalid" : ""; ?>"
                >
                <button type="button" class="password-toggle" data-password-toggle data-target="new_password" data-toggle-password="new_password" aria-controls="new_password" aria-label="Show new password" aria-pressed="false">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
              </div>
              <div id="new-password-note" class="field-note">Use <?php echo SERVITECH_PASSWORD_MIN_LENGTH; ?> to <?php echo SERVITECH_PASSWORD_MAX_BYTES; ?> characters.</div>
              <div id="new-password-error" class="field-error"><?php echo e($errors["new_password"]); ?></div>
            </div>

            <div class="field">
              <label for="confirm_password">Confirm New Password</label>
              <div class="password-field">
                <input
                  id="confirm_password"
                  name="confirm_password"
                  type="password"
                  value=""
                  placeholder="Re-enter your new password"
                  autocomplete="new-password"
                  minlength="<?php echo SERVITECH_PASSWORD_MIN_LENGTH; ?>"
                  maxlength="<?php echo SERVITECH_PASSWORD_MAX_BYTES; ?>"
                  aria-invalid="<?php echo $errors["confirm_password"] !== "" ? "true" : "false"; ?>"
                  aria-describedby="confirm-password-error"
                  class="<?php echo $errors["confirm_password"] !== "" ? "is-invalid" : ""; ?>"
                >
                <button type="button" class="password-toggle" data-password-toggle data-target="confirm_password" data-toggle-password="confirm_password" aria-controls="confirm_password" aria-label="Show confirm password" aria-pressed="false">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
              </div>
              <div id="confirm-password-error" class="field-error"><?php echo e($errors["confirm_password"]); ?></div>
            </div>
          </div>
          <div class="password-modal__actions">
            <button id="passwordModalCancel" type="button" class="btn-secondary">Cancel</button>
            <button id="passwordModalDone" type="button" class="btn-primary">Save</button>
          </div>
        </div>
      </form>
    </section>
  </div>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  (function () {
    if (!document.body || !document.body.classList.contains("customer-page--profile")) {
      return;
    }

    document.documentElement.classList.add("customer-profile-page-root");

    const header = document.querySelector(".customer-shared-header");
    if (!header) {
      return;
    }

    let pendingFrame = 0;

    function readFixedHeaderOffset() {
      const offset = window.getComputedStyle(document.body).getPropertyValue("--fixed-site-header-offset");
      const parsedOffset = Number.parseFloat(offset);
      return Number.isFinite(parsedOffset) ? parsedOffset : 0;
    }

    function syncProfileHeaderOffset() {
      if (pendingFrame) {
        window.cancelAnimationFrame(pendingFrame);
      }

      pendingFrame = window.requestAnimationFrame(function () {
        pendingFrame = 0;
        const headerHeight = header.getBoundingClientRect().height;
        const missingOffset = Math.max(0, Math.ceil(headerHeight - readFixedHeaderOffset()));
        document.body.style.setProperty("--profile-header-extra-offset", missingOffset + "px");
      });
    }

    if ("ResizeObserver" in window) {
      new ResizeObserver(syncProfileHeaderOffset).observe(header);
    }

    if ("MutationObserver" in window) {
      new MutationObserver(syncProfileHeaderOffset).observe(header, {
        attributes: true,
        attributeFilter: ["class", "style"]
      });
    }

    window.addEventListener("resize", syncProfileHeaderOffset, { passive: true });
    window.addEventListener("orientationchange", syncProfileHeaderOffset, { passive: true });
    syncProfileHeaderOffset();
  })();

  (function () {
    const form = document.getElementById("editProfileForm");
    const submitButton = document.getElementById("saveProfileBtn");
    const confirmModal = document.getElementById("profileConfirmModal");
    const confirmOverlay = document.getElementById("profileConfirmOverlay");
    const confirmClose = document.getElementById("profileConfirmClose");
    const confirmCancel = document.getElementById("profileConfirmCancel");
    const confirmSubmit = document.getElementById("profileConfirmSubmit");
    const passwordModal = document.getElementById("passwordChangeModal");
    const passwordOverlay = document.getElementById("passwordModalOverlay");
    const openPasswordButton = document.getElementById("openPasswordModal");
    const passwordClose = document.getElementById("passwordModalClose");
    const passwordCancel = document.getElementById("passwordModalCancel");
    const passwordDone = document.getElementById("passwordModalDone");
    const serverToast = {
      message: <?php echo json_encode($toastMessage); ?>,
      tone: <?php echo json_encode($toastType !== "" ? $toastType : "info"); ?>
    };

    if (serverToast.message && typeof window.servitechToast === "function") {
      window.servitechToast(serverToast.message, { tone: serverToast.tone });
    }

    if (!form || !submitButton) {
      return;
    }

    let confirmationAccepted = false;
    const phoneHiddenInput = document.getElementById("phoneFull");
    const phoneControl = document.getElementById("phoneControl");
    const updateScopeInput = document.getElementById("profileUpdateScope");

    const fields = {
      name: document.getElementById("name"),
      email: document.getElementById("email"),
      phone: document.getElementById("phone"),
      current_password: document.getElementById("current_password"),
      new_password: document.getElementById("new_password"),
      confirm_password: document.getElementById("confirm_password")
    };

    const errorTargets = {
      name: document.getElementById("name-error"),
      email: document.getElementById("email-error"),
      phone: document.getElementById("phone-error"),
      current_password: document.getElementById("current-password-error"),
      new_password: document.getElementById("new-password-error"),
      confirm_password: document.getElementById("confirm-password-error")
    };
    const passwordMinLength = <?php echo SERVITECH_PASSWORD_MIN_LENGTH; ?>;
    const passwordMaxLength = <?php echo SERVITECH_PASSWORD_MAX_BYTES; ?>;
    const profileFieldKeys = ["name", "email", "phone"];
    const editButtons = Array.from(document.querySelectorAll("[data-edit-field]"));
    const initialProfile = {
      name: <?php echo json_encode($storedNameValue); ?>,
      email: <?php echo json_encode($storedEmailValue); ?>,
      phone: <?php echo json_encode($storedPhoneNationalValue); ?>
    };

    function showToast(message, tone) {
      if (typeof window.servitechToast === "function") {
        window.servitechToast(message, { tone: tone || "info" });
      }
    }

    function clearError(key) {
      if (fields[key]) {
        fields[key].classList.remove("is-invalid");
        fields[key].setAttribute("aria-invalid", "false");
      }
      if (key === "phone" && phoneControl) {
        phoneControl.classList.remove("is-invalid");
      }
      if (errorTargets[key]) {
        errorTargets[key].textContent = "";
      }
    }

    function setError(key, message) {
      if (fields[key]) {
        fields[key].classList.add("is-invalid");
        fields[key].setAttribute("aria-invalid", "true");
      }
      if (key === "phone" && phoneControl) {
        phoneControl.classList.add("is-invalid");
      }
      if (errorTargets[key]) {
        errorTargets[key].textContent = message;
      }
    }

    function setActiveEditableField(activeKey) {
      if (updateScopeInput) {
        updateScopeInput.value = "profile";
      }
      confirmationAccepted = false;

      profileFieldKeys.forEach(function (key) {
        const input = fields[key];
        const isActive = key === activeKey;

        if (input) {
          input.readOnly = !isActive;
        }

        if (key === "phone" && phoneControl) {
          phoneControl.classList.toggle("is-readonly", !isActive);
        }
      });

      editButtons.forEach(function (button) {
        const isActive = button.getAttribute("data-edit-field") === activeKey;
        button.classList.toggle("is-active", isActive);
        button.setAttribute("aria-pressed", isActive ? "true" : "false");
        button.textContent = isActive ? "Done" : "Edit";
      });

      if (activeKey && fields[activeKey]) {
        fields[activeKey].focus();
        if (typeof fields[activeKey].select === "function") {
          fields[activeKey].select();
        }
      }
    }

    editButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        const fieldKey = button.getAttribute("data-edit-field");
        const isActive = button.classList.contains("is-active");
        setActiveEditableField(isActive ? null : fieldKey);
      });
    });

    function setPasswordToggleIcon(button, isVisible) {
      const icon = isVisible
        ? '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 2l20 20"></path><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.5 4.3A10.8 10.8 0 0 1 12 4c5 0 9 4.5 10 8a11.8 11.8 0 0 1-2 3.5"></path><path d="M6.1 6.1A11.8 11.8 0 0 0 2 12c1 3.5 5 8 10 8a10.8 10.8 0 0 0 4.1-.8"></path></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>';

      button.innerHTML = icon;
      button.classList.toggle("is-visible", isVisible);
      button.classList.toggle("is-password-visible", isVisible);
      button.setAttribute("aria-pressed", isVisible ? "true" : "false");
    }

    function getPasswordToggleTargetId(button) {
      return button.getAttribute("data-target") || button.getAttribute("data-toggle-password") || button.getAttribute("aria-controls") || "";
    }

    function getPasswordToggleLabel(input) {
      return (input.name || input.id || "password").replace(/_/g, " ");
    }

    function resetPasswordToggle(inputId) {
      const input = inputId ? document.getElementById(inputId) : null;

      if (input) {
        input.type = "password";
      }

      document.querySelectorAll("[data-password-toggle][data-target='" + inputId + "'], [data-toggle-password='" + inputId + "']").forEach(function (button) {
        setPasswordToggleIcon(button, false);
        button.setAttribute("aria-label", input ? "Show " + getPasswordToggleLabel(input) : "Show password");
      });
    }

    function resetPasswordFields() {
      ["new_password", "confirm_password"].forEach(function (key) {
        if (fields[key]) {
          fields[key].value = "";
          fields[key].type = "password";
        }
        clearError(key);
      });

      ["new_password", "confirm_password"].forEach(resetPasswordToggle);
    }

    function openPasswordChangeModal() {
      if (!passwordModal || !passwordOverlay) {
        return;
      }

      passwordOverlay.hidden = false;
      passwordModal.hidden = false;
      passwordOverlay.classList.add("active");
      passwordModal.classList.add("active");
      document.body.classList.add("modal-open");
      window.setTimeout(function () {
        if (fields.new_password) {
          fields.new_password.focus();
        }
      }, 30);
    }

    function closePasswordChangeModal(options) {
      if (!passwordModal || !passwordOverlay) {
        return;
      }

      if (options && options.clear) {
        resetPasswordFields();
      }

      passwordModal.classList.remove("active");
      passwordOverlay.classList.remove("active");
      passwordModal.hidden = true;
      passwordOverlay.hidden = true;
      document.body.classList.remove("modal-open");
      if (openPasswordButton) {
        openPasswordButton.focus();
      }
    }

    function validatePasswordChangeFields() {
      const newPassword = fields.new_password ? fields.new_password.value || "" : "";
      const confirmPassword = fields.confirm_password ? fields.confirm_password.value || "" : "";
      let hasErrors = false;

      clearError("new_password");
      clearError("confirm_password");

      if (newPassword === "" && confirmPassword === "") {
        return false;
      }

      if (newPassword === "") {
        setError("new_password", "Enter a new password.");
        hasErrors = true;
      } else if (newPassword.length < passwordMinLength) {
        setError("new_password", `New password must be at least ${passwordMinLength} characters.`);
        hasErrors = true;
      } else if (newPassword.length > passwordMaxLength) {
        setError("new_password", `New password must not exceed ${passwordMaxLength} characters.`);
        hasErrors = true;
      }

      if (confirmPassword === "") {
        setError("confirm_password", "Confirm your new password.");
        hasErrors = true;
      } else if (confirmPassword !== newPassword) {
        setError("confirm_password", "New password and confirmation do not match.");
        hasErrors = true;
      }

      return !hasErrors;
    }

    function sanitizePhilippineMobileInput(value) {
      let digits = value.replace(/\D/g, "");

      if (digits.startsWith("63")) {
        digits = digits.slice(2);
      }

      if (digits.startsWith("0")) {
        digits = digits.slice(1);
      }

      return digits.slice(0, 10);
    }

    function syncPhilippineMobileInput() {
      if (!fields.phone || !phoneHiddenInput) {
        return;
      }

      fields.phone.value = sanitizePhilippineMobileInput(fields.phone.value);
      phoneHiddenInput.value = fields.phone.value ? `+63${fields.phone.value}` : "";
    }

    if (fields.phone) {
      fields.phone.addEventListener("input", function () {
        syncPhilippineMobileInput();
        const message = fields.phone.value && /^9\d{9}$/.test(fields.phone.value)
          ? ""
          : "Enter a valid 10-digit Philippine mobile number after +63, starting with 9.";

        if (fields.phone.value === "") {
          clearError("phone");
        } else if (message) {
          setError("phone", message);
        } else {
          clearError("phone");
        }
      });

      fields.phone.addEventListener("blur", function () {
        syncPhilippineMobileInput();
        if (fields.phone.value === "") {
          setError("phone", "Phone number is required.");
        } else if (!/^9\d{9}$/.test(fields.phone.value)) {
          setError("phone", "Enter a valid 10-digit Philippine mobile number after +63, starting with 9.");
        } else {
          clearError("phone");
        }
      });
    }

    if (openPasswordButton) {
      openPasswordButton.addEventListener("click", openPasswordChangeModal);
    }

    [confirmModal, passwordModal].forEach(function (modal) {
      if (modal) {
        modal.addEventListener("click", function (event) {
          event.stopPropagation();
        });
      }
    });

    [passwordClose, passwordCancel].forEach(function (button) {
      if (button) {
        button.addEventListener("click", function () {
          closePasswordChangeModal({ clear: true });
        });
      }
    });

    if (passwordOverlay) {
      passwordOverlay.addEventListener("click", function () {
        closePasswordChangeModal({ clear: true });
      });
    }

    if (passwordDone) {
      passwordDone.addEventListener("click", function () {
        const hasPasswordInput = Boolean((fields.new_password && fields.new_password.value) || (fields.confirm_password && fields.confirm_password.value));

        if (!hasPasswordInput) {
          setError("new_password", "Enter a new password.");
          showToast("Enter a new password before saving.", "warning");
          if (fields.new_password) {
            fields.new_password.focus();
          }
          return;
        }

        if (!validatePasswordChangeFields()) {
          showToast("Please fix the password fields before continuing.", "warning");
          const firstInvalid = passwordModal ? passwordModal.querySelector(".is-invalid") : null;
          if (firstInvalid) {
            firstInvalid.focus();
          }
          return;
        }

        closePasswordChangeModal();
        if (updateScopeInput) {
          updateScopeInput.value = "password";
        }
        openConfirmModal();
      });
    }

    function openConfirmModal() {
      if (!confirmModal || !confirmOverlay) {
        confirmationAccepted = true;
        form.requestSubmit ? form.requestSubmit(submitButton) : form.submit();
        return;
      }

      confirmationAccepted = false;
      if (fields.current_password) {
        fields.current_password.value = "";
        clearError("current_password");
      }
      resetPasswordToggle("current_password");
      confirmOverlay.hidden = false;
      confirmModal.hidden = false;
      confirmOverlay.classList.add("active");
      confirmModal.classList.add("active");
      document.body.classList.add("modal-open");
      window.setTimeout(function () {
        if (fields.current_password) {
          fields.current_password.focus();
        }
      }, 30);
    }

    function closeConfirmModal() {
      if (!confirmModal || !confirmOverlay) {
        return;
      }

      confirmationAccepted = false;
      if (updateScopeInput) {
        updateScopeInput.value = "profile";
      }
      confirmModal.classList.remove("active");
      confirmOverlay.classList.remove("active");
      confirmModal.hidden = true;
      confirmOverlay.hidden = true;
      document.body.classList.remove("modal-open");
      if (confirmSubmit) {
        confirmSubmit.disabled = false;
        confirmSubmit.textContent = "Confirm Update";
      }
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = "Save Changes";
        submitButton.removeAttribute("aria-busy");
      }
      if (fields.current_password) {
        fields.current_password.value = "";
        clearError("current_password");
      }
      resetPasswordToggle("current_password");
      submitButton.focus();
    }

    [confirmClose, confirmCancel].forEach(function (button) {
      if (button) {
        button.addEventListener("click", closeConfirmModal);
      }
    });

    if (confirmOverlay) {
      confirmOverlay.addEventListener("click", closeConfirmModal);
    }

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && confirmModal && confirmModal.classList.contains("active")) {
        closeConfirmModal();
      } else if (event.key === "Escape" && passwordModal && passwordModal.classList.contains("active")) {
        closePasswordChangeModal({ clear: true });
      }
    });

    if (confirmModal && confirmModal.classList.contains("active") && fields.current_password) {
      fields.current_password.focus();
    }

    if (passwordModal && passwordModal.classList.contains("active") && fields.new_password) {
      fields.new_password.focus();
    }

    if (confirmSubmit) {
      confirmSubmit.addEventListener("click", function () {
        const currentPassword = fields.current_password ? fields.current_password.value : "";

        clearError("current_password");

        if (currentPassword === "") {
          setError("current_password", "Enter your current password.");
          showToast("Enter your current password to continue.", "warning");
          if (fields.current_password) {
            fields.current_password.focus();
          }
          return;
        }

        confirmationAccepted = true;
        confirmSubmit.disabled = true;
        confirmSubmit.textContent = "Confirming...";
        form.requestSubmit ? form.requestSubmit(submitButton) : form.submit();
      });
    }

    document.querySelectorAll("[data-password-toggle], [data-toggle-password]").forEach(function (toggleButton) {
      const inputId = getPasswordToggleTargetId(toggleButton);
      const targetInput = inputId ? document.getElementById(inputId) : null;

      if (!targetInput) {
        return;
      }

      setPasswordToggleIcon(toggleButton, targetInput.type !== "password");

      toggleButton.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();

        const shouldShow = targetInput.type === "password";
        targetInput.type = shouldShow ? "text" : "password";
        setPasswordToggleIcon(toggleButton, shouldShow);
        toggleButton.setAttribute("aria-label", (shouldShow ? "Hide " : "Show ") + getPasswordToggleLabel(targetInput));
      });
    });

    form.addEventListener("submit", function (event) {
      if (!confirmationAccepted && updateScopeInput) {
        updateScopeInput.value = "profile";
      }
      syncPhilippineMobileInput();
      Object.keys(fields).forEach(clearError);

      const name = (fields.name.value || "").trim();
      const email = (fields.email.value || "").trim();
      const phone = (fields.phone.value || "").trim();
      const currentPassword = fields.current_password.value || "";
      const newPassword = fields.new_password.value || "";
      const confirmPassword = fields.confirm_password.value || "";
      const wantsPassword = newPassword !== "" || confirmPassword !== "";
      const changedFields = {
        name: name !== initialProfile.name,
        email: email.toLowerCase() !== initialProfile.email,
        phone: phone !== initialProfile.phone
      };

      let hasErrors = false;
      let passwordHasErrors = false;

      if (changedFields.name && name === "") {
        setError("name", "Full name is required.");
        hasErrors = true;
      } else if (changedFields.name && name.length > <?php echo SERVITECH_LIMIT_FULLNAME; ?>) {
        setError("name", "Full name is too long.");
        hasErrors = true;
      }

      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (changedFields.email && email === "") {
        setError("email", "Email is required.");
        hasErrors = true;
      } else if (changedFields.email && email.length > <?php echo SERVITECH_LIMIT_EMAIL; ?>) {
        setError("email", "Email address must not exceed <?php echo SERVITECH_LIMIT_EMAIL; ?> characters.");
        hasErrors = true;
      } else if (changedFields.email && !emailPattern.test(email)) {
        setError("email", "Enter a valid email address.");
        hasErrors = true;
      }

      if (changedFields.phone && phone === "") {
        setError("phone", "Phone number is required.");
        hasErrors = true;
      } else if (changedFields.phone && !/^9\d{9}$/.test(phone)) {
        setError("phone", "Enter a valid 10-digit Philippine mobile number after +63, starting with 9.");
        hasErrors = true;
      }

      if (wantsPassword) {
        if (newPassword === "") {
          setError("new_password", "Enter a new password.");
          hasErrors = true;
          passwordHasErrors = true;
        } else if (newPassword.length < passwordMinLength) {
          setError("new_password", `New password must be at least ${passwordMinLength} characters.`);
          hasErrors = true;
          passwordHasErrors = true;
        } else if (newPassword.length > passwordMaxLength) {
          setError("new_password", `New password must not exceed ${passwordMaxLength} characters.`);
          hasErrors = true;
          passwordHasErrors = true;
        }
        if (confirmPassword === "") {
          setError("confirm_password", "Confirm your new password.");
          hasErrors = true;
          passwordHasErrors = true;
        } else if (confirmPassword !== newPassword) {
          setError("confirm_password", "New password and confirmation do not match.");
          hasErrors = true;
          passwordHasErrors = true;
        }
      }

      if (hasErrors) {
        event.preventDefault();
        confirmationAccepted = false;
        if (confirmSubmit) {
          confirmSubmit.disabled = false;
          confirmSubmit.textContent = "Confirm Update";
        }
        if (confirmModal && confirmModal.classList.contains("active")) {
          confirmModal.classList.remove("active");
          confirmOverlay.classList.remove("active");
          confirmModal.hidden = true;
          confirmOverlay.hidden = true;
          document.body.classList.remove("modal-open");
        }
        showToast("Please fix the highlighted fields before confirming.", "warning");

        if (passwordHasErrors) {
          openPasswordChangeModal();
        } else if (changedFields.name && fields.name && fields.name.classList.contains("is-invalid")) {
          setActiveEditableField("name");
        } else if (changedFields.email && fields.email && fields.email.classList.contains("is-invalid")) {
          setActiveEditableField("email");
        } else if (changedFields.phone && fields.phone && fields.phone.classList.contains("is-invalid")) {
          setActiveEditableField("phone");
        }

        const firstInvalid = passwordHasErrors && passwordModal
          ? passwordModal.querySelector(".is-invalid")
          : form.querySelector(".is-invalid");
        if (firstInvalid) {
          firstInvalid.focus();
        }
        return;
      }

      if (!confirmationAccepted) {
        event.preventDefault();
        openConfirmModal();
        return;
      }

      if (currentPassword === "") {
        event.preventDefault();
        confirmationAccepted = false;
        if (confirmSubmit) {
          confirmSubmit.disabled = false;
          confirmSubmit.textContent = "Confirm Update";
        }
        openConfirmModal();
        setError("current_password", "Enter your current password.");
        showToast("Enter your current password to continue.", "warning");
        return;
      }

      submitButton.disabled = true;
      submitButton.textContent = "Saving...";
      submitButton.setAttribute("aria-busy", "true");
    });

    syncPhilippineMobileInput();
    const firstInvalidProfileField = profileFieldKeys.find(function (key) {
      return fields[key] && fields[key].classList.contains("is-invalid");
    });
    setActiveEditableField(firstInvalidProfileField || null);
  })();
</script>

</body>
</html>






















