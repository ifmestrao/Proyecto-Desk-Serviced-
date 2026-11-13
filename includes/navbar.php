<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar-wrapper" style="background-color: #1e293b; padding-right: 0px; overflow-x: hidden;">
        <div class="sidebar-heading text-center py-4 primary-text fs-4 fw-bold text-uppercase border-bottom" style="color: #fff; border-bottom: 1px solid rgba(255,255,255,0.1) !important;">
            <img src="/assets/img/Mesadeservicio.png" alt="Logo" style="max-width: 150px; height: auto;">
        </div>
        <div class="list-group list-group-flush my-3" id="sidebar-menu">
            <?php 
            $current = basename($_SERVER['PHP_SELF']); 
            $isTicketDetails = ($current === 'ticket_details.php'); 
            require_once 'includes/csrf.php'; 
            ?>

            <style>
                .sidebar {
                    width: 260px;
                    transition: all 0.3s;
                    background-color: #1e293b !important;
                }
                .sidebar-heading {
                    color: #fff !important;
                    border-bottom: 1px solid rgba(255,255,255,0.1) !important;
                }
                .sidebar .list-group-item {
                    border: none;
                    padding: 0.8rem 1.5rem;
                    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                    color: #94a3b8;
                    display: flex;
                    align-items: center;
                    background: transparent;
                    margin: 0.25rem 1rem;
                    border-radius: 8px;
                }
                .sidebar .list-group-item:hover {
                    background-color: rgba(255, 255, 255, 0.05) !important;
                    color: #fff;
                    transform: translateX(4px);
                }
                .sidebar .list-group-item.active {
                    background-color: #3b82f6 !important;
                    color: #fff !important;
                    border-radius: 8px;
                    font-weight: 600;
                }
                .sidebar .nav-link-collapse {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .sidebar .nav-link-collapse span {
                    flex-grow: 1;
                }
                .sidebar .collapse-inner {
                    padding: 5px 0;
                }
                .sidebar .collapse-inner .list-group-item {
                    font-size: 0.9rem;
                    padding: 0.6rem 1rem 0.6rem 3rem;
                    margin: 2px 1rem;
                }
                .sidebar .rotate-icon {
                    transition: transform 0.3s;
                    font-size: 0.8rem;
                    margin-right: 10px; /* Alejar un poco del borde derecho */
                }
                .sidebar .nav-link-collapse[aria-expanded="true"] .rotate-icon {
                    transform: rotate(180deg);
                }
                /* Quitar scrollbars si aparecen */
                #sidebar-wrapper::-webkit-scrollbar {
                    width: 4px;
                }
                #sidebar-wrapper::-webkit-scrollbar-thumb {
                    background: rgba(255,255,255,0.1);
                    border-radius: 10px;
                }
            </style>

            
            <?php if($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'supervisor' || $_SESSION['role'] == 'tech'): ?>
                <!-- Grupo Soporte -->
                <?php 
                $soportePages = ['dashboard.php', 'sla_dashboard.php', 'create_ticket.php', 'estadisticas.php', 'tickets_historial.php', 'multicanal.php'];
                $isSoporteActive = in_array($current, $soportePages) || $isTicketDetails;
                ?>
                <a class="list-group-item list-group-item-action bg-transparent second-text fw-bold nav-link-collapse <?php echo $isSoporteActive ? '' : 'collapsed'; ?>" 
                   data-bs-toggle="collapse" href="#collapseSoporte" role="button" aria-expanded="<?php echo $isSoporteActive ? 'true' : 'false'; ?>">
                    <i class="fas fa-solid fa-headset" style="color: rgb(116, 192, 252);"></i>
                    <span>Soporte</span>
                    <i class="fas fa-chevron-down rotate-icon"></i>
                </a>
                <div class="collapse <?php echo $isSoporteActive ? 'show' : ''; ?>" id="collapseSoporte" data-bs-parent="#sidebar-menu">
                    <div class="collapse-inner">
                        <a href="dashboard" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo ($current == 'dashboard.php' || $isTicketDetails) ? 'active' : ''; ?>">
                            <i class="fas fa-solid fa-gauge" style="color: rgb(116, 192, 252);"></i>Dashboard
                        </a>
                        <a href="create_ticket" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'create_ticket.php' ? 'active' : ''; ?>">
                             <i class="fas fa-plus-circle" style="color: rgb(116, 192, 252);"></i>Nuevo Ticket
                        </a>
                        <a href="sla_dashboard" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'sla_dashboard.php' ? 'active' : ''; ?>">
                            <i class="fas fa-stopwatch" style="color: rgb(116, 192, 252);"></i>Panel SLA
                        </a>
                        <a href="estadisticas" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'estadisticas.php' ? 'active' : ''; ?>">
                            <i class="fas fa-solid fa-chart-pie" style="color: rgb(116, 192, 252);"></i>Estadísticas 
                        </a>
                        <a href="multicanal" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'multicanal.php' ? 'active' : ''; ?>">
                            <i class="fas fa-project-diagram" style="color: rgb(116, 192, 252);"></i>Multicanal
                        </a>
                        <a href="tickets_historial" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'tickets_historial.php' ? 'active' : ''; ?>">
                            <i class="fas fa-solid fa-file-medical" style="color: rgb(116, 192, 252);"></i>Historico
                        </a>

                    </div>
                </div>

                <!-- Grupo Comunicaciones -->
                <a class="list-group-item list-group-item-action bg-transparent second-text fw-bold nav-link-collapse <?php echo in_array($current, ['cronograma.php', 'my_activity.php']) ? '' : 'collapsed'; ?>" 
                   data-bs-toggle="collapse" href="#collapseComms" role="button" aria-expanded="<?php echo in_array($current, ['cronograma.php', 'my_activity.php']) ? 'true' : 'false'; ?>">
                    <i class="fas fa-solid fa-timeline" style="color: rgb(116, 192, 252);"></i>
                    <span>Eventos</span>
                    <i class="fas fa-chevron-down rotate-icon"></i>
                </a>
                <div class="collapse <?php echo in_array($current, ['cronograma.php', 'my_activity.php']) ? 'show' : ''; ?>" id="collapseComms" data-bs-parent="#sidebar-menu">
                    <div class="collapse-inner">
                        <a href="cronograma" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'cronograma.php' ? 'active' : ''; ?>">
                            <i class="fas fa-solid fa-calendar-check" style="color: rgb(116, 192, 252);"></i>Cronograma
                        </a>
                        <a href="my_activity" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'my_activity.php' ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line" style="color: rgb(116, 192, 252);"></i>Mi Actividad
                        </a>
                    </div>
                </div>

                <!-- Grupo Administración -->
                <?php 
                $adminPages = ['inventario.php', 'tools.php', 'usuarios.php', 'email_diseno.php', 'asistente_bot.php', 'integraciones.php', 'exportar.php', 'actas.php', 'actas_crear.php', 'acta_view.php', 'configuracion_email.php', 'admin_grupos.php', 'admin_entidades.php', 'admin_reglas.php', 'admin_jerarquia.php', 'admin_servicios.php'];
                $isAdminActive = in_array($current, $adminPages);
                ?>
                <a class="list-group-item list-group-item-action bg-transparent second-text fw-bold nav-link-collapse <?php echo $isAdminActive ? '' : 'collapsed'; ?>" 
                   data-bs-toggle="collapse" href="#collapseAdmin" role="button" aria-expanded="<?php echo $isAdminActive ? 'true' : 'false'; ?>">
                    <i class="fas fa-solid fa-gears" style="color: rgb(116, 192, 252);"></i>
                    <span>Administración</span>
                    <i class="fas fa-chevron-down rotate-icon"></i>
                </a>
                <div class="collapse <?php echo $isAdminActive ? 'show' : ''; ?>" id="collapseAdmin" data-bs-parent="#sidebar-menu">
                    <div class="collapse-inner">
                        <a href="admin_servicios" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'admin_servicios.php' ? 'active' : ''; ?>">
                            <i class="fas fa-tags" style="color: rgb(116, 192, 252);"></i>Portal Servicios
                        </a>
                        <a href="admin_grupos" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'admin_grupos.php' ? 'active' : ''; ?>">
                            <i class="fas fa-users" style="color: rgb(116, 192, 252);"></i>Grupos
                        </a>
                        <a href="admin_reglas" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'admin_reglas.php' ? 'active' : ''; ?>">
                            <i class="fas fa-ruler-combined" style="color: rgb(116, 192, 252);"></i>Reglas
                        </a>
                        <a href="admin_jerarquia" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'admin_jerarquia.php' ? 'active' : ''; ?>">
                            <i class="fas fa-sitemap" style="color: rgb(116, 192, 252);"></i>Jerarquía
                        </a>
                        <a href="inventario" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'inventario.php' ? 'active' : ''; ?>">
                            <i class="fas fa-solid fa-cart-flatbed" style="color: rgb(116, 192, 252);"></i>Inventario
                        </a>
                        <a href="configuracion_email" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'configuracion_email.php' ? 'active' : ''; ?>">
                            <i class="fas fa-envelope" style="color: rgb(116, 192, 252);"></i>Correo
                        </a>
                        <a href="usuarios" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'usuarios.php' ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog" style="color: rgb(116, 192, 252);"></i>Usuarios
                        </a>
                        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'supervisor'], true)): ?>
                        <a href="email_diseno" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'email_diseno.php' ? 'active' : ''; ?>">
                            <i class="fas fa-palette" style="color: rgb(116, 192, 252);"></i>Diseño de correos
                        </a>
                        <a href="asistente_bot" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'asistente_bot.php' ? 'active' : ''; ?>">
                            <i class="fab fa-whatsapp" style="color: rgb(116, 192, 252);"></i>Asistente WhatsApp
                        </a>
                        <?php endif; ?>
                        <a href="tools" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'tools.php' ? 'active' : ''; ?>">
                            <i class="fas fa-solid fa-toolbox" style="color: rgb(116, 192, 252);"></i>Herramientas
                        </a>
                        <a href="integraciones" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'integraciones.php' ? 'active' : ''; ?>">
                            <i class="fas fa-plug" style="color: rgb(116, 192, 252);"></i>Integraciones
                        </a>
                        <a href="actas" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'actas.php' ? 'active' : ''; ?>">
                            <i class="fas fa-file-signature" style="color: rgb(116, 192, 252);"></i>Actas
                        </a>
                        <?php if($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'supervisor'): ?>
                            <a href="exportar" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'exportar.php' ? 'active' : ''; ?>">
                                <i class="fas fa-file-export" style="color: rgb(116, 192, 252);"></i>Exportar
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                
                
            <?php else: ?>
                <a href="dashboard_user" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo ($current == 'dashboard_user.php' || $isTicketDetails) ? 'active' : ''; ?>">
                    <i class="fas fa-solid fa-gauge me-2"></i>Mis Tickets
                </a>
                <a href="create_ticket" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'create_ticket.php' ? 'active' : ''; ?>">
                    <i class="fas fa-plus-circle me-2"></i>Nuevo Ticket
                </a>
            <?php endif; ?>
            
            <!-- Grupo Servicios -->
            <a class="list-group-item list-group-item-action bg-transparent second-text fw-bold nav-link-collapse <?php echo $current == 'knowledge_base.php' ? '' : 'collapsed'; ?>" 
               data-bs-toggle="collapse" href="#collapseServicios" role="button" aria-expanded="<?php echo $current == 'knowledge_base.php' ? 'true' : 'false'; ?>">
                <i class=" fa-brands fa-intercom " style="color: rgb(116, 192, 252);"></i>
                <span>Servicios</span>
                <i class="fas fa-chevron-down rotate-icon"></i>
            </a>
            <div class="collapse <?php echo $current == 'knowledge_base.php' ? 'show' : ''; ?>" id="collapseServicios" data-bs-parent="#sidebar-menu">
                <div class="collapse-inner">
                    <a href="knowledge_base" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'knowledge_base.php' ? 'active' : ''; ?>">
                        <i class="fas fa-robot" style="color: rgb(116, 192, 252);"></i>Chatbot
                    </a>
                </div>
            </div>
            <div class="collapse <?php echo $current == 'ai_knowledge.php' ? 'show' : ''; ?>" id="collapseServicios" data-bs-parent="#sidebar-menu">
                <div class="collapse-inner">
                    <a href="ai_knowledge.php" class="list-group-item list-group-item-action bg-transparent second-text fw-bold <?php echo $current == 'ai_knowledge.php' ? 'active' : ''; ?>">
                        <i class="fa-brands fa-airbnb" style="color: rgb(116, 192, 252);"></i>Centro AI
                    </a>
                </div>
            </div>
            
            <a href="logout" class="list-group-item list-group-item-action bg-transparent text-danger fw-bold">
                <i class="fas fa-power-off me-2 text-danger"></i>Salir
            </a>
        </div>
    </div>
    <!-- /#sidebar-wrapper -->

    <!-- Page Content Wrapper -->
    <div id="page-content-wrapper" class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white py-3 px-4 shadow-sm border-bottom sticky-top" style="z-index: 1030;">
            <div class="d-flex align-items-center">
                <i class="fas fa-align-left fs-4 me-3" id="menu-toggle" style="cursor: pointer; color: #4b5563;"></i>
                <h2 class="fs-4 m-0 font-weight-bold" style="color: #bfa15f; text-shadow: 1px 1px 2px rgba(0,0,0,0.1);"><?php echo $pageTitle ?? 'Dashboard'; ?></h2>
            </div>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0 d-flex align-items-center">
                    
                    <!-- Theme Switcher -->
                    <li class="nav-item dropdown me-3">
                        <a class="nav-link dropdown-toggle text-secondary" href="#" id="themeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-sun" id="current-theme-icon"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="themeDropdown">
                            <li><a class="dropdown-item theme-btn" href="#" data-theme-val="light"><i class="fas fa-sun text-warning me-2"></i>Claro</a></li>
                            <li><a class="dropdown-item theme-btn" href="#" data-theme-val="dark"><i class="fas fa-moon text-secondary me-2"></i>Oscuro</a></li>
                            <li><a class="dropdown-item theme-btn" href="#" data-theme-val="vampire"><i class="fas fa-tint text-danger me-2"></i>Vampiro</a></li>
                        </ul>
                    </li>

                    <!-- Internal Chat -->
                    <li class="nav-item dropdown me-3">
                        <a class="nav-link second-text fw-bold position-relative" href="#" id="chatDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-comment-dots"></i>
                            <span id="chat-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary d-none">0</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="chatDropdown" style="width:380px;">
                            <li class="px-3 py-2 d-flex justify-content-between align-items-center border-bottom">
                                <span class="fw-bold">Chats recientes</span>
                                <a href="internal_chat" class="text-primary fw-bold small text-decoration-none">Ver todos</a>
                            </li>
                            <li>
                                <div id="chat-preview-list" style="max-height:420px; overflow-y:auto;"></div>
                            </li>
                        </ul>
                    </li>

                    <li class="nav-item dropdown me-3">
                        <a class="nav-link dropdown-toggle second-text fw-bold position-relative" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <span id="notif-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="notifDropdown" style="width: 360px;">
                            <li class="px-3 py-2 d-flex justify-content-between align-items-center">
                                <span class="fw-bold">Notificaciones</span>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-link text-decoration-none" id="notif-delete-all">Eliminar</button>
                                    <button type="button" class="btn btn-sm btn-link text-decoration-none" id="notif-mark-all">Marcar todo</button>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider my-0"></li>
                            <li>
                                <div id="notif-list" style="max-height: 400px; overflow-y: auto;"></div>
                            </li>
                            <li><hr class="dropdown-divider my-0"></li>
                            <li class="px-3 py-2 text-center">
                                <a href="notificaciones" class="text-decoration-none small text-primary fw-bold">Ver todas las notificaciones</a>
                            </li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle second-text fw-bold d-flex align-items-center" href="#" id="navbarDropdown"
                            role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php 
                            // Fetch avatar for navbar
                            $st_nav = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                            $st_nav->execute([$_SESSION['user_id']]);
                            $u_nav = $st_nav->fetch();
                            if ($u_nav && $u_nav['avatar'] && file_exists(__DIR__ . '/../' . $u_nav['avatar'])): ?>
                                <img src="/<?php echo htmlspecialchars($u_nav['avatar']); ?>" class="rounded-circle me-2 border shadow-sm" style="width: 32px; height: 32px; object-fit: cover;">
                            <?php else: ?>
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2 shadow-sm" style="width: 32px; height: 32px; font-size: 0.9rem;">
                                    <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="d-none d-md-block">
                                <div class="lh-1" style="font-size: 0.85rem;"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
                                <div class="text-muted extra-small" style="font-size: 0.65rem;"><?php echo ucfirst($_SESSION['role']); ?></div>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-cog me-2"></i>Mi Perfil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-power-off me-2"></i>Salir</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>

        <script>
            (function() {
                const csrfToken = <?php echo json_encode(csrf_token()); ?>;
                const badge = document.getElementById('notif-badge');
                const list = document.getElementById('notif-list');
                const markAll = document.getElementById('notif-mark-all');
                const deleteAll = document.getElementById('notif-delete-all');
                const dropdown = document.getElementById('notifDropdown');

                const limit = 10; // Aumentar límite para que se vean más sin paginar
                let offset = 0;

                function escapeHtml(str) {
                    const div = document.createElement('div');
                    div.textContent = str == null ? '' : String(str);
                    return div.innerHTML;
                }

                function fmtTime(ts) {
                    if (!ts) return '';
                    const d = new Date(ts.replace(' ', 'T'));
                    if (isNaN(d.getTime())) return '';
                    return d.toLocaleString();
                }

                function render(items) {
                    if (!Array.isArray(items) || items.length === 0) {
                        list.innerHTML = `
                            <div class="px-4 py-5 text-center">
                                <div class="icon-circle bg-light text-muted mx-auto mb-3" style="width: 50px; height: 50px;">
                                    <i class="fas fa-bell-slash"></i>
                                </div>
                                <p class="text-muted small mb-0">No tienes notificaciones por ahora.</p>
                            </div>`;
                        return;
                    }
                    list.innerHTML = '';
                    items.forEach(n => {
                        const row = document.createElement('div');
                        row.className = 'notif-item p-3 border-bottom d-flex gap-3 align-items-start position-relative transition-all';
                        if (!n.read_at) row.classList.add('unread');
                        
                        let iconClass = 'fa-info-circle text-info';
                        if (n.type === 'ticket_new' || n.type === 'new_ticket') iconClass = 'fa-ticket-alt text-primary';
                        if (n.type === 'ticket_update' || n.type === 'update') iconClass = 'fa-sync-alt text-warning';
                        if (n.type === 'ticket_resolved' || n.type === 'resolved') iconClass = 'fa-check-circle text-success';
                        if (n.type === 'alert' || n.type === 'critical') iconClass = 'fa-exclamation-triangle text-danger';

                        row.innerHTML = `
                            <div class="notif-icon-circle flex-shrink-0">
                                <i class="fas ${iconClass}"></i>
                            </div>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <a href="${escapeHtml(n.url || '#')}" class="text-decoration-none text-reset notif-open d-block">
                                    <div class="fw-bold text-dark small mb-1">${escapeHtml(n.title)}</div>
                                    ${n.body ? `<div class="text-muted extra-small text-truncate-2 mb-2" style="line-height: 1.2;">${escapeHtml(n.body)}</div>` : ''}
                                    <div class="d-flex align-items-center justify-content-between mt-1">
                                        <span class="text-muted" style="font-size: 0.65rem;">
                                            <i class="far fa-clock me-1"></i>${escapeHtml(fmtTime(n.created_at))}
                                        </span>
                                        ${!n.read_at ? '<span class="badge bg-primary rounded-circle p-1" style="width: 6px; height: 6px;"> </span>' : ''}
                                    </div>
                                </a>
                            </div>
                            <button type="button" class="btn btn-xs btn-link text-muted p-0 hover-danger notif-del" title="Eliminar">
                                <i class="fas fa-times"></i>
                            </button>
                        `;

                        // Add styles for notifications and chat preview if not present
                        if (!document.getElementById('notif-styles')) {
                            const style = document.createElement('style');
                            style.id = 'notif-styles';
                            style.innerHTML = `
                                .notif-item { transition: all 0.2s ease; cursor: pointer; border-left: 3px solid transparent; }
                                .notif-item:hover { background-color: rgba(0,0,0,0.02); }
                                .notif-item.unread { background-color: rgba(13, 110, 253, 0.04); border-left-color: #0d6efd; }
                                .notif-icon-circle { width: 32px; height: 32px; border-radius: 8px; background: rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
                                .text-truncate-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
                                .hover-danger:hover { color: #dc3545 !important; }
                                .hover-bg-light:hover { background-color: rgba(0,0,0,0.03); }
                            `;
                            document.head.appendChild(style);
                        }

                        row.querySelector('.notif-open').addEventListener('click', function(e) {
                            if (!n.url) e.preventDefault();
                            fetch('notifications_api.php?action=mark_read', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&id=' + encodeURIComponent(n.id)
                            }).then(() => {
                                if (n.url) window.location.href = n.url;
                                else refresh();
                            });
                        });
                        row.querySelector('.notif-del').addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            fetch('notifications_api.php?action=delete', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&id=' + encodeURIComponent(n.id)
                            }).then(() => refresh()).catch(() => {});
                        });
                        list.appendChild(row);
                    });
                }

                function setBadge(count) {
                    const n = Number(count || 0);
                    if (n > 0) {
                        badge.textContent = n > 99 ? '99+' : String(n);
                        badge.classList.remove('d-none');
                    } else {
                        badge.classList.add('d-none');
                    }
                }

                function refresh() {
                    fetch(`notifications_api.php?action=fetch&limit=${limit}&offset=0`)
                        .then(r => r.json())
                        .then(data => {
                            setBadge(data.unreadCount);
                            render(data.items);
                        });
                }

                if (markAll) {
                    markAll.addEventListener('click', function(e) {
                        fetch('notifications_api.php?action=mark_all', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'csrf_token=' + encodeURIComponent(csrfToken)
                        }).then(() => refresh()).catch(() => {});
                    });
                }

                if (deleteAll) {
                    deleteAll.addEventListener('click', function() {
                        fetch('notifications_api.php?action=delete_all', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'csrf_token=' + encodeURIComponent(csrfToken)
                        }).then(() => refresh()).catch(() => {});
                    });
                }

                if (dropdown) {
                    dropdown.addEventListener('click', function() {
                        offset = 0;
                        setTimeout(refresh, 50);
                    });
                }

                refresh();
                setInterval(refresh, 10000);
            })();

            // Internal Chat Preview Logic
            (function() {
                const chatBadge = document.getElementById('chat-badge');
                const chatList = document.getElementById('chat-preview-list');
                const chatDropdown = document.getElementById('chatDropdown');
                
                function setChatBadge(count) {
                    const n = Number(count || 0);
                    if (n > 0) {
                        chatBadge.textContent = n > 99 ? '99+' : String(n);
                        chatBadge.classList.remove('d-none');
                    } else {
                        chatBadge.classList.add('d-none');
                    }
                }
                
                function renderChatPreview(chats) {
    if (!Array.isArray(chats) || chats.length === 0) {
        chatList.innerHTML = `
            <div class="px-4 py-5 text-center">
                <div class="icon-circle bg-light text-muted mx-auto mb-3" style="width:50px; height:50px;">
                    <i class="fas fa-comments-slash"></i>
                </div>
                <p class="text-muted small mb-0">No tienes chats por ahora.</p>
                <a href="internal_chat" class="text-primary fw-bold small text-decoration-none">Iniciar un chat</a>
            </div>`;
        return;
    }
    
    chatList.innerHTML = chats.map(chat => {
        const lastMsg = chat.last_message || 'No hay mensajes';
        const hasImage = !!chat.last_image;
        const previewText = hasImage ? '<i class="fas fa-image me-1"></i> Imagen' : lastMsg;
        
        return `
        <a href="internal_chat?room_id=${chat.id}" class="text-decoration-none text-reset d-block p-3 border-bottom hover-bg-light">
            <div class="d-flex gap-3 align-items-center">
                ${chat.other_user_avatar ? 
                    `<img src="${chat.other_user_avatar}" class="rounded-circle" style="width:44px;height:44px;object-fit:cover;">` 
                    : `<div class="rounded-circle bg-gradient-to-r from-blue-500 to-blue-700 d-flex align-items-center justify-content-center text-white fw-bold" style="width:44px;height:44px;">
                        ${(chat.other_user_name || 'U').charAt(0).toUpperCase()}
                       </div>`}
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <span class="fw-bold">${chat.other_user_name || 'Usuario'}</span>
                        ${chat.last_message_time ? `<small class="text-muted">${new Date(chat.last_message_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</small>` : ''}
                    </div>
                    <div class="text-muted small text-truncate">${previewText}</div>
                </div>
                ${chat.unread_count > 0 ? `<span class="badge bg-primary rounded-pill">${chat.unread_count}</span>` : ''}
            </div>
        </a>`;
    }).join('');
}
                
                async function refreshChatPreview() {
                    try {
                        const res = await fetch('api_internal_chat.php?action=get_recent');
                        const data = await res.json();
                        if (data.success) {
                            let totalUnread = 0;
                            if (Array.isArray(data.chats)) {
                                data.chats.forEach(c => { totalUnread += Number(c.unread_count || 0); });
                            }
                            setChatBadge(totalUnread);
                            renderChatPreview(data.chats);
                        }
                    } catch(e) { console.error(e); }
                }
                
                if (chatDropdown) {
                    chatDropdown.addEventListener('click', () => {
                        setTimeout(refreshChatPreview, 50);
                    });
                }
                
                refreshChatPreview();
                setInterval(refreshChatPreview, 5000);
            })();

            // Theme Switcher Logic
            document.addEventListener('DOMContentLoaded', () => {
                const themeBtns = document.querySelectorAll('.theme-btn');
                const currentIcon = document.getElementById('current-theme-icon');
                
                function applyTheme(theme) {
                    if (theme === 'dark') {
                        document.documentElement.setAttribute('data-bs-theme', 'dark');
                        document.documentElement.removeAttribute('data-theme');
                        currentIcon.className = 'fas fa-moon';
                    } else if (theme === 'vampire') {
                        document.documentElement.setAttribute('data-bs-theme', 'dark');
                        document.documentElement.setAttribute('data-theme', 'vampire');
                        currentIcon.className = 'fas fa-tint';
                        currentIcon.style.color = '#bd93f9';
                    } else {
                        document.documentElement.setAttribute('data-bs-theme', 'light');
                        document.documentElement.removeAttribute('data-theme');
                        currentIcon.className = 'fas fa-sun';
                        currentIcon.style.color = '';
                    }
                    localStorage.setItem('deskservice_theme', theme);
                    document.cookie = `deskservice_theme=${theme}; path=/; max-age=31536000; SameSite=Lax`;
                }

                // Initial load icon
                const getCookie = (name) => {
                    const value = `; ${document.cookie}`;
                    const parts = value.split(`; ${name}=`);
                    if (parts.length === 2) return parts.pop().split(';').shift();
                };
                const savedTheme = getCookie('deskservice_theme') || localStorage.getItem('deskservice_theme') || 'light';
                applyTheme(savedTheme);

                themeBtns.forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        const theme = btn.getAttribute('data-theme-val');
                        applyTheme(theme);
                    });
                });
            });
        </script>
