<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';

$stats = ['total'=>0,'open'=>0,'pending'=>0,'closed'=>0,'overdue'=>0];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : null;
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;

// --- Filtros ---
$filter_tech = isset($_GET['tech_id']) ? (int)$_GET['tech_id'] : null;
$filter_status = isset($_GET['status']) ? $_GET['status'] : null;
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;
$filter_q = isset($_GET['q']) ? trim($_GET['q']) : null;

// Si el usuario es técnico y no es admin/supervisor, solo puede ver sus propias estadísticas
if ($role === 'tech' && !($role === 'admin' || $role === 'supervisor')) {
    $filter_tech = $uid;
}

$where_clauses = ["t.tenant_id = ?"];
$params = [$tenant_id];

if ($filter_tech) {
    $where_clauses[] = "(t.assigned_tech_id = ? OR EXISTS (SELECT 1 FROM ticket_techs tt WHERE tt.ticket_id = t.id AND tt.tech_id = ? AND tt.active = 1))";
    $params[] = $filter_tech;
    $params[] = $filter_tech;
}

if ($filter_status) {
    $where_clauses[] = "t.status = ?";
    $params[] = $filter_status;
}

if ($filter_date_from) {
    $where_clauses[] = "t.created_at >= ?";
    $params[] = $filter_date_from . ' 00:00:00';
}

if ($filter_date_to) {
    $where_clauses[] = "t.created_at <= ?";
    $params[] = $filter_date_to . ' 23:59:59';
}

if ($filter_q) {
    $where_clauses[] = "(t.title LIKE ? OR t.local_id = ?)";
    $params[] = "%$filter_q%";
    $params[] = (int)$filter_q;
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Obtener técnicos para el filtro (solo admins/supervisores)
$techs = [];
if ($role === 'admin' || $role === 'supervisor') {
    $stmt_techs = $pdo->prepare("SELECT id, name FROM users WHERE tenant_id = ? AND role IN ('tech', 'supervisor', 'admin') ORDER BY name");
    $stmt_techs->execute([$tenant_id]);
    $techs = $stmt_techs->fetchAll() ?: [];
}

// 1. KPIs principales
$all_statuses = [];
$st_kpi = $pdo->prepare("SELECT COALESCE(t.status, 'open') as status, COUNT(*) c FROM tickets t $where_sql GROUP BY COALESCE(t.status, 'open')");
$st_kpi->execute($params);
while ($r = $st_kpi->fetch()) {
    $s = strtolower(trim($r['status']));
    $all_statuses[$s] = (int)$r['c'];
    $stats['total'] += (int)$r['c'];
    
    if ($s === 'open') {
        $stats['open'] += (int)$r['c'];
    } elseif (in_array($s, ['in_progress', 'resolved', 'pending', 'en espera'])) {
        $stats['pending'] += (int)$r['c'];
    } elseif ($s === 'closed' || $s === 'cerrado') {
        $stats['closed'] += (int)$r['c'];
    } else {
        $stats['pending'] += (int)$r['c'];
    }
}

// 2. Tickets vencidos con filtros
$where_overdue = $where_sql . " AND t.due_date < NOW() AND COALESCE(t.status, 'open') NOT IN ('closed', 'cerrado')";
$st_overdue = $pdo->prepare("SELECT COUNT(*) FROM tickets t $where_overdue");
$st_overdue->execute($params);
$stats['overdue'] = (int)$st_overdue->fetchColumn();

// 3. Categorías
$cats = $pdo->prepare("SELECT id, name FROM categories WHERE tenant_id = ? ORDER BY name");
$cats->execute([$tenant_id]);
$all_categories = $cats->fetchAll();

// 4. Datos para tendencia mensual (últimos 6 meses)
$monthly_labels = [];
$monthly_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthly_labels[$month] = $month;
    $monthly_data[$month] = 0;
}

$st_trend = $pdo->prepare("SELECT DATE_FORMAT(t.created_at, '%Y-%m') as month, COUNT(*) as cnt FROM tickets t $where_sql AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month ASC");
$st_trend->execute($params);
while ($r = $st_trend->fetch()) {
    if (isset($monthly_data[$r['month']])) {
        $monthly_data[$r['month']] = (int)$r['cnt'];
    }
}

