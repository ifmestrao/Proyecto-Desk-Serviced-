<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';
require_once 'includes/csrf.php';

$acta_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Fetch Acta Details
require_once 'config/middleware_tenant.php';
$tenant_filter_a = tenant_where('a');
$tenant_filter_u = tenant_where('u');
$tenant_filter_creator = tenant_where('creator');
$stmt = $pdo->prepare("SELECT a.*, u.name as user_name, u.email as user_email, u.position as user_position, creator.name as creator_name, ast.serial_number as asset_serial, ast.brand as asset_brand, ast.model as asset_model 
                       FROM actas a 
                       JOIN users u ON a.user_id = u.id 
                       JOIN users creator ON a.created_by = creator.id 
                       LEFT JOIN assets ast ON a.asset_id = ast.id 
                       WHERE a.id = ? AND $tenant_filter_a AND $tenant_filter_u AND $tenant_filter_creator");
$stmt->execute([$acta_id]);
$acta = $stmt->fetch();

if (!$acta) {
    header("Location: dashboard.php");
    exit();
}

// Check Permissions (Only user or staff can view)
if ($role == 'user' && $acta['user_id'] != $user_id) {
    header("Location: dashboard_user.php");
    exit();
}

// Handle Approval/Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acta['user_id'] == $user_id && $acta['status'] == 'pending') {
    if (!csrf_check()) {
        die("CSRF validation failed");
    }

    $new_status = isset($_POST['approve']) ? 'approved' : 'rejected';
    $comment = trim($_POST['comment']);

    try {
        $upd = $pdo->prepare("UPDATE actas SET status = ?, response_comment = ?, approved_at = NOW() WHERE id = ?");
        $upd->execute([$new_status, $comment, $acta_id]);
        
        // Notify Creator
        notify_add($pdo, (int)$acta['created_by'], 'acta_response', "Acta #$acta_id respondida", "El usuario ha $new_status el acta: " . $acta['title'], "actas.php", 'acta', $acta_id);

        header("Location: acta_view.php?id=$acta_id&success=response");
        exit();
    } catch (PDOException $e) {
        $error = "Error al procesar la respuesta: " . $e->getMessage();
    }
}

$content = json_decode($acta['content'], true);

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 py-5">
    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-11">
            <div class="card border-0 shadow-lg">
                <div class="card-body p-5">
                    <!-- Acta Header -->
                    <div class="text-center mb-5 pb-3 border-bottom">
                        <i class="fas fa-file-signature text-primary fs-1 mb-3"></i>
                        <h2 class="fw-bold text-uppercase"><?php echo htmlspecialchars($acta['title']); ?></h2>
                        <p class="text-muted mb-0">Documento Oficial de <?php echo ucfirst($acta['type']); ?></p>
                        <p class="small text-muted">ID: #<?php echo $acta['id']; ?> · Fecha: <?php echo date('d/m/Y', strtotime($acta['created_at'])); ?></p>
                    </div>

                    <!-- Acta Content -->
                    <div class="row g-5 mb-5">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-3 border-start border-primary border-4 ps-2">Información del Usuario</h5>
                            <div class="bg-light p-3 rounded">
                                <p class="mb-1"><span class="text-muted small">Nombre:</span><br><strong><?php echo htmlspecialchars($acta['user_name']); ?></strong></p>
                                <p class="mb-1 mt-2"><span class="text-muted small">Cargo:</span><br><strong><?php echo htmlspecialchars($acta['user_position'] ?? 'N/A'); ?></strong></p>
                                <p class="mb-0 mt-2"><span class="text-muted small">Correo:</span><br><strong><?php echo htmlspecialchars($acta['user_email']); ?></strong></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-3 border-start border-primary border-4 ps-2">Información del Equipo</h5>
                            <div class="bg-light p-3 rounded h-100">
                                <?php if ($acta['asset_id']): ?>
                                    <p class="mb-1"><span class="text-muted small">Equipo:</span><br><strong><?php echo htmlspecialchars($acta['asset_brand']); ?> <?php echo htmlspecialchars($acta['asset_model']); ?></strong></p>
                                    <p class="mb-0 mt-2"><span class="text-muted small">S/N:</span><br><strong><?php echo htmlspecialchars($acta['asset_serial']); ?></strong></p>
                                <?php else: ?>
                                    <p class="text-muted italic py-4">No se relacionó un equipo específico.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-5">
                        <h5 class="fw-bold mb-3 border-start border-primary border-4 ps-2">
                            <?php echo $acta['type'] == 'entrega' ? 'Detalles de la Entrega' : 'Proceso de Mantenimiento Realizado'; ?>
                        </h5>
                        <div class="p-4 border rounded bg-white">
                            <?php echo nl2br(htmlspecialchars($content['details'])); ?>
                        </div>
                    </div>

                    <!-- Status Banner -->
                    <?php if ($acta['status'] != 'pending'): ?>
                        <div class="alert alert-<?php echo $acta['status'] == 'approved' ? 'success' : 'danger'; ?> d-flex align-items-center mb-5">
                            <i class="fas <?php echo $acta['status'] == 'approved' ? 'fa-check-circle' : 'fa-times-circle'; ?> fs-4 me-3"></i>
                            <div>
                                <h6 class="mb-0 fw-bold">Esta acta fue <?php echo $acta['status'] == 'approved' ? 'APROBADA' : 'RECHAZADA'; ?>.</h6>
                                <p class="mb-0 small">Fecha: <?php echo date('d/m/Y H:i', strtotime($acta['approved_at'])); ?></p>
                                <?php if ($acta['response_comment']): ?>
                                    <p class="mb-0 mt-2 border-top pt-2">Comentario: <?php echo htmlspecialchars($acta['response_comment']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- User Response Form -->
                    <?php if ($acta['status'] == 'pending' && $acta['user_id'] == $user_id): ?>
                        <div class="card bg-light border-0">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-3 text-center">Confirmación y Firma Digital</h5>
                                <form action="" method="POST">
                                    <?php echo csrf_input(); ?>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Comentarios</label>
                                        <textarea name="comment" class="form-control" rows="2" placeholder="Recibido conforme o El equipo tiene un rayón..."></textarea>
                                    </div>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                        <button type="submit" name="reject" class="btn btn-outline-danger px-5" onclick="return confirm('¿Estás seguro de que quieres rechazar esta acta?');">
                                            <i class="fas fa-times me-2"></i>Rechazar
                                        </button>
                                        <button type="submit" name="approve" class="btn btn-success px-5">
                                            <i class="fas fa-check me-2"></i>Aprobar y Firmar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Footer Info -->
                    <div class="text-center mt-5 pt-4 border-top text-muted small">
                        <p class="mb-0">Generado por: <?php echo htmlspecialchars($acta['creator_name']); ?></p>
                        <p class="mb-0">Este documento es una firma digital válida para la recepción y conformidad de equipos.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>