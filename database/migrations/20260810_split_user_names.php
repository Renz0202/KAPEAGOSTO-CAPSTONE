<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/user_utils.php';

$pdo = db();

$columnExists = static function (PDO $pdo, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
    $stmt->execute([$column]);
    return (int) $stmt->fetchColumn() > 0;
};

$columns = [
    'last_name' => "ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER full_name",
    'first_name' => "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER last_name",
    'middle_name' => "ALTER TABLE users ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name",
];

foreach ($columns as $column => $sql) {
    if (!$columnExists($pdo, $column)) {
        $pdo->exec($sql);
        echo "Added users.{$column}" . PHP_EOL;
    }
}

$rows = $pdo->query('SELECT id, full_name, last_name, first_name, middle_name FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$update = $pdo->prepare('UPDATE users SET full_name = ?, last_name = ?, first_name = ?, middle_name = ? WHERE id = ?');
$updated = 0;

foreach ($rows as $row) {
    $hasStructuredName = trim((string) ($row['last_name'] ?? '')) !== ''
        || trim((string) ($row['first_name'] ?? '')) !== ''
        || trim((string) ($row['middle_name'] ?? '')) !== '';

    $parts = $hasStructuredName
        ? [
            'last_name' => user_title_case($row['last_name'] ?? ''),
            'first_name' => user_title_case($row['first_name'] ?? ''),
            'middle_name' => user_title_case($row['middle_name'] ?? ''),
        ]
        : user_name_parts($row['full_name'] ?? '');

    $fullName = user_build_full_name($parts['last_name'], $parts['first_name'], $parts['middle_name']);
    if ($fullName === '') {
        $fullName = user_title_case($row['full_name'] ?? '', 'User');
    }

    $middleName = $parts['middle_name'] !== '' ? $parts['middle_name'] : null;
    $lastName = $parts['last_name'] !== '' ? $parts['last_name'] : null;
    $firstName = $parts['first_name'] !== '' ? $parts['first_name'] : null;
    $update->execute([$fullName, $lastName, $firstName, $middleName, (int) $row['id']]);
    $updated += $update->rowCount();
}

echo "Structured name migration complete; {$updated} account row(s) changed." . PHP_EOL;

