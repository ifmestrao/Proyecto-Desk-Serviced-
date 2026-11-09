<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'tech')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$tenant_id = get_tenant_id();

    if ($action === 'get_fields') {
    header('Content-Type: application/json');
    $type_id = (int)($_GET['type_id'] ?? 0);
    $asset_id = (int)($_GET['asset_id'] ?? 0);
    
    // Obtener campos definidos para el tipo (filtrado por tenant)
    $stmt = $pdo->prepare("SELECT * FROM asset_fields WHERE type_id = ? AND tenant_id = ?");
    $stmt->execute([$type_id, $tenant_id]);
    $fields = $stmt->fetchAll();
    
    // Si hay un asset_id, obtener los valores guardados
    $values = [];
    if ($asset_id) {
        $vstmt = $pdo->prepare("SELECT field_id, field_value FROM asset_field_values WHERE asset_id = ? AND tenant_id = ?");
        $vstmt->execute([$asset_id, $tenant_id]);
        $values = $vstmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    // Combinar campos con sus valores
    foreach ($fields as &$f) {
        $f['value'] = $values[$f['id']] ?? '';
    }
    
    echo json_encode($fields);
    exit;
}

if ($action === 'get_asset') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $tenant_filter_a = tenant_where('a');
    $tenant_filter_t = tenant_where('t');
    $stmt = $pdo->prepare("SELECT a.*, t.name as type_name, u.name as user_name 
                           FROM assets a 
                           JOIN asset_types t ON a.type_id = t.id 
                           LEFT JOIN users u ON a.user_id = u.id 
                           WHERE a.id = ? AND $tenant_filter_a AND $tenant_filter_t");
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    
    if ($asset) {
        // Obtener campos dinámicos y sus valores
        $vstmt = $pdo->prepare("SELECT f.field_label, v.field_value 
                                FROM asset_field_values v 
                                JOIN asset_fields f ON v.field_id = f.id 
                                WHERE v.asset_id = ? AND v.tenant_id = ? AND f.tenant_id = ?");
        $vstmt->execute([$id, $tenant_id, $tenant_id]);
        $asset['dynamic_fields'] = $vstmt->fetchAll();
    }
    
    echo json_encode($asset);
    exit;
}

if ($action === 'get_fields_config') {
    $type_id = (int)($_GET['type_id'] ?? 0);
    $type = $pdo->prepare("SELECT * FROM asset_types WHERE id = ? AND tenant_id = ?");
    $type->execute([$type_id, $tenant_id]);
    $t = $type->fetch();
    
    $stmt = $pdo->prepare("SELECT * FROM asset_fields WHERE type_id = ? AND tenant_id = ?");
    $stmt->execute([$type_id, $tenant_id]);
    $fields = $stmt->fetchAll();
    ?>
    <div class="p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
            <div>
                <h5 class="fw-bold mb-0"><i class="<?php echo $t['icon']; ?> me-2"></i> Configurar: <?php echo htmlspecialchars($t['name']); ?></h5>
                <small class="text-muted">Define los campos específicos para este tipo de activo.</small>
            </div>
            <button class="btn btn-sm btn-primary" onclick="addFieldToType(<?php echo $t['id']; ?>)">
                <i class="fas fa-plus me-1"></i> Agregar Campo
            </button>
        </div>
        
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>Etiqueta</th>
                        <th>Nombre Interno</th>
                        <th>Tipo</th>
                        <th>Requerido</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fields)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">No hay campos configurados.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fields as $f): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($f['field_label']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($f['field_name']); ?></td>
                                <td><?php echo $f['field_type']; ?></td>
                                <td><?php echo $f['required'] ? '<span class="badge bg-danger">Sí</span>' : '<span class="badge bg-light text-dark border">No</span>'; ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteField(<?php echo $f['id']; ?>, <?php echo $t['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        try {
            $pdo->beginTransaction();
            
            $type_id = (int)($_POST['type_id'] ?? 0);
            if (!$type_id) throw new Exception("Debe seleccionar un tipo de activo.");

            $serial = trim($_POST['serial_number'] ?? '');
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model'] ?? '');
            
            // Corregir user_id: asegurar que sea null si no es un número válido > 0
            $user_id = (isset($_POST['user_id']) && is_numeric($_POST['user_id']) && (int)$_POST['user_id'] > 0) 
                       ? (int)$_POST['user_id'] 
                       : null;
            
            $status = $_POST['status'] ?? 'active';
            $notes = trim($_POST['notes'] ?? '');

            if (empty($serial)) throw new Exception("El número de serial es obligatorio.");

            // Verificar duplicado antes de insertar (filtrado por tenant)
            $check = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE serial_number = ? AND tenant_id = ?");
            $check->execute([$serial, $tenant_id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("El número de serial '$serial' ya está registrado.");
            }

            // Insertar Activo Principal con tenant_id
            $stmt = $pdo->prepare("INSERT INTO assets (tenant_id, type_id, user_id, serial_number, brand, model, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $type_id, $user_id, $serial, $brand, $model, $status, $notes]);
            $asset_id = $pdo->lastInsertId();

            // Insertar Campos Dinámicos con tenant_id
            if (!empty($_POST['dynamic_fields']) && is_array($_POST['dynamic_fields'])) {
                $ins = $pdo->prepare("INSERT INTO asset_field_values (tenant_id, asset_id, field_id, field_value) VALUES (?, ?, ?, ?)");
                foreach ($_POST['dynamic_fields'] as $field_id => $value) {
                    if (trim($value) !== '') {
                        $ins->execute([$tenant_id, $asset_id, (int)$field_id, $value]);
                    }
                }
            }

            $pdo->commit();
            header("Location: inventario.php?success=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header("Location: inventario.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    if ($action === 'update') {
        try {
            $pdo->beginTransaction();
            
            $id = (int)$_POST['id'];
            $type_id = (int)$_POST['type_id'];
            $serial = trim($_POST['serial_number'] ?? '');
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $user_id = (isset($_POST['user_id']) && is_numeric($_POST['user_id']) && (int)$_POST['user_id'] > 0) ? (int)$_POST['user_id'] : null;
            $status = $_POST['status'] ?? 'active';
            $notes = trim($_POST['notes'] ?? '');

            if (empty($serial)) throw new Exception("El número de serial es obligatorio.");

            // Verificar duplicado antes de actualizar (filtrado por tenant)
            $check = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE serial_number = ? AND id != ? AND tenant_id = ?");
            $check->execute([$serial, $id, $tenant_id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("El número de serial '$serial' ya está registrado en otro activo.");
            }

            // Actualizar Activo Principal (filtrado por tenant)
            $stmt = $pdo->prepare("UPDATE assets SET type_id = ?, user_id = ?, serial_number = ?, brand = ?, model = ?, status = ?, notes = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$type_id, $user_id, $serial, $brand, $model, $status, $notes, $id, $tenant_id]);

            // Actualizar Campos Dinámicos: Primero borrar antiguos (filtrado por tenant)
            $del = $pdo->prepare("DELETE FROM asset_field_values WHERE asset_id = ? AND tenant_id = ?");
            $del->execute([$id, $tenant_id]);

            // Luego insertar los nuevos con tenant_id
            if (!empty($_POST['dynamic_fields']) && is_array($_POST['dynamic_fields'])) {
                $ins = $pdo->prepare("INSERT INTO asset_field_values (tenant_id, asset_id, field_id, field_value) VALUES (?, ?, ?, ?)");
                foreach ($_POST['dynamic_fields'] as $field_id => $value) {
                    if (trim($value) !== '') {
                        $ins->execute([$tenant_id, $id, (int)$field_id, $value]);
                    }
                }
            }

            $pdo->commit();
            header("Location: inventario.php?success=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header("Location: inventario.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    if ($action === 'add_type') {
        header('Content-Type: application/json');
        $name = trim($_POST['name']);
        $icon = $_POST['icon'] ?: 'fas fa-box';

        // Validar que el nombre no se repita en la misma instancia
        $check = $pdo->prepare("SELECT COUNT(*) FROM asset_types WHERE name = ? AND tenant_id = ?");
        $check->execute([$name, $tenant_id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Ya existe un tipo de activo con el nombre "' . $name . '" en esta instancia.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO asset_types (tenant_id, name, icon) VALUES (?, ?, ?)");
        $res = $stmt->execute([$tenant_id, $name, $icon]);
        echo json_encode(['success' => $res]);
        exit;
    }

    if ($action === 'update_type') {
        header('Content-Type: application/json');
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $icon = $_POST['icon'] ?: 'fas fa-box';

        // Validar que el nombre no se repita en la misma instancia (excluyendo el actual)
        $check = $pdo->prepare("SELECT COUNT(*) FROM asset_types WHERE name = ? AND id != ? AND tenant_id = ?");
        $check->execute([$name, $id, $tenant_id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Ya existe otro tipo de activo con el nombre "' . $name . '" en esta instancia.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE asset_types SET name = ?, icon = ? WHERE id = ? AND tenant_id = ?");
        $res = $stmt->execute([$name, $icon, $id, $tenant_id]);
        echo json_encode(['success' => $res]);
        exit;
    }

    if ($action === 'delete_type') {
        header('Content-Type: application/json');
        $id = (int)$_POST['id'];
        // Verificar si hay activos de este tipo (filtrado por tenant)
        $check = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE type_id = ? AND tenant_id = ?");
        $check->execute([$id, $tenant_id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'No se puede eliminar un tipo que tiene activos registrados.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM asset_types WHERE id = ? AND tenant_id = ?");
        $res = $stmt->execute([$id, $tenant_id]);
        echo json_encode(['success' => $res]);
        exit;
    }

    if ($action === 'delete_asset') {
        header('Content-Type: application/json');
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ? AND tenant_id = ?");
        $res = $stmt->execute([$id, $tenant_id]);
        echo json_encode(['success' => $res]);
        exit;
    }

    if ($action === 'add_field') {
        header('Content-Type: application/json');
        $type_id = (int)$_POST['type_id'];
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['field_name']));
        $label = $_POST['field_label'];
        $stmt = $pdo->prepare("INSERT INTO asset_fields (tenant_id, type_id, field_name, field_label) VALUES (?, ?, ?, ?)");
        $res = $stmt->execute([$tenant_id, $type_id, $name, $label]);
        echo json_encode(['success' => $res]);
        exit;
    }

    if ($action === 'check_serial') {
        header('Content-Type: application/json');
        $serial = trim($_GET['serial'] ?? '');
        $exclude_id = (int)($_GET['exclude_id'] ?? 0);
        
        $sql = "SELECT COUNT(*) FROM assets WHERE serial_number = ? AND tenant_id = ?";
        $params = [$serial, $tenant_id];
        
        if ($exclude_id > 0) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exists = $stmt->fetchColumn() > 0;
        
        echo json_encode(['exists' => $exists]);
        exit;
    }
}
?>
