<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

// Verificar que haya usuarios pendientes de autenticar
if (empty($_SESSION['pending_auth_users'])) {
    header("Location: login");
    exit;
}

$pending_users = $_SESSION['pending_auth_users'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seleccionar Instancia - Desk Service</title>
    <link rel="shortcut icon" href="/assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #e0eafc 0%, #cfdef3 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .selection-container {
            max-width: 500px;
            width: 100%;
            padding: 15px;
        }
        .tenant-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            background: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
        }
        .tenant-card:hover {
            border-color: #2563eb;
            background: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .tenant-icon {
            width: 40px;
            height: 40px;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>

<div class="container selection-container">
    <div class="card glass-card p-4 p-sm-5 shadow-lg border-0" style="border-radius: 24px;">
        <div class="text-center mb-4">
            <img src="/assets/img/deskser.png" alt="Logo" width="60" class="mb-3">
            <h1 class="h4 fw-bold mb-2">Selecciona una instancia</h1>
            <p class="text-muted small">Tu cuenta está asociada a múltiples empresas. ¿A cuál deseas ingresar?</p>
        </div>

        <div class="tenant-list">
            <?php foreach ($pending_users as $index => $u): ?>
                <form action="auth.php" method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="finalize_selection">
                    <input type="hidden" name="user_index" value="<?php echo $index; ?>">
                    <button type="submit" class="tenant-card w-100 text-start border-0">
                        <div class="d-flex align-items-center gap-3">
                            <div class="tenant-icon">
                                <i class="fas fa-building"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($u['tenant_name'] ?? 'Desk Service Principal'); ?></h6>
                                <span class="badge bg-light text-muted border small"><?php echo htmlspecialchars($u['role']); ?></span>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-muted"></i>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-3">
            <a href="logout" class="text-decoration-none small text-muted">Cancelar e iniciar con otra cuenta</a>
        </div>
    </div>
</div>

</body>
</html>
