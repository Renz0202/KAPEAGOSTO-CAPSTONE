<?php
// Stripe webhook endpoint to receive asynchronous updates
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/config/payment.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';

$pdo = db();

// Read payload
$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

payment_require_stripe();

try {
    $eventClass = 'Stripe\\Webhook';
    $event = $eventClass::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit('Invalid payload');
} catch (\Exception $e) {
    http_response_code(400);
    exit('Invalid signature');
} catch (\Throwable $e) {
    http_response_code(400);
    exit('Webhook error');
}

$type = $event->type ?? '';
$data = $event->data->object ?? null;

// Handle checkout.session.completed
if ($type === 'checkout.session.completed' && $data) {
    $sessionId = $data->id ?? null;
    $orderId = isset($data->metadata->order_id) ? (int)$data->metadata->order_id : 0;
    $paymentIntent = $data->payment_intent ?? null;

    if ($sessionId && $orderId > 0) {
        $stmt = $pdo->prepare('SELECT id, status FROM payments WHERE session_id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($payment) {
            // Update to succeeded
            $upd = $pdo->prepare('UPDATE payments SET status = "succeeded", provider_payment_id = ?, raw_response = ?, paid_at = NOW(), updated_at = NOW() WHERE id = ?');
            $upd->execute([$paymentIntent, $payload, $payment['id']]);
        }
    }
}

http_response_code(200);
echo 'ok';
