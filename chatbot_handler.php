<?php
/**
 * chatbot_handler.php — endpoint JSON del chatbot (página Auto Servicio IA y widget flotante).
 *
 * Novedades:
 *  - Conversa y hace preguntas de diagnóstico antes de responder (includes/ai_chat.php).
 *  - Historial persistente por tenant y usuario (acciones: current, conversations, load, new, delete).
 *  - Adjuntos reales: se aceptan en cualquier momento, se guardan con su nombre original y se
 *    reportan archivo por archivo (aceptado / rechazado + motivo real).
 *  - Creación de ticket confiable: solo se confirma cuando quedó guardado (transacción), se conservan
 *    los adjuntos si falla y el error real queda en el log (y se muestra al personal de soporte).
 *  - Responde con documentos del Centro de conocimiento IA del tenant (includes/ai_docs.php).
 */
require_once __DIR__ . '/config/session_setup.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ai_helper.php';
require_once __DIR__ . '/includes/ai_docs.php';
require_once __DIR__ . '/includes/ai_chat.php';

// Nunca mostrar errores en la respuesta JSON; sí registrarlos.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
while (ob_get_level() > 0) { @ob_end_clean(); }

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('chatbot_handler FATAL: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['status' => 'error', 'message' => 'El asistente tuvo un fallo interno. Intenta de nuevo en un momento.']);
    }
});

const CHAT_MAX_MESSAGE = 1500;       // caracteres por mensaje
const CHAT_MAX_FILES = 5;            // adjuntos por reporte
const CHAT_MAX_FILE_BYTES = 8388608; // 8 MB

/* ================================================================== salida JSON */

function chat_json(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function chat_error(string $msg, array $extra = []): void {
    chat_json(['status' => 'error', 'message' => $msg] + $extra);
}

function chat_tenant_id(): int {
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
}

/** Nota que se antepone a la siguiente respuesta (p. ej. resultado de adjuntos). */
function chat_note(?string $set = null, bool $take = false): string {
    static $note = '';
    if ($set !== null) $note = $set;
    if ($take) { $n = $note; $note = ''; return $n; }
    return $note;
}

/**
 * Respuesta al widget. El widget inserta HTML, así que TODO el texto se escapa aquí.
 * Guarda la respuesta y el estado de la conversación (historial persistente).
 */
function chat_reply(string $text, ?string $state = null, array $extra = []): void {
    global $pdo;
    if ($state !== null) $_SESSION['chatbot_state'] = $state;
    $st = (string)($_SESSION['chatbot_state'] ?? 'IDLE');
    $note = chat_note(null, true);
    if ($note !== '') $text = $note . ($text !== '' ? "\n\n" . $text : '');

    $conv = (int)($_SESSION['chat_conv_id'] ?? 0);
    try {
        if ($conv) {
            if ($text !== '') ai_chat_add($pdo, chat_tenant_id(), $conv, 'assistant', $text);
            ai_chat_save_state($pdo, chat_tenant_id(), $conv, $st, $_SESSION['chatbot_data'] ?? [], isset($extra['ticket_id']) ? (int)$extra['ticket_id'] : null);
        }
    } catch (Throwable $e) {
        error_log('chatbot history save: ' . $e->getMessage());
    }
    chat_json(['status' => 'success', 'reply' => chat_escape($text), 'state' => $st, 'conversation_id' => $conv, 'quick' => ai_chat_quick_replies($st)] + $extra);
}

/* ================================================================== datos de la conversación */

function chat_data(): array { return $_SESSION['chatbot_data'] ?? []; }
function chat_set_data(array $d): void { $_SESSION['chatbot_data'] = $d; }

/** Adjuntos en espera (normaliza el formato antiguo: lista de rutas). */
function chat_attachments_list(): array {
    $out = [];
    foreach (($_SESSION['chatbot_data']['attachments'] ?? []) as $a) {
        if (is_string($a)) $out[] = ['path' => $a, 'name' => basename($a), 'size' => 0, 'mime' => 'application/octet-stream', 'ext' => strtolower(pathinfo($a, PATHINFO_EXTENSION))];
        elseif (is_array($a) && !empty($a['path'])) $out[] = $a;
    }
    return $out;
}

/** Campos de contacto que se conservan entre tickets de una misma sesión. */
function chat_contact_keys(): array { return ['name', 'email', 'phone', 'area', 'prefilled']; }

/** Siguiente dato que falta para el ticket (si la persona ya inició sesión, no se le vuelve a pedir). */
function chat_next_missing_state(): array {
    $d = $_SESSION['chatbot_data'] ?? [];
    if (empty($d['incident'])) return ['COLLECTING_INCIDENT', 'Cuéntame en pocas palabras **qué problema tienes** para que el técnico lo entienda bien.'];
    if (empty($d['name']))  return ['COLLECTING_NAME',  'Perfecto, abro el ticket con todo lo que me contaste. ¿Me indicas tu **nombre completo**?'];
    if (empty($d['email'])) return ['COLLECTING_EMAIL', 'Gracias, **' . $d['name'] . '**. ¿Cuál es tu **correo electrónico**?'];
    if (empty($d['phone'])) return ['COLLECTING_PHONE', '¿Y un **número de teléfono** de contacto?'];
    if (empty($d['area']))  return ['COLLECTING_AREA',  '¿A qué **área o departamento** perteneces?'];
    $n = count(chat_attachments_list());
    return ['COLLECTING_ATTACHMENT', $n > 0
        ? 'Casi listo. Ya tengo **' . $n . ' archivo(s)** adjunto(s). Puedes añadir más con el clip 📎 o escribir **"listo"** para crear el ticket.'
        : 'Casi listo. Si tienes una **captura de pantalla o evidencia**, adjúntala con el clip 📎 (puedes elegir varios archivos). Si no, escribe **"listo"**.'];
}

function chat_go_collect(): void {
    [$state, $prompt] = chat_next_missing_state();
    chat_reply($prompt, $state);
}

/** Si hay sesión iniciada, precargar datos del usuario (solo de su propio tenant). */
function chat_prefill_from_session(PDO $pdo): void {
    if (empty($_SESSION['user_id']) || !empty($_SESSION['chatbot_data']['prefilled'])) return;
    try {
        $cols = ai_db_columns($pdo, 'users');
        $want = array_values(array_filter(['name', 'email', 'phone', 'area'], function ($c) use ($cols) { return isset($cols[$c]); }));
        if (!$want) return;
        $st = $pdo->prepare('SELECT `' . implode('`,`', $want) . '` FROM users WHERE id = ? AND tenant_id = ?');
        $st->execute([(int)$_SESSION['user_id'], chat_tenant_id()]);
        if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach ($want as $k) if (!empty($u[$k]) && empty($_SESSION['chatbot_data'][$k])) $_SESSION['chatbot_data'][$k] = $u[$k];
        }
    } catch (Throwable $e) {
        error_log('chat_prefill: ' . $e->getMessage());
    }
    $_SESSION['chatbot_data']['prefilled'] = true;
}

