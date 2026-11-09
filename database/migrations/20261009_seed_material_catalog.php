<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/20261009_add_material_groups.php';
$pdo->beginTransaction();
try {
    $result = inventory_material_seed_catalog($pdo);
    $pdo->commit();
    echo count($result['added']) . ' materials added at zero stock; ' . count($result['reused']) . " existing records reused.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}