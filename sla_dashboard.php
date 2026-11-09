<?php
require_once 'includes/header.php';
checkAuth();
checkStaff(); // Only for staff
require_once 'config/db.php';

$pageTitle = 'Panel de SLA';

// Definir umbrales (ej: menos de 2 horas = crítico/a punto de vencer)
$threshold_hours = 4; 

// Obtener tickets abiertos con SLA
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
$sql = "SELECT t.*, u.name as user_name, c.name as category_name, tech.name as tech_name
        FROM tickets t
        JOIN users u ON t.user_id = u.id
        JOIN categories c ON t.category_id = c.id
        LEFT JOIN users tech ON t.assigned_tech_id = tech.id
        WHERE t.tenant_id = ? AND t.status != 'closed' AND t.due_date IS NOT NULL
        ORDER BY t.due_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tenant_id]);
$tickets = $stmt->fetchAll();

$sla_breached = [];
$sla_warning = [];
$sla_ok = [];

$now = new DateTime();

foreach ($tickets as $t) {
    $due = new DateTime($t['due_date']);
    $interval = $now->diff($due);
    $hours_left = ($interval->days * 24) + $interval->h + ($interval->i / 60);
    
    // Invert if it's in the past
    if ($due < $now) {
        $hours_left = -$hours_left;
    }
    
    $t['hours_left'] = $hours_left;
    
    if ($hours_left < 0) {
        $sla_breached[] = $t;
    } elseif ($hours_left <= $threshold_hours) {
        $sla_warning[] = $t;
    } else {
        $sla_ok[] = $t;
    }
}
?>

<?php require_once 'includes/navbar.php'; ?>

