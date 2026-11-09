<?php
function send_mail($to, $subject, $html, $altText = null, $attachments = [], $tenant_id = null) {
    $cfg = include __DIR__ . '/../config/mail.php';
    require_once __DIR__ . '/../config/env.php';
    $debug = (int)app_env('SMTP_DEBUG', 0);
    $alt = $altText ?: strip_tags($html);
    $autoload = __DIR__ . '/../vendor/autoload.php';


    if ($tenant_id === null) {
        // No reiniciar sesión si ya hay datos en $_SESSION
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION['tenant_id'])) {
            @session_start();
        }
        
        if (isset($_SESSION['tenant_id'])) {
            $tenant_id = (int)$_SESSION['tenant_id'];
        } elseif (function_exists('get_tenant_id')) {
            $tenant_id = get_tenant_id();
        } else {
            $tenant_id = 1; // Fallback
        }
    }



    if ($tenant_id) {
        // Asegurar que tenemos acceso a la base de datos
        if (!isset($pdo) || !$pdo) {
            global $pdo;
        }
        
        $local_pdo = $pdo;

        // Si no hay $pdo global, intentar cargar db.php
        if (!$local_pdo) {
            try {
                require __DIR__ . '/../config/db.php';
                $local_pdo = $pdo;
            } catch (Throwable $e) {
                error_log("Mailer: Error cargando db.php: " . $e->getMessage());
            }
        }

        if ($local_pdo) {
            try {
                // 1. Obtener desde tenant_settings (Prioridad Alta)
                $stmt = $local_pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
                $stmt->execute([$tenant_id]);
                $tenant_settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $tenant_settings[$row['setting_key']] = $row['setting_value'];
                }

                // 2. Obtener desde integrations (Prioridad Media)
                $stmt = $local_pdo->prepare("SELECT setting_key, setting_value FROM integrations WHERE tenant_id = ? AND provider = 'smtp' AND is_active = 1");
                $stmt->execute([$tenant_id]);
                $integrations_settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $integrations_settings[$row['setting_key']] = $row['setting_value'];
                }

                if (isset($tenant_settings['enable_notifications']) && $tenant_settings['enable_notifications'] === '0') {
                    return false;
                }

                // Mapeo de claves
                $smtp_map = [
                    'smtp_host' => 'host',
                    'smtp_port' => 'port',
                    'smtp_user' => 'user',
                    'smtp_pass' => 'pass',
                    'smtp_secure' => 'encryption',
                    'from_email' => 'from',
                    'from_name' => 'from_name'
                ];

                // A. Integrations
                foreach ($smtp_map as $db_key => $cfg_key) {
                    if (!empty($integrations_settings[$db_key])) {
                        $cfg[$cfg_key] = $integrations_settings[$db_key];
                    }
                }

                // B. Tenant Settings (Sobrescribe todo)
                foreach ($smtp_map as $db_key => $cfg_key) {
                    if (isset($tenant_settings[$db_key]) && $tenant_settings[$db_key] !== '') {
                        $cfg[$cfg_key] = $tenant_settings[$db_key];
                    }
                }

                // Gmail API
                if (($tenant_settings['email_method'] ?? '') === 'google') {
                    $google_settings = [];
                    foreach ($tenant_settings as $k => $v) { $google_settings[$k] = ['value' => $v]; }
                    return send_mail_gmail_api($to, $subject, $html, $google_settings, $tenant_id);
                }

                // Corregir mismatch phpmail vs php_mail
                if (($tenant_settings['email_method'] ?? '') === 'phpmail' || ($tenant_settings['email_method'] ?? '') === 'php_mail') {
                    $cfg['use_php_mail'] = true;
                }

                if (isset($cfg['encryption']) && ($cfg['encryption'] === 'none' || $cfg['encryption'] === '')) {
                    $cfg['encryption'] = '';
                }

            } catch (Throwable $e) {
                error_log('Mailer Error DB: ' . $e->getMessage());
            }
        }
    }
    
    // Si $to es un array, tomamos el primero como principal y el resto como BCC para velocidad
    $recipients = is_array($to) ? array_filter($to) : [trim($to)];
    if (empty($recipients)) return false;
    $primaryTo = array_shift($recipients);

    if (is_file($autoload) && empty($cfg['use_php_mail'])) {
        require_once $autoload;
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'];
            $mail->SMTPSecure = $cfg['encryption'];
            $mail->Port = (int)$cfg['port'];
            $mail->CharSet = 'UTF-8';
            
            // Optimización SMTP: Timeout más corto para no bloquear demasiado
            $mail->Timeout = 10; 
            
            if ($debug) {
                $mail->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
                $mail->Debugoutput = function($str, $level) { error_log(trim($str)); };
            }
            
            // Asegurar remitente
            $fromEmail = !empty($cfg['from']) ? $cfg['from'] : $cfg['user'];
            $fromName = !empty($cfg['from_name']) ? $cfg['from_name'] : 'Desk Service';
            
            $mail->setFrom($fromEmail, $fromName);
            $mail->addReplyTo($fromEmail, $fromName);
            
            $mail->addAddress($primaryTo);
            // Agregar el resto como BCC para enviar un solo paquete SMTP
            foreach ($recipients as $bcc) {
                if (!empty($bcc)) $mail->addBCC($bcc);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $alt;
            if (is_array($attachments) && $attachments) {
                foreach ($attachments as $att) {
                    if (is_array($att)) {
                        $path = $att['path'] ?? null;
                        $name = $att['name'] ?? '';
                    } else {
                        $path = $att;
                        $name = '';
                    }
                    if (!$path) continue;
                    if (!is_file($path)) continue;
                    $mail->addAttachment($path, $name);
                }
            }
            return $mail->send();
        } catch (\Throwable $e) {
            error_log('Mailer exception: '.$e->getMessage());
            return false;
        }
    } else {
        // Fallback mail()
        $fromEmail = !empty($cfg['from']) ? $cfg['from'] : (!empty($cfg['user']) ? $cfg['user'] : 'noreply@deskservices.cloud');
        $fromName = !empty($cfg['from_name']) ? $cfg['from_name'] : 'Desk Service';
        
        $fromHeader = $fromName." <".$fromEmail.">";
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: ".$fromHeader."\r\n";
        $headers .= "Reply-To: ".$fromHeader."\r\n";
        
        return @mail($primaryTo, $subject, $html, $headers);
    }
}

