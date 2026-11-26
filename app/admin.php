<?php
/** Panel web de administración: chat.bezares.com/admin */
class Admin
{
    const REMEMBER_COOKIE = 'bzc_remember';
    const REMEMBER_MS = 2592000000; // 30 días

    private static $me = null;

    /* ================================================================ utilidades */

    private static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function redirect($to)
    {
        header('Location: ' . $to);
        exit;
    }

    private static function csrf()
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = random_token(16);
        }
        return $_SESSION['csrf'];
    }

    private static function csrf_field()
    {
        return '<input type="hidden" name="csrf" value="' . self::csrf() . '">';
    }

    private static function check_csrf()
    {
        if (!isset($_POST['csrf']) || !hash_equals(self::csrf(), (string) $_POST['csrf'])) {
            http_response_code(400);
            exit('Formulario caducado: vuelve atrás y recarga la página.');
        }
    }

    private static function flash($msg = null, $bad = false)
    {
        if ($msg !== null) {
            $_SESSION['flash'] = array($msg, $bad);
            return '';
        }
        if (empty($_SESSION['flash'])) {
            return '';
        }
        list($m, $b) = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return self::alert($m, $b);
    }

    private static function alert($msg, $bad = false)
    {
        return '<div class="alert ' . ($bad ? 'alert-bad' : 'alert-ok') . '" role="' . ($bad ? 'alert' : 'status') . '">' . ($bad ? '⚠️ ' : '✓ ') . self::h($msg) . '</div>';
    }

    /** Botón que navega (formulario GET): la navegación usa botones, no enlaces. */
    private static function nav_button($label, $action, $class = 'btn btn-secondary', $params = array())
    {
        $hidden = '';
        foreach ($params as $k => $v) {
            $hidden .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        return '<form method="get" action="' . self::h($action) . '" class="inline">' . $hidden . '<button type="submit" class="' . $class . '">' . $label . '</button></form>';
    }

    /** Campo de contraseña con botón para mostrarla u ocultarla (admin.js). */
    private static function password_input($name, $attrs = '')
    {
        $id = 'pw_' . preg_replace('/\W/', '', $name);
        return '<div class="pw"><input type="password" id="' . $id . '" name="' . self::h($name) . '" ' . $attrs . '>'
            . '<button type="button" class="pw-toggle" data-target="' . $id . '" aria-label="Mostrar contraseña" aria-pressed="false">Mostrar</button></div>';
    }

    private static function set_remember_cookie($value, $expires)
    {
        setcookie(self::REMEMBER_COOKIE, $value, array(
            'expires'  => $expires,
            'path'     => '/admin',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ));
    }

    private static function start_admin_session($agent)
    {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $agent['id'];
        $_SESSION['pw'] = self::pass_mark($agent);
        $_SESSION['last'] = time();
    }

    /** Huella de la contraseña: si cambia (restablecer, página Usuarios), las sesiones del panel abiertas con la anterior dejan de valer. */
    private static function pass_mark($agent)
    {
        return hash('sha256', 'bzc-admin-session|' . (string) $agent['pass_hash']);
    }

    private static function me()
    {
        if (self::$me !== null) {
            return self::$me ?: null;
        }
        $a = !empty($_SESSION['admin_id']) ? Auth::agent($_SESSION['admin_id']) : null;
        if ($a && !hash_equals(self::pass_mark($a), (string) ($_SESSION['pw'] ?? ''))) {
            $_SESSION = array(); // la contraseña ha cambiado desde que se abrió esta sesión
            session_regenerate_id(true);
            $a = null;
        }
        // Sesión recordada: cookie con token de un solo agente (se revoca al cambiar la contraseña).
        if (!$a && !empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $a = Auth::agent_by_token($_COOKIE[self::REMEMBER_COOKIE]);
            if ($a && Roles::can($a, 'admin_panel')) {
                self::start_admin_session($a);
            } else {
                self::set_remember_cookie('', time() - 3600);
                $a = null;
            }
        }
        self::$me = ($a && (int) $a['active'] && Roles::can($a, 'admin_panel')) ? $a : false;
        return self::$me ?: null;
    }

    /* ================================================================ enrutado */

    public static function handle($method)
    {
        session_name('bzc_admin');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(array('lifetime' => 0, 'path' => '/admin', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict'));
        session_start();
        header('Cache-Control: no-store');
        // Solo se permite el JavaScript propio (admin.js: botón "Mostrar contraseña").
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors " . frame_ancestors() . "; base-uri 'none'");

        // Caducidad por inactividad (2 h) de la sesión sin "recordar"
        if (!empty($_SESSION['admin_id']) && time() - (int) ($_SESSION['last'] ?? 0) > 7200) {
            $_SESSION = array();
            session_regenerate_id(true);
        }
        if (!empty($_SESSION['admin_id'])) {
            $_SESSION['last'] = time();
        }

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($path === '/' || $path === '/index.php') {
            self::redirect('/admin/');
        }
        $page = trim(preg_replace('#^/admin#', '', $path), '/');

        try {
            DB::install();
            $hasAgents = (int) DB::val('SELECT COUNT(*) FROM agents') > 0;
        } catch (PDOException $e) {
            log_error('BD: ' . $e->getMessage());
            self::auth_page('Error', self::alert('No se puede conectar a la base de datos. Revisa config.php (el detalle está en el log de errores del servidor).', true));
        }
        if (!$hasAgents) {
            self::setup($method);
        }

        switch ($page) {
            case 'login':
                self::login($method);
                break;
            case 'forgot':
                self::forgot($method);
                break;
            case 'reset':
                self::reset($method);
                break;
            case 'alta':
                self::signup($method);
                break;
            case 'confirmar':
                self::signup_confirm($method);
                break;
            case 'logout':
                if ($method === 'POST') {
                    self::check_csrf();
                    if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
                        Auth::revoke_token($_COOKIE[self::REMEMBER_COOKIE]);
                        self::set_remember_cookie('', time() - 3600);
                    }
                    $_SESSION = array();
                    session_destroy();
                }
                self::redirect('/admin/login');
        }

        $me = self::me();
        if (!$me) {
            self::redirect('/admin/login');
        }
        switch ($page) {
            case 'robot':
                self::robot_page($method, $me);
                break;
            case 'ai':
                self::ai_page($method, $me);
                break;
            case 'settings':
                self::settings($method, $me);
                break;
            case 'test':
                if ($method === 'POST') {
                    self::test_post($me);
                }
                self::test_tab($me);
                break;
            case 'users':
                self::users($method, $me);
                break;
            case 'chats':
                self::chats($me);
                break;
            default:
                self::stats($me);
        }
    }

    /* ================================================================ plantillas */

    private static function css()
    {
        return <<<CSS
/* Paleta de Bezares Expo (assets/admin.css): mantener ambas iguales. --brand-t es el tono claro de las gráficas. */
:root{--brand:#00567d;--brand-d:#003f5c;--brand-l:#e6f1f7;--brand-t:#b9d9e8;--sky:#009fe3;--bg:#f3f5f9;--card:#fff;--line:#e3e7ee;--text:#1b2230;--muted:#667085;--ok:#1a7f37;--ok-l:#ddf3e5;--bad:#c62828;--bad-l:#fde3e5;--warn:#b54708;--warn-l:#fff1d6;--r:12px;--shadow:0 1px 2px rgba(16,24,40,.06),0 4px 16px rgba(16,24,40,.06);color-scheme:light}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{font:15px/1.5 "Segoe UI",system-ui,-apple-system,Roboto,sans-serif;background:var(--bg);color:var(--text)}
h1{font-size:24px;margin:0}
h2{font-size:17px;margin:0 0 14px}
small,.muted{color:var(--muted);font-size:13px}
.inline{display:inline;margin:0}
/* Botones */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid transparent;border-radius:10px;padding:10px 18px;font-family:inherit;font-size:14px;font-weight:600;line-height:1.2;cursor:pointer;transition:background .15s,box-shadow .15s,transform .05s;white-space:nowrap}
.btn:active{transform:translateY(1px)}
.btn:focus-visible{outline:3px solid #9cc3ef;outline-offset:2px}
.btn-primary{background:var(--brand);color:#fff}
.btn-primary:hover{background:var(--brand-d)}
.btn-secondary{background:#fff;color:var(--text);border-color:var(--line)}
.btn-secondary:hover{background:#f6f8fb}
.btn-danger{background:var(--bad);color:#fff}
.btn-danger:hover{background:#a51f1f}
.btn-ghost{background:transparent;color:var(--brand)}
.btn-ghost:hover{background:var(--brand-l)}
.btn-sm{padding:6px 12px;font-size:13px;border-radius:8px}
.btn-block{width:100%}
/* Estructura con barra lateral */
.layout{display:flex;min-height:100vh}
.side{width:240px;background:linear-gradient(180deg,var(--brand) 0%,var(--brand-d) 100%);color:#fff;display:flex;flex-direction:column;padding:22px 14px;position:sticky;top:0;height:100vh}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;padding:0 8px 22px}
.logo{width:38px;height:38px;display:block;flex:none}
.nav{display:flex;flex-direction:column;gap:4px}
.nav .btn{width:100%;justify-content:flex-start;background:transparent;color:#d6e6ef;border:0;padding:11px 14px;font-weight:500}
.nav .btn:hover{background:rgba(255,255,255,.12);color:#fff}
.nav .btn.on{background:#fff;color:var(--brand);font-weight:700;box-shadow:var(--shadow)}
.side-foot{margin-top:auto;border-top:1px solid rgba(255,255,255,.2);padding:14px 8px 0}
.side-foot .who{font-weight:600}
.side-foot .role{font-size:12px;color:#c4dbe7;margin-bottom:10px}
.side-foot .btn{width:100%;background:rgba(255,255,255,.12);color:#fff;border:0}
.side-foot .btn:hover{background:rgba(255,255,255,.22)}
.main{flex:1;min-width:0;padding:28px 32px 60px;max-width:1180px}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.page-head p{margin:4px 0 0;color:var(--muted)}
/* Tarjetas, avisos */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);padding:22px;margin-bottom:18px}
.alert{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-weight:500}
.alert-ok{background:var(--ok-l);color:#0b5a2b}
.alert-bad{background:var(--bad-l);color:#8a1020}
/* Formularios */
.field{margin-bottom:18px}
.field>label,.label{display:block;font-weight:600;font-size:14px;margin-bottom:6px}
.field small{display:block;margin-top:5px}
input[type=text],input[type=email],input[type=password],input[type=number],input[type=date],input[type=time],textarea,select{width:100%;padding:10px 12px;border:1px solid #d3d9e3;border-radius:10px;font:inherit;background:#fff;color:inherit;transition:border-color .15s,box-shadow .15s}
input:focus,textarea:focus,select:focus{outline:0;border-color:var(--brand);box-shadow:0 0 0 3px #d3e6f0}
textarea{min-height:70px;resize:vertical}
input[type=number]{max-width:140px}
input[type=color]{width:60px;height:40px;border:1px solid #d3d9e3;border-radius:10px;padding:3px;background:#fff}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 22px}
.grid2 .full{grid-column:1/-1}
/* Contraseña con botón mostrar */
.pw{position:relative}
.pw input{padding-right:92px}
.pw-toggle{display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:var(--brand-l);color:var(--brand);font-family:inherit;font-size:12px;font-weight:600;border-radius:8px;padding:6px 10px;cursor:pointer}
.js .pw-toggle{display:block}
/* Interruptor (checkbox) */
.switch{display:flex;align-items:flex-start;gap:12px;cursor:pointer;margin-bottom:18px}
.switch input{appearance:none;-webkit-appearance:none;flex:none;width:44px;height:24px;border-radius:12px;background:#cfd6e1;position:relative;cursor:pointer;transition:background .2s;margin:2px 0 0}
.switch input::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .2s}
.switch input:checked{background:var(--brand)}
.switch input:checked::after{left:23px}
.switch input:focus-visible{outline:3px solid #9cc3ef;outline-offset:2px}
.switch b{display:block;font-size:14px}
.check{display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer}
.check input{width:18px;height:18px;accent-color:var(--brand)}
/* Pestañas (botones) */
.tabs{display:flex;flex-wrap:wrap;gap:6px;background:#e9edf3;padding:5px;border-radius:12px;margin-bottom:18px}
.tabs .btn{background:transparent;border:0;color:var(--muted);font-weight:600;padding:9px 14px}
.tabs .btn:hover{color:var(--text);background:rgba(255,255,255,.6)}
.tabs .btn.on{background:#fff;color:var(--brand);box-shadow:var(--shadow)}
.savebar{position:sticky;bottom:0;background:rgba(243,245,249,.92);backdrop-filter:blur(6px);padding:14px 0;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid var(--line);margin-top:6px}
.card .savebar{background:rgba(255,255,255,.95);margin:6px -22px -22px;padding:14px 22px;border-radius:0 0 var(--r) var(--r)}
.roles th,.roles td{vertical-align:top}
.roles .role-col{text-align:center;min-width:130px}
.roles .role-col input[type=text]{text-align:center;font-weight:600;padding:6px 8px}
.roles .role-col small{display:block;margin-top:4px;font-weight:400;text-transform:none;letter-spacing:0}
.roles input.perm{width:20px;height:20px;accent-color:var(--brand);cursor:pointer}
.del-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid var(--line);margin:0}
.del-row:last-child{border-bottom:0}
.del-row span{flex:1}
.tconv{max-height:420px;overflow-y:auto;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px}
.tmsg{max-width:75%;padding:8px 12px;border-radius:10px;background:#fff;border:1px solid var(--line);font-size:14px;overflow-wrap:anywhere}
.tmsg .tw{font-size:11px;font-weight:600;color:var(--muted);margin-bottom:2px}
.t-in{align-self:flex-start}
.t-out{align-self:flex-end;background:var(--brand-l);border-color:var(--brand-t)}
.t-bot{align-self:flex-end;background:#eef1f5;border-style:dashed}
.t-system{align-self:center;background:#eef1f5;font-size:12px;color:var(--muted)}
.t-sep{align-self:center;background:#e3f1fa;border-color:#e3f1fa;color:#0b4a6b;font-size:12px;font-weight:600;border-radius:999px;scroll-margin-top:12px}
.tmsg img{display:block;max-width:240px;max-height:200px;border-radius:8px;margin-top:4px}
.tmsg .err{color:var(--bad);font-size:12px;font-weight:600}
.tconv.tall{max-height:72vh}
.chat-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:14px 16px}
.chat-bar .field{margin:0;min-width:0}
.chat-bar label{font-size:13px;margin-bottom:4px}
.chat-bar .q{flex:1 1 100%}
.chat-bar input[type=date]{width:150px}
.chat-bar select{width:auto;min-width:130px}
.chat-row .prev-line{color:var(--muted);font-size:13px;overflow-wrap:anywhere}
.pager{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px}
.facts{margin:0 0 16px;display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:14px}
.facts dt{color:var(--muted)}.facts dd{margin:0;overflow-wrap:anywhere}
.chat-list{list-style:none;margin:0;padding:0}
.chat-list li{border-top:1px solid var(--line);padding:10px 0;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:14px}
a.btn{text-decoration:none}
.test-banner{background:var(--warn-l);border:1px solid #f5c27a;color:#7a4100;border-radius:10px;padding:10px 14px;margin-bottom:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.card.test-on{border-color:#f5c27a;background:#fffaf2}
.guide{margin-bottom:20px}
.guide details{border:1px solid var(--line);border-radius:10px;margin-bottom:8px;background:#fafbfd}
.guide summary{cursor:pointer;padding:11px 14px;font-weight:600}
.guide summary:hover{background:var(--brand-l);border-radius:10px}
.guide ol{margin:0;padding:4px 18px 12px 38px}
.guide li{margin-bottom:6px;line-height:1.5}
.guide code{background:#eef2f7;padding:2px 6px;border-radius:5px;font-size:13px;word-break:break-all}
.info-box{background:#e3f1fa;color:#0b4a6b;border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:14px;word-break:break-word}
/* Tablas */
.table-wrap{overflow-x:auto;margin:0 -22px;padding:0 22px}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
tr:last-child td{border-bottom:0}
td.actions{text-align:right;white-space:nowrap}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
.b-admin{background:#efe7fb;color:#5b2ca0}.b-receptionist{background:#e3f1fa;color:#0b4a6b}.b-sales{background:var(--ok-l);color:#0b5a2b}
.b-custom{background:var(--warn-l);color:var(--warn)}
.b-on{background:var(--ok-l);color:#0b5a2b}.b-off{background:#eef0f3;color:var(--muted)}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#cfd6e1;margin-right:8px}.dot.on{background:#22c55e;box-shadow:0 0 0 3px #dcfce7}
.hours td{padding:8px 10px}
.hours input[type=time]{width:auto}
.split{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(320px,1fr);gap:18px;align-items:start}
@media (max-width:1250px){.split{grid-template-columns:1fr}}
/* AI */
.ai-add{border:1px solid var(--line);border-radius:10px;margin-bottom:8px;background:#fafbfd}
.ai-add summary{padding:12px 14px;font-weight:600;cursor:pointer}
.ai-add>form{padding:4px 14px 14px}
.ai-res td:first-child{overflow-wrap:anywhere}
.ai-ans{white-space:pre-wrap;overflow-wrap:anywhere;background:#e8f7f8;border:1px solid #7cc4cb;border-radius:10px;padding:12px 14px;margin:12px 0 6px}
.up{margin-top:14px}
.up-bar{height:10px;border-radius:5px;background:#e3e8ef;overflow:hidden}
.up-bar i{display:block;height:100%;width:0;border-radius:5px;background:var(--brand);transition:width .2s}
.up-wait .up-bar i{width:35%;animation:up-slide 1.4s infinite ease-in-out}
.up-bad .up-bar i{background:var(--bad);width:100%}
.up-row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:6px;font-size:14px}
.up-bad .up-row{color:#8a1020}
@keyframes up-slide{from{margin-left:-35%}to{margin-left:100%}}
@media (prefers-reduced-motion:reduce){.up-wait .up-bar i{width:100%;animation:none;opacity:.5}}
.ai-acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;align-items:center}
/* Comprobación de permisos de Meta */
.mc-h{font-size:15px;margin:18px 0 8px}
.mc{list-style:none;margin:0;padding:0}
.mc li{display:flex;gap:10px;padding:8px 10px;border-radius:8px;font-size:14px;overflow-wrap:anywhere}
.mc li>div{min-width:0}
.mc small{display:block;margin-top:3px;color:inherit}
.mc-bad{background:var(--bad-l);color:#8a1020}
.mc-warn{background:var(--warn-l);color:#7a4100}
.mc li+li{margin-top:4px}
.mc-use{margin:16px 0 8px;font-size:14px}
#reenviar input[type=checkbox]{width:20px;height:20px;accent-color:var(--brand);cursor:pointer}
/* Robot: lienzo con líneas */
.main:has(#flow){max-width:none}
.fl-state{font-size:14px;margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.fl-state-a{margin-left:auto}
.fl-bar{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:10px;font-size:13px;color:var(--muted)}
.fl-bar .btn.on{background:var(--brand);color:#fff}
.fl-bar .btn:disabled{opacity:.45;cursor:default}
.fl-sep{width:1px;height:22px;background:var(--line);margin:0 4px}
.fl-warn{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.fl-warn[hidden],.fl-edit[hidden],.fl-sim[hidden]{display:none}
.fl-w{border:0;border-radius:8px;background:var(--warn-l);color:#7a4100;font:inherit;font-size:13px;padding:4px 10px;cursor:pointer;text-align:left}
.fl-soft{background:#eef1f5;color:var(--muted)}
.fl-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:14px;align-items:stretch}
.fl-canvas{height:max(460px,calc(100vh - 330px));overflow:auto;border:1px solid var(--line);border-radius:var(--r);background:#fbfcfe radial-gradient(#dfe5ee 1px,transparent 1px) 0 0/16px 16px;touch-action:none;cursor:grab;user-select:none}
.fl-size{position:relative;min-width:100%;min-height:100%}
.fl-world{position:absolute;left:0;top:0;transform-origin:0 0}
.fl-lines{position:absolute;left:0;top:0;overflow:visible}
.fl-vis{fill:none;stroke:#8a97a8;stroke-width:2;marker-end:url(#fl-arrow)}
.fl-dash .fl-vis{stroke:#d08a2a;stroke-dasharray:6 4}
.fl-hit{fill:none;stroke:transparent;stroke-width:16;cursor:pointer}
.fl-line:hover .fl-vis,.fl-lsel .fl-vis{stroke:var(--bad);stroke-width:3}
.fl-tmp{fill:none;stroke:var(--brand);stroke-width:2;stroke-dasharray:6 4}
.fl-n{position:absolute;width:190px;background:#fff;border:1.5px solid var(--brand-t);border-radius:10px;padding:8px 10px 10px;font-size:13px;line-height:1.3;cursor:grab;box-shadow:0 1px 3px rgba(16,24,40,.08)}
.fl-n b{display:block;font-size:13.5px;overflow-wrap:anywhere}
.fl-n span{color:var(--muted);font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
.fl-say{background:var(--brand-l)}
.fl-ask{background:#e9f7ee;border-color:#a9d8b8}
.fl-cond{background:#fff8ec;border-color:#e0b060;border-style:dashed}
.fl-start,.fl-end{background:#f3f5f9;border-color:#b9c2cf}
.fl-timer{background:#f4eefc;border-color:#c9b3ea}
.fl-ai{background:#e8f7f8;border-color:#7cc4cb}
.fl-timers{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.fl-timers:empty{display:none}
.fl-n:focus-visible{outline:3px solid #9cc3ef;outline-offset:2px}
.fl-sel{border-color:var(--brand);box-shadow:0 0 0 3px #d3e6f0}
.fl-bad{border-color:var(--bad);border-style:solid}
.fl-lost{opacity:.65}
.fl-over{box-shadow:0 0 0 3px #bfe6cc;border-color:var(--ok)}
.fl-live{box-shadow:0 0 0 4px #ffe1a8}
.fl-ports{display:flex;margin:6px -6px -18px;gap:2px}
.fl-port{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px}
.fl-port em{font-style:normal;font-size:11px;color:var(--muted);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fl-n i{width:16px;height:16px;border-radius:50%;background:var(--brand);border:3px solid #fff;box-shadow:0 0 0 1.5px var(--brand);cursor:crosshair;flex:none}
.fl-n i.fl-free{background:#fff}
.fl-n i:hover{transform:scale(1.25)}
.fl-n i.fl-no{position:absolute;right:-9px;top:50%;margin-top:-8px;background:#d08a2a;box-shadow:0 0 0 1.5px #d08a2a}
.fl-n i.fl-opt{background:#fff}
.fl-menu{position:absolute;display:flex;flex-direction:column;gap:4px;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:6px;z-index:5}
.fl-side{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:18px;overflow-y:auto;height:max(460px,calc(100vh - 330px))}
.fl-side h2{margin-bottom:8px}
.fl-f{display:block;margin-bottom:14px}
.fl-f small:empty{display:none}
.fl-h3{font-size:14px;margin:18px 0 10px}
.fl-help{padding-left:18px;line-height:1.5;font-size:14px}
.fl-help li{margin-bottom:6px}
.fl-more{border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin:14px 0}
.fl-more summary{cursor:pointer;font-weight:600;font-size:14px}
.fl-more[open] summary{margin-bottom:12px}
.fl-sim{height:100%;display:flex;flex-direction:column}
.fl-sim .grid2{gap:0 10px}.fl-sim .field{margin-bottom:10px}
.fl-log{flex:1;min-height:160px;overflow-y:auto;background:#f3f5f9;border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
.fl-b{max-width:88%;padding:7px 10px;border-radius:10px;font-size:14px;white-space:pre-wrap;overflow-wrap:anywhere}
.fl-bot{background:#fff;border:1px solid #e3e7ee;align-self:flex-start}
.fl-me{background:var(--brand);color:#fff;align-self:flex-end}
.fl-note{align-self:center;font-size:12px;color:var(--muted);padding:2px 6px;text-align:center}
.fl-send{display:flex;gap:6px}
.fl-send .btn{flex:none}
@media (max-width:1100px){.fl-grid{grid-template-columns:1fr}.fl-side{height:auto;max-height:none}.fl-log{min-height:260px;max-height:420px}}
/* Robot: diagrama de flujo y editor */
.rb{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(340px,1fr);gap:18px;align-items:start}
.rb>.card{margin:0}
.rb-flow{display:flex;flex-direction:column}
.rb-n{display:block;width:100%;padding:10px 12px;border:1.5px solid var(--brand-t);border-radius:10px;background:var(--brand-l);color:var(--text);font:inherit;font-size:14px;line-height:1.3;text-align:center}
button.rb-n{cursor:pointer}
button.rb-n:hover{border-color:var(--brand)}
button.rb-n:focus-visible{outline:3px solid #9cc3ef;outline-offset:2px}
.rb-n b{display:block;font-weight:600}
.rb-n small{display:block;margin-top:2px}
.rb-n em{display:none;font-style:normal;font-size:12px;color:var(--bad);margin-top:2px}
.rb-start{background:#f3f5f9;border:1.5px dashed #b9c2cf;color:var(--muted);font-weight:600;width:auto;min-width:62%;margin:0 auto}
.rb-q{background:#fff8ec;border:1.5px dashed #e0b060}
.rb-off{background:#fff;border-style:dashed;border-color:#c9d0da;color:var(--muted)}
.rb-off em{display:block}
.js .rb-sel,.js .rb-sel:hover{border-color:var(--brand);box-shadow:0 0 0 3px #d3e6f0}
.rb-v{width:2px;height:16px;background:#c3cbd6;margin:0 auto}
.rb-fork{height:20px;position:relative}
.rb-fork::before{content:"";position:absolute;left:50%;top:0;width:2px;height:10px;margin-left:-1px;background:#c3cbd6}
.rb-fork::after{content:"";position:absolute;left:calc(25% - 4px);right:calc(25% - 4px);top:10px;height:10px;border:2px solid #c3cbd6;border-bottom:0;border-radius:8px 8px 0 0}
.rb-join::before{top:10px}
.rb-join::after{top:0;border:2px solid #c3cbd6;border-top:0;border-radius:0 0 8px 8px}
.rb-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.rb-2>div{min-width:0;display:flex;flex-direction:column}
.rb-tight{gap:8px}
.rb-sub::after{left:calc(25% - 2px);right:calc(25% - 2px)}
.rb-tight .rb-n{padding:10px 6px;font-size:13px}
.rb-line{flex:1;width:2px;min-height:8px;background:#c3cbd6;margin:0 auto}
.rb-own{background:#e9f7ee;border-color:#a9d8b8}
button.rb-own{cursor:grab}
button.rb-own:hover{border-color:var(--ok)}
.rb-own.rb-off{background:#fff;border-color:#c9d0da}
.rb-add{position:relative;display:flex;flex-direction:column;align-items:center;padding:5px 0;background:linear-gradient(#c3cbd6,#c3cbd6) center/2px 100% no-repeat}
.rb-last{background:linear-gradient(#c3cbd6,#c3cbd6) center top/2px 8px no-repeat;padding-top:8px}
.rb-plus{width:24px;height:24px;border-radius:50%;border:1.5px solid #a9d8b8;background:#fff;color:var(--ok);font:700 16px/1 inherit;padding:0 0 2px;cursor:pointer}
.rb-plus:hover,.rb-over .rb-plus{background:var(--ok);border-color:var(--ok);color:#fff}
.rb-plus:focus-visible{outline:3px solid #9cc3ef;outline-offset:2px}
.rb-drag .rb-plus{transform:scale(1.25);border-style:dashed}
.rb-menu{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:6px;margin-top:5px;z-index:2}
.rb-acts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.rb-acts .btn:disabled{opacity:.45;cursor:default}
.rb-time{display:flex;gap:8px;align-items:center}
.rb-time select{width:auto}
.no-js .rb-acts,.no-js #step-custom{display:none}
[data-row][hidden]{display:none!important}
.rb-l{font-size:12px;font-weight:600;color:var(--muted);text-align:center;padding:1px 0 5px}
.rb-hint{margin:16px 0 0;text-align:center}
.rb-edit{position:sticky;top:18px;max-height:max(440px,calc(100vh - 134px));overflow-y:auto}
.card.rb-edit{padding-bottom:0}
.card.rb-edit .savebar{margin-bottom:0;background:#fff;z-index:1}
.rb-step{margin-bottom:26px}
.js .rb-step{display:none;margin:0}
.js .rb-step.rb-show{display:block}
.rb-step h2{margin-bottom:4px}
.rb-step>.muted{margin:0 0 16px}
.rb-msg+.rb-msg{border-top:1px solid var(--line);padding-top:16px;margin-top:18px}
.rb-msg h3{font-size:14px;margin:0 0 10px}
.rb-msg .field{margin-bottom:10px}
.rb-vars{display:none;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.js .rb-vars{display:flex}
.rb-prev{display:none;background:#f3f5f9;border-radius:10px;padding:10px 12px;margin-bottom:6px}
.js .rb-prev{display:block}
.rb-prev span{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
.rb-prev div{background:#fff;border:1px solid #e3e7ee;border-radius:12px 12px 12px 4px;padding:8px 11px;font-size:14px;max-width:92%;white-space:pre-wrap;overflow-wrap:anywhere}
.rb-prev div:empty::before{content:"(vacío: no se envía)";color:var(--muted)}
.rb-dirty{margin-right:auto;align-self:center;font-size:13px;font-weight:600;color:var(--warn)}
.rb .hours td,.rb .hours th{padding:6px 4px}
.rb .hours input[type=time]{padding:7px 6px}
@media (max-width:1150px){.rb{grid-template-columns:1fr}.rb-edit{position:static;max-height:none}}
@media (max-width:520px){.rb-2{gap:8px}.rb-n{padding:9px 6px;font-size:13px}}
/* Estadísticas */
.filters{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
.filters .field{margin:0}
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:18px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);padding:18px;border-top:4px solid var(--brand)}
.kpi.ok{border-top-color:var(--ok)}.kpi.bad{border-top-color:var(--bad)}.kpi.warn{border-top-color:#f59e0b}
.kpi .v{font-size:30px;font-weight:700;line-height:1.1}
.kpi .l{color:var(--muted);font-size:13px;margin-top:4px}
.kpi .s{font-size:13px;margin-top:8px;font-weight:600;color:var(--brand)}
.bars{display:flex;align-items:flex-end;gap:4px;height:180px;padding-top:10px}
.bar{flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%;align-items:center;min-width:6px}
.bar i{display:block;width:100%;background:var(--brand-t);border-radius:4px 4px 0 0;position:relative;min-height:2px}
.bar i b{position:absolute;bottom:0;left:0;right:0;background:var(--brand);border-radius:4px 4px 0 0}
.bar span{font-size:10px;color:var(--muted);margin-top:4px}
.legend{display:flex;gap:16px;font-size:13px;color:var(--muted);margin-top:10px;flex-wrap:wrap}
.kpi .d{font-size:12px;margin-top:6px;color:var(--muted)}.kpi .d.good{color:var(--ok);font-weight:600}.kpi .d.worse{color:var(--bad);font-weight:600}
.bar .pair{flex:1;min-height:0;width:100%;display:flex;align-items:flex-end;gap:1px}
.bar .pair i{flex:1;min-width:0}
.bar i.cmp{background:#e3e7ee}.bar i.cmp b{background:#98a2b3}.bar i.empty{background:none;min-height:0}
.bars.dense{gap:1px}.bars.dense .bar span{font-size:8px}
.stat-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:14px 16px}
.stat-bar .field{margin:0;min-width:0}
.stat-bar label{font-size:13px;margin-bottom:4px}
.stat-bar select{min-width:190px}
.stat-bar .thr input{width:90px}
.stat-bar .dates input{width:150px}
.stat-bar .dates{display:none}
.stat-bar:has(#p option[value="custom"]:checked) .dates-a,.stat-bar:has(#c option[value="custom"]:checked) .dates-b{display:block}
.stat-range{flex-basis:100%;display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:12px;color:var(--muted)}
th.prev,td.prev{color:var(--muted);font-size:13px}
.sw{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:middle}
/* Páginas de acceso */
.auth{min-height:100vh;display:grid;place-items:center;padding:24px;background:var(--bg)}
.auth-card{width:100%;max-width:420px;background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,40,60,.12);padding:36px 34px}
.auth-card .logo{width:64px;height:64px;margin:0 auto 12px}
.auth-card h1{text-align:center;font-size:22px}
.auth-card .sub{text-align:center;color:var(--muted);margin:6px 0 26px}
.auth-row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:-4px 0 20px;flex-wrap:wrap}
.auth-alt{text-align:center;margin-top:18px}
@media (max-width:900px){
  .layout{flex-direction:column}
  .side{width:auto;height:auto;position:static;flex-direction:row;flex-wrap:wrap;align-items:center;padding:12px 14px;gap:8px}
  .brand{padding:0 8px 0 0}
  .nav{flex-direction:row;flex-wrap:wrap}
  .nav .btn{width:auto}
  .side-foot{margin:0 0 0 auto;border:0;padding:0;display:flex;align-items:center;gap:10px}
  .side-foot .role,.side-foot .who{display:none}
  .main{padding:20px 16px 50px}
  .split,.grid2{grid-template-columns:1fr}
}
CSS;
    }

    private static function head($title)
    {
        return '<!doctype html><html lang="es" class="no-js"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="referrer" content="no-referrer"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><title>' . self::h($title) . ' · Bezares Chat</title><style>' . self::css() . '</style>'
            . '<script src="/admin.js?v=' . (int) @filemtime(BZC_ROOT . '/public/admin.js') . '" defer></script></head>';
    }

    /** Página interna con barra lateral. */
    private static function layout($title, $subtitle, $body, $me, $headActions = '')
    {
        $cur = trim(preg_replace('#^/admin#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
        $items = array('' => '📊  Estadísticas', 'chats' => '💬  Chats', 'robot' => '🤖  Robot', 'ai' => '✨  AI', 'settings' => '⚙️  Configuración', 'users' => '👥  Usuarios', 'test' => '🧪  Pruebas');
        if (!self::can_chats($me)) {
            unset($items['chats']);
        }
        $nav = '';
        foreach ($items as $k => $l) {
            $nav .= self::nav_button($l, '/admin/' . $k, 'btn' . ($cur === $k ? ' on' : ''));
        }
        echo self::head($title) . '<body><div class="layout">'
            . '<aside class="side"><div class="brand"><img class="logo" src="/brand/bezares-chat-icon-dark.svg" alt="" width="38" height="38">Bezares Chat</div><nav class="nav" aria-label="Menú">' . $nav . '</nav>'
            . '<div class="side-foot"><div class="who">' . self::h($me['name']) . '</div><div class="role">' . self::h(Roles::name($me['role'])) . '</div>'
            . '<form method="post" action="/admin/logout" class="inline">' . self::csrf_field() . '<button class="btn btn-sm">Cerrar sesión</button></form></div></aside>'
            . '<main class="main"><div class="page-head"><div><h1>' . self::h($title) . '</h1>' . ($subtitle ? '<p>' . self::h($subtitle) . '</p>' : '') . '</div>' . $headActions . '</div>'
            . self::test_banner() . self::flash() . $body . '</main></div></body></html>';
        exit;
    }

    /** Página de acceso (login, recuperar, instalación). */
    private static function auth_page($title, $body, $sub = '')
    {
        echo self::head($title) . '<body><div class="auth"><div class="auth-card"><img class="logo" src="/brand/bezares-chat-icon-light.svg" alt="Bezares Chat" width="64" height="64"><h1>' . self::h($title) . '</h1>'
            . '<p class="sub">' . self::h($sub ?: 'Bezares Chat · Administración') . '</p>' . self::flash() . $body . '</div></div></body></html>';
        exit;
    }

    /* ================================================================ instalación */

    private static function setup($method)
    {
        $err = '';
        if ($method === 'POST') {
            self::check_csrf();
            if (cfg('setup_key') === 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA' || !hash_equals((string) cfg('setup_key'), (string) ($_POST['setup_key'] ?? ''))) {
                $err = 'Clave de instalación incorrecta (setup_key de config.php; no puede ser la de ejemplo).';
            } else {
                try {
                    $a = Auth::save(array('name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'password' => $_POST['password'] ?? '', 'role' => 'admin'));
                    self::start_admin_session($a);
                    self::flash('Instalación completada. Revisa la configuración.');
                    self::redirect('/admin/settings');
                } catch (ApiError $e) {
                    $err = $e->getMessage();
                }
            }
        }
        self::auth_page('Instalación', ($err ? self::alert($err, true) : '')
            . '<form method="post">' . self::csrf_field()
            . '<p class="muted">Base de datos lista. Crea el primer usuario <b>Administrador</b>.</p>'
            . '<div class="field"><label for="pw_setup_key">Clave de instalación (setup_key)</label>' . self::password_input('setup_key', 'required') . '</div>'
            . '<div class="field"><label for="n">Nombre</label><input id="n" type="text" name="name" required></div>'
            . '<div class="field"><label for="e">Correo</label><input id="e" type="email" name="email" required autocomplete="username"></div>'
            . '<div class="field"><label for="pw_password">Contraseña</label>' . self::password_input('password', 'required minlength="10" autocomplete="new-password"') . '<small>Mínimo 10 caracteres, con letras y números.</small></div>'
            . '<button class="btn btn-primary btn-block">Crear administrador</button></form>', 'Primera puesta en marcha');
    }

    /* ================================================================ acceso */

    private static function login($method)
    {
        if (self::me()) {
            self::redirect('/admin/');
        }
        $err = '';
        $email = '';
        if ($method === 'POST') {
            self::check_csrf();
            $email = (string) ($_POST['email'] ?? '');
            try {
                $a = Auth::check_credentials($email, $_POST['password'] ?? '');
                if (!Roles::can($a, 'admin_panel')) {
                    throw new ApiError('Solo los administradores pueden entrar en el panel. Los agentes usan el programa de Windows.');
                }
                self::start_admin_session($a);
                if (!empty($_POST['remember'])) {
                    $token = Auth::issue_token($a['id'], self::REMEMBER_MS);
                    self::set_remember_cookie($token, time() + intdiv(self::REMEMBER_MS, 1000));
                }
                self::redirect('/admin/');
            } catch (ApiError $e) {
                $err = $e->getMessage();
            }
        }
        self::auth_page('Iniciar sesión', ($err ? self::alert($err, true) : '')
            . '<form method="post" novalidate>' . self::csrf_field()
            . '<div class="field"><label for="email">Correo electrónico</label><input id="email" type="email" name="email" required autofocus autocomplete="username" value="' . self::h($email) . '"></div>'
            . '<div class="field"><label for="pw_password">Contraseña</label>' . self::password_input('password', 'required autocomplete="current-password"') . '</div>'
            . '<div class="auth-row"><label class="check"><input type="checkbox" name="remember" value="1"> Recordar sesión 30 días</label></div>'
            . '<button class="btn btn-primary btn-block">Entrar</button></form>'
            . '<div class="auth-alt">' . self::nav_button('¿Has olvidado tu contraseña?', '/admin/forgot', 'btn btn-ghost') . '</div>');
    }

    private static function forgot($method)
    {
        $done = false;
        $err = '';
        if ($method === 'POST') {
            self::check_csrf();
            try {
                Auth::request_reset($_POST['email'] ?? '');
                $done = true;
            } catch (ApiError $e) {
                $err = $e->getMessage();
            }
        }
        if ($done) {
            self::auth_page('Revisa tu correo', self::alert('Si el correo pertenece a un usuario activo, te hemos enviado un enlace para crear una contraseña nueva. Caduca en 1 hora.')
                . self::nav_button('Volver a iniciar sesión', '/admin/login', 'btn btn-primary btn-block'), 'Recuperar contraseña');
        }
        self::auth_page('Recuperar contraseña', ($err ? self::alert($err, true) : '')
            . '<form method="post">' . self::csrf_field()
            . '<p class="muted">Escribe el correo de tu usuario (administrador, recepcionista o comercial) y te enviaremos un enlace para crear una contraseña nueva.</p>'
            . '<div class="field"><label for="email">Correo electrónico</label><input id="email" type="email" name="email" required autofocus autocomplete="username"></div>'
            . '<button class="btn btn-primary btn-block">Enviar enlace</button></form>'
            . '<div class="auth-alt">' . self::nav_button('← Volver a iniciar sesión', '/admin/login', 'btn btn-ghost') . '</div>', 'Te enviaremos un enlace por correo');
    }

    private static function reset($method)
    {
        header('Referrer-Policy: no-referrer');
        $token = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
        $agent = Auth::reset_agent($token);
        $err = '';
        if (!$agent) {
            self::auth_page('Enlace no válido', self::alert('El enlace no es válido, ya se ha usado o ha caducado.', true)
                . self::nav_button('Solicitar un enlace nuevo', '/admin/forgot', 'btn btn-primary btn-block'), 'Recuperar contraseña');
        }
        if ($method === 'POST') {
            self::check_csrf();
            $p1 = (string) ($_POST['password'] ?? '');
            $p2 = (string) ($_POST['password2'] ?? '');
            try {
                if ($p1 !== $p2) {
                    throw new ApiError('Las dos contraseñas no coinciden');
                }
                Auth::complete_reset($token, $p1);
                $msg = Roles::can($agent, 'admin_panel')
                    ? 'Contraseña cambiada. Ya puedes iniciar sesión.'
                    : 'Contraseña cambiada. Ya puedes entrar en el programa Bezares Chat de Windows.';
                self::auth_page('Contraseña cambiada', self::alert($msg)
                    . (Roles::can($agent, 'admin_panel') ? self::nav_button('Iniciar sesión', '/admin/login', 'btn btn-primary btn-block') : ''), 'Por seguridad se han cerrado todas tus sesiones abiertas');
            } catch (ApiError $e) {
                $err = $e->getMessage();
            }
        }
        self::auth_page('Nueva contraseña', ($err ? self::alert($err, true) : '')
            . '<form method="post">' . self::csrf_field() . '<input type="hidden" name="token" value="' . self::h($token) . '">'
            . '<p class="muted">Usuario: <b>' . self::h($agent['email']) . '</b></p>'
            . '<div class="field"><label for="pw_password">Contraseña nueva</label>' . self::password_input('password', 'required minlength="10" autocomplete="new-password" autofocus') . '<small>Mínimo 10 caracteres, con letras y números.</small></div>'
            . '<div class="field"><label for="pw_password2">Repite la contraseña</label>' . self::password_input('password2', 'required minlength="10" autocomplete="new-password"') . '</div>'
            . '<button class="btn btn-primary btn-block">Guardar contraseña</button></form>', 'Elige una contraseña nueva');
    }

    /* ================================================================ alta de agentes con enlace secreto */

    private static function signup($method)
    {
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        $link = (string) ($_POST['clave'] ?? ($_GET['clave'] ?? ''));
        if (!Signup::valid_link($link)) {
            http_response_code(404);
            self::auth_page('Enlace no válido', self::alert('Este enlace no existe o se ha deshabilitado. Pide uno nuevo al administrador.', true), 'Alta de agentes');
        }
        $err = '';
        if ($method === 'POST') {
            self::check_csrf();
            try {
                Signup::register($link, $_POST);
                self::auth_page('Revisa tu correo', self::alert('Si el correo es correcto, te hemos enviado un enlace para confirmarlo. Caduca en 24 horas.')
                    . '<p class="muted">Cuando lo confirmes, un administrador tendrá que habilitar tu cuenta. Si no te llega en unos minutos, mira en el correo no deseado.</p>', 'Falta un paso');
            } catch (ApiError $e) {
                $err = $e->getMessage();
            }
        }
        $opts = '<option value="">Elige…</option>';
        foreach (Signup::roles() as $k => $n) {
            $opts .= '<option value="' . self::h($k) . '"' . (($_POST['role'] ?? '') === $k ? ' selected' : '') . '>' . self::h($n) . '</option>';
        }
        $domains = implode(', ', array_map(function ($d) { return '@' . $d; }, Signup::domains()));
        self::auth_page('Crea tu cuenta', ($err ? self::alert($err, true) : '')
            . '<form method="post" action="/admin/alta">' . self::csrf_field() . '<input type="hidden" name="clave" value="' . self::h($link) . '">'
            . '<div class="field"><label for="s_name">Nombre y apellidos</label><input id="s_name" type="text" name="name" required maxlength="120" autocomplete="name" autofocus value="' . self::h($_POST['name'] ?? '') . '"><small>Los clientes ven solo el nombre.</small></div>'
            . '<div class="field"><label for="s_email">Correo</label><input id="s_email" type="email" name="email" required autocomplete="username" value="' . self::h($_POST['email'] ?? '') . '"><small>Solo correos de ' . self::h($domains ?: '(ningún dominio configurado)') . '</small></div>'
            . '<div class="field"><label for="pw_password">Contraseña</label>' . self::password_input('password', 'required minlength="10" autocomplete="new-password"') . '<small>Mínimo 10 caracteres, con letras y números.</small></div>'
            . '<div class="field"><label for="pw_password2">Verifica la contraseña</label>' . self::password_input('password2', 'required minlength="10" autocomplete="new-password"') . '</div>'
            . '<div class="field"><label for="s_role">Rol</label><select id="s_role" name="role" required>' . $opts . '</select></div>'
            . '<button class="btn btn-primary btn-block">Crear cuenta</button></form>', 'Bezares Chat · Alta de agentes');
    }

    private static function signup_confirm($method)
    {
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        $token = (string) ($_POST['clave'] ?? ($_GET['clave'] ?? ''));
        if ($method === 'POST') {
            self::check_csrf();
            try {
                $a = Signup::confirm($token);
                self::auth_page('Correo confirmado', self::alert('Tu correo ' . $a['email'] . ' está confirmado.')
                    . '<p class="muted">Hemos avisado a los administradores. Te escribiremos cuando habiliten tu cuenta; mientras tanto puedes preparar el programa.</p>' . self::client_help($a['email']), 'Ya casi está');
            } catch (ApiError $e) {
                self::auth_page('Enlace no válido', self::alert($e->getMessage(), true), 'Confirmación del correo');
            }
        }
        $a = Signup::by_token($token);
        if (!$a) {
            self::auth_page('Enlace no válido', self::alert('El enlace no es válido, ya se ha usado o ha caducado.', true), 'Confirmación del correo');
        }
        // Se confirma con un botón y no al abrir el enlace: los antivirus de correo abren los enlaces por su cuenta.
        self::auth_page('Confirma tu correo', '<form method="post" action="/admin/confirmar">' . self::csrf_field() . '<input type="hidden" name="clave" value="' . self::h($token) . '">'
            . '<p class="muted">Cuenta de <b>' . self::h($a['name']) . '</b><br>' . self::h($a['email']) . ' · ' . self::h(Roles::name($a['role'])) . '</p>'
            . '<p class="muted">Si no has pedido esta cuenta, cierra esta página y avisa al administrador.</p>'
            . '<button class="btn btn-primary btn-block">Sí, confirmar mi correo</button></form>', 'Alta de agentes');
    }

    /** Botón de descarga e instrucciones del programa de Windows. */
    private static function client_help($email = '')
    {
        $v = Signup::client_version();
        $server = rtrim((string) cfg('base_url'), '/');
        return '<div style="text-align:left"><h2 style="margin-top:18px">Descarga el programa</h2>'
            . ($v !== '' ? self::nav_button('⬇ Descargar Bezares Chat para Windows', '/download/BezaresChat.exe', 'btn btn-primary btn-block') . '<p class="muted"><small>Versión ' . self::h($v) . ' · Windows 10 u 11 · no necesita instalación</small></p>'
                : '<p class="muted">El programa todavía no está publicado: pídelo al administrador.</p>')
            . '<h2>Cómo empezar</h2><ol style="padding-left:20px;line-height:1.6">'
            . '<li>Guarda <b>BezaresChat.exe</b> en una carpeta tuya, por ejemplo <b>Documentos\\Bezares Chat</b> (no en «Archivos de programa»: allí no podría actualizarse solo).</li>'
            . '<li>Ábrelo. La primera vez Windows puede mostrar «Windows protegió su PC»: si lo has descargado de ' . self::h(parse_url($server, PHP_URL_HOST)) . ', pulsa <b>Más información</b> y <b>Ejecutar de todas formas</b>.</li>'
            . '<li>Servidor: <b>' . self::h($server) . '</b>. Entra con ' . ($email !== '' ? '<b>' . self::h($email) . '</b>' : 'tu correo') . ' y tu contraseña cuando tu cuenta esté habilitada.</li>'
            . '<li>Marca <b>Iniciar con Windows</b> para no perder avisos. Al cerrar la ventana el programa sigue junto al reloj.</li></ol>'
            . '<h2>Uso básico</h2><ul style="padding-left:20px;line-height:1.6">'
            . '<li>Cuando entra un chat suena el aviso: pulsa <b>ATENDER</b>.</li>'
            . '<li>Escribe y pulsa <b>Intro</b> para enviar (Mayús + Intro hace una línea nueva). Los mensajes en otros idiomas se traducen solos.</li>'
            . '<li><b>Transferir</b> pasa el chat a un compañero conectado y <b>Resolver</b> lo da por terminado.</li>'
            . '<li>Clic derecho en un mensaje tuyo: copiar, editar o borrar.</li>'
            . '<li><b>Desconectarme</b> cuando no puedas atender. El programa se actualiza solo.</li></ul></div>';
    }

    /** Tarjeta de Usuarios: enlace secreto de alta y descarga del programa. */
    private static function signup_card()
    {
        $l = Signup::link();
        $domains = implode(', ', array_map(function ($d) { return '@' . $d; }, Signup::domains()));
        $btn = function ($action, $label, $class) {
            return '<form method="post" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><button class="btn ' . $class . '">' . $label . '</button></form> ';
        };
        $v = Signup::client_version();
        return '<div class="card"><h2>🔗 Enlace para crear usuarios</h2>'
            . '<p class="muted">Con este enlace los agentes crean su usuario. La cuenta queda desactivada hasta que confirman su correo y un administrador la habilita (avisa en el programa de Windows). No se puede elegir Administrador, Recepcionista ni ningún rol con acceso al panel.</p>'
            . ($l ? '<div class="field"><label for="su_link">Enlace vigente, generado por ' . self::h($l['by']) . ' el ' . self::when($l['t']) . '</label><input id="su_link" type="text" readonly value="' . self::h(Signup::url($l['token'])) . '"></div>'
                : '<div class="info-box">Enlace <b>deshabilitado</b>: ahora nadie puede crear usuarios.</div>')
            . '<div class="filters">' . ($l ? '<button type="button" class="btn btn-secondary" data-copy="su_link">📋 Copiar</button> ' . $btn('signup_new', '🔄 Generar otro', 'btn-secondary') . $btn('signup_off', '⛔ Deshabilitar', 'btn-danger') : $btn('signup_new', '🔗 Generar enlace', 'btn-primary')) . '</div>'
            . '<small>Correos admitidos: <b>' . self::h($domains ?: 'ninguno') . '</b> (Configuración → General y widget). ' . ($l ? 'Al generar otro, el anterior deja de funcionar.' : '') . '</small>'
            . '<hr style="border:0;border-top:1px solid var(--line);margin:16px 0"><p class="muted">Programa de Windows: ' . ($v !== '' ? 'versión <b>' . self::h($v) . '</b> publicada.</p>' . self::nav_button('⬇ Descargar BezaresChat.exe', '/download/BezaresChat.exe', 'btn btn-secondary') : 'sin publicar.</p>') . '</div>';
    }

    /* ================================================================ configuración */

    /** Botones de pestaña de Configuración; $active es el índice del grupo o 'roles'. */
    private static function settings_tabs($schema, $active)
    {
        $tabs = '<form method="get" action="/admin/settings" class="tabs" role="tablist">';
        foreach ($schema as $i => $g) {
            if ($g['group'] === 'Robot') {
                continue; // tiene su propia página en el menú
            }
            $on = $active === $i;
            $tabs .= '<button type="submit" name="tab" value="' . $i . '" class="btn' . ($on ? ' on' : '') . '" role="tab" aria-selected="' . ($on ? 'true' : 'false') . '">' . self::h($g['group']) . '</button>';
        }
        foreach (array('roles' => '🛡 Roles') as $k => $label) {
            $on = $active === $k;
            $tabs .= '<button type="submit" name="tab" value="' . $k . '" class="btn' . ($on ? ' on' : '') . '" role="tab" aria-selected="' . ($on ? 'true' : 'false') . '">' . $label . '</button>';
        }
        return $tabs . '</form>';
    }

    /* ================================================================ pruebas (actuar como otro usuario) */

    /** Usuario como el que se está actuando en la pestaña Pruebas (o null). */
    private static function test_agent()
    {
        $a = !empty($_SESSION['test_as']) ? Auth::agent((int) $_SESSION['test_as']) : null;
        return $a && (int) $a['active'] ? $a : null;
    }

    private static function test_conv($id)
    {
        $c = Core::conv((int) $id);
        if (!$c || empty($c['is_test'])) {
            throw new ApiError('Chat de prueba no encontrado');
        }
        return $c;
    }

    private static function delete_conv($id)
    {
        foreach (DB::col("SELECT media_url FROM messages WHERE conversation_id = ? AND media_url <> ''", array((int) $id)) as $u) {
            Media::delete_stored($u);
        }
        DB::delete('messages', array('conversation_id' => (int) $id));
        DB::delete('conversations', array('id' => (int) $id));
    }

    /** Acciones de la pestaña Pruebas. Todo ocurre sobre chats de prueba, que el programa de Windows no ve. */
    private static function test_post($me)
    {
        self::check_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $conv = (int) ($_POST['conv'] ?? 0);
        try {
            if ($action === 'test_as') {
                $a = Auth::agent((int) ($_POST['agent_id'] ?? 0));
                if (!$a || !(int) $a['active']) {
                    throw new ApiError('Elige un usuario activo');
                }
                $_SESSION['test_as'] = (int) $a['id'];
                self::flash('Ahora actúas como ' . $a['name'] . ' (' . Roles::name($a['role']) . ') en los chats de prueba.');
            } elseif ($action === 'test_mode_on') {
                $channels = array_keys((array) ($_POST['channels'] ?? array()));
                if (!array_intersect($channels, array_keys(Core::TEST_CHANNELS))) {
                    throw new ApiError('Marca al menos un canal para el modo de pruebas');
                }
                Core::set_test_mode($channels, (int) ($_POST['hours'] ?? 2), $me['name']);
                log_error('Modo de pruebas ACTIVADO por ' . $me['name'] . ' para ' . implode(', ', $channels));
                self::flash('Modo de pruebas activado: los mensajes de esos canales llegan solo a esta pestaña.');
            } elseif ($action === 'test_mode_off') {
                Core::set_test_mode(array(), 0, $me['name']);
                log_error('Modo de pruebas desactivado por ' . $me['name']);
                self::flash('Modo de pruebas desactivado: los mensajes vuelven a llegar al equipo.');
            } elseif ($action === 'test_clear') {
                foreach (DB::col('SELECT id FROM conversations WHERE is_test = 1') as $id) {
                    self::delete_conv($id);
                }
                $conv = 0;
                self::flash('Chats de prueba borrados.');
            } else {
                $agent = self::test_agent();
                if ($action === 'test_start') {
                    $text = clean_text($_POST['text'] ?? '');
                    if ($text === '') {
                        throw new ApiError('Escribe el primer mensaje del visitante');
                    }
                    $lang = lang_norm($_POST['lang'] ?? 'es') ?: 'es';
                    $ext = 'paneltest-' . random_token(10);
                    Core::incoming('web', $ext, array('lang' => $lang, 'page_url' => rtrim((string) cfg('base_url'), '/') . '/admin (prueba)', 'test' => 1), $text);
                    $c = Core::find('web', $ext, 1);
                    $conv = $c ? (int) $c['id'] : 0;
                    self::flash('Chat de prueba creado. El bot ha respondido como lo haría con un visitante real.');
                } elseif ($action === 'test_visitor') {
                    $c = self::test_conv($conv);
                    $text = clean_text($_POST['text'] ?? '');
                    if ($text === '') {
                        throw new ApiError('Escribe el mensaje del visitante');
                    }
                    Core::incoming('web', $c['external_id'], array('lang' => $c['lang'], 'test' => 1), $text);
                } else {
                    if (!$agent) {
                        throw new ApiError('Primero elige como qué usuario quieres actuar');
                    }
                    $c = self::test_conv($conv);
                    if ($action === 'test_reply') {
                        Core::agent_send($c, $agent, clean_text($_POST['text'] ?? ''));
                    } elseif ($action === 'test_accept') {
                        Core::accept($c, $agent);
                    } elseif ($action === 'test_transfer') {
                        Auth::require_perm($agent, 'transfer');
                        $to = Auth::agent((int) ($_POST['to'] ?? 0));
                        if (!$to || !(int) $to['active']) {
                            throw new ApiError('Usuario de destino no válido');
                        }
                        Core::transfer($c, $agent, $to, (string) ($_POST['note'] ?? ''));
                    } elseif ($action === 'test_resolve') {
                        Auth::require_perm($agent, 'resolve');
                        Core::resolve($c, $agent, $c['status'] !== 'resolved');
                    } elseif ($action === 'test_delete') {
                        Auth::require_perm($agent, 'delete');
                        self::delete_conv($c['id']);
                        $conv = 0;
                        self::flash('Chat de prueba borrado.');
                    }
                }
            }
        } catch (ApiError $e) {
            self::flash($e->getMessage(), true);
        }
        self::redirect('/admin/test' . ($conv ? '?conv=' . $conv : ''));
    }

    /** Tarjeta del modo de pruebas de WhatsApp/Messenger (activar con canales y duración, o desactivar). */
    private static function test_mode_card()
    {
        $t = Core::test_mode();
        if ($t) {
            $names = array_map(function ($c) { return Core::TEST_CHANNELS[$c]; }, $t['channels']);
            return '<form method="post" class="card test-on">' . self::csrf_field() . '<input type="hidden" name="action" value="test_mode_off">'
                . '<h2>🧪 Modo de pruebas ACTIVO</h2>'
                . '<p>Los mensajes de <b>' . self::h(implode(' y ', $names)) . '</b> llegan solo a esta pestaña: el equipo no los ve ni le suenan. Lo que respondas aquí se envía <b>de verdad</b> por ese canal.</p>'
                . '<p class="muted">Activado por ' . self::h($t['by']) . ' · se desactiva solo en ' . self::h(self::remaining((int) $t['until'])) . '.</p>'
                . '<button class="btn btn-danger">Desactivar ahora</button></form>';
        }
        $ch = '';
        foreach (Core::TEST_CHANNELS as $k => $l) {
            $enabled = (bool) Settings::get($k === 'whatsapp' ? 'wa_enabled' : 'fb_enabled');
            $ch .= '<label class="check"><input type="checkbox" name="channels[' . $k . ']" value="1" checked> ' . self::h($l) . ($enabled ? '' : ' <small class="muted">(desactivado en Configuración)</small>') . '</label>';
        }
        $hours = '';
        foreach (array(1, 2, 4, 8) as $h) {
            $hours .= '<option value="' . $h . '"' . ($h === 2 ? ' selected' : '') . '>' . $h . ' h</option>';
        }
        return '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="action" value="test_mode_on">'
            . '<h2>📱 Modo de pruebas de WhatsApp y Messenger</h2>'
            . '<p class="muted">Mientras esté activo, <b>todos</b> los mensajes que entren por los canales marcados se desvían a esta pestaña (el equipo no los recibe), y tus respuestas desde aquí salen de verdad por WhatsApp/Messenger. El chat web sigue funcionando normal. Úsalo cuando el número o la página aún no estén en uso con clientes reales.</p>'
            . '<div class="filters">' . $ch . '<div class="field"><label for="tm_hours">Duración</label><select id="tm_hours" name="hours">' . $hours . '</select></div>'
            . '<button class="btn btn-primary">Activar modo de pruebas</button></div></form>';
    }

    /** Aviso en todas las páginas del panel mientras el modo de pruebas está activo. */
    private static function test_banner()
    {
        $t = Core::test_mode();
        if (!$t) {
            return '';
        }
        $names = array_map(function ($c) { return Core::TEST_CHANNELS[$c]; }, $t['channels']);
        return '<div class="test-banner">🧪 <b>Modo de pruebas activo</b> para ' . self::h(implode(' y ', $names)) . ': esos mensajes no llegan al equipo. Se desactiva solo en ' . self::h(self::remaining((int) $t['until'])) . '. '
            . self::nav_button('Ir a Pruebas', '/admin/test', 'btn btn-sm btn-secondary') . '</div>';
    }

    private static function remaining($until)
    {
        $m = max(0, (int) ceil(($until - time()) / 60));
        return $m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
    }

    /** Botón de acción sobre un chat de prueba (desactivado si el rol no tiene el permiso). */
    private static function test_button($action, $label, $conv, $allowed, $class = 'btn btn-secondary btn-sm', $extra = '')
    {
        return '<form method="post" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="conv" value="' . (int) $conv . '">' . $extra
            . '<button class="' . $class . '"' . ($allowed ? '' : ' disabled title="Este rol no tiene permiso"') . '>' . $label . '</button></form>';
    }

    private static function test_tab($me)
    {
        $agent = self::test_agent();
        $agents = Auth::agents();

        // 1) Actuar como
        $opts = '';
        foreach ($agents as $a) {
            if (!(int) $a['active']) {
                continue;
            }
            $opts .= '<option value="' . (int) $a['id'] . '"' . ($agent && (int) $agent['id'] === (int) $a['id'] ? ' selected' : '') . '>' . self::h($a['name'] . ' — ' . Roles::name($a['role'])) . '</option>';
        }
        $perms = '';
        if ($agent) {
            foreach (Roles::PERMS as $p => $info) {
                $has = Roles::can($agent, $p);
                $perms .= '<span class="badge ' . ($has ? 'b-on' : 'b-off') . '" title="' . self::h($info[1]) . '">' . ($has ? '✓ ' : '✕ ') . self::h($info[0]) . '</span> ';
            }
        }
        $html = self::test_mode_card()
            . '<div class="info-box">Aquí puedes probar el chat <b>sin molestar a nadie</b>: actúas como el usuario que elijas sobre <b>chats de prueba</b>. Esos chats no aparecen en el programa de Windows, no suenan a nadie, los simulados no envían nada a nadie, no cuentan en las estadísticas y se borran solos a las 24 h. El usuario real no se entera ni cambia su estado.</div>'
            . '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="action" value="test_as">'
            . '<h2>👤 Actuar como</h2><div class="filters"><div class="field" style="flex:1;min-width:240px"><label for="agent_id">Usuario</label><select id="agent_id" name="agent_id">' . $opts . '</select></div>'
            . '<button class="btn btn-primary">Usar este usuario</button></div>'
            . ($agent ? '<p style="margin:14px 0 0">Permisos de <b>' . self::h($agent['name']) . '</b> (' . self::h(Roles::name($agent['role'])) . '):</p><p style="margin:6px 0 0;line-height:2">' . $perms . '</p>' : '<p class="muted" style="margin:12px 0 0">Elige un usuario para empezar.</p>')
            . '</form>';

        // 2) Simular visitante
        $langs = array('es' => 'Español', 'en' => 'Inglés', 'fr' => 'Francés', 'de' => 'Alemán', 'pt' => 'Portugués', 'it' => 'Italiano');
        $lo = '';
        foreach ($langs as $k => $l) {
            $lo .= '<option value="' . $k . '">' . $l . '</option>';
        }
        $html .= '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="action" value="test_start">'
            . '<h2>💬 Simular un visitante nuevo (web)</h2>' . self::ai_warning()
            . '<div class="field"><label for="t_text">Primer mensaje del visitante</label><textarea id="t_text" name="text" required placeholder="Ej.: Hola, busco una toma de fuerza para un camión Volvo"></textarea><small>Escríbelo en el idioma elegido para probar también la traducción automática.</small></div>'
            . '<div class="filters"><div class="field"><label for="t_lang">Idioma del navegador del visitante</label><select id="t_lang" name="lang">' . $lo . '</select></div><button class="btn btn-primary">Iniciar chat de prueba</button></div></form>';

        // 3) Lista de chats de prueba
        $tests = DB::rows('SELECT * FROM conversations WHERE is_test = 1 ORDER BY updated_at DESC LIMIT 50');
        $sel = isset($_GET['conv']) ? (int) $_GET['conv'] : 0;
        $rows = '';
        foreach ($tests as $c) {
            $status = $c['status'] === 'resolved' ? '<span class="badge b-on">✓ Resuelta</span>' : ((int) $c['assigned_to'] ? '<span class="badge b-receptionist">Asignada</span>' : '<span class="badge b-custom">Nueva</span>');
            $rows .= '<tr' . ((int) $c['id'] === $sel ? ' style="background:var(--brand-l)"' : '') . '><td><b>' . self::h($c['name'] !== '' ? $c['name'] : 'Visitante #' . $c['id']) . '</b> <span class="badge b-off">' . self::h($c['channel'] === 'web' ? 'Web (simulado)' : (Core::TEST_CHANNELS[$c['channel']] ?? $c['channel'])) . '</span><br><small>' . self::h(mb_substr($c['last_preview'], 0, 80)) . '</small></td>'
                . '<td>' . $status . '</td><td>' . self::h((int) $c['assigned_to'] ? (Auth::agent($c['assigned_to'])['name'] ?? '—') : 'Sin asignar') . '</td>'
                . '<td class="actions">' . self::nav_button('Abrir', '/admin/test', 'btn btn-secondary btn-sm', array('conv' => (int) $c['id'])) . '</td></tr>';
        }
        $html .= '<div class="card"><h2>🗂 Chats de prueba</h2>'
            . ($rows ? '<div class="table-wrap"><table><thead><tr><th>Visitante</th><th>Estado</th><th>Asignado a</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
                . '<div style="margin-top:14px">' . self::test_button('test_clear', '🗑 Borrar todos los chats de prueba', 0, true, 'btn btn-danger btn-sm') . '</div>'
                : '<p class="muted">Todavía no hay chats de prueba.</p>')
            . '</div>';

        // 4) Conversación seleccionada
        $c = $sel ? Core::conv($sel) : null;
        if ($c && !empty($c['is_test'])) {
            $msgs = DB::rows('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id', array($c['id']));
            $tz = tz();
            $t = '';
            foreach ($msgs as $m) {
                $when = (new DateTimeImmutable('@' . intdiv((int) $m['created_at'], 1000)))->setTimezone($tz)->format('H:i');
                $who = array('in' => 'Visitante', 'out' => Auth::first_name($m['agent_id']) ?: 'Agente', 'bot' => '🤖 Bot', 'system' => 'ℹ Sistema')[$m['direction']] ?? $m['direction'];
                $body = self::h($m['body']);
                if ($m['body_translated']) {
                    $body .= '<br><small>🌐 ' . ($m['direction'] === 'in' ? 'Traducción para el equipo' : 'Enviado al visitante') . ': ' . self::h($m['body_translated']) . '</small>';
                }
                if ($m['media_url']) {
                    $body .= ' <a href="' . self::h(Media::url($m['media_url'])) . '" target="_blank" rel="noopener">📎 ' . self::h($m['media_name'] ?: 'adjunto') . '</a>';
                }
                $t .= '<div class="tmsg t-' . self::h($m['direction']) . '"><div class="tw">' . self::h($who) . ' · ' . $when . '</div>' . $body . '</div>';
            }
            $info = '';
            if ($agent) {
                $sees = Auth::would_see($agent, $c);
                $rings = $c['status'] === 'open' && !(int) $c['accepted_at'] && Auth::should_ring($agent, $c);
                $info = '<p>Para <b>' . self::h($agent['name']) . '</b>: ' . ($sees ? '✓ vería este chat' : '✕ no vería este chat') . ' · ' . ($rings ? '🔔 le estaría sonando' : 'no le sonaría') . '</p>';
            }
            $transferOpts = '';
            foreach ($agents as $a) {
                if ((int) $a['active'] && (!$agent || (int) $a['id'] !== (int) $agent['id'])) {
                    $transferOpts .= '<option value="' . (int) $a['id'] . '">' . self::h($a['name'] . ' — ' . Roles::name($a['role'])) . '</option>';
                }
            }
            $can = function ($p) use ($agent) { return $agent && Roles::can($agent, $p); };
            $html .= '<div class="card"><h2>Conversación de prueba: ' . self::h($c['name'] !== '' ? $c['name'] : 'Visitante #' . $c['id']) . '</h2>' . $info
                . '<div class="tconv">' . ($t ?: '<p class="muted">Sin mensajes.</p>') . '</div>'
                . '<div class="split" style="margin-top:16px">'
                . '<form method="post">' . self::csrf_field() . '<input type="hidden" name="action" value="test_reply"><input type="hidden" name="conv" value="' . (int) $c['id'] . '">'
                . '<div class="field"><label for="r_text">Responder como ' . self::h($agent ? $agent['name'] : '(elige un usuario)') . ($c['channel'] !== 'web' ? ' <span class="badge b-custom">se envía de verdad por ' . self::h(Core::TEST_CHANNELS[$c['channel']] ?? $c['channel']) . '</span>' : '') . '</label><textarea id="r_text" name="text" required></textarea></div>'
                . '<button class="btn btn-primary"' . ($agent ? '' : ' disabled') . '>Enviar respuesta</button></form>'
                . ($c['channel'] === 'web'
                    ? '<form method="post">' . self::csrf_field() . '<input type="hidden" name="action" value="test_visitor"><input type="hidden" name="conv" value="' . (int) $c['id'] . '">'
                        . '<div class="field"><label for="v_text">Escribir como el visitante</label><textarea id="v_text" name="text" required></textarea></div>'
                        . '<button class="btn btn-secondary">Enviar como visitante</button></form>'
                    : '<div class="info-box">Chat real de ' . self::h(Core::TEST_CHANNELS[$c['channel']] ?? $c['channel']) . ' (' . self::h($c['phone'] ?: $c['external_id']) . '): escribe desde ese móvil o cuenta para seguir la conversación. Recarga la página para ver los mensajes nuevos.</div>')
                . '</div>'
                . '<div class="filters" style="margin-top:16px">'
                . self::test_button('test_accept', '📞 Atender', $c['id'], (bool) $agent)
                . self::test_button('test_resolve', $c['status'] === 'resolved' ? '↺ Reabrir' : '✓ Resuelta', $c['id'], $can('resolve'))
                . self::test_button('test_delete', '🗑 Borrar', $c['id'], $can('delete'), 'btn btn-danger btn-sm')
                . '<form method="post" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="test_transfer"><input type="hidden" name="conv" value="' . (int) $c['id'] . '">'
                . '<select name="to" aria-label="Transferir a" style="width:auto">' . $transferOpts . '</select> '
                . '<button class="btn btn-secondary btn-sm"' . ($can('transfer') ? '' : ' disabled title="Este rol no tiene permiso"') . '>↪ Transferir</button></form>'
                . '</div></div>';
        }
        self::layout('Pruebas', 'Actúa como otro usuario y prueba WhatsApp y Messenger sin molestar a nadie', $html, $me);
    }

    /** Comprobación de WhatsApp: compara el Phone number ID configurado con el del número que recibe los mensajes. */
    private static function wa_card($tab)
    {
        $id = trim((string) Settings::get('wa_phone_id'));
        $seen = Channels::wa_seen();
        $diff = $seen && $seen['id'] !== $id;
        $form = function ($action, $label, $class) use ($tab) {
            return '<form method="post" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="tab" value="' . (int) $tab . '">'
                . '<button class="btn ' . $class . '">' . $label . '</button></form>';
        };
        $last = MetaCheck::last();
        $icons = array('ok' => '✅', 'warn' => '⚠️', 'bad' => '❌', 'info' => 'ℹ️');
        $list = function ($title, array $rows) use ($icons) {
            $bad = count(array_filter($rows, function ($r) { return $r['s'] === 'bad'; }));
            $warn = count(array_filter($rows, function ($r) { return $r['s'] === 'warn'; }));
            $h = '<h3 class="mc-h">' . $title . ' <span class="badge ' . ($bad ? 'b-off mc-bad">' . $bad . ($bad === 1 ? ' fallo' : ' fallos') : ($warn ? 'b-custom">' . $warn . ($warn === 1 ? ' aviso' : ' avisos') : 'b-on">Todo correcto')) . '</span></h3><ul class="mc">';
            foreach ($rows as $r) {
                $h .= '<li class="mc-' . self::h($r['s']) . '"><span aria-hidden="true">' . ($icons[$r['s']] ?? '') . '</span><div>' . self::h($r['t']) . ($r['fix'] !== '' ? '<small><b>Solución:</b> ' . self::h($r['fix']) . '</small>' : '') . '</div></li>';
            }
            return $h . '</ul>';
        };
        $result = '';
        $use = '';
        if ($last) {
            $result = '<p class="muted">Comprobado el ' . self::when($last['t'] * 1000) . '. Si cambias algo, guarda y vuelve a comprobar.</p>' . $list('WhatsApp', $last['wa']) . $list('Facebook Messenger', $last['fb']);
            foreach ($last['numbers'] as $k => $n) {
                if ((string) $k !== $id) {
                    $use .= '<form method="post" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="usewa"><input type="hidden" name="tab" value="' . (int) $tab . '"><input type="hidden" name="wid" value="' . self::h($k) . '">'
                        . '<button class="btn btn-secondary btn-sm">Usar ' . self::h($n['phone']) . ' (ID ' . self::h($k) . ')</button></form> ';
                }
            }
        }
        $info = 'Phone number ID configurado: <b>' . self::h($id !== '' ? $id : '(vacío)') . '</b><br>'
            . ($seen ? 'Último mensaje recibido en: <b>' . self::h($seen['phone']) . '</b>, ID <b>' . self::h($seen['id']) . '</b> (' . self::when($seen['t'] * 1000) . ')'
                : 'Todavía no se ha registrado ningún mensaje entrante: envía uno a tu WhatsApp y recarga esta página para ver aquí el ID correcto.');
        return '<div class="card" id="permisos"><h2>🔎 Comprobar permisos de WhatsApp y Facebook</h2><div class="info-box">' . $info . '</div>'
            . ($diff ? '<div class="alert alert-bad" role="alert">⚠️ El ID configurado no es el del número que recibe los mensajes.</div>' : '')
            . '<div class="filters" style="margin-bottom:10px">' . $form('testwa', '🔎 Comprobar permisos', 'btn-primary') . ($diff ? $form('usewa', 'Usar el ID ' . self::h($seen['id']), 'btn-secondary') : '') . '</div>'
            . '<small>Consulta a Meta (tarda unos segundos). No envía mensajes ni cambia nada en Meta. Guarda antes los cambios del formulario.</small>'
            . $result . ($use !== '' ? '<p class="mc-use"><b>Otros números que puede usar este token:</b></p>' . $use : '') . '</div>';
    }

    /** Mensajes que no se entregaron por la configuración de Meta, con el botón para volver a enviarlos. */
    private static function resend_card($tab, $me)
    {
        if (!self::can_chats($me)) {
            return ''; // se ven textos de los chats
        }
        $open = '';
        $closed = '';
        $nOpen = 0;
        $nClosed = 0;
        $names = array();
        foreach (DB::rows('SELECT id, name FROM agents') as $a) {
            $names[(int) $a['id']] = $a['name'];
        }
        foreach (Core::undelivered() as $m) {
            $c = array('id' => $m['conversation_id'], 'name' => $m['name'], 'phone' => $m['phone'], 'channel' => $m['channel']);
            $text = trim(preg_replace('/\s+/u', ' ', (string) $m['body']));
            $text = ($m['media_url'] !== '' ? '📎 ' . ($m['media_name'] ?: 'Adjunto') . ' ' : '') . mb_substr($text, 0, 120) . (mb_strlen($text) > 120 ? '…' : '');
            $note = '';
            if ($m['later']) {
                $note = 'Después ya se le contestó';
            } elseif ($m['dup']) {
                $note = 'Repetido';
            } elseif ($m['conv_status'] !== 'open') {
                $note = 'Chat cerrado o resuelto';
            }
            $bot = $m['direction'] === 'bot';
            $cells = '<td><b>' . self::h(self::conv_name($c)) . '</b><br><small>' . self::h(self::CHANNELS[$m['channel']] ?? $m['channel']) . ' · ' . ($bot ? 'Mensaje automático' : self::h($names[(int) $m['agent_id']] ?? '(eliminado)')) . ' · ' . self::when($m['created_at']) . '</small></td>'
                . '<td>' . self::h($text) . ($note !== '' ? '<br><span class="badge b-off">' . $note . '</span>' : '') . '</td>'
                . '<td class="actions">' . self::nav_button('Ver', '/admin/chats', 'btn btn-secondary btn-sm', array('id' => (int) $m['conversation_id'])) . '</td></tr>';
            if ($m['open']) {
                $nOpen++;
                $open .= '<tr><td><input type="checkbox" name="ids[]" form="resendf" value="' . (int) $m['id'] . '"' . ($note === '' && !$bot ? ' checked' : '') . ' aria-label="Reenviar este mensaje a ' . self::h(self::conv_name($c)) . '"></td>' . $cells;
            } elseif (!$m['later']) {
                $nClosed++;
                $closed .= '<tr>' . $cells;
            }
        }
        $h = '<div class="card" id="reenviar"><h2>🔁 Volver a enviar mensajes no entregados</h2>'
            . '<p class="muted">Mensajes del equipo y automáticos de los últimos 30 días que no salieron por la conexión con Meta («Unsupported post request», token caducado…). Antes de reenviar, pulsa «Comprobar permisos» y arregla los fallos.</p>';
        if ($nOpen) {
            $h .= '<div class="table-wrap"><table><thead><tr><th>Enviar</th><th>Cliente</th><th>Mensaje</th><th></th></tr></thead><tbody>' . $open . '</tbody></table></div>'
                . '<form method="post" id="resendf" style="margin-top:12px">' . self::csrf_field() . '<input type="hidden" name="action" value="resend"><input type="hidden" name="tab" value="' . (int) $tab . '">'
                . '<button class="btn btn-primary">🔁 Volver a enviar los marcados</button></form>'
                . '<small>El cliente los recibe ahora, en el mismo orden. No se marcan los automáticos, los que ya se contestaron después, los repetidos ni los de chats cerrados o resueltos: marca a mano los que quieras.</small>';
        } else {
            $h .= '<p>No hay mensajes que se puedan reenviar.</p>';
        }
        if ($nClosed) {
            $h .= '<details style="margin-top:14px"><summary>Fuera de las 24 h: ' . $nClosed . ($nClosed === 1 ? ' mensaje' : ' mensajes') . '</summary>'
                . '<p class="muted">Meta ya no permite escribirles hasta que el cliente vuelva a escribir. En WhatsApp, contéstales por correo desde el programa. Si el cliente escribe, aparecerán arriba.</p>'
                . '<div class="table-wrap"><table><thead><tr><th>Cliente</th><th>Mensaje</th><th></th></tr></thead><tbody>' . $closed . '</tbody></table></div></details>';
        }
        return $h . '</div>';
    }

    /* ================================================================ consulta de chats */

    const CHANNELS = array('web' => 'Web', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook');

    /** Consultar todos los chats: administradores y roles del panel con el permiso «Ver todos los chats». */
    private static function can_chats($me)
    {
        return $me && ($me['role'] === 'admin' || Roles::can($me, 'view_all'));
    }

    private static function when($ms, $fmt = 'd/m/Y H:i')
    {
        return (int) $ms ? (new DateTimeImmutable('@' . intdiv((int) $ms, 1000)))->setTimezone(tz())->format($fmt) : '—';
    }

    private static function conv_name($c)
    {
        return $c['name'] !== '' ? $c['name'] : ($c['phone'] !== '' ? $c['phone'] : ($c['channel'] === 'web' ? 'Visitante #' : 'Cliente #') . (int) $c['id']);
    }

    /** Estado de un chat como etiqueta. */
    private static function conv_badge($c)
    {
        if ($c['status'] === 'resolved') {
            return '<span class="badge b-on">✓ Resuelto</span>';
        }
        if ($c['status'] === 'closed') {
            return '<span class="badge b-off">Cerrado</span>';
        }
        if ((int) $c['last_customer_at'] > max((int) $c['last_agent_at'], (int) $c['window_notice_at'])) {
            return '<span class="badge b-custom">Espera respuesta</span>';
        }
        return '<span class="badge b-receptionist">Abierto</span>';
    }

    /** Botón para marcar un chat como resuelto o reabrirlo (página Chats). $back: filtros y, en la ficha, el chat abierto. */
    private static function resolve_button($me, $c, array $back, $class = 'btn btn-secondary btn-sm')
    {
        if (!Roles::can($me, 'resolve')) {
            return '';
        }
        $open = $c['status'] === 'open';
        $hidden = '';
        foreach ($back as $k => $v) {
            $hidden .= '<input type="hidden" name="back[' . self::h($k) . ']" value="' . self::h($v) . '">';
        }
        return '<form method="post" action="/admin/chats" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="' . ($open ? 'resolve' : 'reopen') . '"><input type="hidden" name="conv" value="' . (int) $c['id'] . '">' . $hidden
            . '<button class="' . $class . '">' . ($open ? '✓ Marcar como resuelto' : '↺ Reabrir') . '</button></form>';
    }

    private static function chats_post($me)
    {
        self::check_csrf();
        $back = array();
        foreach (isset($_POST['back']) && is_array($_POST['back']) ? $_POST['back'] : array() as $k => $v) {
            if (in_array($k, array('id', 'q', 'ch', 'st', 'ag', 'from', 'to', 'pg'), true) && is_string($v)) {
                $back[$k] = mb_substr($v, 0, 100); // la página vuelve a comprobar cada filtro
            }
        }
        $c = Core::conv((int) ($_POST['conv'] ?? 0));
        $action = (string) ($_POST['action'] ?? '');
        if (!Roles::can($me, 'resolve')) {
            self::flash('Tu rol no tiene permiso para marcar chats como resueltos.', true);
        } elseif (!$c || !empty($c['is_test'])) {
            self::flash('Ese chat ya no existe.', true);
        } elseif ($action === 'resolve' || $action === 'reopen') {
            $on = $action === 'resolve';
            if (($c['status'] === 'open') === $on) { // si otra persona ya lo ha cambiado, no se repite
                Core::resolve($c, $me, $on);
            }
            self::flash($on ? 'Chat de ' . self::conv_name($c) . ' marcado como resuelto.' : 'Chat de ' . self::conv_name($c) . ' reabierto.');
        }
        self::redirect('/admin/chats' . ($back ? '?' . http_build_query($back) : ''));
    }

    /** Página Chats: consultar no marca nada como leído, atendido ni asignado; solo se puede resolver o reabrir. Los chats de prueba no salen. */
    private static function chats($me)
    {
        if (!self::can_chats($me)) {
            http_response_code(403);
            self::layout('Chats', '', self::alert('Tu rol no tiene permiso para consultar todos los chats.', true), $me);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            self::chats_post($me);
        }
        $get = function ($k) {
            return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : '';
        };
        $states = array('' => 'Todos', 'open' => 'Abiertos', 'waiting' => 'Esperan respuesta', 'unassigned' => 'Sin asignar', 'resolved' => 'Resueltos', 'closed' => 'Cerrados');
        $names = array();
        foreach (Auth::agents() as $a) {
            $names[(int) $a['id']] = $a['name'];
        }
        // Filtros: solo valores conocidos. Se conservan al abrir un chat y al volver.
        $f = array(
            'q'     => mb_substr($get('q'), 0, 100),
            'ch'    => isset(self::CHANNELS[$get('ch')]) ? $get('ch') : '',
            'st'    => isset($states[$get('st')]) ? $get('st') : '',
            'ag'    => isset($names[(int) $get('ag')]) ? (string) (int) $get('ag') : '',
            'from'  => self::get_date('from') ? $get('from') : '',
            'to'    => self::get_date('to') ? $get('to') : '',
        );
        $keep = array_filter($f, function ($v) { return $v !== ''; });
        $page = max(1, min(100000, (int) $get('pg')));

        $id = (int) $get('id');
        if ($id) {
            $c = Core::conv($id);
            if ($c && empty($c['is_test'])) {
                self::chat_view($me, $c, $names, $keep + ($page > 1 ? array('pg' => $page) : array()));
            }
            self::flash('Ese chat ya no existe.', true);
            self::redirect('/admin/chats' . ($keep ? '?' . http_build_query($keep) : ''));
        }

        $w = array('c.is_test = 0');
        $args = array();
        if ($f['ch'] !== '') {
            $w[] = 'c.channel = ?';
            $args[] = $f['ch'];
        }
        if ($f['ag'] !== '') {
            $w[] = 'c.assigned_to = ?';
            $args[] = (int) $f['ag'];
        }
        if ($f['st'] === 'open' || $f['st'] === 'resolved' || $f['st'] === 'closed') {
            $w[] = 'c.status = ?';
            $args[] = $f['st'];
        } elseif ($f['st'] === 'waiting') {
            $w[] = "c.status = 'open' AND c.last_customer_at > GREATEST(c.last_agent_at, c.window_notice_at)";
        } elseif ($f['st'] === 'unassigned') {
            $w[] = "c.status = 'open' AND c.assigned_to = 0";
        }
        // Fechas: chats con actividad dentro del periodo.
        if ($f['from'] !== '') {
            $w[] = 'c.updated_at >= ?';
            $args[] = self::get_date('from')->getTimestamp() * 1000;
        }
        if ($f['to'] !== '') {
            $w[] = 'c.created_at < ?';
            $args[] = self::get_date('to')->modify('+1 day')->getTimestamp() * 1000;
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $or = array('c.name LIKE ?', 'c.email LIKE ?', 'c.phone LIKE ?',
                'EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND (m.body LIKE ? OR m.body_translated LIKE ?))');
            array_push($args, $like, $like, $like, $like, $like);
            $digits = preg_replace('/\D/', '', $f['q']);
            if (strlen($digits) >= 5 && preg_match('/^[\d\s+().\-]+$/', $f['q'])) {
                $or[] = 'c.phone LIKE ?'; // teléfono escrito con espacios o guiones
                $args[] = '%' . $digits . '%';
            }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        $where = implode(' AND ', $w);
        // Una fila por persona (mismo correo o teléfono), como en el programa de Windows.
        $pk = "CASE WHEN c.email <> '' THEN CONCAT('e:', LOWER(c.email)) WHEN c.phone <> '' THEN CONCAT('p:', c.phone) ELSE CONCAT('c:', c.id) END";
        $total = (int) DB::val("SELECT COUNT(DISTINCT $pk) FROM conversations c WHERE $where", $args);
        $per = 30;
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $groups = DB::rows("SELECT $pk AS pk, COUNT(*) AS n, MAX(c.updated_at) AS u, SUBSTRING_INDEX(GROUP_CONCAT(c.id ORDER BY c.updated_at DESC, c.id DESC), ',', 1) AS last_id
            FROM conversations c WHERE $where GROUP BY pk ORDER BY u DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);

        $opts = function (array $list, $cur) {
            $o = '';
            foreach ($list as $k => $l) {
                $o .= '<option value="' . self::h($k) . '"' . ((string) $k === (string) $cur ? ' selected' : '') . '>' . self::h($l) . '</option>';
            }
            return $o;
        };
        $html = '<form method="get" action="/admin/chats" class="card chat-bar">'
            . '<div class="field q"><label for="q">Buscar</label><input id="q" type="text" name="q" maxlength="100" value="' . self::h($f['q']) . '" placeholder="Nombre, correo, teléfono o texto del chat"></div>'
            . '<div class="field"><label for="ch">Canal</label><select id="ch" name="ch">' . $opts(array('' => 'Todos') + self::CHANNELS, $f['ch']) . '</select></div>'
            . '<div class="field"><label for="st">Estado</label><select id="st" name="st">' . $opts($states, $f['st']) . '</select></div>'
            . '<div class="field"><label for="ag">Atiende</label><select id="ag" name="ag">' . $opts(array('' => 'Cualquiera') + $names, $f['ag']) . '</select></div>'
            . '<div class="field"><label for="from">Desde</label><input id="from" type="date" name="from" value="' . self::h($f['from']) . '"></div>'
            . '<div class="field"><label for="to">Hasta</label><input id="to" type="date" name="to" value="' . self::h($f['to']) . '"></div>'
            . '<button class="btn btn-primary">Buscar</button></form>';

        $rows = '';
        foreach ($groups as $g) {
            $c = Core::conv($g['last_id']);
            if (!$c) {
                continue;
            }
            $n = (int) $g['n'];
            $asg = (int) $c['assigned_to'];
            $rows .= '<tr class="chat-row"><td><b>' . self::h(self::conv_name($c)) . '</b>' . ($n > 1 ? ' <span class="badge b-off">' . $n . ' chats</span>' : '')
                . ($c['email'] !== '' ? '<br><small>' . self::h($c['email']) . '</small>' : '')
                . '<div class="prev-line">' . self::h(mb_substr($c['last_preview'], 0, 110)) . '</div></td>'
                . '<td>' . self::h(self::CHANNELS[$c['channel']] ?? $c['channel']) . '</td><td>' . self::conv_badge($c) . '</td>'
                . '<td>' . ($asg ? self::h($names[$asg] ?? '(eliminado)') : '<span class="muted">Sin asignar</span>') . '</td>'
                . '<td><small>' . self::when($c['updated_at']) . '</small></td>'
                . '<td class="actions">' . ($c['status'] === 'open' ? self::resolve_button($me, $c, $keep + ($page > 1 ? array('pg' => $page) : array())) . ' ' : '') . self::nav_button('Ver', '/admin/chats', 'btn btn-secondary btn-sm', array('id' => (int) $c['id']) + $keep + ($page > 1 ? array('pg' => $page) : array())) . '</td></tr>';
        }
        $pager = '';
        if ($pages > 1) {
            $pager = '<div class="pager"><div>' . ($page > 1 ? self::nav_button('← Más recientes', '/admin/chats', 'btn btn-secondary btn-sm', $keep + array('pg' => $page - 1)) : '') . '</div>'
                . '<span class="muted">Página ' . $page . ' de ' . $pages . '</span>'
                . '<div>' . ($page < $pages ? self::nav_button('Más antiguos →', '/admin/chats', 'btn btn-secondary btn-sm', $keep + array('pg' => $page + 1)) : '') . '</div></div>';
        }
        $html .= '<div class="card">'
            . '<div class="pager" style="margin:0 0 12px"><span class="muted">' . ($total === 1 ? '1 conversación' : $total . ' conversaciones') . '</span>'
            . ($keep ? self::nav_button('Quitar filtros', '/admin/chats', 'btn btn-ghost btn-sm') : '') . '</div>'
            . ($rows ? '<div class="table-wrap"><table><thead><tr><th>Cliente</th><th>Canal</th><th>Estado</th><th>Atiende</th><th>Última actividad</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager
                : '<p class="muted">' . ($keep ? 'Ningún chat coincide con estos filtros.' : 'Todavía no hay chats.') . '</p>') . '</div>';
        self::layout('Chats', 'Consulta de todos los chats del equipo', $html, $me);
    }

    /** Conversación completa de la persona del chat $c: todos sus chats y canales en un solo hilo (solo lectura). */
    private static function chat_view($me, $c, array $names, array $back)
    {
        $convs = array((int) $c['id'] => $c);
        foreach (Core::person_convs($c, 'is_test = 0', 200) as $r) {
            $convs[(int) $r['id']] = $r;
        }
        $in = implode(',', array_map('intval', array_keys($convs)));
        // De 300 en 300 mensajes, los últimos primero.
        $per = 300;
        $before = isset($_GET['before']) ? max(0, (int) $_GET['before']) : 0;
        $msgs = DB::rows("SELECT * FROM messages WHERE conversation_id IN ($in)" . ($before ? " AND id < $before" : '') . ' ORDER BY id DESC LIMIT ' . ($per + 1));
        $more = count($msgs) > $per;
        $msgs = array_reverse(array_slice($msgs, 0, $per));

        $t = '';
        $last = 0;
        $anchors = array();
        foreach ($msgs as $m) {
            $cid = (int) $m['conversation_id'];
            $mc = $convs[$cid];
            if ($cid !== $last) {
                $anchor = isset($anchors[$cid]) ? '' : ' id="c' . $cid . '"';
                $anchors[$cid] = true;
                if (count($convs) > 1) {
                    $t .= '<div class="tmsg t-sep"' . $anchor . '>' . self::h(self::CHANNELS[$mc['channel']] ?? $mc['channel']) . ' · chat del ' . self::when($mc['created_at']) . '</div>';
                }
                $last = $cid;
            }
            $dir = $m['direction'];
            $who = $dir === 'in' ? self::conv_name($mc) : ($dir === 'out' ? ($names[(int) $m['agent_id']] ?? '(eliminado)') : ($dir === 'bot' ? 'Mensaje automático' : 'Nota'));
            $body = nl2br(self::h($m['body']), false);
            if ($m['body_translated']) {
                $body .= '<br><small>' . ($dir === 'in' ? 'Traducción' : 'Enviado al cliente') . ': ' . nl2br(self::h($m['body_translated']), false) . '</small>';
            }
            $url = $m['media_url'] ? Media::url($m['media_url']) : '';
            if ($url !== '') {
                $label = $m['media_type'] === 'image' ? '<img src="' . self::h($url) . '" alt="' . self::h($m['media_name'] ?: 'Imagen') . '" loading="lazy">' : '📎 ' . self::h($m['media_name'] ?: 'Adjunto');
                $body .= ($body !== '' ? '<br>' : '') . '<a href="' . self::h($url) . '" target="_blank" rel="noopener">' . $label . '</a>';
            }
            if ($m['send_status'] === 'error') {
                $body .= '<div class="err">⚠ No entregado: ' . self::h($m['send_error']) . '</div>';
            }
            if ((int) $m['deleted']) {
                $body = '<em class="muted">🗑 Mensaje borrado</em>';
            }
            $cls = in_array($dir, array('in', 'out', 'bot', 'system'), true) ? $dir : 'system';
            $t .= '<div class="tmsg t-' . $cls . '"><div class="tw">' . self::h($who) . ' · ' . self::when($m['created_at']) . ((int) $m['changed_at'] && !(int) $m['deleted'] ? ' · editado' : '') . '</div>' . $body . '</div>';
        }

        // Datos de la persona: los del chat abierto y, si faltan, los del más reciente que los tenga.
        $field = function ($k) use ($c, $convs) {
            if (trim($c[$k]) !== '') {
                return $c[$k];
            }
            foreach ($convs as $x) {
                if (trim($x[$k]) !== '') {
                    return $x[$k];
                }
            }
            return '';
        };
        $langs = array('es' => 'Español', 'en' => 'Inglés', 'fr' => 'Francés', 'de' => 'Alemán', 'pt' => 'Portugués', 'it' => 'Italiano', 'nl' => 'Neerlandés', 'pl' => 'Polaco', 'ar' => 'Árabe', 'zh' => 'Chino', 'ru' => 'Ruso');
        $phones = array();
        $pages = array();
        foreach ($convs as $x) {
            if (trim($x['phone']) !== '') {
                $phones[trim($x['phone'])] = true;
            }
            if ($x['channel'] === 'web' && $x['page_url'] !== '' && count($pages) < 3) {
                $pages[$x['page_url']] = true;
            }
        }
        $facts = array(
            'Correo'   => $field('email'),
            'Teléfono' => implode(', ', array_map('strval', array_keys($phones))),
            'País'     => strtoupper($field('country')),
            'Idioma'   => $langs[$c['lang']] ?? strtoupper($c['lang']),
            'Página'   => implode("\n", array_keys($pages)),
        );
        $dl = '';
        foreach ($facts as $k => $v) {
            if ($v !== '') {
                $dl .= '<dt>' . $k . '</dt><dd>' . nl2br(self::h($v), false) . '</dd>';
            }
        }
        // Respuestas a las preguntas del robot (manda la del chat más reciente).
        $answers = array();
        foreach (array_reverse($convs) as $x) {
            foreach (Robot::answers($x) as $r) {
                $answers[$r['label']] = $r['value'];
            }
        }
        foreach ($answers as $k => $v) {
            $dl .= '<dt>' . self::h($k) . '</dt><dd>' . self::h($v) . '</dd>';
        }
        $list = '';
        foreach ($convs as $x) {
            $asg = (int) $x['assigned_to'];
            $list .= '<li><span><b>' . self::h(self::CHANNELS[$x['channel']] ?? $x['channel']) . '</b> · ' . self::when($x['created_at']) . '<br><small>'
                . ($asg ? 'Atiende ' . self::h($names[$asg] ?? '(eliminado)') : 'Sin asignar')
                . ((int) $x['first_response_at'] ? ' · 1.ª respuesta ' . self::dur((int) $x['first_response_at'] - (int) $x['created_at']) : '') . '</small><br>' . self::conv_badge($x) . (count($convs) > 1 ? ' ' . self::resolve_button($me, $x, array('id' => (int) $c['id']) + $back, 'btn btn-ghost btn-sm') : '') . '</span>'
                . (count($convs) > 1 && isset($anchors[(int) $x['id']]) ? '<a class="btn btn-ghost btn-sm" href="#c' . (int) $x['id'] . '">Ir</a>' : '') . '</li>';
        }
        $older = $more ? self::nav_button('↑ Ver mensajes anteriores', '/admin/chats', 'btn btn-secondary btn-sm', array('id' => (int) $c['id'], 'before' => (int) $msgs[0]['id']) + $back) : '';
        $newer = $before ? self::nav_button('↓ Volver a los últimos', '/admin/chats', 'btn btn-secondary btn-sm', array('id' => (int) $c['id']) + $back) : '';

        $html = '<div class="split"><div class="card">' . ($older ? '<div style="margin-bottom:12px">' . $older . '</div>' : '')
            . '<div class="tconv tall" data-end tabindex="0" aria-label="Mensajes">' . ($t ?: '<p class="muted">Sin mensajes.</p>') . '</div>'
            . ($newer ? '<div style="margin-top:12px">' . $newer . '</div>' : '') . '</div>'
            . '<div class="card"><h2>Cliente</h2>' . ($dl !== '' ? '<dl class="facts">' . $dl . '</dl>' : '<p class="muted">Sin datos de contacto.</p>')
            . '<h2>' . (count($convs) === 1 ? '1 chat' : count($convs) . ' chats de esta persona') . '</h2><ul class="chat-list">' . $list . '</ul>'
            . (count($convs) > 1 ? '<p class="muted">Agrupados por el mismo correo o teléfono. En la web, el correo lo escribe el visitante y no está verificado.</p>' : '') . '</div></div>';
        $title = $field('name') !== '' ? $field('name') : self::conv_name($c);
        self::layout($title, 'Consultar un chat no lo marca como leído ni atendido', $html, $me, '<div class="ai-acts">' . self::resolve_button($me, $c, array('id' => (int) $c['id']) + $back, $c['status'] === 'open' ? 'btn btn-primary' : 'btn btn-secondary') . self::nav_button('← Volver a los chats', '/admin/chats', 'btn btn-secondary', $back) . '</div>');
    }

    /** Acciones de la pestaña Roles (guardar permisos, crear y borrar roles). */
    private static function roles_post($me)
    {
        self::check_csrf();
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'roles_save') {
                $names = (array) ($_POST['names'] ?? array());
                $perms = (array) ($_POST['perms'] ?? array());
                foreach (Roles::all() as $key => $r) {
                    Roles::save_perms($key, array_keys((array) ($perms[$key] ?? array())));
                    if (isset($names[$key]) && trim((string) $names[$key]) !== $r['name']) {
                        Roles::rename($key, (string) $names[$key]);
                    }
                }
                // Nadie puede quitarse a sí mismo el acceso al panel.
                if (!in_array('admin_panel', Roles::perms($me['role']), true)) {
                    $p = Roles::perms($me['role']);
                    $p[] = 'admin_panel';
                    Roles::save_perms($me['role'], $p);
                    self::flash('Permisos guardados. Se ha mantenido «Panel de administración» en tu propio rol para que no pierdas el acceso.');
                } else {
                    self::flash('Permisos de los roles guardados. Se aplican al momento en los programas de Windows.');
                }
            } elseif ($action === 'role_create') {
                $from = (string) ($_POST['copy_from'] ?? '');
                $key = Roles::create((string) ($_POST['role_name'] ?? ''), Roles::exists($from) ? Roles::perms($from) : array());
                self::flash('Rol «' . Roles::name($key) . '» creado. Ajusta sus permisos en la tabla y asígnalo en Usuarios.');
            } elseif ($action === 'role_delete') {
                $key = (string) ($_POST['role_key'] ?? '');
                if (empty($_POST['confirm_delete'])) {
                    throw new ApiError('Marca «confirmar» para borrar el rol.');
                }
                $name = Roles::name($key);
                Roles::delete($key);
                self::flash('Rol «' . $name . '» borrado.');
            }
        } catch (ApiError $e) {
            self::flash($e->getMessage(), true);
        }
        self::redirect('/admin/settings?tab=roles');
    }

    /** Pestaña Roles: tabla de permisos por rol, crear y borrar roles. */
    private static function roles_tab($schema, $me)
    {
        $roles = Roles::all();
        $counts = array();
        foreach (DB::rows('SELECT role, COUNT(*) n FROM agents GROUP BY role') as $r) {
            $counts[$r['role']] = (int) $r['n'];
        }
        $head = '<tr><th>Permiso</th>';
        foreach ($roles as $key => $r) {
            $head .= '<th class="role-col"><input type="text" name="names[' . self::h($key) . ']" value="' . self::h($r['name']) . '" maxlength="60" aria-label="Nombre del rol">'
                . '<small>' . ($counts[$key] ?? 0) . ' usuario(s)' . ($r['builtin'] ? ' · predefinido' : '') . '</small></th>';
        }
        $head .= '</tr>';
        $rows = '';
        foreach (Roles::PERMS as $perm => $info) {
            $rows .= '<tr><td><b>' . self::h($info[0]) . '</b><br><small>' . self::h($info[1]) . '</small></td>';
            foreach ($roles as $key => $r) {
                $checked = in_array($perm, $r['perms'], true);
                $locked = $key === 'admin' && $perm === 'admin_panel';
                $rows .= '<td class="role-col"><input type="checkbox" class="perm" name="perms[' . self::h($key) . '][' . $perm . ']" value="1"'
                    . ($checked ? ' checked' : '') . ($locked ? ' checked disabled title="El Administrador siempre conserva el acceso al panel"' : '')
                    . ' aria-label="' . self::h($info[0] . ' — ' . $r['name']) . '">'
                    . ($locked ? '<input type="hidden" name="perms[admin][admin_panel]" value="1">' : '') . '</td>';
            }
            $rows .= '</tr>';
        }
        $matrix = '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="action" value="roles_save">'
            . '<h2>Permisos por rol</h2><p class="muted">Marca lo que puede hacer cada rol. Los cambios se aplican al momento: los programas de Windows muestran u ocultan los botones según el rol de cada usuario.</p>'
            . '<div class="table-wrap"><table class="roles">' . $head . $rows . '</table></div>'
            . '<div class="savebar"><button type="reset" class="btn btn-secondary">Deshacer cambios</button><button class="btn btn-primary">💾 Guardar permisos</button></div></form>';

        $copy = '';
        foreach ($roles as $key => $r) {
            $copy .= '<option value="' . self::h($key) . '">' . self::h($r['name']) . '</option>';
        }
        $create = '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="action" value="role_create">'
            . '<h2>➕ Nuevo rol</h2><div class="filters">'
            . '<div class="field" style="flex:1;min-width:200px"><label for="role_name">Nombre</label><input id="role_name" type="text" name="role_name" maxlength="60" required placeholder="Ej.: Soporte técnico"></div>'
            . '<div class="field"><label for="copy_from">Copiar permisos de</label><select id="copy_from" name="copy_from"><option value="">(ninguno)</option>' . $copy . '</select></div>'
            . '<button class="btn btn-primary">Crear rol</button></div></form>';

        $del = '';
        foreach ($roles as $key => $r) {
            if ($r['builtin']) {
                continue;
            }
            $n = $counts[$key] ?? 0;
            $del .= '<form method="post" class="del-row">' . self::csrf_field() . '<input type="hidden" name="action" value="role_delete"><input type="hidden" name="role_key" value="' . self::h($key) . '">'
                . '<span><b>' . self::h($r['name']) . '</b> <small>(' . $n . ' usuario(s))</small></span>'
                . ($n ? '<small class="muted">Cámbiales el rol en Usuarios para poder borrarlo.</small>'
                      : '<label class="check"><input type="checkbox" name="confirm_delete" value="1" required> confirmar</label><button class="btn btn-danger btn-sm">🗑 Borrar</button>')
                . '</form>';
        }
        $delete = $del ? '<div class="card"><h2>Borrar roles personalizados</h2><p class="muted">Los roles predefinidos (Administrador, Recepcionista, Comercial) no se pueden borrar, pero sí cambiar sus permisos.</p>' . $del . '</div>' : '';

        self::layout('Configuración', 'Roles y permisos del equipo', self::settings_tabs($schema, 'roles') . $matrix . $create . $delete, $me);
    }

    private static function settings($method, $me)
    {
        $schema = Settings::schema();
        if ($method === 'POST' && in_array($_POST['action'] ?? '', array('roles_save', 'role_create', 'role_delete'), true)) {
            self::roles_post($me);
        }
        if ($method === 'GET' && ($_GET['tab'] ?? '') === 'roles') {
            self::roles_tab($schema, $me);
        }
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'testmail') {
            // Diagnóstico: envía un correo de prueba y muestra el error exacto del servidor de correo si falla.
            self::check_csrf();
            $to = strtolower(trim((string) ($_POST['test_to'] ?? '')));
            if (!valid_email($to)) {
                self::flash('Escribe un correo de destino válido para la prueba.', true);
            } elseif (!rate_limit('testmail:' . $me['id'], 10, 3600)) {
                self::flash('Demasiadas pruebas en la última hora.', true);
            } else {
                $ok = Mailer::send($to, 'Prueba de correo de Bezares Chat', "Este es un correo de prueba enviado desde el panel de Bezares Chat (" . cfg('base_url') . ").\n\nSi lo has recibido en la bandeja de entrada, el envío funciona. Si ha llegado a spam o cuarentena, revisa la configuración SPF/DMARC del dominio remitente.\n\nEnviado por: " . $me['name']);
                $msg = $ok
                    ? 'El servidor de correo aceptó la prueba para ' . $to . ' (vía ' . Mailer::transport() . '). Si no llega en unos minutos, revisa spam o la cuarentena de Office 365.'
                    : 'ERROR al enviar a ' . $to . ' (vía ' . Mailer::transport() . '): ' . (Mailer::$lastError ?: 'sin detalle');
                log_error('Prueba de correo: ' . $msg);
                self::flash($msg, !$ok);
            }
            self::redirect('/admin/settings?tab=' . max(0, (int) ($_POST['tab'] ?? 0)));
        }
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'testai') {
            // Diagnóstico: traduce una frase al francés y muestra el error exacto de OpenAI si falla.
            self::check_csrf();
            if (!rate_limit('testai:' . $me['id'], 20, 3600)) {
                self::flash('Demasiadas pruebas en la última hora.', true);
            } else {
                $tr = AI::translate('Hola, ¿en qué podemos ayudarte?', 'fr');
                self::flash($tr
                    ? 'La traducción funciona. «Hola, ¿en qué podemos ayudarte?» → «' . $tr . '»'
                    : 'ERROR de traducción: ' . (AI::$lastError ?: 'sin detalle'), !$tr);
            }
            self::redirect('/admin/settings?tab=' . max(0, (int) ($_POST['tab'] ?? 0)));
        }
        if ($method === 'POST' && in_array($_POST['action'] ?? '', array('testwa', 'usewa'), true)) {
            self::check_csrf();
            $seen = Channels::wa_seen();
            // Solo se admite el ID que Meta firmó en el último mensaje recibido o uno de los que devolvió la comprobación.
            $wid = isset($_POST['wid']) ? (string) $_POST['wid'] : ($seen ? $seen['id'] : '');
            $use = $_POST['action'] === 'usewa' && $wid !== '' && MetaCheck::can_use($wid);
            if ($use) {
                Settings::save(array('wa_phone_id' => $wid));
                log_error('Phone number ID de WhatsApp cambiado a ' . $wid . ' por ' . $me['name']);
            }
            $pre = $use ? 'ID cambiado a ' . $wid . '. ' : '';
            if (!rate_limit('testwa:' . $me['id'], 20, 3600)) {
                self::flash($pre . 'Demasiadas comprobaciones en la última hora.', true);
            } else {
                try {
                    $res = MetaCheck::run();
                    $bad = count(array_filter(array_merge($res['wa'], $res['fb']), function ($r) { return $r['s'] === 'bad'; }));
                    self::flash($pre . ($bad ? 'Comprobación terminada: ' . $bad . ($bad === 1 ? ' fallo. Mira abajo cómo arreglarlo.' : ' fallos. Mira abajo cómo arreglarlos.') : 'Comprobación terminada: no hay fallos.'), $bad > 0);
                } catch (Throwable $e) {
                    log_error('Comprobación de Meta: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                    self::flash($pre . 'La comprobación ha fallado. El detalle está en el registro del servidor.', true);
                }
            }
            self::redirect('/admin/settings?tab=' . max(0, (int) ($_POST['tab'] ?? 0)) . '#permisos');
        }
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'resend') {
            self::check_csrf();
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_filter($_POST['ids'], 'is_string') : array();
            if (!self::can_chats($me)) {
                self::flash('No tienes permiso para reenviar mensajes.', true);
            } elseif (!$ids) {
                self::flash('Marca al menos un mensaje para reenviarlo.', true);
            } elseif (!rate_limit('resend:' . $me['id'], 20, 3600)) {
                self::flash('Demasiados reenvíos en la última hora.', true);
            } else {
                ignore_user_abort(true);
                try {
                    $n = Core::resend($ids, $me);
                    $t = array($n['sent'] === 1 ? '1 mensaje reenviado' : $n['sent'] . ' mensajes reenviados');
                    if ($n['failed']) {
                        $t[] = $n['failed'] . ' sin entregar (' . mb_substr($n['error'], 0, 160) . ')';
                    }
                    if ($n['closed']) {
                        $t[] = $n['closed'] . ' fuera de las 24 h';
                    }
                    if ($n['left']) {
                        $t[] = $n['left'] . ' sin intentar: ' . ($n['failed'] ? 'arregla el error y repite' : 'vuelve a pulsar el botón');
                    }
                    self::flash(implode(' · ', $t) . '.', $n['sent'] === 0 || $n['failed'] > 0 || $n['left'] > 0);
                } catch (Throwable $e) {
                    log_error('Reenvío de mensajes: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                    self::flash('El reenvío ha fallado. El detalle está en el registro del servidor.', true);
                }
            }
            self::redirect('/admin/settings?tab=' . max(0, (int) ($_POST['tab'] ?? 0)) . '#reenviar');
        }
        if ($method === 'POST') {
            self::check_csrf();
            $tab = max(0, min(count($schema) - 1, (int) ($_POST['tab'] ?? 0)));
            if ($schema[$tab]['group'] === 'Robot') {
                self::redirect('/admin/robot'); // se guarda en su propia página
            }
            self::save_group($schema[$tab]['fields']);
            self::flash('Cambios guardados en «' . $schema[$tab]['group'] . '»');
            self::redirect('/admin/settings?tab=' . $tab);
        }
        $tab = isset($_GET['tab']) ? max(0, min(count($schema) - 1, (int) $_GET['tab'])) : 0;

        if ($schema[$tab]['group'] === 'Robot') {
            self::redirect('/admin/robot');
        }
        $tabs = self::settings_tabs($schema, $tab);

        $fields = '';
        foreach ($schema[$tab]['fields'] as $f) {
            $fields .= self::field($f, Settings::get($f['k']));
        }
        if ($schema[$tab]['group'] === 'WhatsApp y Facebook') {
            $fields = self::meta_guide() . $fields;
        }

        $html = $tabs . '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="tab" value="' . $tab . '">'
            . '<h2>' . self::h($schema[$tab]['group']) . '</h2>' . $fields
            . '<div class="savebar">' . '<button type="reset" class="btn btn-secondary">Deshacer cambios</button><button type="submit" class="btn btn-primary">💾 Guardar</button></div></form>';
        // Diagnóstico del correo (recuperación de contraseñas y envío de conversaciones): solo en la pestaña General.
        $from = (string) cfg('mail_from', '');
        if ($tab === 0) $html .= '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="action" value="testmail"><input type="hidden" name="tab" value="' . $tab . '">'
            . '<h2>✉ Probar el envío de correo</h2>'
            . '<div class="info-box">Remitente: <b>' . self::h($from ?: '(sin configurar)') . '</b> · Envío: <b>' . self::h(Mailer::transport()) . '</b><br>'
            . 'Se configura en <code>config.php</code> (mail_from y smtp_*). Si el remitente es de un dominio con Office 365 (como @bezares.com), usa el SMTP de Office 365 (<code>smtp.office365.com</code>, puerto 587, tls) con un buzón real: si no, SPF/DMARC hará que los correos acaben en cuarentena.</div>'
            . '<div class="filters"><div class="field" style="flex:1;min-width:240px"><label for="test_to">Enviar una prueba a</label><input id="test_to" type="email" name="test_to" required value="' . self::h($me['email']) . '"></div>'
            . '<button class="btn btn-secondary">Enviar prueba</button></div></form>';
        if ($schema[$tab]['group'] === 'Traducción (ChatGPT)') {
            $html .= self::ai_card($tab);
        }
        if ($schema[$tab]['group'] === 'WhatsApp y Facebook') {
            $html = $tabs . self::wa_card($tab) . self::resend_card($tab, $me) . substr($html, strlen($tabs)); // arriba: el formulario es largo
        }
        self::layout('Configuración', 'Widget, traducción y canales de WhatsApp/Facebook', $html, $me);
    }

    /** Guarda los ajustes enviados por el formulario de un grupo. */
    private static function save_group(array $fields)
    {
        $in = array();
        foreach ($fields as $f) {
            $k = $f['k'];
            if ($f['type'] === 'info') {
                continue;
            }
            if ($f['type'] === 'bool') {
                $in[$k] = !empty($_POST[$k]);
            } elseif ($f['type'] === 'secret') {
                if (!empty($_POST[$k . '__clear'])) {
                    $in[$k] = '';
                } elseif (trim((string) ($_POST[$k] ?? '')) !== '') {
                    $in[$k] = $_POST[$k];
                }
            } elseif (isset($_POST[$k])) {
                $in[$k] = $_POST[$k];
            }
        }
        Settings::save($in);
    }

    /* ================================================================ robot (mensajes automáticos) */

    /**
     * Pasos del robot, en el orden del diagrama. Refleja lo que hacen Core::bot_flow, Core::insist_email y Cron::reminders:
     * si cambia su funcionamiento, hay que cambiarlo también aquí.
     * on = interruptor; msgs = textos (clave sin _es/_en => título); opts = otros ajustes; when = cuándo se envía.
     */
    const ROBOT = array(
        'welcome'  => array('title' => 'Bienvenida en la web', 'msgs' => array('welcome' => ''), 'opts' => array(), 'vars' => array('company'),
            'when' => 'Responde al primer mensaje del cliente (su consulta) y le pide el nombre. También en WhatsApp o Facebook si el perfil no trae nombre.'),
        'ask'      => array('title' => 'Pide el correo', 'msgs' => array('ask_email' => ''), 'opts' => array(), 'vars' => array('name', 'company'),
            'when' => 'Se envía cuando el cliente da su nombre. Si ya ha escrito el correo, se salta.'),
        'known'    => array('title' => 'Bienvenida en WhatsApp y Facebook', 'on' => 'onboarding_social', 'msgs' => array('welcome_known' => ''), 'opts' => array(), 'vars' => array('name', 'company'),
            'when' => 'Responde al primer mensaje y pide el correo; el nombre se toma del perfil. Si lo desactivas, en WhatsApp y Facebook no se pide ningún dato.'),
        'insist'   => array('title' => 'Insiste en el correo', 'msgs' => array('need_email' => 'Si contesta otra cosa', 'invalid_email' => 'Si el correo está mal escrito'), 'opts' => array('email_asks'), 'vars' => array('name', 'company'),
            'when' => 'Se repite hasta que el cliente da un correo válido o le contesta una persona.'),
        'hours'    => array('title' => 'Horario y chat cerrado', 'msgs' => array('closed' => 'Texto de la ventana de chat de la web fuera de horario'), 'opts' => array('hours', 'holidays'), 'vars' => array('hours', 'company'),
            'when' => 'Decide qué contesta el robot y el color del circulito de la web.'),
        'thanks'   => array('title' => 'Gracias, en horario', 'msgs' => array('thanks' => ''), 'opts' => array(), 'vars' => array('name', 'email', 'company'),
            'when' => 'Al recibir un correo válido, dentro de horario (o siempre, si la respuesta fuera de horario está desactivada).'),
        'ooh'      => array('title' => 'Fuera de horario', 'on' => 'ooh_enabled', 'msgs' => array('ooh' => ''), 'opts' => array(), 'vars' => array('name', 'email', 'company'),
            'when' => 'Al recibir un correo válido, fuera de horario. Después, como mucho una vez cada 12 horas si vuelve a escribir.'),
        'reminder' => array('title' => 'Recordatorio', 'on' => 'reminder_enabled', 'msgs' => array('reminder' => ''), 'opts' => array('reminder_minutes'), 'vars' => array('name', 'company'),
            'when' => 'Si nadie contesta al cliente, solo en horario laboral. Además vuelve a sonar el aviso a los agentes.'),
        'window'   => array('title' => 'Límite de WhatsApp', 'on' => 'window_notice_enabled', 'msgs' => array('window_notice' => 'Si tenemos su correo', 'window_noemail' => 'Si no tenemos su correo'), 'opts' => array('window_hours'), 'vars' => array('name', 'email', 'company'),
            'when' => 'Solo WhatsApp: si nadie ha contestado, avisa de que se responderá por correo y deja de contar el tiempo de respuesta. A las 24 h WhatsApp ya no deja escribirle. Necesita el cron del servidor (o algún programa de Windows conectado a esa hora).'),
        'followup' => array('title' => 'Seguimiento', 'on' => 'followup_enabled', 'msgs' => array('followup' => ''), 'opts' => array('followup_hours'), 'vars' => array('name', 'company'),
            'when' => 'Si un agente contestó y el cliente no responde. Solo se envía dentro de las 24 h desde el último mensaje del cliente.'),
    );

    private static function robot_page($method, $me)
    {
        $fields = array();
        foreach (Settings::schema() as $g) {
            if ($g['group'] === 'Robot') {
                $fields = $g['fields'];
            }
        }
        $live = Flow::live();
        // Con el diagrama publicado, en «Tiempos y horario» solo quedan los pasos que no dependen del camino.
        $only = $live ? array_values(array_diff(array('hours', 'ooh', 'reminder', 'window', 'followup'), Flow::handles_window() ? array('window') : array())) : array_keys(self::ROBOT);
        if ($live) {
            $keep = array('robot_steps' => true, 'onboarding_social' => true);
            foreach ($only as $k) {
                $s = self::ROBOT[$k];
                $keep += array_fill_keys($s['opts'], true) + (isset($s['on']) ? array($s['on'] => true) : array());
                foreach ($s['msgs'] as $m => $_) {
                    $keep += array($m . '_es' => true, $m . '_en' => true);
                }
            }
            $fields = array_values(array_filter($fields, function ($f) use ($keep) { return isset($keep[$f['k']]); }));
        }
        if ($method === 'POST') {
            self::check_csrf();
            $action = (string) ($_POST['action'] ?? '');
            $back = '/admin/robot';
            try {
                if ($action === 'draft' || $action === 'publish') {
                    $graph = Flow::clean((string) ($_POST['graph'] ?? ''));
                    if ($action === 'publish') {
                        Flow::publish($graph, $me);
                        self::flash('Diagrama publicado: los chats nuevos ya lo usan.');
                    } else {
                        Flow::save_draft($graph);
                        self::flash('Borrador guardado. Todavía no lo ven los clientes.');
                    }
                } elseif ($action === 'off') {
                    Flow::off($me);
                    self::flash('Vuelve a funcionar el robot anterior. El diagrama queda como borrador.');
                } elseif ($action === 'discard') {
                    Flow::discard();
                    self::flash('Borrador descartado.');
                } else {
                    $step = (string) ($_POST['step'] ?? '');
                    $back = '/admin/robot?vista=tiempos' . (preg_match('/^[a-z]{1,12}\d{0,8}$/', $step) ? '&step=' . $step : '');
                    self::save_group($fields);
                    self::flash('Cambios guardados');
                }
            } catch (ApiError $e) {
                self::flash($e->getMessage(), true);
            }
            self::redirect($back);
        }
        $times = ($_GET['vista'] ?? '') === 'tiempos';
        $tab = function ($v, $l, $on) { return '<button type="submit" name="vista" value="' . $v . '" class="btn' . ($on ? ' on' : '') . '" role="tab" aria-selected="' . ($on ? 'true' : 'false') . '">' . $l . '</button>'; };
        $tabs = '<form method="get" action="/admin/robot" class="tabs" role="tablist">' . $tab('', '🔀 Conversación', !$times) . $tab('tiempos', $live ? '⏱ Tiempos y horario' : '🤖 Robot en uso (anterior)', $times) . '</form>';
        self::layout('Robot', 'Qué dice el robot, cuándo lo dice y en qué horario', $tabs . ($times ? self::robot($fields, $only) : self::flow_page($live)), $me);
    }

    /** Lienzo de la conversación: lo dibuja flow.js a partir del JSON. */
    private static function flow_page($live)
    {
        $pending = Flow::pending();
        $add = function ($t, $l) { return '<button type="button" class="btn btn-secondary btn-sm" data-add="' . $t . '">' . $l . '</button>'; };
        $act = function ($a, $l, $title = '') { return '<button type="button" class="btn btn-secondary btn-sm" data-a="' . $a . '"' . ($title !== '' ? ' title="' . $title . '" aria-label="' . $title . '"' : '') . '>' . $l . '</button>'; };
        $one = function ($action, $label, $confirm) {
            return '<form method="post" action="/admin/robot" class="inline" data-confirm="' . self::h($confirm) . '">' . self::csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><button class="btn btn-ghost btn-sm">' . $label . '</button></form>';
        };
        $state = $live
            ? '<span class="badge b-on">Publicado</span><span>Los chats nuevos usan este diagrama.</span>' . ($pending ? ' <span class="badge b-custom">Borrador con cambios sin publicar</span>' : '')
            : '<span class="badge b-off">Sin publicar</span><span>Los clientes siguen con el robot anterior. Este diagrama hace lo mismo: cámbialo y pulsa <b>Publicar</b>.</span>';
        return '<div class="fl-state">' . $state . ' <span class="fl-state-a">'
            . ($pending ? $one('discard', $live ? 'Descartar borrador' : 'Empezar de nuevo', '¿Descartar los cambios del borrador?') : '')
            . ($live ? $one('off', 'Volver al robot anterior', '¿Volver al robot anterior? El diagrama se conserva como borrador.') : '') . '</span></div>'
            . '<form method="post" action="/admin/robot" id="flow" data-company="' . self::h(Settings::get('company_name')) . '">' . self::csrf_field()
            . '<input type="hidden" name="graph" value="' . self::h(json_encode(Flow::draft(), JSON_UNESCAPED_UNICODE)) . '">'
            . '<div class="fl-bar"><span>Añadir:</span>' . $add('say', '💬 Mensaje') . $add('ask', '❓ Pregunta') . $add('cond', '🔀 Bifurcación') . $add('ai', '✨ AI') . $add('end', '👤 Final') . $add('timer', '⏱ Tiempo')
            . '<span class="fl-sep"></span>' . $act('undo', '↶ Deshacer') . $act('arrange', 'Ordenar') . $act('zout', '−', 'Alejar') . $act('zin', '+', 'Acercar') . '<span class="fl-sep"></span>' . $act('test', '▶ Probar') . '</div>'
            . '<div class="fl-warn" hidden></div>'
            . '<div class="fl-grid"><div class="fl-canvas" aria-label="Diagrama del robot"><div class="fl-size"><div class="fl-world"><svg class="fl-lines" aria-hidden="true"><defs><marker id="fl-arrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0L8 4L0 8z" fill="#8a97a8"/></marker></defs></svg></div></div></div>'
            . '<div class="fl-side"><div class="fl-edit"></div><div class="fl-sim" hidden><h2>▶ Probar</h2><p class="muted">No envía nada a nadie. Usa el diagrama tal como está en pantalla.</p>'
            . '<div class="grid2"><label class="field"><span class="label">Canal</span><select data-s="channel"><option value="web">Web</option><option value="whatsapp">WhatsApp</option><option value="facebook">Facebook</option></select></label>'
            . '<label class="field"><span class="label">Horario</span><select data-s="hours"><option value="1">En horario</option><option value="0">Cerrado</option></select></label></div>'
            . '<div class="fl-timers"></div><div class="fl-log" aria-live="polite"></div><div class="fl-send"><input type="text" class="fl-in" aria-label="Mensaje del cliente" placeholder="Escribe como el cliente…">' . $act('simsend', 'Enviar') . $act('simreset', '↺', 'Empezar otra vez') . '</div></div></div></div>'
            . '<div class="savebar fl-save"><span class="rb-dirty fl-dirty" hidden>Cambios sin guardar</span><span class="fl-status"></span><button type="submit" name="action" value="draft" class="btn btn-secondary">Guardar borrador</button><button type="submit" name="action" value="publish" class="btn btn-primary">Publicar</button></div></form>'
            . '<script src="/flow.js?v=' . (int) @filemtime(BZC_ROOT . '/public/flow.js') . '" defer></script>';
    }

    /** Editor del robot anterior o, con el diagrama publicado, solo de los pasos $only (tiempos y horario). */
    private static function robot(array $fields, array $only)
    {
        $all = !array_diff(array_keys(self::ROBOT), $only);
        $f = array();
        foreach ($fields as $x) {
            $f[$x['k']] = $x;
        }
        $sel = isset($_GET['step']) && (isset(self::ROBOT[(string) $_GET['step']]) || preg_match('/^c\d{1,8}$/', (string) $_GET['step'])) ? (string) $_GET['step'] : ($all ? 'welcome' : 'hours');
        // Hueco donde robot.js dibuja los pasos propios y los botones «+».
        $slot = function ($k) { return '<div class="rb-slot" data-slot="' . $k . '"><div class="rb-v" aria-hidden="true"></div></div>'; };

        // Nodo del diagrama. $sub: texto pequeño; {clave} se sustituye por el valor del ajuste (también al escribir, con admin.js).
        $node = function ($k, $label, $sub = '') use ($sel) {
            $s = self::ROBOT[$k];
            $on = !isset($s['on']) || (int) Settings::get($s['on']);
            $txt = preg_replace_callback('/\{(\w+)\}/', function ($m) { return (int) Settings::get($m[1]); }, $sub);
            return '<button type="button" class="rb-n' . ($k === 'hours' ? ' rb-q' : '') . ($on ? '' : ' rb-off') . ($k === $sel ? ' rb-sel' : '') . '" data-step="' . $k . '"' . (isset($s['on']) ? ' data-on="' . $s['on'] . '"' : '') . ' aria-controls="step-' . $k . '">'
                . '<b>' . $label . '</b>' . ($sub !== '' ? '<small data-tpl="' . self::h($sub) . '">' . self::h($txt) . '</small>' : '') . '<em>Desactivado</em></button>';
        };
        $start = function ($t) { return '<div class="rb-n rb-start">' . $t . '</div>'; };
        $v = '<div class="rb-v" aria-hidden="true"></div>';
        $fork = '<div class="rb-fork" aria-hidden="true"></div>';
        $join = '<div class="rb-fork rb-join" aria-hidden="true"></div>';
        $sub = '<div class="rb-fork rb-sub" aria-hidden="true"></div>';
        $subJoin = '<div class="rb-fork rb-join rb-sub" aria-hidden="true"></div>';
        $lab = function ($t) { return '<div class="rb-l">' . $t . '</div>'; };

        $diagram = '<div class="rb-flow" aria-label="Diagrama de flujo del robot">'
            . $start('Escribe un cliente nuevo') . $fork
            . '<div class="rb-2"><div>' . $lab('Web') . $node('welcome', 'Bienvenida', 'Pide el nombre') . $slot('before') . $node('ask', 'Pide el correo') . '</div>'
            . '<div class="rb-fill">' . $lab('WhatsApp y Facebook') . $node('known', 'Bienvenida', 'Pide el correo') . '<div class="rb-line" aria-hidden="true"></div></div></div>' . $join
            . $start('¿Ha dado un correo válido?') . $fork
            . '<div class="rb-2"><div>' . $lab('No') . $node('insist', '↺ Insiste', 'Hasta {email_asks} veces') . '<div class="rb-line" aria-hidden="true"></div></div>'
            . '<div>' . $lab('Sí') . $slot('data') . $node('hours', '¿Estamos en horario?', Hours::summary('es') ?: 'Sin días laborables') . $sub
            . '<div class="rb-2 rb-tight"><div>' . $lab('Sí') . $node('thanks', 'Gracias') . '</div><div>' . $lab('No') . $node('ooh', 'Fuera de horario') . '</div></div>' . $subJoin . $slot('end') . '</div></div>' . $join
            . $start('Espera a una persona') . $fork
            . '<div class="rb-2"><div>' . $lab('Nadie le contesta') . $node('reminder', 'Recordatorio', 'A los {reminder_minutes} minutos, en horario') . $v . $node('window', 'Límite de WhatsApp', 'A las {window_hours} horas, solo WhatsApp') . $slot('no_agent') . '</div>'
            . '<div>' . $lab('El cliente no responde al agente') . $node('followup', 'Seguimiento', 'A las {followup_hours} horas') . $slot('no_client') . '</div></div></div>';

        if (!$all) {
            $diagram = '<div class="rb-flow" aria-label="Tiempos y horario">' . $start('Horario') . $v
                . '<div class="rb-2"><div>' . $node('hours', '¿Estamos en horario?', Hours::summary('es') ?: 'Sin días laborables') . '</div><div>' . $node('ooh', 'Vuelve a escribir fuera de horario', 'Como mucho cada 12 horas') . '</div></div>' . $join
                . $start('Espera a una persona') . $fork
                . '<div class="rb-2"><div>' . $lab('Nadie le contesta') . $node('reminder', 'Recordatorio', 'A los {reminder_minutes} minutos, en horario')
                . (in_array('window', $only, true) ? $v . $node('window', 'Límite de WhatsApp', 'A las {window_hours} horas, solo WhatsApp') : '') . $slot('no_agent') . '</div>'
                . '<div>' . $lab('El cliente no responde al agente') . $node('followup', 'Seguimiento', 'A las {followup_hours} horas') . $slot('no_client') . '</div></div></div>';
        }
        $used = array('robot_steps' => true);
        $area = function ($k, $lang) use ($f, &$used) {
            $used[$k] = true;
            return '<div class="field"><label for="' . $k . '">' . $lang . '</label><textarea class="rb-t" id="' . $k . '" name="' . $k . '" rows="3" maxlength="2000">' . self::h(Settings::get($k)) . '</textarea></div>';
        };
        $steps = '';
        foreach (self::ROBOT as $k => $s) {
            if (!in_array($k, $only, true)) {
                continue;
            }
            $steps .= '<section class="rb-step' . ($k === $sel ? ' rb-show' : '') . '" id="step-' . $k . '" data-key="' . $k . '"><h2>' . self::h($s['title']) . '</h2><p class="muted">' . self::h($s['when']) . '</p>';
            if (isset($s['on'])) {
                $used[$s['on']] = true;
                $steps .= '<label class="switch"><input type="checkbox" role="switch" name="' . $s['on'] . '" value="1" ' . ((int) Settings::get($s['on']) ? 'checked' : '') . '><span><b>Activado</b></span></label>';
            }
            if ($k === 'hours') {
                $steps .= '<div class="info-box">Zona horaria: <b>' . self::h(cfg('timezone')) . '</b> · Ahora mismo: <b>' . (Hours::is_open() ? 'DENTRO de horario' : 'FUERA de horario') . '</b></div>';
            }
            foreach ($s['opts'] as $o) {
                $used[$o] = true;
                $steps .= self::field($f[$o], Settings::get($o));
            }
            foreach ($s['msgs'] as $m => $title) {
                $steps .= '<div class="rb-msg">' . ($title !== '' ? '<h3>' . self::h($title) . '</h3>' : '') . $area($m . '_es', 'Español') . $area($m . '_en', 'Inglés')
                    . '<div class="rb-vars">' . implode('', array_map(function ($x) { return '<button type="button" class="btn btn-secondary btn-sm" data-insert="{' . $x . '}">+ {' . $x . '}</button>'; }, $s['vars'])) . '</div>'
                    . '<div class="rb-prev" aria-hidden="true"><span>Así lo ve el cliente</span><div></div></div></div>';
            }
            $steps .= '</section>';
        }
        // Editor de los pasos propios: lo rellena robot.js (los campos no tienen «name»: se envía el JSON de robot_steps).
        $select = function ($f, array $o) {
            $h = '<select data-f="' . $f . '" aria-label="' . $f . '">';
            foreach ($o as $k => $l) {
                $h .= '<option value="' . $k . '">' . $l . '</option>';
            }
            return $h . '</select>';
        };
        $row = function ($k, $label, $html, $help = '') { return '<div class="field" data-row="' . $k . '"><span class="label">' . $label . '</span>' . $html . ($help !== '' ? '<small>' . $help . '</small>' : '') . '</div>'; };
        $act = function ($do, $label, $class = 'btn-secondary') { return '<button type="button" class="btn ' . $class . ' btn-sm" data-do="' . $do . '">' . $label . '</button>'; };
        $steps .= '<section class="rb-step" id="step-custom" data-key="custom"><h2>Paso propio</h2><p class="muted rb-when"></p>'
            . '<div class="rb-acts">' . $act('up', '↑ Subir') . $act('down', '↓ Bajar') . $act('copy', 'Duplicar') . $act('del', '🗑 Eliminar', 'btn-danger') . '</div>'
            . '<label class="switch"><input type="checkbox" role="switch" data-f="on"><span><b>Activado</b></span></label>'
            . '<div class="field"><span class="label">Nombre del paso</span><input type="text" data-f="title" maxlength="60" aria-label="Nombre del paso"><small>Solo lo ve el equipo. En las preguntas, es el nombre del dato en la ficha (por ejemplo, «Modelo de camión»).</small></div>'
            . $row('slot', 'Dónde', $select('slot', array('before' => 'Antes de pedir el correo (web)', 'data' => 'Al recibir el correo', 'end' => 'Después de Gracias o Fuera de horario', 'no_agent' => 'Si nadie le contesta', 'no_client' => 'Si el cliente no responde')))
            . $row('type', 'Qué hace', $select('type', array('message' => 'Envía un mensaje', 'question' => 'Pregunta y guarda la respuesta')))
            . $row('kind', 'Respuesta que espera', $select('kind', array('text' => 'Cualquier texto', 'number' => 'Un número', 'phone' => 'Un teléfono', 'options' => 'Una de varias opciones')), 'Si la respuesta no vale, repite la pregunta una vez y después acepta lo que diga.')
            . '<div data-row="opts" class="grid2"><div class="field"><span class="label">Opciones (español)</span><textarea data-f="opts_es" rows="4" aria-label="Opciones en español" placeholder="Una por línea"></textarea></div><div class="field"><span class="label">Opciones (inglés)</span><textarea data-f="opts_en" rows="4" aria-label="Opciones en inglés" placeholder="Opcional"></textarea></div></div>'
            . $row('time', 'Cuándo', '<div class="rb-time"><input type="number" data-f="amount" min="1" max="1380" aria-label="Cantidad">' . $select('unit', array('1' => 'minutos', '60' => 'horas')) . '</div>', 'Como máximo 23 horas.')
            . '<div class="grid2">' . $row('channel', 'Canal', $select('channel', array('all' => 'Todos', 'web' => 'Web', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook')))
            . $row('when', 'Horario', $select('when', array('always' => 'Siempre', 'open' => 'Solo en horario', 'closed' => 'Solo fuera de horario'))) . '</div>'
            . '<div class="rb-msg"><div class="field"><span class="label">Español</span><textarea class="rb-t" data-f="es" rows="3" maxlength="1500" aria-label="Texto en español"></textarea></div>'
            . '<div class="field"><span class="label">Inglés (opcional)</span><textarea class="rb-t" data-f="en" rows="3" maxlength="1500" aria-label="Texto en inglés"></textarea><small>Si lo dejas vacío, se traduce solo desde el español.</small></div>'
            . '<div class="rb-vars">' . implode('', array_map(function ($x) { return '<button type="button" class="btn btn-secondary btn-sm" data-insert="{' . $x . '}">+ {' . $x . '}</button>'; }, array('name', 'email', 'company'))) . '</div>'
            . '<div class="rb-prev" aria-hidden="true"><span>Así lo ve el cliente</span><div></div></div></div></section>';
        // Red de seguridad: un ajuste nuevo que no esté en ningún paso se sigue pudiendo editar (y no se borra al guardar).
        $rest = '';
        foreach ($fields as $x) {
            if (empty($used[$x['k']])) {
                $rest .= self::field($x, Settings::get($x['k']));
            }
        }
        $own = Settings::get('robot_steps');
        $own = json_encode(array('next' => (int) ($own['next'] ?? 1), 'steps' => Robot::steps()), JSON_UNESCAPED_UNICODE);
        return '<form method="post" class="rb" id="robot" data-company="' . self::h(Settings::get('company_name')) . '" data-hours="' . self::h(Hours::summary('es')) . '" data-hours-en="' . self::h(Hours::summary('en')) . '">'
            . self::csrf_field() . '<input type="hidden" name="step" value="' . $sel . '"><input type="hidden" name="robot_steps" value="' . self::h($own) . '">'
            . '<div class="card">' . $diagram . '<p class="muted rb-hint">Pulsa un paso para editarlo o <b>+</b> para añadir uno. Los pasos propios (verdes) se pueden arrastrar a otro <b>+</b>.</p></div>'
            . '<div class="card rb-edit">' . $steps . ($rest !== '' ? '<h2>Otros ajustes</h2>' . $rest : '')
            . '<div class="savebar"><span class="rb-dirty" hidden>Cambios sin guardar</span><button type="reset" class="btn btn-secondary">Deshacer</button><button type="submit" class="btn btn-primary">💾 Guardar</button></div></div></form>'
            . '<script src="/robot.js?v=' . (int) @filemtime(BZC_ROOT . '/public/robot.js') . '" defer></script>';
    }

    /* ================================================================ AI (contesta en el robot) */

    private static function ai_page($method, $me)
    {
        if ($method === 'POST') {
            self::check_csrf();
            $action = (string) ($_POST['action'] ?? '');
            $id = (int) ($_POST['id'] ?? 0);
            $str = function ($k, $max) { return isset($_POST[$k]) && is_string($_POST[$k]) ? mb_substr(trim($_POST[$k]), 0, $max) : ''; };
            $back = '/admin/ai';
            try {
                switch ($action) {
                    case 'save':
                        Settings::put('ai_instruction', str_replace("\r\n", "\n", strip_tags($str('instruction', 4000))));
                        Settings::put('ai_enabled', empty($_POST['enabled']) ? 0 : 1);
                        Settings::put('ai_model', clean_line($str('model', 60), 60));
                        Settings::put('ai_tech', empty($_POST['tech']) ? 0 : 1);
                        Settings::put('ai_block', implode(', ', Assistant::codes($str('block', 200))));
                        self::flash('Cambios guardados');
                        break;
                    case 'default':
                        if (empty($_POST['confirm'])) {
                            throw new ApiError('Marca «confirmar» para recuperar la instrucción predeterminada');
                        }
                        Settings::put('ai_instruction', '');
                        self::flash('Se ha recuperado la instrucción predeterminada.');
                        break;
                    case 'text':
                        if ($id) {
                            Assistant::save_text($id, $str('title', 120), $str('text', Assistant::MAX_ONE));
                        } else {
                            Assistant::add_text($str('title', 120), $str('text', Assistant::MAX_ONE));
                        }
                        self::flash('Texto guardado');
                        break;
                    case 'link':
                    case 'file':
                        if (!rate_limit('ai_res:' . $me['id'], 60, 3600)) {
                            throw new ApiError('Demasiados recursos en la última hora. Inténtalo más tarde.');
                        }
                        @set_time_limit(300);
                        ignore_user_abort(true); // si se corta la conexión, termina de guardarlo
                        if ($action === 'link') {
                            $r = Assistant::add_link($str('url', 500));
                            self::flash('Enlace añadido: «' . $r['title'] . '».' . ($r['error'] !== '' ? ' ' . $r['error'] : ''), $r['status'] === 'warn');
                        } else {
                            Assistant::add_file($_FILES['file'] ?? null);
                            self::flash('Archivo subido. OpenAI tarda unos segundos en prepararlo: recarga la página para ver cuándo está listo.');
                        }
                        break;
                    case 'reload':
                        @set_time_limit(180);
                        Assistant::update_link($id);
                        self::flash('Página leída de nuevo.');
                        break;
                    case 'on':
                    case 'off':
                        Assistant::toggle($id, $action === 'on');
                        self::flash($action === 'on' ? 'Recurso activado' : 'Recurso desactivado: la AI ya no lo usa.');
                        break;
                    case 'delete':
                        if (empty($_POST['confirm'])) {
                            throw new ApiError('Marca «confirmar» para borrar el recurso');
                        }
                        Assistant::delete($id);
                        self::flash('Recurso borrado');
                        break;
                    case 'test':
                        $back .= '#probar';
                        $t = array('text' => $str('message', 1500), 'country' => Geo::norm($str('country', 2)), 'channel' => in_array($str('channel', 20), array('web', 'whatsapp', 'facebook'), true) ? $str('channel', 20) : 'web');
                        if ($t['text'] === '') {
                            throw new ApiError('Escribe el mensaje del cliente');
                        }
                        if (!rate_limit('ai_test:' . $me['id'], 40, 3600)) {
                            throw new ApiError('Demasiadas pruebas en la última hora.');
                        }
                        @set_time_limit(90);
                        $t['answer'] = Assistant::run(array(array('role' => 'user', 'content' => $t['text'])), $t['country'], $t['channel'], '');
                        $t['reason'] = Assistant::$reason;
                        $t['found'] = array_map(function ($x) { return array($x['file'], mb_substr($x['text'], 0, 900)); }, array_slice(Assistant::$found, 0, 14));
                        $_SESSION['ai_test'] = $t;
                        break;
                }
            } catch (ApiError $e) {
                self::flash($e->getMessage(), true);
            } catch (Throwable $e) {
                log_error('AI (panel): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                self::flash('No se ha podido hacer. El detalle está en el registro del servidor.', true);
            }
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'bzc-upload') { // subida con barra de progreso (admin.js): el aviso sale al recargar
                header('Content-Type: application/json; charset=utf-8');
                echo '{"done":true}';
                exit;
            }
            self::redirect($back);
        }
        try {
            Assistant::refresh_status();
        } catch (Throwable $e) {
            log_error('AI (estado): ' . $e->getMessage());
        }
        $form = function ($action, $inner, $attrs = '') {
            return '<form method="post" action="/admin/ai" ' . $attrs . '>' . self::csrf_field() . '<input type="hidden" name="action" value="' . $action . '">' . $inner . '</form>';
        };
        // Estado
        $problem = Assistant::problem();
        $last = Settings::get('ai_last');
        $err = is_array($last) && !empty($last['error']['t']) ? $last['error'] : null;
        $st = $problem !== '' ? '<div class="alert alert-bad" role="alert">⚠️ ' . self::h($problem) . '</div>'
            : ($err ? '<div class="alert alert-bad" role="alert">⚠️ La última consulta falló (' . self::when($err['t'] * 1000) . '): ' . self::h($err['msg']) . '</div>' : '<div class="info-box">✅ Lista para contestar' . (is_array($last) && !empty($last['ok']) ? ' · última respuesta de OpenAI: ' . self::when($last['ok'] * 1000) : '') . '.</div>');
        if (!Flow::has_ai()) {
            $st .= '<div class="info-box">' . (Flow::live() ? 'El diagrama publicado no tiene ninguna caja <b>✨ AI</b>: añádela en Robot y publica.' : 'El diagrama del robot no está publicado: la AI solo contesta desde el diagrama.') . '</div>';
        }
        $html = $st . '<div class="card"><h2>Instrucción</h2><p class="muted">Qué tiene que hacer la AI y a quién debe contestar. Si la instrucción no permite contestar o la respuesta no está en los recursos, se calla y el chat pasa a una persona.</p>'
            . $form('save', '<label class="switch"><input type="checkbox" role="switch" name="enabled" value="1" ' . (Assistant::enabled() ? 'checked' : '') . '><span><b>AI activada</b><small>Desactivada, el robot sigue por «No contesta».</small></span></label>'
                . '<label class="switch"><input type="checkbox" role="switch" name="tech" value="1" ' . (Assistant::technical() ? 'checked' : '') . '><span><b>Contestar preguntas técnicas en cualquier país</b><small>Solo si la respuesta está en los catálogos o en los demás recursos. Vale también para los países de abajo y aunque la instrucción diga otra cosa.</small></span></label>'
                . '<div class="field"><label for="ai_i">Instrucción</label><textarea id="ai_i" name="instruction" rows="6" maxlength="4000">' . self::h(Assistant::instruction()) . '</textarea></div>'
                . '<div class="field"><label for="ai_b">Países que no redirecciona al contacto local</label><input type="text" id="ai_b" name="block" maxlength="200" value="' . self::h(implode(', ', Assistant::blocked())) . '" placeholder="ES"><small>Códigos de dos letras separados por comas (ES = España). A estos clientes no se les da el contacto, diga lo que diga la instrucción: solo se les contestan las preguntas técnicas, si están activadas arriba. Se mira el país detectado y el que diga el cliente.</small></div>'
                . '<div class="field"><label for="ai_m">Modelo (opcional)</label><input type="text" id="ai_m" name="model" maxlength="60" value="' . self::h((string) Settings::get('ai_model')) . '" placeholder="' . self::h(Settings::get('openai_model') ?: 'gpt-4o-mini') . '"><small>Vacío: el mismo que la traducción. Usa la API key de Configuración → Traducción.</small></div>'
                . '<div class="filters"><button class="btn btn-primary">💾 Guardar</button></div>')
            . (Assistant::instruction() !== Assistant::INSTRUCTION ? $form('default', '<label class="check"><input type="checkbox" name="confirm" value="1" required> confirmar</label> <button class="btn btn-ghost btn-sm">Recuperar la instrucción predeterminada</button>', 'class="inline"') : '') . '</div>';

        // Recursos
        list(, $left, $used, $texts) = Assistant::knowledge();
        $rows = '';
        foreach (Assistant::rows() as $r) {
            $id = (int) $r['id'];
            $hid = '<input type="hidden" name="id" value="' . $id . '">';
            $ic = array('text' => '📝', 'link' => '🔗', 'file' => '📄');
            $on = (int) $r['active'];
            $state = !$on ? '<span class="badge b-off">Desactivado</span>'
                : ($r['status'] === 'wait' ? '<span class="badge b-custom" data-wait>Preparándose…</span>'

                : ($r['status'] === 'error' ? '<span class="badge b-off mc-bad">Error</span>'
                : (isset($left[$id]) ? '<span class="badge b-off mc-bad">No cabe</span>' : ($r['status'] === 'warn' ? '<span class="badge b-custom">Poco texto</span>' : '<span class="badge b-on">Listo</span>'))));
            $text = (string) ($texts[$id] ?? $r['content']); // sin lo que se repite en las demás páginas
            $r['size'] = $r['content'] !== '' ? mb_strlen($text) : $r['size'];
            $size = $r['file_id'] !== '' ? number_format($r['size'] / 1024, 0, ',', '.') . ' KB' : number_format((int) $r['size'], 0, ',', '.') . ' caracteres';
            $rows .= '<tr><td><b>' . ($ic[$r['kind']] ?? '') . ' ' . self::h($r['title']) . '</b>' . ($r['url'] !== '' ? '<br><small>' . self::h($r['url']) . '</small>' : '')
                . ($r['error'] !== '' ? '<br><small>⚠ ' . self::h($r['error']) . '</small>' : '')
                . ($r['content'] !== '' && $r['kind'] !== 'text' ? '<details><summary><small>Ver el texto que usa la AI</small></summary><div class="prev-line" style="white-space:pre-wrap">' . self::h(mb_substr($text, 0, 3000)) . (mb_strlen($text) > 3000 ? '…' : '') . '</div></details>' : '')
                . ($r['kind'] === 'text' ? '<details><summary><small>Ver y editar</small></summary>' . $form('text', $hid . self::ai_text_fields($r['title'], $r['content'], $id)) . '</details>' : '') . '</td>'
                . '<td>' . $state . '</td><td><small>' . $size . '<br>' . self::when($r['updated_at']) . '</small></td><td><div class="ai-acts">'
                . ($r['kind'] === 'link' ? $form('reload', $hid . '<button class="btn btn-secondary btn-sm">Volver a leer</button>', 'class="inline" data-busy="Leyendo…"') : '')
                . $form($on ? 'off' : 'on', $hid . '<button class="btn btn-secondary btn-sm">' . ($on ? 'Desactivar' : 'Activar') . '</button>', 'class="inline"')
                . $form('delete', $hid . '<label class="check"><input type="checkbox" name="confirm" value="1" required> confirmar</label> <button class="btn btn-danger btn-sm">🗑 Borrar</button>', 'class="inline"') . '</div></td></tr>';
        }
        $html .= '<div class="card"><h2>Recursos</h2><p class="muted">Lo único que sabe la AI. Para la instrucción predeterminada, añade aquí los países con subsidiaria y sus contactos. Los archivos se guardan en tu cuenta de OpenAI; no subas nada confidencial: la AI puede contárselo a un cliente.</p>'
            . ($rows !== '' ? '<div class="table-wrap"><table class="ai-res"><thead><tr><th>Recurso</th><th>Estado</th><th>Tamaño</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p>Todavía no hay recursos.</p>')
            . '<p class="muted">Textos y páginas: ' . number_format($used, 0, ',', '.') . ' de ' . number_format(Assistant::MAX_CHARS, 0, ',', '.') . ' caracteres. Los archivos no cuentan: la AI busca en ellos.</p>'
            . '<details class="ai-add"' . ($rows === '' ? ' open' : '') . '><summary>📝 Añadir texto (por ejemplo, contactos de las subsidiarias)</summary>' . $form('text', self::ai_text_fields('', '', 0)) . '</details>'
            . '<details class="ai-add"><summary>🔗 Añadir enlace</summary>' . $form('link', '<div class="field"><label for="ai_u">Dirección de la página o del PDF</label><input type="text" id="ai_u" name="url" required maxlength="480" placeholder="https://www.bezares.com/…"><small>Se lee solo esa página, no toda la web. Si cambia, pulsa «Volver a leer».</small></div><button class="btn btn-primary">Añadir enlace</button>', 'data-busy="Leyendo la página…"') . '</details>'
            . '<details class="ai-add"><summary>📄 Añadir archivo (catálogo)</summary>' . $form('file', '<div class="field"><label for="ai_f">Archivo</label><input type="file" id="ai_f" name="file" required accept=".' . implode(',.', Assistant::FILES) . '"><small>' . implode(', ', Assistant::FILES) . ' · máximo ' . (int) floor(Assistant::max_upload() / 1048576) . ' MB (texto, CSV y Excel, 20 MB). Un PDF escaneado sin texto no sirve.</small></div><button class="btn btn-primary">Subir archivo</button>', 'enctype="multipart/form-data" data-upload data-max="' . Assistant::max_upload() . '"') . '</details></div>';

        // Prueba
        $t = isset($_SESSION['ai_test']) && is_array($_SESSION['ai_test']) ? $_SESSION['ai_test'] : array('text' => '', 'country' => 'MX', 'channel' => 'web');
        unset($_SESSION['ai_test']);
        $res = '';
        if (array_key_exists('answer', $t)) {
            $res = $t['answer'] !== null ? '<p style="margin:14px 0 0"><span class="badge b-on">Contesta</span></p><div class="ai-ans">' . self::h($t['answer']) . '</div>' : '<p style="margin:14px 0 6px"><span class="badge b-off">No contesta</span> El chat seguiría hacia una persona.</p>';
            $res .= $t['reason'] !== '' ? '<p class="muted">Motivo: ' . self::h($t['reason']) . '</p>' : '';
            $frag = '';
            foreach ((array) ($t['found'] ?? array()) as $x) {
                $frag .= '<p><small><b>' . self::h($x[0]) . '</b></small></p><div class="prev-line" style="white-space:pre-wrap">' . self::h($x[1]) . '</div>';
            }
            $res .= $frag !== '' ? '<details><summary><small>Ver lo que ha encontrado en los catálogos (' . count($t['found']) . ')</small></summary>' . $frag . '</details>' : '<p class="muted"><small>No ha encontrado nada en los catálogos.</small></p>';
        }
        $opts = '';
        foreach (self::CHANNELS as $k => $l) {
            $opts .= '<option value="' . $k . '"' . ($t['channel'] === $k ? ' selected' : '') . '>' . $l . '</option>';
        }
        $html .= '<div class="card" id="probar"><h2>Probar</h2><p class="muted">Consulta de verdad a OpenAI con la instrucción y los recursos guardados. No envía nada a ningún cliente.</p>'
            . $form('test', '<div class="grid2"><div class="field"><label for="ai_c">País del cliente (código)</label><input type="text" id="ai_c" name="country" maxlength="2" value="' . self::h($t['country']) . '" placeholder="MX"><small>MX, US, FR… Vacío: desconocido.</small></div>'
                . '<div class="field"><label for="ai_ch">Canal</label><select id="ai_ch" name="channel">' . $opts . '</select></div></div>'
                . '<div class="field"><label for="ai_t">Mensaje del cliente</label><textarea id="ai_t" name="message" rows="3" maxlength="1500" required>' . self::h($t['text']) . '</textarea></div><button class="btn btn-primary">▶ Probar</button>', 'data-busy="Consultando a la AI…"') . $res . '</div>';
        self::layout('AI', 'Qué contesta la AI en el robot y con qué información', $html, $me, self::nav_button('🤖 Ver el robot', '/admin/robot', 'btn btn-secondary'));
    }

    private static function ai_text_fields($title, $text, $id)
    {
        return '<div class="field"><label for="ai_tt' . $id . '">Título</label><input type="text" id="ai_tt' . $id . '" name="title" maxlength="120" required value="' . self::h($title) . '"' . ($id ? '' : ' placeholder="Contactos de las subsidiarias"') . '></div>'
            . '<div class="field"><label for="ai_tx' . $id . '">Texto</label><textarea id="ai_tx' . $id . '" name="text" rows="8" maxlength="' . Assistant::MAX_ONE . '" required' . ($id ? '' : ' placeholder="País: …&#10;Empresa: …&#10;Teléfono: …&#10;Correo: …"') . '>' . self::h($text) . '</textarea></div><button class="btn btn-primary">💾 Guardar texto</button>';
    }

    /** Aviso breve si la traducción no funciona (se muestra en la pestaña Pruebas). */
    private static function ai_warning()
    {
        $tab = 0;
        foreach (Settings::schema() as $i => $g) {
            if ($g['group'] === 'Traducción (ChatGPT)') {
                $tab = $i;
            }
        }
        $st = AI::status();
        $reason = AI::disabled_reason();
        if (!$reason && !empty($st['error']['t']) && (int) $st['error']['t'] >= (int) $st['ok']) {
            $reason = 'La última llamada a OpenAI falló: ' . $st['error']['msg'];
        }
        return $reason
            ? '<div class="info-box" style="border:1px solid var(--bad);background:var(--bad-l);color:#8a1020">⚠ <b>La traducción no está funcionando.</b> ' . self::h($reason) . ' '
                . '<a class="btn btn-secondary btn-sm" href="/admin/settings?tab=' . (int) $tab . '">Ver Configuración → Traducción</a></div>'
            : '';
    }

    /** Estado de la traducción (último error / último éxito) y botón de prueba. */
    private static function ai_card($tab)
    {
        $st = AI::status();
        $fmt = function ($t) {
            return (new DateTimeImmutable('@' . (int) $t))->setTimezone(tz())->format('d/m/Y H:i');
        };
        $reason = AI::disabled_reason();
        if ($reason) {
            $state = '<b style="color:var(--bad)">⚠ ' . self::h($reason) . '</b> Los mensajes no se traducen y el bot responde en inglés a los idiomas que no sean español ni inglés.';
        } elseif (!empty($st['error']['t']) && (int) $st['error']['t'] >= (int) $st['ok']) {
            $state = '<b style="color:var(--bad)">⚠ La última llamada a OpenAI falló</b> (' . $fmt($st['error']['t']) . '): ' . self::h($st['error']['msg']);
        } elseif ($st['ok']) {
            $state = '<b style="color:var(--ok)">✔ Funcionando</b> · última traducción correcta: ' . $fmt($st['ok']);
        } else {
            $state = 'Activada, todavía sin traducciones registradas. Pulsa «Probar traducción».';
        }
        return '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="action" value="testai"><input type="hidden" name="tab" value="' . (int) $tab . '">'
            . '<h2>🌐 Probar la traducción</h2><div class="info-box">' . $state . '<br>Modelo: <b>' . self::h(Settings::get('openai_model') ?: 'gpt-4o-mini') . '</b>. '
            . 'Errores habituales: 401 = API key incorrecta o revocada · 429 = sin saldo o límite de la cuenta de OpenAI · 404 = el modelo no existe en tu cuenta.</div>'
            . '<div class="filters"><button class="btn btn-secondary">Probar traducción</button></div></form>';
    }

    /** Guía paso a paso para conectar WhatsApp Cloud API y Facebook Messenger (Meta). */
    private static function meta_guide()
    {
        $base = rtrim((string) cfg('base_url'), '/');
        $code = function ($s) { return '<code class="copy">' . self::h($s) . '</code>'; };
        $wa = $code($base . '/api/webhook/whatsapp');
        $fb = $code($base . '/api/webhook/facebook');
        $vt = $code((string) Settings::get('meta_verify_token'));
        return '<div class="guide"><p class="muted">Guía de conexión. Los nombres de los menús de Meta pueden variar ligeramente según el idioma y la versión. Necesitas ser administrador de la cuenta comercial (Business Manager) de Bezares.</p>'
            . '<details open><summary>1. Crear la app de Meta (una sola vez)</summary><ol>'
            . '<li>Entra en <b>developers.facebook.com</b> → <b>Mis apps</b> → <b>Crear app</b>.</li>'
            . '<li>Elige el tipo <b>Empresa</b> (Business) y vincúlala a la cuenta comercial de Bezares.</li>'
            . '<li>Dentro de la app, en <b>Añadir productos</b>, añade <b>WhatsApp</b> y/o <b>Messenger</b>.</li>'
            . '</ol></details>'
            . '<details><summary>2. App Secret (obligatorio)</summary><ol>'
            . '<li>En la app: <b>Configuración de la app</b> → <b>Básica</b>.</li>'
            . '<li>Junto a <b>Clave secreta de la app</b> pulsa <b>Mostrar</b> (te pedirá tu contraseña de Facebook).</li>'
            . '<li>Cópiala en el campo <b>App Secret</b> de abajo. Sirve para comprobar que cada mensaje entrante viene realmente de Meta.</li>'
            . '</ol></details>'
            . '<details><summary>3. WhatsApp: Phone number ID y token permanente</summary><ol>'
            . '<li>En la app: <b>WhatsApp</b> → <b>Configuración de la API</b>. Añade y verifica el número de teléfono de empresa (no puede estar en uso en la app de WhatsApp normal).</li>'
            . '<li>Copia el <b>Identificador del número de teléfono</b> (Phone number ID) en el campo <b>WhatsApp Phone number ID</b>.</li>'
            . '<li>El token temporal que aparece ahí caduca en 24 h: <b>no lo uses</b>. Para el permanente ve a <b>business.facebook.com</b> → <b>Configuración del negocio</b> → <b>Usuarios</b> → <b>Usuarios del sistema</b> → <b>Añadir</b> (nombre p. ej. «bezares-chat», rol <b>Administrador</b>).</li>'
            . '<li>Con ese usuario seleccionado: <b>Asignar activos</b> → la <b>app</b> (control total) y la <b>cuenta de WhatsApp</b> (control total).</li>'
            . '<li><b>Generar nuevo token</b> → elige la app → caducidad <b>Nunca</b> → permisos <b>whatsapp_business_messaging</b> y <b>whatsapp_business_management</b> → copia el token en <b>WhatsApp token permanente</b> (solo se muestra una vez).</li>'
            . '<li>Webhook: en la app, <b>WhatsApp</b> → <b>Configuración</b> → <b>Webhook</b> → <b>Editar</b>. URL de devolución: ' . $wa . ' · Token de verificación: ' . $vt . ' → <b>Verificar y guardar</b>. Después, en <b>Campos del webhook</b>, suscribe <b>messages</b>.</li>'
            . '<li>Marca abajo <b>WhatsApp activado</b> y guarda. Para producción, Meta exige verificar la empresa y añadir un método de pago en WhatsApp Manager.</li>'
            . '</ol></details>'
            . '<details><summary>4. Facebook Messenger: Page access token</summary><ol>'
            . '<li>En la app: <b>Messenger</b> → <b>Configuración de la API de Messenger</b> → conecta la <b>página de Facebook de Bezares</b> → <b>Generar token</b>. Basta con el permiso <b>pages_messaging</b>.</li>'
            . '<li>Copia el token en <b>Page access token</b>. El del usuario del sistema (WhatsApp) no sirve aquí: tiene que ser el de la página.</li>'
            . '<li>Webhook: <b>Messenger</b> → <b>Configuración</b> → <b>Webhooks</b>. URL de devolución: ' . $fb . ' · Token de verificación: ' . $vt . '. Luego, en la página conectada, suscribe el campo <b>messages</b>.</li>'
            . '<li>Para hablar con cualquier persona (no solo con los evaluadores de la app), la app debe estar en modo <b>Activo</b> y tener aprobado el permiso <b>pages_messaging</b> en <b>Revisión de la app</b>.</li>'
            . '<li>Marca abajo <b>Facebook Messenger activado</b> y guarda.</li>'
            . '</ol></details>'
            . '<details><summary>5. Comprobar que funciona</summary><ol>'
            . '<li>Escribe al número de WhatsApp o a la página de Facebook desde un móvil: el chat debe aparecer en el programa de Windows y sonar en recepción.</li>'
            . '<li>Si no llega: revisa que el <b>App Secret</b> es correcto (si no, los mensajes se rechazan) y el registro de errores del servidor.</li>'
            . '<li>Recuerda la norma de Meta: solo se puede escribir libremente durante <b>24 h</b> desde el último mensaje del cliente.</li>'
            . '</ol></details></div>';
    }

    private static function field($f, $v)
    {
        $k = self::h($f['k']);
        $help = !empty($f['help']) ? '<small>' . self::h($f['help']) . '</small>' : '';
        switch ($f['type']) {
            case 'robot':
                return ''; // pasos propios: se editan en el diagrama (ver robot)
            case 'info':
                return '<div class="info-box"><b>' . self::h($f['label']) . ':</b> ' . self::h($f['help']) . '</div>';
            case 'bool':
                return '<label class="switch"><input type="checkbox" role="switch" name="' . $k . '" value="1" ' . ((int) $v ? 'checked' : '') . '><span><b>' . self::h($f['label']) . '</b>' . $help . '</span></label>';
            case 'origins':
                $extra = array_diff(Settings::trusted_origins(), Settings::parse_origins((string) $v));
                return '<div class="field"><label for="' . $k . '">' . self::h($f['label']) . '</label>'
                    . '<textarea id="' . $k . '" name="' . $k . '" rows="5" spellcheck="false" placeholder="https://bezares.com&#10;https://cloud.bezares.com">' . self::h($v) . '</textarea>' . $help
                    . ($extra ? '<small>También autorizadas desde config.php (allowed_origins): <b>' . self::h(implode(', ', $extra)) . '</b></small>' : '') . '</div>';
            case 'textarea':
                return '<div class="field"><label for="' . $k . '">' . self::h($f['label']) . '</label><textarea id="' . $k . '" name="' . $k . '">' . self::h($v) . '</textarea>' . $help . '</div>';
            case 'number':
                return '<div class="field"><label for="' . $k . '">' . self::h($f['label']) . '</label><input id="' . $k . '" type="number" min="' . (int) ($f['min'] ?? 0) . '"' . (isset($f['max']) ? ' max="' . (int) $f['max'] . '"' : '') . ' name="' . $k . '" value="' . (int) $v . '">' . $help . '</div>';
            case 'color':
                return '<div class="field"><label for="' . $k . '">' . self::h($f['label']) . '</label><input id="' . $k . '" type="color" name="' . $k . '" value="' . self::h($v) . '">' . $help . '</div>';
            case 'secret':
                $set = $v !== '' && $v !== null;
                return '<div class="field"><label for="pw_' . preg_replace('/\W/', '', $f['k']) . '">' . self::h($f['label']) . ' ' . ($set ? '<span class="badge b-on">Guardado</span>' : '<span class="badge b-off">Sin configurar</span>') . '</label>'
                    . self::password_input($f['k'], 'autocomplete="new-password" placeholder="' . ($set ? 'Déjalo vacío para mantener el actual' : 'Pega aquí el valor') . '"')
                    . ($set ? '<label class="check" style="margin-top:8px"><input type="checkbox" name="' . $k . '__clear" value="1"> Borrar el valor guardado</label>' : '') . $help . '</div>';
            case 'hours':
                $days = array(1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo');
                $o = '<div class="field"><span class="label">' . self::h($f['label']) . '</span><div class="table-wrap"><table class="hours"><thead><tr><th>Día</th><th>Laborable</th><th>Desde</th><th>Hasta</th></tr></thead><tbody>';
                foreach ($days as $d => $l) {
                    $x = isset($v[$d]) ? $v[$d] : array('on' => 0, 'start' => '08:00', 'end' => '17:00');
                    $o .= '<tr><td><b>' . $l . '</b></td><td><label class="switch" style="margin:0"><input type="checkbox" role="switch" aria-label="' . $l . ' laborable" name="' . $k . '[' . $d . '][on]" value="1" ' . (!empty($x['on']) ? 'checked' : '') . '></label></td>'
                        . '<td><input type="time" aria-label="' . $l . ' desde" name="' . $k . '[' . $d . '][start]" value="' . self::h($x['start']) . '"></td>'
                        . '<td><input type="time" aria-label="' . $l . ' hasta" name="' . $k . '[' . $d . '][end]" value="' . self::h($x['end']) . '"></td></tr>';
                }
                return $o . '</tbody></table></div></div>';
            default:
                return '<div class="field"><label for="' . $k . '">' . self::h($f['label']) . '</label><input id="' . $k . '" type="text" name="' . $k . '" value="' . self::h($v) . '">' . $help . '</div>';
        }
    }

    /* ================================================================ usuarios */

    private static function users($method, $me)
    {
        if ($method === 'POST') {
            self::check_csrf();
            if (($_POST['action'] ?? '') === 'signup_new') {
                Signup::generate($me);
                self::flash('Enlace generado. Compártelo solo con quien deba crear su usuario.');
                self::redirect('/admin/users');
            }
            if (($_POST['action'] ?? '') === 'signup_off') {
                Signup::disable($me);
                self::flash('Enlace deshabilitado.');
                self::redirect('/admin/users');
            }
            $id = (int) ($_POST['id'] ?? 0);
            try {
                if (!empty($_POST['delete'])) {
                    if ($id === (int) $me['id']) {
                        throw new ApiError('No puedes eliminar tu propio usuario');
                    }
                    if (empty($_POST['confirm_delete'])) {
                        throw new ApiError('Marca «Confirmo que quiero eliminarlo» para eliminar el usuario.');
                    }
                    Auth::delete($id);
                    self::flash('Usuario eliminado. Sus chats han vuelto a recepción.');
                    self::redirect('/admin/users');
                }
                $d = array('name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'role' => $_POST['role'] ?? 'sales', 'active' => !empty($_POST['active']), 'password' => $_POST['password'] ?? '');
                if ($id === (int) $me['id'] && (!in_array('admin_panel', Roles::perms($d['role']), true) || !$d['active'])) {
                    throw new ApiError('No puedes quitarte el acceso al panel (elige un rol con «Panel de administración») ni desactivarte');
                }
                Auth::save($d, $id);
                if ($id === (int) $me['id'] && $d['password'] !== '') {
                    self::start_admin_session(Auth::agent($id)); // quien cambia su propia contraseña sigue dentro
                }
                self::flash($id ? 'Usuario guardado' : 'Usuario creado');
                self::redirect('/admin/users');
            } catch (ApiError $e) {
                self::flash($e->getMessage(), true);
                self::redirect('/admin/users' . ($id ? '?edit=' . $id : ''));
            }
        }
        $edit = isset($_GET['edit']) ? Auth::agent((int) $_GET['edit']) : null;

        $rows = '';
        $signup = array('unconfirmed' => 'Sin confirmar el correo', 'pending' => 'Pendiente de habilitar', 'ignored' => 'Alta ignorada');
        foreach (Auth::agents() as $a) {
            $online = (now_ms() - (int) $a['last_seen']) < 60000;
            $rows .= '<tr><td><span class="dot' . ($online ? ' on' : '') . '" title="' . ($online ? 'Conectado' : 'Desconectado') . '"></span><b>' . self::h($a['name']) . '</b><br><small>' . self::h($a['email']) . '</small></td>'
                . '<td><span class="badge b-' . (in_array($a['role'], array('admin', 'receptionist', 'sales'), true) ? self::h($a['role']) : 'custom') . '">' . self::h(Roles::name($a['role'])) . '</span></td>'
                . '<td><span class="badge ' . ((int) $a['active'] ? 'b-on">Activo' : 'b-off">' . ($signup[$a['signup']] ?? 'Desactivado')) . '</span></td>'
                . '<td class="actions">' . self::nav_button('✏️ Editar', '/admin/users', 'btn btn-secondary btn-sm', array('edit' => (int) $a['id'])) . '</td></tr>';
        }
        $list = '<div class="card"><h2>Equipo</h2><div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';

        $role = $edit ? $edit['role'] : 'sales';
        $opts = '';
        foreach (Roles::all() as $k => $rr) {
            $opts .= '<option value="' . self::h($k) . '" ' . ($role === $k ? 'selected' : '') . '>' . self::h($rr['name']) . '</option>';
        }
        $form = '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="id" value="' . ($edit ? (int) $edit['id'] : 0) . '">'
            . '<h2>' . ($edit ? 'Editar usuario' : 'Nuevo usuario') . '</h2>'
            . '<div class="field"><label for="u_name">Nombre</label><input id="u_name" type="text" name="name" required value="' . self::h($edit ? $edit['name'] : '') . '"></div>'
            . '<div class="field"><label for="u_email">Correo (usuario de acceso)</label><input id="u_email" type="email" name="email" required autocomplete="off" value="' . self::h($edit ? $edit['email'] : '') . '"></div>'
            . '<div class="field"><label for="u_role">Rol</label><select id="u_role" name="role">' . $opts . '</select><small>Lo que puede hacer cada rol se configura en Configuración → Roles.</small></div>'
            . '<div class="field"><label for="pw_password">Contraseña</label>' . self::password_input('password', 'minlength="10" autocomplete="new-password" ' . ($edit ? 'placeholder="Déjala vacía para no cambiarla"' : 'required')) . '<small>Mínimo 10 caracteres, con letras y números. El usuario también puede recuperarla por correo.</small></div>'
            . '<label class="switch"><input type="checkbox" role="switch" name="active" value="1" ' . (!$edit || (int) $edit['active'] ? 'checked' : '') . '><span><b>Usuario activo</b><small>Si lo desactivas se cierran sus sesiones.</small></span></label>'
            . '<div class="savebar"><button type="submit" class="btn btn-primary">' . ($edit ? '💾 Guardar' : '➕ Crear usuario') . '</button></div>'
            . '</form>';
        if ($edit && (int) $edit['id'] !== (int) $me['id']) {
            $form .= '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="id" value="' . (int) $edit['id'] . '"><input type="hidden" name="delete" value="1">'
                . '<h2>Eliminar usuario</h2><p class="muted">Sus chats volverán a recepción. Esta acción no se puede deshacer.</p>'
                . '<label class="check" style="margin-bottom:14px"><input type="checkbox" name="confirm_delete" value="1" required> Confirmo que quiero eliminarlo</label>'
                . '<button class="btn btn-danger">🗑 Eliminar usuario</button></form>';
        }
        $actions = $edit ? self::nav_button('✕ Cancelar edición', '/admin/users', 'btn btn-secondary') . ' ' . self::nav_button('➕ Nuevo usuario', '/admin/users', 'btn btn-primary') : '';
        self::layout('Usuarios', 'Recepcionistas, comerciales y administradores que usan el chat', '<div class="split"><div>' . $list . ($edit ? '' : self::signup_card()) . '</div><div>' . $form . '</div></div>', $me, $actions);
    }

    /* ================================================================ estadísticas */

    private static function dur($ms)
    {
        if ($ms === null) {
            return '—';
        }
        $s = (int) round($ms / 1000);
        if ($s < 60) {
            return $s . ' s';
        }
        $m = intdiv($s, 60);
        if ($m < 60) {
            return $m . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' s' : '');
        }
        $h = intdiv($m, 60);
        return $h < 24 ? $h . ' h ' . ($m % 60) . ' min' : intdiv($h, 24) . ' d ' . ($h % 24) . ' h';
    }

    private static function pct($a, $b)
    {
        return $b ? round($a * 100 / $b) . '%' : '—';
    }

    /** Fecha AAAA-MM-DD válida del GET, o null. */
    private static function get_date($k)
    {
        $v = isset($_GET[$k]) ? (string) $_GET[$k] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, tz());
        return $d && $d->format('Y-m-d') === $v ? $d : null;
    }

    /**
     * Periodo con el que se compara por defecto:
     * - mes natural completo → el mes anterior;
     * - del día 1 a un día del mismo mes → los mismos días del mes anterior;
     * - cualquier otro → el mismo número de días justo antes.
     */
    private static function previous_period(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        if ($from->format('d') === '01' && $from->format('Y-m') === $to->format('Y-m')) {
            $pStart = $from->modify('first day of previous month');
            $pLast = $pStart->modify('last day of this month');
            if ($to->format('Y-m-d') === $to->modify('last day of this month')->format('Y-m-d')) {
                return array($pStart, $pLast);
            }
            $pEnd = $pStart->modify('+' . ((int) $to->format('j') - 1) . ' days');
            return array($pStart, $pEnd > $pLast ? $pLast : $pEnd);
        }
        $days = (int) $from->diff($to)->days;
        $pEnd = $from->modify('-1 day');
        return array($pEnd->modify('-' . $days . ' days'), $pEnd);
    }

    /** Variación frente al periodo comparado. $better: 'up' (subir es bueno), 'down' (bajar es bueno) o '' (neutro). */
    private static function delta($a, $b, $better, $isDur = false)
    {
        if ($a === null || $b === null) {
            return '<div class="d">Antes: ' . ($isDur ? self::dur($b) : ($b === null ? '—' : (int) $b)) . '</div>';
        }
        $before = 'antes: ' . ($isDur ? self::dur($b) : (int) $b);
        if ((int) $a === (int) $b) {
            return '<div class="d">= sin cambios · ' . $before . '</div>';
        }
        $up = $a > $b;
        $pct = $b ? round(abs($a - $b) * 100 / $b) . '%' : 'nuevo';
        $tone = $better === '' ? '' : (($up === ($better === 'up')) ? ' good' : ' worse');
        return '<div class="d' . $tone . '">' . ($up ? '▲ +' : '▼ −') . $pct . ' · ' . $before . '</div>';
    }

    private static function stats($me)
    {
        $now = new DateTimeImmutable('today', tz());
        $thr = isset($_GET['thr']) ? max(1, (int) $_GET['thr']) : max(1, (int) Settings::get('stats_threshold'));
        $sel = function ($k, array $allowed, $def) {
            $v = isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : '';
            return isset($allowed[$v]) ? $v : $def;
        };
        $periods = array('month' => 'Mes pasado', 'this' => 'Este mes', '7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días', 'year' => 'Este año', 'custom' => 'Personalizado…');
        $compares = array('prev' => 'Periodo anterior', 'yoy' => 'Mismo periodo del año anterior', 'custom' => 'Personalizado…', 'none' => 'Sin comparar');
        // Por defecto: último mes completo comparado con el anterior. (Enlaces antiguos con from/to → personalizado.)
        $p = $sel('p', $periods, isset($_GET['from']) ? 'custom' : 'month');
        $cm = $sel('c', $compares, 'prev');

        if ($p === 'custom' && self::get_date('from') && self::get_date('to')) {
            $from = self::get_date('from');
            $to = self::get_date('to');
        } elseif ($p === 'this') {
            $from = $now->modify('first day of this month');
            $to = $now;
        } elseif ($p === 'year') {
            $from = $now->setDate((int) $now->format('Y'), 1, 1);
            $to = $now;
        } elseif (ctype_digit($p)) {
            $from = $now->modify('-' . ((int) $p - 1) . ' days');
            $to = $now;
        } else {
            $p = $p === 'custom' ? 'month' : $p; // personalizado sin fechas válidas → mes pasado
            $from = $now->modify('first day of previous month');
            $to = $from->modify('last day of this month');
        }
        if ($from > $to) {
            list($from, $to) = array($to, $from);
        }

        if ($cm === 'custom' && self::get_date('cfrom') && self::get_date('cto')) {
            $cFrom = self::get_date('cfrom');
            $cTo = self::get_date('cto');
            if ($cFrom > $cTo) {
                list($cFrom, $cTo) = array($cTo, $cFrom);
            }
        } elseif ($cm === 'yoy') {
            $cFrom = $from->modify('-1 year');
            $cTo = $to->modify('-1 year');
        } else {
            $cm = $cm === 'custom' ? 'prev' : $cm;
            list($cFrom, $cTo) = self::previous_period($from, $to);
        }
        $compare = $cm !== 'none';

        $s = Stats::compute($from->format('Y-m-d'), $to->format('Y-m-d'), $thr);
        $c = $compare ? Stats::compute($cFrom->format('Y-m-d'), $cTo->format('Y-m-d'), $thr) : null;
        $fmt = function (DateTimeImmutable $d) { return $d->format('d/m/Y'); };

        $kpi = function ($label, $value, $sub = '', $tone = '', $cmp = '') {
            return '<div class="kpi ' . $tone . '"><div class="v">' . $value . '</div><div class="l">' . $label . '</div>' . ($sub ? '<div class="s">' . $sub . '</div>' : '') . $cmp . '</div>';
        };
        $dl = function ($key, $better, $isDur = false) use ($s, $c) {
            return $c ? self::delta($s[$key], $c[$key], $better, $isDur) : '';
        };

        $opts = function (array $list, $cur) {
            $o = '';
            foreach ($list as $k => $l) {
                $o .= '<option value="' . $k . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . $l . '</option>';
            }
            return $o;
        };
        // Barra compacta: Periodo · Comparar con · Umbral · Ver. Las fechas solo aparecen con «Personalizado…» (CSS :has, sin JavaScript).
        $html = '<form method="get" action="/admin/" class="card stat-bar">'
            . '<div class="field"><label for="p">Periodo</label><select id="p" name="p">' . $opts($periods, $p) . '</select></div>'
            . '<div class="field dates dates-a"><label for="from">Desde</label><input id="from" type="date" name="from" value="' . $from->format('Y-m-d') . '"></div>'
            . '<div class="field dates dates-a"><label for="to">Hasta</label><input id="to" type="date" name="to" value="' . $to->format('Y-m-d') . '"></div>'
            . '<div class="field"><label for="c">Comparar con</label><select id="c" name="c">' . $opts($compares, $cm) . '</select></div>'
            . '<div class="field dates dates-b"><label for="cfrom">Desde</label><input id="cfrom" type="date" name="cfrom" value="' . $cFrom->format('Y-m-d') . '"></div>'
            . '<div class="field dates dates-b"><label for="cto">Hasta</label><input id="cto" type="date" name="cto" value="' . $cTo->format('Y-m-d') . '"></div>'
            . '<div class="field thr"><label for="thr" title="Para «Contestados en menos de X min»">Umbral (min)</label><input id="thr" type="number" name="thr" min="1" value="' . $thr . '"></div>'
            . '<button class="btn btn-primary">Ver</button>'
            . '<div class="stat-range"><span><span class="sw" style="background:var(--brand)"></span>' . $fmt($from) . ' – ' . $fmt($to) . '</span>'
            . ($c ? '<span>vs</span><span><span class="sw" style="background:#98a2b3"></span>' . $fmt($cFrom) . ' – ' . $fmt($cTo) . '</span><span class="muted">· verde mejora, rojo empeora</span>' : '')
            . '</div></form>';

        $html .= '<div class="kpis">'
            . $kpi('Chats recibidos', $s['total'], $s['in_hours'] . ' en horario laboral', '', $dl('total', ''))
            . $kpi('Atendidos', $s['answered'], self::pct($s['answered'], $s['total']) . ' del total (respondidos o resueltos)', 'ok', $dl('answered', 'up'))
            . $kpi('Resueltos', $s['resolved'], self::pct($s['resolved'], $s['total']) . ' del total', 'ok', $dl('resolved', 'up'))
            . $kpi('Sin atender', $s['unanswered'], self::pct($s['unanswered'], $s['total']) . ' del total', $s['unanswered'] ? 'bad' : '', $dl('unanswered', 'down'))
            . $kpi('Contestados en &lt; ' . $thr . ' min', $s['within'], self::pct($s['within'], $s['total']) . ' del total', 'ok', $dl('within', 'up'))
            . $kpi('En &lt; ' . $thr . ' min laborables', $s['within_biz'], self::pct($s['within_biz'], $s['total']) . ' del total', 'ok', $dl('within_biz', 'up'))
            . $kpi('Tiempo de respuesta medio', self::dur($s['avg']), 'Mediana: ' . self::dur($s['median']), 'warn', $dl('avg', 'down', true))
            . $kpi('Tiempo medio en horario laboral', self::dur($s['avg_biz']), 'Mediana: ' . self::dur($s['median_biz']), 'warn', $dl('avg_biz', 'down', true))
            . '</div>';

        // Chats por día: todos los días del periodo; al comparar, cada día se empareja con el mismo día ordinal del otro periodo.
        $daysOf = function (DateTimeImmutable $a, DateTimeImmutable $b, $byDay) {
            $out = array();
            for ($d = $a; $d <= $b && count($out) < 400; $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                $out[] = array($k, $byDay[$k] ?? array('total' => 0, 'answered' => 0));
            }
            return $out;
        };
        $dA = $daysOf($from, $to, $s['by_day']);
        $dB = $c ? $daysOf($cFrom, $cTo, $c['by_day']) : array();
        $max = 1;
        foreach (array_merge($dA, $dB) as $x) {
            $max = max($max, $x[1]['total']);
        }
        $one = function ($day, $cls) use ($max) {
            if ($day === null) {
                return '<i class="' . $cls . ' empty"></i>';
            }
            $v = $day[1];
            return '<i class="' . $cls . '" style="height:' . round($v['total'] * 100 / $max) . '%" title="' . date('d/m/Y', strtotime($day[0])) . ': ' . $v['total'] . ' chats, ' . $v['answered'] . ' contestados">'
                . '<b style="height:' . ($v['total'] ? round($v['answered'] * 100 / $v['total']) : 0) . '%"></b></i>';
        };
        $bars = '';
        $n = max(count($dA), count($dB));
        $any = false;
        for ($i = 0; $i < $n; $i++) {
            $a = $dA[$i] ?? null;
            $b = $dB[$i] ?? null;
            $any = $any || ($a && $a[1]['total']) || ($b && $b[1]['total']);
            $label = $a ? substr($a[0], 8) : '';
            $bars .= $c
                ? '<div class="bar"><div class="pair">' . $one($a, '') . $one($b, 'cmp') . '</div><span>' . $label . '</span></div>'
                : '<div class="bar">' . $one($a, '') . '<span>' . $label . '</span></div>';
        }
        $legend = '<div class="legend"><span><span class="sw" style="background:var(--brand-t)"></span>Recibidos</span><span><span class="sw" style="background:var(--brand)"></span>Contestados</span>'
            . ($c ? '<span><span class="sw" style="background:#98a2b3"></span>Periodo comparado (' . $fmt($cFrom) . ' – ' . $fmt($cTo) . ')</span>' : '') . '</div>';
        $html .= '<div class="card"><h2>Chats por día</h2>' . ($any ? '<div class="bars' . ($n > 62 ? ' dense' : '') . '">' . $bars . '</div>' . $legend : '<p class="muted">Sin chats en este periodo.</p>') . '</div>';

        // Tablas por agente y por canal (con la columna «antes» al comparar).
        $prev = function ($x) { return '<td class="prev">' . $x . '</td>'; };
        $cAgents = array();
        if ($c) {
            foreach ($c['by_agent'] as $a) {
                $cAgents[$a['name']] = $a;
            }
        }
        $agents = '';
        $seen = array();
        foreach ($s['by_agent'] as $a) {
            $seen[$a['name']] = true;
            $p = $cAgents[$a['name']] ?? null;
            $agents .= '<tr><td><b>' . self::h($a['name']) . '</b></td><td>' . $a['answered'] . '</td>' . ($c ? $prev($p ? $p['answered'] : 0) : '')
                . '<td>' . self::dur($a['avg']) . '</td>' . ($c ? $prev(self::dur($p ? $p['avg'] : null)) : '')
                . '<td>' . self::dur($a['avg_biz']) . '</td>' . ($c ? $prev(self::dur($p ? $p['avg_biz'] : null)) : '') . '</tr>';
        }
        foreach ($cAgents as $name => $p) { // agentes que solo atendieron en el periodo comparado
            if (!isset($seen[$name])) {
                $agents .= '<tr><td><b>' . self::h($name) . '</b></td><td>0</td>' . $prev($p['answered']) . '<td>—</td>' . $prev(self::dur($p['avg'])) . '<td>—</td>' . $prev(self::dur($p['avg_biz'])) . '</tr>';
            }
        }
        $names = array('web' => '🌐 Web', 'whatsapp' => '🟢 WhatsApp', 'facebook' => '🔵 Facebook');
        $chKeys = array_keys($s['by_channel'] + ($c ? $c['by_channel'] : array()));
        $channels = '';
        foreach ($chKeys as $k) {
            $v = $s['by_channel'][$k] ?? array('total' => 0, 'answered' => 0);
            $p = $c ? ($c['by_channel'][$k] ?? array('total' => 0, 'answered' => 0)) : null;
            $channels .= '<tr><td><b>' . self::h($names[$k] ?? $k) . '</b></td><td>' . $v['total'] . '</td>' . ($c ? $prev($p['total']) : '')
                . '<td>' . $v['answered'] . ' (' . self::pct($v['answered'], $v['total']) . ')</td>' . ($c ? $prev($p['answered'] . ' (' . self::pct($p['answered'], $p['total']) . ')') : '') . '</tr>';
        }
        $ah = $c ? '<th>Agente</th><th>Contestados</th><th class="prev">antes</th><th>Tiempo medio</th><th class="prev">antes</th><th>Medio laboral</th><th class="prev">antes</th>'
            : '<th>Agente</th><th>Contestados</th><th>Tiempo medio</th><th>Medio laboral</th>';
        $chh = $c ? '<th>Canal</th><th>Chats</th><th class="prev">antes</th><th>Contestados</th><th class="prev">antes</th>' : '<th>Canal</th><th>Chats</th><th>Contestados</th>';
        $html .= '<div class="split">'
            . '<div class="card"><h2>Por agente (primera respuesta)</h2><div class="table-wrap"><table><thead><tr>' . $ah . '</tr></thead><tbody>' . ($agents ?: '<tr><td colspan="7" class="muted">Sin datos</td></tr>') . '</tbody></table></div></div>'
            . '<div class="card"><h2>Por canal</h2><div class="table-wrap"><table><thead><tr>' . $chh . '</tr></thead><tbody>' . ($channels ?: '<tr><td colspan="5" class="muted">Sin datos</td></tr>') . '</tbody></table></div></div>'
            . '</div><p class="muted">Los tiempos se miden desde que el cliente inicia el chat hasta la primera respuesta de una persona o, si ocurre antes, hasta que se marca como resuelta (en ese momento se deja de contar). El tiempo laboral solo cuenta las horas dentro del horario configurado.'
            . ($c ? ' En la gráfica, cada día se compara con el mismo día ordinal del otro periodo (1.º con 1.º, 2.º con 2.º…).' : '') . '</p>';
        self::layout('Estadísticas', 'Del ' . $fmt($from) . ' al ' . $fmt($to) . ($c ? ' · comparado con del ' . $fmt($cFrom) . ' al ' . $fmt($cTo) : ''), $html, $me);
    }
}
