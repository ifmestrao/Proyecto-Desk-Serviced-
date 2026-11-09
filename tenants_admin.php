<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['tenant_id'] != 1 || $_SESSION['role'] != 'admin') {
    header("Location: dashboard.php");
    exit;
}

$tenants = $pdo->query("SELECT t.*, COUNT(u.id) AS user_count FROM tenants t LEFT JOIN users u ON t.id = u.tenant_id GROUP BY t.id ORDER BY t.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Tenants - Desk Service</title>
    <link rel="shortcut icon" href="assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require_once 'includes/navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="fas fa-building text-primary me-2"></i>Gestión de Instancias (Tenants)</h2>
        <a href="dashboard.php" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2"></i>Volver</a>
    </div>

    <div class="card glass-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Empresa</th>
                            <th>Subdominio</th>
                            <th>Contacto</th>
                            <th>Plan</th>
                            <th>Usuarios</th>
                            <th>Estado</th>
                            <th>Prueba Hasta</th>
                            <th>Creado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $tenant): ?>
                            <tr>
                                <td><?php echo $tenant['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($tenant['name']); ?></strong></td>
                                <td><?php echo $tenant['subdomain'] ? htmlspecialchars($tenant['subdomain']) : '-'; ?></td>
                                <td>
                                    <?php if ($tenant['contact_email']): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($tenant['contact_email']); ?>">
                                            <?php echo htmlspecialchars($tenant['contact_email']); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $tenant['plan'] == 'enterprise' ? 'primary' : ($tenant['plan'] == 'basic' ? 'info' : 'secondary'); ?>">
                                        <?php echo ucfirst($tenant['plan']); ?>
                                    </span>
                                </td>
                                <td><?php echo $tenant['user_count']; ?></td>
                                <td>
                                    <?php if ($tenant['status'] == 'active'): ?>
                                        <span class="badge bg-success">Activa</span>
                                    <?php elseif ($tenant['status'] == 'inactive'): ?>
                                        <span class="badge bg-warning">Inactiva</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Suspendida</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tenant['trial_ends_at']): ?>
                                        <?php 
                                        $trialEnd = new DateTime($tenant['trial_ends_at']);
                                        $now = new DateTime();
                                        $diff = $now->diff($trialEnd);
                                        if ($trialEnd > $now): ?>
                                            <span class="text-success"><?php echo $trialEnd->format('d/m/Y'); ?> (<?php echo $diff->days; ?> días)</span>
                                        <?php else: ?>
                                            <span class="text-danger">Expirado</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (new DateTime($tenant['created_at']))->format('d/m/Y'); ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#"><i class="fas fa-eye me-2"></i>Ver Detalles</a></li>
                                            <li><a class="dropdown-item" href="#"><i class="fas fa-edit me-2"></i>Editar</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($tenant['status'] == 'active'): ?>
                                                <li><a class="dropdown-item text-warning" href="#"><i class="fas fa-pause me-2"></i>Suspender</a></li>
                                            <?php else: ?>
                                                <li><a class="dropdown-item text-success" href="#"><i class="fas fa-play me-2"></i>Activar</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
