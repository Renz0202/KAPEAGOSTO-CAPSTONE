<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/order_utils.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';
require_once dirname(__DIR__, 2) . '/includes/receipt_tax.php';
require_capability('view_order_history');

$pdo = db();
payment_ensure_schema($pdo);
$appSettings = settings_all($pdo);

$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($orderId <= 0) {
    die('No order selected.');
}

$auto = false;
$copy = isset($_GET['copy']) ? strtolower(trim($_GET['copy'])) : '';

$stmt = $pdo->prepare("
    SELECT o.*, c.contact AS customer_contact, c.email AS customer_email,
           u.username AS generated_username, r.name AS generated_role
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = o.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE o.id = ?
    LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found.');
}
$order['generated_by'] = order_generated_by_label($order['generated_username'] ?? null, $order['generated_role'] ?? null);

$relatedOrderIds = payment_related_order_ids($pdo, $orderId);
if (empty($relatedOrderIds)) $relatedOrderIds = [$orderId];
$hasCombinedOrders = count($relatedOrderIds) > 1;
$placeholders = implode(',', array_fill(0, count($relatedOrderIds), '?'));

$relatedOrdersStmt = $pdo->prepare("SELECT id, order_code, total, subtotal, discount_type, discount_rate, discount_amount, vat_exempt_amount, discount_beneficiary_type, discount_customer_name, discount_customer_tin, discount_id_number, sales_invoice_no FROM orders WHERE id IN ($placeholders) ORDER BY id ASC");
$relatedOrdersStmt->execute($relatedOrderIds);
$relatedOrders = $relatedOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
$invoiceNumbers = array_values(array_filter(array_column($relatedOrders, 'sales_invoice_no')));
$invoiceTitle = count($relatedOrders) > 1 ? 'Payment Summary' : ($invoiceNumbers ? 'Sales Invoice' : 'Order Summary');
$receiptOrderLabels = [];
$total = 0.0;
$previousOrderTotal = 0.0;
$receiptGrossSubtotal = 0.0;
$receiptVatExemptAmount = 0.0;
$receiptStatutoryDiscount = 0.0;
$receiptPromoDiscount = 0.0;
$receiptTaxBreakdown = ['VATable Sales' => 0.0, 'VAT Amount (12%)' => 0.0, 'VAT-Exempt Sales' => 0.0, 'Zero-Rated Sales' => 0.0];
$hasReceiptDiscount = false;
$receiptDiscountBeneficiaries = [];
$taxType = settings_value($appSettings, 'bir_tax_type');
foreach ($relatedOrders as $relatedOrder) {
    $relatedId = (int) $relatedOrder['id'];
    $code = ensure_order_code($pdo, $relatedId);
    $receiptOrderLabels[$relatedId] = $code ?: (string) $relatedId;
    $relatedTotal = (float) $relatedOrder['total'];
    $total += $relatedTotal;
    $orderDiscount = (float) ($relatedOrder['discount_amount'] ?? 0);
    $orderVatExempt = (float) ($relatedOrder['vat_exempt_amount'] ?? 0);
    $grossSubtotal = (float) ($relatedOrder['subtotal'] ?? 0);
    if ($grossSubtotal <= 0 && $relatedTotal > 0) $grossSubtotal = $relatedTotal + $orderDiscount + $orderVatExempt;
    $receiptGrossSubtotal += $grossSubtotal;
    $discountType = (string) ($relatedOrder['discount_type'] ?? 'none');
    $receiptVatExemptAmount += $orderVatExempt;
    $receiptPromoDiscount += $discountType === 'promo' ? $orderDiscount : 0;
    $receiptStatutoryDiscount += in_array($discountType, ['senior', 'pwd'], true) ? $orderDiscount : 0;
    $hasReceiptDiscount = $hasReceiptDiscount || $discountType !== 'none';
    if (!empty($relatedOrder['discount_beneficiary_type'])) {
        $receiptDiscountBeneficiaries[] = [
            'type' => strtoupper((string) $relatedOrder['discount_beneficiary_type']),
            'name' => (string) ($relatedOrder['discount_customer_name'] ?? ''),
            'tin' => (string) ($relatedOrder['discount_customer_tin'] ?? ''),
            'id' => (string) ($relatedOrder['discount_id_number'] ?? ''),
            'statutory' => in_array($discountType, ['senior', 'pwd'], true),
        ];
    }
    if ($taxType === 'vat') {
        if (in_array($discountType, ['senior', 'pwd'], true)) {
            $receiptTaxBreakdown['VAT-Exempt Sales'] += $relatedTotal;
        } else {
            foreach (receipt_tax_breakdown($relatedTotal, 'vat') as $label => $amount) $receiptTaxBreakdown[$label] += $amount;
        }
    }
    if ($relatedId !== $orderId) $previousOrderTotal += $relatedTotal;
}
$receiptNo = implode(' + ', array_map(static fn($code): string => 'Order#' . $code, array_values($receiptOrderLabels)));
if ($receiptNo === '') $receiptNo = 'Order#' . $orderId;
$customerName = $order['customer_name'] ?: 'Walk-in';
$serviceType = $order['service_type'] ?? null;
if (in_array($serviceType, ['dine_in', 'take_out'], true)) {
    $isWalkIn = in_array(strtoupper(trim($customerName)), ['', 'WALK-IN', 'WALK IN', 'WALK-IN CUSTOMER'], true);
    $customerName = ($isWalkIn ? '' : $customerName . ' - ') . order_service_type_label($serviceType);
}
$additionalOrderTotal = (float) $order['total'];
$isPaid = payment_is_order_paid($pdo, $orderId);
$isCompleted = strtolower(trim((string) ($order['status'] ?? ''))) === 'completed';
$changeCreditSourceOrderIds = $isCompleted ? payment_change_credit_source_orders_for_order($pdo, $orderId) : [];
$availableChangeCredit = 0.0;
foreach ($changeCreditSourceOrderIds as $sourceOrderId) {
    $availableChangeCredit += payment_change_credit_available($pdo, (int) $sourceOrderId);
}
$paymentStatus = $isPaid ? 'Paid' : 'Unpaid';
$copyLabel = $copy === 'company' ? 'Company Copy' : ($copy === 'customer' ? 'Customer Copy' : $invoiceTitle . ' Copy');

