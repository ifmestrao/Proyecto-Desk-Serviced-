<?php
require_once 'config/db.php';
require_once 'config/env.php';
require_once 'includes/telegram_helper.php';
require_once 'includes/security.php';
require_once 'includes/mailer.php';
require_once 'includes/email_templates.php';

// Handle incoming updates from Telegram
$logFile = __DIR__ . '/webhook_hits.log';
$request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';

$content = file_get_contents("php://input");
if (empty($content)) {
    http_response_code(200);
    exit;
}

$update = json_decode($content, true);
if (!$update) {
    http_response_code(200);
    exit;
}

$message = $update['message'] ?? $update['callback_query']['message'] ?? null;
if (!$message) {
    http_response_code(200);
    exit;
}

$chat_id = $message['chat']['id'];
$from_name = $message['from']['first_name'] ?? 'Usuario';

// DETECCIÓN DINÁMICA DE TENANT
$tenant_id = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;

// Si no viene en la URL, intentamos identificar por el Bot ID si es un callback_query
if (!$tenant_id && isset($update['callback_query']['message']['from']['id'])) {
    $bot_id = $update['callback_query']['message']['from']['id'];
    // Buscar qué tenant tiene este bot (podemos buscar por el token que empieza con el ID)
    try {
        $stmt = $pdo->prepare("SELECT tenant_id FROM integrations WHERE provider = 'telegram' AND setting_key = 'bot_token' AND setting_value LIKE ? LIMIT 1");
        $stmt->execute([$bot_id . ":%"]);
        $tenant_id = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

if (!$tenant_id) {
    // Si aún no lo tenemos, buscamos la última sesión activa
    try {
        $stmt = $pdo->prepare("SELECT tenant_id FROM telegram_sessions WHERE chat_id = ? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$chat_id]);
        $tenant_id = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

// Si después de todo no hay tenant, usamos el 1 por defecto
if (!$tenant_id) {
    $tenant_id = 1;
}
$tenant_id = (int)$tenant_id;

// Autenticar que la petición viene realmente de Telegram para ESTE tenant
// (secret_token enviado en setWebhook; ver reregister_telegram.php)
require_telegram_signature($tenant_id);

// IMPORTANTE: Registrar el tenant detectado y la URI para depuración
file_put_contents($logFile, "\n--- NEW HIT [Tenant: $tenant_id] [URI: $request_uri] [" . date('Y-m-d H:i:s') . "] ---\n", FILE_APPEND);
file_put_contents($logFile, "Payload: " . $content . "\n", FILE_APPEND);

// Handle images/documents
$attachments = [];
if (isset($update['message']['photo'])) {
    $photo = end($update['message']['photo']);
    $attachments[] = ['type' => 'photo', 'file_id' => $photo['file_id']];
} elseif (isset($update['message']['document'])) {
    $doc = $update['message']['document'];
    $attachments[] = [
        'type' => 'document', 
        'file_id' => $doc['file_id'],
        'file_name' => $doc['file_name'] ?? 'document'
    ];
}

$text = $update['message']['text'] ?? '';
$callback_data = null;
if (isset($update['callback_query'])) {
    $callback_data = $update['callback_query']['data'];
    $text = $callback_data;
}

// Log incoming message en telegram_logs
try {
    $log_msg = $text ?: ($callback_data ?: '[Anexo]');
    $stmtLog = $pdo->prepare("INSERT INTO telegram_logs (tenant_id, source, chat_id, direction, message) VALUES (?, 'telegram', ?, 'in', ?)");
    $stmtLog->execute([$tenant_id, $chat_id, $log_msg]);
} catch (Exception $e) {
    file_put_contents($logFile, "DB Log Error: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Ejecutar lógica del bot
try {
    file_put_contents($logFile, "Calling handle_telegram_message for Tenant: $tenant_id\n", FILE_APPEND);
    handle_telegram_message($chat_id, $text, $from_name, $pdo, $attachments, $tenant_id, $callback_data);
    file_put_contents($logFile, "Finished handle_telegram_message\n", FILE_APPEND);
} catch (Throwable $e) {
    file_put_contents($logFile, "Error in handle_telegram_message: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents('telegram_error.log', date('Y-m-d H:i:s') . " - Fatal Error: " . $e->getMessage() . "\n", FILE_APPEND);
}
http_response_code(200);
exit;

function update_telegram_session($chat_id, $state, $data, $pdo, $tenant_id) {
    $pdo->prepare("UPDATE telegram_sessions SET state = ?, data = ?, updated_at = NOW() WHERE chat_id = ? AND tenant_id = ?")
        ->execute([$state, json_encode($data), $chat_id, $tenant_id]);
}

function handle_telegram_message($chat_id, $text, $from_name, $pdo, $attachments = [], $tenant_id = null, $callback_data = null) {
    if (!$tenant_id) $tenant_id = 1;
    $logFile = __DIR__ . '/webhook_hits.log';
    file_put_contents($logFile, "Entering handle_telegram_message - Chat: $chat_id, Tenant: $tenant_id, State: ", FILE_APPEND);
    
    // 1. Check session
    try {
        $stmt = $pdo->prepare("SELECT * FROM telegram_sessions WHERE chat_id = ? AND tenant_id = ?");
        $stmt->execute([$chat_id, $tenant_id]);
        $session = $stmt->fetch();
        
        if (!$session) {
            $state = 'START';
            $data = [];
            $pdo->prepare("INSERT INTO telegram_sessions (chat_id, tenant_id, source, state, data) VALUES (?, ?, 'telegram', ?, ?)")
                ->execute([$chat_id, $tenant_id, $state, json_encode($data)]);
        } else {
            $state = $session['state'];
            $data = json_decode($session['data'], true);
            // Asegurar que el source sea telegram
            if ($session['source'] !== 'telegram') {
                $pdo->prepare("UPDATE telegram_sessions SET source = 'telegram' WHERE chat_id = ? AND tenant_id = ?")
                    ->execute([$chat_id, $tenant_id]);
            }
        }
        file_put_contents($logFile, "$state\n", FILE_APPEND);
    } catch (Exception $e) {
        file_put_contents($logFile, "Session Error: " . $e->getMessage() . "\n", FILE_APPEND);
        return;
    }

    // Global Commands
    if (strtolower(trim($text)) == '/start' || strtolower(trim($text)) == 'hola' || strtolower(trim($text)) == 'menu' || strtolower(trim($text)) == 'menú') {
        $state = 'START';
        $data = [];
    }

    file_put_contents($logFile, "Handling state: $state with text: $text\n", FILE_APPEND);

    try {
        // Handle Callback Queries (Buttons)
        if ($callback_data) {
            if ($callback_data === 'create_ticket') {
                telegram_send_message($chat_id, "Perfecto, vamos a crear un ticket. Por favor, dime tu <b>nombre completo</b>:", $pdo, null, $tenant_id);
                update_telegram_session($chat_id, 'WAIT_NAME', [], $pdo, $tenant_id);
                return;
            }
            if ($callback_data === 'agent_chat') {
                telegram_send_message($chat_id, "Por favor, dime tu <b>nombre completo</b> para que un agente te atienda:", $pdo, null, $tenant_id);
                update_telegram_session($chat_id, 'WAIT_NAME_AGENT', [], $pdo, $tenant_id);
                return;
            }
            if ($callback_data === 'start') {
                $state = 'START';
                $text = '/start';
            }
        }

        // Si estamos en modo CHAT_SUPPORT, todo lo que escriba va a los logs y se notifica
        if ($state === 'CHAT_SUPPORT' && !empty($text) && $text !== '/start') {
            file_put_contents($logFile, "Chat Support message from $chat_id: $text\n", FILE_APPEND);
            
            // Actualizar timestamp para que el agente sepa que el usuario está activo
            $pdo->prepare("UPDATE telegram_sessions SET updated_at = NOW() WHERE chat_id = ? AND tenant_id = ?")
                ->execute([$chat_id, $tenant_id]);
            return;
        }

        switch ($state) {
            case 'START':
                if (!empty(trim($text)) && strtolower(trim($text)) !== '/start') {
                    // Chatbot mode: Search Knowledge Base first
                    $ai_response = get_telegram_ai_response($text, $pdo, $tenant_id);
                    
                    $msg = "🤖 <b>Asistente Virtual</b>:\n\n" . $ai_response . "\n\n¿Esto resolvió tu duda? Si no, puedo ayudarte a crear un ticket o transferirte con un agente.";
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => '🎫 Crear Ticket', 'callback_data' => 'create_ticket']],
                            [['text' => '👤 Hablar con un Agente', 'callback_data' => 'agent_chat']],
                            [['text' => '❓ Otra pregunta', 'callback_data' => 'start']]
                        ]
                    ];
                    telegram_send_keyboard($chat_id, $msg, $keyboard, $pdo, $tenant_id);
                } else {
                    // Welcome message
                    $msg = "¡Hola " . htmlspecialchars($from_name) . "! Bienvenido al asistente de Desk Service.\n\nEscribe tu duda para buscar en nuestra base de conocimientos o usa los botones de abajo.";
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => '🎫 Crear Ticket', 'callback_data' => 'create_ticket']],
                            [['text' => '👤 Hablar con un Agente', 'callback_data' => 'agent_chat']]
                        ]
                    ];
                    telegram_send_keyboard($chat_id, $msg, $keyboard, $pdo, $tenant_id);
                }
                break;

            case 'WAIT_NAME_AGENT':
                $data['agent_chat_name'] = $text;
                telegram_send_message($chat_id, "🔔 <b>Gracias, " . htmlspecialchars($text) . "</b>. Solicitud enviada. Un agente se unirá al chat en breve. Puedes escribir tu duda mientras tanto.", $pdo, null, $tenant_id);
                update_telegram_session($chat_id, 'CHAT_SUPPORT', $data, $pdo, $tenant_id);
                
                // Notificar a la plataforma
                try {
                    $staffStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'tech') AND tenant_id = ?");
                    $staffStmt->execute([$tenant_id]);
                    $staff = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($staff as $sid) {
                        notify_add($pdo, (int)$sid, 'chat_request', "Nueva solicitud de chat (Telegram)", "Usuario: " . htmlspecialchars($text) . " está esperando un agente.", "multicanal.php", 'chat', $chat_id, $tenant_id);
                    }
                } catch (Exception $e) {}
                break;

            case 'WAIT_NAME':
                $data['full_name'] = $text;
                telegram_send_message($chat_id, "Gracias, <b>" . htmlspecialchars($data['full_name']) . "</b>. Ahora, por favor dime tu <b>correo electrónico</b>:", $pdo, null, $tenant_id);
                update_telegram_session($chat_id, 'WAIT_EMAIL', $data, $pdo, $tenant_id);
                break;

            case 'WAIT_EMAIL':
                if (filter_var(trim($text), FILTER_VALIDATE_EMAIL)) {
                    $data['email'] = trim($text);
                    telegram_send_message($chat_id, "Entendido. Ahora describe brevemente la incidencia o problema que presentas:", $pdo, null, $tenant_id);
                    update_telegram_session($chat_id, 'WAIT_DESC', $data, $pdo, $tenant_id);
                } else {
                    telegram_send_message($chat_id, "El correo no parece válido. Por favor, verifícalo e ingrésalo de nuevo:", $pdo, null, $tenant_id);
                }
                break;

            case 'WAIT_DESC':
                $data['description'] = $text;
                telegram_send_message($chat_id, "¿A qué área perteneces? (Ej: Contabilidad, Ventas, Sistemas)", $pdo, null, $tenant_id);
                update_telegram_session($chat_id, 'WAIT_AREA', $data, $pdo, $tenant_id);
                break;

            case 'WAIT_AREA':
                $data['area'] = $text;
                $keyboard = [
                    'keyboard' => [['1. Baja', '2. Media'], ['3. Alta', '4. Crítica']],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true
                ];
                telegram_send_keyboard($chat_id, "Selecciona la prioridad:", $keyboard, $pdo, $tenant_id);
                update_telegram_session($chat_id, 'WAIT_PRIORITY', $data, $pdo, $tenant_id);
                break;

            case 'WAIT_PRIORITY':
                $priorities = ['1' => 'low', '2' => 'medium', '3' => 'high', '4' => 'critical'];
                $clean_text = preg_replace('/[^0-9]/', '', $text);
                
                if (isset($priorities[$clean_text])) {
                    $data['priority'] = $priorities[$clean_text];
                    telegram_send_message($chat_id, "Si tienes algún anexo (imágenes o documentos), envíalos ahora. Si no, escribe <b>'listo'</b>.", $pdo, null, $tenant_id);
                    update_telegram_session($chat_id, 'WAIT_ATTACHMENTS', $data, $pdo, $tenant_id);
                } else {
                    telegram_send_message($chat_id, "Por favor, selecciona una prioridad válida (1-4).", $pdo, null, $tenant_id);
                }
                break;

            case 'WAIT_ATTACHMENTS':
                if (!empty($attachments)) {
                    // ... (keep the same logic but ensure HTML)
                    foreach ($attachments as $att) {
                        $url = telegram_get_file_url($att['file_id'], $pdo, $tenant_id);
                        if ($url) {
                            $file_content = @file_get_contents($url);
                            if ($file_content) {
                                $path_parts = pathinfo($url);
                                $ext = strtolower($path_parts['extension'] ?? (($att['type'] == 'photo') ? 'jpg' : 'bin'));
                                if (!in_array($ext, allowed_upload_extensions(), true)) { continue; }
                                $original_name = $att['file_name'] ?? ('Telegram_' . $att['type'] . '_' . date('Ymd_His') . '.' . $ext);
                                $filename = 'tel_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                                $path = 'uploads/' . $filename;
                                if (!is_dir('uploads/')) mkdir('uploads/', 0777, true);
                                file_put_contents($path, $file_content);
                                
                                $data['attachments'][] = [
                                    'path' => $path,
                                    'name' => $original_name,
                                    'mime' => ($att['type'] == 'photo') ? 'image/jpeg' : 'application/octet-stream'
                                ];
                            }
                        }
                    }
                    update_telegram_session($chat_id, 'WAIT_ATTACHMENTS', $data, $pdo, $tenant_id);
                    telegram_send_message($chat_id, "✅ Recibido. ¿Algo más o escribe <b>'listo'</b>?", $pdo, null, $tenant_id);
                } elseif (strtolower(trim($text)) == 'listo') {
                    $data['title'] = substr($data['description'], 0, 50) . (strlen($data['description']) > 50 ? '...' : '');
                    $ticket_id = create_ticket_from_telegram($chat_id, $data, $from_name, $pdo, $tenant_id);
                    
                    if ($ticket_id) {
                        telegram_send_message($chat_id, "✅ ¡Ticket <b>#$ticket_id</b> creado! Un técnico lo revisará pronto.", $pdo, $ticket_id, $tenant_id);
                    } else {
                        telegram_send_message($chat_id, "❌ Error al crear el ticket. Intenta más tarde.", $pdo, null, $tenant_id);
                    }
                    update_telegram_session($chat_id, 'START', [], $pdo, $tenant_id);
                } else {
                    telegram_send_message($chat_id, "Envía un archivo o escribe <b>'listo'</b>.", $pdo, null, $tenant_id);
                }
                break;

            default:
                update_telegram_session($chat_id, 'START', [], $pdo, $tenant_id);
                break;
        }
    } catch (Throwable $e) {
        file_put_contents('telegram_error.log', date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n", FILE_APPEND);
        telegram_send_message($chat_id, "Lo siento, ocurrió un error interno. Por favor, intenta de nuevo con /start", $pdo, null, $tenant_id);
        update_telegram_session($chat_id, 'START', [], $pdo, $tenant_id);
    }
}
?>