<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';

function validate_password_strength($password) {
    if (strlen($password) < 8) return "La contraseña debe tener al menos 8 caracteres.";
    if (preg_match_all('/[0-9]/', $password) < 2) return "La contraseña debe tener al menos 2 números.";
    if (!preg_match('/[@$!%*?&]/', $password)) return "La contraseña debe tener al menos 1 carácter especial (@$!%*?&).";
    if (!preg_match('/[A-Z]/', $password)) return "La contraseña debe tener al menos 1 letra mayúscula.";
    if (!preg_match('/[a-z]/', $password)) return "La contraseña debe tener al menos 1 letra minúscula.";
    return true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("POST Request received in tenant_actions.php. Action: " . ($_POST['action'] ?? 'none'));
    
    // Si no hay acción, redirigir
    if (!isset($_POST['action'])) {
        header("Location: create_tenant.php?error=" . urlencode("Acción no definida"));
        exit;
    }

    if (!csrf_check()) {
        $msg = "Sesión expirada. Por favor, refresca la página e intenta de nuevo.";
        error_log("CSRF Failure in tenant_actions.php. Token expected: " . ($_SESSION['csrf_token'] ?? 'none') . " - Received: " . ($_POST['csrf_token'] ?? 'none'));
        $_SESSION['error'] = $msg;
        session_write_close();
        header("Location: create_tenant.php?error=" . urlencode($msg));
        exit;
    }

    if ($_POST['action'] == 'create_tenant') {
        try {
            // Verificar límite de instancias
            if (isset($_SESSION['myaccount_user_id'])) {
                $my_email = $_SESSION['myaccount_email'];
                // Contar instancias del usuario
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE contact_email = ?");
                $stmt->execute([$my_email]);
                $total_tenants = (int)$stmt->fetchColumn();

                // Verificar si tiene alguna instancia pagada
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE contact_email = ? AND plan_status != 'trial' AND status = 'active'");
                $stmt->execute([$my_email]);
                $paid_tenants = (int)$stmt->fetchColumn();

                if ($total_tenants >= 2 && $paid_tenants == 0) {
                    throw new Exception("Has alcanzado el límite de 2 instancias gratuitas. Para crear más, debes activar un plan de pago en al menos una de tus instancias actuales.");
                }
            }

            $company_name = trim($_POST['company_name']);
            $subdomain = strtolower(preg_replace('/[^a-z0-9-]/', '', trim($_POST['subdomain'])));
            
            // Usar datos de la sesión si está autenticado en MyAccount
            if (isset($_SESSION['myaccount_user_id'])) {
                $admin_email = $_SESSION['myaccount_email'];
                $admin_name = $_SESSION['myaccount_name'];
                $is_logged_in = true;
            } else {
                $admin_name = trim($_POST['admin_name']);
                $admin_email = trim($_POST['admin_email']);
                $password = $_POST['password'];
                $confirm_password = $_POST['confirm_password'];
                $is_logged_in = false;
            }

            $country = $_POST['country'] ?? 'CO';
            $phone = trim($_POST['phone'] ?? '');

            if (empty($subdomain)) {
                 throw new Exception("El subdominio no es válido.");
            }

            if (!$is_logged_in) {
                if ($password !== $confirm_password) {
                    throw new Exception("Las contraseñas no coinciden.");
                }
                $password_err = validate_password_strength($password);
                if ($password_err !== true) {
                    throw new Exception($password_err);
                }
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ?");
            $stmt->execute([$subdomain]);
            if ($stmt->fetch()) {
                throw new Exception("Este subdominio ya está en uso. Por favor, elige otro.");
            }

            // Si no está logueado, verificar que el correo no exista
            if (!$is_logged_in) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$admin_email]);
                if ($stmt->fetch()) {
                    throw new Exception("Este correo electrónico ya está registrado. Inicia sesión para crear más instancias.");
                }
            }

            $trial_ends = date('Y-m-d H:i:s', strtotime('+7 days'));
            $stmt = $pdo->prepare("INSERT INTO tenants (name, subdomain, contact_email, contact_phone, plan, status, trial_ends_at, plan_status) VALUES (?, ?, ?, ?, 'basic', 'active', ?, 'trial')");
            $stmt->execute([$company_name, $subdomain, $admin_email, $phone, $trial_ends]);
            $tenant_id = $pdo->lastInsertId();

            if (!$is_logged_in) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (tenant_id, local_id, name, email, password, role, is_verified) VALUES (?, 1, ?, ?, ?, 'admin', 1)");
                $stmt->execute([$tenant_id, $admin_name, $admin_email, $hashed_password]);
            } else {
                // Si ya está logueado, crear una copia del usuario para el nuevo tenant
                // El local_id DEBE ser 1 porque es el primer usuario de ESTA nueva instancia
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['myaccount_user_id']]);
                $current_pass = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("INSERT INTO users (tenant_id, local_id, name, email, password, role, is_verified) VALUES (?, 1, ?, ?, ?, 'admin', 1)");
                $stmt->execute([$tenant_id, $admin_name, $admin_email, $current_pass]);
            }

            $pdo->commit();

            $_SESSION['user_id'] = $_SESSION['myaccount_user_id'];
            $_SESSION['name'] = $admin_name;
            $_SESSION['role'] = 'admin';
            
            if (function_exists('set_tenant_session')) {
                set_tenant_session($tenant_id, $company_name, 'basic');
            }

            // Intentar enviar correo (no crítico)
            try {
                $subj = "Tu instancia de Desk Service está lista!";
                $html = "<h1>Bienvenido a Desk Service!</h1><p>Hola $admin_name, tu instancia ha sido creada.</p>
                         <p><strong>Detalles de acceso:</strong></p>
                         <ul>
                             <li>URL: https://deskserviced.alwaysdata.net/$subdomain</li>
                             <li>Correo: $admin_email</li>
                         </ul>";
                send_mail($admin_email, $subj, $html, null, [], $tenant_id);
            } catch (Exception $e) {
                error_log("Mail send failure: " . $e->getMessage());
            }

            $_SESSION['success'] = "¡Tu instancia ha sido creada exitosamente!";
            
            // Forzar guardado de sesión antes de redirigir (Hostinger fix)
            session_write_close();
            
            header("Location: myaccount_dashboard.php");
            exit;

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = $e->getMessage();
            error_log("Error in tenant_actions.php: " . $error_msg);
            
            $_SESSION['error'] = $error_msg;
            session_write_close(); 
            
            // Si el error es de duplicado, ser específicos
            if (strpos($error_msg, 'Integrity constraint violation') !== false) {
                $error_msg = "El correo electrónico o subdominio ya está registrado.";
            }
            
            header("Location: create_tenant.php?error=" . urlencode($error_msg));
            exit;
        }
    }
} else {
    // Si no es POST, redirigir con un error para detectar si hubo una redirección indeseada
    $method = $_SERVER['REQUEST_METHOD'];
    error_log("Non-POST access to tenant_actions.php: " . $method);
    header("Location: create_tenant.php?error=" . urlencode("Acceso inválido (esperado POST, recibido $method)"));
    exit;
}
?>