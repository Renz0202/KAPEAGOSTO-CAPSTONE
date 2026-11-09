<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_admin();
require_once dirname(__DIR__, 2) . '/includes/backup_service.php';

date_default_timezone_set('Asia/Manila');

$backupDir = realpath(dirname(__DIR__, 2) . '/backups');
if ($backupDir === false) {
    $backupDir = dirname(__DIR__, 2) . '/backups';
}
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}

function backup_log(string $message): void {
    global $backupDir;
    @file_put_contents($backupDir . '/backup.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function restore_log(string $message): void {
    global $backupDir;
    @file_put_contents($backupDir . '/restore.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function quote_value(PDO $pdo, $val): string {
    if ($val === null) return 'NULL';
    if (is_int($val) || is_float($val)) return (string) $val;
    return $pdo->quote((string) $val);
}

function get_tables(PDO $pdo): array {
    $stmt = $pdo->query('SHOW TABLES');
    $tables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    return $tables;
}

function dump_structure(PDO $pdo, string $table): string {
    $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
    $create = $row['Create Table'] ?? $row['Create View'] ?? '';
    return "\n--\n-- Structure for table `{$table}`\n--\n\nDROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n";
}

function dump_data(PDO $pdo, string $table): string {
    $sql = "\n--\n-- Data for table `{$table}`\n--\n";
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($count === 0) return $sql . "\n";

    $limit = 500;
    for ($offset = 0; $offset < $count; $offset += $limit) {
        $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;

        $cols = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($rows[0]));
        $sql .= 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ") VALUES\n";

        $vals = [];
        foreach ($rows as $row) {
            $items = [];
            foreach ($row as $value) {
                $items[] = quote_value($pdo, $value);
            }
            $vals[] = '(' . implode(', ', $items) . ')';
        }
        $sql .= implode(",\n", $vals) . ";\n\n";
    }

    return $sql;
}

function load_sql_statements(string $sql): array {
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") $sql = substr($sql, 3);
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) continue;
        $clean[] = $line;
    }

    $parts = preg_split('/;\s*\n/', implode("\n", $clean));
    $statements = [];
    foreach ($parts as $part) {
        $statement = trim($part);
        if ($statement !== '') $statements[] = $statement . ';';
    }
    return $statements;
}

function list_backups(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    $backups = [];
    foreach ($files as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => (int) @filesize($file),
            'time' => (int) @filemtime($file),
        ];
    }
    usort($backups, fn($a, $b) => $b['time'] <=> $a['time']);
    return $backups;
}

function format_backup_size(int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
    return number_format($bytes / 1024, 2) . ' KB';
}

$flash_success = '';
$flash_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db();

        if (isset($_POST['do_backup'])) {
            @ini_set('memory_limit', '512M');
            @set_time_limit(0);

            $result = backup_run($pdo, $backupDir, false);
            if ($result['status'] === 'busy') throw new RuntimeException('Another backup is already running. Please try again shortly.');
            $flash_success = 'Backup created: ' . htmlspecialchars($result['file'], ENT_QUOTES, 'UTF-8');
        }
        if (isset($_POST['do_restore'])) {
            @ini_set('memory_limit', '512M');
            @set_time_limit(0);

            $source = null;
            if (!empty($_POST['existing_backup'])) {
                $candidate = basename((string) $_POST['existing_backup']);
                if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $candidate)) throw new RuntimeException('Invalid backup filename.');

                $path = $backupDir . DIRECTORY_SEPARATOR . $candidate;
                if (!is_file($path)) throw new RuntimeException('Selected backup does not exist.');
                $source = $path;
            } elseif (!empty($_FILES['sql_file']['name'])) {
                if (($_FILES['sql_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('File upload failed.');
                if (strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION)) !== 'sql') throw new RuntimeException('Only .sql files are allowed.');

                $dest = $backupDir . DIRECTORY_SEPARATOR . 'uploaded_' . date('Ymd_His') . '.sql';
                if (!move_uploaded_file($_FILES['sql_file']['tmp_name'], $dest)) throw new RuntimeException('Unable to move uploaded file.');
                $source = $dest;
            } else {
                throw new RuntimeException('Please select a backup or upload a file.');
            }

            restore_log('Starting restore from ' . basename($source));
            $sql = file_get_contents($source);
            if ($sql === false) throw new RuntimeException('Unable to read SQL file.');

            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->beginTransaction();
            $statements = load_sql_statements($sql);
            $total = count($statements);
            foreach ($statements as $index => $statement) {
                $pdo->exec($statement);
                if ((($index + 1) % 50) === 0) {
                    restore_log('Executed ' . ($index + 1) . ' / ' . $total . ' statements...');
                }
            }
            $pdo->commit();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            restore_log('Restore completed successfully.');
            $flash_success = 'Restore completed successfully.';
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash_error = $e->getMessage();
        if (isset($_POST['do_backup'])) backup_log('Backup failed: ' . $flash_error);
        if (isset($_POST['do_restore'])) restore_log('Restore failed: ' . $flash_error);
    }
}

