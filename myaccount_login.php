<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

if (isset($_SESSION['myaccount_user_id'])) {
    header("Location: myaccount_dashboard");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Cuenta - Desk Service</title>
    <link rel="shortcut icon" href="/assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        }
        .login-container {
            max-width: 450px;
            width: 100%;
            padding: 15px;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .form-control-user {
            border-radius: 12px;
            padding: 14px 15px;
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
        .hero-section {
            background: linear-gradient(90deg,#2563eb 0%,#1d4ed8 50%,#0ea5e9 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .btn-primary-custom {
            background: var(--blue-main);
            border: none;
            padding: 14px;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 12px;
            transition: all 0.3s;
            width: 100%;
            color: white;
        }
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }
        .fw-black { font-weight: 800; }
    </style>
</head>
<body>

<div class="container login-container">
    <div class="glass-card">
        <div class="hero-section">
            <h1 class="fw-black mb-0" style="font-size: 2.5rem;">Desk Service</h1>
            <p class="opacity-80 mb-0 small">Su área cliente</p>
        </div>

        <div class="p-4 p-sm-5">
            <div class="mb-4 text-center">
                <span class="text-muted small">¿Ya tienes una instancia?</span><br>
                <a href="login" class="text-decoration-none fw-bold small text-primary">Iniciar sesión en tu instancia</a>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger rounded-3 small"><i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <form action="myaccount_actions.php" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="myaccount_login">

                <div class="mb-3">
                    <label class="form-label fw-semibold text-muted small text-uppercase">Correo Electrónico</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-envelope text-muted"></i></span>
                        <input type="email" class="form-control form-control-user border-start-0 ps-0" name="email" placeholder="admin@empresa.com" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold text-muted small text-uppercase">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-lock text-muted"></i></span>
                        <input type="password" class="form-control form-control-user border-start-0 ps-0" name="password" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn-primary-custom mb-4">
                    <i class="fas fa-sign-in-alt me-2"></i>Conectarse
                </button>

                <div class="text-center small">
                    <a href="forgot_password.php" class="text-decoration-none fw-bold text-primary">¿Olvidó su contraseña?</a>
                    <span class="mx-2 text-muted">•</span>
                    <a href="create_tenant.php" class="text-decoration-none fw-bold text-primary">¿Sin cuenta?</a>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
