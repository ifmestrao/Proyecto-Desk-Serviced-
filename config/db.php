<?php
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/env.php';

$host = app_env('DB_HOST', 'mysql-deskserviced.alwaysdata.net');
$db   = app_env('DB_NAME', '');
$user = app_env('DB_USER', '');
$pass = app_env('DB_PASS', '');
if ($pass === '') {
    error_log('DB_PASS no configurado: define DB_PASS en config/local.env');
    http_response_code(500);
    die('Error de configuración del servidor.');
}
$charset = 'utf8mb4';


$dsn = "mysql:host=$host;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Note: TiDB Cloud requires SSL/TLS
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, // Set to true if you downloaded the CA cert
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    try {
        // En producción (Hostinger), la base de datos ya debe existir.
        // Solo intentamos usarla.
        $pdo->exec("USE `$db` ");
    } catch (PDOException $e) {
        // Si falla el USE, intentamos crearla (solo útil en local)
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $charset COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db` ");
        } catch (PDOException $e2) {
            throw $e; // Re-lanzar el error original de conexión
        }
    }
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL DEFAULT 1,
            user_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NULL,
            url VARCHAR(255) NULL,
            entity_type VARCHAR(40) NULL,
            entity_id BIGINT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            INDEX idx_notifications_tenant (tenant_id),
            INDEX idx_notifications_user_read (user_id, read_at),
            INDEX idx_notifications_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}

    // Asegurar tabla para múltiples solicitantes
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_solicitors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NOT NULL,
            UNIQUE KEY uniq_ts (ticket_id, user_id),
            INDEX idx_ts_ticket (ticket_id),
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}

    // Asegurar tabla para anexos si no existe
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            message_id INT NULL,
            user_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_att_ticket (ticket_id),
            INDEX idx_att_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}

    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($col && isset($col['Type']) && stripos($col['Type'], "enum(") === 0) {
            if (stripos($col['Type'], "'supervisor'") === false) {
                preg_match_all("/'((?:\\\\'|[^'])*)'/", $col['Type'], $m);
                $vals = $m[1] ?? [];
                $vals = array_map(function($v) { return str_replace("\\'", "'", $v); }, $vals);
                if (!in_array('supervisor', $vals, true)) $vals[] = 'supervisor';
                $vals = array_values(array_unique($vals));
                $enumSql = "ENUM(" . implode(",", array_map(function($v) {
                    return "'" . str_replace("'", "\\'", $v) . "'";
                }, $vals)) . ")";
                $nullSql = (isset($col['Null']) && strtoupper((string)$col['Null']) === 'YES') ? 'NULL' : 'NOT NULL';
                $defSql = '';
                if (array_key_exists('Default', $col)) {
                    if ($col['Default'] === null) {
                        $defSql = ' DEFAULT NULL';
                    } else {
                        $defSql = " DEFAULT '" . str_replace("'", "\\'", (string)$col['Default']) . "'";
                    }
                }
                $pdo->exec("ALTER TABLE users MODIFY role $enumSql $nullSql$defSql");
            }
        }
    } catch (Throwable $e) {}
    
} catch (\PDOException $e) {
    // Nunca mostrar detalles de la conexión al navegador: solo al log del servidor
    error_log('DB connection error: ' . $e->getMessage());
    http_response_code(500);
    die("<h3>Error de conexión</h3><p>Estamos experimentando dificultades técnicas. Por favor, intenta de nuevo más tarde.</p>");
}

if (!function_exists('notify_add')) {
    function notify_add(PDO $pdo, int $user_id, string $type, string $title, ?string $body = null, ?string $url = null, ?string $entity_type = null, $entity_id = null, ?int $tenant_id = null): void {
        // El tenant correcto es el del usuario destinatario (evita que cron/webhooks caigan en el tenant 1)
        if (!$tenant_id) {
            $stT = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
            $stT->execute([$user_id]);
            $tenant_id = (int)$stT->fetchColumn();
        }
        if (!$tenant_id) {
            error_log("notify_add: no se pudo determinar tenant para user $user_id");
            return;
        }
        
        $stmt = $pdo->prepare("INSERT INTO notifications (tenant_id, user_id, type, title, body, url, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $user_id, $type, $title, $body, $url, $entity_type, $entity_id]);
    }
}
?>
