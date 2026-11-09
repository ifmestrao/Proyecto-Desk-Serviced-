<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Desk Service - Plataforma de Mesa de Ayuda</title>
  <link rel="shortcut icon" href="/assets/img/deskser.png" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <link href="/assets/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
  <style>
    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --secondary: #7c3aed;
      --accent: #10b981;
      --dark: #0f172a;
      --light: #f8fafc;
    }
    body { 
      font-family: 'Plus Jakarta Sans', sans-serif !important; 
      color: var(--dark);
      overflow-x: hidden;
      background-color: white !important;
    }
    .hero {
      background: radial-gradient(circle at top right, rgba(124, 58, 237, 0.1), transparent),
                  radial-gradient(circle at bottom left, rgba(37, 99, 235, 0.1), transparent);
      padding: 100px 0 140px 0;
      position: relative;
    }
    .hero::before {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 100px;
      background: white;
      clip-path: polygon(0 100%, 100% 100%, 100% 0);
    }
    .navbar {
      transition: all 0.3s ease;
      padding: 15px 0;
      background: white !important;
    }
    .navbar.scrolled {
      padding: 10px 0;
      background: rgba(255, 255, 255, 0.9) !important;
      backdrop-filter: blur(10px);
    }
    .btn-primary {
      background: var(--primary) !important;
      border: none !important;
      padding: 10px 24px !important;
      border-radius: 10px !important;
      font-weight: 600 !important;
      transition: all 0.3s ease !important;
      color: white !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
    }
    .btn-primary:hover {
      background: var(--primary-dark) !important;
      transform: translateY(-2px) !important;
      box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2) !important;
    }
    .btn-outline-primary {
      border: 1.5px solid var(--primary) !important;
      color: var(--primary) !important;
      padding: 9px 24px !important;
      border-radius: 10px !important;
      font-weight: 600 !important;
      background: transparent !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
    }
    .btn-outline-primary:hover {
      background: var(--primary) !important;
      color: white !important;
    }
    .btn-lg {
      padding: 14px 32px !important;
      font-size: 1.1rem !important;
      border-radius: 14px !important;
    }
    .feature-card {
      border: 1px solid #e2e8f0 !important;
      border-radius: 24px !important;
      padding: 40px !important;
      transition: all 0.3s ease !important;
      background: white !important;
    }
    .feature-card:hover {
      border-color: var(--primary) !important;
      transform: translateY(-10px) !important;
      box-shadow: 0 20px 40px rgba(0,0,0,0.05) !important;
    }
    .trial-badge {
      background: rgba(16, 185, 129, 0.1) !important;
      color: var(--accent) !important;
      padding: 8px 16px !important;
      border-radius: 100px !important;
      font-weight: 700 !important;
      font-size: 0.85rem !important;
      display: inline-block !important;
      margin-bottom: 20px !important;
    }
    .glass-card {
      background: rgba(255, 255, 255, 0.7) !important;
      backdrop-filter: blur(20px) !important;
      border: 1px solid rgba(255, 255, 255, 0.3) !important;
      border-radius: 32px !important;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1) !important;
    }
    .pricing-card {
      border-radius: 32px !important;
      border: 1px solid #e2e8f0 !important;
      padding: 48px !important;
      background: white !important;
      transition: all 0.3s ease !important;
    }
    .pricing-card.featured {
      border: 2px solid var(--primary) !important;
      position: relative !important;
    }
    .pricing-card.featured::after {
      content: 'RECOMENDADO';
      position: absolute;
      top: -15px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--primary);
      color: white;
      padding: 4px 20px;
      border-radius: 100px;
      font-size: 0.75rem;
      font-weight: 800;
    }
    .footer {
      background: #0f172a !important;
      color: #94a3b8 !important;
      padding: 80px 0 40px 0 !important;
    }
    .footer h5 { color: white !important; font-weight: 700 !important; margin-bottom: 24px !important; }
    .footer-link {
      color: #94a3b8 !important;
      text-decoration: none !important;
      transition: all 0.2s ease !important;
      display: block !important;
      margin-bottom: 12px !important;
    }
    .footer-link:hover { color: white !important; padding-left: 5px !important; }
    .animate-float {
      animation: float 6s ease-in-out infinite;
    }
    @keyframes float {
      0% { transform: translateY(0px); }
      50% { transform: translateY(-20px); }
      100% { transform: translateY(0px); }
    }
    /* Arreglar conflictos con otros CSS */
    .nav-link {
      color: var(--dark) !important;
    }
    .nav-link:hover {
      color: var(--primary) !important;
    }
    .navbar-brand span {
      color: var(--dark) !important;
    }
    /* Aumentar tamaño de palabras destacadas */
    h1 .text-primary, h2 .text-primary {
      font-size: 1.25em !important;
      display: inline-block;
      font-weight: 800;
      letter-spacing: -0.5px;
    }
  </style>
  <meta name="description" content="Desk Service: la plataforma de mesa de ayuda más robusta y profesional para tu empresa. Gestión de tickets, multicanal y soporte 24/7.">
