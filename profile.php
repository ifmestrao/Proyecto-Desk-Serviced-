<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';
require_once 'includes/csrf.php';

$user_id = $_SESSION['user_id'];
$pageTitle = 'Mi Perfil';

// Create user_gallery table if it doesn't exist
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'user_gallery'");
    if (!$checkTable->fetch()) {
        $pdo->exec("
            CREATE TABLE `user_gallery` (
                `id` int NOT NULL AUTO_INCREMENT,
                `tenant_id` int NOT NULL,
                `user_id` int NOT NULL,
                `file_name` varchar(255) NOT NULL,
                `file_path` varchar(255) NOT NULL,
                `mime_type` varchar(100) DEFAULT NULL,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_gallery_tenant` (`tenant_id`),
                KEY `idx_gallery_user` (`user_id`),
                CONSTRAINT `fk_gallery_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_gallery_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    // Table already exists or error, ignore
}

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Fetch user gallery
$galleryStmt = $pdo->prepare("SELECT * FROM user_gallery WHERE user_id = ? AND tenant_id = ? ORDER BY created_at DESC");
$galleryStmt->execute([$user_id, $_SESSION['tenant_id']]);
$gallery = $galleryStmt->fetchAll();

$success = $_GET['success'] ?? null;
$error = $_GET['error'] ?? null;
?>

<?php require_once 'includes/navbar.php'; ?>

<style>
    body {
        background: #f1f5f9;
        color: #0f172a;
    }

    /* === WRAPPER PRINCIPAL === */
    .profile-container {
        background: #ffffff;
        border-radius: 24px;
        padding: 0;
        position: relative;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        box-shadow: 0 8px 40px rgba(0,0,0,0.10);
    }

    /* Esquinas decorativas */
    .profile-container::before {
        content: '';
        position: absolute;
        top: 0; left: 0;
        width: 90px; height: 90px;
        background: #1e40af;
        border-radius: 0 0 60px 0;
        z-index: 0;
        pointer-events: none;
    }
    .profile-container::after {
        content: '';
        position: absolute;
        bottom: 0; right: 0;
        width: 90px; height: 90px;
        background: #f59e0b;
        border-radius: 60px 0 0 0;
        z-index: 0;
        pointer-events: none;
    }

    /* === COLUMNA IZQUIERDA === */
    .left-column {
        position: relative;
        z-index: 1;
        padding: 2.5rem 1.75rem;
        border-right: 1.5px solid #e2e8f0;
        background: #ffffff;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    /* Avatar */
    .avatar-ring {
        position: relative;
        width: 160px;
        height: 160px;
        margin: 0 auto 1.25rem;
    }
    .avatar-img {
        width: 160px;
        height: 160px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid white;
        box-shadow: 0 4px 24px rgba(30,64,175,0.18);
        display: block;
    }
    .avatar-upload-btn {
        position: absolute;
        bottom: 8px; right: 8px;
        background: #f59e0b;
        color: white;
        border: none;
        width: 40px; height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(245,158,11,0.4);
        font-size: 1rem;
    }
    .avatar-upload-btn:hover {
        transform: scale(1.1);
    }

    /* Nombre y badge */
    .user-name {
        font-size: 1.6rem;
        font-weight: 800;
        color: #0f172a;
        text-align: center;
        margin-top: 0;
        margin-bottom: 0.5rem;
    }
    .role-badge {
        background: #fef3c7;
        border: 1.5px solid #fcd34d;
        color: #b45309;
        padding: 6px 18px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 1rem;
    }

    /* Bio */
    .user-bio {
        font-size: 0.85rem;
        color: #64748b;
        text-align: center;
        line-height: 1.6;
        margin-bottom: 1.5rem;
    }

    /* Panel de info */
    .info-panel {
        background: #f8fafc;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        width: 100%;
        padding: 0.25rem 1rem;
        margin-top: 0;
        box-shadow: none;
        margin-right: 0;
    }
    .info-item {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.85rem 0;
        border-bottom: 1px solid #e2e8f0;
    }
    .info-item:last-child {
        border-bottom: none;
        padding-bottom: 0.85rem;
    }
    .info-icon {
        width: 42px; height: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #eff6ff;
        color: #1e40af;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .info-text small {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        color: #1e40af;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .info-text span {
        color: #0f172a;
        font-weight: 700;
        font-size: 0.95rem;
    }

    /* === COLUMNA DERECHA === */
    .right-column {
        position: relative;
        z-index: 1;
        padding: 2rem 2rem 2rem 2rem;
        background: transparent;
    }

    /* === GALERÍA === */
    .gallery-container {
        background: white;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: none;
    }
    .gallery-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1rem;
    }
    .gallery-title {
        font-size: 1.1rem;
        font-weight: 800;
        color: #1e40af;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-ver-todas {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #64748b;
        border-radius: 8px;
        padding: 5px 14px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
        text-decoration: none;
    }
    .gallery-carousel-wrapper {
        position: relative;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .gallery-carousel {
        display: flex;
        gap: 0.85rem;
        overflow-x: auto;
        scroll-behavior: smooth;
        -ms-overflow-style: none;
        scrollbar-width: none;
        flex: 1;
    }
    .gallery-carousel::-webkit-scrollbar { display: none; }
    .gallery-item {
        flex: 0 0 155px;
        height: 130px;
        border-radius: 12px;
        overflow: hidden;
        border: 1.5px solid #e2e8f0;
        position: relative;
        transition: all 0.3s;
    }
    .gallery-item:hover { transform: scale(1.04); border-color: #3b82f6; }
    .gallery-item img { width: 100%; height: 100%; object-fit: cover; }
    .gallery-delete {
        position: absolute;
        top: 7px; right: 7px;
        background: rgba(239,68,68,0.93);
        color: white;
        border: none;
        width: 30px; height: 30px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.2s;
        font-size: 0.75rem;
    }
    .gallery-item:hover .gallery-delete { opacity: 1; }
    .upload-placeholder {
        flex: 0 0 155px;
        height: 130px;
        border-radius: 12px;
        background: #eff6ff;
        border: 2px dashed #93c5fd;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #1e40af;
        font-weight: 700;
        text-align: center;
        flex-direction: column;
        gap: 4px;
        font-size: 0.85rem;
    }
    .upload-placeholder:hover { background: #dbeafe; border-color: #3b82f6; }
    .carousel-btn {
        background: white;
        border: 1px solid #e2e8f0;
        width: 36px; height: 36px;
        border-radius: 50%;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1e40af;
        font-size: 1.1rem;
        flex-shrink: 0;
        transition: all 0.2s;
    }
    .carousel-btn:hover { background: #3b82f6; color: white; }

    /* === FORMULARIO === */
    .form-panel {
        background: white;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        padding: 1.5rem;
        box-shadow: none;
    }
    .form-title {
        font-size: 1rem;
        font-weight: 800;
        color: #1e40af;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin-bottom: 1.25rem;
    }
    .custom-input {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #0f172a;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        font-size: 0.9rem;
        transition: all 0.2s;
    }
    .custom-input:focus {
        background: white;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        outline: none;
    }
    .custom-input::placeholder { color: #94a3b8; }
    .input-label {
        color: #1e40af;
        font-size: 0.75rem;
        font-weight: 700;
        margin-bottom: 5px;
        display: block;
    }
    .btn-sync {
        background: linear-gradient(90deg, #1e40af 0%, #3b82f6 60%, #f59e0b 100%);
        color: white;
        border: none;
        padding: 1rem 2rem;
        border-radius: 12px;
        font-weight: 800;
        font-size: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        cursor: pointer;
        transition: all 0.3s;
    }
    .btn-sync:hover { transform: translateY(-2px); }

    /* Lightbox */
    .lightbox {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.85);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }
    .lightbox.show { display: flex; }
    .lightbox img { max-width: 90%; max-height: 90vh; border-radius: 12px; }
    .lightbox-close {
        position: absolute;
        top: 20px; right: 20px;
        background: white;
        border: none;
        width: 44px; height: 44px;
        border-radius: 50%;
        font-size: 1.5rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>

<div class="container-fluid px-4 py-5">
    <div class="row justify-content-center">
        <div class="col-xxl-12">
            
            <?php if ($success): ?>
                <div class="alert alert-success border-0 mb-4" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #047857;">
                    <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger border-0 mb-4" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #991b1b;">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="profile-container">
                <div class="row">
                    <!-- Left Column: Avatar & Info -->
                    <div class="col-xl-4 left-column text-center">
                        <form action="profile_actions.php" method="POST" enctype="multipart/form-data">
                            <?php echo csrf_input(); ?>
                            <input type="file" id="avatar-upload" name="avatar" class="d-none" onchange="this.form.submit()">
                            
                            <div class="avatar-ring">
                                <?php if ($user['avatar']): ?>
                                    <img src="<?php echo htmlspecialchars($user['avatar']); ?>" class="avatar-img" alt="Avatar">
                                <?php else: ?>
                                    <div class="avatar-img d-flex align-items-center justify-content-center" style="font-size: 5rem; background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #1e40af;">
                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <button type="button" class="avatar-upload-btn" onclick="document.getElementById('avatar-upload').click()">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                            
                            <h2 class="user-name"><?php echo htmlspecialchars($user['name']); ?></h2>
                            <div class="mt-2">
                                <span class="role-badge">
                                    <i class="fas fa-user-shield"></i> <?php echo strtoupper($user['role']); ?>
                                </span>
                            </div>
                        </form>
                        
                        <div class="info-panel">
                            <div class="info-item">
                                <div class="info-icon"><i class="far fa-calendar-alt"></i></div>
                                <div class="info-text">
                                    <small>Miembro desde</small>
                                    <span><?php echo date('d M, Y', strtotime($user['created_at'])); ?></span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-icon"><i class="far fa-building"></i></div>
                                <div class="info-text">
                                    <small>Área asignada</small>
                                    <span><?php echo htmlspecialchars($user['area'] ?? 'No especificada'); ?></span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-icon"><i class="fas fa-briefcase"></i></div>
                                <div class="info-text">
                                    <small>Cargo actual</small>
                                    <span><?php echo htmlspecialchars($user['position'] ?? 'No definido'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column: Gallery & Form -->
                    <div class="col-xl-8 right-column">
                        <!-- Gallery -->
                        <div class="gallery-container">
                            <div class="gallery-header">
                                <div class="gallery-title">
                                    <i class="fas fa-images"></i> Galería
                                </div>
                            </div>
                            
                            <form id="gallery-form" action="profile_actions.php" method="POST" enctype="multipart/form-data">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="upload_gallery" value="1">
                                
                                <div class="gallery-carousel-wrapper">
                                    <button type="button" class="carousel-btn left" onclick="scrollGallery(-1)">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    
                                    <div id="gallery-carousel" class="gallery-carousel">
                                        <label class="gallery-item upload-placeholder">
                                            <div>
                                                <i class="fas fa-plus-circle" style="font-size: 2.5rem; margin-bottom: 0.5rem;"></i>
                                                <div>Subir Imagen</div>
                                            </div>
                                            <input type="file" id="gallery-upload" name="gallery_image[]" class="d-none" multiple accept="image/*" onchange="document.getElementById('gallery-form').submit()">
                                        </label>
                                        
                                        <?php foreach ($gallery as $img): ?>
                                            <div class="gallery-item">
                                                <img src="<?php echo htmlspecialchars($img['file_path']); ?>" alt="Gallery Image" onclick="showLightbox('<?php echo htmlspecialchars($img['file_path']); ?>')">
                                                <button type="button" class="gallery-delete" onclick="deleteGalleryImage(<?php echo $img['id']; ?>)">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <button type="button" class="carousel-btn right" onclick="scrollGallery(1)">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Form -->
                        <div class="form-panel">
                            <form action="profile_actions.php" method="POST">
                                <?php echo csrf_input(); ?>
                                
                                <div class="form-title">
                                    <i class="fas fa-user-edit"></i> Datos de Contacto & Edición
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="input-label">Nombre Completo</label>
                                        <input type="text" name="name" class="form-control custom-input" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="input-label">Correo Electrónico</label>
                                        <input type="email" name="email" class="form-control custom-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="input-label">Teléfono / Celular</label>
                                        <input type="text" name="phone" class="form-control custom-input" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+00 000 000 0000">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="input-label">Departamento</label>
                                        <input type="text" name="area" class="form-control custom-input" value="<?php echo htmlspecialchars($user['area'] ?? ''); ?>" placeholder="Sistemas, RRHH...">
                                    </div>
                                    <div class="col-12">
                                        <label class="input-label">Posición Laboral</label>
                                        <input type="text" name="position" class="form-control custom-input" value="<?php echo htmlspecialchars($user['position'] ?? ''); ?>" placeholder="Técnico Especialista...">
                                    </div>
                                </div>
                                
                                <div class="mt-4 pt-3 border-top" style="border-color: #e2e8f0 !important;">
                                    <button type="submit" name="update_profile" class="btn-sync">
                                        <i class="fas fa-save"></i> Guardar y Sincronizar
                                    </button>
                                    <div class="text-center mt-3">
                                        <small style="color: #64748b;">Actualización y sincronización de datos completa</small>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- Lightbox -->
<div id="lightbox" class="lightbox">
    <button class="lightbox-close" onclick="closeLightbox()">
        <i class="fas fa-times"></i>
    </button>
    <img id="lightbox-img" src="" alt="Lightbox Image">
</div>

<script>
function scrollGallery(direction) {
    const carousel = document.getElementById('gallery-carousel');
    const scrollAmount = 200;
    if (direction === -1) {
        carousel.scrollLeft -= scrollAmount;
    } else {
        carousel.scrollLeft += scrollAmount;
    }
}

function showLightbox(src) {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox').classList.add('show');
}

function closeLightbox() {
    document.getElementById('lightbox').classList.remove('show');
}

function deleteGalleryImage(id) {
    if (confirm('¿Seguro que quieres eliminar esta imagen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'profile_actions.php';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="delete_gallery" value="1">
            <input type="hidden" name="gallery_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
