<?php
require_once 'config/session_setup.php';
require_once 'config/middleware_tenant.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'tech')) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $tenant_filter = tenant_where();
    $tenant_id = get_tenant_id();
    $stmt = $pdo->prepare("SELECT id, local_id, name, email FROM users WHERE tenant_id = ? AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT 10");
    $term = "%$q%";
    $stmt->execute([$tenant_id, $term, $term]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($users);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error de base de datos']);
}
?>
