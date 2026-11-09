<?php
/**
 * Help content is deliberately independent of live records and external services.
 * Selectors locate controls for explanation only; they never execute actions.
 */
function user_guide_role_profile(string $role): array {
    return match (normalize_role_name($role)) {
        'admin' => [
            'summary' => 'Manage the cafe, assign account roles, maintain products and inventory, and oversee sales and system settings.',
            'steps' => [
                'Use User Management to create accounts and assign the role needed for each person.',
                'Maintain categories, menu prices, and recipes for each cup size.',
                'Review stock balances and movement history, then record verified stock changes.',
                'Review sales and reports, support transactions, and maintain system settings and backups.',
            ],
            'sections' => [
                ['Accounts and access', 'Assign Admin, Manager, or Cashier in User Management. Each account receives the guide for its assigned role.'],
                ['Products and inventory', 'Manage menu details, recipes, stock receipts, and adjustments. Check quantities and units before saving.'],
                ['Sales and oversight', 'Process orders when needed, review payments and reports, and handle actions requiring admin approval.'],
                ['System tools', 'Maintain business settings, service configuration, maintenance status, and backups.'],
            ],
        ],
        'manager' => [
            'summary' => 'Review business performance, order history, and inventory summaries. Prepare reports and review automatic sales and inventory forecasts.',
            'steps' => [
                'Review sales, orders, and inventory summaries on the Dashboard.',
                'Use Order List and Customer History to review recorded activity.',
                'Generate or export reports for the date or period you need.',
                'Use Forecast & Team Settings to review automatic forecasts and cashier trends. Ask an admin for changes outside your access.',
            ],
            'sections' => [
                ['Performance and reports', 'Review dashboard results and generate or export sales reports.'],
                ['Order and menu review', 'View order history, customer history, product details, and prices. Your role reviews orders; payment, completion, and void actions belong to Admin and Cashier accounts.'],
                ['Planning settings', 'Review automatic sales estimates and reorder recommendations. These values do not change actual stock quantities.'],
                ['Ask an admin', 'Request account or role changes, menu or recipe corrections, stock adjustments, and system setting changes from an admin.'],
            ],
        ],
        'cashier' => [
            'summary' => 'Serve customers by creating orders, recording payments, completing paid sales, and providing receipts.',
            'steps' => [
                'Check the menu and choose the correct product and cup size.',
                'Build and review the ticket in Manage Order, then continue to payment.',
                'Record the amount actually received and check any change due.',
                'Mark a fully paid order Done, provide the receipt, and use Order List to review the sale.',
            ],
            'sections' => [
                ['Customer orders', 'Create tickets and check products, sizes, quantities, and totals before payment.'],
                ['Payments and completion', 'Record payments and complete paid orders. Completion records recipe ingredients and packaging usage once.'],
                ['Order history and receipts', 'Find previous orders, check their status, and view or print receipts.'],
                ['Ask an admin', 'Request account or role changes, menu or recipe corrections, and stock adjustments. Follow the admin approval process when voiding an order.'],
            ],
        ],
        default => [],
    };
}