$monthly_labels = array_values($monthly_labels);
$monthly_data = array_values($monthly_data);

// 5. Datos para categorías histórico (Mountain Chart)
$cat_datasets = [];
$base_colors = [
    ['h' => 220, 's' => 70, 'l' => 60], ['h' => 0, 's' => 70, 'l' => 60], ['h' => 160, 's' => 70, 'l' => 45],
    ['h' => 35, 's' => 85, 'l' => 50], ['h' => 270, 's' => 60, 'l' => 60], ['h' => 330, 's' => 70, 'l' => 60],
    ['h' => 190, 's' => 80, 'l' => 45], ['h' => 80, 's' => 60, 'l' => 45], ['h' => 20, 's' => 85, 'l' => 55],
    ['h' => 290, 's' => 50, 'l' => 50],
];

$color_idx = 0;
foreach ($all_categories as $cat) {
    $data_points = array_fill_keys($monthly_labels, 0);
    $st_cat_hist = $pdo->prepare("SELECT DATE_FORMAT(t.created_at, '%Y-%m') as month, COUNT(*) as cnt FROM tickets t $where_sql AND t.category_id = ? AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY month");
    $cat_params = array_merge($params, [$cat['id']]);
    $st_cat_hist->execute($cat_params);
    while ($r = $st_cat_hist->fetch()) {
        if (isset($data_points[$r['month']])) {
            $data_points[$r['month']] = (int)$r['cnt'];
        }
    }

    $c = $base_colors[$color_idx % count($base_colors)];
    $h = ($color_idx < count($base_colors)) ? $c['h'] : ($color_idx * 137) % 360;
    $s = ($color_idx < count($base_colors)) ? $c['s'] : 60 + ($color_idx % 3) * 10;
    $l = ($color_idx < count($base_colors)) ? $c['l'] : 50 + ($color_idx % 2) * 10;

    $cat_datasets[] = [
        'label' => $cat['name'],
        'data' => array_values($data_points),
        'borderColor' => "hsl($h, $s%, $l%)",
        'backgroundColor' => "hsla($h, $s%, $l%, 0.2)",
        'fill' => true, 'tension' => 0.4, 'pointRadius' => 4, 'pointBackgroundColor' => '#fff', 'borderWidth' => 2
    ];
    $color_idx++;
}
?>

<?php $pageTitle = 'Estadísticas'; require_once 'includes/navbar.php'; ?>

