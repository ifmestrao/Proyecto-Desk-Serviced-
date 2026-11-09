<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';

$pageTitle = 'Notificaciones y Correo';
$success_msg = '';
$error_msg = '';

$tenant_id = get_tenant_id();

// Mensajes por URL
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'google_linked') $success_msg = '¡Cuenta de Google vinculada con éxito!';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error_msg = 'Error de seguridad (CSRF).';
    } else {
        $fields = [
            'from_email', 'from_name', 'enable_notifications',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
            'email_method', 'google_client_id', 'google_client_secret',
            'notify_on_new_ticket', 'notify_on_status_change', 'notify_on_new_message',
            'ai_email_autoreply', 'ai_email_confidence_threshold'
        ];

        try {
            $pdo->beginTransaction();

            foreach ($fields as $field) {
                $value = trim($_POST[$field] ?? '');
                
                // Manejo de checkboxes
                if (in_array($field, ['enable_notifications', 'notify_on_new_ticket', 'notify_on_status_change', 'notify_on_new_message', 'ai_email_autoreply'])) {
                    $value = isset($_POST[$field]) ? '1' : '0';
                }

                // Sincronizar con tenant_settings (is_active se puede usar para habilitar/deshabilitar temporalmente)
                $check = $pdo->prepare("SELECT id FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
                $check->execute([$tenant_id, $field]);
                $setting_id = $check->fetchColumn();

                if ($setting_id) {
                    $pdo->prepare("UPDATE tenant_settings SET setting_value = ?, is_active = 1, updated_at = NOW() WHERE id = ?")
                        ->execute([$value, $setting_id]);
                } else {
                    $pdo->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, is_active) VALUES (?, ?, ?, 1)")
                        ->execute([$tenant_id, $field, $value]);
                }
            }

            $pdo->commit();
            $success_msg = 'Configuración de notificaciones guardada.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

// Get current settings
$settings = [];
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4 compact-container">
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <p class="text-muted small mb-0">Gestión de alertas por correo, SMTP y eventos del sistema.</p>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show p-2 small mb-3 border-0 shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show p-2 small mb-3 border-0 shadow-sm" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrf_input(); ?>
        
        <div class="row g-3">
            <div class="col-md-8">
                <!-- Configuración General -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="fw-bold mb-0 text-dark">Configuración General del Remitente</h6>
                    </div>
                    <div class="card-body p-3 pt-0">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Nombre Visual</label>
                                <input type="text" name="from_name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['from_name'] ?? 'Desk Service'); ?>" placeholder="Ej: Soporte Técnico">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email de Salida</label>
                                <input type="email" name="from_email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['from_email'] ?? ''); ?>" placeholder="no-reply@empresa.com">
                            </div>
                        </div>
                        <div class="form-check form-switch small">
                            <input class="form-check-input" type="checkbox" name="enable_notifications" id="enableNotifications" <?php echo ($settings['enable_notifications'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enableNotifications">Habilitar notificaciones automáticas por correo</label>
                        </div>
                    </div>
                </div>

                <!-- Método de Envío -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="fw-bold mb-0 text-dark">Protocolo de Mensajería (SMTP / API)</h6>
                    </div>
                    <div class="card-body p-3 pt-0">
                        <div class="mb-3">
                            <label class="form-label">Método de Envío Seleccionado</label>
                            <select name="email_method" class="form-select form-select-sm bg-light" id="emailMethodSelect">
                                <option value="phpmail" <?php echo ($settings['email_method'] ?? '') === 'phpmail' ? 'selected' : ''; ?>>PHP Mail (Básico - No recomendado)</option>
                                <option value="smtp" <?php echo ($settings['email_method'] ?? '') === 'smtp' ? 'selected' : ''; ?>>Servidor SMTP Corporativo (Seguro)</option>
                                <option value="google" <?php echo ($settings['email_method'] ?? '') === 'google' ? 'selected' : ''; ?>>Google Workspace / Gmail API</option>
                            </select>
                        </div>

                        <!-- SMTP Fields -->
                        <div id="smtpFields" class="method-fields <?php echo ($settings['email_method'] ?? '') === 'smtp' ? '' : 'd-none'; ?>">
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <label class="form-label">Host SMTP</label>
                                    <input type="text" name="smtp_host" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Puerto</label>
                                    <input type="number" name="smtp_port" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Usuario SMTP</label>
                                    <input type="text" name="smtp_user" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['smtp_user'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contraseña / Token</label>
                                    <input type="password" name="smtp_pass" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['smtp_pass'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Cifrado</label>
                                    <select name="smtp_secure" class="form-select form-select-sm">
                                        <option value="tls" <?php echo ($settings['smtp_secure'] ?? '') === 'tls' ? 'selected' : ''; ?>>STARTTLS (Recomendado)</option>
                                        <option value="ssl" <?php echo ($settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL/TLS</option>
                                        <option value="none" <?php echo ($settings['smtp_secure'] ?? '') === 'none' ? 'selected' : ''; ?>>Ninguno (Inseguro)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Google API Fields -->
                        <div id="googleFields" class="method-fields <?php echo ($settings['email_method'] ?? '') === 'google' ? '' : 'd-none'; ?>">
                            <div class="alert alert-info py-2 small border-0 bg-opacity-10 bg-info text-info mb-3">
                                <i class="fab fa-google me-2"></i>Requiere configuración de proyecto en Google Cloud Console. Use <b>Redirect URI:</b> <code><?php echo (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]/google_auth_callback.php"; ?></code>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Google Client ID</label>
                                <input type="text" name="google_client_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['google_client_id'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Google Client Secret</label>
                                <input type="password" name="google_client_secret" class="form-control form-control-sm" value="<?php echo htmlspecialchars($settings['google_client_secret'] ?? ''); ?>">
                            </div>
                            
                            <?php if (!empty($settings['google_client_id']) && !empty($settings['google_client_secret'])): ?>
                                <?php if (!empty($settings['google_refresh_token'])): ?>
                                    <div class="alert alert-success py-2 small border-0 bg-opacity-10 bg-success text-success mb-3">
                                        <i class="fas fa-check-circle me-2"></i>Cuenta de Google vinculada correctamente.
                                    </div>
                                    <a href="google_auth_callback.php?action=auth" class="btn btn-xs btn-outline-primary px-3 rounded-pill">
                                        <i class="fab fa-google me-2"></i>Volver a Vincular
                                    </a>
                                <?php else: ?>
                                    <div class="alert alert-warning py-2 small border-0 bg-opacity-10 bg-warning text-dark mb-3">
                                        <i class="fas fa-exclamation-triangle me-2"></i>Credenciales guardadas. Ahora debe vincular su cuenta.
                                    </div>
                                    <a href="google_auth_callback.php?action=auth" class="btn btn-xs btn-danger px-3 rounded-pill shadow-sm">
                                        <i class="fab fa-google me-2"></i>Vincular Cuenta de Google Ahora
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="p-2 border rounded bg-light mb-3">
                                    <p class="extra-small text-muted mb-2"><i class="fas fa-info-circle me-1"></i> Para habilitar la vinculación:</p>
                                    <ol class="extra-small text-muted mb-0 ps-3">
                                        <li>Ingrese el <b>Client ID</b> y <b>Client Secret</b> arriba.</li>
                                        <li>Haga clic en el botón azul <b>"Guardar Configuración"</b> al final de la página.</li>
                                        <li>El botón de vincular se activará automáticamente.</li>
                                    </ol>
                                </div>
                                <button type="button" class="btn btn-xs btn-secondary px-3 rounded-pill" disabled>
                                    <i class="fab fa-google me-2"></i>Vincular (Guarde primero)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Asistente de IA para el canal Email -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-robot me-2 text-purple"></i>Asistente de IA para Correos Entrantes</h6>
                    </div>
                    <div class="card-body p-3 pt-0">
                        <p class="extra-small text-muted mb-3">
                            Cuando llega un correo nuevo, el asistente lo analiza (sentimiento, categoría y búsqueda en la
                            base de conocimiento) y redacta una propuesta de respuesta. Esa respuesta queda como
                            <b>borrador</b> visible en el Centro Multicanal para que un agente la revise y la envíe, o bien
                            puede enviarse <b>automáticamente</b> si su nivel de confianza supera el umbral que definas aquí.
                        </p>
                        <div class="form-check form-switch small mb-3">
                            <input class="form-check-input" type="checkbox" name="ai_email_autoreply" id="aiEmailAutoreply" <?php echo ($settings['ai_email_autoreply'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="aiEmailAutoreply">Enviar la respuesta de la IA automáticamente cuando la confianza sea alta</label>
                        </div>
                        <div class="mb-1">
                            <label class="form-label">Umbral mínimo de confianza para auto-respuesta (0-100)</label>
                            <input type="number" min="0" max="100" name="ai_email_confidence_threshold" class="form-control form-control-sm" style="max-width: 140px;"
                                value="<?php echo htmlspecialchars($settings['ai_email_confidence_threshold'] ?? '60'); ?>">
                            <div class="form-text extra-small">Por debajo de este umbral, la IA solo dejará el borrador listo para que un agente lo apruebe y envíe manualmente desde <b>Centro Multicanal</b>.</div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm" style="margin-bottom: 30px;">
                    <i class="fas fa-save me-2"></i>Guardar Configuración
                </button>
            </div>

            <div class="col-md-4">
                <div class="card border-0 shadow-sm bg-light bg-opacity-50 mb-3">
                    <div class="card-body p-3">
                        <h6 class="fw-bold mb-2 small"><i class="fas fa-info-circle text-primary me-2"></i>Recomendación Técnica</h6>
                        <p class="extra-small text-muted mb-0">
                            Para evitar que los correos sean marcados como <b>SPAM</b>, configure un servidor SMTP corporativo con registros SPF/DKIM válidos. La función básica de PHP suele ser bloqueada por Gmail y Outlook.
                        </p>
                    </div>
                </div>
                
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="fw-bold mb-0 text-dark">Eventos Notificables</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush extra-small">
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <span>Apertura de nuevos tickets</span>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="notify_on_new_ticket" <?php echo ($settings['notify_on_new_ticket'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <span>Actualización de estados</span>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="notify_on_status_change" <?php echo ($settings['notify_on_status_change'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <span>Nuevos mensajes en el chat</span>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="notify_on_new_message" <?php echo ($settings['notify_on_new_message'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2 text-muted bg-light bg-opacity-50">
                                <span>Alertas de vencimiento SLA</span>
                                <i class="fas fa-lock small" title="Requiere plan Enterprise"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
    .extra-small { font-size: 0.75rem; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
    .text-purple { color: #7c3aed !important; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('emailMethodSelect');
    const fields = document.querySelectorAll('.method-fields');

    select.addEventListener('change', function() {
        fields.forEach(f => f.classList.add('d-none'));
        if (this.value === 'smtp') {
            document.getElementById('smtpFields').classList.remove('d-none');
        } else if (this.value === 'google') {
            document.getElementById('googleFields').classList.remove('d-none');
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
