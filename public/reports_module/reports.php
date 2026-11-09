<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_capability('view_reports');

$pdo = db();

$filterDate = isset($_GET['report_date']) ? (string) $_GET['report_date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

function reports_money($amount): string {
    return 'PHP ' . number_format((float) $amount, 2);
}

function reports_date_label(?string $date): string {
    if (!$date) {
        return 'Not available';
    }

    $time = strtotime($date);
    return $time ? date('M d, Y', $time) : $date;
}

function reports_datetime_label(?string $date): string {
    if (!$date) {
        return 'Not available';
    }

    $time = strtotime($date);
    return $time ? date('M d, Y g:i A', $time) : $date;
}

$stmt = $pdo->prepare('SELECT * FROM daily_sales_reports WHERE report_date = ? LIMIT 1');
$stmt->execute([$filterDate]);
$selectedReport = $stmt->fetch(PDO::FETCH_ASSOC);

$dailyReportsStmt = $pdo->query("
    SELECT r.*, COALESCE(u.username, r.generated_by) AS generated_by_display
    FROM daily_sales_reports r
    LEFT JOIN users u ON u.id = r.generated_by
    ORDER BY r.report_date DESC
");
$dailyReports = $dailyReportsStmt->fetchAll(PDO::FETCH_ASSOC);

$voidedOrders = [];
$voidedStmt = $pdo->query("
    SELECT DATE(COALESCE(voided_at, cancelled_at, created_at)) AS report_date, COUNT(*) AS total
    FROM orders
    WHERE status IN ('void', 'voided', 'cancelled')
    GROUP BY DATE(COALESCE(voided_at, cancelled_at, created_at))
");
foreach ($voidedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $voidedOrders[$row['report_date']] = (int) $row['total'];
}

$ordersExist = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() > 0;
$inventoryExist = (int) $pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn() > 0;
$reportsExist = count($dailyReports) > 0;

$reportCount = count($dailyReports);
$totalOrders = 0;
$totalSales = 0.0;
$latestReport = $dailyReports[0] ?? null;
foreach ($dailyReports as $reportRow) {
    $totalOrders += (int) ($reportRow['total_orders'] ?? 0);
    $totalSales += (float) ($reportRow['total_sales'] ?? 0);
}

$selectedVoids = $voidedOrders[$filterDate] ?? 0;
$selectedSales = $selectedReport ? (float) $selectedReport['total_sales'] : 0.0;
$selectedOrders = $selectedReport ? (int) $selectedReport['total_orders'] : 0;
$selectedGeneratedBy = $selectedReport ? (string) ($selectedReport['generated_by_display'] ?? $selectedReport['generated_by'] ?? 'System') : 'Not generated';
$paymentStmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN status = 'succeeded' THEN amount ELSE 0 END), 0) AS collected,
           SUM(status = 'succeeded') AS successful_count,
           SUM(status IN ('created','pending')) AS pending_count,
           SUM(status IN ('failed','cancelled','expired')) AS failed_count
    FROM payments
    WHERE DATE(COALESCE(paid_at, created_at)) = ?
");
$paymentStmt->execute([$filterDate]);
$selectedPayments = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: ['collected' => 0, 'successful_count' => 0, 'pending_count' => 0, 'failed_count' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kape Agosto | Reports</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/reports-workspace.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/reports-workspace.css')) ?>">
</head>
<body class="kape-reports-module-reports sales-reports-page">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content reports-page sales-reports-workspace">
  <section class="reports-hero" aria-labelledby="reports-title">
    <div>
      <p class="reports-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Business Intelligence <i class="fa fa-chevron-right"></i> Reports</p>
      <h1 id="reports-title">Daily Reports</h1>
      <p>Generate and review daily sales and inventory activity records. Excel exports are available to Admin and Manager accounts.</p>
    </div>
    <div class="reports-hero-actions">
      <?php if (is_admin()): ?><a href="backup_restore.php" class="reports-action secondary">
        <i class="fa fa-database"></i> Backup & Restore
      </a><?php endif; ?>
      <a href="<?= is_manager() ? 'manager_settings.php' : 'inventory.php' ?>" class="reports-action muted">
        <i class="fa fa-arrow-left"></i>
        <?= is_manager() ? 'Forecast Settings' : 'Inventory' ?>
      </a>
    </div>
  </section>

  <?php if (($ordersExist || $inventoryExist) && !$reportsExist): ?>
    <section class="reports-notice warning" role="status">
      <i class="fa fa-circle-exclamation"></i>
      <div>
        <strong>Reports have not been generated yet.</strong>
        <span>Generate a report to keep sales and inventory records aligned.</span>
      </div>
      <a href="generate_daily_report.php?date=<?= urlencode($filterDate) ?>">Generate selected date</a>
    </section>
  <?php endif; ?>

  <section class="reports-summary-grid" aria-label="Report summary">
    <article class="reports-summary-card">
      <span class="reports-card-icon"><i class="fa fa-file-lines"></i></span>
      <span class="reports-card-label">Reports</span>
      <strong><?= number_format($reportCount) ?></strong>
    </article>
    <article class="reports-summary-card">
      <span class="reports-card-icon"><i class="fa fa-receipt"></i></span>
      <span class="reports-card-label">Recorded Orders</span>
      <strong><?= number_format($totalOrders) ?></strong>
    </article>
    <article class="reports-summary-card">
      <span class="reports-card-icon"><i class="fa fa-peso-sign"></i></span>
      <span class="reports-card-label">Recorded Sales</span>
      <strong><?= htmlspecialchars(reports_money($totalSales)) ?></strong>
    </article>
    <article class="reports-summary-card">
      <span class="reports-card-icon"><i class="fa fa-clock-rotate-left"></i></span>
      <span class="reports-card-label">Latest Report</span>
      <strong><?= htmlspecialchars(reports_date_label($latestReport['report_date'] ?? null)) ?></strong>
    </article>
  </section>

  <section class="reports-toolbar" aria-label="Report tools">
    <form method="get" id="dateFilterForm" class="reports-date-form">
      <label for="report_date">View Date</label>
      <div class="reports-date-row">
        <input type="date" id="report_date" name="report_date" value="<?= htmlspecialchars($filterDate) ?>">
        <button type="submit" class="reports-action primary">
          <i class="fa fa-filter"></i>
          Apply
        </button>
      </div>
    </form>

    <div class="reports-tool-actions">
      <a href="generate_daily_report.php?date=<?= urlencode($filterDate) ?>" class="reports-action primary">
        <i class="fa fa-rotate"></i>
        Generate Date
      </a>
      <button type="button" class="reports-action secondary" id="showRangeToolsBtn">
        <i class="fa fa-calendar-days"></i>
        Range Tools
      </button>
    </div>

    <form method="get" action="generate_daily_report.php" id="rangeToolsForm" class="reports-range-form" hidden>
      <label>
        <span>From</span>
        <input type="date" id="from" name="from" required>
      </label>
      <label>
        <span>To</span>
        <input type="date" id="to" name="to" required>
      </label>
      <button type="submit" class="reports-action primary" id="btnGenerateRange">
        <i class="fa fa-wand-magic-sparkles"></i>
        Generate
      </button>
      <button type="button" class="reports-action secondary" id="btnExportRange">
        <i class="fa fa-file-excel"></i>
        Export
      </button>
      <button type="button" class="reports-action muted" id="btnCancelRange">
        Cancel
      </button>
    </form>
  </section>

  <section class="reports-selected-panel" aria-labelledby="selected-report-title">
    <div class="reports-panel-heading">
      <div>
        <span class="reports-eyebrow">Selected Date</span>
        <h2 id="selected-report-title"><?= htmlspecialchars(reports_date_label($filterDate)) ?></h2>
      </div>
      <?php if ($selectedReport): ?>
        <a href="view_daily_report.php?date=<?= urlencode($filterDate) ?>" class="reports-action secondary">
          <i class="fa fa-eye"></i>
          View Detailed Report
        </a>
      <?php endif; ?>
    </div>

    <?php if ($selectedReport): ?>
      <div class="reports-selected-grid">
        <div>
          <span>Total Sales</span>
          <strong><?= htmlspecialchars(reports_money($selectedSales)) ?></strong>
        </div>
        <div>
          <span>Total Orders</span>
          <strong><?= number_format($selectedOrders) ?></strong>
        </div>
        <div>
          <span>Voided / Cancelled</span>
          <strong><?= number_format($selectedVoids) ?></strong>
        </div>
        <div>
          <span>Generated By</span>
          <strong><?= htmlspecialchars($selectedGeneratedBy) ?></strong>
        </div>
        <div>
          <span>Payments Collected</span>
          <strong><?= htmlspecialchars(reports_money($selectedPayments['collected'])) ?></strong>
        </div>
        <div>
          <span>Payment Records</span>
          <strong><?= number_format((int) $selectedPayments['successful_count']) ?> successful</strong>
        </div>
        <div>
          <span>Pending / Failed Payments</span>
          <strong><?= number_format((int) $selectedPayments['pending_count']) ?> / <?= number_format((int) $selectedPayments['failed_count']) ?></strong>
        </div>
      </div>
    <?php else: ?>
      <div class="reports-empty-state">
        <i class="fa fa-folder-open"></i>
        <strong>No report found for this date.</strong>
        <span>Generate the selected date once completed orders are ready for reporting.</span>
        <a href="generate_daily_report.php?date=<?= urlencode($filterDate) ?>" class="reports-action primary">
          <i class="fa fa-rotate"></i>
          Generate Report
        </a>
      </div>
    <?php endif; ?>
  </section>

  <section class="reports-table-panel" aria-labelledby="reports-history-title">
    <div class="reports-panel-heading">
      <div>
        <span class="reports-eyebrow">History</span>
        <h2 id="reports-history-title">Generated Reports</h2>
      </div>
      <span class="reports-count-pill"><?= number_format($reportCount) ?> record<?= $reportCount === 1 ? '' : 's' ?></span>
    </div>

    <div class="reports-table-wrap" data-sales-report-history tabindex="0" role="region" aria-labelledby="reports-history-title">
    <?php if (empty($dailyReports)): ?>
      <div class="reports-empty-state compact">
        <i class="fa fa-chart-line"></i>
        <strong>No generated reports yet.</strong>
        <span>Use the Generate Date or Range Tools button to create records.</span>
      </div>
    <?php else: ?>
        <table class="reports-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Orders</th>
              <th>Total Sales</th>
              <th>Voided</th>
              <th>Generated By</th>
              <th>Generated At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dailyReports as $row): ?>
              <?php $rowDate = (string) ($row['report_date'] ?? ''); ?>
              <tr class="<?= $rowDate === $filterDate ? 'selected-row' : '' ?>">
                <td data-label="Date">
                  <strong><?= htmlspecialchars(reports_date_label($rowDate)) ?></strong>
                  <span><?= htmlspecialchars($rowDate) ?></span>
                </td>
                <td data-label="Orders"><?= number_format((int) ($row['total_orders'] ?? 0)) ?></td>
                <td data-label="Total Sales"><?= htmlspecialchars(reports_money($row['total_sales'] ?? 0)) ?></td>
                <td data-label="Voided"><?= number_format($voidedOrders[$rowDate] ?? 0) ?></td>
                <td data-label="Generated By"><?= htmlspecialchars((string) ($row['generated_by_display'] ?? 'System')) ?></td>
                <td data-label="Generated At"><?= htmlspecialchars(reports_datetime_label($row['generated_at'] ?? null)) ?></td>
                <td data-label="Actions">
                  <div class="reports-row-actions">
                    <a href="view_daily_report.php?date=<?= urlencode($rowDate) ?>" title="View report">
                      <i class="fa fa-eye"></i>
                    </a>
<?php if (can('export_reports')): ?>
                      <a href="../scripts/export_daily_report_excel.php?date=<?= urlencode($rowDate) ?>" title="Export report to Excel" aria-label="Export report for <?= htmlspecialchars($rowDate) ?> to Excel">
                        <i class="fa fa-file-excel"></i>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
    <?php endif; ?>
    </div>
  </section>
</main>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
(function() {
  const rangeForm = document.getElementById('rangeToolsForm');
  const showRangeBtn = document.getElementById('showRangeToolsBtn');
  const cancelRangeBtn = document.getElementById('btnCancelRange');
  const generateRangeBtn = document.getElementById('btnGenerateRange');
  const exportRangeBtn = document.getElementById('btnExportRange');
  const fromInput = document.getElementById('from');
  const toInput = document.getElementById('to');

  function todayStr() {
    const today = new Date();
    return today.toISOString().slice(0, 10);
  }

  function validateRange() {
    if (!fromInput.value || !toInput.value) {
      return 'Please select both start and end dates.';
    }

    if (fromInput.value > toInput.value) {
      return 'The start date must be earlier than or equal to the end date.';
    }

    return '';
  }

  async function showMessage(message) {
    await window.kapeAlert({
      title: 'Range Tools',
      message: message,
      type: 'warning'
    });
  }

  showRangeBtn?.addEventListener('click', function() {
    const today = todayStr();
    if (!fromInput.value) fromInput.value = today;
    if (!toInput.value) toInput.value = today;
    toInput.min = fromInput.value;
    rangeForm.hidden = false;
    showRangeBtn.hidden = true;
  });

  fromInput?.addEventListener('change', function() {
    toInput.min = fromInput.value;
    if (toInput.value && toInput.value < fromInput.value) {
      toInput.value = fromInput.value;
    }
  });

  cancelRangeBtn?.addEventListener('click', function() {
    rangeForm.hidden = true;
    showRangeBtn.hidden = false;
  });

  generateRangeBtn?.addEventListener('click', async function(event) {
    event.preventDefault();
    const validationMessage = validateRange();
    if (validationMessage) {
      await showMessage(validationMessage);
      return;
    }

    const confirmMessage = 'Generate reports from ' + fromInput.value + ' to ' + toInput.value + '?';
    const ok = await window.kapeConfirm({
      title: 'Generate Reports',
      message: confirmMessage,
      type: 'warning'
    });

    if (ok) rangeForm.submit();
  });

  exportRangeBtn?.addEventListener('click', async function() {
    const validationMessage = validateRange();
    if (validationMessage) {
      await showMessage(validationMessage);
      return;
    }

    const previousAction = rangeForm.action;
    rangeForm.action = '../scripts/export_reports_range_excel.php';
    rangeForm.submit();
    rangeForm.action = previousAction;
  });
})();
</script>
</body>
</html>
