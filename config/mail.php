<?php
require_once __DIR__ . '/env.php';
return [
    'host' => app_env('SMTP_HOST', ''),
    'port' => app_env('SMTP_PORT', 587),
    'user' => app_env('SMTP_USER', ''),
    'pass' => app_env('SMTP_PASS', ''),
    'encryption' => app_env('SMTP_ENCRYPTION', 'tls'),
    'from' => app_env('SMTP_FROM', 'no-reply@example.com'),
    'from_name' => app_env('SMTP_FROM_NAME', 'Desk Service')
];
