<?php
/** Conversaciones y mensajes, comunes a todos los canales. */
class Core
{
    const OOH_REPEAT_MS = 43200000; // mensaje fuera de horario como máximo cada 12 h
    const EMAIL_BURST_MS = 8000;    // varios mensajes seguidos: se insiste una sola vez

    public static function conv($id)
    {
        return DB::row('SELECT * FROM conversations WHERE id = ?', array((int) $id));
    }

    /** Última conversación de ese remitente; $test separa los chats de prueba de los reales. */
    public static function find($channel, $ext, $test = 0)
    {
        return DB::row('SELECT * FROM conversations WHERE channel = ? AND external_id = ? AND is_test = ? ORDER BY id DESC LIMIT 1', array($channel, $ext, $test ? 1 : 0));
    }

    /**
     * Todos los chats de la misma persona (incluido $c): mismo correo, mismo teléfono o mismo remitente del canal.
     * Se repite con los correos y teléfonos encontrados (WhatsApp con correo -> chats web con ese correo -> …).
     * $visible: condición SQL de los chats permitidos (debe excluir los de prueba). El más reciente primero.
     */
    public static function person_convs($c, $visible, $limit)
    {
        $emails = array();
        $phones = array();
        $found = array($c);
        $rows = array();
        for ($i = 0; $i < 4; $i++) {
            $before = count($emails) + count($phones);
            foreach ($found as $r) {
                if (trim($r['email']) !== '') {
                    $emails[strtolower(trim($r['email']))] = true;
                }
                if (trim($r['phone']) !== '') {
                    $phones[trim($r['phone'])] = true;
                }
            }
            if ($i > 0 && count($emails) + count($phones) === $before) {
                break; // ya no aparecen datos nuevos de la persona
            }
            $or = array('(channel = ? AND external_id = ?)');
            $args = array($c['channel'], $c['external_id']);
            foreach (array('email' => array_keys($emails), 'phone' => array_keys($phones)) as $col => $vals) {
                if ($vals) {
                    $or[] = $col . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
                    foreach ($vals as $v) {
                        $args[] = (string) $v;
                    }
                }
            }
            $rows = DB::rows('SELECT * FROM conversations WHERE (' . implode(' OR ', $or) . ') AND ' . $visible
                . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit, $args);
            $found = $rows;
        }
        return $rows;
    }

    /* ---------------------------------------------------------- modo de pruebas */

    /** Canales que admiten el modo de pruebas (el chat web se prueba con el visitante simulado del panel). */
    const TEST_CHANNELS = array('whatsapp' => 'WhatsApp', 'facebook' => 'Facebook Messenger');

    /** Estado del modo de pruebas: ['channels' => [...], 'until' => unix, 'by' => nombre] o null si no está activo. */
    public static function test_mode()
    {
        $t = cache_get('test_mode');
        return is_array($t) && !empty($t['channels']) && (int) $t['until'] > time() ? $t : null;
    }

    public static function test_mode_for($channel)
    {
        $t = self::test_mode();
        return $t && in_array($channel, $t['channels'], true);
    }

    /** Activa el modo de pruebas para unos canales durante unas horas (se desactiva solo al caducar). */
    public static function set_test_mode(array $channels, $hours, $by)
    {
        $channels = array_values(array_intersect(array_keys(self::TEST_CHANNELS), $channels));
        if (!$channels) {
            cache_del('test_mode');
            return;
        }
        $hours = max(1, min(8, (int) $hours));
        cache_set('test_mode', array('channels' => $channels, 'until' => time() + $hours * 3600, 'by' => (string) $by), $hours * 3600);
    }

    public static function update($id, array $f)
    {
        $f['updated_at'] = now_ms();
        DB::update('conversations', $f, array('id' => (int) $id));
    }

    public static function message($id)
    {
        return DB::row('SELECT * FROM messages WHERE id = ?', array((int) $id));
    }

    private static function preview($body, $type, $name = '')
    {
        $icons = array('image' => '📷 Imagen', 'audio' => '🎤 Audio', 'video' => '🎬 Vídeo', 'file' => '📄 ' . ($name !== '' ? $name : 'Documento'));
        $p = trim(preg_replace('/\s+/u', ' ', (string) $body));
        if ($p === '' && $type) {
            $p = $icons[$type] ?? '📎';
        }
        return mb_substr($p, 0, 250);
    }

