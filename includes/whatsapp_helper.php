<?php
require_once __DIR__ . '/../config/env.php';

const WA_GRAPH_VERSION = 'v23.0';

function wa_log($msg) {
    @file_put_contents(__DIR__ . '/../whatsapp_webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function get_whatsapp_config($pdo = null, $tenant_id = null) {
    if (!$pdo) {
        require_once __DIR__ . '/../config/db.php';
    }
    
    $config = [
        'api_key' => null,
        'phone_id' => null,
        'verify_token' => null,
        'app_secret' => null
    ];

    try {
        if ($tenant_id) {
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM integrations WHERE provider = 'whatsapp' AND tenant_id = ? AND is_active = 1");
            $stmt->execute([$tenant_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $config[$row['setting_key']] = $row['setting_value'];
            }
        } else {
            // Fallback general si no hay tenant
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM integrations WHERE provider = 'whatsapp' AND is_active = 1 LIMIT 3");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $config[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching WhatsApp config: " . $e->getMessage());
    }

    return $config;
}

function whatsapp_send_message($to, $message, $pdo = null, $tenant_id = null) {
    $config = get_whatsapp_config($pdo, $tenant_id);
    
    if (empty($config['api_key']) || empty($config['phone_id'])) {
        error_log("WhatsApp Error: No API Key or Phone ID configured for tenant $tenant_id.");
        wa_log("ENVIO FALLO: sin api_key/phone_id activos para tenant $tenant_id (revisa que 'Activar Recepción' esté marcado)");
        return false;
    }

    $url = "https://graph.facebook.com/" . WA_GRAPH_VERSION . "/" . $config['phone_id'] . "/messages";
    
    $data = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $message]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Evita error de certificado local
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['api_key']
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("WhatsApp API Error ($http_code): " . $result);
        wa_log("ENVIO FALLO a $to http=$http_code curl='$curl_err' resp=" . $result);
        return false;
    }

    // Registrar en logs similares a los de Telegram para que aparezcan en el chat de soporte
    if ($pdo) {
        try {
            $effective_tenant_id = $tenant_id ?: 1;
            // Usamos el prefijo 'wa_' en el chat_id para diferenciarlo de Telegram en la misma tabla si fuera necesario, 
            // o simplemente usamos el número de teléfono como chat_id.
            $stmt = $pdo->prepare("INSERT INTO telegram_logs (tenant_id, source, chat_id, direction, message) VALUES (?, 'whatsapp', ?, 'out', ?)");
            $stmt->execute([$effective_tenant_id, $to, $message]);
        } catch (Exception $e) {
            error_log("WhatsApp Logging Error: " . $e->getMessage());
        }
    }

    return json_decode($result, true);
}