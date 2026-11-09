<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
header('Content-Type: application/json');
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '') { echo json_encode([]); exit; }
$tenant_id = get_tenant_id();
$stmt = $pdo->prepare("SELECT id, local_id, name, email FROM users WHERE tenant_id = ? AND email LIKE ? ORDER BY email LIMIT 10");
$stmt->execute([$tenant_id, '%'.$q.'%']);
echo json_encode($stmt->fetchAll());
