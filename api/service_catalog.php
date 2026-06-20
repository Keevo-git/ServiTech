<?php

function servitech_catalog_slug(string $value): string {
  $slug = strtolower(trim($value));
  $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
  $slug = trim(is_string($slug) ? $slug : '', '_');
  return $slug !== '' ? $slug : 'option';
}

function servitech_catalog_money_label($value): string {
  if ($value === null || $value === '' || !is_numeric($value)) {
    return 'For assessment';
  }
  return 'PHP ' . number_format(max(0, (float)$value), 2);
}

function servitech_catalog_price_range_from_rules(array $rules): string {
  $prices = [];
  foreach ($rules as $rule) {
    if (($rule['price_type'] ?? '') === 'fixed' && isset($rule['price']) && is_numeric($rule['price'])) {
      $prices[] = max(0, (float)$rule['price']);
    }
  }
  if (!$prices) return 'For assessment';
  sort($prices, SORT_NUMERIC);
  $low = $prices[0];
  $high = $prices[count($prices) - 1];
  if (abs($low - $high) < 0.01) return servitech_catalog_money_label($low);
  return servitech_catalog_money_label($low) . ' - ' . servitech_catalog_money_label($high);
}

function servitech_catalog_fetch_service(PDO $pdo, int $serviceId, bool $activeOnly = true): ?array {
  if ($serviceId <= 0) return null;
  $activeSql = $activeOnly ? "AND active = TRUE AND archived_at IS NULL" : "";
  $stmt = $pdo->prepare("
    SELECT id, category, name, description, price, price_range, pricing_json::text AS pricing_json,
           CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
    FROM services
    WHERE id = :id {$activeSql}
    LIMIT 1
  ");
  $stmt->execute([":id" => $serviceId]);
  $service = $stmt->fetch(PDO::FETCH_ASSOC);
  return is_array($service) ? $service : null;
}

function servitech_catalog_fetch_service_by_kind(PDO $pdo, string $kind, bool $activeOnly = true): ?array {
  $activeSql = $activeOnly ? "AND active = TRUE AND archived_at IS NULL" : "";
  $where = match ($kind) {
    'document_printing' => "category = 'printing' AND LOWER(name) LIKE '%document%' AND (LOWER(name) LIKE '%printing%' OR LOWER(name) LIKE '%print%')",
    'photocopy' => "category = 'printing' AND (LOWER(name) LIKE '%photocopy%' OR LOWER(name) LIKE '%xerox%')",
    'rush_id' => "category = 'printing' AND LOWER(name) LIKE '%rush%' AND LOWER(name) LIKE '%id%'",
    'laminating' => "category = 'printing' AND LOWER(name) LIKE '%laminat%'",
    'repair' => "category = 'repair' AND (LOWER(name) LIKE '%repair%' OR LOWER(name) LIKE '%device%')",
    'installation' => "category = 'installation' AND (LOWER(name) LIKE '%installation%' OR LOWER(name) LIKE '%software%')",
    default => "1 = 0",
  };
  $stmt = $pdo->prepare("
    SELECT id, category, name, description, price, price_range, pricing_json::text AS pricing_json,
           CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
    FROM services
    WHERE {$where} {$activeSql}
    ORDER BY sort_order ASC, id ASC
    LIMIT 1
  ");
  $stmt->execute();
  $service = $stmt->fetch(PDO::FETCH_ASSOC);
  return is_array($service) ? $service : null;
}

function servitech_catalog_fetch(PDO $pdo, int $serviceId, bool $activeOnly = true): array {
  $service = servitech_catalog_fetch_service($pdo, $serviceId, $activeOnly);
  if (!$service) {
    throw new DomainException("Service not found.");
  }

  $activeGroupSql = $activeOnly ? "AND g.active = TRUE AND g.archived_at IS NULL" : "";
  $activeValueSql = $activeOnly ? "AND v.active = TRUE AND v.archived_at IS NULL" : "";
  $activeRuleSql = $activeOnly ? "AND r.active = TRUE AND r.archived_at IS NULL" : "";

  $groupsStmt = $pdo->prepare("
    SELECT g.id, g.service_id, g.group_key, g.name, CASE WHEN g.active THEN 1 ELSE 0 END AS active, g.sort_order
    FROM service_option_groups g
    WHERE g.service_id = :service_id {$activeGroupSql}
    ORDER BY g.sort_order ASC, g.id ASC
  ");
  $groupsStmt->execute([":service_id" => $serviceId]);
  $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

  $valuesStmt = $pdo->prepare("
    SELECT v.id, v.group_id, g.group_key, v.value_key, v.label, v.description,
           CASE WHEN v.active THEN 1 ELSE 0 END AS active, v.sort_order
    FROM service_option_values v
    JOIN service_option_groups g ON g.id = v.group_id
    WHERE g.service_id = :service_id {$activeValueSql}
    ORDER BY g.sort_order ASC, v.sort_order ASC, v.id ASC
  ");
  $valuesStmt->execute([":service_id" => $serviceId]);
  $values = $valuesStmt->fetchAll(PDO::FETCH_ASSOC);

  $valuesByGroup = [];
  $valueLookup = [];
  foreach ($values as $value) {
    $groupKey = (string)$value["group_key"];
    $valuesByGroup[$groupKey][] = $value;
    $valueLookup[(int)$value["id"]] = $value;
  }

  foreach ($groups as &$group) {
    $group["values"] = $valuesByGroup[(string)$group["group_key"]] ?? [];
  }
  unset($group);

  $rulesStmt = $pdo->prepare("
    SELECT id, service_id, rule_key, option_value_ids::text AS option_value_ids, label, description,
           price, price_type, CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
    FROM service_pricing_rules r
    WHERE r.service_id = :service_id {$activeRuleSql}
    ORDER BY r.sort_order ASC, r.id ASC
  ");
  $rulesStmt->execute([":service_id" => $serviceId]);
  $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rules as &$rule) {
    $ids = json_decode((string)($rule["option_value_ids"] ?? "{}"), true);
    $ids = is_array($ids) ? $ids : [];
    $rule["option_value_ids"] = $ids;
    $rule["option_labels"] = [];
    $rule["option_value_keys"] = [];
    foreach ($ids as $groupKey => $valueId) {
      $value = $valueLookup[(int)$valueId] ?? null;
      if ($value) {
        $rule["option_labels"][$groupKey] = (string)$value["label"];
        $rule["option_value_keys"][$groupKey] = (string)$value["value_key"];
      }
    }
  }
  unset($rule);

  $service["catalog_price_range"] = servitech_catalog_price_range_from_rules($rules);
  return [
    "service" => $service,
    "groups" => $groups,
    "rules" => $rules,
  ];
}

function servitech_catalog_find_rule(array $catalog, int $ruleId): ?array {
  foreach ($catalog["rules"] ?? [] as $rule) {
    if ((int)($rule["id"] ?? 0) === $ruleId) return $rule;
  }
  return null;
}

function servitech_catalog_option_label(array $rule, string $groupKey): string {
  return trim((string)($rule["option_labels"][$groupKey] ?? ""));
}

function servitech_catalog_rule_display_label(array $rule): string {
  $label = trim((string)($rule["label"] ?? ""));
  if ($label !== "") return $label;
  $labels = array_filter(array_values($rule["option_labels"] ?? []), static fn($item) => trim((string)$item) !== "");
  return $labels ? implode(" / ", $labels) : "Selected option";
}

function servitech_catalog_upsert(PDO $pdo, int $serviceId, array $catalog): void {
  $groups = isset($catalog["groups"]) && is_array($catalog["groups"]) ? $catalog["groups"] : [];
  $rules = isset($catalog["rules"]) && is_array($catalog["rules"]) ? $catalog["rules"] : [];
  $groupIds = [];
  $valueIds = [];

  $groupStmt = $pdo->prepare("
    INSERT INTO service_option_groups (service_id, group_key, name, active, sort_order)
    VALUES (:service_id, :group_key, :name, :active, :sort_order)
    ON CONFLICT (service_id, group_key)
    DO UPDATE SET name = EXCLUDED.name, active = EXCLUDED.active, sort_order = EXCLUDED.sort_order,
                  archived_at = CASE WHEN EXCLUDED.active THEN NULL ELSE COALESCE(service_option_groups.archived_at, NOW()) END,
                  updated_at = NOW()
    RETURNING id
  ");
  $valueStmt = $pdo->prepare("
    INSERT INTO service_option_values (group_id, value_key, label, description, active, sort_order)
    VALUES (:group_id, :value_key, :label, :description, :active, :sort_order)
    ON CONFLICT (group_id, value_key)
    DO UPDATE SET label = EXCLUDED.label, description = EXCLUDED.description, active = EXCLUDED.active,
                  sort_order = EXCLUDED.sort_order,
                  archived_at = CASE WHEN EXCLUDED.active THEN NULL ELSE COALESCE(service_option_values.archived_at, NOW()) END,
                  updated_at = NOW()
    RETURNING id
  ");

  foreach ($groups as $groupIndex => $group) {
    $groupKey = servitech_catalog_slug((string)($group["group_key"] ?? $group["name"] ?? "group_" . $groupIndex));
    $groupName = trim((string)($group["name"] ?? $groupKey));
    if ($groupName === "") $groupName = $groupKey;
    $groupStmt->execute([
      ":service_id" => $serviceId,
      ":group_key" => $groupKey,
      ":name" => $groupName,
      ":active" => !empty($group["active"]),
      ":sort_order" => (int)($group["sort_order"] ?? $groupIndex),
    ]);
    $groupId = (int)$groupStmt->fetchColumn();
    $groupIds[$groupKey] = $groupId;

    $values = isset($group["values"]) && is_array($group["values"]) ? $group["values"] : [];
    foreach ($values as $valueIndex => $value) {
      $label = trim((string)($value["label"] ?? ""));
      if ($label === "") continue;
      $valueKey = servitech_catalog_slug((string)($value["value_key"] ?? $label));
      $valueStmt->execute([
        ":group_id" => $groupId,
        ":value_key" => $valueKey,
        ":label" => $label,
        ":description" => trim((string)($value["description"] ?? "")),
        ":active" => !empty($value["active"]),
        ":sort_order" => (int)($value["sort_order"] ?? $valueIndex),
      ]);
      $valueIds[$groupKey][$valueKey] = (int)$valueStmt->fetchColumn();
    }
  }

  $ruleStmt = $pdo->prepare("
    INSERT INTO service_pricing_rules (service_id, rule_key, option_value_ids, label, description, price, price_type, active, sort_order)
    VALUES (:service_id, :rule_key, CAST(:option_value_ids AS jsonb), :label, :description, :price, :price_type, :active, :sort_order)
    ON CONFLICT (service_id, rule_key)
    DO UPDATE SET option_value_ids = EXCLUDED.option_value_ids, label = EXCLUDED.label,
                  description = EXCLUDED.description, price = EXCLUDED.price, price_type = EXCLUDED.price_type,
                  active = EXCLUDED.active, sort_order = EXCLUDED.sort_order,
                  archived_at = CASE WHEN EXCLUDED.active THEN NULL ELSE COALESCE(service_pricing_rules.archived_at, NOW()) END,
                  updated_at = NOW()
  ");

  foreach ($rules as $ruleIndex => $rule) {
    $keys = isset($rule["option_value_keys"]) && is_array($rule["option_value_keys"]) ? $rule["option_value_keys"] : [];
    $ids = [];
    $ruleParts = [];
    foreach ($keys as $groupKeyRaw => $valueKeyRaw) {
      $groupKey = servitech_catalog_slug((string)$groupKeyRaw);
      $valueKey = servitech_catalog_slug((string)$valueKeyRaw);
      if (!isset($valueIds[$groupKey][$valueKey])) continue;
      $ids[$groupKey] = $valueIds[$groupKey][$valueKey];
      $ruleParts[] = $groupKey . "_" . $valueKey;
    }
    if (!$ids) continue;
    $ruleKey = servitech_catalog_slug((string)($rule["rule_key"] ?? implode("__", $ruleParts)));
    $priceType = (string)($rule["price_type"] ?? "fixed");
    if (!in_array($priceType, ["fixed", "assessment"], true)) $priceType = "assessment";
    $price = null;
    if ($priceType === "fixed" && isset($rule["price"]) && $rule["price"] !== "" && is_numeric($rule["price"])) {
      $price = max(0, (float)$rule["price"]);
    }
    $ruleStmt->execute([
      ":service_id" => $serviceId,
      ":rule_key" => $ruleKey,
      ":option_value_ids" => json_encode($ids),
      ":label" => trim((string)($rule["label"] ?? "")),
      ":description" => trim((string)($rule["description"] ?? "")),
      ":price" => $price,
      ":price_type" => $priceType,
      ":active" => !empty($rule["active"]),
      ":sort_order" => (int)($rule["sort_order"] ?? $ruleIndex),
    ]);
  }
}
