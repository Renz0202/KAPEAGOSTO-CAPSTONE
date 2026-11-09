<?php
/** Read-only customer history queries and compact presentation. */
require_once __DIR__ . '/management_lists.php';
require_once __DIR__ . '/order_utils.php';
function customer_history_rows(PDO $pdo, string $search): array {
    $placeholderNames = "('walk-in', 'walk in', 'walkin', 'guest', '--')";
    $sql = "
        SELECT c.id, c.customer_code, c.name, c.contact, c.email, c.created_at,
               COUNT(o.id) AS order_count,
               COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total ELSE 0 END), 0) AS lifetime_sales,
               MAX(o.created_at) AS last_order_at,
               0 AS is_unregistered
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id
        WHERE NULLIF(TRIM(c.name), '') IS NOT NULL
          AND LOWER(TRIM(c.name)) NOT IN $placeholderNames
    ";
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (c.name LIKE ? OR c.customer_code LIKE ? OR c.contact LIKE ? OR c.email LIKE ?)';
        $params = array_fill(0, 4, '%' . $search . '%');
    }
    $sql .= ' GROUP BY c.id, c.customer_code, c.name, c.contact, c.email, c.created_at';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unregisteredLink = "(o.customer_id IS NULL OR c.id IS NULL OR NULLIF(TRIM(c.name), '') IS NULL OR LOWER(TRIM(c.name)) IN $placeholderNames)";
    $namedOrderWhere = $unregisteredLink . "
        AND NULLIF(TRIM(o.customer_name), '') IS NOT NULL
        AND LOWER(TRIM(o.customer_name)) NOT IN $placeholderNames";
    $namedParams = [];
    if ($search !== '') {
        $namedOrderWhere .= " AND (o.customer_name LIKE ? OR COALESCE(o.order_code, '') LIKE ? OR CAST(o.id AS CHAR) LIKE ?)";
        $like = '%' . $search . '%';
        $namedParams = [$like, $like, $like];
    }
    $namedStmt = $pdo->prepare("SELECT TRIM(o.customer_name) AS name, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total ELSE 0 END), 0) AS lifetime_sales, MIN(o.created_at) AS created_at, MAX(o.created_at) AS last_order_at FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE " . $namedOrderWhere . ' GROUP BY TRIM(o.customer_name)');
    $namedStmt->execute($namedParams);
    foreach ($namedStmt->fetchAll(PDO::FETCH_ASSOC) as $namedOrderCustomer) {
        $customers[] = [
            'id' => null,
            'customer_code' => '--',
            'name' => $namedOrderCustomer['name'],
            'contact' => '',
            'email' => '',
            'created_at' => $namedOrderCustomer['created_at'],
            'order_count' => (int) $namedOrderCustomer['order_count'],
            'lifetime_sales' => (float) $namedOrderCustomer['lifetime_sales'],
            'last_order_at' => $namedOrderCustomer['last_order_at'],
            'is_unregistered' => 1,
        ];
    }

    usort($customers, function(array $a, array $b): int {
        $aTime = !empty($a['last_order_at']) ? strtotime($a['last_order_at']) : 0;
        $bTime = !empty($b['last_order_at']) ? strtotime($b['last_order_at']) : 0;
        return $bTime <=> $aTime;
    });
    return $customers;
}

function customer_walk_in_history(PDO $pdo, string $search): array {
    $placeholderNames = "('walk-in', 'walk in', 'walkin', 'guest', '--')";
    $unregisteredLink = "(o.customer_id IS NULL OR c.id IS NULL OR NULLIF(TRIM(c.name), '') IS NULL OR LOWER(TRIM(c.name)) IN $placeholderNames)";
    $where = $unregisteredLink . "
        AND (NULLIF(TRIM(o.customer_name), '') IS NULL OR LOWER(TRIM(o.customer_name)) IN $placeholderNames)
        AND LOWER(COALESCE(o.status, '')) NOT IN ('pending', 'void', 'voided')";
    $params = [];
    if ($search !== '') {
        $where .= " AND (COALESCE(NULLIF(TRIM(o.customer_name), ''), '--') LIKE ? OR COALESCE(o.order_code, '') LIKE ? OR CAST(o.id AS CHAR) LIKE ? OR LOWER(?) IN ('walk-in', 'walk in', 'walkin', 'guest', 'unregistered', '--'))";
        $like = '%' . $search . '%';
        $where = substr($where, 0, -1) . " OR REPLACE(o.service_type, '_', '-') LIKE ?)";
        $params = [$like, $like, $like, strtolower($search), '%' . str_replace(' ', '-', $search) . '%'];
    }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE " . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $rowsStmt = $pdo->prepare("SELECT o.id, o.order_code, o.customer_name, o.service_type, o.total, o.status, o.created_at FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE " . $where . ' ORDER BY o.created_at DESC, o.id DESC');
    $rowsStmt->execute($params);
    return ['total' => $total, 'orders' => $rowsStmt->fetchAll(PDO::FETCH_ASSOC)];
}

