<?php
/**
 * includes/remember_me.php — "Mantener sesión iniciada".
 *
 * Al iniciar sesión con la casilla marcada se entrega una cookie larga (HELPDESK_REMEMBER = selector:validador).
 * En la base solo se guarda el hash del validador. Si la sesión de PHP caduca, se reabre sola con esa cookie,
 * así nadie se queda fuera por inactividad. Se invalida al cerrar sesión, al cambiar la contraseña o al desactivar la cuenta.
 */
require_once __DIR__ . '/../config/env.php';

const REMEMBER_COOKIE = 'HELPDESK_REMEMBER';

function remember_days(): int {
    return max(1, (int)app_env('REMEMBER_DAYS', 30));
}

function remember_is_secure(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (app_env('FORCE_SECURE') === '1');
}

function remember_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_remember_tokens (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector CHAR(24) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        pw_fp CHAR(32) NOT NULL,
        user_agent VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL,
        last_used DATETIME NULL,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uq_selector (selector),
        KEY idx_user (user_id),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Huella de la contraseña: si la persona la cambia, las sesiones recordadas dejan de valer. */
function remember_pw_fp(?string $password_hash): string {
    return substr(hash('sha256', 'rm|' . (string)$password_hash), 0, 32);
}

function remember_cookie_set(string $value, int $expires): void {
    if (headers_sent()) return;
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => remember_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function remember_cookie_clear(): void {
    if (!headers_sent()) remember_cookie_set('', time() - 3600);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/**
 * Crea el token y la cookie para el usuario que acaba de iniciar sesión.
 * @return string|false valor de la cookie emitida, o false si no se pudo
 */
function remember_issue(PDO $pdo, int $user_id) {
    try {
        remember_table($pdo);
        $st = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $st->execute([$user_id]);
        $pw = $st->fetchColumn();
        if ($pw === false) return false;

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + remember_days() * 86400;
        $now = date('Y-m-d H:i:s');

        $pdo->prepare("INSERT INTO auth_remember_tokens (user_id, selector, token_hash, pw_fp, user_agent, ip, created_at, last_used, expires_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$user_id, $selector, hash('sha256', $validator), remember_pw_fp((string)$pw),
                       mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                       $now, $now, date('Y-m-d H:i:s', $expires)]);

        // Limpieza: vencidos y, como máximo, 5 dispositivos por usuario
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE expires_at < ?")->execute([$now]);
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ? AND id NOT IN
                       (SELECT id FROM (SELECT id FROM auth_remember_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 5) t)")
            ->execute([$user_id, $user_id]);

        $value = $selector . ':' . $validator;
        remember_cookie_set($value, $expires);
        return $value;
    } catch (Throwable $e) {
        error_log('remember_issue: ' . $e->getMessage());
        return false;
    }
}

/** Cierre de sesión: borra el token de este dispositivo y su cookie. */
function remember_forget(PDO $pdo): void {
    $c = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (preg_match('/^([a-f0-9]{24}):/', $c, $m)) {
        try {
            remember_table($pdo);
            $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?")->execute([$m[1]]);
        } catch (Throwable $e) {}
    }
    remember_cookie_clear();
}

/** Para usar al desactivar una cuenta o forzar el cierre de sesión en todos los dispositivos. */
function remember_forget_user(PDO $pdo, int $user_id): void {
    try {
        remember_table($pdo);
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([$user_id]);
    } catch (Throwable $e) {}
}

/**
 * Si no hay sesión pero sí una cookie válida, abre la sesión del usuario.
 * Debe llamarse con la sesión de PHP ya iniciada y la conexión $pdo disponible.
 */
function remember_try_login(PDO $pdo): bool {
    if (!empty($_SESSION['user_id'])) return false;
    $c = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m)) {
        if ($c !== '') remember_cookie_clear();
        return false;
    }
    try {
        remember_table($pdo);
        $st = $pdo->prepare("SELECT * FROM auth_remember_tokens WHERE selector = ? AND expires_at > ?");
        $st->execute([$m[1], date('Y-m-d H:i:s')]);
        $tok = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tok) { remember_cookie_clear(); return false; }

        if (!hash_equals((string)$tok['token_hash'], hash('sha256', $m[2]))) {
            // Selector válido con validador incorrecto: cookie manipulada o robada. Se descarta este token.
            $pdo->prepare("DELETE FROM auth_remember_tokens WHERE id = ?")->execute([$tok['id']]);
            remember_cookie_clear();
            return false;
        }

        $su = $pdo->prepare("SELECT u.*, t.name AS tenant_name, t.plan AS tenant_plan, t.status AS tenant_status
                             FROM users u LEFT JOIN tenants t ON u.tenant_id = t.id WHERE u.id = ?");
        $su->execute([$tok['user_id']]);
        $user = $su->fetch(PDO::FETCH_ASSOC);

        $valid = $user
            && !(isset($user['is_active']) && $user['is_active'] == 0)
            && !(isset($user['is_verified']) && !$user['is_verified'])
            && ((($user['tenant_status'] ?? null) === 'active') || (int)$user['tenant_id'] === 1)
            && hash_equals((string)$tok['pw_fp'], remember_pw_fp($user['password'] ?? ''));

        if (!$valid) {
            $pdo->prepare("DELETE FROM auth_remember_tokens WHERE id = ?")->execute([$tok['id']]);
            remember_cookie_clear();
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        $tenant_id = $user['tenant_id'] ?? 1;
        if (function_exists('set_tenant_session')) {
            set_tenant_session($tenant_id, $user['tenant_name'] ?? '', $user['tenant_plan'] ?? '');
        } else {
            $_SESSION['tenant_id'] = $tenant_id;
            $_SESSION['tenant_name'] = $user['tenant_name'] ?? '';
            $_SESSION['tenant_plan'] = $user['tenant_plan'] ?? '';
        }

        // Vigencia deslizante: cada uso la renueva (el validador se mantiene para no cortar peticiones simultáneas)
        $exp = time() + remember_days() * 86400;
        $pdo->prepare("UPDATE auth_remember_tokens SET last_used = ?, expires_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $exp), $tok['id']]);
        remember_cookie_set($c, $exp);
        return true;
    } catch (Throwable $e) {
        error_log('remember_try_login: ' . $e->getMessage());
        return false;
    }
}