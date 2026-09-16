<?php
// Ensure PHP starts at the first byte so no stray output appears before the HTML.
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_capability('view_reports');

date_default_timezone_set('Asia/Manila');

$pdo = db();

// Robust detection of PhpSpreadsheet
$hasXlsx = false;
$autoload = null;
$scanDir = realpath(__DIR__);
for ($i = 0; $i < 5 && $scanDir; $i++) {
    $cand = $scanDir . '/vendor/autoload.php';
    if (is_file($cand)) { $autoload = $cand; break; }
    $scanDir = dirname($scanDir);
}
if ($autoload) {
    require_once $autoload;
    // Use string class name to avoid static analysis errors when package is missing
    $hasXlsx = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
}

$date = $_GET['date'] ?? date('Y-m-d');

// Fetch report summary
$stmt = $pdo->prepare("SELECT * FROM daily_sales_reports WHERE report_date = ?");
$stmt->execute([$date]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    // return JSON for partial requests instead of HTML
    if (isset($_GET['partial'])) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => "No report found for $date."]);
        exit;
    }
    echo "<h2>No report found for $date.</h2>";
    exit;
}

// Fetch sales items
$sales_stmt = $pdo->prepare("
    SELECT item_name, price, qty, subtotal
    FROM daily_sales_items
    WHERE report_id = ?
");
$sales_stmt->execute([$report['id']]);
$sales_items = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch inventory usage (align with inventory.php; include legacy types)
$inv_stmt = $pdo->prepare("
    SELECT
        inv.name AS item_name,
        inv.cup_size,
        inv.unit,
        -- beginning = qty before the start of the selected date (guard inv.qty)
        (COALESCE(inv.qty,0) - COALESCE((
            SELECT SUM(
                CASE
                    WHEN LOWER(change_type) IN ('added','restock') THEN quantity
                    WHEN LOWER(change_type) IN (
                        'used','use','usage',
                        'consume','consumed','consumption',
                        'deduct','deduction',
                        'auto-deduct','auto deduct','auto_deduct','auto-use','auto use','auto_usage','auto usage',
                        'damage'
                    )
                    OR LOWER(remarks) LIKE '%auto-deduct%'
                    OR LOWER(remarks) LIKE '%auto deduct%'
                    OR LOWER(remarks) LIKE '%auto_deduct%'
                    OR LOWER(remarks) LIKE '%auto-use%'
                    OR LOWER(remarks) LIKE '%auto use%'
                    OR LOWER(remarks) LIKE '%auto_usage%'
                        THEN -quantity
                    WHEN LOWER(change_type) = 'adjustment' THEN
                        CASE WHEN quantity >= 0 THEN quantity ELSE -quantity END
                    ELSE 0
                END
            )
            FROM inventory_logs
            WHERE inventory_id = inv.id
              AND DATE(created_at) >= ?
        ), 0)) AS beginning,
        -- added_today (unchanged)
        COALESCE((
            SELECT SUM(quantity)
            FROM inventory_logs
            WHERE inventory_id = inv.id
              AND DATE(created_at) = ?
              AND (
                    LOWER(change_type) IN ('added','restock')
                 OR (LOWER(change_type) = 'adjustment' AND quantity > 0)
              )
        ), 0) AS added_today,
        -- used_today (unchanged)
        COALESCE((
            SELECT SUM(quantity)
            FROM inventory_logs
            WHERE inventory_id = inv.id
              AND DATE(created_at) = ?
              AND (
                    LOWER(change_type) IN (
                        'used','use','usage',
                        'consume','consumed','consumption',
                        'deduct','deduction',
                        'auto-deduct','auto deduct','auto_deduct','auto-use','auto use','auto_usage','auto usage',
                        'damage'
                    )
                 OR LOWER(remarks) LIKE '%auto-deduct%'
                 OR LOWER(remarks) LIKE '%auto deduct%'
                 OR LOWER(remarks) LIKE '%auto_deduct%'
                 OR LOWER(remarks) LIKE '%auto-use%'
                 OR LOWER(remarks) LIKE '%auto use%'
                 OR LOWER(remarks) LIKE '%auto_usage%'
                 OR (LOWER(change_type) = 'adjustment' AND quantity < 0)
              )
        ), 0) AS used_today,
        -- remaining = beginning + added_today - used_today (guard inv.qty)
        (
            (COALESCE(inv.qty,0) - COALESCE((
                SELECT SUM(
                    CASE
                        WHEN LOWER(change_type) IN ('added','restock') THEN quantity
                        WHEN LOWER(change_type) IN (
                            'used','use','usage',
                            'consume','consumed','consumption',
                            'deduct','deduction',
                            'auto-deduct','auto deduct','auto_deduct','auto-use','auto use','auto_usage','auto usage',
                            'damage'
                        )
                        OR LOWER(remarks) LIKE '%auto-deduct%'
                        OR LOWER(remarks) LIKE '%auto deduct%'
                        OR LOWER(remarks) LIKE '%auto_deduct%'
                        OR LOWER(remarks) LIKE '%auto-use%'
                        OR LOWER(remarks) LIKE '%auto use%'
                        OR LOWER(remarks) LIKE '%auto_usage%'
                            THEN -quantity
                        WHEN LOWER(change_type) = 'adjustment' THEN
                            CASE WHEN quantity >= 0 THEN quantity ELSE -quantity END
                        ELSE 0
                    END
                )
                FROM inventory_logs
                WHERE inventory_id = inv.id
                  AND DATE(created_at) >= ?
            ), 0))
            + COALESCE((SELECT SUM(quantity) FROM inventory_logs WHERE inventory_id = inv.id AND DATE(created_at) = ? AND (LOWER(change_type) IN ('added','restock') OR (LOWER(change_type)='adjustment' AND quantity>0))), 0)
            - COALESCE((SELECT SUM(quantity) FROM inventory_logs WHERE inventory_id = inv.id AND DATE(created_at) = ? AND (LOWER(change_type) IN ('used','use','usage','consume','consumed','consumption','deduct','deduction','auto-deduct','auto deduct','auto_deduct','auto-use','auto use','auto_usage','auto usage','damage') OR LOWER(remarks) LIKE '%auto-deduct%' OR LOWER(remarks) LIKE '%auto deduct%' OR LOWER(remarks) LIKE '%auto_deduct%' OR LOWER(remarks) LIKE '%auto-use%' OR LOWER(remarks) LIKE '%auto use%' OR LOWER(remarks) LIKE '%auto_usage%' OR (LOWER(change_type)='adjustment' AND quantity<0))), 0)
        ) AS remaining
    FROM inventory inv
    ORDER BY inv.name, inv.cup_size
");
$inv_stmt->execute([$date, $date, $date, $date, $date, $date]);
$inventory_items = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch voided orders count for the date (cover void/voided/cancelled by event date)
$void_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM orders
    WHERE status IN ('void','voided','cancelled')
      AND DATE(COALESCE(voided_at, cancelled_at, created_at)) = ?
");
$void_stmt->execute([$date]);
$voided_count = $void_stmt->fetchColumn();

// Formatter for qty
function formatQty($num) {
    return ($num == floor($num)) ? (int)$num : rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}

// Partial JSON for realtime refresh
if (isset($_GET['partial'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    if ($_GET['partial'] === 'inventory') {
        echo json_encode([
            'inventory_items' => array_map(function($r){
                return [
                    'item_name'    => $r['item_name'],
                    'cup_size'     => $r['cup_size'],
                    'unit'         => $r['unit'],
                    'beginning'    => (float)$r['beginning'],
                    'added_today'  => (float)$r['added_today'],
                    'used_today'   => (float)$r['used_today'],
                    'remaining'    => (float)$r['remaining'],
                ];
            }, $inventory_items),
        ]);
        exit;
    }

    // default: return all (summary, sales, inventory)
    echo json_encode([
        'summary' => [
            'total_sales'   => (float)$report['total_sales'],
            'total_orders'  => (int)$report['total_orders'],
            'voided_orders' => (int)$voided_count,
            'sales_tax'     => (float)$report['sales_tax'],
            'generated_by'  => (string)$report['generated_by'],
            'notes'         => (string)$report['notes'],
            'report_date'   => (string)$report['report_date'],
        ],
        'sales_items' => array_map(function($r){
            return [
                'item_name' => $r['item_name'],
                'price'     => (float)$r['price'],
                'qty'       => (int)$r['qty'],
                'subtotal'  => (float)$r['subtotal'],
            ];
        }, $sales_items),
        'inventory_items' => array_map(function($r){
            return [
                'item_name'    => $r['item_name'],
                'cup_size'     => $r['cup_size'],
                'unit'         => $r['unit'],
                'beginning'    => (float)$r['beginning'],
                'added_today'  => (float)$r['added_today'],
                'used_today'   => (float)$r['used_today'],
                'remaining'    => (float)$r['remaining'],
            ];
        }, $inventory_items),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- removed meta refresh; JS polling used for realtime -->
    <title>View Daily Report – <?= htmlspecialchars($date) ?> | Kape Agosto</title>
    <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
    </head>
<body class="kape-reports-module-view-daily-report">
<?php include dirname(__DIR__, 2) . "/includes/sidebar.php"; ?>
<?php include dirname(__DIR__, 2) . "/includes/topbar.php"; ?>

<main class="content report-detail-page">
<div class="report-view">
    <h2>🗓️ Daily Report for <?= date('F d, Y', strtotime($date)) ?></h2>
    <div class="summary">
        <b>Total Sales:</b> ₱<span id="sum-total-sales"><?= number_format($report['total_sales'],2) ?></span><br>
        <b>Total Orders:</b> <span id="sum-total-orders"><?= (int)$report['total_orders'] ?></span><br>
        <b>Voided Orders:</b> <span id="sum-voided-orders"><?= (int)$voided_count ?></span><br>
        <b>Sales Tax (12%):</b> ₱<span id="sum-sales-tax"><?= number_format($report['sales_tax'],2) ?></span><br>
        <b>Generated By:</b> <span id="sum-generated-by"><?= htmlspecialchars($report['generated_by']) ?></span><br>
        <b>Notes:</b> <span id="sum-notes"><?= htmlspecialchars($report['notes']) ?></span><br>
        <b>Report Date:</b> <span id="sum-report-date"><?= date('F d, Y', strtotime($report['report_date'])) ?></span>
    </div>

    <h3>📊 Sales Summary</h3>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Price</th>
                <th>Qty</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody id="sales-tbody">
            <?php foreach ($sales_items as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['item_name']) ?></td>
                <td>₱<?= number_format($row['price'],2) ?></td>
                <td><?= $row['qty'] ?></td>
                <td>₱<?= number_format($row['subtotal'],2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3>📦 Inventory Usage</h3>
    <div class="muted">Beginning = stock as of 12:00 AM on <?= htmlspecialchars(date('F d, Y', strtotime($date))) ?> • <span id="inv-last-updated">Last updated: —</span></div>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Cup Size</th>
                <th>Unit</th>
                <th>Beginning</th>
                <th>Added Today</th>
                <th>Used Today</th>
                <th>Remaining</th>
            </tr>
        </thead>
        <tbody id="inv-tbody">
            <?php foreach ($inventory_items as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['item_name']) ?></td>
                <td><?= htmlspecialchars($row['cup_size'] ?? '-') ?></td>
                <td><?= htmlspecialchars($row['unit']) ?></td>
                <td><?= formatQty((float)$row['beginning']) ?></td>
                <td><?= formatQty((float)$row['added_today']) ?></td>
                <td><?= formatQty((float)$row['used_today']) ?></td>
                <td><?= formatQty((float)$row['remaining']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top:20px;">
        <a href="reports.php" class="btn gray"><i class="fa fa-arrow-left"></i> Back to Reports</a>
        <a href="scripts/export_daily_report_excel.php?date=<?= urlencode($date) ?>" class="btn green" title="<?= $hasXlsx ? 'Styled Excel (.xlsx)' : 'Styled Excel (HTML .xls)' ?>">
            <i class="fa fa-file-excel"></i> <?= $hasXlsx ? 'Export Excel (Styled)' : 'Export Excel (HTML)' ?>
        </a>
        <?php if (!$hasXlsx): ?>
            <div class="muted" style="margin-top:6px">
                Note: Export uses HTML-based Excel (.xls). For native .xlsx, install PhpSpreadsheet:
                <code>composer require phpoffice/phpspreadsheet</code>
            </div>
        <?php endif; ?>
    </div>
</div>
</main>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
(function(){
    const dateStr = '<?= addslashes($date) ?>';
    const salesTbody = document.getElementById('sales-tbody');
    const invTbody = document.getElementById('inv-tbody');

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const fmtMoney = v => Number(v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtQty = v => {
        const n = Number(v);
        if (!isFinite(n)) return '0';
        return Math.floor(n) === n ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
    };
    const fmtDate = s => {
        const d = new Date(s);
        if (isNaN(d)) return s || '';
        return d.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' });
    };

    async function refreshAll(){
        try {
            const res = await fetch(`view_daily_report.php?date=${encodeURIComponent(dateStr)}&partial=all&t=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            if (data.error) return;

            // Summary
            document.getElementById('sum-total-sales').textContent = fmtMoney(data.summary.total_sales);
            document.getElementById('sum-total-orders').textContent = data.summary.total_orders;
            document.getElementById('sum-voided-orders').textContent = data.summary.voided_orders;
            document.getElementById('sum-sales-tax').textContent = fmtMoney(data.summary.sales_tax);
            document.getElementById('sum-generated-by').textContent = data.summary.generated_by || '';
            document.getElementById('sum-notes').textContent = data.summary.notes || '';
            document.getElementById('sum-report-date').textContent = fmtDate(data.summary.report_date);

            // Sales
            salesTbody.innerHTML = (data.sales_items || []).map(r => `
                <tr>
                    <td>${esc(r.item_name)}</td>
                    <td>₱${fmtMoney(r.price)}</td>
                    <td>${r.qty}</td>
                    <td>₱${fmtMoney(r.subtotal)}</td>
                </tr>
            `).join('');

            // Inventory
            invTbody.innerHTML = (data.inventory_items || []).map(r => `
                <tr>
                    <td>${esc(r.item_name)}</td>
                    <td>${esc(r.cup_size ?? '-')}</td>
                    <td>${esc(r.unit)}</td>
                    <td>${fmtQty(r.beginning)}</td>
                    <td>${fmtQty(r.added_today)}</td>
                    <td>${fmtQty(r.used_today)}</td>
                    <td>${fmtQty(r.remaining)}</td>
                </tr>
            `).join('');

            // Last updated
            const ts = new Date();
            const fmt = ts.toLocaleString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
            const el = document.getElementById('inv-last-updated');
            if (el) el.textContent = 'Last updated: ' + fmt;
        } catch(e) { /* ignore */ }
    }

    refreshAll();
    // Faster polling for more realistic live updates
    setInterval(refreshAll, 2000);
})();
</script>
</body>
</html>
