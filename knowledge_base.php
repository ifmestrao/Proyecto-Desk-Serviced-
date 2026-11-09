<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';

$pageTitle = 'Auto Servicio IA';
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$isStaff = ($role == 'admin' || $role == 'tech');

// Paginación para Gestión de Soluciones
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Fetch all categories (filtradas por tenant)
$tenant_filter = tenant_where();
$cats = $pdo->prepare("SELECT id, name FROM categories WHERE $tenant_filter ORDER BY name");
$cats->execute();
$cats = $cats->fetchAll();

// Fetch Knowledge Base entries for management (only for staff, filtradas por tenant)
$kb_entries = [];
$total_pages = 0;
if ($isStaff) {
    // Contar total (filtrado por tenant)
    $tenant_filter_kb = tenant_where('k');
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_base k WHERE $tenant_filter_kb");
    $total_stmt->execute();
    $total_rows = $total_stmt->fetchColumn();
    $total_pages = ceil($total_rows / $limit);

    $tenant_filter_c = tenant_where('c');
    $stmt = $pdo->prepare("SELECT k.*, c.name as category_name 
                           FROM knowledge_base k 
                           LEFT JOIN categories c ON k.category_id = c.id 
                           WHERE $tenant_filter_kb
                           ORDER BY k.created_at DESC 
                           LIMIT $limit OFFSET $offset");
    $stmt->execute();
    $kb_entries = $stmt->fetchAll();
}

// Handle Knowledge Base Management (Staff only)
if ($isStaff && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kb_action'])) {
    if (!csrf_check()) { die("CSRF token inválido"); }
    
    $kb_action = $_POST['kb_action'];
    $title = $_POST['title'] ?? '';
    $category_id = $_POST['category_id'] ?: null;
    $content = $_POST['content'] ?? '';
    $keywords = $_POST['keywords'] ?? '';
    $kb_id = $_POST['kb_id'] ?? null;
    $tenant_id = get_tenant_id();

    if ($kb_action == 'create') {
        $stmt = $pdo->prepare("INSERT INTO knowledge_base (tenant_id, title, category_id, content, keywords, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $title, $category_id, $content, $keywords, $user_id]);
    } elseif ($kb_action == 'update' && $kb_id) {
        $tenant_filter_kb = tenant_where();
        $stmt = $pdo->prepare("UPDATE knowledge_base SET title = ?, category_id = ?, content = ?, keywords = ? WHERE id = ? AND $tenant_filter_kb");
        $stmt->execute([$title, $category_id, $content, $keywords, $kb_id]);
    } elseif ($kb_action == 'delete' && $kb_id) {
        $tenant_filter_kb = tenant_where();
        $stmt = $pdo->prepare("DELETE FROM knowledge_base WHERE id = ? AND $tenant_filter_kb");
        $stmt->execute([$kb_id]);
    } elseif ($kb_action == 'import_csv' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($file['tmp_name'], "r");
            if ($handle) {
                // Detectar delimitador
                $firstLine = fgets($handle);
                $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
                rewind($handle);

                $headers = fgetcsv($handle, 0, $delimiter);
                if ($headers) {
                    $headers = array_map(function ($h) { return trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)); }, $headers); // quita BOM de Excel
                    
                    // Obtener categorías para mapeo (filtradas por tenant)
                    $tenant_filter = tenant_where();
                    $catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE $tenant_filter");
                    $catStmt->execute();
                    $categories = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    $categories = array_change_key_case(array_flip($categories), CASE_LOWER);

                    $import_count = 0;
                    $skipped_count = 0;
                    $dupStmt = $pdo->prepare("SELECT 1 FROM knowledge_base WHERE tenant_id = ? AND LOWER(title) = LOWER(?) LIMIT 1");
                    while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                        if (count($headers) !== count($row)) continue;
                        $data = array_combine($headers, $row);
                        
                        $title = $data['asunto_del_ticket'] ?? $data['ticket_subject'] ?? $data['subject'] ?? 'Ticket sin asunto';
                        $description = $data['descripción_del_ticket'] ?? $data['ticket_description'] ?? $data['description'] ?? '';
                        $resolution = $data['notas_de_resolución'] ?? $data['resolution_notes'] ?? $data['resolution'] ?? '';
                        $content = $description . ($resolution ? "\n\n**Solución:**\n" . $resolution : "");
                        
                        $kw_fields = ['categoría', 'subcategoría', 'etiquetas', 'departamento', 'prioridad', 'ubicación', 'tipo_de_dispositivo', 'category', 'subcategory', 'tags', 'department', 'priority', 'location', 'device_type'];
                        $kw_parts = [];
                        foreach ($kw_fields as $field) {
                            if (!empty($data[$field])) $kw_parts[] = trim($data[$field]);
                        }
                        $keywords = implode(', ', array_unique($kw_parts));
                        
                        $cat_name = strtolower(trim($data['categoría'] ?? $data['category'] ?? ''));
                        $category_id = null; // sin coincidencia: sin categoría (antes se asignaba el id 10 de otro tenant)
                        foreach ($categories as $name => $id) {
                            if ($cat_name && (strpos($cat_name, strtolower($name)) !== false || strpos(strtolower($name), $cat_name) !== false)) {
                                $category_id = $id;
                                break;
                            }
                        }
                        
                        $title = trim($title);
                        if ($title === '' || $title === 'Ticket sin asunto') { $skipped_count++; continue; }
                        $dupStmt->execute([$tenant_id, $title]);
                        if ($dupStmt->fetchColumn()) { $skipped_count++; continue; }

                        $created_at_raw = $data['fecha_de_creación'] ?? $data['created_datetime'] ?? date('Y-m-d H:i:s');
                        $created_at = date('Y-m-d H:i:s', strtotime($created_at_raw));

                        try {
                            $stmt = $pdo->prepare("INSERT INTO knowledge_base (tenant_id, title, content, keywords, category_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$tenant_id, $title, $content, $keywords, $category_id, $user_id, $created_at]);
                            $import_count++;
                        } catch (Exception $e) {}
                    }
                    $_SESSION['flash_message'] = "Importación completada. Se añadieron $import_count registros" . ($skipped_count ? " ($skipped_count omitidos: duplicados o sin título)." : ".");
                }
                fclose($handle);
            }
        }
    }
    header("Location: knowledge_base.php");
    exit;
}
?>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4">
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3 shadow-sm border-0" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['flash_message']; unset($_SESSION['flash_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <div class="row my-4">
        <!-- Chatbot Section (Everyone) -->
        <div class="col-lg-<?php echo $isStaff ? '5' : '12'; ?>">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-robot me-2"></i>Chatbot</h6>
                    <div class="d-flex gap-2">
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="fas fa-clock-rotate-left me-1"></i>Historial</button>
                            <ul class="dropdown-menu dropdown-menu-end shadow" id="chat-history-menu" style="min-width: 300px; max-height: 320px; overflow-y: auto;"></ul>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="chat-new-btn"><i class="fas fa-plus me-1"></i>Nuevo chat</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="chat-container" class="chat-box" style="height: 450px; overflow-y: auto; background: #fff; padding: 20px;"></div>
                    <div class="p-3 border-top bg-light">
                        <form id="chat-form" enctype="multipart/form-data" autocomplete="off">
                            <div id="chat-chips" class="mb-1" style="display: none;"></div>
                            <div class="input-group shadow-sm">
                                <button type="button" class="btn btn-outline-secondary" id="chat-attach-btn" title="Adjuntar archivos (imágenes, PDF, documentos)" aria-label="Adjuntar archivos"><i class="fas fa-paperclip"></i></button>
                                <input type="file" id="chat-file" class="d-none">
                                <input type="text" id="chat-input" class="form-control border-0 py-2 px-3" placeholder="Escribe tu duda aquí..." maxlength="1500" autocomplete="off">
                                <button class="btn btn-primary px-4" type="submit" id="send-btn" aria-label="Enviar"><i class="fas fa-paper-plane"></i></button>
                            </div>
                            <small class="text-muted d-block mt-1">Puedes adjuntar capturas, PDF y documentos (hasta 5 archivos de 8 MB); también arrastrándolos al chat o pegando una captura.</small>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isStaff): ?>
        <!-- Management Section (Staff only) -->
        <div class="col-lg-7">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-database me-2"></i>Gestión de Soluciones</h6>
                    <div class="btn-group">
                        <a href="ai_knowledge.php" class="btn btn-sm btn-primary me-2"><i class="fas fa-brain me-1"></i>Centro de conocimiento IA</a>
                        <button class="btn btn-sm btn-outline-info me-2" data-bs-toggle="modal" data-bs-target="#importCSVModal">
                            <i class="fas fa-file-import me-1"></i>Importar CSV
                        </button>
                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addSolutionModal">
                            <i class="fas fa-plus me-1"></i>Nueva Solución
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-bordered table-hover mb-0" id="kbTable" width="100%" cellspacing="0">
                            <thead class="bg-light sticky-top" style="z-index: 10;">
                                <tr>
                                    <th>Título</th>
                                    <th>Categoría</th>
                                    <th>Palabras Clave</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($kb_entries)): ?>
                                    <tr><td colspan="4" class="text-center py-4">No hay soluciones registradas.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($kb_entries as $kb): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($kb['title']); ?></strong></td>
                                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($kb['category_name'] ?: 'General'); ?></span></td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($kb['keywords']); ?></small></td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-primary" onclick="editKB(<?php echo htmlspecialchars(json_encode($kb)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteKB(<?php echo $kb['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Paginación KB -->
                    <?php if ($total_pages > 1): ?>
                        <div class="mt-3">
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-sm justify-content-center mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page-1; ?>"><i class="fas fa-chevron-left"></i></a>
                                    </li>
                                    
                                    <?php 
                                    $range = 2; // Número de páginas a mostrar a los lados de la actual
                                    $start = max(1, $page - $range);
                                    $end = min($total_pages, $page + $range);

                                    if ($start > 1): ?>
                                        <li class="page-item"><a class="page-link" href="?page=1">1</a></li>
                                        <?php if ($start > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for($i = $start; $i <= $end; $i++): ?>
                                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php if ($end < $total_pages): ?>
                                        <?php if ($end < $total_pages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a></li>
                                    <?php endif; ?>

                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page+1; ?>"><i class="fas fa-chevron-right"></i></a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Importar CSV -->
<div class="modal fade" id="importCSVModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="kb_action" value="import_csv">
                <div class="modal-header bg-light py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-file-csv text-info me-2"></i>Importar desde CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Seleccionar archivo CSV</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                        <small class="text-muted d-block mt-2">
                            El archivo debe estar delimitado por comas (,) o punto y coma (;).<br>
                            Columnas esperadas: <strong>asunto_del_ticket</strong>, <strong>descripción_del_ticket</strong>, etc.
                        </small>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info px-4">Importar Registros</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Añadir Solución -->
<div class="modal fade" id="addSolutionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="kb_action" value="create">
                <div class="modal-header bg-light py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle text-success me-2"></i>Nueva Solución para IA</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Problema Común</label>
                        <input type="text" name="title" class="form-control" required placeholder="Escribe aquí problema común...">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Categoría</label>
                        <select name="category_id" class="form-select" required>
                            <option value="" disabled selected>Seleccione...</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Solución Detallada</label>
                        <textarea name="content" class="form-control" rows="5" required placeholder="Escribe aquí los pasos de la solución..."></textarea>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold">Palabras Claves</label>
                        <input type="text" name="keywords" class="form-control" placeholder="Escribe aquí palabras claves...">
                        <small class="text-muted">Separa las palabras con comas.</small>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success px-4">Guardar Solución</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Solución -->
<div class="modal fade" id="editSolutionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="kb_action" value="update">
                <input type="hidden" name="kb_id" id="edit_kb_id">
                <div class="modal-header bg-light py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-2"></i>Editar Solución</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Problema</label>
                        <input type="text" name="title" id="edit_kb_title" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Categoría</label>
                        <select name="category_id" id="edit_kb_category" class="form-select" required>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Solución Detallada</label>
                        <textarea name="content" id="edit_kb_content" class="form-control" rows="5" required></textarea>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold">Palabras Claves</label>
                        <input type="text" name="keywords" id="edit_kb_keywords" class="form-control">
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">Actualizar Solución</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/desk_chat.js?v=3"></script>
<script>
function editKB(entry) {
    document.getElementById('edit_kb_id').value = entry.id;
    document.getElementById('edit_kb_title').value = entry.title;
    document.getElementById('edit_kb_category').value = entry.category_id || '';
    document.getElementById('edit_kb_content').value = entry.content;
    document.getElementById('edit_kb_keywords').value = entry.keywords;
    new bootstrap.Modal(document.getElementById('editSolutionModal')).show();
}

const deskChat = new DeskChat({
    endpoint: 'chatbot_handler.php',
    csrf: '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>',
    container: document.getElementById('chat-container'),
    form: document.getElementById('chat-form'),
    input: document.getElementById('chat-input'),
    sendBtn: document.getElementById('send-btn'),
    fileInput: document.getElementById('chat-file'),
    attachBtn: document.getElementById('chat-attach-btn'),
    chipsBox: document.getElementById('chat-chips'),
    historyMenu: document.getElementById('chat-history-menu'),
    newBtn: document.getElementById('chat-new-btn'),
    botBubble: 'bg-light border text-dark',
    greeting: '¡Hola! Soy tu asistente de soporte. Cuéntame qué está pasando (qué equipo o aplicación y qué error ves) y busco la solución en la base de conocimientos y en los documentos de tu empresa.'
});
deskChat.loadCurrent();

    function deleteKB(id) {
        if (confirm('¿Seguro que quieres eliminar esta solución?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="kb_action" value="delete">
                <input type="hidden" name="kb_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php require_once 'includes/footer.php'; ?>
