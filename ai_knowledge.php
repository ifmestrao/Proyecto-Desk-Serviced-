<?php

require_once 'includes/header.php';
checkAuth();
require_once 'config/middleware_tenant.php';
require_once 'includes/csrf.php';
require_once 'includes/ai_docs.php';

$pageTitle = 'Centro de conocimiento IA';
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'supervisor', 'tech'], true)) {
    http_response_code(403);
    die('Acceso denegado: solo el personal de soporte puede administrar el centro de conocimiento.');
}
$tenantId = get_tenant_id();
$userId = (int)$_SESSION['user_id'];
ai_docs_ensure_schema($pdo);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------------ descargas y vista previa (GET) */

if (isset($_GET['download'])) {
    $st = $pdo->prepare('SELECT * FROM ai_documents WHERE id = ? AND tenant_id = ?');
    $st->execute([(int)$_GET['download'], $tenantId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    $path = $doc ? ai_docs_storage_dir($tenantId) . $doc['stored_name'] : '';
    if (!$doc || !is_file($path)) { http_response_code(404); die('Archivo no encontrado.'); }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    $inline = isset($_GET['inline']) && $doc['kind'] === 'image';
    $safeName = preg_replace('/[^\w.\- ()áéíóúñÁÉÍÓÚÑ]/u', '_', $doc['original_name']);
    header('Content-Type: ' . ($inline ? $doc['mime_type'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (isset($_GET['preview'])) {
    $st = $pdo->prepare('SELECT id, title, status, status_message, extract_method, char_count, chunk_count FROM ai_documents WHERE id = ? AND tenant_id = ?');
    $st->execute([(int)$_GET['preview'], $tenantId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!$doc) { echo json_encode(['ok' => false, 'message' => 'No encontrado']); exit; }
    $cs = $pdo->prepare('SELECT content FROM ai_doc_chunks WHERE document_id = ? AND tenant_id = ? ORDER BY chunk_index LIMIT 4');
    $cs->execute([$doc['id'], $tenantId]);
    $text = implode("\n\n[…]\n\n", $cs->fetchAll(PDO::FETCH_COLUMN));
    echo json_encode(['ok' => true, 'title' => $doc['title'], 'status' => $doc['status'], 'message' => $doc['status_message'], 'method' => $doc['extract_method'],
                      'chars' => (int)$doc['char_count'], 'chunks' => (int)$doc['chunk_count'], 'text' => mb_substr($text, 0, 5000)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ------------------------------------------------------------ acciones (POST) */

function ai_flash(bool $ok, string $msg): void { $_SESSION['ai_docs_flash'][] = ['ok' => $ok, 'msg' => $msg]; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        ai_flash(false, 'Los archivos superan lo que permite el servidor (post_max_size = ' . ini_get('post_max_size') . ', upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Sube menos archivos a la vez o más pequeños.');
        header('Location: ai_knowledge.php'); exit;
    }
    if (!csrf_check()) { ai_flash(false, 'Sesión expirada. Vuelve a intentarlo.'); header('Location: ai_knowledge.php'); exit; }
    @set_time_limit(600);
    ignore_user_abort(true);
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    try {
        if ($action === 'upload') {
            $files = [];
            $f = $_FILES['docs'] ?? null;
            if ($f && is_array($f['name'])) {
                foreach ($f['name'] as $i => $n) {
                    if ($n === '' && (int)$f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                    $files[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
                }
            }
            if (!$files) { ai_flash(false, 'No seleccionaste ningún archivo.'); }
            elseif (count($files) > 10) { ai_flash(false, 'Sube máximo 10 archivos por vez.'); }
            else {
                $title = count($files) === 1 ? trim((string)($_POST['title'] ?? '')) : '';
                foreach ($files as $file) {
                    $r = ai_docs_store_upload($pdo, $tenantId, $userId, $file, null, $title);
                    if (!$r['ok']) { ai_flash(false, '«' . $r['name'] . '»: ' . $r['message']); continue; }
                    $p = ai_docs_process($pdo, $r['id'], $tenantId);
                    ai_flash($p['ok'], '«' . $r['name'] . '»: ' . $p['message']);
                }
            }
        } elseif ($action === 'reprocess' && $id) {
            $p = ai_docs_process($pdo, $id, $tenantId);
            ai_flash($p['ok'], $p['message']);
        } elseif ($action === 'reprocess_pending') {
            $st = $pdo->prepare("SELECT id, original_name FROM ai_documents WHERE tenant_id = ? AND status IN ('pending','error','processing') LIMIT 25");
            $st->execute([$tenantId]);
            $n = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) { $p = ai_docs_process($pdo, (int)$d['id'], $tenantId); ai_flash($p['ok'], '«' . $d['original_name'] . '»: ' . $p['message']); $n++; }
            if (!$n) ai_flash(true, 'No hay archivos pendientes ni con error.');
        } elseif ($action === 'toggle' && $id) {
            $pdo->prepare('UPDATE ai_documents SET is_active = 1 - is_active WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            ai_flash(true, 'Disponibilidad para la IA actualizada.');
        } elseif ($action === 'delete' && $id) {
            ai_flash(ai_docs_delete($pdo, $tenantId, $id), 'Documento eliminado del centro de conocimiento.');
        }
    } catch (Throwable $e) {
        error_log('ai_knowledge: ' . $e->getMessage());
        ai_flash(false, 'Ocurrió un error: ' . $e->getMessage());
    }
    header('Location: ai_knowledge.php' . (isset($_GET['kind']) ? '?kind=' . urlencode($_GET['kind']) : ''));
    exit;
}

/* ------------------------------------------------------------ datos de la página */

$kind = $_GET['kind'] ?? 'all';
if (!in_array($kind, ['all', 'document', 'book', 'image', 'text'], true)) $kind = 'all';
$sql = 'SELECT * FROM ai_documents WHERE tenant_id = ?' . ($kind !== 'all' ? ' AND kind = ?' : '') . ' ORDER BY created_at DESC, id DESC LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($kind !== 'all' ? [$tenantId, $kind] : [$tenantId]);
$docs = $st->fetchAll(PDO::FETCH_ASSOC);
$stats = ai_docs_stats($pdo, $tenantId);

$kbCount = 0;
try { $q = $pdo->prepare('SELECT COUNT(*) FROM knowledge_base WHERE tenant_id = ?'); $q->execute([$tenantId]); $kbCount = (int)$q->fetchColumn(); } catch (Throwable $e) {}

// Prueba de búsqueda: "¿qué encontraría la IA con esta pregunta?"
$testQ = trim((string)($_GET['q'] ?? ''));
$testRes = $testQ !== '' ? ai_docs_search($pdo, $tenantId, $testQ, 5) : null;

$flash = $_SESSION['ai_docs_flash'] ?? [];
unset($_SESSION['ai_docs_flash']);

$caps = [
    ['Extensión ZIP (DOCX, PPTX, XLSX, EPUB)', class_exists('ZipArchive'), 'Pide a tu hosting activar php-zip.'],
    ['pdftotext (leer PDF)', ai_docs_have_bin('pdftotext'), 'Sin él se usa un lector PHP básico para PDF simples.'],
    ['tesseract (OCR de imágenes y PDF escaneados)', ai_docs_have_bin('tesseract'), 'Opcional si tienes IA con visión.'],
    ['IA con visión (ANTHROPIC_API_KEY)', ai_llm_enabled(), 'Lee capturas, imágenes y PDF escaneados.'],
];

$kindLabels = ['all' => 'Todos', 'document' => 'Documentos', 'book' => 'Libros', 'image' => 'Imágenes', 'text' => 'Textos'];
function ai_kind_icon($k) { return ['document' => 'fa-file-lines', 'book' => 'fa-book', 'image' => 'fa-image', 'text' => 'fa-file-code'][$k] ?? 'fa-file'; }
function ai_status_badge($s) {
    $m = ['indexed' => ['success', 'Indexado'], 'pending' => ['secondary', 'Pendiente'], 'processing' => ['info', 'Procesando'], 'error' => ['danger', 'Con error']];
    $b = $m[$s] ?? ['secondary', $s];
    return '<span class="badge bg-' . $b[0] . '">' . $b[1] . '</span>';
}
?>
<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid px-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3 gap-2">
        <div>
            <h3 class="mb-0"><i class="fas fa-brain text-primary me-2"></i>Centro de conocimiento IA</h3>
            <small class="text-muted">Lo que subas aquí alimenta al chatbot <strong>solo de tu empresa</strong>. Cada empresa tiene su propia base, nunca se mezclan.</small>
        </div>
        <a href="knowledge_base.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver al Auto Servicio IA</a>
    </div>

    <?php foreach ($flash as $f): ?>
        <div class="alert alert-<?php echo $f['ok'] ? 'success' : 'danger'; ?> alert-dismissible fade show shadow-sm border-0 py-2" role="alert">
            <i class="fas <?php echo $f['ok'] ? 'fa-check-circle' : 'fa-triangle-exclamation'; ?> me-2"></i><?php echo h($f['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endforeach; ?>

    <!-- Resumen -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-2"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Documentos</div><div class="fs-3 fw-bold"><?php echo $stats['document'] + $stats['text']; ?></div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Libros</div><div class="fs-3 fw-bold"><?php echo $stats['book']; ?></div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Imágenes</div><div class="fs-3 fw-bold"><?php echo $stats['image']; ?></div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Artículos de la base</div><div class="fs-3 fw-bold"><?php echo $kbCount; ?></div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card shadow-sm h-100 border-success"><div class="card-body py-3"><div class="text-muted small">Indexados</div><div class="fs-3 fw-bold text-success"><?php echo $stats['indexed']; ?></div><div class="small text-muted"><?php echo number_format($stats['chunks'], 0, ',', '.'); ?> fragmentos</div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card shadow-sm h-100 <?php echo ($stats['error'] || $stats['pending']) ? 'border-danger' : ''; ?>"><div class="card-body py-3"><div class="text-muted small">Pendientes / con error</div><div class="fs-3 fw-bold <?php echo $stats['error'] ? 'text-danger' : ''; ?>"><?php echo $stats['pending']; ?> / <?php echo $stats['error']; ?></div></div></div></div>
    </div>

    <div class="row g-4">
        <!-- Subir -->
        <div class="col-lg-5">
            <div class="card shadow mb-4">
                <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary"><i class="fas fa-cloud-arrow-up me-2"></i>Alimentar a la IA</h6></div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" id="kc-form">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="upload">
                        <div id="kc-drop" class="border border-2 border-dashed rounded-3 p-4 text-center mb-3" style="border-style: dashed !important; cursor: pointer;">
                            <i class="fas fa-file-arrow-up fa-2x text-primary mb-2"></i>
                            <div class="fw-semibold">Arrastra aquí tus archivos o haz clic para elegirlos</div>
                            <div class="small text-muted">PDF · libros EPUB · Word, Excel, PowerPoint · TXT/MD/CSV · imágenes JPG/PNG/WEBP<br>Hasta 10 archivos por vez, 20 MB cada uno</div>
                            <input type="file" name="docs[]" id="kc-files" class="d-none" multiple accept=".pdf,.docx,.pptx,.xlsx,.epub,.txt,.md,.csv,.json,.html,.htm,.log,.jpg,.jpeg,.png,.webp">
                        </div>
                        <div id="kc-list" class="mb-3"></div>
                        <div id="kc-title-wrap" class="mb-3 d-none">
                            <label class="form-label small mb-1">Título (opcional)</label>
                            <input type="text" name="title" class="form-control form-control-sm" maxlength="190" placeholder="Ej.: Manual de la impresora HP del piso 2">
                        </div>
                        <button type="submit" class="btn btn-primary w-100" id="kc-submit" disabled><i class="fas fa-upload me-2"></i>Subir e indexar</button>
                        <div id="kc-busy" class="text-center text-muted small mt-2 d-none"><span class="spinner-border spinner-border-sm me-2"></span>Subiendo y leyendo los archivos… no cierres esta página (los libros grandes pueden tardar).</div>
                    </form>
                    <p class="small text-muted mt-3 mb-0"><i class="fas fa-circle-info me-1"></i>Subir un archivo no basta: el sistema extrae su texto, lo indexa y comprueba que quedó disponible. Si algo falla verás el motivo real en el estado.</p>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary"><i class="fas fa-magnifying-glass me-2"></i>Probar qué encontraría la IA</h6></div>
                <div class="card-body">
                    <form method="get" class="input-group mb-3">
                        <input type="text" name="q" value="<?php echo h($testQ); ?>" class="form-control" placeholder="Ej.: error 0x6d9 en la impresora" maxlength="200">
                        <button class="btn btn-outline-primary" type="submit">Probar</button>
                    </form>
                    <?php if ($testRes !== null): ?>
                        <?php if (!$testRes): ?>
                            <div class="alert alert-warning py-2 small mb-0">No hay fragmentos relevantes para esa pregunta. El chatbot usaría solo los artículos de la base de conocimientos o te ofrecería abrir un ticket.</div>
                        <?php else: foreach ($testRes as $r): ?>
                            <div class="border rounded-3 p-2 mb-2">
                                <div class="d-flex justify-content-between"><strong class="small"><i class="fas <?php echo ai_kind_icon($r['kind']); ?> me-1 text-primary"></i><?php echo h($r['title']); ?></strong><span class="badge bg-success"><?php echo (int)$r['confidence']; ?>%</span></div>
                                <div class="small text-muted" style="white-space: pre-line;"><?php echo h(mb_substr($r['snippet'], 0, 320)); ?>…</div>
                            </div>
                        <?php endforeach; endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary"><i class="fas fa-gears me-2"></i>Capacidad de lectura del servidor</h6></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($caps as $c): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <span><?php echo h($c[0]); ?><?php if (!$c[1]): ?><br><span class="text-muted"><?php echo h($c[2]); ?></span><?php endif; ?></span>
                            <span class="badge bg-<?php echo $c[1] ? 'success' : 'secondary'; ?>"><?php echo $c[1] ? 'Disponible' : 'No disponible'; ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex justify-content-between"><span>Límite de subida del servidor</span><span class="text-muted">archivo <?php echo h(ini_get('upload_max_filesize')); ?> · total <?php echo h(ini_get('post_max_size')); ?></span></li>
                </ul>
            </div>
        </div>

        <!-- Lista -->
        <div class="col-lg-7">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <ul class="nav nav-pills small">
                        <?php foreach ($kindLabels as $k => $label): ?>
                            <li class="nav-item"><a class="nav-link py-1 <?php echo $kind === $k ? 'active' : ''; ?>" href="ai_knowledge.php?kind=<?php echo $k; ?>"><?php echo $label; ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($stats['pending'] || $stats['error']): ?>
                        <form method="post" class="m-0"><?php echo csrf_input(); ?><input type="hidden" name="action" value="reprocess_pending">
                            <button class="btn btn-sm btn-outline-primary"><i class="fas fa-rotate me-1"></i>Reintentar pendientes y errores</button></form>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Archivo</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody>
                        <?php if (!$docs): ?>
                            <tr><td colspan="3" class="text-center text-muted py-5"><i class="fas fa-box-open fa-2x mb-2 d-block"></i>Aún no has subido nada<?php echo $kind !== 'all' ? ' en esta categoría' : ''; ?>. Sube manuales, guías o capturas para que la IA los use.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($docs as $d): ?>
                            <tr class="<?php echo $d['is_active'] ? '' : 'opacity-50'; ?>">
                                <td style="max-width: 280px;">
                                    <div class="d-flex align-items-start">
                                        <?php if ($d['kind'] === 'image'): ?>
                                            <img src="ai_knowledge.php?download=<?php echo (int)$d['id']; ?>&inline=1" alt="" class="rounded me-2" style="width: 38px; height: 38px; object-fit: cover;">
                                        <?php else: ?>
                                            <i class="fas <?php echo ai_kind_icon($d['kind']); ?> fa-lg text-primary me-2 mt-1"></i>
                                        <?php endif; ?>
                                        <div style="min-width: 0;">
                                            <div class="fw-semibold text-truncate" title="<?php echo h($d['title']); ?>"><?php echo h($d['title']); ?></div>
                                            <div class="small text-muted text-truncate" title="<?php echo h($d['original_name']); ?>"><?php echo h($d['original_name']); ?> · <?php echo ai_docs_format_size((int)$d['size_bytes']); ?> · <?php echo h(ai_docs_kind_label($d['kind'])); ?></div>
                                            <div class="small text-muted"><?php echo h(date('d/m/Y H:i', strtotime($d['created_at']))); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="max-width: 260px;">
                                    <?php echo ai_status_badge($d['status']); ?>
                                    <?php if (!$d['is_active']): ?><span class="badge bg-warning text-dark">Pausado</span><?php endif; ?>
                                    <?php if ($d['status'] === 'indexed'): ?>
                                        <div class="small text-muted"><?php echo (int)$d['chunk_count']; ?> fragmentos · <?php echo h($d['extract_method']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($d['status'] !== 'indexed' && $d['status_message']): ?>
                                        <div class="small <?php echo $d['status'] === 'error' ? 'text-danger' : 'text-muted'; ?>"><?php echo h($d['status_message']); ?></div>
                                    <?php elseif ($d['status'] === 'indexed' && strpos((string)$d['status_message'], 'Extraído con IA') !== false): ?>
                                        <div class="small text-warning-emphasis">Extraído con IA (puede ser parcial)</div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($d['status'] === 'indexed'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Ver el texto que leyó la IA" onclick="kcPreview(<?php echo (int)$d['id']; ?>)"><i class="fas fa-eye"></i></button>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-secondary" title="Descargar" href="ai_knowledge.php?download=<?php echo (int)$d['id']; ?>"><i class="fas fa-download"></i></a>
                                    <form method="post" class="d-inline"><?php echo csrf_input(); ?><input type="hidden" name="action" value="reprocess"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                        <button class="btn btn-sm btn-outline-primary" title="Volver a leer e indexar"><i class="fas fa-rotate"></i></button></form>
                                    <form method="post" class="d-inline"><?php echo csrf_input(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                        <button class="btn btn-sm btn-outline-<?php echo $d['is_active'] ? 'warning' : 'success'; ?>" title="<?php echo $d['is_active'] ? 'Pausar (la IA deja de usarlo)' : 'Activar'; ?>"><i class="fas <?php echo $d['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i></button></form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este documento y su contenido indexado?');"><?php echo csrf_input(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="fas fa-trash"></i></button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Vista previa del texto extraído -->
<div class="modal fade" id="kcPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="kcPreviewTitle">Texto leído</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="small text-muted mb-2" id="kcPreviewMeta"></div>
                <pre id="kcPreviewText" class="small bg-light p-3 rounded" style="white-space: pre-wrap; max-height: 55vh; overflow: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<script>
const kcFiles = document.getElementById('kc-files');
const kcDrop = document.getElementById('kc-drop');
const kcList = document.getElementById('kc-list');
const kcSubmit = document.getElementById('kc-submit');
const KC_ALLOWED = ['pdf','docx','pptx','xlsx','epub','txt','md','csv','json','html','htm','log','jpg','jpeg','png','webp'];
const KC_MAX = 20 * 1024 * 1024;
let kcSelected = [];

function kcSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
function kcEsc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function kcRender() {
    kcList.innerHTML = '';
    kcSelected.forEach((f, i) => {
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        const bad = !KC_ALLOWED.includes(ext) ? 'Formato no permitido' : (f.size > KC_MAX ? 'Supera 20 MB' : '');
        const row = document.createElement('div');
        row.className = 'd-flex align-items-center justify-content-between border rounded-2 px-2 py-1 mb-1 small ' + (bad ? 'border-danger' : '');
        row.innerHTML = '<span class="text-truncate" style="max-width:70%"><i class="fas fa-file me-2 text-primary"></i>' + kcEsc(f.name) + '</span>' +
            '<span class="text-nowrap">' + (bad ? '<span class="text-danger me-2">' + bad + '</span>' : '<span class="text-muted me-2">' + kcSize(f.size) + '</span>') +
            '<button type="button" class="btn btn-sm btn-link text-danger p-0" aria-label="Quitar">&times;</button></span>';
        row.querySelector('button').onclick = () => { kcSelected.splice(i, 1); kcSync(); };
        kcList.appendChild(row);
    });
    const valid = kcSelected.length > 0 && !kcSelected.some(f => !KC_ALLOWED.includes((f.name.split('.').pop() || '').toLowerCase()) || f.size > KC_MAX);
    kcSubmit.disabled = !valid;
    document.getElementById('kc-title-wrap').classList.toggle('d-none', kcSelected.length !== 1);
}
function kcSync() {
    const dt = new DataTransfer();
    kcSelected.forEach(f => dt.items.add(f));
    kcFiles.files = dt.files;
    kcRender();
}
function kcAdd(list) {
    Array.from(list).forEach(f => { if (kcSelected.length < 10 && !kcSelected.some(x => x.name === f.name && x.size === f.size)) kcSelected.push(f); });
    kcSync();
}
kcDrop.addEventListener('click', () => kcFiles.click());
kcFiles.addEventListener('change', () => { const l = Array.from(kcFiles.files); kcSelected = []; kcAdd(l); });
['dragenter', 'dragover'].forEach(e => kcDrop.addEventListener(e, ev => { ev.preventDefault(); kcDrop.classList.add('bg-light'); }));
['dragleave', 'drop'].forEach(e => kcDrop.addEventListener(e, ev => { ev.preventDefault(); kcDrop.classList.remove('bg-light'); }));
kcDrop.addEventListener('drop', ev => kcAdd(ev.dataTransfer.files));
document.getElementById('kc-form').addEventListener('submit', () => {
    kcSubmit.disabled = true;
    document.getElementById('kc-busy').classList.remove('d-none');
});

async function kcPreview(id) {
    document.getElementById('kcPreviewTitle').textContent = 'Cargando…';
    document.getElementById('kcPreviewText').textContent = '';
    document.getElementById('kcPreviewMeta').textContent = '';
    new bootstrap.Modal(document.getElementById('kcPreviewModal')).show();
    try {
        const r = await (await fetch('ai_knowledge.php?preview=' + id)).json();
        document.getElementById('kcPreviewTitle').textContent = r.ok ? r.title : 'No disponible';
        document.getElementById('kcPreviewMeta').textContent = r.ok ? (r.chars.toLocaleString('es') + ' caracteres · ' + r.chunks + ' fragmentos · método: ' + r.method + ' — se muestra el inicio') : '';
        document.getElementById('kcPreviewText').textContent = r.ok ? r.text : (r.message || '');
    } catch (e) { document.getElementById('kcPreviewTitle').textContent = 'No se pudo cargar la vista previa'; }
}
</script>

<?php require_once 'includes/footer.php'; ?>
