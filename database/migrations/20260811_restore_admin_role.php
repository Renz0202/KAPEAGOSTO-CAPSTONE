<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';

$pdo = db();

try {
    $roleRows = $pdo->query('SELECT id, LOWER(name) AS name FROM roles')->fetchAll(PDO::FETCH_ASSOC);
    $adminId = null;
    $businessAdminId = null;
    foreach ($roleRows as $role) {
        if ($role['name'] === 'admin') {
            $adminId = (int) $role['id'];
        } elseif ($role['name'] === 'business_admin') {
            $businessAdminId = (int) $role['id'];
        }
    }

    if ($businessAdminId !== null && $adminId === null) {
        $stmt = $pdo->prepare("UPDATE roles SET name = 'admin' WHERE id = ?");
        $stmt->execute([$businessAdminId]);
    } elseif ($businessAdminId !== null && $adminId !== null) {
        $stmt = $pdo->prepare('UPDATE users SET role_id = ? WHERE role_id = ?');
        $stmt->execute([$adminId, $businessAdminId]);
        $stmt = $pdo->prepare('DELETE FROM roles WHERE id = ?');
        $stmt->execute([$businessAdminId]);
    } elseif ($adminId === null) {
        $pdo->exec("INSERT INTO roles (name) VALUES ('admin')");
    }

    echo 'Admin role migration complete.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Admin role migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
