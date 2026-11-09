<?php
/**
 * chat_support_upload.php — el agente envía una imagen o documento al cliente desde el chat en vivo.
 * POST: chat_id, file, caption (opcional), csrf_token. Responde JSON.
 */
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/security.php';
require_once 'includes/whatsapp_helper.php';
require_once 'includes/telegram_helper.php';
require_once 'includes/chat_media.php';

header('Content-Type: application/json; charset=utf-8');

function cs_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cs_fail('Método no permitido', 405);
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'supervisor', 'tech'], true)) cs_fail('No autorizado', 403);
if (!csrf_check()) cs_fail('Token de seguridad inválido. Recarga la página.', 403);

$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$chat_id = trim((string)($_POST['chat_id'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
if ($tenant_id <= 0 || $chat_id === '') cs_fail('Datos incompletos.');

// El canal se toma de la base de datos, no del cliente
$st = $pdo->prepare("SELECT source FROM telegram_sessions WHERE chat_id = ? AND tenant_id = ?");
$st->execute([$chat_id, $tenant_id]);
$source = $st->fetchColumn();
if ($source === false) cs_fail('Chat no encontrado.', 404);
$source = ($source === 'whatsapp') ? 'whatsapp' : 'telegram';

// Archivo
$f = $_FILES['file'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) cs_fail('No se recibió el archivo (máx. 10 MB).');
if (!is_uploaded_file($f['tmp_name']) || $f['size'] > CM_MAX_BYTES) cs_fail('El archivo supera los 10 MB.');
$bin = (string)file_get_contents($f['tmp_name']);
$det = cm_detect_mime($bin, (string)($f['type'] ?? ''));
if (!$det) cs_fail('Tipo de archivo no permitido. Usa imágenes (JPG/PNG/WEBP), PDF, Word, Excel, PowerPoint, TXT o CSV.');
[$mime, $ext] = $det;
unset($bin);

$rel_dir = 'uploads/chat/' . $tenant_id;
$abs_dir = cm_ensure_dir($rel_dir, true);
if (!$abs_dir) cs_fail('No se pudo crear la carpeta de adjuntos.', 500);
$stored = bin2hex(random_bytes(10)) . '.' . $ext;
$abs_path = $abs_dir . '/' . $stored;
if (!move_uploaded_file($f['tmp_name'], $abs_path)) cs_fail('No se pudo guardar el archivo.', 500);
$display = cm_safe_display_name((string)$f['name'], $ext);

// Enviar al cliente
if ($source === 'whatsapp') {
    [$ok, $err] = wa_send_file($pdo, $tenant_id, $chat_id, $abs_path, $mime, $caption, $display);
} else {
    [$ok, $err] = telegram_send_file($pdo, $tenant_id, $chat_id, $abs_path, $mime, $caption, $display);
}
if (!$ok) {
    @unlink($abs_path);
    cs_fail($err ?: 'No se pudo enviar el archivo.', 502);
}

$kind = strpos($mime, 'image/') === 0 ? 'image' : 'file';
cm_log_chat($pdo, $tenant_id, $source, $chat_id, 'out', cm_chat_marker($kind, $rel_dir . '/' . $stored, $display, $caption));

echo json_encode(['success' => true]);