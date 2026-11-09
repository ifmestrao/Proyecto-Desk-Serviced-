<?php
/**
 * security.php — utilidades de seguridad compartidas.
 * Rate limiting, códigos seguros, secretos de webhook, validación de archivos.
 */
require_once __DIR__ . '/../config/env.php';

if (!function_exists('client_ip')) {
    function client_ip(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/** Secreto maestro de la app (para derivar secretos de webhook). Define APP_KEY en config/local.env. */
if (!function_exists('app_secret')) {
    function app_secret(): string {
        $k = app_env('APP_KEY', '');
        if ($k === '') $k = app_env('DB_PASS', '');
        if ($k === '') $k = 'cambia-esta-clave-en-local-env';
        return $k;
    }
}

/** Código numérico de 6 dígitos criptográficamente seguro. */
if (!function_exists('secure_code')) {
    function secure_code(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Rate limit por clave. Devuelve true si se permite, false si se excedió.
 * $max intentos dentro de $window segundos.
 */
if (!function_exists('rate_limit_hit')) {
    function rate_limit_hit(PDO $pdo, string $key, int $max, int $window): bool {
        static $created = false;
        try {
            if (!$created) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                    k CHAR(64) NOT NULL PRIMARY KEY,
                    hits INT NOT NULL DEFAULT 0,
                    window_start INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $created = true;
            }
            $k = hash('sha256', $key);
            $now = time();
            $st = $pdo->prepare("SELECT hits, window_start FROM rate_limits WHERE k = ?");
            $st->execute([$k]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($now - (int)$row['window_start']) > $window) {
                $pdo->prepare("REPLACE INTO rate_limits (k, hits, window_start) VALUES (?, 1, ?)")->execute([$k, $now]);
                return true;
            }
            if ((int)$row['hits'] >= $max) return false;
            $pdo->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE k = ?")->execute([$k]);
            return true;
        } catch (Throwable $e) {
            return true; // si falla el almacenamiento no bloqueamos el servicio
        }
    }
}

if (!function_exists('rate_limit_reset')) {
    function rate_limit_reset(PDO $pdo, string $key): void {
        try { $pdo->prepare("DELETE FROM rate_limits WHERE k = ?")->execute([hash('sha256', $key)]); } catch (Throwable $e) {}
    }
}

/** Secreto de webhook de Telegram ligado a un tenant (se envía como secret_token en setWebhook). */
if (!function_exists('telegram_webhook_secret')) {
    function telegram_webhook_secret(int $tenant_id): string {
        return substr(hash_hmac('sha256', 'tg|' . $tenant_id, app_secret()), 0, 48);
    }
}

/** Valida la cabecera secreta de Telegram. Termina la petición si no coincide. */
if (!function_exists('require_telegram_signature')) {
    function require_telegram_signature(int $tenant_id): void {
        if (app_env('WEBHOOK_ALLOW_UNSIGNED', '0') === '1') return;
        $got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if ($got === '' || !hash_equals(telegram_webhook_secret($tenant_id), $got)) {
            http_response_code(403);
            exit('forbidden');
        }
    }
}

/** Valida X-Hub-Signature-256 de Meta/WhatsApp con el App Secret. */
if (!function_exists('require_whatsapp_signature')) {
    function require_whatsapp_signature(string $rawBody, ?string $tenantAppSecret = null): void {
        if (app_env('WEBHOOK_ALLOW_UNSIGNED', '0') === '1') return;
        $secret = $tenantAppSecret ?: app_env('WHATSAPP_APP_SECRET', '');
        $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if ($secret === '' || $sig === '') { http_response_code(403); exit('forbidden'); }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $sig)) { http_response_code(403); exit('forbidden'); }
    }
}

/** Extensiones permitidas para adjuntos (sin ejecutables ni scripts). */
if (!function_exists('allowed_upload_extensions')) {
    function allowed_upload_extensions(): array {
        return ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','mp4','mov'];
    }
}

/**
 * Sanea un nombre de archivo y valida extensión. Devuelve [nombreSeguro, ext] o null si no es válido.
 * El nombre final nunca conserva extensiones "dobles" (a.php.jpg) ni caracteres raros.
 */
if (!function_exists('safe_upload_name')) {
    function safe_upload_name(string $original, string $prefix = 'f'): ?array {
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, allowed_upload_extensions(), true)) return null;
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', $base);
        $base = trim(substr($base, 0, 40), '_') ?: 'archivo';
        return [$prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $base . '.' . $ext, $ext];
    }
}

/** Comprueba que el contenido real coincida con la extensión (imágenes/PDF) y que no sea código. */
if (!function_exists('upload_content_ok')) {
    function upload_content_ok(string $tmpPath, string $ext): bool {
        if (!is_file($tmpPath)) return false;
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmpPath) : '';
        $imgs = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
        if (isset($imgs[$ext])) return $mime === '' || $mime === $imgs[$ext];
        if ($ext === 'pdf') return $mime === '' || $mime === 'application/pdf';
        $head = (string)@file_get_contents($tmpPath, false, null, 0, 2048);
        if (preg_match('/<\?php|<\?=|<script/i', $head)) return false;
        return true;
    }
}

/** Cabeceras de seguridad adicionales. */
if (!function_exists('send_security_headers')) {
    function send_security_headers(): void {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

/** Escapa texto para enviarlo al widget del chat (que inserta HTML). */
if (!function_exists('chat_escape')) {
    function chat_escape(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
