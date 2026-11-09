# Historical ingredient record correction

**This plan adds missing historical usage estimates and preserves all current stock balances.**

Every proposed entry must predate a later generated opening stock entry or stock count adjustment. Old usage is recorded separately from that later balance. No purchases, restocks, or historical stock balances are invented.

- Completed orders reviewed: **82**.
- Orders needing historical records: **80**.
- Ingredient usage records to add: **377** across **27** ingredients.
- Packaging is excluded. Existing movements, orders, payments, recipes, and notifications are preserved.
- New entries are marked **Historical recipe estimate**. Balance After shows the unchanged current stock when the correction is posted. The old sale's stock balance is unknown.
- Excluded pending orders: **27**.
- Excluded void orders: **9**.

The estimates use the currently saved recipes, including generated recipes. Current setup stock remains an estimate until physically counted. The shortages in the earlier subtraction-only preview do not establish a physical shortage.

| Ingredient | Unit | Missing historical usage | Current stock | Stock after correction | Later stock baseline |
|---|---|---:|---:|---:|---|
| BISCOFF SPREAD | g | 30.00 | 1,600.00 | 1,600.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| BLUEBERRY SYRUP | ml | 255.00 | 1,470.00 | 1,470.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| CARAMEL SYRUP | ml | 35.00 | 3,000.00 | 3,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| CEREMONIAL MATCHA POWDER | g | 90.00 | 500.00 | 500.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| COFFEE BEANS | g | 3,022.00 | 5,000.00 | 5,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| CONDENSED MILK | ml | 80.00 | 2,000.00 | 2,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| CREAM CHEESE | g | 75.00 | 1,000.00 | 1,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| DARK CHOCOLATE SAUCE | ml | 205.00 | 2,000.00 | 2,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| FRAPPE BASE POWDER | g | 270.00 | 2,000.00 | 2,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| FRESH MILK | ml | 33,668.00 | 2,000.00 | 2,000.00 | Stock count adjustment: 2026-10-09 20:38:41 |
| GRAHAM CRACKERS | g | 45.00 | 1,000.00 | 1,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| GREEN APPLE SYRUP | ml | 45.00 | 705.00 | 705.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| HEAVY CREAM | ml | 180.00 | 2,000.00 | 2,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| HONEY | ml | 45.00 | 990.00 | 990.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| ICE | g | 19,510.00 | 18,320.00 | 18,320.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| JELLO POWDER | g | 16.00 | 500.00 | 500.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| KIWI SYRUP | ml | 45.00 | 705.00 | 705.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| LOTUS BISCOFF BISCUITS | g | 8.00 | 1,000.00 | 1,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| OREO BISCUITS | g | 25.00 | 1,000.00 | 1,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| PASSION FRUIT SYRUP | ml | 30.00 | 675.00 | 675.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| PEACH SYRUP | ml | 90.00 | 730.00 | 730.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| SODA WATER | ml | 1,650.00 | 12,000.00 | 12,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| STRAWBERRY SYRUP | ml | 190.00 | 2,175.00 | 2,175.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| TEA BASE | ml | 2,380.00 | 2,840.00 | 2,840.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| UBE FLAVOR | ml | 30.00 | 1,500.00 | 1,500.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| VANILLA SYRUP | ml | 2,585.00 | 1,500.00 | 1,500.00 | Generated opening estimate: 2026-10-09 17:36:07 |
| WHITE CHOCOLATE SAUCE | ml | 580.00 | 2,000.00 | 2,000.00 | Generated opening estimate: 2026-10-09 17:36:07 |

## Missing usage entries