</head>
<body data-bs-spy="scroll" data-bs-target="#nav">
  <nav class="navbar navbar-expand-lg bg-white sticky-top shadow-sm" id="mainNav">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" href="/">
        <img src="/assets/img/deskser.png" width="40" height="40" alt="Logo">
        <span class="fw-bold fs-3 tracking-tight">Desk Service</span>
      </a>
      <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav ms-auto mb-2 mb-lg-0 me-4">
          <li class="nav-item"><a class="nav-link fw-medium" href="#caracteristicas">Funciones</a></li>
          <li class="nav-item"><a class="nav-link fw-medium" href="#planes">Precios</a></li>
          <li class="nav-item"><a class="nav-link fw-medium" href="docs.php">Documentación</a></li>
          <li class="nav-item"><a class="nav-link fw-medium" href="#contacto">Contacto</a></li>
        </ul>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-primary px-4" href="login.php">Entrar</a>
          <a class="btn btn-primary px-4" href="create_tenant.php">Empezar Gratis</a>
        </div>
      </div>
    </div>
  </nav>

  <header class="hero overflow-hidden">
    <div class="container">
      <div class="row align-items-center g-5">
        <div class="col-lg-6" data-aos="fade-right">
          <div class="trial-badge">
            <i class="fas fa-gift me-2"></i> PRUÉBALO GRATIS POR 7 DÍAS
          </div>
          <h1 class="display-3 fw-bold mb-4">Soporte técnico <span class="text-primary">inteligente</span> para empresas modernas.</h1>
          <p class="lead text-muted mb-5 fs-5">Optimiza la gestión de tickets, automatiza respuestas y mejora la satisfacción de tus usuarios con la plataforma más completa del mercado.</p>
          <div class="d-flex gap-3 flex-wrap">
            <a class="btn btn-primary btn-lg shadow-lg" href="create_tenant.php">
              <i class="fas fa-rocket me-2"></i>Crear mi Instancia
            </a>
            <a class="btn btn-outline-dark btn-lg" href="myaccount_login.php">
              <i class="fas fa-user-circle me-2"></i>Mi Cuenta
            </a>
          </div>
          <div class="mt-5 d-flex align-items-center gap-4 text-muted small">
            <span><i class="fas fa-check text-success me-2"></i>Sin tarjeta de crédito</span>
            <span><i class="fas fa-check text-success me-2"></i>Instalación en 1 minuto</span>
          </div>
        </div>
        <div class="col-lg-6" data-aos="zoom-in" data-aos-delay="200">
          <div class="position-relative">
            <div class="glass-card p-3 animate-float">
              <img src="assets/img/capdashboard.png" alt="Dashboard" class="img-fluid rounded-4 shadow-2xl">
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <section id="caracteristicas" class="py-5">
    <div class="container py-5">
      <div class="text-center mb-5" data-aos="fade-up">
        <h2 class="display-5 fw-bold mb-3">Todo lo que necesitas para un <span class="text-primary">soporte excepcional</span></h2>
        <p class="text-muted fs-5 mx-auto" style="max-width: 700px;">Herramientas diseñadas para agilizar tu flujo de trabajo y mantener a tus clientes felices.</p>
      </div>
      <div class="row g-4">
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
          <div class="feature-card h-100">
            <h4 class="fw-bold mb-3">Gestión de Tickets</h4>
            <p class="text-muted mb-0">Centraliza todas las solicitudes en un solo lugar con seguimiento detallado y estados personalizables.</p>
          </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
          <div class="feature-card h-100">
            <h4 class="fw-bold mb-3">Multicanal Real</h4>
            <p class="text-muted mb-0">Recibe reportes desde WhatsApp, Telegram y Email. Responde a todos desde un mismo panel unificado.</p>
          </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
          <div class="feature-card h-100">
            <h4 class="fw-bold mb-3">IA & Automatización</h4>
            <p class="text-muted mb-0">Bots inteligentes que responden dudas frecuentes y ayudan a clasificar tickets automáticamente.</p>
          </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="400">
          <div class="feature-card h-100">
            <h4 class="fw-bold mb-3">Análisis & KPIs</h4>
            <p class="text-muted mb-0">Mide el rendimiento de tu equipo con reportes detallados sobre tiempos de respuesta y satisfacción.</p>
          </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="500">
          <div class="feature-card h-100">
            <h4 class="fw-bold mb-3">Seguridad Enterprise</h4>
            <p class="text-muted mb-0">Tus datos están protegidos con cifrado de datos, 2FA y auditorías de seguridad constantes.</p>
          </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="600">
          <div class="feature-card h-100">
            <h4 class="fw-bold mb-3">Inventario IT</h4>
            <p class="text-muted mb-0">Controla tus activos tecnológicos y vinculalos directamente a los tickets de soporte.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="planes" class="py-5 bg-light">
    <div class="container py-5">
      <div class="text-center mb-5" data-aos="fade-up">
        <h2 class="display-5 fw-bold mb-3">Precios <span class="text-primary">transparentes</span></h2>
        <p class="text-muted fs-5">Todo lo que necesitas en un solo plan profesional.</p>
      </div>
      <div class="row justify-content-center">
        <div class="col-lg-5" data-aos="zoom-in">
          <div class="pricing-card featured shadow-xl">
            <h3 class="fw-bold mb-2"  style="text-align: center;">Plan Profesional</h3>
            <p class="text-muted mb-4"  style="text-align: center;">Ideal para empresas en crecimiento.</p>
            <div class="d-flex align-items-end mb-4">
              <span class="display-3 fw-bold" >$16</span>
              <span class="text-muted ms-2 mb-3">USD / mensual</span>
            </div>
            <hr class="my-4">
            <ul class="list-unstyled mb-5">
              <li class="mb-3 d-flex align-items-center gap-2"><i class="fas fa-check-circle text-primary"></i> <strong>Instancias Ilimitadas</strong></li>
              <li class="mb-3 d-flex align-items-center gap-2"><i class="fas fa-check-circle text-primary"></i> Integración WhatsApp & Telegram</li>
              <li class="mb-3 d-flex align-items-center gap-2"><i class="fas fa-check-circle text-primary"></i> Dashboard Real-time</li>
              <li class="mb-3 d-flex align-items-center gap-2"><i class="fas fa-check-circle text-primary"></i> Control de Inventario</li>
              <li class="mb-3 d-flex align-items-center gap-2"><i class="fas fa-check-circle text-primary"></i> Reportes & Exportación</li>
              <li class="mb-3 d-flex align-items-center gap-2"><i class="fas fa-check-circle text-primary"></i> Soporte 24/7</li>
            </ul>
            <a href="create_tenant.php" class="btn btn-primary btn-lg w-100 py-3 fw-bold">
              Comenzar Prueba de 7 Días
            </a>
            <p class="text-center text-muted small mt-3 mb-0">No requiere tarjeta de crédito</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="documentacion" class="py-5">
    <div class="container py-5">
      <div class="row align-items-center g-5">
        <div class="col-lg-6" data-aos="fade-right">
          <h2 class="display-5 fw-bold mb-4">Documentación <span class="text-primary">completa</span> para tu equipo</h2>
          <p class="text-muted fs-5 mb-4">Aprende a configurar integraciones, gestionar usuarios y maximizar la eficiencia de tu mesa de servicio con nuestras guías detalladas.</p>
          <div class="row g-4">
            <div class="col-sm-6">
              <div class="d-flex align-items-start gap-3">
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3"><i class="fas fa-book"></i></div>
                <div>
                  <h6 class="fw-bold mb-1">Guías de Inicio</h6>
                  <p class="small text-muted mb-0">Configura tu instancia en segundos.</p>
                </div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="d-flex align-items-start gap-3">
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3"><i class="fas fa-code"></i></div>
                <div>
                  <h6 class="fw-bold mb-1">API & Webhooks</h6>
                  <p class="small text-muted mb-0">Integra con tus apps favoritas.</p>
                </div>
              </div>
            </div>
          </div>
          <a href="docs.php" class="btn btn-outline-primary fw-bold mt-5 px-4 py-2">Explorar Documentación</a>
        </div>
        <div class="col-lg-6" data-aos="fade-left">
          <div class="glass-card p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
              <div class="bg-danger rounded-circle" style="width:12px; height:12px;"></div>
              <div class="bg-warning rounded-circle" style="width:12px; height:12px;"></div>
              <div class="bg-success rounded-circle" style="width:12px; height:12px;"></div>
            </div>
            <pre class="bg-dark text-light p-3 rounded-3 small mb-0"><code>// Ejemplo de integración
