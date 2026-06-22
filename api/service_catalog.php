<?php

function servitech_catalog_slug(string $value): string {
  $slug = strtolower(trim($value));
  $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
  $slug = trim(is_string($slug) ? $slug : '', '_');
  return $slug !== '' ? $slug : 'option';
}

function servitech_catalog_bool_param($value): string {
  if (is_string($value)) {
    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) return 'true';
    if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) return 'false';
  }
  return !empty($value) ? 'true' : 'false';
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

function servitech_catalog_service_kind(array $service): string {
  $category = strtolower(trim((string)($service["category"] ?? "")));
  $name = strtolower(trim((string)($service["name"] ?? "")));
  if ($category === "printing" && str_contains($name, "document") && str_contains($name, "print")) return "document_printing";
  if ($category === "printing" && (str_contains($name, "photocopy") || str_contains($name, "xerox"))) return "photocopy";
  if ($category === "printing" && str_contains($name, "rush") && str_contains($name, "id")) return "rush_id";
  if ($category === "printing" && str_contains($name, "laminat")) return "laminating";
  if ($category === "printing" && str_contains($name, "scan")) return "scanning";
  if ($category === "repair") return "repair";
  if ($category === "installation") return "installation";
  return "";
}

function servitech_catalog_service_dedupe_score(array $service): int {
  $kind = servitech_catalog_service_kind($service);
  $name = strtolower(trim((string)($service["name"] ?? "")));
  $score = !empty($service["active"]) ? 10 : 0;

  if ($kind === "photocopy" && str_contains($name, "photocopy")) return $score + 100;
  if ($kind === "document_printing" && str_contains($name, "document") && str_contains($name, "print")) return $score + 100;
  if ($kind === "rush_id" && str_contains($name, "rush") && str_contains($name, "id")) return $score + 100;
  if ($kind === "laminating" && str_contains($name, "laminat")) return $score + 100;
  if ($kind === "scanning" && str_contains($name, "scan")) return $score + 100;

  if ($kind === "repair") {
    if (in_array($name, ["device repair", "repair services", "repair"], true)) return $score + 300;
    if (str_contains($name, "device") && str_contains($name, "repair")) return $score + 250;
    if (str_contains($name, "repair services")) return $score + 240;
    return $score - 100;
  }

  if ($kind === "installation") {
    if (in_array($name, ["installation services", "installation"], true)) return $score + 300;
    if (str_contains($name, "installation services")) return $score + 250;
    if (str_contains($name, "installation") && !str_contains($name, "windows")) return $score + 150;
    return $score - 100;
  }

  return $score;
}

function servitech_catalog_dedupe_services(array $services, bool $keepUnsupported = true): array {
  $unsupported = [];
  $byKind = [];
  $kindOrder = [];
  foreach ($services as $index => $service) {
    if (!is_array($service)) continue;
    $kind = servitech_catalog_service_kind($service);
    if ($kind === "") {
      if ($keepUnsupported) $unsupported[] = $service;
      continue;
    }
    $score = servitech_catalog_service_dedupe_score($service);
    if (!isset($byKind[$kind])) {
      $kindOrder[] = $kind;
      $byKind[$kind] = ["service" => $service, "score" => $score, "index" => $index];
      continue;
    }
    if ($score > $byKind[$kind]["score"]) {
      $byKind[$kind] = ["service" => $service, "score" => $score, "index" => $index];
    }
  }

  $result = [];
  foreach ($kindOrder as $kind) {
    $result[] = $byKind[$kind]["service"];
  }
  return array_merge($result, $unsupported);
}

function servitech_catalog_group_contract(string $kind): array {
  return match ($kind) {
    "document_printing", "photocopy" => [
      "paper_size" => "Paper Size",
      "color_option" => "Color Option",
    ],
    "rush_id" => [
      "package" => "Package",
      "addon" => "Add-Ons",
    ],
    "laminating" => ["lamination_type" => "Type"],
    "scanning" => ["paper_size" => "Paper Size"],
    "repair" => [
      "device_type" => "Devices",
      "repair_type" => "Service Type",
    ],
    "installation" => [
      "installation_type" => "Installation Type",
      "device_type" => "Devices",
    ],
    default => [],
  };
}

function servitech_catalog_expected_rule_groups(string $kind, array $keys): bool {
  $groups = array_values(array_unique(array_map("strval", array_keys($keys))));
  sort($groups);
  $allowed = match ($kind) {
    "document_printing", "photocopy" => [["color_option", "paper_size"]],
    "rush_id" => [["addon"], ["package"]],
    "laminating" => [["lamination_type"]],
    "scanning" => [["paper_size"]],
    "repair" => [["device_type", "repair_type"]],
    "installation" => [["installation_type"], ["device_type", "installation_type"]],
    default => [],
  };
  return in_array($groups, $allowed, true);
}

