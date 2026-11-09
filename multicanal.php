<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';

$pageTitle = 'Centro Multicanal';
$tenant_id = get_tenant_id();

// Asegurar que la columna source existe en tickets
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM `tickets` LIKE 'source'");
    if (!$checkColumn->fetch()) {
        $pdo->exec("ALTER TABLE `tickets` ADD COLUMN source ENUM('web', 'email', 'whatsapp', 'telegram', 'chatbot') DEFAULT 'web' AFTER tenant_id");
    }
} catch (Throwable $e) {}

// Estadísticas rápidas por canal
$stats = [
    'whatsapp' => 0,
    'email' => 0,
    'telegram' => 0,
    'chatbot' => 0
];

try {
    $st = $pdo->prepare("SELECT source, COUNT(*) as c FROM tickets WHERE tenant_id = ? AND status IN ('open', 'in_progress', 'pending') GROUP BY source");
    $st->execute([$tenant_id]);
    while ($r = $st->fetch()) {
        if (isset($stats[$r['source']])) $stats[$r['source']] = $r['c'];
    }
    
    // Sumar chats de Telegram en espera al contador de Telegram
    $stChat = $pdo->prepare("SELECT COUNT(*) FROM telegram_sessions WHERE tenant_id = ? AND state = 'CHAT_SUPPORT'");
    $stChat->execute([$tenant_id]);
    $stats['telegram'] += (int)$stChat->fetchColumn();
    
} catch (Throwable $e) {}

// Paginación y Filtros
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_source = isset($_GET['source_filter']) ? $_GET['source_filter'] : '';
$offset = ($page - 1) * $limit;

// --- LÓGICA DE FILTROS PARA EL UNION ---

// 1. Filtros para TICKETS
$t_cond = " AND t.tenant_id = ? AND t.source IN ('telegram', 'chatbot', 'whatsapp', 'email') AND t.status != 'closed'";
$t_params = [$tenant_id];
if (!empty($search)) {
    $t_cond .= " AND (u.name LIKE ? OR t.title LIKE ? OR t.local_id = ?)";
    $t_params[] = "%$search%";
    $t_params[] = "%$search%";
    $t_params[] = (int)$search;
}
if (!empty($filter_source)) {
    $t_cond .= " AND t.source = ?";
    $t_params[] = $filter_source;
}

// 2. Filtros para CHATS ACTIVOS (Telegram y WhatsApp)
$ts_cond = " AND ts.tenant_id = ? AND ts.state = 'CHAT_SUPPORT'";
$ts_params = [$tenant_id];
if (!empty($search)) {
    $ts_cond .= " AND (u.name LIKE ? OR ts.chat_id LIKE ?)";
    $ts_params[] = "%$search%";
    $ts_params[] = "%$search%";
}
// Si hay filtro de canal, aplicar a la fuente detectada
if (!empty($filter_source)) {
    if ($filter_source === 'telegram' || $filter_source === 'whatsapp') {
        $ts_cond .= " AND ts.source = ?";
        $ts_params[] = $filter_source;
    } else {
        $ts_cond .= " AND 1=0";
    }
}

// 3. Filtros para EMAIL REQUESTS
$er_cond = " AND er.tenant_id = ? AND er.status = 'pending'";
$er_params = [$tenant_id];
if (!empty($search)) {
    $er_cond .= " AND (er.from_name LIKE ? OR er.from_email LIKE ? OR er.subject LIKE ?)";
    $er_params[] = "%$search%";
    $er_params[] = "%$search%";
    $er_params[] = "%$search%";
}
// Si hay filtro de canal y no es email, esta parte no debe traer nada
if (!empty($filter_source) && $filter_source !== 'email') {
    $er_cond .= " AND 1=0";
}

