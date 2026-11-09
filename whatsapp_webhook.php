<?php
require_once 'config/db.php';
require_once 'includes/whatsapp_helper.php';
require_once 'includes/telegram_helper.php';
require_once 'includes/security.php';
require_once 'includes/whatsapp_bot.php';


$WA_DEBUG = (app_env('WHATSAPP_DEBUG', '0') === '1');
function wa_debug(string $m): void {
    global $WA_DEBUG;
    if ($WA_DEBUG) wa_log($m);
}

$tenant_id = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 1;

// 1. Verificación del Webhook (Meta la exige al registrar la URL)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $config = get_whatsapp_config($pdo, $tenant_id);
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    $expected_vt = (string)($config['verify_token'] ?? '');
    if ($mode === 'subscribe' && $expected_vt !== '' && hash_equals($expected_vt, (string)$token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('forbidden');
}

// 2. Mensajes entrantes
$content = file_get_contents('php://input');
wa_debug('POST tenant=' . $tenant_id . ' len=' . strlen($content));

// Autenticar que el POST viene de Meta (firma HMAC con el App Secret)
$wa_cfg = get_whatsapp_config($pdo, $tenant_id);
require_whatsapp_signature($content, $wa_cfg['app_secret'] ?? null);

$update = json_decode($content, true);
if (!is_array($update)) {
    http_response_code(200);
    exit('OK');
}
wa_debug('Payload: ' . $content);

// Responder 200 enseguida: Meta reintenta si tardamos (el procesamiento sigue después)
ignore_user_abort(true);
@set_time_limit(90);
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

wa_bot_ensure_schema($pdo);

foreach (($update['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value = $change['value'] ?? [];
        if (empty($value['messages'])) continue;   // estados de entrega (sent/delivered/read) y otros eventos
        $names = [];
        foreach (($value['contacts'] ?? []) as $c) {
            $names[(string)($c['wa_id'] ?? '')] = (string)($c['profile']['name'] ?? '');
        }
        foreach ($value['messages'] as $message) {
            try {
                $wa_name = $names[(string)($message['from'] ?? '')] ?? '';
                wa_bot_handle($pdo, $tenant_id, $message, $wa_name);
            } catch (Throwable $e) {
                wa_log('Error bot: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
    }
}