$allowedBackupTabs = ['schedule', 'restore', 'archive', 'logs'];
$activeBackupTab = isset($_POST['do_restore']) ? 'restore' : (isset($_POST['do_backup']) ? 'archive' : (string) ($_GET['tab'] ?? 'schedule'));
if (!in_array($activeBackupTab, $allowedBackupTabs, true)) $activeBackupTab = 'schedule';
$backups = list_backups($backupDir);
$backupCount = count($backups);
$latestBackup = $backups[0] ?? null;
$totalBackupBytes = array_sum(array_map(fn($b) => (int) ($b['size'] ?? 0), $backups));

require_once dirname(__DIR__, 2) . '/includes/backup_service.php';
$backupState = backup_read_state($backupDir);
$autoLast = $backupState['last_success_at'] ?? $backupState['last_run'] ?? null;
$statusClass = 'none';
$statusText = AUTO_BACKUP_ENABLED ? 'Awaiting automatic backup' : 'Disabled';
if (AUTO_BACKUP_ENABLED && ($backupState['last_attempt_status'] ?? '') === 'failed') {
    $statusClass = 'wait';
    $statusText = 'Last attempt failed';
} elseif (AUTO_BACKUP_ENABLED && $autoLast) {
    $statusClass = backup_is_due($backupState, new DateTimeImmutable()) ? 'wait' : 'ok';
    $statusText = $statusClass === 'ok' ? 'Up to date' : 'Backup overdue';
}
$now = new DateTimeImmutable();
$nextScheduled = new DateTimeImmutable($now->format('Y-m-d') . ' ' . AUTO_BACKUP_TIME);
if ($nextScheduled <= $now || ($backupState['completed_schedule_date'] ?? $backupState['last_run'] ?? '') >= $now->format('Y-m-d')) {
    $nextScheduled = $nextScheduled->modify('+1 day');
}
$nextRun = AUTO_BACKUP_ENABLED ? $nextScheduled->format('Y-m-d H:i') . ' (Philippine time)' : 'Disabled';
$schedulerFile = $backupDir . '/.scheduler.json';
$schedulerState = json_decode((string) @file_get_contents($schedulerFile), true);
$schedulerLabel = is_array($schedulerState) && !empty($schedulerState['registered_at'])
    ? 'Windows task registered on ' . $schedulerState['registered_at'] . '. Runs while the PC is on, even when the registered Windows user is signed out; missed runs catch up when Windows starts. MySQL must be running.'
    : 'Windows task registration not confirmed. Page requests provide catch-up backups.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kape Agosto | Backup & Restore</title>
    <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/backup-workspace.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/backup-workspace.css')) ?>">
    <script src="<?= htmlspecialchars(kape_asset_url('scripts/backup-tabs.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/backup-tabs.js')) ?>" defer></script>
    <script>
        function renderLog(el, txt) {
            if (!el) return;
            el.innerHTML = '';
            const lines = (txt || '').split(/\r?\n/)
                .map((raw, index) => ({ raw, index, timestamp: (raw.match(/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/) || [])[1] || '' }))
                .filter(entry => entry.raw.trim())
                .sort((a, b) => b.timestamp.localeCompare(a.timestamp) || b.index - a.index)
                .map(entry => entry.raw);
            for (const raw of lines) {
                if (!raw || !raw.trim()) continue;
                const match = raw.match(/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*(.*)$/);
                const time = match ? match[1] : '--';
                const msg = match ? match[2] : raw;
                const lower = msg.toLowerCase();
                const line = document.createElement('div');
                line.className = 'log-line';
                if (lower.includes('failed') || lower.includes('error')) line.classList.add('log-error');
                else if (lower.includes('done') || lower.includes('completed') || lower.includes('success')) line.classList.add('log-ok');
                else if (lower.includes('start')) line.classList.add('log-info');

                const t = document.createElement('span');
                t.className = 'log-time';
                t.textContent = time;
                const m = document.createElement('span');
                m.className = 'log-msg';
                m.textContent = msg;
                line.appendChild(t);
                line.appendChild(m);
                el.appendChild(line);
            }
            el.scrollTop = 0;
        }

        async function fetchAndRender(url, el) {
            if (!el) return;
            try {
                const res = await fetch(url, { cache: 'no-cache' });
                renderLog(el, await res.text());
            } catch {}
        }

        function copyLog(id, btn) {
            const el = document.getElementById(id);
            if (!el) return;
            const text = Array.from(el.querySelectorAll('.log-line')).map(line => {
                const time = line.querySelector('.log-time')?.textContent || '';
                const msg = line.querySelector('.log-msg')?.textContent || '';
                return (time && time !== '--' ? '[' + time + '] ' : '') + msg;
            }).join('\n');
            const done = () => {
                if (!btn) return;
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-check"></i> Copied';
                setTimeout(() => btn.innerHTML = original, 1200);
            };
            if (navigator.clipboard?.writeText) navigator.clipboard.writeText(text).then(done).catch(done);
            else done();
        }

        function kb(bytes) {
            const value = Number(bytes || 0);
            if (value >= 1024 * 1024) return (value / 1024 / 1024).toFixed(2) + ' MB';
            return (value / 1024).toFixed(2) + ' KB';
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char]));
        }

        function renderBackupsTable(list) {
            const tbody = document.getElementById('backupsTbody');
            const wrapper = document.getElementById('backupsTableWrapper');
            const empty = document.getElementById('noBackupsAlert');
            const count = document.getElementById('backupCountPill');
            if (!tbody || !wrapper || !empty) return;
            if (count) count.textContent = list.length + ' files';

            if (!list.length) {
                wrapper.style.display = 'none';
                empty.style.display = 'grid';
                tbody.innerHTML = '';
                return;
            }

            wrapper.style.display = 'block';
            empty.style.display = 'none';
            let i = list.length;
            tbody.innerHTML = list.map(b => `
                <tr>
                    <td>${i--}</td>
                    <td>${escapeHtml(b.name)}</td>
                    <td>${kb(b.size)}</td>
                    <td>${escapeHtml(b.time_iso)}</td>
                    <td><a class="backup-action secondary small" href="backup_download.php?file=${encodeURIComponent(b.name)}"><i class="fa fa-download"></i> Download</a></td>
                </tr>
            `).join('');
        }

        async function refreshBackups() {
            try {
                const res = await fetch('backup_list.php', { cache: 'no-cache' });
                const data = await res.json();
                if (data?.ok) renderBackupsTable(data.backups || []);
            } catch {}
        }

        document.addEventListener('DOMContentLoaded', () => {
            const backupEl = document.getElementById('backupLog');
            const restoreEl = document.getElementById('restoreLog');
            const pollLogs = () => {
                fetchAndRender('backup_status.php', backupEl);
                fetchAndRender('restore_status.php', restoreEl);
            };
            pollLogs();
            refreshBackups();
            setInterval(pollLogs, 2000);
            setInterval(refreshBackups, 5000);
        });
    </script>
