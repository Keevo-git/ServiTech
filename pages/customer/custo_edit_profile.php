<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/db.php";

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
    $candidates = [
        [
            "table" => "customers",
            "name" => ["name"],
            "email" => ["email"],
            "phone" => ["phone"],
            "password" => ["password_hash", "password"],
            "updated_at" => ["updated_at"],
        ],
        [
            "table" => "users",
            "name" => ["fullname", "name"],
            "email" => ["email"],
            "phone" => ["phone", "contact", "contacts"],
            "password" => ["password_hash", "password"],
            "updated_at" => ["updated_at"],
        ],
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
    return $formData["current_password"] !== ""
        || $formData["new_password"] !== ""
        || $formData["confirm_password"] !== "";
}

function verifyStoredPassword(string $storedPassword, string $submittedPassword): bool
{
    if ($storedPassword === "" || $submittedPassword === "") {
        return false;
    }

    $hashInfo = password_get_info($storedPassword);
    $isHash = (int)($hashInfo["algo"] ?? 0) !== 0;

    if ($isHash) {
        return password_verify($submittedPassword, $storedPassword);
    }

    return hash_equals($storedPassword, $submittedPassword);
}

function validateProfileInput(array $formData): array
{
    $errors = [
        "name" => "",
        "email" => "",
        "phone" => "",
        "current_password" => "",
        "new_password" => "",
        "confirm_password" => "",
        "general" => "",
    ];

    if ($formData["name"] === "") {
        $errors["name"] = "Full name is required.";
    } elseif (mb_strlen($formData["name"]) > 100) {
        $errors["name"] = "Full name must be 100 characters or fewer.";
    }

    if ($formData["email"] === "") {
        $errors["email"] = "Email is required.";
    } elseif (!filter_var($formData["email"], FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Enter a valid email address.";
    } elseif (mb_strlen($formData["email"]) > 150) {
        $errors["email"] = "Email must be 150 characters or fewer.";
    }

    if ($formData["phone"] !== "" && !ctype_digit($formData["phone"])) {
        $errors["phone"] = "Phone must contain numbers only.";
    } elseif ($formData["phone"] !== "" && strlen($formData["phone"]) > 20) {
        $errors["phone"] = "Phone must be 20 digits or fewer.";
    }

    if (wantsPasswordChange($formData)) {
        if ($formData["current_password"] === "") {
            $errors["current_password"] = "Enter your current password.";
        }
        if ($formData["new_password"] === "") {
            $errors["new_password"] = "Enter a new password.";
        } elseif (strlen($formData["new_password"]) < 8) {
            $errors["new_password"] = "New password must be at least 8 characters.";
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

$errors = [
    "name" => "",
    "email" => "",
    "phone" => "",
    "current_password" => "",
    "new_password" => "",
    "confirm_password" => "",
    "general" => "",
];

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
        $formData["phone"] = sanitizeInput($profile["profile_phone"] ?? "");
    }
} catch (Throwable $exception) {
    error_log("profile page load error: " . $exception->getMessage());
    $errors["general"] = "Profile settings are temporarily unavailable.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $formData["name"] = sanitizeInput($_POST["name"] ?? $_POST["fullname"] ?? "");
    $formData["email"] = normalizeEmail(sanitizeInput($_POST["email"] ?? ""));
    $formData["phone"] = sanitizeInput($_POST["phone"] ?? $_POST["contact"] ?? $_POST["contacts"] ?? "");
    $formData["current_password"] = (string)($_POST["current_password"] ?? "");
    $formData["new_password"] = (string)($_POST["new_password"] ?? "");
    $formData["confirm_password"] = (string)($_POST["confirm_password"] ?? "");

    $errors = validateProfileInput($formData);

    if (!$schema || !$profile) {
        $errors["general"] = "We could not load your profile for updating.";
    }

    if ($errors["email"] === "" && $schema && emailExists($pdo, $schema, $formData["email"], $userId)) {
        $errors["email"] = "That email address is already in use.";
    }

    if (
        wantsPasswordChange($formData)
        && $errors["current_password"] === ""
        && $schema
        && $profile
        && !verifyStoredPassword((string)($profile["profile_password"] ?? ""), $formData["current_password"])
    ) {
        $errors["current_password"] = "Current password is incorrect.";
    }

    $hasErrors = (bool)array_filter($errors);

    if (!$hasErrors && $schema && $profile) {
        $changes = [];

        $currentName = sanitizeInput($profile["profile_name"] ?? "");
        $currentEmail = normalizeEmail((string)($profile["profile_email"] ?? ""));
        $currentPhone = sanitizeInput($profile["profile_phone"] ?? "");

        if ($formData["name"] !== $currentName) {
            $changes[$schema["nameColumn"]] = $formData["name"];
        }

        if ($formData["email"] !== $currentEmail) {
            $changes[$schema["emailColumn"]] = $formData["email"];
        }

        $newPhoneValue = $formData["phone"] === "" ? null : $formData["phone"];
        $currentPhoneValue = $currentPhone === "" ? null : $currentPhone;
        if ($newPhoneValue !== $currentPhoneValue && $schema["phoneColumn"]) {
            $changes[$schema["phoneColumn"]] = $newPhoneValue;
        }

        if (wantsPasswordChange($formData)) {
            $changes[$schema["passwordColumn"]] = password_hash($formData["new_password"], PASSWORD_DEFAULT);
        }

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

                $_SESSION["profile_flash"] = [
                    "type" => "success",
                    "message" => wantsPasswordChange($formData)
                        ? "Your profile and password were updated successfully."
                        : "Your profile was updated successfully.",
                ];
            } catch (PDOException $exception) {
                error_log("profile update error: " . $exception->getMessage());
                $errors["general"] = "We could not save your changes right now.";
            }
        } else {
            $_SESSION["profile_flash"] = [
                "type" => "info",
                "message" => "No changes were detected.",
            ];
        }

        if ($errors["general"] === "") {
            header("Location: /pages/customer/custo_edit_profile.php");
            exit();
        }
    }

    $formData["current_password"] = "";
    $formData["new_password"] = "";
    $formData["confirm_password"] = "";
}

