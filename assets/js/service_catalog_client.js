(function (root, factory) {
  const api = factory();
  if (typeof module === "object" && module.exports) module.exports = api;
  if (root) root.ServitechCatalogClient = api;
})(typeof globalThis !== "undefined" ? globalThis : this, function () {
  "use strict";

  function positiveId(value) {
    const id = Number(value);
    return Number.isInteger(id) && id > 0 ? id : 0;
  }

  function fromOption(option, groupKey) {
    if (!option || option.disabled || !groupKey) return null;
    return {
      group_key: String(groupKey),
      value_id: positiveId(option.dataset?.valueId),
      value_key: String(option.dataset?.valueKey || option.value || ""),
      label: String(option.textContent || option.value || "").trim(),
    };
  }

  function fromSelect(select, groupKey) {
    if (!select || select.selectedIndex < 0) return null;
    return fromOption(select.options[select.selectedIndex], groupKey);
  }

  function fromChecked(name, groupKey, documentRef) {
    const doc = documentRef || (typeof document !== "undefined" ? document : null);
    const input = doc?.querySelector?.(`input[name="${name}"]:checked`);
    if (!input || !groupKey) return null;
    return {
      group_key: String(groupKey),
      value_id: positiveId(input.dataset?.valueId),
      value_key: String(input.dataset?.valueKey || input.value || ""),
      label: String(input.dataset?.label || input.value || "").trim(),
    };
  }

  function selectionMap(items) {
    return (items || []).reduce((result, item) => {
      if (item?.group_key && (item.value_id || item.value_key)) result[item.group_key] = item;
      return result;
    }, {});
  }

  function optionIdMap(selection) {
    return Object.entries(selection || {}).reduce((result, [groupKey, item]) => {
      const id = positiveId(item?.value_id);
      if (id) result[groupKey] = id;
      return result;
    }, {});
  }

  function ruleMatches(rule, selection, exact) {
    if (!rule || Number(rule.active) === 0) return false;
    const entries = Object.entries(selection || {}).filter(([, item]) => item?.value_id || item?.value_key);
    if (!entries.length) return false;
    const ruleIds = rule.option_value_ids || {};
    const ruleKeys = rule.option_value_keys || {};
    if (exact && Object.keys(ruleIds).length !== entries.length && Object.keys(ruleKeys).length !== entries.length) return false;

    return entries.every(([groupKey, item]) => {
      const selectedId = positiveId(item?.value_id);
      const ruleId = positiveId(ruleIds[groupKey]);
      if (selectedId && ruleId) return selectedId === ruleId;
      return String(item?.value_key || "") !== ""
        && String(ruleKeys[groupKey] || "") === String(item.value_key);
    });
  }

  function findRule(rules, selection) {
    return (Array.isArray(rules) ? rules : []).find((rule) => ruleMatches(rule, selection, true)) || null;
  }

  function debugUnavailable(serviceName, selection, rules) {
    if (typeof console === "undefined" || typeof console.warn !== "function") return;
    console.warn("[ServiTech service catalog] Active pricing rule not found.", {
      service: serviceName || "Service",
      selected_option_ids: optionIdMap(selection),
      selected_option_keys: Object.fromEntries(Object.entries(selection || {}).map(([key, item]) => [key, item?.value_key || ""])),
      selected_labels: Object.fromEntries(Object.entries(selection || {}).map(([key, item]) => [key, item?.label || ""])),
      active_rule_count: (Array.isArray(rules) ? rules : []).filter((rule) => Number(rule?.active) !== 0).length,
    });
  }

  return {
    findRule,
    fromChecked,
    fromOption,
    fromSelect,
    optionIdMap,
    ruleMatches,
    selectionMap,
    debugUnavailable,
  };
});
