<?php
require_once 'includes/header.php';
checkAuth();
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor') {
    header("Location: dashboard.php");
    exit;
}
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';
require_once 'includes/email_templates.php';

$pageTitle = 'Diseño de correos';
$tenant_id = (int)get_tenant_id();
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error_msg = 'Error de seguridad CSRF.';
    } elseif (isset($_POST['reset_defaults'])) {
        ts_delete($pdo, $tenant_id, 'email_design');
        $success_msg = 'Se restauró el diseño predeterminado.';
    } else {
        $current = ts_get($pdo, $tenant_id, 'email_design', []);
        $current = $current ? ds_sanitize_config($current) : ds_default_config();
        $new = ds_sanitize_config($_POST);

        // Logo: si suben archivo, reemplaza; si marcan quitar, se vacía; si no, se conserva el escrito/actual
        if (!empty($_POST['remove_logo'])) {
            $new['brand']['logo_url'] = '';
        } elseif (!empty($_FILES['logo_file']['name'])) {
            $res = ts_save_logo($_FILES['logo_file'], 'email', $tenant_id);
            if ($res['ok']) {
                $new['brand']['logo_url'] = $res['url'];
            } else {
                $error_msg = $res['error'];
                $new['brand']['logo_url'] = $current['brand']['logo_url'];
            }
        }
        if ($error_msg === '') {
            if (ts_set($pdo, $tenant_id, 'email_design', $new)) {
                $success_msg = 'Diseño de correos guardado. Se aplicará a las próximas notificaciones.';
            } else {
                $error_msg = 'No se pudo guardar la configuración.';
            }
        }
    }
}

