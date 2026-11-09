<?php
require_once 'includes/header.php';
checkAuth();
checkAdmin();
require_once 'config/db.php';
require_once 'includes/csrf.php';

$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
$pageTitle = 'Gestión de Grupos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        die('CSRF token inválido');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO `groups` (tenant_id, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$tenant_id, $name, $description]);
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name) {
            $stmt = $pdo->prepare("UPDATE `groups` SET name = ?, description = ?, is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$name, $description, $is_active, $id, $tenant_id]);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM `groups` WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
        }
    } elseif ($action === 'add_user') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($group_id && $user_id) {
            try {
                $stmt = $pdo->prepare("INSERT INTO group_users (tenant_id, group_id, user_id) VALUES (?, ?, ?)");
                $stmt->execute([$tenant_id, $group_id, $user_id]);
            } catch (PDOException $e) {
            }
        }
    } elseif ($action === 'remove_user') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($group_id && $user_id) {
            $stmt = $pdo->prepare("DELETE FROM group_users WHERE group_id = ? AND user_id = ? AND tenant_id = ?");
            $stmt->execute([$group_id, $user_id, $tenant_id]);
        }
    }
    header('Location: admin_grupos.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM `groups` WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenant_id]);
$groups = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, email, role, avatar FROM users WHERE tenant_id = ? ORDER BY name");
$stmt->execute([$tenant_id]);
$all_users = $stmt->fetchAll();

$group_users = [];
foreach ($groups as $g) {
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.email, u.role, u.avatar FROM group_users gu JOIN users u ON gu.user_id = u.id WHERE gu.group_id = ? AND gu.tenant_id = ? AND gu.is_active = 1");
    $stmt->execute([$g['id'], $tenant_id]);
    $group_users[$g['id']] = $stmt->fetchAll();
}

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <p class="text-muted extra-small mb-0">Organización de equipos para flujos de trabajo y asignación.</p>
        </div>
        <button class="btn btn-primary btn-sm shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#createModal">
            <i class="fas fa-plus me-2"></i>Nuevo Grupo
        </button>
    </div>

    <div class="row g-3" style="margin-bottom: 30px;">
        <?php foreach ($groups as $group): ?>
            <div class="col-xl-4 col-md-6">
                <div class="card border-0 shadow-sm h-100 group-card overflow-hidden">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="icon-circle-sm bg-primary bg-opacity-10 text-primary me-2">
                                <i class="fas fa-users"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($group['name']); ?></h6>
                                <span class="badge rounded-pill <?php echo $group['is_active'] ? 'bg-success' : 'bg-secondary'; ?> bg-opacity-10 <?php echo $group['is_active'] ? 'text-success' : 'text-secondary'; ?> extra-small border border-opacity-10" style="font-size: 0.6rem;">
                                    <?php echo $group['is_active'] ? 'ACTIVO' : 'INACTIVO'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="d-flex gap-1">
                            <button class="btn btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $group['id']; ?>" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que desea eliminar este grupo?');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $group['id']; ?>">
                                <button type="submit" class="btn btn-action btn-delete" title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body p-3 pt-0">
                        <?php if ($group['description']): ?>
                            <p class="text-muted extra-small mb-3"><?php echo htmlspecialchars($group['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-uppercase fw-bold text-muted" style="font-size: 0.65rem;">Miembros del Equipo</span>
                                <span class="badge bg-light text-dark border rounded-pill px-2" style="font-size: 0.65rem;"><?php echo count($group_users[$group['id']]); ?></span>
                            </div>
                            <div class="member-list overflow-auto" style="max-height: 120px;">
                                <?php if (!empty($group_users[$group['id']])): ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($group_users[$group['id']] as $user): ?>
                                            <div class="badge bg-light text-dark border rounded-pill px-2 py-1 d-flex align-items-center gap-2 extra-small">
                                                <?php if (!empty($user['avatar']) && file_exists(__DIR__ . '/' . $user['avatar'])): ?>
                                                    <img src="<?php echo htmlspecialchars($user['avatar']); ?>" class="rounded-circle shadow-sm" style="width: 18px; height: 18px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="avatar-xs bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 18px; height: 18px; font-size: 0.6rem;">
                                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span><?php echo htmlspecialchars($user['name']); ?></span>
                                                <form method="POST" class="m-0">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="action" value="remove_user">
                                                    <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="btn-close" style="font-size: 0.5rem;"></button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-2 bg-light rounded border border-dashed extra-small text-muted">Sin miembros</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form method="POST" class="input-group input-group-sm">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="add_user">
                            <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                            <select name="user_id" class="form-select" style="font-size: 0.75rem;" required>
                                <option value="">Añadir usuario...</option>
                                <?php 
                                $existing_ids = array_column($group_users[$group['id']], 'id');
                                foreach ($all_users as $user): 
                                    if (!in_array($user['id'], $existing_ids)):
                                ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                <?php 
                                    endif; 
                                endforeach; 
                                ?>
                            </select>
                            <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i></button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Modal Editar -->
            <div class="modal fade" id="editModal<?php echo $group['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content border-0 shadow">
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $group['id']; ?>">
                            <div class="modal-header bg-light border-0">
                                <h6 class="modal-title fw-bold"><i class="fas fa-edit me-2 text-primary"></i>Editar Grupo</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-3">
                                <div class="mb-3">
                                    <label class="form-label">Nombre del Grupo</label>
                                    <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($group['name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Descripción</label>
                                    <textarea name="description" class="form-control form-control-sm" rows="3"><?php echo htmlspecialchars($group['description']); ?></textarea>
                                </div>
                                <div class="form-check form-switch small">
                                    <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="active<?php echo $group['id']; ?>" <?php echo $group['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="active<?php echo $group['id']; ?>">Estado Activo</label>
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

        <?php if (empty($groups)): ?>
            <div class="col-12 text-center py-5">
                <img src="assets/img/empty-state.svg" alt="" style="width: 80px; opacity: 0.3;" class="mb-3">
                <h5 class="text-muted">No hay grupos configurados</h5>
                <button class="btn btn-sm btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#createModal">Crear Primer Grupo</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i>Nuevo Grupo</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label">Nombre del Grupo</label>
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Ej: Mesa de Ayuda" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Propósito del grupo..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-primary px-3">Crear Grupo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .group-card { border: 1px solid rgba(0,0,0,0.05) !important; transition: all 0.2s; }
    .group-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.08) !important; }
    .icon-circle-sm { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
    .extra-small { font-size: 0.7rem; }
</style>

<?php require_once 'includes/footer.php'; ?>
