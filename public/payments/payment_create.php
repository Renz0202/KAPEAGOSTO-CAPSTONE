<?php
// Initiate Stripe Checkout for an existing order
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

// Optional: require login for staff-only use
require_capability('process_transactions');

$pdo = db();
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    die('Invalid order.');
}

// Fetch order
$stmt = $pdo->prepare('SELECT id, customer_name, total, status FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    http_response_code(404);
    die('Order not found.');
}

// If already paid, go to receipt
if (payment_is_order_paid($pdo, $orderId)) {
    header('Location: receipt_template.php?order_id=' . $orderId . '&paid=1');
    exit;
}

payment_require_stripe();

$amount = max(0, (float) $order['total'] - payment_total_paid($pdo, $orderId));
if ($amount <= 0) {
    http_response_code(400);
    die('Order has no amount to charge.');
}

// Create Stripe Checkout Session
$stripeClass = 'Stripe\\StripeClient';
/** @var object $stripe */
$stripe = new $stripeClass(STRIPE_SECRET);

$amountCents = (int) round($amount * 100);
$base = rtrim(app_base_url(), '/');
$successUrl = $base . '/payment_success.php?order_id=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}';
$cancelUrl  = $base . '/payment_cancel.php?order_id=' . $orderId . '&reason=user_cancelled';

try {
    $session = $stripe->checkout->sessions->create([
        'mode' => 'payment',
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => PAYMENT_CURRENCY,
                'product_data' => [
                    'name' => 'Order #' . $orderId,
                    'description' => 'Kape Agosto order payment',
                ],
                'unit_amount' => $amountCents,
            ],
            'quantity' => 1,
        ]],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'metadata' => [
            'order_id' => (string)$orderId,
        ],
    ]);

    // Record payment attempt
    $ins = $pdo->prepare('INSERT INTO payments (order_id, provider, session_id, amount, currency, status) VALUES (?, "stripe", ?, ?, ?, "pending")');
    $ins->execute([$orderId, $session->id, $amount, PAYMENT_CURRENCY]);

    header('Location: ' . $session->url);
    exit;
} catch (\Throwable $e) {
    error_log('Stripe create session error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Payment initialization failed. Please try again later.';
}
