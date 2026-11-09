<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/ai_helper.php';

// Configuración de ejecución infinita para modo "Daemon"
set_time_limit(0); 
ignore_user_abort(true);


$lockFile = __DIR__ . '/mail_to_ticket.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "⚠️ Ya hay una instancia de mail_to_ticket.php en ejecución. Saliendo.\n";
    exit(0);
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

echo "Iniciando servicio de Email-to-Ticket en modo automático...\n";

while (true) {
    // 1. Obtener todas las configuraciones IMAP activas por tenant
    $stmt = $pdo->query("SELECT tenant_id, setting_key, setting_value FROM integrations WHERE provider = 'email_imap' AND is_active = 1");
    $all_settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $all_settings[$row['tenant_id']][$row['setting_key']] = $row['setting_value'];
    }

    if (empty($all_settings)) {
        echo "[" . date('Y-m-d H:i:s') . "] No hay configuraciones IMAP activas. Reintentando en 60s...\n";
        sleep(60);
        continue;
    }

    foreach ($all_settings as $tenant_id => $settings) {
        if (empty($settings['host']) || empty($settings['user']) || empty($settings['pass'])) {
            echo "[" . date('Y-m-d H:i:s') . "] Tenant #$tenant_id: IMAP incompleto.\n";
            continue;
        }

        $host = $settings['host'];
        $port = $settings['port'] ?? '993';
        $user = $settings['user'];
        $pass = $settings['pass'];
        
        echo "[" . date('Y-m-d H:i:s') . "] Procesando Tenant #$tenant_id ($user)...\n";

        // Determinar el string de conexión según el host/puerto
        // Se agrega /novalidate-cert para evitar errores de certificados auto-firmados o SNI en Hostinger/Gmail
        $server = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}INBOX';

        // Abrir conexión IMAP
        $mbox = @imap_open($server, $user, $pass);

        if (!$mbox) {
            echo "Error de conexión IMAP para Tenant #$tenant_id: " . imap_last_error() . "\n";
            continue;
        }

        // Buscar correos no leídos
        $emails = imap_search($mbox, 'UNSEEN');

        if ($emails) {
            echo "Se encontraron " . count($emails) . " correos nuevos.\n";
            
            foreach ($emails as $email_number) {
                $overview = imap_fetch_overview($mbox, $email_number, 0);
                $header = imap_headerinfo($mbox, $email_number);
                $structure = imap_fetchstructure($mbox, $email_number);
                
                $from_email = $header->from[0]->mailbox . "@" . $header->from[0]->host;
                $from_name = isset($header->from[0]->personal) ? imap_utf8($header->from[0]->personal) : $from_email;
                $subject = imap_utf8($overview[0]->subject);
                
                // Obtener el cuerpo del correo
                $body = get_body($mbox, $email_number);
                
                // Procesar adjuntos
                $attachments = get_attachments($mbox, $email_number, $structure);
                
                echo "Procesando correo de $from_email: $subject\n";
                
                // Guardar como solicitud pendiente en lugar de ticket directo
                $request_id = save_email_request($pdo, $from_email, $from_name, $subject, $body, $attachments, $tenant_id);
                
                if ($request_id) {
                    echo "✅ Solicitud #$request_id guardada para revisión.\n";
                    // Marcar como leído en el servidor de correo
                    imap_setflag_full($mbox, $email_number, "\\Seen");

                    // ¿Es una respuesta a un caso que la IA ya contestó antes? -> escalar a técnico
                    $prior = find_recent_answered_request($pdo, $tenant_id, $from_email, $subject);
                    if ($prior) {
                        echo "🔁 Es una respuesta a la solicitud #{$prior['id']} (ya respondida por IA). Escalando a técnico...\n";
                        $escalated = escalate_email_to_ticket($pdo, $tenant_id, $request_id, $prior);
                        echo $escalated
                            ? "🎫 Ticket #{$escalated['local_id']} creado automáticamente por escalación.\n"
                            : "❌ No se pudo escalar la solicitud #$request_id a ticket.\n";
                    } else {
                        // Analizar el correo con IA y, si corresponde, responder automáticamente
                        process_email_with_ai($pdo, $request_id, $tenant_id, $from_email, $from_name, $subject, $body);
                    }
                } else {
                    echo "❌ Error al guardar solicitud para $from_email.\n";
                }
            }
        }
        imap_close($mbox);
    }
    
    // Esperar 1 minuto antes de la siguiente revisión
    echo "Dormido por 60 segundos...\n";
    sleep(60);
}

