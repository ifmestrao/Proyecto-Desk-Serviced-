<?php
require_once 'config/db.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS integrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        provider VARCHAR(50) NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT NULL,
        is_active BOOLEAN DEFAULT 0,
        UNIQUE KEY idx_tenant_provider_key (tenant_id, provider, setting_key)
    )");
    echo "Table 'integrations' created successfully.\n";
} catch (PDOException $e) {
    echo "Error creating 'integrations': " . $e->getMessage() . "\n";
}

// Agregar integraciones básicas
$defaultSettings = [
    ['teams', 'webhook_url', '', 0],
    ['telegram', 'bot_token', '', 0],
    ['telegram', 'chat_id', '', 0],
    ['sso_google', 'client_id', '', 0],
    ['sso_google', 'client_secret', '', 0],
    ['sso_microsoft', 'client_id', '', 0],
    ['sso_microsoft', 'tenant_id', '', 0],
    ['imap', 'host', 'imap.gmail.com', 0],
    ['imap', 'port', '993', 0],
    ['imap', 'email', '', 0],
    ['imap', 'password', '', 0],
];

foreach ($defaultSettings as $setting) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO integrations (provider, setting_key, setting_value, is_active) VALUES (?, ?, ?, ?)");
        $stmt->execute($setting);
    } catch (PDOException $e) {}
}

echo "Integration settings initialized.\n";
