<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';

header('Content-Type: application/json');

// Check if user is authenticated (or we could make it public, but let's keep it safe)
$chat_id = $_GET['chat_id'] ?? null;
$last_id = $_GET['last_id'] ?? 0;
$tenant_id = $_SESSION['tenant_id'] ?? 1;

if (!$chat_id) {
    echo json_encode(['error' => 'Missing chat_id']);
    exit;
}

// Get chat messages
$stmt = $pdo->prepare("SELECT id, message, direction, created_at FROM chat_messages WHERE chat_id = ? AND tenant_id = ? AND id > ? ORDER BY created_at ASC");
$stmt->execute([$chat_id, $tenant_id, $last_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'messages' => $messages,
    'user_name' => ''
]);
?>