function servitech_catalog_normalize_admin_payload(array $service, array $catalog): array {
  $kind = servitech_catalog_service_kind($service);
  $contract = servitech_catalog_group_contract($kind);
  if (!$contract) throw new DomainException("This service does not support catalog editing.");

  $submittedGroups = isset($catalog["groups"]) && is_array($catalog["groups"]) ? $catalog["groups"] : [];
  $groupsByKey = [];
  foreach ($submittedGroups as $group) {
    $key = servitech_catalog_slug((string)($group["group_key"] ?? ""));
    if (!isset($contract[$key])) throw new DomainException("Unsupported option group for this service.");
    $group["group_key"] = $key;
    $group["name"] = $contract[$key];
    $group["active"] = ($kind === "installation" && $key === "device_type")
      ? (!empty($group["active"]) ? 1 : 0)
      : 1;
    $group["sort_order"] = array_search($key, array_keys($contract), true);
    $groupsByKey[$key] = $group;
  }
  foreach ($contract as $key => $name) {
    if (!isset($groupsByKey[$key])) {
      $groupsByKey[$key] = [
        "group_key" => $key,
        "name" => $name,
        "active" => ($kind === "installation" && $key === "device_type") ? 0 : 1,
        "sort_order" => array_search($key, array_keys($contract), true),
        "values" => [],
      ];
    }
  }

  $submittedRules = isset($catalog["rules"]) && is_array($catalog["rules"]) ? $catalog["rules"] : [];
  $rules = [];
  $seenCombinations = [];
  foreach ($submittedRules as $rule) {
    $keys = isset($rule["option_value_keys"]) && is_array($rule["option_value_keys"])
      ? $rule["option_value_keys"]
      : [];
    ksort($keys);
    $signature = json_encode($keys);
    if ($signature === false || isset($seenCombinations[$signature])) continue;
    $seenCombinations[$signature] = true;
    $rule["option_value_keys"] = $keys;
    $rules[] = $rule;
  }
  foreach ($rules as &$rule) {
    $keys = isset($rule["option_value_keys"]) && is_array($rule["option_value_keys"])
      ? $rule["option_value_keys"]
      : [];
    $usesInactiveOption = false;
    foreach (array_keys($keys) as $key) {
      if (!isset($contract[$key])) throw new DomainException("A pricing rule uses an unsupported option group.");
    }
    foreach ($keys as $key => $valueKey) {
      $validValue = false;
      foreach ($groupsByKey[$key]["values"] ?? [] as $value) {
        if ((string)($value["value_key"] ?? "") === (string)$valueKey) {
          $validValue = true;
          if (empty($groupsByKey[$key]["active"]) || empty($value["active"]) || trim((string)($value["label"] ?? "")) === "") {
            $usesInactiveOption = true;
          }
          break;
        }
      }
      if (!$validValue) throw new DomainException("A pricing rule references an unavailable option value.");
    }
    if (!servitech_catalog_expected_rule_groups($kind, $keys)) {
      throw new DomainException("A pricing rule does not match this service's pricing structure.");
    }
    $priceType = ($rule["price_type"] ?? "fixed") === "assessment" ? "assessment" : "fixed";
    $price = $rule["price"] ?? null;
    $rule["active"] = (!empty($rule["active"]) && !$usesInactiveOption) ? 1 : 0;
    if (!empty($rule["active"]) && $priceType === "fixed" && ($price === "" || $price === null || !is_numeric($price))) {
      throw new DomainException("Every active fixed-price option must have a valid price.");
    }
    if (is_numeric($price) && (float)$price < 0) {
      throw new DomainException("Prices cannot be negative.");
    }
    $rule["price_type"] = $priceType;
  }
  unset($rule);

  $activeValues = static function (string $groupKey) use (&$groupsByKey): array {
    return array_values(array_filter(
      $groupsByKey[$groupKey]["values"] ?? [],
      static fn($value) => !empty($value["active"]) && trim((string)($value["label"] ?? "")) !== ""
    ));
  };
  $ensureRule = static function (array $keys, string $label, int $sortOrder) use (&$rules, &$seenCombinations): void {
    ksort($keys);
    $signature = json_encode($keys);
    if ($signature === false || isset($seenCombinations[$signature])) return;
    $seenCombinations[$signature] = true;
    $rules[] = [
      "rule_key" => servitech_catalog_slug(implode("__", array_map(
        static fn($key, $value) => $key . "_" . $value,
        array_keys($keys),
        array_values($keys)
      ))),
      "option_value_keys" => $keys,
      "label" => $label,
      "description" => "",
      "price" => null,
      "price_type" => "assessment",
      "active" => 1,
      "sort_order" => $sortOrder,
    ];
  };

  if (in_array($kind, ["document_printing", "photocopy"], true)) {
    $order = count($rules);
    foreach ($activeValues("paper_size") as $paper) {
      foreach ($activeValues("color_option") as $color) {
        $ensureRule(
          ["paper_size" => (string)$paper["value_key"], "color_option" => (string)$color["value_key"]],
          trim((string)$paper["label"]) . " / " . trim((string)$color["label"]),
          $order++
        );
      }
    }
  } elseif (in_array($kind, ["rush_id", "laminating", "scanning"], true)
    || ($kind === "installation" && empty($groupsByKey["device_type"]["active"]))) {
    $simpleGroups = match ($kind) {
      "rush_id" => ["package", "addon"],
      "laminating" => ["lamination_type"],
      "scanning" => ["paper_size"],
      "installation" => ["installation_type"],
      default => [],
    };
    $order = count($rules);
    foreach ($simpleGroups as $groupKey) {
      foreach ($activeValues($groupKey) as $value) {
        $ensureRule(
          [$groupKey => (string)$value["value_key"]],
          trim((string)$value["label"]),
          $order++
        );
      }
    }
  }

  if ($kind === "installation" && !empty($groupsByKey["device_type"]["active"])) {
    $hasActiveDeviceRule = false;
    foreach ($rules as $rule) {
      if (!empty($rule["active"])
        && isset($rule["option_value_keys"]["device_type"], $rule["option_value_keys"]["installation_type"])) {
        $hasActiveDeviceRule = true;
        break;
      }
    }
    if (!$hasActiveDeviceRule) {
      throw new DomainException("Add at least one active installation service under a device before enabling Device Category.");
    }
  }

  return [
    "groups" => array_values($groupsByKey),
    "rules" => $rules,
  ];
}

