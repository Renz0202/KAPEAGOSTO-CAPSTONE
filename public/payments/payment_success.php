<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/config/payment.php';

$pdo = db();
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$sessionId = $_GET['session_id'] ?? '';

if ($orderId <= 0 || !$sessionId) {
    http_response_code(400);
    die('Invalid payment confirmation.');
}

payment_require_stripe();

try {
    $stripeClass = 'Stripe\\StripeClient';
    /** @var object $stripe */
    $stripe = new $stripeClass(STRIPE_SECRET);
    $session = $stripe->checkout->sessions->retrieve($sessionId, []);

    $status = $session->payment_status ?? 'unpaid';
    $paymentIntent = $session->payment_intent ?? null;

    // Update payment row
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE session_id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        if ($status === 'paid') {
            $receiptUrl = is_array($session->receipt_url ?? null) ? null : ($session->receipt_url ?? null);
            $confirmedOrder = transaction_confirm_card($pdo, $sessionId, $paymentIntent, $receiptUrl, json_encode($session));
            if ($confirmedOrder) $orderId = $confirmedOrder;
        } elseif ($payment['status'] !== 'succeeded') {
            $upd = $pdo->prepare('UPDATE payments SET status = ?, raw_response = ?, updated_at = NOW() WHERE id = ?');
            $upd->execute(['pending', json_encode($session), $payment['id']]);
        }
    }

    // Redirect to receipt with a flag
    header('Location: receipt_template.php?order_id=' . $orderId . '&paid=' . ($status === 'paid' ? '1' : '0'));
    exit;
} catch (\Throwable $e) {
    error_log('Stripe success handle error: ' . $e->getMessage());
    echo 'Payment confirmed, but we could not verify details automatically. If you were charged, please contact support with Order #' . (int)$orderId . '.';
}