function customer_status_history(PDO $pdo, string $type, string $search): array {
    $statuses = $type === 'void' ? ['void', 'voided'] : ['pending'];
    $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
    $where = 'LOWER(o.status) IN (' . $statusPlaceholders . ')';
    $params = $statuses;
    if ($search !== '') {
        $where .= " AND (COALESCE(NULLIF(TRIM(o.customer_name), ''), c.name, '') LIKE ? OR COALESCE(o.order_code, '') LIKE ? OR CAST(o.id AS CHAR) LIKE ?)";
        $like = '%' . $search . '%';
        $where = substr($where, 0, -1) . " OR REPLACE(o.service_type, '_', '-') LIKE ?)";
        array_push($params, $like, $like, $like, '%' . str_replace(' ', '-', $search) . '%');
    }
    $stmt = $pdo->prepare("SELECT o.id, o.order_code, o.service_type, COALESCE(NULLIF(TRIM(o.customer_name), ''), NULLIF(TRIM(c.name), ''), '--') AS customer_name, o.total, o.status, o.created_at, o.voided_at, COALESCE(o.voided_at, o.created_at) AS history_at FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE " . $where . ' ORDER BY COALESCE(o.voided_at, o.created_at) DESC, o.id DESC');
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE ' . $where);
    $countStmt->execute($params);
    return ['total' => (int) $countStmt->fetchColumn(), 'orders' => $orders];
}

