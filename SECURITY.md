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
- Cabeceras `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` y `Cache-Control: no-store` en la API y el panel.

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