function user_guide_topic_access(string $role, string $topic): string {
    $role = normalize_role_name($role);
    if ($topic === 'role') return $role === 'admin'
        ? 'You assign account roles in User Management. This guide follows the access for your current role.'
        : 'An admin assigns your account role. This guide follows that role; ask an admin if your work requires different access.';
    if ($topic === 'profile') return 'You can update your own profile and password using the fields available on this page.';
    if ($topic === 'notifications') return 'You can read your notifications and mark them read. Review the related issue with the person responsible for that area.';
    if ($topic === 'maintenance') return $role === 'admin'
        ? 'You can manage maintenance status in System Settings.'
        : 'You can read the maintenance notice. An admin controls when transactions resume.';
    if ($role === 'admin') return match ($topic) {
        'dashboard' => 'You can review business results and open the related management pages.',
        'users' => 'You can manage accounts, assign roles, and change account status, subject to protected-account rules.',
        'inventory' => 'You can maintain materials, configure recipes, record stock changes, and review movement history.',
        'items', 'categories' => 'You can add, edit, and remove catalog records using the available management controls.',
        'orders', 'payment', 'order-list' => 'You can review orders, process payments, complete paid sales, and handle order cancellations.',
        'customers' => 'You can review customer and order history using the available filters.',
        'reports' => 'You can view, generate, and export reports.',
        'planning' => 'You can review automatic sales estimates, team trends, and stock forecasts.',
        default => 'You can maintain the settings and tools available on this page. Review each change before saving.',
    };
    if ($role === 'manager') return match ($topic) {
        'dashboard' => 'You can review sales, orders, stock summaries, and forecasts.',
        'items' => 'You can view products, cup sizes, and prices. Ask an admin to change menu details or recipes.',
        'order-list' => 'You can review order history and receipts. Ask an Admin or Cashier to process payments, complete orders, or handle cancellations.',
        'customers' => 'You can review customer and order history using the available filters.',
        'reports' => 'You can view, generate, and export reports.',
        'planning' => 'You can review automatic sales estimates, team trends, and stock forecasts. Ask an admin to change actual stock balances.',
        default => '',
    };
    if ($role === 'cashier') return match ($topic) {
        'items' => 'You can view products, cup sizes, and prices. Ask an admin to change menu details or recipes.',
        'orders', 'payment', 'order-list' => 'You can create orders, record payments, complete paid sales, and review receipts. Follow the admin approval process for voids.',
        default => '',
    };
    return '';
}

