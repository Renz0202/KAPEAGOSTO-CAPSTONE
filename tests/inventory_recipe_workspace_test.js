'use strict';
// Execute the actual Inventory recipe manager and shared size filter with DOM/event fixtures.
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
class Element extends EventTarget {
  constructor(tagName) {
    super();
    this.tagName = tagName;
    this.children = [];
    this.dataset = {};
    this.attributes = {};
    this.value = '';
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
  }
  append(...children) {
    children.forEach(child => { child.parent = this; this.children.push(child); });
    if (this.tagName === 'select' && this.children.length === children.length) this.value = this.children[0]?.value || '';
  }
  replaceChildren(...children) { this.children = []; this.value = ''; this.append(...children); }
  setAttribute(name, value) { this.attributes[name] = value; }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(child => child !== this); this.parent = null; }
  focus() { let root = this; while (root.parent) root = root.parent; root.activeElement = this; }
  matches(selector) {
    const attribute = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
    if (!attribute) return this.tagName === selector;
    const [, name, value] = attribute;
    if (name.startsWith('data-')) {
      const key = name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      return Object.hasOwn(this.dataset, key) && (value === undefined || this.dataset[key] === value);
    }
    return value === undefined ? this[name] !== undefined : this[name] === value;
  }
  querySelectorAll(selector) {
    const result = [];
    this.children.forEach(child => { if (child.matches(selector)) result.push(child); result.push(...child.querySelectorAll(selector)); });
    return result;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  closest(selector) { for (let node = this; node; node = node.parent) if (node.matches(selector)) return node; return null; }
}
function fixture(initial) {
  const document = new Element('document');
  document.createElement = tag => new Element(tag);
  document.getElementById = id => {
    function visit(node) { if (node.id === id) return node; for (const child of node.children) { const found = visit(child); if (found) return found; } return null; }
    return visit(document);
  };
  function element(tag, id, parent, properties = {}) {
    const node = Object.assign(new Element(tag), { id }, properties);
    parent.append(node);
    return node;
  }
  const form = element('form', 'inventoryRecipeForm', document);
  const category = element('select', 'inventory_recipe_category', form);
  const product = element('select', 'inventory_recipe_product', form);
  const variant = element('select', 'inventory_recipe_variant', form, { name: 'item_id' });
  const size = element('input', 'inventory_recipe_cup_size', form, { name: 'cup_size' });
  const name = element('input', 'inventory_recipe_name', form, { name: 'name' });
  const empty = element('p', 'inventoryRecipeEmpty', form);
  const fields = element('fieldset', 'inventoryRecipeFields', form, { disabled: true, hidden: true, dataset: { itemRecipe: '' } });
  const scope = element('p', 'scope', fields, { dataset: { recipeScope: '' } });
  const recipeSource = element('p', 'inventoryRecipeSource', fields, { hidden: true });
  const ingredients = element('div', 'inventoryRecipeIngredients', fields);
  const packaging = element('div', 'inventoryRecipePackaging', fields);
  const ingredientsEmpty = element('p', 'inventoryRecipeIngredientsEmpty', fields);
  const packagingEmpty = element('p', 'inventoryRecipePackagingEmpty', fields);
  const addIngredient = element('button', 'inventoryRecipeAddIngredient', fields);
  const picker = element('div', 'inventoryRecipeIngredientPicker', fields, { hidden: true });
  const materialGroup = element('select', 'inventory_recipe_material_group', picker);
  const search = element('input', 'inventory_recipe_ingredient_search', picker);
  const results = element('ul', 'inventoryRecipeIngredientResults', picker);
  const searchStatus = element('p', 'inventoryRecipeIngredientSearchStatus', picker);
  element('script', 'inventoryRecipeData', document, { textContent: JSON.stringify(initial) });
  const window = {};
  let nextData;
  const context = vm.createContext({ document, window, Event, fetch: async () => ({ ok: true, json: async () => nextData }) });
  for (const script of ['item-recipe.js', 'inventory-recipes.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/scripts', script), 'utf8'), context);
  }
  return {
    document, category, product, variant, size, name, fields, scope, recipeSource, empty, ingredients, packaging, ingredientsEmpty, packagingEmpty, addIngredient, picker, search, materialGroup, results, searchStatus,
    input: id => document.getElementById('inventory_recipe_requirement_' + id),
    refresh: async data => { nextData = data; await window.refreshInventoryRecipes(); }
  };
}
function change(field, value) { field.value = value; field.dispatchEvent(new Event('change')); }
const materialGroups = {
  coffee_tea: 'Coffee & Tea Bases', dairy: 'Dairy, Milk & Alternative Bases', flavorings: 'Syrups, Sauces & Flavorings',
  powders: 'Powders & Mixers', soda: 'Soda & Carbonation', toppings: 'Toppings, Mix-ins & Biscuits', packaging: 'Packaging & Consumables'
};
const material = (id, name, type, size, kind, unit) => {
  const group = type === 'packaging' ? 'packaging' : id === 2 ? 'dairy' : id === 6 ? 'flavorings' : 'coffee_tea';
  return { id, sku: 'TEST-' + id, name, cup_size: size || null, material_type: type, recipe_size: size, recipe_kind: kind, unit, material_group: group, material_group_label: materialGroups[group] };
};
const data = {
  material_groups: materialGroups,
  products: [
    { key: 'latte', name: 'LATTE', category_id: 1, category: 'Coffee', variants: [{ id: 1, cup_size: '16oz', requirements: { 2: '150.00' } }, { id: 2, cup_size: '22oz', requirements: { 2: '250.00' }, recipe_source: 'generated', recipe_notes: 'Generated starter estimate. Review milk and espresso.' }] },
    { key: 'hot', name: 'HOT LATTE', category_id: 1, category: 'Coffee', variants: [{ id: 3, cup_size: '12oz Hot', requirements: { 2: '100.00' } }] },
    { key: 'tea', name: 'BLUEBERRY TEA', category_id: 2, category: 'Fruit Tea', variants: [{ id: 4, cup_size: '16oz', requirements: {} }, { id: 5, cup_size: '22oz', requirements: { 6: '30.00' } }] }
  ],
  materials: [
    material(1, 'COFFEE BEANS', 'raw_material', '', '', 'g'),
    material(2, 'FRESH MILK', 'raw_material', '', '', 'ml'),
    material(6, 'BLUEBERRY SYRUP', 'raw_material', '', '', 'ml'),
    material(10, 'PAPER CUP', 'packaging', '12oz Hot', 'cup', 'pcs'),
    material(11, 'PLASTIC CUP', 'packaging', '16oz', 'cup', 'pcs'),
    material(12, 'PLASTIC CUP', 'packaging', '22oz', 'cup', 'pcs'),
    material(13, 'FLAT LID', 'packaging', '22oz', 'lid', 'pcs'),
    material(14, 'STRAW', 'packaging', '', 'straw', 'pcs'),
    material(15, 'COFFEE STIRRER', 'packaging', '', 'stirrer', 'pcs')
  ]
};
function click(element) { assert.ok(element, 'Click target exists'); element.dispatchEvent(new Event('click')); }
function search(editor, value) { editor.search.value = value; editor.search.dispatchEvent(new Event('input')); }
function addChoice(editor, id) { return editor.results.querySelectorAll('[data-add-ingredient-id]').find(button => button.dataset.addIngredientId === String(id)); }
async function run() {
  const editor = fixture(data);
  check(editor.fields.hidden && editor.fields.disabled && editor.variant.disabled, 'No recipe can be submitted until a product is selected');
  check(editor.recipeSource.hidden, 'Recipe provenance stays hidden until a generated variant is selected');
  check(editor.product.children.filter(child => child.tagName === 'optgroup').length === 2, 'All-category products are grouped under their menu categories');
  change(editor.category, '1');
  check(editor.product.querySelectorAll('option').some(option => option.value === 'latte') && !editor.product.querySelectorAll('option').some(option => option.value === 'tea'), 'The category filter shows only products in the selected category');
  change(editor.product, 'latte');
  check(editor.variant.value === '1' && editor.size.value === '16oz' && editor.input(2).value === '150.00' && !editor.fields.hidden, 'Selecting a product loads its first size and existing recipe');
  check(editor.ingredients.querySelectorAll('input').length === 1 && !editor.input(1) && !editor.input(6), 'Only ingredients assigned to the selected recipe are shown');
  check(!editor.input(11).disabled && editor.input(10).disabled && editor.input(12).disabled && editor.input(15).disabled && editor.packaging.querySelectorAll('input').length === 6, 'Packaging is separate and still follows the shared size and temperature filter');
  change(editor.variant, '2');
  check(editor.size.value === '22oz' && editor.input(2).value === '250.00' && !editor.input(12).disabled && !editor.input(13).disabled && editor.input(11).disabled, 'Switching to 22oz loads its own quantities and matching packaging');
  check(editor.input(12).placeholder === 'Auto: 1 pcs' && editor.scope.textContent.includes('22oz'), 'Automatic quantities and the selected serving size are displayed');
  check(!editor.recipeSource.hidden && editor.recipeSource.textContent.includes('Generated starter estimate') && editor.recipeSource.textContent.includes('Review milk'), 'Generated quantities show their review note for the selected size');
  editor.input(2).value = '280.25';
  change(editor.category, '');
  check(editor.variant.value === '2' && editor.input(2).value === '280.25', 'Changing to All categories preserves an active recipe draft');
  change(editor.category, '2');
  check(editor.product.value === '' && editor.variant.disabled && editor.fields.hidden, 'Filtering to another category clears products outside the filter');
  change(editor.product, 'tea');
  check(!editor.input(2) && editor.ingredientsEmpty.hidden === false && !editor.input(11).disabled && !editor.input(14).disabled, 'Blueberry Tea shows no milk unless assigned, while matching packaging remains visible');
  check(editor.recipeSource.hidden, 'Switching to a custom recipe removes the generated note');
  click(editor.addIngredient);
  check(!editor.picker.hidden && editor.addIngredient.attributes['aria-expanded'] === 'true' && !addChoice(editor, 11), 'Add Ingredient opens searchable inventory choices without packaging');
  check(editor.materialGroup.children.length === 7 && !editor.materialGroup.children.some(option => option.value === 'packaging'), 'Ingredient group choices include all six ingredient groups and exclude packaging');
  change(editor.materialGroup, 'flavorings');
  search(editor, '');
  check(addChoice(editor, 6) && !addChoice(editor, 2) && !addChoice(editor, 1), 'Filtering Syrups, Sauces & Flavorings excludes dairy and coffee bases');
  search(editor, 'milk');
  check(editor.searchStatus.textContent === 'No ingredients match this group and search.', 'Ingredient group and text search filters work together');
  change(editor.materialGroup, '');
  search(editor, 'blueberry');
  check(addChoice(editor, 6) && !addChoice(editor, 2), 'Ingredient search filters names');
  search(editor, 'TEST-2');
  check(addChoice(editor, 2) && !addChoice(editor, 6), 'Ingredient search also matches inventory SKUs');
  const enter = new Event('keydown', { cancelable: true });
  enter.key = 'Enter';
  editor.search.dispatchEvent(enter);
  check(enter.defaultPrevented, 'Pressing Enter in ingredient search cannot accidentally save the recipe');
  const escape = new Event('keydown', { cancelable: true });
  escape.key = 'Escape';
  editor.search.dispatchEvent(escape);
  check(editor.picker.hidden && editor.addIngredient.attributes['aria-expanded'] === 'false', 'Escape closes ingredient search and returns focus to Add Ingredient');
  click(editor.addIngredient);
  search(editor, 'blueberry');
  const syrupChoice = addChoice(editor, 6);
  click(syrupChoice);
  click(syrupChoice);
  check(editor.input(6) && editor.input(6).value === '' && editor.ingredientsEmpty.hidden && editor.ingredients.querySelectorAll('input').length === 1 && !addChoice(editor, 6), 'Adding an ingredient creates one quantity field and prevents duplicates');
  editor.input(6).value = '25.5';
  change(editor.materialGroup, 'flavorings');
  const updated = { ...data, materials: [...data.materials, material(16, 'TEA LEAVES', 'raw_material', '', '', 'g')] };
  await editor.refresh(updated);
  check(editor.materialGroup.value === 'flavorings' && editor.category.value === '2' && editor.variant.value === '4' && editor.input(6).value === '25.5' && !editor.input(16) && !editor.picker.hidden, 'Adding inventory preserves the recipe draft and keeps new ingredients unassigned');
  change(editor.materialGroup, 'coffee_tea');
  search(editor, 'tea leaves');
  click(addChoice(editor, 16));
  await editor.refresh(updated);
  check(editor.input(16) && editor.input(16).value === '' && editor.input(6).value === '25.5', 'A newly added ingredient with a blank quantity survives inventory refresh');
  const remove = editor.input(6).closest('div').querySelector('button');
  click(remove);
  check(!editor.input(6) && !editor.fields.querySelectorAll('input').some(input => input.name === 'requirements[6]'), 'Removing an ingredient excludes it from the recipe submission');
  change(editor.materialGroup, 'flavorings');
  search(editor, 'blueberry');
  check(addChoice(editor, 6), 'Removed ingredients become available to add again');
  change(editor.materialGroup, '');
  search(editor, 'nonexistent ingredient');
  check(editor.searchStatus.textContent === 'No ingredients match your search.', 'An unmatched ingredient search shows a clear empty state');
  change(editor.variant, '5');
  check(editor.input(6).value === '30.00' && !editor.input(2) && !editor.input(16) && !editor.input(12).disabled, 'Switching sizes loads only that variant\'s saved ingredients and packaging');
  change(editor.category, '1');
  change(editor.product, 'hot');
  check(editor.variant.value === '3' && editor.name.value === 'HOT LATTE' && editor.input(2).value === '100.00' && !editor.input(10).disabled && !editor.input(15).disabled && editor.input(14).disabled, 'Switching categories and products loads the hot recipe with stirrers and hot cups');
  const reopened = fixture({ ...data, initial_item_id: 2 });
  check(reopened.category.value === '1' && reopened.product.value === 'latte' && reopened.variant.value === '2' && reopened.input(2).value === '250.00', 'After saving, the same category, product, and size reopen with saved quantities');
  const failed = fixture({ ...data, initial_item_id: 2, draft: { 2: '300', 10: '1' } });
  check(failed.input(2).value === '300' && failed.input(10).value === '1' && failed.input(10).disabled, 'A validation error preserves entered quantities and excludes incompatible packaging on retry');
  const zero = fixture({ ...data, initial_item_id: 2, draft: { 2: '0' } });
  check(!zero.input(2) && !zero.ingredientsEmpty.hidden, 'Blank or zero ingredient quantities are excluded from the reopened draft');
  const missingPackaging = fixture({ ...data, initial_item_id: 4, materials: data.materials.filter(material => material.material_type !== 'packaging') });
  check(!missingPackaging.packagingEmpty.hidden, 'Missing matching packaging has an explicit setup message');
  const emptyEditor = fixture({ products: [], materials: [] });
  check(emptyEditor.fields.disabled && emptyEditor.empty.textContent.includes('Manage Item'), 'An empty catalog gives a useful setup message');
  console.log('All ' + checks + ' Inventory recipe workspace checks passed.');
}
run().catch(error => { console.error(error); process.exitCode = 1; });