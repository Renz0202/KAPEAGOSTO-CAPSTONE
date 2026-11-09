<?php
// Keep the original refresh URL aligned with Current Inventory and its filters.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$_GET['ajax_inventory'] = '1';
require __DIR__ . '/inventory.php';
