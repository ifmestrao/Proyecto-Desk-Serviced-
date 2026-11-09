<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';

$pageTitle = 'Inventario de Activos';

// Filtros
$type_filter = $_GET['type'] ?? '';
$search = $_GET['q'] ?? '';

// Paginación
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Obtener tipos de activos para el filtro y modales
try {
    $col = $pdo->query("SHOW COLUMNS FROM asset_types LIKE 'tenant_id'")->fetch();
    if ($col) {
        $tenant_filter_t = tenant_where('t');
        $types_stmt = $pdo->prepare("SELECT * FROM asset_types t WHERE $tenant_filter_t ORDER BY name");
        $types_stmt->execute();
        $types = $types_stmt->fetchAll();
    } else {
        $types = $pdo->query("SELECT * FROM asset_types ORDER BY name")->fetchAll();
    }
} catch (Exception $e) {
    $types = $pdo->query("SELECT * FROM asset_types ORDER BY name")->fetchAll();
}

// Consulta para contar total (para paginación)
try {
    $col_a = $pdo->query("SHOW COLUMNS FROM assets LIKE 'tenant_id'")->fetch();
    $col_u = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'")->fetch();
    $col_t = $pdo->query("SHOW COLUMNS FROM asset_types LIKE 'tenant_id'")->fetch();
    
    if ($col_a && $col_u && $col_t) {
        $tenant_filter_a = tenant_where('a');
        $tenant_filter_t = tenant_where('t');
        $tenant_filter_u = "(u.tenant_id = " . get_tenant_id() . " OR u.tenant_id IS NULL)";
        $count_sql = "SELECT COUNT(*) FROM assets a LEFT JOIN users u ON a.user_id = u.id JOIN asset_types t ON a.type_id = t.id WHERE $tenant_filter_a AND $tenant_filter_u AND $tenant_filter_t";
    } else {
        $count_sql = "SELECT COUNT(*) FROM assets a LEFT JOIN users u ON a.user_id = u.id LEFT JOIN asset_types t ON a.type_id = t.id WHERE 1=1";
    }
} catch (Exception $e) {
    $count_sql = "SELECT COUNT(*) FROM assets a LEFT JOIN users u ON a.user_id = u.id LEFT JOIN asset_types t ON a.type_id = t.id WHERE 1=1";
}

$params = [];