// --- CONTAR TOTAL PARA PAGINACIÓN ---
$total_items = 0;
try {
    $countQuery = "
        SELECT SUM(total) as total FROM (
            SELECT COUNT(*) as total FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE 1=1 $t_cond
            UNION ALL
            SELECT COUNT(*) as total FROM telegram_sessions ts LEFT JOIN users u ON ts.chat_id = u.telegram_id AND u.tenant_id = ts.tenant_id WHERE 1=1 $ts_cond
            UNION ALL
            SELECT COUNT(*) as total FROM email_requests er WHERE 1=1 $er_cond
        ) as combined";
    
    $c_params = array_merge($t_params, $ts_params, $er_params);
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($c_params);
    $total_items = (int)$countStmt->fetchColumn();
} catch (Throwable $e) { error_log($e->getMessage()); }
$total_pages = ceil($total_items / $limit);

// --- OBTENER RESULTADOS PAGINADOS ---
$recent = [];
try {
    $mainQuery = "
        (SELECT CAST('ticket' AS CHAR) as type, CAST(t.source AS CHAR) as source, t.id as ref_id, CAST(u.name AS CHAR) as user_name, CAST(t.title AS CHAR) as content, t.updated_at as date, CAST(t.status AS CHAR) as status 
         FROM tickets t 
         LEFT JOIN users u ON t.user_id = u.id 
         WHERE 1=1 $t_cond)
        UNION ALL
        (SELECT 
            CAST('chat' AS CHAR) as type, 
            CAST(ts.source AS CHAR) as source, 
            ts.chat_id as ref_id, 
            CAST(COALESCE(u.name, IF(ts.source = 'whatsapp', 'Usuario WhatsApp', 'Usuario Telegram')) AS CHAR) as user_name, 
            CAST('Esperando agente de soporte...' AS CHAR) as content, 
            ts.updated_at as date, 
            CAST('warning' AS CHAR) as status
         FROM telegram_sessions ts
         LEFT JOIN users u ON ts.chat_id = u.telegram_id AND u.tenant_id = ts.tenant_id
         WHERE 1=1 $ts_cond)
        UNION ALL
        (SELECT CAST('email_request' AS CHAR) as type, CAST('email' AS CHAR) as source, er.id as ref_id, CAST(COALESCE(er.from_name, er.from_email) AS CHAR) as user_name, CAST(CONCAT(er.subject, ' | ', LEFT(er.body, 100), '...') AS CHAR) as content, er.created_at as date, CAST('pending_review' AS CHAR) as status
         FROM email_requests er
         WHERE 1=1 $er_cond)
        ORDER BY date DESC LIMIT $limit OFFSET $offset";
    
    $m_params = array_merge($t_params, $ts_params, $er_params);
    $stmt = $pdo->prepare($mainQuery);
    $stmt->execute($m_params);
    $recent = $stmt->fetchAll();
} catch (Throwable $e) { error_log($e->getMessage()); }


// Obtener estado real de integraciones
$channels = [
    'whatsapp' => false,
    'email' => false,
    'telegram' => false,
    'chatbot' => true // El chatbot interno suele estar activo por defecto o manejado aparte
];

$stmt = $pdo->prepare("SELECT provider, is_active FROM integrations WHERE tenant_id = ? AND is_active = 1");
$stmt->execute([$tenant_id]);
while ($row = $stmt->fetch()) {
    if ($row['provider'] === 'whatsapp') $channels['whatsapp'] = true;
    if ($row['provider'] === 'email_imap') $channels['email'] = true;
    if ($row['provider'] === 'telegram') $channels['telegram'] = true;
}

