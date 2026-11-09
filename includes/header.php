<?php
require_once __DIR__ . '/../config/session_setup.php';
require_once __DIR__ . '/../config/db.php';

// Detect theme from cookie
$theme = $_COOKIE['deskservice_theme'] ?? 'light';
$data_bs_theme = ($theme === 'dark' || $theme === 'vampire') ? 'dark' : 'light';
$data_theme = ($theme === 'vampire') ? 'vampire' : '';

function checkAuth() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        header("Location: login");
        exit;
    }
    

    if (isset($_SESSION['tenant_id'])) {
        $stmt = $pdo->prepare("SELECT created_at, plan_status FROM tenants WHERE id = ?");
        $stmt->execute([$_SESSION['tenant_id']]);
        $tenant = $stmt->fetch();
        
        if ($tenant && $tenant['plan_status'] !== 'paid') {
            $created = strtotime($tenant['created_at']);
            $now = time();
            $days_passed = floor(($now - $created) / (60 * 60 * 24));
            
            if ($days_passed > 7) {
  
                $allowed_pages = ['myaccount_login.php', 'logout.php', 'contact_support.php'];
                $current_page = basename($_SERVER['PHP_SELF']);
                
                if (!in_array($current_page, $allowed_pages)) {
                    header("Location: payment_required");
                    exit;
                }
            }
        }
    }
}
function checkStaff() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] == 'user') {
        header("Location: dashboard_user");
        exit;
    }
}
function checkAdmin() {
    if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'supervisor')) {
        header("Location: dashboard");
        exit;
    }
}
function t_status($s) {
    $map = ['open' => 'Abierto', 'pending' => 'En Espera', 'closed' => 'Cerrado'];
    return isset($map[$s]) ? $map[$s] : $s;
}
function t_priority($p) {
    $map = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Crítica'];
    return isset($map[$p]) ? $map[$p] : $p;
}
function p_color($p) {
    $p = strtolower($p);
    $map = [
        'low' => 'success', 
        'medium' => 'warning', 
        'high' => 'danger', 
        'critical' => 'priority-critical',
        'baja' => 'success',
        'media' => 'warning',
        'alta' => 'danger',
        'crítica' => 'priority-critical',
        'critica' => 'priority-critical'
    ];
    return isset($map[$p]) ? $map[$p] : 'secondary';
}
function audit_log($pdo, $action, $details = null) {
    try {
        $user_id = $_SESSION['user_id'] ?? null;
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $user_id, $action, $details, $ip, $ua]);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="<?php echo $data_bs_theme; ?>" <?php echo $data_theme ? "data-theme='$data_theme'" : ""; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desk Service</title>
    <link rel="shortcut icon" href="/assets/img/deskser.png" />
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Theme initialization logic
        (function() {
            const getCookie = (name) => {
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
            };
            
            const savedTheme = getCookie('deskservice_theme') || localStorage.getItem('deskservice_theme') || 'light';
            
            // Sync localStorage with cookie if needed
            if (!getCookie('deskservice_theme')) {
                document.cookie = `deskservice_theme=${savedTheme}; path=/; max-age=31536000; SameSite=Lax`;
            }
            if (!localStorage.getItem('deskservice_theme')) {
                localStorage.setItem('deskservice_theme', savedTheme);
            }

            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
                document.documentElement.removeAttribute('data-theme');
            } else if (savedTheme === 'vampire') {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
                document.documentElement.setAttribute('data-theme', 'vampire');
            } else {
                document.documentElement.setAttribute('data-bs-theme', 'light');
                document.documentElement.removeAttribute('data-theme');
            }
        })();
        
        // Register Service Worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(reg => console.log('Service Worker registrado', reg))
                    .catch(err => console.log('Error al registrar Service Worker', err));
            });
        }
    </script>
</head>
<body class="bg-light">
    <!-- Global Loading Spinner Overlay -->
    <div id="loading-overlay" class="loading-overlay d-none">
        <div class="loading-spinner-container">
            <div class="dot-spinner-large"></div>
            <div class="loading-text">Cargando...</div>
        </div>
    </div>
