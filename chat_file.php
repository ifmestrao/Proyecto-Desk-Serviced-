<?php
/**
 * chat_file.php?p=uploads/chat/{tenant}/{archivo} — entrega adjuntos del chat solo a personal autenticado
 * de la misma organización. La carpeta uploads/chat bloquea el acceso directo por URL.
 */
require_once 'config/session_setup.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'supervisor', 'tech'], true)) {
    http_response_code(403);
    exit('No autorizado');
}
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$p = (string)($_GET['p'] ?? '');

if (!preg_match('#^uploads/chat/(\d+)/([A-Za-z0-9_.\-]+)$#', $p, $m) || (int)$m[1] !== $tenant_id) {
    http_response_code(404);
    exit('No encontrado');
}
$abs = __DIR__ . '/' . $p;
$real = realpath($abs);
$base = realpath(__DIR__ . '/uploads/chat/' . $tenant_id);
if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('No encontrado');
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
];
if (!isset($types[$ext])) {
    http_response_code(404);
    exit('No encontrado');
}
$inline = in_array($ext, ['jpg', 'png', 'webp', 'pdf'], true);

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
if ($ext !== 'pdf') {   // el visor de PDF del navegador no funciona bajo CSP sandbox
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($real) . '"');
readfile($real);