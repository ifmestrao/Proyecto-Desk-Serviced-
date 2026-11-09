<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';
require_once 'includes/csrf.php';

$pageTitle = 'Exportar información';
$role = $_SESSION['role'] ?? '';
$allowed = ($role === 'admin' || $role === 'supervisor');
if (!$allowed) {
    header("Location: dashboard.php");
    exit;
}
$techs = [];
if ($role === 'admin' || $role === 'supervisor') {
    $stTechs = $pdo->prepare("SELECT id, name, email FROM users WHERE role = 'tech' AND tenant_id = ? ORDER BY name");
    $stTechs->execute([(int)($_SESSION['tenant_id'] ?? 0)]);
    $techs = $stTechs->fetchAll() ?: [];
}
?>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">

    </div>

    <div class="row g-3">
        <!-- Tickets Cerrados -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 export-card">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center mb-3">
                        <div class="icon-circle-sm bg-primary bg-opacity-10 text-primary me-3">
                            <i class="fas fa-solid fa-file-medical"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Historial de Tickets</h6>
                            <p class="text-muted extra-small mb-0">Solo solicitudes en estado Cerrado.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="POST" action="exportar_download.php" target="_blank">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="dataset" value="tickets_closed">
                            <input type="hidden" name="format" value="excel">
                            <button class="btn btn-sm btn-outline-success rounded-pill px-3"><i class="fas fa-file-excel me-2"></i>Excel</button>
                        </form>
                        <form method="POST" action="exportar_download.php" target="_blank">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="dataset" value="tickets_closed">
                            <input type="hidden" name="format" value="pdf">
                            <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="fas fa-file-pdf me-2"></i>PDF</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($role === 'supervisor' || $role === 'admin'): ?>
        <!-- Estadísticas Técnicos -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 export-card">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center mb-3">
                        <div class="fas fa-solid fa-chart-pie icon-circle-sm bg-info bg-opacity-10 text-info me-3">
                            <i class="fas fa-user-chart"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Rendimiento por Técnico</h6>
                            <p class="text-muted extra-small mb-0">KPIs: Abiertos, en espera y cerrados.</p>
                        </div>
                    </div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 mb-2">
                            <label class="form-label extra-small">Seleccionar Técnico</label>
                            <select id="tech_selector" class="form-select form-select-sm bg-light">
                                <option value="">Todos los técnicos</option>
                                <?php foreach ($techs as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <form method="POST" action="exportar_download.php" target="_blank" class="tech-form">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="dataset" value="tech_stats">
                                <input type="hidden" name="format" value="excel">
                                <input type="hidden" name="tech_id" class="tech-id-input">
                                <button class="btn btn-sm btn-outline-success rounded-pill px-3"><i class="fas fa-file-excel me-2"></i>Excel</button>
                            </form>
                            <form method="POST" action="exportar_download.php" target="_blank" class="tech-form">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="dataset" value="tech_stats">
                                <input type="hidden" name="format" value="pdf">
                                <input type="hidden" name="tech_id" class="tech-id-input">
                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="fas fa-file-pdf me-2"></i>PDF</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cronograma -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 export-card">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center mb-3">
                        <div class="icon-circle-sm bg-warning bg-opacity-10 text-warning me-3">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Actividades Completadas</h6>
                            <p class="text-muted extra-small mb-0">Resumen del cronograma finalizado.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="POST" action="exportar_download.php" target="_blank">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="dataset" value="cronograma_completed">
                            <input type="hidden" name="format" value="excel">
                            <button class="btn btn-sm btn-outline-success rounded-pill px-3"><i class="fas fa-file-excel me-2"></i>Excel</button>
                        </form>
                        <form method="POST" action="exportar_download.php" target="_blank">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="dataset" value="cronograma_completed">
                            <input type="hidden" name="format" value="pdf">
                            <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="fas fa-file-pdf me-2"></i>PDF</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventario -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 export-card">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center mb-3">
                        <div class="icon-circle-sm bg-success bg-opacity-10 text-success me-3">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Inventario de Activos</h6>
                            <p class="text-muted extra-small mb-0">Listado total de equipos y hardware.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="POST" action="exportar_download.php" target="_blank">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="dataset" value="inventario">
                            <input type="hidden" name="format" value="excel">
                            <button class="btn btn-sm btn-outline-success rounded-pill px-3"><i class="fas fa-file-excel me-2"></i>Excel</button>
                        </form>
                        <form method="POST" action="exportar_download.php" target="_blank">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="dataset" value="inventario">
                            <input type="hidden" name="format" value="pdf">
                            <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="fas fa-file-pdf me-2"></i>PDF</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const techSelector = document.getElementById('tech_selector');
    if (techSelector) {
        techSelector.addEventListener('change', function() {
            const val = this.value;
            document.querySelectorAll('.tech-id-input').forEach(input => {
                input.value = val;
            });
        });
    }
});
</script>

<style>
    .export-card { border: 1px solid rgba(0,0,0,0.05) !important; transition: all 0.2s; }
    .export-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.08) !important; }
    .icon-circle-sm { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .extra-small { font-size: 0.7rem; }
</style>

<?php require_once 'includes/footer.php'; ?>
