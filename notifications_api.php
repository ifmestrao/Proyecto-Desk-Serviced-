<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unreadCount' => 0, 'items' => []]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 1);
$action = $_GET['action'] ?? 'fetch';

if ($action === 'fetch') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    if ($limit < 1) $limit = 5;
    if ($limit > 20) $limit = 20;
    if ($offset < 0) $offset = 0;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND tenant_id = ? AND read_at IS NULL");
    $countStmt->execute([$user_id, $tenant_id]);
    $unread = (int)$countStmt->fetchColumn();

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND tenant_id = ?");
    $totalStmt->execute([$user_id, $tenant_id]);
    $total = (int)$totalStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id, type, title, body, url, created_at, read_at FROM notifications WHERE user_id = ? AND tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute([$user_id, $tenant_id]);
    $items = $stmt->fetchAll();

    $hasMore = ($offset + $limit) < $total;
    echo json_encode(['unreadCount' => $unread, 'items' => $items, 'hasMore' => $hasMore, 'offset' => $offset, 'limit' => $limit, 'total' => $total]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]);
    exit;
}

if (!csrf_check()) {
    echo json_encode(['ok' => false]);
    exit;
}

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND tenant_id = ? AND read_at IS NULL");
        $stmt->execute([$id, $user_id, $tenant_id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'mark_all') {
    $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND tenant_id = ? AND read_at IS NULL");
    $stmt->execute([$user_id, $tenant_id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ? AND tenant_id = ?");
        $stmt->execute([$id, $user_id, $tenant_id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete_all') {
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND tenant_id = ?");
    $stmt->execute([$user_id, $tenant_id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false]);
?>
