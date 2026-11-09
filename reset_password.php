<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/security.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}

$email = $_SESSION['reset_email'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_check()) {
        $error = "Sesión expirada.";
    } else {
        $code = trim($_POST['code'] ?? '');
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';
        
        if ($new_pass !== $confirm_pass) {
            $error = "Las contraseñas no coinciden.";
        } else {
            // Validar fortaleza de la contraseña
            if (strlen($new_pass) < 8) $error = "La contraseña debe tener al menos 8 caracteres.";
            elseif (preg_match_all('/[0-9]/', $new_pass) < 2) $error = "La contraseña debe tener al menos 2 números.";
            elseif (!preg_match('/[@$!%*?&]/', $new_pass)) $error = "La contraseña debe tener al menos 1 carácter especial (@$!%*?&).";
            elseif (!preg_match('/[A-Z]/', $new_pass)) $error = "La contraseña debe tener al menos 1 letra mayúscula.";
            elseif (!preg_match('/[a-z]/', $new_pass)) $error = "La contraseña debe tener al menos 1 letra minúscula.";
            
            if (!$error && !rate_limit_hit($pdo, 'reset|' . strtolower($email) . '|' . client_ip(), 6, 900)) {
                $error = "Demasiados intentos. Solicita un nuevo código.";
            }
            if (!$error) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND reset_code = ? AND reset_expires > NOW()");
                $stmt->execute([$email, $code]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password = ?, reset_code = NULL, reset_expires = NULL WHERE email = ? AND reset_code = ?");
                    $upd->execute([$hash, $email, $code]);
                    
                    $_SESSION['success'] = "Contraseña restablecida con éxito. Ya puedes iniciar sesión.";
                    unset($_SESSION['reset_email']);
                    header("Location: login.php");
                    exit;
                } else {
                    $error = "El código es inválido o ha expirado.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña - Desk Service</title>
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
        .login-icon {
            font-size: 2.5rem;
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
            <h1 class="h3 fw-bold mb-2 mt-2" style="color: #bfa15f; text-shadow: 1px 1px 2px rgba(0,0,0,0.1);">Restablecer Contraseña</h1>
            <p class="text-muted small">Ingresa el código enviado a <br><strong><?php echo htmlspecialchars($email); ?></strong></p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?></div>
        <?php endif; ?>

        <form class="user" action="" method="POST">
            <?php echo csrf_input(); ?>
            
            <div class="mb-3 form-field">
                <label class="form-label text-muted fw-semibold small text-uppercase tracking-wide">Código de Verificación</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-shield-alt text-muted"></i></span>
                    <input type="text" class="form-control form-control-user border-start-0 ps-0 text-center fw-bold fs-5" name="code" placeholder="000000" maxlength="6" pattern="\d{6}" required autofocus>
                </div>
            </div>
            
            <div class="mb-3 form-field">
                <label class="form-label text-muted fw-semibold small text-uppercase tracking-wide">Nueva Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" class="form-control form-control-user border-start-0 ps-0" name="new_password" placeholder="••••••••" required>
                </div>
            </div>
            
            <div class="mb-3 form-field">
                <label class="form-label text-muted fw-semibold small text-uppercase tracking-wide">Confirmar Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" class="form-control form-control-user border-start-0 ps-0" name="confirm_password" placeholder="••••••••" required>
                </div>
            </div>
            
            <div class="alert alert-light border p-3 mb-4 rounded-3 form-field" style="font-size: 0.75rem; background: rgba(255,255,255,0.7);">
                <div class="fw-bold mb-2 text-muted"><i class="fas fa-check-circle me-1"></i> Requisitos:</div>
                <div class="d-flex flex-wrap gap-2 text-secondary">
                    <span><i class="fas fa-check text-success me-1"></i>Mín. 8 caracteres</span>
                    <span><i class="fas fa-check text-success me-1"></i>2 números</span>
                    <span><i class="fas fa-check text-success me-1"></i>1 especial (@$!%*?&)</span>
                    <span><i class="fas fa-check text-success me-1"></i>Mayúsc. y minúsc.</span>
                </div>
            </div>

            <button type="submit" class="btn-animated w-100 mb-4 form-field">
                <span class="btn-text">RESTABLECER CONTRASEÑA <i class="fas fa-arrow-right ms-2"></i></span>
            </button>
        </form>
        
        <div class="text-center form-field">
            <a class="text-decoration-none small text-muted hover-primary fw-medium" href="forgot_password.php">¿No recibiste el código? Solicítalo de nuevo</a>
        </div>
    </div>
</div>

</body>
</html>