if ($type_filter) {
    $count_sql .= " AND a.type_id = ?";
    $params[] = $type_filter;
}
if ($search) {
    $count_sql .= " AND (a.serial_number LIKE ? OR a.brand LIKE ? OR a.model LIKE ? OR u.name LIKE ? OR t.name LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

$total_stmt = $pdo->prepare($count_sql);
$total_stmt->execute($params);
$total_rows = $total_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Consulta principal de activos con LIMIT y OFFSET
try {
    $col_a = $pdo->query("SHOW COLUMNS FROM assets LIKE 'tenant_id'")->fetch();
    $col_u = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'")->fetch();
    $col_t = $pdo->query("SHOW COLUMNS FROM asset_types LIKE 'tenant_id'")->fetch();
    
    if ($col_a && $col_u && $col_t) {
        $tenant_filter_a = tenant_where('a');
        $tenant_filter_t = tenant_where('t');
        $tenant_filter_u = "(u.tenant_id = " . get_tenant_id() . " OR u.tenant_id IS NULL)";
        $sql = "SELECT a.*, t.name as type_name, t.icon as type_icon, u.name as assigned_user_name, u.avatar as assigned_user_avatar
                FROM assets a 
                JOIN asset_types t ON a.type_id = t.id 
                LEFT JOIN users u ON a.user_id = u.id 
                WHERE $tenant_filter_a AND $tenant_filter_u AND $tenant_filter_t";
    } else {
        $sql = "SELECT a.*, t.name as type_name, t.icon as type_icon, u.name as assigned_user_name, u.avatar as assigned_user_avatar
                FROM assets a 
                JOIN asset_types t ON a.type_id = t.id 
                LEFT JOIN users u ON a.user_id = u.id 
                WHERE 1=1";
    }
} catch (Exception $e) {
    $sql = "SELECT a.*, t.name as type_name, t.icon as type_icon, u.name as assigned_user_name, u.avatar as assigned_user_avatar
            FROM assets a 
            JOIN asset_types t ON a.type_id = t.id 
            LEFT JOIN users u ON a.user_id = u.id 
            WHERE 1=1";
}

if ($type_filter) {
    $sql .= " AND a.type_id = ?";
}
if ($search) {
    $sql .= " AND (a.serial_number LIKE ? OR a.brand LIKE ? OR a.model LIKE ? OR u.name LIKE ? OR t.name LIKE ?)";
}

$sql .= " ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll();

?>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-light border shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#manageTypesModal">
                <i class="fas fa-cog me-2 text-primary"></i>Configurar Tipos
            </button>
            <button class="btn btn-primary btn-sm shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                <i class="fas fa-plus me-2"></i>Nuevo Activo
            </button>
        </div>
    </div>

    <!-- Filtros Compactos -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-2">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control bg-light border-start-0" placeholder="Serial, Marca, Modelo..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="type" class="form-select form-select-sm bg-light">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $type_filter == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filtrar</button>
                </div>
                <div class="col-md-2">
                    <a href="inventario.php" class="btn btn-sm btn-light border w-100">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de Activos Moderna -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4">Tipo de Activo</th>
                            <th>Identificación (S/N)</th>
                            <th>Especificaciones</th>
                            <th>Asignación</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <img src="assets/img/empty-state.svg" alt="" style="width: 60px; opacity: 0.3;" class="mb-3 d-block mx-auto">
                                    <p>No se encontraron activos en el inventario.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assets as $asset): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <div class="icon-circle-sm bg-primary bg-opacity-10 text-primary me-2" style="width: 32px; height: 32px; font-size: 0.9rem; border-radius: 8px;">
                                                <i class="<?php echo $asset['type_icon']; ?>"></i>
                                            </div>
                                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($asset['type_name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($asset['serial_number'] ?: 'S/N'); ?></div>
                                        <div class="text-muted extra-small"><?php echo htmlspecialchars($asset['brand']); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-dark"><?php echo htmlspecialchars($asset['model']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($asset['assigned_user_name']): ?>
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($asset['assigned_user_avatar']) && file_exists(__DIR__ . '/' . $asset['assigned_user_avatar'])): ?>
                                                    <img src="<?php echo htmlspecialchars($asset['assigned_user_avatar']); ?>" class="rounded-circle me-2 shadow-sm" style="width: 24px; height: 24px; object-fit: cover;">
                                                <?php else: ?>
                                                    <i class="far fa-user-circle me-2 text-muted"></i>
                                                <?php endif; ?>
                                                <span><?php echo htmlspecialchars($asset['assigned_user_name']); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border fw-normal">Disponible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $status_classes = [
                                            'active' => 'bg-success',
                                            'maintenance' => 'bg-warning',
                                            'retired' => 'bg-danger'
                                        ];
                                        $status_labels = [
                                            'active' => 'Activo',
                                            'maintenance' => 'Mantenimiento',
                                            'retired' => 'Retirado'
                                        ];
                                        $s = $asset['status'];
                                        ?>
                                        <span class="badge <?php echo $status_classes[$s]; ?> bg-opacity-10 <?php echo str_replace('bg-', 'text-', $status_classes[$s]); ?> rounded-pill border border-opacity-10" style="font-size: 0.65rem;">
                                            <?php echo strtoupper($status_labels[$s]); ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-1">
                                            <button class="btn btn-action btn-edit" onclick="viewAsset(<?php echo $asset['id']; ?>)" data-bs-toggle="modal" data-bs-target="#viewAssetModal" title="Ver"><i class="fas fa-eye"></i></button>
                                            <button class="btn btn-action btn-edit" onclick="editAsset(<?php echo $asset['id']; ?>)" data-bs-toggle="modal" data-bs-target="#editAssetModal" title="Editar"><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-action btn-delete" onclick="deleteAsset(<?php echo $asset['id']; ?>, '<?php echo addslashes($asset['serial_number']); ?>')" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white border-0 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Página <?php echo $page; ?> de <?php echo $total_pages; ?></small>
                    <div class="btn-group shadow-sm">
                        <a class="btn btn-xs btn-light border <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&type=<?php echo $type_filter; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a class="btn btn-xs btn-light border <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&type=<?php echo $type_filter; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .extra-small { font-size: 0.75rem; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
    .icon-circle-sm { display: flex; align-items: center; justify-content: center; border-radius: 10px; }
</style>

<!-- Modal: Agregar Activo -->
<div class="modal fade" id="addAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form id="addAssetForm" action="inventario_actions.php" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i>Nuevo Activo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tipo de Activo</label>
                            <select name="type_id" id="asset_type_select" class="form-select" required onchange="loadDynamicFields(this.value, 'dynamic-fields-container')">
                                <option value="" disabled selected>Selecciona un tipo...</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Serial</label>
                            <input type="text" name="serial_number" id="asset_serial_input" class="form-control" required placeholder="Número único de identificación">
                            <div id="serial_error_msg" class="text-danger small mt-1 d-none">
                                <i class="fas fa-exclamation-circle me-1"></i> Este serial ya existe.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Marca</label>
                            <input type="text" name="brand" class="form-control" required placeholder="Marca del activo">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Modelo</label>
                            <input type="text" name="model" class="form-control" required placeholder="Modelo del activo">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Asignar a Usuario</label>
                            <div class="position-relative">
                                <input type="text" id="user_search_input" class="form-control" placeholder="Buscar usuario" autocomplete="off">
                                <input type="hidden" name="user_id" id="selected_user_id" value="">
                                <div id="user_search_results" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 1050; max-height: 150px; overflow-y: auto;"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Estado Inicial</label>
                            <select name="status" class="form-select">
                                <option value="active">Activo</option>
                                <option value="maintenance">En Mantenimiento</option>
                                <option value="retired">Retirado</option>
                            </select>
                        </div>
                        
                        <!-- Contenedor de Campos Dinámicos -->
                        <div class="col-12 mt-4">
                            <h6 class="border-bottom pb-2 fw-bold text-primary">Información Técnica Específica</h6>
                            <div id="dynamic-fields-container" class="row g-3">
                                <div class="col-12 text-center py-3 text-muted">
                                    <small>Selecciona un tipo de activo para ver campos adicionales.</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Observaciones</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Guardar Activo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Configurar Tipos y Campos (Paleta de herramientas) -->
<div class="modal fade" id="manageTypesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-tools me-2"></i>Paleta de Herramientas de Inventario</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="row g-0" style="min-height: 500px;">
                    <!-- Lista de Tipos -->
                    <div class="col-md-4 border-end bg-light p-4">
                        <h6 class="fw-bold mb-3 d-flex justify-content-between align-items-center">
                            Tipos de Activos
                            <button class="btn btn-sm btn-primary" onclick="showAddTypeForm()">
                                <i class="fas fa-plus"></i>
                            </button>
                        </h6>
                        <div class="list-group shadow-sm" id="types-list">
                            <?php foreach ($types as $t): ?>
                                <div class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-0">
                                    <button class="btn btn-link text-decoration-none text-dark flex-grow-1 text-start p-3" onclick="loadTypeFields(<?php echo $t['id']; ?>)">
                                        <i class="<?php echo $t['icon']; ?> me-2 text-muted"></i> <?php echo htmlspecialchars($t['name']); ?>
                                    </button>
                                    <div class="pe-3 d-flex gap-1">
                                        <button class="btn btn-sm btn-outline-secondary border-0" onclick="showEditTypeForm(<?php echo $t['id']; ?>, '<?php echo addslashes($t['name']); ?>', '<?php echo $t['icon']; ?>')" title="Editar Tipo">
                                            <i class="fas fa-edit small"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger border-0" onclick="deleteType(<?php echo $t['id']; ?>, '<?php echo addslashes($t['name']); ?>')" title="Eliminar Tipo">
                                            <i class="fas fa-trash small"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Configuración de Campos -->
                    <div class="col-md-8 p-4">
                        <div id="type-fields-config" class="d-flex flex-column h-100 justify-content-center align-items-center text-muted">
                            <i class="fas fa-mouse-pointer fa-3x mb-3"></i>
                            <p>Selecciona un tipo de activo para configurar sus campos dinámicos.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Ver Detalles del Activo -->
<div class="modal fade" id="viewAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-info-circle me-2"></i>Detalles del Activo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="viewAssetBody">
                <!-- Se llena por JS -->
            </div>
            <div class="modal-footer bg-light" id="viewAssetFooter">
                <!-- Botón de Mantenimiento se inyecta por JS -->
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Editar Activo -->
<div class="modal fade" id="editAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form id="editAssetForm" action="inventario_actions.php" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_asset_id">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Editar Activo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tipo de Activo</label>
                            <select name="type_id" id="edit_type_id" class="form-select" required onchange="loadDynamicFields(this.value, 'edit-dynamic-fields-container', document.getElementById('edit_asset_id').value)">
                                <?php foreach ($types as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Serial</label>
                            <input type="text" name="serial_number" id="edit_serial_number" class="form-control" required>
                            <div id="edit_serial_error_msg" class="text-danger small mt-1 d-none">
                                <i class="fas fa-exclamation-circle me-1"></i> Este serial ya existe en otro activo.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Marca</label>
                            <input type="text" name="brand" id="edit_brand" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Modelo</label>
                            <input type="text" name="model" id="edit_model" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Asignar a Usuario</label>
                            <div class="position-relative">
                                <input type="text" id="edit_user_search_input" class="form-control" placeholder="Buscar usuario...">
                                <input type="hidden" name="user_id" id="edit_selected_user_id">
                                <div id="edit_user_search_results" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 1050;"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Estado</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Activo</option>
                                <option value="maintenance">Mantenimiento</option>
                                <option value="retired">Retirado</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mt-4">
                            <h6 class="border-bottom pb-2 fw-bold text-primary">Información Técnica Específica</h6>
                            <div id="edit-dynamic-fields-container" class="row g-3"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Notas</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-dark px-4">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Función para cargar campos dinámicos al seleccionar un tipo
function loadDynamicFields(typeId, containerId, assetId = 0) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
    
    fetch(`inventario_actions.php?action=get_fields&type_id=${typeId}&asset_id=${assetId}`)
        .then(r => r.json())
        .then(fields => {
            container.innerHTML = '';
            if (fields.length === 0) {
                container.innerHTML = '<div class="col-12 text-center py-3 text-muted"><small>No hay campos específicos para este tipo.</small></div>';
                return;
            }
            fields.forEach(f => {
                const col = document.createElement('div');
                col.className = 'col-md-6';
                col.innerHTML = `
                    <label class="form-label fw-bold">${f.field_label}${f.required ? ' <span class="text-danger">*</span>' : ''}</label>
                    <input type="${f.field_type}" name="dynamic_fields[${f.id}]" class="form-control" ${f.required ? 'required' : ''} value="${f.value || ''}">
                `;
                container.appendChild(col);
            });
        });
}

function viewAsset(id) {
    console.log('Viewing asset:', id);
    const body = document.getElementById('viewAssetBody');
    body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    
    fetch(`inventario_actions.php?action=get_asset&id=${id}`)
        .then(r => r.json())
        .then(asset => {
            let html = `
                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Tipo de Activo</small>
                    <p class="mb-0 fw-bold fs-5 text-primary">${asset.type_name}</p>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Serial</small>
                        <p class="mb-0">${asset.serial_number || 'S/N'}</p>
                    </div>
                    <div class="col-6">
                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Marca / Modelo</small>
                        <p class="mb-0">${asset.brand} - ${asset.model}</p>
                    </div>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Asignado a</small>
                    <p class="mb-0">${asset.user_name || '<span class="text-success">Disponible</span>'}</p>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Estado</small>
                    <p class="mb-0 text-capitalize">${asset.status}</p>
                </div>
                <div class="mt-4 pt-3 border-top">
                    <h6 class="fw-bold text-primary mb-3">Información Técnica</h6>
                    <div class="row g-2">
            `;
            
            if (asset.dynamic_fields && asset.dynamic_fields.length > 0) {
                asset.dynamic_fields.forEach(f => {
                    html += `
                        <div class="col-12 d-flex justify-content-between border-bottom pb-1">
                            <span class="text-muted small">${f.field_label}:</span>
                            <span class="fw-bold small">${f.field_value}</span>
                        </div>
                    `;
                });
            } else {
                html += '<p class="text-muted small">No hay información adicional.</p>';
            }
            
            html += `
                    </div>
                </div>
                <div class="mt-4">
                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Notas</small>
                    <p class="small bg-light p-2 rounded">${asset.notes || 'Sin observaciones'}</p>
                </div>
            `;
            body.innerHTML = html;
            
            const footer = document.getElementById('viewAssetFooter');
            if (footer) {
                const btnHtml = `
                    <a href="cronograma.php?add_task=1&cat=Actividad&title=${encodeURIComponent('Mantenimiento Preventivo: ' + (asset.brand || 'Equipo') + ' ' + (asset.model || ''))}&desc=${encodeURIComponent('Mantenimiento programado para el equipo con serial: ' + (asset.serial_number || 'S/N') + '.\nUsuario asignado: ' + (asset.user_name || 'Ninguno') + '.')}" class="btn btn-warning text-dark me-auto">
                        <i class="fas fa-tools me-1"></i> Programar Mantenimiento
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                `;
                footer.innerHTML = btnHtml;
            }
        });
}

function editAsset(id) {
    fetch(`inventario_actions.php?action=get_asset&id=${id}`)
        .then(r => r.json())
        .then(asset => {
            document.getElementById('edit_asset_id').value = asset.id;
            document.getElementById('edit_type_id').value = asset.type_id;
            document.getElementById('edit_serial_number').value = asset.serial_number;
            document.getElementById('edit_brand').value = asset.brand;
            document.getElementById('edit_model').value = asset.model;
            document.getElementById('edit_status').value = asset.status;
            document.getElementById('edit_notes').value = asset.notes;
            
            // Usuario asignado
            document.getElementById('edit_user_search_input').value = asset.user_name || '';
            document.getElementById('edit_selected_user_id').value = asset.user_id || '';
            
            // Cargar campos dinámicos
            loadDynamicFields(asset.type_id, 'edit-dynamic-fields-container', asset.id);
        });
}

// Búsqueda de usuarios para asignación (Crear)
document.addEventListener('DOMContentLoaded', function() {
    function setupUserSearch(inputId, hiddenId, resultsId) {
        const input = document.getElementById(inputId);
        const hidden = document.getElementById(hiddenId);
        const results = document.getElementById(resultsId);

        if (!input) return;

        input.addEventListener('input', function() {
            const query = this.value.trim();
            if (query === '') {
                hidden.value = '';
                results.classList.add('d-none');
                return;
            }
            if (query.length < 2) {
                results.classList.add('d-none');
                return;
            }

            fetch(`search_users.php?q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(users => {
                    results.innerHTML = '';
                    if (users.length > 0) {
                        users.forEach(u => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'list-group-item list-group-item-action py-2';
                            btn.innerHTML = `<div class="fw-bold small">${u.name}</div><div class="text-muted small">${u.email}</div>`;
                            btn.onclick = () => {
                                console.log('User selected:', u.id, u.name);
                                input.value = u.name;
                                hidden.value = u.id;
                                results.classList.add('d-none');
                            };
                            results.appendChild(btn);
                        });
                        results.classList.remove('d-none');
                    } else {
                        results.classList.add('d-none');
                    }
                });
        });

        document.addEventListener('click', e => {
            if (!input.contains(e.target) && !results.contains(e.target)) {
                results.classList.add('d-none');
            }
        });
    }

    setupUserSearch('user_search_input', 'selected_user_id', 'user_search_results');
    setupUserSearch('edit_user_search_input', 'edit_selected_user_id', 'edit_user_search_results');

    // Validación de Serial en tiempo real (Creación)
    const serialInput = document.getElementById('asset_serial_input');
    const serialError = document.getElementById('serial_error_msg');
    const addBtn = document.querySelector('#addAssetForm button[type="submit"]');

    if (serialInput) {
        serialInput.addEventListener('blur', function() {
            const serial = this.value.trim();
            if (serial === '') return;

            fetch(`inventario_actions.php?action=check_serial&serial=${encodeURIComponent(serial)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.exists) {
                        serialInput.classList.add('is-invalid');
                        serialError.classList.remove('d-none');
                        addBtn.disabled = true;
                    } else {
                        serialInput.classList.remove('is-invalid');
                        serialError.classList.add('d-none');
                        addBtn.disabled = false;
                    }
                });
        });
    }

    // Validación de Serial en tiempo real (Edición)
    const editSerialInput = document.getElementById('edit_serial_number');
    const editSerialError = document.getElementById('edit_serial_error_msg');
    const editAssetId = document.getElementById('edit_asset_id');
    const editBtn = document.querySelector('#editAssetForm button[type="submit"]');

    if (editSerialInput) {
        editSerialInput.addEventListener('blur', function() {
            const serial = this.value.trim();
            const id = editAssetId.value;
            if (serial === '') return;

            fetch(`inventario_actions.php?action=check_serial&serial=${encodeURIComponent(serial)}&exclude_id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.exists) {
                        editSerialInput.classList.add('is-invalid');
                        editSerialError.classList.remove('d-none');
                        editBtn.disabled = true;
                    } else {
                        editSerialInput.classList.remove('is-invalid');
                        editSerialError.classList.add('d-none');
                        editBtn.disabled = false;
                    }
                });
        });
    }
});

// Funciones para la "Paleta de Herramientas" (Configuración de tipos y campos)
function loadTypeFields(typeId) {
    const configDiv = document.getElementById('type-fields-config');
    configDiv.innerHTML = '<div class="spinner-border text-primary"></div>';
    
    fetch(`inventario_actions.php?action=get_fields_config&type_id=${typeId}`)
        .then(r => r.text())
        .then(html => {
            configDiv.innerHTML = html;
            configDiv.className = 'h-100';
        });
}

function showAddTypeForm() {
    const configDiv = document.getElementById('type-fields-config');
    configDiv.className = 'h-100';
    configDiv.innerHTML = `
        <div class="p-4 w-100">
            <h5 class="fw-bold mb-4">Agregar Nuevo Tipo de Activo</h5>
            <form id="addTypeForm" onsubmit="event.preventDefault(); saveNewType();">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre del Tipo</label>
                    <input type="text" id="new_type_name" class="form-control" required placeholder="Monitor, UPS, Servidor...">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Icono de FontAwesome</label>
                    <input type="text" id="new_type_icon" class="form-control" value="fas fa-box" placeholder="Ej: fas fa-desktop">
                    <div class="mt-2">
                        <small class="text-muted d-block mb-2">Selecciona un icono:</small>
                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                            <div class="row g-1">
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-desktop', 'new_type_icon')">
                                        <i class="fas fa-desktop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-laptop', 'new_type_icon')">
                                        <i class="fas fa-laptop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-tablet-alt', 'new_type_icon')">
                                        <i class="fas fa-tablet-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-mobile-alt', 'new_type_icon')">
                                        <i class="fas fa-mobile-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-server', 'new_type_icon')">
                                        <i class="fas fa-server"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-hdd', 'new_type_icon')">
                                        <i class="fas fa-hdd"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-print', 'new_type_icon')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-keyboard', 'new_type_icon')">
                                        <i class="fas fa-keyboard"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-mouse', 'new_type_icon')">
                                        <i class="fas fa-mouse"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-headphones', 'new_type_icon')">
                                        <i class="fas fa-headphones"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-camera', 'new_type_icon')">
                                        <i class="fas fa-camera"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-phone-alt', 'new_type_icon')">
                                        <i class="fas fa-phone-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-wifi', 'new_type_icon')">
                                        <i class="fas fa-wifi"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-tv', 'new_type_icon')">
                                        <i class="fas fa-tv"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-box', 'new_type_icon')">
                                        <i class="fas fa-box"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-tools', 'new_type_icon')">
                                        <i class="fas fa-tools"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-battery-full', 'new_type_icon')">
                                        <i class="fas fa-battery-full"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Crear Tipo</button>
                    <button type="button" class="btn btn-light" onclick="location.reload()">Cancelar</button>
                </div>
            </form>
        </div>
    `;
}

function selectIcon(iconClass, inputId) {
    document.getElementById(inputId).value = iconClass;
}

function saveNewType() {
    const name = document.getElementById('new_type_name').value;
    const icon = document.getElementById('new_type_icon').value;
    
    const formData = new FormData();
    formData.append('action', 'add_type');
    formData.append('name', name);
    formData.append('icon', icon);
    
    fetch('inventario_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) location.reload();
            else alert(res.error || 'Error al crear el tipo');
        });
}

function addFieldToType(typeId) {
    const name = prompt("Nombre técnico del campo (sin espacios, ej: cap_disco):");
    if (!name) return;
    const label = prompt("Etiqueta visible del campo (ej: Capacidad de Disco):");
    if (!label) return;
    
    const formData = new FormData();
    formData.append('action', 'add_field');
    formData.append('type_id', typeId);
    formData.append('field_name', name);
    formData.append('field_label', label);
    
    fetch('inventario_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) loadTypeFields(typeId);
            else alert(res.error || 'Error al agregar campo');
        });
}

function showEditTypeForm(id, name, icon) {
    const configDiv = document.getElementById('type-fields-config');
    configDiv.className = 'h-100';
    configDiv.innerHTML = `
        <div class="p-4 w-100">
            <h5 class="fw-bold mb-4">Editar Tipo de Activo</h5>
            <form id="editTypeForm" onsubmit="event.preventDefault(); updateType(${id});">
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre del Tipo</label>
                    <input type="text" id="edit_type_name" class="form-control" required value="${name}">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Icono de FontAwesome</label>
                    <input type="text" id="edit_type_icon" class="form-control" value="${icon}">
                    <div class="mt-2">
                        <small class="text-muted d-block mb-2">Selecciona un icono:</small>
                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                            <div class="row g-1">
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-desktop', 'edit_type_icon')">
                                        <i class="fas fa-desktop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-laptop', 'edit_type_icon')">
                                        <i class="fas fa-laptop"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-tablet-alt', 'edit_type_icon')">
                                        <i class="fas fa-tablet-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-mobile-alt', 'edit_type_icon')">
                                        <i class="fas fa-mobile-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-server', 'edit_type_icon')">
                                        <i class="fas fa-server"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-hdd', 'edit_type_icon')">
                                        <i class="fas fa-hdd"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-print', 'edit_type_icon')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-keyboard', 'edit_type_icon')">
                                        <i class="fas fa-keyboard"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-mouse', 'edit_type_icon')">
                                        <i class="fas fa-mouse"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-headphones', 'edit_type_icon')">
                                        <i class="fas fa-headphones"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-camera', 'edit_type_icon')">
                                        <i class="fas fa-camera"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-phone-alt', 'edit_type_icon')">
                                        <i class="fas fa-phone-alt"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-wifi', 'edit_type_icon')">
                                        <i class="fas fa-wifi"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-tv', 'edit_type_icon')">
                                        <i class="fas fa-tv"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-box', 'edit_type_icon')">
                                        <i class="fas fa-box"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-tools', 'edit_type_icon')">
                                        <i class="fas fa-tools"></i>
                                    </button>
                                </div>
                                <div class="col-2 text-center">
                                    <button type="button" class="btn btn-outline-secondary w-100" onclick="selectIcon('fas fa-battery-full', 'edit_type_icon')">
                                        <i class="fas fa-battery-full"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    <button type="button" class="btn btn-light" onclick="location.reload()">Cancelar</button>
                </div>
            </form>
        </div>
    `;
}

function updateType(id) {
    const name = document.getElementById('edit_type_name').value;
    const icon = document.getElementById('edit_type_icon').value;
    
    const formData = new FormData();
    formData.append('action', 'update_type');
    formData.append('id', id);
    formData.append('name', name);
    formData.append('icon', icon);
    
    fetch('inventario_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) location.reload();
            else alert(res.error || 'Error al actualizar el tipo');
        });
}

function deleteType(id, name) {
    if (confirm(`¿Estás seguro de eliminar el tipo "${name}"?\nEsta acción no se puede deshacer y fallará si hay activos de este tipo.`)) {
        const formData = new FormData();
        formData.append('action', 'delete_type');
        formData.append('id', id);
        
        fetch('inventario_actions.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) location.reload();
                else alert(res.error || 'Error al eliminar el tipo');
            });
    }
}

function deleteAsset(id, serial) {
    if (confirm(`¿Estás seguro de eliminar el activo con serial "${serial}"?\nEsta acción no se puede deshacer.`)) {
        const formData = new FormData();
        formData.append('action', 'delete_asset');
        formData.append('id', id);
        
        fetch('inventario_actions.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) location.reload();
                else alert(res.error || 'Error al eliminar el activo');
            });
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>