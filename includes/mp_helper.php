<?php
/**
 * Mercado Pago Helper para Desk Service
 * Gestiona la creación de preferencias y validación de pagos.
 */

function get_mp_config($pdo) {
    // Estas credenciales se guardan en la tabla integrations bajo el tenant 1 (Admin Global)
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM integrations WHERE provider = 'mercadopago' AND tenant_id = 1 AND is_active = 1");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function mp_create_preference($tenant_data, $pdo) {
    $config = get_mp_config($pdo);
    $access_token = $config['access_token'] ?? '';

    if (empty($access_token)) {
        error_log("Mercado Pago Error: No Access Token configured.");
        return null;
    }

    $url = "https://api.mercadopago.com/checkout/preferences";

    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    // Forzar la URL de ngrok para pruebas de Webhook e IPN
    $base_url = "https://inge-phatic-ema.ngrok-free.dev";

    $data = [
        "items" => [
            [
                "id" => "PLAN_PRO_" . $tenant_data['id'],
                "title" => "Plan Profesional - Desk Service (" . $tenant_data['subdomain'] . ")",
                "quantity" => 1,
                "unit_price" => 16.00,
                "currency_id" => "USD" // Cambiar según moneda local si es necesario
            ]
        ],
        "payer" => [
            "email" => $tenant_data['contact_email']
        ],
        "back_urls" => [
            "success" => "$base_url/myaccount_dashboard.php?payment=success",
            "failure" => "$base_url/myaccount_dashboard.php?payment=failure",
            "pending" => "$base_url/myaccount_dashboard.php?payment=pending"
        ],
        "auto_return" => "approved",
        "notification_url" => "$base_url/mp_webhook.php?tenant_id=" . $tenant_data['id'],
        "external_reference" => (string)$tenant_data['id']
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $access_token"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 && $http_code !== 201) {
        error_log("Mercado Pago API Error ($http_code): " . $response);
        return null;
    }

    return json_decode($response, true);
}
?>