</head>
<body class="kape-maintenance-backup-restore">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content backup-page">
    <section class="backup-hero">
        <div>
            <p class="backup-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> <a href="settings.php" style="color:inherit">System Settings</a> <i class="fa fa-chevron-right"></i> Backup &amp; Restore</p>
            <h1>Backup & Restore</h1>
            <p>Create database snapshots, restore protected SQL backups, and monitor system recovery activity from one workspace.</p>
        </div>
        <div class="backup-hero-actions">
            <a class="backup-action muted" href="settings.php"><i class="fa fa-gear"></i> System Settings</a>
        </div>
    </section>

    <?php if ($flash_success): ?>
        <div class="backup-alert success"><i class="fa fa-circle-check"></i> <?= $flash_success ?></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div class="backup-alert danger"><i class="fa fa-triangle-exclamation"></i> <?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <section class="backup-summary-grid" aria-label="Backup status overview">
        <article>
            <span class="backup-card-icon"><i class="fa fa-shield-halved"></i></span>
            <span class="backup-card-label">Auto Backup</span>
            <strong><?= htmlspecialchars($statusText) ?></strong>
        </article>
        <article>
            <span class="backup-card-icon"><i class="fa fa-folder-open"></i></span>
            <span class="backup-card-label">Available Files</span>
            <strong><?= (int) $backupCount ?></strong>
        </article>
        <article>
            <span class="backup-card-icon"><i class="fa fa-hard-drive"></i></span>
            <span class="backup-card-label">Storage Used</span>
            <strong><?= htmlspecialchars(format_backup_size((int) $totalBackupBytes)) ?></strong>
        </article>
        <article>
            <span class="backup-card-icon"><i class="fa fa-clock-rotate-left"></i></span>
            <span class="backup-card-label">Latest Backup</span>
            <strong><?= $latestBackup ? htmlspecialchars(date('M d, Y', $latestBackup['time'])) : 'None' ?></strong>
        </article>
    </section>

    <div class="backup-tabs" data-backup-tabs>
        <nav class="backup-tab-list" role="tablist" aria-label="Backup and restore sections">
            <?php foreach (['schedule' => 'Schedule', 'restore' => 'Restore Database', 'archive' => 'Available Backups', 'logs' => 'Activity Logs'] as $tabKey => $tabLabel): ?>
                <a href="?tab=<?= $tabKey ?>" id="backup-tab-<?= $tabKey ?>" class="backup-tab" role="tab" aria-controls="backup-panel-<?= $tabKey ?>" aria-selected="<?= $activeBackupTab === $tabKey ? 'true' : 'false' ?>" tabindex="<?= $activeBackupTab === $tabKey ? '0' : '-1' ?>" data-backup-tab="<?= $tabKey ?>"><?= $tabLabel ?></a>
            <?php endforeach; ?>
        </nav>
        <section class="backup-tab-panel" id="backup-panel-schedule" role="tabpanel" aria-labelledby="backup-tab-schedule" <?= $activeBackupTab !== 'schedule' ? 'hidden' : '' ?>>
        <article class="backup-panel">
            <div class="backup-panel-heading">
                <div>
                    <span class="backup-eyebrow">Protection</span>
                    <h2>Backup Schedule</h2>
                </div>
                <span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
            </div>
            <p class="backup-note"><?= htmlspecialchars($schedulerLabel) ?> MySQL must be running.</p>
            <?php if (AUTO_BACKUP_ENABLED && backup_is_due($backupState, $now)): ?>
                <p class="backup-note">A missed scheduled backup is pending. The next Dashboard, Inventory or Orders request will retry it.</p>
            <?php endif; ?>
            <?php if (($backupState['last_attempt_status'] ?? '') === 'failed'): ?>
                <p class="backup-note">Last attempt: <?= htmlspecialchars($backupState['last_attempt_at'] ?? 'Unknown') ?>. <?= htmlspecialchars($backupState['last_error'] ?? 'Check the backup log.') ?></p>
            <?php endif; ?>
            <div class="backup-status-list">
                <div>
                    <span>Last Auto Backup</span>
                    <strong><?= $autoLast ? htmlspecialchars($autoLast) : 'Not available' ?></strong>
                </div>
                <div>
                    <span>Next Scheduled Time</span>
                    <strong><?= htmlspecialchars($nextRun) ?></strong>
                </div>
                <div>
                    <span>Retention</span>
                    <strong><?= (int) AUTO_BACKUP_KEEP_DAYS ?> days for automatic files; manual backups retained</strong>
                </div>
            </div>
        </article>

        </section>
        <section class="backup-tab-panel" id="backup-panel-restore" role="tabpanel" aria-labelledby="backup-tab-restore" <?= $activeBackupTab !== 'restore' ? 'hidden' : '' ?>>
        <article class="backup-panel danger">
            <div class="backup-panel-heading">
                <div>
                    <span class="backup-eyebrow">Recovery</span>
                    <h2>Restore Database</h2>
                </div>
                <i class="fa fa-rotate"></i>
            </div>
            <p class="backup-note">Restoring replaces the current database with the selected SQL backup.</p>
            <form class="backup-restore-form" action="" method="post" enctype="multipart/form-data" data-confirm="Restore will overwrite current database data. Continue?" data-confirm-title="Restore Backup" data-confirm-type="danger">
                <input type="hidden" name="do_restore" value="1">
                <label>
                    <span>Existing Backup</span>
                    <select name="existing_backup">
                        <option value="">-- Select backup --</option>
                        <?php foreach ($backups as $backup): ?>
                            <option value="<?= htmlspecialchars($backup['name']) ?>"><?= htmlspecialchars($backup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Upload SQL File</span>
                    <input id="sql_file" type="file" name="sql_file" accept=".sql">
                </label>
                <button class="backup-action danger" type="submit"><i class="fa fa-rotate-left"></i> Restore Backup</button>
            </form>
        </article>
    </section>

    <section class="backup-tab-panel" id="backup-panel-archive" role="tabpanel" aria-labelledby="backup-tab-archive" <?= $activeBackupTab !== 'archive' ? 'hidden' : '' ?>>
    <article class="backup-panel">
        <div class="backup-panel-heading">
            <div>
                <span class="backup-eyebrow">Archive</span>
                <h2>Available Backups</h2>
            </div>
            <div class="backup-archive-actions">
                <span id="backupCountPill" class="backup-count-pill"><?= (int) $backupCount ?> files</span>
                <form action="" method="post">
                    <input type="hidden" name="do_backup" value="1">
                    <button type="submit" class="backup-action muted small"><i class="fa fa-plus" aria-hidden="true"></i> Create Backup</button>
                </form>
            </div>
        </div>
        <div id="noBackupsAlert" class="backup-empty" style="display: <?= empty($backups) ? 'grid' : 'none' ?>;">
            <i class="fa fa-folder-open"></i>
            <strong>No backups found</strong>
            <span>Create a manual backup to start the recovery archive.</span>
        </div>
        <div id="backupsTableWrapper" class="backup-table-wrap" style="display: <?= empty($backups) ? 'none' : 'block' ?>;">
            <table class="backup-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="backupsTbody">
                    <?php $i = count($backups); foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= $i-- ?></td>
                            <td><?= htmlspecialchars($backup['name']) ?></td>
                            <td><?= htmlspecialchars(format_backup_size((int) $backup['size'])) ?></td>
                            <td><?= date('M d, Y g:i A', $backup['time']) ?></td>
                            <td><a class="backup-action secondary small" href="backup_download.php?file=<?= urlencode($backup['name']) ?>"><i class="fa fa-download"></i> Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    </section>

    <section class="backup-tab-panel" id="backup-panel-logs" role="tabpanel" aria-labelledby="backup-tab-logs" <?= $activeBackupTab !== 'logs' ? 'hidden' : '' ?>>
    <article class="backup-panel">
        <div class="backup-panel-heading">
            <div>
                <span class="backup-eyebrow">Activity</span>
                <h2>Recent Logs</h2>
            </div>
        </div>
        <div class="backup-log-grid">
            <div>
                <div class="code-toolbar">
                    <h4>Backup Log</h4>
                    <button type="button" class="backup-action muted small" onclick="copyLog('backupLog', this)"><i class="fa fa-copy"></i> Copy</button>
                </div>
                <div id="backupLog" class="log-box"></div>
            </div>
            <div>
                <div class="code-toolbar">
                    <h4>Restore Log</h4>
                    <button type="button" class="backup-action muted small" onclick="copyLog('restoreLog', this)"><i class="fa fa-copy"></i> Copy</button>
                </div>
                <div id="restoreLog" class="log-box"></div>
            </div>
        </div>
    </article>
    </section>
    </div>
</main>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
