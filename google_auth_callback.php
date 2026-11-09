<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';

$tenant_id = get_tenant_id();

// Obtener credenciales del tenant
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key IN ('google_client_id', 'google_client_secret')");
$stmt->execute([$tenant_id]);
$creds = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$client_id = $creds['google_client_id'] ?? '';
$client_secret = $creds['google_client_secret'] ?? '';
$redirect_uri = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]/google_auth_callback.php";

if (isset($_GET['action']) && $_GET['action'] === 'auth') {
    if (empty($client_id) || empty($client_secret)) {
        die("Error: Configure Client ID y Secret primero.");
    }

    $auth_params = [
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email',
        'access_type' => 'offline',
        'prompt' => 'consent select_account',
        'include_granted_scopes' => 'true'
    ];

    $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($auth_params);

    header("Location: $auth_url");
    exit;
}

if (isset($_GET['code'])) {
    $code = $_GET['code'];

    // Intercambiar código por tokens
    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Evita error de certificado local
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code' => $code,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ]));

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($curl_error) {
        die("Error de conexión (CURL): $curl_error");
    }

    if ($http_code !== 200) {
        $error_desc = $data['error_description'] ?? $data['error'] ?? 'Error desconocido';
        die("Google devolvió un error ($http_code): $error_desc <br><br><b>Raw Response:</b> $response");
    }

    if (isset($data['refresh_token'])) {
        $refresh_token = $data['refresh_token'];

        // Guardar refresh_token
        $check = $pdo->prepare("SELECT id FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'google_refresh_token'");
        $check->execute([$tenant_id]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE tenant_settings SET setting_value = ?, updated_at = NOW() WHERE tenant_id = ? AND setting_key = 'google_refresh_token'")
                ->execute([$refresh_token, $tenant_id]);
        } else {
            $pdo->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?, 'google_refresh_token', ?)")
                ->execute([$tenant_id, $refresh_token]);
        }

        header("Location: configuracion_email.php?success=google_linked");
    } elseif (isset($data['access_token'])) {
        // Tenemos access_token pero NO refresh_token. Esto pasa si el usuario ya había dado permiso antes.
        // Intentamos ver si ya tenemos un refresh_token guardado.
        $stmt = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'google_refresh_token'");
        $stmt->execute([$tenant_id]);
        $existing_refresh = $stmt->fetchColumn();

        if ($existing_refresh) {
            // Ya teníamos uno, simplemente confirmamos que se ha "refrescado" la conexión
            header("Location: configuracion_email.php?success=google_linked");
        } else {
            // No tenemos ninguno guardado. Hay que forzar que Google lo envíe.
            die("Error: No se recibió Refresh Token. <br><br><b>Solución:</b> Ve a <a href='https://myaccount.google.com/permissions'>Seguridad de tu Cuenta Google</a>, busca la app 'Desk Service SSO', <b>elimina su acceso</b> y vuelve a intentar vincular aquí. <br><br>Esto obligará a Google a enviarnos la clave de larga duración.");
        }
    } else {
        $error_detail = $data['error_description'] ?? $data['error'] ?? 'Error desconocido';
        echo "<h3>Error al vincular con Google</h3>";
        echo "<p>Detalle técnico: <b>$error_detail</b></p>";
        echo "<hr>";
        echo "<h4>Posibles soluciones:</h4>";
        echo "<ul>";
        echo "<li>Asegúrese de que el <b>ID de cliente</b> y el <b>Secreto</b> sean correctos y pertenezcan a una <b>Aplicación Web</b>.</li>";
        echo "<li>Si el error persiste, vaya a <a href='https://myaccount.google.com/permissions' target='_blank'>Seguridad de Google</a>, busque la app y <b>elimine el acceso</b> por completo.</li>";
        echo "<li>Verifique que en la consola de Google Cloud, el estado de la app sea 'En prueba' y su correo esté en la lista de 'Usuarios de prueba'.</li>";
        echo "</ul>";
        echo "<br><a href='configuracion_email.php' class='btn btn-primary'>Volver a intentarlo</a>";
    }
    exit;
}

header("Location: configuracion_email.php");
exit;