/**
 * Guarda el correo en la tabla de revisiones
 */
function save_email_request($pdo, $email, $name, $subject, $body, $attachments, $tenant_id) {
    try {
        // Serializar adjuntos si existen
        $att_json = !empty($attachments) ? json_encode($attachments) : null;
        
        $stmt = $pdo->prepare("INSERT INTO email_requests (tenant_id, from_email, from_name, subject, body, attachments, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$tenant_id, $email, $name, $subject, $body, $att_json]);
        
        $req_id = $pdo->lastInsertId();

        // Notificar a los técnicos de la plataforma
        try {
            // Notifications logic integrada directamente para evitar require_once fallido
            $staffStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'tech') AND tenant_id = ?");
            $staffStmt->execute([$tenant_id]);
            $staff = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($staff as $sid) {
                $pdo->prepare("INSERT INTO notifications (tenant_id, user_id, type, title, body, url, entity_type, entity_id) VALUES (?, ?, 'email_request', 'Nueva solicitud por Email', ?, 'multicanal.php', 'email', ?)")
                    ->execute([$tenant_id, (int)$sid, "De: $email - Asunto: $subject", $req_id]);
            }
        } catch (Exception $e) {}

        return $req_id;
    } catch (Exception $e) {
        error_log("Error saving email request: " . $e->getMessage());
        return false;
    }
}

/**
 * Analiza el correo entrante con la IA, guarda el borrador de respuesta y,
 * si la respuesta automática está habilitada para el tenant y la confianza
 * de la IA supera el umbral configurado, envía la respuesta de inmediato.
 * En caso contrario, deja el borrador listo para que un agente lo revise
 * y lo envíe manualmente desde el Centro Multicanal (multicanal.php).
 */
function process_email_with_ai($pdo, $request_id, $tenant_id, $from_email, $from_name, $subject, $body) {
    try {
        $ai = ai_analyze_email($pdo, $tenant_id, $from_name, $subject, $body);

        if (($ai['status'] ?? '') !== 'success') {
            echo "⚠️ IA no pudo analizar la solicitud #$request_id: " . ($ai['message'] ?? 'error desconocido') . "\n";
            return;
        }

        save_ai_email_draft($pdo, $request_id, $ai);
        echo "🤖 Borrador de respuesta IA generado para la solicitud #$request_id (confianza: " . ($ai['confidence'] ?? 0) . "%).\n";

        $autoreply = get_tenant_setting($pdo, $tenant_id, 'ai_email_autoreply', '0');
        $threshold = (int) get_tenant_setting($pdo, $tenant_id, 'ai_email_confidence_threshold', '60');

        if ($autoreply === '1' && (int)($ai['confidence'] ?? 0) >= $threshold) {
            $sent = send_ai_email_reply(
                $pdo, $request_id, $from_email, $from_name,
                $ai['reply_subject'], $ai['reply_body'], $ai['classification'], $tenant_id
            );
            echo $sent
                ? "📤 Respuesta automática enviada a $from_email para la solicitud #$request_id.\n"
                : "❌ No se pudo enviar la respuesta automática a $from_email.\n";
        }
    } catch (Throwable $e) {
        error_log("process_email_with_ai error (request #$request_id): " . $e->getMessage());
        echo "❌ Error al procesar IA para la solicitud #$request_id: " . $e->getMessage() . "\n";
    }
}

/**
 * Obtener el cuerpo del correo (texto plano, decodificado y sin el hilo citado)
 */