function user_guide_catalog(string $role): array {
    $role = normalize_role_name($role);
    $topics = [];
    $add = static function (string $id, string $title, string $page, array $roles, string $intro, array $steps, array $sections, array $controls = [], array $aliases = []) use (&$topics, $role): void {
        if (!in_array($role, $roles, true)) return;
        $access = user_guide_topic_access($role, $id);
        $topics[] = compact('id', 'title', 'page', 'intro', 'steps', 'sections', 'controls', 'aliases', 'access');
    };
    $all = ['admin', 'manager', 'cashier'];
    $leaders = ['admin', 'manager'];
    $sales = ['admin', 'cashier'];
    $control = static fn(string $selector, string $label, string $text): array => compact('selector', 'label', 'text');
    $profile = user_guide_role_profile($role);
    if ($profile) $add('role', 'Your Role & Tasks', '', $all, $profile['summary'], $profile['steps'], $profile['sections']);
    $add('dashboard', 'Dashboard', 'dashboard.php', $leaders,
        'Review cafe sales and orders, inventory coverage and purchase planning, and sales analytics from one dashboard. Summary cards stay visible while you switch tabs.',
        ['Check the summary cards for current sales, orders, user accounts, and inventory alerts.', 'Choose Sales & Orders, Inventory Forecasting, or AI Sales Analytics.', 'Read the period and notes shown with each forecast; use the linked page to review or manage the underlying records.'],
        [
            ['Sales & Orders', 'Review the monthly revenue and today\'s orders in the summary cards. This tab shows the average completed order value, overall completed orders, the year\'s revenue and order trend, order status mix, recent orders, and top items this month. Use Manage Orders or View all to open the related records.'],
            ['Inventory Forecasting', 'The summary shows inventory turnover, five recently updated materials, and counts of monitored materials, restock needs, empty stock, and materials without usage history. The forecast table shows on-hand quantity, average daily usage, days of coverage, reorder point, suggested purchase quantity, and status. Usage is based on the last 14 complete days. The reorder point covers saved supplier lead time plus a 2-day buffer; the target purchase quantity adds 7 days of coverage. Different material units are not combined.'],
            ['Stock outlook & purchase budget', 'Choose a material and a 7-, 14-, or 30-day planning horizon to see its projected balance and the reorder threshold. The purchase estimate covers supplier lead time, the selected horizon, and the safety buffer. Enter a temporary cost per inventory unit to estimate the budget. This cost is not saved, and the estimate does not include delivery, taxes, or supplier pack sizes. It does not change stock or place an order.'],
            ['Coverage and readiness charts', 'Compare estimated days of material coverage with supplier lead time for up to eight materials. The readiness chart counts materials that need restocking, have sufficient stock, or are waiting for usage history. Use the material search and restock-status filter to narrow the forecast table.'],
            ['AI Sales Analytics', 'Review the last 28 days of actual sales, 7-day averages, saved forecasts, and tomorrow\'s revenue or completed-order estimate. Product movement and the analytics cards summarize weekly sales trend, tomorrow\'s forecast, best seller, forecast accuracy, fast-moving products, and slow or inactive products. Forecasts are estimates, not confirmed orders.'],
            ['How does Sales Analytics work?', 'Sales Analytics analyzes recorded sales and statistics to discover patterns, answer business questions, and support decisions about stock and staffing. Actual sales include completed orders only. The graph shows the last 28 days and tomorrow\'s estimate. Today\'s figures are partial until the day ends.'],
            ['Read the line graph', 'Use Show to switch between Revenue (PHP) and Completed orders. The brown line shows actual results, the teal line shows the 7-day average, purple markers show saved forecasts where available, and the dashed gold line connects today\'s actual result to tomorrow\'s estimate. Hover over points for exact values, click legend labels to hide or show a series, or expand View chart data to read the values in a table.'],
            ['How tomorrow\'s estimate is calculated', 'The local forecast combines recent and preceding 7-day averages, adjusts for their trend, and—when at least three matching weekdays are available—blends that result with up to four recent matching weekdays. Today\'s partial sales are displayed separately and excluded from the estimate and period comparison. Forecasts are estimates, not guaranteed sales or confirmed orders.'],
            ['Compare predictions with results', 'Saved forecasts are the first estimates recorded for each date. Compare their markers with the actual sales line on the same date. Missing markers mean no forecast was saved. The Forecast Accuracy card averages evaluated revenue, order-count, and item-demand forecasts over the last 30 days; it is not a revenue-only score.'],
            ['Use patterns to make decisions', 'Read the weekly sales change and tomorrow\'s estimate alongside inventory coverage and the recommendation. Rising demand can help you plan stock and staffing; low coverage can highlight materials to review for restocking. Check reports for the underlying records. Sales figures alone do not show profit or explain why a change happened.'],
            ['Local analytics and AI support', 'Sales forecasts and inventory planning use local calculations and remain available when AI is disabled. AI creates a recommendation from sales summary metrics only when requested. Generating is limited to one request every 30 seconds; the dashboard refreshes local forecasts every 15 seconds without using AI API quota.'],
        ], [
            $control('#dashboard-tab-sales', 'Sales & Orders', 'Shows sales totals, order activity, and charts. Check the displayed date or period.'),
            $control('#dashboard-tab-inventory', 'Inventory Forecasting', 'Shows stock coverage, consumption, reorder points, and recommended purchases. The badge counts materials needing restock.'),
            $control('#dashboard-tab-ai', 'AI Sales Analytics', 'Shows sales patterns, product performance, demand estimates, and recommendations. These are estimates rather than confirmed orders.'),
            $control('#dashboard-panel-sales', 'Sales & Orders panel', 'Review the average completed order value, order and revenue trend, status mix, recent orders, and top selling items.'),
            $control('#dashboard-panel-inventory', 'Inventory Forecasting panel', 'Review stock turnover, latest material balances, restock counts, material projections, and recommended purchases.'),
            $control('#inventoryForecastSummary', 'Inventory status cards', 'Counts monitored materials, items needing restock, empty stock, and items without usage history.'),
            $control('#ifMaterial', 'Material', 'Choose which inventory material to project. Its quantity and unit remain separate from other materials.'),
            $control('#ifHorizon', 'Planning horizon', 'Choose 7, 14, or 30 days for the projected balance and purchase estimate.'),
            $control('#ifCost', 'Estimated cost per unit', 'Enter a temporary PHP cost for one inventory unit to estimate a purchase budget. The value is not saved or used to order stock.'),
            $control('#ifBudget', 'Planning purchase estimate', 'Shows the suggested quantity for lead time, the selected horizon, and safety buffer. A budget appears only after you enter a unit cost.'),
            $control('#ifProjection', 'Projected stock balance', 'Compares the selected material\'s projected quantity with its reorder threshold. It assumes the current daily usage continues without deliveries.'),
            $control('#ifCoverage', 'Coverage versus supplier lead time', 'Compares estimated stock coverage with supplier lead time for materials with usage history.'),
            $control('#ifRisk', 'Inventory readiness', 'Counts materials by restock readiness, including materials without enough usage history.'),
            $control('#ifSearch', 'Find material', 'Searches material names and units in the forecast table.'),
            $control('#ifStatus', 'Restock status', 'Filters the forecast table to all materials, restock needed, sufficient stock, or items awaiting usage history.'),
            $control('#dashboardInventoryForecastRows', 'Stock coverage & recommended purchases', 'Review on-hand quantity, average daily use, estimated coverage, reorder point, suggested purchase quantity, and status. The forecast does not change stock or place orders.'),
            $control('#generateAiRecommendation', 'Generate New AI Recommendation', 'Requests an AI recommendation from recent sales summaries when AI is enabled and configured. The button has a 30-second cooldown; automatic dashboard refresh updates local forecasts and does not request AI.'),
            $control('#aiDataUsed', 'Sales summary used by AI', 'Expand to review the summary metrics included with the generated AI recommendation.'),
            $control('#aiSalesMetric', 'Sales Analytics metric', 'Switch between revenue in PHP and completed order counts. The chart and data table update together.'),
            $control('#aiSalesChart', 'Actual sales & forecast', 'Compare actual sales, the 7-day average, saved forecast markers, and tomorrow\'s estimate. Hover over a point for values. Today is still in progress.'),
            $control('.ai-sales-method', 'Chart data', 'Expand View chart data for the daily values. Read this guide for the calculation method.'),
        ]);
    $add('users', 'User Management', 'users.php', ['admin'],
        'Create accounts and give each person the access needed for their work.',
        ['Choose Create User and enter the person’s details.', 'Select Admin, Manager, or Cashier, then save.', 'Use Edit to update details or account access.'],
        [
            ['Admin', 'Manages accounts, products, inventory, transactions, and system settings.'],
            ['Manager', 'Reviews business results, reports, order history, and planning settings.'],
            ['Cashier', 'Creates orders, records payments, and completes sales.'],
            ['Role assignments and guide', 'Select the role that matches each person\'s work. Their guide shows the tasks and pages available for that role when they refresh or open a page.'],
            ['Account status', 'Deactivate pauses access. Activate restores access. Permanent deletion removes the account and is separate from deactivation.'],
        ], [], ['create_user.php', 'edit_user.php']);
    $add('categories', 'Manage Category', 'categories.php', ['admin'],
        'Organize menu products into categories so they are easier to find.',
        ['Create a category with a clear name.', 'Choose an image if needed.', 'Assign the category when adding or editing a product.'],
        [['Edit', 'Update a category name or image.'], ['Delete', 'Review the selected category and its related products before confirming removal.']], [], ['add_category.php', 'edit_category.php']);
    $itemSteps = $role === 'admin'
        ? ['Search or filter the menu by category or cup size.', 'Use Add Item or Edit to maintain a product’s details and price.', 'Set the recipe for every cup size in Inventory > Configure Recipes.']
        : ['Search or filter the menu by category or cup size.', 'Check the product name, size, and price.', 'Ask an admin to correct product or recipe details.'];
    $add('items', 'Manage Item', 'items.php', $all,
        'Browse the menu and check each product’s cup size and price.',
        $itemSteps,
        [['Search and filters', 'Narrow the list to the product, category, or size you need.'], ['View details', 'Open more information about the selected product.']],
        [$control('[data-compact-details]', 'View details', 'Opens the details for this product.'),
         ...($role === 'admin' ? [$control('.js-open-create-item', 'Add Item', 'Opens a form to create a real menu product. Enter its category, cup size, price, and other details.'),
         $control('.js-open-edit-item', 'Edit Item', 'Opens this product’s details for editing. Saving changes updates the menu.')] : [])],
        ['add_item.php', 'edit_item.php']);
    $add('inventory', 'Manage Inventory', 'inventory.php', ['admin'],
        'Maintain materials, connect recipes, and review stock movements.',
        ['Add materials using g, ml, or pcs and enter a verified starting quantity.', 'Configure each product and cup size with the amount used for one serving.', 'Review stock and movement history after completed sales.'],
        [
            ['Current Stock', 'See the current balance and saved cost per unit. Restock records received materials. Report Damage or Spoilage to reduce stock and record the quantity, reason, and estimated peso loss. Set a cost per unit in Add Inventory or Edit Inventory so each loss can be valued.'],
            ['Configure Recipes', 'Choose a product and cup size. Add ingredients and enter the amount for one serving. Save each size separately. Cups, lids, and a straw or stirrer are included automatically. Saving a recipe does not deduct stock.'],
            ['Movement History', 'Review restocks, deductions, and usage. Past use shows the original sale date; Recorded On shows when the entry was added. Historical recipe estimates preserve the balance recorded when they were added.'],
            ['Voided / Cancelled', 'Review cancelled orders and their recorded inventory effects. Check the movement details for any returned stock.'],
        ], [
            $control('#inventory-tab-stock', 'Current Stock', 'Shows current material balances, stock filters, and stock actions.'),
            $control('#inventory-tab-recipes', 'Configure Recipes', 'Connects ingredients and quantities to one serving of a product and cup size.'),
            $control('#inventory-tab-logs', 'Movement History', 'Shows stock movements. Past use follows the original sale date; Recorded On is the date the record was added.'),
            $control('#inventory-tab-voided', 'Voided / Cancelled', 'Shows cancelled orders and recorded stock returns.'),
            $control('#inventoryRecipeAddIngredient', 'Add Ingredient', 'Opens the ingredient picker for this recipe. Add the amount used for one serving.'),
            $control('#inventory_recipe_product', 'Product', 'Choose the menu product whose recipe you want to configure.'),
            $control('#inventory_recipe_variant', 'Cup Size', 'Choose a size. Every size has its own recipe and must be saved separately.'),
            $control('#inventory_recipe_category', 'Category', 'Filters the recipe product list by category.'),
            $control('#inventoryRecipeForm button[type="submit"]', 'Save Recipe', 'Saves the selected size’s recipe for future orders. It does not change existing stock or rewrite past sales.'),
            $control('[data-list-details][aria-controls="inventoryStockDetails"], [data-log-details]', 'View details', 'Shows the full details for the selected stock or movement record.'),
        ], ['restock.php', 'deduct.php']);
    $add('orders', 'Manage Order', 'orders.php', $sales,
        'Build a customer’s ticket, check the total, and continue to payment.',
        ['Enter the customer name if needed, then choose a category or search the menu.', 'Select the correct product and cup size. Review quantities in the ticket.', 'Check Apply active promo to use the rate set in Settings. Admins set the rate and optional date range in System Settings; managers set them in Forecast & Team Settings. The promo applies only within the inclusive date range. Verify a Senior/PWD ID and exclusive use for statutory discounts. The POS applies only the more favorable discount, then continue to payment.'],
        [
            ['Menu', 'Selecting a product adds it to the current ticket. Check the cup size before adding it.'],
            ['Ticket', 'Adjust quantities or remove an item before checkout. Clear removes the current ticket’s items.'],
            ['Customer Discount', 'An admin sets the promotional percentage and optional date range in System Settings; a manager sets them in Forecast & Team Settings. All roles may apply the saved rate to a ticket while its date range is active, but the percentage cannot be changed in the POS. Leave both dates blank for no date limit. Senior/PWD discounts require the beneficiary name, ID number, and confirmation that the items are for that person’s exclusive use. The POS compares offers and does not stack discounts.'],
            ['Pending order', 'An order awaiting completion does not deduct ingredients. Paid orders deduct recipe ingredients and packaging when marked Done.'],
        ], [
            $control('#searchInput', 'Search menu', 'Finds a menu product by name or cup size.'),
            $control('#customer_name', 'Customer name', 'Enter the customer name, or leave the walk-in name when appropriate.'),
            $control('#orderItemsGrid [data-id]', 'Add product to ticket', 'Adds this product and cup size to the ticket. Review the quantity and total before payment.'),
            $control('#clearCartBtn', 'Clear ticket', 'Removes the items from the current ticket.'),
            $control('#checkoutForm button[type="submit"]', 'Continue to Payment', 'Creates the order from the current ticket and opens payment. Check the customer, products, sizes, and total first.'),
        ], ['new_order.php', 'order_create.php']);
    $add('payment', 'Payment & Completion', '', $sales,
        'Record the amount received, confirm payment, complete eligible paid orders, and provide the customer receipt.',
        ['Check the order number, items, order total, amount already paid, and amount remaining.', 'Choose the payment method actually used. For cash, enter the full amount received and check the recorded cash and change.', 'Confirm the payment result before retrying. A sales invoice is issued when the order is fully paid.', 'When eligible and fully paid, select Mark as Done. Then review the receipt and give the customer copy.'],
        [
            ['Payment method', 'Choose the method actually used by the customer. Check any reference information before saving.'],
            ['Cash received and change', 'Enter the full cash handed over, not only the remaining balance. Confirm the recorded cash and change after payment.'],
            ['Sales invoice', 'The receipt shows the order, transaction, and invoice numbers when available. An invoice is issued after full payment; an unpaid order may show an order summary instead.'],
            ['Receipt copy', 'Review the items, discount, totals, payment status, cash received, and change. Use Print / Reprint for the customer copy, then return to orders when finished.'],
            ['Mark as Done', 'Completes a paid order and deducts its recipe ingredients and packaging once. Payment and completion are separate actions.'],
            ['Void', 'Requires admin approval where requested. Review the order and any stock effects before confirming.'],
        ], [
            $control('#payInput', 'Cash Received from Customer', 'Enter the full cash amount received and verify the resulting change.'),
            $control('#submitOrderBtn', 'Mark as Done', 'Completes this paid order and records its stock usage once.'),
            $control('#voidOrderBtn', 'Void Order', 'Requests cancellation of the selected order. Review the order and required admin approval before confirming.'),
        ], ['order_payment.php', 'payment_success.php']);
    $orderSections = [
        ['Search, date, and status', 'Choose the date, status, or search term. Use the full-history option when reviewing earlier orders.'],
        ['View / Print', 'Open the matching order and check its order number, transaction number, date, items, and status. Use Print / Reprint on the receipt to produce another customer copy.'],
        ['Receipt and invoice', 'A completed receipt can show the business profile and configured BIR details, itemized sales, discounts, totals, payment status, cash received, and change. The Sales Invoice No. is available after full payment. Check the displayed amounts and customer copy before handing it over.'],
    ];
    if (in_array($role, $sales, true)) $orderSections[] = ['Pay / Done / Void', 'Pay opens an unpaid order’s payment page. Done completes a paid order and records stock usage once. Void cancels an order with the required approval.'];
    $add('order-list', 'Order List', 'order_list.php', $all,
        'Find orders, review their status, and open or print the matching receipt.',
        ['Choose the date range or full history.', 'Filter by status or search for the order.', 'Open the matching order and check its details before using an available action.'],
        $orderSections, array_merge(in_array($role, $sales, true) ? [
            $control('.order-action-btn.done', 'Mark as Done', 'Completes a fully paid order. Its recipe ingredients and packaging are deducted once.'),
        ] : [], [
            $control('.receipt-action-btn.primary', 'Print / Reprint', 'Prints the displayed receipt or sales invoice. Confirm the order and customer copy first.'),
            $control('.receipt-action-btn.secondary', 'Back to Orders', 'Returns to the order list after reviewing or printing the receipt.'),
        ]), ['view_order.php', 'receipt_template.php', 'order_void.php']);
    $add('customers', 'Customer History', 'customers.php', $leaders,
        'Review customer purchases and related order history.',
        ['Choose the customer or order group you need.', 'Use search and date filters to find the records.', 'Open an order to check its date, items, and status.'],
        [['Customer tabs', 'Switch between the customer and order groups shown on this page.'], ['Full history', 'Includes earlier records beyond the current date filter.']],
        [$control('#customer-search', 'Search customer history', 'Finds matching customer or order records within the selected filters.'),
         $control('#customerHistoryReset', 'Reset filters', 'Clears the customer history filters and returns to the default view.')]);
    $add('reports', 'Reports', 'reports.php', $leaders,
        'Review saved daily reports and generate or export reports for a selected date.',
        ['Select the date you want to review.', 'Open a saved report, or generate a report for that date.', 'Use the date range tools when generating or exporting several days.'],
        [
            ['Selected date', 'Shows the saved report available for the chosen day.'],
            ['Generate report', 'Creates or updates a saved report from the recorded transactions.'],
            ['Export', 'Downloads the report for the selected date or range.'],
            ['Generated Reports', 'Lists saved reports. Open the correct date to review its details.'],
        ], [
            $control('#report_date', 'Report date', 'Choose the day whose report you want to review.'),
            $control('#showRangeToolsBtn', 'Date range tools', 'Shows the start and end date fields for range generation or export.'),
            $control('#btnGenerateRange', 'Generate range', 'Creates saved daily reports for the selected date range. Review the dates first.'),
            $control('#btnExportRange', 'Export range', 'Downloads reports for the selected date range.'),
        ], ['view_daily_report.php', 'generate_daily_report.php']);
    $add('planning', 'Manager Settings', 'manager_settings.php', $leaders,
        'Review automatic sales estimates, team performance, and inventory planning.',
        ['Choose Sales Forecast, Team Performance, or Inventory Forecast.', 'Review estimates and compare each cashier with their previous 30 days.', 'Review calculated stock coverage and restock recommendations. No manual goals or save action are required.'],
        [['Sales Forecast', 'Daily, weekly, and monthly sales estimates are generated from completed sales history.'], ['Team Performance', 'Compare the latest 30 days, including today, with the preceding 30 days for each cashier.'], ['Inventory Forecast', 'Review generated safety stock, reorder points, and target stock from usage and saved supplier lead time. These estimates do not change stock balances or place purchase orders.']]);
    $add('settings', 'System Settings', 'settings.php', ['admin'],
        'Maintain business profile, BIR receipt details, POS promotional discount dates, system status, email, and other administrator settings.',
        ['Update only the section you need.', 'Check the field formats, date ranges, and notes on the page.', 'Select Save Settings and review any confirmation or validation message.'],
        [
            ['BIR Registration', 'Copy the registered name, tax type, TIN, and branch code from the business registration record. Enter the TIN as 000-000-000 and put the branch code in its separate field. These values appear on receipts. Saving them in Kape Agosto does not register or verify the business with the BIR. The page notes that VAT receipts show included 12% VAT for regular menu sales and menu prices must already include VAT; NON-VAT receipts do not calculate VAT. This checkout does not support special VAT-exempt or zero-rated transactions.'],
            ['POS Promotional Discount', 'Set Promo Rate (%) from 0 to 100. Enter 0 to turn off the promo. Start and end dates are inclusive. Leave both dates blank for no date limit; when using a date range, both dates are required. The POS applies the saved rate only during the active period, and staff cannot edit the rate from the register.'],
            ['Business Profile', 'Maintain the business name, owner name, tagline, contact number, email, and map link. These details appear in the interface, reports, and receipt header as described on the page.'],
            ['Receipt & Localization', 'Set the receipt address and thank-you message, currency label, and system timezone. Review a receipt after changing business or receipt details.'],
            ['System status', 'Active allows transactions. Maintenance pauses order creation, payment, completion, item changes, job updates, and voiding. The displayed notice explains which review pages remain available.'],
            ['Email & SMTP', 'Configure outgoing email for password reset links and system email tools. Use Send Test Email or Mail Diagnostics to check the configuration; keep SMTP credentials private.'],
            ['AI Settings', 'Configure optional AI-assisted recommendations separately. Local sales forecasts and inventory planning do not require AI.'],
            ['Backup & Restore', 'Open Backup & Restore to create or download database backups, review schedules and logs, or restore a saved SQL backup. This tool is available to administrators only.'],
        ], [
            $control('[name="promo_discount_percent"]', 'Promo Rate (%)', 'Enter a value from 0 to 100. Set 0 to switch off the POS promo.'),
            $control('[name="promo_discount_start_date"]', 'Promo Start Date (inclusive)', 'Optional. Use together with the end date to limit when the POS can apply the promo.'),
            $control('[name="promo_discount_end_date"]', 'Promo End Date (inclusive)', 'Optional. The promo remains active through this date. If one date is entered, enter the other too.'),
            $control('[name="bir_tax_type"]', 'Tax Registration Type', 'Choose VAT or NON-VAT as shown on the business registration record.'),
            $control('[name="bir_tin"]', 'Taxpayer Identification Number (TIN)', 'Enter the TIN in the displayed 000-000-000 format.'),
            $control('[name="bir_branch_code"]', 'Branch Code', 'Enter the branch code separately from the TIN.'),
            $control('#system-mode', 'System status', 'Active allows transactions. Maintenance temporarily pauses transaction processing.'),
            $control('button.settings-save', 'Save Settings', 'Saves the settings form. Review any validation message and verify the receipt or POS result after changing related values.'),
        ]);
    $add('ai-settings', 'AI Settings', 'ai_settings.php', ['admin'],
        'Configure optional AI-assisted sales recommendations. The dashboard’s sales forecasts and inventory planning continue to use local calculations.',
        ['Enable AI-assisted analytics only if you want generated recommendations.', 'Choose the provider and optional model, then enter or replace the API key if needed.', 'Check the connection status and save. Return to the dashboard to request a recommendation.'],
        [
            ['Enable and provider', 'AI is optional. Choose the provider and optional model. Disabling AI does not turn off local sales forecasts, inventory projections, or dashboard analytics.'],
            ['API key', 'A saved key is never shown again. Leave the field blank to keep the saved key, or select Clear stored AI API key on save to remove it. Keep credentials private.'],
            ['Generate and cache', 'AI recommendations are requested from the dashboard only when Generate New AI Recommendation is clicked. The request has a 30-second cooldown and successful recommendations are cached for six hours. API use and limits depend on the selected provider.'],
            ['Connection status', 'Use the status information on this page to check whether the selected provider is configured and reachable. PHP cURL and a valid provider key are required for a live request.'],
        ], [
            $control('[name="ai_enabled"]', 'Enable AI-assisted analytics', 'Turns generated AI recommendations on or off. Local charts and forecasts continue to work when it is off.'),
            $control('[name="ai_provider"]', 'AI Provider', 'Choose the service that will receive a recommendation request.'),
            $control('[name="ai_model"]', 'AI Model', 'Optionally choose a provider model. Leave blank to use the provider default.'),
            $control('[name="ai_api_key"]', 'AI API Key', 'Enter a key to add or replace it. Leave blank to keep the currently saved key.'),
            $control('[name="clear_ai_api_key"]', 'Clear stored AI API key', 'Select this only if you want to remove the saved key when you save these settings.'),
            $control('.ai-settings-help', 'How AI recommendations work', 'Explains when AI requests are made and how recommendations are cached.'),
        ]);
    $add('backup', 'Backup & Restore', 'backup_restore.php', ['admin'],
        'Keep a database backup and restore a selected backup when required.',
        ['Open System Settings, then Backup & Restore.', 'Create a backup before a major data change and download it to a secure location.', 'For a restore, check the selected file and backup date before confirming.'],
        [
            ['Create Backup', 'Creates a copy of the current database.'],
            ['Download', 'Downloads the selected backup file.'],
            ['Restore Backup', 'Replaces the current database with the selected backup. Changes made after that backup may be lost.'],
        ], [$control('#sql_file', 'Backup file', 'Choose the SQL backup to restore. Restoring replaces the current database with that file.')], ['backup_run.php', 'restore_run.php']);
    $add('profile', 'My Profile', 'profile.php', $all,
        'Keep your own account details up to date.',
        ['Review your name, contact details, and email.', 'Update the fields you need to change.', 'Save your changes. Use the password fields only when changing your password.'],
        [['Profile image', 'Choose a supported image for your account.'], ['Password', 'Follow the password requirements shown on the form.']]);
    $add('notifications', 'Notifications', 'notifications.php', $all,
        'Read alerts and open the related records when available.',
        ['Open the notification bell or Notifications page.', 'Read the alert and check its date.', 'Use its link to review the related page, or mark it read.'],
        [['Mark as read', 'Updates the notification’s read status. It does not resolve the underlying stock or order issue.']]);
    $add('maintenance', 'Maintenance', 'maintenance.php', $all,
        'Check the system notice when transactions are temporarily paused.',
        ['Read the maintenance message.', 'Ask an admin about availability when needed.', 'Resume transactions when the system returns to active mode.'],
        [['System notice', 'Only an admin can change the system’s maintenance status.']]);
    return $topics;
}

function user_guide_current_topic(array $topics, string $path): string {
    $page = basename((string) parse_url($path, PHP_URL_PATH));
    foreach ($topics as $topic) {
        if ($page === $topic['page'] || in_array($page, $topic['aliases'], true)) return $topic['id'];
    }
    return $topics[0]['id'] ?? '';
}
