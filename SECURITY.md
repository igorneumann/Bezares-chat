# Seguridad: auditoría y medidas aplicadas

Auditoría del código del 19/11/2026, sobre el servidor, el widget, el plugin y el cliente de Windows. El código no se ha ejecutado en un servidor PHP real: estas medidas deben verificarse también en el despliegue.

## Medidas implementadas

### Comunicaciones
- **HTTPS obligatorio**: el servidor redirige HTTP a HTTPS (`force_https`) y envía la cabecera HSTS. El cliente de Windows rechaza servidores que no sean `https://` (solo admite `http://localhost` para pruebas).
- Las conexiones salientes (OpenAI, Meta) usan **solo HTTPS**, con verificación del certificado, también en las redirecciones.

### Autenticación y sesiones
- Contraseñas con `password_hash` (bcrypt/argon según PHP) y rehash automático. Mínimo 10 caracteres, con letras y números.
- **Freno a la fuerza bruta**: 10 intentos fallidos por IP y 20 por cuenta cada 15 minutos. Solo cuentan los fallos.
- Tiempo de respuesta igual tanto si el correo existe como si no, para no revelar qué usuarios existen.
- Tokens del cliente de Windows de 256 bits, **guardados solo como hash SHA-256**. Caducan a los 30 días y se revocan al cerrar sesión, al cambiar la contraseña o al desactivar el usuario.
- En el PC, el token se guarda **cifrado con DPAPI**: solo lo puede leer el mismo usuario de Windows. La contraseña nunca se guarda.
- Panel de administración: cookie `HttpOnly`, `Secure` y `SameSite=Strict`, modo estricto de sesión, ID regenerado al entrar, caducidad tras 2 h de inactividad, **token CSRF** en todos los formularios y cierre de sesión por POST.
- Solo el rol Administrador entra en el panel. Ningún administrador puede quitarse el rol, desactivarse ni eliminarse a sí mismo.
- **Recordar sesión** (panel): cookie `HttpOnly`, `Secure` y `SameSite=Strict` con un token aleatorio de 256 bits, guardado solo como hash. Caduca a los 30 días y se revoca al cerrar sesión, al cambiar la contraseña o al desactivar el usuario.
- **Recuperar contraseña**: enlace de un solo uso, válido 1 hora, con el token guardado como hash. La respuesta es la misma exista o no el correo, y hay límites de 5 solicitudes por hora por IP y 3 por cuenta. Al cambiar la contraseña se cierran todas las sesiones. La página no envía `Referer`.
- La CSP del panel solo permite el script propio `/admin.js` (botón «Mostrar contraseña»).
- Instalación inicial protegida por `setup_key`. Solo funciona mientras no existe ningún usuario y no acepta la clave de ejemplo.

### Autorización
- Comercial: solo ve y contesta los chats asignados a él, comprobado en el servidor en cada petición.
- Solo Recepcionista y Administrador pueden transferir.

### Entradas y salida
- **SQL**: todas las consultas usan sentencias preparadas (PDO sin emulación).
- **XSS**: el panel escapa toda la salida (`htmlspecialchars`) y aplica una **CSP sin JavaScript**. El widget escapa todo antes de insertarlo y solo convierte en enlace las URLs `http(s)`. El cliente de Windows es nativo (no interpreta HTML) y solo abre enlaces `http(s)`.
- Límites de longitud en textos, nombres y correos; correos validados.

### Archivos subidos
- Lista blanca de extensiones (imagen, audio, vídeo, PDF, Word, Excel, PowerPoint, OpenDocument, RTF, TXT, CSV), **comprobación de la firma interna** de cada documento (un HTML o ejecutable renombrado a .pdf o .docx se rechaza), validación real de las imágenes, nombre aleatorio de 96 bits y límite de tamaño.
- En `uploads/` se **prohíbe ejecutar o servir** PHP, HTML, JS y SVG. Se añaden `nosniff` y una CSP `sandbox`, y los documentos se fuerzan a descarga.
- Los adjuntos deben ser públicos con URL impredecible, porque WhatsApp y Messenger descargan por enlace los que envían los agentes.

### Webhooks de WhatsApp y Facebook
- **Firma `X-Hub-Signature-256` obligatoria** (App Secret). Sin App Secret configurado se rechazan.
- Verificación del *verify token* en tiempo constante (`hash_equals`).
- Los mensajes repetidos por reintentos de Meta se descartan por identificador.
- **Anti-SSRF**: los adjuntos solo se descargan de dominios de Meta (`fbcdn.net`, `fbsbx.com`, `whatsapp.net`…) por HTTPS. Así el token de WhatsApp nunca se envía a terceros.

### Abuso del widget público
- Solo las **webs de confianza** (Configuración → General y widget, más `allowed_origins` de config.php) pueden incrustar el widget: CORS restringido a esa lista, y el servidor rechaza con 403 cualquier petición del widget que llegue desde otro origen, aunque sea un envío "simple" sin comprobación previa del navegador. Solo se admiten orígenes https.
- Límites por IP: 30 mensajes por minuto, 10 archivos cada 5 minutos y **5 chats nuevos por hora**, para evitar inundar a recepción de llamadas falsas.
- Identificador del visitante generado con un generador criptográfico (`crypto.randomUUID` o `getRandomValues`).
- Si usas Cloudflare u otro proxy, configura `client_ip_header`. Si no, todos los visitantes compartirían la IP del proxy.