function get_body($mbox, $uid) {
    $structure = imap_fetchstructure($mbox, $uid);

    if (!isset($structure->parts)) {
        $raw = imap_fetchbody($mbox, $uid, 1);
        return clean_email_body(decode_mime_part($raw, $structure));
    }

    // 1. Preferir la parte de texto plano
    foreach ($structure->parts as $part_number => $part) {
        if ($part->type == 0 && strtolower($part->subtype) == 'plain') {
            $raw = imap_fetchbody($mbox, $uid, $part_number + 1);
            return clean_email_body(decode_mime_part($raw, $part));
        }
    }

    // 2. Si no hay texto plano, usar HTML y convertirlo a texto
    foreach ($structure->parts as $part_number => $part) {
        if ($part->type == 0 && strtolower($part->subtype) == 'html') {
            $raw = imap_fetchbody($mbox, $uid, $part_number + 1);
            $decoded = decode_mime_part($raw, $part);
            $decoded = preg_replace('/<(br|\/p|\/div|\/tr)[^>]*>/i', "\n", $decoded);
            return clean_email_body(trim(html_entity_decode(strip_tags($decoded), ENT_QUOTES, 'UTF-8')));
        }
    }

    return "";
}

/**
 * Decodifica un fragmento MIME (quoted-printable / base64) y lo convierte a UTF-8
 * según el charset declarado en la parte del correo. Sin esto, tildes y símbolos
 * quedan como "=C3=B3", "=E2=80=94", etc.
 */
function decode_mime_part($text, $part) {
    if (isset($part->encoding)) {
        if ($part->encoding == 3) { // BASE64
            $text = base64_decode($text);
        } elseif ($part->encoding == 4) { // QUOTED-PRINTABLE
            $text = quoted_printable_decode($text);
        }
    }

    $charset = null;
    if (isset($part->parameters)) {
        foreach ($part->parameters as $param) {
            if (strtolower($param->attribute) === 'charset') {
                $charset = $param->value;
            }
        }
    }

    if ($charset && strtoupper($charset) !== 'UTF-8' && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        if ($converted !== false) $text = $converted;
    }

    return $text;
}

/**
 * Recorta el hilo citado (respuestas anteriores) que Gmail/Outlook agregan
 * automáticamente al responder, para quedarnos solo con el mensaje nuevo.
 * Esto también evita que la IA "relea" siempre la misma respuesta anterior.
 */
function clean_email_body($body) {
    $patterns = [
        // "El vie, 28 ago 2026 a las 10:02, Desk Service (...) escribió:"
        '/\n\s*El\s.+?escribi[oó]:\s*\n/iu',
        // "On Fri, Aug 28, 2026 at 10:02 AM Desk Service wrote:"
        '/\n\s*On\s.+?wrote:\s*\n/iu',
        // Separador clásico de Outlook: "-----Mensaje original-----" / "-----Original Message-----"
        '/\n\s*-{2,}\s*(Mensaje original|Original Message)\s*-{2,}.*/isu',
    ];

    foreach ($patterns as $pattern) {
        $parts = preg_split($pattern, $body, 2);
        if ($parts !== null && count($parts) > 1) {
            $body = $parts[0];
        }
    }

    // Quitar líneas citadas que empiezan con ">"
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $lines = array_filter($lines, function ($line) {
        return strpos(ltrim($line), '>') !== 0;
    });

    return trim(implode("\n", $lines));
}

/**
 * Normaliza un asunto quitando prefijos de respuesta/reenvío (Re:, Fwd:, RV:, etc.)
 * para poder comparar si dos correos pertenecen a la misma conversación.
 */
function normalize_subject($subject) {
    $s = trim($subject ?? '');
    do {
        $prev = $s;
        $s = preg_replace('/^(re|res|rv|fwd|fw)\s*:\s*/i', '', $s);
        $s = trim($s);
    } while ($s !== $prev);
    return mb_strtolower($s, 'UTF-8');
}

/**
 * Busca una solicitud reciente del mismo remitente y mismo asunto (misma
 * conversación) que ya fue respondida automáticamente por la IA. Si existe,
 * el correo actual es una respuesta a esa auto-respuesta (el problema
 * persiste) y debe escalarse a un técnico en vez de repetir el mismo consejo.
 */