// Obtener logs recientes de Telegram para la tabla de interacciones
$telegram_logs = [];
try {
    $st = $pdo->prepare("SELECT tl.*, COALESCE(u.name, 'Usuario Telegram') as user_name, u.avatar 
                         FROM telegram_logs tl
                         LEFT JOIN users u ON tl.chat_id = u.telegram_id AND u.tenant_id = tl.tenant_id
                         WHERE tl.tenant_id = ? AND tl.direction = 'in'
                         ORDER BY tl.created_at DESC LIMIT 5");
    $st->execute([$tenant_id]);
    $telegram_logs = $st->fetchAll();
} catch (Throwable $e) {}

require_once 'includes/navbar.php';
?>

<div id="app" class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center my-4">

        <div class="d-flex gap-2">
            <button class="btn btn-light btn-sm border shadow-sm px-3" onclick="location.reload()">
                <i class="fas fa-sync-alt me-2 text-primary"></i>Actualizar
            </button>
            <a href="integraciones.php" class="btn btn-primary btn-sm shadow-sm px-3">
                <i class="fas fa-cog me-2"></i>Configurar Canales
            </a>
        </div>
    </div>

    <!-- Canal Status Cards -->
    <div class="row g-3 mb-4">
        <!-- WhatsApp -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 channel-card-v2">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="icon-circle bg-success bg-opacity-10 text-success">
                            <i class="fab fa-whatsapp"></i>
                        </div>
                        <?php if ($channels['whatsapp']): ?>
                            <span class="badge rounded-pill bg-success-soft text-success border border-success border-opacity-25">Conectado</span>
                        <?php else: ?>
                            <span class="badge rounded-pill bg-light text-muted border">Inactivo</span>
                        <?php endif; ?>
                    </div>
                    <h6 class="fw-bold mb-1">WhatsApp Business</h6>
                    <p class="text-muted extra-small mb-3">API oficial de Meta.</p>
                    <div class="d-flex justify-content-between align-items-end">
                        <div>
                            <span class="text-muted small d-block">Pendientes</span>
                            <h4 class="fw-bold mb-0"><?php echo $stats['whatsapp']; ?></h4>
                        </div>
                        <a href="dashboard.php?source=whatsapp" class="btn btn-link btn-sm p-0 text-decoration-none">Ver más <i class="fas fa-chevron-right ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 channel-card-v2">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="icon-circle bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <?php if ($channels['email']): ?>
                            <span class="badge rounded-pill bg-primary-soft text-primary border border-primary border-opacity-25">Conectado</span>
                        <?php else: ?>
                            <span class="badge rounded-pill bg-light text-muted border">Inactivo</span>
                        <?php endif; ?>
                    </div>
                    <h6 class="fw-bold mb-1">Email Ticketing</h6>
                    <p class="text-muted extra-small mb-3">Sincronización vía IMAP/SMTP.</p>
                    <div class="d-flex justify-content-between align-items-end">
                        <div>
                            <span class="text-muted small d-block">Pendientes</span>
                            <h4 class="fw-bold mb-0"><?php echo $stats['email']; ?></h4>
                        </div>
                        <a href="dashboard.php?source=email" class="btn btn-link btn-sm p-0 text-decoration-none">Ver más <i class="fas fa-chevron-right ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Telegram -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 channel-card-v2">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="icon-circle bg-info bg-opacity-10 text-info">
                            <i class="fab fa-telegram"></i>
                        </div>
                        <?php if ($channels['telegram']): ?>
                            <span class="badge rounded-pill bg-info-soft text-info border border-info border-opacity-25">Conectado</span>
                        <?php else: ?>
                            <span class="badge rounded-pill bg-light text-muted border">Inactivo</span>
                        <?php endif; ?>
                    </div>
                    <h6 class="fw-bold mb-1">Telegram Bot</h6>
                    <p class="text-muted extra-small mb-3">Atención híbrida</p>
                    <div class="d-flex justify-content-between align-items-end">
                        <div>
                            <span class="text-muted small d-block">Pendientes</span>
                            <h4 class="fw-bold mb-0"><?php echo $stats['telegram']; ?></h4>
                        </div>
                        <a href="dashboard.php?source=telegram" class="btn btn-link btn-sm p-0 text-decoration-none">Ver más <i class="fas fa-chevron-right ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Chatbot -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 channel-card-v2">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="icon-circle bg-purple bg-opacity-10 text-purple">
                            <i class="fab fa-solid fa-robot fa-2xs" style="color: rgb(99, 230, 190);"></i>
                        </div>
                        <span class="badge rounded-pill bg-purple-soft text-purple border border-purple border-opacity-25">Activo</span>
                    </div>
                    <h6 class="fw-bold mb-1">AI Agent</h6>
                    <p class="text-muted extra-small mb-3">Inteligencia artificial y KB.</p>
                    <div class="d-flex justify-content-between align-items-end">
                        <div>
                            <span class="text-muted small d-block">Atendidos</span>
                            <h4 class="fw-bold mb-0"><?php echo $stats['chatbot']; ?></h4>
                        </div>
                        <a href="knowledge_base.php" class="btn btn-link btn-sm p-0 text-decoration-none">Ver más <i class="fas fa-chevron-right ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Interactions Table -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0"><i class="fas fa-history me-2 text-muted"></i>Interacciones Recientes</h6>
            <form method="GET" class="d-flex gap-2">
                <select name="source_filter" class="form-select form-select-sm bg-light" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">Todos los canales</option>
                    <option value="whatsapp" <?php echo $filter_source === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
                    <option value="telegram" <?php echo $filter_source === 'telegram' ? 'selected' : ''; ?>>Telegram</option>
                    <option value="email" <?php echo $filter_source === 'email' ? 'selected' : ''; ?>>Email</option>
                    <option value="chatbot" <?php echo $filter_source === 'chatbot' ? 'selected' : ''; ?>>Chatbot</option>
                </select>
                <div class="input-group input-group-sm" style="width: 250px;">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control bg-light border-start-0" placeholder="Buscar por nombre o asunto..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4 py-3">Canal</th>
                            <th>Remitente</th>
                            <th>Asunto / Mensaje</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($recent)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <img src="assets/img/empty-state.svg" alt="" style="width: 80px; opacity: 0.5;" class="mb-3 d-block mx-auto">
                                <p class="text-muted">No hay interacciones externas para mostrar.</p>
                            </td>
                        </tr>
                        <?php else: foreach ($recent as $t): ?>
                        <tr>
                            <td class="ps-4">
                                <?php if ($t['source'] === 'whatsapp'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success p-2 rounded-circle"><i class="fab fa-whatsapp"></i></span>
                                <?php elseif ($t['source'] === 'email'): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary p-2 rounded-circle"><i class="fas fa-envelope"></i></span>
                                <?php elseif ($t['source'] === 'telegram'): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info p-2 rounded-circle"><i class="fab fa-telegram"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-purple bg-opacity-10 text-purple p-2 rounded-circle"><i class="fab fa-solid fa-robot fa-2xs" style="color: rgb(99, 230, 190);"></i></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-xs me-2 bg-light rounded-circle border d-flex align-items-center justify-content-center">
                                        <i class="fas fa-user text-muted" style="font-size: 0.7rem;"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($t['user_name']); ?></div>
                                        <div class="text-muted" style="font-size: 0.7rem;">
                                            <?php 
                                            if ($t['source'] === 'telegram') echo 'Reporte Telegram';
                                            elseif ($t['source'] === 'chatbot') echo 'Reporte Chatbot';
                                            else echo ucfirst($t['source']);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 300px;">
                                    <?php echo htmlspecialchars($t['content']); ?>
                                </div>
                            </td>
                            <td class="text-muted"><?php echo (new DateTime($t['date']))->format('d/m/Y H:i'); ?></td>
                            <td>
                                <?php
                                if ($t['type'] === 'chat') {
                                    echo '<span class="badge bg-warning text-dark">Chat en Vivo</span>';
                                } elseif ($t['type'] === 'email_request') {
                                    echo '<span class="badge bg-info text-white">Revisión Pendiente</span>';
                                } else {
                                    $badgeClass = 'bg-secondary';
                                    if ($t['status'] === 'open') $badgeClass = 'bg-success';
                                    if ($t['status'] === 'in_progress') $badgeClass = 'bg-warning';
                                    echo '<span class="badge '.$badgeClass.'">'.ucfirst($t['status']).'</span>';
                                }
                                ?>
                            </td>
                            <td class="text-end pe-4">
                                <?php if ($t['type'] === 'ticket'): ?>
                                    <a href="ticket_details.php?id=<?php echo $t['ref_id']; ?>" class="btn btn-xs btn-outline-primary border-0" title="Ver Ticket"><i class="fas fa-eye"></i></a>
                                <?php elseif ($t['type'] === 'email_request'): ?>
                                    <div class="btn-group">
                                        <button onclick="viewEmailBody(<?php echo $t['ref_id']; ?>)" class="btn btn-xs btn-outline-info" title="Ver Contenido"><i class="fas fa-eye"></i></button>
                                        <button onclick="viewAiDraft(<?php echo $t['ref_id']; ?>)" class="btn btn-xs btn-outline-primary" title="Respuesta con IA"><i class="fas fa-robot"></i></button>
                                        <button onclick="approveEmail(<?php echo $t['ref_id']; ?>)" class="btn btn-xs btn-success" title="Aprobar Ticket"><i class="fas fa-check"></i></button>
                                        <button onclick="rejectEmail(<?php echo $t['ref_id']; ?>)" class="btn btn-xs btn-danger" title="Rechazar"><i class="fas fa-times"></i></button>
                                    </div>
                                <?php else: ?>
                                    <a href="chat_support.php?chat_id=<?php echo $t['ref_id']; ?>" class="btn btn-xs btn-outline-primary rounded-pill px-3">Atender</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Paginación -->
        <?php if ($total_pages > 1): 
            $query_params = $_GET;
        ?>
        <div class="card-footer bg-white border-0 py-3">
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <?php 
                        $query_params['page'] = $page - 1;
                        $prev_url = '?' . http_build_query($query_params);
                        ?>
                        <a class="page-link" href="<?php echo $prev_url; ?>"><i class="fas fa-chevron-left"></i></a>
                    </li>
                    <?php for($i = 1; $i <= $total_pages; $i++): 
                        $query_params['page'] = $i;
                        $page_url = '?' . http_build_query($query_params);
                    ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $page_url; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <?php 
                        $query_params['page'] = $page + 1;
                        $next_url = '?' . http_build_query($query_params);
                        ?>
                        <a class="page-link" href="<?php echo $next_url; ?>"><i class="fas fa-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    :root {
        --bs-purple: #8b5cf6;
    }
    .bg-purple { background-color: var(--bs-purple) !important; }
    .text-purple { color: var(--bs-purple) !important; }
    .bg-purple-soft { background-color: rgba(139, 92, 246, 0.1) !important; }
    .bg-success-soft { background-color: rgba(25, 135, 84, 0.1) !important; }
    .bg-primary-soft { background-color: rgba(13, 110, 253, 0.1) !important; }
    .bg-info-soft { background-color: rgba(13, 202, 240, 0.1) !important; }
    
    .channel-card-v2 { 
        transition: all 0.2s ease-in-out; 
        border: 1px solid rgba(0,0,0,0.05) !important;
    }
    .channel-card-v2:hover { 
        transform: translateY(-3px);
        box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.08) !important;
    }
    .icon-circle {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    .extra-small { font-size: 0.75rem; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
    .avatar-xs { width: 28px; height: 28px; }
    
    /* Scrollbar minimalista */
    .table-responsive::-webkit-scrollbar { height: 4px; }
    .table-responsive::-webkit-scrollbar-track { background: #f1f1f1; }
    .table-responsive::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
</style>

<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script>
    // Convierte el cuerpo de un correo (HTML o texto con CSS) en texto legible
    function cleanEmailBody(raw) {
        let t = String(raw);
        // Si parece HTML, pasarlo por el parser del navegador (no ejecuta scripts)
        if (/<\/?(html|body|div|p|br|span|table|style|head)\b/i.test(t)) {
            const doc = new DOMParser().parseFromString(t, 'text/html');
            doc.querySelectorAll('style, script, head, title').forEach(n => n.remove());
            doc.querySelectorAll('br').forEach(n => n.replaceWith('\n'));
            doc.querySelectorAll('p, div, tr, li, h1, h2, h3, h4, h5, h6').forEach(n => n.append('\n'));
            t = doc.body ? doc.body.textContent : '';
        }
        // Quitar bloques CSS sueltos que llegan como texto (@font-face, @media, etc.)
        t = t.replace(/@(font-face|media|import|keyframes|page)[^{]*\{(?:[^{}]*\{[^{}]*\}|[^{}])*\}/gi, '');
        // Quitar reglas CSS sueltas tipo "selector { prop: valor; }"
        t = t.replace(/(^|\n)[^\n{}]{0,120}\{[^{}]*:[^{}]*\}/g, '$1');
        t = t.replace(/\u00a0/g, ' ').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n');
        return t.trim();
    }

    async function viewEmailBody(id) {
        const modalEl = document.getElementById('emailBodyModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        document.getElementById('emailBodySubject').textContent = 'Cargando...';
        document.getElementById('emailBodyFrom').textContent = '';
        document.getElementById('emailBodyDate').textContent = '';
        document.getElementById('emailBodyMain').textContent = '';
        document.getElementById('emailBodyQuoted').textContent = '';
        document.getElementById('emailBodyQuotedWrap').classList.add('d-none');
        modal.show();

        try {
            const response = await fetch(`email_review_actions.php?action=get_body&id=${id}`);
            const res = await response.json();
            if (res.success) {
                document.getElementById('emailBodySubject').textContent = res.subject || '(sin asunto)';
                document.getElementById('emailBodyFrom').textContent = res.from_name ? `${res.from_name} <${res.from_email}>` : (res.from_email || '');
                document.getElementById('emailBodyDate').textContent = res.date || '';

                // Limpiar HTML/CSS del correo y separar el mensaje nuevo del hilo citado
                const raw = cleanEmailBody(res.body || '');
                const splitRegex = /\n\s*(El\s.+?escribi[oó]:|On\s.+?wrote:)\s*\n/i;
                const parts = raw.split(splitRegex);
                const mainText = parts[0].trim();
                const quotedText = raw.slice(parts[0].length).trim();

                document.getElementById('emailBodyMain').textContent = mainText || '(sin contenido)';
                if (quotedText) {
                    document.getElementById('emailBodyQuoted').textContent = quotedText;
                    document.getElementById('emailBodyQuotedWrap').classList.remove('d-none');
                }
            } else {
                document.getElementById('emailBodySubject').textContent = 'Error';
                document.getElementById('emailBodyMain').textContent = res.error || 'No se pudo cargar el correo.';
            }
        } catch (e) {
            document.getElementById('emailBodySubject').textContent = 'Error';
            document.getElementById('emailBodyMain').textContent = 'Error de conexión al cargar el correo.';
        }
    }

    function toggleQuotedThread() {
        const box = document.getElementById('emailBodyQuoted');
        const label = document.getElementById('emailBodyQuotedLabel');
        const collapsed = box.classList.toggle('d-none');
        label.textContent = collapsed ? 'Ver conversación anterior ▾' : 'Ocultar conversación anterior ▴';
    }

    async function approveEmail(id) {
        if(confirm('¿Deseas convertir este correo en un Ticket oficial?')) {
            try {
                const response = await fetch('email_review_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=approve&id=${id}`
                });
                const res = await response.json();
                if(res.success) {
                    alert('✅ Ticket creado: #' + res.ticket_id);
                    location.reload();
                } else {
                    alert('❌ Error: ' + res.error);
                }
            } catch(e) { alert('Error de conexión'); }
        }
    }

    async function rejectEmail(id) {
        if(confirm('¿Estás seguro de rechazar y eliminar esta solicitud de correo?')) {
            try {
                const response = await fetch('email_review_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=reject&id=${id}`
                });
                const res = await response.json();
                if(res.success) {
                    location.reload();
                } else {
                    alert('❌ Error: ' + res.error);
                }
            } catch(e) { alert('Error de conexión'); }
        }
    }

    async function viewAiDraft(id) {
        const modalEl = document.getElementById('aiDraftModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        document.getElementById('aiDraftRequestId').value = id;
        document.getElementById('aiDraftSubject').value = '';
        document.getElementById('aiDraftBody').value = '';
        document.getElementById('aiDraftMeta').innerHTML = '<span class="text-muted">Analizando correo con IA...</span>';
        document.getElementById('aiDraftError').classList.add('d-none');
        modal.show();

        try {
            const response = await fetch(`email_review_actions.php?action=get_ai_draft&id=${id}`);
            const res = await response.json();
            if (res.success) {
                document.getElementById('aiDraftSubject').value = res.subject || '';
                document.getElementById('aiDraftBody').value = res.body || '';
                const statusLabel = res.status === 'sent' ? 'Ya enviada' : 'Borrador (no enviada)';
                document.getElementById('aiDraftMeta').innerHTML =
                    `Para: <b>${res.to_name || ''}</b> &lt;${res.to_email || ''}&gt; &nbsp;|&nbsp; ` +
                    `Categoría: <b>${res.classification || 'N/A'}</b> &nbsp;|&nbsp; ` +
                    `Sentimiento: <b>${res.sentiment || 'N/A'}</b> &nbsp;|&nbsp; ` +
                    `Confianza: <b>${res.confidence ?? 0}%</b> &nbsp;|&nbsp; ` +
                    `Estado: <b>${statusLabel}</b>`;
            } else {
                document.getElementById('aiDraftMeta').innerHTML = '';
                const err = document.getElementById('aiDraftError');
                err.textContent = res.error || 'No se pudo generar la respuesta con IA.';
                err.classList.remove('d-none');
            }
        } catch (e) {
            document.getElementById('aiDraftMeta').innerHTML = '';
            const err = document.getElementById('aiDraftError');
            err.textContent = 'Error de conexión al analizar el correo.';
            err.classList.remove('d-none');
        }
    }

    async function sendAiDraft() {
        const id = document.getElementById('aiDraftRequestId').value;
        const subject = document.getElementById('aiDraftSubject').value.trim();
        const body = document.getElementById('aiDraftBody').value.trim();
        const err = document.getElementById('aiDraftError');
        err.classList.add('d-none');

        if (!subject || !body) {
            err.textContent = 'El asunto y el cuerpo de la respuesta no pueden estar vacíos.';
            err.classList.remove('d-none');
            return;
        }

        try {
            const response = await fetch('email_review_actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=send_ai_reply&id=${id}&subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
            });
            const res = await response.json();
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('aiDraftModal')).hide();
                alert('✅ Respuesta enviada al usuario.');
                location.reload();
            } else {
                err.textContent = res.error || 'No se pudo enviar la respuesta.';
                err.classList.remove('d-none');
            }
        } catch (e) {
            err.textContent = 'Error de conexión al enviar la respuesta.';
            err.classList.remove('d-none');
        }
    }

    const { createApp } = Vue;
    createApp({
        data() {
            return {
                // Aquí podrías manejar estados reactivos si fuera necesario
            }
        }
    }).mount('#app');
</script>

<!-- Modal: Ver contenido de un correo -->
<div class="modal fade" id="emailBodyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold" id="emailBodySubject"><i class="fas fa-envelope-open-text me-2 text-info"></i></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between extra-small text-muted mb-3 pb-2 border-bottom">
                    <span><i class="fas fa-user me-1"></i><span id="emailBodyFrom"></span></span>
                    <span><i class="fas fa-clock me-1"></i><span id="emailBodyDate"></span></span>
                </div>
                <div id="emailBodyMain" style="white-space: pre-line; font-size: 0.9rem; color: #334155; line-height: 1.6;"></div>

                <div id="emailBodyQuotedWrap" class="mt-3 d-none">
                    <a href="#" onclick="toggleQuotedThread(); return false;" id="emailBodyQuotedLabel" class="extra-small text-decoration-none">Ver conversación anterior ▾</a>
                    <div id="emailBodyQuoted" class="d-none mt-2 p-2 border-start border-3 border-light-subtle extra-small text-muted" style="white-space: pre-line; background: #f8fafc;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Revisar / Editar / Enviar respuesta generada por IA -->
<div class="modal fade" id="aiDraftModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="fas fa-robot text-primary me-2"></i>Respuesta generada por IA</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="aiDraftRequestId">
                <div class="extra-small mb-3" id="aiDraftMeta"></div>
                <div class="alert alert-danger py-2 small d-none" id="aiDraftError"></div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">Asunto</label>
                    <input type="text" class="form-control form-control-sm" id="aiDraftSubject">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">Cuerpo de la respuesta</label>
                    <textarea class="form-control form-control-sm" id="aiDraftBody" rows="10"></textarea>
                    <div class="form-text extra-small">Puedes editar el texto antes de enviarlo. El correo se enviará con la plantilla oficial de Desk Service.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="sendAiDraft()"><i class="fas fa-paper-plane me-2"></i>Enviar respuesta</button>
            </div>
        </div>
    </div>
</div>

<style>
    .extra-small { font-size: 0.75rem; }
</style>

<?php require_once 'includes/footer.php'; ?>