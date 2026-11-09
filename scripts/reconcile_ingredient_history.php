<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_reconciliation_history.php';
$options = array_slice($argv, 1);
$applyPath = null;
$writeReport = false;
foreach ($options as $option) {
    if ($option === '--write-report') $writeReport = true;
    elseif (strpos($option, '--apply-plan=') === 0) $applyPath = substr($option, 13);
    else { fwrite(STDERR, "Usage: php scripts/reconcile_ingredient_history.php [--write-report | --apply-plan=path.json]\n"); exit(1); }
}
if ($applyPath !== null && $writeReport) { fwrite(STDERR, "Preview and apply must run separately.\n"); exit(1); }
$pdo = db();
try {
    if ($applyPath !== null) {
        $path = realpath($applyPath);
        $docs = realpath(dirname(__DIR__) . '/docs');
        if (!$path || !$docs || strpos(strtolower($path), strtolower($docs . DIRECTORY_SEPARATOR)) !== 0 || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            throw new RuntimeException('Use a reviewed JSON plan from this project docs directory.');
        }
        $saved = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($saved['mode'] ?? '') !== 'historical_usage_only' || ($saved['stock_changes'] ?? null) !== false) {
            throw new RuntimeException('This command only accepts a historical-usage-only plan.');
        }
        $result = inventory_reconciliation_record_history($pdo, $saved['state_fingerprint'] ?? '');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $plan = inventory_reconciliation_history_plan($pdo);
    $pdo->rollBack();
    echo json_encode(['mode'=>$plan['mode'], 'summary'=>$plan['recipe_comparison']['summary'], 'issues'=>count($plan['issues']), 'stock_changes'=>false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    if ($writeReport) {
        $base = dirname(__DIR__) . '/docs/ingredient_history_plan_' . (new DateTimeImmutable('now',new DateTimeZone('Asia/Singapore')))->format('Ymd_His');
        foreach (['.json'=>json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n", '.md'=>inventory_reconciliation_history_report($plan)] as $extension=>$content) {
            $handle = fopen($base . $extension, 'x');
            if (!$handle) throw new RuntimeException('Cannot create correction plan.');
            try {
                if (fwrite($handle,$content) !== strlen($content) || !fflush($handle)) throw new RuntimeException('Correction plan write is incomplete.');
            } finally { fclose($handle); }
            echo 'Report: ', $base . $extension, PHP_EOL;
        }
    }
    echo "PREVIEW ONLY: no database records changed.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,$error->getMessage()."\n");
    exit(1);
}
