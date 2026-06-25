<?php

function admin_customer_display_code(int $sequence): string
{
    $sequence = max(0, $sequence);
    return "C-" . str_pad((string)$sequence, 3, "0", STR_PAD_LEFT);
}

function admin_customer_display_code_for_customer_id(PDO $pdo, int $customerId): string
{
    if ($customerId <= 0) {
        return admin_customer_display_code(0);
    }

    $customerScopeSql = admin_auth_backed_customer_scope_sql();
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      {$customerScopeSql}
        AND users.id <= :customer_id
    ");
    $stmt->execute([":customer_id" => $customerId]);

    return admin_customer_display_code((int)$stmt->fetchColumn());
}
