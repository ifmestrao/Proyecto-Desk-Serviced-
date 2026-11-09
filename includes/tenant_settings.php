<?php

require_once __DIR__ . '/../config/env.php';

function ts_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_settings (
        tenant_id INT NOT NULL,
        setting_key VARCHAR(64) NOT NULL,
        setting_value MEDIUMTEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (tenant_id, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Lee un arreglo JSON guardado para el tenant. Devuelve $default si no existe o es inválido. */
function ts_get(PDO $pdo, int $tenant_id, string $key, array $default = []): array {
    try {
        ts_ensure($pdo);
        $st = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
        $st->execute([$tenant_id, $key]);
        $raw = $st->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') return $default;
        $arr = json_decode((string)$raw, true);
        return is_array($arr) ? $arr : $default;
    } catch (Throwable $e) {
        error_log('ts_get: ' . $e->getMessage());
        return $default;
    }
}

function ts_set(PDO $pdo, int $tenant_id, string $key, array $value): bool {
    try {
        ts_ensure($pdo);
        $st = $pdo->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        return $st->execute([$tenant_id, $key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        error_log('ts_set: ' . $e->getMessage());
        return false;
    }
}

function ts_delete(PDO $pdo, int $tenant_id, string $key): void {
    try {
        ts_ensure($pdo);
        $pdo->prepare("DELETE FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?")->execute([$tenant_id, $key]);
    } catch (Throwable $e) {}
}

/** URL pública base del sitio (https://dominio). Funciona también en cron/CLI usando APP_URL. */
function ts_base_url(): string {
    $env = trim((string)app_env('APP_URL', ''));
    if ($env !== '') return rtrim($env, '/');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return 'https://deskserviced.alwaysdata.net';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * Guarda un logo subido ($_FILES[...]) en uploads/branding.
 * Devuelve ['ok'=>true,'url'=>URL absoluta,'path'=>ruta relativa] o ['ok'=>false,'error'=>...].
 * Solo JPG/PNG/WEBP/GIF (sin SVG), máx. 2 MB, verificado como imagen real.
 */
function ts_save_logo(array $file, string $prefix, int $tenant_id): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => ''];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'No se pudo subir el archivo (código ' . (int)$file['error'] . ').'];
    if (!is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'error' => 'Archivo no válido.'];
    if ($file['size'] > 2 * 1024 * 1024) return ['ok' => false, 'error' => 'El logo no puede superar 2 MB.'];

    $info = @getimagesize($file['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $map[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($map[$info[2]])) return ['ok' => false, 'error' => 'Formato no válido. Usa JPG, PNG, WEBP o GIF.'];
    $ext = $map[$info[2]];

    $rel_dir = 'uploads/branding';
    $abs_dir = dirname(__DIR__) . '/' . $rel_dir;
    if (!is_dir($abs_dir) && !@mkdir($abs_dir, 0755, true)) return ['ok' => false, 'error' => 'No se pudo crear la carpeta de logos.'];

    $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . $tenant_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $abs_dir . '/' . $name)) return ['ok' => false, 'error' => 'No se pudo guardar el logo (revisa permisos de uploads/).'];

    return ['ok' => true, 'path' => $rel_dir . '/' . $name, 'url' => ts_base_url() . '/' . $rel_dir . '/' . $name];
}