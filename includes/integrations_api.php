<?php
require_once __DIR__ . '/../config/db.php';

function get_integration_setting($provider, $key, $tenant_id = null) {
    global $pdo;
    if ($tenant_id === null) {
        $tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
    }
    $stmt = $pdo->prepare("SELECT setting_value FROM integrations WHERE tenant_id = ? AND provider = ? AND setting_key = ? AND is_active = 1");
    $stmt->execute([$tenant_id, $provider, $key]);
    return $stmt->fetchColumn();
}

function send_teams_notification($ticket_id, $title, $status, $priority, $local_id = null) {
    global $pdo;
    // Obtener tenant_id del ticket para asegurar el envío a la instancia correcta
    $stmtT = $pdo->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
    $stmtT->execute([$ticket_id]);
    $tenant_id = $stmtT->fetchColumn() ?: 1;

    $webhook_url = get_integration_setting('teams', 'webhook_url', $tenant_id);
    if (!$webhook_url) return false;

    $display_id = $local_id ?: $ticket_id;
    $color = "0078D7"; // Blue
    if ($priority == 'critical') $color = "E81123"; // Red
    if ($status == 'closed') $color = "107C10"; // Green

    $payload = [
        "@type" => "MessageCard",
        "@context" => "http://schema.org/extensions",
        "themeColor" => $color,
        "summary" => "Actualización de Ticket #$display_id",
        "sections" => [
            [
                "activityTitle" => "Ticket #$display_id: $title",
                "activitySubtitle" => "Estado: " . strtoupper($status) . " | Prioridad: " . strtoupper($priority),
                "activityImage" => "https://cdn-icons-png.flaticon.com/512/2830/2830305.png"
            ]
        ]
    ];

    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function send_telegram_notification($ticket_id, $title, $status, $local_id = null) {
    global $pdo;
    // Obtener tenant_id del ticket
    $stmtT = $pdo->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
    $stmtT->execute([$ticket_id]);
    $tenant_id = $stmtT->fetchColumn() ?: 1;

    $bot_token = get_integration_setting('telegram', 'bot_token', $tenant_id);
    $chat_id = get_integration_setting('telegram', 'chat_id', $tenant_id);
    
    if (!$bot_token || !$chat_id) return false;

    $display_id = $local_id ?: $ticket_id;
    
    // Obtener nombre del tenant para el mensaje
    $stmtN = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmtN->execute([$tenant_id]);
    $tenant_name = $stmtN->fetchColumn() ?: 'Desk Service';

    $message = "🔔 *Actualización de Ticket #$display_id*\n";
    $message .= "Empresa: $tenant_name\n";
    $message .= "Título: $title\n";
    $message .= "Estado: " . strtoupper($status);

    $url = "https://api.telegram.org/bot$bot_token/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
?>