<?php


const CM_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB
const CM_MAX_TICKET_FILES = 5;

/** mime => extensión permitida (sin ejecutables, scripts ni SVG). */
function cm_allowed_mimes(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt',
        'text/csv'   => 'csv',
    ];
}

function cm_root(): string { return dirname(__DIR__); }

/** Crea la carpeta (relativa a la raíz web). Si es protegida, impide el acceso directo por URL. */
function cm_ensure_dir(string $rel_dir, bool $protected): ?string {
    $abs = cm_root() . '/' . trim($rel_dir, '/');
    if (!is_dir($abs) && !@mkdir($abs, 0755, true)) return null;
    if ($protected) {
        $ht = cm_root() . '/uploads/chat/.htaccess';
        if (is_dir(dirname($ht)) && !file_exists($ht)) {
            @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        }
    }
    return $abs;
}

/** Detecta el mime real del contenido y valida que sea uno de los permitidos. Devuelve [mime, ext] o null. */
function cm_detect_mime(string $bin, string $declared = ''): ?array {
    $allowed = cm_allowed_mimes();
    $declared = strtolower(trim(explode(';', $declared)[0]));
    $real = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $real = strtolower((string)finfo_buffer($fi, $bin));
        finfo_close($fi);
    }
    $mime = $real;
    // Office modernos y antiguos suelen detectarse como zip / cdf / octet-stream
    $office_ok = ['application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/x-tika-ooxml', 'application/x-tika-msoffice'];
    if (isset($allowed[$declared]) && ($real === '' || $real === $declared || in_array($real, $office_ok, true))) {
        $mime = $declared;
    }
    if (!isset($allowed[$mime])) return null;

    if (strpos($mime, 'image/') === 0 && @getimagesizefromstring($bin) === false) return null;
    if ($mime === 'application/pdf' && strpos(substr($bin, 0, 1024), '%PDF') === false) return null;
    if (strpos($mime, 'openxmlformats') !== false && substr($bin, 0, 2) !== 'PK') return null;
    if (in_array($mime, ['text/plain', 'text/csv'], true) && preg_match('/<\?php|<\?=|<script/i', substr($bin, 0, 4096))) return null;
    return [$mime, $allowed[$mime]];
}

/** Nombre visible seguro (sin rutas ni caracteres raros). */
function cm_safe_display_name(string $name, string $ext): string {
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^\p{L}\p{N}_\- ]+/u', '', $base);
    $base = trim(mb_substr($base, 0, 60));
    if ($base === '') $base = 'archivo';
    return $base . '.' . $ext;
}

/** Llamada simple a la Graph API. Devuelve ['http'=>int,'json'=>array|null,'err'=>string]. */
function cm_graph(string $method, string $path, string $token, $body = null, bool $json = true): array {
    $url = 'https://graph.facebook.com/' . WA_GRAPH_VERSION . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($json) { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        else { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }   // multipart
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['http' => $http, 'json' => is_string($raw) ? json_decode($raw, true) : null, 'err' => $err];
}

/**
 * Descarga un archivo recibido por WhatsApp (media id) y lo guarda.
 * $rel_dir: carpeta relativa (p. ej. uploads/whatsapp/2). $protected: bloquear acceso directo por URL.
 * Devuelve ['path','name','mime','kind'(image|file),'size'] o null.
 */
function wa_media_fetch(PDO $pdo, int $tenant_id, string $media_id, string $rel_dir, bool $protected, string $orig_name = ''): ?array {
    $cfg = get_whatsapp_config($pdo, $tenant_id);
    $token = (string)($cfg['api_key'] ?? '');
    if ($token === '' || $media_id === '') return null;

    $meta = cm_graph('GET', rawurlencode($media_id), $token);
    $url = $meta['json']['url'] ?? '';
    if ($meta['http'] !== 200 || $url === '') { wa_log('MEDIA meta fallo http=' . $meta['http']); return null; }
    if ((int)($meta['json']['file_size'] ?? 0) > CM_MAX_BYTES) return null;
    if (!preg_match('#^https://#i', $url)) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $bin = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !is_string($bin) || $bin === '' || strlen($bin) > CM_MAX_BYTES) { wa_log('MEDIA descarga fallo http=' . $http); return null; }

    $det = cm_detect_mime($bin, (string)($meta['json']['mime_type'] ?? ''));
    if (!$det) { wa_log('MEDIA tipo no permitido: ' . ($meta['json']['mime_type'] ?? '?')); return null; }
    [$mime, $ext] = $det;

    $abs = cm_ensure_dir($rel_dir, $protected);
    if (!$abs) return null;
    $file = bin2hex(random_bytes(10)) . '.' . $ext;
    if (@file_put_contents($abs . '/' . $file, $bin) === false) return null;

    $is_img = strpos($mime, 'image/') === 0;
    $display = $orig_name !== '' ? cm_safe_display_name($orig_name, $ext) : ($is_img ? 'imagen.' . $ext : 'archivo.' . $ext);
    return ['path' => trim($rel_dir, '/') . '/' . $file, 'name' => $display, 'mime' => $mime, 'kind' => $is_img ? 'image' : 'file', 'size' => strlen($bin)];
}

