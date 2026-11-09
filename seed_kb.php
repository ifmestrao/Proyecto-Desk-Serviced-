<?php

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/config/session_setup.php';
    require_once __DIR__ . '/includes/csrf.php';
}
require_once __DIR__ . '/config/db.php';

if ($isCli) {
    $tenant_id = isset($argv[1]) ? (int)$argv[1] : 0;
    if ($tenant_id <= 0) { fwrite(STDERR, "Uso: php seed_kb.php ID_DEL_TENANT\n"); exit(1); }
    $st = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND role = 'admin' ORDER BY id LIMIT 1");
    $st->execute([$tenant_id]);
    $creator_id = (int)$st->fetchColumn();
    if (!$creator_id) { fwrite(STDERR, "El tenant $tenant_id no tiene un usuario admin.\n"); exit(1); }
} else {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['tenant_id'])) {
        http_response_code(403);
        exit('Solo un administrador con sesión iniciada puede cargar la base de conocimientos.');
    }
    $tenant_id = (int)$_SESSION['tenant_id'];   // siempre SU propia empresa
    $creator_id = (int)$_SESSION['user_id'];
}

$ARTICLES = json_decode(<<<'JSON'
[
 {
  "cat": "red",
  "title": "No tengo internet / sin conexión a la red",
  "keywords": "sin internet, no hay internet, no tengo red, no navega, no carga páginas, se cayó el internet, sin conexión, no hay red, internet caído",
  "content": "1. Revisa si el problema es solo tuyo: pregunta a un compañero si él sí tiene internet.\n2. Si usas cable: verifica que esté bien conectado en el computador y en la toma de pared (debe encender la luz del puerto).\n3. Si usas Wi-Fi: apaga y vuelve a encender el Wi-Fi del computador y confirma que estés conectado a la red de la empresa.\n4. Reinicia el computador.\n5. Si varios compañeros tampoco tienen internet, el fallo es de la red general: no reinicies nada y crea un ticket indicando cuántas personas están afectadas.\nSi nada funciona, crea un ticket e indica tu área y el piso o ubicación."
 },
 {
  "cat": "red",
  "title": "Wi-Fi conectado pero sin internet",
  "keywords": "wifi sin internet, wifi conectado sin acceso, conectado pero no navega, sin acceso a internet, triángulo amarillo wifi, wi-fi limitado",
  "content": "1. Desconéctate del Wi-Fi y vuelve a conectarte (olvidar red y reconectar).\n2. Abre una página distinta (por ejemplo una de noticias) para descartar que sea solo un sitio.\n3. Reinicia el computador.\n4. Prueba con otra red Wi-Fi o con el cable, si tienes acceso.\n5. Si el problema ocurre en varios equipos de la misma zona, avisa por ticket: puede ser el punto de acceso o el router."
 },
 {
  "cat": "red",
  "title": "Internet lento",
  "keywords": "internet lento, lentitud en la red, navega lento, se demora en cargar, red lenta, páginas tardan mucho, descargas lentas, conexión lenta",
  "content": "1. Cierra las pestañas y programas que no estés usando, sobre todo descargas, videos y actualizaciones.\n2. Si usas Wi-Fi, acércate al punto de acceso o conéctate por cable.\n3. Haz la prueba con otro sitio y a otra hora: así sabemos si es constante o intermitente.\n4. Reinicia el computador.\nSi sigue lento, crea un ticket e indica desde cuándo ocurre, en qué sitios o programas y si afecta a más personas."
 },
 {
  "cat": "red",
  "title": "No puedo abrir una página web específica",
  "keywords": "no abre página, sitio bloqueado, no carga una web, error al abrir sitio, página no disponible, acceso denegado a página web, bloqueo de sitios",
  "content": "1. Verifica que la dirección esté bien escrita.\n2. Prueba con otro navegador (Chrome, Edge, Firefox).\n3. Borra la caché y las cookies del navegador, y vuelve a intentar.\n4. Si otras páginas sí abren, es posible que el sitio esté bloqueado por la política de la empresa o esté caído.\nSi lo necesitas para tu trabajo, crea un ticket con la dirección exacta (URL) y para qué la necesitas."
 },
 {
  "cat": "red",
  "title": "No puedo conectarme a la VPN",
  "keywords": "vpn no conecta, no puedo conectarme a la vpn, vpn falla, acceso remoto, teletrabajo sin conexión, error vpn, trabajo desde casa",
  "content": "1. Comprueba que tu internet de casa funcione abriendo cualquier página.\n2. Cierra la VPN por completo y vuelve a abrirla.\n3. Verifica que tu usuario y contraseña sean correctos (si cambiaste la contraseña hace poco, usa la nueva).\n4. Reinicia el computador y vuelve a intentar.\n5. Prueba desconectar otras VPN o programas de seguridad que estén abiertos.\nSi aparece un mensaje de error, tómale una foto o captura y adjúntala al ticket."
 },
 {
  "cat": "red",
  "title": "No veo las carpetas compartidas o la unidad de red",
  "keywords": "carpeta compartida, no veo unidad de red, disco de red, no abre carpeta compartida, ruta de red, servidor de archivos, unidad z",
  "content": "1. Verifica que estés conectado a la red de la empresa (o a la VPN si estás fuera).\n2. Cierra sesión y vuelve a iniciar.\n3. Reinicia el computador.\n4. Si ves un mensaje de acceso denegado, no es un fallo de red sino de permisos: solicita el acceso por ticket indicando la ruta de la carpeta y quién debe autorizarlo."
 },
 {
  "cat": "cuentas",
  "title": "Olvidé mi contraseña",
  "keywords": "olvidé mi contraseña, no recuerdo la clave, restablecer contraseña, recuperar contraseña, olvidé mi clave, reset password, cambiar clave olvidada",
  "content": "1. En la pantalla de inicio de sesión pulsa \"¿Olvidaste tu contraseña?\".\n2. Escribe tu correo: recibirás un código en tu bandeja (revisa también spam o correo no deseado).\n3. Ingresa el código y crea una contraseña nueva: mínimo 8 caracteres, con mayúscula, minúscula, 2 números y 1 carácter especial (@$!%*?&).\nSi no recibes el código, espera unos minutos y vuelve a pedirlo. Si sigue sin llegar, crea un ticket."
 },
 {
  "cat": "cuentas",
  "title": "Mi cuenta está bloqueada",
  "keywords": "cuenta bloqueada, usuario bloqueado, demasiados intentos, no puedo iniciar sesión, acceso bloqueado, me bloquearon la cuenta",
  "content": "1. Espera 15 minutos sin intentar de nuevo: el bloqueo por intentos fallidos se libera solo.\n2. Después, entra escribiendo con calma tu correo y contraseña (revisa que no esté activado el bloqueo de mayúsculas).\n3. Si no recuerdas la contraseña, usa \"¿Olvidaste tu contraseña?\".\nSi la cuenta sigue bloqueada, crea un ticket indicando tu correo y la hora aproximada del bloqueo. Nunca compartas tu contraseña con nadie, ni siquiera con soporte."
 },
 {
  "cat": "cuentas",
  "title": "Cómo cambiar mi contraseña",
  "keywords": "cambiar contraseña, cambiar clave, actualizar contraseña, nueva contraseña, modificar contraseña",
  "content": "1. Inicia sesión y entra a tu perfil.\n2. Busca la opción de cambiar contraseña e ingresa la nueva.\n3. Usa una contraseña de al menos 8 caracteres con mayúscula, minúscula, 2 números y un carácter especial (@$!%*?&). No repitas contraseñas de otros servicios.\nSi estás fuera de tu cuenta, usa \"¿Olvidaste tu contraseña?\" en la pantalla de acceso."
 },
 {
  "cat": "cuentas",
  "title": "No me llega el código de verificación",
  "keywords": "no llega el código, código de verificación, no recibo el código, verificación en dos pasos, código por correo, 2fa, código no llega",
  "content": "1. Revisa la carpeta de spam o correo no deseado.\n2. Confirma que el correo con el que entras sea el correcto.\n3. Espera 2 o 3 minutos y pulsa reenviar código (hay un límite de reenvíos por seguridad).\n4. Usa siempre el último código recibido: los anteriores dejan de servir.\nSi pasados 10 minutos no llega, crea un ticket con tu correo."
 },
 {
  "cat": "cuentas",
  "title": "Recibí un correo sospechoso (phishing)",
  "keywords": "correo sospechoso, phishing, estafa, me pidieron mi contraseña, correo falso, suplantación, enlace sospechoso, fraude por correo, spam peligroso",
  "content": "1. NO hagas clic en enlaces ni abras archivos adjuntos.\n2. NO respondas ni entregues contraseñas, códigos o datos personales.\n3. Si ya hiciste clic o escribiste tu contraseña: cámbiala de inmediato y avisa cuanto antes por ticket con prioridad alta.\n4. Reenvía el correo a soporte como adjunto o toma una captura, y luego elimínalo.\nSeñales típicas: urgencia exagerada, remitente raro, faltas de ortografía y enlaces que no coinciden con la empresa."
 },
 {
  "cat": "cuentas",
  "title": "Creo que mi computador tiene un virus",
  "keywords": "virus, malware, computador infectado, ransomware, ventanas emergentes, publicidad rara, archivos cifrados, antivirus alerta, hackeo, me hackearon",
  "content": "1. Desconecta el computador de internet (cable de red o Wi-Fi) para frenar cualquier daño.\n2. No apagues ni formatees: puede borrar pistas que necesita el técnico.\n3. No sigas trabajando con información importante en ese equipo.\n4. Crea de inmediato un ticket de prioridad alta o llama al soporte, indicando qué viste (mensajes, archivos que no abren, ventanas raras).\nSi aparece un mensaje que pide dinero para recuperar archivos, no pagues ni respondas."
 },
 {
  "cat": "correo",
  "title": "No puedo enviar o recibir correos",
  "keywords": "no puedo enviar correos, no recibo correos, correo no llega, correo no sale, problema con el correo, bandeja no actualiza, outlook no envía",
  "content": "1. Verifica tu conexión a internet.\n2. Revisa la bandeja de salida: si el mensaje está atascado, ábrelo y envíalo de nuevo.\n3. Comprueba que el adjunto no sea demasiado pesado (si supera unos 20 MB, usa un enlace de nube).\n4. Cierra y vuelve a abrir el programa de correo; si usas correo web, prueba otro navegador.\n5. Revisa que tu buzón no esté lleno.\nSi recibes un mensaje de error, adjunta una captura al ticket."
 },
 {
  "cat": "correo",
  "title": "Outlook no abre o se queda cargando",
  "keywords": "outlook no abre, outlook se congela, outlook cargando, outlook no responde, outlook lento, se cierra outlook, outlook pantalla en blanco",
  "content": "1. Cierra Outlook por completo (también desde el Administrador de tareas, si no responde) y vuelve a abrirlo.\n2. Reinicia el computador.\n3. Si te pide contraseña una y otra vez, cierra sesión de la cuenta y vuelve a entrar.\n4. Prueba abrir tu correo por la versión web para confirmar que la cuenta funciona.\nSi la versión web sí funciona y Outlook no, crea un ticket indicando la versión de Office y el mensaje que ves."
 },
 {
  "cat": "correo",
  "title": "Mi buzón de correo está lleno",
  "keywords": "buzón lleno, correo lleno, sin espacio en el correo, cuota de correo, no puedo recibir correos buzón, mailbox full",
  "content": "1. Elimina correos grandes con adjuntos (ordena la bandeja por tamaño).\n2. Vacía la carpeta de elementos eliminados y la de correo no deseado.\n3. Guarda los adjuntos importantes en tu disco o en la nube y borra el correo.\n4. Si tu trabajo requiere más espacio, solicita por ticket una ampliación de la cuota."
 },
 {
  "cat": "correo",
  "title": "Correo rebotado o no entregado",
  "keywords": "correo rebotado, no se entregó el correo, mensaje devuelto, undeliverable, correo regresó, error de entrega, dirección no existe",
  "content": "1. Lee el motivo en el mensaje de rebote: casi siempre indica si la dirección no existe, el buzón del destinatario está lleno o el mensaje es demasiado grande.\n2. Confirma que escribiste bien la dirección del destinatario.\n3. Si el motivo es el tamaño, comparte el archivo mediante un enlace.\n4. Si el rebote menciona bloqueo o spam, crea un ticket y adjunta el mensaje de rebote completo."
 },
 {
  "cat": "impresora",
  "title": "La impresora no imprime",
  "keywords": "impresora no imprime, no sale la impresión, impresión pendiente, cola de impresión, impresora sin respuesta, no imprime nada, impresora fuera de línea, imprimir no funciona",
  "content": "1. Verifica que la impresora esté encendida, con papel y sin luces de error.\n2. Comprueba que esté conectada (cable o red) y que la hayas elegido como impresora en la ventana de imprimir.\n3. Abre la cola de impresión y cancela los trabajos atascados; luego imprime de nuevo.\n4. Apaga la impresora 30 segundos, enciéndela y reintenta.\n5. Si tu computador dice \"sin conexión\", reinícialo.\nSi es una impresora compartida y nadie puede imprimir, crea un ticket con el nombre o ubicación de la impresora."
 },
 {
  "cat": "impresora",
  "title": "Atasco de papel en la impresora",
  "keywords": "atasco de papel, papel atascado, impresora atascada, se traba el papel, papel arrugado, hoja atascada",
  "content": "1. Apaga la impresora y desconéctala si es posible.\n2. Abre las tapas y retira el papel atascado con cuidado, tirando en la dirección del recorrido del papel, sin romperlo.\n3. Revisa que no queden pedazos adentro.\n4. Verifica que el papel esté seco, sin arrugas y bien alineado en la bandeja, sin sobrepasar la marca de capacidad.\nSi el atasco se repite, crea un ticket: puede necesitar mantenimiento."
 },
 {
  "cat": "impresora",
  "title": "La impresión sale con rayas, manchas o muy clara",
  "keywords": "impresión con rayas, impresión manchada, impresión clara, tóner bajo, tinta agotada, mala calidad de impresión, sale borroso, líneas en la hoja",
  "content": "1. Revisa el nivel de tóner o tinta en el panel de la impresora.\n2. Usa la función de limpieza de cabezales o de impresión de prueba, si tu impresora la tiene.\n3. Verifica que el papel sea el adecuado y esté seco.\n4. Si el tóner se agotó, solicita el cambio por ticket indicando el modelo de la impresora."
 },
 {
  "cat": "impresora",
  "title": "No encuentro la impresora o necesito agregarla",
  "keywords": "agregar impresora, instalar impresora, no aparece la impresora, impresora nueva, conectar impresora, driver de impresora",
  "content": "1. Verifica que estés conectado a la red de la empresa.\n2. Ve a Configuración > Impresoras y escáneres > Agregar dispositivo y espera a que aparezca.\n3. Si no aparece, crea un ticket con el nombre de la impresora (suele estar en una etiqueta) y tu ubicación; soporte la instalará en tu equipo."
 },
 {
  "cat": "hardware",
  "title": "El computador no enciende",
  "keywords": "no enciende, computador no prende, equipo no arranca, no da imagen, está muerto, no hace nada al encender, no inicia",
  "content": "1. Verifica que el cable de corriente esté bien conectado en el equipo y en la toma, y que la regleta esté encendida.\n2. En un portátil: conéctalo al cargador y espera 10 minutos antes de intentar encender.\n3. Desconecta dispositivos USB y otros accesorios, y prueba de nuevo.\n4. Mantén pulsado el botón de encendido 15 segundos, suéltalo y vuelve a encender.\nSi sigue sin responder, crea un ticket indicando marca, modelo y qué luces ves encendidas."
 },
 {
  "cat": "hardware",
  "title": "El computador está muy lento",
  "keywords": "computador lento, equipo lento, pc lenta, se demora en abrir, se congela, se traba, va muy lento, se cuelga, rendimiento bajo",
  "content": "1. Guarda tu trabajo y reinicia el computador (es la solución más frecuente si lleva días encendido).\n2. Cierra programas y pestañas que no uses.\n3. Revisa que tengas espacio libre en el disco (menos de 10% libre vuelve lento el equipo).\n4. Instala las actualizaciones pendientes.\n5. Si lo notas lento incluso recién reiniciado, crea un ticket indicando desde cuándo y con qué programas pasa."
 },
 {
  "cat": "hardware",
  "title": "Pantalla negra o sin imagen",
  "keywords": "pantalla negra, sin imagen, monitor no enciende, no hay señal, pantalla en blanco, monitor sin señal, no se ve nada, pantalla apagada",
  "content": "1. Comprueba que el monitor esté encendido y que su luz no esté en naranja o parpadeando.\n2. Revisa que el cable de video (HDMI, DisplayPort o VGA) esté firme en ambos extremos.\n3. Mueve el mouse o pulsa una tecla por si el equipo está en reposo.\n4. Si tienes varias entradas en el monitor, selecciona la correcta con el botón de fuente (Input/Source).\n5. En un portátil, prueba Windows + P para cambiar el modo de proyección."
 },
 {
  "cat": "hardware",
  "title": "Teclado o mouse no responden",
  "keywords": "teclado no funciona, mouse no responde, ratón no mueve, teclas no escriben, teclado inalámbrico, mouse inalámbrico, cursor congelado",
  "content": "1. Desconecta y vuelve a conectar el dispositivo en otro puerto USB.\n2. Si es inalámbrico, cambia las pilas y verifica el receptor.\n3. Reinicia el computador.\n4. Prueba el dispositivo en otro equipo para saber si el fallo es del dispositivo.\nSi el dispositivo está dañado, crea un ticket para solicitar su reemplazo."
 },
 {
  "cat": "hardware",
  "title": "Sin sonido, micrófono o cámara que no funcionan",
  "keywords": "sin sonido, no hay audio, micrófono no funciona, cámara no funciona, no se escucha, no me escuchan, webcam no detecta, parlantes no suenan, audífonos no funcionan",
  "content": "1. Revisa que el volumen no esté en silencio y que el dispositivo correcto (audífonos, parlantes o micrófono) esté seleccionado como predeterminado.\n2. Verifica que el programa (Teams, Zoom, navegador) tenga permiso para usar el micrófono y la cámara.\n3. Desconecta y vuelve a conectar los audífonos o la cámara.\n4. Cierra otros programas que puedan estar usando la cámara o el micrófono.\n5. Reinicia el computador."
 },
 {
  "cat": "hardware",
  "title": "Pantallazo azul o reinicios inesperados",
  "keywords": "pantallazo azul, pantalla azul, se reinicia solo, reinicio inesperado, blue screen, se apaga solo, error crítico windows",
  "content": "1. Anota el código de error que aparece en la pantalla azul (por ejemplo, el texto en mayúsculas) o toma una foto.\n2. Reinicia el computador.\n3. Si ocurre otra vez, no sigas trabajando con información importante sin guardarla.\n4. Crea un ticket con el código de error, qué estabas haciendo y si pasa con frecuencia."
 },
 {
  "cat": "hardware",
  "title": "El equipo se calienta mucho o hace ruido",
  "keywords": "se calienta, sobrecalentamiento, ventilador ruidoso, ruido en el computador, equipo caliente, ventilador a toda velocidad, temperatura alta",
  "content": "1. Verifica que las rejillas de ventilación no estén tapadas y que el equipo esté sobre una superficie dura, no sobre telas.\n2. Cierra programas pesados que estén consumiendo mucho.\n3. Si el ruido es fuerte o constante, o el equipo se apaga por temperatura, crea un ticket: puede requerir limpieza o mantenimiento."
 },
 {
  "cat": "hardware",
  "title": "La batería del portátil dura poco o no carga",
  "keywords": "batería no carga, batería dura poco, portátil no carga, cargador no funciona, batería se agota, laptop no carga, batería hinchada",
  "content": "1. Prueba con otra toma de corriente y revisa que el cable del cargador no esté dañado ni doblado.\n2. Si ves el cable roto, el conector caliente en exceso o la base del portátil abultada, deja de usarlo y avisa de inmediato por ticket.\n3. Baja el brillo y cierra programas para ahorrar energía.\n4. Si la duración cayó mucho con el tiempo, solicita por ticket la revisión de la batería."
 },
 {
  "cat": "software",
  "title": "Una aplicación se cierra sola o no abre",
  "keywords": "aplicación se cierra sola, programa no abre, no inicia el programa, se cierra el programa, la app falla, error al abrir, programa se congela",
  "content": "1. Cierra el programa por completo (Administrador de tareas si no responde) y ábrelo otra vez.\n2. Reinicia el computador.\n3. Verifica que no haya actualizaciones pendientes del programa o de Windows.\n4. Anota el mensaje de error exacto o toma una captura.\nSi el problema continúa, crea un ticket indicando el nombre del programa, su versión y el error."
 },
 {
  "cat": "software",
  "title": "Necesito instalar un programa",
  "keywords": "instalar programa, instalar software, necesito una aplicación, licencia de software, instalar office, instalar aplicación, descargar programa",
  "content": "Por seguridad, no instales programas por tu cuenta. Crea un ticket de solicitud indicando:\n• Nombre y versión del programa\n• Para qué lo necesitas y quién lo autoriza (tu jefe o área)\n• En qué equipo debe instalarse\nSoporte validará la licencia y lo instalará."
 },
 {
  "cat": "software",
  "title": "Excel o Word no responde / perdí un documento sin guardar",
  "keywords": "excel no responde, word no responde, perdí el documento, no guardé, recuperar documento, archivo sin guardar, se cerró excel, documento corrupto",
  "content": "1. Espera unos minutos: a veces el programa está procesando y se recupera solo.\n2. Si se cerró, vuelve a abrir el programa: suele mostrar el panel \"Recuperación de documentos\" a la izquierda; elige la versión más reciente.\n3. En Archivo > Información > Administrar documento > \"Recuperar libros/documentos sin guardar\".\n4. Para el futuro, activa el autoguardado o guarda con Ctrl + S con frecuencia.\nSi el archivo está dañado, no lo sobrescribas: crea un ticket."
 },
 {
  "cat": "software",
  "title": "Problemas en videollamadas (Teams, Zoom, Meet)",
  "keywords": "teams no funciona, zoom falla, meet sin audio, videollamada se corta, reunión no carga, no me escuchan en la reunión, cámara en la reunión, teams lento",
  "content": "1. Verifica tu conexión a internet y cierra descargas o transmisiones.\n2. Antes de entrar, revisa que el micrófono, la cámara y los parlantes correctos estén seleccionados en la configuración del programa.\n3. Cierra y vuelve a abrir la aplicación; si usas el navegador, prueba la aplicación de escritorio o al revés.\n4. Desactiva tu cámara si el audio se entrecorta, para liberar ancho de banda.\n5. Reinicia el computador si nada de lo anterior funciona."
 },
 {
  "cat": "software",
  "title": "Limpiar la caché y cookies del navegador",
  "keywords": "borrar caché, limpiar cookies, navegador lento, sitio no carga bien, caché del navegador, historial navegador, chrome lento, edge lento",
  "content": "1. Abre el menú del navegador > Historial > Borrar datos de navegación.\n2. Elige \"Cookies y otros datos de sitios\" e \"Imágenes y archivos en caché\" (si quieres, mantén tus contraseñas guardadas desmarcadas).\n3. Pulsa Borrar datos, cierra el navegador y ábrelo de nuevo.\nEsto soluciona muchos errores de páginas que no cargan o no inician sesión."
 },
 {
  "cat": "software",
  "title": "Actualizaciones de Windows pendientes",
  "keywords": "actualización windows, actualizaciones pendientes, windows update, reinicio pendiente, actualizar el equipo, parche de seguridad",
  "content": "1. Guarda tu trabajo.\n2. Ve a Configuración > Windows Update y pulsa \"Buscar actualizaciones\".\n3. Instala las pendientes y reinicia cuando te lo pida.\nMantener el equipo actualizado mejora la seguridad y evita muchos fallos. Si una actualización falla repetidamente, crea un ticket con el código de error."
 },
 {
  "cat": "telefonia",
  "title": "El teléfono IP no funciona (sin tono)",
  "keywords": "teléfono ip no funciona, teléfono sin tono, no hay tono, teléfono de oficina, teléfono no llama, no puedo llamar, extensión no funciona, sin línea telefónica",
  "content": "1. Apaga y vuelve a encender el teléfono (desconecta el cable de red o corriente unos segundos).\n2. Revisa que el cable de red esté bien conectado al teléfono y a la toma de pared.\n3. Si el teléfono comparte conexión con tu computador, verifica que el computador sí tenga red.\n4. Espera 2 minutos a que el teléfono se registre y muestre su extensión.\nSi la pantalla sigue mostrando \"sin registro\" o error, crea un ticket con tu extensión y ubicación."
 },
 {
  "cat": "telefonia",
  "title": "El audio de las llamadas se corta o se escucha mal",
  "keywords": "audio entrecortado, llamadas se cortan, se escucha mal, eco en llamadas, voz robótica, llamada con ruido, no se oye, calidad de llamada",
  "content": "1. Cierra descargas o videos que consuman la red mientras llamas.\n2. Si usas diadema, desconéctala y conéctala de nuevo, o prueba con el auricular.\n3. Reinicia el teléfono.\n4. Si pasa en varias llamadas y con varias personas, crea un ticket indicando horas aproximadas y a qué números llamaste."
 },
 {
  "cat": "permisos",
  "title": "No tengo acceso a una carpeta, sistema o aplicación",
  "keywords": "sin acceso, acceso denegado, no tengo permisos, necesito permiso, no puedo entrar a la carpeta, solicitar acceso, permiso de carpeta, privilegios",
  "content": "Un mensaje de \"acceso denegado\" significa que falta autorización, no que algo esté dañado. Crea un ticket de solicitud con:\n• Qué recurso necesitas (nombre de la carpeta, sistema o aplicación) y su ruta o enlace\n• Qué necesitas hacer (solo ver, editar o administrar)\n• Quién autoriza (jefe de área)\nSoporte aplicará el permiso una vez cuente con la aprobación."
 },
 {
  "cat": "solicitud",
  "title": "Solicitar un equipo, periférico o software nuevo",
  "keywords": "solicitar equipo, necesito un computador, equipo nuevo, pedir monitor, solicitar mouse, solicitud de compra, nuevo equipo, cambio de equipo",
  "content": "Crea un ticket de solicitud indicando:\n• Qué necesitas (equipo, monitor, teclado, programa, etc.)\n• Para qué lo necesitas y quién lo aprueba\n• Para quién es y en qué área/ubicación\nSoporte validará disponibilidad e inventario y te informará el estado en el mismo ticket."
 },
 {
  "cat": "solicitud",
  "title": "Crear o dar de baja un usuario (ingreso o salida de personal)",
  "keywords": "crear usuario, nuevo empleado, alta de usuario, baja de usuario, ingreso de personal, retiro de personal, cuenta nueva, desactivar usuario",
  "content": "Solicítalo por ticket con anticipación (idealmente 3 días hábiles antes):\n• Nombre completo, cargo, área y jefe directo\n• Fecha de ingreso o de salida\n• Sistemas y carpetas a los que necesita acceso (o de los que se debe retirar)\n• Equipo requerido\nLas bajas deben solicitarse el mismo día de la salida para proteger la información."
 },
 {
  "cat": "solicitud",
  "title": "Solicitar mantenimiento preventivo o limpieza de un equipo",
  "keywords": "mantenimiento, mantenimiento preventivo, limpieza de equipo, revisión del equipo, calibración, mantenimiento computador",
  "content": "Crea un ticket de mantenimiento indicando el equipo (marca, modelo o código de inventario), su ubicación y la razón (lentitud, ruido, calentamiento o revisión periódica). Soporte coordinará la fecha para no interrumpir tu trabajo."
 },
 {
  "cat": "respaldo",
  "title": "Borré un archivo por error (cómo recuperarlo)",
  "keywords": "borré un archivo, recuperar archivo eliminado, archivo borrado, papelera, restaurar archivo, recuperar documento borrado, eliminé por error",
  "content": "1. Abre la Papelera de reciclaje, busca el archivo, clic derecho > Restaurar.\n2. Si estaba en una carpeta de red o en la nube, revisa la papelera de ese servicio (suele guardar los archivos unos 30 días).\n3. Si no aparece, no sigas guardando archivos en esa carpeta y crea un ticket indicando el nombre del archivo, la ruta y la fecha aproximada: soporte puede intentar restaurarlo desde una copia de seguridad."
 },
 {
  "cat": "respaldo",
  "title": "Cómo guardar y respaldar mis archivos importantes",
  "keywords": "respaldo, copia de seguridad, backup, guardar archivos, dónde guardo mis archivos, proteger mi información, perder información",
  "content": "• Guarda tus archivos de trabajo en las carpetas compartidas o en la nube de la empresa, no solo en el escritorio del equipo.\n• Evita depender de memorias USB como único lugar de guardado.\n• Activa el autoguardado en tus programas de oficina.\nSi tu información está solo en tu equipo, solicita por ticket que se configure un respaldo."
 },
 {
  "cat": "crm",
  "title": "No puedo iniciar sesión en el CRM o sistema de la empresa",
  "keywords": "crm no abre, no puedo entrar al sistema, error al iniciar sesión en el sistema, sistema de facturación, erp no carga, credenciales del sistema",
  "content": "1. Confirma que escribes tu usuario y contraseña correctamente (cuidado con mayúsculas).\n2. Prueba en otro navegador o en una ventana de incógnito.\n3. Borra la caché y las cookies del navegador.\n4. Si el sistema dice que tu usuario está bloqueado o no existe, crea un ticket con tu usuario y el nombre del sistema."
 },
 {
  "cat": "crm",
  "title": "El sistema o CRM va lento o muestra errores",
  "keywords": "crm lento, sistema lento, error en el sistema, sistema se cae, reporte no carga, error 500, sistema no responde, módulo no abre",
  "content": "1. Actualiza la página (Ctrl + F5) y vuelve a intentar.\n2. Prueba en otro navegador.\n3. Verifica que tu internet funcione con otros sitios.\n4. Anota el mensaje de error, el módulo en que estabas y la hora.\nSi afecta a varios compañeros o a un proceso crítico, crea un ticket con prioridad alta y esos datos."
 },
 {
  "cat": "general",
  "title": "Cómo crear un ticket y qué información incluir",
  "keywords": "crear ticket, abrir ticket, cómo reporto un problema, qué datos incluir, reportar incidencia, solicitar soporte, hablar con un técnico",
  "content": "Un ticket bien descrito se resuelve más rápido. Incluye:\n• Qué ocurre y qué esperabas que pasara\n• Desde cuándo y con qué frecuencia\n• Mensaje de error exacto o captura de pantalla\n• Equipo o programa afectado y tu ubicación\n• Si te impide trabajar o a cuántas personas afecta\nPuedo ayudarte a crearlo aquí mismo: solo dime que quieres abrir un ticket."
 },
 {
  "cat": "general",
  "title": "Cómo consultar el estado de mi ticket",
  "keywords": "estado de mi ticket, seguimiento de ticket, mi ticket, cuándo me responden, ver mis tickets, consultar solicitud, ticket pendiente",
  "content": "Inicia sesión y entra a \"Mis tickets\" (o a tu panel): allí verás el estado (abierto, en espera o cerrado), el técnico asignado y los mensajes. También recibes avisos por correo cuando hay novedades. Para agregar información, responde dentro del mismo ticket en lugar de crear uno nuevo."
 }
]
JSON
, true);

