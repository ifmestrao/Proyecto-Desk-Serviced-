<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $tenant_filter = tenant_filter('users');
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE role IN ('admin','supervisor','tech') AND $tenant_filter AND (name LIKE ? OR email LIKE ?) LIMIT 10");
    $term = "%$q%";
    $stmt->execute([$term, $term]);
    $techs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($techs);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error de base de datos']);
}
?>
