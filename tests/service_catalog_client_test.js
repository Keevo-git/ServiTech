const assert = require("assert");
const catalog = require("../assets/js/service_catalog_client.js");

const rules = [
  {
    id: 301,
    active: 1,
    option_value_ids: { paper_size: 12, color_option: 21 },
    option_value_keys: { paper_size: "letter", color_option: "colored" },
    price: "3.00",
    price_type: "fixed",
  },
  {
    id: 302,
    active: 1,
    option_value_ids: { paper_size: 13, color_option: 21 },
    option_value_keys: { paper_size: "8_5x13", color_option: "colored" },
    price: "5.00",
    price_type: "fixed",
  },
  {
    id: 303,
    active: 0,
    option_value_ids: { paper_size: 13, color_option: 22 },
    option_value_keys: { paper_size: "8_5x13", color_option: "black_and_white" },
    price: "5.00",
    price_type: "fixed",
  },
];

const longColored = catalog.selectionMap([
  { group_key: "paper_size", value_id: 13, value_key: "renamed-paper", label: "8.5 x 13" },
  { group_key: "color_option", value_id: 21, value_key: "renamed-color", label: "Full Color" },
]);

assert.strictEqual(catalog.findRule(rules, longColored)?.id, 302, "8.5x13 + Colored must match by immutable option IDs.");
assert.deepStrictEqual(catalog.optionIdMap(longColored), { paper_size: 13, color_option: 21 });

const inactiveCombination = catalog.selectionMap([
  { group_key: "paper_size", value_id: 13, value_key: "8_5x13", label: "8.5x13" },
  { group_key: "color_option", value_id: 22, value_key: "black_and_white", label: "Black and White" },
]);
assert.strictEqual(catalog.findRule(rules, inactiveCombination), null, "Inactive pricing rules must not be selectable.");

const legacyKeySelection = catalog.selectionMap([
  { group_key: "paper_size", value_id: 0, value_key: "letter", label: "Letter" },
  { group_key: "color_option", value_id: 0, value_key: "colored", label: "Colored" },
]);
assert.strictEqual(catalog.findRule(rules, legacyKeySelection)?.id, 301, "Value keys remain a compatibility fallback.");

function assertMatrix(rows, columns, serviceName) {
  const matrixRules = [];
  rows.forEach((row, rowIndex) => columns.forEach((column, columnIndex) => {
    matrixRules.push({
      id: 1000 + (rowIndex * columns.length) + columnIndex,
      active: 1,
      option_value_ids: { paper_size: row.id, color_option: column.id },
      option_value_keys: { paper_size: row.key, color_option: column.key },
      price: "5.00",
      price_type: "fixed",
    });
  }));
  rows.forEach((row) => columns.forEach((column) => {
    const selection = catalog.selectionMap([
      { group_key: "paper_size", value_id: row.id, value_key: row.key, label: row.key },
      { group_key: "color_option", value_id: column.id, value_key: column.key, label: column.key },
    ]);
    assert.ok(catalog.findRule(matrixRules, selection), `${serviceName}: ${row.key} + ${column.key} must resolve.`);
  }));
}

assertMatrix(
  [{ id: 1, key: "letter" }, { id: 2, key: "8_5x13" }, { id: 3, key: "a4" }],
  [{ id: 4, key: "half" }, { id: 5, key: "full" }, { id: 6, key: "bw" }],
  "Document Printing"
);
assertMatrix(
  [{ id: 11, key: "letter" }, { id: 12, key: "8_5x13" }, { id: 13, key: "a4" }],
  [{ id: 14, key: "colored" }, { id: 15, key: "bw" }],
  "Photocopy"
);

const deviceSelection = catalog.selectionMap([
  { group_key: "device_type", value_id: 90, value_key: "laptop", label: "Laptop" },
]);
const laptopRule = {
  active: 1,
  option_value_ids: { device_type: 90, repair_type: 91 },
  option_value_keys: { device_type: "laptop", repair_type: "lcd" },
};
assert.strictEqual(catalog.ruleMatches(laptopRule, deviceSelection, false), true, "Device filtering must match child services by device ID.");

console.log("Service catalog client tests passed.");
