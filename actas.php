<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/middleware_tenant.php';

$pageTitle = 'Gestión de Actas';
$role = $_SESSION['role'];

// Paginación
$per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Obtener total de actas
$tenant_filter_a = tenant_where('a');
$tenant_filter_u = tenant_where('u');
$tenant_filter_c = tenant_where('creator');
$count_sql = "SELECT COUNT(*) FROM actas a 
              JOIN users u ON a.user_id = u.id 
              JOIN users creator ON a.created_by = creator.id 
              LEFT JOIN assets ast ON a.asset_id = ast.id 
              WHERE $tenant_filter_a AND $tenant_filter_u AND $tenant_filter_c";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute();
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Obtener actas de la página actual
$sql = "SELECT a.*, u.name as user_name, creator.name as creator_name, ast.serial_number as asset_serial 
        FROM actas a 
        JOIN users u ON a.user_id = u.id 
        JOIN users creator ON a.created_by = creator.id 
        LEFT JOIN assets ast ON a.asset_id = ast.id 
        WHERE $tenant_filter_a AND $tenant_filter_u AND $tenant_filter_c
        ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$per_page, $offset]);
$actas = $stmt->fetchAll();

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <p class="text-muted small mb-0">Documentación oficial de entregas, devoluciones y mantenimientos técnicos.</p>
        </div>
        <a href="actas_crear.php" class="btn btn-primary btn-sm shadow-sm px-3">
            <i class="fas fa-plus me-2"></i>Nueva Acta
        </a>
    </div>

    <div class="card border-0 shadow-sm" style="margin-bottom: 30px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Tipo de Trámite</th>
                            <th>Título / Referencia</th>
                            <th>Solicitante / Usuario</th>
                            <th>Activo Vinculado</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($actas)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <img src="assets/img/empty-state.svg" alt="" style="width: 60px; opacity: 0.3;" class="mb-3 d-block mx-auto">
                                    <p>No se han generado actas oficiales aún.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($actas as $acta): ?>
                            <tr>
                                <td class="ps-4 fw-bold">#<?php echo $acta['id']; ?></td>
                                <td>
                                    <?php 
                                        $type_class = $acta['type'] == 'entrega' ? 'bg-info' : 'bg-warning';
                                        $type_text = $acta['type'] == 'entrega' ? 'ENTREGA' : 'MANTENIMIENTO';
                                    ?>
                                    <span class="badge rounded-pill <?php echo $type_class; ?> bg-opacity-10 <?php echo str_replace('bg-', 'text-', $type_class); ?> border border-opacity-10" style="font-size: 0.6rem;">
                                        <?php echo $type_text; ?>
                                    </span>
                                </td>
                                <td><span class="fw-bold text-dark"><?php echo htmlspecialchars($acta['title']); ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="far fa-user-circle me-2 text-muted"></i>
                                        <span><?php echo htmlspecialchars($acta['user_name']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($acta['asset_serial']): ?>
                                        <span class="badge bg-light text-muted border fw-normal"><i class="fas fa-box me-1"></i><?php echo htmlspecialchars($acta['asset_serial']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted extra-small">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $status_map = [
                                        'pending' => ['class' => 'bg-secondary', 'label' => 'PENDIENTE'],
                                        'approved' => ['class' => 'bg-success', 'label' => 'APROBADO'],
                                        'rejected' => ['class' => 'bg-danger', 'label' => 'RECHAZADO']
                                    ];
                                    $s = $status_map[$acta['status']];
                                    ?>
                                    <span class="badge <?php echo $s['class']; ?> bg-opacity-10 <?php echo str_replace('bg-', 'text-', $s['class']); ?> rounded-pill border border-opacity-10" style="font-size: 0.6rem;">
                                        <?php echo $s['label']; ?>
                                    </span>
                                </td>
                                <td class="text-muted extra-small"><?php echo date('d/m/Y', strtotime($acta['created_at'])); ?></td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <a href="acta_view.php?id=<?php echo $acta['id']; ?>" class="btn btn-action btn-edit" title="Visualizar Acta">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="acta_pdf.php?id=<?php echo $acta['id']; ?>" class="btn btn-action" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white;" title="Descargar PDF">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
                <!-- Paginación -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                    <small class="text-muted">Mostrando <?php echo ($offset + 1); ?> - <?php echo min($offset + count($actas), $total); ?> de <?php echo $total; ?> actas</small>
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($total_pages, $page + 1); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .extra-small { font-size: 0.7rem; }
</style>

<?php require_once 'includes/footer.php'; ?>