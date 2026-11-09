<?php
require_once 'includes/header.php';
checkAuth();
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'tech') {
    header("Location: dashboard.php");
    exit;
}
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';

$pageTitle = 'Gestión de Usuarios';

$success_msg = '';
$error_msg = '';

function table_has_column(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

// Asegurar columnas de perfil en la tabla users
try {
    if (!table_has_column($pdo, 'users', 'avatar')) { $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL"); }
    if (!table_has_column($pdo, 'users', 'area')) { $pdo->exec("ALTER TABLE users ADD COLUMN area VARCHAR(100) NULL"); }
    if (!table_has_column($pdo, 'users', 'is_active')) { $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1"); }
    if (!table_has_column($pdo, 'users', 'local_id')) { 
        // Add local_id column, and set default values based on id
        $pdo->exec("ALTER TABLE users ADD COLUMN local_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id"); 
        // Now populate it for existing users
        $pdo->exec("UPDATE users SET local_id = id WHERE local_id = 0"); 
    }
} catch (Throwable $e) {}

// Procesar alta, edición y eliminación de usuarios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error_msg = "Error de seguridad CSRF.";
    } else {
        try {
            $pdo->beginTransaction();

            // Nueva lógica: Actualizar Usuario
            if (isset($_POST['update_user'])) {
                $uid = (int)$_POST['edit_user_id'];
                $name = trim($_POST['edit_name']);
                $email = trim($_POST['edit_email']);
                $role = $_POST['edit_role'];
                $area = trim($_POST['edit_area']);
                $position = trim($_POST['edit_position'] ?? '');
                $phone = trim($_POST['edit_phone'] ?? '');
                $is_active = isset($_POST['edit_active']) ? 1 : 0;
                $new_password = $_POST['edit_password'] ?? '';
                $tenant_id = get_tenant_id();

                $upd = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, area = ?, position = ?, phone = ?, is_active = ? WHERE id = ? AND tenant_id = ?");
                $upd->execute([$name, $email, $role, $area, $position, $phone, $is_active, $uid, $tenant_id]);

                if (!empty($new_password)) {
                    $password_err = validate_password_strength($new_password);
                    if ($password_err !== true) throw new Exception($password_err);
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND tenant_id = ?")->execute([$hash, $uid, $tenant_id]);
                }

                // Manejo de Avatar
                if (isset($_FILES['edit_avatar']) && $_FILES['edit_avatar']['name'] !== '') {
                    if ($_FILES['edit_avatar']['error'] !== UPLOAD_ERR_OK) {
                        $error_msg_upload = "Error en la subida del avatar: ";
                        switch ($_FILES['edit_avatar']['error']) {
                            case UPLOAD_ERR_INI_SIZE: $error_msg_upload .= "Excede tamaño del servidor."; break;
                            case UPLOAD_ERR_FORM_SIZE: $error_msg_upload .= "Excede tamaño del formulario."; break;
                            default: $error_msg_upload .= "Error desconocido."; break;
                        }
                        throw new Exception($error_msg_upload);
                    }

                    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
                    
                    if (!is_dir($uploadDir)) {
                        if (!mkdir($uploadDir, 0777, true)) {
                            throw new Exception("Error: No se pudo crear el directorio de destino en $uploadDir");
                        }
                    }

                    if (!is_writable($uploadDir)) {
                        // Intentar dar permisos (en Windows esto es limitado pero no sobra)
                        @chmod($uploadDir, 0777);
                        if (!is_writable($uploadDir)) {
                            throw new Exception("Error: El directorio de destino no tiene permisos de escritura en $uploadDir");
                        }
                    }
                    
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($_FILES['edit_avatar']['tmp_name']);
                    
                    if (!in_array($mimeType, $allowedTypes)) {
                        throw new Exception("Avatar: Tipo de archivo no permitido.");
                    }

                    $ext = pathinfo($_FILES['edit_avatar']['name'], PATHINFO_EXTENSION);
                    $filename = 'avatar_' . $uid . '_' . time() . '.' . $ext;
                    $dest = $uploadDir . $filename;
                    $relativeDest = 'uploads/avatars/' . $filename;

                    if (move_uploaded_file($_FILES['edit_avatar']['tmp_name'], $dest)) {
                        // Eliminar avatar anterior
                        $stOld = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                        $stOld->execute([$uid]);
                        $oldAvatar = $stOld->fetchColumn();
                        if ($oldAvatar && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $oldAvatar)) {
                            @unlink(__DIR__ . DIRECTORY_SEPARATOR . $oldAvatar);
                        }

                        $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ? AND tenant_id = ?")->execute([$relativeDest, $uid, $tenant_id]);
                    } else {
                        throw new Exception("Error al guardar el archivo del avatar.");
                    }
                }
                $success_msg = "Usuario actualizado correctamente.";
            }

            // Nueva lógica: Eliminar Usuario
            if (isset($_POST['delete_user'])) {
                $uid = (int)$_POST['delete_user_id'];
                $tenant_id = get_tenant_id();
                // No permitir auto-eliminación
                if ($uid === (int)$_SESSION['user_id']) throw new Exception("No puedes eliminar tu propia cuenta.");
                
                // Limpiar dependencias antes de eliminar el usuario para evitar errores de FK
                try {
                    // 1. Notificaciones
                    $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND tenant_id = ?")->execute([$uid, $tenant_id]);
                    
                    // 2. Asignaciones de técnicos en tickets
                    // Para ticket_techs y otros que no tengan tenant_id directamente, usamos subquery o join con tabla tickets/users que sí tenga tenant_id
                    $pdo->prepare("DELETE tt FROM ticket_techs tt JOIN tickets t ON tt.ticket_id = t.id WHERE tt.tech_id = ? AND t.tenant_id = ?")->execute([$uid, $tenant_id]);
                    $pdo->prepare("UPDATE tickets SET assigned_tech_id = NULL WHERE assigned_tech_id = ? AND tenant_id = ?")->execute([$uid, $tenant_id]);
                    
                    // 3. Asignaciones en cronograma
                    $pdo->prepare("DELETE ca FROM cronograma_assignees ca JOIN cronograma c ON ca.event_id = c.id WHERE ca.tech_id = ? AND c.tenant_id = ?")->execute([$uid, $tenant_id]);
                    $pdo->prepare("UPDATE cronograma SET assigned_to = NULL WHERE assigned_to = ? AND tenant_id = ?")->execute([$uid, $tenant_id]);
                    
                    // 4. Adjuntos (y archivos físicos)
                    $stAtt = $pdo->prepare("SELECT file_path FROM attachments WHERE user_id = ? AND tenant_id = ?");
                    $stAtt->execute([$uid, $tenant_id]);
                    while ($f = $stAtt->fetch()) {
                        if (!empty($f['file_path']) && file_exists($f['file_path'])) {
                            @unlink($f['file_path']);
                        }
                    }
                    $pdo->prepare("DELETE FROM attachments WHERE user_id = ? AND tenant_id = ?")->execute([$uid, $tenant_id]);
                    
                    // 5. Adjuntos de cronograma
                    $stCrAtt = $pdo->prepare("SELECT file_path FROM cronograma_attachments WHERE user_id = ? AND tenant_id = ?");
                    $stCrAtt->execute([$uid, $tenant_id]);
                    while ($f = $stCrAtt->fetch()) {
                        if (!empty($f['file_path']) && file_exists($f['file_path'])) {
                            @unlink($f['file_path']);
                        }
                    }
                    $pdo->prepare("DELETE FROM cronograma_attachments WHERE user_id = ? AND tenant_id = ?")->execute([$uid, $tenant_id]);

                    // 6. Mensajes
                    $pdo->prepare("DELETE m FROM messages m JOIN tickets t ON m.ticket_id = t.id WHERE m.user_id = ? AND t.tenant_id = ?")->execute([$uid, $tenant_id]);
                    
                    // 7. Tickets creados
                     $pdo->prepare("DELETE FROM tickets WHERE user_id = ? AND tenant_id = ?")->execute([$uid, $tenant_id]);

                     // 8. Actas (Documentos)
                     $pdo->prepare("DELETE FROM actas WHERE (user_id = ? OR created_by = ?) AND tenant_id = ?")->execute([$uid, $uid, $tenant_id]);

                     // Finalmente eliminar el usuario
                     $pdo->prepare("DELETE FROM users WHERE id = ? AND tenant_id = ?")->execute([$uid, $tenant_id]);
                    $success_msg = "Usuario y todos sus registros relacionados eliminados correctamente.";
                } catch (PDOException $e) {
                    throw new Exception("Error al limpiar registros del usuario: " . $e->getMessage());
                }
            }

            if (isset($_POST['create_user'])) {
                $name = isset($_POST['user_name']) ? trim((string)$_POST['user_name']) : '';
                $email = isset($_POST['user_email']) ? trim((string)$_POST['user_email']) : '';
                $area = isset($_POST['user_area']) ? trim((string)$_POST['user_area']) : '';
                $password = isset($_POST['user_password']) ? (string)$_POST['user_password'] : '';
                $confirm_password = isset($_POST['user_password_confirm']) ? (string)$_POST['user_password_confirm'] : '';
                $role = isset($_POST['user_role']) ? (string)$_POST['user_role'] : 'user';
                
                if ($name === '' || $email === '' || $password === '') {
                    throw new Exception("Completa todos los campos del usuario.");
                }

                // Validaciones de contraseña robusta
                if ($password !== $confirm_password) {
                    throw new Exception("Las contraseñas no coinciden.");
                }

                if (strlen($password) < 8) throw new Exception("La contraseña debe tener al menos 8 caracteres.");
                if (preg_match_all('/[0-9]/', $password) < 2) throw new Exception("La contraseña debe tener al menos 2 números.");
                if (!preg_match('/[@$!%*?&]/', $password)) throw new Exception("La contraseña debe tener al menos 1 carácter especial (@$!%*?&).");
                if (!preg_match('/[A-Z]/', $password)) throw new Exception("La contraseña debe tener al menos 1 letra mayúscula.");
                if (!preg_match('/[a-z]/', $password)) throw new Exception("La contraseña debe tener al menos 1 letra minúscula.");

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("El correo no es válido.");
                }
                if (!in_array($role, ['user', 'tech', 'supervisor', 'admin'], true)) {
                    throw new Exception("Rol no válido.");
                }

                $tenant_id = get_tenant_id();
                $tenant_filter = tenant_where();
                $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND $tenant_filter");
                $st->execute([$email]);
                if ($st->fetchColumn()) {
                    throw new Exception("El correo ya está registrado.");
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM users WHERE tenant_id = ?");
                $st_local->execute([$tenant_id]);
                $next_user_local_id = (int)$st_local->fetchColumn();

                $ins = $pdo->prepare("INSERT INTO users (tenant_id, local_id, name, email, area, password, role, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
                $ins->execute([$tenant_id, $next_user_local_id, $name, $email, $area, $hash, $role]);

                $success_msg = "Usuario registrado: " . htmlspecialchars($name) . " (" . htmlspecialchars($email) . ").";
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Error: " . $e->getMessage();
        }
    }
}

/**
 * Valida que la contraseña cumpla con las políticas de seguridad.
 * @param string $password
 * @return string|true Retorna true si es válida, o un mensaje de error si no.
 */
function validate_password_strength($password) {
    if (strlen($password) < 8) return "La contraseña debe tener al menos 8 caracteres.";
    if (preg_match_all('/[0-9]/', $password) < 2) return "La contraseña debe tener al menos 2 números.";
    if (!preg_match('/[@$!%*?&]/', $password)) return "La contraseña debe tener al menos 1 carácter especial (@$!%*?&).";
    if (!preg_match('/[A-Z]/', $password)) return "La contraseña debe tener al menos 1 letra mayúscula.";
    if (!preg_match('/[a-z]/', $password)) return "La contraseña debe tener al menos 1 letra minúscula.";
    return true;
}

$users = [];
if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'supervisor' || $_SESSION['role'] === 'tech')) {
    // Simplificamos la consulta asumiendo que las columnas ya existen (se aseguran al inicio del archivo)
    $orderBy = 'ORDER BY id DESC';
    
    // Filtros de búsqueda para gestión de usuarios
    $whereUsers = [];
    $paramsUsers = [];
    $whereUsers[] = tenant_where();
    if (isset($_GET['user_q']) && !empty($_GET['user_q'])) {
        $term = '%' . $_GET['user_q'] . '%';
        $whereUsers[] = "(name LIKE ? OR email LIKE ? OR area LIKE ?)";
        array_push($paramsUsers, $term, $term, $term);
    }
    
    $whereSql = $whereUsers ? "WHERE " . implode(" AND ", $whereUsers) : "";
    // Seleccionamos todas las columnas necesarias directamente
    $st = $pdo->prepare("SELECT id, local_id, name, email, role, avatar, area, position, phone, is_active, created_at FROM users $whereSql $orderBy LIMIT 100");
    $st->execute($paramsUsers);
    $users = $st->fetchAll() ?: [];
}

