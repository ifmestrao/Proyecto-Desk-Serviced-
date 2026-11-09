<?php
require_once __DIR__ . '/../config/env.php';

function get_telegram_config($pdo = null, $tenant_id = null) {
    if (!$pdo) {
        require_once __DIR__ . '/../config/db.php';
    }
    
    try {
        if ($tenant_id) {
            $stmt = $pdo->prepare("SELECT setting_value FROM integrations WHERE provider = 'telegram' AND setting_key = 'bot_token' AND tenant_id = ? AND is_active = 1");
            $stmt->execute([$tenant_id]);
            $token = $stmt->fetchColumn();
            if ($token) return trim($token);
        } else {
            // Si no hay tenant_id, buscar cualquier bot activo (útil para el webhook inicial)
            $stmt = $pdo->prepare("SELECT setting_value FROM integrations WHERE provider = 'telegram' AND setting_key = 'bot_token' AND is_active = 1 LIMIT 1");
            $stmt->execute();
            $token = $stmt->fetchColumn();
            if ($token) return trim($token);
        }
    } catch (Exception $e) {
        error_log("Error fetching Telegram config: " . $e->getMessage());
    }

    return trim(app_env('TELEGRAM_TOKEN'));
}

function telegram_send_message($chat_id, $message, $pdo = null, $ticket_id = null, $tenant_id = null) {
    $token = get_telegram_config($pdo, $tenant_id);
    if (empty($token)) {
        error_log("Telegram Error: No bot token configured.");
        return false;
    }
    $url = "https://api.telegram.org/bot$token/sendMessage";
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        $debug_msg = "Telegram API Error ($http_code): " . $result . " | Curl Error: " . $error . " | URL: $url | Data: " . json_encode($data);
        file_put_contents(__DIR__ . '/../telegram_api_debug.log', "[" . date('Y-m-d H:i:s') . "] " . $debug_msg . "\n", FILE_APPEND);
        return false;
    }

    if ($pdo) {
        try {
            // Asegurar que el tenant_id no sea nulo, usar 1 por defecto si no se provee
            $effective_tenant_id = $tenant_id ?: 1;
            $stmt = $pdo->prepare("INSERT INTO telegram_logs (tenant_id, chat_id, direction, message, ticket_id) VALUES (?, ?, 'out', ?, ?)");
            $stmt->execute([$effective_tenant_id, $chat_id, $message, $ticket_id]);
        } catch (Exception $e) {
            error_log("Telegram Logging Error: " . $e->getMessage());
        }
    }
    
    return json_decode($result, true);
}

function telegram_send_keyboard($chat_id, $message, $keyboard, $pdo = null, $tenant_id = null) {
    $token = get_telegram_config($pdo, $tenant_id);
    if (empty($token)) return false;
    $url = "https://api.telegram.org/bot$token/sendMessage";
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'reply_markup' => is_string($keyboard) ? $keyboard : json_encode($keyboard),
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Telegram Keyboard Error ($http_code): " . $result);
        return false;
    }

    if ($pdo) {
        try {
            $effective_tenant_id = $tenant_id ?: 1;
            $stmt = $pdo->prepare("INSERT INTO telegram_logs (tenant_id, chat_id, direction, message) VALUES (?, ?, 'out', ?)");
            $stmt->execute([$effective_tenant_id, $chat_id, $message]);
        } catch (Exception $e) {}
    }
    
    return json_decode($result, true);
}

function telegram_get_file_url($file_id, $pdo = null, $tenant_id = null) {
    $token = get_telegram_config($pdo, $tenant_id);
    if (empty($token)) return false;
    $url = "https://api.telegram.org/bot$token/getFile?file_id=$file_id";
    $res = @file_get_contents($url);
    if ($res) {
        $json = json_decode($res, true);
        if ($json['ok']) {
            $path = $json['result']['file_path'];
            return "https://api.telegram.org/file/bot$token/$path";
        }
    }
    return false;
}

/**
 * Notifica al usuario vía Telegram sobre cambios en su ticket
 */
