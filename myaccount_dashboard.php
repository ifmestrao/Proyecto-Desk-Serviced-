<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

if (!isset($_SESSION['myaccount_user_id'])) {
    header("Location: myaccount_login.php");
    exit;
}

$user_id = $_SESSION['myaccount_user_id'];
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$email = $user['email'] ?? '';

$tenants = $pdo->prepare("SELECT * FROM tenants WHERE contact_email = ? ORDER BY created_at DESC");
$tenants->execute([$email]);
$tenants_list = $tenants->fetchAll(PDO::FETCH_ASSOC);
// Contar instancias del usuario para el límite
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE contact_email = ?");
$stmt->execute([$email]);
$total_tenants = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE contact_email = ? AND plan_status != 'trial' AND status = 'active'");
$stmt->execute([$email]);
$paid_tenants = (int)$stmt->fetchColumn();

$can_create_more = !($total_tenants >= 2 && $paid_tenants == 0);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Cuenta - Desk Service</title>
    <link rel="shortcut icon" href="assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }
        .navbar-top {
            background: #ffffff;
            padding: 0.75rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .navbar-top .nav-link {
            color: #64748b;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.2s;
            font-size: 0.9rem;
        }
        .navbar-top .nav-link:hover, .navbar-top .nav-link.active {
            color: var(--blue-main);
            background: rgba(37, 99, 235, 0.05);
        }
        .alert-trial {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            padding: 1.25rem;
            margin-bottom: 2rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-dashboard {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .card-dashboard:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.05);
        }
        .btn-pay {
            background: #009ee3; /* Mercado Pago Blue */
            color: white;
            border: none;
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .btn-pay:hover {
            background: #0081bc;
            transform: scale(1.02);
            color: white;
        }
        .fw-black { font-weight: 800; }
        .text-primary-custom { color: var(--blue-main); }
    </style>
</head>
<body>

<nav class="navbar navbar-top">
    <div class="container-fluid">
        <a href="/" class="navbar-brand d-flex align-items-center gap-2">
            <img src="assets/img/deskser.png" width="32" height="32" alt="Logo">
            <span class="fw-black text-dark fs-4">Desk Service</span>
        </a>
        <div class="d-flex gap-1">
            <a href="myaccount_dashboard" class="nav-link active">Tablero</a>
            <a href="#" class="nav-link">Instancias</a>
            <a href="#" class="nav-link">Facturación</a>
        </div>
        <div class="ms-auto d-flex align-items-center gap-3">
            <div class="dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle me-2 fs-5"></i><?php echo htmlspecialchars($_SESSION['myaccount_email']); ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm rounded-3">
                    <li><a class="dropdown-item py-2" href="#"><i class="fas fa-user-cog me-2 opacity-50"></i>Perfil</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="myaccount_actions.php" method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="myaccount_logout">
                            <button type="submit" class="dropdown-item py-2 text-danger"><i class="fas fa-sign-out-alt me-2 opacity-50"></i>Salir</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <h2 class="fw-bold mb-1">¡Hola de nuevo!</h2>
            <p class="text-muted mb-0">Gestiona tus instancias y pagos desde aquí.</p>
        </div>
        <?php if ($can_create_more): ?>
            <a href="create_tenant.php" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold shadow-sm">
                <i class="fas fa-plus me-2"></i>Nueva Instancia
            </a>
        <?php else: ?>
            <button class="btn btn-secondary rounded-pill px-4 py-2 fw-semibold shadow-sm" disabled title="Límite alcanzado">
                <i class="fas fa-lock me-2"></i>Nueva Instancia
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$can_create_more): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-4 small py-2 px-3">
            <i class="fas fa-info-circle me-2"></i> Has alcanzado el límite de 2 instancias gratuitas. Activa un plan en una de ellas para crear más.
        </div>
    <?php endif; ?>

    <?php if (!empty($tenants_list)): ?>
        <?php foreach ($tenants_list as $tenant): ?>
            <?php 
            $days_left = null;
            $is_paid = ($tenant['plan_status'] === 'paid');
            
            if (!$is_paid) {
                // Cálculo real basado en la fecha de creación (7 días de prueba)
                $created_date = new DateTime($tenant['created_at']);
                $trial_end_date = clone $created_date;
                $trial_end_date->modify('+7 days');
                $now = new DateTime();
                
                if ($trial_end_date > $now) {
                    $interval = $now->diff($trial_end_date);
                    $days_left = $interval->days;
                } else {
                    $days_left = 0; // Expirado
                }
            }
            ?>
            <div class="alert-trial border-0 shadow-sm mb-4 <?php echo ($days_left === 0 && !$is_paid) ? 'bg-danger bg-opacity-10 border-danger' : ''; ?>">
                <div class="d-flex align-items-center gap-3">
                    <?php if ($is_paid): ?>
                        <div class="bg-success bg-opacity-20 text-success rounded-circle p-3">
                            <i class="fas fa-check-circle fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-1 fw-bold text-dark">Instancia Activa: deskserviced.alwaysdata.net<?php echo htmlspecialchars($tenant['subdomain']); ?></h6>
                            <p class="mb-0 text-muted small">Tu plan profesional está activo. ¡Gracias por confiar en nosotros!</p>
                        </div>
                    <?php elseif ($days_left > 0): ?>
                        <div class="bg-primary bg-opacity-20 text-primary rounded-circle p-3">
                            <i class="fas fa-rocket fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-1 fw-bold text-dark">Prueba gratuita: deskserviced.alwaysdata.net/<?php echo htmlspecialchars($tenant['subdomain']); ?></h6>
                            <p class="mb-0 text-muted small">Te quedan <strong><?php echo $days_left; ?> días</strong> de acceso completo. No pierdas tus datos.</p>
                        </div>
                    <?php else: ?>
                        <div class="bg-danger bg-opacity-20 text-danger rounded-circle p-3">
                            <i class="fas fa-exclamation-triangle fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-1 fw-bold text-danger">Prueba expirada: deskserviced.alwaysdata.net/<?php echo htmlspecialchars($tenant['subdomain']); ?></h6>
                            <p class="mb-0 text-muted small">Tu período de prueba ha terminado. Realiza el pago para continuar usando la plataforma.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if (!$is_paid): ?>
                        <a href="pay_mercadopago.php?tenant_id=<?php echo $tenant['id']; ?>" class="btn-pay">
                            <i class="fas fa-credit-card me-2"></i>Mercado Pago ($16.00)
                        </a>
                        <a href="pay_paypal.php?tenant_id=<?php echo $tenant['id']; ?>" class="btn-pay" style="background: #003087;">
                            <i class="fab fa-paypal me-2"></i>PayPal ($16.00)
                        </a>
                    <?php endif; ?>
                    <form action="auth.php" method="POST" style="display: inline;">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="switch_tenant">
                        <input type="hidden" name="tenant_id" value="<?php echo $tenant['id']; ?>">
                        <button type="submit" class="btn btn-light border fw-semibold px-3 py-2">
                            Entrar <i class="fas fa-external-link-alt ms-1 small"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center py-5">
            <img src="assets/img/Mesadeservicio.png" width="200" class="opacity-20 mb-4" style="filter: grayscale(1);">
            <h5 class="text-muted">No tienes instancias activas</h5>
            <p class="small text-muted mb-4">Comienza creando tu primera instancia de Desk Service.</p>
            <a href="create_tenant.php" class="btn btn-primary rounded-pill px-4">Crear Instancia</a>
        </div>
    <?php endif; ?>

    <div class="row g-4 mt-2">
        <div class="col-md-4">
            <div class="card-dashboard h-100">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-3 p-3">
                        <i class="fas fa-layer-group fs-4"></i>
                    </div>
                    <h5 class="fw-bold mb-0">Instancias</h5>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Total activas</span>
                    <span class="fs-2 fw-bold text-dark"><?php echo count($tenants_list); ?></span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-dashboard h-100">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="bg-success bg-opacity-10 text-success rounded-3 p-3">
                        <i class="fas fa-file-invoice-dollar fs-4"></i>
                    </div>
                    <h5 class="fw-bold mb-0">Facturación</h5>
                </div>
                <?php 
                $needs_payment = 0;
                foreach($tenants_list as $t) {
                    if ($t['trial_ends_at']) {
                        $diff = (new DateTime())->diff(new DateTime($t['trial_ends_at']));
                        if ($diff->invert == 0 && $diff->days <= 3) $needs_payment++;
                        if ($diff->invert == 1) $needs_payment++;
                    }
                }
                ?>
                <?php if ($needs_payment > 0): ?>
                    <p class="text-danger small mb-0 fw-bold"><i class="fas fa-exclamation-circle me-1"></i> Tienes <?php echo $needs_payment; ?> instancia(s) por vencer o vencidas.</p>
                <?php else: ?>
                    <p class="text-muted small mb-0">No hay facturas pendientes por el momento.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <a href="https://wa.me/573024070830?text=<?php echo urlencode('Hola, Necesito soporte para mi desk service.'); ?>" target="_blank" class="text-decoration-none">
                <div class="card-dashboard h-100 border-primary border-opacity-25 bg-primary bg-opacity-10">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="bg-primary text-white rounded-3 p-3 shadow-sm">
                            <i class="fab fa-whatsapp fs-4"></i>
                        </div>
                        <h5 class="fw-bold mb-0 text-primary">Soporte</h5>
                    </div>
                    <p class="text-primary small mb-0 fw-semibold">Click aquí para soporte vía WhatsApp <i class="fas fa-external-link-alt ms-1 small"></i></p>
                </div>
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
