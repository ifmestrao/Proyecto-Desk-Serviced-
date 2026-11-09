<?php
require_once 'includes/header.php';
checkAuth();
checkAdmin();
require_once 'config/db.php';
require_once 'includes/csrf.php';

$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
$pageTitle = 'Reglas de Asignación';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        die('CSRF token inválido');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $group_id = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $assigned_user_id = !empty($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : null;
        $priority = (int)($_POST['priority'] ?? 1);
        if ($name && $category_id) {
            $stmt = $pdo->prepare("INSERT INTO assignment_rules (tenant_id, name, category_id, group_id, assigned_user_id, priority) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $name, $category_id, $group_id, $assigned_user_id, $priority]);
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $group_id = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $assigned_user_id = !empty($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : null;
        $priority = (int)($_POST['priority'] ?? 1);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name && $category_id) {
            $stmt = $pdo->prepare("UPDATE assignment_rules SET name = ?, category_id = ?, group_id = ?, assigned_user_id = ?, priority = ?, is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$name, $category_id, $group_id, $assigned_user_id, $priority, $is_active, $id, $tenant_id]);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM assignment_rules WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
        }
    }
    header('Location: admin_reglas.php');
    exit;
}

try {
    $stmt = $pdo->query("SELECT 1 FROM assignment_rules LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `groups` (
        id int NOT NULL AUTO_INCREMENT,
        tenant_id int NOT NULL,
        name varchar(190) NOT NULL,
        description text,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_group_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `group_users` (
        id int NOT NULL AUTO_INCREMENT,
        tenant_id int NOT NULL,
        group_id int NOT NULL,
        user_id int NOT NULL,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_group_user (group_id, user_id),
        KEY idx_guser_tenant (tenant_id),
        KEY idx_guser_group (group_id),
        KEY idx_guser_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS entities (
        id int NOT NULL AUTO_INCREMENT,
        tenant_id int NOT NULL,
        name varchar(190) NOT NULL,
        description text,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_entity_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS assignment_rules (
        id int NOT NULL AUTO_INCREMENT,
        tenant_id int NOT NULL,
        name varchar(190) NOT NULL,
        category_id int NOT NULL,
        group_id int DEFAULT NULL,
        assigned_user_id int DEFAULT NULL,
        priority int DEFAULT 1,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_rule_tenant (tenant_id),
        KEY idx_rule_category (category_id),
        KEY idx_rule_group (group_id),
        KEY idx_rule_user (assigned_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Paginación
$per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Obtener total de reglas
$stmt = $pdo->prepare("SELECT COUNT(*) FROM assignment_rules WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$total = $stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Obtener reglas de la página actual
$stmt = $pdo->prepare("SELECT ar.*, c.name as category_name, g.name as group_name, u.name as user_name 
                       FROM assignment_rules ar 
                       LEFT JOIN categories c ON ar.category_id = c.id 
                       LEFT JOIN `groups` g ON ar.group_id = g.id 
                       LEFT JOIN users u ON ar.assigned_user_id = u.id 
                       WHERE ar.tenant_id = ? 
                       ORDER BY ar.priority DESC, ar.name
                       LIMIT ? OFFSET ?");
$stmt->execute([$tenant_id, $per_page, $offset]);
$rules = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM categories WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenant_id]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenant_id]);
$groups = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenant_id]);
$users = $stmt->fetchAll();

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <p class="text-muted small mb-0">Automatización de la distribución de tickets según categorías y prioridades.</p>
        </div>
        <button class="btn btn-primary btn-sm shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#createModal">
            <i class="fas fa-plus me-2"></i>Nueva Regla
        </button>
    </div>

    <div class="card border-0 shadow-sm" style="margin-bottom: 30px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4">Nombre de la Regla</th>
                            <th>Categoría Aplicable</th>
                            <th>Destino de Asignación</th>
                            <th>Prioridad</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <img src="assets/img/empty-state.svg" alt="" style="width: 60px; opacity: 0.3;" class="mb-3 d-block mx-auto">
                                    <p>No hay reglas de asignación configuradas.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rules as $rule): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-xs bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px; font-size: 0.75rem; font-weight: bold;">
                                            <?php echo strtoupper(substr($rule['name'], 0, 1)); ?>
                                        </div>
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($rule['name']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-10 rounded-pill px-2" style="font-size: 0.65rem;">
                                        <?php echo htmlspecialchars($rule['category_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($rule['group_name']): ?>
                                        <span class="text-success extra-small d-inline-flex align-items-center gap-1">
                                            <i class="fas fa-users"></i> <?php echo htmlspecialchars($rule['group_name']); ?>
                                        </span>
                                    <?php elseif ($rule['user_name']): ?>
                                        <span class="text-info extra-small d-inline-flex align-items-center gap-1">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($rule['user_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted extra-small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border rounded-pill px-2" style="font-size: 0.65rem;">Prio: <?php echo $rule['priority']; ?></span>
                                </td>
                                <td>
                                    <span class="badge rounded-pill <?php echo $rule['is_active'] ? 'bg-success' : 'bg-secondary'; ?> bg-opacity-10 <?php echo $rule['is_active'] ? 'text-success' : 'text-secondary'; ?> extra-small border border-opacity-10" style="font-size: 0.6rem;">
                                        <?php echo $rule['is_active'] ? 'ACTIVA' : 'INACTIVA'; ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <button class="btn btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $rule['id']; ?>" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que desea eliminar esta regla?');">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $rule['id']; ?>">
                                            <button type="submit" class="btn btn-action btn-delete" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Modal Edit -->
                            <div class="modal fade" id="editModal<?php echo $rule['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content border-0 shadow">
                                        <form method="POST">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?php echo $rule['id']; ?>">
                                            <div class="modal-header bg-light border-0">
                                                <h6 class="modal-title fw-bold"><i class="fas fa-edit me-2 text-primary"></i>Editar Regla</h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-3">
                                                <div class="mb-3">
                                                    <label class="form-label">Nombre de la regla</label>
                                                    <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($rule['name']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Categoría</label>
                                                    <select name="category_id" class="form-select form-select-sm" required>
                                                        <?php foreach ($categories as $cat): ?>
                                                            <option value="<?php echo $cat['id']; ?>" <?php echo $rule['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($cat['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Asignar a Grupo</label>
                                                    <select name="group_id" class="form-select form-select-sm">
                                                        <option value="">Ninguno</option>
                                                        <?php foreach ($groups as $grp): ?>
                                                            <option value="<?php echo $grp['id']; ?>" <?php echo $rule['group_id'] == $grp['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($grp['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Asignar a Usuario</label>
                                                    <select name="assigned_user_id" class="form-select form-select-sm">
                                                        <option value="">Ninguno</option>
                                                        <?php foreach ($users as $usr): ?>
                                                            <option value="<?php echo $usr['id']; ?>" <?php echo $rule['assigned_user_id'] == $usr['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($usr['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Prioridad (1-100)</label>
                                                    <input type="number" name="priority" class="form-control form-control-sm" value="<?php echo $rule['priority']; ?>" min="1" max="100">
                                                </div>
                                                <div class="form-check form-switch small">
                                                    <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="active<?php echo $rule['id']; ?>" <?php echo $rule['is_active'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="active<?php echo $rule['id']; ?>">Regla Activa</label>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0 p-3 pt-0">
                                                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-sm btn-primary px-3">Guardar Cambios</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center">
                <small class="text-muted">Mostrando <?php echo ($offset + 1); ?> - <?php echo min($offset + $per_page, $total); ?> de <?php echo $total; ?> reglas</small>
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

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i>Nueva Regla</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label">Nombre de la regla</label>
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Soporte" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Categoría</label>
                        <select name="category_id" class="form-select form-select-sm" required>
                            <option value="">Seleccionar categoría...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Asignar a Grupo</label>
                        <select name="group_id" class="form-select form-select-sm">
                            <option value="">Ninguno</option>
                            <?php foreach ($groups as $grp): ?>
                                <option value="<?php echo $grp['id']; ?>"><?php echo htmlspecialchars($grp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Asignar a Usuario</label>
                        <select name="assigned_user_id" class="form-select form-select-sm">
                            <option value="">Ninguno</option>
                            <?php foreach ($users as $usr): ?>
                                <option value="<?php echo $usr['id']; ?>"><?php echo htmlspecialchars($usr['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prioridad</label>
                        <input type="number" name="priority" class="form-control form-control-sm" value="1" min="1" max="100">
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-primary px-3">Crear Regla</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .extra-small { font-size: 0.7rem; }
</style>

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-gradient-primary text-white rounded-top-4">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Nueva Regla de Asignación
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Nombre de la regla</label>
                        <input type="text" name="name" class="form-control form-control-lg" placeholder="Ej: Asignar Soporte a Técnicos" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Categoría</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Seleccionar categoría...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Asignar a Grupo (opcional)</label>
                        <select name="group_id" class="form-select">
                            <option value="">Ninguno</option>
                            <?php foreach ($groups as $grp): ?>
                                <option value="<?php echo $grp['id']; ?>"><?php echo htmlspecialchars($grp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Asignar a Usuario (opcional)</label>
                        <select name="assigned_user_id" class="form-select">
                            <option value="">Ninguno</option>
                            <?php foreach ($users as $usr): ?>
                                <option value="<?php echo $usr['id']; ?>"><?php echo htmlspecialchars($usr['name']); ?> (<?php echo htmlspecialchars($usr['role']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted mt-1">Si selecciona ambos, el usuario tiene prioridad sobre el grupo</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Prioridad</label>
                        <input type="number" name="priority" class="form-control" value="1" min="1" max="100">
                        <div class="form-text text-muted mt-1">Mayor número = mayor prioridad</div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Crear Regla
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.bg-gradient-primary {
    background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
}
.avatar {
    background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
}
.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.btn-primary {
    background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
    border: none;
}
.btn-primary:hover {
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);
}
.form-select:focus, .form-control:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 0.25rem rgba(99, 102, 241, 0.15);
}
</style>

<?php require_once 'includes/footer.php'; ?>
