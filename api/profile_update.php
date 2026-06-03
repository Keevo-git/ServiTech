<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/account.php";

servitech_enforce_csrf_token(false);

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}
if (!servitech_is_customer()) {
  header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
  exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: /pages/customer/custo_edit_profile.php");
  exit();
}

$fullname = trim((string)($_POST["fullname"] ?? ""));
$email    = trim((string)($_POST["email"] ?? ""));
$contact  = trim((string)($_POST["contact"] ?? $_POST["contacts"] ?? ""));

$current_password = (string)($_POST["current_password"] ?? "");
$new_password     = (string)($_POST["new_password"] ?? "");
$confirm_password = (string)($_POST["confirm_password"] ?? "");

if ($fullname === "" || $email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode("Full name and email are required."));
  exit();
}

try {
  // email uniqueness
  $chk = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1");
  $chk->execute([":email" => $email, ":id" => $user_id]);
  if ($chk->fetch()) {
    header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode("Email is already used."));
    exit();
  }

  $changingPass = ($new_password !== "" || $confirm_password !== "");

  if ($changingPass) {
    if ($new_password !== $confirm_password) {
      header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode("New password and confirm password do not match."));
      exit();
    }
    $passwordError = servitech_password_validation_error($new_password);
    if ($passwordError !== "") {
      header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode($passwordError));
      exit();
    }

    $p = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
    $p->execute([":id" => $user_id]);
    $row = $p->fetch();

    if (!$row || !password_verify($current_password, $row["password_hash"])) {
      header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode("Current password is incorrect."));
      exit();
    }

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);

    $params = [
      ":fullname" => $fullname,
      ":email" => $email,
      ":contact" => ($contact === "" ? null : $contact),
      ":password_hash" => $hashed,
      ":id" => $user_id
    ];

    try {
      $upd = $pdo->prepare("
        UPDATE users
        SET fullname = :fullname, email = :email, contact = :contact, password_hash = :password_hash,
            email_verified_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verified_at ELSE NULL END,
            email_verification_token = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_token ELSE NULL END,
            email_verification_expires = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_expires ELSE NULL END,
            email_verification_sent_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_sent_at ELSE NULL END
        WHERE id = :id
      ");
      $upd->execute($params);
    } catch (PDOException $e) {
      $upd = $pdo->prepare("
        UPDATE users
        SET fullname = :fullname, email = :email, contacts = :contact, password_hash = :password_hash,
            email_verified_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verified_at ELSE NULL END,
            email_verification_token = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_token ELSE NULL END,
            email_verification_expires = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_expires ELSE NULL END,
            email_verification_sent_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_sent_at ELSE NULL END
        WHERE id = :id
      ");
      $upd->execute($params);
    }

  } else {
    $params = [
      ":fullname" => $fullname,
      ":email" => $email,
      ":contact" => ($contact === "" ? null : $contact),
      ":id" => $user_id
    ];

    try {
      $upd = $pdo->prepare("
        UPDATE users
        SET fullname = :fullname, email = :email, contact = :contact,
            email_verified_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verified_at ELSE NULL END,
            email_verification_token = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_token ELSE NULL END,
            email_verification_expires = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_expires ELSE NULL END,
            email_verification_sent_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_sent_at ELSE NULL END
        WHERE id = :id
      ");
      $upd->execute($params);
    } catch (PDOException $e) {
      $upd = $pdo->prepare("
        UPDATE users
        SET fullname = :fullname, email = :email, contacts = :contact,
            email_verified_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verified_at ELSE NULL END,
            email_verification_token = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_token ELSE NULL END,
            email_verification_expires = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_expires ELSE NULL END,
            email_verification_sent_at = CASE WHEN LOWER(email) = LOWER(:email) THEN email_verification_sent_at ELSE NULL END
        WHERE id = :id
      ");
      $upd->execute($params);
    }
  }

  header("Location: /pages/customer/custo_edit_profile.php?ok=" . urlencode("Profile updated!"));
  exit();

} catch (PDOException $e) {
  error_log("profile_update error: " . $e->getMessage());
  header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode("DB error updating profile."));
  exit();
}