$avatarInitials = getInitials($formData["name"]);
$flashType = is_array($flash) ? (string)($flash["type"] ?? "") : "";
$flashMessage = is_array($flash) ? (string)($flash["message"] ?? "") : "";
$profileFieldsCompleted = 0;
foreach (["name", "email", "phone"] as $profileField) {
    if ($formData[$profileField] !== "") {
        $profileFieldsCompleted++;
    }
}
$profileCompletion = (int)round(($profileFieldsCompleted / 3) * 100);
$profileCompletionLabel = $profileCompletion >= 100 ? "Complete" : ($profileCompletion >= 67 ? "Almost there" : "Needs attention");
$phoneStatusLabel = $formData["phone"] !== "" ? "Ready for queue and service updates." : "Add a phone number for faster service updates.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Edit Profile</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h9">
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
      position: sticky;
      top: 1.5rem;
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

    .status-toast,
    .status-banner {
      border-radius: 16px;
      padding: 0.95rem 1rem;
      margin-bottom: 1rem;
      border: 1px solid transparent;
      line-height: 1.55;
    }

    .status-toast {
      position: sticky;
      top: 1rem;
      z-index: 20;
      margin: 0 auto 1rem;
      max-width: 1080px;
      box-shadow: 0 14px 28px rgba(74, 5, 5, 0.16);
      transition: opacity 0.25s ease, transform 0.25s ease;
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

    .summary-progress-card {
      margin-top: 1.2rem;
      padding: 1rem 1rem 0.95rem;
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 236, 214, 0.18);
      position: relative;
      z-index: 1;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
    }

    .summary-progress-copy {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.75rem;
    }

    .summary-progress-copy span {
      color: rgba(255, 225, 194, 0.82);
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .summary-progress-copy strong {
      color: #ffffff;
      font-size: 0.95rem;
      text-align: right;
    }

    .summary-progress-bar {
      height: 10px;
      border-radius: 999px;
      overflow: hidden;
      background: rgba(255, 255, 255, 0.18);
    }

    .summary-progress-bar span {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, #ffd782 0%, #ffb347 55%, #ff8a2d 100%);
      box-shadow: 0 2px 8px rgba(255, 179, 71, 0.35);
    }

    .summary-progress-card small {
      display: block;
      margin-top: 0.75rem;
      color: rgba(255, 244, 232, 0.88);
      font-size: 0.9rem;
      line-height: 1.5;
    }

    .summary-strip {
      display: flex;
      flex-wrap: wrap;
      gap: 0.55rem;
      margin-top: 1rem;
      position: relative;
      z-index: 1;
    }

    .summary-chip {
      display: inline-flex;
      align-items: center;
      min-height: 34px;
      padding: 0.45rem 0.8rem;
      border-radius: 999px;
      background: rgba(255, 248, 236, 0.14);
      border: 1px solid rgba(255, 231, 204, 0.2);
      color: #fff7eb;
      font-size: 0.86rem;
      font-weight: 700;
    }

    .panel-kicker {
      margin-bottom: 0.45rem;
      color: #b85a11;
    }

    .status-banner {
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
      .summary-progress-copy,
      .section-head,
      .form-actions {
        flex-direction: column;
        align-items: flex-start;
      }

      .action-buttons {
        width: 100%;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--profile">

<?php include __DIR__ . "/../../components/header.php"; ?>

<?php if ($flashMessage !== ""): ?>
  <div id="profileToast" class="status-toast <?php echo $flashType === "success" ? "status-success" : "status-info"; ?>" role="status" aria-live="polite">
    <?php echo e($flashMessage); ?>
  </div>
<?php endif; ?>

<main class="profile-edit-page">
  <div class="profile-shell">
    <aside class="profile-summary" aria-label="Profile summary">
      <span class="summary-kicker">Customer Account</span>
      <div class="profile-avatar"><?php echo e($avatarInitials); ?></div>
      <h1><?php echo e($formData["name"] !== "" ? $formData["name"] : "Customer"); ?></h1>
      <p>Keep your account details current so we can contact you about queue updates, orders, and service progress.</p>

      <div class="summary-progress-card">
        <div class="summary-progress-copy">
          <span>Profile completion</span>
          <strong><?php echo e($profileCompletionLabel); ?> · <?php echo e((string)$profileCompletion); ?>%</strong>
        </div>
        <div class="summary-progress-bar" aria-hidden="true">
          <span style="width: <?php echo e((string)$profileCompletion); ?>%;"></span>
        </div>
        <small><?php echo e($phoneStatusLabel); ?></small>
      </div>

      <div class="summary-strip" aria-hidden="true">
        <span class="summary-chip">Secure update</span>
        <span class="summary-chip"><?php echo e($formData["phone"] !== "" ? "Phone added" : "Phone optional"); ?></span>
      </div>

      <div class="profile-meta">
        <div class="profile-meta-item">
          <span>Email</span>
          <strong><?php echo e($formData["email"] !== "" ? $formData["email"] : "Not set"); ?></strong>
        </div>
        <div class="profile-meta-item">
          <span>Phone</span>
          <strong><?php echo e($formData["phone"] !== "" ? $formData["phone"] : "Optional"); ?></strong>
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
          <span aria-hidden="true">&larr;</span>
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

        <div class="form-section">
          <div class="section-head">
            <div>
              <span class="section-kicker">Basic Details</span>
              <h3>Personal Information</h3>
            </div>
            <span class="section-tag">Required fields</span>
          </div>
          <p>Fields marked with an asterisk are required.</p>

          <div class="field-grid">
            <div class="field full-width">
              <label for="name">Full Name <span class="required">*</span></label>
              <input
                id="name"
                name="name"
                type="text"
                value="<?php echo e($formData["name"]); ?>"
                placeholder="Enter your full name"
                autocomplete="name"
                maxlength="100"
                required
                aria-invalid="<?php echo $errors["name"] !== "" ? "true" : "false"; ?>"
                aria-describedby="name-error"
                class="<?php echo $errors["name"] !== "" ? "is-invalid" : ""; ?>"
              >
              <div id="name-error" class="field-error"><?php echo e($errors["name"]); ?></div>
            </div>

            <div class="field">
              <label for="email">Email Address <span class="required">*</span></label>
              <input
                id="email"
                name="email"
                type="email"
                value="<?php echo e($formData["email"]); ?>"
                placeholder="name@example.com"
                autocomplete="email"
                maxlength="150"
                required
                aria-invalid="<?php echo $errors["email"] !== "" ? "true" : "false"; ?>"
                aria-describedby="email-error"
                class="<?php echo $errors["email"] !== "" ? "is-invalid" : ""; ?>"
              >
              <div id="email-error" class="field-error"><?php echo e($errors["email"]); ?></div>
            </div>

            <div class="field">
              <label for="phone">Phone Number</label>
              <input
                id="phone"
                name="phone"
                type="tel"
                value="<?php echo e($formData["phone"]); ?>"
                placeholder="Numbers only"
                autocomplete="tel"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="20"
                aria-invalid="<?php echo $errors["phone"] !== "" ? "true" : "false"; ?>"
                aria-describedby="phone-note phone-error"
                class="<?php echo $errors["phone"] !== "" ? "is-invalid" : ""; ?>"
              >
              <div id="phone-note" class="field-note">Optional. Use digits only with no spaces or symbols.</div>
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
            <span class="section-tag">Optional</span>
          </div>
          <p>Leave these fields empty if you want to keep your current password.</p>

          <div class="field-grid">
            <div class="field full-width">
              <label for="current_password">Current Password</label>
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
              <div id="current-password-error" class="field-error"><?php echo e($errors["current_password"]); ?></div>
            </div>

            <div class="field">
              <label for="new_password">New Password</label>
              <input
                id="new_password"
                name="new_password"
                type="password"
                value=""
                placeholder="Minimum 8 characters"
                autocomplete="new-password"
                aria-invalid="<?php echo $errors["new_password"] !== "" ? "true" : "false"; ?>"
                aria-describedby="new-password-note new-password-error"
                class="<?php echo $errors["new_password"] !== "" ? "is-invalid" : ""; ?>"
              >
              <div id="new-password-note" class="field-note">Use at least 8 characters for stronger security.</div>
              <div id="new-password-error" class="field-error"><?php echo e($errors["new_password"]); ?></div>
            </div>

            <div class="field">
              <label for="confirm_password">Confirm New Password</label>
              <input
                id="confirm_password"
                name="confirm_password"
                type="password"
                value=""
                placeholder="Re-enter your new password"
                autocomplete="new-password"
                aria-invalid="<?php echo $errors["confirm_password"] !== "" ? "true" : "false"; ?>"
                aria-describedby="confirm-password-error"
                class="<?php echo $errors["confirm_password"] !== "" ? "is-invalid" : ""; ?>"
              >
              <div id="confirm-password-error" class="field-error"><?php echo e($errors["confirm_password"]); ?></div>
            </div>
          </div>
        </div>

        <div class="form-actions">
          <p class="action-copy">Your current password stays hidden and is only replaced when you submit a new one.</p>
          <div class="action-buttons">
            <a href="/pages/customer/customer_dash.php" class="btn-secondary">Cancel</a>
            <button id="saveProfileBtn" type="submit" class="btn-primary">Save Changes</button>
          </div>
        </div>
      </form>
    </section>
  </div>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  (function () {
    const form = document.getElementById("editProfileForm");
    const submitButton = document.getElementById("saveProfileBtn");
    const toast = document.getElementById("profileToast");

    if (toast) {
      window.setTimeout(function () {
        toast.style.opacity = "0";
        toast.style.transform = "translateY(-8px)";
      }, 3500);
    }

    if (!form || !submitButton) {
      return;
    }

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

    function clearError(key) {
      if (fields[key]) {
        fields[key].classList.remove("is-invalid");
        fields[key].setAttribute("aria-invalid", "false");
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
      if (errorTargets[key]) {
        errorTargets[key].textContent = message;
      }
    }

    form.addEventListener("submit", function (event) {
      Object.keys(fields).forEach(clearError);

      const name = (fields.name.value || "").trim();
      const email = (fields.email.value || "").trim();
      const phone = (fields.phone.value || "").trim();
      const currentPassword = fields.current_password.value || "";
      const newPassword = fields.new_password.value || "";
      const confirmPassword = fields.confirm_password.value || "";
      const wantsPassword = currentPassword !== "" || newPassword !== "" || confirmPassword !== "";

      let hasErrors = false;

      if (name === "") {
        setError("name", "Full name is required.");
        hasErrors = true;
      }

      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (email === "") {
        setError("email", "Email is required.");
        hasErrors = true;
      } else if (!emailPattern.test(email)) {
        setError("email", "Enter a valid email address.");
        hasErrors = true;
      }

      if (phone !== "" && !/^\d+$/.test(phone)) {
        setError("phone", "Phone must contain numbers only.");
        hasErrors = true;
      }

      if (wantsPassword) {
        if (currentPassword === "") {
          setError("current_password", "Enter your current password.");
          hasErrors = true;
        }
        if (newPassword === "") {
          setError("new_password", "Enter a new password.");
          hasErrors = true;
        } else if (newPassword.length < 8) {
          setError("new_password", "New password must be at least 8 characters.");
          hasErrors = true;
        }
        if (confirmPassword === "") {
          setError("confirm_password", "Confirm your new password.");
          hasErrors = true;
        } else if (confirmPassword !== newPassword) {
          setError("confirm_password", "New password and confirmation do not match.");
          hasErrors = true;
        }
      }

      if (hasErrors) {
        event.preventDefault();
        return;
      }

      submitButton.disabled = true;
      submitButton.textContent = "Saving...";
      submitButton.setAttribute("aria-busy", "true");
    });
  })();
</script>

</body>
</html>








