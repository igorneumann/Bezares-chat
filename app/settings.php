<?php
/**
 * Ajustes guardados en la tabla `settings`.
 * El esquema se envía al cliente de Windows, que genera el formulario de Configuración.
 */
class Settings
{
    private static $cache = null;

    public static function schema()
    {
        $day = array('on' => 1, 'start' => '08:00', 'end' => '17:00');
        $off = array('on' => 0, 'start' => '08:00', 'end' => '17:00');
        return array(
            array('group' => 'General y widget', 'fields' => array(
                array('k' => 'company_name', 'label' => 'Nombre de la empresa', 'type' => 'text', 'def' => 'Bezares', 'help' => 'Se usa en los mensajes con {company}.'),
                array('k' => 'widget_color', 'label' => 'Color del widget', 'type' => 'color', 'def' => '#0057a8'),
                array('k' => 'widget_title_es', 'label' => 'Título del widget (ES)', 'type' => 'text', 'def' => 'Chatea con nosotros'),
                array('k' => 'widget_title_en', 'label' => 'Título del widget (EN)', 'type' => 'text', 'def' => 'Chat with us'),
                array('k' => 'max_upload_mb', 'label' => 'Tamaño máximo de archivo (MB)', 'type' => 'number', 'def' => 16, 'help' => 'Se admiten imágenes, audio, vídeo y documentos (PDF, Word, Excel, PowerPoint, OpenDocument, RTF, TXT, CSV). Límites de WhatsApp: 5 MB imágenes, 16 MB audio/vídeo, 100 MB documentos. Comprueba también upload_max_filesize y post_max_size de PHP.'),
                array('k' => 'privacy_url', 'label' => 'URL de la política de privacidad', 'type' => 'text', 'def' => 'https://bezares.com/politica-de-privacidad/', 'help' => 'RGPD: el widget muestra un aviso con este enlace antes de pedir nombre y correo.'),
                array('k' => 'privacy_es', 'label' => 'Aviso de privacidad (ES)', 'type' => 'text', 'def' => 'Al chatear aceptas nuestra política de privacidad.'),
                array('k' => 'privacy_en', 'label' => 'Aviso de privacidad (EN)', 'type' => 'text', 'def' => 'By chatting you accept our privacy policy.'),
                array('k' => 'trusted_origins', 'label' => 'Webs de confianza (pueden incrustar el chat)', 'type' => 'origins', 'def' => "https://bezares.com\nhttps://www.bezares.com\nhttps://cloud.bezares.com",
                    'help' => 'Una dirección por línea, con https://. Solo estas webs pueden mostrar el widget; en cualquier otra no aparece. Se guarda solo el dominio (por ejemplo https://cloud.bezares.com).'),
                array('k' => 'help_embed', 'label' => 'Código para incrustar en otra web', 'type' => 'info', 'def' => '',
                    'help' => '<script src="' . rtrim((string) cfg('base_url'), '/') . '/widget.js" data-position="bottom-right" data-offset="20" data-devices="desktop,tablet,mobile" defer></script>  ·  data-position: bottom-right, bottom-left, top-right o top-left · data-devices: desktop, tablet, mobile. En WordPress no hace falta: el plugin lo añade solo.'),
                array('k' => 'help_devices', 'label' => 'Posición y dispositivos', 'type' => 'info', 'def' => '', 'help' => 'La esquina del icono y los dispositivos donde aparece se configuran en el plugin de WordPress.'),
            )),
            array('group' => 'Mensajes de bienvenida', 'fields' => array(
                array('k' => 'help_vars', 'label' => 'Variables', 'type' => 'info', 'def' => '', 'help' => 'Puedes usar {name}, {email} y {company}. Para idiomas distintos de español e inglés se traduce automáticamente la versión en inglés.'),
                array('k' => 'welcome_es', 'label' => 'Bienvenida, pide el nombre (ES)', 'type' => 'textarea', 'def' => '¡Hola! Bienvenido a {company}. Para atenderte mejor, ¿cómo te llamas?'),
                array('k' => 'welcome_en', 'label' => 'Bienvenida, pide el nombre (EN)', 'type' => 'textarea', 'def' => 'Hi! Welcome to {company}. To help you better, what is your name?'),
                array('k' => 'ask_email_es', 'label' => 'Pide el correo (ES)', 'type' => 'textarea', 'def' => 'Encantados, {name}. ¿Cuál es tu correo electrónico?'),
                array('k' => 'ask_email_en', 'label' => 'Pide el correo (EN)', 'type' => 'textarea', 'def' => 'Nice to meet you, {name}. What is your email address?'),
                array('k' => 'invalid_email_es', 'label' => 'Correo no válido (ES)', 'type' => 'textarea', 'def' => 'Ese correo no parece válido. ¿Puedes revisarlo?'),
                array('k' => 'invalid_email_en', 'label' => 'Correo no válido (EN)', 'type' => 'textarea', 'def' => 'That email does not look valid. Could you check it?'),
                array('k' => 'thanks_es', 'label' => 'Gracias, pasando con una persona (ES)', 'type' => 'textarea', 'def' => '¡Gracias! Te pasamos con una persona de nuestro equipo, en seguida te atiende.'),
                array('k' => 'thanks_en', 'label' => 'Gracias, pasando con una persona (EN)', 'type' => 'textarea', 'def' => 'Thank you! We are connecting you with someone from our team, they will be with you shortly.'),
                array('k' => 'onboarding_social', 'label' => 'Pedir el correo también en WhatsApp/Facebook', 'type' => 'bool', 'def' => 1, 'help' => 'El nombre se toma del perfil.'),
                array('k' => 'welcome_known_es', 'label' => 'Bienvenida WhatsApp/Facebook (ES)', 'type' => 'textarea', 'def' => '¡Hola {name}! Gracias por escribir a {company}. ¿Nos indicas tu correo electrónico?'),
                array('k' => 'welcome_known_en', 'label' => 'Bienvenida WhatsApp/Facebook (EN)', 'type' => 'textarea', 'def' => 'Hi {name}! Thanks for contacting {company}. Could you tell us your email address?'),
            )),
            array('group' => 'Horario laboral', 'fields' => array(
                array('k' => 'hours', 'label' => 'Horario semanal', 'type' => 'hours', 'def' => array(0 => $off, 1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => $day, 6 => $off)),
                array('k' => 'holidays', 'label' => 'Festivos', 'type' => 'textarea', 'def' => '', 'help' => 'Fechas AAAA-MM-DD separadas por espacios, comas o líneas.'),
                array('k' => 'ooh_enabled', 'label' => 'Respuesta automática fuera de horario', 'type' => 'bool', 'def' => 1),
                array('k' => 'ooh_es', 'label' => 'Mensaje fuera de horario (ES)', 'type' => 'textarea', 'def' => 'Ahora mismo estamos fuera de nuestro horario de atención. Déjanos tu mensaje y te responderemos lo antes posible.'),
                array('k' => 'ooh_en', 'label' => 'Mensaje fuera de horario (EN)', 'type' => 'textarea', 'def' => 'We are currently outside our business hours. Leave us your message and we will reply as soon as possible.'),
            )),
            array('group' => 'Recordatorios y limpieza', 'fields' => array(
                array('k' => 'reminder_enabled', 'label' => 'Recordatorio si nadie contesta', 'type' => 'bool', 'def' => 1, 'help' => 'Si el cliente escribe y nadie responde en X minutos (en horario laboral), se le envía este mensaje y vuelve a sonar el aviso.'),
                array('k' => 'reminder_minutes', 'label' => 'Minutos sin respuesta', 'type' => 'number', 'def' => 5),
                array('k' => 'reminder_es', 'label' => 'Mensaje de recordatorio (ES)', 'type' => 'textarea', 'def' => 'Seguimos contigo, en un momento te atendemos. Gracias por tu paciencia.'),
                array('k' => 'reminder_en', 'label' => 'Mensaje de recordatorio (EN)', 'type' => 'textarea', 'def' => 'We are still here, someone will be with you in a moment. Thanks for your patience.'),
                array('k' => 'followup_enabled', 'label' => 'Seguimiento si el cliente no responde', 'type' => 'bool', 'def' => 0, 'help' => 'Si el agente contestó y el cliente no responde en X horas, se envía este mensaje. En WhatsApp/Facebook solo se puede enviar dentro de las 24 h desde el último mensaje del cliente; si el cliente contesta, la ventana se renueva.'),
                array('k' => 'followup_hours', 'label' => 'Horas sin respuesta del cliente', 'type' => 'number', 'def' => 20, 'help' => 'Recomendado 20, para que llegue antes de que se cierre la ventana de 24 h.'),
                array('k' => 'followup_es', 'label' => 'Mensaje de seguimiento (ES)', 'type' => 'textarea', 'def' => '¿Podemos ayudarte en algo más? Responde a este mensaje si necesitas cualquier cosa.'),
                array('k' => 'followup_en', 'label' => 'Mensaje de seguimiento (EN)', 'type' => 'textarea', 'def' => 'Can we help you with anything else? Just reply to this message if you need anything.'),
                array('k' => 'retention_months', 'label' => 'Borrar chats inactivos tras (meses)', 'type' => 'number', 'def' => 12, 'help' => '0 = no borrar nunca. También se borran los archivos adjuntos.'),
                array('k' => 'stats_threshold', 'label' => 'Umbral por defecto en estadísticas (minutos)', 'type' => 'number', 'def' => 5),
            )),
            array('group' => 'Traducción (ChatGPT)', 'fields' => array(
                array('k' => 'translate_enabled', 'label' => 'Traducción automática', 'type' => 'bool', 'def' => 1, 'help' => 'Los mensajes que no estén en español ni inglés se traducen para el equipo, y las respuestas se envían traducidas al idioma del cliente.'),
                array('k' => 'openai_key', 'label' => 'API key de OpenAI', 'type' => 'secret', 'def' => ''),
                array('k' => 'openai_model', 'label' => 'Modelo', 'type' => 'text', 'def' => 'gpt-4o-mini', 'help' => 'Cualquier modelo de chat disponible en tu cuenta de OpenAI.'),
                array('k' => 'agent_lang', 'label' => 'Idioma del equipo (código ISO)', 'type' => 'text', 'def' => 'es'),
            )),
            array('group' => 'WhatsApp y Facebook', 'fields' => array(
                array('k' => 'help_webhooks', 'label' => 'Webhooks', 'type' => 'info', 'def' => '', 'help' => 'WhatsApp: ' . cfg('base_url') . '/api/webhook/whatsapp  ·  Messenger: ' . cfg('base_url') . '/api/webhook/facebook  (suscribir el campo "messages")'),
                array('k' => 'meta_verify_token', 'label' => 'Verify token', 'type' => 'text', 'def' => '', 'help' => 'Cópialo en la configuración del webhook en developers.facebook.com.'),
                array('k' => 'meta_app_secret', 'label' => 'App Secret', 'type' => 'secret', 'def' => '', 'help' => 'OBLIGATORIO: sin él se rechazan los mensajes entrantes, porque no se puede comprobar que vienen de Meta.'),
                array('k' => 'graph_version', 'label' => 'Versión de Graph API', 'type' => 'text', 'def' => 'v23.0'),
                array('k' => 'wa_enabled', 'label' => 'WhatsApp activado', 'type' => 'bool', 'def' => 0),
                array('k' => 'wa_phone_id', 'label' => 'WhatsApp Phone number ID', 'type' => 'text', 'def' => ''),
                array('k' => 'wa_token', 'label' => 'WhatsApp token permanente (System User)', 'type' => 'secret', 'def' => ''),
                array('k' => 'fb_enabled', 'label' => 'Facebook Messenger activado', 'type' => 'bool', 'def' => 0),
                array('k' => 'fb_page_token', 'label' => 'Page access token', 'type' => 'secret', 'def' => ''),
            )),
        );
    }