<div class="container compact-container px-3" style="margin-bottom: 50px;">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-light border shadow-sm px-3" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="false">
                <i class="fas fa-filter me-2 text-primary"></i>Filtros
            </button>
            <?php if ($role === 'admin' || $role === 'supervisor'): ?>
                <a href="exportar.php" class="btn btn-sm btn-light border shadow-sm px-3"><i class="fas fa-file-export me-2 text-success"></i>Exportar</a>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm shadow-sm px-3" onclick="location.reload()"><i class="fas fa-sync-alt me-2"></i>Refrescar</button>
        </div>
    </div>

    <!-- Filtros Colapsables -->
    <div class="collapse <?php echo ($filter_tech || $filter_status || $filter_date_from || $filter_date_to) ? 'show' : ''; ?>" id="filterPanel">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" class="row g-2 align-items-end">
                    <?php if ($role === 'admin' || $role === 'supervisor'): ?>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Técnico</label>
                        <select name="tech_id" class="form-select form-select-sm">
                            <option value="">Todos los técnicos</option>
                            <?php foreach ($techs as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo $filter_tech == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="<?php echo ($role === 'admin' || $role === 'supervisor') ? 'col-md-2' : 'col-md-3'; ?>">
                        <label class="form-label small fw-bold">Estado</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Todos los estados</option>
                            <option value="open" <?php echo $filter_status === 'open' ? 'selected' : ''; ?>>Abierto</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>En Espera</option>
                            <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>En Proceso</option>
                            <option value="resolved" <?php echo $filter_status === 'resolved' ? 'selected' : ''; ?>>Resuelto</option>
                            <option value="closed" <?php echo $filter_status === 'closed' ? 'selected' : ''; ?>>Cerrado</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Desde</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date_from ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Hasta</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date_to ?? ''); ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i> Filtrar</button>
                        <a href="estadisticas.php" class="btn btn-light border btn-sm w-100"><i class="fas fa-times me-1"></i> Limpiar</a>
                    </div>
                </form>
            </div>
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
                    <h6 class="text-muted small fw-bold mb-1">Tickets Generados</h6>
                    <h3 class="mb-0 fw-bold" id="kpi-total"><?php echo $stats['total']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;">
                    <div class="progress-bar bg-primary" style="width: 100%"></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 kpi-card overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="icon-circle-sm bg-success bg-opacity-10 text-success">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success" style="font-size: 0.6rem;">ACTIVOS</span>
                    </div>
                    <h6 class="text-muted small fw-bold mb-1">Abiertos</h6>
                    <h3 class="mb-0 fw-bold" id="kpi-open"><?php echo $stats['open']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $stats['total'] > 0 ? ($stats['open']/$stats['total'])*100 : 0; ?>%"></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 kpi-card overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="icon-circle-sm bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <span class="badge rounded-pill bg-warning bg-opacity-10 text-warning" style="font-size: 0.6rem;">EN ESPERA</span>
                    </div>
                    <h6 class="text-muted small fw-bold mb-1">Pendientes</h6>
                    <h3 class="mb-0 fw-bold" id="kpi-pending"><?php echo $stats['pending']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;">
                    <div class="progress-bar bg-warning" style="width: <?php echo $stats['total'] > 0 ? ($stats['pending']/$stats['total'])*100 : 0; ?>%"></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 kpi-card overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="icon-circle-sm bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger" style="font-size: 0.6rem;">SLA BREACH</span>
                    </div>
                    <h6 class="text-muted small fw-bold mb-1">Vencidos</h6>
                    <h3 class="mb-0 fw-bold" id="kpi-overdue"><?php echo $stats['overdue']; ?></h3>
                </div>
                <div class="progress" style="height: 3px;">
                    <div class="progress-bar bg-danger" style="width: <?php echo $stats['total'] > 0 ? ($stats['overdue']/$stats['total'])*100 : 0; ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="m-0 fw-bold text-dark"><i class="me-2 text-primary"></i>Casos por estado</h6>
                </div>
                <div class="card-body p-3">
                    <div class="chart-container" style="height: 250px;"><canvas id="statusBar"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="m-0 fw-bold text-dark"><i class="me-2 text-success"></i>Estados actuales</h6>
                </div>
                <div class="card-body p-3">
                    <div class="chart-container" style="height: 250px;"><canvas id="statusPie"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="m-0 fw-bold text-dark"><i class="me-2 text-info"></i>Tendencia mensual de casos</h6>
                </div>
                <div class="card-body p-3">
                    <div class="chart-container" style="height: 250px;"><canvas id="lineChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 chart-card">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-dark"><i class="me-2 text-warning"></i>Casos por categoría</h6>
                </div>
                <div class="card-body p-3">
                    <div class="chart-fixed"><canvas id="mountainChart"></canvas></div>
                    <div class="loading-overlay active" id="load-mountain"><div class="spinner-border text-primary" role="status"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .kpi-card { transition: transform 0.2s; border: 1px solid rgba(0,0,0,0.05) !important; }
    .kpi-card:hover { transform: translateY(-3px); }
    .icon-circle-sm { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .bg-purple { background-color: #8b5cf6 !important; }
    .text-purple { color: #8b5cf6 !important; }
    .chart-container { position: relative; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
var statusColors = ['#d946ef','#22c55e','#f59e0b','#0ea5e9','#ef4444'];
var statusBar = new Chart(document.getElementById('statusBar').getContext('2d'), {
    type: 'bar',
    data: { 
        labels: ['Total','Abiertos','En espera','Cerrados','Vencidos'], 
        datasets: [{
            label: 'Casos',
            data: [<?php echo (int)$stats['total'];?>,<?php echo (int)$stats['open'];?>,<?php echo (int)$stats['pending'];?>,<?php echo (int)$stats['closed'];?>,<?php echo (int)$stats['overdue'];?>],
            backgroundColor: statusColors,
            borderColor: statusColors,
            borderWidth: 1,
            borderSkipped: false,
            maxBarThickness: 46
        }] 
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        animation:{duration:250},
        scales: { 
            x: { stacked: false, grid: { color: '#eef2f7' } },
            y: { 
                beginAtZero: true, 
                stacked: false, 
                grid: { color: '#eef2f7' }, 
                suggestedMax: Math.max(<?php echo (int)$stats['total'];?>,<?php echo (int)$stats['open'];?>,<?php echo (int)$stats['pending'];?>,<?php echo (int)$stats['closed'];?>,<?php echo (int)$stats['overdue'];?>) + 2,
                ticks: {
                    maxTicksLimit: 5,
                    callback: function(value) {
                        if (value % 1 === 0) return value;
                    }
                }
            } 
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(ctx){
                        var v = Array.isArray(ctx.dataset.data) ? ctx.dataset.data[ctx.dataIndex] : (ctx.parsed.y || 0);
                        return ctx.label+': '+ v;
                    }
                }
            },
            legend: { display: false }
        }
    }
});
var _initMax = Math.max.apply(null, statusBar.data.datasets[0].data || [0]);
statusBar.options.scales.y.suggestedMax = Math.max(_initMax + 1, 3);
statusBar.update();
document.getElementById('load-mountain').classList.remove('active');

var statusPie = new Chart(document.getElementById('statusPie').getContext('2d'), {
    type: 'doughnut',
    data: { 
        labels: ['Abiertos','En espera','Cerrados','Vencidos'], 
        datasets: [{ 
            data: [<?php echo (int)$stats['open'];?>,<?php echo (int)$stats['pending'];?>,<?php echo (int)$stats['closed'];?>,<?php echo (int)$stats['overdue'];?>], 
            backgroundColor: ['#22c55e','#f59e0b','#0ea5e9','#ef4444'] 
        }] 
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        animation:{duration:250}, 
        plugins:{
            legend:{position:'bottom'},
            tooltip:{
                callbacks:{
                    label: function(ctx){
                        var total = ctx.dataset.data.reduce((a,b)=>a+b,0) || 1;
                        var v = ctx.parsed;
                        var pct = Math.round((v/total)*100);
                        return ctx.label+': '+v+' ('+pct+'%)';
                    }
                }
            }
        } 
    }
});

var lineChart = new Chart(document.getElementById('lineChart').getContext('2d'), {
    type: 'line',
    data: { 
        labels: <?php echo json_encode($monthly_labels); ?>, 
        datasets: [{ 
            label: 'Casos por mes', 
            data: <?php echo json_encode($monthly_data); ?>, 
            borderColor: '#3b82f6', 
            backgroundColor: '#3b82f622', 
            fill: true, 
            tension: 0.4, 
            pointRadius: 5, 
            pointHoverRadius: 8 
        }] 
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        plugins: { legend: { display: false } },
        scales: { 
            y: { 
                beginAtZero: true, 
                ticks: { 
                    maxTicksLimit: 5,
                    callback: function(v) { return v % 1 === 0 ? v : ''; }
                } 
            } 
        }
    }
});

var mountainChart = new Chart(document.getElementById('mountainChart').getContext('2d'), {
    type: 'line',
    data: { 
        labels: <?php echo json_encode($monthly_labels); ?>, 
        datasets: <?php echo json_encode($cat_datasets); ?>
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        animation: { duration: 250 },
        plugins: { 
            legend: { 
                display: true,
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    font: { size: 10 }
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false,
            }
        },
        scales: { 
            x: { 
                grid: { display: false },
                ticks: { font: { size: 10 } }
            },
            y: { 
                beginAtZero: true, 
                grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false }, 
                ticks: {
                    maxTicksLimit: 5,
                    font: { size: 10 },
                    callback: function(value) {
                        if (value % 1 === 0) return value;
                    }
                }
            } 
        }
    }
});


</script>

<?php require_once 'includes/footer.php'; ?>