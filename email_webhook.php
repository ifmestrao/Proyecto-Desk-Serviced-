<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/email_templates.php';

// Solo aceptar peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Leer el JSON entrante
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

if (!$data) {
    // Si no es JSON, intentar capturar datos de formulario (form-data)
    $data = $_POST;
}

if (empty($data)) {
    http_response_code(400);
    exit('No data received');
}

// Extraer campos básicos (adaptado para que sea genérico)
// Estos nombres de campos pueden variar según si usas Zapier o Make.com
$from_email = $data['from_email'] ?? $data['sender'] ?? $data['From'] ?? '';
$from_name = $data['from_name'] ?? $data['sender_name'] ?? $data['FromName'] ?? $from_email;
$subject = $data['subject'] ?? $data['Subject'] ?? 'Sin Asunto';
$body = $data['body_plain'] ?? $data['body_text'] ?? $data['TextBody'] ?? $data['body_html'] ?? $data['HtmlBody'] ?? '';

if (empty($from_email)) {
    http_response_code(400);
    exit('Missing sender email');
}

// --- Lógica de creación del Ticket ---
$tenant_id = 1; // Default tenant for email webhook
// 1. Encontrar o crear usuario
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
$stmt->execute([$from_email, $tenant_id]);
$user = $stmt->fetch();

if (!$user) {
    $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, is_verified) VALUES (?, ?, ?, ?, 'user', 1)")
        ->execute([$tenant_id, $from_name, $from_email, password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT)]);
    $user_id = $pdo->lastInsertId();
} else {
    $user_id = $user['id'];
}

// 2. Categorización simple por palabras clave en asunto o cuerpo
$category_id = 1; // General por defecto
$text_to_scan = mb_strtolower($subject . " " . $body);

$cat_map = [
    'software' => ['software', 'programa', 'aplicacion', 'app', 'instalacion', 'licencia'],
    'hardware' => ['hardware', 'equipo', 'computador', 'teclado', 'mouse', 'monitor', 'pantalla', 'impresora', 'disco'],
    'red' => ['red', 'wifi', 'internet', 'conexion', 'vpn', 'router'],
    'seguridad' => ['seguridad', 'virus', 'password', 'contraseña', 'acceso', 'bloqueo']
];

foreach ($cat_map as $cat_name => $keywords) {
    foreach ($keywords as $kw) {
        if (strpos($text_to_scan, $kw) !== false) {
            $cat_stmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) LIKE ? AND tenant_id = ? LIMIT 1");
            $cat_stmt->execute(["%$cat_name%", $tenant_id]);
            $cat = $cat_stmt->fetch();
            if ($cat) {
                $category_id = $cat['id'];
                break 2;
            }
        }
    }
}

// 3. Crear el Ticket
$priority = 'medium'; // Por defecto

// Obtener configuración de SLA para la categoría
$stmtSLA = $pdo->prepare("SELECT * FROM sla_config WHERE category_id = ? AND tenant_id = ?");
$stmtSLA->execute([$category_id, $tenant_id]);
$sla = $stmtSLA->fetch();

$val = $sla ? (int)$sla['resolution_time_hours'] : 48;
$unit = $sla ? $sla['resolution_unit'] : 'hours';
$hours = ($unit == 'days') ? ($val * 24) : $val;

// El vencimiento se calcula desde el momento de la creación
$due_date = date('Y-m-d H:i:s', strtotime("+$hours hours"));

// Obtener el siguiente local_id para este tenant
$st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM tickets WHERE tenant_id = ?");
$st_local->execute([$tenant_id]);
$next_local_id = (int)$st_local->fetchColumn();

$pdo->prepare("INSERT INTO tickets (tenant_id, local_id, user_id, category_id, title, description, priority, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$tenant_id, $next_local_id, $user_id, $category_id, $subject, strip_tags($body), $priority, $due_date]);

$ticket_id = $pdo->lastInsertId();

// Registrar el mensaje inicial
$pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)")
    ->execute([$ticket_id, $user_id, strip_tags($body)]);

// 4. Notificar al usuario que su ticket fue creado
try {
    $subj = "Ticket #$next_local_id creado vía Email: $subject";
    $emailBody = tpl_ticket_created($from_name, $next_local_id, $subject, $priority, $due_date);
    @send_mail($from_email, $subj, $emailBody, null, [], $tenant_id);
} catch (Exception $e) {}

http_response_code(200);
echo json_encode(['status' => 'success', 'ticket_id' => $ticket_id, 'local_id' => $next_local_id]);