const deskService = new DeskService('TU_API_KEY');
deskService.createTicket({
  title: 'Error en servidor',
  priority: 'high',
  channel: 'api'
});</code></pre>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="galeria" class="py-5 bg-light">
    <div class="container py-5">
      <div class="text-center mb-5" data-aos="fade-up">
        <h2 class="display-5 fw-bold mb-3">Interfaz <span class="text-primary">moderna y limpia</span></h2>
        <p class="text-muted fs-5">Diseñada para la productividad, sin distracciones.</p>
      </div>
      <div class="row g-4">
   		 <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
          	<div class="glass-card overflow-hidden h-100">
            	<img src="assets/img/capdashboard.png" alt="Estadísticas" class="img-fluid">
          	</div>
       	 </div>
    	 <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
          	<div class="glass-card overflow-hidden h-100">
            	<img src="assets/img/capmulticanal.png" alt="Estadísticas" class="img-fluid">
          	</div>
       	 </div>
         <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
          	<div class="glass-card overflow-hidden h-100">
            	<img src="assets/img/caddetalletickets.png" alt="Estadísticas" class="img-fluid">
          	</div>
       	 </div>
    	 <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
          	<div class="glass-card overflow-hidden h-100">
            	<img src="assets/img/capherramientas.png" alt="Estadísticas" class="img-fluid">
          	</div>
       	 </div>
    	 <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
          	<div class="glass-card overflow-hidden h-100">
            	<img src="assets/img/capseguridad.png" alt="Estadísticas" class="img-fluid">
          	</div>
       	 </div>
        <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
          <div class="glass-card overflow-hidden h-100">
            <img src="assets/img/estadisticas.png" alt="Estadísticas" class="img-fluid">
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="contacto" class="py-5">
    <div class="container py-5">
      <div class="glass-card p-5" data-aos="fade-up">
        <div class="row g-5 align-items-center">
          <div class="col-lg-6">
            <h2 class="display-6 fw-bold mb-4">¿Listo para transformar tu soporte?</h2>
            <p class="text-muted fs-5 mb-5">Únete a cientos de empresas que ya optimizan sus procesos con Desk Service. Estamos aquí para ayudarte en cada paso.</p>
            <div class="d-flex flex-column gap-3">
              <div class="mt-4">
                <a href="https://wa.me/573024070830?text=Hola,%20quiero%20más%20información%20sobre%20Desk%20Service" target="_blank" class="btn btn-success btn-lg rounded-pill px-4 shadow-sm">
                  <i class="fab fa-whatsapp me-2"></i>Chatear por WhatsApp
                </a>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <form action="https://formspree.io/f/xjgzygpr" method="POST" class="row g-3">
              <div class="col-md-6">
                <input type="text" name="name" class="form-control form-control-lg border-0 bg-light" placeholder="Nombre" required>
              </div>
              <div class="col-md-6">
                <input type="email" name="email" class="form-control form-control-lg border-0 bg-light" placeholder="Email" required>
              </div>
              <div class="col-12">
                <textarea name="message" class="form-control form-control-lg border-0 bg-light" rows="4" placeholder="¿Cómo podemos ayudarte?" required></textarea>
              </div>
              <div class="col-12">
                <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">Enviar Mensaje</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>

  <footer class="footer">
    <div class="container">
      <div class="row g-5 mb-5">
        <div class="col-lg-4">
          <a class="navbar-brand d-flex align-items-center gap-2 text-white mb-4" href="#">
            <img src="assets/img/deskser.png" width="32" height="32" alt="Logo">
            <span class="fw-bold fs-4">Desk Service</span>
          </a>
          <p class="small opacity-75">La plataforma líder en gestión de tickets y mesa de ayuda multicanal para empresas modernas.</p>
        </div>
        <div class="col-lg-3 col-md-4">
          <h5>Producto</h5>
          <a href="#caracteristicas" class="footer-link">Funciones</a>
          <a href="#planes" class="footer-link">Precios</a>
          <a href="docs.php" class="footer-link">Documentación</a>
          <a href="create_tenant.php" class="footer-link">Pruébalo Gratis</a>
          <a href="myaccount_login.php" class="footer-link fw-bold text-primary">Gestionar mi Instancia (Pagos)</a>
        </div>
        <div class="col-lg-3 col-md-4">
          <h5>Legal</h5>
          <a href="politicas.php" class="footer-link">Privacidad</a>
          <a href="politicas.php#terminos" class="footer-link">Términos</a>
          <a href="cookies.php" class="footer-link">Cookies</a>
        </div>
        <div class="col-lg-2 col-md-4 text-md-end">
          <h5>Síguenos</h5>
          <div class="d-flex gap-3 justify-content-md-end mt-2">
            <a href="#" class="text-white opacity-50 hover-opacity-100"><i class="fab fa-facebook-f"></i></a>
            <a href="#" class="text-white opacity-50 hover-opacity-100"><i class="fab fa-twitter"></i></a>
            <a href="#" class="text-white opacity-50 hover-opacity-100"><i class="fab fa-linkedin-in"></i></a>
          </div>
        </div>
      </div>
      <hr class="border-white opacity-10 my-5">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
        <div class="small">© <?php echo date('Y'); ?> Desk Service. Todos los derechos reservados.</div>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script>
    AOS.init({
      duration: 800,
      once: true,
      easing: 'ease-out-quad'
    });

    window.addEventListener('scroll', function() {
      if (window.scrollY > 50) {
        document.getElementById('mainNav').classList.add('scrolled');
      } else {
        document.getElementById('mainNav').classList.remove('scrolled');
      }
    });
  </script>
</body>
</html>