$itemStmt = $pdo->prepare("
    SELECT oi.order_id AS source_order_id, oi.qty, oi.price, COALESCE(oi.subtotal, oi.qty * oi.price) AS subtotal,
           i.name, i.cup_size, c.name AS category_name
    FROM order_items oi
    JOIN items i ON oi.item_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE oi.order_id IN ($placeholders)
    ORDER BY oi.order_id ASC, oi.id ASC
");
$itemStmt->execute($relatedOrderIds);
$orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$receiptSummary = payment_receipt_summary($pdo, $relatedOrderIds);
$receiptPaid = $receiptSummary['paid'];
$receiptCashReceived = $receiptSummary['cash_received'];
$receiptChange = $receiptSummary['change'];
$receiptChangeCredit = $receiptSummary['change_applied'];
$createdAt = !empty($order['created_at'])
    ? new DateTime($order['created_at'])
    : new DateTime('now');
$createdDate = $createdAt->format('Y-m-d');
$createdTime = $createdAt->format('H:i:s');
$receiptTaxBreakdown = array_filter($receiptTaxBreakdown, static fn($amount): bool => abs((float) $amount) > 0.00001);
$hasStatutoryReceiptDiscount = count(array_filter($receiptDiscountBeneficiaries, static fn($beneficiary): bool => $beneficiary['statutory'])) > 0;

function receipt_money($amount, string $currencyLabel): string {
    return $currencyLabel . ' ' . number_format((float) $amount, 2);
}

function receipt_status_label($status): string {
    $status = (string) $status;
    if ($status === 'void' || $status === 'voided') {
        return 'Voided';
    }
    return ucfirst($status ?: 'Pending');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - <?= htmlspecialchars($invoiceTitle) ?> <?= htmlspecialchars(implode(' + ', $invoiceNumbers)) ?></title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-orders-receipt-template<?= is_cashier() ? ' cashier-pos-shell cashier-pos-receipt' : '' ?>">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content receipt-page">
  <section class="receipt-stage" aria-label="Order receipt preview">
    <article id="receipt" class="receipt-paper">
      <header class="receipt-header">
        <img class="receipt-logo" src="img/NEWLOGO_BLACK.png" alt="Kape Agosto Logo" onerror="this.style.display='none'">
        <h1><?= htmlspecialchars(settings_value($appSettings, 'business_name')) ?></h1>
        <?php if (settings_value($appSettings, 'bir_tin') !== '' && in_array(settings_value($appSettings, 'bir_tax_type'), ['vat', 'non_vat'], true)): ?>
          <p><?= settings_value($appSettings, 'bir_tax_type') === 'vat' ? 'VAT' : 'NON-VAT' ?> TIN: <?= htmlspecialchars(settings_value($appSettings, 'bir_tin')) ?><?= settings_value($appSettings, 'bir_branch_code') !== '' ? '-' . htmlspecialchars(settings_value($appSettings, 'bir_branch_code')) : '' ?></p>
        <?php endif; ?>
        <p><?= nl2br(htmlspecialchars(settings_value($appSettings, 'business_address'))) ?></p>
        <p><?= htmlspecialchars(settings_value($appSettings, 'business_phone')) ?> &bull; <?= htmlspecialchars(settings_value($appSettings, 'business_email')) ?></p>
      </header>

      <div class="receipt-divider"></div>
      <h2><?= htmlspecialchars($invoiceTitle) ?></h2>
      <span class="receipt-copy-label"><?= htmlspecialchars($copyLabel) ?></span>

      <section class="receipt-meta" aria-label="Receipt information">
        <div><span>Order No.</span><strong><?= htmlspecialchars(implode(' + ', array_values($receiptOrderLabels))) ?></strong></div>
        <div><span>Transaction No.</span><strong><?= htmlspecialchars(implode(' + ', array_map('order_transaction_number', $relatedOrderIds))) ?></strong></div>
        <div><span>Sales Invoice No.</span><strong><?= htmlspecialchars($invoiceNumbers ? implode(' + ', $invoiceNumbers) : ($isPaid ? 'Historical sale — invoice not recorded' : 'Not issued — awaiting full payment')) ?></strong></div>
        <div><span>Date</span><strong><?= htmlspecialchars($createdDate) ?></strong></div>
        <div><span>Time</span><strong><?= htmlspecialchars($createdTime) ?></strong></div>
        <div><span>Customer</span><strong><?= htmlspecialchars($customerName) ?></strong></div>
        <div><span>Generated By</span><strong><?= htmlspecialchars($order['generated_by']) ?></strong></div>
        <?php foreach ($receiptDiscountBeneficiaries as $beneficiary): ?>
          <div><span><?= htmlspecialchars($beneficiary['type']) ?> Beneficiary</span><strong><?= htmlspecialchars($beneficiary['name']) ?></strong></div>
          <?php if ($beneficiary['tin'] !== ''): ?><div><span><?= htmlspecialchars($beneficiary['type']) ?> TIN</span><strong><?= htmlspecialchars($beneficiary['tin']) ?></strong></div><?php endif; ?>
          <div><span><?= htmlspecialchars($beneficiary['type']) ?> ID No.</span><strong><?= htmlspecialchars($beneficiary['id']) ?></strong></div>
        <?php endforeach; ?>
      </section>

      <?php if (!empty($order['service_details'])): ?>
        <p class="receipt-thanks"><strong>Service details:</strong> <?= nl2br(htmlspecialchars($order['service_details'])) ?></p>
      <?php endif; ?>
      <?php if ($receiptDiscountBeneficiaries): ?>
        <p class="receipt-thanks"><?= $hasStatutoryReceiptDiscount ? 'Statutory discount applied to the named customer’s eligible items. Please retain the required ID verification record.' : 'Senior/PWD ID verified; the customer received the more favorable promotional offer. Discounts were not combined.' ?></p>
      <?php endif; ?>

      <table class="receipt-table">
        <thead>
          <tr>
            <th>Category</th>
            <th>Item</th>
            <th>Qty</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($orderItems)): ?>
            <?php foreach ($orderItems as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['category_name'] ?: '-') ?></td>
                <td>
                  <?= htmlspecialchars($item['name']) ?>
                  <?php if (!empty($item['cup_size'])): ?>
                    <small><?= htmlspecialchars($item['cup_size']) ?></small>
                  <?php endif; ?>
                </td>
                <td><?= number_format((int) $item['qty']) ?></td>
                <td><?= htmlspecialchars(receipt_money($item['subtotal'], settings_value($appSettings, 'currency_label'))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4" class="receipt-empty">No items found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <section class="receipt-totals" aria-label="Receipt totals">
        <?php if ($hasCombinedOrders): ?>
          <div class="receipt-status-row">
            <span>Previous Order(s) Total</span>
            <strong><?= htmlspecialchars(receipt_money($previousOrderTotal, settings_value($appSettings, 'currency_label'))) ?></strong>
          </div>
          <div class="receipt-status-row">
            <span>Additional Order Total</span>
            <strong><?= htmlspecialchars(receipt_money($additionalOrderTotal, settings_value($appSettings, 'currency_label'))) ?></strong>
          </div>
        <?php endif; ?>
        <?php if ($hasReceiptDiscount): ?>
          <div class="receipt-status-row"><span>Subtotal before discount</span><strong><?= htmlspecialchars(receipt_money($receiptGrossSubtotal, settings_value($appSettings, 'currency_label'))) ?></strong></div>
          <?php if ($receiptVatExemptAmount > 0): ?>
            <div class="receipt-status-row"><span>VAT Exemption</span><strong>-<?= htmlspecialchars(receipt_money($receiptVatExemptAmount, settings_value($appSettings, 'currency_label'))) ?></strong></div>
          <?php endif; ?>
          <?php if ($receiptPromoDiscount > 0): ?>
            <div class="receipt-status-row"><span>Promo Discount<?= $receiptDiscountBeneficiaries ? ' (more favorable offer)' : '' ?></span><strong>-<?= htmlspecialchars(receipt_money($receiptPromoDiscount, settings_value($appSettings, 'currency_label'))) ?></strong></div>
          <?php endif; ?>
          <?php if ($receiptStatutoryDiscount > 0): ?>
            <div class="receipt-status-row"><span>Senior/PWD Discount (20%)</span><strong>-<?= htmlspecialchars(receipt_money($receiptStatutoryDiscount, settings_value($appSettings, 'currency_label'))) ?></strong></div>
          <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($receiptTaxBreakdown as $taxLabel => $taxAmount): ?>
          <div class="receipt-status-row">
            <span><?= htmlspecialchars($taxLabel) ?></span>
            <strong><?= htmlspecialchars(receipt_money($taxAmount, settings_value($appSettings, 'currency_label'))) ?></strong>
          </div>
        <?php endforeach; ?>
        <div class="receipt-total-row">
          <span>Total</span>
          <strong><?= htmlspecialchars(receipt_money($total, settings_value($appSettings, 'currency_label'))) ?></strong>
        </div>
        <?php if ($receiptPaid > 0): ?>
          <?php if ($total - $receiptPaid > 0.009): ?>
          <div class="receipt-status-row">
            <span>Amount Paid</span>
            <strong><?= htmlspecialchars(receipt_money($receiptPaid, settings_value($appSettings, 'currency_label'))) ?></strong>
          </div>
          <?php endif; ?>
          <?php if ($receiptSummary['has_cash']): ?>
            <div class="receipt-status-row">
              <span>Cash Received</span>
              <strong><?= $receiptSummary['cash_complete'] ? htmlspecialchars(receipt_money($receiptCashReceived, settings_value($appSettings, 'currency_label'))) : 'Not fully recorded' ?></strong>
            </div>
          <?php endif; ?>
          <?php if ($receiptChangeCredit > 0): ?>
            <div class="receipt-status-row">
              <span>Change Applied to Added Items</span>
              <strong><?= htmlspecialchars(receipt_money($receiptChangeCredit, settings_value($appSettings, 'currency_label'))) ?></strong>
            </div>
          <?php endif; ?>
          <?php if ($receiptSummary['change_applied_elsewhere'] > 0): ?>
            <div class="receipt-status-row">
              <span>Change Used on Other Orders</span>
              <strong><?= htmlspecialchars(receipt_money($receiptSummary['change_applied_elsewhere'], settings_value($appSettings, 'currency_label'))) ?></strong>
            </div>
          <?php endif; ?>
          <?php if ($receiptSummary['has_cash'] && $receiptSummary['cash_complete']): ?>
            <div class="receipt-status-row receipt-change-row">
              <span>Change</span>
              <strong><?= htmlspecialchars(receipt_money($receiptChange, settings_value($appSettings, 'currency_label'))) ?></strong>
            </div>
          <?php endif; ?>
          <?php if ($total - $receiptPaid > 0.009): ?>
            <div class="receipt-status-row">
              <span>Balance Due</span>
              <strong><?= htmlspecialchars(receipt_money($total - $receiptPaid, settings_value($appSettings, 'currency_label'))) ?></strong>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="receipt-status-row">
          <span>Payment Status</span>
          <strong class="<?= $isPaid ? 'is-paid' : 'is-unpaid' ?>"><?= htmlspecialchars($paymentStatus) ?></strong>
        </div>
      </section>

      <?php if ($receiptDiscountBeneficiaries): ?>
        <p class="receipt-discount-signature">Senior Citizen / PWD Signature: __________________________________</p>
      <?php endif; ?>

      <p class="receipt-thanks"><?= htmlspecialchars(settings_value($appSettings, 'receipt_thank_you')) ?></p>

      <nav class="receipt-actions" aria-label="Receipt actions">
        <button type="button" class="receipt-action-btn primary" onclick="manualPrint()">
          <i class="fa fa-print"></i>
          Print / Reprint
        </button>
        <button type="button" class="receipt-action-btn secondary" onclick="goBack()">
          <i class="fa fa-arrow-left"></i>
          Back to Orders
        </button>
        <?php if (!$isPaid): ?>
          <a class="receipt-action-btn pay" href="order_payment.php?order_id=<?= (int) $orderId ?>">
            <i class="fa fa-credit-card"></i>
            Pay
          </a>
        <?php endif; ?>
      </nav>
    </article>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
function manualPrint() {
  window.print();
}

function goBack() {
  window.location.href = 'order_list.php';
}
</script>
</body>
</html>
