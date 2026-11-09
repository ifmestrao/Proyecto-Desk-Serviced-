<?php
require_once 'includes/header.php';
checkAuth();
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'tech') {
    header("Location: dashboard.php");
    exit;
}
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';

$tenant_id = get_tenant_id();

// Asegurar que la tabla integrations tenga tenant_id
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM `integrations` LIKE 'tenant_id'");
    if (!$checkColumn->fetch()) {
        $pdo->exec("ALTER TABLE `integrations` ADD COLUMN tenant_id INT NOT NULL AFTER id");
        $pdo->exec("ALTER TABLE `integrations` DROP INDEX idx_provider_key");
        $pdo->exec("ALTER TABLE `integrations` ADD UNIQUE KEY idx_tenant_provider_key (tenant_id, provider, setting_key)");
        $pdo->exec("UPDATE `integrations` SET tenant_id = $tenant_id WHERE tenant_id = 0");
    }
} catch (Throwable $e) {}

$pageTitle = 'Integraciones Externas';
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error_msg = 'Error de seguridad (CSRF).';
    } else {
        $provider = $_POST['provider'];
        
        if ($provider === 'teams') {
            $webhook_url = $_POST['teams_webhook_url'];
            $is_active = isset($_POST['teams_active']) ? 1 : 0;
            
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'teams', 'webhook_url', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $webhook_url, $is_active]);
            $success_msg = 'Configuración de Microsoft Teams guardada.';
        } elseif ($provider === 'telegram') {
            $bot_token = $_POST['telegram_bot_token'];
            $chat_id = $_POST['telegram_chat_id'];
            $is_active = isset($_POST['telegram_active']) ? 1 : 0;
            
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'telegram', 'bot_token', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $bot_token, $is_active]);
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'telegram', 'chat_id', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $chat_id, $is_active]);

            // Auto-configurar Webhook al guardar si es activo
            if ($is_active && !empty($bot_token)) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $webhook_url = $protocol . $host . "/telegram_webhook.php?tenant_id=" . $tenant_id;
                
                // Guardar la URL en BD para referencia
                $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                               VALUES (?, 'telegram', 'webhook_url', ?, ?) 
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                    ->execute([$tenant_id, $webhook_url, $is_active]);

                // Asegurar HTTPS para Telegram (Requisito obligatorio)
                $webhook_url_https = str_replace('http://', 'https://', $webhook_url);
                
                require_once __DIR__ . '/includes/security.php';
                $api_url = "https://api.telegram.org/bot$bot_token/setWebhook?url=" . urlencode($webhook_url_https) . "&secret_token=" . urlencode(telegram_webhook_secret((int)$tenant_id));
                $res = @file_get_contents($api_url);
                
                if ($res) {
                    $res_data = json_decode($res, true);
                    if (!$res_data['ok']) {
                        $error_msg = "Telegram guardado, pero falló el Webhook: " . $res_data['description'];
                    }
                } else {
                    $error_msg = "No se pudo contactar con la API de Telegram para el Webhook.";
                }
            }

            if (empty($error_msg)) {
                $success_msg = 'Configuración de Telegram guardada y Webhook actualizado (HTTPS).';
            }
        } elseif ($provider === 'whatsapp') {
            $api_key = $_POST['whatsapp_api_key'];
            $phone_id = $_POST['whatsapp_phone_id'];
            $verify_token = $_POST['whatsapp_verify_token'];
            $app_secret = trim($_POST['whatsapp_app_secret'] ?? '');
            $waba_id = preg_replace('/\D/', '', $_POST['whatsapp_waba_id'] ?? '');
            $is_active = isset($_POST['whatsapp_active']) ? 1 : 0;
            
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'whatsapp', 'api_key', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $api_key, $is_active]);
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'whatsapp', 'phone_id', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $phone_id, $is_active]);
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'whatsapp', 'verify_token', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $verify_token, $is_active]);

            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'whatsapp', 'app_secret', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $app_secret, $is_active]);
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'whatsapp', 'waba_id', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $waba_id, $is_active]);

            if ($is_active) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $webhook_url = $protocol . $host . "/whatsapp_webhook.php?tenant_id=" . $tenant_id;
                
                $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                               VALUES (?, 'whatsapp', 'webhook_url', ?, ?) 
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                    ->execute([$tenant_id, $webhook_url, $is_active]);
            }

            $success_msg = 'Configuración de WhatsApp Cloud API guardada.';

            // Suscribir automáticamente la cuenta de WhatsApp (WABA) a esta app
            if ($is_active && $waba_id !== '' && $api_key !== '') {
                $wa_subscribe = function (array $fields) use ($waba_id, $api_key) {
                    $ch = curl_init("https://graph.facebook.com/v23.0/{$waba_id}/subscribed_apps");
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key],
                        CURLOPT_POSTFIELDS => http_build_query($fields),
                    ]);
                    $res = json_decode((string)curl_exec($ch), true);
                    curl_close($ch);
                    return $res;
                };
                // 1) Con la URL propia de este tenant
                $sub_res = $wa_subscribe(['override_callback_uri' => $webhook_url, 'verify_token' => $verify_token]);
                if (!empty($sub_res['success'])) {
                    $success_msg = 'WhatsApp guardado y cuenta suscrita al webhook correctamente.';
                } else {
                    // 2) Reintento sin override: usa la URL configurada en la app de Meta
                    $sub_res2 = $wa_subscribe([]);
                    if (!empty($sub_res2['success'])) {
                        $success_msg = 'WhatsApp guardado y cuenta suscrita (usando el webhook configurado en la app de Meta).';
                    } else {
                        $error_msg = 'WhatsApp guardado, pero falló la suscripción: ' . ($sub_res2['error']['message'] ?? $sub_res['error']['message'] ?? 'sin respuesta de Meta (revisa token y WABA ID)');
                    }
                }
            } elseif ($is_active && $waba_id === '') {
                $error_msg = 'WhatsApp guardado, pero falta el WABA ID para suscribir la cuenta automáticamente.';
            }
        } elseif ($provider === 'email_imap') {
            $host = $_POST['imap_host'];
            $port = $_POST['imap_port'];
            $user = $_POST['imap_user'];
            $pass = $_POST['imap_pass'];
            $is_active = isset($_POST['imap_active']) ? 1 : 0;
            
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'email_imap', 'host', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $host, $is_active]);
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'email_imap', 'port', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $port, $is_active]);
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'email_imap', 'user', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $user, $is_active]);
            $pdo->prepare("INSERT INTO integrations (tenant_id, provider, setting_key, setting_value, is_active) 
                           VALUES (?, 'email_imap', 'pass', ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_active = VALUES(is_active)")
                ->execute([$tenant_id, $pass, $is_active]);
            $success_msg = 'Configuración de lectura de Email guardada.';
        }
    }
}

