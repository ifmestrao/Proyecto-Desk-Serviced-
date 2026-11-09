<?php
require_once 'includes/header.php';
checkAuth();
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'tech') {
    header("Location: dashboard.php");
    exit;
}
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';

$pageTitle = 'Herramientas de Configuración';

$success_msg = '';
$error_msg = '';


// Procesar actualización de SLAs y Gestión de Usuarios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error_msg = "Error de seguridad CSRF.";
    } else {
        try {
            $pdo->beginTransaction();

            if (isset($_POST['update_sla'])) {
                $tenant_id = get_tenant_id();
                foreach ($_POST['sla'] as $category_id => $data) {
                    // Validar que la categoría pertenezca al tenant
                    $stCheck = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND tenant_id = ?");
                    $stCheck->execute([$category_id, $tenant_id]);
                    if (!$stCheck->fetch()) continue;

                    $stmt = $pdo->prepare("INSERT INTO sla_config (category_id, resolution_time_hours, resolution_unit) 
                                           VALUES (?, ?, ?) 
                                           ON DUPLICATE KEY UPDATE resolution_time_hours = VALUES(resolution_time_hours),
                                                                   resolution_unit = VALUES(resolution_unit)");
                    $stmt->execute([
                        $category_id, 
                        $data['resolution'],
                        $data['resolution_unit']
                    ]);
                }
                $success_msg = "Configuración actualizada correctamente.";

                // Recalcular tickets abiertos si se solicita - FILTRADO POR TENANT
                if (isset($_POST['recalculate_open'])) {
                    $stmt = $pdo->prepare("SELECT t.id, t.category_id, t.priority, t.created_at, 
                                                s.resolution_time_hours, s.resolution_unit 
                                         FROM tickets t 
                                         LEFT JOIN sla_config s ON t.category_id = s.category_id 
                                         WHERE t.status IN ('open', 'pending') AND t.tenant_id = ?");
                    $stmt->execute([$tenant_id]);
                    $open_tickets = $stmt->fetchAll();
                    
                    $updated_count = 0;
                    $upd = $pdo->prepare("UPDATE tickets SET due_date = ? WHERE id = ? AND tenant_id = ?");
                    foreach ($open_tickets as $ticket) {
                        $val = $ticket['resolution_time_hours'] !== null ? (int)$ticket['resolution_time_hours'] : 48;
                        $unit = $ticket['resolution_unit'] ?? 'hours';
                        $hours = ($unit == 'days') ? ($val * 24) : $val;
                        
                        if ($ticket['priority'] == 'critical') $hours = max(1, round($hours * 0.25));
                        elseif ($ticket['priority'] == 'high') $hours = max(2, round($hours * 0.5));
                        elseif ($ticket['priority'] == 'low') $hours = round($hours * 1.5);
                        
                        $base_time = strtotime($ticket['created_at']);
                        $new_due = date('Y-m-d H:i:s', $base_time + ($hours * 3600));
                        if ($upd->execute([$new_due, $ticket['id'], $tenant_id])) $updated_count++;
                    }
                    $success_msg .= " Se han actualizado $updated_count tickets de tu instancia.";
                }
            }

            if (isset($_POST['add_category'])) {
                $cat_name = trim($_POST['category_name']);
                if (!empty($cat_name)) {
                    $tenant_id = get_tenant_id();
                    $stmt = $pdo->prepare("INSERT INTO categories (tenant_id, name) VALUES (?, ?)");
                    $stmt->execute([$tenant_id, $cat_name]);
                    $success_msg = "Categoría '$cat_name' agregada exitosamente.";
                }
            }

            // Nueva lógica: Editar Categoría
            if (isset($_POST['update_category'])) {
                $cid = (int)$_POST['edit_category_id'];
                $name = trim($_POST['edit_category_name']);
                $tenant_filter = tenant_where();
                if (!empty($name)) {
                    $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ? AND $tenant_filter");
                    $stmt->execute([$name, $cid]);
                    $success_msg = "Categoría actualizada correctamente.";
                }
            }

            // Nueva lógica: Eliminar Categoría
            if (isset($_POST['delete_category'])) {
                $cid = (int)$_POST['delete_category_id'];
                $tenant_filter = tenant_where();
                // Opcional: Verificar si hay tickets asociados antes de borrar
                $stCheck = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE category_id = ? AND $tenant_filter");
                $stCheck->execute([$cid]);
                if ($stCheck->fetchColumn() > 0) {
                    throw new Exception("No se puede eliminar la categoría porque tiene tickets asociados.");
                }
                $pdo->prepare("DELETE FROM categories WHERE id = ? AND $tenant_filter")->execute([$cid]);
                $success_msg = "Categoría eliminada.";
            }


            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Error: " . $e->getMessage();
        }
    }
}

// Obtener categorías y su configuración de SLA
$tenant_filter = tenant_where('c');
$stmt = $pdo->prepare("SELECT c.id, c.name, s.resolution_time_hours, s.resolution_unit 
                     FROM categories c 
                     LEFT JOIN sla_config s ON c.id = s.category_id 
                     WHERE $tenant_filter
                     ORDER BY c.name");
$stmt->execute();
$categories = $stmt->fetchAll();

?>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <p class="text-muted small mb-0">Parámetros globales y tiempos de respuesta (SLA). La gestión de usuarios está en su propia página.</p>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show p-2 small mb-3 border-0 shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
            <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show p-2 small mb-3 border-0 shadow-sm" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
            <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Panel de SLA -->
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-clock me-2 text-primary"></i>Tiempos de Vencimiento (SLA)</h6>
                    <button class="btn btn-sm btn-success px-3 rounded-pill" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus me-1"></i> Nueva Categoría
                    </button>
                </div>
                <div class="card-body p-0">
                    <form method="POST">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="update_sla" value="1">
                        
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                                    <tr>
                                        <th class="ps-4">Categoría</th>
                                        <th style="width: 250px;">Tiempo de Resolución</th>
                                        <th class="text-end pe-4">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">
                                                <span id="cat_name_<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></span>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="sla[<?php echo $cat['id']; ?>][resolution]" 
                                                           class="form-control text-center" min="1" max="1000" 
                                                           value="<?php echo $cat['resolution_time_hours'] ?? 48; ?>" style="max-width: 80px;">
                                                    <select name="sla[<?php echo $cat['id']; ?>][resolution_unit]" class="form-select bg-light">
                                                        <option value="hours" <?php echo ($cat['resolution_unit'] ?? 'hours') == 'hours' ? 'selected' : ''; ?>>Horas</option>
                                                        <option value="days" <?php echo ($cat['resolution_unit'] ?? 'hours') == 'days' ? 'selected' : ''; ?>>Días</option>
                                                    </select>
                                                </div>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="d-flex justify-content-end gap-1">
                                                    <button type="button" class="btn btn-action btn-edit" 
                                                            onclick="editCategory(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>')"
                                                            title="Editar Nombre">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-action btn-delete" 
                                                            onclick="deleteCategory(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>')"
                                                            title="Eliminar">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                            <div class="form-check form-switch small">
                                <input class="form-check-input" type="checkbox" name="recalculate_open" id="recalcCheck" value="1">
                                <label class="form-check-label text-muted extra-small fw-bold" for="recalcCheck">Recalcular tickets abiertos</label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">
                                <i class="fas fa-save me-2"></i>Guardar SLA
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm bg-primary bg-opacity-10 mb-3 border border-primary border-opacity-10">
                <div class="card-body p-3">
                    <h6 class="fw-bold mb-2 small text-primary"><i class="fas fa-info-circle me-2"></i>Gestión de Tiempos</h6>
                    <p class="extra-small text-muted mb-0">
                        El tiempo configurado define el plazo máximo de respuesta antes de que el ticket sea marcado como <b>Vencido (Breach)</b>. La prioridad del ticket ajusta proporcionalmente estos tiempos.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .extra-small { font-size: 0.75rem; }
    .btn-action { width: 32px; height: 32px; }
</style>

<!-- Modal: Agregar Categoría -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="add_category" value="1">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-folder-plus me-2"></i>Nueva Categoría</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre de la Categoría</label>
                        <input type="text" name="category_name" class="form-control" required placeholder="Ej: Software, Hardware, Redes...">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success px-4">Agregar Categoría</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Categoría -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="update_category" value="1">
                <input type="hidden" name="edit_category_id" id="edit_category_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Categoría</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre de la Categoría</label>
                        <input type="text" name="edit_category_name" id="edit_category_name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Formulario oculto para eliminar categoría -->
<form id="delete_category_form" method="POST" class="d-none">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="delete_category" value="1">
    <input type="hidden" name="delete_category_id" id="delete_category_id">
</form>

<script>
function editCategory(id, name) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function deleteCategory(id, name) {
    if (confirm(`¿Estás seguro de eliminar la categoría "${name}"?\nEsta acción no se puede deshacer y fallará si hay tickets asociados.`)) {
        document.getElementById('delete_category_id').value = id;
        document.getElementById('delete_category_form').submit();
    }
}

</script>

<?php require_once 'includes/footer.php'; ?>
