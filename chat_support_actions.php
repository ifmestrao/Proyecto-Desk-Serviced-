<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

function csa_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Solo personal de soporte de la organización
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'supervisor', 'tech'], true)) {
    csa_json(['success' => false, 'error' => 'No autorizado'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    csa_json(['success' => false, 'error' => 'Método no permitido'], 405);
}
if (!csrf_check()) {
    csa_json(['success' => false, 'error' => 'Token de seguridad inválido. Recarga la página.'], 403);
}

require_once 'includes/telegram_helper.php';

$chat_id = isset($_POST['chat_id']) ? trim((string)$_POST['chat_id']) : '';
$tenant_id = (int)$_SESSION['tenant_id'];

if ($chat_id === '') {
    csa_json(['success' => false, 'error' => 'Falta chat_id'], 400);
}

// El canal sale de la base de datos (no del navegador)
$stSrc = $pdo->prepare("SELECT source, data FROM telegram_sessions WHERE chat_id = ? AND tenant_id = ?");
$stSrc->execute([$chat_id, $tenant_id]);
$sess = $stSrc->fetch(PDO::FETCH_ASSOC);
if (!$sess) {
    csa_json(['success' => false, 'error' => 'Chat no encontrado'], 404);
}
$is_whatsapp = (($sess['source'] ?? '') === 'whatsapp');

if (isset($_POST['message'])) {
    $msg = trim((string)$_POST['message']);
    if ($msg === '') {
        csa_json(['success' => false, 'error' => 'Mensaje vacío'], 400);
    }
    if (mb_strlen($msg) > 3500) {
        csa_json(['success' => false, 'error' => 'El mensaje es demasiado largo.'], 400);
    }

    if ($is_whatsapp) {
        require_once 'includes/whatsapp_helper.php';
        $res = whatsapp_send_message($chat_id, $msg, $pdo, $tenant_id);
        $fail_msg = 'No se pudo enviar el mensaje por WhatsApp. Si pasaron más de 24 h desde el último mensaje del cliente, WhatsApp solo permite plantillas aprobadas. Revisa también el token en Integraciones.';
    } else {
        // Telegram: HTML con el texto escapado para evitar errores de parseo
        $res = telegram_send_message($chat_id, "💬 <b>Agente:</b> " . htmlspecialchars($msg), $pdo, null, $tenant_id);
        $fail_msg = 'No se pudo enviar el mensaje a Telegram. Verifique la configuración del bot.';
    }

    if ($res) {
        csa_json(['success' => true]);
    }
    error_log("Chat Support: fallo de envío a $chat_id (tenant $tenant_id, " . ($is_whatsapp ? 'whatsapp' : 'telegram') . ")");
    csa_json(['success' => false, 'error' => $fail_msg], 500);
}

if (isset($_POST['finish_chat'])) {
    $finish_msg = "✅ <b>El chat ha finalizado.</b> Gracias por contactarnos.";
    if ($is_whatsapp) {
        require_once 'includes/whatsapp_helper.php';
        whatsapp_send_message($chat_id, strip_tags($finish_msg), $pdo, $tenant_id);
    } else {
        telegram_send_message($chat_id, $finish_msg, $pdo, null, $tenant_id);
    }

    // Reiniciar la sesión del bot conservando la identidad ya conocida del cliente (evita volver a pedir datos)
    $old = json_decode((string)($sess['data'] ?? ''), true);
    $keep = is_array($old) ? ['profile' => $old['profile'] ?? [], 'profile_checked' => $old['profile_checked'] ?? 0] : [];
    $stmt = $pdo->prepare("UPDATE telegram_sessions SET state = 'START', data = ?, updated_at = NOW() WHERE chat_id = ? AND tenant_id = ?");
    $stmt->execute([json_encode($keep, JSON_UNESCAPED_UNICODE), $chat_id, $tenant_id]);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        csa_json(['success' => true, 'redirect' => 'multicanal.php']);
    }
    header("Location: multicanal.php");
    exit;
}

csa_json(['success' => false, 'error' => 'Acción no válida'], 400);