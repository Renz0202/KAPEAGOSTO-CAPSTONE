<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_capability('generate_reports');


$pdo = db();
$user = current_user();
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$today = date('Y-m-d');
$isRangeRequest = isset($_GET['from'], $_GET['to'])
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']);

// Determine redirect target (after generation, go back to reports page)
$redirectDate = $date; // default single-date
$redirectTo = 'reports.php?report_date=' . urlencode($redirectDate);

if (!$isRangeRequest) {

// 🔹 Check if report for selected date already exists
$exists = $pdo->prepare("SELECT id FROM daily_sales_reports WHERE report_date = ?");
$exists->execute([$date]);
$already_exists = $exists->fetch();

// 🔹 Fetch completed orders for selected date
$items = $pdo->prepare("
    SELECT 
        oi.item_id,
        i.name AS item_name,
        i.price,
        SUM(oi.qty) AS total_qty,
        SUM(oi.subtotal) AS total_amount
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN items i ON oi.item_id = i.id
    WHERE DATE(o.created_at) = ? AND o.status = 'completed'
    GROUP BY oi.item_id
");
$items->execute([$date]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// 🔹 Count completed orders for selected date
$total_orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=? AND status='completed'");
$total_orders_stmt->execute([$date]);
$total_orders = $total_orders_stmt->fetchColumn();

$success = false; // track success state for single-date generation

if (!$items) {
  if ($already_exists) {
    $message = "Report for $date already exists. No new completed orders to add.";
  } else {
    $message = "No completed orders found for $date.";
  }
} else {
    $total_sales = array_sum(array_column($items, 'total_amount'));
    $sales_tax = round($total_sales * 0.12, 2);

    // 🔹 Inventory activity logs for selected date
    $invLogs = $pdo->prepare("
        SELECT 
            inv.id AS inventory_id,
            inv.name AS item_name,
            inv.cup_size,
            inv.unit,
            inv.critical_level, /* NEW */
            COALESCE(SUM(CASE WHEN il.change_type IN ('used','auto-deduct') THEN il.quantity ELSE 0 END),0) AS total_used,
            inv.qty AS remaining,
            MAX(il.created_at) AS last_activity
        FROM inventory inv
        LEFT JOIN inventory_logs il ON il.inventory_id = inv.id AND DATE(il.created_at) = ?
        GROUP BY inv.id
        ORDER BY inv.name, inv.cup_size
    ");
    $invLogs->execute([$date]);
    $invLogs = $invLogs->fetchAll(PDO::FETCH_ASSOC);

    // 🔹 Save report files
    $folder = __DIR__ . "/../../public/reports/" . $date;
    if (!is_dir($folder)) mkdir($folder, 0777, true);

    $json_file = "$folder/report.json";
    file_put_contents($json_file, json_encode([
        'date' => $date,
        'sales' => [
            'total_sales' => $total_sales,
            'total_orders' => $total_orders,
            'tax' => $sales_tax,
            'items' => $items
        ],
        'inventory' => $invLogs
    ], JSON_PRETTY_PRINT));

    $csv_file = "$folder/report.csv";
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['TYPE', 'ID', 'NAME', 'QTY/USED', 'PRICE/REMAINING', 'TOTAL/STATUS']);
    foreach ($items as $it) {
        fputcsv($fp, ['SALE', $it['item_id'], $it['item_name'], $it['total_qty'], $it['price'], $it['total_amount']]);
    }
    foreach ($invLogs as $inv) {
        fputcsv($fp, ['INVENTORY', $inv['inventory_id'], $inv['item_name'], $inv['total_used'], $inv['remaining'], $inv['last_activity']]);
    }
    fclose($fp);

    $now = date('Y-m-d H:i:s');

    if ($already_exists) {
        // Update the existing report
        $stmt = $pdo->prepare("
            UPDATE daily_sales_reports
            SET total_sales = ?, total_orders = ?, sales_tax = ?, notes = ?
            WHERE report_date = ?
        ");
        $stmt->execute([
            $total_sales,
            $total_orders,
            $sales_tax,
            'Auto-updated report',
            $date
        ]);

        // Get the report id
        $report_id_stmt = $pdo->prepare("SELECT id FROM daily_sales_reports WHERE report_date = ?");
        $report_id_stmt->execute([$date]);
        $report_id = $report_id_stmt->fetchColumn();

        // Remove old sales items for selected date
        $del_stmt = $pdo->prepare("DELETE FROM daily_sales_items WHERE report_id = ?");
        $del_stmt->execute([$report_id]);
    } else {
        // Insert new report
        $stmt = $pdo->prepare("
            INSERT INTO daily_sales_reports (report_date, generated_by, total_sales, total_orders, sales_tax, notes)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([$date, $user['id'], $total_sales, $total_orders, $sales_tax, 'Auto-generated report']);
        $report_id = $pdo->lastInsertId();
    }

    // Insert latest sales items
    $item_stmt = $pdo->prepare("
        INSERT INTO daily_sales_items (report_id, item_id, item_name, price, qty, subtotal)
        VALUES (?,?,?,?,?,?)
    ");
    foreach ($items as $it) {
        $item_stmt->execute([
            $report_id,
            $it['item_id'],
            $it['item_name'],
            $it['price'],
            $it['total_qty'],
            $it['total_amount']
        ]);
    }

  $message = "Daily report generated and updated successfully!";
  $success = true;
}
}

// Support for date range generation
if ($isRangeRequest) {
    $from = $_GET['from'];
    $to = $_GET['to'];

    // Validate range
    if ($from > $to) {
  $message = "Invalid range: FROM date must be earlier than or equal to TO date.";
        $range_results = [];
    } else {
        $dates = [];
        $start = strtotime($from);
        $end = strtotime($to);
        for ($d = $start; $d <= $end; $d = strtotime('+1 day', $d)) {
            $dates[] = date('Y-m-d', $d);
        }
        $range_results = [];
        foreach ($dates as $date) {
            // ...repeat the single-date report generation logic for each $date...
            $exists = $pdo->prepare("SELECT id FROM daily_sales_reports WHERE report_date = ?");
            $exists->execute([$date]);
            $already_exists = $exists->fetch();

            $items = $pdo->prepare("
                SELECT 
                    oi.item_id,
                    i.name AS item_name,
                    i.price,
                    SUM(oi.qty) AS total_qty,
                    SUM(oi.subtotal) AS total_amount
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN items i ON oi.item_id = i.id
                WHERE DATE(o.created_at) = ? AND o.status = 'completed'
                GROUP BY oi.item_id
            ");
            $items->execute([$date]);
            $items = $items->fetchAll(PDO::FETCH_ASSOC);

            $total_orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=? AND status='completed'");
            $total_orders_stmt->execute([$date]);
            $total_orders = $total_orders_stmt->fetchColumn();

            if (!$items) {
                if ($already_exists) {
          $range_results[] = [
            'date' => $date,
            'message' => "Report for $date already exists. No new completed orders to add.",
            'success' => false
          ];
                } else {
          $range_results[] = [
            'date' => $date,
            'message' => "No completed orders found for $date.",
            'success' => false
          ];
                }
                continue;
            }

            $total_sales = array_sum(array_column($items, 'total_amount'));
            $sales_tax = round($total_sales * 0.12, 2);

            $invLogs = $pdo->prepare("
                SELECT 
                    inv.id AS inventory_id,
                    inv.name AS item_name,
                    inv.cup_size,
                    inv.unit,
                    inv.critical_level, /* NEW */
                    COALESCE(SUM(CASE WHEN il.change_type IN ('used','auto-deduct') THEN il.quantity ELSE 0 END),0) AS total_used,
                    inv.qty AS remaining,
                    MAX(il.created_at) AS last_activity
                FROM inventory inv
                LEFT JOIN inventory_logs il ON il.inventory_id = inv.id AND DATE(il.created_at) = ?
                GROUP BY inv.id
                ORDER BY inv.name, inv.cup_size
            ");
            $invLogs->execute([$date]);
            $invLogs = $invLogs->fetchAll(PDO::FETCH_ASSOC);

            $folder = __DIR__ . "/../../public/reports/" . $date;
            if (!is_dir($folder)) mkdir($folder, 0777, true);

            $json_file = "$folder/report.json";
            file_put_contents($json_file, json_encode([
                'date' => $date,
                'sales' => [
                    'total_sales' => $total_sales,
                    'total_orders' => $total_orders,
                    'tax' => $sales_tax,
                    'items' => $items
                ],
                'inventory' => $invLogs
            ], JSON_PRETTY_PRINT));

            $csv_file = "$folder/report.csv";
            $fp = fopen($csv_file, 'w');
            fputcsv($fp, ['TYPE', 'ID', 'NAME', 'QTY/USED', 'PRICE/REMAINING', 'TOTAL/STATUS']);
            foreach ($items as $it) {
                fputcsv($fp, ['SALE', $it['item_id'], $it['item_name'], $it['total_qty'], $it['price'], $it['total_amount']]);
            }
            foreach ($invLogs as $inv) {
                fputcsv($fp, ['INVENTORY', $inv['inventory_id'], $inv['item_name'], $inv['total_used'], $inv['remaining'], $inv['last_activity']]);
            }
            fclose($fp);

            $now = date('Y-m-d H:i:s');

            if ($already_exists) {
                $stmt = $pdo->prepare("
                    UPDATE daily_sales_reports
                    SET total_sales = ?, total_orders = ?, sales_tax = ?, notes = ?
                    WHERE report_date = ?
                ");
                $stmt->execute([
                    $total_sales,
                    $total_orders,
                    $sales_tax,
                    'Auto-updated report',
                    $date
                ]);
                $report_id_stmt = $pdo->prepare("SELECT id FROM daily_sales_reports WHERE report_date = ?");
                $report_id_stmt->execute([$date]);
                $report_id = $report_id_stmt->fetchColumn();
                $del_stmt = $pdo->prepare("DELETE FROM daily_sales_items WHERE report_id = ?");
                $del_stmt->execute([$report_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO daily_sales_reports (report_date, generated_by, total_sales, total_orders, sales_tax, notes)
                    VALUES (?,?,?,?,?,?)
                ");
                $stmt->execute([$date, $user['id'], $total_sales, $total_orders, $sales_tax, 'Auto-generated report']);
                $report_id = $pdo->lastInsertId();
            }

            $item_stmt = $pdo->prepare("
                INSERT INTO daily_sales_items (report_id, item_id, item_name, price, qty, subtotal)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($items as $it) {
                $item_stmt->execute([
                    $report_id,
                    $it['item_id'],
                    $it['item_name'],
                    $it['price'],
                    $it['total_qty'],
                    $it['total_amount']
                ]);
            }

      $range_results[] = [
        'date' => $date,
        'message' => "Daily report for $date generated and updated successfully!",
        'success' => true
      ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kape Agosto | Generate Daily Report</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <?php
    // If a range is being requested, prefer redirecting back with the 'to' date
    if (
      isset($_GET['from']) && isset($_GET['to']) &&
      preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) &&
      preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])
    ) {
      $redirectDate = $_GET['to'];
      $redirectTo = 'reports.php?report_date=' . urlencode($redirectDate);
    }
  ?>
  <meta http-equiv="refresh" content="15;url=<?= htmlspecialchars($redirectTo) ?>">
  <script>
    // Fallback JS-based redirect with a visible countdown
    (function(){
      var seconds = 30;
      function tick(){
        var el = document.getElementById('auto-redirect-count');
        if (el) el.textContent = seconds;
        if (seconds <= 0) { window.location.href = '<?= htmlspecialchars($redirectTo) ?>'; return; }
        seconds -= 1;
        setTimeout(tick, 1000);
      }
      window.addEventListener('DOMContentLoaded', tick);
    })();
  </script>
</head>
<body class="kape-reports-module-generate-daily-report">
  <?php include dirname(__DIR__, 2) . "/includes/sidebar.php"; ?>
  <?php include dirname(__DIR__, 2) . "/includes/topbar.php"; ?>

  <?php include __DIR__ . '/partials/generate_daily_report_content.php'; ?>
  <?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
<?php exit; ?>

  <div class="content">
    <div class="button-group" style="margin-bottom:16px;">
      <a href="reports.php" class="btn gray">
        <i class="fa fa-arrow-left"></i> Back
      </a>
    </div>

    <?php if (isset($range_results)): ?>
      <h1>🗓️ Generate Daily Reports (<?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>)</h1>
      <?php foreach ($range_results as $result): ?>
        <div class="alert <?= $result['success'] ? 'alert-success' : 'alert-warning' ?>">
          <?= htmlspecialchars($result['message']) ?>
        </div>
      <?php endforeach; ?>
      <div class="alert" style="background:#eaf6ff;color:#2c3e50;">
        Returning to Reports in <b><span id="auto-redirect-count">15</span>s</b>…
      </div>
    <?php else: ?>
      <h1>🗓️ Generate Daily Report (<?= htmlspecialchars($date) ?>)</h1>
      <div class="alert <?= $success ? 'alert-success' : 'alert-warning' ?>">
        <?= htmlspecialchars($message) ?>
      </div>
      <?php if (!empty($items)): ?>
      <h2>📊 Sales Summary</h2>
      <div style="margin-bottom:8px; color:#555;">
        <b>Date:</b> <?= date('F d, Y (l)', strtotime($date)) ?>
      </div>
      <table>
        <thead>
          <tr><th>Item</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr>
        </thead>
        <tbody>
          <?php foreach($items as $it): ?>
          <tr>
            <td><?= htmlspecialchars($it['item_name']) ?></td>
            <td>₱<?= number_format($it['price'],2) ?></td>
            <td><?= $it['total_qty'] ?></td>
            <td>₱<?= number_format($it['total_amount'],2) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="total-row">
            <td colspan="3">Total Orders: <?= $total_orders ?></td>
            <td><b>₱<?= number_format($total_sales,2) ?></b></td>
          </tr>
        </tbody>
      </table>

      <h2>📦 Inventory Usage</h2>
      <table>
        <thead>
          <tr>
            <th>Item</th><th>Cup Size</th><th>Unit</th><th>Used</th><th>Remaining</th><th>Critical</th><th>Last Updated</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($invLogs as $inv): ?>
          <?php
            $low = ($inv['critical_level'] !== null && $inv['critical_level'] > 0 && $inv['remaining'] <= $inv['critical_level']);
          ?>
          <tr <?= $low ? 'style="background:#ffdddd;"' : '' ?>>
            <td><?= htmlspecialchars($inv['item_name']) ?></td>
            <td><?= htmlspecialchars($inv['cup_size'] ?? '-') ?></td>
            <td><?= htmlspecialchars($inv['unit']) ?></td>
            <td><?= number_format($inv['total_used'],2) ?></td>
            <td><?= number_format($inv['remaining'],2) ?></td>
            <td><?= $inv['critical_level'] !== null ? number_format($inv['critical_level'],2) : 'N/A' ?></td>
            <td><?= $inv['last_activity'] ? date('h:i A', strtotime($inv['last_activity'])) : '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="button-group" style="margin-top:20px;">
        <a href="scripts/export_daily_report_excel.php?date=<?= $date ?>" class="btn blue">
          <i class="fa fa-file-excel"></i> Export Excel
        </a>
        <a href="<?= htmlspecialchars($redirectTo) ?>" class="btn gray">
          <i class="fa fa-arrow-left"></i> Back
        </a>
      </div>
      <div class="alert" style="background:#eaf6ff;color:#2c3e50;margin-top:10px;">
        Returning to Reports in <b><span id="auto-redirect-count">15</span>s</b>…
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
