<?php
// Ensure PHP starts at the first byte to avoid early output; enable buffering so headers can be sent safely.
ob_start();

require_once __DIR__ . "/../../includes/db.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . '/../../includes/order_discounts.php';
require_once __DIR__ . '/../../includes/settings.php';
require_capability('export_reports');

$pdo = db();
$reportTaxType = settings_value(settings_all($pdo), 'bir_tax_type');

// Helpers
function is_valid_date($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }

$from = isset($_GET['from']) ? $_GET['from'] : '';
$to   = isset($_GET['to']) ? $_GET['to'] : '';

// Fallback to available range when missing
if (!$from || !$to || !is_valid_date($from) || !is_valid_date($to)) {
    $rangeStmt = $pdo->query("SELECT MIN(report_date) AS min_d, MAX(report_date) AS max_d FROM daily_sales_reports");
    $range = $rangeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$from || !is_valid_date($from)) $from = $range['min_d'] ?: date('Y-m-d');
    if (!$to || !is_valid_date($to)) $to = $range['max_d'] ?: $from;
}

// Normalize and validate
if (!is_valid_date($from) || !is_valid_date($to)) {
    http_response_code(400);
    die('Invalid date format. Expected YYYY-MM-DD.');
}
if ($from > $to) {
    http_response_code(400);
    die('Invalid range: FROM date must be earlier than or equal to TO date.');
}