function customer_history_date($value): string {
    $timestamp = is_string($value) && $value !== '' ? strtotime($value) : false;
    return $timestamp === false ? 'Not recorded' : date('M d, Y g:i A', $timestamp);
}
function customer_history_money($value): string { return 'PHP ' . number_format((float)$value, 2); }
function customer_history_config(): array {
    return [
        'customers' => ['panel'=>'customerRecordsPanel','tab'=>'customerRecordsTab','body'=>'customerHistoryBody','page_param'=>'customers_page','title'=>'Customer Records','noun'=>'customers','hint'=>'Contact information is available in View Details.','empty'=>'No customer records found.'],
        'walkIn' => ['panel'=>'walkInRecordsPanel','tab'=>'walkInRecordsTab','body'=>'walkInHistoryBody','page_param'=>'walkin_page','title'=>'Dine-in / Take-out','noun'=>'orders','hint'=>'Orders without a customer name, labeled Dine-in or Take-out. Each order is tracked separately.','empty'=>'No unnamed Dine-in or Take-out orders found.'],
        'pending' => ['panel'=>'pendingRecordsPanel','tab'=>'pendingRecordsTab','body'=>'pendingHistoryBody','page_param'=>'pending_page','title'=>'Pending Records','noun'=>'orders','hint'=>'Pending orders from named customers and walk-ins.','empty'=>'No pending orders found.'],
        'void' => ['panel'=>'voidRecordsPanel','tab'=>'voidRecordsTab','body'=>'voidHistoryBody','page_param'=>'void_page','title'=>'Void Records','noun'=>'orders','hint'=>'Voided orders remain available here for review.','empty'=>'No void records found.'],
    ];
}
function customer_history_request_pages(array $query): array {
    $pages = [];
    foreach (customer_history_config() as $key => $config) {
        $value = $query[$config['page_param']] ?? '1';
        $pages[$key] = is_scalar($value) && preg_match('/^\d{1,7}$/', (string)$value) ? max(1, min(1000000, (int)$value)) : 1;
    }
    return $pages;
}
function customer_history_record_html(array $record, string $key): string {
    $e = 'management_list_escape';
    if ($key === 'customers') {
        $name = $record['name'] ?: '--'; $code = $record['customer_code'] ?: '--';
        $isNamed = !empty($record['is_unregistered']);
        $details = ['title'=>$name, 'code'=>$code, 'type'=>$isNamed ? 'Named order customer (unregistered)' : 'Registered customer', 'contact'=>$record['contact'] ?: 'Not recorded', 'email'=>$record['email'] ?: 'Not recorded', 'created'=>customer_history_date($record['created_at']), 'orders'=>number_format((int)$record['order_count']), 'sales'=>customer_history_money($record['lifetime_sales']), 'last'=>customer_history_date($record['last_order_at'])];
        $href = 'order_list.php?scope=all&search=' . urlencode($isNamed ? $name : $code);
        return '<tr><td data-label="Customer"><div class="management-item-name"><strong>'.$e($name).'</strong><small>'.$e($isNamed ? 'Unregistered customer' : $code).'</small></div></td><td data-label="Orders">'.number_format((int)$record['order_count']).'</td><td data-label="Completed sales">'.$e($details['sales']).'</td><td data-label="Last order">'.$e($details['last']).'</td><td data-label="Details">'.management_list_details($details, 'customerHistoryDetails', $name).'</td><td data-label="History"><a class="customer-history-link" href="'.$e($href).'" aria-label="View order history for '.$e($name).'">View Orders</a></td></tr>';
    }
    $code = trim((string)($record['order_code'] ?? ''));
    $code = $code !== '' ? $code : '#' . (int)$record['id'];
    $name = order_customer_display($record['customer_name'] ?? null, $record['service_type'] ?? null);
    $type = $record['service_type'] ?? null;
    if (in_array($type, ['dine_in', 'take_out'], true) && $name !== order_service_type_label($type)) $name .= ' · ' . order_service_type_label($type);
    $status = $key === 'void' ? 'Voided' : ucfirst((string)($record['status'] ?? ''));
    $created = customer_history_date($record['created_at'] ?? null);
    $voided = customer_history_date($record['voided_at'] ?? null);
    $date = $key === 'void' ? $voided : $created;
    $details = ['title'=>$code,'code'=>$code,'customer'=>$name,'status'=>$status,'total'=>customer_history_money($record['total']),'created'=>$created,'voided'=>$voided];
    $href = 'order_list.php?scope=all&search=' . urlencode(trim((string)($record['order_code'] ?? '')) ?: (string)$record['id']);
    $dateCell = $e($date);
    if ($key === 'void' && $voided === 'Not recorded') $dateCell .= '<small class="customer-history-date-note">Created: '.$e($created).'</small>';
    return '<tr><td data-label="Order"><div class="management-item-name"><strong>'.$e($code).'</strong><small>'.$e($name).'</small></div></td><td data-label="'.($key === 'void' ? 'Voided at' : 'Date &amp; time').'">'.$dateCell.'</td><td data-label="Status"><span class="customer-history-status">'.$e($status).'</span></td><td data-label="Total">'.$e($details['total']).'</td><td data-label="Details">'.management_list_details($details,'customerOrderDetails',$code).'</td><td data-label="History"><a class="customer-history-link" href="'.$e($href).'" aria-label="View order '.$e($code).'">View Order</a></td></tr>';
}
function customer_history_page(array $records, int $requested, string $key): array {
    $config = customer_history_config()[$key]; $total = count($records); $pages = max(1, (int)ceil($total / 10)); $page = max(1, min($pages, $requested));
    $rows = '';
    foreach (array_slice($records, ($page - 1) * 10, 10) as $record) $rows .= customer_history_record_html($record, $key);
    if ($rows === '') $rows = '<tr data-compact-empty><td colspan="6">'.management_list_escape($config['empty']).'</td></tr>';
    return ['page'=>$page,'pages'=>$pages,'total'=>$total,'html'=>$rows];
}
function customer_history_workspace(PDO $pdo, string $search, array $pages): array {
    $customers = customer_history_rows($pdo, $search);
    $walkIn = customer_walk_in_history($pdo, $search);
    $pending = customer_status_history($pdo, 'pending', $search);
    $void = customer_status_history($pdo, 'void', $search);
    $groups = [];
    foreach (['customers'=>$customers,'walkIn'=>$walkIn['orders'],'pending'=>$pending['orders'],'void'=>$void['orders']] as $key=>$records) $groups[$key] = customer_history_page($records, $pages[$key] ?? 1, $key);
    return ['groups'=>$groups,'orderTotal'=>array_sum(array_column($customers, 'order_count')) + $walkIn['total']];
}
