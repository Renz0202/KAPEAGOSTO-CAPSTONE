<?php
// CLI-only, read-only preview. This command intentionally has no apply option.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_reconciliation.php';
$writeReport = false;
foreach (array_slice($argv, 1) as $flag) {
    if ($flag === '--write-report') { $writeReport = true; }
    else { fwrite(STDERR, "Usage: php scripts/preview_ingredient_usage.php [--write-report]\n"); exit(1); }
}
$pdo = db();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
try {
    $preview = inventory_reconciliation_preview($pdo);
    $pdo->rollBack();
    echo json_encode($preview['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    foreach ($preview['balances'] as $row) {
        echo $row['ingredient'], ': ', inventory_reconciliation_quantity($row['missing_usage_units']), ' ', $row['unit'],
            ' missing; ', inventory_reconciliation_quantity($row['proposed_stock_units']), ' ', $row['unit'], ' proposed balance',
            $row['shortage_units'] > 0 ? ' [SHORTAGE]' : '', PHP_EOL;
    }
    if ($writeReport) {
        $base = dirname(__DIR__) . '/docs/ingredient_usage_preview_' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Singapore')))->format('Ymd_His');
        foreach ([
            '.json' => json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            '.md' => inventory_reconciliation_report($preview),
        ] as $extension => $content) {
            $path = $base . $extension;
            $handle = fopen($path, 'x');
            if ($handle === false) { throw new RuntimeException('Cannot create preview report.'); }
            try {
                if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                    throw new RuntimeException('Preview report is incomplete.');
                }
            } finally { fclose($handle); }
            echo 'Report: ', $path, PHP_EOL;
        }
    }
    echo "PREVIEW ONLY: no inventory, order, payment, or movement records changed.\n";
    if ($preview['issues']) { echo count($preview['issues']), " unresolved record issues require review.\n"; }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
