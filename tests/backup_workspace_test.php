<?php
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/includes/backup_service.php';
function role_display_name($role) { return 'Admin'; }
function current_user_role() { return 'admin'; }
function format_backup_size($bytes) { return number_format($bytes / 1024, 2) . ' KB'; }
$source = file_get_contents(dirname(__DIR__) . '/public/maintenance/backup_restore.php');
$_SESSION = [];
$start = strpos($source, '<main ');
$template = substr($source, $start, strpos($source, '</main>', $start) + strlen('</main>') - $start);
function check($value, $message) { if (!$value) throw new RuntimeException($message); echo 'PASS: ', $message, PHP_EOL; }
foreach (['schedule', 'restore', 'archive', 'logs'] as $activeBackupTab) {
    $flash_success = ''; $flash_error = ''; $statusText = 'Up to date'; $statusClass = 'ok';
    $backupCount = 1; $totalBackupBytes = 1200;
    $latestBackup = ['name' => '<unsafe>.sql', 'size' => 1200, 'time' => time()];
    $backups = [$latestBackup]; $schedulerLabel = 'Task registered'; $backupState = ['completed_schedule_date' => '2099-01-01'];
    $now = new DateTimeImmutable(); $autoLast = '2026-10-24'; $nextRun = '2026-10-24 23:55';
    ob_start(); eval('?>' . $template); $rendered = ob_get_clean();
    $dom = new DOMDocument(); @$dom->loadHTML('<!doctype html><meta charset="utf-8">' . $rendered);
    $x = new DOMXPath($dom);
    check($x->query('//*[@data-backup-tabs]/section[@role="tabpanel"]')->length === 4, $activeBackupTab . ': four sibling panels remain inside the tab container');
    check($x->query('//*[@role="tabpanel" and not(@hidden)]')->length === 1 && $x->query('//*[@role="tabpanel" and not(@hidden)]')->item(0)->getAttribute('id') === 'backup-panel-' . $activeBackupTab, $activeBackupTab . ': only the selected panel is visible');
    check($x->query('//*[@role="tab" and @aria-selected="true" and @tabindex="0"]')->length === 1, $activeBackupTab . ': exactly one tab is selected and keyboard reachable');
    check($x->query('//*[@id="backup-panel-restore"]//form[@data-confirm]//input[@name="do_restore"]')->length === 1, $activeBackupTab . ': restore confirmation and submission remain intact');
    check($x->query('//*[@id="backup-panel-archive"]//tbody[@id="backupsTbody"]/tr')->length === 1 && $x->query('//*[@id="backup-panel-logs"]//*[@id="backupLog" or @id="restoreLog"]')->length === 2, $activeBackupTab . ': archive and both live log targets are preserved');
    check($x->query('//unsafe')->length === 0, $activeBackupTab . ': filenames remain escaped');
}