    /** $o: agent_id, translated, lang, media (array), ext_id, created_at */
    public static function insert_message($conv_id, $dir, $body, array $o = array())
    {
        $m = $o['media'] ?? null;
        $id = DB::insert('messages', array(
            'conversation_id' => (int) $conv_id,
            'direction'       => $dir,
            'agent_id'        => (int) ($o['agent_id'] ?? 0),
            'body'            => (string) $body,
            'body_translated' => $o['translated'] ?? null,
            'lang'            => (string) ($o['lang'] ?? ''),
            'media_url'       => $m ? $m['url'] : '',
            'media_type'      => $m ? $m['type'] : '',
            'media_name'      => $m ? $m['name'] : '',
            'ext_id'          => (string) ($o['ext_id'] ?? ''),
            'created_at'      => isset($o['created_at']) ? (int) $o['created_at'] : now_ms(),
        ));
        self::update($conv_id, $dir === 'system' ? array() : array('last_preview' => self::preview($body, $m ? $m['type'] : '', $m ? $m['name'] : '')));
        return $id;
    }

    private static function send_result($msg_id, $r)
    {
        DB::update('messages', array(
            'send_status' => $r['ok'] ? 'sent' : 'error',
            'send_error'  => $r['ok'] ? '' : (string) $r['error'],
            'ext_id'      => $r['ok'] ? (string) $r['id'] : '',
        ), array('id' => $msg_id));
        return (bool) $r['ok'];
    }

    /** Mensaje automático en el idioma del cliente. Devuelve si se ha entregado. */
    public static function bot_say($conv_id, $key)
    {
        $c = self::conv($conv_id);
        return self::bot_text($conv_id, Settings::text($key, $c['lang'], $c));
    }

    /** Envía un texto del robot ya preparado. Devuelve si se ha entregado. */
    public static function bot_text($conv_id, $text)
    {
        $c = self::conv($conv_id);
        if (!$c || $text === '') {
            return false;
        }
        $id = self::insert_message($c['id'], 'bot', $text, array('lang' => $c['lang']));
        return self::send_result($id, Channels::send($c, $text));
    }

    /**
     * WhatsApp y Facebook: a las 23 h sin respuesta se avisa al cliente de que se le contestará por correo (a las 24 h
     * Meta ya no deja escribirle) y se para el tiempo de respuesta. Lo llama Cron una sola vez por mensaje del cliente.
     */
    public static function window_notice($id)
    {
        $now = now_ms();
        // Reserva atómica: si coinciden dos ejecuciones, solo una envía el aviso.
        $st = DB::exec('UPDATE conversations SET window_try_at = ? WHERE id = ? AND window_try_at < last_customer_at', array($now, (int) $id));
        if ($st->rowCount() !== 1) {
            return;
        }
        $c = self::conv($id);
        $noEmail = $c['email'] === '';
        if (!self::bot_say($id, $noEmail ? 'window_noemail' : 'window_notice')) {
            return; // no se ha entregado: el cliente no lo sabe y el tiempo sigue contando
        }
        $f = array('window_notice_at' => $now);
        if (!(int) $c['window_first_at']) {
            $f['window_first_at'] = $now;
        }
        if ($noEmail && !(Flow::live() && Flow::wait_email($id, false))) {
            $f += array('onboarding' => 'email', 'onboarding_tries' => 1); // su respuesta se lee como correo
        }
        self::update($id, $f);
        self::note($id, 'Aviso enviado: se le contestará por correo. Se deja de contar el tiempo de respuesta.');
    }

    /** El cliente ha recibido una respuesta de una persona (mensaje entregado o correo). */
    public static function mark_answered($id, $agent)
    {
        $c = self::conv($id);
        $now = now_ms();
        $f = array('last_agent_at' => $now, 'onboarding' => 'done');
        if (!(int) $c['first_response_at']) {
            $f['first_response_at'] = $now;
            $f['first_response_by'] = (int) $agent['id'];
        }
        self::update($id, $f);
    }

    public static function require_window($c)
    {
        if (Channels::must_email($c)) {
            throw new ApiError('Han pasado más de 24 horas desde el último mensaje del cliente y ' . ($c['channel'] === 'facebook' ? 'Facebook' : 'WhatsApp') . ' no permite contestarle por el chat. Usa el envío por correo.', 409);
        }
    }

