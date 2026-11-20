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
            if ($a && $a['role'] === 'admin') {
                self::start_admin_session($a);
            } else {
                self::set_remember_cookie('', time() - 3600);
                $a = null;
            }
        }
        self::$me = ($a && (int) $a['active'] && $a['role'] === 'admin') ? $a : false;
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
            . '<div class="side-foot"><div class="who">' . self::h($me['name']) . '</div><div class="role">Administrador</div>'
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
                if ($a['role'] !== 'admin') {
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
                $msg = $agent['role'] === 'admin'
                    ? 'Contraseña cambiada. Ya puedes iniciar sesión.'
                    : 'Contraseña cambiada. Ya puedes entrar en el programa Bezares Chat de Windows.';
                self::auth_page('Contraseña cambiada', self::alert($msg)
                    . ($agent['role'] === 'admin' ? self::nav_button('Iniciar sesión', '/admin/login', 'btn btn-primary btn-block') : ''), 'Por seguridad se han cerrado todas tus sesiones abiertas');
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

    private static function settings($method, $me)
    {
        $schema = Settings::schema();
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

        $tabs = '<form method="get" class="tabs" role="tablist">';
        foreach ($schema as $i => $g) {
            $tabs .= '<button type="submit" name="tab" value="' . $i . '" class="btn' . ($i === $tab ? ' on' : '') . '" role="tab" aria-selected="' . ($i === $tab ? 'true' : 'false') . '">' . self::h($g['group']) . '</button>';
        }
        $tabs .= '</form>';

        $fields = '';
        foreach ($schema[$tab]['fields'] as $f) {
            $fields .= self::field($f, Settings::get($f['k']));
        }
        if ($schema[$tab]['group'] === 'Horario laboral') {
            $fields = '<div class="info-box">Zona horaria: <b>' . self::h(cfg('timezone')) . '</b> · Ahora mismo: <b>' . (Hours::is_open() ? 'DENTRO de horario' : 'FUERA de horario') . '</b></div>' . $fields;
        }
        $html = $tabs . '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="tab" value="' . $tab . '">'
            . '<h2>' . self::h($schema[$tab]['group']) . '</h2>' . $fields
            . '<div class="savebar">' . '<button type="reset" class="btn btn-secondary">Deshacer cambios</button><button type="submit" class="btn btn-primary">💾 Guardar</button></div></form>';
        self::layout('Configuración', 'Mensajes automáticos, horario, traducción y canales de WhatsApp/Facebook', $html, $me);
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
                if ($id === (int) $me['id'] && ($d['role'] !== 'admin' || !$d['active'])) {
                    throw new ApiError('No puedes quitarte el rol de administrador ni desactivarte');
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
                . '<td><span class="badge b-' . self::h($a['role']) . '">' . self::h(Auth::ROLES[$a['role']] ?? $a['role']) . '</span></td>'
                . '<td><span class="badge ' . ((int) $a['active'] ? 'b-on">Activo' : 'b-off">Desactivado') . '</span></td>'
                . '<td class="actions">' . self::nav_button('✏️ Editar', '/admin/users', 'btn btn-secondary btn-sm', array('edit' => (int) $a['id'])) . '</td></tr>';
        }
        $list = '<div class="card"><h2>Equipo</h2><div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';

        $role = $edit ? $edit['role'] : 'sales';
        $opts = '';
        foreach (Auth::ROLES as $k => $l) {
            $opts .= '<option value="' . $k . '" ' . ($role === $k ? 'selected' : '') . '>' . $l . '</option>';
        }
        $form = '<form method="post" class="card">' . self::csrf_field() . '<input type="hidden" name="id" value="' . ($edit ? (int) $edit['id'] : 0) . '">'
            . '<h2>' . ($edit ? 'Editar usuario' : 'Nuevo usuario') . '</h2>'
            . '<div class="field"><label for="u_name">Nombre</label><input id="u_name" type="text" name="name" required value="' . self::h($edit ? $edit['name'] : '') . '"></div>'
            . '<div class="field"><label for="u_email">Correo (usuario de acceso)</label><input id="u_email" type="email" name="email" required autocomplete="off" value="' . self::h($edit ? $edit['email'] : '') . '"></div>'
            . '<div class="field"><label for="u_role">Rol</label><select id="u_role" name="role">' . $opts . '</select><small>Recepcionista: recibe todos los chats y transfiere. Comercial: solo ve los chats que le transfieren. Administrador: además entra en este panel.</small></div>'
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
