# Desk Service - Plataforma SaaS de Mesa de Servicios Inteligente

**Desk Service** es una solución SaaS multi-tenant desarrollada para optimizar la gestión de tickets, inventario y soporte técnico en PYMES. Integra automatización mediante Inteligencia Artificial (IA), procesamiento de lenguaje natural (PLN) y conexión multicanal (WhatsApp, Telegram, Email).

## 🌐 Demo en Vivo
Puedes probar el sistema funcionando en tiempo real en el siguiente enlace:
👉 **[https://deskserviced.alwaysdata.net/](https://deskserviced.alwaysdata.net/)**

## 🚀 Características Principales
- **Gestión de Tickets:** Seguimiento completo con SLA y trazabilidad.
- **Multicanalidad:** Webhooks integrados para WhatsApp, Telegram y correo electrónico.
- **Inteligencia Artificial:** Agente en Python (`ai_agent.py`) para clasificación y respuestas automáticas.
- **Multi-tenant:** Gestión de múltiples empresas (tenants) en una sola instancia.
- **Inventario IT:** Control de activos tecnológicos asociados a incidencias.
- **Reportes y Analítica:** Dashboards con KPIs de rendimiento.

## 🛠️ Tecnologías Utilizadas
- **Backend:** PHP 8.x (Arquitectura MVC personalizada).
- **Base de Datos:** MySQL / MariaDB (Relacional).
- **Módulo IA:** Python (Procesamiento de Lenguaje Natural).
- **Frontend:** HTML5, CSS3, JavaScript (Vanilla), PWA (Service Workers).
- **Librerías:** Composer (Gestión de dependencias PHP).

## ⚙️ Instalación y Configuración Local
1. Clona el repositorio:
   `git clone https://github.com/TU-USUARIO/TU-REPOSITORIO.git`
2. Importa la base de datos: Ejecuta el archivo `Base de Datos - Estructura.sql` en tu gestor MySQL (phpMyAdmin).
3. Configura las credenciales: Renombra el archivo `config/database.example.php` a `config/database.php` y edita tus credenciales locales (Host, User, Password, DB).
4. Instala las dependencias de PHP:
   `composer install`
5. Levanta tu servidor local (XAMPP/Laragon) y accede al proyecto.

## 👥 Autores
- Isacc Fidel Mestra Ochoa
- Juan Carlos Almanza Humanez
*Proyecto de Grado - Ingeniería de Sistemas - UNAD (2026)*
