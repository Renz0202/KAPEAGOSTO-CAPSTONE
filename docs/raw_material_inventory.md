# Raw material inventory and POS orders

## Setup

1. Open **Inventory > Add Inventory**, then choose **Raw Material / Ingredient** in **Inventory Type**. The same form also supports **Packaging** and **Other Supply**. Choose its **Material Group**, then enter the ingredient name, stock quantity, and unit. Known material names are grouped automatically when the group is left blank. Use the same unit for stock and recipes; for example, track milk in ml and coffee beans in g. A new material can start at zero stock.
2. In **Inventory**, open **Configure Recipes**. Filter by **Category**, choose a **Product** and **Cup Size**, then use **Add Ingredient** to filter by **Material Group** and search existing inventory by name or SKU. Enter each ingredient quantity for one serving and click **Save Recipe**. Recipes stay in the Inventory workspace. Blank or zero removes an ingredient from that recipe.
3. Use actual recipe amounts. For example only, a recipe of 18 g beans and 150 ml milk would consume 36 g beans and 300 ml milk for two servings.

Cups, lids, and a straw for cold drinks or a stirrer for hot drinks are included automatically. If you explicitly configure one of these packaging types in a recipe, that entry replaces its automatic amount.

The Inventory recipe tab and Edit Item share the same saved recipes and POS deductions. Add Inventory is also available inside the recipe tab; new materials become searchable with Add Ingredient without clearing the current recipe draft or assigning themselves to the recipe. The recipe editor shows cups, lids, and other sized packaging only for the selected serving size. Cold drinks show straws; hot drinks show stirrers. Changing the size updates the list immediately. The **Recipe Ingredients** section shows only ingredients assigned to the selected variant. For example, milk is hidden for Blueberry Tea unless explicitly added to its recipe. Use **Add Ingredient** to include another ingredient or **Remove** to exclude one; removal is applied when you save. **Packaging** appears in a separate section. Each product variant keeps the quantities you enter for one serving; quantities are not scaled automatically. Hidden packaging is excluded when saving. The server also rejects mismatched packaging, including preexisting incompatible recipes at checkout or order completion. Edit and save an affected recipe to correct it.

## Material groups and opening inventory

The existing **Manage Inventory** page remains at `public/inventory.php`. Direct `public/inventory_module/*.php` inventory URLs redirect to the corresponding public routes while preserving query parameters and request methods. The original `realtime_inventory.php` refresh URL now uses the same Current Inventory view and filters.

**Current Stock > Material Group** filters inventory using the seven supplied groups:

- Coffee & Tea Bases
- Dairy, Milk & Alternative Bases
- Syrups, Sauces & Flavorings
- Powders & Mixers
- Soda & Carbonation
- Toppings, Mix-ins & Biscuits
- Packaging & Consumables

The **Add Ingredient** picker provides the six ingredient groups and combines the group filter with name/SKU search. Packaging stays in its own recipe section. Edit an inventory record to change its group.

The supplied catalog has been applied to this workspace: **33 missing records were added at zero stock**, and **9 existing milk and packaging records were reused**. Existing names, units, balances, recipes, and movement history were preserved. No new ingredients were assigned to products automatically.

On 9 October 2026, at the user's request, generated opening stock estimates were added to the 33 previously empty materials. All 42 items now have positive balances, while the 9 original balances and all recipe quantities were preserved. Each generated addition is labeled in Movement History. See [the full opening stock list](generated_opening_stock_20261009.md) for quantities, assumptions, and package-size references. Update estimates to match a physical count before relying on live stock availability.

New entries use g for powders, biscuits, spreads, sugar, and ice; ml for liquid bases, milk, syrups, sauces, and honey; and pcs for packaging. Review the name and unit against the actual product you purchase before restocking or assigning recipes. Slash-separated alternatives in the supplied list were treated as alternative names for one material. If you stock both alternatives independently, create separate records. Biscoff biscuits and Biscoff spread, and peach syrup and honey, have separate records.

Napkins and cup sleeves are optional packaging: they are consumed only when explicitly assigned a positive recipe amount. Cup sleeves never replace actual cups. An explicit wide-straw recipe replaces the automatic regular straw for that recipe. New zero-stock materials affect checkout only after they are assigned to a recipe.

## Generated starter recipes

On 9 October 2026, the user authorized starter recipes for all 80 current item/size variants. See [the complete recipe list](generated_recipes_20261009.md) for per-serving quantities, effective packaging, source references, and preparation assumptions. These are estimates to review against the cafe's actual formulas. The original 22oz jelly recipe containing only 3 ml milk was backed up before replacement; no stock, catalog details, orders, or old consumption records were changed.

Generated notes appear for each variant in Configure Recipes and both Edit Item views. Saving through either editor records that variant as custom and removes its generated note. Sizes remain independent; editing a 16oz recipe does not update 22oz. Recipe setup is repeat-safe and preserves custom edits, including a deliberately cleared recipe. Unknown products and unsupported serving styles are left for a house recipe.

Checkout now checks all assigned materials. Fresh milk still has its original 148 ml balance, which is below most generated milk-based serving amounts. Restock using actual received quantities to resolve those availability warnings.

## Order behavior

- Checkout checks the combined ingredient and packaging requirements for the whole cart, including materials shared by different products. The older Place Order, Save Order, and New Order pages use the same checks and catalog prices.
- Pending orders do not reserve or deduct stock. Their order cards show the quantities that will be used when marked Done.
- **Mark as Done** requires payment and rechecks current stock. It deducts all required materials in one transaction and records usage against the order. Repeating completion cannot deduct twice.
- Cancelled or voided pending orders consume no stock. Previously completed orders are not recalculated when recipes change.
- Restocking and recipe edits refresh pending order stock warnings. Low-stock notices include ingredients.
- Use **Movement History > Used** to review consumption. Quantity values include their units.