/**
 * Envía correo usando la API de Gmail (OAuth2)
 */
function send_mail_gmail_api($to, $subject, $html, $settings, $tenant_id) {
    $client_id = $settings['google_client_id']['value'] ?? '';
    $client_secret = $settings['google_client_secret']['value'] ?? '';
    $refresh_token = $settings['google_refresh_token']['value'] ?? '';
    $from_email = $settings['from_email']['value'] ?? '';
    $from_name = $settings['from_name']['value'] ?? 'Desk Service';

    if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
        error_log("Gmail API Error: Credenciales incompletas para tenant $tenant_id");
        return false;
    }

    // 1. Obtener Access Token
    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Evita error de certificado local
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'grant_type' => 'refresh_token'
    ]));
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    $access_token = $data['access_token'] ?? null;
    if (!$access_token) {
        error_log("Gmail API Error: No se pudo obtener access_token. " . ($data['error_description'] ?? ''));
        return false;
    }

    // 2. Construir el mensaje RFC2822
    $boundary = uniqid('np');
    $to_str = is_array($to) ? implode(', ', $to) : $to;
    
    $raw_msg = "To: $to_str\r\n";
    $raw_msg .= "Subject: =?utf-8?B?" . base64_encode($subject) . "?=\r\n";
    $raw_msg .= "MIME-Version: 1.0\r\n";
    $raw_msg .= "Content-Type: text/html; charset=utf-8\r\n";
    $raw_msg .= "Content-Transfer-Encoding: base64\r\n";
    $raw_msg .= "From: $from_name <$from_email>\r\n\r\n";
    $raw_msg .= base64_encode($html);

    // Gmail API requiere base64url encoding
    $encoded_msg = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($raw_msg));

    // 3. Enviar vía REST API
    $ch = curl_init("https://www.googleapis.com/gmail/v1/users/me/messages/send");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Evita error de certificado local
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['raw' => $encoded_msg]));
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("Gmail API Send Error ($http_code): " . $result);
        return false;
    }

    return true;
}
