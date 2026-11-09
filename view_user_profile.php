<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';

$tenant_id = $_SESSION['tenant_id'];
$current_user_id = $_SESSION['user_id'];
$view_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($view_user_id === 0) {
    header("Location: dashboard.php");
    exit;
}

if ($view_user_id === $current_user_id) {
    header("Location: profile.php");
    exit;
}

// Get user data
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ? AND tenant_id = ?");
$stmtUser->execute([$view_user_id, $tenant_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: dashboard.php");
    exit;
}

// Ensure user_gallery exists
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
} catch (Exception $e) { }

// Get user's gallery
$galleryStmt = $pdo->prepare("SELECT * FROM user_gallery WHERE user_id = ? AND tenant_id = ? ORDER BY created_at DESC");
$galleryStmt->execute([$view_user_id, $tenant_id]);
$gallery = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Perfil de ' . $user['name'];

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 py-4">
    <a href="internal_chat.php" class="btn btn-outline-primary mb-4 rounded-pill">
        <i class="fas fa-arrow-left me-2"></i> Volver a chats
    </a>
    
    <div class="profile-container">
        <div class="row">
            <!-- Left Column -->
            <div class="col-xl-4 left-column text-center">
                <div class="avatar-ring mx-auto mb-4">
                    <?php if ($user['avatar']): ?>
                        <img src="<?php echo htmlspecialchars($user['avatar']); ?>" class="avatar-img" alt="Avatar">
                    <?php else: ?>
                        <div class="avatar-img d-flex align-items-center justify-content-center" style="font-size: 5rem; background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #1e40af;">
                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="user-name mb-2"><?php echo htmlspecialchars($user['name']); ?></h2>
                <div class="mb-4">
                    <span class="role-badge">
                        <i class="fas fa-user-tag me-1"></i> <?php echo ucfirst($user['role']); ?>
                    </span>
                </div>
                
                <button class="btn btn-primary rounded-pill px-5" id="start-chat-btn">
                    <i class="fas fa-comment-dots me-2"></i> Iniciar chat
                </button>
                
                <div class="info-panel mt-4">
                    <h4 class="contact-title">
                        Información de contacto
                    </h4>
                    <div class="info-item">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="info-text">
                            <small class="text-muted">Correo</small>
                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                    </div>
                    <?php if ($user['phone']): ?>
                        <div class="info-item">
                            <div class="info-icon"><i class="fas fa-phone"></i></div>
                            <div class="info-text">
                                <small class="text-muted">Teléfono</small>
                                <span><?php echo htmlspecialchars($user['phone']); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($user['area']): ?>
                        <div class="info-item">
                            <div class="info-icon"><i class="fas fa-building"></i></div>
                            <div class="info-text">
                                <small class="text-muted">Área</small>
                                <span><?php echo htmlspecialchars($user['area']); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($user['position']): ?>
                        <div class="info-item">
                            <div class="info-icon"><i class="fas fa-briefcase"></i></div>
                            <div class="info-text">
                                <small class="text-muted">Cargo</small>
                                <span><?php echo htmlspecialchars($user['position']); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column: Gallery -->
            <div class="col-xl-8 right-column">
                <div class="gallery-container mb-4">
                    <div class="gallery-header d-flex justify-content-between align-items-center">
                        <h5 class="gallery-title fw-bold mb-0">
                            <i class="fas fa-images me-2"></i> Galería de fotos
                        </h5>
                        <?php if (count($gallery) > 0): ?>
                            <button class="btn btn-outline-secondary btn-sm rounded-pill" onclick="openAllGallery()">Ver todas</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="gallery-grid mt-3">
                        <?php if (count($gallery) > 0): ?>
                            <?php foreach ($gallery as $img): ?>
                                <div class="gallery-item">
                                    <img src="<?php echo htmlspecialchars($img['file_path']); ?>" alt="Gallery Image" onclick="showLightbox('<?php echo htmlspecialchars($img['file_path']); ?>')">
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="w-100 p-5 text-center text-muted">
                                <i class="fas fa-images" style="font-size: 3rem;"></i>
                                <p class="mt-2 mb-0">No hay fotos en la galería</p>
                            </div>
                        <?php endif; ?>
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

<!-- Copy styles from profile.php -->
<style>
body{
    background:linear-gradient(135deg,#f8fafc 0%,#e2e8f0 100%);
    color:#0f172a;
}

.profile-container{
    background:#fff;
    border-radius:30px;
    padding:1.5rem;
    position:relative;
    overflow:hidden;
    border:1px solid #e2e8f0;
    box-shadow:0 25px 50px -12px rgba(0,0,0,.12);
}

.profile-container::before{
    content:'';
    position:absolute;
    left:0;
    top:0;
    width:32%;
    height:100%;
    background:linear-gradient(
        180deg,
        #2563eb 0%,
        #1d4ed8 50%,
        #1e40af 100%
    );
    border-radius:30px 0 0 30px;
}

.left-column{
    position:relative;
    z-index:2;
    color:white;
    padding:2rem 1.5rem;
    display:flex;
    flex-direction:column;
    align-items:center;
}

.avatar-ring{
    position:relative;
    width:150px;
    height:150px;
    border-radius:50%;
    background:white;
    padding:8px;
    margin-bottom:1.5rem;
    box-shadow:0 15px 35px rgba(0,0,0,.15);
    margin-right: 30px;
}

.avatar-ring::after{
    content:'';
    position:absolute;
    bottom:10px;
    right:10px;
    width:24px;
    height:24px;
    border-radius:50%;
    background:#4ade80;
    border:4px solid white;
}

.avatar-img{
    width:100%;
    height:100%;
    border-radius:50%;
    object-fit:cover;
    margin-right: 30px;
}

.user-name{
    font-size:2.3rem;
    font-weight:800;
    text-align:center;
    margin-bottom:.75rem;
    margin-right: 30px;
}

.role-badge{
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.2);
    backdrop-filter:blur(10px);
    color:white;
    padding:10px 24px;
    border-radius:999px;
    font-size:.95rem;
    font-weight:600;
    margin-right: 30px;
}