    public static function fields()
    {
        $out = array();
        foreach (self::schema() as $g) {
            foreach ($g['fields'] as $f) {
                $out[$f['k']] = $f;
            }
        }
        return $out;
    }

    public static function all()
    {
        if (self::$cache === null) {
            $vals = array();
            foreach (self::fields() as $k => $f) {
                $vals[$k] = $f['def'];
            }
            foreach (DB::rows('SELECT k, v FROM settings') as $r) {
                $vals[$r['k']] = json_decode($r['v'], true);
            }
            if (empty($vals['meta_verify_token'])) {
                $vals['meta_verify_token'] = random_token(12);
                DB::exec('REPLACE INTO settings (k, v) VALUES (?, ?)', array('meta_verify_token', json_encode($vals['meta_verify_token'])));
            }
            self::$cache = $vals;
        }
        return self::$cache;
    }

    public static function get($k)
    {
        $a = self::all();
        return isset($a[$k]) ? $a[$k] : null;
    }

    /** Valores para el cliente: los secretos se devuelven solo como "configurado sí/no". */
    public static function for_admin()
    {
        $out = array();
        foreach (self::fields() as $k => $f) {
            if ($f['type'] === 'info') {
                continue;
            }
            $v = self::get($k);
            $out[$k] = $f['type'] === 'secret' ? ($v !== '' && $v !== null) : $v;
        }
        return $out;
    }

