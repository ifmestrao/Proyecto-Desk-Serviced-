<?php
require_once 'includes/header.php';
checkAuth();
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor') {
    header("Location: dashboard.php");
    exit;
}
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';
require_once 'includes/bot_config.php';

$pageTitle = 'Asistente de WhatsApp';
$tenant_id = (int)get_tenant_id();
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error_msg = 'Error de seguridad CSRF.';
    } elseif (isset($_POST['reset_defaults'])) {
        ts_delete($pdo, $tenant_id, 'bot_config');
        $success_msg = 'Se restauró la configuración predeterminada del asistente.';
    } else {
        $current = bot_get_config($pdo, $tenant_id);
        $new = bot_sanitize_config($_POST, true);

        if (!empty($_POST['remove_logo'])) {
            $new['logo_url'] = '';
        } elseif (!empty($_FILES['logo_file']['name'])) {
            $res = ts_save_logo($_FILES['logo_file'], 'bot', $tenant_id);
            if ($res['ok']) {
                $new['logo_url'] = $res['url'];
            } else {
                $error_msg = $res['error'];
                $new['logo_url'] = $current['logo_url'];
            }
        }
        if ($error_msg === '') {
            if (ts_set($pdo, $tenant_id, 'bot_config', $new)) {
                $success_msg = 'Asistente actualizado. Los cambios aplican a los próximos mensajes.';
            } else {
                $error_msg = 'No se pudo guardar la configuración.';
            }
        }
    }
}

