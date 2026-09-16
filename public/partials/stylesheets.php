<?php
require_once __DIR__ . '/assets.php';

$stylePath = dirname(__DIR__) . '/css/style.css';
$styleVersion = is_file($stylePath) ? filemtime($stylePath) : time();
$alertsPath = dirname(__DIR__) . '/scripts/alerts.js';
$alertsVersion = is_file($alertsPath) ? filemtime($alertsPath) : time();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600;1,700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.14.0/build/css/alertify.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.14.0/build/css/themes/default.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/style.css') . '?v=' . $styleVersion) ?>">
<script src="https://cdn.jsdelivr.net/npm/alertifyjs@1.14.0/build/alertify.min.js" defer></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/alerts.js') . '?v=' . $alertsVersion) ?>" defer></script>