// Optional autogen
if (isset($_GET['autogen']) && $_GET['autogen'] === '1') {
    $user = current_user();
    $cur = strtotime($from);
    $end = strtotime($to);
    while ($cur <= $end) {
        $d = date('Y-m-d', $cur);
        $existsStmt = $pdo->prepare("SELECT id FROM daily_sales_reports WHERE report_date = ?");
        $existsStmt->execute([$d]);
        $rid = $existsStmt->fetchColumn();
        if (!$rid) {
            // Compute items and totals for this date
            $itemsStmt = $pdo->prepare("SELECT 
                    oi.item_id,
                    i.name AS item_name,
                    i.price,
                    SUM(oi.qty) AS total_qty,
                    SUM(oi.subtotal * CASE WHEN o.subtotal > 0 THEN o.total / o.subtotal ELSE 1 END) AS total_amount
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN items i ON oi.item_id = i.id
                WHERE DATE(o.created_at) = ? AND o.status = 'completed'
                GROUP BY oi.item_id");
            $itemsStmt->execute([$d]);
            $dayItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Only create a report when there are completed sales for the day
            if ($dayItems && count($dayItems) > 0) {
                $reportTotals = order_discount_report_totals($pdo, $d . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($d . ' +1 day')), $reportTaxType);
                $total_orders = $reportTotals['total_orders'];
                $total_sales = $reportTotals['total_sales'];
                $sales_tax = $reportTotals['sales_tax'];

                // Insert report
                $insRep = $pdo->prepare("INSERT INTO daily_sales_reports (report_date, generated_by, total_sales, total_orders, sales_tax, notes)
                                         VALUES (?,?,?,?,?,?)");
                $insRep->execute([$d, $user['id'] ?? null, $total_sales, $total_orders, $sales_tax, 'Auto-generated via export']);
                $rid = $pdo->lastInsertId();

                // Insert items
                $insItem = $pdo->prepare("INSERT INTO daily_sales_items (report_id, item_id, item_name, price, qty, subtotal)
                                          VALUES (?,?,?,?,?,?)");
                foreach ($dayItems as $it) {
                    $insItem->execute([
                        $rid,
                        $it['item_id'],
                        $it['item_name'],
                        $it['price'],
                        $it['total_qty'],
                        $it['total_amount'],
                    ]);
                }
            }
        }
        $cur = strtotime('+1 day', $cur);
    }
}

// Preload voided/cancelled counts per date in range (by event date)
$voidMap = [];
$voidStmt = $pdo->prepare("SELECT DATE(COALESCE(voided_at, cancelled_at, created_at)) AS d, COUNT(*) AS c
                                                     FROM orders
                                                     WHERE (status IN ('void','voided','cancelled'))
                                                         AND DATE(COALESCE(voided_at, cancelled_at, created_at)) BETWEEN ? AND ?
                                                     GROUP BY DATE(COALESCE(voided_at, cancelled_at, created_at))");
$voidStmt->execute([$from, $to]);
foreach ($voidStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $voidMap[$row['d']] = (int)$row['c'];
}

// Fetch daily report rows in range
$sql = "SELECT r.id, r.report_date, r.total_orders, r.total_sales, r.sales_tax, r.notes,
               COALESCE(u.username, r.generated_by) AS generated_by
        FROM daily_sales_reports r
        LEFT JOIN users u ON u.id = r.generated_by
        WHERE r.report_date BETWEEN ? AND ?
        ORDER BY r.report_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
	http_response_code(404);
	die('No reports found for the selected range.');
}

// Locate Composer autoload robustly
$autoload = null;
$scanDir = realpath(__DIR__);
for ($i = 0; $i < 5 && $scanDir; $i++) {
	$cand = $scanDir . '/vendor/autoload.php';
	if (is_file($cand)) { $autoload = $cand; break; }
	$scanDir = dirname($scanDir);
}
$hasXlsx = false;
if ($autoload) {
    require_once $autoload;
    // Use string class name to avoid static analysis errors when package is missing
    $hasXlsx = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
}

if ($hasXlsx) {
    try {
        // XLSX export with design
        $spreadsheetClass = '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet';
        $writerClass = '\\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
        $spreadsheet = new $spreadsheetClass();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('POS Reports Summary');

        $brandDark = '1F2937';
        $brandLight = 'F4F6F8';
        $currencyFormat = '"₱"#,##0.00';
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        // Company Header Block (rows 1-4) - Unified Professional Branding
        $storeName = defined('APP_NAME') ? APP_NAME : 'Kape Agosto';
        $companyAddress = '1157 R. Papa St. Corner F. Aguilar St. Barrio Obrero, Tondo, Manila, PH';
        $companyContact = '0915-292-6848 • kape.agosto@gmail.com';
        $logoPath = realpath(__DIR__ . '/../img/NEWLOGO_BLACK.png');
        $drawingClass = '\\PhpOffice\\PhpSpreadsheet\\Worksheet\\Drawing';
        if ($logoPath && is_file($logoPath) && class_exists($drawingClass)) {
            try {
                $drawing = new $drawingClass();
                $drawing->setName('Logo');
                $drawing->setDescription('Company Logo');
                $drawing->setPath($logoPath);
                $drawing->setHeight(60);
                $drawing->setCoordinates('A1');
                $drawing->setOffsetX(5);
                $drawing->setOffsetY(5);
                $drawing->setWorksheet($sheet);
                $sheet->setCellValue('B1', $storeName);
                $sheet->mergeCells('B1:G1');
                $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(18);
                $sheet->getStyle('B1')->getAlignment()->setHorizontal('left')->setVertical('center');
            } catch (\Throwable $eLogo) {
                $sheet->setCellValue('A1', $storeName);
                $sheet->mergeCells('A1:G1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
            }
        } else {
            $sheet->setCellValue('A1', $storeName);
            $sheet->mergeCells('A1:G1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
        }
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->setCellValue('A2', $companyAddress); $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');
        $sheet->setCellValue('A3', $companyContact); $sheet->mergeCells('A3:G3');
        $sheet->getStyle('A3')->getAlignment()->setHorizontal('center');

    // Title row (4) - Standardized wording
    $sheet->setCellValue('A4', 'Sales Reports Summary – ' . $from . ' to ' . $to); // en dash for professionalism
        $sheet->mergeCells('A4:G4');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A4:G4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($brandDark);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Range meta (From/To) now on row 5-6
        $sheet->setCellValue('A5', 'From:'); $sheet->setCellValue('B5', $from);
        $sheet->setCellValue('A6', 'To:');   $sheet->setCellValue('B6', $to);
        $sheet->getStyle('A5:A6')->getFont()->setBold(true);

        $start = 8; // header row for table after meta
        $headers = ['Date', 'Total Orders (completed)', 'Total Sales', 'Sales Tax (12%)', 'Voided/Cancelled Orders', 'Generated By', 'Notes'];
        $cols = ['A','B','C','D','E','F','G'];
        foreach ($headers as $i => $h) { $sheet->setCellValue($cols[$i] . $start, $h); }
        $sheet->getStyle("A{$start}:G{$start}")->getFont()->setBold(true);
        $sheet->getStyle("A{$start}:G{$start}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($brandLight);

        $r = $start + 1;
        foreach ($rows as $row) {
            $date = $row['report_date'];
            $voided = isset($voidMap[$date]) ? $voidMap[$date] : 0;

            $sheet->setCellValue("A{$r}", $date);
            $sheet->setCellValue("B{$r}", (int)$row['total_orders']);
            $sheet->setCellValue("C{$r}", (float)$row['total_sales']);
            $sheet->setCellValue("D{$r}", (float)$row['sales_tax']);
            $sheet->setCellValue("E{$r}", (int)$voided);
            $sheet->setCellValue("F{$r}", $row['generated_by']);
            $sheet->setCellValue("G{$r}", $row['notes']);
            $r++;
        }
        $dataStart = $start + 1;
        $dataEnd = max($dataStart, $r - 1);

        $sheet->getStyle("C{$dataStart}:C{$dataEnd}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("D{$dataStart}:D{$dataEnd}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("A{$start}:G{$dataEnd}")
            ->getBorders()->getAllBorders()->setBorderStyle('thin');

        // Zebra striping for data rows
        $zebra = 'F9FAFB';
        for ($zr = $dataStart; $zr <= $dataEnd; $zr++) {
            if ((($zr - $dataStart) % 2) === 1) {
                $sheet->getStyle("A{$zr}:G{$zr}")->getFill()->setFillType('solid')->getStartColor()->setRGB($zebra);
            }
        }

        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("B{$r}", array_sum(array_map(function($rr){ return (int)$rr['total_orders']; }, $rows)));
        $sheet->setCellValue("C{$r}", array_sum(array_map(function($rr){ return (float)$rr['total_sales']; }, $rows)));
        $sheet->setCellValue("D{$r}", array_sum(array_map(function($rr){ return (float)$rr['sales_tax']; }, $rows)));
        $total_voided = 0;
        foreach ($rows as $rr) { $d = $rr['report_date']; $total_voided += isset($voidMap[$d]) ? $voidMap[$d] : 0; }
        $sheet->setCellValue("E{$r}", $total_voided);

        $sheet->getStyle("A{$r}:G{$r}")->getFont()->setBold(true);
        $sheet->getStyle("C{$r}:D{$r}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("A{$r}:G{$r}")
              ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD1FAE5');

        $sheet->setAutoFilter("A{$start}:G{$dataEnd}");
    $sheet->freezePane("A" . ($start + 1));
        foreach ($cols as $c) { $sheet->getColumnDimension($c)->setAutoSize(true); }

        $r += 2;
        $sheet->setCellValue("A{$r}", 'Generated on: ' . date('Y-m-d H:i:s'));
        $sheet->mergeCells("A{$r}:G{$r}");
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->getColor()->setARGB('FF6B7280');

        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
        $filename = 'daily_reports_summary_' . $from . '_to_' . $to . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = new $writerClass($spreadsheet);
        $writer->save('php://output');
    } catch (\Throwable $e) {
        error_log('Range XLSX export error: ' . $e->getMessage());
        // Styled HTML (Excel-compatible) fallback on failure
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
        $filename = 'daily_reports_summary_' . $from . '_to_' . $to . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $total_orders = array_sum(array_map(function($r){ return (int)$r['total_orders']; }, $rows));
        $total_sales  = array_sum(array_map(function($r){ return (float)$r['total_sales']; }, $rows));
        $total_tax    = array_sum(array_map(function($r){ return (float)$r['sales_tax']; }, $rows));
        $total_voided = 0; foreach ($rows as $r) { $d = $r['report_date']; $total_voided += isset($voidMap[$d]) ? $voidMap[$d] : 0; }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<style>
            body{font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#111}
            .header{text-align:center;margin-bottom:8px}
            .logo{width:72px;height:auto;margin:0 auto 6px;display:block}
            .store-name{font-weight:700;font-size:18pt}
            .store-meta{font-size:11pt;color:#374151}
            .title{background:#1F2937;color:#fff;font-weight:700;font-size:14pt;text-align:center;padding:8px;border-radius:6px;margin-top:4px}
            .meta{margin:10px 0;font-size:11pt}
            table{border-collapse:collapse;width:100%;margin-top:6px}
            th,td{border:1px solid #ccc;padding:6px}
            th{background:#F4F6F8;text-align:left}
            tbody tr:nth-child(odd){background:#F9FAFB}
            .num{text-align:right}
            .money{mso-number-format:"\0022₱\0022#,##0.00";text-align:right}
            .totals th,.totals td{font-weight:700;background:#D1FAE5}
        </style></head><body>';
        $storeName = defined('APP_NAME') ? APP_NAME : 'Kape Agosto';
        $companyAddress = '1157 R. Papa St. Corner F. Aguilar St. Barrio Obrero, Tondo, Manila, PH';
        $companyContact = '0915-292-6848 • kape.agosto@gmail.com';
        echo '<div class="header">'
            .'<img class="logo" src="img/NEWLOGO_BLACK.png" alt="Kape Agosto Logo" onerror="this.style.display=\'none\'">'
            .'<div class="store-name">'.htmlspecialchars($storeName).'</div>'
            .'<div class="store-meta">'.htmlspecialchars($companyAddress).'<br>'.htmlspecialchars($companyContact).'</div>'
            .'</div>';
        echo '<div class="title">Sales Reports Summary – '.htmlspecialchars($from).' to '.htmlspecialchars($to).'</div>';
        echo '<div class="meta"><b>From:</b> ' . htmlspecialchars($from) . ' &nbsp; <b>To:</b> ' . htmlspecialchars($to) . '</div>';
        echo '<table><thead><tr>';
        $heads = ['Date','Total Orders (completed)','Total Sales','Sales Tax (12%)','Voided/Cancelled Orders','Generated By','Notes'];
        foreach($heads as $h){ echo '<th>'.htmlspecialchars($h).'</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $date = $r['report_date'];
            $voided = isset($voidMap[$date]) ? $voidMap[$date] : 0;
            echo '<tr>';
            echo '<td>'.htmlspecialchars($date).'</td>';
            echo '<td class="num">'.(int)$r['total_orders'].'</td>';
            echo '<td class="money">'.(0+($r['total_sales'])).'</td>';
            echo '<td class="money">'.(0+($r['sales_tax'])).'</td>';
            echo '<td class="num">'.(int)$voided.'</td>';
            echo '<td>'.htmlspecialchars((string)$r['generated_by']).'</td>';
            echo '<td>'.htmlspecialchars((string)$r['notes']).'</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '<tfoot><tr class="totals">'
            .'<td>TOTAL</td>'
            .'<td class="num">'.$total_orders.'</td>'
            .'<td class="money">'.$total_sales.'</td>'
            .'<td class="money">'.$total_tax.'</td>'
            .'<td class="num">'.$total_voided.'</td>'
            .'<td></td><td></td>'
            .'</tr></tfoot>';
        echo '</table>';
        echo '<div style="margin-top:10px;color:#6B7280;font-style:italic">Generated on: '.date('Y-m-d H:i:s').'</div>';
        echo '</body></html>';
    }
} else {
    // Styled HTML (Excel-compatible) fallback (no PhpSpreadsheet)
    if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
    $filename = 'daily_reports_summary_' . $from . '_to_' . $to . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $total_orders = array_sum(array_map(function($r){ return (int)$r['total_orders']; }, $rows));
    $total_sales  = array_sum(array_map(function($r){ return (float)$r['total_sales']; }, $rows));
    $total_tax    = array_sum(array_map(function($r){ return (float)$r['sales_tax']; }, $rows));
    $total_voided = 0; foreach ($rows as $r) { $d = $r['report_date']; $total_voided += isset($voidMap[$d]) ? $voidMap[$d] : 0; }

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<style>
        body{font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#111}
        .header{text-align:center;margin-bottom:8px}
        .logo{width:72px;height:auto;margin:0 auto 6px;display:block}
        .store-name{font-weight:700;font-size:18pt}
        .store-meta{font-size:11pt;color:#374151}
        .title{background:#1F2937;color:#fff;font-weight:700;font-size:14pt;text-align:center;padding:8px;border-radius:6px;margin-top:4px}
        .meta{margin:10px 0;font-size:11pt}
        table{border-collapse:collapse;width:100%;margin-top:6px}
        th,td{border:1px solid #ccc;padding:6px}
        th{background:#F4F6F8;text-align:left}
        tbody tr:nth-child(odd){background:#F9FAFB}
        .num{text-align:right}
        .money{mso-number-format:"\0022₱\0022#,##0.00";text-align:right}
        .totals th,.totals td{font-weight:700;background:#D1FAE5}
    </style></head><body>';
    $storeName = defined('APP_NAME') ? APP_NAME : 'Kape Agosto';
    $companyAddress = '1157 R. Papa St. Corner F. Aguilar St. Barrio Obrero, Tondo, Manila, PH';
    $companyContact = '0915-292-6848 • kape.agosto@gmail.com';
    echo '<div class="header">'
        .'<img class="logo" src="img/NEWLOGO_BLACK.png" alt="Kape Agosto Logo" onerror="this.style.display=\'none\'">'
        .'<div class="store-name">'.htmlspecialchars($storeName).'</div>'
        .'<div class="store-meta">'.htmlspecialchars($companyAddress).'<br>'.htmlspecialchars($companyContact).'</div>'
        .'</div>';
    echo '<div class="title">Sales Reports Summary – '.htmlspecialchars($from).' to '.htmlspecialchars($to).'</div>';
    echo '<div class="meta"><b>From:</b> ' . htmlspecialchars($from) . ' &nbsp; <b>To:</b> ' . htmlspecialchars($to) . '</div>';
    echo '<table><thead><tr>';
    $heads = ['Date','Total Orders (completed)','Total Sales','Sales Tax (12%)','Voided/Cancelled Orders','Generated By','Notes'];
    foreach($heads as $h){ echo '<th>'.htmlspecialchars($h).'</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $date = $r['report_date'];
        $voided = isset($voidMap[$date]) ? $voidMap[$date] : 0;
        echo '<tr>';
        echo '<td>'.htmlspecialchars($date).'</td>';
        echo '<td class="num">'.(int)$r['total_orders'].'</td>';
        echo '<td class="money">'.(0+($r['total_sales'])).'</td>';
        echo '<td class="money">'.(0+($r['sales_tax'])).'</td>';
        echo '<td class="num">'.(int)$voided.'</td>';
        echo '<td>'.htmlspecialchars((string)$r['generated_by']).'</td>';
        echo '<td>'.htmlspecialchars((string)$r['notes']).'</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '<tfoot><tr class="totals">'
        .'<td>TOTAL</td>'
        .'<td class="num">'.$total_orders.'</td>'
        .'<td class="money">'.$total_sales.'</td>'
        .'<td class="money">'.$total_tax.'</td>'
        .'<td class="num">'.$total_voided.'</td>'
        .'<td></td><td></td>'
        .'</tr></tfoot>';
    echo '</table>';
    echo '<div style="margin-top:10px;color:#6B7280;font-style:italic">Generated on: '.date('Y-m-d H:i:s').'</div>';
    echo '</body></html>';
}
exit;
?>
