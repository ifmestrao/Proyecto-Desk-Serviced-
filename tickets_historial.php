<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';

$pageTitle = 'Historial de Tickets';
$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'mine';

// Paginación
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Filtros base
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
$where = ["t.status = 'closed'", "t.tenant_id = ?"];
$params = [$tenant_id];

// Restricción por rol: Técnico solo ve sus tickets, Supervisor/Admin ven todos
if ($role === 'tech' && $view_mode === 'mine') {
    $where[] = "(t.assigned_tech_id = ? OR t.user_id = ? OR EXISTS (SELECT 1 FROM ticket_techs tt WHERE tt.ticket_id = t.id AND tt.tech_id = ? AND tt.active = 1))";
    $params[] = $user_id;
    $params[] = $user_id;
    $params[] = $user_id;
}

// Búsqueda opcional
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q !== '') {
    $where[] = "(t.title LIKE ? OR t.local_id = ? OR u.name LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = (int)$q;
    $params[] = $like;
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Make a copy of params for count query
$count_params = $params;

// Contar total
$count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tickets t JOIN users u ON t.user_id = u.id $where_sql");
$count_stmt->execute($count_params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Consulta principal
$sql = "SELECT t.*, u.name as user_name, c.name as category_name 
        FROM tickets t 
        JOIN users u ON t.user_id = u.id 
        LEFT JOIN categories c ON t.category_id = c.id
        $where_sql 
        ORDER BY t.updated_at DESC 
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">

        <div class="d-flex gap-2">
            <a href="exportar.php" class="btn btn-sm btn-light border shadow-sm px-3"><i class="fas fa-file-export me-2 text-success"></i>Exportar</a>
            <button class="btn btn-primary btn-sm shadow-sm px-3" onclick="location.reload()"><i class="fas fa-sync-alt me-2"></i>Actualizar</button>
        </div>
    </div>

    <!-- Barra de Búsqueda Compacta -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-2">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control bg-light border-start-0" placeholder="ID, Título o Usuario..." value="<?php echo htmlspecialchars($q); ?>">
                    </div>
                </div>
                <?php if ($role === 'tech'): ?>
                <div class="col-md-3">
                    <select name="view" class="form-select form-select-sm bg-light">
                        <option value="mine" <?php echo $view_mode === 'mine' ? 'selected' : ''; ?>>Mis Tickets</option>
                        <option value="all" <?php echo $view_mode === 'all' ? 'selected' : ''; ?>>Todos los Tickets</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filtrar</button>
                </div>
                <div class="col-md-2">
                    <a href="tickets_historial.php" class="btn btn-sm btn-light border w-100">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Listado Moderno -->
    <div class="card border-0 shadow-sm" style="margin-bottom: 30px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Asunto / Solicitante</th>
                            <th>Categoría</th>
                            <th>Estado</th>
                            <th>Prioridad</th>
                            <th>Fecha Cierre</th>
                            <th class="text-end pe-4">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <img src="assets/img/empty-state.svg" alt="" style="width: 60px; opacity: 0.3;" class="mb-3 d-block mx-auto">
                                    <p>No se encontraron tickets cerrados.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($tickets as $t): ?>
                            <tr>
                                <td class="ps-4 fw-bold">#<?php echo $t['local_id']; ?></td>
                                <td>
                                    <div class="fw-bold text-dark text-truncate" style="max-width: 250px;"><?php echo htmlspecialchars($t['title']); ?></div>
                                    <div class="text-muted extra-small"><?php echo htmlspecialchars($t['user_name']); ?></div>
                                </td>
                                <td><span class="badge bg-light text-muted border"><?php echo htmlspecialchars($t['category_name'] ?? 'General'); ?></span></td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill border border-opacity-10" style="font-size: 0.65rem;">
                                        <?php echo strtoupper(t_status($t['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo p_color($t['priority']); ?> bg-opacity-10 <?php echo str_replace('bg-', 'text-', p_color($t['priority'])); ?> rounded-pill border border-opacity-10" style="font-size: 0.65rem;">
                                        <?php echo strtoupper(t_priority($t['priority'])); ?>
                                    </span>
                                </td>
                                <td class="text-muted extra-small">
                                    <?php echo date('d/m/Y H:i', strtotime($t['updated_at'])); ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="ticket_details.php?id=<?php echo $t['id']; ?>" class="btn btn-action btn-edit" title="Ver Detalles">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card-footer bg-white border-0 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">Página <?php echo $page; ?> de <?php echo max(1, $total_pages); ?></small>
                <div class="btn-group shadow-sm">
                    <?php 
                        $qParam = $q !== '' ? '&q='.urlencode($q) : '';
                        $vParam = '&view=' . $view_mode;
                    ?>
                    <a class="btn btn-xs btn-light border <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page-1).$qParam.$vParam; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <a class="btn btn-xs btn-light border <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($total_pages, $page+1).$qParam.$vParam; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .extra-small { font-size: 0.75rem; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
</style>

<?php require_once 'includes/footer.php'; ?>
