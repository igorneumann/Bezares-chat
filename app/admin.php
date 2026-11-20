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
        $_SESSION['last'] = time();
    }

    private static function me()
    {
        if (self::$me !== null) {
            return self::$me ?: null;
        }
        $a = !empty($_SESSION['admin_id']) ? Auth::agent($_SESSION['admin_id']) : null;
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
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors " . frame_ancestors() . "; base-uri 'none'");

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
            case 'settings':
                self::settings($method, $me);
                break;
            case 'users':
                self::users($method, $me);
                break;
            default:
                self::stats($me);
        }
    }

    /* ================================================================ plantillas */

    private static function css()
    {
        return <<<CSS
:root{--brand:#0057a8;--brand-d:#003f7d;--brand-l:#e8f0fb;--bg:#f3f5f9;--card:#fff;--line:#e3e7ee;--text:#1b2230;--muted:#667085;--ok:#1a7f37;--ok-l:#e6f4ea;--bad:#c62828;--bad-l:#fdecec;--warn:#b54708;--warn-l:#fff4e5;--r:12px;--shadow:0 1px 2px rgba(16,24,40,.06),0 4px 16px rgba(16,24,40,.06)}
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
.nav .btn{width:100%;justify-content:flex-start;background:transparent;color:#dbe8f7;border:0;padding:11px 14px;font-weight:500}
.nav .btn:hover{background:rgba(255,255,255,.12);color:#fff}
.nav .btn.on{background:#fff;color:var(--brand);font-weight:700;box-shadow:var(--shadow)}
.side-foot{margin-top:auto;border-top:1px solid rgba(255,255,255,.2);padding:14px 8px 0}
.side-foot .who{font-weight:600}
.side-foot .role{font-size:12px;color:#c9dbf0;margin-bottom:10px}
.side-foot .btn{width:100%;background:rgba(255,255,255,.12);color:#fff;border:0}
.side-foot .btn:hover{background:rgba(255,255,255,.22)}
.main{flex:1;min-width:0;padding:28px 32px 60px;max-width:1180px}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.page-head p{margin:4px 0 0;color:var(--muted)}
/* Tarjetas, avisos */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);padding:22px;margin-bottom:18px}
.alert{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-weight:500}
.alert-ok{background:var(--ok-l);color:var(--ok)}
.alert-bad{background:var(--bad-l);color:var(--bad)}
/* Formularios */
.field{margin-bottom:18px}
.field>label,.label{display:block;font-weight:600;font-size:14px;margin-bottom:6px}
.field small{display:block;margin-top:5px}
input[type=text],input[type=email],input[type=password],input[type=number],input[type=date],input[type=time],textarea,select{width:100%;padding:10px 12px;border:1px solid #d3d9e3;border-radius:10px;font:inherit;background:#fff;color:inherit;transition:border-color .15s,box-shadow .15s}
input:focus,textarea:focus,select:focus{outline:0;border-color:var(--brand);box-shadow:0 0 0 3px #d5e5f7}
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
.t-out{align-self:flex-end;background:#dcebfb;border-color:#c6dcf5}
.t-bot{align-self:flex-end;background:#eef1f5;border-style:dashed}
.t-system{align-self:center;background:#eef1f5;font-size:12px;color:var(--muted)}
.guide{margin-bottom:20px}
.guide details{border:1px solid var(--line);border-radius:10px;margin-bottom:8px;background:#fafbfd}
.guide summary{cursor:pointer;padding:11px 14px;font-weight:600}
.guide summary:hover{background:var(--brand-l);border-radius:10px}
.guide ol{margin:0;padding:4px 18px 12px 38px}
.guide li{margin-bottom:6px;line-height:1.5}
.guide code{background:#eef2f7;padding:2px 6px;border-radius:5px;font-size:13px;word-break:break-all}
.info-box{background:var(--brand-l);color:#1d3f66;border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:14px;word-break:break-word}
/* Tablas */
.table-wrap{overflow-x:auto;margin:0 -22px;padding:0 22px}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
tr:last-child td{border-bottom:0}
td.actions{text-align:right;white-space:nowrap}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
.b-admin{background:#efe7fb;color:#5b2ca0}.b-receptionist{background:#e3f1fd;color:#0b5cad}.b-sales{background:#e7f6ec;color:#1a7f37}
.b-custom{background:#fff4e5;color:#b54708}
.b-on{background:var(--ok-l);color:var(--ok)}.b-off{background:#eef0f3;color:var(--muted)}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#cfd6e1;margin-right:8px}.dot.on{background:#22c55e;box-shadow:0 0 0 3px #dcfce7}
.hours td{padding:8px 10px}
.hours input[type=time]{width:auto}
.split{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(320px,1fr);gap:18px;align-items:start}
@media (max-width:1250px){.split{grid-template-columns:1fr}}
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
.bar i{display:block;width:100%;background:#c6dcf5;border-radius:4px 4px 0 0;position:relative;min-height:2px}
.bar i b{position:absolute;bottom:0;left:0;right:0;background:var(--brand);border-radius:4px 4px 0 0}
.bar span{font-size:10px;color:var(--muted);margin-top:4px}
.legend{display:flex;gap:16px;font-size:13px;color:var(--muted);margin-top:10px}
.sw{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:middle}
/* Páginas de acceso */
.auth{min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 20% 10%,#dbe8f8 0,transparent 40%),radial-gradient(circle at 90% 90%,#e3eefb 0,transparent 40%),var(--bg)}
.auth-card{width:100%;max-width:420px;background:#fff;border-radius:18px;box-shadow:0 10px 40px rgba(16,24,40,.12);padding:36px 34px}
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
            . '<script src="/admin.js" defer></script></head>';
    }

    /** Página interna con barra lateral. */
    private static function layout($title, $subtitle, $body, $me, $headActions = '')
    {
        $cur = trim(preg_replace('#^/admin#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
        $items = array('' => '📊  Estadísticas', 'settings' => '⚙️  Configuración', 'users' => '👥  Usuarios');
        $nav = '';
        foreach ($items as $k => $l) {
            $nav .= self::nav_button($l, '/admin/' . $k, 'btn' . ($cur === $k ? ' on' : ''));
        }
        echo self::head($title) . '<body><div class="layout">'
            . '<aside class="side"><div class="brand"><img class="logo" src="/brand/bezares-chat-icon-dark.svg" alt="" width="38" height="38">Bezares Chat</div><nav class="nav" aria-label="Menú">' . $nav . '</nav>'
            . '<div class="side-foot"><div class="who">' . self::h($me['name']) . '</div><div class="role">' . self::h(Roles::name($me['role'])) . '</div>'
            . '<form method="post" action="/admin/logout" class="inline">' . self::csrf_field() . '<button class="btn btn-sm">Cerrar sesión</button></form></div></aside>'
            . '<main class="main"><div class="page-head"><div><h1>' . self::h($title) . '</h1>' . ($subtitle ? '<p>' . self::h($subtitle) . '</p>' : '') . '</div>' . $headActions . '</div>'
            . self::flash() . $body . '</main></div></body></html>';
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

    /* ================================================================ configuración */

    /** Botones de pestaña de Configuración; $active es el índice del grupo o 'roles'. */
    private static function settings_tabs($schema, $active)
    {
        $tabs = '<form method="get" action="/admin/settings" class="tabs" role="tablist">';
        foreach ($schema as $i => $g) {
            $on = $active === $i;
            $tabs .= '<button type="submit" name="tab" value="' . $i . '" class="btn' . ($on ? ' on' : '') . '" role="tab" aria-selected="' . ($on ? 'true' : 'false') . '">' . self::h($g['group']) . '</button>';
        }
        foreach (array('roles' => '🛡 Roles', 'test' => '🧪 Pruebas') as $k => $label) {
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
                    $c = Core::find('web', $ext);
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
        self::redirect('/admin/settings?tab=test' . ($conv ? '&conv=' . $conv : ''));
    }

    /** Botón de acción sobre un chat de prueba (desactivado si el rol no tiene el permiso). */
    private static function test_button($action, $label, $conv, $allowed, $class = 'btn btn-secondary btn-sm', $extra = '')
    {
        return '<form method="post" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="conv" value="' . (int) $conv . '">' . $extra
            . '<button class="' . $class . '"' . ($allowed ? '' : ' disabled title="Este rol no tiene permiso"') . '>' . $label . '</button></form>';
    }

    private static function test_tab($schema, $me)
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
        $html = self::settings_tabs($schema, 'test')
            . '<div class="info-box">Aquí puedes probar el chat <b>sin molestar a nadie</b>: actúas como el usuario que elijas sobre <b>chats de prueba</b>. Esos chats no aparecen en el programa de Windows, no suenan a nadie, no envían nada por WhatsApp/Facebook, no cuentan en las estadísticas y se borran solos a las 24 h. El usuario real no se entera ni cambia su estado.</div>'
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
            . '<h2>💬 Simular un visitante nuevo (web)</h2>'
            . '<div class="field"><label for="t_text">Primer mensaje del visitante</label><textarea id="t_text" name="text" required placeholder="Ej.: Hola, busco una toma de fuerza para un camión Volvo"></textarea><small>Escríbelo en el idioma elegido para probar también la traducción automática.</small></div>'
            . '<div class="filters"><div class="field"><label for="t_lang">Idioma del navegador del visitante</label><select id="t_lang" name="lang">' . $lo . '</select></div><button class="btn btn-primary">Iniciar chat de prueba</button></div></form>';

        // 3) Lista de chats de prueba
        $tests = DB::rows('SELECT * FROM conversations WHERE is_test = 1 ORDER BY updated_at DESC LIMIT 50');
        $sel = isset($_GET['conv']) ? (int) $_GET['conv'] : 0;
        $rows = '';
        foreach ($tests as $c) {
            $status = $c['status'] === 'resolved' ? '<span class="badge b-on">✓ Resuelta</span>' : ((int) $c['assigned_to'] ? '<span class="badge b-receptionist">Asignada</span>' : '<span class="badge b-custom">Nueva</span>');
            $rows .= '<tr' . ((int) $c['id'] === $sel ? ' style="background:var(--brand-l)"' : '') . '><td><b>' . self::h($c['name'] !== '' ? $c['name'] : 'Visitante #' . $c['id']) . '</b><br><small>' . self::h(mb_substr($c['last_preview'], 0, 80)) . '</small></td>'
                . '<td>' . $status . '</td><td>' . self::h((int) $c['assigned_to'] ? (Auth::agent($c['assigned_to'])['name'] ?? '—') : 'Sin asignar') . '</td>'
                . '<td class="actions">' . self::nav_button('Abrir', '/admin/settings', 'btn btn-secondary btn-sm', array('tab' => 'test', 'conv' => (int) $c['id'])) . '</td></tr>';
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
                . '<div class="field"><label for="r_text">Responder como ' . self::h($agent ? $agent['name'] : '(elige un usuario)') . '</label><textarea id="r_text" name="text" required></textarea></div>'
                . '<button class="btn btn-primary"' . ($agent ? '' : ' disabled') . '>Enviar respuesta</button></form>'
                . '<form method="post">' . self::csrf_field() . '<input type="hidden" name="action" value="test_visitor"><input type="hidden" name="conv" value="' . (int) $c['id'] . '">'
                . '<div class="field"><label for="v_text">Escribir como el visitante</label><textarea id="v_text" name="text" required></textarea></div>'
                . '<button class="btn btn-secondary">Enviar como visitante</button></form></div>'
                . '<div class="filters" style="margin-top:16px">'
                . self::test_button('test_accept', '📞 Atender', $c['id'], (bool) $agent)
                . self::test_button('test_resolve', $c['status'] === 'resolved' ? '↺ Reabrir' : '✓ Resuelta', $c['id'], $can('resolve'))
                . self::test_button('test_delete', '🗑 Borrar', $c['id'], $can('delete'), 'btn btn-danger btn-sm')
                . '<form method="post" class="inline">' . self::csrf_field() . '<input type="hidden" name="action" value="test_transfer"><input type="hidden" name="conv" value="' . (int) $c['id'] . '">'
                . '<select name="to" aria-label="Transferir a" style="width:auto">' . $transferOpts . '</select> '
                . '<button class="btn btn-secondary btn-sm"' . ($can('transfer') ? '' : ' disabled title="Este rol no tiene permiso"') . '>↪ Transferir</button></form>'
                . '</div></div>';
        }
        self::layout('Configuración', 'Pruebas: actúa como otro usuario sin molestar a nadie', $html, $me);
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
        if ($method === 'POST' && strpos((string) ($_POST['action'] ?? ''), 'test_') === 0) {
            self::test_post($me);
        }
        if ($method === 'GET' && ($_GET['tab'] ?? '') === 'test') {
            self::test_tab($schema, $me);
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
        if ($method === 'POST') {
            self::check_csrf();
            $tab = max(0, min(count($schema) - 1, (int) ($_POST['tab'] ?? 0)));
            $in = array();
            foreach ($schema[$tab]['fields'] as $f) {
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
            self::flash('Cambios guardados en «' . $schema[$tab]['group'] . '»');
            self::redirect('/admin/settings?tab=' . $tab);
        }
        $tab = isset($_GET['tab']) ? max(0, min(count($schema) - 1, (int) $_GET['tab'])) : 0;

        $tabs = self::settings_tabs($schema, $tab);

        $fields = '';
        foreach ($schema[$tab]['fields'] as $f) {
            $fields .= self::field($f, Settings::get($f['k']));
        }
        if ($schema[$tab]['group'] === 'WhatsApp y Facebook') {
            $fields = self::meta_guide() . $fields;
        }
        if ($schema[$tab]['group'] === 'Horario laboral') {
            $fields = '<div class="info-box">Zona horaria: <b>' . self::h(cfg('timezone')) . '</b> · Ahora mismo: <b>' . (Hours::is_open() ? 'DENTRO de horario' : 'FUERA de horario') . '</b></div>' . $fields;
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
        self::layout('Configuración', 'Mensajes automáticos, horario, traducción y canales de WhatsApp/Facebook', $html, $me);
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
            . '<li>Asigna también la <b>página de Facebook de Bezares</b> al usuario del sistema (<b>Asignar activos</b> → Páginas → control total).</li>'
            . '<li>En el usuario del sistema, <b>Generar nuevo token</b> → la app → caducidad <b>Nunca</b> → permisos <b>pages_messaging</b>, <b>pages_manage_metadata</b> y <b>pages_show_list</b>. Un token generado así para la página no caduca. (Alternativa: en la app, <b>Messenger</b> → <b>Configuración de la API de Messenger</b> → conectar la página → <b>Generar token</b>.)</li>'
            . '<li>Copia el token en <b>Page access token</b>.</li>'
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
                return '<div class="field"><label for="' . $k . '">' . self::h($f['label']) . '</label><input id="' . $k . '" type="number" min="0" name="' . $k . '" value="' . (int) $v . '">' . $help . '</div>';
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
                self::flash($id ? 'Usuario guardado' : 'Usuario creado');
                self::redirect('/admin/users');
            } catch (ApiError $e) {
                self::flash($e->getMessage(), true);
                self::redirect('/admin/users' . ($id ? '?edit=' . $id : ''));
            }
        }
        $edit = isset($_GET['edit']) ? Auth::agent((int) $_GET['edit']) : null;

        $rows = '';
        foreach (Auth::agents() as $a) {
            $online = (now_ms() - (int) $a['last_seen']) < 60000;
            $rows .= '<tr><td><span class="dot' . ($online ? ' on' : '') . '" title="' . ($online ? 'Conectado' : 'Desconectado') . '"></span><b>' . self::h($a['name']) . '</b><br><small>' . self::h($a['email']) . '</small></td>'
                . '<td><span class="badge b-' . (in_array($a['role'], array('admin', 'receptionist', 'sales'), true) ? self::h($a['role']) : 'custom') . '">' . self::h(Roles::name($a['role'])) . '</span></td>'
                . '<td><span class="badge ' . ((int) $a['active'] ? 'b-on">Activo' : 'b-off">Desactivado') . '</span></td>'
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
        self::layout('Usuarios', 'Recepcionistas, comerciales y administradores que usan el chat', '<div class="split">' . $list . '<div>' . $form . '</div></div>', $me, $actions);
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

    private static function stats($me)
    {
        $now = new DateTimeImmutable('now', tz());
        $from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : $now->modify('-29 days')->format('Y-m-d');
        $to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : $now->format('Y-m-d');
        $thr = isset($_GET['thr']) ? max(1, (int) $_GET['thr']) : max(1, (int) Settings::get('stats_threshold'));
        if (isset($_GET['range'])) { // botones rápidos: últimos 7/30/90 días
            $d = max(1, min(365, (int) $_GET['range']));
            $from = $now->modify('-' . ($d - 1) . ' days')->format('Y-m-d');
            $to = $now->format('Y-m-d');
        }
        $s = Stats::compute($from, $to, $thr);

        $kpi = function ($label, $value, $sub = '', $tone = '') {
            return '<div class="kpi ' . $tone . '"><div class="v">' . $value . '</div><div class="l">' . $label . '</div>' . ($sub ? '<div class="s">' . $sub . '</div>' : '') . '</div>';
        };
        $quick = '';
        foreach (array(7 => '7 días', 30 => '30 días', 90 => '90 días') as $d => $l) {
            $quick .= '<button type="submit" name="range" value="' . $d . '" class="btn btn-secondary btn-sm">' . $l . '</button> ';
        }

        $html = '<form method="get" action="/admin/" class="card filters">'
            . '<div class="field"><label for="from">Desde</label><input id="from" type="date" name="from" value="' . self::h($from) . '"></div>'
            . '<div class="field"><label for="to">Hasta</label><input id="to" type="date" name="to" value="' . self::h($to) . '"></div>'
            . '<div class="field"><label for="thr">Contestados en menos de (min)</label><input id="thr" type="number" name="thr" min="1" value="' . $thr . '"></div>'
            . '<button class="btn btn-primary">Calcular</button><span class="muted">o</span>' . $quick . '</form>'
            . '<div class="kpis">'
            . $kpi('Chats recibidos', $s['total'], $s['in_hours'] . ' en horario laboral')
            . $kpi('Atendidos', $s['answered'], self::pct($s['answered'], $s['total']) . ' del total (respondidos o resueltos)', 'ok')
            . $kpi('Resueltos', $s['resolved'], self::pct($s['resolved'], $s['total']) . ' del total', 'ok')
            . $kpi('Sin atender', $s['unanswered'], self::pct($s['unanswered'], $s['total']) . ' del total', $s['unanswered'] ? 'bad' : '')
            . $kpi('Contestados en &lt; ' . $thr . ' min', $s['within'], self::pct($s['within'], $s['total']) . ' del total', 'ok')
            . $kpi('En &lt; ' . $thr . ' min laborables', $s['within_biz'], self::pct($s['within_biz'], $s['total']) . ' del total', 'ok')
            . $kpi('Tiempo de respuesta medio', self::dur($s['avg']), 'Mediana: ' . self::dur($s['median']), 'warn')
            . $kpi('Tiempo medio en horario laboral', self::dur($s['avg_biz']), 'Mediana: ' . self::dur($s['median_biz']), 'warn')
            . '</div>';

        $max = 1;
        foreach ($s['by_day'] as $v) {
            $max = max($max, $v['total']);
        }
        $bars = '';
        foreach ($s['by_day'] as $d => $v) {
            $bars .= '<div class="bar" title="' . $d . ': ' . $v['total'] . ' chats, ' . $v['answered'] . ' contestados"><i style="height:' . round($v['total'] * 100 / $max) . '%"><b style="height:' . ($v['total'] ? round($v['answered'] * 100 / $v['total']) : 0) . '%"></b></i><span>' . substr($d, 8) . '</span></div>';
        }
        $html .= '<div class="card"><h2>Chats por día</h2>' . ($bars ? '<div class="bars">' . $bars . '</div><div class="legend"><span><span class="sw" style="background:#c6dcf5"></span>Recibidos</span><span><span class="sw" style="background:var(--brand)"></span>Contestados</span></div>' : '<p class="muted">Sin chats en este periodo.</p>') . '</div>';

        $agents = '';
        foreach ($s['by_agent'] as $a) {
            $agents .= '<tr><td><b>' . self::h($a['name']) . '</b></td><td>' . $a['answered'] . '</td><td>' . self::dur($a['avg']) . '</td><td>' . self::dur($a['avg_biz']) . '</td></tr>';
        }
        $names = array('web' => '🌐 Web', 'whatsapp' => '🟢 WhatsApp', 'facebook' => '🔵 Facebook');
        $channels = '';
        foreach ($s['by_channel'] as $k => $v) {
            $channels .= '<tr><td><b>' . self::h($names[$k] ?? $k) . '</b></td><td>' . $v['total'] . '</td><td>' . $v['answered'] . ' (' . self::pct($v['answered'], $v['total']) . ')</td></tr>';
        }
        $html .= '<div class="split">'
            . '<div class="card"><h2>Por agente (primera respuesta)</h2><div class="table-wrap"><table><thead><tr><th>Agente</th><th>Contestados</th><th>Tiempo medio</th><th>Medio laboral</th></tr></thead><tbody>' . ($agents ?: '<tr><td colspan="4" class="muted">Sin datos</td></tr>') . '</tbody></table></div></div>'
            . '<div class="card"><h2>Por canal</h2><div class="table-wrap"><table><thead><tr><th>Canal</th><th>Chats</th><th>Contestados</th></tr></thead><tbody>' . ($channels ?: '<tr><td colspan="3" class="muted">Sin datos</td></tr>') . '</tbody></table></div></div>'
            . '</div><p class="muted">Los tiempos se miden desde que el cliente inicia el chat hasta la primera respuesta de una persona o, si ocurre antes, hasta que se marca como resuelta (en ese momento se deja de contar). El tiempo laboral solo cuenta las horas dentro del horario configurado.</p>';
        self::layout('Estadísticas', 'Del ' . date('d/m/Y', strtotime($from)) . ' al ' . date('d/m/Y', strtotime($to)), $html, $me);
    }
}
