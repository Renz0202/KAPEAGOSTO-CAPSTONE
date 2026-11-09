<?php
// Explicitly generated legacy data, not a reconstruction of customer choices.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
$pdo = db();
try {
    $pdo->beginTransaction();
    $rows = $pdo->query('SELECT id, service_type FROM orders WHERE service_type IS NULL ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $pdo->rollBack();
        echo "No unrecorded order types remain. Existing values were preserved.\n";
        exit;
    }
    $backup = dirname(__DIR__) . '/backups/legacy_order_types_' . date('Ymd_His') . '.json';
    if (file_put_contents($backup, json_encode(['purpose' => 'Before generated legacy service types', 'orders' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not save the legacy values backup.');
    }
    $update = $pdo->prepare('UPDATE orders SET service_type = ? WHERE id = ? AND service_type IS NULL');
    $audit = $pdo->prepare("INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (?, NULL, 'legacy_service_type_generated', ?)");
    $counts = ['dine_in' => 0, 'take_out' => 0];
    foreach ($rows as $index => $row) {
        $type = $index % 2 === 0 ? 'dine_in' : 'take_out';
        $update->execute([$type, $row['id']]);
        $audit->execute([$row['id'], json_encode([
            'service_type' => $type, 'previous_service_type' => null,
            'generated' => true, 'source' => 'legacy_backfill',
            'method' => 'alternating_by_order_id',
            'note' => 'Generated for the system update; not an actual recorded customer selection.',
        ], JSON_THROW_ON_ERROR)]);
        $counts[$type]++;
    }
    $pdo->commit();
    echo 'Generated legacy order types: ' . json_encode($counts) . ". Existing selections were preserved.\n";
    echo 'Backup: ' . basename($backup) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
