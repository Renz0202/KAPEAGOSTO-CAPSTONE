<?php
// Ensure PHP starts at the first byte to avoid early output; enable buffering so headers can be sent safely.
ob_start();

require_once __DIR__ . "/../../includes/db.php";
require_once __DIR__ . "/../../includes/auth.php";
require_capability('export_reports');

$pdo = db();

$date = isset($_GET['date']) ? $_GET['date'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die('Invalid date.');
}

// Fetch report (include username if available)
$report_stmt = $pdo->prepare("SELECT r.*, COALESCE(u.username, r.generated_by) AS generated_by_display
                              FROM daily_sales_reports r
                              LEFT JOIN users u ON u.id = r.generated_by
                              WHERE r.report_date = ?");
$report_stmt->execute([$date]);
$report = $report_stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) { die('No report found for this date.'); }

// Fetch items
$items_stmt = $pdo->prepare("SELECT * FROM daily_sales_items WHERE report_id = ?");
$items_stmt->execute([$report['id']]);
$sales_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Inventory Usage (unified with view_daily_report.php)
$inv_stmt = $pdo->prepare("
    SELECT
        inv.name AS item_name,
        inv.cup_size,
        inv.unit,
        (COALESCE(inv.qty,0) - COALESCE((
            SELECT SUM(
                CASE
                    WHEN LOWER(change_type) IN ('added','restock') THEN quantity
                    WHEN LOWER(change_type) IN (
                        'used','use','usage',
                        'consume','consumed','consumption',
                        'deduct','deduction',
                        'auto-deduct','auto deduct','auto_deduct','auto-use','auto use','auto_usage','auto usage',
                        'damage','spoilage'
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
                        'damage','spoilage'
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
                            'damage','spoilage'
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
            - COALESCE((SELECT SUM(quantity) FROM inventory_logs WHERE inventory_id = inv.id AND DATE(created_at) = ? AND (LOWER(change_type) IN ('used','use','usage','consume','consumed','consumption','deduct','deduction','auto-deduct','auto deduct','auto_deduct','auto-use','auto use','auto_usage','auto usage','damage','spoilage') OR LOWER(remarks) LIKE '%auto-deduct%' OR LOWER(remarks) LIKE '%auto deduct%' OR LOWER(remarks) LIKE '%auto_deduct%' OR LOWER(remarks) LIKE '%auto-use%' OR LOWER(remarks) LIKE '%auto use%' OR LOWER(remarks) LIKE '%auto_usage%' OR (LOWER(change_type)='adjustment' AND quantity<0))), 0)
        ) AS remaining
    FROM inventory inv
    ORDER BY inv.name, inv.cup_size
");
$inv_stmt->execute([$date, $date, $date, $date, $date, $date]);
$inventory_items = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

// Voided/Cancelled orders count for the date (by event date)
$void_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM orders
    WHERE status IN ('void','voided','cancelled')
      AND DATE(COALESCE(voided_at, cancelled_at, created_at)) = ?
");
$void_stmt->execute([$date]);
$voided_count = (int)$void_stmt->fetchColumn();

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
    $hasXlsx = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
}

// Removed automatic Composer install attempt to avoid runtime package modifications.
// If PhpSpreadsheet is not available, we will fall back to HTML/Excel-compatible output.

if ($hasXlsx) {
    try {
        // XLSX export with design
        $spreadsheetClass = '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet';
        $writerClass = '\\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
        /** @var \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet */
        $spreadsheet = new $spreadsheetClass();
        /** @var \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet */
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daily POS Report');

        // Design tokens
        $brandDark = '1F2937';
        $brandLight = 'F4F6F8';
        $currencyFormat = '"₱"#,##0.00';

        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        // Company Header Block (rows 1-4)
        $storeName = defined('APP_NAME') ? APP_NAME : 'Kape Agosto';
        $companyAddress = '1157 R. Papa St. Corner F. Aguilar St. Barrio Obrero, Tondo, Manila, PH';
        $companyContact = '0915-292-6848 • kape.agosto@gmail.com';

        // Optional logo (use app logo if present)
        $logoPath = realpath(__DIR__ . '/../img/NEWLOGO_BLACK.png');
        $drawingClass = '\\PhpOffice\\PhpSpreadsheet\\Worksheet\\Drawing';
        if ($logoPath && is_file($logoPath) && class_exists($drawingClass)) {
            try {
                /** @var \PhpOffice\PhpSpreadsheet\Worksheet\Drawing $drawing */
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
                $sheet->getStyle('B1')->getAlignment()->setHorizontal('left')->setVertical('center');
                $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(18);
            } catch (\Throwable $eLogo) {
                $sheet->setCellValue('A1', $storeName);
                $sheet->mergeCells('A1:G1');
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
            }
        } else {
            $sheet->setCellValue('A1', $storeName);
            $sheet->mergeCells('A1:G1');
            $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
        }
        $sheet->getRowDimension(1)->setRowHeight(40);

        $sheet->setCellValue('A2', $companyAddress);
        $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(11);

        $sheet->setCellValue('A3', $companyContact);
        $sheet->mergeCells('A3:G3');
        $sheet->getStyle('A3')->getAlignment()->setHorizontal('center');
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(11);

        // Title Row (row 4)
        $sheet->setCellValue('A4', 'Daily Sales Report – ' . $report['report_date']);
        $sheet->mergeCells('A4:G4');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brandDark);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension(4)->setRowHeight(24);

        // Meta blocks similar to main daily export
        $salesTotal = (float)$report['total_sales'] + (float)$report['sales_tax'];
        $person = (string)($report['generated_by_display'] ?? $report['generated_by'] ?? '');
        $sheet->setCellValue('A5', 'Sales Person');
        $sheet->setCellValue('B5', $person); $sheet->mergeCells('B5:D5');
        $sheet->setCellValue('A6', 'Date');
        $sheet->setCellValue('B6', $report['report_date']); $sheet->mergeCells('B6:D6');
        $sheet->getStyle('A5:A6')->getFont()->setBold(true);
        $sheet->setCellValue('F5', 'Sales Amount'); $sheet->setCellValue('G5', (float)$report['total_sales']);
        $sheet->setCellValue('F6', 'Sales Tax (12%)'); $sheet->setCellValue('G6', (float)$report['sales_tax']);
        $sheet->setCellValue('F7', 'Sales Total'); $sheet->setCellValue('G7', $salesTotal);
        $sheet->getStyle('F5:F7')->getFont()->setBold(true);
        $sheet->getStyle('G5:G7')->getNumberFormat()->setFormatCode($currencyFormat);

        // Summary start row after header/meta block
        $startRow = 9;
        $sheet->setCellValue("A{$startRow}", 'Summary');
        $sheet->mergeCells("A{$startRow}:D{$startRow}");
        $sheet->getStyle("A{$startRow}:D{$startRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brandLight);
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);

        $summary = [
            ['Total Sales', (float)$report['total_sales']],
            ['Total Orders', (int)$report['total_orders']],
            ['Voided Orders', (int)$voided_count],
            ['Sales Tax (12%)', (float)$report['sales_tax']],
            ['Generated By', (string)($report['generated_by_display'] ?? $report['generated_by'])],
            ['Notes', (string)$report['notes']],
        ];
        $r = $startRow + 1;
        foreach ($summary as $s) {
            $sheet->setCellValue("A{$r}", $s[0]);
            $sheet->setCellValue("B{$r}", $s[1]);
            $r++;
        }
        $sheet->getStyle("B" . ($startRow + 1))->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("B" . ($startRow + 4))->getNumberFormat()->setFormatCode($currencyFormat);

        // Sales table
        $r += 1;
        $sheet->setCellValue("A{$r}", 'Sales Summary');
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->getStyle("A{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brandLight);
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;

        foreach (['Item','Price','Qty','Subtotal'] as $i => $h) { $sheet->setCellValue(chr(65+$i) . $r, $h); }
        $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true)->getColor()->setARGB('FF000000');
        $sheet->getStyle("A{$r}:D{$r}")->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE5E7EB');
        $r++;

        $itemsStart = $r;
        foreach ($sales_items as $row) {
            $sheet->setCellValue("A{$r}", $row['item_name']);
            $sheet->setCellValue("B{$r}", (float)$row['price']);
            $sheet->setCellValue("C{$r}", (int)$row['qty']);
            $sheet->setCellValue("D{$r}", (float)$row['subtotal']);
            $r++;
        }
        $itemsEnd = max($itemsStart, $r - 1);
        $sheet->getStyle("B{$itemsStart}:B{$itemsEnd}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("D{$itemsStart}:D{$itemsEnd}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("A" . ($itemsStart - 1) . ":D{$itemsEnd}")
            ->getBorders()->getAllBorders()->setBorderStyle('thin');

        // Zebra striping for Sales rows
        $zebra = 'F9FAFB';
        for ($zr = $itemsStart; $zr <= $itemsEnd; $zr++) {
            if ((($zr - $itemsStart) % 2) === 1) {
                $sheet->getStyle("A{$zr}:D{$zr}")->getFill()->setFillType('solid')->getStartColor()->setRGB($zebra);
            }
        }

        // Inventory Usage
        $r += 1;
        $sheet->setCellValue("A{$r}", 'Inventory Usage');
        $sheet->mergeCells("A{$r}:G{$r}");
        $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brandLight);
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;

        foreach (['Item','Cup Size','Unit','Beginning','Added Today','Used Today','Remaining'] as $i => $h) { $sheet->setCellValue(chr(65+$i) . $r, $h); }
        $sheet->getStyle("A{$r}:G{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE5E7EB');
        $r++;

        $invStart = $r;
        foreach ($inventory_items as $row) {
            $sheet->setCellValue("A{$r}", $row['item_name']);
            $sheet->setCellValue("B{$r}", ($row['cup_size'] !== null && $row['cup_size'] !== '') ? $row['cup_size'] : '-');
            $sheet->setCellValue("C{$r}", $row['unit'] ?? '');
            $sheet->setCellValue("D{$r}", (float)$row['beginning']);
            $sheet->setCellValue("E{$r}", (float)$row['added_today']);
            $sheet->setCellValue("F{$r}", (float)$row['used_today']);
            $sheet->setCellValue("G{$r}", (float)$row['remaining']);
            $r++;
        }
        $invEnd = max($invStart, $r - 1);
        $sheet->getStyle("A" . ($invStart - 1) . ":G{$invEnd}")
            ->getBorders()->getAllBorders()->setBorderStyle('thin');

        // Zebra striping for Inventory rows
        for ($zr = $invStart; $zr <= $invEnd; $zr++) {
            if ((($zr - $invStart) % 2) === 1) {
                $sheet->getStyle("A{$zr}:G{$zr}")->getFill()->setFillType('solid')->getStartColor()->setRGB($zebra);
            }
        }

    // Footer, autosize, freeze
    $r += 1;
    $sheet->setCellValue("A{$r}", 'Generated on: ' . date('Y-m-d H:i:s'));
    $sheet->mergeCells("A{$r}:G{$r}");
    $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->getColor()->setARGB('FF6B7280');

        foreach (range('A', 'G') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
    // Freeze top section + headers (row below summary block heading)
    $sheet->freezePane('A' . ($itemsStart));

        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
        $filename = 'daily_report_' . $date . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        /** @var \PhpOffice\PhpSpreadsheet\Writer\Xlsx $writer */
        $writer = new $writerClass($spreadsheet);
        $writer->save('php://output');
    } catch (\Throwable $e) {
        error_log('Unified daily XLSX export error: ' . $e->getMessage());
        // Styled HTML (Excel-compatible) fallback on failure
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
        $filename = 'daily_report_' . $date . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<style>
            body{font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#111}
            .header{text-align:center;margin-bottom:8px}
            .logo{width:72px;height:auto;margin:0 auto 6px;display:block}
            .store-name{font-weight:700;font-size:18pt}
            .store-meta{font-size:11pt;color:#374151}
            .title{background:#1F2937;color:#fff;font-weight:700;font-size:14pt;text-align:center;padding:8px;border-radius:6px;margin-top:6px}
            .section{margin:10px 0;font-weight:700;background:#F4F6F8;padding:6px;border:1px solid #ddd}
            table{border-collapse:collapse;width:100%;margin-top:6px}
            th,td{border:1px solid #ccc;padding:6px}
            th{background:#E5E7EB;text-align:left}
            .num{mso-number-format:"0";text-align:right}
            .money{mso-number-format:"\0022₱\0022#,##0.00";text-align:right}
        </style></head><body>';
        $storeName = defined('APP_NAME') ? APP_NAME : 'Kape Agosto';
        $companyAddress = '1157 R. Papa St. Corner F. Aguilar St. Barrio Obrero, Tondo, Manila, PH';
        $companyContact = '0915-292-6848 • kape.agosto@gmail.com';
        echo '<div class="header">'
            .'<img class="logo" src="img/NEWLOGO_BLACK.png" alt="Kape Agosto Logo" onerror="this.style.display=\'none\'">'
            .'<div class="store-name">'.htmlspecialchars($storeName).'</div>'
            .'<div class="store-meta">'.htmlspecialchars($companyAddress).'<br>'.htmlspecialchars($companyContact).'</div>'
            .'</div>';
        echo '<div class="title">Daily Sales Report – '.htmlspecialchars($report['report_date']).'</div>';

        // Summary
        echo '<table><thead><tr><th colspan="2">Summary</th></tr></thead><tbody>';
        echo '<tr><td>Total Sales</td><td class="money">'.(0+($report['total_sales'])).'</td></tr>';
        echo '<tr><td>Total Orders</td><td class="num">'.(int)$report['total_orders'].'</td></tr>';
        echo '<tr><td>Voided Orders</td><td class="num">'.(int)$voided_count.'</td></tr>';
        echo '<tr><td>Sales Tax (12%)</td><td class="money">'.(0+($report['sales_tax'])).'</td></tr>';
        echo '<tr><td>Generated By</td><td>'.htmlspecialchars((string)($report['generated_by_display'] ?? $report['generated_by'])).'</td></tr>';
        echo '<tr><td>Notes</td><td>'.htmlspecialchars((string)$report['notes']).'</td></tr>';
        echo '</tbody></table>';

        // Sales Summary
        echo '<div class="section">Sales Summary</div>';
        echo '<table><thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr></thead><tbody>';
        foreach ($sales_items as $row) {
            echo '<tr>'
                .'<td>'.htmlspecialchars($row['item_name']).'</td>'
                .'<td class="money">'.(0+($row['price'])).'</td>'
                .'<td class="num">'.(int)$row['qty'].'</td>'
                .'<td class="money">'.(0+($row['subtotal'])).'</td>'
                .'</tr>';
        }
        echo '</tbody></table>';

        // Inventory Usage
        echo '<div class="section">Inventory Usage</div>';
        echo '<table><thead><tr><th>Item</th><th>Cup Size</th><th>Unit</th><th>Beginning</th><th>Added Today</th><th>Used Today</th><th>Remaining</th></tr></thead><tbody>';
        foreach ($inventory_items as $row) {
            echo '<tr>'
                .'<td>'.htmlspecialchars($row['item_name']).'</td>'
                .'<td>'.htmlspecialchars(($row['cup_size'] !== null && $row['cup_size'] !== '') ? $row['cup_size'] : '-').'</td>'
                .'<td>'.htmlspecialchars($row['unit'] ?? '').'</td>'
                .'<td class="num">'.(0+($row['beginning'])).'</td>'
                .'<td class="num">'.(0+($row['added_today'])).'</td>'
                .'<td class="num">'.(0+($row['used_today'])).'</td>'
                .'<td class="num">'.(0+($row['remaining'])).'</td>'
                .'</tr>';
        }
        echo '</tbody></table>';

        echo '<div style="margin-top:10px;color:#6B7280;font-style:italic">Generated on: '.date('Y-m-d H:i:s').'</div>';
        echo '</body></html>';
    }
} else {
    // Styled HTML (Excel-compatible) fallback (no PhpSpreadsheet)
    if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
    $filename = 'daily_report_' . $date . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<style>
        body{font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#111}
        .header{text-align:center;margin-bottom:8px}
        .logo{width:72px;height:auto;margin:0 auto 6px;display:block}
        .store-name{font-weight:700;font-size:18pt}
        .store-meta{font-size:11pt;color:#374151}
        .title{background:#1F2937;color:#fff;font-weight:700;font-size:14pt;text-align:center;padding:8px;border-radius:6px;margin-top:6px}
        .section{margin:10px 0;font-weight:700;background:#F4F6F8;padding:6px;border:1px solid #ddd}
        table{border-collapse:collapse;width:100%;margin-top:6px}
        th,td{border:1px solid #ccc;padding:6px}
        th{background:#E5E7EB;text-align:left}
        .num{mso-number-format:"0";text-align:right}
        .money{mso-number-format:"\0022₱\0022#,##0.00";text-align:right}
    </style></head><body>';
    $storeName = defined('APP_NAME') ? APP_NAME : 'Kape Agosto';
    $companyAddress = '1157 R. Papa St. Corner F. Aguilar St. Barrio Obrero, Tondo, Manila, PH';
    $companyContact = '0915-292-6848 • kape.agosto@gmail.com';
    echo '<div class="header">'
        .'<img class="logo" src="img/NEWLOGO_BLACK.png" alt="Kape Agosto Logo" onerror="this.style.display=\'none\'">'
        .'<div class="store-name">'.htmlspecialchars($storeName).'</div>'
        .'<div class="store-meta">'.htmlspecialchars($companyAddress).'<br>'.htmlspecialchars($companyContact).'</div>'
        .'</div>';
    echo '<div class="title">Daily Sales Report – '.htmlspecialchars($report['report_date']).'</div>';

    // Summary
    echo '<table><thead><tr><th colspan="2">Summary</th></tr></thead><tbody>';
    echo '<tr><td>Total Sales</td><td class="money">'.(0+($report['total_sales'])).'</td></tr>';
    echo '<tr><td>Total Orders</td><td class="num">'.(int)$report['total_orders'].'</td></tr>';
    echo '<tr><td>Voided Orders</td><td class="num">'.(int)$voided_count.'</td></tr>';
    echo '<tr><td>Sales Tax (12%)</td><td class="money">'.(0+($report['sales_tax'])).'</td></tr>';
    echo '<tr><td>Generated By</td><td>'.htmlspecialchars((string)($report['generated_by_display'] ?? $report['generated_by'])).'</td></tr>';
    echo '<tr><td>Notes</td><td>'.htmlspecialchars((string)$report['notes']).'</td></tr>';
    echo '</tbody></table>';

    // Sales Summary
    echo '<div class="section">Sales Summary</div>';
    echo '<table><thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr></thead><tbody>';
    foreach ($sales_items as $row) {
        echo '<tr>'
            .'<td>'.htmlspecialchars($row['item_name']).'</td>'
            .'<td class="money">'.(0+($row['price'])).'</td>'
            .'<td class="num">'.(int)$row['qty'].'</td>'
            .'<td class="money">'.(0+($row['subtotal'])).'</td>'
            .'</tr>';
    }
    echo '</tbody></table>';

    // Inventory Usage
    echo '<div class="section">Inventory Usage</div>';
    echo '<table><thead><tr><th>Item</th><th>Cup Size</th><th>Unit</th><th>Beginning</th><th>Added Today</th><th>Used Today</th><th>Remaining</th></tr></thead><tbody>';
    foreach ($inventory_items as $row) {
        echo '<tr>'
            .'<td>'.htmlspecialchars($row['item_name']).'</td>'
            .'<td>'.htmlspecialchars(($row['cup_size'] !== null && $row['cup_size'] !== '') ? $row['cup_size'] : '-').'</td>'
            .'<td>'.htmlspecialchars($row['unit'] ?? '').'</td>'
            .'<td class="num">'.(0+($row['beginning'])).'</td>'
            .'<td class="num">'.(0+($row['added_today'])).'</td>'
            .'<td class="num">'.(0+($row['used_today'])).'</td>'
            .'<td class="num">'.(0+($row['remaining'])).'</td>'
            .'</tr>';
    }
    echo '</tbody></table>';

    echo '<div style="margin-top:10px;color:#6B7280;font-style:italic">Generated on: '.date('Y-m-d H:i:s').'</div>';
    echo '</body></html>';
}
exit;
?>
