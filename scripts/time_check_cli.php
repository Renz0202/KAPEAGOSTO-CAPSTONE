<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$phpNow = date('Y-m-d H:i:s T');
$phpIso = date('c');

$pdo = db();
$rows = $pdo->query("SELECT NOW() AS now_ts, @@session.time_zone AS session_tz, @@global.time_zone AS global_tz")->fetch();

echo "PHP now:    {$phpNow}\n";
echo "PHP ISO:    {$phpIso}\n";
echo "MySQL NOW(): {$rows['now_ts']}\n";
echo "MySQL tz:    session={$rows['session_tz']} | global={$rows['global_tz']}\n";
