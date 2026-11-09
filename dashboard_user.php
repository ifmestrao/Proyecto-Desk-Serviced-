<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';

$user_id = $_SESSION['user_id'];
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;

// Búsqueda y paginación
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$conditions = ["t.user_id = ?", "t.tenant_id = ?"];
$params = [$user_id, $tenant_id];

if ($q !== '') {
    if (ctype_digit($q)) {
        $conditions[] = "t.local_id = ?";
        $params[] = (int)$q;
    } else {
        $conditions[] = "t.title LIKE ?";
        $like = '%' . $q . '%';
        $params[] = $like;
    }
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Contar total
$countSql = "SELECT COUNT(t.id) FROM tickets t $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total_filtered = (int)$stmt->fetchColumn();

// Obtener tickets
$sql = "SELECT t.*, c.name as category_name 
        FROM tickets t 
        JOIN categories c ON t.category_id = c.id 
        $whereClause
        ORDER BY t.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();
?>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-4">
        <div>
            <h3 class="fs-4 mb-0">Mis Tickets</h3>
            <p class="text-muted small">Sigue el estado de tus solicitudes de soporte.</p>
        </div>
        <a href="create_ticket.php" class="btn btn-primary shadow-sm">
            <i class="fas fa-plus me-2"></i>Nuevo Ticket
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-list me-2 text-muted"></i>Listado de Tickets</h6>
            <form class="d-flex gap-2" method="GET" style="max-width: 400px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control bg-light border-start-0" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="ID o título...">
                    <button class="btn btn-primary" type="submit">Buscar</button>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.8rem;">
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Asunto</th>
                            <th>Categoría</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th class="text-end pe-4">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($tickets) > 0): ?>
                            <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td class="ps-4 fw-bold">#<?php echo $ticket['local_id']; ?></td>
                                <td><?php echo htmlspecialchars($ticket['title']); ?></td>
                                <td><?php echo htmlspecialchars($ticket['category_name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $ticket['status'] == 'open' ? 'success' : 
                                            ($ticket['status'] == 'pending' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php echo t_status($ticket['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($ticket['created_at'])); ?></td>
                                <td class="text-end pe-4">
                                    <a href="ticket_details.php?id=<?php echo $ticket['id']; ?>" class="btn btn-sm btn-primary">Ver</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No tienes tickets registrados. <a href="create_ticket.php">Crea uno nuevo</a>.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($total_filtered > $limit): ?>
            <div class="card-footer bg-white py-3 border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <?php 
                    $pages = max(1, ceil($total_filtered / $limit));
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($pages, $page + 1);
                    $qParam = !empty($q) ? "&q=".urlencode($q) : "";
                    ?>
                    <small class="text-muted">Página <?php echo $page; ?> de <?php echo $pages; ?></small>
                    <div class="btn-group shadow-sm">
                        <a class="btn btn-xs btn-light border <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo $prevPage.$qParam; ?>"><i class="fas fa-chevron-left"></i></a>
                        <a class="btn btn-xs btn-light border <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?page=<?php echo $nextPage.$qParam; ?>"><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .compact-container { max-width: 1100px; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
</style>

<?php require_once 'includes/footer.php'; ?>
