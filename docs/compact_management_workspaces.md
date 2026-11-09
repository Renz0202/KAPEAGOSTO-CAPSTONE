# Compact management workspaces

Dashboard, User Management, Manage Item, Manage Category and Customer History load the scoped `public/css/management-workspace.css` stylesheet after the existing application theme. These five pages carry `compact-management-page`; Inventory, payment screens and other modules keep their own layout. Customer History also carries its own page class so report screens are unaffected.

## Dashboard

The four existing totals sit above Sales & Orders, Inventory and AI Forecast tabs so they remain visible when switching sections. Compact headings, metric cards and chart frames reduce vertical space. Activity/inventory previews contain at most five records in both initial HTML and live responses. Recent orders, menu and inventory previews link to their full modules. Manager links keep their existing permitted destinations. Chart IDs, live metric IDs and tab keyboard behavior are retained.

## User Management

The five columns are Account (photo, name, username), Role, Status, Details and Actions. Search accepts name, username, ID, email or contact. Combine it with role and status filters. The list shows ten matching accounts per page with Previous/Next and a result range. View Details shows full contact information and registration date.

The protected administrator and current-account action restrictions are unchanged. Existing Create/Edit, Activate/Deactivate and confirmed Delete handlers remain in use.

## Manage Item

The six administrator columns are Item (thumbnail, name, SKU), Category, Cup Size, Price, Details and Actions. Existing search/category/size filtering now applies to a ten-item page. Selecting a category limits cup-size options to sizes present in that category. Search includes item ID and SKU. Read-only catalog roles have five columns and receive no create/edit/delete controls or recipe data.

Administrator details include saved quantities per serving, recipe provenance and notes. The preview uses shared size/temperature matching and the same lowest-ID automatic cup/lid/straw/stirrer defaults as POS. Explicit packaging overrides suppress matching defaults. Missing ingredients, missing packaging or incompatible recipe materials are identified for review. Opening details does not save a recipe or deduct stock.

## Manage Category

Three columns show Category (thumbnail, name and actual ID), Linked Items and Actions. Search by name or ID, then filter categories with or without linked products. Ten matching categories appear per page. Clicking the linked-item count opens Manage Item with that category selected. The original create modal, upload preview, CSRF token, Edit link and confirmed Delete action remain in use.

## Customer History

Customer Records, Walk-in Records, Pending Records and Void Records each have independent ten-record pages with Previous/Next, a result range and a page count. Selecting a tab and paging keeps reloadable URL state. Search applies across all four sections and resets their pages. Customer rows combine name/code and keep Orders, Completed Sales, Last Order, Details and History. Order rows combine code/customer and keep Date, Status, Total, Details and History. View Details shows full contact information or the actual creation/void timestamps. A missing void timestamp is labeled as not recorded, with creation time shown separately.

`includes/customer_history.php` retains the existing read-only customer/order queries and aggregate definitions, then returns only the requested ten-row slice for each tab. `public/scripts/customer-history.js` reuses the server-rendered initial state and polls every five seconds. Live updates preserve tab, page and frame scroll, skip unchanged rows, pause during details/row interaction or while hidden, and reject stale responses. Search is debounced and aborts superseded requests. Failed requests leave existing results visible. No-JavaScript section and page links remain available. The existing administrator/manager permission checks are retained.

## Shared list behavior

`public/scripts/management-list.js` paginates the records already loaded by each module. It keeps URL filters and page state reloadable, normalizes invalid filter/page values, resets to page one on filter changes, announces the visible result range, and returns focus after closing the native details dialog. Validation redirects that reopen an editor reveal that account/item's page. Existing modal handlers still bind to every record.

Desktop tables stay inside a bounded frame with a sticky header. At narrow widths the rows become labeled cards, avoiding a wide table that requires sideways sliding. Existing actions remain visible on each card. With JavaScript unavailable, Users, Items and Categories expose their server-rendered records as a full list. Customer History keeps ten-row server pages with fallback section/page links.

## Desktop viewport layout

The shared stylesheet include loads `viewport-workspace.css` and the deferred `viewport-workspace.js`. This controller activates only when a page has the management sidebar, top bar and `main.content`, at a screen width of at least 1101px and height of at least 640px. Cashier POS, receipts, authentication, welcome pages and pages without the full shell keep their own layout.

The workspace sits between the actual top-bar bottom and the screen bottom, with a small inset. The browser document no longer grows with the record count. Users, Categories, Items and Customer History give the remaining height to the table and keep filters/pagination visible. Inventory supports Current Stock, Movement History, Voided/Cancelled and the recipe tab, including live wrapper replacements. Dashboard tabs, the order queue, menu/ticket and administrator payment panels own their scroll areas. Longer reports/settings and supplementary forms use one scrolling workspace. Sidebar navigation scrolls separately when necessary, keeping its header/footer available. Viewport styles preserve the established sidebar width, logo height, menu typography, spacing and collapse controls; only the content workspace is made compact.

Resize and mutation observers update fitting when navigation collapses, the viewport changes, tabs switch, notices appear, fonts load or live content is replaced. If fixed controls overflow or a record panel would be too short to read, the module falls back to natural content flow inside one accessible workspace. At smaller/shorter screen sizes, fixed viewport constraints are removed entirely. Existing labels, focus settings, scroll positions and business handlers are preserved. Print rules restore normal flow.

## Validation

```powershell
C:\xampp\php\php.exe tests/management_workspace_render_test.php
node tests/management_list_workspace_test.js
C:\xampp\php\php.exe tests/customer_category_workspace_test.php
node tests/customer_history_workspace_test.js
node tests/viewport_workspace_test.js
node tests/item_recipe_form_test.js
C:\xampp\php\php.exe tests/inventory_recipe_editor_test.php
```

These checks render the real PHP templates with role fixtures, exercise the actual list client using DOM fixtures, and compare recipe previews to the POS calculator using connection-local temporary tables. They do not modify real accounts, products or stock. Browser visual review was unavailable in this environment because the Windows computer-use helper could not initialize.
## Sales Reports layout

`public/reports_module/reports.php` loads `reports-workspace.css` and exposes a dedicated Sales Reports workspace. Its header, summary, date/range controls and selected-date details retain their natural height. Generated history uses one labelled, keyboard-accessible record frame, including its empty state; narrow screens display labelled report cards. Empty-state action icons use their normal button size. Report queries, permissions, generation and export handlers are unchanged.

The shared viewport controller includes the report history frame in fitting checks. Expanded range tools or a short window fall back to a single scrolling workspace. Document-mode grids use `grid-auto-rows: max-content` so fixed-height containment cannot compress a hero with hidden overflow. This corrects the report heading clipping caused by the earlier shared layout update.

`C:\xampp\php\php.exe tests/reports_workspace_test.php` covers 18 real-template rendering cases without a database connection. `node tests/viewport_workspace_test.js` covers 39 viewport behaviors, including Reports and its short-frame/range-tools fallback. Syntax, asset paths and served stylesheet checks pass. Live browser visual review remains blocked by the Windows helper initialization issue.
