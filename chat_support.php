<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';
require_once 'includes/telegram_helper.php';
require_once 'includes/csrf.php';

$chat_id = $_GET['chat_id'] ?? null;
$tenant_id = $_SESSION['tenant_id'];

if (!$chat_id) {
    header("Location: multicanal.php");
    exit;
}

// Obtener info del usuario - Join con tenant_id para precisión
$stmt = $pdo->prepare("SELECT u.name, ts.data, ts.source FROM telegram_sessions ts LEFT JOIN users u ON ts.chat_id = u.telegram_id AND u.tenant_id = ts.tenant_id WHERE ts.chat_id = ? AND ts.tenant_id = ?");
$stmt->execute([$chat_id, $tenant_id]);
$row = $stmt->fetch();

if (!$row) {
    header("Location: multicanal.php");
    exit;
}

// Usar la fuente guardada en la base de datos
$source_type = $row['source'] ?? 'telegram';
$is_whatsapp = ($source_type === 'whatsapp');
$source_icon = $is_whatsapp ? 'fab fa-whatsapp' : 'fab fa-telegram-plane';
$source_color = $is_whatsapp ? 'success' : 'info';
$default_name = $is_whatsapp ? 'Usuario WhatsApp' : 'Usuario Telegram';

$session_data = json_decode($row['data'] ?? '[]', true);
$display_name = $session_data['agent_chat_name'] ?? ($row['name'] ?? $default_name);

require_once 'includes/navbar.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm overflow-hidden" style="height: 85vh;">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm bg-<?php echo $source_color; ?> bg-opacity-10 text-<?php echo $source_color; ?> rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                            <i class="<?php echo $source_icon; ?>"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($display_name); ?></h6>
                            <small class="text-success"><i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i> Chat en Vivo (ID: <?php echo htmlspecialchars($chat_id); ?> | T: <?php echo (int)$tenant_id; ?>)</small>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <form id="finish-chat-form" method="POST" action="chat_support_actions.php" style="display:none;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="chat_id" value="<?php echo htmlspecialchars($chat_id); ?>">
                            <input type="hidden" name="finish_chat" value="1">
                        </form>
                        <button onclick="confirmFinishChat()" class="btn btn-sm btn-outline-danger px-3 rounded-pill">Finalizar</button>
                        <a href="multicanal.php" class="btn btn-sm btn-light border px-3 rounded-pill">Volver</a>
                    </div>
                </div>
                
                <!-- Quick Reply Menu -->
                <div class="px-4 py-2 bg-light border-bottom">
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="quick-reply btn btn-outline-primary btn-sm" data-text="¡Hola! ¿En qué puedo ayudarte hoy?">
                            <i class="fas fa-hand-wave"></i> Saludo
                        </button>
                        <button class="quick-reply btn btn-outline-primary btn-sm" data-text="¿Podrías describir el problema que estás experimentando?">
                            <i class="fas fa-question-circle"></i> Preguntar problema
                        </button>
                        <button class="quick-reply btn btn-outline-primary btn-sm" data-text="¿Has probado a reiniciar el dispositivo/software?">
                            <i class="fas fa-redo"></i> Reinicio
                        </button>
                        <button class="quick-reply btn btn-outline-primary btn-sm" data-text="Perfecto, ya estoy revisando tu caso. Te daré respuesta en breve.">
                            <i class="fas fa-spinner"></i> Revisando
                        </button>
                        <button class="quick-reply btn btn-outline-primary btn-sm" data-text="Gracias por tu paciencia. ¿Hay algo más en lo que te puedo ayudar?">
                            <i class="fas fa-check-circle"></i> Cerrar
                        </button>
                    </div>
                </div>
                
                <div class="card-body bg-light chat-container p-4" id="chat-messages" style="overflow-y: auto; flex-grow: 1;">
                    <!-- Los mensajes se cargarán aquí vía AJAX -->
                </div>

                <div class="card-footer bg-white p-3 border-top">
                    <div id="attach-chip" class="d-none align-items-center gap-2 mb-2 small bg-light border rounded-pill px-3 py-1">
                        <i class="fas fa-paperclip text-primary"></i>
                        <span id="attach-name" class="text-truncate flex-grow-1"></span>
                        <button type="button" id="attach-clear" class="btn btn-sm btn-link text-danger p-0" title="Quitar archivo"><i class="fas fa-times"></i></button>
                    </div>
                    <form id="chat-form" class="d-flex gap-2 align-items-center">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="chat_id" value="<?php echo htmlspecialchars($chat_id); ?>">
                        <input type="hidden" name="source" value="<?php echo htmlspecialchars($source_type); ?>">
                        <input type="file" id="attach-input" class="d-none" accept="image/jpeg,image/png,image/webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv">
                        <button type="button" id="attach-btn" class="btn btn-light border rounded-circle" style="width: 40px; height: 40px;" title="Adjuntar imagen o archivo"><i class="fas fa-paperclip"></i></button>
                        <input type="text" id="message-input" name="message" class="form-control border-0 bg-light px-3 py-2" placeholder="Escribe un mensaje..." autocomplete="off">
                        <button type="submit" class="btn btn-primary rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let lastMessageId = 0;