$cfg = bot_get_config($pdo, $tenant_id);
$company = bot_company_name($pdo, $tenant_id, $cfg);
$msg_labels = [
    'ticket_start'        => 'Inicio de ticket',
    'recognized'          => 'Cliente reconocido por teléfono',
    'email_found'         => 'Correo encontrado en la plataforma',
    'name_suggestion'     => 'Sugerencia de nombre (perfil de WhatsApp)',
    'invalid_email'       => 'Correo inválido',
    'invalid_text'        => 'Texto muy corto',
    'invalid_priority'    => 'Prioridad inválida',
    'attach_hint'         => 'Recordatorio de adjuntos',
    'attachment_saved'    => 'Archivo recibido',
    'attachment_limit'    => 'Límite de archivos',
    'attachment_rejected' => 'Archivo rechazado',
    'media_in_menu'       => 'Archivo enviado fuera de un flujo',
    'unsupported'         => 'Tipo de mensaje no soportado',
    'ticket_created'      => 'Ticket creado',
    'ticket_error'        => 'Error al crear ticket',
    'agent_ask_name'      => 'Pedir nombre para hablar con agente',
    'agent_waiting'       => 'Esperando agente',
    'chat_ended'          => 'Chat finalizado',
    'cancelled'           => 'Proceso cancelado',
    'ai_footer'           => 'Pie de respuesta de la IA',
];
$field_labels = [
    'email'       => ['Correo electrónico', 'Siempre se solicita (si no se reconoce al cliente).', true],
    'full_name'   => ['Nombre completo',    'Solo se pide si no se reconoce al cliente.', true],
    'description' => ['Descripción del problema', 'Siempre se solicita.', true],
    'area'        => ['Área / departamento', 'Si el cliente ya tiene área en la plataforma, no se pregunta.', false],
    'priority'    => ['Prioridad',          'Si se desactiva, se usa la prioridad por defecto.', false],
];
?>
<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container" style="margin-bottom: 50px;" >
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <h5 class="fw-bold mb-0"><i class="fab text-success"></i>Asistente de WhatsApp</h5>
            <p class="text-muted small mb-0">Define qué dice el asistente, qué datos pide y cómo los valida. La vista previa se actualiza mientras escribes.</p>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show p-2 small border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?><button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show p-2 small border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?><button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <form id="bot-form" method="POST" enctype="multipart/form-data">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="logo_url" id="logo_url" value="<?php echo htmlspecialchars($cfg['logo_url']); ?>">
        <div class="row g-3">
            <!-- ───────── Editor ───────── -->
            <div class="col-lg-7">
                <!-- Identidad y bienvenida -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-bold small"><i class="fas fa-hand-sparkles me-2 text-success"></i>Bienvenida</div>
                    <div class="card-body">
                        <div class="row g-3 mb-2">
                            <div class="col-md-5">
                                <label class="form-label small fw-bold">Nombre en el saludo <code>{empresa}</code></label>
                                <input type="text" name="company_name" class="form-control form-control-sm" maxlength="60" placeholder="<?php echo htmlspecialchars($company); ?>" value="<?php echo htmlspecialchars($cfg['company_name']); ?>">
                                <div class="form-text">Vacío = nombre de tu organización.</div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-bold">Imagen de la empresa</label>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="border rounded bg-light d-flex align-items-center justify-content-center" style="width:64px;height:44px;flex:none;">
                                        <img id="logo-thumb" src="<?php echo htmlspecialchars($cfg['logo_url']); ?>" alt="" style="max-width:100%;max-height:100%;<?php echo $cfg['logo_url'] ? '' : 'display:none;'; ?>">
                                        <span id="logo-empty" class="text-muted" style="font-size:.7rem;<?php echo $cfg['logo_url'] ? 'display:none;' : ''; ?>">Sin imagen</span>
                                    </div>
                                    <input type="file" name="logo_file" id="logo_file" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
                                </div>
                                <div class="d-flex gap-3 mt-1">
                                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="send_logo" id="send_logo" value="1" <?php echo !empty($cfg['send_logo']) ? 'checked' : ''; ?>><label class="form-check-label small" for="send_logo">Enviar con el saludo</label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_logo" id="remove_logo" value="1"><label class="form-check-label small" for="remove_logo">Quitar imagen</label></div>
                                </div>
                            </div>
                        </div>
                        <label class="form-label small fw-bold">Mensaje de bienvenida</label>
                        <textarea name="welcome" rows="3" maxlength="600" class="form-control form-control-sm mb-2"><?php echo htmlspecialchars($cfg['welcome']); ?></textarea>
                        <label class="form-label small fw-bold">Opciones del menú</label>
                        <textarea name="menu" rows="4" maxlength="600" class="form-control form-control-sm"><?php echo htmlspecialchars($cfg['menu']); ?></textarea>
                        <div class="form-text">
                            Variables: <code>{nombre}</code> <code>{nombre_completo}</code> <code>{correo}</code> <code>{empresa}</code>.
                            Formato de WhatsApp: <code>*negrita*</code> <code>_cursiva_</code>.
                            La imagen se envía arriba y el texto va debajo, como pie de foto (máx. ~1000 caracteres).
                        </div>
                    </div>
                </div>

                <!-- Datos a solicitar -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-bold small"><i class="fas fa-list-check me-2 text-success"></i>Datos que pide para crear un ticket</div>
                    <div class="card-body">
                        <div class="alert alert-light border small py-2">
                            <i class="fas fa-bolt text-warning me-1"></i>
                            El asistente reconoce al cliente por su <strong>número de teléfono</strong> (campo teléfono del usuario) o por su <strong>correo</strong>,
                            y solo pregunta lo que falta.
                        </div>
                        <?php foreach ($field_labels as $key => [$label, $hint, $locked]): $f = $cfg['fields'][$key]; ?>
                            <div class="border rounded p-2 mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div><span class="fw-bold small"><?php echo $label; ?></span> <span class="text-muted" style="font-size:.75rem;">— <?php echo $hint; ?></span></div>
                                    <?php if ($locked): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Obligatorio</span>
                                    <?php else: ?>
                                        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="fields[<?php echo $key; ?>][enabled]" value="1" <?php echo !empty($f['enabled']) ? 'checked' : ''; ?>><label class="form-check-label small">Preguntar</label></div>
                                    <?php endif; ?>
                                </div>
                                <textarea name="fields[<?php echo $key; ?>][question]" rows="<?php echo $key === 'priority' ? 4 : 2; ?>" maxlength="400" class="form-control form-control-sm"><?php echo htmlspecialchars($f['question']); ?></textarea>
                                <div class="row g-2 mt-1">
                                    <?php if (isset($f['min'])): ?>
                                        <div class="col-auto"><label class="small text-muted">Longitud mínima</label>
                                            <input type="number" min="0" max="500" name="fields[<?php echo $key; ?>][min]" value="<?php echo (int)$f['min']; ?>" class="form-control form-control-sm" style="width:90px;">
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($key === 'area'): ?>
                                        <div class="col"><label class="small text-muted">Área por defecto (si no se pregunta)</label>
                                            <input type="text" maxlength="60" name="fields[area][default]" value="<?php echo htmlspecialchars($f['default']); ?>" class="form-control form-control-sm"></div>
                                    <?php endif; ?>
                                    <?php if ($key === 'priority'): ?>
                                        <div class="col-auto"><label class="small text-muted">Prioridad por defecto</label>
                                            <select name="fields[priority][default]" class="form-select form-select-sm">
                                                <?php foreach (['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Crítica'] as $pv => $pl): ?>
                                                    <option value="<?php echo $pv; ?>" <?php echo ($f['default'] ?? 'medium') === $pv ? 'selected' : ''; ?>><?php echo $pl; ?></option>
                                                <?php endforeach; ?>
                                            </select></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="border rounded p-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small"><i class="fas fa-paperclip me-1"></i>Adjuntar imágenes y archivos</span>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="allow_attachments" value="1" <?php echo !empty($cfg['allow_attachments']) ? 'checked' : ''; ?>><label class="form-check-label small">Permitir</label></div>
                            </div>
                            <textarea name="attach_question" rows="2" maxlength="400" class="form-control form-control-sm"><?php echo htmlspecialchars($cfg['attach_question']); ?></textarea>
                            <div class="form-text">JPG, PNG, WEBP, PDF, Word, Excel, PowerPoint, TXT o CSV. Hasta 5 archivos de 10 MB por ticket.</div>
                        </div>
                    </div>
                </div>

                <!-- Mensajes -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-bold small" role="button" data-bs-toggle="collapse" data-bs-target="#msgs-collapse">
                        <i class="fas fa-comments me-2 text-success"></i>Otros mensajes del asistente <i class="fas fa-chevron-down float-end text-muted"></i>
                    </div>
                    <div id="msgs-collapse" class="collapse">
                        <div class="card-body">
                            <div class="form-text mb-2">Variables según el mensaje: <code>{nombre}</code> <code>{ticket}</code> <code>{min}</code> <code>{n}</code> <code>{max}</code> <code>{wa_name}</code></div>
                            <?php foreach ($msg_labels as $k => $label): ?>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold mb-1"><?php echo $label; ?></label>
                                    <textarea name="msgs[<?php echo $k; ?>]" rows="2" maxlength="500" class="form-control form-control-sm"><?php echo htmlspecialchars($cfg['msgs'][$k]); ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mb-4">
                    <button type="submit" class="btn btn-success btn-sm px-4"><i class="fas fa-save me-1"></i>Guardar asistente</button>
                    <button type="submit" name="reset_defaults" value="1" class="btn btn-outline-secondary btn-sm" onclick="return confirm('¿Restaurar los textos predeterminados del asistente?');">Restaurar predeterminado</button>
                </div>
            </div>

            <!-- ───────── Vista previa ───────── -->
            <div class="col-lg-5">
                <div style="position:sticky; top:12px;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold small"><i class="fas fa-eye me-2 text-success"></i>Vista previa</span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active" data-scn="new">Cliente nuevo</button>
                            <button type="button" class="btn btn-outline-secondary" data-scn="known">Cliente reconocido</button>
                        </div>
                    </div>
                    <div class="wa-phone shadow">
                        <div class="wa-head">
                            <div class="wa-avatar" id="wa-avatar"></div>
                            <div><div class="fw-bold" id="wa-name"></div><div style="font-size:.7rem;opacity:.8;">en línea</div></div>
                        </div>
                        <div class="wa-body" id="wa-body"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
    .wa-phone { background:#ECE5DD; border-radius:22px; overflow:hidden; border:6px solid #1f2937; max-width:380px; margin:0 auto; }
    .wa-head { background:#075E54; color:#fff; padding:10px 14px; display:flex; align-items:center; gap:10px; }
    .wa-avatar { width:36px; height:36px; border-radius:50%; background:#cbd5e1 center/cover no-repeat; display:flex; align-items:center; justify-content:center; font-weight:700; color:#334155; flex:none; }
    .wa-body { padding:12px; height:560px; overflow-y:auto; display:flex; flex-direction:column; gap:6px;
               background-image: radial-gradient(rgba(0,0,0,.035) 1px, transparent 1px); background-size: 14px 14px; }
    .wa-msg { max-width:86%; padding:6px 9px; border-radius:9px; font-size:.82rem; line-height:1.35; box-shadow:0 1px 1px rgba(0,0,0,.12); word-break:break-word; }
    .wa-msg.bot { background:#fff; align-self:flex-start; border-top-left-radius:2px; }
    .wa-msg.user { background:#DCF8C6; align-self:flex-end; border-top-right-radius:2px; }
    .wa-msg img { width:100%; border-radius:7px; margin-bottom:5px; display:block; }
    .wa-msg .wa-ph { height:120px; background:#e5e7eb; border-radius:7px; margin-bottom:5px; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:.75rem; }
</style>

<script>
(function () {
    const form = document.getElementById('bot-form');
    const body = document.getElementById('wa-body');
    let scenario = 'new';
    let logoSrc = <?php echo json_encode($cfg['logo_url']); ?>;
    const defaultCompany = <?php echo json_encode($company); ?>;

    const el = (name) => form.elements[name];
    const v = (name) => (el(name) ? el(name).value : '');
    const on = (name) => { const e = el(name); return !!(e && e.checked); };

    function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function fmt(t) {
        return esc(t).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/_([^_\n]+)_/g, '<i>$1</i>').replace(/\n/g, '<br>');
    }
    function fill(t, vars) {
        return String(t).replace(/([ \t]?)\{(\w+)\}/g, (m, sp, k) => {
            if (!(k in vars)) return m;
            return vars[k] === '' ? '' : sp + vars[k];
        }).trim();
    }

    function render() {
        const company = v('company_name').trim() || defaultCompany;
        const known = scenario === 'known';
        const vars = {
            nombre: known ? 'María' : 'Carlos', nombre_completo: known ? 'María Pérez' : '', correo: known ? 'maria@empresa.com' : '',
            empresa: company, wa_name: 'Carlos Gómez', ticket: '1024', n: '1', max: '5', min: '10'
        };
        const items = [];
        const bot = (t, img) => items.push({ f: 'bot', t, img: !!img });
        const user = (t) => items.push({ f: 'user', t });
        const q = (k) => v('fields[' + k + '][question]');
        const msg = (k) => v('msgs[' + k + ']');

        user('Hola');
        bot(fill(v('welcome'), vars) + '\n\n' + fill(v('menu'), vars), on('send_logo') && !on('remove_logo') && !!logoSrc);
        user('1');
        bot(fill(msg('ticket_start'), vars));
        if (known) {
            bot(fill(msg('recognized'), vars));
        } else {
            bot(fill(q('email'), vars));
            user('carlos@empresa.com');
            bot(fill(q('full_name'), vars) + '\n' + fill(msg('name_suggestion'), vars));
            user('SI');
        }
        bot(fill(q('description'), vars));
        user('No puedo abrir mi correo desde ayer');
        if (on('allow_attachments')) {
            bot(fill(v('attach_question'), vars));
            user('📷 captura.jpg');
            bot(fill(msg('attachment_saved'), vars));
            user('LISTO');
        }
        if (!known && on('fields[area][enabled]')) { bot(fill(q('area'), vars)); user('Sistemas'); }
        if (on('fields[priority][enabled]')) { bot(fill(q('priority'), vars)); user('3'); }
        bot(fill(msg('ticket_created'), vars));

        body.innerHTML = items.map(i => {
            let img = '';
            if (i.img) img = logoSrc ? '<img src="' + esc(logoSrc) + '" alt="">' : '<div class="wa-ph">Imagen de la empresa</div>';
            return '<div class="wa-msg ' + i.f + '">' + img + fmt(i.t) + '</div>';
        }).join('');

        document.getElementById('wa-name').textContent = company;
        const av = document.getElementById('wa-avatar');
        if (logoSrc && !on('remove_logo')) { av.style.backgroundImage = 'url("' + logoSrc.replace(/"/g, '%22') + '")'; av.textContent = ''; }
        else { av.style.backgroundImage = 'none'; av.textContent = company.charAt(0).toUpperCase(); }
    }

    form.addEventListener('input', render);
    form.addEventListener('change', render);

    document.querySelectorAll('[data-scn]').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('[data-scn]').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        scenario = b.dataset.scn;
        render();
    }));

    document.getElementById('logo_file').addEventListener('change', function () {
        const f = this.files[0];
        if (!f) return;
        logoSrc = URL.createObjectURL(f);
        const t = document.getElementById('logo-thumb');
        t.src = logoSrc; t.style.display = '';
        document.getElementById('logo-empty').style.display = 'none';
        document.getElementById('remove_logo').checked = false;
        render();
    });

    render();
})();
</script>

<?php require_once 'includes/footer.php'; ?>