$stored = ts_get($pdo, $tenant_id, 'email_design', []);
$cfg = $stored ? ds_sanitize_config($stored) : ds_default_config();
$meta = ds_template_meta();
$labels = $meta['_labels'];
unset($meta['_labels']);
$defaults = ds_default_config();
?>
<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4 compact-container" style="margin-bottom: 50px;" >
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <h5 class="fw-bold mb-0"><i class="fas text-primary"></i>Diseño de correos</h5>
            <p class="text-muted small mb-0">Personaliza la marca y los textos de las notificaciones por correo de tu organización.</p>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show p-2 small border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?><button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show p-2 small border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?><button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <form id="design-form" method="POST" enctype="multipart/form-data">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="preview_tpl" id="preview_tpl" value="ticket_created">
        <div class="row g-3">
            <!-- ───────── Editor ───────── -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-bold small"><i class="fas fa-id-badge me-2 text-primary"></i>Marca</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Nombre de la organización</label>
                            <input type="text" name="brand[name]" class="form-control form-control-sm live" maxlength="60" value="<?php echo htmlspecialchars($cfg['brand']['name']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Logo</label>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="border rounded p-2 bg-light d-flex align-items-center justify-content-center" style="width:90px;height:50px;">
                                    <?php if (!empty($cfg['brand']['logo_url'])): ?>
                                        <img id="logo-thumb" src="<?php echo htmlspecialchars($cfg['brand']['logo_url']); ?>" alt="logo" style="max-width:100%;max-height:100%;">
                                    <?php else: ?>
                                        <span id="logo-thumb" class="text-muted small">Sin logo</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="logo_file" id="logo_file" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp,image/gif">
                                    <div class="form-text">PNG o JPG, máx. 2 MB. Se recomienda fondo transparente.</div>
                                </div>
                            </div>
                            <input type="hidden" name="brand[logo_url]" id="logo_url" value="<?php echo htmlspecialchars($cfg['brand']['logo_url']); ?>">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_logo" id="remove_logo" value="1"><label class="form-check-label small" for="remove_logo">Quitar logo</label></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-5">
                                <label class="form-label small fw-bold">Color principal</label>
                                <div class="input-group input-group-sm">
                                    <input type="color" id="color_picker" class="form-control form-control-color" value="<?php echo htmlspecialchars($cfg['brand']['color'] ?: '#1d4ed8'); ?>">
                                    <input type="text" name="brand[color]" id="color_text" class="form-control live" maxlength="7" placeholder="Auto" value="<?php echo htmlspecialchars($cfg['brand']['color']); ?>">
                                </div>
                                <div class="form-text">Vacío = colores originales.</div>
                            </div>
                            <div class="col-7">
                                <label class="form-label small fw-bold">Sitio web</label>
                                <input type="url" name="brand[site_url]" class="form-control form-control-sm live" maxlength="255" value="<?php echo htmlspecialchars($cfg['brand']['site_url']); ?>">
                            </div>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input live" type="checkbox" name="brand[show_footer]" id="show_footer" value="1" <?php echo !empty($cfg['brand']['show_footer']) ? 'checked' : ''; ?>>
                            <label class="form-check-label small fw-bold" for="show_footer">Mostrar pie de página</label>
                        </div>
                        <input type="text" name="brand[footer_text]" class="form-control form-control-sm live" maxlength="200" value="<?php echo htmlspecialchars($cfg['brand']['footer_text']); ?>">
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-bold small"><i class="fas fa-envelope-open-text me-2 text-primary"></i>Textos por tipo de correo</div>
                    <div class="card-body">
                        <ul class="nav nav-pills small mb-3 flex-wrap gap-1" id="tpl-tabs">
                            <?php $first = true; foreach ($meta as $key => $m): ?>
                                <li class="nav-item"><button type="button" class="nav-link py-1 px-2 <?php echo $first ? 'active' : ''; ?>" data-tpl="<?php echo $key; ?>"><?php echo htmlspecialchars($m['label']); ?></button></li>
                            <?php $first = false; endforeach; ?>
                        </ul>
                        <?php $first = true; foreach ($meta as $key => $m): ?>
                            <div class="tpl-pane" data-tpl="<?php echo $key; ?>" style="<?php echo $first ? '' : 'display:none'; ?>">
                                <div class="small text-muted mb-2">Variables:
                                    <?php foreach ($m['vars'] as $v): ?><code class="me-1">{<?php echo $v; ?>}</code><?php endforeach; ?>
                                </div>
                                <?php foreach ($m['fields'] as $f): ?>
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold mb-1"><?php echo htmlspecialchars($labels[$f]); ?></label>
                                        <?php $isLong = in_array($f, ['intro', 'body', 'cta_text'], true); ?>
                                        <?php if ($isLong): ?>
                                            <textarea name="tpl[<?php echo $key; ?>][<?php echo $f; ?>]" rows="2" maxlength="500" class="form-control form-control-sm live" data-default="<?php echo htmlspecialchars($defaults['templates'][$key][$f]); ?>"><?php echo htmlspecialchars($cfg['templates'][$key][$f]); ?></textarea>
                                        <?php else: ?>
                                            <input type="text" name="tpl[<?php echo $key; ?>][<?php echo $f; ?>]" maxlength="200" class="form-control form-control-sm live" data-default="<?php echo htmlspecialchars($defaults['templates'][$key][$f]); ?>" value="<?php echo htmlspecialchars($cfg['templates'][$key][$f]); ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php $first = false; endforeach; ?>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm px-4"><i class="fas fa-save me-1"></i>Guardar diseño</button>
                    <button type="submit" name="reset_defaults" value="1" class="btn btn-outline-secondary btn-sm" onclick="return confirm('¿Restaurar el diseño predeterminado?');">Restaurar predeterminado</button>
                </div>
            </div>

            <!-- ───────── Vista previa ───────── -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm" style="position:sticky; top:12px;">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold small"><i class="fas fa-eye me-2 text-primary"></i>Vista previa: <span id="preview-label"><?php echo htmlspecialchars(reset($meta)['label']); ?></span></span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active" id="view-desktop"><i class="fas fa-desktop"></i></button>
                            <button type="button" class="btn btn-outline-secondary" id="view-mobile"><i class="fas fa-mobile-alt"></i></button>
                        </div>
                    </div>
                    <div class="card-body p-2 bg-light text-center">
                        <iframe id="preview-frame" title="Vista previa del correo" sandbox="" style="width:100%;height:560px;border:0;background:#f1f5f9;border-radius:8px;transition:width .2s;"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('design-form');
    const frame = document.getElementById('preview-frame');
    const tplInput = document.getElementById('preview_tpl');
    const label = document.getElementById('preview-label');
    let timer = null, ctrl = null;

    async function refresh() {
        if (ctrl) ctrl.abort();
        ctrl = new AbortController();
        const fd = new FormData(form);
        fd.delete('logo_file');
        try {
            const res = await fetch('email_preview.php', { method: 'POST', body: fd, signal: ctrl.signal });
            frame.srcdoc = await res.text();
        } catch (e) { /* cancelado o sin red */ }
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(refresh, 350); }

    form.addEventListener('input', (e) => { if (e.target.classList.contains('live')) schedule(); });
    form.addEventListener('change', (e) => { if (e.target.classList.contains('live') || e.target.id === 'remove_logo') schedule(); });

    // Pestañas de plantillas
    document.querySelectorAll('#tpl-tabs .nav-link').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#tpl-tabs .nav-link').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const key = btn.dataset.tpl;
            document.querySelectorAll('.tpl-pane').forEach(p => p.style.display = (p.dataset.tpl === key ? '' : 'none'));
            tplInput.value = key;
            label.textContent = btn.textContent;
            refresh();
        });
    });

    // Color
    const cp = document.getElementById('color_picker'), ct = document.getElementById('color_text');
    cp.addEventListener('input', () => { ct.value = cp.value; schedule(); });
    ct.addEventListener('input', () => { if (/^#[0-9a-fA-F]{6}$/.test(ct.value)) cp.value = ct.value; });

    // Logo: miniatura local al elegir archivo (la vista previa de correo usa el logo guardado)
    document.getElementById('logo_file').addEventListener('change', function () {
        const f = this.files[0]; if (!f) return;
        const thumb = document.getElementById('logo-thumb');
        const url = URL.createObjectURL(f);
        if (thumb.tagName === 'IMG') { thumb.src = url; }
        else { const img = document.createElement('img'); img.id = 'logo-thumb'; img.src = url; img.style.cssText = 'max-width:100%;max-height:100%'; thumb.replaceWith(img); }
    });

    // Móvil / escritorio
    const dBtn = document.getElementById('view-desktop'), mBtn = document.getElementById('view-mobile');
    dBtn.addEventListener('click', () => { frame.style.width = '100%'; dBtn.classList.add('active'); mBtn.classList.remove('active'); });
    mBtn.addEventListener('click', () => { frame.style.width = '380px'; mBtn.classList.add('active'); dBtn.classList.remove('active'); });

    refresh();
})();
</script>

<?php require_once 'includes/footer.php'; ?>