#start-chat-btn{
    width:85%;
    height:60px;
    border:none;
    border-radius:999px;
    background:linear-gradient(
        135deg,
        #4f8cff,
        #2563eb
    );
    font-size:1.15rem;
    font-weight:700;
    box-shadow:0 15px 30px rgba(37,99,235,.25);
    transition:.3s;
    margin-right: 30px;

}

#start-chat-btn:hover{
    transform:translateY(-2px);
}

.info-panel{
    width:100%;
    margin-top:2rem;
    background:white;
    border-radius:24px;
    padding:2rem;
    box-shadow:0 15px 40px rgba(0,0,0,.08);
    margin-right: 30px;

}

.contact-title{
    color:#0f172a;
    font-weight:800;
    margin-bottom:1.3rem;
    font-size:1.35rem;
}

.info-item{
    display:flex;
    gap:1rem;
    align-items:center;
    padding:0.9rem 0;
    border-bottom:1px solid #f1f5f9;
    transition:all 0.2s;
}
.info-item:hover{
    padding-left:4px;
}

.info-item:last-child{
    border-bottom:none;
}

.info-icon{
    width:52px;
    height:52px;
    flex-shrink:0;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#eff6ff,#dbeafe);
    color:#2563eb;
    font-size:1.2rem;
}

.info-text{
    flex:1;
    min-width:0;
}

.info-text small{
    display:block;
    color:#64748b;
    font-size:.7rem;
    text-transform:uppercase;
    letter-spacing:1px;
    font-weight:700;
    margin-bottom:4px;
}

.info-text span{
    display:block;
    color:#0f172a;
    font-weight:600;
    font-size:0.95rem;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.right-column{
    position:relative;
    z-index:2;
    padding:1rem;
}

.gallery-container{
    background:white;
    border-radius:24px;
    padding:2rem;
    min-height:750px;
    box-shadow:0 10px 30px rgba(0,0,0,.05);
}

.gallery-header{
    margin-bottom:2rem;
}

.gallery-title{
    font-size:2rem;
    font-weight:800;
    color:#0f172a;
}

.gallery-title i{
    color:#2563eb;
}

.gallery-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
    gap:1rem;
}

.gallery-item{
    position:relative;
    border-radius:18px;
    overflow:hidden;
    aspect-ratio:1;
    transition:.3s;
    box-shadow:0 8px 20px rgba(0,0,0,.08);
}

.gallery-item:hover{
    transform:translateY(-4px);
    box-shadow:0 15px 30px rgba(0,0,0,.15);
}

.gallery-item img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.gallery-empty{
    min-height:500px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    text-align:center;
}

.empty-icon{
    width:130px;
    height:130px;
    border-radius:50%;
    background:#eff6ff;
    display:flex;
    justify-content:center;
    align-items:center;
    margin-bottom:25px;
}

.empty-icon i{
    font-size:55px;
    color:#2563eb;
}

.gallery-empty h3{
    font-size:2rem;
    font-weight:800;
    color:#0f172a;
    margin-bottom:10px;
}

.gallery-empty p{
    color:#64748b;
    font-size:1.1rem;
}

.lightbox{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.9);
    display:none;
    justify-content:center;
    align-items:center;
    z-index:9999;
}

.lightbox.show{
    display:flex;
}

.lightbox img{
    max-width:90%;
    max-height:90vh;
    border-radius:18px;
}

.lightbox-close{
    position:absolute;
    top:20px;
    right:20px;
    width:50px;
    height:50px;
    border:none;
    border-radius:50%;
    background:white;
    cursor:pointer;
}

@media(max-width:1200px){

    .profile-container::before{
        width:100%;
        height:420px;
        border-radius:30px 30px 0 0;
    }

    .left-column{
        margin-bottom:2rem;
    }

    .info-panel{
        max-width:700px;
        margin:auto;
        margin-top:2rem;
    }
}
</style>

<script>
const viewUserId = <?= $view_user_id ?>;

function showLightbox(src) {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox').classList.add('show');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('show');
}
function openAllGallery() {
    const firstImg = document.querySelector('.gallery-item img');
    if (firstImg) firstImg.click();
}

// Start chat button
document.getElementById('start-chat-btn').addEventListener('click', async () => {
    try {
        const formData = new FormData();
        formData.append('action', 'start_chat');
        formData.append('user_id', viewUserId);
        
        const res = await fetch('api_internal_chat.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            window.location.href = `internal_chat.php?room_id=${data.room_id}`;
        }
    } catch (e) {
        console.error(e);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
