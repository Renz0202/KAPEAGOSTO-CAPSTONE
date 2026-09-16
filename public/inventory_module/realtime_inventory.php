<?php
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_admin();

$pdo = db();

function formatQty($num) {
    return ($num == floor($num)) ? (int)$num : rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}

function normalizeCupSize($size) {
  if ($size === null) return null;
  $s = trim(strtolower((string)$size));
  if ($s === '' || $s === '-') return null;
  $s = preg_replace('/\s+/', ' ', $s);
  if (preg_match('/12\s*oz.*hot|hot\s*12\s*oz|12\s*hot|12oz\s*hot/', $s)) return '12oz Hot';
  if (preg_match('/^(16\s*oz|16oz)\b/', $s)) return '16oz';
  if (preg_match('/^(22\s*oz|22oz)\b/', $s)) return '22oz';
  return ucwords(trim((string)$size));
}

function getItemType($name) {
    $n = strtolower($name);
    if (strpos($n, 'straw') !== false) return 'Straw';
    if (strpos($n, 'cup') !== false) return 'Cup';
    if (strpos($n, 'lid') !== false) return 'Lid';
    return 'Others';
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Load current inventory (optionally filtered)
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE name LIKE ? OR cup_size LIKE ? ORDER BY name ASC, cup_size ASC, id DESC");
    $kw = "%" . $search . "%";
    $stmt->execute([$kw, $kw]);
    $inventory = $stmt->fetchAll();
} else {
    $inventory = $pdo->query("SELECT * FROM inventory ORDER BY name ASC, cup_size ASC, id DESC")->fetchAll();
}

// Group and sort inventory similar to inventory.php
$grouped_inventory = ['Straw'=>[], 'Cup'=>[], 'Lid'=>[], 'Others'=>[]];
foreach ($inventory as $inv) {
    $type = getItemType($inv['name']);
    $grouped_inventory[$type][] = $inv;
}
foreach ($grouped_inventory as &$items) {
    usort($items, function($a, $b) {
        $cmp = strcmp(strtolower($a['name']), strtolower($b['name']));
        if ($cmp === 0) {
            return strcmp((string)$a['cup_size'], (string)$b['cup_size']);
        }
        return $cmp;
    });
}
unset($items);
?>
<h2>📋 Current Inventory</h2>
<table>
  <thead>
    <tr>
      <th>#</th><th>Name</th><th>Cup Size</th><th>Qty</th><th>Unit</th><th>Critical</th><th>Date & Time</th>
    </tr>
  </thead>
  <tbody>
  <?php
    $rownum = 1;
    foreach ($grouped_inventory as $type => $items):
      if (count($items) > 0): ?>
        <tr style="background:#f5f5f5; font-weight:bold;">
          <td colspan="7"><?= htmlspecialchars($type) ?></td>
        </tr>
        <?php foreach($items as $inv):
          $is_low = ($inv['critical_level'] !== null && $inv['critical_level'] > 0 && $inv['qty'] <= $inv['critical_level']);
        ?>
          <tr class="<?= $is_low ? 'low-stock' : '' ?>" style="<?= $is_low ? 'background:#ffdddd;' : '' ?>">
            <td><?= $rownum++ ?></td>
            <td><?= htmlspecialchars($inv['name']) ?></td>
            <td>
              <?php
                if (stripos($inv['name'], 'straw') !== false) {
                  echo '-';
                } else {
                  $cs = isset($inv['cup_size']) ? normalizeCupSize($inv['cup_size']) : null;
                  echo htmlspecialchars($cs ?? '-');
                }
              ?>
            </td>
            <td><?= formatQty($inv['qty']) ?></td>
            <td><?= htmlspecialchars($inv['unit']) ?></td>
            <td><?= $inv['critical_level'] !== null ? formatQty($inv['critical_level']) : 'N/A' ?></td>
            <td><?= $inv['date_added'] ? date('F d, Y h:i A', strtotime($inv['date_added'])) : '-' ?></td>
          </tr>
        <?php endforeach; endif; endforeach; ?>
  </tbody>
</table>
