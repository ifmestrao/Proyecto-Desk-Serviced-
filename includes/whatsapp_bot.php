<?php

require_once __DIR__ . '/bot_config.php';
require_once __DIR__ . '/chat_media.php';

const WA_FLOW_STATES = ['WAIT_EMAIL', 'WAIT_NAME', 'WAIT_DESC', 'WAIT_ATTACH', 'WAIT_AREA', 'WAIT_PRIORITY'];


function wa_bot_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_processed (
            msg_id VARCHAR(128) NOT NULL PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Los estados nuevos (WAIT_ATTACH) requieren que la columna no sea ENUM
        $col = $pdo->query("SHOW COLUMNS FROM telegram_sessions LIKE 'state'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)$col['Type'], 'enum') === 0) {
            $pdo->exec("ALTER TABLE telegram_sessions MODIFY state VARCHAR(40) NOT NULL DEFAULT 'START'");
        }
        if (mt_rand(1, 50) === 1) {
            $pdo->exec("DELETE FROM wa_processed WHERE created_at < (NOW() - INTERVAL 3 DAY)");
        }
    } catch (Throwable $e) {
        wa_log('schema: ' . $e->getMessage());
    }
}

function wa_bot_seen(PDO $pdo, string $msg_id): bool {
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO wa_processed (msg_id) VALUES (?)");
        $st->execute([$msg_id]);
        return $st->rowCount() === 0;
    } catch (Throwable $e) {
        return false;
    }
}

function wa_bot_save(PDO $pdo, int $tid, string $from, string $state, array $data): void {
    try {
        $pdo->prepare("UPDATE telegram_sessions SET state = ?, data = ?, source = 'whatsapp', updated_at = NOW() WHERE chat_id = ? AND tenant_id = ?")
            ->execute([$state, json_encode($data, JSON_UNESCAPED_UNICODE), $from, $tid]);
    } catch (Throwable $e) {
        wa_log('save sesión: ' . $e->getMessage());
    }
}

function wa_bot_discard_files(array $data): void {
    foreach (($data['attachments'] ?? []) as $a) {
        $p = (string)($a['path'] ?? '');
        if (preg_match('#^uploads/whatsapp/\d+/[A-Za-z0-9_.\-]+$#', $p)) @unlink(cm_root() . '/' . $p);
    }
}

/** Conserva solo la identidad del usuario; descarta lo del flujo en curso. */
function wa_bot_reset_data(array $data): array {
    wa_bot_discard_files($data);
    return ['profile' => $data['profile'] ?? [], 'profile_checked' => $data['profile_checked'] ?? 0];
}

