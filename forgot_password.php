<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';
require_once 'includes/security.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_check()) {
        $error = "Sesión expirada.";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Ingresa un correo válido.";
        } elseif (!rate_limit_hit($pdo, 'forgot_ip|' . client_ip(), 6, 900)
               || !rate_limit_hit($pdo, 'forgot_em|' . strtolower($email), 3, 900)) {
            $error = "Demasiadas solicitudes. Intenta de nuevo en unos minutos.";
        } else {
            $stmt = $pdo->prepare("SELECT id, name, tenant_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $found = $stmt->fetchAll();

            if ($found) {
                $code = secure_code();
                $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                // Un mismo correo puede existir en varias empresas: el código aplica a todas sus cuentas
                $pdo->prepare("UPDATE users SET reset_code = ?, reset_expires = ? WHERE email = ?")
                    ->execute([$code, $expires, $email]);

                $first = $found[0];
                $subj = "Recuperación de Contraseña - Desk Service";
                $html = "<h1>Recuperar Contraseña</h1><p>Hola " . htmlspecialchars($first['name'], ENT_QUOTES, 'UTF-8') . ", tu código para restablecer la contraseña es: <strong>$code</strong></p><p>Este código expira en 30 minutos. Si no lo solicitaste, ignora este correo.</p>";
                send_mail($email, $subj, $html, null, [], (int)($first['tenant_id'] ?: 1));
            }

            // Respuesta idéntica exista o no la cuenta (evita enumeración de usuarios)
            $_SESSION['reset_email'] = $email;
            header("Location: reset_password.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Olvidé mi contraseña - Desk Service</title>
    <link rel="shortcut icon" href="assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #e0eafc 0%, #cfdef3 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            max-width: 400px;
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
        .login-icon {
            font-size: 3rem;
            color: var(--blue-main);
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>

<div class="container login-container">
    <div class="card glass-card p-4 form-field">
        <div class="text-center mb-4">
            <img src="assets/img/deskser.png" alt="" width="50px" height="50px">
            <h1 class="h3 fw-bold mb-2 mt-2" style="color: #bfa15f; text-shadow: 1px 1px 2px rgba(0,0,0,0.1);">Recuperar Cuenta</h1>
            <p class="text-muted">Te enviaremos un código de seguridad a tu correo</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?></div>
        <?php endif; ?>

        <form class="user" action="" method="POST">
            <?php echo csrf_input(); ?>
            <div class="mb-4 form-field">
                <label class="form-label text-muted fw-semibold small text-uppercase tracking-wide">Correo Electrónico</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-envelope text-muted"></i></span>
                    <input type="email" class="form-control form-control-user border-start-0 ps-0" name="email" placeholder="Ingresa tu correo" required autofocus>
                </div>
            </div>
            
            <button type="submit" class="btn-animated w-100 mb-4 form-field">
                <span class="btn-text">ENVIAR CÓDIGO <i class="fas fa-paper-plane ms-2"></i></span>
            </button>
        </form>
        
        <div class="text-center form-field">
            <a class="text-decoration-none small text-muted hover-primary fw-medium" href="login.php"><i class="fas fa-arrow-left me-1"></i> Volver al inicio de sesión</a>
        </div>
    </div>
</div>

</body>
</html>