function find_recent_answered_request($pdo, $tenant_id, $from_email, $subject, $days = 7) {
    $norm = normalize_subject($subject);
    if ($norm === '') return null;

    $stmt = $pdo->prepare("SELECT id, subject, ai_classification FROM email_requests
        WHERE tenant_id = ? AND from_email = ? AND ai_status = 'sent'
        AND created_at >= (NOW() - INTERVAL ? DAY)
        ORDER BY created_at DESC");
    $stmt->execute([$tenant_id, $from_email, $days]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (normalize_subject($row['subject']) === $norm) {
            return $row;
        }
    }
    return null;
}

/**
 * Escala una solicitud directamente a ticket (prioridad alta) en lugar de
 * volver a responder con IA, y avisa al usuario que ya fue derivada a un técnico.
 */
function escalate_email_to_ticket($pdo, $tenant_id, $request_id, $prior) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_requests WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$request_id, $tenant_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) return false;

        ensure_email_ai_columns($pdo);
        $result = create_ticket_from_email_request($pdo, $tenant_id, $req, 'high');

        $pdo->prepare("UPDATE email_requests SET status = 'approved', ai_status = 'escalated' WHERE id = ?")
            ->execute([$request_id]);

        try {
            $subj = "Hemos escalado tu caso — Ticket #" . $result['local_id'];
            $html = tpl_escalated_to_tech($req['from_name'], $result['local_id'], $req['subject']);
            @send_mail($req['from_email'], $subj, $html, null, [], $tenant_id);
        } catch (Throwable $e) {
            error_log("escalate_email_to_ticket notify error: " . $e->getMessage());
        }

        return $result;
    } catch (Throwable $e) {
        error_log("escalate_email_to_ticket error: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtener adjuntos
 */
function get_attachments($mbox, $uid, $structure) {
    $attachments = [];
    if (isset($structure->parts) && count($structure->parts)) {
        for ($i = 0; $i < count($structure->parts); $i++) {
            $attachments[$i] = [
                'is_attachment' => false,
                'filename' => '',
                'name' => '',
                'attachment' => ''
            ];

            if ($structure->parts[$i]->ifdparameters) {
                foreach ($structure->parts[$i]->dparameters as $object) {
                    if (strtolower($object->attribute) == 'filename') {
                        $attachments[$i]['is_attachment'] = true;
                        $attachments[$i]['filename'] = $object->value;
                    }
                }
            }

            if ($structure->parts[$i]->ifparameters) {
                foreach ($structure->parts[$i]->parameters as $object) {
                    if (strtolower($object->attribute) == 'name') {
                        $attachments[$i]['is_attachment'] = true;
                        $attachments[$i]['name'] = $object->value;
                    }
                }
            }

            if ($attachments[$i]['is_attachment']) {
                $attachments[$i]['attachment'] = imap_fetchbody($mbox, $uid, $i + 1);
                if ($structure->parts[$i]->encoding == 3) { // 3 = BASE64
                    $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                } elseif ($structure->parts[$i]->encoding == 4) { // 4 = QUOTED-PRINTABLE
                    $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                }
            }
        }
    }
    return array_filter($attachments, function($a) { return $a['is_attachment']; });
}

/**
 * Crear ticket en la base de datos
 */
function create_ticket_from_email($pdo, $email, $name, $subject, $body, $attachments, $tenant_id) {
    // 1. Encontrar o crear usuario
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
    $stmt->execute([$email, $tenant_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM users WHERE tenant_id = ?");
        $st_local->execute([$tenant_id]);
        $next_user_local_id = (int)$st_local->fetchColumn();

        $pdo->prepare("INSERT INTO users (tenant_id, local_id, name, email, password, role, is_verified) VALUES (?, ?, ?, ?, ?, 'user', 1)")
            ->execute([$tenant_id, $next_user_local_id, $name, $email, password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT)]);
        $user_id = $pdo->lastInsertId();
    } else {
        $user_id = $user['id'];
    }

    // 2. Categorización automática simple basada en palabras clave
    $category_id = 1; // General por defecto
    $subject_lower = mb_strtolower($subject);
    $body_lower = mb_strtolower($body);
    $text_to_scan = $subject_lower . " " . $body_lower;

    $cat_map = [
        'software' => ['software', 'programa', 'aplicacion', 'app', 'instalacion', 'licencia'],
        'hardware' => ['hardware', 'equipo', 'computador', 'teclado', 'mouse', 'monitor', 'pantalla', 'impresora', 'disco'],
        'red' => ['red', 'wifi', 'internet', 'conexion', 'vpn', 'router'],
        'seguridad' => ['seguridad', 'virus', 'password', 'contraseña', 'acceso', 'bloqueo'],
        'soporte técnico' => ['soporte', 'ayuda', 'falla', 'error', 'problema', 'lento']
    ];

    foreach ($cat_map as $cat_name => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($text_to_scan, $kw) !== false) {
                $stmt_cat = $pdo->prepare("SELECT id FROM categories WHERE name LIKE ? AND tenant_id = ? LIMIT 1");
                $stmt_cat->execute(["%$cat_name%", $tenant_id]);
                $cat_res = $stmt_cat->fetch();
                if ($cat_res) {
                    $category_id = $cat_res['id'];
                    break 2;
                }
            }
        }
    }

    // 3. Crear ticket con prioridad BAJA y ubicación EMAIL
    $description = "Creado desde Email Por el usuario: " . $name . " <" . $email . ">\n\n" . $body;
    
    // Obtener configuración de SLA para la categoría
    $stmtSLA = $pdo->prepare("SELECT * FROM sla_config WHERE category_id = ? AND tenant_id = ?");
    $stmtSLA->execute([$category_id, $tenant_id]);
    $sla = $stmtSLA->fetch();

    $val = $sla ? (int)$sla['resolution_time_hours'] : 48;
    $unit = $sla ? $sla['resolution_unit'] : 'hours';
    $hours = ($unit == 'days') ? ($val * 24) : $val;
    
    // Prioridad BAJA usa multiplicador 1.5 (igual que en ticket_details.php y create_ticket.php)
    $hours = round($hours * 1.5);
    
    $due_date = date('Y-m-d H:i:s', strtotime("+$hours hours"));

    // Obtener el siguiente local_id para este tenant
    $st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM tickets WHERE tenant_id = ?");
    $st_local->execute([$tenant_id]);
    $next_local_id = (int)$st_local->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO tickets (tenant_id, local_id, user_id, category_id, title, description, priority, due_date, location, status) VALUES (?, ?, ?, ?, ?, ?, 'low', ?, 'Email', 'open')");
    $stmt->execute([$tenant_id, $next_local_id, $user_id, $category_id, $subject, $description, $due_date]);
    $ticket_id = $pdo->lastInsertId();

    // 4. Agregar solicitante real
    $pdo->prepare("INSERT IGNORE INTO ticket_solicitors (ticket_id, user_id) VALUES (?, ?)")->execute([$ticket_id, $user_id]);

    // 5. Agregar mensaje inicial del usuario
    $stmt = $pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$ticket_id, $user_id, $description]);
    $message_id = $pdo->lastInsertId();

    // 6. Procesar adjuntos
    if (!empty($attachments)) {
        foreach ($attachments as $att) {
            // Adjuntos de correo: solo extensiones permitidas y nombre saneado (evita subir .php)
            require_once __DIR__ . '/includes/security.php';
            $safe = safe_upload_name((string)($att['filename'] ?: $att['name']), 'mail');
            if (!$safe) { continue; }
            $filename = $safe[0];
            $path = 'uploads/' . $filename;
            if (!is_dir('uploads/')) mkdir('uploads/', 0755, true);
            file_put_contents($path, $att['attachment']);
            
            $pdo->prepare("INSERT INTO attachments (ticket_id, message_id, user_id, file_name, file_path, mime_type) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$ticket_id, $message_id, $user_id, ($att['filename'] ?: $att['name']), $path, 'application/octet-stream']);
        }
    }

    // 7. Notificar al usuario por correo que su ticket fue creado
    try {
        $subj = "Ticket #$next_local_id creado vía Email: $subject";
        $emailBody = tpl_ticket_created($name, $next_local_id, $subject, 'low', date('Y-m-d H:i:s', strtotime("+72 hours")));
        @send_mail($email, $subj, $emailBody, null, [], $tenant_id);
    } catch (Exception $e) {}

    // 8. Notificaciones adicionales
    try {
        require_once __DIR__ . '/includes/notifications.php';
        notify_add($pdo, (int)$user_id, 'ticket_created', "Ticket #$next_local_id creado", $subject, "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id, $tenant_id);
        
        require_once __DIR__ . '/includes/integrations_api.php';
        send_teams_notification($ticket_id, $subject, 'open', 'low', $next_local_id);
        send_telegram_notification($ticket_id, $subject, 'open', $next_local_id);
    } catch (Throwable $e) {}

    return $ticket_id;
}
?>
