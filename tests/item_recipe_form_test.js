'use strict';
// Run with node tests/item_recipe_form_test.js. Executes the shared editor script with DOM event fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
let checks = 0;
function check(condition, label) {
  assert.ok(condition, label);
  checks++;
  console.log('PASS: ' + label);
}
function field(value) {
  const target = new EventTarget();
  target.value = value;
  return target;
}
function material(type, size, kind, unit, value = '') {
  const input = { value, disabled: false, placeholder: '' };
  return {
    dataset: { recipeType: type, recipeSize: size, recipeKind: kind, recipeUnit: unit },
    hidden: false,
    input,
    querySelector: selector => selector === 'input' ? input : null
  };
}
const sizeField = field('22oz');
const nameField = field('LATTE');
const milk = material('raw_material', '', '', 'ml', '250');
const cup12 = material('packaging', '12oz Hot', 'cup', 'pcs', '1');
const cup16 = material('packaging', '16oz', 'cup', 'pcs', '1');
const cup22 = material('packaging', '22oz Cold', 'cup', 'pcs', '2');
const lid22 = material('packaging', '22oz', 'lid', 'pcs');
const straw = material('packaging', '', 'straw', 'pcs');
const stirrer = material('packaging', '', 'stirrer', 'pcs');
const unsizedCup = material('packaging', '', 'cup', 'pcs');
const sleeve16 = material('packaging', '16oz', '', 'pcs');
const other = material('other', '', '', 'pcs');
const rows = [milk, cup12, cup16, cup22, lid22, straw, stirrer, unsizedCup, sleeve16, other];
const scope = { textContent: '' };
const form = { querySelector: selector => ({ '[name="cup_size"]': sizeField, '[name="name"]': nameField })[selector] };
const section = Object.assign(new EventTarget(), {
  closest: selector => selector === 'form' ? form : null,
  querySelector: selector => selector === '[data-recipe-scope]' ? scope : null,
  querySelectorAll: selector => selector === '[data-recipe-row]' ? rows : []
});
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/scripts/item-recipe.js'), 'utf8'), {
  document: { querySelectorAll: selector => selector === '[data-item-recipe]' ? [section] : [] }
});
const visible = row => !row.hidden && !row.input.disabled;
check(visible(cup22) && visible(lid22) && !visible(cup12) && !visible(cup16), 'Opening a 22oz item shows only matching cups and lids');
check(visible(milk) && visible(other) && milk.input.value === '250', 'Shared ingredients remain editable with their configured quantities');
check(visible(straw) && !visible(stirrer) && !visible(unsizedCup) && !visible(sleeve16), 'Cold recipes exclude stirrers, unsized cups, and other mismatched packaging');
check(scope.textContent.includes('22oz') && lid22.input.placeholder === 'Auto: 1 pcs', 'The recipe scope and automatic packaging quantity are clear');
sizeField.value = '12oz Hot';
sizeField.dispatchEvent(new Event('change'));
check(visible(cup12) && visible(stirrer) && !visible(cup22) && !visible(straw), 'Switching to 12oz Hot immediately changes packaging and temperature');
check(cup22.input.value === '2' && cup22.input.disabled, 'Hidden draft packaging quantities are kept but excluded from submission');
sizeField.value = '22oz';
sizeField.dispatchEvent(new Event('change'));
check(visible(cup22) && cup22.input.value === '2' && milk.input.value === '250', 'Switching back restores packaging drafts without changing ingredient quantities');
nameField.value = 'HOT LATTE';
nameField.dispatchEvent(new Event('input'));
check(visible(stirrer) && !visible(straw), 'Temperature changes inferred from the item name stay consistent with the server');
sizeField.value = '22oz Cold';
sizeField.dispatchEvent(new Event('change'));
check(visible(straw) && !visible(stirrer) && visible(cup22), 'Legacy Cold labels override HOT in an item name consistently');
const submitted = rows.filter(row => !row.input.disabled && Number(row.input.value) > 0);
check(!submitted.includes(cup12) && !submitted.includes(cup16) && submitted.includes(cup22), 'Only compatible positive packaging quantities can be submitted');
// The modal dispatches change after loading another product into the same form.
nameField.value = 'MOCHA';
sizeField.value = '16oz';
milk.input.value = '100';
cup22.input.value = '';
sizeField.dispatchEvent(new Event('change', { bubbles: true }));
check(visible(cup16) && !visible(cup22) && milk.input.value === '100', 'Opening another product updates the reused modal recipe filter');
const wideStraw = material('packaging', '', 'straw', 'pcs');
wideStraw.input.dataset = { requirementId: '200' };
straw.input.dataset = { requirementId: '100' };
rows.splice(rows.indexOf(straw), 0, wideStraw);
section.dispatchEvent(new Event('input'));
check(straw.input.placeholder === 'Auto: 1 pcs' && wideStraw.input.placeholder === '0 pcs', 'Blank straw choices show only the default with the lowest inventory ID');
wideStraw.input.value = '1';
section.dispatchEvent(new Event('input'));
check(straw.input.placeholder === '0 pcs' && wideStraw.input.placeholder === '0 pcs', 'An explicit wide straw suppresses the automatic regular straw hint');
wideStraw.input.value = '';
section.dispatchEvent(new Event('input'));
check(straw.input.placeholder === 'Auto: 1 pcs', 'Removing an explicit wide straw restores the automatic regular straw hint');
console.log('All ' + checks + ' recipe form checks passed.');