<?php
require_once __DIR__ . '/env.php';

if (!ini_get('output_buffering')) {
    ob_start();
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
          || (app_env('FORCE_SECURE') === '1');


$timeout  = max(300, (int)app_env('SESSION_TIMEOUT', 28800));
$lifetime = max($timeout, (int)app_env('SESSION_LIFETIME', 604800));

session_name('HELPDESKSESSID');

// Configuración de cookies más compatible con Hostinger
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

 
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    $__save = trim((string)app_env('SESSION_SAVE_PATH', ''));
    $__candidates = $__save !== '' ? [$__save] : [dirname(__DIR__, 2) . '/sessions_deskservice', dirname(__DIR__) . '/storage/sessions'];
    foreach ($__candidates as $__dir) {
        if (!is_dir($__dir)) @mkdir($__dir, 0700, true);
        if (is_dir($__dir) && is_writable($__dir)) {
            if (!file_exists($__dir . '/.htaccess')) {
                @file_put_contents($__dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            }
            session_save_path($__dir);
            break;
        }
    }
    unset($__save, $__candidates, $__dir);
    session_start();
}

@header('X-Frame-Options: SAMEORIGIN');
@header('X-Content-Type-Options: nosniff');
@header('Referrer-Policy: strict-origin-when-cross-origin');
@header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($secure) { @header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); }

$now = time();

if (isset($_SESSION['LAST_ACTIVITY']) && ($now - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    // Sesión inactiva: se vacía y se renueva el id (la sesión sigue abierta para poder reabrirla con "Mantener sesión")
    $_SESSION = [];
    session_regenerate_id(true);
}

$_SESSION['LAST_ACTIVITY'] = $now;

if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = $now;
} elseif ($now - $_SESSION['CREATED'] > 600) {
    session_regenerate_id(true);
    $_SESSION['CREATED'] = $now;
}


$__script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__is_logout = ($__script === 'logout.php')
    || ($__script === 'auth.php' && (($_GET['action'] ?? $_POST['action'] ?? '') === 'logout'));

if ($__is_logout) {
    // Al cerrar sesión se borra también el token de este dispositivo
    if (!empty($_COOKIE['HELPDESK_REMEMBER'])) {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/../includes/remember_me.php';
        remember_forget($pdo);
    }
} else {

    if (empty($_SESSION['user_id']) && !empty($_COOKIE['HELPDESK_REMEMBER'])) {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/middleware_tenant.php';
        require_once __DIR__ . '/../includes/remember_me.php';
        remember_try_login($pdo);
    }
   
    if (!empty($_SESSION['user_id']) && isset($_SESSION['remember_pending'])) {
        $__pending = (int)$_SESSION['remember_pending'];
        unset($_SESSION['remember_pending']);
        if ($now - $__pending <= 900) {
            require_once __DIR__ . '/db.php';
            require_once __DIR__ . '/../includes/remember_me.php';
            remember_issue($pdo, (int)$_SESSION['user_id']);
        }
        unset($__pending);
    }
}
unset($__script, $__is_logout);
?>
