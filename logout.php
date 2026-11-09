<?php
require_once 'config/session_setup.php';
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
$reason = isset($_GET['reason']) ? '?reason=' . urlencode($_GET['reason']) : '';
header("Location: login" . $reason);
exit;
?>