    public static function note($conv_id, $text, $agent_id = 0)
    {
        self::insert_message($conv_id, 'system', $text, array('agent_id' => $agent_id));
    }

    /* ---------------------------------------------------------- entrantes */

    /** Mensaje de un cliente por cualquier canal. $profile: name, phone, email, lang, page_url */
    public static function incoming($channel, $ext, array $profile, $text, $media = null, $ext_id = '')
    {
        $text = trim((string) $text);
        if ($text === '' && !$media) {
            return null;
        }
        if ($ext_id !== '' && DB::val('SELECT id FROM messages WHERE ext_id = ? LIMIT 1', array($ext_id))) {
            return null; // reintento de Meta
        }
        $now = now_ms();
        // Chat de prueba: simulado desde el panel, o cualquier mensaje de un canal en modo de pruebas.
        $test = !empty($profile['test']) || self::test_mode_for($channel);
        $profile['test'] = $test;
        $c = self::find($channel, $ext, $test);
        $new = false;

        if (!$c) {
            $new = true;
            $onb = 'done';
            if ($channel === 'web') {
                $onb = 'name';
            } elseif (Settings::get('onboarding_social')) {
                $onb = !empty($profile['name']) ? 'email' : 'name';
            }
            $id = DB::insert('conversations', array(
                'channel'     => $channel,
                'external_id' => $ext,
                'name'        => mb_substr((string) ($profile['name'] ?? ''), 0, 120),
                'phone'       => (string) ($profile['phone'] ?? ''),
                'email'       => (string) ($profile['email'] ?? ''),
                'lang'        => lang_norm($profile['lang'] ?? ''),
                'country'     => Geo::norm($profile['country'] ?? ''),
                'is_test'     => empty($profile['test']) ? 0 : 1, // chat de prueba (panel o modo de pruebas): el equipo no lo ve
                'page_url'    => mb_substr((string) ($profile['page_url'] ?? ''), 0, 500),
                'status'      => 'open',
                'onboarding'  => $onb,
                'created_at'  => $now,
                'updated_at'  => $now,
            ));
            $c = self::conv($id);
            // El primer mensaje del cliente es su consulta; el bot responde después pidiendo el nombre (ver bot_flow).
        } elseif ($c['status'] === 'closed' || $c['status'] === 'resolved') {
            // El cliente vuelve a escribir en un chat resuelto/cerrado: se reabre y vuelve a sonar.
            // Si la AI va a leer el mensaje, no suena todavía: solo si al final pasa a una persona (Flow::ai).
            self::update($c['id'], array('status' => 'open', 'accepted_by' => 0, 'accepted_at' => Flow::ai_waiting($c) ? now_ms() : 0));
            self::note($c['id'], 'Conversación reabierta por el cliente');
        }
        if ($channel === 'web' && !empty($profile['page_url'])) {
            self::update($c['id'], array('page_url' => mb_substr($profile['page_url'], 0, 500)));
        }
        // Completa el país si aún no se conocía (chats anteriores a esta función o datos que llegan después).
        if ($c['country'] === '' && !empty($profile['country'])) {
            self::update($c['id'], array('country' => Geo::norm($profile['country'])));
        }

        // Idioma + traducción: el primer mensaje siempre (es la consulta); luego, no en las respuestas de nombre/correo
        // (mientras se insiste en el correo, sí se traducen los mensajes largos: son parte de la consulta).
        $o = array('ext_id' => $ext_id, 'media' => $media);
        $ask = preg_match('/^[qf]\d+$/', $c['onboarding']) === 1; // respondiendo a una pregunta del robot
        $long = ($ask || $c['onboarding'] === 'email') && strpos($text, '@') === false && mb_strlen($text) >= 15;
        if ($text !== '' && ($new || $long || !($ask || in_array($c['onboarding'], array('name', 'email'), true)))) {
            $a = AI::analyze($text);
            $o['lang'] = $a['lang'];
            $o['translated'] = $a['translation'];
            if ($a['lang'] !== '' && $a['lang'] !== $c['lang']) {
                self::update($c['id'], array('lang' => $a['lang']));
            }
        }
        self::set_typing('in', $c['id'], 0, false);
        $msg = self::insert_message($c['id'], 'in', $text, $o);
        self::update($c['id'], array('last_customer_at' => now_ms()));

        self::bot_flow(self::conv($c['id']), $text, $new);
        return $msg;
    }

