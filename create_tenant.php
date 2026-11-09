<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

// Si hay sesión de MyAccount, obtener datos del usuario
$currentUser = null;
if (isset($_SESSION['myaccount_user_id'])) {
    $user_id = $_SESSION['myaccount_user_id'];
    $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $currentUser = $stmt->fetch();
}

// Verificar límite de instancias solo si está logueado
$limit_reached = false;
if ($currentUser) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE contact_email = ?");
    $stmt->execute([$currentUser['email']]);
    $total_tenants = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE contact_email = ? AND plan_status != 'trial' AND status = 'active'");
    $stmt->execute([$currentUser['email']]);
    $paid_tenants = (int)$stmt->fetchColumn();

    $limit_reached = ($total_tenants >= 2 && $paid_tenants == 0);
}

$countries = [
    'CO' => 'Colombia (CO)',
    'MX' => 'México (MX)',
    'AR' => 'Argentina (AR)',
    'ES' => 'España (ES)',
    'US' => 'Estados Unidos (US)',
    'BR' => 'Brasil (BR)',
    'CL' => 'Chile (CL)',
    'PE' => 'Perú (PE)',
    'EC' => 'Ecuador (EC)',
    'VE' => 'Venezuela (VE)'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Instancia - Desk Service</title>
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
            padding: 40px 0;
        }
        .container-tenant {
            max-width: 650px;
            width: 100%;
            padding: 15px;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .form-control-user {
            border-radius: 12px;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            background-color: #f9fafb;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .form-control-user:focus {
            background-color: #fff;
            border-color: var(--blue-main);
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.15);
        }
        .input-group-text {
            font-size: 0.85rem;
            padding: 0.5rem 0.75rem;
        }
        .hero-section {
            background: linear-gradient(90deg,#2563eb 0%,#1d4ed8 50%,#0ea5e9 100%);
            color: white;
            border-radius: 24px 24px 0 0;
            padding: 25px;
        }
        .form-label {
            font-size: 0.75rem;
            margin-bottom: 0.4rem;
        }
        .fw-black { font-weight: 800; }
        .btn-animated {
            background: var(--blue-main);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        .btn-animated:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }
    </style>
</head>
<body>

<div class="container container-tenant">
    <div class="card glass-card overflow-hidden">
        <div class="hero-section text-center">
            <h1 class="fw-black mb-2">Creación de Instancia</h1>
            <p class="opacity-80 mb-0">Crea tu propia instancia de Desk Service para tu empresa</p>
        </div>

        <div class="p-4 p-sm-5">
            <?php if ($limit_reached): ?>
                <div class="text-center">
                    <div class="alert alert-warning border-0 shadow-sm rounded-4 p-4">
                        <i class="fas fa-exclamation-triangle fs-1 mb-3 text-warning"></i>
                        <h4 class="fw-bold">Límite Alcanzado</h4>
                        <p class="mb-0">Has alcanzado el límite de <strong>2 instancias gratuitas</strong>.</p>
                        <p class="small text-muted mt-2">Para crear más instancias, debes activar un plan de pago en alguna de tus empresas actuales.</p>
                        <hr>
                        <a href="myaccount_dashboard" class="btn btn-warning btn-sm rounded-pill px-4">Volver al Tablero</a>
                    </div>
                </div>
            <?php else: ?>
                <?php 
                $error = $_SESSION['error'] ?? $_GET['error'] ?? null;
                $success = $_SESSION['success'] ?? $_GET['success'] ?? null;
                
                if ($error): ?>
                    <div class="alert alert-danger rounded-3">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success rounded-3">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <form action="tenant_actions.php" method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="create_tenant">

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small text-uppercase">Nombre de la Empresa</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-building text-muted"></i></span>
                            <input type="text" class="form-control form-control-user border-start-0 ps-0" name="company_name" placeholder="Mi Empresa S.A.S" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small text-uppercase">URL de tu Empresa</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted">deskserviced.alwaysdata.net/</span>
                            <input type="text" class="form-control form-control-user border-start-0" name="subdomain" placeholder="miempresa" required pattern="[a-z0-9-]+">
                        </div>
                        <small class="text-muted">Solo letras minúsculas, números y guiones (Ej: deskserviced.alwaysdata.net/mi-empresa)</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small text-uppercase">Tu Nombre</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-user text-muted"></i></span>
                            <input type="text" class="form-control form-control-user border-start-0 ps-0" name="admin_name" value="<?php echo htmlspecialchars($currentUser['name'] ?? ''); ?>" required <?php echo $currentUser ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small text-uppercase">Correo Electrónico</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-envelope text-muted"></i></span>
                            <input type="email" class="form-control form-control-user border-start-0 ps-0" name="admin_email" value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" required <?php echo $currentUser ? 'readonly' : ''; ?>>
                        </div>
                        <?php if ($currentUser): ?>
                            <small class="text-muted">Tu instancia estará vinculada a tu cuenta actual.</small>
                        <?php endif; ?>
                    </div>

                    <?php if ($currentUser): ?>
                        <div class="alert alert-info py-2 px-3 mb-4 rounded-3 border-0" style="background: rgba(13, 110, 253, 0.05);">
                            <p class="small mb-0 text-primary"><i class="fas fa-info-circle me-2"></i>Como ya tienes una cuenta, no necesitas configurar una nueva contraseña.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-muted small text-uppercase">Contraseña</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-lock text-muted"></i></span>
                                    <input type="password" class="form-control form-control-user border-start-0 ps-0" name="password" placeholder="••••••••" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-muted small text-uppercase">Confirmar</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-lock text-muted"></i></span>
                                    <input type="password" class="form-control form-control-user border-start-0 ps-0" name="confirm_password" placeholder="••••••••" required>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small text-uppercase">País</label>
                        <select class="form-select form-control-user" name="country" required>
                            <?php foreach ($countries as $code => $name): ?>
                                <option value="<?php echo $code; ?>" <?php echo $code === 'CO' ? 'selected' : ''; ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small text-uppercase">Teléfono (opcional)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="fas fa-phone text-muted"></i></span>
                            <input type="tel" class="form-control form-control-user border-start-0 ps-0" name="phone" placeholder="+57 300 123 4567">
                        </div>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox" class="form-check-input" id="terms" name="terms" required>
                        <label class="form-check-label text-muted small" for="terms">
                            Acepto los términos y condiciones y la política de privacidad
                        </label>
                    </div>

                    <button type="submit" class="btn-animated w-100">
                        <span class="btn-text"><i class="fas fa-rocket me-2"></i>Crear mi Instancia</span>
                    </button>
                </form>

                <div class="text-center mt-4">
                    <span class="text-muted small">¿Ya tienes una instancia?</span>
                    <a href="login" class="text-decoration-none small text-primary fw-bold ms-1">Inicia Sesión aquí</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
