<?php
/**
 * Copia este archivo como config.php y rellena los datos.
 * El resto de ajustes (mensajes, horario, WhatsApp, OpenAI...) se configuran desde
 * el cliente de Windows con un usuario Administrador.
 */
return array(
    // Base de datos MySQL / MariaDB (crea una base de datos vacía en tu hosting)
    'db_host'    => 'localhost',
    'db_name'    => 'bezares_chat',
    'db_user'    => 'bezares_chat',
    'db_pass'    => 'CAMBIA_ESTO',

    // URL pública del servidor de chat (sin barra final)
    'base_url'   => 'https://chat.bezares.com',

    // Webs autorizadas a mostrar el widget. Normalmente se gestionan en /admin → Configuración →
    // "Webs de confianza"; las que pongas aquí se suman a esa lista (útil si no tienes acceso al panel).
    'allowed_origins' => array(),

    // Zona horaria para el horario laboral y las estadísticas
    'timezone'   => 'Europe/Madrid',

    // Clave que se pide una sola vez para crear el primer administrador en /admin (larga y aleatoria)
    'setup_key'  => 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA',

    // Correo para recuperar contraseñas. Con smtp_host vacío se usa mail() de PHP.
    'mail_from'      => 'chat@bezares.com',
    'mail_from_name' => 'Bezares Chat',
    'smtp_host'      => '',            // p. ej. 'smtp.office365.com' o el SMTP de tu hosting
    'smtp_port'      => 587,
    'smtp_secure'    => 'tls',         // 'tls' (STARTTLS, puerto 587), 'ssl' (puerto 465) o ''
    'smtp_user'      => '',
    'smtp_pass'      => '',

    // Redirigir siempre a HTTPS (desactívalo solo para pruebas en local)
    'force_https' => true,

    // Si el servidor está detrás de un proxy/CDN (p. ej. Cloudflare), cabecera con la IP real del visitante,
    // por ejemplo 'HTTP_CF_CONNECTING_IP'. Déjalo vacío si no hay proxy (si no, se podría falsificar la IP).
    'client_ip_header' => '',
);
