    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script src="/assets/js/script.js"></script>

    <?php
    
    $__showChatWidget = isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'user'
        && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'knowledge_base.php';
    if ($__showChatWidget):
        require_once __DIR__ . '/csrf.php';
    ?>

    <div id="ai-chat-widget" class="position-fixed bottom-0 end-0 m-4" style="z-index: 9999; display: flex; flex-direction: column; align-items: flex-end;">

        <!-- Chat Window -->
        <div id="ai-chat-window" class="card shadow-lg border-0 d-none mb-3" style="width: 370px; max-width: calc(100vw - 32px); height: 560px; max-height: 82vh; border-radius: 16px; overflow: hidden;">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2 px-3">
                <div class="d-flex align-items-center">
                    <div class="bg-white text-primary rounded-circle d-flex justify-content-center align-items-center me-2" style="width: 32px; height: 32px;">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold">Asistente IA</h6>
                        <small class="opacity-75" style="font-size: 0.7rem;">En línea</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm text-white" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="Historial de conversaciones" aria-label="Historial"><i class="fas fa-clock-rotate-left"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end shadow" id="global-chat-history" style="min-width: 270px; max-height: 300px; overflow-y: auto;"></ul>
                    </div>
                    <button type="button" class="btn btn-sm text-white" id="global-chat-new" title="Nueva conversación" aria-label="Nueva conversación"><i class="fas fa-plus"></i></button>
                    <button type="button" class="btn-close btn-close-white ms-1" id="close-chat-btn" aria-label="Close"></button>
                </div>
            </div>

            <div class="card-body bg-light p-3" id="global-chat-container" style="overflow-y: auto; flex-grow: 1;"></div>

            <div class="card-footer bg-white border-top p-2">
                <div id="global-chat-chips" style="display: none;" class="mb-1"></div>
                <form id="global-chat-form" class="d-flex align-items-center gap-2" autocomplete="off">
                    <button type="button" id="global-chat-attach" class="btn btn-light rounded-circle d-flex justify-content-center align-items-center shadow-sm p-0" style="width: 38px; height: 38px; flex-shrink: 0;" title="Adjuntar archivos (imágenes, PDF, documentos)" aria-label="Adjuntar archivos">
                        <i class="fas fa-paperclip text-muted"></i>
                    </button>
                    <input type="file" id="global-chat-file" class="d-none">
                    <input type="text" id="global-chat-input" class="form-control rounded-pill bg-light border-0 py-2 px-3" style="font-size: 0.85rem;" placeholder="Escribe tu mensaje..." maxlength="1500" autocomplete="off">
                    <button type="submit" id="global-send-btn" class="btn btn-primary rounded-circle d-flex justify-content-center align-items-center shadow-sm" style="width: 38px; height: 38px; flex-shrink: 0;" aria-label="Enviar">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- Toggle Button -->
        <button id="ai-chat-toggle" class="btn btn-primary rounded-circle shadow-lg d-flex justify-content-center align-items-center position-relative" style="width: 60px; height: 60px;" aria-label="Abrir asistente">
            <i class="fas fa-comment-dots fs-3"></i>
            <span class="position-absolute top-0 start-100 translate-middle p-2 bg-danger border border-light rounded-circle">
                <span class="visually-hidden">Nuevos mensajes</span>
            </span>
        </button>
    </div>

    <script src="/assets/js/desk_chat.js?v=3"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggleBtn = document.getElementById('ai-chat-toggle');
        const chatWindow = document.getElementById('ai-chat-window');
        const closeBtn = document.getElementById('close-chat-btn');

        // Se usa el endpoint relativo a la página actual, igual que antes (chatbot_handler.php en la raíz del sitio).
        const chat = new DeskChat({
            endpoint: '/chatbot_handler.php',
            csrf: '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>',
            container: document.getElementById('global-chat-container'),
            form: document.getElementById('global-chat-form'),
            input: document.getElementById('global-chat-input'),
            sendBtn: document.getElementById('global-send-btn'),
            fileInput: document.getElementById('global-chat-file'),
            attachBtn: document.getElementById('global-chat-attach'),
            chipsBox: document.getElementById('global-chat-chips'),
            historyMenu: document.getElementById('global-chat-history'),
            newBtn: document.getElementById('global-chat-new'),
            botBubble: 'bg-white border text-dark',
            greeting: '¡Hola! Soy tu asistente inteligente de Desk Service. Cuéntame qué está pasando y lo revisamos juntos.'
        });

        function toggleChat() {
            const opening = chatWindow.classList.contains('d-none');
            chatWindow.classList.toggle('d-none');
            chatWindow.style.display = opening ? 'flex' : '';
            chatWindow.style.flexDirection = 'column';
            const icon = toggleBtn.querySelector('i');
            if (opening) {
                icon.classList.replace('fa-comment-dots', 'fa-times');
                const badge = toggleBtn.querySelector('.bg-danger');
                if (badge) badge.remove();
                chat.loadCurrent();                       // recupera la conversación guardada
                document.getElementById('global-chat-input').focus();
            } else {
                icon.classList.replace('fa-times', 'fa-comment-dots');
            }
        }
        toggleBtn.addEventListener('click', toggleChat);
        closeBtn.addEventListener('click', toggleChat);
    });
    </script>
    <?php endif; ?>

    <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Auto Logout for Inactivity (20 minutes) -->
    <script>
    (function() {
        let timeout;
        const idleTime = <?php echo (int)app_env('SESSION_TIMEOUT', 1200); ?> * 1000;

        function logout() {
            window.location.href = 'logout.php?reason=inactivity';
        }

        function resetTimer() {
            clearTimeout(timeout);
            timeout = setTimeout(logout, idleTime);
        }

        // Activity events
        window.onload = resetTimer;
        window.onmousemove = resetTimer;
        window.onmousedown = resetTimer; // touches
        window.ontouchstart = resetTimer;
        window.onclick = resetTimer;     // clicks
        window.onkeydown = resetTimer;   
        window.addEventListener('scroll', resetTimer, true);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
