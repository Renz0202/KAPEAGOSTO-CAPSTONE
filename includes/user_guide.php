<?php
require_once __DIR__ . '/user_guide_catalog.php';

const USER_GUIDE_VERSION = 1;

function user_guide_progress(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT welcome_status, guide_version FROM user_guide_progress WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'needsWelcome' => !$row,
        'status' => $row['welcome_status'] ?? null,
        'version' => (int) ($row['guide_version'] ?? USER_GUIDE_VERSION),
    ];
}

function user_guide_save_progress(PDO $pdo, int $userId, string $status): array {
    if ($userId < 1 || !in_array($status, ['completed', 'skipped'], true)) {
        throw new InvalidArgumentException('Choose a valid welcome status.');
    }
    $stmt = $pdo->prepare('INSERT INTO user_guide_progress (user_id, welcome_status, guide_version)
        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE welcome_status = VALUES(welcome_status), guide_version = VALUES(guide_version)');
    $stmt->execute([$userId, $status, USER_GUIDE_VERSION]);
    return user_guide_progress($pdo, $userId);
}

/** Run once after creating the table, never during normal page rendering. */
function user_guide_initialize_existing(PDO $pdo): int {
    $pdo->beginTransaction();
    try {
        // A unique marker also serializes concurrent initial installations.
        $marker = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('user_guide_installed_at', ?)");
        $marker->execute([date('c')]);
        $count = 0;
        if ($marker->rowCount() === 1) {
            $count = $pdo->exec("INSERT IGNORE INTO user_guide_progress (user_id, welcome_status, guide_version)
                SELECT id, 'existing', 1 FROM users");
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Validates only the signed-in account's preference, never a client-supplied ID. */
function user_guide_validate_update(array $body, string $csrf): string {
    if ($csrf === '' || !is_string($body['csrf'] ?? null) || !hash_equals($csrf, $body['csrf'])) {
        throw new DomainException('Your session changed. Reload the page and try again.');
    }
    if (array_diff(array_keys($body), ['csrf', 'status']) || !is_string($body['status'] ?? null)
        || !in_array($body['status'], ['completed', 'skipped'], true)) {
        throw new InvalidArgumentException('Choose a valid welcome status.');
    }
    return $body['status'];
}
