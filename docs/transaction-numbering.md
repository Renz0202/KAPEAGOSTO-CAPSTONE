# Transaction and invoice numbers

`orders.id` remains the numeric primary key used by existing foreign keys.
`orders.transaction_no` is a unique permanent reference, allocated by a database
trigger when an order is inserted: `TRX-YYYYMMDD-NNNN`. It survives cancellation
and voiding. Existing orders receive references based on their creation date and
original ID; historical sequences therefore need not be contiguous.

`orders.sales_invoice_no` and `invoiced_at` are nullable. A full successful payment
on a pending order issues `SI-00000001` using a locked transactional counter.
Partial, failed and cancelled payments do not allocate a number. Cash, change
credit and confirmed card payments use the same allocator. Repeated confirmations
reuse the saved invoice. Invoice allocation rolls back with payment failure.
Invoices and transaction numbers are retained when an order is voided. Issued
invoice totals cannot be edited; use a separate additional order.

Historical completed sales are not retroactively assigned invoices. Their receipt
shows that the historical invoice was not recorded. Combined receipts are titled
Payment Summary and list their underlying invoice numbers.

Database triggers retain saved order, item and payment events in
`transaction_events`, with a cashier actor when the request has an authenticated
session. Browser-only cart changes before an order is saved are not transactions
and are not captured by these database triggers. Stock continues to use the
existing completion and reversal inventory logs.

Migration: `C:\xampp\php\php.exe scripts/migrate_transaction_numbers.php`.
It is additive and can be rerun. Applied to the local database after a backup at
`backups/kape_agosto_20261021_200307.sql`. After restoring a database backup or
importing the original SQL dump, rerun the migration to install the schema and
audit triggers. Do not reset document counters or reuse issued invoice numbers.

Checks: `tests/transaction_numbers_test.php` (installed triggers with rollback),
`tests/cash_receipt_test.php` (temporary tables), and
`tests/order_entry_inventory_test.php` (temporary tables).
