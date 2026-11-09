<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'config/env.php';
require_once 'includes/sso_helper.php';

// Obtener la configuración de Google SSO
// Credenciales desde config/local.env (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET). Nunca en el código.
$google_client_id = app_env('GOOGLE_CLIENT_ID', '');
$google_client_secret = app_env('GOOGLE_CLIENT_SECRET', '');

if (empty($google_client_id) || empty($google_client_secret)) {
    $_SESSION['error'] = 'El inicio de sesión con Google no está habilitado o le falta el Client Secret.';
    header('Location: login.php');
    exit;
}

// Configuración OAuth 2.0
// Detectar si estamos usando ngrok u otro proxy (x-forwarded-proto/host)
$protocol = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = !empty($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : $_SERVER['HTTP_HOST'];

// Asegurar que la ruta base no incluya '\' y terminar con la ruta correcta al archivo
$base_dir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
if ($base_dir == '/') $base_dir = '';

$redirect_uri = $protocol . $host . $base_dir . '/login_google.php';

if (isset($_GET['code'])) {
    // Protección CSRF del flujo OAuth (parámetro state)
    $state_ok = isset($_GET['state'], $_SESSION['oauth_state']) && hash_equals($_SESSION['oauth_state'], (string)$_GET['state']);
    unset($_SESSION['oauth_state']);
    if (!$state_ok) {
        $_SESSION['error'] = 'Sesión de Google inválida. Intenta de nuevo.';
        header('Location: login.php');
        exit;
    }
    $code = $_GET['code'];

    // 1. Intercambiar el código por un token de acceso usando cURL
    $token_url = 'https://oauth2.googleapis.com/token';
    $post_data = [
        'code' => $code,
        'client_id' => $google_client_id,
        'client_secret' => $google_client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $_SESSION['error'] = 'Error cURL (Token): ' . curl_error($ch);
        header('Location: login.php');
        exit;
    }
    curl_close($ch);

    $token_data = json_decode($response, true);

    if (isset($token_data['error'])) {
        $_SESSION['error'] = 'Error de autenticación con Google: ' . ($token_data['error_description'] ?? $token_data['error']);
        header('Location: login.php');
        exit;
    }

    $access_token = $token_data['access_token'];

    // 2. Obtener los datos del perfil del usuario (Email y Nombre)
    $userinfo_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
    $ch = curl_init($userinfo_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $_SESSION['error'] = 'Error cURL (UserInfo): ' . curl_error($ch);
        header('Location: login.php');
        exit;
    }
    curl_close($ch);

    $user_info = json_decode($response, true);

    if (isset($user_info['email'])) {
        $email = $user_info['email'];
        $name = $user_info['name'] ?? 'Usuario Google';
        
        // 3. Correo verificado por Google
        if (isset($user_info['verified_email']) && !$user_info['verified_email']) {
            $_SESSION['error'] = 'Tu correo de Google no está verificado.';
            header('Location: login.php');
            exit;
        }

        // 4. Buscar al usuario, abrir sesión y redirigir (lógica compartida con LinkedIn)
        sso_complete_login($pdo, $email, 'Google');
    } else {
        $_SESSION['error'] = 'No se pudo obtener tu correo electrónico de Google.';
        header('Location: login.php');
        exit;
    }
} else {
    // Redirigir al usuario a la página de autorización de Google
    sso_remember_preference();
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $google_client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
        'state' => $_SESSION['oauth_state']
    ]);
    
    header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
    exit;
}
?>