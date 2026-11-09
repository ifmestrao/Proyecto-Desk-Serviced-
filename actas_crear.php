<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';

$pageTitle = 'Nueva Acta';
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        die("CSRF validation failed");
    }

    $type = $_POST['type'];
    $title = trim($_POST['title']);
    $target_user_id = (int)$_POST['user_id'];
    $asset_id = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
    $details = trim($_POST['details']);

    // Get User Details for the email
    $stU = $pdo->prepare("SELECT name, email, position FROM users WHERE id = ?");
    $stU->execute([$target_user_id]);
    $target_user = $stU->fetch();

    // Get Asset Details for the email
    $asset_info = "";
    if ($asset_id) {
        $stA = $pdo->prepare("SELECT serial_number, brand, model FROM assets WHERE id = ?");
        $stA->execute([$asset_id]);
        $asset = $stA->fetch();
        if ($asset) {
            $asset_info = "Equipo: " . $asset['brand'] . " " . $asset['model'] . " (S/N: " . $asset['serial_number'] . ")\n";
        }
    }

    // Build Content (JSON for future proofing or simple text for now)
    $content = [
        'details' => $details,
        'user_name' => $target_user['name'],
        'user_position' => $target_user['position'],
        'asset_info' => $asset_info
    ];
    $json_content = json_encode($content);

    try {
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $stmt = $pdo->prepare("INSERT INTO actas (type, title, user_id, asset_id, content, created_by, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$type, $title, $target_user_id, $asset_id, $json_content, $user_id, $tenant_id]);
        $acta_id = $pdo->lastInsertId();

        // Notification to User
        notify_add($pdo, $target_user_id, 'new_acta', "Nueva Acta Generada", "Se ha generado una nueva acta de $type para ti.", "acta_view.php?id=$acta_id", 'acta', $acta_id);

        // Send Email
        $subj = "Nueva Acta de $type: $title";
        $body = "Hola " . $target_user['name'] . ",\n\nSe ha generado una nueva acta de " . ucfirst($type) . " para tu revisión y firma digital.\n\n" .
                "Detalles del Documento:\n" .
                "- Título: $title\n" .
                "- " . ($asset_id ? "Equipo: " . $asset['brand'] . " " . $asset['model'] : "Tipo: Entrega") . "\n\n" .
                "Puedes ver y aprobar el acta ingresando al siguiente enlace:\n" .
                "http://" . $_SERVER['HTTP_HOST'] . "/acta_view.php?id=$acta_id\n\n" .
                "Gracias,\nEquipo de Desk Service.";

        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        @send_mail($target_user['email'], $subj, nl2br(htmlspecialchars($body)), null, [], $tenant_id);

        header("Location: actas.php?success=created");
        exit();
    } catch (PDOException $e) {
        $error = "Error al crear el acta: " . $e->getMessage();
    }
}

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="row my-3 justify-content-center">
        <div class="col-xl-8 col-lg-10">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 border-0">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle-sm bg-primary bg-opacity-10 text-primary me-3">
                            <i class="fas fa-file-signature"></i>
                        </div>
                        <div>
                            <h4 class="mb-0 fw-bold text-dark h5">Generar Nueva Acta Oficial</h4>
                            <p class="text-muted small mb-0">Documentación legal de entrega o mantenimiento de activos.</p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4 pt-0">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger d-flex align-items-center p-2 small mb-3">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <div><?php echo $error; ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="" method="POST" id="actaForm" class="row g-3">
                        <?php echo csrf_input(); ?>
                        
                        <div class="col-md-6">
                            <label class="form-label">Tipo de Acta</label>
                            <select name="type" id="actaType" class="form-select form-select-sm" required>
                                <option value="entrega">Acta de Entrega de Equipo</option>
                                <option value="mantenimiento">Acta de Mantenimiento</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Título del Documento</label>
                            <input type="text" name="title" class="form-control form-control-sm" placeholder="Ej: Entrega Laptop Dell" required>
                        </div>

                        <div class="col-md-6 position-relative">
                            <label class="form-label">Dirigida a (Usuario)</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light border-end-0"><i class="fas fa-user text-muted"></i></span>
                                <input type="text" id="user_search_input" class="form-control border-start-0" placeholder="Buscar por nombre o correo" autocomplete="off" required>
                            </div>
                            <input type="hidden" name="user_id" id="selected_user_id">
                            <div id="user_results" class="list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 200px; overflow-y: auto;"></div>
                        </div>
                        <div class="col-md-6 position-relative">
                            <label class="form-label">Equipo Relacionado</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light border-end-0"><i class="fas fa-laptop text-muted"></i></span>
                                <input type="text" id="asset_search_input" class="form-control border-start-0" placeholder="Serial, Marca o Modelo" autocomplete="off">
                            </div>
                            <input type="hidden" name="asset_id" id="selected_asset_id">
                            <div id="asset_results" class="list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 200px; overflow-y: auto;"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" id="detailsLabel">Detalles del Trámite</label>
                            <textarea name="details" class="form-control form-control-sm" rows="5" placeholder="Escriba aquí las observaciones, especificaciones o trabajos realizados..."></textarea>
                        </div>

                        <div class="col-12 mt-4">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="actas.php" class="btn btn-sm btn-light border px-4">Cancelar</a>
                                <button type="submit" class="btn btn-sm btn-primary px-4 shadow-sm">
                                    <i class="fas fa-check-circle me-2"></i>Generar y Notificar
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .icon-circle-sm { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .compact-container { max-width: 1000px; }
</style>

<script>
    document.getElementById('actaType').addEventListener('change', function() {
        const label = document.getElementById('detailsLabel');
        if (this.value === 'mantenimiento') {
            label.textContent = 'Detalles del Mantenimiento / Proceso Realizado';
        } else {
            label.textContent = 'Detalles de la Entrega';
        }
    });

    // Búsqueda Online de Usuarios
    const userInput = document.getElementById('user_search_input');
    const userHidden = document.getElementById('selected_user_id');
    const userResults = document.getElementById('user_results');
    let userTimeout = null;

    userInput.addEventListener('input', function() {
        const q = this.value.trim();
        clearTimeout(userTimeout);
        userHidden.value = '';
        if (q.length < 2) { userResults.classList.add('d-none'); return; }
        
        userResults.innerHTML = '<div class="list-group-item small text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Buscando...</div>';
        userResults.classList.remove('d-none');

        userTimeout = setTimeout(() => {
            fetch(`search_users.php?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    userResults.innerHTML = '';
                    if (data.length === 0) {
                        userResults.innerHTML = '<div class="list-group-item small text-muted">No se encontraron usuarios</div>';
                    } else {
                        data.forEach(u => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action py-2';
                            item.innerHTML = `<div class="fw-bold small">${u.name}</div><div class="text-muted small">${u.email}</div>`;
                            item.addEventListener('click', (e) => {
                                e.preventDefault();
                                userInput.value = u.name;
                                userHidden.value = u.id;
                                userResults.classList.add('d-none');
                            });
                            userResults.appendChild(item);
                        });
                    }
                });
        }, 300);
    });

    // Búsqueda Online de Equipos
    const assetInput = document.getElementById('asset_search_input');
    const assetHidden = document.getElementById('selected_asset_id');
    const assetResults = document.getElementById('asset_results');
    let assetTimeout = null;

    assetInput.addEventListener('input', function() {
        const q = this.value.trim();
        clearTimeout(assetTimeout);
        assetHidden.value = '';
        if (q.length < 2) { assetResults.classList.add('d-none'); return; }

        assetResults.innerHTML = '<div class="list-group-item small text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Buscando...</div>';
        assetResults.classList.remove('d-none');

        assetTimeout = setTimeout(() => {
            fetch(`search_assets.php?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    assetResults.innerHTML = '';
                    if (data.length === 0) {
                        assetResults.innerHTML = '<div class="list-group-item small text-muted">No se encontraron equipos</div>';
                    } else {
                        data.forEach(a => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action py-2';
                            item.innerHTML = `<div class="fw-bold small">${a.serial_number}</div><div class="text-muted small">${a.brand} ${a.model}</div>`;
                            item.addEventListener('click', (e) => {
                                e.preventDefault();
                                assetInput.value = `${a.serial_number} - ${a.brand} ${a.model}`;
                                assetHidden.value = a.id;
                                assetResults.classList.add('d-none');
                            });
                            assetResults.appendChild(item);
                        });
                    }
                });
        }, 300);
    });

    // Cerrar resultados al hacer clic fuera
    document.addEventListener('click', function(e) {
        if (!userInput.contains(e.target) && !userResults.contains(e.target)) userResults.classList.add('d-none');
        if (!assetInput.contains(e.target) && !assetResults.contains(e.target)) assetResults.classList.add('d-none');
    });

    // Validar antes de enviar
    document.getElementById('actaForm').addEventListener('submit', function(e) {
        if (!userHidden.value) {
            e.preventDefault();
            alert('Por favor selecciona un usuario válido de la lista de resultados.');
            userInput.focus();
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>