?>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <p class="text-muted small mb-0">Alta, edición y baja de usuarios, roles y áreas.</p>
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

    <div class="card border-0 shadow-sm mt-1 mb-4">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-users-cog me-2 text-primary"></i>Gestión de Usuarios</h6>
            <div class="d-flex gap-2">
                <div class="input-group input-group-sm" style="width: 250px;">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="user_search_input" class="form-control bg-light border-start-0" placeholder="Filtrar por nombre o área..." onkeyup="liveSearchUsers()">
                </div>
                <button class="btn btn-primary btn-sm px-3 rounded-pill" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-user-plus me-1"></i> Registrar
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0 small" id="users_table">
                    <thead class="bg-light text-muted text-uppercase" style="font-size: 0.7rem;">
                        <tr>
                            <th class="ps-4">Usuario / Identificación</th>
                            <th>Información de Contacto</th>
                            <th>Perfil / Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">No se encontraron usuarios registrados.</td></tr>
                        <?php else: foreach ($users as $u): ?>
                            <tr class="<?php echo $u['is_active'] ? '' : 'bg-light opacity-75'; ?>">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-xs bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 38px; height: 38px; font-weight: bold;">
                                            <?php if ($u['avatar']): ?>
                                                <img src="<?php echo htmlspecialchars($u['avatar']); ?>" class="rounded-circle border" width="38" height="38" style="object-fit: cover;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($u['name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($u['name']); ?></div>
                                            <div class="text-muted extra-small">ID #<?php echo $u['local_id'] ?? $u['id']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-dark fw-bold" style="font-size: 0.8rem;"><?php echo htmlspecialchars($u['email']); ?></div>
                                    <div class="text-muted extra-small"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($u['area'] ?: 'General'); ?></div>
                                </td>
                                <td>
                                    <?php
                                        $roleLabel = $u['role'] === 'admin' ? 'Administrador' : ($u['role'] === 'supervisor' ? 'Supervisor' : ($u['role'] === 'tech' ? 'Técnico' : 'Usuario'));
                                        $roleClass = $u['role'] === 'admin' ? 'bg-dark' : ($u['role'] === 'supervisor' ? 'bg-info text-dark' : ($u['role'] === 'tech' ? 'bg-primary' : 'bg-secondary'));
                                    ?>
                                    <span class="badge <?php echo $roleClass; ?> bg-opacity-10 <?php echo str_replace('bg-', 'text-', $roleClass); ?> border border-opacity-10 rounded-pill mb-1 d-inline-block" style="font-size: 0.6rem;">
                                        <?php echo strtoupper($roleLabel); ?>
                                    </span>
                                    <div class="d-block">
                                        <?php if ($u['is_active']): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill" style="font-size: 0.6rem;">ACTIVO</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill" style="font-size: 0.6rem;">INACTIVO</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <button type="button" class="btn btn-action btn-edit" onclick='editUser(<?php echo json_encode($u); ?>)' title="Editar"><i class="fas fa-edit"></i></button>
                                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Confirmas la eliminación total? Se perderán todos los datos vinculados.');">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="delete_user" value="1">
                                                <input type="hidden" name="delete_user_id" value="<?php echo $u['id']; ?>">
                                                <button type="submit" class="btn btn-action btn-delete" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .extra-small { font-size: 0.75rem; }
    .btn-action { width: 32px; height: 32px; }
