<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/mail.php";

$check = servitech_check_smtp_config();
$dotenvFiles = servitech_mail_dotenv_files();
$localConfigPath = __DIR__ . "/../../config/mail.local.php";
$smtpPasswordPresent = !empty($check["status"]["SMTP_PASSWORD"]["present"]);

function smtp_diag_relative_path(string $path): string
{
    $root = realpath(__DIR__ . "/../..");
    $realPath = realpath($path);
    if (is_string($root) && is_string($realPath) && strpos($realPath, $root) === 0) {
        return ltrim(str_replace("\\", "/", substr($realPath, strlen($root))), "/");
    }

    return basename($path);
}

servitech_forgot_password_mail_log(
    "Admin SMTP diagnostic viewed by user_id=" . (string)($_SESSION["user_id"] ?? "unknown")
    . "; SMTP_PASSWORD=" . ($smtpPasswordPresent ? "present" : "missing")
);
servitech_log_smtp_config_status($check);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SMTP Diagnostics - ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <style>
    body {
      margin: 0;
      padding: 32px;
      background: #f8f5f2;
      color: #24120f;
      font-family: "Bahnschrift", "Trebuchet MS", "Segoe UI", Arial, sans-serif;
    }

    main {
      max-width: 860px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e7d6cc;
      border-radius: 8px;
      padding: 28px;
    }

    h1 {
      margin: 0 0 8px;
      color: #4a0505;
      font-size: 28px;
    }

    p {
      line-height: 1.5;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    th,
    td {
      border-bottom: 1px solid #eadbd3;
      padding: 12px;
      text-align: left;
    }

    th {
      background: #fff7ed;
      color: #4a0505;
    }

    .status {
      display: inline-block;
      min-width: 76px;
      padding: 5px 9px;
      border-radius: 999px;
      font-weight: 700;
      text-align: center;
    }

    .status-ok {
      background: #e7f8ed;
      color: #176234;
    }

    .status-missing {
      background: #fdecec;
      color: #9f1717;
    }

    .meta {
      margin-top: 22px;
      padding: 14px 16px;
      background: #fbfaf8;
      border: 1px solid #eadbd3;
      border-radius: 8px;
    }

    a {
      color: #7c130d;
      font-weight: 700;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <main>
    <h1>SMTP Diagnostics</h1>
    <p>This admin-only page confirms whether the SMTP password is loaded. Secrets are never printed.</p>

    <table>
      <thead>
        <tr>
          <th>Setting</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>SMTP_PASSWORD</td>
          <td>
            <span class="status <?= $smtpPasswordPresent ? "status-ok" : "status-missing" ?>">
              <?= $smtpPasswordPresent ? "present" : "missing" ?>
            </span>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="meta">
      <p><strong>Overall SMTP config:</strong> <?= $check["ok"] ? "complete" : "incomplete; details were written to the private log" ?></p>
      <p><strong>.env files loaded:</strong>
        <?php if ($dotenvFiles): ?>
          <?= htmlspecialchars(implode(", ", array_map("smtp_diag_relative_path", $dotenvFiles)), ENT_QUOTES, "UTF-8") ?>
        <?php else: ?>
          none detected
        <?php endif; ?>
      </p>
      <p><strong>Fallback config:</strong> <?= is_file($localConfigPath) ? "config/mail.local.php present" : "config/mail.local.php missing" ?></p>
      <p><strong>Private log:</strong> logs/forgot_password_mail.log</p>
      <p><a href="<?= admin_url("/pages/admin/admin_dashboard.php") ?>">Back to dashboard</a></p>
      <p><a href="<?= admin_url("/privacy-policy.php#privacy-settings") ?>" data-privacy-settings-open>Cookie Preferences</a></p>
    </div>
  </main>
  <?php require_once __DIR__ . "/../../components/cookie_consent.php"; ?>
</body>
</html>