    public static function save(array $in)
    {
        $fields = self::fields();
        foreach ($in as $k => $v) {
            if (!isset($fields[$k])) {
                continue;
            }
            $t = $fields[$k]['type'];
            if ($t === 'info') {
                continue;
            }
            if ($t === 'secret') {
                // null/true = sin cambios; '' = borrar; texto = nuevo valor
                if ($v === null || $v === true) {
                    continue;
                }
                $v = trim((string) $v);
            } elseif ($t === 'bool') {
                $v = $v ? 1 : 0;
            } elseif ($t === 'number') {
                $v = max(0, (int) $v);
            } elseif ($t === 'color') {
                $v = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? $v : '#0057a8';
            } elseif ($t === 'hours') {
                $h = array();
                for ($d = 0; $d < 7; $d++) {
                    $x = isset($v[$d]) ? $v[$d] : (isset($v[(string) $d]) ? $v[(string) $d] : array());
                    $h[$d] = array(
                        'on'    => empty($x['on']) ? 0 : 1,
                        'start' => isset($x['start']) && preg_match('/^\d{2}:\d{2}$/', $x['start']) ? $x['start'] : '08:00',
                        'end'   => isset($x['end']) && preg_match('/^\d{2}:\d{2}$/', $x['end']) ? $x['end'] : '17:00',
                    );
                }
                $v = $h;
            } elseif ($t === 'origins') {
                $v = implode("\n", self::parse_origins((string) $v));
            } elseif ($t === 'textarea') {
                $v = mb_substr(trim(strip_tags((string) $v)), 0, 2000);
            } else {
                $v = clean_line($v, 300);
            }
            DB::exec('REPLACE INTO settings (k, v) VALUES (?, ?)', array($k, json_encode($v)));
        }
        self::$cache = null;
    }

