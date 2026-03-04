<?php
require_once __DIR__ . "/session_check.php";
require_once __DIR__ . "/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  header("Location: log_in.html");
  exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: custo_edit_profile.php");
  exit();
}

$fullname = trim((string)($_POST["fullname"] ?? ""));
$email    = trim((string)($_POST["email"] ?? ""));
$contact  = trim((string)($_POST["contacts"] ?? ""));

$current_password = (string)($_POST["current_password"] ?? "");
$new_password     = (string)($_POST["new_password"] ?? "");
$confirm_password = (string)($_POST["confirm_password"] ?? "");

if ($fullname === "" || $email === "") {
  header("Location: custo_edit_profile.php?err=" . urlencode("Full name and email are required."));
  exit();
}

try {
  // email uniqueness
  $chk = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1");
  $chk->execute([":email" => $email, ":id" => $user_id]);
  if ($chk->fetch()) {
    header("Location: custo_edit_profile.php?err=" . urlencode("Email is already used."));
    exit();
  }

  $changingPass = ($new_password !== "" || $confirm_password !== "");

  if ($changingPass) {
    if ($new_password !== $confirm_password) {
      header("Location: custo_edit_profile.php?err=" . urlencode("New password and confirm password do not match."));
      exit();
    }
    if (strlen($new_password) < 6) {
      header("Location: custo_edit_profile.php?err=" . urlencode("New password must be at least 6 characters."));
      exit();
    }

    $p = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
    $p->execute([":id" => $user_id]);
    $row = $p->fetch();

    if (!$row || !password_verify($current_password, $row["password_hash"])) {
      header("Location: custo_edit_profile.php?err=" . urlencode("Current password is incorrect."));
      exit();
    }

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);

    $upd = $pdo->prepare("
      UPDATE users
      SET fullname = :fullname, email = :email, contact = :contact, password_hash = :password_hash
      WHERE id = :id
    ");
    $upd->execute([
      ":fullname" => $fullname,
      ":email" => $email,
      ":contact" => ($contact === "" ? null : $contact),
      ":password_hash" => $hashed,
      ":id" => $user_id
    ]);

  } else {
    $upd = $pdo->prepare("
      UPDATE users
      SET fullname = :fullname, email = :email, contact = :contact
      WHERE id = :id
    ");
    $upd->execute([
      ":fullname" => $fullname,
      ":email" => $email,
      ":contact" => ($contact === "" ? null : $contact),
      ":id" => $user_id
    ]);
  }

  header("Location: custo_edit_profile.php?ok=" . urlencode("Profile updated!"));
  exit();

} catch (PDOException $e) {
  error_log("profile_update error: " . $e->getMessage());
  header("Location: custo_edit_profile.php?err=" . urlencode("DB error updating profile."));
  exit();
}