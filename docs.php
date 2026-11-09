<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentación Oficial - Desk Service</title>
    <link rel="shortcut icon" href="assets/img/deskser.png" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; color: #1e293b; scroll-behavior: smooth; }
        .navbar { background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-bottom: 1px solid #e2e8f0; }
        .docs-header { background: #0f172a; color: #fff; padding: 80px 0; margin-bottom: 40px; border-radius: 0; }
        .card-doc { border: none; border-radius: 20px; transition: all 0.3s ease; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .card-doc:hover { transform: translateY(-5px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .accordion-item { border: 1px solid #e2e8f0; margin-bottom: 1rem; border-radius: 16px !important; overflow: hidden; }
        .accordion-button { font-weight: 700; padding: 1.25rem; background: white; }
        .accordion-button:not(.collapsed) { background-color: #f1f5f9; color: #2563eb; box-shadow: none; }
        .badge-step { width: 32px; height: 32px; background: #2563eb; color: #fff; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; margin-right: 12px; font-weight: 800; }
        .sidebar-nav .nav-link { color: #64748b; padding: 12px 20px; border-radius: 12px; margin-bottom: 5px; font-weight: 600; transition: all 0.2s; }
        .sidebar-nav .nav-link:hover, .sidebar-nav .nav-link.active { background: #2563eb; color: white; }
        .code-snippet { background: #1e293b; color: #f8fafc; padding: 24px; border-radius: 16px; font-family: 'Fira Code', monospace; margin: 24px 0; }
        .section-title { position: relative; padding-bottom: 15px; margin-bottom: 30px; }
        .section-title::after { content: ''; position: absolute; bottom: 0; left: 0; width: 60px; height: 4px; background: #2563eb; border-radius: 2px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="./">
                <img src="assets/img/deskser.png" width="32" height="32" alt="Logo">
                <span class="fw-bold fs-4">Desk Service</span>
            </a>
            <div class="ms-auto">
                <a href="./" class="btn btn-light rounded-pill px-4 fw-bold">Volver al Inicio</a>
            </div>
        </div>
    </nav>

    <header class="docs-header text-center">
        <div class="container">
            <h1 class="display-4 fw-800">Centro de Documentación</h1>
            <p class="lead opacity-75">Guía completa para administradores, técnicos y usuarios.</p>
        </div>
    </header>

    <div class="container pb-5">
        <div class="row">
            <!-- Menú Lateral -->
            <div class="col-lg-3 mb-4">
                <div class="card card-doc p-3 sticky-top shadow-sm" style="top: 100px;">
                    <nav class="nav flex-column sidebar-nav">
                        <a class="nav-link active" href="#telegram"><i class="fab fa-telegram-plane me-2"></i>Telegram Bot</a>
                        <a class="nav-link" href="#whatsapp"><i class="fab fa-whatsapp me-2"></i>WhatsApp API</a>
                        <a class="nav-link" href="#email"><i class="fas fa-envelope me-2"></i>Configuración Email</a>
                        <a class="nav-link" href="#tickets"><i class="fas fa-tasks me-2"></i>Gestión de Tickets</a>
                        <a class="nav-link" href="#inventario"><i class="fas fa-boxes me-2"></i>Inventario IT</a>
                        <a class="nav-link" href="#seguridad"><i class="fas fa-shield-alt me-2"></i>Seguridad & 2FA</a>
                        <a class="nav-link" href="#base-conocimiento"><i class="fas fa-book me-2"></i>Base de Conocimiento</a>
                        <a class="nav-link" href="#analitica"><i class="fas fa-chart-bar me-2"></i>Reportes & Analítica</a>
                    </nav>
                </div>
            </div>

            <!-- Contenido Principal -->
            <div class="col-lg-9">
                <!-- Sección Telegram -->
                <section id="telegram" class="mb-5">
                    <h2 class="fw-bold mb-4 text-primary"><i class="fab fa-telegram me-3"></i>Configuración de Telegram</h2>
                    <div class="accordion" id="accTelegram">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#tgBot">
                                    1. Obtener Token y Chat ID
                                </button>
                            </h3>
                            <div id="tgBot" class="accordion-collapse collapse show" data-bs-parent="#accTelegram">
                                <div class="accordion-body">
                                    <p><span class="badge-step">1</span> <b>Crear el Bot:</b> Busca a <code>@BotFather</code> en Telegram. Envía <code>/newbot</code>, elige un nombre y obtén tu <b>HTTP API Token</b>.</p>
                                    <p><span class="badge-step">2</span> <b>Obtener Chat ID:</b> Para recibir notificaciones en un grupo o canal:</p>
                                    <ul>
                                        <li>Añade a tu bot al grupo deseado.</li>
                                        <li>Busca el bot <code>@userinfobot</code> o <code>@GetIDsBot</code> y envíale un mensaje desde el grupo, o simplemente añade el bot al grupo y escribe algo.</li>
                                        <li>El <b>Chat ID</b> suele ser un número largo (los de grupos empiezan con un guion, ej: <code>-100123456789</code>).</li>
                                    </ul>
                                    <div class="info-box small">
                                        <b>Importante:</b> Sin el Chat ID correcto, el sistema no sabrá a dónde enviar las alertas de nuevos tickets.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Sección WhatsApp -->
                <section id="whatsapp" class="mb-5">
                    <h2 class="fw-bold mb-4 text-success"><i class="fab fa-whatsapp me-3"></i>WhatsApp Business API</h2>
                    <div class="accordion" id="accWhatsapp">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#waConfig">
                                    Configuración de la API (Meta)
                                </button>
                            </h3>
                            <div id="waConfig" class="accordion-collapse collapse" data-bs-parent="#accWhatsapp">
                                <div class="accordion-body">
                                    <p>Desk Service se integra con la API oficial de WhatsApp Cloud. Sigue estos pasos:</p>
                                    <p><span class="badge-step">1</span> Crea una cuenta en <a href="https://developers.facebook.com" target="_blank">Meta for Developers</a>.</p>
                                    <p><span class="badge-step">2</span> Crea una "App" de tipo <b>Negocios</b> y añade el producto <b>WhatsApp</b>.</p>
                                    <p><span class="badge-step">3</span> <b>Tokens:</b> En el panel de WhatsApp, obtén el <b>Token de acceso temporal</b> (o configura uno permanente) y el <b>Identificador de número de teléfono</b>.</p>
                                    <p><span class="badge-step">4</span> <b>Webhook:</b> Configura la URL de retorno que te proporciona Desk Service en la sección de Integraciones para recibir los mensajes de tus clientes.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Sección Email -->
                <section id="email" class="mb-5">
                    <h2 class="fw-bold mb-4 text-warning"><i class="fas fa-envelope me-3"></i>Configuración de Correo (SMTP)</h2>
                    <div class="accordion" id="accEmail">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#emailSmtp">
                                    Envío y Recepción de Emails
                                </button>
                            </h3>
                            <div id="emailSmtp" class="accordion-collapse collapse" data-bs-parent="#accEmail">
                                <div class="accordion-body">
                                    <h5>Envío de Notificaciones:</h5>
                                    <p>Configura tu servidor SMTP (Gmail, Outlook o propio) en <b>Administración > Ajustes de Email</b>:</p>
                                    <ul class="small">
                                        <li><b>Host:</b> ej. smtp.gmail.com</li>
                                        <li><b>Puerto:</b> 587 (TLS) o 465 (SSL)</li>
                                        <li><b>Cifrado:</b> STARTTLS o SSL</li>
                                    </ul>
                                    <div class="info-box small">
                                        <b>Tip:</b> Si usas Gmail, recuerda generar una <b>Contraseña de Aplicación</b> en tu cuenta de Google.
                                    </div>
                                    <hr>
                                    <h5>Email a Ticket (IMAP):</h5>
                                    <p>Para que los correos que recibes se conviertan en tickets automáticamente, activa la lectura IMAP con la misma configuración de servidor.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Sección Tickets -->
                <section id="tickets" class="mb-5">
                    <h2 class="fw-bold mb-4 text-info section-title"><i class="fas fa-tasks me-3"></i>Guía de Uso de Tickets</h2>
                    <div class="accordion" id="accTickets">
                        <div class="accordion-item shadow-sm">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ticketOps">
                                    Funcionalidades y Acciones
                                </button>
                            </h3>
                            <div id="ticketOps" class="accordion-collapse collapse" data-bs-parent="#accTickets">
                                <div class="accordion-body">
                                    <p>Dentro de cada ticket, tienes las siguientes herramientas:</p>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="p-3 border rounded">
                                                <h6><i class="fas fa-reply me-2 text-primary"></i>Respuestas Enriquecidas</h6>
                                                <p class="small text-muted mb-0">Usa el editor para dar formato, insertar enlaces o imágenes en tus respuestas.</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-3 border rounded">
                                                <h6><i class="fas fa-paperclip me-2 text-primary"></i>Adjuntos Multi-formato</h6>
                                                <p class="small text-muted mb-0">Sube PDFs, imágenes o logs de error para documentar el caso.</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-3 border rounded">
                                                <h6><i class="fas fa-user-tag me-2 text-primary"></i>Asignación de Técnicos</h6>
                                                <p class="small text-muted mb-0">Asigna responsables principales y secundarios para trabajo colaborativo.</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-3 border rounded">
                                                <h6><i class="fas fa-history me-2 text-primary"></i>Historial de Actividad</h6>
                                                <p class="small text-muted mb-0">Audita cada cambio de estado, prioridad o técnico asignado.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Sección Inventario -->
                <section id="inventario" class="mb-5">
                    <h2 class="fw-bold mb-4 text-primary section-title"><i class="fas fa-boxes me-3"></i>Gestión de Inventario IT</h2>
                    <div class="accordion" id="accInventario">
                        <div class="accordion-item shadow-sm">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#invActivos">
                                    Control de Activos y Equipos
                                </button>
                            </h3>
                            <div id="invActivos" class="accordion-collapse collapse" data-bs-parent="#accInventario">
                                <div class="accordion-body">
                                    <p>El módulo de inventario te permite llevar un control riguroso de los recursos tecnológicos de tu empresa:</p>
                                    <ul>
                                        <li><strong>Registro de Equipos:</strong> Laptops, desktops, servidores, periféricos, etc.</li>
                                        <li><strong>Asignación:</strong> Vincula cada equipo a un usuario o departamento específico.</li>
                                        <li><strong>Trazabilidad:</strong> Visualiza todos los tickets asociados a un equipo para detectar fallas recurrentes.</li>
                                        <li><strong>Estados:</strong> Controla si un equipo está Activo, En Reparación, En Bodega o De Baja.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Sección Seguridad -->
                <section id="seguridad" class="mb-5">
                    <h2 class="fw-bold mb-4 text-danger section-title"><i class="fas fa-shield-alt me-3"></i>Seguridad & 2FA</h2>
                    <div class="accordion" id="accSeguridad">
                        <div class="accordion-item shadow-sm">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#seg2fa">
                                    Protección de Cuentas
                                </button>
                            </h3>
                            <div id="seg2fa" class="accordion-collapse collapse" data-bs-parent="#accSeguridad">
                                <div class="accordion-body">
                                    <p>Desk Service prioriza la seguridad de tus datos. Recomendamos activar estas funciones:</p>
                                    <p><span class="badge-step">1</span> <b>2FA (Autenticación de Dos Factores):</b> Activa el uso de Google Authenticator o Microsoft Authenticator desde tu perfil.</p>
                                    <p><span class="badge-step">2</span> <b>Sesiones Activas:</b> Monitorea desde dónde se ha iniciado sesión en tu cuenta.</p>
                                    <p><span class="badge-step">3</span> <b>Logs de Auditoría:</b> Los administradores pueden ver un historial detallado de acciones críticas realizadas en el sistema.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Sección Base de Conocimiento -->
                <section id="base-conocimiento" class="mb-5">
                    <h2 class="fw-bold mb-4 text-success section-title"><i class="fas fa-book me-3"></i>Base de Conocimiento</h2>
                    <div class="accordion" id="accKb">
                        <div class="accordion-item shadow-sm">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#kbArticles">
                                    Auto-servicio para Usuarios
                                </button>
                            </h3>
                            <div id="kbArticles" class="accordion-collapse collapse" data-bs-parent="#accKb">
                                <div class="accordion-body">
                                    <p>Reduce la carga de tickets creando una base de conocimientos robusta:</p>
                                    <ul>
                                        <li><strong>Artículos FAQ:</strong> Crea guías paso a paso para resolver problemas comunes.</li>
                                        <li><strong>Búsqueda Inteligente:</strong> Los usuarios verán sugerencias de artículos antes de crear un ticket.</li>
                                        <li><strong>Categorías:</strong> Organiza la información por departamentos o tipos de servicio.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Sección Analítica -->
                <section id="analitica" class="mb-5">
                    <h2 class="fw-bold mb-4 text-warning section-title"><i class="fas fa-chart-bar me-3"></i>Reportes & Analítica</h2>
                    <div class="accordion" id="accAnalytics">
                        <div class="accordion-item shadow-sm">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#anaReports">
                                    Midiendo el Éxito
                                </button>
                            </h3>
                            <div id="anaReports" class="accordion-collapse collapse" data-bs-parent="#accAnalytics">
                                <div class="accordion-body">
                                    <p>Toma decisiones basadas en datos con nuestros módulos de reportes:</p>
                                    <ul>
                                        <li><strong>SLA Compliance:</strong> Porcentaje de tickets resueltos dentro del tiempo establecido.</li>
                                        <li><strong>Carga por Técnico:</strong> Distribución de trabajo entre tu equipo de soporte.</li>
                                        <li><strong>Exportación:</strong> Descarga reportes en Excel/PDF para presentaciones o auditorías externas.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