// Obtener todas las integraciones filtradas por tenant_id
$stmt = $pdo->prepare("SELECT provider, setting_key, setting_value, is_active FROM integrations WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['provider']][$row['setting_key']] = [
        'value' => $row['setting_value'],
        'active' => $row['is_active']
    ];
}

require_once 'includes/navbar.php';
?>

<?php
$open = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (string)($_POST['provider'] ?? '') : '';
$val = fn(string $p, string $k): string => (string)($settings[$p][$k]['value'] ?? '');
$act = fn(string $p, string $k): bool => !empty($settings[$p][$k]['active']);

/** [texto, color bootstrap, icono] según haya datos y esté activa. */
function int_status(bool $filled, bool $active): array {
    if (!$filled) return ['Sin configurar', 'secondary', 'fa-circle'];
    return $active ? ['Activa', 'success', 'fa-check-circle'] : ['Pausada', 'warning', 'fa-pause-circle'];
}

function int_head(string $id, string $title, string $subtitle, string $icon_html, array $status, bool $open): void { ?>
    <button class="int-head <?php echo $open ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#c-<?php echo $id; ?>" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="c-<?php echo $id; ?>">
        <span class="int-icon"><?php echo $icon_html; ?></span>
        <span class="int-title"><strong><?php echo htmlspecialchars($title); ?></strong><small><?php echo htmlspecialchars($subtitle); ?></small></span>
        <span class="badge rounded-pill text-bg-<?php echo $status[1]; ?> int-badge"><i class="fas <?php echo $status[2]; ?> me-1"></i><?php echo $status[0]; ?></span>
        <i class="fas fa-chevron-down int-chevron"></i>
    </button>
<?php }