const chatContainer = document.getElementById('chat-messages');
const chatForm = document.getElementById('chat-form');
const messageInput = document.getElementById('message-input');
const chatId = <?php echo json_encode((string)$chat_id); ?>;
const userName = <?php echo json_encode((string)$display_name); ?>;
const csrfToken = <?php echo json_encode(csrf_token()); ?>;

// Quick reply functionality
document.querySelectorAll('.quick-reply').forEach(button => {
    button.addEventListener('click', () => {
        messageInput.value = button.getAttribute('data-text');
        messageInput.focus();
    });
});

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
// Si la API ya escapó entidades HTML, las decodifica antes de volver a escapar (evita &amp;lt;). Siempre termina escapado.
function decodeEntities(s) {
    const t = document.createElement('textarea');
    t.innerHTML = String(s);
    return t.value;
}
function renderMessageBody(raw) {
    const text = decodeEntities(raw || '');
    const m = /^\[\[(img|file):([A-Za-z0-9_\/.\-]+)(?:\|([^\]]*))?\]\]\n?([\s\S]*)$/.exec(text);
    if (m && /^uploads\/chat\/\d+\/[A-Za-z0-9_.\-]+$/.test(m[2])) {
        const url = 'chat_file.php?p=' + encodeURIComponent(m[2]);
        let html = (m[1] === 'img')
            ? `<a href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="Imagen adjunta" class="chat-img" loading="lazy"></a>`
            : `<a href="${url}" target="_blank" rel="noopener" class="chat-file"><i class="fas fa-file-alt me-2"></i>${escapeHtml(m[3] || 'archivo')}</a>`;
        if (m[4]) html += `<div class="mt-2">${escapeHtml(m[4]).replace(/\n/g, '<br>')}</div>`;
        return html;
    }
    return escapeHtml(text).replace(/\n/g, '<br>');
}

