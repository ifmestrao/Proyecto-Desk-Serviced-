<?php

require_once __DIR__ . '/../config/env.php';


function sso_redirect_uri(string $script): string {
    $https = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
    $host = !empty($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/'));
    if ($base === '/' || $base === '.') $base = '';
    return ($https ? 'https://' : 'http://') . $host . $base . '/' . ltrim($script, '/');
}

function sso_google_configured(): bool {
    return app_env('GOOGLE_CLIENT_ID', '') !== '' && app_env('GOOGLE_CLIENT_SECRET', '') !== '';
}


function sso_google_legacy_row(PDO $pdo): bool {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM integrations WHERE provider = 'sso_google' AND setting_key = 'client_id' AND is_active = 1 LIMIT 1");
        $st->execute();
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


function sso_google_login_enabled(?PDO $pdo = null): bool {
    return sso_google_configured() || ($pdo !== null && sso_google_legacy_row($pdo));
}

function sso_linkedin_configured(): bool {
    return app_env('LINKEDIN_CLIENT_ID', '') !== '' && app_env('LINKEDIN_CLIENT_SECRET', '') !== '';
}

/** Guarda si la persona pidió "mantener sesión" antes de salir hacia el proveedor */
function sso_remember_preference(): void {
    if (!empty($_GET['remember'])) $_SESSION['remember_pending'] = time();
    else unset($_SESSION['remember_pending']);
}


function sso_complete_login(PDO $pdo, string $email, string $label): void {
    $stmt = $pdo->prepare("SELECT u.*, t.name AS tenant_name, t.plan AS tenant_plan, t.status AS tenant_status
                           FROM users u LEFT JOIN tenants t ON u.tenant_id = t.id
                           WHERE u.email = ? AND (t.status = 'active' OR u.tenant_id = 1)");
    $stmt->execute([$email]);
    $all_users = $stmt->fetchAll();

    if (count($all_users) > 1) {
        // Un correo puede existir en varias empresas: que la persona elija instancia
        $_SESSION['pending_auth_users'] = array_map(function ($u) {
            unset($u['password'], $u['verification_code'], $u['reset_code'], $u['reset_expires']);
            return $u;
        }, $all_users);
        header('Location: select_tenant');
        exit;
    }
    $user = $all_users[0] ?? null;

    if (!$user) {
        $_SESSION['error'] = "Usuario no registrado en ninguna instancia. Por favor, regístrese para crear su propia empresa.";
        header("Location: register.php");
        exit;
    }
    if (isset($user['is_active']) && $user['is_active'] == 0) {
        $_SESSION['error'] = "Tu cuenta está desactivada.";
        header("Location: login.php");
        exit;
    }
    if (isset($user['is_verified']) && !$user['is_verified']) {
        $_SESSION['verify_email'] = $email;
        header("Location: verify_email.php");
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];

    $tenant_id = $user['tenant_id'] ?? 1;
    if ($tenant_id) {
        $tenant_stmt = $pdo->prepare("SELECT name, plan FROM tenants WHERE id = ?");
        $tenant_stmt->execute([$tenant_id]);
        $tenant = $tenant_stmt->fetch(PDO::FETCH_ASSOC);
        if ($tenant && function_exists('set_tenant_session')) {
            set_tenant_session($tenant_id, $tenant['name'], $tenant['plan']);
        }
    }

    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, details, ip_address, user_agent) VALUES (?, ?, 'login', ?, ?, ?)")
            ->execute([$tenant_id, $user['id'], 'SSO ' . $label, $ip, $ua]);
    } catch (Throwable $e) {}

    if (in_array($user['role'], ['admin', 'supervisor', 'tech'])) {
        header("Location: dashboard.php");
    } else {
        header("Location: dashboard_user.php");
    }
    exit;
}