/** Envía una imagen por URL pública con texto debajo (para el saludo con logo). */
function wa_send_image_link(string $to, string $image_url, string $caption, PDO $pdo, int $tenant_id): bool {
    $cfg = get_whatsapp_config($pdo, $tenant_id);
    if (empty($cfg['api_key']) || empty($cfg['phone_id'])) return false;
    $r = cm_graph('POST', $cfg['phone_id'] . '/messages', $cfg['api_key'], [
        'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'image',
        'image' => ['link' => $image_url, 'caption' => mb_substr($caption, 0, 1000)],
    ]);
    if ($r['http'] !== 200) { wa_log('ENVIO IMAGEN FALLO http=' . $r['http'] . ' ' . json_encode($r['json'])); return false; }
    try {
        $pdo->prepare("INSERT INTO telegram_logs (tenant_id, source, chat_id, direction, message) VALUES (?, 'whatsapp', ?, 'out', ?)")
            ->execute([$tenant_id, $to, $caption]);
    } catch (Throwable $e) {}
    return true;
}

/** Sube un archivo local a WhatsApp y lo envía al contacto. Devuelve [ok, error, code]. */
function wa_send_file(PDO $pdo, int $tenant_id, string $to, string $abs_path, string $mime, string $caption, string $filename): array {
    $cfg = get_whatsapp_config($pdo, $tenant_id);
    if (empty($cfg['api_key']) || empty($cfg['phone_id'])) return [false, 'WhatsApp no está configurado o activo.', 0];

    $up = cm_graph('POST', $cfg['phone_id'] . '/media', $cfg['api_key'], [
        'messaging_product' => 'whatsapp',
        'type' => $mime,
        'file' => new CURLFile($abs_path, $mime, $filename),
    ], false);
    $media_id = $up['json']['id'] ?? '';
    if ($up['http'] !== 200 || $media_id === '') {
        wa_log('SUBIDA MEDIA FALLO http=' . $up['http'] . ' ' . json_encode($up['json']));
        return [false, $up['json']['error']['message'] ?? 'No se pudo subir el archivo a WhatsApp.', (int)($up['json']['error']['code'] ?? 0)];
    }

    $is_img = in_array($mime, ['image/jpeg', 'image/png'], true);
    $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $is_img ? 'image' : 'document'];
    if ($is_img) {
        $payload['image'] = ['id' => $media_id] + ($caption !== '' ? ['caption' => mb_substr($caption, 0, 1000)] : []);
    } else {
        $payload['document'] = ['id' => $media_id, 'filename' => $filename] + ($caption !== '' ? ['caption' => mb_substr($caption, 0, 1000)] : []);
    }
    $r = cm_graph('POST', $cfg['phone_id'] . '/messages', $cfg['api_key'], $payload);
    if ($r['http'] !== 200) {
        wa_log('ENVIO ARCHIVO FALLO http=' . $r['http'] . ' ' . json_encode($r['json']));
        $code = (int)($r['json']['error']['code'] ?? 0);
        $msg = $code === 131047
            ? 'Pasaron más de 24 h desde el último mensaje del cliente: WhatsApp solo permite enviar una plantilla aprobada.'
            : ($r['json']['error']['message'] ?? 'WhatsApp rechazó el envío.');
        return [false, $msg, $code];
    }
    return [true, null, 0];
}

/** Envía un archivo a un chat de Telegram. */
function telegram_send_file(PDO $pdo, int $tenant_id, string $chat_id, string $abs_path, string $mime, string $caption, string $filename): array {
    $token = get_telegram_config($pdo, $tenant_id);
    if (empty($token)) return [false, 'Telegram no está configurado.', 0];
    $is_img = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    $method = $is_img ? 'sendPhoto' : 'sendDocument';
    $field = $is_img ? 'photo' : 'document';
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat_id, $field => new CURLFile($abs_path, $mime, $filename)] + ($caption !== '' ? ['caption' => mb_substr($caption, 0, 1000)] : []),
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['ok'])) return [false, $res['description'] ?? 'Telegram rechazó el envío.', (int)($res['error_code'] ?? 0)];
    return [true, null, 0];
}

// ── Marcadores que se guardan en telegram_logs.message ────────

/** [[img:ruta]] o [[file:ruta|nombre]] + salto de línea + texto opcional. */
function cm_chat_marker(string $kind, string $rel_path, string $name = '', string $caption = ''): string {
    $name = str_replace(['|', ']', "\n", "\r"], ' ', $name);
    $m = '[[' . ($kind === 'image' ? 'img' : 'file') . ':' . $rel_path . ($kind === 'image' ? '' : '|' . $name) . ']]';
    return $caption !== '' ? $m . "\n" . $caption : $m;
}

/** Guarda un mensaje en el historial del chat de soporte. */
function cm_log_chat(PDO $pdo, int $tenant_id, string $source, string $chat_id, string $direction, string $message): void {
    try {
        $pdo->prepare("INSERT INTO telegram_logs (tenant_id, source, chat_id, direction, message) VALUES (?, ?, ?, ?, ?)")
            ->execute([$tenant_id, $source, $chat_id, $direction, $message]);
    } catch (Throwable $e) {
        error_log('cm_log_chat: ' . $e->getMessage());
    }
}