function seed_norm($s) {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u']);
}

// Categorías del tenant
$cs = $pdo->prepare("SELECT id, name FROM categories WHERE tenant_id = ?");
$cs->execute([$tenant_id]);
$categories = $cs->fetchAll(PDO::FETCH_ASSOC);

$HINTS = [
    'red' => ['red', 'conectividad', 'internet'], 'cuentas' => ['seguridad', 'cuenta', 'acceso'],
    'correo' => ['correo', 'email'], 'impresora' => ['impresora', 'impresion'],
    'hardware' => ['hardware', 'equipo'], 'software' => ['software', 'aplicacion'],
    'telefonia' => ['telefon', 'voip'], 'permisos' => ['permiso', 'acceso'],
    'solicitud' => ['solicitud', 'asignacion'], 'respaldo' => ['software', 'seguridad'],
    'crm' => ['crm', 'sistema'], 'general' => ['general', 'otro', 'solicitud'],
];
function seed_category_id($hint, $categories, $HINTS) {
    foreach (($HINTS[$hint] ?? [$hint]) as $h) {
        foreach ($categories as $c) {
            if (strpos(seed_norm($c['name']), $h) !== false) return (int)$c['id'];
        }
    }
    return null;
}

$run = $isCli || ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check());
$inserted = 0; $skipped = 0;

