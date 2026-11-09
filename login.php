<?php
require_once 'config/session_setup.php';
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';
require_once 'includes/sso_helper.php';

$current_tenant_id = 1; // Login centralizado
$tenant_name = 'Desk Service';
$is_main_domain = true;

// Botones de inicio de sesión social (las claves viven en config/local.env)
$google_sso_active = sso_google_login_enabled($pdo ?? null);
$linkedin_sso_active = sso_linkedin_configured();

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'supervisor' || $_SESSION['role'] == 'tech') {
        header("Location: dashboard");
    } else {
        header("Location: dashboard_user");
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Desk Service</title>
    <link rel="shortcut icon" href="/assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
    <style>
        body {
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
        .login-icon {
            font-size: 3rem;
            color: var(--blue-main);
            margin-bottom: 1rem;
        }
        .btn-google {
            background-color: #fff;
            color: #3c4043;
            border: 1px solid #dadce0;
            border-radius: 12px;
            padding: 12px 15px;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }
        .btn-google:hover {
            background-color: #f8f9fa;
            border-color: #d2e3fc;
            box-shadow: 0 1px 3px rgba(60,64,67,0.3);
        }
        .btn-linkedin {
            background-color: #0a66c2;
            color: #fff;
            border: 1px solid #0a66c2;
            border-radius: 12px;
            padding: 12px 15px;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }
        .btn-linkedin:hover { background-color: #004182; border-color: #004182; color: #fff; box-shadow: 0 1px 3px rgba(60,64,67,0.3); }
        .btn-google img {
            width: 20px;
            height: 20px;
        }
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            color: #6c757d;
            font-size: 0.85rem;
            margin: 1.5rem 0;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e5e7eb;
        }
        .divider::before { margin-right: .5em; }
        .divider::after { margin-left: .5em; }
    </style>
</head>
<body>

<div class="container login-container">
    <div class="card glass-card p-4 p-sm-5 form-field" style="border-radius: 20px;">
        <div class="text-center mb-4">
            <img src="/assets/img/deskser.png" alt="" width="60px" height="60px" class="mb-3">
            <h1 class="h4 fw-bold mb-2" style="color: #bfa15f; text-shadow: 1px 1px 2px rgba(0,0,0,0.1);"><?php echo htmlspecialchars($tenant_name); ?></h1>
            <p class="text-muted small">Gestión Inteligente de tus Casos</p>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger rounded-3 small"><i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success rounded-3 small"><i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['reason']) && $_GET['reason'] === 'inactivity'): ?>
            <div class="alert alert-warning rounded-3 small"><i class="fas fa-clock me-2"></i>Tu sesión ha expirado por inactividad. Por favor, ingresa de nuevo.</div>
        <?php endif; ?>

        <?php if ($google_sso_active || $linkedin_sso_active): ?>
            <?php if ($google_sso_active): ?>
            <a href="login_google.php" class="btn-google w-100 mb-2 form-field sso-link">
                <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" alt="Google">
                Continuar con Google
            </a>
            <?php endif; ?>
            <?php if ($linkedin_sso_active): ?>
            <a href="login_linkedin.php" class="btn-linkedin w-100 mb-2 form-field sso-link">
                <i class="fab fa-linkedin-in"></i>
                Continuar con LinkedIn
            </a>
            <?php endif; ?>

            <div class="divider form-field">O INGRESA CON TU CORREO</div>
        <?php endif; ?>

        <form class="user" action="login_submit.php" method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="login">
            
            <div class="mb-3 form-field">
                <label class="form-label text-muted fw-bold small text-uppercase tracking-wide" style="font-size: 0.75rem;">Correo Electrónico</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-user text-muted"></i></span>
                    <input type="email" class="form-control form-control-user border-start-0 ps-0" name="email" placeholder="Ingresa tu correo" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="mb-3 form-field">
                <label class="form-label text-muted fw-bold small text-uppercase tracking-wide" style="font-size: 0.75rem;">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" class="form-control form-control-user border-start-0 ps-0" name="password" placeholder="••••••••" required>
                </div>
            </div>
            
            <div class="form-check mb-4 form-field">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" checked>
                <label class="form-check-label small text-muted" for="remember">Mantener sesión iniciada en este dispositivo</label>
            </div>

            <button type="submit" class="btn-animated w-100 mb-4 form-field" onclick="this.classList.add('loading')">
                <span class="btn-text">INICIAR SESIÓN <i class="fas fa-arrow-right ms-2"></i></span>
                <span class="btn-spinner"></span>
            </button>
        </form>
        
        <div class="text-center form-field">
            <a class="text-decoration-none small text-muted hover-primary fw-medium" href="forgot_password.php">¿Olvidaste tu contraseña?</a>
        </div>
    </div>
</div>

<script>
    // "Mantener sesión iniciada" también aplica al entrar con Google / LinkedIn
    (function () {
        const chk = document.getElementById('remember');
        const links = document.querySelectorAll('.sso-link');
        function sync() {
            links.forEach(a => {
                const base = a.getAttribute('href').split('?')[0];
                a.setAttribute('href', chk && chk.checked ? base + '?remember=1' : base);
            });
        }
        if (chk) { chk.addEventListener('change', sync); sync(); }
    })();

    // Animación de carga opcional al enviar
    document.querySelector('form').addEventListener('submit', function() {
        const btn = this.querySelector('button[type="submit"]');
        btn.classList.add('loading');
    });
</script>
</body>
</html>