### Configuración y fugas de información
- `config.php` y `app/` quedan fuera de la raíz web (y bloqueados por `.htaccess` si no se puede).
- `display_errors` desactivado y errores internos genéricos para el público. El detalle solo va al log.
- Las claves (OpenAI, Meta) nunca se muestran en el panel, solo «guardado».
- Anti-clickjacking con CSP `frame-ancestors`: solo la propia web y las webs de confianza pueden mostrar chat.bezares.com en un iframe (se elimina `X-Frame-Options`, que no admite listas).
- Cabeceras `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` y `Cache-Control: no-store` en la API y el panel.

### Privacidad (RGPD)
- El widget muestra un **aviso de privacidad con enlace** a la política antes de pedir nombre y correo (configurable).
- **Borrado automático** de conversaciones y adjuntos tras X meses de inactividad.
- La traducción envía a OpenAI el texto de los mensajes en idiomas distintos de español e inglés: hay que **mencionarlo en la política de privacidad** (encargado del tratamiento) y revisar la configuración de retención de datos de la cuenta de OpenAI.

## Riesgos residuales y recomendaciones
- **Firmar el ejecutable** de Windows con un certificado de firma de código, para evitar el aviso de SmartScreen y garantizar que nadie lo ha modificado.
- Hacer **copias de seguridad** de la base de datos y de `public/uploads/`.
- Mantener PHP y MySQL actualizados y usar contraseñas largas y únicas para la base de datos.
- Un mensaje de un cliente podría intentar manipular a la IA de traducción (*prompt injection*). El agente siempre ve también el texto original.
- El token del visitante vive en el `localStorage` de bezares.com: una vulnerabilidad XSS en WordPress permitiría leer su conversación. Mantén WordPress y sus plugins al día.
- Se recomienda activar un WAF o límites de peticiones en el hosting (por ejemplo, Cloudflare) para frenar ataques de volumen.

## Segunda auditoría (20/11/2026): correcciones aplicadas

| # | Hallazgo | Corrección |
|---|---|---|
| 1 | Descargas que seguían redirecciones sin revalidar | `Http` **nunca sigue redirecciones**. Las descargas de Meta recorren a mano como máximo 3 saltos y en cada uno comprueban dominio de Meta, HTTPS, sin usuario ni puerto, y que **todas las IP resueltas sean públicas** (rechaza rangos privados y reservados). La conexión se fija a la IP validada (`CURLOPT_RESOLVE`) para evitar cambios de DNS entre la comprobación y la descarga, y `Authorization` solo se envía al dominio original. |
| 2 | Archivos cargados enteros en memoria | El tamaño se comprueba con `$_FILES['size']` y `filesize()` **antes de leer**. Las subidas se mueven con `move_uploaded_file` y las descargas se escriben directamente en disco (`CURLOPT_FILE`) con corte por tamaño. Para validar solo se leen los primeros 64 KB. Ajusta también `upload_max_filesize` y `post_max_size` de PHP al límite de la aplicación. |
| 3 | Límites no atómicos y API pública sin anti-bot | Contadores **atómicos en MySQL** (`INSERT … ON DUPLICATE KEY UPDATE` con `LAST_INSERT_ID`) y ventanas fijas. Para abrir un chat nuevo el navegador debe resolver una **prueba de trabajo** (hashcash SHA-256 de 18 bits, firmada con HMAC, caducidad de 10 min y un solo uso). A una persona le cuesta menos de 1 s en segundo plano; a un bot que quiera abrir chats masivamente le supone un coste real. CORS no se usa como autenticación. |
| 4 | Documentos ZIP validados solo por la cabecera | Se abren con `ZipArchive` y se exigen los archivos internos del formato (`word/document.xml`, `xl/workbook.xml`, `ppt/presentation.xml`; `mimetype` y `content.xml` en OpenDocument). Contra **bombas ZIP**: máximo 5.000 entradas, 300 MB descomprimidos y un ratio de compresión de 200:1 para entradas de más de 1 MB. Requiere la extensión `zip` de PHP; sin ella, los documentos Office se rechazan. |
| 5 | Adjuntos públicos permanentes | Los adjuntos se guardan **fuera de la carpeta pública** (`storage/uploads`) y solo se sirven por `/media/…` con **enlace firmado (HMAC) que caduca**: 24 h para agentes y visitantes (se renueva al cargar los mensajes) y 2 h para que WhatsApp o Messenger descarguen lo que envía un agente. La base de datos guarda solo la ruta relativa. Los adjuntos antiguos de `public/uploads` se migran automáticamente en la tarea diaria. |
| 6 | Cabeceras de proxy sin verificar | `client_ip_header`, `X-Forwarded-Proto` y la cabecera de país solo se aceptan si la conexión llega desde una IP o rango de `trusted_proxies` (config.php). |
| 7 | Token de Facebook en la URL | Se envía en la cabecera `Authorization: Bearer`. |
| 8 | Plugin de WordPress con servidor libre | Solo admite `https://` y dominios permitidos (por defecto `chat.bezares.com`; ampliable con la constante `BZCW_ALLOWED_HOSTS` en wp-config.php). Se comprueba al guardar y al mostrar el widget. |

- **Envío de la conversación por correo**: solo a quien puede ver el chat, máximo 20 envíos por hora y agente, y queda registrado en la conversación («X envió la conversación por correo a Y»). Los enlaces a adjuntos del correo caducan a los 7 días.
- **Migración de adjuntos antiguos**: la base de datos solo se actualiza si el archivo se movió de verdad (rename, o copia verificada por tamaño + borrado). Lo que falla se reintenta al día siguiente sin bloquear al resto, y `public/uploads` está bloqueada desde ya (los archivos se siguen sirviendo solo por `/media/` con firma).

Otros cambios: el esquema de la base de datos se actualiza solo tras desplegar código nuevo (`schema_version`), y la tarea diaria limpia los límites caducados y los temporales huérfanos.