Manual restocks, deductions, and damage reports support quantities with two decimal places and record their changes in one transaction. Restock in the existing unit. Convert purchased amounts first (for example, 1 liter = 1000 ml). A material with stock or linked recipes cannot have its unit changed. Materials linked to recipes or audit history cannot be deleted.

## Installation on another database

Import the current `db.sql` for a new installation. For an existing installation, run:

```powershell
C:\xampp\php\php.exe database/migrations/20261009_add_raw_materials.php
C:\xampp\php\php.exe database/migrations/20261009_add_material_groups.php
C:\xampp\php\php.exe database/migrations/20261009_add_recipe_metadata.php
```

These migrations add inventory types and material groups, classify known existing materials, and preserve stock quantities and recipes. They are safe to run again and have already been applied to this workspace's database.

To add the supplied catalog at zero stock on another installation, optionally run:

```powershell
C:\xampp\php\php.exe database/migrations/20261009_seed_material_catalog.php
```

This command reuses matching names and aliases, preserves existing records, and adds only missing materials. Repeating it adds no duplicates. It has already been applied to this workspace. Enter actual stock balances and recipe amounts before using the new ingredients in orders.

## Optional generated quantities for a setup database

The catalog seed above creates materials at zero stock. To preview the separate generated quantity setup:

```powershell
C:\xampp\php\php.exe database/migrations/20261009_seed_generated_stock.php
```

Preview runs stock updates and their audit checks inside a transaction, then rolls back. Add `--apply` only when intentionally loading generated estimates. Only listed materials with zero stock and no movement history are initialized; existing counts are preserved. Repeating setup cannot refill materials that were already initialized or later consumed. This opt-in setup has already been applied to this workspace.

## Optional generated menu recipes on another installation

Apply the recipe metadata migration above before updating application code. The starter recipes use existing material SKUs and units. Preview with:

```powershell
C:\xampp\php\php.exe database/migrations/20261009_seed_product_recipes.php
```

Add `--apply` to intentionally save the missing starter recipes. The command saves an original-recipe backup before committing. Existing recipes are preserved. The narrow `--replace-placeholder` option replaces only the original unannotated 22oz Nobyembre Jello recipe whose only entry is 3 ml fresh milk; it does not replace custom or previously generated recipes. This workspace has already been initialized with both flags. The preview checks units, quantity multiplication, matching packaging, and preservation of stock and orders, then rolls back.

## Verification

```powershell
C:\xampp\php\php.exe tests/inventory_service_test.php
C:\xampp\php\php.exe tests/generated_recipe_test.php
C:\xampp\php\php.exe tests/material_catalog_test.php
C:\xampp\php\php.exe tests/inventory_form_test.php
C:\xampp\php\php.exe tests/order_entry_inventory_test.php
node tests/item_recipe_form_test.js
C:\xampp\php\php.exe tests/inventory_recipe_editor_test.php
node tests/inventory_recipe_workspace_test.js
```

These checks use connection-local temporary tables, leaving real stock and orders untouched.
## Compact Movement History

Open **Inventory > Movement History** to browse ten records per page. The Item field accepts a typed name or SKU and offers inventory-item suggestions, including cup sizes. Item, date, and type filters apply to every page; Reset returns to today's unfiltered movements. Previous and Next keep navigation inside the same panel.

The table shows Date & Time, Item, Type, Quantity, Balance After, and View Details. **Balance After** is the balance recorded immediately after that movement; it does not change when stock changes later. View Details includes complete remarks, critical level, SKU, exact timestamp, and current stock separately. Missing historical balances are labeled Not recorded.

Automatic refresh preserves the current page and scroll position, waits while details are open, and ignores stale responses from earlier filters. Browsing history does not modify inventory or audit records. Older matching records remain available beyond the previous 500-record limit.

Validation: `php tests/inventory_history_test.php` and `node tests/inventory_history_workspace_test.js` use temporary database tables and DOM fixtures.

## Compact Current Stock and Voided / Cancelled

Current Stock uses ten records per page with search, material group, type and stock-status filters. The six columns keep item/SKU/size, group/type, stock with its unit, status, details, and existing Edit/Delete actions together. View Details includes critical level, date added and last restock. Critical-stock alerts are summarized instead of listing every item above the table.

Voided / Cancelled defaults to today's exceptions. Search by order reference, numeric ID, customer or order creator; combine this with a date range and Voided/Cancelled status. Both legacy `void` and `voided` values appear as Voided. The tab badge always counts today's exceptions, while pagination counts the selected results. Details include the customer, order creator, total, status, timestamps and service details. For legacy records without a void/cancellation timestamp, the table identifies its creation-time fallback and the popup states that the event time was not recorded.

Both lists share a bounded table frame, Previous/Next navigation, automatic refresh that retains page/scroll/focus, and read-only details dialogs. Filters reset to page one. Refresh waits for an open details dialog, ignores stale responses and keeps previous results on an error. Summary counts remain independent of the selected stock page.

Validation: `php tests/inventory_lists_test.php` and `node tests/inventory_lists_workspace_test.js` exercise temporary database records, real PHP form fragments and DOM fixtures. Existing inventory and recipe POST-handler tests also cover the retained edit/restock/save workflows.
