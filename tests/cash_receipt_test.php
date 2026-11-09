<?php
// Connection-local temporary tables keep real payments and orders untouched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/payment.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/receipt_tax.php';
$pdo = db();
foreach ([
    'orders' => 'id INT PRIMARY KEY, total DECIMAL(10,2), status VARCHAR(20), sales_invoice_no VARCHAR(40) NULL, invoiced_at DATETIME NULL',
    'document_sequences' => 'sequence_key VARCHAR(50) PRIMARY KEY, last_number BIGINT NOT NULL',
    'payments' => 'id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, provider VARCHAR(50), amount DECIMAL(10,2), currency VARCHAR(10), status VARCHAR(20), raw_response LONGTEXT NULL, receipt_url VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, paid_at DATETIME NULL',
    'payment_change_credits' => 'id INT AUTO_INCREMENT PRIMARY KEY, source_payment_id INT, destination_payment_id INT, amount DECIMAL(10,2)',
] as $table => $columns) $pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
$checks = 0;
$pdo->exec("INSERT INTO document_sequences VALUES ('sales_invoice', 0)");
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); echo 'PASS: ', $label, PHP_EOL; $checks++; }
function reject(callable $run, int $code): bool { try { $run(); } catch (Throwable $e) { return (int) $e->getCode() === $code; } return false; }
function addOrder(int $id, string $total, string $status = 'pending'): void { global $pdo; $pdo->prepare('INSERT INTO orders (id,total,status) VALUES (?,?,?)')->execute([$id, $total, $status]); }
function addPayment(int $order, string $provider, string $amount, $raw, string $status = 'succeeded'): int { global $pdo; $pdo->prepare('INSERT INTO payments (order_id,provider,amount,currency,status,raw_response) VALUES (?,?,?,\'php\',?,?)')->execute([$order,$provider,$amount,$status,$raw]); return (int) $pdo->lastInsertId(); }
check(payment_cash_cents('500') === 50000 && payment_cash_cents('150.75') === 15075 && payment_cash_cents('0.01') === 1, 'Currency values convert to exact cents');
$invalid = ['', 'abc', '-1', '1.001', '1e3', '100000000', 'INF', 'NaN', [], true];
check(count(array_filter($invalid, static fn($v) => reject(static fn() => payment_cash_cents($v), 400))) === count($invalid), 'Malformed, negative, oversized, and excess-precision amounts are rejected');
addOrder(1, '150');
$result = payment_record_cash($pdo, 1, '500');
check($result['tendered'] === 500 && $result['applied'] === 150 && $result['change'] === 350 && $result['remaining'] === 0, 'PHP 500 handed over for PHP 150 records PHP 350 change');
$saved = $pdo->query('SELECT * FROM payments WHERE order_id = 1')->fetch();
check((float) $saved['amount'] === 150.0 && payment_cash_details($saved) === ['tendered'=>500, 'change'=>350], 'Database saves the full cash tender separately from applied sales revenue');
$single = payment_receipt_summary($pdo, [1]);
check($single['paid'] === 150 && $single['cash_received'] === 500 && $single['change'] === 350, 'Receipt reload restores the saved cash and change');
check(reject(static fn() => payment_record_cash($pdo, 1, '500'), 409) && (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = 1')->fetchColumn() === 1, 'A second full-payment submission cannot charge an already-paid order');
addOrder(2, '300.25');
$first = payment_record_cash($pdo, 2, '100.10');
$second = payment_record_cash($pdo, 2, '500');
$partial = payment_receipt_summary($pdo, [2]);
check($first['remaining'] === 200.15 && $second['applied'] === 200.15 && $second['change'] === 299.85, 'Partial payments retain cent precision and calculate final change');
check($partial['paid'] === 300.25 && $partial['cash_received'] === 600.1 && $partial['change'] === 299.85, 'Receipt sums all cash tenders for a split payment');
addOrder(3, '100');
payment_record_cash($pdo, 3, '500');
$pdo->exec("UPDATE orders SET status = 'completed' WHERE id = 3");
addOrder(4, '250');
$pdo->beginTransaction(); $credited = payment_apply_change_credit($pdo, [3], 4, 250); $pdo->commit();
check($credited === 250.0 && payment_related_order_ids($pdo, 4) === [3,4], 'Existing additional-order credit keeps its related receipt orders');
$combined = payment_receipt_summary($pdo, [3,4]);
check($combined['cash_received'] === 500 && $combined['paid'] === 350 && $combined['change'] === 150 && $combined['change_applied'] === 250, 'Combined receipt shows original PHP 500 cash, PHP 350 payment, and PHP 150 change');
$source = payment_receipt_summary($pdo, [3]);
check($source['cash_received'] === 500 && $source['change'] === 150 && $source['change_applied_elsewhere'] === 250, 'Reprinting the original receipt accounts for change consumed on another order');
addOrder(5, '200');
$pdo->beginTransaction(); payment_apply_change_credit($pdo, [3], 5, 200); $pdo->commit();
payment_record_cash($pdo, 5, '100');
$branch = payment_receipt_summary($pdo, payment_related_order_ids($pdo, 5));
check($branch['cash_received'] === 600 && $branch['paid'] === 300 && $branch['change'] === 50 && $branch['change_applied'] === 150 && $branch['change_applied_elsewhere'] === 250, 'Additional cash and sibling credit usage remain separate');
$pdo->exec("UPDATE orders SET status = 'voided' WHERE id = 4");
$released = payment_receipt_summary($pdo, [3,5]);
check($released['change'] === 300 && $released['change_applied_elsewhere'] === 0, 'Voided added orders release their used change');
addOrder(6, '100');
payment_record_cash($pdo, 6, '500');
$pdo->exec('UPDATE orders SET total = 350 WHERE id = 6');
$pdo->beginTransaction(); $sameCredit = payment_apply_pending_order_change_credit($pdo, 6); $pdo->commit();
$same = payment_receipt_summary($pdo, [6]);
check($sameCredit === 250.0 && $same['cash_received'] === 500 && $same['paid'] === 350 && $same['change'] === 150, 'Adding items to the same pending order never hides the earlier cash tender');
addOrder(7, '99.99');
payment_record_cash($pdo, 7, '99.99');
$exact = payment_receipt_summary($pdo, [7]);
check($exact['cash_received'] === 99.99 && $exact['change'] === 0, 'Exact cash is recorded with zero change');
addOrder(8, '100', 'completed');
check(reject(static fn() => payment_record_cash($pdo, 8, '100'), 409) && !$pdo->inTransaction(), 'Completed orders reject new cash and close their transaction');
check(reject(static fn() => payment_record_cash($pdo, 999, '100'), 404), 'Missing orders reject cash without saving a payment');
check(reject(static fn() => payment_record_cash($pdo, 7, '0'), 400), 'Zero cash is rejected');
addOrder(9, '150');
addPayment(9, 'manual', '150', null);
$old = payment_receipt_summary($pdo, [9]);
check(!$old['cash_complete'] && $old['paid'] === 150 && $old['cash_received'] === 0, 'Older missing cash amounts are marked unrecorded instead of invented');
check(payment_cash_details(['provider'=>'manual','amount'=>'100','raw_response'=>'{"tendered":500,"change":0}']) === [], 'Inconsistent historical cash details are not represented as accurate');
check(payment_cash_details(['provider'=>'manual','amount'=>'100','raw_response'=>'{"tendered":500}']) === ['tendered'=>500,'change'=>400], 'Historical recorded cash can derive its change from the applied payment');
addOrder(10, '150');
addPayment(10, 'stripe', '50', null);
payment_record_cash($pdo, 10, '200');
addPayment(10, 'manual', '500', '{"tendered":500,"change":0}', 'failed');
$mixed = payment_receipt_summary($pdo, [10]);
check($mixed['paid'] === 150 && $mixed['cash_received'] === 200 && $mixed['change'] === 100, 'Card payments and failed transactions do not inflate cash received');
addOrder(11, '100');
$pdo->exec("ALTER TABLE payments ADD CONSTRAINT test_cash_failure CHECK (order_id <> 11 OR amount <= 20)");
addPayment(11, 'manual', '20', '{"tendered":20,"change":0}');
check(reject(static fn() => payment_record_cash($pdo, 11, '80'), 23000) && !$pdo->inTransaction() && payment_total_paid($pdo, 11) === 20.0, 'A database failure rolls back without losing the earlier payment');
$receiptSource = file_get_contents(dirname(__DIR__) . '/public/orders/receipt_template.php');
$functionStart = strpos($receiptSource, 'function receipt_money(');
$functionEnd = strpos($receiptSource, 'function receipt_status_label(', $functionStart);
eval(substr($receiptSource, $functionStart, $functionEnd - $functionStart));
$totalsStart = strpos($receiptSource, '      <section class="receipt-totals"');
$totalsEnd = strpos($receiptSource, '      </section>', $totalsStart) + strlen('      </section>');
$totalsTemplate = substr($receiptSource, $totalsStart, $totalsEnd - $totalsStart);
function renderTotals(array $receiptSummary, float $total, bool $hasCombinedOrders = false): string {
    global $totalsTemplate;
    $receiptPaid = $receiptSummary['paid']; $receiptCashReceived = $receiptSummary['cash_received'];
    $receiptChange = $receiptSummary['change']; $receiptChangeCredit = $receiptSummary['change_applied'];
    $appSettings = ['currency_label'=>'PHP']; $previousOrderTotal = 100; $additionalOrderTotal = $total - 100;
    $isPaid = $receiptPaid >= $total; $paymentStatus = $isPaid ? 'Paid' : 'Unpaid';
    ob_start(); eval('?>' . $totalsTemplate); return ob_get_clean();
}
$html = renderTotals($single, 150);
check(str_contains($html, '<span>Total</span>') && !str_contains($html, 'Total Payment') && !str_contains($html, 'Amount Paid') && !str_contains($html, 'Balance Due'), 'Fully paid receipts show one total without a duplicate payment row');
$partialHtml = renderTotals(array_replace($single, ['paid' => 50.0]), 150);
check((bool) preg_match('/Amount Paid<\/span>\s*<strong>PHP 50\.00/', $partialHtml) && (bool) preg_match('/Balance Due<\/span>\s*<strong>PHP 100\.00/', $partialHtml), 'Partial receipts retain amount paid and outstanding balance');
check((bool) preg_match('/Cash Received<\/span>\s*<strong>PHP 500\.00/', $html) && (bool) preg_match('/Change<\/span>\s*<strong>PHP 350\.00/', $html), 'Actual receipt markup prints Cash Received and Change');
$html = renderTotals($combined, 350, true);
check(str_contains($html, 'Change Applied to Added Items') && str_contains($html, 'PHP 500.00') && str_contains($html, 'PHP 350.00') && str_contains($html, 'PHP 150.00'), 'Combined receipt uses clear labels and saved amounts');
check((bool) preg_match('/Change<\/span>\s*<strong>PHP 0\.00/', renderTotals($exact, 99.99)), 'Exact-payment receipts print zero change explicitly');
check(str_contains(renderTotals($old, 150), 'Not fully recorded') && !str_contains(renderTotals($old, 150), 'Change'), 'Receipt honestly labels incomplete historical cash records');
$unpaid = payment_receipt_summary($pdo, []);
check(str_contains(renderTotals($unpaid, 150), 'PHP 150.00') && !str_contains(renderTotals($unpaid, 150), 'Cash Received'), 'Unpaid receipts show the order total without fictitious cash');
$_GET = ['order_id' => 6];
$dataSource = file_get_contents(dirname(__DIR__) . '/public/payments/order_payment_data.php');
$dataStart = strpos($dataSource, '$orderId = isset($_GET');
ob_start(); eval(substr($dataSource, $dataStart)); $api = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
check($api['ok'] && $api['cash_summary']['cash_received'] === 500 && $api['cash_summary']['change'] === 150 && $api['order']['paid'] === 350, 'Actual payment-data endpoint returns persisted cash and remaining change for refresh');
check($api['payments'][0]['tendered'] === 500 && $api['payments'][0]['change'] === 400 && $api['payments'][1]['provider'] === 'change_credit' && $api['payments'][1]['tendered'] === null, 'Payment history preserves the original tender and distinguishes reused change from new cash');
echo 'All ', $checks, ' cash and receipt checks passed. Real payments and orders were not changed.', PHP_EOL;
