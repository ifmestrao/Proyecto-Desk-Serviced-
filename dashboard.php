<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';

// Ejecutar revisión de alertas de vencimiento (Simulación de Cron)
include_once 'cron_overdue_alerts.php';

$role = isset($_SESSION['role']) ? $_SESSION['role'] : null;
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'mine';

$joinAssigned = "LEFT JOIN ticket_techs tt ON tt.ticket_id = t.id AND tt.active = 1";
$baseConditions = ["t.tenant_id = ?"];
$baseParams = [$tenant_id];

if ($view_mode === 'mine') {
    $baseConditions[] = "(tt.tech_id = ? OR t.assigned_tech_id = ? OR t.user_id = ?)";
    $baseParams = array_merge($baseParams, [$current_user_id, $current_user_id, $current_user_id]);
} elseif ($view_mode === 'unassigned') {
    $baseConditions[] = "(tt.tech_id IS NULL AND t.assigned_tech_id IS NULL)";
} elseif ($view_mode === 'all') {
    // Todos los tickets
} else {
    $view_mode = 'mine';
    $baseConditions[] = "(tt.tech_id = ? OR t.assigned_tech_id = ? OR t.user_id = ?)";
    $baseParams = array_merge($baseParams, [$current_user_id, $current_user_id, $current_user_id]);
}

function buildCountQuery($pdo, $statusCondition = null) {
    global $baseConditions, $baseParams, $joinAssigned;
    $conditions = $baseConditions;
    if ($statusCondition) {
        $conditions[] = $statusCondition;
    }
    $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $sql = "SELECT COUNT(DISTINCT t.id) FROM tickets t $joinAssigned $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    return (int)$stmt->fetchColumn();
}

// Fetch Statistics
$stats = [
    'total' => buildCountQuery($pdo, "t.status != 'closed'"),
    'open' => 0,
    'pending' => 0,
    'closed' => buildCountQuery($pdo, "t.status = 'closed'"),
    'overdue' => buildCountQuery($pdo, "t.due_date < NOW() AND t.status != 'closed'")
];

$where = !empty($baseConditions) ? 'WHERE ' . implode(' AND ', $baseConditions) : '';
$stmt = $pdo->prepare("SELECT t.status, COUNT(DISTINCT t.id) as count FROM tickets t $joinAssigned $where GROUP BY t.status");
$stmt->execute($baseParams);
while ($row = $stmt->fetch()) {
    $stats[$row['status']] = (int)$row['count'];
}

// Recent Tickets con búsqueda y paginación
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$listConditions = $baseConditions;
$listConditions[] = "t.status != 'closed'";
$listParams = $baseParams;

if ($q !== '') {
    if (ctype_digit($q)) {
        $listConditions[] = "t.local_id = ?";
        $listParams[] = (int)$q;
    } else {
        $listConditions[] = "(t.title LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        $like = '%'.$q.'%';
        $listParams[] = $like;
        $listParams[] = $like;
        $listParams[] = $like;
    }
}

$whereClause = !empty($listConditions) ? "WHERE " . implode(" AND ", $listConditions) : "";
$countSql = "SELECT COUNT(DISTINCT t.id) 
             FROM tickets t 
             JOIN users u ON t.user_id = u.id 
             JOIN categories c ON t.category_id = c.id 
             $joinAssigned
             $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($listParams);
$total_filtered = (int)$stmt->fetchColumn();

$sql = "SELECT t.*, u.name as user_name, c.name as category_name 
        FROM tickets t 
        JOIN users u ON t.user_id = u.id 
        JOIN categories c ON t.category_id = c.id 
        $joinAssigned
        $whereClause
        GROUP BY t.id
        ORDER BY t.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($listParams);
$recent_tickets = $stmt->fetchAll();

// Tickets per day (Last 7 days)
$chartConditions = $baseConditions;
$chartConditions[] = "t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$chartWhere = 'WHERE ' . implode(' AND ', $chartConditions);
$stmt = $pdo->prepare("SELECT DATE(t.created_at) as date, COUNT(DISTINCT t.id) as count 
                       FROM tickets t 
                       $joinAssigned
                       $chartWhere 
                       GROUP BY DATE(t.created_at) 
                       ORDER BY date ASC");
$stmt->execute($baseParams);
$daily_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fill missing days
$last_7_days = [];
$last_7_days_counts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $last_7_days[] = $date;
    $last_7_days_counts[] = isset($daily_stats[$date]) ? $daily_stats[$date] : 0;
}

