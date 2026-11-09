<?php

require_once __DIR__ . '/tenant_settings.php';

/** Prioridades: número/palabra => valor interno. */
function bot_priority_map(): array {
    return ['1' => 'low', '2' => 'medium', '3' => 'high', '4' => 'critical'];
}

function bot_default_config(): array {
    return [
        'company_name' => '',                    // vacío = nombre de la organización
        'logo_url'     => '',                    // imagen que se envía con el saludo
        'send_logo'    => 1,
        'welcome'      => "¡Hola {nombre}! 👋 Soy el asistente virtual de {empresa}.\n¿En qué puedo ayudarte hoy?",
        'menu'         => "Escribe una opción:\n*1.* Crear un ticket\n*2.* Hablar con un agente\n\nO escríbeme tu duda y trataré de resolverla.",
        'fields' => [
            // email, full_name y description siempre se piden (el ticket los necesita). area y priority son opcionales.
            'email'       => ['enabled' => 1, 'question' => "Por favor, dime tu *correo electrónico*:", 'min' => 0],
            'full_name'   => ['enabled' => 1, 'question' => "¿Cuál es tu *nombre completo*?", 'min' => 5],
            'description' => ['enabled' => 1, 'question' => "Describe brevemente tu problema:", 'min' => 10],
            'area'        => ['enabled' => 1, 'question' => "¿A qué área perteneces? (Ej: Contabilidad, Ventas, Sistemas)", 'min' => 2, 'default' => 'WhatsApp'],
            'priority'    => ['enabled' => 1, 'question' => "Selecciona la prioridad:\n*1.* Baja\n*2.* Media\n*3.* Alta\n*4.* Crítica", 'default' => 'medium'],
        ],
        'allow_attachments' => 1,
        'attach_question'   => "📎 ¿Quieres adjuntar una imagen o un archivo (PDF, Word, Excel)? Envíalo ahora o escribe *LISTO* para continuar.",
        'msgs' => [
            'recognized'          => "Te identifiqué como *{nombre_completo}* ✅",
            'email_found'         => "Encontré tu correo en la plataforma ✅",
            'name_suggestion'     => "_Responde *SI* para usar «{wa_name}»_",
            'ticket_start'        => "Perfecto, vamos a crear tu ticket. Puedes escribir *CANCELAR* en cualquier momento.",
            'invalid_email'       => "Ese correo no parece válido. Inténtalo de nuevo (ej: nombre@empresa.com).",
            'invalid_text'        => "Necesito un poco más de detalle (mínimo {min} caracteres).",
            'invalid_priority'    => "Selecciona un número del 1 al 4.",
            'attach_hint'         => "Envía una imagen o archivo, o escribe *LISTO* para continuar.",
            'attachment_saved'    => "✅ Archivo recibido ({n}). Puedes enviar otro o escribir *LISTO*.",
            'attachment_limit'    => "Ya recibí el máximo de {max} archivos. Escribe *LISTO* para continuar.",
            'attachment_rejected' => "No pude recibir ese archivo. Envía una imagen (JPG/PNG/WEBP) o un PDF/Word/Excel de hasta 10 MB.",
            'media_in_menu'       => "Para enviar archivos primero elige *1* (crear ticket) o *2* (hablar con un agente).",
            'unsupported'         => "Por ahora solo puedo recibir texto, imágenes y documentos.",
            'ticket_created'      => "✅ ¡Ticket *#{ticket}* creado exitosamente! Te avisaremos cuando haya novedades.",
            'ticket_error'        => "❌ No pude crear el ticket. Inténtalo de nuevo en unos minutos.",
            'agent_ask_name'      => "Por favor, dime tu *nombre completo* para que un agente te atienda:",
            'agent_waiting'       => "🔔 Gracias, {nombre}. Un agente se unirá pronto. Puedes ir escribiendo tu consulta.",
            'chat_ended'          => "Chat finalizado. Escribe *HOLA* cuando necesites ayuda de nuevo.",
            'cancelled'           => "Listo, cancelé el proceso. Escribe *HOLA* para empezar de nuevo.",
            'ai_footer'           => "¿Esto resolvió tu duda? Si no, escribe *1* para crear un ticket o *2* para hablar con un agente.",
        ],
    ];
}

/** Configuración efectiva del tenant (predeterminada + personalizada). */
function bot_get_config(PDO $pdo, int $tenant_id): array {
    $stored = ts_get($pdo, $tenant_id, 'bot_config', []);
    return array_replace_recursive(bot_default_config(), bot_sanitize_config($stored, false));
}

/**
 * Limpia datos del formulario (o de la base). Solo conserva claves conocidas.
 * $strict=true: se usa al guardar desde el formulario (los interruptores ausentes = apagados).
 */
