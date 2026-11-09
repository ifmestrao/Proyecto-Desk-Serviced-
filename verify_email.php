<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';
require_once 'includes/security.php';

if (!isset($_SESSION['verify_email'])) {
    header("Location: login");
    exit;
}

$email = $_SESSION['verify_email'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_check()) {
        $error = "Sesión expirada.";
    } elseif (isset($_POST['resend']) && !rate_limit_hit($pdo, 'resend|' . strtolower($email) . '|' . client_ip(), 3, 600)) {
        $error = "Has solicitado demasiados códigos. Espera unos minutos.";
    } elseif (isset($_POST['resend'])) {
        // Generar y reenviar un nuevo código
        $code = secure_code();
        $selected_user_id = $_SESSION['selected_user_id'] ?? null;
        
        if ($selected_user_id) {
            $stmt = $pdo->prepare("UPDATE users SET verification_code = ? WHERE id = ?");
            $stmt->execute([$code, $selected_user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET verification_code = ? WHERE email = ?");
            $stmt->execute([$code, $email]);
        }
        
        $subj = "Nuevo Código de Verificación - Desk Service";
        $html = "<h1>Nuevo código de verificación</h1><p>Tu nuevo código de verificación es: <strong>$code</strong></p>";
        require_once 'includes/mailer.php';
        
        // Get tenant id
        if ($selected_user_id) {
            $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
            $stmt->execute([$selected_user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
        }
        $u = $stmt->fetch();
        $tenant_id = $u['tenant_id'] ?? null;
        send_mail($email, $subj, $html, null, [], $tenant_id);
        
        $success = "Se ha enviado un nuevo código a tu correo.";
    } elseif (!rate_limit_hit($pdo, 'verify|' . strtolower($email) . '|' . client_ip(), 6, 600)) {
        $error = "Demasiados intentos. Espera unos minutos y solicita un nuevo código.";
    } else {
        $code = trim($_POST['code'] ?? '');
        $selected_user_id = $_SESSION['selected_user_id'] ?? null;
        
        if ($selected_user_id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND verification_code = ?");
            $stmt->execute([$selected_user_id, $code]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND verification_code = ?");
            $stmt->execute([$email, $code]);
        }
        $user = $stmt->fetch();
        
        if ($user) {
            $stmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_code = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);

            $tenant_id = $user['tenant_id'] ?? 1;

            // Set tenant session
            if ($tenant_id) {
                $tenant_stmt = $pdo->prepare("SELECT name, plan FROM tenants WHERE id = ?");
                $tenant_stmt->execute([$tenant_id]);
                $tenant = $tenant_stmt->fetch(PDO::FETCH_ASSOC);
                if ($tenant) {
                    set_tenant_session($tenant_id, $tenant['name'], $tenant['plan']);
                }
            }

            // Manejar "Confiar en este dispositivo"
            if (isset($_POST['trust_device'])) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                $insT = $pdo->prepare("INSERT INTO trusted_devices (tenant_id, user_id, token, expires_at) VALUES (?, ?, ?, ?)");
                $insT->execute([$tenant_id, $user['id'], hash('sha256', $token), $expires]);
                
                // Set cookie for 30 days
                setcookie('trust_token', $token, [
                    'expires'  => time() + (30 * 24 * 60 * 60),
                    'path'     => '/',
                    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            
            // Iniciar sesión automáticamente
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            
            // Registrar auditoría si la tabla existe
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)")->execute([$tenant_id, $user['id'], 'login', "Inicio de sesión exitoso (Validación de dispositivo)", $ip, $ua]);
            } catch (Exception $e) {}

            unset($_SESSION['verify_email']);
            unset($_SESSION['selected_user_id']);
            
            if ($user['role'] == 'admin' || $user['role'] == 'supervisor' || $user['role'] == 'tech') {
                header("Location: dashboard");
            } else {
                header("Location: dashboard_user");
            }
            exit;
        } else {
            $error = "El código de verificación es incorrecto.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Correo - Desk Service</title>
    <link rel="shortcut icon" href="/assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #e0eafc 0%, #cfdef3 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px 0;
        }
        .login-container {
            max-width: 450px;
            width: 100%;
        }
        .form-control-user {
            border-radius: 12px;
            padding: 12px 15px;
            border: 1px solid #d1d5db;
            background-color: #f9fafb;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        .form-control-user:focus {
            background-color: #fff;
            border-color: var(--blue-main);
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.15);
        }
    </style>
</head>
<body>

<div class="container login-container">
    <div class="card glass-card p-4 form-field">
        <div class="text-center mb-4">
            <img src="/assets/img/deskser.png" alt="" width="50px" height="50px">
            <h1 class="h3 fw-bold text-gray-900 mb-2 mt-2">Verifica tu Correo</h1>
            <p class="text-muted small">Hemos enviado un código de 6 dígitos a <br><strong><?php echo htmlspecialchars($email); ?></strong></p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success rounded-3"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?></div>
        <?php endif; ?>

        <form class="user" action="" method="POST">
            <?php echo csrf_input(); ?>
            
            <div class="mb-3 form-field">
                <label class="form-label text-muted fw-semibold small text-uppercase tracking-wide">Código de Verificación</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-shield-alt text-muted"></i></span>
                    <input type="text" class="form-control form-control-user border-start-0 ps-0 text-center fw-bold fs-4" name="code" placeholder="000000" maxlength="6" pattern="\d{6}" required autofocus>
                </div>
            </div>
            
            <div class="mb-4 form-field">
                <div class="form-check custom-checkbox">
                    <input class="form-check-input" type="checkbox" name="trust_device" id="trust_device">
                    <label class="form-check-label text-muted small" for="trust_device">
                        Confiar en este dispositivo por 30 días
                    </label>
                </div>
            </div>
            
            <button type="submit" class="btn-animated w-100 mb-3 form-field">
                <span class="btn-text">VERIFICAR CÓDIGO <i class="fas fa-check ms-2"></i></span>
            </button>
        </form>
        
        <form action="" method="POST" class="text-center form-field mb-3">
            <?php echo csrf_input(); ?>
            <button type="submit" name="resend" value="1" class="btn btn-link text-decoration-none small text-muted hover-primary fw-medium p-0 border-0 bg-transparent">¿No recibiste el código? Reenviar</button>
        </form>
        
        <div class="text-center form-field">
            <hr class="my-3 opacity-25">
            <a class="text-decoration-none small text-primary fw-bold" href="login.php"><i class="fas fa-arrow-left me-1"></i> Volver al inicio de sesión</a>
        </div>
    </div>
</div>

</body>
</html>
