<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';

header('Content-Type: application/json');

// Solo personal de soporte (un cliente con sesión no debe poder leer chats)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'supervisor', 'tech'], true)) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$chat_id = isset($_GET['chat_id']) ? trim((string)$_GET['chat_id']) : '';
$tenant_id = (int)$_SESSION['tenant_id'];
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if ($chat_id === '') {
    echo json_encode(['error' => 'Falta chat_id']);
    exit;
}

try {
    // Historial completo si last_id es 0; solo los nuevos si ya hay mensajes cargados
    $stmt = $pdo->prepare("SELECT id, direction, message, created_at FROM telegram_logs
                           WHERE chat_id = ? AND tenant_id = ? AND id > ?
                           ORDER BY id ASC LIMIT 500");
    $stmt->execute([$chat_id, $tenant_id, $last_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtUser = $pdo->prepare("SELECT name FROM users WHERE telegram_id = ? AND tenant_id = ? LIMIT 1");
    $stmtUser->execute([$chat_id, $tenant_id]);
    $user_name = $stmtUser->fetchColumn();

    $stmtSession = $pdo->prepare("SELECT state FROM telegram_sessions WHERE chat_id = ? AND tenant_id = ?");
    $stmtSession->execute([$chat_id, $tenant_id]);
    $session_state = $stmtSession->fetchColumn();

    echo json_encode([
        'messages' => $messages,
        'user_name' => $user_name ?: null,
        'session_active' => ($session_state === 'CHAT_SUPPORT')
    ]);
} catch (Exception $e) {
    error_log('api_chat_messages: ' . $e->getMessage());
    echo json_encode(['error' => 'Error al cargar los mensajes']);
}