</style>

<!-- Modal: Registrar Usuario -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST" autocomplete="off">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="create_user" value="1">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Registrar usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre</label>
                        <input type="text" name="user_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Correo</label>
                        <input type="email" name="user_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Área</label>
                        <input type="text" name="user_area" class="form-control" placeholder="Contabilidad...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Contraseña</label>
                        <input type="password" name="user_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Confirmar Contraseña</label>
                        <input type="password" name="user_password_confirm" class="form-control" required>
                    </div>
                    <div class="alert alert-light border p-2 mb-3" style="font-size: 0.75rem;">
                        <div class="fw-bold mb-1 text-muted">Requisitos:</div>
                        <ul class="list-unstyled mb-0 ps-2">
                            <li><i class="fas fa-check-circle me-1 text-primary"></i> Mínimo 8 caracteres</li>
                            <li><i class="fas fa-check-circle me-1 text-primary"></i> 2 números, 1 especial, Mayús/Min</li>
                        </ul>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold">Rol</label>
                        <select name="user_role" class="form-select" required>
                            <option value="user">Usuario</option>
                            <option value="tech">Técnico</option>
                            <option value="supervisor">Supervisor Técnico</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Usuario -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="update_user" value="1">
                <input type="hidden" name="edit_user_id" id="edit_user_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-edit me-2"></i>Editar Perfil de Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4 text-center border-end">
                            <div class="mb-3">
                                <img id="edit_avatar_preview" src="" class="rounded-circle border shadow-sm mb-2 d-none" width="120" height="120" style="object-fit: cover;">
                                <div id="edit_avatar_placeholder" class="bg-primary text-white rounded-circle align-items-center justify-content-center fw-bold mx-auto mb-2 shadow-sm d-none" style="width: 120px; height: 120px; font-size: 3rem;"></div>
                                <label class="btn btn-sm btn-outline-primary mt-2">
                                    <i class="fas fa-camera me-1"></i> Cambiar Foto
                                    <input type="file" name="edit_avatar" class="d-none" onchange="previewAvatar(this)">
                                </label>
                            </div>
                            <div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input me-2" type="checkbox" name="edit_active" id="edit_active" checked>
                                <label class="form-check-label fw-bold" for="edit_active">Usuario Activo</label>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Nombre Completo</label>
                                    <input type="text" name="edit_name" id="edit_name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Correo Electrónico</label>
                                    <input type="email" name="edit_email" id="edit_email" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Teléfono</label>
                                    <input type="text" name="edit_phone" id="edit_phone" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Cargo</label>
                                    <input type="text" name="edit_position" id="edit_position" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Área</label>
                                    <input type="text" name="edit_area" id="edit_area" class="form-control" placeholder="Contabilidad...">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Rol en el Sistema</label>
                                    <select name="edit_role" id="edit_role" class="form-select" required>
                                        <option value="user">Usuario</option>
                                        <option value="tech">Técnico</option>
                                        <option value="supervisor">Supervisor</option>
                                        <option value="admin">Administrador</option>
                                    </select>
                                </div>
                                <div class="col-12 mt-4">
                                    <div class="card bg-light border-0">
                                        <div class="card-body p-3">
                                            <h6 class="fw-bold text-danger mb-2 small"><i class="fas fa-key me-1"></i>Cambiar Contraseña</h6>
                                            <input type="password" name="edit_password" class="form-control form-control-sm mb-2" placeholder="Nueva contraseña">
                                            <div class="text-muted" style="font-size: 0.65rem;">Dejar en blanco para mantener la actual. Debe cumplir con las políticas de seguridad.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Actualizar Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_name').value = user.name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_phone').value = user.phone || '';
    document.getElementById('edit_position').value = user.position || '';
    document.getElementById('edit_area').value = user.area || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_active').checked = user.is_active == 1;
    
    const preview = document.getElementById('edit_avatar_preview');
    const placeholder = document.getElementById('edit_avatar_placeholder');
    
    if (user.avatar) {
        preview.src = user.avatar;
        preview.classList.remove('d-none');
        placeholder.classList.add('d-none');
        placeholder.style.display = 'none';
    } else {
        preview.classList.add('d-none');
        placeholder.classList.remove('d-none');
        placeholder.style.display = 'flex';
        placeholder.textContent = user.name.charAt(0).toUpperCase();
    }
    
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

function previewAvatar(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('edit_avatar_preview');
            const placeholder = document.getElementById('edit_avatar_placeholder');
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            placeholder.classList.add('d-none');
            placeholder.style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function liveSearchUsers() {
    const input = document.getElementById('user_search_input');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('users_table');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        const td = tr[i].getElementsByTagName('td');
        let found = false;
        for (let j = 0; j < td.length - 1; j++) { // Exclude actions column
            if (td[j]) {
                const txtValue = td[j].textContent || td[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }
        tr[i].style.display = found ? "" : "none";
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
