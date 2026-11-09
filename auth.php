<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';
require_once 'includes/security.php';
require_once 'includes/mailer.php';

// Asegurar columnas de verificación
try {
    $c = $pdo->query("SHOW COLUMNS FROM users LIKE 'verification_code'")->fetch();
    if (!$c) { $pdo->exec("ALTER TABLE users ADD COLUMN verification_code CHAR(6) NULL, ADD COLUMN is_verified TINYINT(1) DEFAULT 0"); }
    
    // Columnas para recuperación de contraseña
    $c = $pdo->query("SHOW COLUMNS FROM users LIKE 'reset_code'")->fetch();
    if (!$c) { $pdo->exec("ALTER TABLE users ADD COLUMN reset_code CHAR(6) NULL, ADD COLUMN reset_expires DATETIME NULL"); }
} catch (Throwable $e) {}

// Tabla para dispositivos de confianza
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS trusted_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        token VARCHAR(100) NOT NULL,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uniq_user_token (user_id, token),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

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

function audit_log_auth($pdo, $action, $user_id = null, $details = null, $tenant_id = 1) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $user_id, $action, $details, $ip, $ua]);
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_check()) {
        $_SESSION['error'] = "Sesión expirada. Intenta de nuevo.";
        $dest = (isset($_POST['action']) && $_POST['action']==='register') ? 'register' : 'login';
        
        session_write_close();
        header("Location: $dest");
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] == 'register') {
        // Registration disabled, redirect
        header("Location: myaccount_login");
        exit;

    } elseif (isset($_POST['action']) && $_POST['action'] == 'login') {
        // Login Centralizado: Buscamos al usuario globalmente
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Anti fuerza bruta: por IP y por correo
        if (!rate_limit_hit($pdo, 'login_ip|' . client_ip(), 30, 900)
            || !rate_limit_hit($pdo, 'login_em|' . strtolower($email) . '|' . client_ip(), 8, 900)) {
            audit_log_auth($pdo, 'login_blocked', null, "Bloqueo por demasiados intentos: $email", 1);
            $_SESSION['error'] = "Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.";
            session_write_close();
            header("Location: login");
            exit;
        }

        // Buscar todos los usuarios con ese correo y sus tenants
        $stmt = $pdo->prepare("SELECT u.*, t.name as tenant_name, t.plan as tenant_plan, t.status as tenant_status 
                               FROM users u 
                               LEFT JOIN tenants t ON u.tenant_id = t.id 
                               WHERE u.email = ?");
        $stmt->execute([$email]);
        $all_users = $stmt->fetchAll();

        // Filtrar solo los usuarios cuya contraseña coincida
        $matched_users = [];
        foreach ($all_users as $u) {
            if (password_verify($password, $u['password'])) {
                $matched_users[] = $u;
            }
        }

        if (count($matched_users) > 1) {
            // Múltiples instancias encontradas, guardar en sesión y redirigir a selección
            // Guardar solo lo necesario (nunca hashes ni códigos) en la sesión
            $_SESSION['pending_auth_users'] = array_map(function ($u) {
                unset($u['password'], $u['verification_code'], $u['reset_code'], $u['reset_expires']);
                return $u;
            }, $matched_users);
            session_write_close();
            header("Location: select_tenant");
            exit;
        }

        $user = $matched_users[0] ?? null;

        if ($user) {
            // Verificar si el tenant está activo (si tiene uno)
            if ($user['tenant_id'] && $user['tenant_status'] !== 'active' && $user['tenant_id'] != 1) {
                $_SESSION['error'] = "Tu instancia de servicio está inactiva. Contacta al administrador.";
                session_write_close();
                header("Location: login");
                exit;
            }

            // Check if we have a trusted device
            $trusted = false;
            if (isset($_COOKIE['trust_token'])) {
                $stmt = $pdo->prepare("SELECT * FROM trusted_devices WHERE user_id = ? AND token = ? AND expires_at > NOW()");
                $stmt->execute([$user['id'], hash('sha256', $_COOKIE['trust_token'])]);
                $trusted = $stmt->fetch() !== false;
            }

            // Always require verification unless device is trusted
            if (!$trusted) {
                // Generate verification code
                $code = secure_code();
                $stmt = $pdo->prepare("UPDATE users SET verification_code = ? WHERE id = ?");
                $stmt->execute([$code, $user['id']]);

                // Send verification email
                $subj = "Tu código de verificación - Desk Service";
                $html = "<h1>Código de Verificación</h1><p>Tu código es: <strong>$code</strong></p>";
                $tid = $user['tenant_id'] ?? 1;
                send_mail($email, $subj, $html, null, [], $tid);

                $_SESSION['verify_email'] = $email;
                session_write_close();
                header("Location: verify_email");
                exit;
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            // Asignar el tenant detectado a la sesión
            $tenant_id = (int)($user['tenant_id'] ?? 1);
            set_tenant_session($tenant_id, $user['tenant_name'] ?? 'Desk Service', $user['tenant_plan'] ?? 'enterprise');

            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            audit_log_auth($pdo, 'login', $user['id'], "Inicio de sesión exitoso", $tenant_id);

            if ($user['role'] == 'admin' || $user['role'] == 'supervisor' || $user['role'] == 'tech') {
                session_write_close();
                header("Location: dashboard");
                exit;
            } else {
                session_write_close();
                header("Location: dashboard_user");
                exit;
            }
        } else {
            // Mensaje genérico: no revelar si el usuario existe
            audit_log_auth($pdo, 'failed_login', null, "Intento de inicio de sesión fallido para: $email", 1);
            $_SESSION['error'] = "Correo o contraseña incorrectos.";
            session_write_close();
            header("Location: login");
            exit;
        }
    } elseif (isset($_POST['action']) && $_POST['action'] == 'finalize_selection') {
        // Finalizar selección de tenant
        if (empty($_SESSION['pending_auth_users'])) {
            header("Location: login");
            exit;
        }

        $index = (int)($_POST['user_index'] ?? -1);
        $user = $_SESSION['pending_auth_users'][$index] ?? null;

        if (!$user) {
            header("Location: login");
            exit;
        }

        // Limpiar usuarios pendientes de la lista (pero guardamos el seleccionado temporalmente si falta 2FA)
        unset($_SESSION['pending_auth_users']);

        // Check if we have a trusted device
        $trusted = false;
        if (isset($_COOKIE['trust_token'])) {
            $stmt = $pdo->prepare("SELECT * FROM trusted_devices WHERE user_id = ? AND token = ? AND expires_at > NOW()");
            $stmt->execute([$user['id'], hash('sha256', $_COOKIE['trust_token'])]);
            $trusted = $stmt->fetch() !== false;
        }

        // Always require verification unless device is trusted
        if (!$trusted) {
            // Generate verification code
            $code = secure_code();
            $stmt = $pdo->prepare("UPDATE users SET verification_code = ? WHERE id = ?");
            $stmt->execute([$code, $user['id']]);

            // Send verification email
            $subj = "Tu código de verificación - Desk Service";
            $html = "<h1>Verificación de Inicio de Sesión</h1><p>Tu código es: <strong>$code</strong></p>";
            $tid = $user['tenant_id'] ?? 1;
            send_mail($user['email'], $subj, $html, null, [], $tid);

            $_SESSION['verify_email'] = $user['email'];
            // Guardamos el ID del usuario seleccionado para recuperarlo tras la verificación
            $_SESSION['selected_user_id'] = $user['id']; 
            
            session_write_close();
            header("Location: verify_email");
            exit;
        }

        // Proceder con el login del usuario seleccionado (si ya es de confianza)
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];

        $tenant_id = (int)($user['tenant_id'] ?? 1);
        set_tenant_session($tenant_id, $user['tenant_name'] ?? 'Desk Service', $user['tenant_plan'] ?? 'enterprise');

        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        audit_log_auth($pdo, 'login', $user['id'], "Inicio de sesión exitoso (Selección manual)", $tenant_id);

        if ($user['role'] == 'admin' || $user['role'] == 'supervisor' || $user['role'] == 'tech') {
            session_write_close();
            header("Location: dashboard");
            exit;
        } else {
            session_write_close();
            header("Location: dashboard_user");
            exit;
        }
    } elseif (isset($_POST['action']) && $_POST['action'] == 'switch_tenant') {
        // Cambiar de instancia desde MyAccount
        if (!isset($_SESSION['myaccount_user_id'])) {
            header("Location: myaccount_login");
            exit;
        }

        $tenant_id = (int)$_POST['tenant_id'];
        $email = $_SESSION['myaccount_email'];

        // Buscar al usuario en ese tenant específico
        $stmt = $pdo->prepare("SELECT u.*, t.name as tenant_name, t.plan as tenant_plan, t.status as tenant_status 
                               FROM users u 
                               JOIN tenants t ON u.tenant_id = t.id 
                               WHERE u.email = ? AND u.tenant_id = ? AND t.status = 'active' LIMIT 1");
        $stmt->execute([$email, $tenant_id]);
        $user = $stmt->fetch();

        if ($user) {
            // Actualizar la sesión principal con los datos del nuevo tenant
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            
            set_tenant_session($tenant_id, $user['tenant_name'], $user['tenant_plan']);

            session_write_close();
            if ($user['role'] == 'admin' || $user['role'] == 'supervisor' || $user['role'] == 'tech') {
                header("Location: dashboard");
            } else {
                header("Location: dashboard_user");
            }
            exit;
        } else {
            $_SESSION['error'] = "No tienes acceso a esta instancia o está inactiva.";
            header("Location: myaccount_dashboard");
            exit;
        }
    }
}
?>
