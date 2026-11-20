# Bezares Chat: servidor

Sistema de chat propio para sustituir a Crisp en bezares.com. Este repositorio contiene el **servidor** que se instala en `chat.bezares.com`.

| Pieza | Repositorio | Qué hace |
|---|---|---|
| Servidor PHP | [Bezares-chat](https://github.com/igorneumann/Bezares-chat) | Base de datos, API, WhatsApp/Facebook, traducción, horario, recordatorios, limpieza, **panel de administración y estadísticas** |
| Cliente Windows | [BZChat-client](https://github.com/igorneumann/BZChat-client) | Programa portable (un `.exe` de 72 KB) para contestar chats, con timbre de teléfono |
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
- Usuarios con roles: **Administrador**, **Recepcionista** y **Comercial**, y además **roles personalizados**: en `/admin` → Configuración → **Roles** se crean roles y se marca qué puede hacer cada uno (ver todos los chats, recibir chats nuevos, atender si no hay recepción, transferir, borrar, plantillas, enviar por correo, marcar como resuelta y entrar en el panel).
- **Pruebas sin molestar a nadie**: en `/admin` → Configuración → **🧪 Pruebas** el administrador actúa como cualquier usuario sobre chats de prueba (simula al visitante y responde como el usuario, transfiere, resuelve…). Esos chats no aparecen en el programa de Windows, no suenan, no se envían por WhatsApp/Facebook, no cuentan en estadísticas y se borran solos a las 24 h. Indica además si ese usuario vería el chat y si le sonaría, según los permisos de su rol.
- **Plantillas** de respuesta para recepción y envío de la **transcripción por correo** desde el cliente de Windows.
- El visitante escribe su consulta y **la primera respuesta automática le pide el nombre** (y después el correo), en todos los canales. Mientras tanto suena el aviso: **solo en recepción** y, si no hay ninguna recepcionista conectada, en los comerciales, que entonces también ven los chats nuevos sin asignar.
- Si el visitante **recarga** la página sigue en el mismo chat. Si la **cierra** y vuelve pasados X minutos (configurable), empieza un chat nuevo.
- **País de origen** de cada chat (bandera en el cliente): por el prefijo en WhatsApp; en la web, por la cabecera del CDN o una base GeoIP local si existen, y si no por la **zona horaria del navegador** (sin enviar la IP a nadie).
- **Horario laboral** y festivos, con respuesta automática fuera de horario.
- **Traducción automática con ChatGPT**: los mensajes que no estén en español ni inglés se traducen para el agente, y las respuestas se envían en el idioma del cliente.
- Envío y recepción de **imágenes, audio, vídeo y documentos** (PDF, Word, Excel, PowerPoint, OpenDocument, RTF, TXT, CSV) por la web, WhatsApp y Facebook.
- **Recordatorio** automático si nadie contesta en X minutos y **seguimiento** si el cliente no responde en X horas, para mantener abierta la ventana de 24 h de WhatsApp.
- **Limpieza automática** de chats inactivos tras X meses, adjuntos incluidos.
- **Panel de administración** en `/admin` con inicio de sesión («mostrar contraseña» y «recordar sesión 30 días») y **recuperación de contraseña por correo**, para todos los roles.
- **Estadísticas** para el administrador: chats recibidos y contestados, porcentaje contestado en menos de X minutos, tiempo de respuesta medio y mediano, en tiempo real y en horario laboral, por agente, por canal y por día.

## Requisitos

- Hosting con **PHP 7.4 o superior** (extensiones `pdo_mysql`, `curl`, `mbstring`, `json` y `zip`) y **MySQL 5.7+ / MariaDB 10.3+**.
- Apache con `mod_rewrite`. Para Nginx, ver más abajo.
- Subdominio `chat.bezares.com` con **certificado SSL** (HTTPS es obligatorio).

## Instalación

1. Crea el subdominio `chat.bezares.com` y apunta su carpeta raíz a **`public/`** de este repositorio.
   Si tu hosting no lo permite, sube todo a la raíz del subdominio: el `.htaccess` incluido redirige a `public/` y bloquea el resto.
2. Crea una base de datos MySQL vacía y un usuario con permisos sobre ella.
3. Copia `config.sample.php` como **`config.php`** y rellena los datos de la base de datos, `base_url` y una `setup_key` larga y aleatoria.
   Configura también el **correo** (`mail_from` y, recomendado, los datos `smtp_*`): se usa para que cualquier usuario recupere su contraseña desde «¿Has olvidado tu contraseña?».
4. Abre `https://chat.bezares.com/admin/`. Se crean las tablas y te pide la `setup_key` para crear el **primer administrador**.
5. En **Configuración**, revisa mensajes, horario, OpenAI y WhatsApp/Facebook. En **Usuarios**, crea recepcionistas y comerciales.
6. Programa el cron cada minuto (recomendado, para recordatorios y limpieza):
   ```
   * * * * * php /ruta/a/Bezares-chat/cron.php
   ```
   Si no hay cron, las tareas se ejecutan igualmente mientras haya algún cliente de Windows conectado.
7. Instala el plugin de WordPress ([BZChat-plugin](https://github.com/igorneumann/BZChat-plugin)) y entrega el cliente de Windows ([BZChat-client](https://github.com/igorneumann/BZChat-client)) a los agentes.
8. Cuando todo funcione, desactiva Crisp en WordPress.

## Incrustar el chat en otras webs (por ejemplo, cloud.bezares.com)

1. En `/admin` → **Configuración → General y widget → Webs de confianza**, añade la web, una por línea (por ejemplo `https://cloud.bezares.com`) y guarda. En las webs que no estén en la lista el widget no aparece y el servidor rechaza sus peticiones.
2. Pega este código antes de `</body>` en la web (en WordPress no hace falta, lo añade el plugin):
   ```html
   <script src="https://chat.bezares.com/widget.js" data-position="bottom-right" data-offset="20" data-devices="desktop,tablet,mobile" defer></script>
   ```
   - `data-position`: `bottom-right`, `bottom-left`, `top-right` o `top-left`
   - `data-devices`: cualquier combinación de `desktop`, `tablet` y `mobile`
3. Si la web tiene una **política de seguridad de contenidos (CSP)** estricta, debe permitir `https://chat.bezares.com` en `script-src`, `style-src`, `connect-src`, `img-src` y `media-src`. Para grabar notas de voz, además, el micrófono no debe estar bloqueado por `Permissions-Policy`.

**Mostrar chat.bezares.com dentro de un iframe** (por ejemplo, el panel `/admin` en Nextcloud → app *Sitios externos* / *External sites*): basta con que la web esté en la lista de confianza. El servidor envía `Content-Security-Policy: frame-ancestors` con esa lista, y el resto de webs ven el aviso «ha rechazado la conexión». Si tras añadirla sigue apareciendo, comprueba que `https://chat.bezares.com/admin/` abre en una pestaña normal (si no abre, el servidor no está accesible) y que el hosting no añade su propia cabecera `X-Frame-Options` (el `.htaccess` incluido la elimina en Apache).

Para el **widget** en **Nextcloud** (`cloud.bezares.com`) envía una CSP estricta y no permite editar el HTML de sus páginas. Hace falta una app que inserte scripts, como *JSLoader* desde la tienda de apps de Nextcloud, con un fragmento que cree la etiqueta:

```js
var s = document.createElement('script');
s.src = 'https://chat.bezares.com/widget.js';
s.defer = true;
s.dataset.position = 'bottom-right';
s.dataset.devices = 'desktop,tablet,mobile';
document.head.appendChild(s);
```

y añadir `https://chat.bezares.com` a los dominios permitidos de la CSP en esa misma app. Si la app no permite ampliar la CSP, el navegador bloqueará el widget aunque la web esté en la lista de confianza.

### WhatsApp y Facebook (Meta)

En `/admin` → Configuración → **WhatsApp y Facebook** hay una guía paso a paso (cómo crear la app, obtener el App Secret, el token permanente de WhatsApp con un usuario del sistema y el Page access token), con las URLs de webhook y el token de verificación de tu instalación listos para copiar. Resumen:


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
location ^~ /uploads/ { deny all; }   # adjuntos antiguos: se migran solos a storage/ y se sirven por /media/ con enlace firmado
```

## Estructura

```
config.sample.php   → copiar como config.php (no se sube a git)
cron.php            → tareas periódicas (solo línea de comandos)
app/                → lógica (no accesible desde la web)
public/             → raíz web: index.php (API, panel y /media/ con enlaces firmados), widget.js, widget.css
storage/            → adjuntos privados y temporales (fuera de la web; se crea sola)
```

## API (resumen)

| Método | Ruta | Uso |
|---|---|---|
| GET | `/api/widget/config` | Configuración pública del widget |
| GET/POST | `/api/visitor/messages`, POST `/api/visitor/upload` | Widget web (solo desde las webs de confianza) |
| GET/POST | `/api/webhook/whatsapp`, `/api/webhook/facebook` | Webhooks de Meta (firma obligatoria) |
| POST | `/api/auth/login`, `/api/auth/logout` | Sesión del cliente de Windows |
| GET | `/api/sync?since=` | Sincronización incremental (cada 2 s) |
| POST | `/api/me/offline` | Aparecer desconectado al instante |
| GET/POST | `/api/conversations/{id}/messages` | Leer / responder (texto o archivo) |
| POST | `/api/conversations/{id}/accept`, `/transfer` | Atender / transferir (solo recepción y admin) |
| POST | `/api/conversations/{id}/email` | Enviar la transcripción por correo (varios destinatarios, introducción personalizable) |
| POST | `/api/conversations/{id}/resolve` | Marcar o desmarcar como resuelta |
| DELETE | `/api/conversations/{id}` | Borrar la conversación (recepción y administración) |
| GET/POST/DELETE | `/api/templates`, `/api/templates/{id}` | Plantillas de respuesta (solo recepción) |

Seguridad: ver [SECURITY.md](SECURITY.md).
