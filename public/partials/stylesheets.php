<?php
require_once __DIR__ . '/assets.php';

$stylePath = dirname(__DIR__) . '/css/style.css';
$styleVersion = is_file($stylePath) ? filemtime($stylePath) : time();
$alertsPath = dirname(__DIR__) . '/scripts/alerts.js';
$alertsVersion = is_file($alertsPath) ? filemtime($alertsPath) : time();
?>
<?php if (empty($kapeLocalAssetsOnly)): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600;1,700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.14.0/build/css/alertify.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.14.0/build/css/themes/default.min.css">
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/style.css') . '?v=' . $styleVersion) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/app-loading.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/app-loading.css')) ?>">
<script src="<?= htmlspecialchars(kape_asset_url('scripts/app-loading.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/app-loading.js')) ?>" data-logo="<?= htmlspecialchars(kape_asset_url('img/NEWLOGO_WHITE.png')) ?>" data-icon="<?= htmlspecialchars(kape_asset_url('img/CUP_WHITE.png')) ?>"></script>
<link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/viewport-workspace.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/viewport-workspace.css')) ?>">
<script src="<?= htmlspecialchars(kape_asset_url('scripts/viewport-workspace.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/viewport-workspace.js')) ?>" defer></script>
<?php if (empty($kapeLocalAssetsOnly)): ?>
<script src="https://cdn.jsdelivr.net/npm/alertifyjs@1.14.0/build/alertify.min.js" defer></script>
<?php endif; ?>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/alerts.js') . '?v=' . $alertsVersion) ?>" defer></script>

<link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/user-guide.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/user-guide.css')) ?>">
