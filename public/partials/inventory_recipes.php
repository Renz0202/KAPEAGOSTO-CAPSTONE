<section class="inventory-tab-panel" id="inventory-panel-recipes" role="tabpanel" aria-labelledby="inventory-tab-recipes" data-tab-panel="recipes" hidden>
  <section class="inventory-recipes-card" aria-labelledby="inventory-recipes-title">
    <header class="inventory-panel-header">
      <div><span class="panel-eyebrow">Product Recipes</span><h2 id="inventory-recipes-title">Configure Recipes</h2></div>
      <button type="button" class="inventory-cancel-btn" onclick="openAddInventoryModal()" aria-haspopup="dialog" aria-controls="addInventoryModal"><i class="fa fa-plus"></i> Add Inventory</button>
    </header>
    <div class="inventory-alert">
      <strong>Recipe guide:</strong> Add ingredients to inventory in g, ml, or pcs. Choose a category, product, and cup size, then enter the amount needed for one serving. Save each cup size separately. Cups, lids, and a straw or stirrer are included automatically. Stock is deducted when an order is marked Done.
    </div>
    <?php if ($recipeNotice): ?>
      <div class="inventory-alert <?= $recipeNotice['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($recipeNotice['message']) ?></div>
    <?php endif; ?>
    <form method="post" action="inventory.php?tab=recipes" id="inventoryRecipeForm">
      <input type="hidden" name="save_recipe" value="1">
      <input type="hidden" name="cup_size" id="inventory_recipe_cup_size">
      <input type="hidden" name="name" id="inventory_recipe_name">
      <div class="inventory-recipe-selectors">
        <div><label for="inventory_recipe_category">Category</label><select id="inventory_recipe_category"><option value="">All categories</option></select></div>
        <div><label for="inventory_recipe_product">Product</label><select id="inventory_recipe_product" required><option value="">Select a product</option></select></div>
        <div><label for="inventory_recipe_variant">Cup Size</label><select id="inventory_recipe_variant" name="item_id" required disabled><option value="">Select a size</option></select></div>
      </div>
      <p class="inventory-recipe-empty" id="inventoryRecipeEmpty">Select a product to configure its recipe.</p>
      <fieldset id="inventoryRecipeFields" data-item-recipe disabled hidden>
        <legend>Materials per serving</legend>
        <p class="inventory-alert warning recipe-estimate-note" id="inventoryRecipeSource" role="status" hidden></p>
        <p data-recipe-scope aria-live="polite"></p>
        <p class="inventory-recipe-help">Ingredient quantities apply to one serving of this size. Only ingredients assigned to this recipe are shown.</p>
        <section class="inventory-recipe-group" aria-labelledby="inventoryRecipeIngredientsTitle">
          <div class="inventory-recipe-group-heading">
            <h3 id="inventoryRecipeIngredientsTitle">Recipe Ingredients</h3>
            <button type="button" class="inventory-cancel-btn" id="inventoryRecipeAddIngredient" aria-expanded="false" aria-controls="inventoryRecipeIngredientPicker"><i class="fa fa-plus"></i> Add Ingredient</button>
          </div>
          <div class="inventory-ingredient-picker" id="inventoryRecipeIngredientPicker" hidden>
            <label for="inventory_recipe_material_group">Material Group</label>
            <select id="inventory_recipe_material_group"><option value="">All ingredient groups</option></select>
            <label for="inventory_recipe_ingredient_search">Search inventory ingredients</label>
            <input type="search" id="inventory_recipe_ingredient_search" placeholder="Search ingredient name or SKU..." autocomplete="off">
            <ul class="inventory-ingredient-results" id="inventoryRecipeIngredientResults"></ul>
            <p id="inventoryRecipeIngredientSearchStatus" role="status"></p>
          </div>
          <p class="inventory-recipe-empty" id="inventoryRecipeIngredientsEmpty">No ingredients assigned yet. Use Add Ingredient to build this recipe.</p>
          <div class="inventory-recipe-materials" id="inventoryRecipeIngredients"></div>
          <p class="inventory-recipe-help">Enter quantities in the inventory unit shown. Remove an ingredient, or leave its quantity blank or zero, to exclude it when saving.</p>
        </section>
        <section class="inventory-recipe-group" aria-labelledby="inventoryRecipePackagingTitle">
          <div class="inventory-recipe-group-heading"><h3 id="inventoryRecipePackagingTitle">Packaging</h3></div>
          <p class="inventory-recipe-help">Blank packaging uses one matching cup, lid, and straw or stirrer automatically.</p>
          <div class="inventory-recipe-materials" id="inventoryRecipePackaging"></div>
          <p class="inventory-recipe-empty" id="inventoryRecipePackagingEmpty" hidden>No matching packaging in inventory. Use Add Inventory to add packaging for this size.</p>
        </section>
        <div class="inventory-recipe-footer"><span>Save the quantities for the selected product and size.</span><button type="submit" class="inventory-submit-btn"><i class="fa fa-floppy-disk"></i> Save Recipe</button></div>
      </fieldset>
    </form>
    <noscript><p>Enable JavaScript to select products and configure recipes here.</p></noscript>
  </section>
</section>
<script type="application/json" id="inventoryRecipeData"><?= json_encode($recipeEditorData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>