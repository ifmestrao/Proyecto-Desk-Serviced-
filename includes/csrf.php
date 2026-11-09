<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/../config/session_setup.php';
}
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_input() {
    return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'">';
}
function csrf_check() {
    $t = $_POST['csrf_token'] ?? '';
    return $t && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t);
}
