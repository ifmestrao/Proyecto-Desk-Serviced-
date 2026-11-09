<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_check()) {
        $_SESSION['error'] = "Sesión expirada. Intenta de nuevo.";
        header("Location: myaccount_login");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] == 'myaccount_login') {
        $email = $_POST['email'];
        $password = $_POST['password'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['myaccount_user_id'] = $user['id'];
            $_SESSION['myaccount_name'] = $user['name'];
            $_SESSION['myaccount_email'] = $user['email'];
            
            header("Location: myaccount_dashboard");
            exit;
        } else {
            $_SESSION['error'] = "Credenciales incorrectas.";
            header("Location: myaccount_login");
            exit;
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'create_tenant') {
        $tenant_name = $_POST['tenant_name'];
        $subdomain = strtolower(trim($_POST['subdomain']));
        $owner_email = $_SESSION['myaccount_email'];

        // Validar que el subdominio no exista
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ?");
        $stmt->execute([$subdomain]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = "El subdominio ya está en uso.";
            header("Location: create_tenant");
            exit;
        }

        // Crear el tenant con 10 días de prueba
        $stmt = $pdo->prepare("INSERT INTO tenants (name, subdomain, contact_email, trial_ends_at, status) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 DAY), 'active')");
        $stmt->execute([$tenant_name, $subdomain, $owner_email]);
        
        $tenant_id = $pdo->lastInsertId();

        header("Location: myaccount_dashboard?success=tenant_created");
        exit;
    }
    if (isset($_POST['action']) && ($_POST['action'] == 'logout' || $_POST['action'] == 'myaccount_logout')) {
        unset($_SESSION['myaccount_user_id']);
        unset($_SESSION['myaccount_name']);
        unset($_SESSION['myaccount_email']);
        session_destroy();
        header("Location: myaccount_login.php");
        exit;
    }
}
?>