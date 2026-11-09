<?php
require_once 'config/session_setup.php';
require_once 'config/middleware_tenant.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','supervisor','tech'], true)) {
    echo json_encode([]);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$ticket_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $tenant_id = get_tenant_id();
    $params = [];
    $sql = "SELECT a.id, a.serial_number, a.brand, a.model, u.name AS owner_name
            FROM assets a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tenant_id = ? AND (a.serial_number LIKE ? OR a.brand LIKE ? OR a.model LIKE ?)";
    $term = "%$q%";
    $params = [$tenant_id, $term, $term, $term];
    if ($ticket_user_id > 0) {
        $sql .= " AND (a.user_id = ? OR a.user_id IS NULL)";
        $params[] = $ticket_user_id;
    }
    $sql .= " ORDER BY a.created_at DESC LIMIT 10";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
} catch (Throwable $e) {
    echo json_encode([]);
}
?>
