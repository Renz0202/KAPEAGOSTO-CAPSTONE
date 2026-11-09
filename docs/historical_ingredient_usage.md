# Historical ingredient usage correction

Completed orders can be missing ingredient movements when recipes were configured after the sale. The current recipe is an estimate of the old sale, so the correction is explicitly labeled Historical recipe estimate.

The 10 October 2026 review found 377 missing ingredient entries across 80 completed orders. Every entry predates the ingredient's later generated opening stock setup or stock count adjustment. Subtracting those old sales from that later balance would mix two stock periods.

The history-only correction adds the missing order-linked ingredient usage while preserving the later current balance. It creates no purchases or restocks and does not invent the old sale's stock balance. Each new movement is dated when the correction is posted; its Balance After is the unchanged stock at that posting time. Its remarks identify the later baseline and the estimate. Movement History labels these records Past use with Estimate underneath and marks their balance When added. View Details explains that adding the historical record did not reduce stock again. Packaging, existing movements, order status, payment, completion markers, recipes, and notifications are preserved. Pending and voided orders are excluded.

## Preview

Run from the project root:

~~~powershell
C:\xampp\php\php.exe scripts/reconcile_ingredient_history.php --write-report
~~~

The command uses a read-only transaction and saves a JSON plan and Markdown report under docs. Review the report and create a database backup before applying it.

## Apply a reviewed plan

~~~powershell
C:\xampp\php\php.exe scripts/backup_database.php
C:\xampp\php\php.exe scripts/reconcile_ingredient_history.php --apply-plan=docs/ingredient_history_plan_YYYYMMDD_HHMMSS.json
~~~

The correction locks the source records, checks the saved fingerprint, inserts only positive missing usage, verifies that nothing remains missing and that stock is unchanged, then commits in one transaction. Any failure rolls back the entire correction. A stale plan is rejected. A new plan after successful correction has no missing entries, so repeating it adds nothing.

The command refuses usage that is not proven to predate a recognized later baseline, unknown completion times, unlinked ingredient use, or ambiguous movements. Such cases need a separate review of actual stock, purchase records, and recipes.

This does not establish a physical inventory count. Generated recipes and stock remain estimates until the cafe reviews them.

## Verification

~~~powershell
C:\xampp\php\php.exe tests/inventory_reconciliation_test.php
C:\xampp\php\php.exe tests/inventory_reconciliation_history_test.php
~~~

Fixtures use connection-local temporary tables. The checks cover fractional amounts, partial usage, all packaging, excluded order statuses, stale plans, baseline dates, unchanged stock and other records, repeated corrections, and rollback after a mid-batch database failure.

## Sale dates and recorded dates

Past use displays the original order date used on the receipt. Movement History date filters and pagination use that sale date for historical estimates. View Details shows Sale / Movement Date, Recorded On, and Order Reference separately. Regular movements retain their recorded date.

The 377 corrections recorded on 10 October 2026 now appear under the original sale dates, from 16 September through 8 October 2026. Saved timestamps were preserved. Stock When Recorded remains the balance when the correction was added, rather than a reconstructed balance for the old sale. Missing order dates fall back to the recorded date and are explicitly labeled Sale date unknown.