| Order | Ingredient | Unit | Recipe total | Already recorded | Missing historical usage |
|---|---|---|---:|---:|---:|
| 160926-01 | FRESH MILK | ml | 370.00 | 0.00 | 370.00 |
| 160926-01 | COFFEE BEANS | g | 40.00 | 0.00 | 40.00 |
| 160926-01 | VANILLA SYRUP | ml | 60.00 | 0.00 | 60.00 |
| 160926-01 | ICE | g | 380.00 | 0.00 | 380.00 |
| 300926-01 | FRESH MILK | ml | 630.00 | 0.00 | 630.00 |
| 300926-01 | COFFEE BEANS | g | 54.00 | 0.00 | 54.00 |
| 300926-01 | VANILLA SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 300926-01 | DARK CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 300926-01 | ICE | g | 160.00 | 0.00 | 160.00 |
| 300926-01 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 011026-01 | FRESH MILK | ml | 610.00 | 0.00 | 610.00 |
| 011026-01 | COFFEE BEANS | g | 58.00 | 0.00 | 58.00 |
| 011026-01 | VANILLA SYRUP | ml | 80.00 | 0.00 | 80.00 |
| 011026-01 | ICE | g | 380.00 | 0.00 | 380.00 |
| 021026-01 | FRESH MILK | ml | 450.00 | 0.00 | 450.00 |
| 021026-01 | COFFEE BEANS | g | 54.00 | 0.00 | 54.00 |
| 021026-01 | DARK CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 021026-01 | ICE | g | 1,260.00 | 0.00 | 1,260.00 |
| 021026-01 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 021026-01 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 021026-01 | TEA BASE | ml | 980.00 | 0.00 | 980.00 |
| 021026-01 | BLUEBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 021026-01 | STRAWBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 021026-01 | PASSION FRUIT SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 021026-01 | PEACH SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 021026-01 | HONEY | ml | 15.00 | 0.00 | 15.00 |
| 021026-05 | FRESH MILK | ml | 4,800.00 | 0.00 | 4,800.00 |
| 021026-05 | COFFEE BEANS | g | 360.00 | 0.00 | 360.00 |
| 021026-05 | CEREMONIAL MATCHA POWDER | g | 40.00 | 0.00 | 40.00 |
| 021026-05 | VANILLA SYRUP | ml | 200.00 | 0.00 | 200.00 |
| 021026-06 | FRESH MILK | ml | 300.00 | 0.00 | 300.00 |
| 021026-06 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 021026-06 | VANILLA SYRUP | ml | 50.00 | 0.00 | 50.00 |
| 021026-06 | ICE | g | 320.00 | 0.00 | 320.00 |
| 021026-07 | FRESH MILK | ml | 440.00 | 0.00 | 440.00 |
| 021026-07 | COFFEE BEANS | g | 44.00 | 0.00 | 44.00 |
| 021026-07 | VANILLA SYRUP | ml | 70.00 | 0.00 | 70.00 |
| 021026-07 | ICE | g | 440.00 | 0.00 | 440.00 |
| 021026-08 | FRESH MILK | ml | 600.00 | 0.00 | 600.00 |
| 021026-08 | COFFEE BEANS | g | 72.00 | 0.00 | 72.00 |
| 021026-08 | WHITE CHOCOLATE SAUCE | ml | 120.00 | 0.00 | 120.00 |
| 021026-08 | ICE | g | 640.00 | 0.00 | 640.00 |
| 021026-09 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 021026-09 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 021026-09 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 021026-09 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 021026-10 | FRESH MILK | ml | 2,550.00 | 0.00 | 2,550.00 |
| 021026-10 | COFFEE BEANS | g | 306.00 | 0.00 | 306.00 |
| 021026-10 | VANILLA SYRUP | ml | 425.00 | 0.00 | 425.00 |
| 021026-10 | ICE | g | 2,720.00 | 0.00 | 2,720.00 |
| 021026-14 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 021026-14 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 021026-14 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 021026-14 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 021026-13 | FRESH MILK | ml | 450.00 | 0.00 | 450.00 |
| 021026-13 | COFFEE BEANS | g | 54.00 | 0.00 | 54.00 |
| 021026-13 | WHITE CHOCOLATE SAUCE | ml | 60.00 | 0.00 | 60.00 |
| 021026-13 | ICE | g | 480.00 | 0.00 | 480.00 |
| 021026-13 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 021026-15 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 021026-15 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 021026-15 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 021026-15 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 021026-25 | FRESH MILK | ml | 480.00 | 0.00 | 480.00 |
| 021026-25 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 021026-25 | CEREMONIAL MATCHA POWDER | g | 4.00 | 0.00 | 4.00 |
| 021026-25 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 021026-26 | FRESH MILK | ml | 720.00 | 0.00 | 720.00 |
| 021026-26 | COFFEE BEANS | g | 54.00 | 0.00 | 54.00 |
| 021026-26 | CEREMONIAL MATCHA POWDER | g | 6.00 | 0.00 | 6.00 |
| 021026-26 | VANILLA SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 061026-01 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 061026-01 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 061026-01 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 061026-01 | ICE | g | 160.00 | 0.00 | 160.00 |
| 061026-02 | FRESH MILK | ml | 220.00 | 3.00 | 217.00 |
| 061026-02 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 061026-02 | CONDENSED MILK | ml | 20.00 | 0.00 | 20.00 |
| 061026-02 | JELLO POWDER | g | 4.00 | 0.00 | 4.00 |
| 061026-02 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-01 | FRESH MILK | ml | 520.00 | 0.00 | 520.00 |
| 071026-01 | COFFEE BEANS | g | 58.00 | 0.00 | 58.00 |
| 071026-01 | VANILLA SYRUP | ml | 60.00 | 0.00 | 60.00 |
| 071026-01 | ICE | g | 540.00 | 0.00 | 540.00 |
| 071026-01 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-02 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-02 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-02 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-02 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 071026-03 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-03 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-03 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-03 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 071026-03 | TEA BASE | ml | 220.00 | 0.00 | 220.00 |
| 071026-03 | BLUEBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 071026-03 | ICE | g | 180.00 | 0.00 | 180.00 |
| 071026-04 | FRESH MILK | ml | 460.00 | 0.00 | 460.00 |
| 071026-04 | COFFEE BEANS | g | 40.00 | 0.00 | 40.00 |
| 071026-04 | VANILLA SYRUP | ml | 55.00 | 0.00 | 55.00 |
| 071026-04 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-05 | FRESH MILK | ml | 460.00 | 0.00 | 460.00 |
| 071026-05 | COFFEE BEANS | g | 40.00 | 0.00 | 40.00 |
| 071026-05 | VANILLA SYRUP | ml | 55.00 | 0.00 | 55.00 |
| 071026-05 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-06 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-06 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-06 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-06 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 071026-07 | FRESH MILK | ml | 160.00 | 0.00 | 160.00 |
| 071026-07 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-07 | HEAVY CREAM | ml | 20.00 | 0.00 | 20.00 |
| 071026-07 | WHITE CHOCOLATE SAUCE | ml | 35.00 | 0.00 | 35.00 |
| 071026-07 | FRAPPE BASE POWDER | g | 30.00 | 0.00 | 30.00 |
| 071026-07 | ICE | g | 250.00 | 0.00 | 250.00 |
| 071026-10 | FRESH MILK | ml | 3,600.00 | 0.00 | 3,600.00 |
| 071026-10 | COFFEE BEANS | g | 270.00 | 0.00 | 270.00 |
| 071026-10 | VANILLA SYRUP | ml | 300.00 | 0.00 | 300.00 |
| 071026-11 | FRESH MILK | ml | 860.00 | 0.00 | 860.00 |
| 071026-11 | COFFEE BEANS | g | 80.00 | 0.00 | 80.00 |
| 071026-11 | DARK CHOCOLATE SAUCE | ml | 65.00 | 0.00 | 65.00 |
| 071026-11 | HEAVY CREAM | ml | 20.00 | 0.00 | 20.00 |
| 071026-11 | WHITE CHOCOLATE SAUCE | ml | 35.00 | 0.00 | 35.00 |
| 071026-11 | FRAPPE BASE POWDER | g | 30.00 | 0.00 | 30.00 |
| 071026-11 | ICE | g | 470.00 | 0.00 | 470.00 |
| 071026-11 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-11 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 071026-12 | FRESH MILK | ml | 480.00 | 0.00 | 480.00 |
| 071026-12 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-12 | CEREMONIAL MATCHA POWDER | g | 4.00 | 0.00 | 4.00 |
| 071026-12 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-13 | FRESH MILK | ml | 480.00 | 0.00 | 480.00 |
| 071026-13 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-13 | CEREMONIAL MATCHA POWDER | g | 4.00 | 0.00 | 4.00 |
| 071026-13 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-14 | FRESH MILK | ml | 380.00 | 3.00 | 377.00 |
| 071026-14 | COFFEE BEANS | g | 44.00 | 0.00 | 44.00 |
| 071026-14 | CONDENSED MILK | ml | 20.00 | 0.00 | 20.00 |
| 071026-14 | JELLO POWDER | g | 4.00 | 0.00 | 4.00 |
| 071026-14 | ICE | g | 470.00 | 0.00 | 470.00 |
| 071026-14 | HEAVY CREAM | ml | 20.00 | 0.00 | 20.00 |
| 071026-14 | WHITE CHOCOLATE SAUCE | ml | 35.00 | 0.00 | 35.00 |
| 071026-14 | FRAPPE BASE POWDER | g | 30.00 | 0.00 | 30.00 |
| 071026-16 | FRESH MILK | ml | 440.00 | 0.00 | 440.00 |
| 071026-16 | HEAVY CREAM | ml | 20.00 | 0.00 | 20.00 |
| 071026-16 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-16 | FRAPPE BASE POWDER | g | 30.00 | 0.00 | 30.00 |
| 071026-16 | OREO BISCUITS | g | 25.00 | 0.00 | 25.00 |
| 071026-16 | ICE | g | 250.00 | 0.00 | 250.00 |
| 071026-16 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-16 | DARK CHOCOLATE SAUCE | ml | 25.00 | 0.00 | 25.00 |
| 071026-17 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-17 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-17 | DARK CHOCOLATE SAUCE | ml | 25.00 | 0.00 | 25.00 |
| 071026-18 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-18 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-18 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-18 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-18 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-19 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-19 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-19 | WHITE CHOCOLATE SAUCE | ml | 25.00 | 0.00 | 25.00 |
| 071026-19 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-19 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-20 | FRESH MILK | ml | 220.00 | 0.00 | 220.00 |
| 071026-20 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-20 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-20 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-21 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-21 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-21 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-21 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-22 | FRESH MILK | ml | 220.00 | 0.00 | 220.00 |
| 071026-22 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-22 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-22 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-23 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-23 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-23 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-23 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-23 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-24 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-24 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-24 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-24 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-24 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-25 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-25 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-25 | VANILLA SYRUP | ml | 45.00 | 0.00 | 45.00 |
| 071026-25 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-26 | FRESH MILK | ml | 300.00 | 0.00 | 300.00 |
| 071026-26 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-26 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-26 | ICE | g | 320.00 | 0.00 | 320.00 |
| 071026-26 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-27 | FRESH MILK | ml | 370.00 | 3.00 | 367.00 |
| 071026-27 | COFFEE BEANS | g | 40.00 | 0.00 | 40.00 |
| 071026-27 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-27 | ICE | g | 380.00 | 0.00 | 380.00 |
| 071026-27 | CONDENSED MILK | ml | 20.00 | 0.00 | 20.00 |
| 071026-27 | JELLO POWDER | g | 4.00 | 0.00 | 4.00 |
| 071026-28 | FRESH MILK | ml | 300.00 | 0.00 | 300.00 |
| 071026-28 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-28 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-28 | ICE | g | 320.00 | 0.00 | 320.00 |
| 071026-28 | DARK CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-30 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-30 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-30 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-30 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-30 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-29 | FRESH MILK | ml | 220.00 | 0.00 | 220.00 |
| 071026-29 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-29 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-29 | ICE | g | 400.00 | 0.00 | 400.00 |
| 071026-29 | TEA BASE | ml | 220.00 | 0.00 | 220.00 |
| 071026-29 | BLUEBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 071026-31 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-31 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-31 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-31 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-31 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-33 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-33 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-33 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-33 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-33 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-34 | FRESH MILK | ml | 390.00 | 0.00 | 390.00 |
| 071026-34 | COFFEE BEANS | g | 36.00 | 0.00 | 36.00 |
| 071026-34 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-34 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-34 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-32 | FRESH MILK | ml | 630.00 | 0.00 | 630.00 |
| 071026-32 | COFFEE BEANS | g | 54.00 | 0.00 | 54.00 |
| 071026-32 | VANILLA SYRUP | ml | 65.00 | 0.00 | 65.00 |
| 071026-32 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-36 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-36 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-36 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-36 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-37 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-37 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-37 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-37 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-35 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-35 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-35 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-35 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-38 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-38 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-38 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-38 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-39 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-39 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-39 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-40 | FRESH MILK | ml | 220.00 | 0.00 | 220.00 |
| 071026-40 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-40 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-40 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-41 | TEA BASE | ml | 640.00 | 0.00 | 640.00 |
| 071026-41 | PEACH SYRUP | ml | 60.00 | 0.00 | 60.00 |
| 071026-41 | HONEY | ml | 30.00 | 0.00 | 30.00 |
| 071026-41 | ICE | g | 980.00 | 0.00 | 980.00 |
| 071026-41 | FRESH MILK | ml | 400.00 | 0.00 | 400.00 |
| 071026-41 | HEAVY CREAM | ml | 40.00 | 0.00 | 40.00 |
| 071026-41 | CREAM CHEESE | g | 50.00 | 0.00 | 50.00 |
| 071026-41 | BLUEBERRY SYRUP | ml | 70.00 | 0.00 | 70.00 |
| 071026-41 | FRAPPE BASE POWDER | g | 60.00 | 0.00 | 60.00 |
| 071026-41 | GRAHAM CRACKERS | g | 30.00 | 0.00 | 30.00 |
| 071026-42 | FRESH MILK | ml | 220.00 | 0.00 | 220.00 |
| 071026-42 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-42 | CARAMEL SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-42 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-43 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-43 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-43 | UBE FLAVOR | ml | 30.00 | 0.00 | 30.00 |
| 071026-43 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-44 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-44 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-44 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-44 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 071026-46 | FRESH MILK | ml | 200.00 | 0.00 | 200.00 |
| 071026-46 | HEAVY CREAM | ml | 20.00 | 0.00 | 20.00 |
| 071026-46 | CREAM CHEESE | g | 25.00 | 0.00 | 25.00 |
| 071026-46 | BLUEBERRY SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-46 | FRAPPE BASE POWDER | g | 30.00 | 0.00 | 30.00 |
| 071026-46 | GRAHAM CRACKERS | g | 15.00 | 0.00 | 15.00 |
| 071026-46 | ICE | g | 250.00 | 0.00 | 250.00 |
| 071026-48 | BLUEBERRY SYRUP | ml | 15.00 | 0.00 | 15.00 |
| 071026-48 | STRAWBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 071026-48 | SODA WATER | ml | 330.00 | 0.00 | 330.00 |
| 071026-48 | ICE | g | 240.00 | 0.00 | 240.00 |
| 071026-50 | BLUEBERRY SYRUP | ml | 15.00 | 0.00 | 15.00 |
| 071026-50 | STRAWBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 071026-50 | SODA WATER | ml | 330.00 | 0.00 | 330.00 |
| 071026-50 | ICE | g | 240.00 | 0.00 | 240.00 |
| 071026-49 | FRESH MILK | ml | 160.00 | 0.00 | 160.00 |
| 071026-49 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-49 | HEAVY CREAM | ml | 20.00 | 0.00 | 20.00 |
| 071026-49 | FRAPPE BASE POWDER | g | 30.00 | 0.00 | 30.00 |
| 071026-49 | LOTUS BISCOFF BISCUITS | g | 8.00 | 0.00 | 8.00 |
| 071026-49 | BISCOFF SPREAD | g | 30.00 | 0.00 | 30.00 |
| 071026-49 | ICE | g | 250.00 | 0.00 | 250.00 |
| 071026-51 | BLUEBERRY SYRUP | ml | 15.00 | 0.00 | 15.00 |
| 071026-51 | STRAWBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 071026-51 | SODA WATER | ml | 330.00 | 0.00 | 330.00 |
| 071026-51 | ICE | g | 240.00 | 0.00 | 240.00 |
| 071026-52 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-52 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-52 | WHITE CHOCOLATE SAUCE | ml | 30.00 | 0.00 | 30.00 |
| 071026-52 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-53 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-53 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-53 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-53 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-54 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-54 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-54 | CEREMONIAL MATCHA POWDER | g | 2.00 | 0.00 | 2.00 |
| 071026-54 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 071026-55 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-55 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-55 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-55 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-56 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-56 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-56 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-56 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-57 | FRESH MILK | ml | 220.00 | 0.00 | 220.00 |
| 071026-57 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 071026-57 | VANILLA SYRUP | ml | 35.00 | 0.00 | 35.00 |
| 071026-57 | ICE | g | 220.00 | 0.00 | 220.00 |
| 071026-58 | KIWI SYRUP | ml | 45.00 | 0.00 | 45.00 |
| 071026-58 | SODA WATER | ml | 330.00 | 0.00 | 330.00 |
| 071026-58 | ICE | g | 240.00 | 0.00 | 240.00 |
| 071026-59 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-59 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-59 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-60 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-60 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-60 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-61 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-61 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-61 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-61 | ICE | g | 160.00 | 0.00 | 160.00 |
| 071026-62 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-62 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-62 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-63 | FRESH MILK | ml | 240.00 | 0.00 | 240.00 |
| 071026-63 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-63 | VANILLA SYRUP | ml | 20.00 | 0.00 | 20.00 |
| 071026-64 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 071026-64 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 071026-64 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 071026-64 | ICE | g | 160.00 | 0.00 | 160.00 |
| 081026-01 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 081026-01 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 081026-01 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 081026-01 | ICE | g | 160.00 | 0.00 | 160.00 |
| 081026-02 | FRESH MILK | ml | 150.00 | 0.00 | 150.00 |
| 081026-02 | COFFEE BEANS | g | 18.00 | 0.00 | 18.00 |
| 081026-02 | VANILLA SYRUP | ml | 25.00 | 0.00 | 25.00 |
| 081026-02 | ICE | g | 400.00 | 0.00 | 400.00 |
| 081026-02 | BLUEBERRY SYRUP | ml | 15.00 | 0.00 | 15.00 |
| 081026-02 | STRAWBERRY SYRUP | ml | 30.00 | 0.00 | 30.00 |
| 081026-02 | SODA WATER | ml | 330.00 | 0.00 | 330.00 |
| 081026-03 | FRESH MILK | ml | 220.00 | 3.00 | 217.00 |
| 081026-03 | COFFEE BEANS | g | 22.00 | 0.00 | 22.00 |
| 081026-03 | CONDENSED MILK | ml | 20.00 | 0.00 | 20.00 |
| 081026-03 | JELLO POWDER | g | 4.00 | 0.00 | 4.00 |
| 081026-03 | ICE | g | 220.00 | 0.00 | 220.00 |
| 081026-05 | TEA BASE | ml | 320.00 | 0.00 | 320.00 |
| 081026-05 | GREEN APPLE SYRUP | ml | 45.00 | 0.00 | 45.00 |
| 081026-05 | ICE | g | 490.00 | 0.00 | 490.00 |
| 081026-05 | FRESH MILK | ml | 200.00 | 0.00 | 200.00 |
| 081026-05 | HEAVY CREAM | ml | 20.00 | 0.00 | 20.00 |
| 081026-05 | VANILLA SYRUP | ml | 10.00 | 0.00 | 10.00 |
| 081026-05 | STRAWBERRY SYRUP | ml | 40.00 | 0.00 | 40.00 |
| 081026-05 | FRAPPE BASE POWDER | g | 30.00 | 0.00 | 30.00 |

State fingerprint: 265a0d52017b36060374855cf398ee0d3898568f4d43e3d77df381803f254b9b