if ($run) {
    $exists = $pdo->prepare("SELECT 1 FROM knowledge_base WHERE tenant_id = ? AND LOWER(title) = LOWER(?) LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO knowledge_base (tenant_id, category_id, title, content, keywords, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($ARTICLES as $a) {
        $exists->execute([$tenant_id, $a['title']]);
        if ($exists->fetchColumn()) { $skipped++; continue; }
        $ins->execute([$tenant_id, seed_category_id($a['cat'], $categories, $HINTS), $a['title'], $a['content'], $a['keywords'], $creator_id]);
        $inserted++;
    }
}

if ($isCli) {
    echo "Tenant $tenant_id: $inserted artículos agregados, $skipped ya existían.\n";
    exit(0);
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Cargar base de conocimientos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-5" style="max-width:720px">
<h3 class="mb-3">Cargar base de conocimientos inicial</h3>
<?php if ($run): ?>
  <div class="alert alert-success"><b><?= (int)$inserted ?></b> artículos agregados a tu empresa. <b><?= (int)$skipped ?></b> ya existían y no se tocaron.</div>
  <a class="btn btn-primary" href="knowledge_base.php">Ver base de conocimientos</a>
<?php else: ?>
  <p>Se agregarán hasta <b><?= count($ARTICLES) ?></b> artículos de soporte (red, cuentas, correo, impresoras, hardware, software, telefonía IP, permisos, solicitudes, respaldos, CRM) <b>solo a tu empresa</b>. Los que ya existan con el mismo título no se duplican.</p>
  <p class="text-muted">Después, revísalos y ajústalos con tus nombres de sistemas, rutas y políticas reales: mientras más específicos, mejores respuestas dará el asistente.</p>
  <form method="post"><?= csrf_input() ?><button class="btn btn-primary">Cargar artículos</button></form>
<?php endif; ?>
</div></body></html>