// Categories counts
$catWhereStr = !empty($baseConditions) ? " AND (" . implode(' AND ', $baseConditions) . ") OR t.id IS NULL" : "";
$stmt = $pdo->prepare("SELECT c.name as category, COUNT(DISTINCT t.id) as count
                       FROM categories c
                       LEFT JOIN tickets t ON t.category_id = c.id
                       $joinAssigned
                       WHERE c.tenant_id = ? $catWhereStr
                       GROUP BY c.id
                       ORDER BY c.name");
$stmt->execute(array_merge([$tenant_id], $baseParams));
$category_labels = [];
$category_counts = [];
while ($r = $stmt->fetch()) {
    $category_labels[] = $r['category'];
    $category_counts[] = (int)$r['count'];
}

// Categories list for filter
$cats = $pdo->prepare("SELECT id, name FROM categories WHERE tenant_id = ? ORDER BY name");
$cats->execute([$tenant_id]);
$cats = $cats->fetchAll();

// Priority distribution
$stmt = $pdo->prepare("SELECT t.priority, COUNT(DISTINCT t.id) c FROM tickets t $joinAssigned $where GROUP BY t.priority");
$stmt->execute($baseParams);
$priority_counts = ['low'=>0,'medium'=>0,'high'=>0,'critical'=>0];
while ($r = $stmt->fetch()) { $priority_counts[$r['priority']] = (int)$r['c']; }
?>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container">

    <div class="d-flex justify-content-between align-items-center my-3">

        <div class="btn-group shadow-sm">
            <a href="?view=mine" class="btn btn-sm <?php echo $view_mode === 'mine' ? 'btn-primary' : 'btn-light border'; ?>">Mis Tickets</a>
            <a href="?view=unassigned" class="btn btn-sm <?php echo $view_mode === 'unassigned' ? 'btn-primary' : 'btn-light border'; ?>">Sin Asignar</a>
            <a href="?view=all" class="btn btn-sm <?php echo $view_mode === 'all' ? 'btn-primary' : 'btn-light border'; ?>">Todos</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 kpi-card overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="icon-circle-sm bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary" style="font-size: 0.6rem;">TOTAL</span>
                    </div>
                    <h6 class="text-muted small fw-bold mb-1">Volumen Activo</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $stats['total']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;"><div class="progress-bar bg-primary" style="width: 100%"></div></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 kpi-card overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="icon-circle-sm bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <span class="badge rounded-pill bg-warning bg-opacity-10 text-warning" style="font-size: 0.6rem;">PENDIENTES</span>
                    </div>
                    <h6 class="text-muted small fw-bold mb-1">En Espera</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $stats['pending']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;"><div class="progress-bar bg-warning" style="width: <?php echo $stats['total'] > 0 ? ($stats['pending']/$stats['total'])*100 : 0; ?>%"></div></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 kpi-card overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="icon-circle-sm bg-success bg-opacity-10 text-success">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success" style="font-size: 0.6rem;">ABIERTOS</span>
                    </div>
                    <h6 class="text-muted small fw-bold mb-1">En Atención</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $stats['open']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;"><div class="progress-bar bg-success" style="width: <?php echo $stats['total'] > 0 ? ($stats['open']/$stats['total'])*100 : 0; ?>%"></div></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 kpi-card overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="icon-circle-sm bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger" style="font-size: 0.6rem;">FUERA PLAZO</span>
                    </div>
                    <h6 class="text-muted small fw-bold mb-1">Vencidos</h6>
                    <h3 class="mb-0 fw-bold"><?php echo $stats['overdue']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;"><div class="progress-bar bg-danger" style="width: <?php echo $stats['total'] > 0 ? ($stats['overdue']/$stats['total'])*100 : 0; ?>%"></div></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-list me-2 text-muted"></i>Listado de Tickets</h6>
            <form class="d-flex gap-2" method="GET" style="max-width: 400px;">
                <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control bg-light border-start-0" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="ID o título...">
                    <button class="btn btn-primary" type="submit">Buscar</button>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Asunto / Solicitante</th>
                            <th>Categoría</th>
                            <th>Prioridad</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_tickets as $t): ?>
                        <tr>
                            <td class="ps-4 fw-bold">#<?php echo $t['local_id']; ?></td>
                            <td>
                                <div class="fw-bold text-dark text-truncate" style="max-width: 250px;"><?php echo htmlspecialchars($t['title']); ?></div>
                                <div class="text-muted extra-small"><?php echo htmlspecialchars($t['user_name']); ?></div>
                            </td>
                            <td><span class="badge bg-light text-muted border"><?php echo htmlspecialchars($t['category_name']); ?></span></td>
                            <td><span class="badge bg-<?php echo p_color($t['priority']); ?> bg-opacity-10 <?php echo str_replace('bg-', 'text-', p_color($t['priority'])); ?> rounded-pill border border-opacity-10" style="font-size: 0.65rem;"><?php echo strtoupper(t_priority($t['priority'])); ?></span></td>
                            <td><span class="badge bg-<?php echo ($t['status'] === 'closed') ? 'status-closed' : 'secondary'; ?> bg-opacity-10 <?php echo ($t['status'] === 'closed' ? 'text-secondary' : 'text-primary'); ?> rounded-pill border border-opacity-10" style="font-size: 0.65rem;"><?php echo strtoupper(t_status($t['status'])); ?></span></td>
                            <td class="text-end pe-4">
                                <a href="ticket_details.php?id=<?php echo $t['id']; ?>" class="btn btn-action btn-edit" title="Ver Detalles"><i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($recent_tickets)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No se encontraron tickets.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white py-3 border-0">
            <div class="d-flex justify-content-between align-items-center">
                <?php 
                $pages = max(1, ceil($total_filtered / $limit));
                $prevPage = max(1, $page - 1);
                $nextPage = min($pages, $page + 1);
                $qParam = !empty($q) ? "&q=".urlencode($q) : "";
                $vParam = !empty($view_mode) ? "&view=".urlencode($view_mode) : "";
                ?>
                <small class="text-muted">Página <?php echo $page; ?> de <?php echo $pages; ?></small>
                <div class="btn-group shadow-sm">
                    <a class="btn btn-xs btn-light border <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo $prevPage.$qParam.$vParam; ?>"><i class="fas fa-chevron-left"></i></a>
                    <a class="btn btn-xs btn-light border <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?page=<?php echo $nextPage.$qParam.$vParam; ?>"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .kpi-card { transition: transform 0.2s; border: 1px solid rgba(0,0,0,0.05) !important; }
    .kpi-card:hover { transform: translateY(-3px); }
    .icon-circle-sm { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .extra-small { font-size: 0.75rem; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
</style>

<?php require_once 'includes/footer.php'; ?>
