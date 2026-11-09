# Ingredient records corrected - 10 October 2026

**Applied successfully. Current stock balances were preserved.**

- Reviewed 82 completed orders and corrected all 80 with missing ingredient records.
- Added 377 historical ingredient usage estimates across 27 ingredients.
- Rechecked all completed orders: zero missing usage, zero ambiguous records, and zero over-recorded amounts against the current recipes.
- Excluded 27 pending and 9 voided orders.
- Preserved all 42 inventory balances, all packaging, all 367 original movements, and every existing order, payment, recipe, notification, and other database record.

The old sales occurred before later stock setup entries or a stock count adjustment. Their missing usage was added to history without deducting it from those later stock balances. Milk remains 2,000 ml, ice 18,320 g, and vanilla syrup 1,500 ml. No purchases or restocks were created.

New entries are labeled Historical recipe estimate. Their Balance After records the unchanged stock when this correction was posted. The old sale's actual stock balance is unknown. Recipe quantities and generated stock remain estimates until reviewed against cafe recipes and a physical count.

## Corrected ingredient history

| Ingredient | Unit | Historical usage added | Current stock after correction |
|---|---|---:|---:|
| BISCOFF SPREAD | g | 30.00 | 1,600.00 |
| BLUEBERRY SYRUP | ml | 255.00 | 1,470.00 |
| CARAMEL SYRUP | ml | 35.00 | 3,000.00 |
| CEREMONIAL MATCHA POWDER | g | 90.00 | 500.00 |
| COFFEE BEANS | g | 3,022.00 | 5,000.00 |
| CONDENSED MILK | ml | 80.00 | 2,000.00 |
| CREAM CHEESE | g | 75.00 | 1,000.00 |
| DARK CHOCOLATE SAUCE | ml | 205.00 | 2,000.00 |
| FRAPPE BASE POWDER | g | 270.00 | 2,000.00 |
| FRESH MILK | ml | 33,668.00 | 2,000.00 |
| GRAHAM CRACKERS | g | 45.00 | 1,000.00 |
| GREEN APPLE SYRUP | ml | 45.00 | 705.00 |
| HEAVY CREAM | ml | 180.00 | 2,000.00 |
| HONEY | ml | 45.00 | 990.00 |
| ICE | g | 19,510.00 | 18,320.00 |
| JELLO POWDER | g | 16.00 | 500.00 |
| KIWI SYRUP | ml | 45.00 | 705.00 |
| LOTUS BISCOFF BISCUITS | g | 8.00 | 1,000.00 |
| OREO BISCUITS | g | 25.00 | 1,000.00 |
| PASSION FRUIT SYRUP | ml | 30.00 | 675.00 |
| PEACH SYRUP | ml | 90.00 | 730.00 |
| SODA WATER | ml | 1,650.00 | 12,000.00 |
| STRAWBERRY SYRUP | ml | 190.00 | 2,175.00 |
| TEA BASE | ml | 2,380.00 | 2,840.00 |
| UBE FLAVOR | ml | 30.00 | 1,500.00 |
| VANILLA SYRUP | ml | 2,585.00 | 1,500.00 |
| WHITE CHOCOLATE SAUCE | ml | 580.00 | 2,000.00 |

## Audit and verification

- Correction batch: 265a0d52017b3606.
- Database records changed: only 377 new ingredient movement entries. Existing rows were preserved.
- Movement records before: 367; after: 744.
- All 20 other database tables were verified unchanged using complete row hashes.
- All 65 fixture checks passed, including duplicate prevention, unchanged stock, source-date validation, and full rollback after a mid-batch failure.
- Backup: [kape_agosto_20261010_065440.sql](../backups/kape_agosto_20261010_065440.sql).
- Original reviewed plan: [ingredient_history_plan_20261010_065439.md](ingredient_history_plan_20261010_065439.md).

To inspect the new entries in the app, open Inventory > Movement History, select 16 September through 8 October 2026 and Used, then open View Details. Past use follows each original sale date. Recorded On retains 10 October 2026, when the correction was added. Each entry also contains its order reference, historical estimate label, stock baseline date, and correction batch.

Repeating a correction cannot duplicate these entries. A stale plan is rejected; a fresh preview now has no missing usage.
