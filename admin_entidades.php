<?php
require_once 'includes/header.php';
checkAuth();
checkAdmin();
require_once 'config/db.php';
require_once 'includes/csrf.php';

$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
$pageTitle = 'Gestión de Entidades';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        die('CSRF token inválido');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO entities (tenant_id, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$tenant_id, $name, $description]);
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name) {
            $stmt = $pdo->prepare("UPDATE entities SET name = ?, description = ?, is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$name, $description, $is_active, $id, $tenant_id]);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM entities WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
        }
    }
    header('Location: admin_entidades.php');
    exit;
}

try {
    $stmt = $pdo->query("SELECT 1 FROM entities LIMIT 1");
} catch (PDOException $e) {
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
}

$stmt = $pdo->prepare("SELECT * FROM entities WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenant_id]);
$entities = $stmt->fetchAll();

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <h3 class="fw-bold text-dark mb-0"><i class="fas fa-building text-primary me-2"></i>Gestión de Entidades</h3>
            <p class="text-muted small mb-0">Administración de la estructura organizacional institucional.</p>
        </div>
        <button class="btn btn-primary btn-sm shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#createModal">
            <i class="fas fa-plus me-2"></i>Nueva Entidad
        </button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4">Nombre de la Entidad</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Fecha Registro</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entities)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <img src="assets/img/empty-state.svg" alt="" style="width: 60px; opacity: 0.3;" class="mb-3 d-block mx-auto">
                                    <p>No hay entidades registradas.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($entities as $entity): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-xs bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px; font-size: 0.75rem; font-weight: bold;">
                                            <?php echo strtoupper(substr($entity['name'], 0, 1)); ?>
                                        </div>
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($entity['name']); ?></span>
                                    </div>
                                </td>
                                <td><span class="text-muted extra-small"><?php echo htmlspecialchars($entity['description'] ?: 'Sin descripción'); ?></span></td>
                                <td>
                                    <span class="badge rounded-pill <?php echo $entity['is_active'] ? 'bg-success' : 'bg-secondary'; ?> bg-opacity-10 <?php echo $entity['is_active'] ? 'text-success' : 'text-secondary'; ?> extra-small border border-opacity-10" style="font-size: 0.6rem;">
                                        <?php echo $entity['is_active'] ? 'ACTIVO' : 'INACTIVO'; ?>
                                    </span>
                                </td>
                                <td class="text-muted extra-small"><?php echo date('d/m/Y', strtotime($entity['created_at'])); ?></td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <button class="btn btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $entity['id']; ?>" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que desea eliminar esta entidad?');">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $entity['id']; ?>">
                                            <button type="submit" class="btn btn-action btn-delete" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Modal Edit -->
                            <div class="modal fade" id="editModal<?php echo $entity['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content border-0 shadow">
                                        <form method="POST">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?php echo $entity['id']; ?>">
                                            <div class="modal-header bg-light border-0">
                                                <h6 class="modal-title fw-bold"><i class="fas fa-edit me-2 text-primary"></i>Editar Entidad</h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-3">
                                                <div class="mb-3">
                                                    <label class="form-label">Nombre</label>
                                                    <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($entity['name']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Descripción</label>
                                                    <textarea name="description" class="form-control form-control-sm" rows="3"><?php echo htmlspecialchars($entity['description']); ?></textarea>
                                                </div>
                                                <div class="form-check form-switch small">
                                                    <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="active<?php echo $entity['id']; ?>" <?php echo $entity['is_active'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="active<?php echo $entity['id']; ?>">Entidad Activa</label>
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
                    <h6 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i>Nueva Entidad</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Ej: Departamento Administrativo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Propósito de la entidad..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-primary px-3">Crear Entidad</button>
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
                        <i class="fas fa-plus-circle me-2"></i>Nueva Entidad
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Nombre</label>
                        <input type="text" name="name" class="form-control form-control-lg" placeholder="Ej: Departamento de Tecnología" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Descripción</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe la entidad"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-2"></i>Crear Entidad
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
