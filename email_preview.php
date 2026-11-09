<?php

require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/email_templates.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'supervisor'], true)) {
    http_response_code(403);
    exit('No autorizado');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    http_response_code(403);
    exit('Token de seguridad inválido');
}

$meta = ds_template_meta();
$key = (string)($_POST['preview_tpl'] ?? 'ticket_created');
if (!isset($meta[$key]) || $key === '_labels') $key = 'ticket_created';

$cfgPrev = ds_sanitize_config($_POST);
if (!empty($_POST['remove_logo'])) $cfgPrev['brand']['logo_url'] = '';
$GLOBALS['ds_cfg_override'] = $cfgPrev;
echo ds_render_sample($key);