    /**
     * Convierte una lista de URLs (una por línea, comas o espacios) en orígenes únicos "https://dominio[:puerto]".
     * Solo se admite https (y http://localhost para pruebas). Se descarta lo que no sea una URL válida.
     */
    public static function parse_origins($text)
    {
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string) $text) as $u) {
            $u = trim($u);
            if ($u === '') {
                continue;
            }
            if (!preg_match('#^[a-z]+://#i', $u)) {
                $u = 'https://' . $u;
            }
            $p = parse_url($u);
            if (!$p || empty($p['host']) || isset($p['user'])) {
                continue;
            }
            $scheme = strtolower($p['scheme'] ?? '');
            $host = strtolower($p['host']);
            if (!preg_match('/^[a-z0-9.\-]+$/', $host)) {
                continue;
            }
            $local = in_array($host, array('localhost', '127.0.0.1'), true);
            if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
                continue;
            }
            $origin = $scheme . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '');
            $out[$origin] = $origin;
        }
        return array_values($out);
    }

    /** Orígenes autorizados a incrustar el widget: lista del panel + allowed_origins de config.php. */
    public static function trusted_origins()
    {
        $list = self::parse_origins((string) self::get('trusted_origins'));
        foreach ((array) cfg('allowed_origins', array()) as $o) {
            $list = array_merge($list, self::parse_origins($o));
        }
        return array_values(array_unique($list));
    }

    /** Texto configurable en el idioma del cliente (otros idiomas: traducción cacheada del inglés). */
    public static function text($key, $lang, $conv = null)
    {
        $lang = lang_norm($lang);
        $es = (string) self::get($key . '_es');
        $en = (string) self::get($key . '_en');
        if ($lang === 'en') {
            return fill_vars($en, $conv);
        }
        if ($lang === '' || $lang === 'es') {
            return fill_vars($es, $conv);
        }
        $tr = AI::translate_cached($en, $lang);
        return fill_vars($tr ? $tr : $en, $conv);
    }
}
