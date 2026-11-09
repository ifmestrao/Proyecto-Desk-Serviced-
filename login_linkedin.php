<?php
/**
 * login_linkedin.php — Inicio de sesión con LinkedIn (OpenID Connect).
 * Requiere en config/local.env: LINKEDIN_CLIENT_ID y LINKEDIN_CLIENT_SECRET.
 * En LinkedIn Developers: producto «Sign In with LinkedIn using OpenID Connect» y esta URL como redirect.
 */
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'config/env.php';
require_once 'includes/sso_helper.php';

$client_id = app_env('LINKEDIN_CLIENT_ID', '');
$client_secret = app_env('LINKEDIN_CLIENT_SECRET', '');

function li_fail(string $msg): void {
    $_SESSION['error'] = $msg;
    header('Location: login.php');
    exit;
}

if ($client_id === '' || $client_secret === '') {
    li_fail('El inicio de sesión con LinkedIn no está habilitado en este sistema.');
}

$redirect_uri = sso_redirect_uri('login_linkedin.php');

/** POST/GET sencillo con cURL. Devuelve [http, json|null]. */
function li_http(string $url, ?array $post = null, ?string $bearer = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }
    if ($bearer !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $bearer]);
    }
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        error_log('LinkedIn SSO cURL: ' . $err);
        return [0, null];
    }
    return [$http, json_decode($raw, true)];
}

// ── Regreso desde LinkedIn ──
if (isset($_GET['code']) || isset($_GET['error'])) {
    $state_ok = isset($_GET['state'], $_SESSION['oauth_state_li']) && hash_equals($_SESSION['oauth_state_li'], (string)$_GET['state']);
    unset($_SESSION['oauth_state_li']);

    if (isset($_GET['error'])) {
        // Cancelaron en LinkedIn u ocurrió un error del lado del proveedor
        li_fail(($_GET['error'] === 'user_cancelled_login' || $_GET['error'] === 'user_cancelled_authorize')
            ? 'Cancelaste el inicio de sesión con LinkedIn.'
            : 'LinkedIn no pudo completar el inicio de sesión. Intenta de nuevo.');
    }
    if (!$state_ok) {
        li_fail('Sesión de LinkedIn inválida. Intenta de nuevo.');
    }

    // 1. Código -> token de acceso
    [$http, $token] = li_http('https://www.linkedin.com/oauth/v2/accessToken', [
        'grant_type'    => 'authorization_code',
        'code'          => (string)$_GET['code'],
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => $redirect_uri,
    ]);
    if ($http !== 200 || empty($token['access_token'])) {
        error_log('LinkedIn SSO token fallo: http=' . $http . ' ' . json_encode($token));
        li_fail('No se pudo autenticar con LinkedIn: ' . ($token['error_description'] ?? 'inténtalo de nuevo.'));
    }

    // 2. Datos del perfil (OpenID Connect userinfo)
    [$http, $info] = li_http('https://api.linkedin.com/v2/userinfo', null, $token['access_token']);
    if ($http !== 200 || empty($info['email'])) {
        li_fail('No se pudo obtener tu correo electrónico de LinkedIn.');
    }
    $verified = $info['email_verified'] ?? false;
    if ($verified !== true && $verified !== 'true') {
        li_fail('Tu correo de LinkedIn no está verificado.');
    }

    sso_complete_login($pdo, strtolower(trim((string)$info['email'])), 'LinkedIn');
}

// ── Inicio: mandar a LinkedIn ──
sso_remember_preference();
$_SESSION['oauth_state_li'] = bin2hex(random_bytes(16));
$auth_url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $client_id,
    'redirect_uri'  => $redirect_uri,
    'state'         => $_SESSION['oauth_state_li'],
    'scope'         => 'openid profile email',
], '', '&', PHP_QUERY_RFC3986);

header('Location: ' . $auth_url);
exit;