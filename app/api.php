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
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        if ($origin && in_array(rtrim($origin, '/'), (array) cfg('allowed_origins', array()), true)) {
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
            }
            $routes = array(
                array('GET', '#^/widget/config$#', 'widget_config'),
                array('GET', '#^/visitor/messages$#', 'visitor_messages'),
                array('POST', '#^/visitor/messages$#', 'visitor_send'),
                array('POST', '#^/visitor/upload$#', 'visitor_send'),
                array('GET', '#^/webhook/(whatsapp|facebook)$#', 'webhook_verify'),
                array('POST', '#^/webhook/(whatsapp|facebook)$#', 'webhook'),
                array('POST', '#^/auth/login$#', 'login'),
                array('POST', '#^/auth/logout$#', 'logout'),
                array('GET', '#^/me$#', 'me'),
                array('GET', '#^/sync$#', 'sync'),
                array('GET', '#^/conversations/(\d+)/messages$#', 'conv_messages'),
                array('POST', '#^/conversations/(\d+)/messages$#', 'conv_send'),
                array('POST', '#^/conversations/(\d+)/accept$#', 'conv_accept'),
                array('POST', '#^/conversations/(\d+)/transfer$#', 'conv_transfer'),
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
            'open'      => Hours::is_open(),
            'privacyUrl' => preg_match('#^https://#i', (string) $s['privacy_url']) ? $s['privacy_url'] : '',
            'es'        => array('title' => $s['widget_title_es'], 'welcome' => fill_vars($s['welcome_es']), 'privacy' => $s['privacy_es']),
            'en'        => array('title' => $s['widget_title_en'], 'welcome' => fill_vars($s['welcome_en']), 'privacy' => $s['privacy_en']),
        );
    }

    private static function visitor_token()
    {
        $t = (string) self::param('token');
        if (!preg_match('/^[a-zA-Z0-9\-]{20,64}$/', $t)) {
            throw new ApiError('Token no válido');
        }
        return $t;
    }

    private static function visitor_messages()
    {
        $c = Core::find('web', self::visitor_token());
        if (!$c) {
            return array('messages' => array(), 'open' => Hours::is_open());
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
        $media = null;
        if (!empty($_FILES['file'])) {
            if (!rate_limit('up', 10, 300)) {
                throw new ApiError('Demasiados archivos, espera unos minutos', 429);
            }
            $media = Media::store_upload($_FILES['file']);
        }
        $text = clean_text(self::param('text'));
        if ($text === '' && !$media) {
            throw new ApiError('Mensaje vacío');
        }
        // Límite de chats nuevos por IP: evita inundar a recepción con llamadas falsas.
        if (!Core::find('web', $token) && !rate_limit('newconv', 5, 3600)) {
            throw new ApiError('Demasiadas conversaciones nuevas desde tu conexión. Inténtalo más tarde.', 429);
        }
        $page = (string) self::param('page');
        $id = Core::incoming('web', $token, array(
            'lang'     => lang_norm(self::param('lang')),
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

    private static function me()
    {
        return array('agent' => Auth::out(Auth::require_agent()));
    }

    private static function visible_sql($a, $alias = '')
    {
        if (in_array($a['role'], array('admin', 'receptionist'), true)) {
            return '1=1';
        }
        return $alias . 'assigned_to = ' . (int) $a['id'];
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
        $reception = in_array($a['role'], array('admin', 'receptionist'), true);
        foreach (DB::rows("SELECT id, assigned_to FROM conversations WHERE status = 'open' AND accepted_at = 0 AND updated_at > ?", array($now - 7 * 86400000)) as $c) {
            if ((int) $c['assigned_to'] === (int) $a['id'] || (!(int) $c['assigned_to'] && $reception)) {
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
        if ($a['role'] === 'sales') {
            throw new ApiError('Solo recepción puede transferir', 403);
        }
        $to = Auth::agent((int) self::param('agent_id'));
        if (!$to || !(int) $to['active']) {
            throw new ApiError('Usuario de destino no válido');
        }
        Core::transfer($c, $a, $to, (string) self::param('note'));
        return array('conversation' => Core::conv_out(Core::conv($c['id'])));
    }
}
