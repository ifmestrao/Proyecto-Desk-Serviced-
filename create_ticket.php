<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'includes/mailer.php';
require_once 'includes/email_templates.php';
require_once 'includes/csrf.php';
require_once 'includes/integrations_api.php';

$pageTitle = 'Portal de Servicios';
$tenant_id = get_tenant_id();
$current_user_id = $_SESSION['user_id'];
$is_staff = in_array($_SESSION['role'], ['admin', 'supervisor', 'tech']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        exit('CSRF inválido');
    }

    function sanitize_html($html) {
        $allowed = '<p><br><b><strong><i><em><ul><ol><li><a><img><video><source>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('/on\\w+=/i', '', $clean);
        $clean = preg_replace('/javascript:/i', '', $clean);
        return $clean;
    }

    $title = $_POST['title'];
    $category_id = $_POST['category'];
    $description = sanitize_html($_POST['description']);
    $priority = $_POST['priority'] ?? 'medium';
    $location = trim($_POST['location']);
    $incident_at = null;
    if (!empty($_POST['incident_date'])) {
        $d = $_POST['incident_date'];
        $t = !empty($_POST['incident_time']) ? $_POST['incident_time'] : '00:00';
        $incident_at = $d . ' ' . $t . ':00';
    }

    // Determine user_id
    $user_id = $current_user_id;
    if ($is_staff && !empty($_POST['solicitor_ids']) && is_array($_POST['solicitor_ids'])) {
        $solicitor_ids = array_filter(array_map('intval', $_POST['solicitor_ids']));
        if (!empty($solicitor_ids)) {
            $user_id = $solicitor_ids[0];
        }
    }

    // Optional: Assign tech
    $assigned_tech_id = null;
    if ($is_staff && !empty($_POST['assigned_tech_id'])) {
        $tid = intval($_POST['assigned_tech_id']);
        $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('tech', 'supervisor', 'admin')");
        $st->execute([$tid]);
        if ($st->fetch()) $assigned_tech_id = $tid;
    } else {
        // Auto-assign by rules
        $stmt = $pdo->prepare("SELECT ar.* FROM assignment_rules ar WHERE ar.tenant_id = ? AND ar.category_id = ? AND ar.is_active = 1 ORDER BY ar.priority DESC, ar.id ASC LIMIT 1");
        $stmt->execute([$tenant_id, $category_id]);
        $rule = $stmt->fetch();
        
        if ($rule) {
            if ($rule['assigned_user_id']) {
                // Verify user has valid role
                $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('tech', 'supervisor', 'admin') AND tenant_id = ?");
                $st->execute([$rule['assigned_user_id'], $tenant_id]);
                if ($st->fetch()) {
                    $assigned_tech_id = $rule['assigned_user_id'];
                }
            } elseif ($rule['group_id']) {
                $stmt = $pdo->prepare("SELECT gu.user_id FROM group_users gu 
                                     JOIN users u ON gu.user_id = u.id 
                                     WHERE gu.group_id = ? AND gu.is_active = 1 
                                     AND u.role IN ('tech', 'supervisor', 'admin') 
                                     AND u.tenant_id = ?
                                     ORDER BY RAND() LIMIT 1");
                $stmt->execute([$rule['group_id'], $tenant_id]);
                $group_user = $stmt->fetch();
                if ($group_user) {
                    $assigned_tech_id = $group_user['user_id'];
                }
            }
        }
    }

    // Optional: Asset
    $asset_id = null;
    if ($is_staff && !empty($_POST['asset_id'])) {
        $aid = intval($_POST['asset_id']);
        $st = $pdo->prepare("SELECT id FROM assets WHERE id = ?");
        $st->execute([$aid]);
        if ($st->fetchColumn()) $asset_id = $aid;
    }

    // SLA calculation
    $stmt = $pdo->prepare("SELECT * FROM sla_config WHERE category_id = ?");
    $stmt->execute([$category_id]);
    $sla = $stmt->fetch();
    $val = $sla ? intval($sla['resolution_time_hours']) : 48;
    $unit = $sla ? $sla['resolution_unit'] : 'hours';
    $hours = ($unit === 'days') ? ($val * 24) : $val;

    if ($priority === 'critical') $hours = max(1, round($hours * 0.25));
    elseif ($priority === 'high') $hours = max(2, round($hours * 0.5));
    elseif ($priority === 'low') $hours = round($hours * 1.5);

    $created_at_now = date('Y-m-d H:i:s');
    $due_date = date('Y-m-d H:i:s', strtotime($created_at_now . " +$hours hours"));

    try {
        // Next local ID
        $st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM tickets WHERE tenant_id = ?");
        $st_local->execute([$tenant_id]);
        $next_local_id = intval($st_local->fetchColumn());

        $stmt = $pdo->prepare("INSERT INTO tickets (tenant_id, local_id, user_id, assigned_tech_id, asset_id, category_id, title, description, priority, due_date, incident_at, location, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)");
        $stmt->execute([$tenant_id, $next_local_id, $user_id, $assigned_tech_id, $asset_id, $category_id, $title, $description, $priority, $due_date, $incident_at, $location, $created_at_now]);
        $ticket_id = $pdo->lastInsertId();

        // Solicitors
        if (isset($solicitor_ids) && !empty($solicitor_ids)) {
            $insS = $pdo->prepare("INSERT IGNORE INTO ticket_solicitors (ticket_id, user_id) VALUES (?, ?)");
            foreach ($solicitor_ids as $sid) $insS->execute([$ticket_id, $sid]);
        } else {
            $pdo->prepare("INSERT IGNORE INTO ticket_solicitors (ticket_id, user_id) VALUES (?, ?)")->execute([$ticket_id, $user_id]);
        }

        // Initial message
        $stmt = $pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$ticket_id, $current_user_id, $description]);

        // Notifications
        try {
            $dueTxt = $due_date ? date('d/m/Y H:i', strtotime($due_date)) : '';
            notify_add($pdo, intval($user_id), 'ticket_created', "Ticket #$next_local_id creado", $title . ($dueTxt ? " · Vence: $dueTxt" : ''), "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id);
            if (!empty($assigned_tech_id)) {
                notify_add($pdo, intval($assigned_tech_id), 'ticket_assigned', "Te asignaron el ticket #$next_local_id", $title, "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id);
            }
        } catch (Throwable $e) {}

        if (!empty($assigned_tech_id)) {
            try {
                $ins = $pdo->prepare("INSERT INTO ticket_techs (ticket_id, tech_id, active) VALUES (?, ?, 1)");
                $ins->execute([$ticket_id, $assigned_tech_id]);
            } catch (Throwable $e) {}
        }

        // Integrations
        try {
            send_teams_notification($ticket_id, $title, 'open', $priority, $next_local_id);
            send_telegram_notification($ticket_id, $title, 'open', $next_local_id);
        } catch (Throwable $e) {}

        // Emails
        session_write_close();

        $usr = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $usr->execute([$user_id]);
        $u = $usr->fetch();
        if ($u && $u['email']) {
            $subj = "Ticket #$next_local_id creado: $title";
            $body = tpl_ticket_created($u['name'], $next_local_id, $title, $priority, $due_date);
            @send_mail($u['email'], $subj, $body, null, [], $tenant_id);
        }

        if (!empty($assigned_tech_id)) {
            $tq = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
            $tq->execute([$assigned_tech_id]);
            $tinfo = $tq->fetch();
            if ($tinfo && $tinfo['email']) {
                $subj = "Ticket #$next_local_id asignado";
                $body = "Hola " . $tinfo['name'] . ",\n\nSe te ha asignado el ticket #" . $next_local_id . " (" . $title . ").\n\nIngresa al sistema para gestionarlo.\n";
                @send_mail($tinfo['email'], $subj, nl2br(htmlspecialchars($body)), null, [], $tenant_id);
            }
        }

        header("Location: ticket_details.php?id=$ticket_id&success=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch active categories with nice data
$stmt = $pdo->prepare("SELECT * FROM categories WHERE tenant_id = ? AND is_active = 1 ORDER BY priority ASC, name ASC");
$stmt->execute([$tenant_id]);
$categories = $stmt->fetchAll();

// Fetch techs if staff
$techs = [];
if ($is_staff) {
    $techs = $pdo->prepare("SELECT id, name, email FROM users WHERE tenant_id = ? AND role IN ('tech', 'supervisor', 'admin') ORDER BY name");
    $techs->execute([$tenant_id]);
    $techs = $techs->fetchAll();
}

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="row my-4 justify-content-center">
        <div class="col-xl-10">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-dark mb-2">¿Cómo podemos ayudarte hoy?</h2>
                <p class="text-muted mb-0">Selecciona un servicio para solicitar soporte</p>
            </div>

            <!-- Search Bar -->
            <div class="row justify-content-center mb-4">
                <div class="col-md-6">
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" id="serviceSearch" 
                               placeholder="¿Qué problema tienes? Busca un servicio..." 
                               oninput="filterServices()">
                    </div>
                </div>
            </div>

            <!-- Categories Grid -->
            <div class="row g-3 mb-5" id="categoryGrid">
                <?php if (empty($categories)): ?>
                    <div class="col-12">
                        <div class="text-center py-5" id="noServices">
                            <i class="fas fa-tags mb-3" style="font-size: 3rem; color: #e5e7eb;"></i>
                            <p class="text-muted mb-0">No hay servicios disponibles por ahora.</p>
                        </div>
                        <div class="col-12 text-center py-5 d-none" id="noResults">
                            <i class="fas fa-search mb-3" style="font-size: 3rem; color: #e5e7eb;"></i>
                            <p class="text-muted mb-0">No se encontraron servicios que coincidan con tu búsqueda.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col-12 text-center py-5 d-none" id="noResults">
                        <i class="fas fa-search mb-3" style="font-size: 3rem; color: #e5e7eb;"></i>
                        <p class="text-muted mb-0">No se encontraron servicios que coincidan con tu búsqueda.</p>
                    </div>
                    <?php foreach ($categories as $i => $cat): ?>
                        <div class="col-md-4 col-lg-3 category-item" data-category-id="<?php echo $cat['id']; ?>"
                             data-search-term="<?php echo strtolower($cat['name'] . ' ' . ($cat['description'] ?? '')); ?>">
                            <div class="card border-0 shadow-sm hover-lift transition-all h-100" 
                                 style="cursor: pointer;" onclick="selectCategory(<?php echo $cat['id']; ?>)">
                                <div class="card-body text-center p-4">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" 
                                         style="width: 72px; height: 72px; background-color: <?php echo htmlspecialchars($cat['color'] ?? '#e0f2fe'); ?>20;">
                                        <i class="fas fa-<?php echo htmlspecialchars($cat['icon'] ?? 'question-circle'); ?>" 
                                           style="color: <?php echo htmlspecialchars($cat['color'] ?? '#0369a1'); ?>; font-size: 1.75rem;"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($cat['name']); ?></h6>
                                    <?php if ($cat['description']): ?>
                                        <p class="text-muted small mb-0 line-clamp-2"><?php echo htmlspecialchars($cat['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Ticket Form (initially hidden) -->
            <div class="card border-0 shadow-lg" id="ticketFormCard" style="display: none;">
                <div class="card-body p-5">
                    <div class="d-flex align-items-center mb-4 pb-3 border-bottom">
                        <button class="btn btn-link text-muted p-0 me-3" onclick="goBack()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div>
                            <h5 class="fw-bold text-dark mb-0" id="selectedCategoryName"></h5>
                            <p class="text-muted small mb-0" id="selectedCategoryDesc"></p>
                        </div>
                    </div>

                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger d-flex align-items-center p-3 small mb-4">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <div><?php echo $error; ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" id="ticketForm" class="row g-4">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" id="selectedCategory" name="category">

                        <div class="col-md-6">
                            <label for="title" class="form-label fw-bold text-dark">Título</label>
                            <input type="text" class="form-control" id="title" name="title" required placeholder="Breve descripción del problema">
                        </div>

                        <div class="col-md-6">
                            <label for="priority_display" class="form-label fw-bold text-dark">Prioridad</label>
                            <input type="text" class="form-control bg-light" id="priority_display" readonly>
                            <input type="hidden" id="priority" name="priority">
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label fw-bold text-dark">Descripción</label>
                            <textarea class="form-control" id="description" name="description" rows="5" required placeholder="Explica detalladamente tu solicitud o problema..."></textarea>
                        </div>

                        <?php if ($is_staff): ?>
                            <div class="col-md-6 position-relative">
                                <label for="assigned_tech_name" class="form-label fw-bold text-dark">Técnico Asignado (opcional)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-user-cog text-muted"></i></span>
                                    <input type="text" class="form-control border-start-0" id="assigned_tech_name" placeholder="Buscar técnico..." autocomplete="off">
                                    <input type="hidden" id="assigned_tech_id" name="assigned_tech_id">
                                </div>
                                <div id="tech-search-results" class="list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 200px; overflow-y: auto;"></div>
                            </div>

                            <div class="col-md-6 position-relative">
                                <label for="solicitor_email" class="form-label fw-bold text-dark">Solicitante (opcional)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" class="form-control border-start-0" id="solicitor_email" placeholder="Buscar por nombre o correo..." autocomplete="off">
                                </div>
                                <div id="search-results" class="list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 200px; overflow-y: auto;"></div>
                                <div id="solicitors-selected" class="d-flex flex-wrap gap-1 mt-2"></div>
                                <div id="solicitors-hidden"></div>
                            </div>

                            <div class="col-12 position-relative">
                                <label class="form-label fw-bold text-dark">Equipo Relacionado (opcional)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-box text-muted"></i></span>
                                    <input type="text" class="form-control border-start-0" id="asset_search" placeholder="Serial, marca o modelo del equipo" autocomplete="off">
                                    <input type="hidden" name="asset_id" id="asset_id">
                                </div>
                                <div id="asset-results" class="list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 200px; overflow-y: auto;"></div>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label for="location" class="form-label fw-bold text-dark">Ubicación</label>
                            <input type="text" class="form-control" id="location" name="location" required placeholder="Oficina o Area">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold text-dark">Fecha</label>
                            <input type="date" class="form-control" name="incident_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold text-dark">Hora</label>
                            <input type="time" class="form-control" name="incident_time" value="<?php echo date('H:i'); ?>">
                        </div>

                        <div class="col-12 d-flex justify-content-end pt-3">
                            <button type="button" class="btn btn-light border px-4 me-2" onclick="goBack()">Cancelar</button>
                            <button type="submit" class="btn btn-primary px-5 shadow-sm">
                                <i class="fas fa-paper-plane me-2"></i>Enviar Solicitud
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const categories = <?php echo json_encode($categories); ?>;

function selectCategory(categoryId) {
    const cat = categories.find(c => c.id === categoryId);
    if (cat) {
        document.getElementById('selectedCategory').value = cat.id;
        document.getElementById('selectedCategoryName').textContent = cat.name;
        document.getElementById('selectedCategoryDesc').textContent = cat.description || '';
        
        // Map category priority (0-3) to ticket priority (low/medium/high/critical)
        const priorityMap = ['low', 'medium', 'high', 'critical'];
        const priorityTextMap = ['Baja', 'Media', 'Alta', 'Crítica'];
        const priorityValue = cat.priority !== undefined ? parseInt(cat.priority) : 1; // Default to medium
        const ticketPriority = priorityMap[priorityValue] || 'medium';
        const ticketPriorityText = priorityTextMap[priorityValue] || 'Media';
        
        document.getElementById('priority').value = ticketPriority;
        document.getElementById('priority_display').value = ticketPriorityText;
        
        document.getElementById('categoryGrid').style.display = 'none';
        document.getElementById('ticketFormCard').style.display = 'block';
        // Add active visual to selected
        document.querySelectorAll('.category-item').forEach(el => {
            el.querySelector('.card').classList.remove('border-primary', 'border-2');
            if (parseInt(el.dataset.categoryId) === categoryId) {
                el.querySelector('.card').classList.add('border-primary', 'border-2');
            }
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function goBack() {
    document.getElementById('ticketFormCard').style.display = 'none';
    document.getElementById('categoryGrid').style.display = 'flex';
}

function filterServices() {
    const searchTerm = document.getElementById('serviceSearch').value.toLowerCase().trim();
    const categoryItems = document.querySelectorAll('.category-item');
    const noResults = document.getElementById('noResults');
    
    let visibleCount = 0;
    
    categoryItems.forEach(item => {
        const searchData = item.getAttribute('data-search-term');
        if (searchData && searchData.includes(searchTerm)) {
            item.style.display = 'block';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    if (noResults) {
        noResults.classList.toggle('d-none', visibleCount > 0 || searchTerm === '');
    }
}

<?php if ($is_staff): ?>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Búsqueda de Solicitante(s)
    const requesterInput = document.getElementById('solicitor_email');
    const resultsContainer = document.getElementById('search-results');
    const solicitorsSelected = document.getElementById('solicitors-selected');
    const solicitorsHidden = document.getElementById('solicitors-hidden');
    const selectedSolicitors = new Set();

    function addSolicitorChip(user) {
        if (selectedSolicitors.has(user.id)) return;
        selectedSolicitors.add(user.id);

        const chip = document.createElement('span');
        chip.className = 'badge rounded-pill bg-info-subtle text-info border d-inline-flex align-items-center gap-2 px-3 py-2';
        chip.innerHTML = `<span>${user.name}</span><button type="button" class="btn btn-sm p-0 border-0 text-info" onclick="removeSolicitor(${user.id}, this)">×</button>`;
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'solicitor_ids[]';
        input.value = user.id;
        input.id = 'sol_input_' + user.id;

        solicitorsSelected.appendChild(chip);
        solicitorsHidden.appendChild(input);
    }

    window.removeSolicitor = function(id, btn) {
        selectedSolicitors.delete(id);
        btn.closest('.badge').remove();
        const input = document.getElementById('sol_input_' + id);
        if (input) input.remove();
    };

    if (requesterInput) {
        requesterInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length < 2) {
                resultsContainer.classList.add('d-none');
                return;
            }
            fetch(`search_users.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    resultsContainer.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(user => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action py-2';
                            item.innerHTML = `
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold small">${user.name}</div>
                                        <div class="text-muted small">${user.email}</div>
                                    </div>
                                    <i class="fas fa-plus text-primary small"></i>
                                </div>`;
                            item.addEventListener('click', e => {
                                e.preventDefault();
                                addSolicitorChip(user);
                                requesterInput.value = '';
                                resultsContainer.classList.add('d-none');
                            });
                            resultsContainer.appendChild(item);
                        });
                        resultsContainer.classList.remove('d-none');
                    } else {
                        resultsContainer.classList.add('d-none');
                    }
                });
        });
    }

    // 2. Búsqueda de Técnicos
    const techNameInput = document.getElementById('assigned_tech_name');
    const techIdInput = document.getElementById('assigned_tech_id');
    const techResults = document.getElementById('tech-search-results');

    if (techNameInput) {
        techNameInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length < 2) {
                techResults.classList.add('d-none');
                return;
            }
            fetch(`search_techs.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    techResults.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(tech => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action py-2';
                            item.innerHTML = `
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold small">${tech.name}</div>
                                        <div class="text-muted small">${tech.email}</div>
                                    </div>
                                    <i class="fas fa-user-check text-primary small"></i>
                                </div>`;
                            item.addEventListener('click', e => {
                                e.preventDefault();
                                techNameInput.value = tech.name;
                                techIdInput.value = tech.id;
                                techResults.classList.add('d-none');
                            });
                            techResults.appendChild(item);
                        });
                        techResults.classList.remove('d-none');
                    } else {
                        techResults.classList.add('d-none');
                    }
                });
        });
    }

    // 3. Búsqueda de Activos
    const assetInput = document.getElementById('asset_search');
    const assetIdInput = document.getElementById('asset_id');
    const assetResults = document.getElementById('asset-results');

    if (assetInput) {
        assetInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length < 2) {
                assetResults.classList.add('d-none');
                return;
            }
            fetch(`search_assets.php?q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(list => {
                    assetResults.innerHTML = '';
                    if (list.length > 0) {
                        list.forEach(a => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action py-2';
                            const owner = a.owner_name ? ` · ${a.owner_name}` : '';
                            item.innerHTML = `
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold small">${a.serial_number || 'S/N'} - ${a.brand || ''}</div>
                                        <div class="text-muted small">${a.model || ''}${owner}</div>
                                    </div>
                                    <i class="fas fa-link text-primary small"></i>
                                </div>`;
                            item.addEventListener('click', e => {
                                e.preventDefault();
                                assetInput.value = `${a.serial_number || 'S/N'} - ${a.brand || ''} ${a.model || ''}`.trim();
                                assetIdInput.value = a.id;
                                assetResults.classList.add('d-none');
                            });
                            assetResults.appendChild(item);
                        });
                        assetResults.classList.remove('d-none');
                    } else {
                        assetResults.classList.add('d-none');
                    }
                });
        });
    }

    // Cerrar resultados al hacer clic fuera
    document.addEventListener('click', function(e) {
        if (requesterInput && !requesterInput.contains(e.target) && !resultsContainer.contains(e.target)) {
            resultsContainer.classList.add('d-none');
        }
        if (techNameInput && !techNameInput.contains(e.target) && !techResults.contains(e.target)) {
            techResults.classList.add('d-none');
        }
        if (assetInput && !assetInput.contains(e.target) && !assetResults.contains(e.target)) {
            assetResults.classList.add('d-none');
        }
    });
});
<?php endif; ?>
</script>

<style>
.compact-container { max-width: 1100px; }
.hover-lift { transition: all 0.25s ease; }
.hover-lift:hover { transform: translateY(-4px); box-shadow: 0 12px 20px rgba(0, 0, 0, 0.1) !important; }
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.transition-all { transition: all 0.3s ease; }
</style>

<?php require_once 'includes/footer.php'; ?>