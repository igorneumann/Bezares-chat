<?php
/** API JSON: /api/... */
class Api
{
    private static $raw = null;

    private static function raw()
    {
        if (self::$raw === null) {
            self::$raw = (string) file_get_contents('php://input');
        }
        return self::$raw;
    }

    private static function input()
    {
        if (!empty($_POST)) {
            return $_POST;
        }
        $d = json_decode(self::raw(), true);
        return is_array($d) ? $d : array();
    }

    private static function param($k, $def = '')
    {
        $in = self::input();
        if (isset($in[$k])) {
            return $in[$k];
        }
        return isset($_GET[$k]) ? $_GET[$k] : $def;
    }

    private static function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** CORS solo para los endpoints del widget y solo para las webs autorizadas. */
    private static function cors()
    {
        $origin = strtolower(rtrim(isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '', '/'));
        // Webs de confianza: lista del panel (Configuración) + allowed_origins de config.php
        if ($origin && in_array($origin, Settings::trusted_origins(), true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 86400');
        }
    }

    public static function handle($method, $path)
    {
        try {
            $path = '/' . trim($path, '/');
            if (strpos($path, '/visitor/') === 0 || $path === '/widget/config') {
                self::cors();
                if ($method === 'OPTIONS') {
                    http_response_code(204);
                    exit;
                }
                // Un navegador en una web que no es de confianza no puede usar el widget (ni siquiera con envíos "simples").
                $origin = strtolower(rtrim(isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '', '/'));
                $own = strtolower(rtrim((string) cfg('base_url'), '/'));
                if ($origin !== '' && $origin !== 'null' && $origin !== $own && !in_array($origin, Settings::trusted_origins(), true)) {
                    throw new ApiError('Esta web no está autorizada para mostrar el chat', 403);
                }
            }
            $routes = array(
                array('GET', '#^/widget/config$#', 'widget_config'),
                array('GET', '#^/visitor/messages$#', 'visitor_messages'),
                array('GET', '#^/visitor/challenge$#', 'visitor_challenge'),
                array('POST', '#^/visitor/messages$#', 'visitor_send'),
                array('POST', '#^/visitor/upload$#', 'visitor_send'),
                array('GET', '#^/webhook/(whatsapp|facebook)$#', 'webhook_verify'),
                array('POST', '#^/webhook/(whatsapp|facebook)$#', 'webhook'),
                array('POST', '#^/auth/login$#', 'login'),
                array('POST', '#^/auth/logout$#', 'logout'),
                array('GET', '#^/me$#', 'me'),
                array('POST', '#^/me/offline$#', 'me_offline'),
                array('GET', '#^/sync$#', 'sync'),
                array('GET', '#^/conversations/(\d+)/messages$#', 'conv_messages'),
                array('POST', '#^/conversations/(\d+)/messages$#', 'conv_send'),
                array('POST', '#^/conversations/(\d+)/accept$#', 'conv_accept'),
                array('POST', '#^/conversations/(\d+)/transfer$#', 'conv_transfer'),
                array('POST', '#^/conversations/(\d+)/email$#', 'conv_email'),
                array('POST', '#^/conversations/(\d+)/resolve$#', 'conv_resolve'),
                array('DELETE', '#^/conversations/(\d+)$#', 'conv_delete'),
                array('GET', '#^/templates$#', 'templates_list'),
                array('POST', '#^/templates$#', 'template_save'),
                array('POST', '#^/templates/(\d+)$#', 'template_save'),
                array('DELETE', '#^/templates/(\d+)$#', 'template_delete'),
            );
            foreach ($routes as $r) {
                if ($r[0] === $method && preg_match($r[1], $path, $m)) {
                    self::json(call_user_func(array(__CLASS__, $r[2]), isset($m[1]) ? $m[1] : null));
                }
            }
            throw new ApiError('Ruta no encontrada', 404);
        } catch (ApiError $e) {
            self::json(array('message' => $e->getMessage()), $e->status);
        } catch (Throwable $e) {
            log_error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::json(array('message' => 'Error interno del servidor'), 500);
        }
    }

    /* ---------------------------------------------------------- widget */

    private static function widget_config()
    {
        $s = Settings::all();
        return array(
            'color'     => $s['widget_color'],
            'maxUpload' => (int) $s['max_upload_mb'],
            'resetMinutes' => (int) $s['widget_reset_minutes'],
            'open'      => Hours::is_open(),
            'privacyUrl' => preg_match('#^https://#i', (string) $s['privacy_url']) ? $s['privacy_url'] : '',
            'es'        => array('title' => $s['widget_title_es'], 'welcome' => fill_vars($s['welcome_es']), 'privacy' => $s['privacy_es']),
            'en'        => array('title' => $s['widget_title_en'], 'welcome' => fill_vars($s['welcome_en']), 'privacy' => $s['privacy_en']),
        );
    }

    /* ---------------------------------------------------------- anti-bot: prueba de trabajo */

    /*
     * Para abrir un chat nuevo, el navegador debe resolver un pequeño reto criptográfico (hashcash):
     * encontrar un número n tal que SHA-256(reto + ":" + n) empiece por POW_BITS bits a cero.
     * A una persona le cuesta menos de un segundo, en segundo plano mientras abre el chat; a un bot que
     * quiera abrir miles de chats le supone un coste de cálculo real. Cada reto está firmado, caduca y
     * solo vale una vez. Complementa (no sustituye) los límites por IP: CORS no es autenticación.
     */
    const POW_BITS = 18;
    const POW_TTL = 600;

    private static function visitor_challenge()
    {
        if (!rate_limit('challenge', 30, 60)) {
            throw new ApiError('Demasiadas solicitudes, espera un momento', 429);
        }
        $c = 'v1.' . random_token(12) . '.' . (time() + self::POW_TTL) . '.' . self::POW_BITS;
        return array('c' => $c, 's' => sign('pow|' . $c), 'bits' => self::POW_BITS);
    }

    private static function leading_zero_bits($bin)
    {
        $bits = 0;
        for ($i = 0; $i < strlen($bin); $i++) {
            $b = ord($bin[$i]);
            if ($b === 0) {
                $bits += 8;
                continue;
            }
            for ($m = 0x80; ($b & $m) === 0; $m >>= 1) {
                $bits++;
            }
            break;
        }
        return $bits;
    }

    private static function verify_pow()
    {
        $c = (string) self::param('pow_c');
        $s = (string) self::param('pow_s');
        $n = (string) self::param('pow_n');
        $fail = new ApiError('Verificación anti-spam no superada. Recarga la página e inténtalo de nuevo.', 403);
        if (!preg_match('/^v1\.([a-f0-9]{24})\.(\d{10})\.(\d{1,2})$/', $c, $m) || !preg_match('/^\d{1,12}$/', $n)) {
            throw $fail;
        }
        if (!hash_equals(sign('pow|' . $c), $s) || (int) $m[2] < time() || (int) $m[3] < self::POW_BITS) {
            throw $fail;
        }
        if (self::leading_zero_bits(hash('sha256', $c . ':' . $n, true)) < (int) $m[3]) {
            throw $fail;
        }
        // Un solo uso: INSERT IGNORE es atómico (si dos peticiones usan el mismo reto, solo una lo consigue).
        $st = DB::exec('INSERT IGNORE INTO kv (k, v, expires) VALUES (?, ?, ?)', array('pow:' . $m[1], '1', (int) $m[2] + 60));
        if ($st->rowCount() !== 1) {
            throw $fail;
        }
    }

    private static function visitor_token()
    {
        $t = (string) self::param('token');
        // "paneltest-..." está reservado a los chats de prueba del panel: el widget no puede usarlos.
        if (!preg_match('/^[a-zA-Z0-9\-]{20,64}$/', $t) || stripos($t, 'paneltest-') === 0) {
            throw new ApiError('Token no válido');
        }
        return $t;
    }

    private static function visitor_messages()
    {
        $c = Core::find('web', self::visitor_token());
        if (!$c) {
            // exists=false: el widget empieza de cero si su conversación se borró.
            return array('messages' => array(), 'open' => Hours::is_open(), 'exists' => false);
        }
        $rows = DB::rows("SELECT * FROM messages WHERE conversation_id = ? AND id > ? AND direction <> 'system' ORDER BY id LIMIT 200", array($c['id'], max(0, (int) self::param('after', 0))));
        return array('messages' => array_map(function ($m) { return Core::msg_out($m, true); }, $rows), 'open' => Hours::is_open());
    }

    /** Texto y/o archivo del visitante. */
    private static function visitor_send()
    {
        $token = self::visitor_token();
        if (!rate_limit('msg', 30, 60)) {
            throw new ApiError('Demasiados mensajes, espera un momento', 429);
        }
        $text = clean_text(self::param('text'));
        $hasFile = !empty($_FILES['file']);
        if ($text === '' && !$hasFile) {
            throw new ApiError('Mensaje vacío');
        }
        if (!Core::find('web', $token)) {
            // Chat nuevo: prueba de trabajo anti-bot y límite de chats nuevos por IP, ANTES de aceptar ningún archivo.
            self::verify_pow();
            if (!rate_limit('newconv', 5, 3600)) {
                throw new ApiError('Demasiadas conversaciones nuevas desde tu conexión. Inténtalo más tarde.', 429);
            }
        }
        $media = null;
        if ($hasFile) {
            if (!rate_limit('up', 10, 300)) {
                throw new ApiError('Demasiados archivos, espera unos minutos', 429);
            }
            $media = Media::store_upload($_FILES['file']);
        }
        $page = (string) self::param('page');
        $id = Core::incoming('web', $token, array(
            'lang'     => lang_norm(self::param('lang')),
            'country'  => Geo::from_web((string) self::param('locale'), (string) self::param('tz')),
            'page_url' => preg_match('#^https?://#i', $page) ? $page : '',
        ), $text, $media);
        return array('ok' => true, 'id' => (int) $id);
    }

    /* ---------------------------------------------------------- webhooks de Meta */

    private static function webhook_verify()
    {
        // PHP convierte "hub.mode" en "hub_mode"
        $mode = isset($_GET['hub_mode']) ? $_GET['hub_mode'] : '';
        $token = isset($_GET['hub_verify_token']) ? (string) $_GET['hub_verify_token'] : '';
        $challenge = isset($_GET['hub_challenge']) ? (string) $_GET['hub_challenge'] : '';
        if ($mode === 'subscribe' && hash_equals((string) Settings::get('meta_verify_token'), $token)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo preg_replace('/[^0-9A-Za-z_\-]/', '', $challenge);
            exit;
        }
        throw new ApiError('Token de verificación incorrecto', 403);
    }

    private static function webhook($channel)
    {
        $raw = self::raw();
        if (!Channels::verify_signature($raw)) {
            throw new ApiError('Firma no válida', 403);
        }
        $p = json_decode($raw, true);
        if (is_array($p)) {
            if ($channel === 'whatsapp') {
                Channels::handle_whatsapp($p);
            } else {
                Channels::handle_facebook($p);
            }
        }
        return array('ok' => true);
    }

    /* ---------------------------------------------------------- agentes */

    private static function login()
    {
        return Auth::login(self::param('email'), self::param('password'));
    }

    private static function logout()
    {
        Auth::logout();
        return array('ok' => true);
    }

    /** El agente se desconecta: deja de contar como conectado (avisos y transferencias van a otros). */
    private static function me_offline()
    {
        $a = Auth::require_agent();
        Auth::set_offline($a['id']);
        return array('ok' => true);
    }

    private static function me()
    {
        return array('agent' => Auth::out(Auth::require_agent()));
    }

    private static function visible_sql($a, $alias = '')
    {
        // Los chats de prueba del panel (is_test) nunca llegan al programa de Windows.
        $real = $alias . 'is_test = 0 AND ';
        if (Roles::can($a, 'view_all')) {
            return $real . '1=1'; // permiso «Ver todos los chats»
        }
        // Sus chats asignados/transferidos y, si recibe chats nuevos, también los nuevos sin asignar.
        $own = $alias . 'assigned_to = ' . (int) $a['id'];
        return $real . (Auth::gets_new($a) ? '(' . $own . ' OR ' . $alias . 'assigned_to = 0)' : $own);
    }

    /** Sincronización incremental (cada ~2 s). */
    private static function sync()
    {
        $a = Auth::require_agent();
        Cron::tick();
        $now = now_ms();
        $since = max(0, (int) self::param('since', 0));
        $vis = self::visible_sql($a);
        if ($since === 0) {
            $convs = DB::rows("SELECT * FROM conversations WHERE $vis AND updated_at > ? ORDER BY updated_at DESC LIMIT 300", array($now - 30 * 86400000));
            $msgs = array();
        } else {
            $from = $since - 5000; // margen; el cliente deduplica por id
            $convs = DB::rows("SELECT * FROM conversations WHERE $vis AND updated_at > ? ORDER BY updated_at DESC LIMIT 300", array($from));
            $msgs = DB::rows('SELECT m.* FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.created_at > ? AND ' . self::visible_sql($a, 'c.') . ' ORDER BY m.id LIMIT 500', array($from));
        }
        // Conversaciones que deben sonar para este agente
        $ringing = array();
        foreach (DB::rows("SELECT id, assigned_to FROM conversations WHERE is_test = 0 AND status = 'open' AND accepted_at = 0 AND updated_at > ?", array($now - 7 * 86400000)) as $c) {
            if (Auth::should_ring($a, $c)) {
                $ringing[] = (int) $c['id'];
            }
        }
        return array(
            'now'           => $now,
            'business_open' => Hours::is_open($now),
            'conversations' => array_map(array('Core', 'conv_out'), $convs),
            'messages'      => array_map(array('Core', 'msg_out'), $msgs),
            'agents'        => array_map(array('Auth', 'out'), Auth::agents()),
            'ringing'       => $ringing,
        );
    }

    private static function conv_for_agent($id)
    {
        $a = Auth::require_agent();
        $c = Core::conv($id);
        if (!$c || !Auth::can_see($a, $c)) {
            throw new ApiError('Conversación no encontrada', 404);
        }
        return array($a, $c);
    }

    private static function conv_messages($id)
    {
        list(, $c) = self::conv_for_agent($id);
        $rows = DB::rows('SELECT * FROM (SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 500) x ORDER BY id', array($c['id']));
        return array('conversation' => Core::conv_out($c), 'messages' => array_map(array('Core', 'msg_out'), $rows));
    }

    private static function conv_send($id)
    {
        list($a, $c) = self::conv_for_agent($id);
        $media = !empty($_FILES['file']) ? Media::store_upload($_FILES['file']) : null;
        $mid = Core::agent_send($c, $a, clean_text(self::param('text')), $media);
        return array('message' => Core::msg_out(Core::message($mid)), 'conversation' => Core::conv_out(Core::conv($c['id'])));
    }

    private static function conv_accept($id)
    {
        list($a, $c) = self::conv_for_agent($id);
        Core::accept($c, $a);
        return array('conversation' => Core::conv_out(Core::conv($c['id'])));
    }

    private static function conv_transfer($id)
    {
        list($a, $c) = self::conv_for_agent($id);
        Auth::require_perm($a, 'transfer');
        $to = Auth::agent((int) self::param('agent_id'));
        if (!$to || !(int) $to['active']) {
            throw new ApiError('Usuario de destino no válido');
        }
        Core::transfer($c, $a, $to, (string) self::param('note'));
        return array('conversation' => Core::conv_out(Core::conv($c['id'])));
    }

    /* ---------------------------------------------------------- resolver */

    private static function conv_resolve($id)
    {
        list($a, $c) = self::conv_for_agent($id);
        Auth::require_perm($a, 'resolve');
        $on = filter_var(self::param('resolved', true), FILTER_VALIDATE_BOOLEAN);
        Core::resolve($c, $a, $on);
        return array('conversation' => Core::conv_out(Core::conv($c['id'])));
    }

    /* ---------------------------------------------------------- borrar una conversación */

    /** Borra definitivamente la conversación, sus mensajes y sus adjuntos (solo recepción y administración). */
    private static function conv_delete($id)
    {
        list($a, $c) = self::conv_for_agent($id);
        Auth::require_perm($a, 'delete');
        foreach (DB::col("SELECT media_url FROM messages WHERE conversation_id = ? AND media_url <> ''", array($c['id'])) as $u) {
            Media::delete_stored($u);
        }
        DB::delete('messages', array('conversation_id' => $c['id']));
        DB::delete('conversations', array('id' => $c['id']));
        // Registro mínimo para auditoría (sin el contenido del chat).
        log_error('Conversación ' . (int) $c['id'] . ' (' . $c['channel'] . ') borrada por ' . $a['name'] . ' (agente ' . (int) $a['id'] . ')');
        return array('ok' => true);
    }

    /* ---------------------------------------------------------- enviar la conversación por correo */

    private static function conv_email($id)
    {
        list($a, $c) = self::conv_for_agent($id);
        Auth::require_perm($a, 'email');
        // Uno o varios destinatarios separados por comas (o punto y coma). Máximo 10.
        $to = array();
        foreach (preg_split('/[,;\s]+/', strtolower((string) self::param('to'))) as $e) {
            $e = trim($e);
            if ($e === '') {
                continue;
            }
            if (!valid_email($e)) {
                throw new ApiError('Correo de destino no válido: ' . $e);
            }
            $to[$e] = $e;
        }
        $to = array_values($to);
        if (!$to) {
            throw new ApiError('Indica al menos un correo de destino');
        }
        if (count($to) > 10) {
            throw new ApiError('Como máximo 10 destinatarios por envío');
        }
        // El límite cuenta cada destinatario (20 correos por hora y agente).
        foreach ($to as $unused) {
            if (!rate_limit('email:' . $a['id'], 20, 3600)) {
                throw new ApiError('Has enviado demasiados correos en la última hora. Inténtalo más tarde.', 429);
            }
        }
        $rows = DB::rows("SELECT * FROM messages WHERE conversation_id = ? AND direction <> 'system' ORDER BY id LIMIT 2000", array($c['id']));
        $company = (string) Settings::get('company_name');
        $tz = tz();
        $lines = array();
        foreach ($rows as $m) {
            $when = (new DateTimeImmutable('@' . intdiv((int) $m['created_at'], 1000)))->setTimezone($tz)->format('d/m/Y H:i');
            if ($m['direction'] === 'in') {
                $who = $c['name'] !== '' ? $c['name'] : 'Cliente';
                $body = (string) $m['body']; // lo que escribió el cliente
            } elseif ($m['direction'] === 'out') {
                $who = Auth::first_name($m['agent_id']) . ' (' . $company . ')';
                $body = $m['body_translated'] ? (string) $m['body_translated'] : (string) $m['body']; // lo que recibió el cliente
            } else {
                $who = $company;
                $body = (string) $m['body'];
            }
            $text = '[' . $when . '] ' . $who . ":\n" . ($body !== '' ? $body : '');
            if ($m['media_url']) {
                // Enlace firmado con validez de 7 días para que el destinatario pueda abrir los adjuntos.
                $text .= ($body !== '' ? "\n" : '') . '📎 ' . ($m['media_name'] ?: 'Adjunto') . ': ' . Media::url($m['media_url'], 7 * 86400);
            }
            $lines[] = $text;
        }
        if (!$lines) {
            throw new ApiError('La conversación no tiene mensajes');
        }
        $start = (new DateTimeImmutable('@' . intdiv((int) $c['created_at'], 1000)))->setTimezone($tz)->format('d/m/Y');
        // Introducción: la que escribe el agente (con variables) o, si la deja vacía, la predeterminada.
        $intro = trim(clean_text(self::param('intro'), 3000));
        if ($intro === '') {
            $intro = self::default_email_intro();
        }
        $intro = strtr($intro, array(
            '{nombre}'  => $c['name'],
            '{empresa}' => $company,
            '{fecha}'   => $start,
            '{agente}'  => $a['name'],
        ));
        $intro = preg_replace('/^Hola ,/u', 'Hola,', $intro); // sin nombre de cliente
        $body = $intro . "\n\n" . str_repeat('-', 50) . "\n\n" . implode("\n\n", $lines) . "\n\n" . str_repeat('-', 50) . "\n" . $company;
        $subject = 'Tu conversación con ' . $company . ' (' . $start . ')';
        // Un correo por destinatario: nadie ve las direcciones de los demás.
        $ok = array();
        $fail = array();
        foreach ($to as $e) {
            if (Mailer::send($e, $subject, $body)) {
                $ok[] = $e;
            } else {
                $fail[] = $e;
            }
        }
        if ($ok) {
            Core::note($c['id'], $a['name'] . ' envió la conversación por correo a ' . implode(', ', $ok), $a['id']);
        }
        if (!$ok) {
            throw new ApiError('No se pudo enviar el correo. Revisa la configuración de correo del servidor (config.php).', 502);
        }
        return array('ok' => true, 'sent' => $ok, 'failed' => $fail);
    }

    /** Texto predeterminado de la introducción del correo (con variables que se sustituyen al enviar). */
    public static function default_email_intro()
    {
        return "Hola {nombre},\n\nTe enviamos la transcripción de tu conversación con {empresa} del {fecha}.\nLos enlaces a archivos adjuntos caducan en 7 días.";
    }

    /* ---------------------------------------------------------- plantillas (solo recepción) */

    private static function require_receptionist()
    {
        $a = Auth::require_agent();
        Auth::require_perm($a, 'templates');
        return $a;
    }

    private static function template_out($t)
    {
        return array('id' => (int) $t['id'], 'title' => $t['title'], 'body' => $t['body'], 'updated_at' => (int) $t['updated_at']);
    }

    private static function templates_list()
    {
        self::require_receptionist();
        return array('templates' => array_map(array(__CLASS__, 'template_out'), DB::rows('SELECT * FROM templates ORDER BY title LIMIT 500')));
    }

    private static function template_save($id = null)
    {
        $a = self::require_receptionist();
        $title = clean_line(self::param('title'), 120);
        $body = clean_text(self::param('body'), 4000);
        if ($title === '' || $body === '') {
            throw new ApiError('La plantilla necesita un título y un texto');
        }
        if ($id) {
            if (!DB::val('SELECT id FROM templates WHERE id = ?', array((int) $id))) {
                throw new ApiError('Plantilla no encontrada', 404);
            }
            DB::update('templates', array('title' => $title, 'body' => $body, 'updated_at' => now_ms()), array('id' => (int) $id));
        } else {
            if ((int) DB::val('SELECT COUNT(*) FROM templates') >= 500) {
                throw new ApiError('Se ha alcanzado el máximo de 500 plantillas');
            }
            $id = DB::insert('templates', array('title' => $title, 'body' => $body, 'created_by' => (int) $a['id'], 'updated_at' => now_ms()));
        }
        return array('template' => self::template_out(DB::row('SELECT * FROM templates WHERE id = ?', array((int) $id))));
    }

    private static function template_delete($id)
    {
        self::require_receptionist();
        DB::delete('templates', array('id' => (int) $id));
        return array('ok' => true);
    }
}
