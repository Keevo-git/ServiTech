<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/input_limits.php";

servitech_enforce_csrf_token(false);

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}
if (!servitech_is_customer()) {
  header("Location: " . servitech_url(servitech_internal_dashboard_path()));
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

$nameError = servitech_person_name_validation_error($fullname, "Full name");
if ($nameError !== "") {
  header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode($nameError));
  exit();
}

$emailError = servitech_email_validation_error($email);
if ($emailError !== "") {
  header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode($emailError));
  exit();
}

if (preg_match('/^09\d{9}$/', $contact)) {
  $contact = "+63" . substr($contact, 1);
}
if ($contact !== "" && !preg_match('/^\+639\d{9}$/', $contact)) {
  header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode("Enter a valid Philippine mobile number."));
  exit();
}

if (servitech_supabase_auth_enabled()) {
  try {
    $privilegedPdo = servitech_db_connect_privileged();
    $current = $privilegedPdo->prepare("
      SELECT id, email
      FROM users
      WHERE id = :id AND auth_user_id = :auth_user_id
      LIMIT 1
    ");
    $current->execute([
      ":id" => $user_id,
      ":auth_user_id" => (string)($_SESSION["auth_user_id"] ?? ""),
    ]);
    $profile = $current->fetch(PDO::FETCH_ASSOC);
    if (!is_array($profile)) {
      throw new DomainException("Your authenticated profile could not be found.");
    }

    $emailOwner = $privilegedPdo->prepare("
      SELECT id
      FROM users
      WHERE LOWER(email) = LOWER(:email)
        AND id <> :id
      LIMIT 1
    ");
    $emailOwner->execute([
      ":email" => $email,
      ":id" => $user_id,
    ]);
    if ($emailOwner->fetchColumn()) {
      throw new DomainException("Email is already used.");
    }

    $changingPass = ($new_password !== "" || $confirm_password !== "");
    if ($changingPass) {
      if ($new_password !== $confirm_password) {
        throw new DomainException("New password and confirm password do not match.");
      }
      $passwordError = servitech_password_validation_error($new_password);
      if ($passwordError !== "") {
        throw new DomainException($passwordError);
      }
      if ($current_password === "") {
        throw new DomainException("Current password is required.");
      }
      $reauth = servitech_supabase_sign_in((string)$profile["email"], $current_password);
      servitech_supabase_store_auth_session($reauth);
    }

    $authUpdates = [];
    if (strcasecmp((string)$profile["email"], $email) !== 0) {
      $authUpdates["email"] = strtolower($email);
    }
    if ($changingPass) {
      $authUpdates["password"] = $new_password;
    }
    $emailChangePending = false;
    if ($authUpdates) {
      $updatedAuth = servitech_supabase_update_user(
        (string)$_SESSION["supabase_access_token"],
        $authUpdates
      );
      $returnedEmail = strtolower(trim((string)($updatedAuth["email"] ?? $profile["email"])));
      if (isset($authUpdates["email"]) && $returnedEmail !== strtolower($email)) {
        $emailChangePending = true;
      }
    }

    $update = $pdo->prepare("
      UPDATE users
      SET fullname = :fullname,
          email = :email,
          contact = :contact,
          updated_at = NOW()
      WHERE id = :id
    ");
    $update->execute([
      ":fullname" => $fullname,
      // Keep the current public profile email until Supabase confirms the new
      // address. The auth.users synchronization trigger updates it afterward.
      ":email" => $emailChangePending ? strtolower((string)$profile["email"]) : strtolower($email),
      ":contact" => $contact !== "" ? $contact : null,
      ":id" => $user_id,
    ]);

    $successMessage = $emailChangePending
      ? "Profile updated. Check both email addresses to confirm the email change."
      : "Profile updated!";
    header("Location: /pages/customer/custo_edit_profile.php?ok=" . urlencode($successMessage));
    exit();
  } catch (DomainException $e) {
    header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode($e->getMessage()));
    exit();
  } catch (Throwable $e) {
    error_log("Supabase profile_update error: " . $e->getMessage());
    header("Location: /pages/customer/custo_edit_profile.php?err=" . urlencode("Unable to update your profile right now."));
    exit();
  }
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