function bot_sanitize_config(array $in, bool $strict = true): array {
    $def = bot_default_config();
    $out = [];
    $clean = function ($v, int $max) {
        $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
        return mb_substr(trim($v), 0, $max);
    };

    foreach (['company_name' => 60, 'welcome' => 600, 'menu' => 600, 'attach_question' => 400] as $k => $max) {
        if (array_key_exists($k, $in)) {
            $v = $clean($in[$k], $max);
            if ($v !== '' || $k === 'company_name') $out[$k] = $v;
        }
    }
    if (array_key_exists('logo_url', $in)) {
        $u = $clean($in['logo_url'], 500);
        if ($u === '' || (preg_match('#^https://#i', $u) && filter_var($u, FILTER_VALIDATE_URL))) $out['logo_url'] = $u;
    }
    foreach (['send_logo', 'allow_attachments'] as $k) {
        if ($strict) $out[$k] = !empty($in[$k]) ? 1 : 0;
        elseif (array_key_exists($k, $in)) $out[$k] = !empty($in[$k]) ? 1 : 0;
    }

    if (isset($in['fields']) && is_array($in['fields'])) {
        foreach ($def['fields'] as $key => $fdef) {
            if (!isset($in['fields'][$key]) || !is_array($in['fields'][$key])) continue;
            $f = $in['fields'][$key];
            $q = $clean($f['question'] ?? '', 400);
            if ($q !== '') $out['fields'][$key]['question'] = $q;
            if (isset($fdef['min']) && isset($f['min'])) $out['fields'][$key]['min'] = max(0, min(500, (int)$f['min']));
            if ($key === 'area' || $key === 'priority') {
                if ($strict) $out['fields'][$key]['enabled'] = !empty($f['enabled']) ? 1 : 0;
                elseif (array_key_exists('enabled', $f)) $out['fields'][$key]['enabled'] = !empty($f['enabled']) ? 1 : 0;
            }
            if ($key === 'area' && isset($f['default'])) {
                $d = $clean($f['default'], 60);
                if ($d !== '') $out['fields']['area']['default'] = $d;
            }
            if ($key === 'priority' && isset($f['default']) && in_array($f['default'], bot_priority_map(), true)) {
                $out['fields']['priority']['default'] = $f['default'];
            }
        }
    }
    if (isset($in['msgs']) && is_array($in['msgs'])) {
        foreach ($def['msgs'] as $k => $_) {
            if (isset($in['msgs'][$k])) {
                $v = $clean($in['msgs'][$k], 500);
                if ($v !== '') $out['msgs'][$k] = $v;
            }
        }
    }
    return $out;
}

/** Reemplaza {variables}. Si una variable viene vacía, también quita el espacio previo ("¡Hola !" => "¡Hola!"). */
function bot_render(string $tpl, array $vars): string {
    $out = preg_replace_callback('/([ \t]?)\{(\w+)\}/u', function ($m) use ($vars) {
        if (!array_key_exists($m[2], $vars)) return $m[0];
        $v = (string)$vars[$m[2]];
        return $v === '' ? '' : $m[1] . $v;
    }, $tpl);
    return trim($out);
}

function bot_first_name(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    $parts = preg_split('/\s+/u', $name);
    return $parts[0] ?? '';
}

/** Nombre de la organización para el saludo. */
function bot_company_name(PDO $pdo, int $tenant_id, array $cfg): string {
    if (!empty($cfg['company_name'])) return $cfg['company_name'];
    try {
        $st = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $st->execute([$tenant_id]);
        $n = $st->fetchColumn();
        if ($n) return (string)$n;
    } catch (Throwable $e) {}
    return 'Desk Service';
}

// ── Validaciones ──────────────────────────────────────────────

function bot_valid_email(string $email): bool {
    return strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function bot_valid_text(string $text, int $min): bool {
    return mb_strlen(trim($text)) >= max(1, $min);
}

/** Convierte "3", "alta", "crítica"... en 'high', etc. null si no es válida. */
function bot_parse_priority(string $text): ?string {
    $map = bot_priority_map();
    $t = mb_strtolower(trim($text));
    $t = strtr($t, ['í' => 'i', 'á' => 'a', 'é' => 'e', 'ó' => 'o', 'ú' => 'u']);
    $digits = preg_replace('/[^1-4]/', '', $t);
    if (strlen($digits) === 1 && preg_match('/^\s*[1-4][\.\)]?\s*$/', $t)) return $map[$digits];
    $words = ['baja' => 'low', 'media' => 'medium', 'normal' => 'medium', 'alta' => 'high', 'urgente' => 'high', 'critica' => 'critical'];
    return $words[$t] ?? null;
}

// ── Reconocimiento de usuarios ────────────────────────────────

function bot_digits(string $s): string {
    return preg_replace('/\D+/', '', $s);
}

/** Busca un usuario del tenant por teléfono (compara los últimos 7-10 dígitos). */
function bot_find_user_by_phone(PDO $pdo, int $tenant_id, string $phone): ?array {
    $d = bot_digits($phone);
    if (strlen($d) < 7) return null;
    $rows = [];
    try {
        $st = $pdo->prepare("SELECT id, name, email, phone, area FROM users
                             WHERE tenant_id = ? AND phone IS NOT NULL AND phone <> '' AND (is_active IS NULL OR is_active = 1) LIMIT 5000");
        $st->execute([$tenant_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {   // instalaciones sin columnas is_active / area
            $st = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE tenant_id = ? AND phone IS NOT NULL AND phone <> '' LIMIT 5000");
            $st->execute([$tenant_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) { return null; }
    }
    foreach ($rows as $u) {
        $pd = bot_digits((string)$u['phone']);
        $n = min(strlen($pd), strlen($d), 10);
        if ($n >= 7 && substr($pd, -$n) === substr($d, -$n)) {
            $u['area'] = $u['area'] ?? '';
            return $u;
        }
    }
    return null;
}

function bot_find_user_by_email(PDO $pdo, int $tenant_id, string $email): ?array {
    try {
        $st = $pdo->prepare("SELECT id, name, email, area FROM users WHERE tenant_id = ? AND email = ? AND email <> 'autoservicio@deskservice.com' LIMIT 1");
        $st->execute([$tenant_id, $email]);
    } catch (Throwable $e) {
        try {
            $st = $pdo->prepare("SELECT id, name, email FROM users WHERE tenant_id = ? AND email = ? AND email <> 'autoservicio@deskservice.com' LIMIT 1");
            $st->execute([$tenant_id, $email]);
        } catch (Throwable $e2) { return null; }
    }
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    $u['area'] = $u['area'] ?? '';
    return $u;
}