function telegram_notify_ticket_update($pdo, $ticket_id, $message, $type = 'info') {
    // Obtener datos del ticket y tenant_id
    $stmt = $pdo->prepare("SELECT t.*, u.name as user_name FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();
    if (!$ticket) return false;

    $tenant_id = $ticket['tenant_id'];
    $display_id = $ticket['local_id'] ?: $ticket['id'];
    
    // Obtener chat_id vinculado al usuario si existe
    $stmtS = $pdo->prepare("SELECT chat_id FROM telegram_sessions WHERE tenant_id = ? AND user_id = ? AND is_verified = 1 LIMIT 1");
    $stmtS->execute([$tenant_id, $ticket['user_id']]);
    $chat_id = $stmtS->fetchColumn();

    if (!$chat_id) return false;

    $emoji = ($type === 'success') ? '✅' : ($type === 'warning' ? '⚠️' : '🔔');
    $full_msg = "$emoji <b>Actualización de Ticket #$display_id</b>\n\n";
    $full_msg .= "Empresa: " . htmlspecialchars($_SESSION['tenant_name'] ?? 'Desk Service') . "\n";
    $full_msg .= "Título: " . htmlspecialchars($ticket['title']) . "\n";
    $full_msg .= "Mensaje: " . htmlspecialchars($message);

    return telegram_send_message($chat_id, $full_msg, $pdo, $ticket_id, $tenant_id);
}

function create_ticket_from_telegram($chat_id, $data, $from_name, $pdo, $tenant_id, $source = 'telegram') {
    if (!$tenant_id) $tenant_id = 1;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'autoservicio@deskservice.com' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $autoservice_user = $stmt->fetch();
    
    if (!$autoservice_user) {
        $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, is_verified) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$tenant_id, 'Autoservicio', 'autoservicio@deskservice.com', password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT), 'user', 1]);
        $creator_id = $pdo->lastInsertId();
    } else {
        $creator_id = $autoservice_user['id'];
    }

    $solicitor_email = trim($data['email']);
    $solicitor_name = $data['full_name'];
    
    // Solo limpiar telegram_id si es de telegram
    if ($source === 'telegram') {
        $pdo->prepare("UPDATE users SET telegram_id = NULL WHERE telegram_id = ? AND tenant_id = ?")->execute([$chat_id, $tenant_id]);
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
    $stmt->execute([$solicitor_email, $tenant_id]);
    $solicitor = $stmt->fetch();
    
    if (!$solicitor) {
        $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, is_verified, telegram_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tenant_id, $solicitor_name, $solicitor_email, password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT), 'user', 1, ($source === 'telegram' ? $chat_id : null)]);
        $solicitor_id = $pdo->lastInsertId();
    } else {
        $solicitor_id = $solicitor['id'];
        if ($source === 'telegram') {
            $pdo->prepare("UPDATE users SET telegram_id = ? WHERE id = ? AND tenant_id = ?")
                ->execute([$chat_id, $solicitor_id, $tenant_id]);
        }
    }

    // Obtener la primera categoría disponible para este tenant
    $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE tenant_id = ? LIMIT 1");
    $stmtCat->execute([$tenant_id]);
    $category_id = $stmtCat->fetchColumn() ?: 1;

    $title = $data['title'];
    $priority = $data['priority'];
    $location = $data['area'] ?? ucfirst($source);
    $formatted_description = "Creado desde " . ucfirst($source) . " Por: " . $data['full_name'] . "\n" . $data['description'];

    // Obtener configuración de SLA para la categoría
    $stmtSLA = $pdo->prepare("SELECT * FROM sla_config WHERE category_id = ? AND tenant_id = ?");
    $stmtSLA->execute([$category_id, $tenant_id]);
    $sla = $stmtSLA->fetch();

    $val = $sla ? (int)$sla['resolution_time_hours'] : 48;
    $unit = $sla ? $sla['resolution_unit'] : 'hours';
    $hours = ($unit == 'days') ? ($val * 24) : $val;

    // Aplicar multiplicadores por prioridad
    if ($priority == 'critical') {
        $hours = max(1, round($hours * 0.25));
    } elseif ($priority == 'high') {
        $hours = max(2, round($hours * 0.5));
    } elseif ($priority == 'low') {
        $hours = round($hours * 1.5);
    }

    $due_date = date('Y-m-d H:i:s', strtotime("+$hours hours"));

    try {
        // Obtener el siguiente local_id para este tenant
        $st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM tickets WHERE tenant_id = ?");
        $st_local->execute([$tenant_id]);
        $next_local_id = (int)$st_local->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO tickets (tenant_id, local_id, user_id, category_id, title, description, priority, due_date, location, status, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)");
        $stmt->execute([$tenant_id, $next_local_id, $creator_id, $category_id, $title, $formatted_description, $priority, $due_date, $location, $source]);
        $ticket_id = $pdo->lastInsertId();

        $pdo->prepare("INSERT IGNORE INTO ticket_solicitors (ticket_id, user_id) VALUES (?, ?)")->execute([$ticket_id, $solicitor_id]);

        $stmt = $pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$ticket_id, $creator_id, $formatted_description]);
        $message_id = $pdo->lastInsertId();

        if (isset($data['attachments'])) {
            foreach ($data['attachments'] as $att) {
                $pdo->prepare("INSERT INTO attachments (ticket_id, message_id, user_id, file_name, file_path, mime_type) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$ticket_id, $message_id, $creator_id, $att['name'], $att['path'], $att['mime']]);
            }
        }

        // Notificaciones con local_id
        try {
            notify_add($pdo, (int)$creator_id, 'ticket_created', "Ticket #$next_local_id creado", $title, "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id);
            require_once __DIR__ . '/integrations_api.php';
            send_teams_notification($ticket_id, $title, 'open', $priority, $next_local_id);
            send_telegram_notification($ticket_id, $title, 'open', $next_local_id);
        } catch (Throwable $e) {}

        return $ticket_id;
    } catch (Throwable $e) {
        error_log("Error creating ticket from $source: " . $e->getMessage());
        return false;
    }
}

function get_telegram_ai_response($query, $pdo, $tenant_id = 1, $channel = 'telegram') {
    try {
        require_once __DIR__ . '/ai_helper.php';
        $tenant_id = (int)$tenant_id;
        $result = call_ai_agent([
            'mode'        => 'query',
            'query'       => mb_substr((string)$query, 0, 1200),
            'tenant_name' => ai_tenant_name($pdo, $tenant_id),
            'kb'          => fetch_kb_for_tenant($pdo, $tenant_id),   // SOLO la KB de este tenant
            'categories'  => fetch_categories_for_tenant($pdo, $tenant_id),
        ]);
        if (($result['status'] ?? '') === 'success' && !empty($result['reply'])) {
            $reply = (string)$result['reply'];
            if ($channel === 'whatsapp') {
                return preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $reply);          // negrita de WhatsApp
            }
            // Telegram usa parse_mode HTML: escapar y convertir **negrita**
            $safe = htmlspecialchars($reply, ENT_QUOTES, 'UTF-8');
            return preg_replace('/\*\*(.*?)\*\*/s', '<b>$1</b>', $safe);
        }
    } catch (Throwable $e) {
        error_log('get_telegram_ai_response: ' . $e->getMessage());
    }
    return "No pude encontrar una respuesta en mi base de conocimientos. ¿Deseas que te ayude a crear un ticket de soporte?";
}