function wa_bot_notify_staff(PDO $pdo, int $tid, string $type, string $title, string $body, string $link, string $ref_type, $ref_id): void {
    if (!function_exists('notify_add')) return;
    try {
        $st = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin','supervisor','tech') AND tenant_id = ?");
        $st->execute([$tid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            notify_add($pdo, (int)$sid, $type, $title, $body, $link, $ref_type, $ref_id, $tid);
        }
    } catch (Throwable $e) {
        wa_log('notify: ' . $e->getMessage());
    }
}

function wa_bot_send_welcome(PDO $pdo, int $tid, string $to, array $cfg, array $vars): void {
    $welcome = bot_render($cfg['welcome'], $vars);
    $menu = bot_render($cfg['menu'], $vars);
    $caption = $welcome . ($menu !== '' ? "\n\n" . $menu : '');
    $logo = (!empty($cfg['send_logo']) && !empty($cfg['logo_url'])) ? $cfg['logo_url'] : '';
    if ($logo !== '') {
        if (mb_strlen($caption) <= 1000) {
            if (wa_send_image_link($to, $logo, $caption, $pdo, $tid)) return;
        } elseif (wa_send_image_link($to, $logo, $welcome, $pdo, $tid)) {
            if ($menu !== '') whatsapp_send_message($to, $menu, $pdo, $tid);
            return;
        }
    }
    whatsapp_send_message($to, $caption, $pdo, $tid);
}

/** Siguiente dato que falta. 'create' = ya se puede crear el ticket. */
function wa_bot_next_step(array $cfg, array $data): string {
    $f = $cfg['fields'];
    if (empty($data['email'])) return 'email';
    if (empty($data['full_name'])) return 'full_name';
    if (empty($data['description'])) return 'description';
    if (!empty($cfg['allow_attachments']) && empty($data['attach_done'])) return 'attach';
    if (!empty($f['area']['enabled']) && empty($data['area'])) return 'area';
    if (!empty($f['priority']['enabled']) && empty($data['priority'])) return 'priority';
    return 'create';
}

/** Envía la pregunta del paso y devuelve el estado de sesión correspondiente. */
function wa_bot_ask(PDO $pdo, int $tid, string $to, string $step, array $cfg, array $vars): string {
    $states = ['email' => 'WAIT_EMAIL', 'full_name' => 'WAIT_NAME', 'description' => 'WAIT_DESC',
               'attach' => 'WAIT_ATTACH', 'area' => 'WAIT_AREA', 'priority' => 'WAIT_PRIORITY'];
    $q = $step === 'attach' ? $cfg['attach_question'] : ($cfg['fields'][$step]['question'] ?? '');
    $q = bot_render($q, $vars);
    if ($step === 'full_name' && !empty($vars['wa_name'])) {
        $q .= "\n" . bot_render($cfg['msgs']['name_suggestion'], $vars);
    }
    whatsapp_send_message($to, $q, $pdo, $tid);
    return $states[$step] ?? 'START';
}

function wa_bot_send(PDO $pdo, int $tid, string $to, array $cfg, string $msg_key, array $vars): void {
    whatsapp_send_message($to, bot_render($cfg['msgs'][$msg_key] ?? '', $vars), $pdo, $tid);
}

/** Crea el ticket con lo reunido. Devuelve el número visible (local_id) o null. */
function wa_bot_create_ticket(PDO $pdo, int $tid, string $from, array $data, array $cfg, string $wa_name) {
    $desc = (string)$data['description'];
    $first_line = preg_split('/\R/u', $desc)[0] ?? $desc;
    $payload = [
        'full_name'   => $data['full_name'],
        'email'       => $data['email'],
        'description' => $desc,
        'area'        => $data['area'] ?? ($cfg['fields']['area']['default'] ?? 'WhatsApp'),
        'priority'    => $data['priority'] ?? ($cfg['fields']['priority']['default'] ?? 'medium'),
        'title'       => mb_substr(trim($first_line), 0, 50),
        'attachments' => $data['attachments'] ?? [],
    ];
    $ticket_id = create_ticket_from_telegram($from, $payload, $wa_name !== '' ? $wa_name : $payload['full_name'], $pdo, $tid, 'whatsapp');
    if (!$ticket_id) return null;

    $display = $ticket_id;
    try {
        $st = $pdo->prepare("SELECT local_id FROM tickets WHERE id = ?");
        $st->execute([$ticket_id]);
        $lid = $st->fetchColumn();
        if ($lid) $display = $lid;
    } catch (Throwable $e) {}

    wa_bot_notify_staff($pdo, $tid, 'ticket_new', 'Nuevo Ticket WhatsApp',
        "Se ha creado el ticket #$display vía WhatsApp.", "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id);
    return ['id' => $ticket_id, 'display' => $display];
}

/** Pasa la conversación a un agente humano (aparece en Multicanal / chat en vivo). */
function wa_bot_start_agent_chat(PDO $pdo, int $tid, string $from, array &$data, array $cfg, array $vars, string $name): string {
    $data['full_name'] = $name;
    $data['agent_chat_name'] = $name;
    $vars['nombre'] = bot_first_name($name);
    wa_bot_notify_staff($pdo, $tid, 'chat_request', 'Nuevo chat WhatsApp', "Usuario: $name esperando.", 'multicanal.php', 'chat', $from);
    wa_bot_send($pdo, $tid, $from, $cfg, 'agent_waiting', $vars);
    return 'CHAT_SUPPORT';
}

/**
 * Procesa un mensaje entrante de WhatsApp.
 * $wa_name: nombre del perfil de WhatsApp del contacto (puede venir vacío).
 */
function wa_bot_handle(PDO $pdo, int $tid, array $msg, string $wa_name): void {
    $from = (string)($msg['from'] ?? '');
    if ($from === '') return;
    $msg_id = (string)($msg['id'] ?? '');
    if ($msg_id !== '' && wa_bot_seen($pdo, $msg_id)) return;

    // ── Tipo de mensaje ──
    $type = (string)($msg['type'] ?? 'text');
    $text = '';
    $media = null;
    $unsupported = false;
    switch ($type) {
        case 'text':
            $text = trim((string)($msg['text']['body'] ?? ''));
            break;
        case 'button':
            $text = trim((string)($msg['button']['text'] ?? ''));
            break;
        case 'interactive':
            $i = $msg['interactive'] ?? [];
            $text = trim((string)($i['button_reply']['title'] ?? $i['list_reply']['title'] ?? ''));
            break;
        case 'image':
            $media = ['id' => (string)($msg['image']['id'] ?? ''), 'name' => '', 'caption' => trim((string)($msg['image']['caption'] ?? ''))];
            break;
        case 'document':
            $media = ['id' => (string)($msg['document']['id'] ?? ''), 'name' => (string)($msg['document']['filename'] ?? ''), 'caption' => trim((string)($msg['document']['caption'] ?? ''))];
            break;
        case 'reaction':
        case 'system':
        case 'request_welcome':
            return;   // no requieren respuesta
        default:
            $unsupported = true;
    }

    // ── Sesión ──
    $st = $pdo->prepare("SELECT * FROM telegram_sessions WHERE chat_id = ? AND tenant_id = ?");
    $st->execute([$from, $tid]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    $is_new = false;
    $updated_at = null;
    if (!$session) {
        $pdo->prepare("INSERT INTO telegram_sessions (chat_id, tenant_id, source, state, data) VALUES (?, ?, 'whatsapp', 'START', ?)")
            ->execute([$from, $tid, '{}']);
        $state = 'START';
        $data = [];
        $is_new = true;
    } else {
        $state = (string)($session['state'] ?: 'START');
        $data = json_decode((string)($session['data'] ?? ''), true);
        if (!is_array($data)) $data = [];
        $updated_at = $session['updated_at'] ?? null;
    }

    // ── Historial (lo que ve el agente en el chat en vivo) ──
    if ($media === null && $text !== '') cm_log_chat($pdo, $tid, 'whatsapp', $from, 'in', $text);

    // ── Configuración e identidad ──
    $cfg = bot_get_config($pdo, $tid);
    $company = bot_company_name($pdo, $tid, $cfg);

    if (empty($data['profile']['email']) && empty($data['profile']['user_id']) && (int)($data['profile_checked'] ?? 0) < time() - 3600) {
        $data['profile_checked'] = time();
        $u = bot_find_user_by_phone($pdo, $tid, $from);
        if ($u) {
            $data['profile'] = ['user_id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => (string)$u['email'], 'area' => (string)($u['area'] ?? ''), 'by' => 'phone'];
        }
    }
    $profile = $data['profile'] ?? [];
    $vars = [
        'nombre'          => bot_first_name((string)($profile['name'] ?? '')) ?: bot_first_name($wa_name),
        'nombre_completo' => (string)($profile['name'] ?? ''),
        'correo'          => (string)($profile['email'] ?? ''),
        'empresa'         => $company,
        'wa_name'         => $wa_name,
    ];

    $cmd = mb_strtoupper(trim($text), 'UTF-8');
    $cmdc = trim(rtrim($cmd, '.!¡¿?'));
    $in_flow = in_array($state, WA_FLOW_STATES, true) || $state === 'WAIT_NAME_AGENT';
    $is_greeting = (bool)preg_match('/^(\/?START|HOLA|OLA|MENU|MENÚ|BUENAS|BUENOS DIAS|BUENOS DÍAS|BUENAS TARDES|BUENAS NOCHES|HI|HELLO)$/u', $cmdc);

    if ($unsupported) {
        wa_bot_send($pdo, $tid, $from, $cfg, 'unsupported', $vars);
        wa_bot_save($pdo, $tid, $from, $state, $data);
        return;
    }

    // ── Cancelar / salir ──
    if ($in_flow && in_array($cmdc, ['CANCELAR', 'CANCEL', 'SALIR'], true)) {
        $data = wa_bot_reset_data($data);
        wa_bot_send($pdo, $tid, $from, $cfg, 'cancelled', $vars);
        wa_bot_save($pdo, $tid, $from, 'START', $data);
        return;
    }
    if ($state === 'CHAT_SUPPORT' && in_array($cmdc, ['SALIR', 'FIN', 'TERMINAR'], true)) {
        wa_bot_send($pdo, $tid, $from, $cfg, 'chat_ended', $vars);
        wa_bot_save($pdo, $tid, $from, 'START', wa_bot_reset_data($data));
        return;
    }

    // ── Archivos adjuntos ──
    if ($media !== null) {
        if (in_array($state, WA_FLOW_STATES, true)) {
            $n = count($data['attachments'] ?? []);
            if (empty($cfg['allow_attachments'])) {
                wa_bot_send($pdo, $tid, $from, $cfg, 'unsupported', $vars);
            } elseif ($n >= CM_MAX_TICKET_FILES) {
                wa_bot_send($pdo, $tid, $from, $cfg, 'attachment_limit', $vars + ['max' => CM_MAX_TICKET_FILES]);
            } else {
                $f = wa_media_fetch($pdo, $tid, $media['id'], 'uploads/whatsapp/' . $tid, false, $media['name']);
                if ($f) {
                    $data['attachments'][] = ['name' => $f['name'], 'path' => $f['path'], 'mime' => $f['mime']];
                    wa_bot_send($pdo, $tid, $from, $cfg, 'attachment_saved', $vars + ['n' => $n + 1]);
                } else {
                    wa_bot_send($pdo, $tid, $from, $cfg, 'attachment_rejected', $vars);
                }
            }
        } elseif ($state === 'CHAT_SUPPORT' || $state === 'WAIT_NAME_AGENT') {
            $f = wa_media_fetch($pdo, $tid, $media['id'], 'uploads/chat/' . $tid, true, $media['name']);
            if ($f) cm_log_chat($pdo, $tid, 'whatsapp', $from, 'in', cm_chat_marker($f['kind'], $f['path'], $f['name'], $media['caption']));
            else wa_bot_send($pdo, $tid, $from, $cfg, 'attachment_rejected', $vars);
        } else {
            wa_bot_send($pdo, $tid, $from, $cfg, 'media_in_menu', $vars);
        }
        wa_bot_save($pdo, $tid, $from, $state, $data);
        return;
    }

    // ── Chat con agente: el bot no interviene ──
    if ($state === 'CHAT_SUPPORT') {
        wa_bot_save($pdo, $tid, $from, $state, $data);
        return;
    }

    // ── Saludo / reinicio ──
    $stale = $updated_at && (strtotime((string)$updated_at) < time() - 12 * 3600);
    if ($is_greeting) {
        $data = wa_bot_reset_data($data);
        wa_bot_send_welcome($pdo, $tid, $from, $cfg, $vars);
        wa_bot_save($pdo, $tid, $from, 'START', $data);
        return;
    }

    $is_opt1 = ($cmdc === '1' || (bool)preg_match('/\bTICKET\b/u', $cmdc));
    $is_opt2 = ($cmdc === '2' || (bool)preg_match('/\b(AGENTE|ASESOR|HUMANO)\b/u', $cmdc));

    // Primer contacto (o tras 12 h de inactividad) con un texto libre: saludo con logo y, si trae una duda, también se responde.
    if ($state === 'START' && ($is_new || $stale) && !$is_opt1 && !$is_opt2) {
        wa_bot_send_welcome($pdo, $tid, $from, $cfg, $vars);
        if (mb_strlen($text) >= 3) {
            $ai = get_telegram_ai_response($text, $pdo, $tid, 'whatsapp');
            whatsapp_send_message($from, "🤖 *Asistente Virtual*:\n\n$ai\n\n" . bot_render($cfg['msgs']['ai_footer'], $vars), $pdo, $tid);
        }
        wa_bot_save($pdo, $tid, $from, 'START', $data);
        return;
    }

    // ── Máquina de estados ──
    $advance = false;
    switch ($state) {
        case 'WAIT_EMAIL':
            $email = mb_strtolower(trim($text));
            if (!bot_valid_email($email)) { wa_bot_send($pdo, $tid, $from, $cfg, 'invalid_email', $vars); break; }
            $data['email'] = $email;
            $u = bot_find_user_by_email($pdo, $tid, $email);
            if ($u) {
                if (empty($data['full_name']) && !empty($u['name'])) $data['full_name'] = (string)$u['name'];
                if (empty($data['area']) && !empty($u['area'])) $data['area'] = (string)$u['area'];
                $data['profile'] = ['user_id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => $email, 'area' => (string)($u['area'] ?? ''), 'by' => 'email'];
                wa_bot_send($pdo, $tid, $from, $cfg, 'email_found', $vars);
            }
            $advance = true;
            break;

        case 'WAIT_NAME':
            $t = $text;
            if ($wa_name !== '' && preg_match('/^(SI|SÍ|S|OK|CLARO|YES)$/u', $cmdc)) $t = $wa_name;
            $min = (int)($cfg['fields']['full_name']['min'] ?? 5);
            if (!bot_valid_text($t, $min)) { wa_bot_send($pdo, $tid, $from, $cfg, 'invalid_text', $vars + ['min' => $min]); break; }
            $data['full_name'] = mb_substr(trim($t), 0, 100);
            $advance = true;
            break;

        case 'WAIT_DESC':
            $min = (int)($cfg['fields']['description']['min'] ?? 10);
            if (!bot_valid_text($text, $min)) { wa_bot_send($pdo, $tid, $from, $cfg, 'invalid_text', $vars + ['min' => $min]); break; }
            $data['description'] = mb_substr(trim($text), 0, 2000);
            $advance = true;
            break;

        case 'WAIT_ATTACH':
            if (preg_match('/^(LISTO|NO|NINGUNO|OMITIR|SIN ADJUNTOS?|CONTINUAR|SIGUIENTE|YA)$/u', $cmdc)) {
                $data['attach_done'] = 1;
                $advance = true;
            } else {
                wa_bot_send($pdo, $tid, $from, $cfg, 'attach_hint', $vars);
            }
            break;

        case 'WAIT_AREA':
            $min = (int)($cfg['fields']['area']['min'] ?? 2);
            if (!bot_valid_text($text, $min)) { wa_bot_send($pdo, $tid, $from, $cfg, 'invalid_text', $vars + ['min' => $min]); break; }
            $data['area'] = mb_substr(trim($text), 0, 60);
            $advance = true;
            break;

        case 'WAIT_PRIORITY':
            $p = bot_parse_priority($text);
            if (!$p) { wa_bot_send($pdo, $tid, $from, $cfg, 'invalid_priority', $vars); break; }
            $data['priority'] = $p;
            $advance = true;
            break;

        case 'WAIT_NAME_AGENT':
            $min = (int)($cfg['fields']['full_name']['min'] ?? 5);
            $t = ($wa_name !== '' && preg_match('/^(SI|SÍ|S|OK|CLARO|YES)$/u', $cmdc)) ? $wa_name : $text;
            if (!bot_valid_text($t, $min)) { wa_bot_send($pdo, $tid, $from, $cfg, 'invalid_text', $vars + ['min' => $min]); break; }
            $state = wa_bot_start_agent_chat($pdo, $tid, $from, $data, $cfg, $vars, mb_substr(trim($t), 0, 100));
            break;

        default: // START (o estado desconocido)
            $state = 'START';
            if ($is_opt1) {
                $data = wa_bot_reset_data($data);
                $data['attachments'] = [];
                if (!empty($profile['email']))     $data['email'] = $profile['email'];
                if (!empty($profile['name']))      $data['full_name'] = $profile['name'];
                if (!empty($profile['area']))      $data['area'] = $profile['area'];
                wa_bot_send($pdo, $tid, $from, $cfg, 'ticket_start', $vars);
                if (($profile['by'] ?? '') === 'phone' && !empty($profile['name'])) {
                    wa_bot_send($pdo, $tid, $from, $cfg, 'recognized', $vars);
                }
                $advance = true;
            } elseif ($is_opt2) {
                $known = trim((string)($profile['name'] ?? ''));
                if ($known !== '') {
                    $state = wa_bot_start_agent_chat($pdo, $tid, $from, $data, $cfg, $vars, $known);
                } else {
                    $q = bot_render($cfg['msgs']['agent_ask_name'], $vars);
                    if ($wa_name !== '') $q .= "\n" . bot_render($cfg['msgs']['name_suggestion'], $vars);
                    whatsapp_send_message($from, $q, $pdo, $tid);
                    $state = 'WAIT_NAME_AGENT';
                }
            } elseif (mb_strlen($text) >= 2) {
                $ai = get_telegram_ai_response($text, $pdo, $tid, 'whatsapp');
                whatsapp_send_message($from, "🤖 *Asistente Virtual*:\n\n$ai\n\n" . bot_render($cfg['msgs']['ai_footer'], $vars), $pdo, $tid);
            }
            break;
    }

    if ($advance) {
        $step = wa_bot_next_step($cfg, $data);
        if ($step === 'create') {
            $res = wa_bot_create_ticket($pdo, $tid, $from, $data, $cfg, $wa_name);
            if ($res) {
                wa_bot_send($pdo, $tid, $from, $cfg, 'ticket_created', $vars + ['ticket' => $res['display']]);
                $new_profile = $data['profile'] ?? [];
                $new_profile['name']  = $data['full_name'];
                $new_profile['email'] = $data['email'];
                if (!empty($data['area'])) $new_profile['area'] = $data['area'];
                $new_profile['by'] = $new_profile['by'] ?? 'chat';
                $data = ['profile' => $new_profile, 'profile_checked' => time()];   // los archivos ya pertenecen al ticket
            } else {
                wa_bot_send($pdo, $tid, $from, $cfg, 'ticket_error', $vars);
                $data = wa_bot_reset_data($data);
            }
            $state = 'START';
        } else {
            $state = wa_bot_ask($pdo, $tid, $from, $step, $cfg, $vars);
        }
    }

    wa_bot_save($pdo, $tid, $from, $state, $data);
}