/* ================================================================== adjuntos */

/** Convierte $_FILES['attachments'] (uno o varios) en una lista plana. */
function chat_normalize_files($f): array {
    if (!$f || !isset($f['name'])) return [];
    $out = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            if ($n === '' && (int)($f['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => (string)$n, 'tmp_name' => $f['tmp_name'][$i] ?? '', 'size' => (int)($f['size'][$i] ?? 0), 'error' => (int)($f['error'][$i] ?? 0)];
        }
    } elseif ($f['name'] !== '') {
        $out[] = ['name' => (string)$f['name'], 'tmp_name' => $f['tmp_name'] ?? '', 'size' => (int)($f['size'] ?? 0), 'error' => (int)($f['error'] ?? 0)];
    }
    return $out;
}

function chat_mime_for_ext(string $ext): string {
    $m = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
          'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
          'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    return $m[$ext] ?? 'application/octet-stream';
}

function chat_display_name(string $name): string {
    $n = basename(str_replace('\\', '/', $name));
    $n = preg_replace('/[\x00-\x1F\x7F]+/', '', $n);
    return mb_substr($n !== '' ? $n : 'archivo', 0, 120);
}

/**
 * Guarda los archivos recibidos en la carpeta uploads/ y los deja "en espera" para el ticket.
 * Devuelve ['saved' => [[name,size],..], 'rejected' => [[name,reason],..]].
 */
function chat_stage_files(PDO $pdo, array $files): array {
    $saved = []; $rejected = [];
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    $attachments = chat_attachments_list();
    $visionDone = 0;

    foreach ($files as $f) {
        $name = chat_display_name($f['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (count($attachments) >= CHAT_MAX_FILES) { $rejected[] = [$name, 'Máximo ' . CHAT_MAX_FILES . ' archivos por reporte.']; continue; }
        if ($f['error'] !== UPLOAD_ERR_OK) { $rejected[] = [$name, ai_docs_upload_error($f['error'])]; continue; }
        if ($f['size'] > CHAT_MAX_FILE_BYTES) { $rejected[] = [$name, 'Pesa ' . ai_docs_format_size($f['size']) . ' y el máximo es ' . ai_docs_format_size(CHAT_MAX_FILE_BYTES) . '.']; continue; }
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) { $rejected[] = [$name, 'El servidor no puede guardar archivos (la carpeta uploads/ no tiene permisos de escritura).']; continue; }
        $dupe = false;
        foreach ($attachments as $a) if (($a['name'] ?? '') === $name && (int)($a['size'] ?? 0) === (int)$f['size']) $dupe = true;
        if ($dupe) { $rejected[] = [$name, 'Ya estaba adjunto.']; continue; }
        $safe = safe_upload_name($name, 'chat');
        if (!$safe) { $rejected[] = [$name, 'Formato .' . ($ext ?: '?') . ' no permitido. Formatos válidos: ' . implode(', ', allowed_upload_extensions()) . '.']; continue; }
        if (!upload_content_ok($f['tmp_name'], $safe[1])) { $rejected[] = [$name, 'El contenido no coincide con su extensión (.' . $safe[1] . ') o parece peligroso.']; continue; }
        $moved = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $uploadDir . $safe[0]) : (PHP_SAPI === 'cli' && copy($f['tmp_name'], $uploadDir . $safe[0]));
        if (!$moved) { $rejected[] = [$name, 'No se pudo guardar el archivo en el servidor.']; continue; }
        @chmod($uploadDir . $safe[0], 0644);

        $entry = ['path' => 'uploads/' . $safe[0], 'name' => $name, 'size' => (int)$f['size'], 'mime' => chat_mime_for_ext($safe[1]), 'ext' => $safe[1]];
        $attachments[] = $entry;
        $saved[] = [$name, (int)$f['size']];

        // Si hay IA con visión, "lee" la captura para entender mejor el caso (máx. 3 por conversación).
        if (in_array($safe[1], ['jpg', 'jpeg', 'png', 'webp'], true) && ai_llm_enabled() && $visionDone < 2
            && count($_SESSION['chatbot_data']['attachment_notes'] ?? []) < 3 && $f['size'] <= 4500000) {
            try {
                [$bytes, $media] = ai_docs_prepare_image($uploadDir . $safe[0], $safe[1]);
                $d = ai_docs_llm_media($bytes, $media, 'Esto es una captura o foto enviada a soporte técnico. En máximo 60 palabras di qué aplicación o equipo muestra y transcribe literalmente cualquier mensaje o código de error visible. Responde en español, sin introducción.', 300);
                if ($d !== '') { $_SESSION['chatbot_data']['attachment_notes'][] = $name . ': ' . mb_substr($d, 0, 500); $visionDone++; }
            } catch (Throwable $e) {
                error_log('chat vision: ' . $e->getMessage());
            }
        }
    }
    $_SESSION['chatbot_data']['attachments'] = $attachments;
    return ['saved' => $saved, 'rejected' => $rejected];
}

/** Texto para la persona con el resultado de la carga. */
function chat_files_report(array $staged): string {
    $lines = [];
    if ($staged['saved']) {
        $n = count($staged['saved']);
        $lines[] = '📎 Recibí **' . $n . '** archivo' . ($n > 1 ? 's' : '') . ':';
        foreach ($staged['saved'] as $s) $lines[] = '• ' . $s[0] . ' (' . ai_docs_format_size($s[1]) . ')';
        $notes = $_SESSION['chatbot_data']['attachment_notes'] ?? [];
        if ($notes && !empty($staged['saved'])) {
            $last = end($notes);
            if (!isset($_SESSION['chatbot_noted']) || $_SESSION['chatbot_noted'] !== $last) {
                $_SESSION['chatbot_noted'] = $last;
                $lines[] = '👁️ Esto es lo que veo: ' . mb_substr($last, 0, 220);
            }
        }
    }
    if ($staged['rejected']) {
        $lines[] = '⚠️ No pude adjuntar:';
        foreach ($staged['rejected'] as $r) $lines[] = '• ' . $r[0] . ' — ' . $r[1];
    }
    return implode("\n", $lines);
}

/* ================================================================== ticket */

function chat_fallback_category_id(array $categories) {
    foreach (['general', 'otro', 'otros', 'solicitud'] as $want) {
        foreach ($categories as $c) if (mb_strtolower(trim($c['name'])) === $want) return $c['id'];
    }
    return $categories[0]['id'] ?? null;
}

/**
 * Crea el ticket (con sus mensajes y adjuntos) en UNA transacción. Devuelve ['id','local_id','attachments'].
 * Si algo falla lanza la excepción (nada queda a medias) y los adjuntos siguen en espera.
 */
function chat_create_ticket(PDO $pdo): array {
    $data = chat_data();
    $tenant_id = chat_tenant_id();
    if (empty($data['incident'])) throw new RuntimeException('Falta la descripción del problema.');

    // --- preparación del esquema (fuera de la transacción: ALTER hace commit implícito)
    try {
        if (!isset(ai_db_columns($pdo, 'tickets')['source'])) {
            $pdo->exec("ALTER TABLE `tickets` ADD COLUMN source ENUM('web','email','whatsapp','telegram','chatbot') DEFAULT 'web' AFTER tenant_id");
            ai_db_columns($pdo, 'tickets', true);
        } else {
            ai_db_ensure_enum_value($pdo, 'tickets', 'source', 'chatbot');
        }
    } catch (Throwable $e) { error_log('Chatbot DB (source): ' . $e->getMessage()); }
    $tcols = ai_db_columns($pdo, 'tickets');

    $email = trim((string)($data['email'] ?? '')) ?: 'anonimo@chatbot.com';
    $name  = trim((string)($data['name'] ?? '')) ?: 'Usuario Chatbot';
    $phone = $data['phone'] ?? null;
    $area  = $data['area'] ?? null;

    // --- adjuntos que realmente existen todavía
    $atts = [];
    foreach (chat_attachments_list() as $a) if (is_file(__DIR__ . '/' . $a['path'])) $atts[] = $a;

    // --- prioridad válida para el esquema real
    $priority = $data['priority'] ?? 'medium';
    if ($priority === 'urgent') $priority = 'critical';
    if (isset($tcols['priority'])) {
        $allowed = ai_db_enum_values($tcols['priority']);
        if ($allowed && !in_array($priority, $allowed, true)) $priority = in_array('high', $allowed, true) && $priority === 'critical' ? 'high' : (in_array('medium', $allowed, true) ? 'medium' : $allowed[0]);
    }
    if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) $priority = 'medium';

    // --- categoría validada contra el tenant
    $category_id = null;
    if (!empty($data['category_id'])) {
        $stc = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND tenant_id = ?');
        $stc->execute([(int)$data['category_id'], $tenant_id]);
        $category_id = $stc->fetchColumn() ?: null;
    }
    if (!$category_id) $category_id = chat_fallback_category_id(fetch_categories_for_tenant($pdo, $tenant_id));
    if (!$category_id) throw new RuntimeException('Este tenant no tiene categorías configuradas. Un administrador debe crear al menos una en Servicios.');

    // --- SLA (misma lógica que create_ticket.php)
    $hours = 48;
    try {
        $sl = $pdo->prepare('SELECT * FROM sla_config WHERE category_id = ? AND tenant_id = ?');
        $sl->execute([$category_id, $tenant_id]);
        if ($sla = $sl->fetch(PDO::FETCH_ASSOC)) {
            $val = (int)$sla['resolution_time_hours'];
            $hours = (($sla['resolution_unit'] ?? 'hours') === 'days') ? $val * 24 : $val;
        }
    } catch (Throwable $e) { /* sin SLA configurado: 48 h */ }
    if ($priority === 'critical') $hours = max(1, (int)round($hours * 0.25));
    elseif ($priority === 'high') $hours = max(2, (int)round($hours * 0.5));
    elseif ($priority === 'low') $hours = (int)round($hours * 1.5);
    $due_date = date('Y-m-d H:i:s', time() + $hours * 3600);

    $incident = (string)$data['incident'];
    $title = 'Reporte Chatbot: ' . mb_substr(trim(preg_replace('/\s+/', ' ', $incident)), 0, 60);
    $description = ai_chat_ticket_summary($data) . "\n\nTELÉFONO: " . ($phone ?? 'N/A') . "\nÁREA: " . ($area ?? 'N/A');

    $pdo->beginTransaction();
    try {
        // Usuario (siempre dentro del tenant actual)
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND tenant_id = ?');
        $stmt->execute([$email, $tenant_id]);
        $user_id = $stmt->fetchColumn();
        $ucols = ai_db_columns($pdo, 'users');
        if ($user_id) {
            $sets = []; $vals = [];
            if (isset($ucols['phone']) && $phone) { $sets[] = 'phone = COALESCE(phone, ?)'; $vals[] = $phone; }
            if (isset($ucols['area']) && $area)   { $sets[] = 'area = COALESCE(area, ?)'; $vals[] = $area; }
            if ($sets) { $vals[] = $user_id; $vals[] = $tenant_id; $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?')->execute($vals); }
        } else {
            $row = ['tenant_id' => $tenant_id, 'name' => $name, 'email' => $email, 'password' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
                    'role' => 'user', 'is_verified' => 1, 'phone' => $phone, 'area' => $area];
            if (isset($ucols['local_id'])) {
                $st = $pdo->prepare('SELECT COALESCE(MAX(local_id), 0) + 1 FROM users WHERE tenant_id = ? FOR UPDATE');
                $st->execute([$tenant_id]);
                $row['local_id'] = (int)$st->fetchColumn();
            }
            $user_id = ai_db_insert($pdo, 'users', $row);
        }
        $user_id = (int)$user_id;

        $st = $pdo->prepare('SELECT COALESCE(MAX(local_id), 0) + 1 FROM tickets WHERE tenant_id = ? FOR UPDATE');
        $st->execute([$tenant_id]);
        $next_local_id = (int)$st->fetchColumn();

        $ticket_id = ai_db_insert($pdo, 'tickets', [
            'tenant_id' => $tenant_id, 'local_id' => $next_local_id, 'user_id' => $user_id, 'category_id' => $category_id,
            'title' => $title, 'description' => $description, 'priority' => $priority, 'status' => 'open',
            'source' => 'chatbot', 'due_date' => $due_date, 'location' => $area ?: 'Chatbot',
        ]);
        if (!$ticket_id) throw new RuntimeException('La base de datos no devolvió el id del ticket.');

        try { $pdo->prepare('INSERT IGNORE INTO ticket_solicitors (ticket_id, user_id) VALUES (?, ?)')->execute([$ticket_id, $user_id]); }
        catch (Throwable $e) { error_log('chatbot solicitor: ' . $e->getMessage()); }

        $message_id = ai_db_insert($pdo, 'messages', ['ticket_id' => $ticket_id, 'user_id' => $user_id, 'message' => $description]);

        $names = [];
        foreach ($atts as $a) {
            ai_db_insert($pdo, 'attachments', [
                'ticket_id' => $ticket_id, 'message_id' => $message_id, 'user_id' => $user_id,
                'file_name' => mb_substr($a['name'], 0, 255), 'file_path' => $a['path'], 'mime_type' => $a['mime'] ?? chat_mime_for_ext($a['ext'] ?? ''),
            ]);
            $names[] = $a['name'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Avisos (si fallan, el ticket ya existe y no se pierde nada)
    try {
        notify_add($pdo, $user_id, 'ticket_created', "Ticket #$next_local_id creado", $title, "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id, $tenant_id);
        if (is_file(__DIR__ . '/includes/integrations_api.php')) {
            require_once __DIR__ . '/includes/integrations_api.php';
            if (function_exists('send_teams_notification')) send_teams_notification($ticket_id, $title, 'open', $priority, $next_local_id);
            if (function_exists('send_telegram_notification')) send_telegram_notification($ticket_id, $title, 'open', $next_local_id);
        }
    } catch (Throwable $e) { error_log('chatbot notify: ' . $e->getMessage()); }

    return ['id' => (int)$ticket_id, 'local_id' => $next_local_id, 'attachments' => $names];
}

/** Crea el ticket y responde. Solo confirma si realmente se guardó. */
function chat_finish_ticket(PDO $pdo): void {
    try {
        $res = chat_create_ticket($pdo);
    } catch (Throwable $e) {
        $ref = 'CHT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        error_log("chatbot ticket error [$ref]: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $n = count(chat_attachments_list());
        $msg = "⚠️ No pude crear el ticket por un problema del sistema (código **$ref**).\n\n"
             . 'Tus datos' . ($n ? " y tus **$n archivo(s)** adjunto(s)" : '') . ' siguen guardados, no hace falta repetir nada. '
             . 'Escribe **"listo"** para intentarlo de nuevo; si vuelve a fallar, comparte ese código con soporte.';
        if (in_array($_SESSION['role'] ?? 'user', ['admin', 'supervisor', 'tech'], true)) {
            $msg .= "\n\nDetalle técnico (solo visible para personal de soporte): " . mb_substr($e->getMessage(), 0, 300);
        }
        chat_reply($msg, 'COLLECTING_ATTACHMENT', ['error_ref' => $ref]);
        return;
    }

    $data = chat_data();
    $diag = $data['diag'] ?? [];
    $keep = [];
    foreach (chat_contact_keys() as $k) if (isset($data[$k])) $keep[$k] = $data[$k];   // el contacto se conserva para el siguiente reporte
    chat_set_data($keep);
    unset($_SESSION['chatbot_noted']);

    $msg = '✅ Tu ticket **#' . $res['local_id'] . '** fue creado y ya lo tiene el equipo de soporte.';
    if ($res['attachments']) $msg .= "\n📎 Adjuntos incluidos: " . implode(', ', $res['attachments']) . '.';
    $sum = ai_chat_slot_summary($diag);
    if ($sum) $msg .= "\n📝 Lo que registré: " . $sum . '.';
    $msg .= "\n\n¿Necesitas ayuda con algo más?";
    chat_reply($msg, 'IDLE', ['ticket_id' => $res['id'], 'local_id' => $res['local_id'], 'attachments_received' => count($res['attachments'])]);
}

function chat_is_done_word(string $message): bool {
    $t = ai_docs_norm($message);
    if ($t === '') return false;
    if (count(explode(' ', $t)) > 5) return false;
    return (bool)preg_match('/^(listo|ya|enviar|envialo|nada|ok|crear|crear ticket|terminar|sin adjuntos?|sin archivos?|no gracias|no|continuar|adelante)\b/', $t);
}

/* ================================================================== manejadores por estado */

function chat_handle_incident(string $message, PDO $pdo): void {
    $tenantId = chat_tenant_id();
    if ($message === '') {
        chat_reply('¡Hola! 👋 Soy el asistente de soporte. Cuéntame qué está pasando (qué equipo o aplicación y qué ves en pantalla) y lo revisamos juntos. También puedes adjuntar una captura con el clip 📎.', 'AWAITING_INCIDENT');
        return;
    }
    if (ai_chat_is_greeting($message)) {
        chat_reply('¡Hola! 👋 Cuéntame qué está pasando: qué equipo o aplicación está fallando y qué error ves.', 'AWAITING_INCIDENT');
        return;
    }
    if (ai_chat_is_thanks($message)) {
        chat_reply('¡Con gusto! 😊 Si necesitas algo más, aquí estaré.', 'AWAITING_INCIDENT');
        return;
    }
    $data = chat_data();
    if (ai_chat_wants_ticket($message, true)) {
        if (empty($data['incident'])) { chat_set_data($data); chat_reply('Claro, abramos un ticket. Cuéntame en pocas palabras **qué problema tienes** para que el técnico lo entienda bien.', 'COLLECTING_INCIDENT'); return; }
        chat_go_collect();
        return;
    }
    $history = ai_chat_history($pdo, $tenantId, (int)$_SESSION['chat_conv_id'], 12);
    array_pop($history); // el mensaje actual ya se guardó; la IA lo recibe aparte
    $res = ai_chat_turn($pdo, $tenantId, $message, $data, $history, true);
    chat_set_data($data);
    if ($res['handoff']) { chat_go_collect(); return; }
    chat_reply($res['reply'], $res['state']);
}

function chat_handle_diagnosing(string $message, PDO $pdo): void {
    $tenantId = chat_tenant_id();
    $data = chat_data();
    $history = ai_chat_history($pdo, $tenantId, (int)$_SESSION['chat_conv_id'], 12);
    array_pop($history);
    $res = ai_chat_turn($pdo, $tenantId, $message, $data, $history, false);
    chat_set_data($data);
    if ($res['handoff']) { chat_go_collect(); return; }
    chat_reply($res['reply'], $res['state']);
}

function chat_show_alternative(array $alt): string {
    if (($alt['source'] ?? 'kb') === 'doc') {
        return "Entiendo, probemos otra opción. Esto dice **" . $alt['title'] . "**:\n\n" . ai_chat_trim_words((string)$alt['content'], 900) . "\n\n¿Con esto se solucionó? Si no, abro un ticket para que un técnico te ayude.";
    }
    return "Entiendo, probemos otra opción:\n\n**" . $alt['title'] . "**\n" . $alt['content'] . "\n\n¿Con esto se solucionó? Si no, abro un ticket para que un técnico te ayude.";
}

function chat_handle_kb_confirmation(string $message, PDO $pdo): void {
    $tenantId = chat_tenant_id();
    $intent = ai_chat_intent($message);
    $data = chat_data();

    if ($intent === 'yes') {
        $keep = [];
        foreach (array_merge(chat_contact_keys(), ['attachments', 'attachment_notes']) as $k) if (isset($data[$k])) $keep[$k] = $data[$k];
        chat_set_data($keep);
        chat_reply('¡Qué bueno que se resolvió! 🎉 Si vuelve a pasar o necesitas algo más, aquí estaré.', 'IDLE');
        return;
    }
    if ($intent === 'handoff') { chat_go_collect(); return; }

    if ($intent === 'no') {
        if (mb_strlen($message) > 20) { $data['diag']['extra'] = array_slice(array_merge($data['diag']['extra'] ?? [], [mb_substr($message, 0, 300)]), -5); }
        $alts = $data['alt_articles'] ?? [];
        if (!empty($alts)) {
            $alt = array_shift($alts);
            $data['alt_articles'] = $alts;
            $data['current_article'] = $alt;
            $data['sources'] = array_merge($data['sources'] ?? [], [$alt['title']]);
            chat_set_data($data);
            chat_reply(chat_show_alternative($alt), 'AWAITING_KB_CONFIRMATION');
            return;
        }
        chat_set_data($data);
        [$next, $prompt] = chat_next_missing_state();
        chat_reply("Lamento que no haya funcionado. No te preocupes: creo un ticket con todo lo que me contaste (equipo, error y lo que ya intentaste) para que un técnico lo revise.\n\n" . $prompt, $next);
        return;
    }

    // clarify / unclear
    $data['unclear_count'] = (int)($data['unclear_count'] ?? 0) + 1;
    if ($data['unclear_count'] > 3) {
        chat_set_data($data);
        chat_reply('Parece que esto necesita ojos humanos. ¿Quieres que abra un ticket para que un técnico te ayude?', 'OFFER_TICKET');
        return;
    }
    if ($intent === 'unclear' && mb_strlen($message) < 12) {
        chat_set_data($data);
        chat_reply('No estoy seguro de haberte entendido. ¿Se resolvió con esos pasos? Puedes responder **sí** o **no**, preguntarme lo que no te quedó claro, o pedirme un **ticket**.', 'AWAITING_KB_CONFIRMATION');
        return;
    }
    $history = ai_chat_history($pdo, $tenantId, (int)$_SESSION['chat_conv_id'], 12);
    array_pop($history);
    $res = ai_chat_followup($pdo, $tenantId, $message, $data, $history);
    chat_set_data($data);
    if ($res['handoff']) { chat_go_collect(); return; }
    chat_reply($res['reply'], 'AWAITING_KB_CONFIRMATION');
}

function chat_handle_offer_ticket(string $message, PDO $pdo): void {
    $intent = ai_chat_intent($message);
    if (in_array($intent, ['yes', 'handoff'], true)) { chat_go_collect(); return; }
    if ($intent === 'no' && count(explode(' ', ai_docs_norm($message))) <= 4) {
        chat_reply('De acuerdo, no abriré ningún ticket. Si cambias de opinión o tienes otra consulta, escríbeme. 🙂', 'IDLE');
        return;
    }
    $_SESSION['chatbot_state'] = 'AWAITING_INCIDENT';
    chat_handle_incident($message, $pdo);   // cualquier otra cosa se trata como una nueva consulta
}

function chat_handle_attachment(string $message, array $staged, PDO $pdo): void {
    $hasFiles = !empty($staged['saved']) || !empty($staged['rejected']);
    if ($hasFiles) {
        $report = chat_files_report($staged);
        if ($message !== '' && chat_is_done_word($message)) { chat_note($report); chat_finish_ticket($pdo); return; }
        $n = count(chat_attachments_list());
        chat_reply($report . "\n\nLlevo **$n** archivo(s) para el ticket. Puedes añadir más con el clip 📎 o escribir **\"listo\"** para crear el ticket.", 'COLLECTING_ATTACHMENT');
        return;
    }
    if (chat_is_done_word($message)) { chat_finish_ticket($pdo); return; }
    if ($message !== '') {
        $data = chat_data();
        $data['diag']['extra'] = array_slice(array_merge($data['diag']['extra'] ?? [], [mb_substr($message, 0, 300)]), -5);
        chat_set_data($data);
        chat_reply('Anotado ✍️ lo añadí al reporte. Puedes adjuntar archivos con el clip 📎 o escribir **"listo"** para crear el ticket.', 'COLLECTING_ATTACHMENT');
        return;
    }
    chat_reply('Adjunta tu evidencia con el clip 📎 o escribe **"listo"** para crear el ticket.', 'COLLECTING_ATTACHMENT');
}

/* ================================================================== entrada */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { chat_error('Método no permitido'); exit; }

// Si el envío supera post_max_size PHP descarta TODO el cuerpo: lo detectamos para dar el motivo real.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    chat_error('Los archivos pesan más de lo que permite el servidor (post_max_size = ' . ini_get('post_max_size') . '). Envía archivos más pequeños.', ['code' => 'post_too_large']);
    exit;
}
if (!csrf_check()) { chat_error('Sesión expirada. Recarga la página.', ['code' => 'csrf']); exit; }

$tenantId = chat_tenant_id();
try {
    ai_chat_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('chat schema: ' . $e->getMessage());
    chat_error('No se pudo preparar el historial del chat. Avisa a soporte.');
    exit;
}

/** Datos para pintar una conversación completa en el widget. */
function chat_conversation_payload(PDO $pdo, int $tenantId, int $conv): array {
    $state = (string)($_SESSION['chatbot_state'] ?? 'IDLE');
    return ['status' => 'success', 'conversation_id' => $conv, 'state' => $state, 'quick' => ai_chat_quick_replies($state),
            'messages' => ai_chat_messages_ui($pdo, $tenantId, $conv), 'conversations' => ai_chat_list($pdo, $tenantId),
            'attachments_pending' => array_map(function ($a) { return ['name' => $a['name'], 'size' => (int)($a['size'] ?? 0)]; }, chat_attachments_list())];
}

/** Nueva conversación (reutiliza la actual si todavía está vacía). */
function chat_start_new(PDO $pdo, int $tenantId): int {
    $cur = (int)($_SESSION['chat_conv_id'] ?? 0);
    if ($cur && ai_chat_owned($pdo, $tenantId, $cur)) {
        $n = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ? AND tenant_id = ?');
        $n->execute([$cur, $tenantId]);
        $id = (int)$n->fetchColumn() === 0 ? $cur : ai_chat_create($pdo, $tenantId);
    } else {
        $id = ai_chat_create($pdo, $tenantId);
    }
    $contact = [];
    foreach (chat_contact_keys() as $k) if (isset($_SESSION['chatbot_data'][$k])) $contact[$k] = $_SESSION['chatbot_data'][$k];
    $_SESSION['chat_conv_id'] = $id;
    $_SESSION['chatbot_state'] = 'IDLE';
    $_SESSION['chatbot_data'] = $contact;
    unset($_SESSION['chatbot_noted'], $_SESSION['chatbot_history']);
    ai_chat_save_state($pdo, $tenantId, $id, 'IDLE', $contact);
    return $id;
}

$action = (string)($_POST['action'] ?? '');
try {
    if ($action === 'current') {
        $had = !empty($_SESSION['chat_conv_id']);
        $conv = ai_chat_current($pdo, $tenantId);
        if (!$had || !isset($_SESSION['chatbot_state'])) ai_chat_activate($pdo, $tenantId, $conv, true);
        chat_json(chat_conversation_payload($pdo, $tenantId, $conv));
        exit;
    }
    if ($action === 'conversations') {
        chat_json(['status' => 'success', 'conversations' => ai_chat_list($pdo, $tenantId), 'conversation_id' => (int)($_SESSION['chat_conv_id'] ?? 0)]);
        exit;
    }
    if ($action === 'load') {
        $id = (int)($_POST['id'] ?? 0);
        if (!ai_chat_activate($pdo, $tenantId, $id)) { chat_error('No encontré esa conversación.'); exit; }
        chat_json(chat_conversation_payload($pdo, $tenantId, $id));
        exit;
    }
    if ($action === 'new' || $action === 'reset') {
        $id = chat_start_new($pdo, $tenantId);
        $p = chat_conversation_payload($pdo, $tenantId, $id);
        $p['message'] = 'Chat reiniciado';
        chat_json($p);
        exit;
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!ai_chat_delete($pdo, $tenantId, $id)) { chat_error('No encontré esa conversación.'); exit; }
        $wasCurrent = ((int)($_SESSION['chat_conv_id'] ?? 0) === $id);
        if ($wasCurrent) { unset($_SESSION['chat_conv_id']); $new = chat_start_new($pdo, $tenantId); }
        chat_json(['status' => 'success', 'deleted' => $id, 'was_current' => $wasCurrent, 'conversation_id' => (int)($_SESSION['chat_conv_id'] ?? 0), 'conversations' => ai_chat_list($pdo, $tenantId)]);
        exit;
    }
} catch (Throwable $e) {
    error_log('chatbot action ' . $action . ': ' . $e->getMessage());
    chat_error('No se pudo completar la acción. Intenta de nuevo.');
    exit;
}

// Límite de uso (protege costos de IA y el servidor): por IP y por sesión
if (!rate_limit_hit($pdo, 'chat_ip|' . client_ip(), 90, 600) || !rate_limit_hit($pdo, 'chat_sess|' . session_id(), 60, 600)) {
    chat_json(['status' => 'success', 'reply' => chat_escape('Estás escribiendo muy rápido para mí 😅. Espera unos minutos y seguimos.'), 'state' => $_SESSION['chatbot_state'] ?? 'IDLE', 'quick' => []]);
    exit;
}

$message = trim((string)($_POST['message'] ?? ''));
if (mb_strlen($message) > CHAT_MAX_MESSAGE) $message = mb_substr($message, 0, CHAT_MAX_MESSAGE);
$files = chat_normalize_files($_FILES['attachments'] ?? null);

try {
    $hadConv = !empty($_SESSION['chat_conv_id']);
    $conv = ai_chat_current($pdo, $tenantId);
    if (!$hadConv) ai_chat_activate($pdo, $tenantId, $conv, true);        // sesión nueva: continúa la conversación (si es reciente)
    if (!isset($_SESSION['chatbot_state'])) $_SESSION['chatbot_state'] = 'IDLE';
    chat_prefill_from_session($pdo);

    $state = (string)$_SESSION['chatbot_state'];
    $cleanMsg = mb_strtolower($message);

    // Un saludo en medio de un flujo atascado reinicia la conversación
    if ($message !== '' && !$files && ai_chat_is_greeting($cleanMsg) && !in_array($state, ['IDLE', 'AWAITING_INCIDENT'], true)) {
        $keep = [];
        foreach (array_merge(chat_contact_keys(), ['attachments', 'attachment_notes']) as $k) if (isset($_SESSION['chatbot_data'][$k])) $keep[$k] = $_SESSION['chatbot_data'][$k];
        chat_set_data($keep);
        $_SESSION['chatbot_state'] = $state = 'IDLE';
    }

    // Archivos: se guardan en CUALQUIER estado y se informa archivo por archivo
    $staged = ['saved' => [], 'rejected' => []];
    if ($files) $staged = chat_stage_files($pdo, $files);

    // Historial: guardamos el mensaje de la persona (con los nombres de sus archivos)
    $filesMeta = [];
    foreach ($staged['saved'] as $s) $filesMeta[] = ['name' => $s[0], 'size' => $s[1], 'ok' => true];
    foreach ($staged['rejected'] as $r) $filesMeta[] = ['name' => $r[0], 'ok' => false, 'error' => $r[1]];
    if ($message !== '' || $filesMeta) {
        ai_chat_add($pdo, $tenantId, $conv, 'user', $message !== '' ? $message : '📎 ' . count($filesMeta) . ' archivo(s)', $filesMeta ?: null);
    }

    $hasFileResult = !empty($staged['saved']) || !empty($staged['rejected']);

    // En cualquier estado que no sea la recogida de adjuntos, el resultado de la carga se antepone a la respuesta
    if ($hasFileResult && $state !== 'COLLECTING_ATTACHMENT') chat_note(chat_files_report($staged));

    if ($message === '' && $hasFileResult && $state !== 'COLLECTING_ATTACHMENT') {
        // Solo archivos: confirmamos y seguimos donde íbamos
        switch ($state) {
            case 'IDLE': case 'AWAITING_INCIDENT':
                chat_reply('Los guardé. Cuéntame ahora **qué está pasando** (qué equipo o aplicación y qué error ves) y los tendré en cuenta.', 'AWAITING_INCIDENT'); break;
            case 'AWAITING_KB_CONFIRMATION':
                chat_reply('Los adjuntaré a tu ticket si hace falta abrir uno. ¿Se resolvió con los pasos que te di?', 'AWAITING_KB_CONFIRMATION'); break;
            case 'DIAGNOSING':
                chat_reply('Los guardé; los adjuntaré si abrimos un ticket. Cuando puedas, responde mi pregunta para seguir.', 'DIAGNOSING'); break;
            default:
                [$s, $p] = chat_next_missing_state();
                chat_reply('Los guardé para tu ticket. ' . $p, $s);
        }
        exit;
    }

    switch ($state) {
        case 'IDLE':
        case 'AWAITING_INCIDENT':
            chat_handle_incident($message, $pdo);
            break;
        case 'DIAGNOSING':
            chat_handle_diagnosing($message, $pdo);
            break;
        case 'AWAITING_KB_CONFIRMATION':
            chat_handle_kb_confirmation($message, $pdo);
            break;
        case 'OFFER_TICKET':
            chat_handle_offer_ticket($message, $pdo);
            break;
        case 'COLLECTING_INCIDENT':
            if (mb_strlen($message) < 5) { chat_reply('Cuéntame un poco más para que el técnico entienda el problema.'); break; }
            $d = chat_data(); $d['incident'] = $message; chat_set_data($d);
            chat_go_collect();
            break;
        case 'COLLECTING_NAME':
            if (mb_strlen($message) < 3) { chat_reply('Por favor, escribe tu nombre completo.'); break; }
            $_SESSION['chatbot_data']['name'] = mb_substr($message, 0, 100);
            chat_go_collect();
            break;
        case 'COLLECTING_EMAIL':
            if (!filter_var($message, FILTER_VALIDATE_EMAIL)) { chat_reply('Ese correo no parece válido. ¿Puedes revisarlo?'); break; }
            $_SESSION['chatbot_data']['email'] = mb_substr($message, 0, 150);
            chat_go_collect();
            break;
        case 'COLLECTING_PHONE':
            if (!preg_match('/^[0-9+()\s-]{7,20}$/', $message)) { chat_reply('Escribe un teléfono válido (solo números, 7 a 20 dígitos).'); break; }
            $_SESSION['chatbot_data']['phone'] = $message;
            chat_go_collect();
            break;
        case 'COLLECTING_AREA':
            if (mb_strlen($message) < 2) { chat_reply('Indícame tu área o departamento, por favor.'); break; }
            $_SESSION['chatbot_data']['area'] = mb_substr($message, 0, 100);
            chat_go_collect();
            break;
        case 'COLLECTING_ATTACHMENT':
            chat_handle_attachment($message, $staged, $pdo);
            break;
        default:
            chat_reply('Perdí el hilo de la conversación. ¿En qué puedo ayudarte?', 'IDLE');
    }
} catch (Throwable $e) {
    error_log('chatbot_handler error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    chat_error('Ocurrió un error inesperado. Intenta de nuevo.');
}
exit;
