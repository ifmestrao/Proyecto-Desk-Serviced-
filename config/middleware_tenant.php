<?php

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/db.php';

function ensure_tenant_session() {
    global $pdo;
    
    // Si ya hay un tenant en la sesión (post-login), lo respetamos
    if (isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id'])) {
        return;
    }

    // Por defecto, antes del login, usamos el tenant 1 (Mesa de Servicio Centralizada)
    set_tenant_session(1, 'Desk Service', 'enterprise');
}

function get_tenant_id() {
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
}

function ensure_tenant_access($resource_tenant_id) {
    $current_tenant_id = get_tenant_id();
    if ((int)$resource_tenant_id !== $current_tenant_id) {
        http_response_code(403);
        die("Acceso denegado: No tienes permisos para ver este recurso.");
    }
}

function tenant_where($alias = null) {
    $tenant_id = get_tenant_id();
    if ($alias) {
        return " `$alias`.`tenant_id` = $tenant_id ";
    }
    return " `tenant_id` = $tenant_id ";
}

function tenant_and() {
    return " AND " . tenant_where();
}

function tenant_or() {
    return " OR " . tenant_where();
}

ensure_tenant_session();
?>
