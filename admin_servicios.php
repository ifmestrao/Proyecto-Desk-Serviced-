<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';

$pageTitle = 'Portal de Servicios';
$tenant_id = get_tenant_id();

// Handle actions: add, edit, delete, toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $icon = trim($_POST['icon']);
        $color = trim($_POST['color']);
        $priority = intval($_POST['priority']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO categories (tenant_id, name, description, icon, color, priority, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $name, $description, $icon, $color, $priority, $is_active]);
        } else {
                $id = intval($_POST['id']);
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ?, icon = ?, color = ?, priority = ?, is_active = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$name, $description, $icon, $color, $priority, $is_active, $id, $tenant_id]);
            }
        } elseif ($action === 'delete') {
            $id = intval($_POST['id']);
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
        } elseif ($action === 'toggle') {
            $id = intval($_POST['id']);
            $is_active = intval($_POST['is_active']);
            $stmt = $pdo->prepare("UPDATE categories SET is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$is_active, $id, $tenant_id]);
        }
        header("Location: admin_servicios.php");
        exit;
    }
}

// Paginación
$per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Obtener total de categorías
$stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$total = $stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Obtener categorías de la página actual
$stmt = $pdo->prepare("SELECT * FROM categories WHERE tenant_id = ? ORDER BY priority ASC, name ASC LIMIT ? OFFSET ?");
$stmt->execute([$tenant_id, $per_page, $offset]);
$categories = $stmt->fetchAll();

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <p class="text-muted small mb-0">Gestión de servicios del portal de autoservicio</p>
        </div>
        <button class="btn btn-primary btn-sm shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="fas fa-plus me-2"></i>Nuevo Servicio
        </button>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="margin-bottom: 30px;">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted text-uppercase" style="font-size: 0.75rem;">
                                <tr>
                                    <th class="ps-4" style="width: 80px;"></th>
                                    <th>Servicio</th>
                                    <th>Descripción</th>
                                    <th>Icono</th>
                                    <th>Prioridad</th>
                                    <th>Estado</th>
                                    <th class="text-end pe-4">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categories)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="fas fa-tags mb-3" style="font-size: 3rem; opacity: 0.3;"></i>
                                            <p class="mb-0">No tienes servicios configurados.</p>
                                            <p class="small">Agrega tu primer servicio para que los usuarios lo vean en el portal.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td class="ps-4">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                         style="width: 48px; height: 48px; background-color: <?php echo htmlspecialchars($cat['color'] ?? '#e0f2fe'); ?>20;">
                                        <i class="fas fa-<?php echo htmlspecialchars($cat['icon'] ?? 'question-circle'); ?>" 
                                           style="color: <?php echo htmlspecialchars($cat['color'] ?? '#0369a1'); ?>; font-size: 1.25rem;"></i>
                                    </div>
                                </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($cat['name']); ?></div>
                                        </td>
                                        <td>
                                            <div class="text-muted small"><?php echo htmlspecialchars($cat['description'] ?? '-'); ?></div>
                                        </td>
                                        <td>
                                            <code class="text-primary bg-light px-2 py-1 rounded small">fa-<?php echo htmlspecialchars($cat['icon'] ?? 'question'); ?></code>
                                        </td>
                                        <td>
                                    <?php 
                                    $priorityText = ['Baja', 'Media', 'Alta', 'Crítica'];
                                    $priorityColor = ['bg-secondary', 'bg-primary', 'bg-warning', 'bg-danger'];
                                    $p = intval($cat['priority'] ?? 0);
                                    if ($p < 0) $p = 0;
                                    if ($p > 3) $p = 3;
                                    ?>
                                    <span class="badge <?php echo $priorityColor[$p]; ?>"><?php echo $priorityText[$p]; ?></span>
                                </td>
                                        <td>
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $cat['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm <?php echo $cat['is_active'] ? 'btn-success' : 'btn-secondary'; ?> btn-icon btn-round">
                                                    <i class="fas fa-<?php echo $cat['is_active'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-action btn-edit" data-bs-toggle="modal" 
                                                    data-bs-target="#editCategoryModal"
                                                    data-id="<?php echo $cat['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($cat['name']); ?>"
                                                    data-description="<?php echo htmlspecialchars($cat['description'] ?? ''); ?>"
                                                    data-icon="<?php echo htmlspecialchars($cat['icon'] ?? 'question'); ?>"
                                                    data-color="<?php echo htmlspecialchars($cat['color'] ?? '#0369a1'); ?>"
                                                    data-priority="<?php echo intval($cat['priority'] ?? 0); ?>"
                                                    data-is_active="<?php echo intval($cat['is_active'] ?? 1); ?>"
                                                    title="Editar">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Seguro que deseas eliminar este servicio?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                                <button type="submit" class="btn btn-action btn-delete" title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- Paginación -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                    <small class="text-muted">Mostrando <?php echo ($offset + 1); ?> - <?php echo min($offset + count($categories), $total); ?> de <?php echo $total; ?> servicios</small>
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
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pb-2">
                <h5 class="modal-title fw-bold text-dark">Nuevo Servicio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body pt-0">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label for="add_name" class="form-label fw-bold small">Nombre del servicio</label>
                        <input type="text" class="form-control" id="add_name" name="name" required placeholder="Ej: Problemas de Red">
                    </div>
                    <div class="mb-3">
                        <label for="add_description" class="form-label fw-bold small">Descripción</label>
                        <textarea class="form-control" id="add_description" name="description" rows="2" placeholder="Breve descripción del servicio"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="add_icon" class="form-label fw-bold small">Icono de FontAwesome</label>
                        <div class="input-group mb-2">
                            <span class="input-group-text">fa-</span>
                            <input type="text" class="form-control" id="add_icon" name="icon" value="question-circle" placeholder="question-circle">
                        </div>
                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                            <small class="text-muted d-block mb-2">Selecciona un icono:</small>
                            <div class="row g-1">
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('question-circle', 'add_icon')">
                                        <i class="fas fa-question-circle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('wrench', 'add_icon')">
                                        <i class="fas fa-wrench"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('tools', 'add_icon')">
                                        <i class="fas fa-tools"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('desktop', 'add_icon')">
                                        <i class="fas fa-desktop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('laptop', 'add_icon')">
                                        <i class="fas fa-laptop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('server', 'add_icon')">
                                        <i class="fas fa-server"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('wifi', 'add_icon')">
                                        <i class="fas fa-wifi"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('network-wired', 'add_icon')">
                                        <i class="fas fa-network-wired"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('print', 'add_icon')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('mouse', 'add_icon')">
                                        <i class="fas fa-mouse"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('keyboard', 'add_icon')">
                                        <i class="fas fa-keyboard"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('phone', 'add_icon')">
                                        <i class="fas fa-phone"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('mobile-alt', 'add_icon')">
                                        <i class="fas fa-mobile-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('tablet-alt', 'add_icon')">
                                        <i class="fas fa-tablet-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('tv', 'add_icon')">
                                        <i class="fas fa-tv"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('hdd', 'add_icon')">
                                        <i class="fas fa-hdd"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('database', 'add_icon')">
                                        <i class="fas fa-database"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('cloud', 'add_icon')">
                                        <i class="fas fa-cloud"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('lock', 'add_icon')">
                                        <i class="fas fa-lock"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('shield-alt', 'add_icon')">
                                        <i class="fas fa-shield-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('user', 'add_icon')">
                                        <i class="fas fa-user"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('users', 'add_icon')">
                                        <i class="fas fa-users"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('envelope', 'add_icon')">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('calendar', 'add_icon')">
                                        <i class="fas fa-calendar"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('file-alt', 'add_icon')">
                                        <i class="fas fa-file-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('folder', 'add_icon')">
                                        <i class="fas fa-folder"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('exclamation-triangle', 'add_icon')">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('exclamation-circle', 'add_icon')">
                                        <i class="fas fa-exclamation-circle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('info-circle', 'add_icon')">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('lightbulb', 'add_icon')">
                                        <i class="fas fa-lightbulb"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="add_color" class="form-label fw-bold small">Color</label>
                            <input type="color" class="form-control form-control-color" id="add_color" name="color" value="#0369a1">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="add_priority" class="form-label fw-bold small">Prioridad</label>
                        <select class="form-select" id="add_priority" name="priority">
                            <option value="0" selected>Baja</option>
                            <option value="1">Media</option>
                            <option value="2">Alta</option>
                            <option value="3">Crítica</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="add_is_active" name="is_active" checked>
                        <label class="form-check-label fw-bold small" for="add_is_active">Servicio activo</label>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pb-2">
                <h5 class="modal-title fw-bold text-dark">Editar Servicio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body pt-0">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold small">Nombre del servicio</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-bold small">Descripción</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_icon" class="form-label fw-bold small">Icono de FontAwesome</label>
                        <div class="input-group mb-2">
                            <span class="input-group-text">fa-</span>
                            <input type="text" class="form-control" id="edit_icon" name="icon">
                        </div>
                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                            <small class="text-muted d-block mb-2">Selecciona un icono:</small>
                            <div class="row g-1">
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('question-circle', 'edit_icon')">
                                        <i class="fas fa-question-circle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('wrench', 'edit_icon')">
                                        <i class="fas fa-wrench"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('tools', 'edit_icon')">
                                        <i class="fas fa-tools"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('desktop', 'edit_icon')">
                                        <i class="fas fa-desktop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('laptop', 'edit_icon')">
                                        <i class="fas fa-laptop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('server', 'edit_icon')">
                                        <i class="fas fa-server"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('wifi', 'edit_icon')">
                                        <i class="fas fa-wifi"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('network-wired', 'edit_icon')">
                                        <i class="fas fa-network-wired"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('print', 'edit_icon')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('mouse', 'edit_icon')">
                                        <i class="fas fa-mouse"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('keyboard', 'edit_icon')">
                                        <i class="fas fa-keyboard"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('phone', 'edit_icon')">
                                        <i class="fas fa-phone"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('mobile-alt', 'edit_icon')">
                                        <i class="fas fa-mobile-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('tablet-alt', 'edit_icon')">
                                        <i class="fas fa-tablet-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('tv', 'edit_icon')">
                                        <i class="fas fa-tv"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('hdd', 'edit_icon')">
                                        <i class="fas fa-hdd"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('database', 'edit_icon')">
                                        <i class="fas fa-database"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('cloud', 'edit_icon')">
                                        <i class="fas fa-cloud"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('lock', 'edit_icon')">
                                        <i class="fas fa-lock"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('shield-alt', 'edit_icon')">
                                        <i class="fas fa-shield-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('user', 'edit_icon')">
                                        <i class="fas fa-user"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('users', 'edit_icon')">
                                        <i class="fas fa-users"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('envelope', 'edit_icon')">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('calendar', 'edit_icon')">
                                        <i class="fas fa-calendar"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('file-alt', 'edit_icon')">
                                        <i class="fas fa-file-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('folder', 'edit_icon')">
                                        <i class="fas fa-folder"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('exclamation-triangle', 'edit_icon')">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('exclamation-circle', 'edit_icon')">
                                        <i class="fas fa-exclamation-circle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('info-circle', 'edit_icon')">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('lightbulb', 'edit_icon')">
                                        <i class="fas fa-lightbulb"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_color" class="form-label fw-bold small">Color</label>
                            <input type="color" class="form-control form-control-color" id="edit_color" name="color">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_priority" class="form-label fw-bold small">Prioridad</label>
                        <select class="form-select" id="edit_priority" name="priority">
                            <option value="0">Baja</option>
                            <option value="1">Media</option>
                            <option value="2">Alta</option>
                            <option value="3">Crítica</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                        <label class="form-check-label fw-bold small" for="edit_is_active">Servicio activo</label>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function selectIcon(iconClass, inputId) {
    document.getElementById(inputId).value = iconClass;
}

document.getElementById('editCategoryModal').addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    document.getElementById('edit_id').value = button.getAttribute('data-id');
    document.getElementById('edit_name').value = button.getAttribute('data-name');
    document.getElementById('edit_description').value = button.getAttribute('data-description');
    document.getElementById('edit_icon').value = button.getAttribute('data-icon');
    document.getElementById('edit_color').value = button.getAttribute('data-color');
    const priority = parseInt(button.getAttribute('data-priority'));
    const editPrioritySelect = document.getElementById('edit_priority');
    for (let i = 0; i < editPrioritySelect.options.length; i++) {
        editPrioritySelect.options[i].selected = parseInt(editPrioritySelect.options[i].value) === priority;
    }
    const isActive = button.getAttribute('data-is_active') === '1';
    document.getElementById('edit_is_active').checked = isActive;
});
</script>

<style>
.compact-container { max-width: 1200px; }
.icon-circle-sm { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0; border-radius: 50%; }
.btn-action { opacity: 0.8; transition: all 0.2s ease; }
.btn-action:hover { opacity: 1; transform: translateY(-1px); }
.btn-edit:hover { background-color: rgba(59,130,246,0.1); color: #3b82f6; }
.btn-delete:hover { background-color: rgba(239,68,68,0.1); color: #ef4444; }
</style>

<?php require_once 'includes/footer.php'; ?>