function servitech_catalog_fetch_service(PDO $pdo, int $serviceId, bool $activeOnly = true): ?array {
  if ($serviceId <= 0) return null;
  $activeSql = $activeOnly ? "AND active = TRUE" : "";
  $stmt = $pdo->prepare("
    SELECT id, category, name, description,
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
  $activeSql = $activeOnly ? "AND active = TRUE" : "";
  $where = match ($kind) {
    'document_printing' => "category = 'printing' AND LOWER(name) LIKE '%document%' AND (LOWER(name) LIKE '%printing%' OR LOWER(name) LIKE '%print%')",
    'photocopy' => "category = 'printing' AND (LOWER(name) LIKE '%photocopy%' OR LOWER(name) LIKE '%xerox%')",
    'rush_id' => "category = 'printing' AND LOWER(name) LIKE '%rush%' AND LOWER(name) LIKE '%id%'",
    'laminating' => "category = 'printing' AND LOWER(name) LIKE '%laminat%'",
    'scanning' => "category = 'printing' AND LOWER(name) LIKE '%scan%'",
    'repair' => "category = 'repair' AND (LOWER(name) LIKE '%repair%' OR LOWER(name) LIKE '%device%')",
    'installation' => "category = 'installation' AND (LOWER(name) LIKE '%installation%' OR LOWER(name) LIKE '%software%')",
    default => "1 = 0",
  };
  $stmt = $pdo->prepare("
    SELECT id, category, name, description,
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

  $activeGroupSql = $activeOnly ? "AND g.active = TRUE" : "";
  $activeValueSql = $activeOnly
    ? "AND v.active = TRUE AND g.active = TRUE"
    : "";
  $activeRuleSql = $activeOnly ? "AND r.active = TRUE" : "";

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
    $missingActiveOption = false;
    foreach ($ids as $groupKey => $valueId) {
      $value = $valueLookup[(int)$valueId] ?? null;
      if ($value) {
        $rule["option_labels"][$groupKey] = (string)$value["label"];
        $rule["option_value_keys"][$groupKey] = (string)$value["value_key"];
      } elseif ($activeOnly) {
        $missingActiveOption = true;
      }
    }
    $rule["_missing_active_option"] = $missingActiveOption;
  }
  unset($rule);
  if ($activeOnly) {
    $rules = array_values(array_filter($rules, static fn($rule) => empty($rule["_missing_active_option"])));
  }
  foreach ($rules as &$rule) {
    unset($rule["_missing_active_option"]);
  }
  unset($rule);

  if ($activeOnly && servitech_catalog_service_kind($service) === "installation") {
    $deviceMode = false;
    foreach ($groups as $group) {
      if (($group["group_key"] ?? "") === "device_type") {
        $deviceMode = true;
        break;
      }
    }
    $rules = array_values(array_filter($rules, static function ($rule) use ($deviceMode): bool {
      $hasDevice = isset($rule["option_value_keys"]["device_type"]);
      return $deviceMode ? $hasDevice : !$hasDevice;
    }));
  }

  $rangeRules = $rules;
  if (servitech_catalog_service_kind($service) === "rush_id") {
    $rangeRules = array_values(array_filter($rules, static fn($rule) => isset($rule["option_value_keys"]["package"])));
  }
  $service["catalog_price_range"] = servitech_catalog_price_range_from_rules($rangeRules);
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

function servitech_catalog_values_used_by_rules(array $values, array $rules, string $groupKey): array {
  $usedIds = [];
  foreach ($rules as $rule) {
    $valueId = (int)($rule["option_value_ids"][$groupKey] ?? 0);
    if ($valueId > 0) $usedIds[$valueId] = true;
  }
  return array_values(array_filter(
    $values,
    static fn($value) => isset($usedIds[(int)($value["id"] ?? 0)])
  ));
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
  $submittedRuleKeys = [];

  $groupStmt = $pdo->prepare("
    INSERT INTO service_option_groups (service_id, group_key, name, active, sort_order)
    VALUES (:service_id, :group_key, :name, CAST(:active AS boolean), :sort_order)
    ON CONFLICT (service_id, group_key)
    DO UPDATE SET name = EXCLUDED.name, active = EXCLUDED.active, sort_order = EXCLUDED.sort_order,
                  updated_at = NOW()
    RETURNING id
  ");
  $valueStmt = $pdo->prepare("
    INSERT INTO service_option_values (group_id, value_key, label, description, active, sort_order)
    VALUES (:group_id, :value_key, :label, :description, CAST(:active AS boolean), :sort_order)
    ON CONFLICT (group_id, value_key)
    DO UPDATE SET label = EXCLUDED.label, description = EXCLUDED.description, active = EXCLUDED.active,
                  sort_order = EXCLUDED.sort_order,
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
      ":active" => servitech_catalog_bool_param($group["active"] ?? false),
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
        ":active" => servitech_catalog_bool_param($value["active"] ?? false),
        ":sort_order" => (int)($value["sort_order"] ?? $valueIndex),
      ]);
      $valueIds[$groupKey][$valueKey] = (int)$valueStmt->fetchColumn();
    }
  }

  $ruleStmt = $pdo->prepare("
    INSERT INTO service_pricing_rules (service_id, rule_key, option_value_ids, label, description, price, price_type, active, sort_order)
    VALUES (:service_id, :rule_key, CAST(:option_value_ids AS jsonb), :label, :description, :price, :price_type, CAST(:active AS boolean), :sort_order)
    ON CONFLICT (service_id, rule_key)
    DO UPDATE SET option_value_ids = EXCLUDED.option_value_ids, label = EXCLUDED.label,
                  description = EXCLUDED.description, price = EXCLUDED.price, price_type = EXCLUDED.price_type,
                  active = EXCLUDED.active, sort_order = EXCLUDED.sort_order,
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
      ":active" => servitech_catalog_bool_param($rule["active"] ?? false),
      ":sort_order" => (int)($rule["sort_order"] ?? $ruleIndex),
    ]);
    $submittedRuleKeys[$ruleKey] = true;
  }

  $existingRulesStmt = $pdo->prepare("SELECT rule_key FROM service_pricing_rules WHERE service_id = :service_id");
  $existingRulesStmt->execute([":service_id" => $serviceId]);
  $deactivateRuleStmt = $pdo->prepare("
    UPDATE service_pricing_rules
    SET active = FALSE, updated_at = NOW()
    WHERE service_id = :service_id AND rule_key = :rule_key
  ");
  foreach ($existingRulesStmt->fetchAll(PDO::FETCH_COLUMN) as $existingRuleKey) {
    if (!isset($submittedRuleKeys[(string)$existingRuleKey])) {
      $deactivateRuleStmt->execute([":service_id" => $serviceId, ":rule_key" => (string)$existingRuleKey]);
    }
  }
}