<style>
    .sla-card {
        border-radius: 12px;
        border: none;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        height: 100%;
    }
    .sla-header {
        border-radius: 12px 12px 0 0 !important;
        font-weight: 700;
        padding: 1rem;
    }
    .ticket-item {
        border-left: 4px solid transparent;
        transition: transform 0.2s;
    }
    .ticket-item:hover {
        transform: translateX(3px);
        background-color: #f8fafc;
    }
    .item-breached { border-left-color: #e74a3b; }
    .item-warning { border-left-color: #f6c23e; }
    .item-ok { border-left-color: #1cc88a; }
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1"><i class="text-primary"></i>Monitoreo de SLA</h3>
        </div>
        <div class="text-end">
            <span class="badge bg-danger rounded-pill fs-6"><?php echo count($sla_breached); ?> Vencidos</span>
            <span class="badge bg-warning text-dark rounded-pill fs-6 ms-2"><?php echo count($sla_warning); ?> En Riesgo</span>
            <span class="badge bg-success rounded-pill fs-6 ms-2"><?php echo count($sla_ok); ?> A Tiempo</span>
        </div>
    </div>

    <div class="row g-4">
        <!-- Vencidos -->
        <div class="col-lg-4">
            <div class="card sla-card d-flex flex-column">
                <div class="card-header sla-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-exclamation-triangle me-2"></i>Vencidos</span>
                    <span class="badge bg-white text-danger rounded-pill"><?php echo count($sla_breached); ?></span>
                </div>
                <div class="card-body p-0 flex-grow-1">
                    <div class="list-group list-group-flush sla-list" id="list-breached">
                        <?php if (empty($sla_breached)): ?>
                            <div class="p-4 text-center text-muted small">No hay tickets vencidos. ¡Buen trabajo!</div>
                        <?php endif; ?>
                        <?php foreach ($sla_breached as $t): ?>
                            <a href="ticket_details.php?id=<?php echo $t['id']; ?>" class="list-group-item list-group-item-action p-3 ticket-item item-breached sla-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <h6 class="mb-0 fw-bold text-dark">#<?php echo $t['id']; ?> - <?php echo htmlspecialchars($t['title']); ?></h6>
                                </div>
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($t['user_name']); ?> | 
                                    <i class="fas fa-tools me-1"></i><?php echo htmlspecialchars($t['tech_name'] ?: 'Sin asignar'); ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-danger">Vencido hace <?php echo round(abs($t['hours_left']), 1); ?> h</span>
                                    <span class="small text-muted"><?php echo date('d/m H:i', strtotime($t['due_date'])); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 d-flex justify-content-between align-items-center pagination-controls" data-target="list-breached" style="display: none !important;">
                    <button class="btn btn-sm btn-outline-secondary btn-prev"><i class="fas fa-chevron-left me-1"></i>Anterior</button>
                    <span class="small text-muted fw-bold page-info">1 / 1</span>
                    <button class="btn btn-sm btn-outline-secondary btn-next">Siguiente<i class="fas fa-chevron-right ms-1"></i></button>
                </div>
            </div>
        </div>

        <!-- En Riesgo -->
        <div class="col-lg-4">
            <div class="card sla-card d-flex flex-column">
                <div class="card-header sla-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-clock me-2"></i>En Riesgo (< <?php echo $threshold_hours; ?>h)</span>
                    <span class="badge bg-dark text-warning rounded-pill"><?php echo count($sla_warning); ?></span>
                </div>
                <div class="card-body p-0 flex-grow-1">
                    <div class="list-group list-group-flush sla-list" id="list-warning">
                        <?php if (empty($sla_warning)): ?>
                            <div class="p-4 text-center text-muted small">No hay tickets en riesgo inmediato.</div>
                        <?php endif; ?>
                        <?php foreach ($sla_warning as $t): ?>
                            <a href="ticket_details.php?id=<?php echo $t['id']; ?>" class="list-group-item list-group-item-action p-3 ticket-item item-warning sla-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <h6 class="mb-0 fw-bold text-dark">#<?php echo $t['id']; ?> - <?php echo htmlspecialchars($t['title']); ?></h6>
                                </div>
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($t['user_name']); ?> | 
                                    <i class="fas fa-tools me-1"></i><?php echo htmlspecialchars($t['tech_name'] ?: 'Sin asignar'); ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-warning text-dark">Quedan <?php echo round($t['hours_left'], 1); ?> h</span>
                                    <span class="small text-muted"><?php echo date('d/m H:i', strtotime($t['due_date'])); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 d-flex justify-content-between align-items-center pagination-controls" data-target="list-warning" style="display: none !important;">
                    <button class="btn btn-sm btn-outline-secondary btn-prev"><i class="fas fa-chevron-left me-1"></i>Anterior</button>
                    <span class="small text-muted fw-bold page-info">1 / 1</span>
                    <button class="btn btn-sm btn-outline-secondary btn-next">Siguiente<i class="fas fa-chevron-right ms-1"></i></button>
                </div>
            </div>
        </div>

        <!-- A Tiempo -->
        <div class="col-lg-4">
            <div class="card sla-card d-flex flex-column">
                <div class="card-header sla-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-check-circle me-2"></i>A Tiempo</span>
                    <span class="badge bg-white text-success rounded-pill"><?php echo count($sla_ok); ?></span>
                </div>
                <div class="card-body p-0 flex-grow-1">
                    <div class="list-group list-group-flush sla-list" id="list-ok">
                        <?php if (empty($sla_ok)): ?>
                            <div class="p-4 text-center text-muted small">No hay tickets dentro de tiempo seguro.</div>
                        <?php endif; ?>
                        <?php foreach ($sla_ok as $t): ?>
                            <a href="ticket_details.php?id=<?php echo $t['id']; ?>" class="list-group-item list-group-item-action p-3 ticket-item item-ok sla-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <h6 class="mb-0 fw-bold text-dark">#<?php echo $t['id']; ?> - <?php echo htmlspecialchars($t['title']); ?></h6>
                                </div>
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($t['user_name']); ?> | 
                                    <i class="fas fa-tools me-1"></i><?php echo htmlspecialchars($t['tech_name'] ?: 'Sin asignar'); ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-success bg-opacity-75">Quedan <?php echo round($t['hours_left'], 1); ?> h</span>
                                    <span class="small text-muted"><?php echo date('d/m H:i', strtotime($t['due_date'])); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 d-flex justify-content-between align-items-center pagination-controls" data-target="list-ok" style="display: none !important;">
                    <button class="btn btn-sm btn-outline-secondary btn-prev"><i class="fas fa-chevron-left me-1"></i>Anterior</button>
                    <span class="small text-muted fw-bold page-info">1 / 1</span>
                    <button class="btn btn-sm btn-outline-secondary btn-next">Siguiente<i class="fas fa-chevron-right ms-1"></i></button>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const itemsPerPage = 5;

    // Función para inicializar paginación en un contenedor específico
    function initPagination(listId) {
        const listContainer = document.getElementById(listId);
        if (!listContainer) return;
        
        const items = listContainer.querySelectorAll('.sla-item');
        if (items.length === 0) return;

        const totalPages = Math.ceil(items.length / itemsPerPage);
        
        // Encontrar controles asociados a este contenedor
        const controls = document.querySelector(`.pagination-controls[data-target="${listId}"]`);
        if (!controls) return;
        
        // Si hay más de 1 página, mostrar los controles
        if (totalPages > 1) {
            controls.style.setProperty('display', 'flex', 'important');
        } else {
            return; // No es necesario paginar
        }

        let currentPage = 1;
        const btnPrev = controls.querySelector('.btn-prev');
        const btnNext = controls.querySelector('.btn-next');
        const pageInfo = controls.querySelector('.page-info');

        function updateView() {
            // Actualizar botones
            btnPrev.disabled = currentPage === 1;
            btnNext.disabled = currentPage === totalPages;
            pageInfo.textContent = `${currentPage} / ${totalPages}`;

            // Ocultar todos, mostrar solo los de la página actual
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            
            items.forEach((item, index) => {
                if (index >= start && index < end) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        btnPrev.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                updateView();
            }
        });

        btnNext.addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                updateView();
            }
        });

        // Inicializar vista
        updateView();
    }

    // Inicializar cada lista
    initPagination('list-breached');
    initPagination('list-warning');
    initPagination('list-ok');
});
</script>

<?php require_once 'includes/footer.php'; ?>
