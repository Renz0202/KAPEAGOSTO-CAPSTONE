(function () {
  'use strict';
  const form = document.getElementById('inventoryRecipeForm');
  const payload = document.getElementById('inventoryRecipeData');
  if (!form || !payload) return;
  const categoryField = document.getElementById('inventory_recipe_category');
  const productField = document.getElementById('inventory_recipe_product');
  const variantField = document.getElementById('inventory_recipe_variant');
  const sizeField = document.getElementById('inventory_recipe_cup_size');
  const nameField = document.getElementById('inventory_recipe_name');
  const fields = document.getElementById('inventoryRecipeFields');
  const recipeSource = document.getElementById('inventoryRecipeSource');
  const ingredients = document.getElementById('inventoryRecipeIngredients');
  const packaging = document.getElementById('inventoryRecipePackaging');
  const empty = document.getElementById('inventoryRecipeEmpty');
  const ingredientsEmpty = document.getElementById('inventoryRecipeIngredientsEmpty');
  const packagingEmpty = document.getElementById('inventoryRecipePackagingEmpty');
  const addIngredient = document.getElementById('inventoryRecipeAddIngredient');
  const picker = document.getElementById('inventoryRecipeIngredientPicker');
  const search = document.getElementById('inventory_recipe_ingredient_search');
  const materialGroup = document.getElementById('inventory_recipe_material_group');
  const results = document.getElementById('inventoryRecipeIngredientResults');
  const searchStatus = document.getElementById('inventoryRecipeIngredientSearchStatus');
  let data;

  function option(value, label) {
    const element = document.createElement('option');
    element.value = String(value);
    element.textContent = label;
    return element;
  }

  function categoryKey(product) { return String(product.category_id); }

  function selectedIngredientIds() {
    return Array.from(ingredients.querySelectorAll('[data-requirement-id]'), input => input.dataset.requirementId);
  }

  function draftValues() {
    const values = {};
    fields.querySelectorAll('[data-requirement-id]').forEach(input => { values[input.dataset.requirementId] = input.value; });
    return values;
  }

  function updateIngredientEmpty() {
    ingredientsEmpty.hidden = selectedIngredientIds().length > 0;
  }

  function setPickerOpen(open) {
    picker.hidden = !open;
    addIngredient.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      renderIngredientChoices();
      search.focus();
    }
  }

  function materialRow(material, value) {
    const row = document.createElement('div');
    const isPackaging = material.material_type === 'packaging';
    row.className = 'inventory-recipe-material-row' + (isPackaging ? '' : ' inventory-recipe-ingredient-row');
    Object.assign(row.dataset, {
      recipeRow: '', recipeType: material.material_type, recipeSize: material.recipe_size,
      recipeKind: material.recipe_kind, recipeUnit: material.unit
    });
    const description = document.createElement('label');
    description.htmlFor = 'inventory_recipe_requirement_' + material.id;
    const title = document.createElement('strong');
    title.textContent = material.name + (material.cup_size ? ' (' + material.cup_size + ')' : '');
    const details = document.createElement('small');
    details.textContent = material.sku + ' \u00b7 ' + material.unit + (!isPackaging && material.material_group_label ? ' \u00b7 ' + material.material_group_label : '');
    description.append(title, details);
    const input = document.createElement('input');
    input.id = description.htmlFor;
    input.type = 'number';
    input.min = '0';
    input.max = '99999999.99';
    input.step = '0.01';
    input.name = 'requirements[' + material.id + ']';
    input.dataset.requirementId = String(material.id);
    input.value = value ?? '';
    input.setAttribute('aria-label', material.name + ' per serving in ' + material.unit);
    row.append(description, input);
    if (!isPackaging) {
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'inventory-recipe-remove-ingredient';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', 'Remove ' + material.name + ' from recipe');
      remove.addEventListener('click', function () {
        row.remove();
        updateIngredientEmpty();
        renderIngredientChoices();
        addIngredient.focus();
      });
      row.append(remove);
    }
    return row;
  }

  function renderIngredientChoices() {
    const assigned = new Set(selectedIngredientIds());
    const available = data.materials.filter(material => material.material_type !== 'packaging');
    const remaining = available.filter(material => !assigned.has(String(material.id)));
    const query = search.value.trim().toLowerCase();
    const matches = remaining.filter(material => (!materialGroup.value || (material.material_group || 'ungrouped') === materialGroup.value) && (material.name + ' ' + material.sku).toLowerCase().includes(query));
    results.replaceChildren();
    matches.slice(0, 20).forEach(function (material) {
      const row = document.createElement('li');
      const description = document.createElement('span');
      const title = document.createElement('strong');
      title.textContent = material.name;
      const details = document.createElement('small');
      details.textContent = material.sku + ' \u00b7 ' + material.unit + (material.material_group_label ? ' \u00b7 ' + material.material_group_label : '');
      description.append(title, details);
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'inventory-recipe-add-choice';
      add.textContent = 'Add';
      add.dataset.addIngredientId = String(material.id);
      add.setAttribute('aria-label', 'Add ' + material.name + ' to recipe');
      add.addEventListener('click', function () {
        if (selectedIngredientIds().includes(String(material.id))) return;
        ingredients.append(materialRow(material, ''));
        updateIngredientEmpty();
        sizeField.dispatchEvent(new Event('change', { bubbles: true }));
        renderIngredientChoices();
        document.getElementById('inventory_recipe_requirement_' + material.id)?.focus();
      });
      row.append(description, add);
      results.append(row);
    });
    searchStatus.textContent = !available.length ? 'No ingredients in inventory yet. Use Add Inventory to create one.' :
      !remaining.length ? 'All inventory ingredients are already in this recipe.' :
      !matches.length ? (materialGroup.value ? 'No ingredients match this group and search.' : 'No ingredients match your search.') :
      matches.length > 20 ? 'Showing the first 20 matches. Refine your search to see more.' : '';
  }

  function loadVariant(values, ingredientIds) {
    const product = data.products.find(item => item.key === productField.value);
    const variant = product?.variants.find(item => String(item.id) === variantField.value);
    if (recipeSource) {
      recipeSource.hidden = variant?.recipe_source !== 'generated';
      recipeSource.textContent = variant?.recipe_source === 'generated' ?
        (variant.recipe_notes || 'Generated starter estimate. Review the quantities against your house recipe.') + ' Edit the amounts and save to use your reviewed recipe.' : '';
    }
    fields.hidden = !variant;
    fields.disabled = !variant;
    empty.hidden = Boolean(variant);
    sizeField.value = variant?.cup_size || '';
    nameField.value = product?.name || '';
    ingredients.replaceChildren();
    packaging.replaceChildren();
    setPickerOpen(false);
    search.value = '';
    materialGroup.value = '';
    if (variant) {
      const quantities = values ?? variant.requirements ?? {};
      const assigned = new Set(ingredientIds ?? Object.keys(quantities).filter(id => quantities[id] !== '' && quantities[id] !== null && Number(quantities[id]) !== 0));
      data.materials.forEach(function (material) {
        if (material.material_type === 'packaging') {
          packaging.append(materialRow(material, quantities[material.id]));
        } else if (assigned.has(String(material.id))) {
          ingredients.append(materialRow(material, quantities[material.id]));
        }
      });
    }
    updateIngredientEmpty();
    sizeField.dispatchEvent(new Event('change', { bubbles: true }));
    packagingEmpty.hidden = Array.from(packaging.querySelectorAll('[data-recipe-row]')).some(row => !row.hidden);
    if (!variant) empty.textContent = data.products.length ? 'Select a product to configure its recipe.' : 'Add menu items in Manage Item before configuring recipes.';
  }

  function loadProduct(itemId, values, ingredientIds) {
    const product = data.products.find(item => item.key === productField.value);
    variantField.replaceChildren(option('', 'Select a size'));
    variantField.disabled = !product;
    if (product) {
      product.variants.forEach(variant => variantField.append(option(variant.id, variant.cup_size)));
      const requested = product.variants.find(variant => String(variant.id) === String(itemId));
      variantField.value = String((requested || product.variants[0]).id);
    }
    loadVariant(values, ingredientIds);
  }

  function renderProducts(itemId, values, ingredientIds) {
    const previousKey = productField.value;
    const visible = data.products.filter(product => !categoryField.value || categoryKey(product) === categoryField.value);
    productField.replaceChildren(option('', 'Select a product'));
    if (categoryField.value) {
      visible.forEach(product => productField.append(option(product.key, product.name)));
    } else {
      const groups = new Map();
      visible.forEach(function (product) {
        const key = categoryKey(product);
        if (!groups.has(key)) {
          const group = document.createElement('optgroup');
          group.label = product.category;
          groups.set(key, group);
          productField.append(group);
        }
        groups.get(key).append(option(product.key, product.name));
      });
    }
    const requested = visible.find(product => product.variants.some(variant => String(variant.id) === String(itemId)));
    const selected = requested || visible.find(product => product.key === previousKey);
    productField.value = selected?.key || '';
    loadProduct(itemId, values, ingredientIds);
  }

  function render(nextData, itemId, values, ingredientIds) {
    const previousCategory = categoryField.value;
    data = nextData;
    materialGroup.replaceChildren(option('', 'All ingredient groups'));
    Object.entries(data.material_groups || {}).forEach(function ([key, label]) {
      if (key !== 'packaging') materialGroup.append(option(key, label));
    });
    if (data.materials.some(material => material.material_type !== 'packaging' && !material.material_group)) {
      materialGroup.append(option('ungrouped', 'Other / Ungrouped'));
    }
    const categories = new Map();
    data.products.forEach(product => categories.set(categoryKey(product), product.category));
    categoryField.replaceChildren(option('', 'All categories'));
    categories.forEach((label, key) => categoryField.append(option(key, label)));
    const selected = data.products.find(product => product.variants.some(variant => String(variant.id) === String(itemId)));
    categoryField.value = selected ? categoryKey(selected) : categories.has(previousCategory) ? previousCategory : '';
    renderProducts(itemId, values, ingredientIds);
  }

  categoryField.addEventListener('change', function () { renderProducts(variantField.value, draftValues(), selectedIngredientIds()); });
  productField.addEventListener('change', function () { loadProduct(); });
  variantField.addEventListener('change', function () { loadVariant(); });
  addIngredient.addEventListener('click', function () { setPickerOpen(picker.hidden); });
  search.addEventListener('input', renderIngredientChoices);
  materialGroup.addEventListener('change', renderIngredientChoices);
  search.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') event.preventDefault();
    if (event.key === 'Escape') {
      event.preventDefault();
      setPickerOpen(false);
      addIngredient.focus();
    }
  });

  // Refresh stock choices while preserving only the ingredients explicitly included in this draft.
  window.refreshInventoryRecipes = async function () {
    const itemId = variantField.value;
    const ingredientIds = selectedIngredientIds();
    const values = draftValues();
    const pickerOpen = !picker.hidden;
    const query = search.value;
    const selectedMaterialGroup = materialGroup.value;
    try {
      const response = await fetch('inventory.php?ajax_recipes=1', { cache: 'no-store' });
      if (!response.ok) throw new Error('Recipe refresh failed.');
      render(await response.json(), itemId, values, ingredientIds);
      search.value = query;
      materialGroup.value = selectedMaterialGroup;
      if (pickerOpen && !fields.hidden) setPickerOpen(true);
    } catch (error) {
      window.kapeNotify?.('Inventory was added. Reload the page to refresh recipe materials.', 'warning');
    }
  };

  try {
    const initial = JSON.parse(payload.textContent || '{}');
    render(initial, initial.initial_item_id, initial.draft);
  } catch (error) {
    empty.textContent = 'Recipes could not be loaded. Reload the page and try again.';
  }
})();