$st_teams    = int_status($val('teams', 'webhook_url') !== '', $act('teams', 'webhook_url'));
$st_telegram = int_status($val('telegram', 'bot_token') !== '', $act('telegram', 'bot_token'));
$st_whatsapp = int_status($val('whatsapp', 'api_key') !== '' && $val('whatsapp', 'phone_id') !== '', $act('whatsapp', 'api_key'));
$st_imap     = int_status($val('email_imap', 'host') !== '', $act('email_imap', 'host'));
$all_status  = [$st_teams, $st_telegram, $st_whatsapp, $st_imap];
$n_active    = count(array_filter($all_status, fn($s) => $s[0] === 'Activa'));
?>

<div class="container-fluid px-4 compact-container" style="margin-bottom: 30px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center my-3 gap-2">
        <div>
            <h5 class="fw-bold mb-1"><i class="fas fa-plug me-2 text-primary"></i>Integraciones</h5>
            <p class="text-muted small mb-0">Conecta tu Help Desk con otras plataformas. Abre cada tarjeta para ver qué hace y configurarla.
                <span class="badge bg-secondary ms-1" title="ID de instancia actual">Instancia <?php echo (int)$tenant_id; ?></span></p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill text-bg-light border"><i class="fas fa-bolt text-warning me-1"></i><?php echo $n_active; ?> de 4 activas</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="int-expand">Abrir todas</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="int-collapse">Cerrar todas</button>
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

    <div class="int-section-title"><i class="fas fa-comments me-2"></i>Mensajería y notificaciones</div>
    <div class="row g-3 align-items-start mb-4">

        <!-- Microsoft Teams -->
        <div class="col-xl-4 col-md-6" id="int-teams">
            <div class="card border-0 shadow-sm integration-card">
                <?php int_head('teams', 'Microsoft Teams', 'Avisos en un canal de tu equipo',
                    '<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/0/07/Microsoft_Office_Teams_%282025%E2%80%93present%29.svg/1280px-Microsoft_Office_Teams_%282025%E2%80%93present%29.svg.png" width="26" alt="Teams">',
                    $st_teams, $open === 'teams'); ?>
                <div id="c-teams" class="collapse <?php echo $open === 'teams' ? 'show' : ''; ?>">
                    <div class="int-body">
                        <div class="int-what">
                            <div class="int-what-title"><i class="fas fa-circle-info me-1"></i>¿Qué hace?</div>
                            <ul>
                                <li>Publica un aviso en tu canal cuando se crea o cambia un ticket.</li>
                                <li>Sirve para que el equipo se entere sin abrir la plataforma.</li>
                            </ul>
                        </div>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="provider" value="teams">
                            <div class="mb-3">
                                <label class="form-label">Webhook URL</label>
                                <input type="url" name="teams_webhook_url" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('teams', 'webhook_url')); ?>" placeholder="https://outlook.office.com/...">
                                <div class="form-text">En Teams: canal → ⋯ → Flujos de trabajo / Conectores → «Webhook entrante». Copia la URL que te da.</div>
                            </div>
                            <div class="form-check form-switch small mb-3">
                                <input class="form-check-input" type="checkbox" name="teams_active" id="teamsActive" <?php echo $act('teams', 'webhook_url') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-muted" for="teamsActive">Enviar avisos a Teams</label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 rounded-pill shadow-sm">Guardar cambios</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Telegram Bot -->
        <div class="col-xl-4 col-md-6" id="int-telegram">
            <div class="card border-0 shadow-sm integration-card">
                <?php int_head('telegram', 'Telegram Bot', 'Avisos y atención por Telegram',
                    '<i class="fab fa-telegram text-info fs-3"></i>', $st_telegram, $open === 'telegram'); ?>
                <div id="c-telegram" class="collapse <?php echo $open === 'telegram' ? 'show' : ''; ?>">
                    <div class="int-body">
                        <div class="int-what">
                            <div class="int-what-title"><i class="fas fa-circle-info me-1"></i>¿Qué hace?</div>
                            <ul>
                                <li>Envía avisos de tickets a un grupo o chat privado.</li>
                                <li>Permite que tus clientes escriban al bot y los atiendas desde Multicanal.</li>
                            </ul>
                        </div>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="provider" value="telegram">
                            <div class="mb-3">
                                <label class="form-label">Bot Token</label>
                                <input type="password" name="telegram_bot_token" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('telegram', 'bot_token')); ?>" placeholder="123456789:ABCDefgh..." autocomplete="off">
                                <div class="form-text">Te lo da <b>@BotFather</b> en Telegram al crear el bot con <code>/newbot</code>.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Chat ID</label>
                                <input type="text" name="telegram_chat_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('telegram', 'chat_id')); ?>" placeholder="-10012345678">
                                <div class="form-text">Dónde llegan los avisos. Los grupos empiezan con <code>-100</code>.</div>
                            </div>
                            <div class="form-check form-switch small mb-3">
                                <input class="form-check-input" type="checkbox" name="telegram_active" id="telegramActive" <?php echo $act('telegram', 'bot_token') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-muted" for="telegramActive">Activar el bot</label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 rounded-pill shadow-sm">Guardar cambios</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- WhatsApp Business API -->
        <div class="col-xl-4 col-md-6" id="int-whatsapp">
            <div class="card border-0 shadow-sm integration-card">
                <?php int_head('whatsapp', 'WhatsApp Business', 'Tickets y chat con clientes',
                    '<i class="fab fa-whatsapp text-success fs-3"></i>', $st_whatsapp, $open === 'whatsapp'); ?>
                <div id="c-whatsapp" class="collapse <?php echo $open === 'whatsapp' ? 'show' : ''; ?>">
                    <div class="int-body">
                        <div class="int-what">
                            <div class="int-what-title"><i class="fas fa-circle-info me-1"></i>¿Qué hace?</div>
                            <ul>
                                <li>Recibe los mensajes de tus clientes y los convierte en tickets o en un chat con un agente.</li>
                                <li>Un asistente responde automáticamente. <a href="asistente_bot.php">Personalizar asistente <i class="fas fa-arrow-right small"></i></a></li>
                            </ul>
                        </div>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="provider" value="whatsapp">
                            <div class="mb-3">
                                <label class="form-label">Access Token</label>
                                <input type="password" name="whatsapp_api_key" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('whatsapp', 'api_key')); ?>" autocomplete="off">
                                <div class="form-text">Meta for Developers → tu app → WhatsApp → <i>Configuración de la API</i>. El temporal vence en 24 h; para producción usa uno permanente.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone ID</label>
                                <input type="text" name="whatsapp_phone_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('whatsapp', 'phone_id')); ?>">
                                <div class="form-text">Identificador del número de teléfono (no es el número).</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">WABA ID</label>
                                <input type="text" name="whatsapp_waba_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('whatsapp', 'waba_id')); ?>" placeholder="Ej: 1799390824666531">
                                <div class="form-text">«Identificador de la cuenta de mensajes». Se usa para suscribir tu cuenta automáticamente.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Verify Token (Webhook)</label>
                                <input type="text" name="whatsapp_verify_token" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('whatsapp', 'verify_token')); ?>" placeholder="Ej: my_secure_token">
                                <div class="form-text">Una clave que inventas tú. Debe ser idéntica en Meta al registrar el webhook.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">App Secret</label>
                                <input type="password" name="whatsapp_app_secret" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('whatsapp', 'app_secret')); ?>" placeholder="Meta: Configuración de la app → Básica" autocomplete="off">
                                <div class="form-text">Sirve para comprobar que los mensajes realmente vienen de Meta.</div>
                            </div>
                            <?php if ($val('whatsapp', 'webhook_url') !== ''): ?>
                            <div class="mb-3 p-2 bg-light rounded border">
                                <label class="form-label extra-small text-primary mb-1">Webhook URL para Meta (Facebook Developers):</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control extra-small" id="wa-webhook-url" value="<?php echo htmlspecialchars($val('whatsapp', 'webhook_url')); ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" data-copy="#wa-webhook-url" title="Copiar"><i class="far fa-copy"></i></button>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="form-check form-switch small mb-3">
                                <input class="form-check-input" type="checkbox" name="whatsapp_active" id="whatsappActive" <?php echo $act('whatsapp', 'api_key') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-muted" for="whatsappActive">Activar recepción de mensajes</label>
                            </div>
                            <button type="submit" class="btn btn-success btn-sm w-100 rounded-pill shadow-sm">Guardar WhatsApp</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="int-section-title"><i class="fas fa-envelope me-2"></i>Correo electrónico</div>
    <div class="row g-3 align-items-start mb-4">
        <!-- Email IMAP (Reading) -->
        <div class="col-xl-4 col-md-6" id="int-email_imap">
            <div class="card border-0 shadow-sm integration-card">
                <?php int_head('email_imap', 'Correo a ticket (IMAP)', 'Cada correo nuevo, un ticket',
                    '<i class="fas fa-envelope-open-text text-primary fs-4"></i>', $st_imap, $open === 'email_imap'); ?>
                <div id="c-email_imap" class="collapse <?php echo $open === 'email_imap' ? 'show' : ''; ?>">
                    <div class="int-body">
                        <div class="int-what">
                            <div class="int-what-title"><i class="fas fa-circle-info me-1"></i>¿Qué hace?</div>
                            <ul>
                                <li>Revisa una bandeja de entrada y convierte cada correo nuevo en un ticket.</li>
                                <li>Los clientes pueden pedir ayuda escribiendo a tu correo de soporte.</li>
                            </ul>
                        </div>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="provider" value="email_imap">
                            <div class="row g-2 mb-3">
                                <div class="col-8">
                                    <label class="form-label">Servidor</label>
                                    <input type="text" name="imap_host" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('email_imap', 'host')); ?>" placeholder="imap.gmail.com">
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Puerto</label>
                                    <input type="number" name="imap_port" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('email_imap', 'port') ?: '993'); ?>">
                                </div>
                                <div class="col-12"><div class="form-text mt-0">Casi siempre el puerto es <b>993</b> (conexión segura).</div></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Usuario / Correo</label>
                                <input type="text" name="imap_user" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('email_imap', 'user')); ?>" placeholder="soporte@tuempresa.com">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" name="imap_pass" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val('email_imap', 'pass')); ?>" autocomplete="off">
                                <div class="form-text">En Gmail u Outlook usa una <b>contraseña de aplicación</b>, no la normal.</div>
                            </div>
                            <div class="form-check form-switch small mb-3">
                                <input class="form-check-input" type="checkbox" name="imap_active" id="imapActive" <?php echo $act('email_imap', 'host') ? 'checked' : ''; ?>>
                                <label class="form-check-label text-muted" for="imapActive">Leer la bandeja de entrada</label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100 rounded-pill shadow-sm">Guardar IMAP</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Diseño de correos (enlace) -->
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm integration-card">
                <a class="int-head text-decoration-none" href="email_diseno.php">
                    <span class="int-icon"><i class="fas fa-palette text-primary fs-4"></i></span>
                    <span class="int-title"><strong>Diseño de los correos</strong><small>Logo, colores y textos de las notificaciones</small></span>
                    <i class="fas fa-arrow-right text-muted"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .integration-card { border: 1px solid rgba(0,0,0,0.05) !important; overflow: hidden; }
    .integration-card:hover { transform: none; }
    .extra-small { font-size: 0.72rem; }
    .int-section-title { font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; margin: 0 2px 10px; }
    .int-head { width: 100%; display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: transparent; border: 0; text-align: left; color: inherit; cursor: pointer; }
    .int-head:hover { background: rgba(13,110,253,.04); }
    .int-icon { width: 42px; height: 42px; flex: none; display: flex; align-items: center; justify-content: center; background: #f1f5f9; border-radius: 12px; }
    .int-title { display: flex; flex-direction: column; min-width: 0; flex: 1; line-height: 1.25; }
    .int-title strong { font-size: .92rem; color: #0f172a; }
    .int-title small { font-size: .74rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .int-badge { font-size: .66rem; font-weight: 600; flex: none; }
    .int-chevron { font-size: .75rem; color: #94a3b8; transition: transform .2s ease; flex: none; }
    .int-head:not(.collapsed) .int-chevron { transform: rotate(180deg); }
    .int-body { padding: 4px 16px 16px; border-top: 1px dashed #e5e7eb; }
    .int-what { background: #f8fafc; border-radius: 10px; padding: 10px 12px; margin: 12px 0 14px; font-size: .78rem; color: #475569; }
    .int-what-title { font-weight: 700; color: #0f172a; margin-bottom: 4px; font-size: .75rem; }
    .int-what ul { margin: 0; padding-left: 1.1rem; }
    .int-what li + li { margin-top: 2px; }
    .int-body .form-label { font-size: .75rem; font-weight: 600; color: #334155; margin-bottom: 3px; }
    .int-body .form-text { font-size: .7rem; margin-top: 3px; }
    [data-bs-theme="dark"] .int-icon, [data-bs-theme="dark"] .int-what { background: #161826; }
    [data-bs-theme="dark"] .int-title strong, [data-bs-theme="dark"] .int-what-title { color: #e4e6eb; }
    [data-bs-theme="dark"] .int-body { border-top-color: #2d304d; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cards = () => document.querySelectorAll('.integration-card .collapse');
    const hasBS = () => typeof bootstrap !== 'undefined' && bootstrap.Collapse;

    function setOpen(panel, open) {
        if (hasBS()) {
            const inst = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
            open ? inst.show() : inst.hide();
        } else {                                   // respaldo sin Bootstrap
            panel.classList.toggle('show', open);
            const btn = document.querySelector('[data-bs-target="#' + panel.id + '"]');
            if (btn) { btn.classList.toggle('collapsed', !open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        }
    }

    // Respaldo: si Bootstrap no llegó a cargar, las cabeceras se abren/cierran igual
    window.addEventListener('load', function () {
        if (hasBS()) return;
        document.querySelectorAll('.int-head[data-bs-toggle="collapse"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const panel = document.querySelector(btn.dataset.bsTarget);
                if (panel) setOpen(panel, !panel.classList.contains('show'));
            });
        });
        document.querySelectorAll('[data-bs-dismiss="alert"]').forEach(b => b.addEventListener('click', () => b.closest('.alert')?.remove()));
    });

    document.getElementById('int-expand').addEventListener('click', () => cards().forEach(c => setOpen(c, true)));
    document.getElementById('int-collapse').addEventListener('click', () => cards().forEach(c => setOpen(c, false)));

    // Abrir la tarjeta indicada en la URL (…/integraciones#whatsapp)
    const h = location.hash.replace('#', '');
    if (h) { const c = document.getElementById('c-' + h); if (c) setOpen(c, true); }

    // Botones "copiar"
    document.querySelectorAll('[data-copy]').forEach(btn => btn.addEventListener('click', async () => {
        const input = document.querySelector(btn.dataset.copy);
        if (!input) return;
        try { await navigator.clipboard.writeText(input.value); } catch (e) { input.select(); document.execCommand('copy'); }
        const icon = btn.querySelector('i'); const prev = icon.className;
        icon.className = 'fas fa-check text-success';
        setTimeout(() => icon.className = prev, 1200);
    }));
});
</script>

<?php require_once 'includes/footer.php'; ?>