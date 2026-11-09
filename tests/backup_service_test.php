<?php
if (PHP_SAPI !== 'cli') exit;
date_default_timezone_set('Asia/Manila');
require_once dirname(__DIR__) . '/includes/backup_service.php';
function verify($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS: ', $message, PHP_EOL;
}
$at = static fn(string $time): DateTimeImmutable => new DateTimeImmutable($time);
verify(backup_due_date($at('2026-10-24 23:54:59')) === '2026-10-23', 'Before schedule, only the preceding scheduled date is due');
verify(backup_due_date($at('2026-10-24 23:55:00')) === '2026-10-24', 'Daily schedule becomes due at 23:55');
verify(!backup_is_due(['completed_schedule_date' => '2026-10-23'], $at('2026-10-24 08:00')), 'No extra morning backup after a successful previous evening');
verify(backup_is_due(['completed_schedule_date' => '2026-10-22'], $at('2026-10-24 08:00')), 'Missed evening catches up next morning');
verify(!backup_is_due(['completed_schedule_date' => '2026-10-23'], $at('2026-10-24 00:01')), 'Midnight does not duplicate the previous scheduled backup');
verify(backup_is_due([], $at('2026-10-24 08:00')), 'First installation has a catch-up backup due');
verify(!backup_is_due(['last_run' => '2026-10-24'], $at('2026-10-24 23:55')), 'Legacy marker migrates without creating duplicate same-day backups');

class BackupFixtureStatement extends PDOStatement {
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { $rows = $this->rows; $this->rows = []; return $rows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
}
class BackupFixturePDO extends PDO {
    public bool $fail = false;
    public function __construct() {}
    public function inTransaction(): bool { return false; }
    public function exec(string $statement): int|false { return 0; }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return "'" . str_replace("'", "''", $string) . "'"; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if ($this->fail) throw new RuntimeException('Simulated database failure');
        $rows = match (true) {
            str_contains($query, "'BASE TABLE'") => [['test_items', 'BASE TABLE']],
            str_contains($query, "'VIEW'") => [],
            str_starts_with($query, 'SHOW CREATE') => [['Create Table' => 'CREATE TABLE `test_items` (`name` TEXT, `derived` INT GENERATED ALWAYS AS (1) STORED)']],
            str_starts_with($query, 'SHOW COLUMNS') => [['Field' => 'name', 'Extra' => ''], ['Field' => 'derived', 'Extra' => 'STORED GENERATED']],
            str_starts_with($query, 'SELECT') => [["Milk's"]],
            default => [],
        };
        return new BackupFixtureStatement($rows);
    }
}
$dir = dirname(__DIR__) . '/tests/.backup-fixture-' . bin2hex(random_bytes(4));
mkdir($dir);
$db = new BackupFixturePDO();
try {
    file_put_contents($dir . '/manual_keep.sql', 'manual');
    file_put_contents($dir . '/legacy.sql', 'legacy');
    file_put_contents($dir . '/auto_20000101_000000_abcdef.sql', 'old automatic');
    foreach (glob($dir . '/*.sql') as $file) touch($file, time() - 90 * 86400);
    $result = backup_run($db, $dir);
    verify($result['status'] === 'success', 'Automatic backup completes');
    $sql = file_get_contents($dir . '/' . $result['file']);
    verify(str_contains($sql, "INSERT INTO `test_items` (`name`) VALUES ('Milk''s');"), 'Generated columns omitted from INSERT and strings escaped');
    verify(str_ends_with($sql, "SET FOREIGN_KEY_CHECKS=1;\n"), 'Completed SQL has its footer');
    verify(is_file($dir . '/manual_keep.sql') && is_file($dir . '/legacy.sql'), 'Retention preserves manual and legacy backups');
    verify(!is_file($dir . '/auto_20000101_000000_abcdef.sql'), 'Retention expires only owned automatic backup files');
    verify(backup_run($db, $dir)['status'] === 'not_due', 'A repeated scheduled request does not duplicate backup');
    $lock = fopen($dir . '/.backup.lock', 'c'); flock($lock, LOCK_EX);
    verify(backup_run($db, $dir, false)['status'] === 'busy', 'Concurrent backup cannot bypass lock');
    flock($lock, LOCK_UN); fclose($lock);
    $state = backup_read_state($dir); $state['completed_schedule_date'] = '2000-01-01'; backup_write_state($dir, $state);
    $db->fail = true;
    try { backup_run($db, $dir); throw new RuntimeException('Expected failure'); }
    catch (RuntimeException $error) { verify($error->getMessage() === 'Simulated database failure', 'Database failure reported'); }
    $failed = backup_read_state($dir);
    verify($failed['last_attempt_status'] === 'failed' && $failed['last_success_at'] === $state['last_success_at'], 'Failure preserves last successful backup and records error');
    verify(!glob($dir . '/*.partial'), 'Failure removes incomplete SQL file');
} finally {
    // Only files in this newly-created, fixed-parent test fixture may be removed.
    foreach (scandir($dir) as $name) if ($name !== '.' && $name !== '..') unlink($dir . '/' . $name);
    rmdir($dir);
}
