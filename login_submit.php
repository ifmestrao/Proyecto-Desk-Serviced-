<?php
/**
 * login_submit.php — recibe el formulario de login, anota si pidieron "Mantener sesión iniciada"
 * y deja que auth.php haga la validación de siempre.
 * La cookie larga se emite en la primera petición ya autenticada (ver config/session_setup.php).
 */
require_once 'config/session_setup.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!empty($_POST['remember'])) {
        $_SESSION['remember_pending'] = time();   // vale 15 minutos y solo si el login termina bien
    } else {
        unset($_SESSION['remember_pending']);
    }
}

require __DIR__ . '/auth.php';