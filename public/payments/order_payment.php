<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/order_utils.php';
require_capability('process_transactions');

$pdo = db();
payment_ensure_schema($pdo);

$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($orderId <= 0) {
    die('No order selected.');
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    die('Order not found.');
}

$orderLabel = get_order_label($pdo, $orderId);
$total = (float) $order['total'];
$paid = payment_total_paid($pdo, $orderId);
$remaining = max(0, $total - $paid);
$payments = payment_list_for_order($pdo, $orderId);
$isPending = ($order['status'] ?? '') === 'pending';
$userRole = strtolower((string) ($_SESSION['user']['role'] ?? ''));

$itemsStmt = $pdo->prepare('
    SELECT oi.id AS order_item_id, i.name, i.cup_size, oi.qty, oi.price,
           COALESCE(oi.subtotal, oi.qty * oi.price) AS subtotal
    FROM order_items oi
    JOIN items i ON oi.item_id = i.id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
');
$itemsStmt->execute([$orderId]);
$orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

function payment_money($amount): string {
    return 'PHP ' . number_format((float) $amount, 2);
}

function payment_status_label(string $status): string {
    return in_array($status, ['void', 'voided'], true) ? 'Voided' : ucfirst($status ?: 'pending');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - Payment</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-payments-order-payment<?= is_cashier() ? ' cashier-pos-shell cashier-pos-payment' : '' ?>">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content payment-page">
  <section class="payment-heading" aria-labelledby="payment-title">
    <div>
      <p class="payment-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Manage Order</p>
      <h1 id="payment-title">Payment</h1>
      <p class="payment-copy">Settle payment for <?= htmlspecialchars($orderLabel) ?> and print the customer copy.</p>
    </div>
    <div class="payment-order-badge">
      <span><?= htmlspecialchars(payment_status_label((string) $order['status'])) ?></span>
      <strong><?= htmlspecialchars($order['customer_name'] ?: 'Walk-in') ?></strong>
    </div>
  </section>

  <section class="payment-workspace">
    <section class="payment-terminal-panel" aria-labelledby="terminal-title">
      <div class="payment-metrics">
        <div>
          <span>Total</span>
          <strong id="totalValue"><?= htmlspecialchars(payment_money($total)) ?></strong>
        </div>
        <div>
          <span>Paid</span>
          <strong id="paidValue"><?= htmlspecialchars(payment_money($paid)) ?></strong>
        </div>
        <div>
          <span>Amount Remaining</span>
          <strong id="remainValue" class="<?= $remaining > 0 ? 'is-due' : 'is-paid' ?>"><?= htmlspecialchars(payment_money($remaining)) ?></strong>
        </div>
      </div>

      <div class="payment-entry">
        <label for="payInput" class="sr-only">Payment Amount</label>
        <div class="payment-input-wrap">
          <span>PHP</span>
          <input id="payInput" class="pay-input" type="text" inputmode="decimal" placeholder="0.00" autocomplete="off">
        </div>
        <button type="button" class="payment-cash-btn" data-method="cash" <?= $isPending ? '' : 'disabled' ?>>
          <i class="fa fa-money-bill-wave"></i>
          Cash
        </button>
      </div>

      <div class="payment-keypad" aria-label="Payment keypad">
        <button type="button" data-k="7">7</button>
        <button type="button" data-k="8">8</button>
        <button type="button" data-k="9">9</button>
        <button type="button" data-action="exact">Exact</button>
        <button type="button" data-action="back"><i class="fa fa-delete-left"></i></button>
        <button type="button" data-k="4">4</button>
        <button type="button" data-k="5">5</button>
        <button type="button" data-k="6">6</button>
        <button type="button" data-action="clear">Clear</button>
        <button type="button" data-action="plus" data-value="100">M+</button>
        <button type="button" data-k="1">1</button>
        <button type="button" data-k="2">2</button>
        <button type="button" data-k="3">3</button>
        <button type="button" class="key-tall" data-action="plus" data-value="50">+</button>
        <button type="button" class="key-tall" data-action="submit">Entry</button>
        <button type="button" data-k=",">,</button>
        <button type="button" data-k="0">0</button>
        <button type="button" data-k=".">.</button>
        <button type="button" data-quick="20">PHP 20</button>
        <button type="button" data-quick="50">PHP 50</button>
        <button type="button" data-quick="100">PHP 100</button>
        <button type="button" data-quick="500">PHP 500</button>
        <button type="button" data-quick="1000">PHP 1000</button>
      </div>

      <div class="payment-notice">Only cash payments are enabled at this time.</div>
      <div class="payment-feedback" id="paymentClientNotice" role="status" aria-live="polite" hidden></div>
    </section>

    <aside class="payment-side-panel">
      <section class="payment-card" aria-labelledby="order-items-title">
        <div class="payment-panel-header">
          <h2 id="order-items-title">Order Items</h2>
          <a href="orders.php?order_id=<?= (int) $orderId ?>&customer_name=<?= urlencode($order['customer_name'] ?? 'Walk-in') ?>" class="payment-link-btn">
            <i class="fa fa-pen-to-square"></i>
            Edit
          </a>
        </div>
        <div class="payment-table-wrap">
          <table class="payment-table">
            <thead>
              <tr>
                <th>Item</th>
                <th>Cup Size</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($orderItems)): ?>
                <?php foreach ($orderItems as $item): ?>
                  <tr>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= htmlspecialchars($item['cup_size'] ?: '-') ?></td>
                    <td><?= number_format((int) $item['qty']) ?></td>
                    <td><?= htmlspecialchars(payment_money($item['price'])) ?></td>
                    <td><?= htmlspecialchars(payment_money($item['subtotal'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="5" class="payment-empty">No items found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="payment-table-total">
          <span>Total</span>
          <strong><?= htmlspecialchars(payment_money($total)) ?></strong>
        </div>
      </section>

      <section class="payment-card" aria-labelledby="payments-title">
        <div class="payment-panel-header">
          <h2 id="payments-title">Payments</h2>
        </div>
        <div class="payment-table-wrap">
          <table class="payment-table">
            <thead>
              <tr>
                <th>Payment Type</th>
                <th>Amount</th>
                <th>Date</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="paymentsTbody">
              <?php
                $hasSucceededPayment = false;
                foreach ($payments as $payment):
                  if (($payment['status'] ?? '') !== 'succeeded') {
                      continue;
                  }
                  $hasSucceededPayment = true;
              ?>
                <tr data-id="<?= (int) $payment['id'] ?>">
                  <td><?= htmlspecialchars(ucfirst($payment['provider'])) ?></td>
                  <td><?= htmlspecialchars(payment_money($payment['amount'])) ?></td>
                  <td><?= htmlspecialchars(date('m/d/Y h:i A', strtotime($payment['created_at']))) ?></td>
                  <td>
                    <?php if ($payment['provider'] === 'manual' && $isPending): ?>
                      <button type="button" class="payment-mini-btn" data-remove="<?= (int) $payment['id'] ?>">
                        <i class="fa fa-xmark"></i>
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$hasSucceededPayment): ?>
                <tr><td colspan="4" class="payment-empty">No payments recorded.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <div class="payment-actions">
        <?php if ($isPending): ?>
          <button type="button" id="submitOrderBtn" class="payment-submit-btn" <?= $remaining <= 0 ? '' : 'disabled' ?>>
            <i class="fa fa-print"></i>
            Submit & Print Customer Copy
          </button>
          <button type="button" id="voidOrderBtn" class="payment-void-btn">
            <i class="fa fa-ban"></i>
            Void Order
          </button>
        <?php else: ?>
          <a href="receipt_template.php?order_id=<?= (int) $orderId ?>&copy=customer" class="payment-submit-btn">
            <i class="fa fa-print"></i>
            Reprint Customer Copy
          </a>
        <?php endif; ?>
      </div>
    </aside>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<div id="adminPermissionModal" class="payment-modal" aria-hidden="true">
  <div class="payment-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminLoginTitle">
    <img src="img/CUP_BLACK.png" alt="Kape Agosto" class="payment-modal-logo">
    <h2 id="adminLoginTitle">Admin Login Required</h2>
    <p>Only an Admin can void or cancel orders. Please sign in as an Admin to continue.</p>
    <form id="adminLoginForm" method="post" autocomplete="off" class="payment-modal-form">
      <label for="admin_username">Username</label>
      <input id="admin_username" type="text" name="admin_username" placeholder="Admin Username" required>
      <label for="admin_password">Password</label>
      <input id="admin_password" type="password" name="admin_password" placeholder="Admin Password" required>
      <input type="hidden" name="void_order_id" id="voidOrderIdInput" value="<?= (int) $orderId ?>">
      <button type="submit" id="adminLoginBtn" class="payment-modal-primary">Login</button>
    </form>
    <button type="button" id="closeAdminModal" class="payment-modal-secondary">Cancel</button>
    <div id="adminLoginError" class="payment-modal-error"></div>
  </div>
</div>

<form method="post" action="order_void.php" id="paymentVoidOrderForm" data-no-confirm hidden>
  <input type="hidden" name="order_id" value="<?= (int) $orderId ?>">
  <input type="hidden" name="confirm_void" value="1">
</form>

<script>
(function() {
  const orderId = <?= (int) $orderId ?>;
  const userRole = "<?= htmlspecialchars($userRole, ENT_QUOTES) ?>";
  const pendingOrder = <?= $isPending ? 'true' : 'false' ?>;
  let remainingAmount = <?= json_encode((float) $remaining) ?>;
  const payInput = document.getElementById('payInput');
  const paidValue = document.getElementById('paidValue');
  const remainValue = document.getElementById('remainValue');
  const paymentsTbody = document.getElementById('paymentsTbody');
  const submitOrderBtn = document.getElementById('submitOrderBtn');
  const paymentClientNotice = document.getElementById('paymentClientNotice');

  function money(value) {
    return 'PHP ' + Number(value || 0).toFixed(2);
  }

  function showPaymentNotice(message, type) {
    if (typeof window.kapeNotify === 'function') {
      window.kapeNotify(message, type === 'success' ? 'success' : 'error');
      return;
    }
    if (!paymentClientNotice) return;
    paymentClientNotice.textContent = message;
    paymentClientNotice.classList.remove('is-error', 'is-success');
    paymentClientNotice.classList.add(type === 'success' ? 'is-success' : 'is-error');
    paymentClientNotice.hidden = false;
    paymentClientNotice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearPaymentNotice() {
    if (!paymentClientNotice) return;
    paymentClientNotice.hidden = true;
    paymentClientNotice.textContent = '';
    paymentClientNotice.classList.remove('is-error', 'is-success');
  }

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data)
    }).then(function(response) {
      return response.json().then(function(payload) {
        if (!response.ok) throw payload;
        return payload;
      });
    });
  }

  function setModal(id, open) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.toggle('is-open', open);
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
  }

  async function confirmVoidOrder() {
    const confirmed = await window.kapeConfirm({
      title: 'Void Order',
      message: <?= json_encode('Void ' . $orderLabel . '? This action cannot be undone.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      type: 'danger',
      okLabel: 'Void Order'
    });
    if (!confirmed) return;
    document.getElementById('paymentVoidOrderForm').submit();
  }

  function appendKey(key) {
    if (!payInput || !pendingOrder) return;
    if (key === ',') return;
    if (key === '.' && payInput.value.includes('.')) return;
    payInput.value = (payInput.value || '') + key;
    payInput.focus();
  }

  function addAmount(value) {
    if (!payInput || !pendingOrder) return;
    const current = parseFloat(payInput.value || '0') || 0;
    payInput.value = (current + Number(value || 0)).toFixed(2);
    payInput.focus();
  }

  async function submitCashPayment() {
    if (!pendingOrder) return;
    const amount = parseFloat(payInput.value || '0');
    if (!amount || amount <= 0) {
      showPaymentNotice('Enter a valid payment amount.', 'error');
      return;
    }

    clearPaymentNotice();

    document.querySelectorAll('[data-method], [data-action="submit"]').forEach(function(button) {
      button.setAttribute('disabled', 'disabled');
    });

    try {
      const result = await post('payment_manual_add.php', { order_id: orderId, method: 'cash', amount });
      if (!result.ok) {
        showPaymentNotice(result.message || 'Payment failed.', 'error');
        return;
      }
      payInput.value = '';
      await refreshPaymentData();
      showPaymentNotice('Payment recorded successfully.', 'success');
    } catch (error) {
      showPaymentNotice(error.message || 'Payment failed.', 'error');
    } finally {
      document.querySelectorAll('[data-method], [data-action="submit"]').forEach(function(button) {
        button.removeAttribute('disabled');
      });
    }
  }

  async function refreshPaymentData() {
    try {
      const response = await fetch('order_payment_data.php?order_id=' + encodeURIComponent(orderId) + '&t=' + Date.now(), { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      if (!data.ok) return;

      remainingAmount = Number(data.order.remaining || 0);
      if (paidValue) paidValue.textContent = money(data.order.paid);
      if (remainValue) {
        remainValue.textContent = money(data.order.remaining);
        remainValue.classList.toggle('is-due', remainingAmount > 0);
        remainValue.classList.toggle('is-paid', remainingAmount <= 0);
      }

      if (paymentsTbody) {
        paymentsTbody.innerHTML = '';
        if (!data.payments.length) {
          paymentsTbody.innerHTML = '<tr><td colspan="4" class="payment-empty">No payments recorded.</td></tr>';
        }
        data.payments.forEach(function(payment) {
          const row = document.createElement('tr');
          row.dataset.id = payment.id;
          const date = payment.created_at ? new Date(payment.created_at.replace(' ', 'T')).toLocaleString('en-PH', { month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';
          row.innerHTML = `
            <td>${(payment.provider || '').charAt(0).toUpperCase() + (payment.provider || '').slice(1)}</td>
            <td>${money(payment.amount)}</td>
            <td>${date}</td>
            <td>${payment.removable && pendingOrder ? '<button type="button" class="payment-mini-btn" data-remove="' + payment.id + '"><i class="fa fa-xmark"></i></button>' : ''}</td>
          `;
          paymentsTbody.appendChild(row);
        });
      }

      if (submitOrderBtn) {
        if (data.order.status === 'pending' && remainingAmount <= 0) {
          submitOrderBtn.removeAttribute('disabled');
        } else {
          submitOrderBtn.setAttribute('disabled', 'disabled');
        }
      }
    } catch (error) {
      return;
    }
  }

  document.querySelectorAll('.payment-keypad button').forEach(function(button) {
    button.addEventListener('click', function() {
      if (button.dataset.k) appendKey(button.dataset.k);
      if (button.dataset.quick) addAmount(button.dataset.quick);
      if (button.dataset.action === 'back' && payInput) payInput.value = payInput.value.slice(0, -1);
      if (button.dataset.action === 'clear' && payInput) payInput.value = '';
      if (button.dataset.action === 'exact' && payInput) payInput.value = remainingAmount.toFixed(2);
      if (button.dataset.action === 'plus') addAmount(button.dataset.value);
      if (button.dataset.action === 'submit') submitCashPayment();
    });
  });

  document.querySelector('[data-method="cash"]')?.addEventListener('click', submitCashPayment);

  paymentsTbody?.addEventListener('click', async function(event) {
    const button = event.target.closest('[data-remove]');
    if (!button) return;
    const ok = await window.kapeConfirm({
      title: 'Remove Payment',
      message: 'Remove this manual payment from this order?',
      type: 'danger'
    });
    if (!ok) return;

    try {
      const result = await post('payment_manual_remove.php', { order_id: orderId, payment_id: button.dataset.remove });
      if (!result.ok) {
        showPaymentNotice(result.message || 'Failed to remove payment.', 'error');
        return;
      }
      await refreshPaymentData();
      showPaymentNotice('Payment removed successfully.', 'success');
    } catch (error) {
      showPaymentNotice(error.message || 'Failed to remove payment.', 'error');
    }
  });

  submitOrderBtn?.addEventListener('click', function() {
    if (submitOrderBtn.hasAttribute('disabled')) {
      showPaymentNotice('Please settle the payment first before printing the receipt.', 'error');
      return;
    }
    window.location.href = 'receipt_template.php?order_id=' + encodeURIComponent(orderId) + '&copy=customer';
  });

  document.getElementById('voidOrderBtn')?.addEventListener('click', function() {
    if (userRole === 'admin' || userRole === 'business_admin') {
      confirmVoidOrder();
    } else {
      setModal('adminPermissionModal', true);
    }
  });

  document.getElementById('closeAdminModal')?.addEventListener('click', function() {
    setModal('adminPermissionModal', false);
  });

  document.querySelectorAll('.payment-modal').forEach(function(modal) {
    modal.addEventListener('click', function(event) {
      if (event.target === modal) setModal(modal.id, false);
    });
  });

  document.getElementById('adminLoginForm')?.addEventListener('submit', function(event) {
    event.preventDefault();
    const form = event.target;
    const error = document.getElementById('adminLoginError');
    if (error) {
      error.textContent = '';
      error.classList.remove('is-visible');
    }

    fetch('order_list_admin_login.php', { method: 'POST', body: new FormData(form) })
      .then(function(response) {
        return response.json().then(function(payload) {
          if (!response.ok) throw payload;
          return payload;
        });
      })
      .then(function(result) {
        if (!result.success) throw result;
        setModal('adminPermissionModal', false);
        confirmVoidOrder();
      })
      .catch(function(result) {
        if (!error) return;
        error.textContent = result.error || 'Admin login failed. Please try again.';
        error.classList.add('is-visible');
      });
  });

  setTimeout(refreshPaymentData, 1000);
  setInterval(refreshPaymentData, 5000);
})();
</script>
</body>
</html>
