<?php
require_once dirname(__DIR__) . '/config/backup.php';

function backup_read_state(string $dir): array {
    $state = json_decode((string) @file_get_contents($dir . '/.auto_backup.json'), true);
    return is_array($state) ? $state : [];
}
function backup_due_date(DateTimeImmutable $now): string {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', AUTO_BACKUP_TIME)) throw new RuntimeException('Invalid backup schedule.');
    return ($now->format('H:i') >= AUTO_BACKUP_TIME ? $now : $now->modify('-1 day'))->format('Y-m-d');
}
function backup_is_due(array $state, DateTimeImmutable $now): bool {
    return AUTO_BACKUP_ENABLED && ($state['completed_schedule_date'] ?? $state['last_run'] ?? '') < backup_due_date($now);
}
function backup_write_state(string $dir, array $state): void {
    $tmp = $dir . '/.auto_backup.json.tmp';
    if (file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) === false || !rename($tmp, $dir . '/.auto_backup.json')) throw new RuntimeException('Cannot save backup status.');
}
function backup_log_event(string $dir, string $message): void {
    file_put_contents($dir . '/backup.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function backup_run(PDO $pdo, string $dir, bool $automatic = true): array {
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Backup directory unavailable.');
    $lock = fopen($dir . '/.backup.lock', 'c');
    if (!$lock) throw new RuntimeException('Backup lock unavailable.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['status' => 'busy']; }
    $tmp = null; $stream = null; $snapshot = false;
    $state = backup_read_state($dir); $now = new DateTimeImmutable();
    try {
        if ($automatic && !backup_is_due($state, $now)) return ['status' => 'not_due'];
        if ($pdo->inTransaction()) throw new RuntimeException('Cannot back up inside another transaction.');
        if ($automatic) {
            $state['last_attempt_at'] = $now->format(DATE_ATOM);
            $state['last_attempt_status'] = 'running';
            backup_write_state($dir, $state);
        }
        $name = ($automatic ? 'auto_' : 'manual_') . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql';
        $tmp = $dir . '/' . $name . '.partial';
        $stream = fopen($tmp, 'xb');
        if (!$stream) throw new RuntimeException('Cannot create backup file.');
        $write = static function(string $data) use ($stream): void {
            $offset = 0;
            while ($offset < strlen($data)) {
                $n = fwrite($stream, substr($data, $offset));
                if ($n === false || $n === 0) throw new RuntimeException('Backup write failed; check storage.');
                $offset += $n;
            }
        };
        $identifier = static fn(string $value): string => '`' . str_replace('`', '``', $value) . '`';
        backup_log_event($dir, 'Backup starting: ' . $name);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'); $snapshot = true;
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        if (!$tables) throw new RuntimeException('No database tables found.');
        if ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetch()) throw new RuntimeException('Database views require a mysqldump backup.');
        $write("-- Kape Agosto database backup\n-- Generated at: " . $now->format(DATE_ATOM) . "\nSET NAMES utf8mb4;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($tables as $table) {
            $id = $identifier($table[0]);
            $create = $pdo->query('SHOW CREATE TABLE ' . $id)->fetch(PDO::FETCH_ASSOC);
            $write('DROP TABLE IF EXISTS ' . $id . ";\n" . $create['Create Table'] . ";\n\n");
            $columns = $pdo->query('SHOW COLUMNS FROM ' . $id)->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_filter($columns, static fn(array $c): bool => !preg_match('/(?:VIRTUAL|STORED) GENERATED/i', $c['Extra']));
            if (!$columns) continue;
            $names = implode(', ', array_map(static fn(array $c): string => $identifier($c['Field']), $columns));
            $query = $pdo->query('SELECT ' . $names . ' FROM ' . $id);
            while ($row = $query->fetch(PDO::FETCH_NUM)) {
                $values = array_map(static fn($v): string => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
                $write('INSERT INTO ' . $id . ' (' . $names . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        $pdo->exec('COMMIT'); $snapshot = false;
        if (!fflush($stream)) throw new RuntimeException('Cannot flush backup file.');
        fclose($stream); $stream = null;
        if (!rename($tmp, $dir . '/' . $name)) throw new RuntimeException('Cannot finalize backup file.');
        $tmp = null;
        if ($automatic) {
            $state = array_merge($state, ['last_run' => $now->format('Y-m-d'), 'last_success_at' => date(DATE_ATOM), 'completed_schedule_date' => backup_due_date($now), 'file' => $name, 'last_attempt_status' => 'success', 'last_error' => null]);
            backup_write_state($dir, $state);
            foreach (glob($dir . '/auto_*.sql') ?: [] as $file) {
                if (preg_match('/^auto_\d{8}_\d{6}_[a-f0-9]{6}\.sql$/', basename($file)) && !is_link($file) && filemtime($file) < time() - max(1, (int) AUTO_BACKUP_KEEP_DAYS) * 86400 && basename($file) !== $name) {
                    if (!unlink($file)) backup_log_event($dir, 'Retention cleanup failed: ' . basename($file));
                }
            }
        }
        backup_log_event($dir, 'Backup completed: ' . $name);
        return ['status' => 'success', 'file' => $name];
    } catch (Throwable $error) {
        if ($snapshot) $pdo->exec('ROLLBACK');
        if ($automatic) {
            $state['last_attempt_status'] = 'failed'; $state['last_error'] = $error->getMessage();
            backup_write_state($dir, $state);
        }
        backup_log_event($dir, 'Backup failed: ' . $error->getMessage());
        throw $error;
    } finally {
        if (is_resource($stream)) fclose($stream);
        if ($tmp !== null && is_file($tmp)) unlink($tmp);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