function scrollToBottom() {
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

async function confirmFinishChat() {
    if(confirm('¿Estás seguro de que deseas finalizar esta sesión de soporte? El bot volverá al estado inicial.')) {
        try {
            const formData = new FormData();
            formData.append('chat_id', chatId);
            formData.append('csrf_token', csrfToken);
            formData.append('finish_chat', '1');
            formData.append('source', '<?php echo $source_type; ?>');
            
            const response = await fetch('chat_support_actions.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                window.location.href = result.redirect || 'multicanal.php';
            } else {
                alert('Error al finalizar el chat: ' + (result.error || 'Desconocido'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error de red al intentar finalizar el chat.');
        }
    }
}

async function loadMessages() {
    try {
        const response = await fetch(`api_chat_messages.php?chat_id=${chatId}&last_id=${lastMessageId}`);
        if (!response.ok) throw new Error('Error en la petición');
        
        const data = await response.json();
        
        if (data.error) {
            console.error('API Error:', data.error);
            if (data.error === 'No autorizado') {
                window.location.href = 'login.php';
            }
            return;
        }
        
        if (data.messages && data.messages.length > 0) {
            data.messages.forEach(msg => {
                const isOut = (msg.direction === 'out');
                const div = document.createElement('div');
                div.className = `mb-3 d-flex ${isOut ? 'justify-content-end' : 'justify-content-start'}`;
                
                const time = new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                
                // Usar el nombre que viene de la API si es posible, o el estático
                const senderName = escapeHtml(isOut ? 'Tú' : (data.user_name || userName));
                
                div.innerHTML = `
                    <div class="p-3 rounded-3 shadow-sm ${isOut ? 'bg-primary text-white' : 'bg-white text-dark'}" style="max-width: 85%; border-radius: 15px !important;">
                        <div class="extra-small mb-1 opacity-75 fw-bold">${senderName}</div>
                        <div style="word-break: break-word;">${renderMessageBody(msg.message)}</div>
                        <div class="extra-small mt-1 opacity-50 text-end" style="font-size: 0.6rem;">${time}</div>
                    </div>
                `;
                chatContainer.appendChild(div);
                lastMessageId = msg.id;
            });
            scrollToBottom();
        }
    } catch (error) {
        console.error('Error cargando mensajes:', error);
    }
}

// ── Adjuntos ──
const attachInput = document.getElementById('attach-input');
const attachBtn = document.getElementById('attach-btn');
const attachChip = document.getElementById('attach-chip');
const attachName = document.getElementById('attach-name');
let pendingFile = null;

attachBtn.addEventListener('click', () => attachInput.click());
attachInput.addEventListener('change', () => {
    const f = attachInput.files[0];
    if (!f) return;
    if (f.size > 10 * 1024 * 1024) { alert('El archivo supera los 10 MB.'); attachInput.value = ''; return; }
    pendingFile = f;
    attachName.textContent = f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)';
    attachChip.classList.remove('d-none'); attachChip.classList.add('d-flex');
    messageInput.placeholder = 'Texto opcional que acompaña al archivo...';
    messageInput.focus();
});
function clearAttachment() {
    pendingFile = null; attachInput.value = '';
    attachChip.classList.add('d-none'); attachChip.classList.remove('d-flex');
    messageInput.placeholder = 'Escribe un mensaje...';
}
document.getElementById('attach-clear').addEventListener('click', clearAttachment);

chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = messageInput.value.trim();
    if (!message && !pendingFile) return;

    messageInput.disabled = true;
    attachBtn.disabled = true;

    try {
        const formData = new FormData();
        formData.append('chat_id', chatId);
        formData.append('csrf_token', csrfToken);
        let url = 'chat_support_actions.php';
        if (pendingFile) {
            url = 'chat_support_upload.php';
            formData.append('file', pendingFile);
            formData.append('caption', message);
        } else {
            formData.append('message', message);
            formData.append('source', '<?php echo $source_type; ?>');
        }

        const response = await fetch(url, { method: 'POST', body: formData });
        if (pendingFile) {
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.success) throw new Error(result.error || 'No se pudo enviar el archivo.');
            clearAttachment();
        } else if (!response.ok) {
            throw new Error('Error en el servidor');
        }
        messageInput.value = '';
        loadMessages(); // Cargar inmediatamente el mensaje enviado
    } catch (error) {
        console.error('Error enviando mensaje:', error);
        alert(error.message || 'No se pudo enviar el mensaje.');
    } finally {
        messageInput.disabled = false;
        attachBtn.disabled = false;
        messageInput.focus();
    }
});

// Cargar mensajes inicialmente y luego cada 3 segundos
loadMessages();
setInterval(loadMessages, 3000);
</script>

<style>
    .chat-container {
        scrollbar-width: thin;
        display: flex;
        flex-direction: column;
    }
    .extra-small { font-size: 0.75rem; }
    .chat-img { max-width: 100%; max-height: 260px; border-radius: 10px; display: block; }
    .chat-file { color: inherit; text-decoration: underline; word-break: break-all; }
    #message-input:focus { box-shadow: none; }
    .chat-container::-webkit-scrollbar { width: 4px; }
    .chat-container::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
</style>
<?php require_once 'includes/footer.php'; ?>