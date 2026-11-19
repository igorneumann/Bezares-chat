# Bezares Chat: servidor

Sistema de chat propio para sustituir a Crisp en bezares.com. Este repositorio contiene el **servidor** que se instala en `chat.bezares.com`.

| Pieza | Repositorio | Qué hace |
|---|---|---|
| Servidor PHP | [Bezares-chat](https://github.com/igorneumann/Bezares-chat) | Base de datos, API, WhatsApp/Facebook, traducción, horario, recordatorios, limpieza, **panel de administración y estadísticas** |
| Cliente Windows | [BZChat-client](https://github.com/igorneumann/BZChat-client) | Programa portable (un `.exe` de 65 KB) para contestar chats, con timbre de teléfono |
| Plugin WordPress | [BZChat-plugin](https://github.com/igorneumann/BZChat-plugin) | Solo inserta el icono flotante en la web. No usa la base de datos de WordPress |

```
 Visitante web ──► widget.js ─┐
 WhatsApp ───► webhook Meta ──┼──► chat.bezares.com (PHP + MySQL) ◄──► Cliente Windows (agentes)
 Messenger ──► webhook Meta ──┘            │                                  
                                           ├──► OpenAI (traducción)
                                           └──► /admin (configuración, usuarios, estadísticas)
```

## Funciones

- Chats desde la **web, WhatsApp y Facebook Messenger**, contestados desde un único programa.
- Usuarios con roles: **Administrador**, **Recepcionista** (recibe todos los chats y transfiere) y **Comercial** (solo ve los que le transfieren).
- Al empezar, el bot pide **nombre y correo** (mensajes personalizables) mientras **suena el aviso** en recepción.
- **Horario laboral** y festivos, con respuesta automática fuera de horario.
- **Traducción automática con ChatGPT**: los mensajes que no estén en español ni inglés se traducen para el agente, y las respuestas se envían en el idioma del cliente.
- Recepción de **imágenes, audio y vídeo** (y documentos).
- **Recordatorio** automático si nadie contesta en X minutos y **seguimiento** si el cliente no responde en X horas, para mantener abierta la ventana de 24 h de WhatsApp.
- **Limpieza automática** de chats inactivos tras X meses, adjuntos incluidos.
- **Estadísticas** para el administrador: chats recibidos y contestados, porcentaje contestado en menos de X minutos, tiempo de respuesta medio y mediano, en tiempo real y en horario laboral, por agente, por canal y por día.

## Requisitos

- Hosting con **PHP 7.4 o superior** (extensiones `pdo_mysql`, `curl`, `mbstring`, `json`) y **MySQL 5.7+ / MariaDB 10.3+**.
- Apache con `mod_rewrite`. Para Nginx, ver más abajo.
- Subdominio `chat.bezares.com` con **certificado SSL** (HTTPS es obligatorio).

## Instalación

1. Crea el subdominio `chat.bezares.com` y apunta su carpeta raíz a **`public/`** de este repositorio.
   Si tu hosting no lo permite, sube todo a la raíz del subdominio: el `.htaccess` incluido redirige a `public/` y bloquea el resto.
2. Crea una base de datos MySQL vacía y un usuario con permisos sobre ella.
3. Copia `config.sample.php` como **`config.php`** y rellena los datos de la base de datos, `base_url`, `allowed_origins` y una `setup_key` larga y aleatoria.
4. Abre `https://chat.bezares.com/admin/`. Se crean las tablas y te pide la `setup_key` para crear el **primer administrador**.
5. En **Configuración**, revisa mensajes, horario, OpenAI y WhatsApp/Facebook. En **Usuarios**, crea recepcionistas y comerciales.
6. Programa el cron cada minuto (recomendado, para recordatorios y limpieza):
   ```
   * * * * * php /ruta/a/Bezares-chat/cron.php
   ```
   Si no hay cron, las tareas se ejecutan igualmente mientras haya algún cliente de Windows conectado.
7. Instala el plugin de WordPress ([BZChat-plugin](https://github.com/igorneumann/BZChat-plugin)) y entrega el cliente de Windows ([BZChat-client](https://github.com/igorneumann/BZChat-client)) a los agentes.
8. Cuando todo funcione, desactiva Crisp en WordPress.

### WhatsApp y Facebook (Meta)

1. En [developers.facebook.com](https://developers.facebook.com/apps/) crea una app de tipo *Business* y añade los productos **WhatsApp** y **Messenger**.
2. **WhatsApp**: copia el *Phone number ID* y crea un token permanente de *System User* con permiso `whatsapp_business_messaging`.
3. **Messenger**: genera el *Page access token* de la página de Bezares (permisos `pages_messaging`).
4. Webhooks: URL `https://chat.bezares.com/api/webhook/whatsapp` (y `/api/webhook/facebook`), con el *Verify token* que aparece en Configuración. Suscribe el campo `messages`.
5. Copia el **App Secret** (Configuración básica de la app) en el panel. **Es obligatorio**: sin él se rechazan los webhooks.

> Norma de Meta: solo se pueden enviar mensajes libres durante **24 h desde el último mensaje del cliente**. El cliente de Windows avisa cuando la ventana está cerrada. El seguimiento automático sirve para que el cliente conteste y la ventana se renueve.

### Nginx

```nginx
root /ruta/Bezares-chat/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
location ^~ /uploads/ { location ~ \.(php|phtml|phar|html?|js|svg)$ { deny all; } add_header X-Content-Type-Options nosniff; add_header Content-Security-Policy "default-src 'none'; sandbox"; }
```

## Estructura

```
config.sample.php   → copiar como config.php (no se sube a git)
cron.php            → tareas periódicas (solo línea de comandos)
app/                → lógica (no accesible desde la web)
public/             → raíz web: index.php (API + panel), widget.js, widget.css, uploads/
```

## API (resumen)

| Método | Ruta | Uso |
|---|---|---|
| GET | `/api/widget/config` | Configuración pública del widget |
| GET/POST | `/api/visitor/messages`, POST `/api/visitor/upload` | Widget web (CORS solo para `allowed_origins`) |
| GET/POST | `/api/webhook/whatsapp`, `/api/webhook/facebook` | Webhooks de Meta (firma obligatoria) |
| POST | `/api/auth/login`, `/api/auth/logout` | Sesión del cliente de Windows |
| GET | `/api/sync?since=` | Sincronización incremental (cada 2 s) |
| GET/POST | `/api/conversations/{id}/messages` | Leer / responder (texto o archivo) |
| POST | `/api/conversations/{id}/accept`, `/transfer` | Atender / transferir (solo recepción y admin) |

Seguridad: ver [SECURITY.md](SECURITY.md).