    /** Primer correo válido que aparezca en el texto, o ''. */
    public static function find_email($text)
    {
        $ok = preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $text, $m) && valid_email($m[0]);
        return $ok ? strtolower($m[0]) : '';
    }

    /** Nombre escrito por el cliente («Me llamo Ana», «Ana, ana@example.com»…). $email: el que ha dado en ese mensaje. */
    public static function parse_name($text, $email)
    {
        $name = strip_tags($text);
        if ($email !== '') { // ha dado los dos a la vez
            $name = preg_replace('/(y\s+)?(mi|my)\s+(correo|email|e-mail)(\s+(es|is))?\s*:?/iu', ' ', str_ireplace($email, ' ', $name));
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name), " ,;:-<>()\t");
        return (string) preg_replace('/^(me llamo|soy|mi nombre es|my name is|i am|i\'m)\s+/iu', '', $name);
    }

    /** Captura de nombre/correo + respuesta fuera de horario (esta última, solo cuando ya tenemos el correo). */
    private static function bot_flow($c, $text, $new)
    {
        $now = now_ms();
        $closed = Settings::get('ooh_enabled') && !Hours::is_open($now);
        // Robot con caminos dibujados (ver flow.php). Si algo falla, el chat sigue sin robot: nunca se pierde el mensaje.
        $flow = preg_match('/^f\d+$/', $c['onboarding']) === 1;
        if ($flow || ($new && $c['onboarding'] !== 'done' && Flow::live())) {
            try {
                if ($flow) {
                    Flow::answer($c, $text);
                } else {
                    Flow::start($c, $text);
                }
            } catch (Throwable $e) {
                log_error('Robot (diagrama): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                self::update($c['id'], array('onboarding' => 'done', 'onboarding_tries' => 0));
            }
            return;
        }
        if (preg_match('/^q\d+$/', $c['onboarding'])) { // contesta a una pregunta añadida en el diagrama
            $email = $c['email'] === '' ? self::find_email($text) : '';
            if ($email !== '') {
                self::update($c['id'], array('email' => $email, 'ooh_sent_at' => 0));
                $c = self::conv($c['id']);
            }
            Robot::answer($c, $text);
            return;
        }
        $email = $c['onboarding'] !== 'done' ? self::find_email($text) : '';
        if ($email !== '') {
            self::update($c['id'], array('email' => $email, 'ooh_sent_at' => 0)); // el aviso de horario sale justo después
            $c = self::conv($c['id']);
        }
        $done = $email !== '' && $c['onboarding'] === 'email';

        if ($new && $c['onboarding'] !== 'done' && !$done) {
            // Primera respuesta a un chat nuevo (web, WhatsApp o Facebook): pedir el nombre (o el correo si ya lo conocemos).
            self::bot_say($c['id'], $c['onboarding'] === 'email' ? 'welcome_known' : 'welcome');
        } elseif ($c['onboarding'] === 'name') {
            $name = self::parse_name($text, $email);
            if ($name !== '') {
                self::update($c['id'], array('name' => mb_substr($name, 0, 120), 'onboarding' => 'email', 'onboarding_tries' => 0));
                if ($c['email'] !== '') {
                    $done = true;
                } else {
                    Robot::run($c['id'], 'before'); // pasos propios y, después, pide el correo
                    return;
                }
            } elseif ($email !== '') { // solo ha dado el correo: no se le retiene por el nombre
                $done = true;
            }
        } elseif ($c['onboarding'] === 'email' && !$done) {
            self::insist_email($c, $text);
        }
        if ($done) { // pasos propios y, después, «Gracias» o «Fuera de horario»
            Robot::run($c['id'], 'data');
            return;
        }

        $c = self::conv($c['id']);
        if (!$closed || $c['onboarding'] !== 'done' || ($now - (int) $c['ooh_sent_at']) <= self::OOH_REPEAT_MS) {
            return;
        }
        self::update($c['id'], array('ooh_sent_at' => $now));
        $asks = $c['email'] === '' && ($c['channel'] === 'web' || Settings::get('onboarding_social'));
        if ($asks && Flow::live() && Flow::wait_email($c['id'], true)) {
            return; // se lo pide la caja del correo y sigue por su camino
        }
        if ($asks && !Flow::live()) {
            // Chat antiguo sin correo: primero se pide (al recibirlo sale el aviso de horario).
            self::update($c['id'], array('onboarding' => 'email', 'onboarding_tries' => 1));
            self::bot_say($c['id'], 'need_email');
        } else {
            self::bot_say($c['id'], 'ooh');
        }
    }

    /** El cliente no ha dado un correo válido: se le vuelve a pedir (hasta que lo dé o le conteste una persona). */
    private static function insist_email($c, $text)
    {
        $tries = (int) $c['onboarding_tries'];
        $ago = now_ms() - (int) DB::val("SELECT MAX(created_at) FROM messages WHERE conversation_id = ? AND direction = 'bot'", array($c['id']));
        $bad = strpos($text, '@') !== false; // ha intentado escribir un correo
        if ($tries >= max(1, (int) Settings::get('email_asks'))) { // evita bucles con contestadores automáticos
            if ($ago < self::OOH_REPEAT_MS) {
                return;
            }
            $tries = 0; // vuelve otro día: se le pide de nuevo
        } elseif (!$bad && $tries > 0 && $ago < self::EMAIL_BURST_MS) {
            return;
        }
        self::update($c['id'], array('onboarding_tries' => $tries + 1));
        self::bot_say($c['id'], $bad ? 'invalid_email' : 'need_email');
    }

    /* ---------------------------------------------------------- agentes */

    public static function agent_send($c, $agent, $text, $media = null)
    {
        $text = trim((string) $text);
        if ($text === '' && !$media) {
            throw new ApiError('Mensaje vacío');
        }
        self::require_window($c);
        $now = now_ms();
        $tr = null;
        if ($text !== '' && $c['lang'] && !in_array($c['lang'], AI::NATIVE, true) && AI::enabled()) {
            $tr = AI::translate($text, $c['lang']);
        }
        self::set_typing('out', $c['id'], $agent['id'], false);
        $id = self::insert_message($c['id'], 'out', $text, array(
            'agent_id' => $agent['id'], 'translated' => $tr, 'lang' => $tr ? $c['lang'] : '', 'media' => $media,
        ));
        $f = array('status' => 'open');
        if (!(int) $c['accepted_at']) {
            $f['accepted_at'] = $now;
            $f['accepted_by'] = $agent['id'];
        }
        if (!(int) $c['assigned_to']) {
            $f['assigned_to'] = $agent['id'];
        }
        self::update($c['id'], $f);
        $meta = Channels::is_meta($c);
        if (!$meta) {
            self::mark_answered($c['id'], $agent);
        }
        // En WhatsApp y Facebook solo cuenta como respuesta si se ha entregado: si no, el cliente sigue esperando.
        if (self::send_result($id, Channels::send(self::conv($c['id']), $tr ?: $text, $media)) && $meta) {
            self::mark_answered($c['id'], $agent);
        }
        return $id;
    }

    /* ---------------------------------------------------------- «está escribiendo» */

    const TYPING_MS = 7000; // sin noticias en este tiempo, se considera que ha dejado de escribir

    /** $dir: 'in' = el visitante de la web; 'out' = el agente $by. */
    public static function set_typing($dir, $conv_id, $by, $on)
    {
        $key = 'typing:' . $dir . ':' . (int) $conv_id;
        if ($on) {
            cache_set($key, array('t' => now_ms(), 'by' => (int) $by), 10);
        } else {
            cache_del($key);
        }
    }

    /** ['t', 'by'] si alguien está escribiendo ahora en ese chat; si no, null. */
    public static function typing($dir, $conv_id)
    {
        $v = cache_get('typing:' . $dir . ':' . (int) $conv_id);
        return is_array($v) && (int) $v['t'] > now_ms() - self::TYPING_MS ? $v : null;
    }

    /** Todos los que escriben ahora: [['id' => conversación, 'by' => 0 cliente | agente], ...] */
    public static function typing_all()
    {
        $out = array();
        foreach (DB::rows("SELECT k, v FROM kv WHERE k LIKE 'typing:%' AND expires >= ?", array(time())) as $r) {
            $v = json_decode($r['v'], true);
            if (is_array($v) && (int) $v['t'] > now_ms() - self::TYPING_MS && preg_match('/^typing:(in|out):(\d+)$/', $r['k'], $m)) {
                $out[] = array('id' => (int) $m[2], 'by' => $m[1] === 'in' ? 0 : (int) $v['by']);
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------- editar y borrar mensajes enviados */

    /**
     * Lanza ApiError si ese mensaje enviado no se puede editar o borrar. En la web siempre se puede; en WhatsApp y
     * Facebook solo si no llegó a entregarse: Meta no permite cambiar ni retirar lo que el cliente ya ha recibido.
     */
    private static function check_changeable($c, $m, $agent, $delete)
    {
        if ($m['direction'] !== 'out' || (int) $m['deleted']) {
            throw new ApiError('Ese mensaje no se puede ' . ($delete ? 'borrar' : 'editar'));
        }
        // Editar, solo los propios (aparecen con su nombre). Borrar, también los de otros con el permiso «Borrar».
        if ((int) $m['agent_id'] !== (int) $agent['id'] && !($delete && Roles::can($agent, 'delete'))) {
            throw new ApiError('Solo puedes ' . ($delete ? 'borrar' : 'editar') . ' los mensajes que has enviado tú', 403);
        }
        if ($c['channel'] !== 'web' && $m['send_status'] !== 'error') {
            throw new ApiError(($c['channel'] === 'whatsapp' ? 'WhatsApp' : 'Facebook') . ' no permite editar ni borrar un mensaje que ya se ha enviado al cliente');
        }
    }

    /** Vista previa de la lista: se recalcula con el último mensaje que quede. */
    private static function refresh_preview($conv_id)
    {
        $l = DB::row("SELECT * FROM messages WHERE conversation_id = ? AND direction <> 'system' AND deleted = 0 ORDER BY id DESC LIMIT 1", array((int) $conv_id));
        self::update($conv_id, array('last_preview' => $l ? self::preview($l['body'], $l['media_type'], $l['media_name']) : ''));
    }

    /** Cambia el texto de un mensaje enviado. Si no se había entregado (WhatsApp/Facebook), lo vuelve a enviar. */
    public static function edit_message($c, $m, $agent, $text)
    {
        self::check_changeable($c, $m, $agent, false);
        $text = trim((string) $text);
        if ($text === '' && $m['media_url'] === '') {
            throw new ApiError('El mensaje no puede quedar vacío');
        }
        $resend = $c['channel'] !== 'web';
        if ($resend) {
            self::require_window($c);
        }
        if ($text === trim((string) $m['body']) && !$resend) {
            return;
        }
        $tr = null;
        if ($text !== '' && $c['lang'] && !in_array($c['lang'], AI::NATIVE, true) && AI::enabled()) {
            $tr = AI::translate($text, $c['lang']);
        }
        DB::update('messages', array(
            'body' => $text, 'body_translated' => $tr, 'lang' => $tr ? $c['lang'] : '', 'changed_at' => now_ms(),
        ), array('id' => (int) $m['id']));
        if ($resend) {
            $media = $m['media_url'] !== '' ? array('url' => $m['media_url'], 'type' => $m['media_type'], 'name' => $m['media_name']) : null;
            if (self::send_result((int) $m['id'], Channels::send($c, $tr ?: $text, $media)) && Channels::is_meta($c)) {
                self::mark_answered($c['id'], $agent);
            }
        }
        self::refresh_preview($c['id']);
    }

    /* ---------------------------------------------------------- reenviar lo que no se entregó por la configuración */

    /** Errores de la conexión con Meta (token, permisos, ID, canal apagado): el mensaje seguro que no salió. */
    const CONFIG_ERRORS = array('Unsupported post request', 'Error validating access token', 'Invalid OAuth access token', 'Authentication Error', 'WhatsApp desactivado', 'Facebook desactivado');

    private static function is_config_error($err)
    {
        foreach (self::CONFIG_ERRORS as $e) {
            if (strpos((string) $err, $e) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mensajes del equipo y automáticos de los últimos 30 días que no se entregaron por la configuración, en orden de envío.
     * open: aún se le puede escribir. later: después ya se le ha contestado. dup: repite uno anterior de la lista.
     */
    public static function undelivered()
    {
        $like = implode(' OR ', array_fill(0, count(self::CONFIG_ERRORS), 'm.send_error LIKE ?'));
        $args = array(now_ms() - 30 * 86400000);
        foreach (self::CONFIG_ERRORS as $e) {
            $args[] = $e . '%';
        }
        $rows = DB::rows(
            "SELECT m.*, c.channel, c.name, c.phone, c.email, c.status AS conv_status, c.last_customer_at, c.last_agent_at,
               (SELECT COUNT(*) FROM messages x WHERE x.conversation_id = m.conversation_id AND x.id > m.id
                  AND x.direction = 'out' AND x.send_status = 'sent' AND x.deleted = 0) AS later_sent
             FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE c.is_test = 0 AND c.channel IN ('whatsapp', 'facebook') AND m.direction IN ('out', 'bot') AND m.deleted = 0
               AND m.send_status = 'error' AND m.created_at > ? AND ($like)
             ORDER BY m.id LIMIT 300",
            $args
        );
        $seen = array();
        foreach ($rows as $i => $m) {
            $key = $m['conversation_id'] . '|' . trim((string) $m['body']) . '|' . $m['media_url'];
            $rows[$i]['open'] = Channels::is_window_open($m);
            // last_agent_at también cuenta la respuesta por correo (pasadas las 24 h).
            $rows[$i]['later'] = (int) $m['later_sent'] > 0 || (int) $m['last_agent_at'] > (int) $m['created_at'];
            $rows[$i]['dup'] = isset($seen[$key]);
            $seen[$key] = true;
        }
        return $rows;
    }

    /**
     * Vuelve a enviar los mensajes elegidos que sigan pendientes. Si Meta repite un error de configuración, se deja
     * ese canal. Devuelve [enviados, fallidos, fuera de plazo, sin intentar, último error].
     */
    public static function resend(array $ids, $agent)
    {
        $ids = array_flip(array_map('intval', $ids));
        $start = time();
        $n = array('sent' => 0, 'failed' => 0, 'closed' => 0, 'left' => 0, 'error' => '');
        $broken = array();
        $done = array();
        foreach (self::undelivered() as $m) {
            $id = (int) $m['id'];
            if (!isset($ids[$id])) {
                continue;
            }
            if (isset($broken[$m['channel']]) || time() - $start > 35) { // antes de que el servidor web corte la petición
                $n['left']++;
                continue;
            }
            $c = self::conv($m['conversation_id']);
            if (!$c || !Channels::is_window_open($c)) {
                $n['closed']++;
                continue;
            }
            // Reserva atómica: con dos pulsaciones a la vez, solo una lo envía.
            $st = DB::exec("UPDATE messages SET send_error = 'Reenvío en curso' WHERE id = ? AND send_status = 'error' AND send_error = ? AND deleted = 0", array($id, $m['send_error']));
            if ($st->rowCount() !== 1) {
                continue;
            }
            $media = $m['media_url'] !== '' ? array('url' => $m['media_url'], 'type' => $m['media_type'], 'name' => $m['media_name']) : null;
            try {
                $r = Channels::send($c, (string) ($m['body_translated'] ?: $m['body']), $media);
            } catch (Throwable $e) {
                log_error('Reenvío del mensaje ' . $id . ': ' . $e->getMessage());
                $r = array('ok' => false, 'error' => 'Error interno al reenviar');
            }
            $ok = self::send_result($id, $r);
            DB::update('messages', array('changed_at' => now_ms()), array('id' => $id)); // el programa de Windows quita el aviso
            if ($ok) {
                $n['sent']++;
                $done[(int) $c['id']] = ($done[(int) $c['id']] ?? 0) + 1;
                if ($m['direction'] === 'out') { // un mensaje automático no cuenta como respuesta
                    self::mark_answered($c['id'], array('id' => (int) $m['agent_id']));
                }
                continue;
            }
            $n['failed']++;
            $n['error'] = (string) $r['error'];
            if (self::is_config_error($r['error'])) {
                $broken[$m['channel']] = true;
            }
        }
        foreach ($done as $cid => $k) {
            self::note($cid, $agent['name'] . ($k === 1 ? ' reenvió un mensaje que no se había entregado.' : ' reenvió ' . $k . ' mensajes que no se habían entregado.'), $agent['id']);
        }
        return $n;
    }

    /** Borra el contenido (y el adjunto) de un mensaje enviado. El equipo ve «Mensaje borrado»; el cliente deja de verlo. */
    public static function delete_message($c, $m, $agent)
    {
        self::check_changeable($c, $m, $agent, true);
        DB::update('messages', array(
            'body' => '', 'body_translated' => null, 'lang' => '', 'media_url' => '', 'media_type' => '', 'media_name' => '',
            'send_status' => '', 'send_error' => '', 'deleted' => 1, 'changed_at' => now_ms(),
        ), array('id' => (int) $m['id']));
        if ($m['media_url'] !== '') {
            Media::delete_stored($m['media_url']);
        }
        self::refresh_preview($c['id']);
        // Registro mínimo para auditoría (sin el contenido).
        log_error('Mensaje ' . (int) $m['id'] . ' del chat ' . (int) $c['id'] . ' borrado por ' . $agent['name'] . ' (agente ' . (int) $agent['id'] . ')');
    }

    public static function accept($c, $agent)
    {
        $f = array('accepted_at' => now_ms(), 'accepted_by' => $agent['id']);
        if (!(int) $c['assigned_to']) {
            $f['assigned_to'] = $agent['id'];
        }
        self::update($c['id'], $f);
    }

    public static function transfer($c, $from, $to, $note = '')
    {
        self::update($c['id'], array('assigned_to' => $to['id'], 'transferred_by' => $from['id'], 'accepted_at' => 0, 'accepted_by' => 0, 'status' => 'open'));
        $t = $from['name'] . ' transfirió la conversación a ' . $to['name'];
        if (trim($note) !== '') {
            $t .= ': ' . clean_text($note, 500);
        }
        self::note($c['id'], $t, $from['id']);
    }

    public static function set_status($c, $agent, $status)
    {
        self::update($c['id'], array('status' => $status));
        self::note($c['id'], $agent['name'] . ($status === 'closed' ? ' cerró la conversación' : ' reabrió la conversación'), $agent['id']);
    }

    /**
     * Marca (o desmarca) la conversación como resuelta. Al resolverla se deja de contar el tiempo de respuesta
     * (resolved_at, se guarda la primera vez), deja de sonar y no se envían recordatorios automáticos.
     */
    public static function resolve($c, $agent, $on)
    {
        $now = now_ms();
        if ($on) {
            $f = array('status' => 'resolved');
            if (!(int) $c['resolved_at']) {
                $f['resolved_at'] = $now;
            }
            if (!(int) $c['accepted_at']) {
                $f['accepted_at'] = $now;
                $f['accepted_by'] = $agent['id'];
            }
            self::update($c['id'], $f);
            self::note($c['id'], $agent['name'] . ' marcó la conversación como resuelta', $agent['id']);
        } else {
            self::update($c['id'], array('status' => 'open'));
            self::note($c['id'], $agent['name'] . ' reabrió la conversación', $agent['id']);
        }
    }

    /* ---------------------------------------------------------- salida JSON */

    public static function conv_out($c)
    {
        $o = array();
        foreach (array('id', 'assigned_to', 'accepted_at', 'created_at', 'updated_at', 'last_customer_at', 'last_agent_at', 'first_response_at', 'resolved_at', 'window_notice_at') as $k) {
            $o[$k] = (int) $c[$k];
        }
        $o['transferred_by'] = isset($c['transferred_by']) ? (int) $c['transferred_by'] : 0;
        foreach (array('channel', 'name', 'email', 'phone', 'lang', 'country', 'page_url', 'status', 'onboarding', 'last_preview') as $k) {
            $o[$k] = $c[$k];
        }
        $o['answers'] = Robot::answers($c);
        $o['window_open'] = Channels::is_window_open($c);
        return $o;
    }

    public static function msg_out($m, $visitor = false)
    {
        $o = array(
            'id'         => (int) $m['id'],
            'direction'  => $m['direction'],
            'body'       => $m['body'],
            'media_url'  => Media::url($m['media_url']), // enlace firmado que caduca
            'media_type' => $m['media_type'],
            'media_name' => $m['media_name'],
            'created_at' => (int) $m['created_at'],
            'changed_at' => (int) $m['changed_at'], // > 0: editado o borrado
            'deleted'    => (int) $m['deleted'],
        );
        if ($visitor) {
            if ($m['direction'] === 'out') {
                if ($m['body_translated']) {
                    $o['body'] = $m['body_translated']; // el visitante ve la versión traducida
                }
                $o['agent_name'] = Auth::first_name($m['agent_id']);
            }
            return $o;
        }
        $o['conversation_id'] = (int) $m['conversation_id'];
        $o['agent_id'] = (int) $m['agent_id'];
        $o['translated'] = $m['body_translated'];
        $o['lang'] = $m['lang'];
        $o['send_status'] = $m['send_status'];
        $o['send_error'] = $m['send_error'];
        return $o;
    }
}
