<?php
/**
 * Webhook para recibir notificaciones de Mercado Pago (IPN / Webhooks)
 * Actualiza el estado de la suscripción de la instancia.
 */

require_once 'config/db.php';
require_once 'includes/mp_helper.php';

// Log de notificaciones
$logFile = __DIR__ . '/mp_notifications.log';
$content = file_get_contents("php://input");
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Notification: " . $content . "\n", FILE_APPEND);

$data = json_decode($content, true);
$type = $_GET['topic'] ?? ($data['type'] ?? null);
$id = $_GET['id'] ?? ($data['data']['id'] ?? null);

if ($type === 'payment' && $id) {
    $config = get_mp_config($pdo);
    $access_token = $config['access_token'] ?? '';

    // Consultar el estado del pago a Mercado Pago
    $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $access_token"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $payment_info = json_decode($response, true);

    if (isset($payment_info['status']) && $payment_info['status'] === 'approved') {
        $tenant_id = $payment_info['external_reference']; // El ID que pasamos al crear la preferencia
        
        // 1. Extender el trial o activar suscripción
        $stmt = $pdo->prepare("UPDATE tenants SET 
            status = 'active', 
            trial_ends_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
            plan_status = 'paid',
            updated_at = NOW() 
            WHERE id = ?");
        $stmt->execute([$tenant_id]);

        // 2. Registrar el pago en una tabla de facturación (opcional pero recomendado)
        try {
            $pdo->prepare("INSERT INTO tenant_payments (tenant_id, payment_id, amount, status, gateway) VALUES (?, ?, ?, ?, 'mercadopago')")
                ->execute([$tenant_id, $id, $payment_info['transaction_amount'], 'approved']);
        } catch (Exception $e) {}

        file_put_contents($logFile, "[SUCCESS] Tenant $tenant_id activated via Payment $id\n", FILE_APPEND);
    }
}

http_response_code(200);
echo "OK";
?>
