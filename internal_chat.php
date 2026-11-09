<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';

$pageTitle = 'Chat Interno';
$tenant_id = $_SESSION['tenant_id'];
$current_user_id = $_SESSION['user_id'];

// Ensure internal chat tables exist (just in case)
try {
    $checkRooms = $pdo->query("SHOW TABLES LIKE 'internal_chat_rooms'");
    if (!$checkRooms->fetch()) {
        $pdo->exec("
            CREATE TABLE `internal_chat_rooms` (
                `id` int NOT NULL AUTO_INCREMENT,
                `tenant_id` int NOT NULL,
                `user1_id` int NOT NULL,
                `user2_id` int NOT NULL,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_room` (`tenant_id`, `user1_id`, `user2_id`),
                CONSTRAINT `fk_room_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_room_user1` FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_room_user2` FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    $checkMessages = $pdo->query("SHOW TABLES LIKE 'internal_chat_messages'");
    if (!$checkMessages->fetch()) {
        $pdo->exec("
            CREATE TABLE `internal_chat_messages` (
                `id` int NOT NULL AUTO_INCREMENT,
                `room_id` int NOT NULL,
                `sender_id` int NOT NULL,
                `message` text,
                `file_path` varchar(255) DEFAULT NULL,
                `file_name` varchar(255) DEFAULT NULL,
                `file_type` varchar(100) DEFAULT NULL,
                `file_size` bigint DEFAULT NULL,
                `is_read` tinyint(1) DEFAULT 0,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_msg_room` FOREIGN KEY (`room_id`) REFERENCES `internal_chat_rooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) { }

// Get all users in this tenant (for starting new chats)
$stmtUsers = $pdo->prepare("SELECT id, name, email, avatar, role FROM users WHERE tenant_id = ? AND id != ? ORDER BY name ASC");
$stmtUsers->execute([$tenant_id, $current_user_id]);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/navbar.php';
?>

<!-- Reemplaza el container principal -->
<div class="container-fluid p-0" style="height: calc(100vh - 64px);">
  <div class="chat-layout">

    <!-- SIDEBAR -->
    <div class="chat-sidebar">
      <div class="sidebar-header">
        <h5 class="sidebar-title">Chats
          <button class="btn-icon-round" data-bs-toggle="modal" data-bs-target="#newChatModal" aria-label="Nuevo chat">
            <i class="fas fa-pen-to-square"></i>
          </button>
        </h5>
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="chat-search-input" placeholder="Buscar chats...">
        </div>
      </div>
      <div class="sidebar-body" id="chat-list-container"></div>
    </div>

    <!-- PANEL DERECHO -->
    <div class="chat-main" id="chat-window-container">
      <div class="chat-empty" id="empty-chat-state">
        <i class="fas fa-comments"></i>
        <p>Selecciona un chat para empezar</p>
      </div>
      <div class="chat-active" id="active-chat" style="display:none;">
        <div class="chat-header">
          <div class="d-flex align-items-center gap-3">
            <div class="chat-av-wrap">
              <div class="chat-av" id="chat-user-avatar"></div>
              <span class="av-dot" id="chat-av-dot"></span>
            </div>
            <div>
              <div class="chat-header-name" id="chat-user-name"></div>
              <div class="chat-header-status" id="chat-user-status"></div>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn-icon-round" id="view-profile-btn"><i class="fas fa-info-circle"></i></button>
            <button class="btn-icon-round"><i class="fas fa-ellipsis-v"></i></button>
          </div>
        </div>

        <div class="chat-messages" id="chat-messages-area"></div>

        <div class="chat-footer">
          <div id="file-preview-container" class="file-preview" style="display:none;">
            <div class="d-flex align-items-center gap-2">
              <span id="file-preview-icon" class="text-primary"></span>
              <span id="file-preview-name" class="text-truncate flex-grow-1 small fw-medium"></span>
              <button id="remove-file-btn" class="btn-icon-round text-danger"><i class="fas fa-times"></i></button>
            </div>
          </div>
          <div class="chat-input-row">
            <label for="chat-file-input" class="btn-icon-round" style="cursor:pointer">
              <i class="fas fa-paperclip"></i>
            </label>
            <input type="file" id="chat-file-input" accept="image/*,.pdf,.doc,.docx" class="d-none">
            <input type="text" id="chat-message-input" class="chat-input" placeholder="Escribe un mensaje..." autocomplete="off">
            <button type="button" id="send-btn" class="btn-send"><i class="fas fa-paper-plane"></i></button>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- New Chat Modal -->
<div class="modal fade" id="newChatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark">Nuevo chat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="text" id="search-user-input" class="form-control mb-3 rounded-pill bg-light border" placeholder="Buscar usuario...">
                <div class="list-group list-group-flush" id="new-chat-users-list">
                    <?php foreach ($users as $u): ?>
                        <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 border-0 user-item" data-user-id="<?= $u['id'] ?>" data-user-name="<?= htmlspecialchars($u['name']) ?>" data-user-email="<?= htmlspecialchars($u['email']) ?>">
                            <?php if ($u['avatar']): ?>
                                <img src="<?= htmlspecialchars($u['avatar']) ?>" class="rounded-circle" style="width:40px; height:40px; object-fit:cover;">
                            <?php else: ?>
                                <div class="rounded-circle bg-gradient-to-r from-blue-500 to-blue-700 d-flex align-items-center justify-content-center text-white fw-bold" style="width:40px; height:40px; font-size:0.9rem;">
                                    <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-grow-1 text-start">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($u['name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let activeRoomId = null;
let activeOtherUserId = null;
let selectedFile = null;

// Helper to check if user is online
function isOnline(lastSeen) {
    if (!lastSeen) return false;
    return (new Date() - new Date(lastSeen)) < 5 * 60 * 1000;
}

// Helper to format last seen
function formatLastSeen(lastSeen) {
    if (!lastSeen) return 'Desconocido';
    const diff = new Date() - new Date(lastSeen);
    const mins = Math.floor(diff / 60000);
    if (mins < 5) return 'En línea';
    if (mins < 60) return `Hace ${mins} min`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `Hace ${hours} h`;
    return new Date(lastSeen).toLocaleDateString();
}

// Helper to get file icon
function getFileIcon(type) {
    if (!type) return 'fa-file';
    if (type.startsWith('image/')) return 'fa-image';
    if (type === 'application/pdf') return 'fa-file-pdf';
    if (type.includes('word') || type.includes('document')) return 'fa-file-word';
    return 'fa-file';
}

// Load recent chats on page load
async function loadChatList() {
    try {
        const res = await fetch('api_internal_chat.php?action=get_recent');
        const data = await res.json();
        if (data.success) {
            renderChatList(data.chats);
            return data.chats;
        }
        return [];
    } catch (e) {
        console.error(e);
        return [];
    }
}

function renderChatList(chats) {
    const container = document.getElementById('chat-list-container');
    if (!chats.length) {
        container.innerHTML = '<div class="p-4 text-center text-muted"><i class="fas fa-inbox mb-2" style="font-size:2rem;"></i><p>No tienes chats aún</p></div>';
        return;
    }
    
    container.innerHTML = chats.map(chat => `
    <button class="chat-item ${activeRoomId === chat.id ? 'active' : ''}"
            data-room-id="${chat.id}" data-other-user-id="${chat.other_user_id}">
        <div class="av-wrap">
            ${chat.other_user_avatar
                ? `<div class="av-circle"><img src="${chat.other_user_avatar}"></div>`
                : `<div class="av-circle">${(chat.other_user_name || 'U').charAt(0).toUpperCase()}</div>`}
            <span class="av-dot ${isOnline(chat.other_user_last_seen) ? '' : 'off'}"></span>
        </div>
        <div class="chat-meta">
            <div class="chat-top">
                <span class="chat-name">${chat.other_user_name || 'Usuario'}</span>
                ${chat.last_message_time
                    ? `<span class="chat-time">${new Date(chat.last_message_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</span>`
                    : ''}
            </div>
            <div class="chat-bottom">
                <span class="chat-preview">${chat.last_message || (chat.last_file_name ? '📎 Archivo' : 'Sin mensajes')}</span>
                ${chat.unread_count > 0 ? `<span class="chat-unread">${chat.unread_count}</span>` : ''}
            </div>
        </div>
    </button>
`).join('');
    
    // Attach click listeners to chat items
    document.querySelectorAll('.chat-item').forEach(el => {
        el.addEventListener('click', () => openChat(parseInt(el.dataset.roomId), parseInt(el.dataset.otherUserId)));
    });
}

// Search in chat list
document.getElementById('chat-search-input').addEventListener('input', (e) => {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('.chat-item').forEach(el => {
        const name = el.querySelector('.chat-name').textContent.toLowerCase();
        el.style.display = name.includes(q) ? 'flex' : 'none';
    });
});

async function openChat(roomId, otherUserId) {
    activeRoomId = roomId;
    activeOtherUserId = otherUserId;
    
    document.getElementById('empty-chat-state').style.display = 'none';
    document.getElementById('active-chat').style.display = 'flex';
    
    // Load chat messages
    try {
        const res = await fetch(`api_internal_chat.php?action=get_messages&room_id=${roomId}`);
        const data = await res.json();
        
        if (data.success) {
            document.getElementById('chat-user-name').textContent = data.other_user_name;
            
            // Avatar
            if (data.other_user_avatar) {
                document.getElementById('chat-user-avatar').innerHTML = `<img src="${data.other_user_avatar}" class="w-100 h-100 rounded-circle object-fit-cover">`;
            } else {
                document.getElementById('chat-user-avatar').innerHTML = data.other_user_name.charAt(0).toUpperCase();
            }
            
            // Online status
            const online = isOnline(data.other_user_last_seen);
            const statusEl = document.getElementById('chat-user-status');
            statusEl.textContent = formatLastSeen(data.other_user_last_seen);
            statusEl.className = 'chat-header-status ' + (online ? 'online' : 'offline');
            
            // Avatar dot
            document.getElementById('chat-av-dot').className = 'av-dot ' + (online ? '' : 'off');
            
            // View profile button
            document.getElementById('view-profile-btn').onclick = () => {
                window.location.href = `view_user_profile.php?user_id=${activeOtherUserId}`;
            };
            
            renderMessages(data.messages);
            loadChatList(); // Refresh unread count
        }
    } catch (e) {
        console.error(e);
    }
}

function renderMessages(messages, scrollToBottom = true) {
    const container = document.getElementById('chat-messages-area');
    
    container.innerHTML = messages.map(msg => {
        const isMe = msg.sender_id === <?= $current_user_id ?>;
        const time = new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        
        let fileHtml = '';
        if (msg.file_path) {
            const isImage = msg.file_type && msg.file_type.startsWith('image/');
            if (isImage) {
                fileHtml = `
                    <div class="mb-2">
                        <img src="${msg.file_path}" class="img-fluid rounded shadow-sm cursor-pointer" style="max-height: 250px; border-radius: 8px;" onclick="openImageLightbox('${msg.file_path}')">
                    </div>
                `;
            } else {
                fileHtml = `
                    <a href="${msg.file_path}" target="_blank" class="d-flex align-items-center gap-2 p-2 rounded mb-2 text-decoration-none text-inherit" style="background: rgba(255,255,255,0.5);">
                        <i class="fas ${getFileIcon(msg.file_type)} text-xl"></i>
                        <div class="flex-grow-1 text-truncate">
                            <div class="text-sm font-medium">${msg.file_name}</div>
                        </div>
                        <i class="fas fa-download text-xs"></i>
                    </a>
                `;
            }
        }
        
        let messageHtml = '';
        if (msg.message) {
            messageHtml = `<div style="word-break: break-word;">${msg.message.replace(/\n/g, '<br>')}</div>`;
        }
        
        return `
    <div class="msg-row ${isMe ? 'me' : ''}">
        <div class="bubble ${isMe ? 'sent' : 'recv'}">
            ${fileHtml}
            ${messageHtml}
            <div class="bubble-time">
                ${time}
                ${isMe ? '<i class="fas fa-check-double"></i>' : ''}
            </div>
        </div>
    </div>
`;
    }).join('');
    
    if (isNearBottom) container.scrollTop = container.scrollHeight;
}

// Lightbox function for images
function openImageLightbox(src) {
    const existing = document.getElementById('chat-image-lightbox');
    if (existing) existing.remove();
    
    const lightbox = document.createElement('div');
    lightbox.id = 'chat-image-lightbox';
    lightbox.style.cssText = 'position: fixed; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.85); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 2rem;';
    
    const img = document.createElement('img');
    img.src = src;
    img.style.cssText = 'max-width: 90%; max-height: 90vh; border-radius: 8px; box-shadow: 0 20px 40px rgba(0,0,0,0.3);';
    
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '<i class="fas fa-times"></i>';
    closeBtn.style.cssText = 'position: absolute; top: 20px; right: 20px; background: white; border: none; width: 44px; height: 44px; border-radius: 50%; font-size: 1.5rem; cursor: pointer;';
    closeBtn.onclick = () => lightbox.remove();
    
    lightbox.appendChild(img);
    lightbox.appendChild(closeBtn);
    lightbox.onclick = (e) => { if (e.target === lightbox) lightbox.remove(); };
    
    document.body.appendChild(lightbox);
}

// Handle file selection
document.getElementById('chat-file-input').addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
        selectedFile = file;
        document.getElementById('file-preview-name').textContent = file.name;
        document.getElementById('file-preview-icon').innerHTML = `<i class="fas ${getFileIcon(file.type)}"></i>`;
        document.getElementById('file-preview-container').style.display = 'block';
    }
});

// Handle file removal
document.getElementById('remove-file-btn').addEventListener('click', () => {
    selectedFile = null;
    document.getElementById('chat-file-input').value = '';
    document.getElementById('file-preview-container').style.display = 'none';
});

// Handle sending messages (click on button and enter key)
document.getElementById('send-btn').addEventListener('click', sendMessage);
document.getElementById('chat-message-input').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') sendMessage();
});

async function sendMessage() {
    const input = document.getElementById('chat-message-input');
    const msg = input.value.trim();
    
    if (!activeRoomId) return;
    if (!msg && !selectedFile) return;
    
    // Send message
    try {
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('room_id', activeRoomId);
        formData.append('message', msg);
        if (selectedFile) {
            formData.append('file', selectedFile);
        }
        
        await fetch('api_internal_chat.php', {
            method: 'POST',
            body: formData
        });
        
        input.value = '';
        selectedFile = null;
        document.getElementById('chat-file-input').value = '';
        document.getElementById('file-preview-container').style.display = 'none';
        openChat(activeRoomId, activeOtherUserId); // Refresh chat
    } catch (e) {
        console.error(e);
    }
}

// Handle new chat modal user clicks
document.querySelectorAll('.user-item').forEach(el => {
    el.addEventListener('click', async () => {
        const userId = parseInt(el.dataset.userId);
        
        try {
            const formData = new FormData();
            formData.append('action', 'start_chat');
            formData.append('user_id', userId);
            
            const res = await fetch('api_internal_chat.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('newChatModal')).hide();
                openChat(data.room_id, userId);
                loadChatList();
            }
        } catch (e) {
            console.error(e);
        }
    });
});

// Search users in new chat modal
document.getElementById('search-user-input').addEventListener('input', (e) => {
    const q = e.target.value.toLowerCase().trim();
    const userItems = document.querySelectorAll('.user-item');
    const container = document.getElementById('new-chat-users-list');
    
    // Remove old no-results message if exists
    const oldMsg = container.querySelector('.no-results');
    if (oldMsg) oldMsg.remove();
    
    let hasVisible = false;
    
    if (q === '') {
        userItems.forEach(el => {
            el.style.display = 'flex';
            hasVisible = true;
        });
    } else {
        userItems.forEach(el => {
            const name = el.dataset.userName.toLowerCase();
            const email = el.dataset.userEmail.toLowerCase();
            const matches = name.includes(q) || email.includes(q);
            el.style.display = matches ? 'flex' : 'none';
            if (matches) hasVisible = true;
        });
    }
    
    // Show no results message if needed
    if (!hasVisible) {
        const msg = document.createElement('div');
        msg.className = 'no-results p-4 text-center text-muted';
        msg.innerHTML = '<i class="fas fa-user-slash mb-2" style="font-size: 2rem;"></i><p class="mb-0">No se encontraron usuarios</p>';
        container.appendChild(msg);
    }
});

// Load everything on page load
loadChatList().then(() => {
    // Check for room_id in URL
    const params = new URLSearchParams(window.location.search);
    const roomIdFromUrl = params.get('room_id');
    if (roomIdFromUrl) {
        // Find the chat in the list to get other_user_id
        const chatButtons = document.querySelectorAll('.chat-item');
        let found = false;
        chatButtons.forEach(btn => {
            if (parseInt(btn.dataset.roomId) === parseInt(roomIdFromUrl)) {
                openChat(parseInt(btn.dataset.roomId), parseInt(btn.dataset.otherUserId));
                found = true;
            }
        });
    }
});

// Auto refresh chat list and active chat every 3 seconds
setInterval(() => {
    loadChatList();
    if (activeRoomId) {
        openChat(activeRoomId, activeOtherUserId);
    }
}, 3000);
</script>

<style>
/* Layout general */
.chat-layout {
    display: flex;
    height: 100%;
    background: #fff;
}

/* ── SIDEBAR ─────────────────────────── */
.chat-sidebar {
    width: 300px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    border-right: 0.5px solid #e5e7eb;
    background: #fff;
}
.sidebar-header {
    padding: 14px 16px 10px;
    border-bottom: 0.5px solid #f0f0f0;
}
.sidebar-title {
    font-size: 17px;
    font-weight: 700;
    color: #111;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.search-box {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f5f6fa;
    border-radius: 10px;
    padding: 8px 12px;
}
.search-box i { color: #9ca3af; font-size: 13px; }
.search-box input {
    border: none;
    background: none;
    outline: none;
    font-size: 13px;
    color: #111;
    width: 100%;
}
.search-box input::placeholder { color: #9ca3af; }
.sidebar-body { overflow-y: auto; flex: 1; }

/* ── CHAT ITEMS ─────────────────────── */
.chat-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 16px;
    cursor: pointer;
    border-bottom: 0.5px solid #f5f5f5;
    border: none;
    background: transparent;
    width: 100%;
    text-align: left;
    transition: background .12s;
}
.chat-item:hover { background: #f8faff; }
.chat-item.active { background: #eff6ff; }

/* Avatar */
.av-wrap { position: relative; flex-shrink: 0; }
.av-circle {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: #dbeafe;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 700; color: #1d4ed8;
    overflow: hidden;
}
.av-circle img { width: 100%; height: 100%; object-fit: cover; }
.av-dot {
    position: absolute; bottom: 1px; right: 1px;
    width: 11px; height: 11px;
    border-radius: 50%; border: 2px solid #fff;
    background: #22c55e;
}
.av-dot.off { background: #9ca3af; }

/* Meta texto */
.chat-meta { flex: 1; min-width: 0; }
.chat-top {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 3px;
}
.chat-name {
    font-size: 14px; font-weight: 600; color: #111;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    max-width: 150px;
}
.chat-time { font-size: 11px; color: #9ca3af; flex-shrink: 0; }
.chat-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.chat-preview {
    font-size: 12px; color: #6b7280;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    max-width: 180px;
}
.chat-unread {
    background: #2563eb; color: #fff;
    font-size: 11px; font-weight: 700;
    border-radius: 10px; padding: 1px 7px;
    flex-shrink: 0;
}

/* ── PANEL PRINCIPAL ─────────────────── */
.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: #f5f6fa;
}
.chat-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
    gap: 10px;
    background: #eae6df;
}
.chat-empty i { font-size: 3rem; }
.chat-empty p { font-size: 14px; }
.chat-active {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

/* Header del chat activo */
.chat-header {
    padding: 10px 16px;
    border-bottom: 0.5px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
}
.chat-av-wrap { position: relative; }
.chat-av {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: #dbeafe;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #1d4ed8;
    overflow: hidden;
}
.chat-av img { width: 100%; height: 100%; object-fit: cover; }
.chat-header-name { font-size: 14px; font-weight: 700; color: #111; }
.chat-header-status {
    font-size: 11px; font-weight: 500;
    display: flex; align-items: center; gap: 4px;
}
.chat-header-status.online { color: #22c55e; }
.chat-header-status.offline { color: #9ca3af; }

/* Mensajes */
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    background: #eae6df;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.msg-row { display: flex; margin-bottom: 2px; }
.msg-row.me { justify-content: flex-end; }

.bubble {
    max-width: 72%;
    padding: 8px 12px 4px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.45;
    color: #111;
    word-break: break-word;
}
.bubble.recv { background: #fff; border-top-left-radius: 0; }
.bubble.sent { background: #dcf8c6; border-top-right-radius: 0; }

.bubble-time {
    font-size: 10px;
    color: #9ca3af;
    text-align: right;
    margin-top: 2px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
}
.bubble-time .fa-check-double { color: #53bdeb; font-size: 11px; }

/* Footer */
.chat-footer {
    padding: 10px 16px;
    background: #fff;
    border-top: 0.5px solid #e5e7eb;
}
.file-preview {
    background: #f5f6fa;
    border-radius: 8px;
    padding: 8px 12px;
    margin-bottom: 8px;
    border: 0.5px solid #e5e7eb;
}
.chat-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.chat-input {
    flex: 1;
    border: 0.5px solid #e5e7eb;
    border-radius: 22px;
    padding: 9px 16px;
    font-size: 13px;
    outline: none;
    background: #f5f6fa;
    color: #111;
}
.chat-input::placeholder { color: #9ca3af; }
.chat-input:focus { border-color: #93c5fd; background: #fff; }

/* Botones */
.btn-icon-round {
    background: none;
    border: 0.5px solid #e5e7eb;
    border-radius: 50%;
    width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    color: #6b7280;
    font-size: 14px;
    flex-shrink: 0;
    transition: background .12s;
}
.btn-icon-round:hover { background: #f5f6fa; }
.btn-send {
    background: #2563eb;
    border: none;
    border-radius: 50%;
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    color: #fff;
    font-size: 15px;
    flex-shrink: 0;
    transition: background .12s;
}
.btn-send:hover { background: #1d4ed8; }

/* Scrollbar */
.chat-messages::-webkit-scrollbar,
.sidebar-body::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-thumb,
.sidebar-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
</style>

<?php require_once 'includes/footer.php'; ?>
