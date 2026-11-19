<?php
/** Panel web de administración: chat.bezares.com/admin */
class Admin
{
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

    private static function check_csrf()
    {
        if (!isset($_POST['csrf']) || !hash_equals(self::csrf(), (string) $_POST['csrf'])) {
            http_response_code(400);
            exit('Formulario caducado, vuelve atrás y recarga la página.');
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
        return '<div class="flash ' . ($b ? 'bad' : '') . '">' . self::h($m) . '</div>';
    }

    private static function me()
    {
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        $a = Auth::agent($_SESSION['admin_id']);
        return ($a && (int) $a['active'] && $a['role'] === 'admin') ? $a : null;
    }

    public static function handle($method)
    {
        session_name('bzc_admin');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(array('lifetime' => 0, 'path' => '/admin', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict'));
        session_start();
        header('Cache-Control: no-store');
        // Sin JavaScript en el panel: CSP muy estricta.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

        // Caducidad por inactividad (2 h)
        if (!empty($_SESSION['admin_id']) && time() - (int) ($_SESSION['last'] ?? 0) > 7200) {
            $_SESSION = array();
            session_regenerate_id(true);
        }
        $_SESSION['last'] = time();

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $page = trim(preg_replace('#^/admin#', '', $path), '/');
        if ($path === '/' || $path === '/index.php') {
            self::redirect('/admin/');
        }

        // Primera instalación
        try {
            DB::install();
            $hasAgents = (int) DB::val('SELECT COUNT(*) FROM agents') > 0;
        } catch (PDOException $e) {
            log_error('BD: ' . $e->getMessage()); // el detalle solo va al log, no a la pantalla
            self::layout('Error', '<div class="flash bad">No se puede conectar a la base de datos. Revisa config.php (el detalle está en el log de errores del servidor).</div>', null);
        }
        if (!$hasAgents) {
            self::setup($method);
        }

        if ($page === 'login') {
            self::login($method);
        }
        if ($page === 'logout' && $method === 'POST') {
            self::check_csrf();
            $_SESSION = array();
            session_destroy();
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

    /* ---------------------------------------------------------- vistas */

    private static function layout($title, $body, $me)
    {
        $nav = '';
        if ($me) {
            $cur = trim(preg_replace('#^/admin#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
            $items = array('' => 'Estadísticas', 'settings' => 'Configuración', 'users' => 'Usuarios');
            foreach ($items as $k => $l) {
                $nav .= '<a href="/admin/' . $k . '" class="' . ($cur === $k ? 'on' : '') . '">' . $l . '</a>';
            }
            $nav .= '<span class="sp"></span><span class="who">' . self::h($me['name']) . '</span>'
                . '<form method="post" action="/admin/logout" style="margin:0"><input type="hidden" name="csrf" value="' . self::csrf() . '"><button class="btn sec" style="padding:4px 10px">Salir</button></form>';
        }
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . ' · Bezares Chat</title><style>' . self::css() . '</style></head><body>'
            . '<header><b>● Bezares Chat</b>' . $nav . '</header><main><h1>' . self::h($title) . '</h1>' . self::flash() . $body . '</main></body></html>';
        exit;
    }

    private static function css()
    {
        return ':root{--c:#0057a8;--l:#e2e6ed;--m:#667085}*{box-sizing:border-box}body{margin:0;font:14px/1.5 "Segoe UI",system-ui,sans-serif;background:#f4f6f9;color:#1d2433}'
            . 'header{display:flex;gap:18px;align-items:center;background:#fff;border-bottom:1px solid var(--l);padding:12px 20px;flex-wrap:wrap}header b{color:var(--c);font-size:16px}header a{color:#1d2433;text-decoration:none;padding:4px 2px}header a.on{color:var(--c);border-bottom:2px solid var(--c)}.sp{flex:1}.who{color:var(--m)}'
            . 'main{max-width:1050px;margin:0 auto;padding:20px 16px 60px}h1{font-size:22px;margin:4px 0 16px}h2{font-size:17px;margin:28px 0 10px}'
            . '.box{background:#fff;border:1px solid var(--l);border-radius:12px;padding:18px;margin-bottom:16px}'
            . 'label{display:block;margin-bottom:12px}label>span{display:block;font-weight:600;margin-bottom:3px}small,.help{color:var(--m);font-size:12px}'
            . 'input[type=text],input[type=email],input[type=password],input[type=number],input[type=date],textarea,select{width:100%;max-width:640px;padding:8px 10px;border:1px solid var(--l);border-radius:8px;font:inherit}textarea{min-height:58px}input[type=number]{max-width:120px}'
            . 'button,.btn{background:var(--c);color:#fff;border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer;text-decoration:none;display:inline-block}.btn.sec{background:#fff;color:#1d2433;border:1px solid var(--l)}.btn.danger{background:#c62828}'
            . '.flash{background:#e6f4ea;color:#1a7f37;padding:10px 14px;border-radius:8px;margin-bottom:14px}.flash.bad{background:#fdecec;color:#c62828}'
            . 'table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--l)}th{color:var(--m);font-weight:600;font-size:13px}'
            . '.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}.card{background:#fff;border:1px solid var(--l);border-radius:12px;padding:14px}.card .v{font-size:26px;font-weight:700}.card .l{color:var(--m);font-size:12px}.card .s{font-size:12px;margin-top:4px}'
            . '.row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}.row label{margin:0}'
            . '.bars{display:flex;align-items:flex-end;gap:3px;height:150px;background:#fff;border:1px solid var(--l);border-radius:12px;padding:10px}.bar{flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%;align-items:center;min-width:6px}.bar i{display:block;width:100%;background:#c6dcf5;border-radius:3px 3px 0 0;position:relative}.bar i b{position:absolute;bottom:0;left:0;right:0;background:var(--c);border-radius:3px 3px 0 0}.bar span{font-size:9px;color:var(--m)}'
            . '.hours td{padding:4px 8px}.hours input[type=time]{padding:4px}.tabs a{margin-right:12px}'
            . '.login{max-width:380px;margin:60px auto}';
    }

    /* ---------------------------------------------------------- instalación */

    private static function setup($method)
    {
        $err = '';
        if ($method === 'POST') {
            self::check_csrf();
            if (!hash_equals((string) cfg('setup_key'), (string) ($_POST['setup_key'] ?? '')) || cfg('setup_key') === 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA') {
                $err = 'Clave de instalación incorrecta (setup_key de config.php; no puede ser la de ejemplo).';
            } else {
                try {
                    $a = Auth::save(array('name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'password' => $_POST['password'] ?? '', 'role' => 'admin'));
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = (int) $a['id'];
                    self::flash('Instalación completada. Revisa la configuración.');
                    self::redirect('/admin/settings');
                } catch (ApiError $e) {
                    $err = $e->getMessage();
                }
            }
        }
        self::layout('Instalación', ($err ? '<div class="flash bad">' . self::h($err) . '</div>' : '')
            . '<form method="post" class="box login"><p>Base de datos lista. Crea el primer usuario <b>Administrador</b>.</p>'
            . '<input type="hidden" name="csrf" value="' . self::csrf() . '">'
            . '<label><span>Clave de instalación (setup_key)</span><input type="password" name="setup_key" required></label>'
            . '<label><span>Nombre</span><input type="text" name="name" required></label>'
            . '<label><span>Correo</span><input type="email" name="email" required></label>'
            . '<label><span>Contraseña (mín. 10, letras y números)</span><input type="password" name="password" minlength="10" required></label>'
            . '<button>Crear administrador</button></form>', null);
    }

    private static function login($method)
    {
        $err = '';
        if ($method === 'POST') {
            self::check_csrf();
            try {
                $a = Auth::check_credentials($_POST['email'] ?? '', $_POST['password'] ?? '');
                if ($a['role'] !== 'admin') {
                    throw new ApiError('Solo los administradores pueden entrar en el panel. Los agentes usan el programa de Windows.');
                }
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $a['id'];
                self::redirect('/admin/');
            } catch (ApiError $e) {
                $err = $e->getMessage();
            }
        }
        self::layout('Acceso', ($err ? '<div class="flash bad">' . self::h($err) . '</div>' : '')
            . '<form method="post" class="box login"><input type="hidden" name="csrf" value="' . self::csrf() . '">'
            . '<label><span>Correo</span><input type="email" name="email" required autofocus></label>'
            . '<label><span>Contraseña</span><input type="password" name="password" required></label>'
            . '<button>Entrar</button></form>', null);
    }

    /* ---------------------------------------------------------- configuración */

    private static function settings($method, $me)
    {
        if ($method === 'POST') {
            self::check_csrf();
            $in = array();
            foreach (Settings::fields() as $k => $f) {
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
            self::flash('Configuración guardada');
            self::redirect('/admin/settings' . (isset($_POST['tab']) ? '?tab=' . (int) $_POST['tab'] : ''));
        }
        $schema = Settings::schema();
        $tab = isset($_GET['tab']) ? max(0, min(count($schema) - 1, (int) $_GET['tab'])) : 0;
        $tabs = '<p class="tabs">';
        foreach ($schema as $i => $g) {
            $tabs .= $i === $tab ? '<b>' . self::h($g['group']) . '</b> ' : '<a href="?tab=' . $i . '">' . self::h($g['group']) . '</a> ';
        }
        $tabs .= '</p>';
        $html = $tabs . '<form method="post" class="box"><input type="hidden" name="csrf" value="' . self::csrf() . '"><input type="hidden" name="tab" value="' . $tab . '">';
        foreach ($schema[$tab]['fields'] as $f) {
            $html .= self::field($f, Settings::get($f['k']));
        }
        if ($tab === 2) {
            $html .= '<p class="help">Zona horaria: ' . self::h(cfg('timezone')) . '. Ahora mismo: <b>' . (Hours::is_open() ? 'DENTRO' : 'FUERA') . '</b> de horario.</p>';
        }
        $html .= '<button>Guardar</button></form>';
        self::layout('Configuración', $html, $me);
    }

    private static function field($f, $v)
    {
        $k = self::h($f['k']);
        $help = !empty($f['help']) ? '<small>' . self::h($f['help']) . '</small>' : '';
        switch ($f['type']) {
            case 'info':
                return '<p><b>' . self::h($f['label']) . ':</b> ' . self::h($f['help']) . '</p>';
            case 'bool':
                return '<label><input type="checkbox" name="' . $k . '" value="1" ' . ((int) $v ? 'checked' : '') . '> <b>' . self::h($f['label']) . '</b><br>' . $help . '</label>';
            case 'textarea':
                return '<label><span>' . self::h($f['label']) . '</span><textarea name="' . $k . '">' . self::h($v) . '</textarea>' . $help . '</label>';
            case 'number':
                return '<label><span>' . self::h($f['label']) . '</span><input type="number" min="0" name="' . $k . '" value="' . (int) $v . '">' . $help . '</label>';
            case 'color':
                return '<label><span>' . self::h($f['label']) . '</span><input type="color" name="' . $k . '" value="' . self::h($v) . '">' . $help . '</label>';
            case 'secret':
                $set = $v !== '' && $v !== null;
                return '<label><span>' . self::h($f['label']) . '</span><input type="password" autocomplete="new-password" name="' . $k . '" placeholder="' . ($set ? '•••••• guardado (déjalo vacío para mantenerlo)' : 'sin configurar') . '">'
                    . ($set ? ' <small><input type="checkbox" name="' . $k . '__clear" value="1"> borrar</small>' : '') . $help . '</label>';
            case 'hours':
                $days = array(1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo');
                $o = '<span style="font-weight:600">' . self::h($f['label']) . '</span><table class="hours" style="max-width:520px;margin-bottom:14px">';
                foreach ($days as $d => $l) {
                    $x = isset($v[$d]) ? $v[$d] : array('on' => 0, 'start' => '08:00', 'end' => '17:00');
                    $o .= '<tr><td>' . $l . '</td><td><label style="margin:0"><input type="checkbox" name="' . $k . '[' . $d . '][on]" value="1" ' . (!empty($x['on']) ? 'checked' : '') . '> laborable</label></td>'
                        . '<td><input type="time" name="' . $k . '[' . $d . '][start]" value="' . self::h($x['start']) . '"> – <input type="time" name="' . $k . '[' . $d . '][end]" value="' . self::h($x['end']) . '"></td></tr>';
                }
                return $o . '</table>';
            default:
                return '<label><span>' . self::h($f['label']) . '</span><input type="text" name="' . $k . '" value="' . self::h($v) . '">' . $help . '</label>';
        }
    }

    /* ---------------------------------------------------------- usuarios */

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
                        throw new ApiError('Marca "confirmar" para eliminar el usuario. Sus chats volverán a recepción.');
                    }
                    Auth::delete($id);
                    self::flash('Usuario eliminado');
                } else {
                    $d = array('name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'role' => $_POST['role'] ?? 'sales', 'active' => !empty($_POST['active']), 'password' => $_POST['password'] ?? '');
                    if ($id === (int) $me['id'] && ($d['role'] !== 'admin' || !$d['active'])) {
                        throw new ApiError('No puedes quitarte el rol de administrador ni desactivarte');
                    }
                    Auth::save($d, $id);
                    self::flash('Usuario guardado');
                }
            } catch (ApiError $e) {
                self::flash($e->getMessage(), true);
            }
            self::redirect('/admin/users');
        }
        $edit = isset($_GET['edit']) ? Auth::agent((int) $_GET['edit']) : null;
        $rows = '';
        foreach (Auth::agents() as $a) {
            $online = (now_ms() - (int) $a['last_seen']) < 60000;
            $rows .= '<tr><td>' . ($online ? '🟢 ' : '') . self::h($a['name']) . '</td><td>' . self::h($a['email']) . '</td><td>' . self::h(Auth::ROLES[$a['role']] ?? $a['role']) . '</td>'
                . '<td>' . ((int) $a['active'] ? 'Activo' : 'Desactivado') . '</td><td><a href="?edit=' . (int) $a['id'] . '">Editar</a></td></tr>';
        }
        $role = $edit ? $edit['role'] : 'sales';
        $opts = '';
        foreach (Auth::ROLES as $k => $l) {
            $opts .= '<option value="' . $k . '" ' . ($role === $k ? 'selected' : '') . '>' . $l . '</option>';
        }
        $html = '<table><thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<h2>' . ($edit ? 'Editar usuario' : 'Nuevo usuario') . '</h2>'
            . '<form method="post" class="box"><input type="hidden" name="csrf" value="' . self::csrf() . '"><input type="hidden" name="id" value="' . ($edit ? (int) $edit['id'] : 0) . '">'
            . '<label><span>Nombre</span><input type="text" name="name" required value="' . self::h($edit ? $edit['name'] : '') . '"></label>'
            . '<label><span>Correo (usuario de acceso)</span><input type="email" name="email" required value="' . self::h($edit ? $edit['email'] : '') . '"></label>'
            . '<label><span>Rol</span><select name="role">' . $opts . '</select><small>Recepcionista: recibe todos los chats y puede transferir. Comercial: solo ve los chats que le transfieren. Administrador: además entra en este panel.</small></label>'
            . '<label><span>Contraseña</span><input type="password" name="password" minlength="10" autocomplete="new-password" ' . ($edit ? 'placeholder="(sin cambios)"' : 'required') . '></label>'
            . '<label><input type="checkbox" name="active" value="1" ' . (!$edit || (int) $edit['active'] ? 'checked' : '') . '> Activo</label>'
            . '<div class="row"><button>' . ($edit ? 'Guardar' : 'Crear usuario') . '</button>'
            . ($edit ? ' <a class="btn sec" href="/admin/users">Cancelar</a>' : '')
            . ($edit && (int) $edit['id'] !== (int) $me['id'] ? ' <span style="margin-left:auto"><label style="display:inline;margin:0"><input type="checkbox" name="confirm_delete" value="1"> confirmar</label> <button name="delete" value="1" class="btn danger">Eliminar usuario</button></span>' : '')
            . '</div></form>';
        self::layout('Usuarios', $html, $me);
    }

    /* ---------------------------------------------------------- estadísticas */

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
        $s = Stats::compute($from, $to, $thr);
        $card = function ($l, $v, $sub = '') {
            return '<div class="card"><div class="v">' . $v . '</div><div class="l">' . $l . '</div>' . ($sub ? '<div class="s">' . $sub . '</div>' : '') . '</div>';
        };
        $html = '<form class="row box" method="get">'
            . '<label><span>Desde</span><input type="date" name="from" value="' . self::h($from) . '"></label>'
            . '<label><span>Hasta</span><input type="date" name="to" value="' . self::h($to) . '"></label>'
            . '<label><span>Contestados en menos de (min)</span><input type="number" name="thr" min="1" value="' . $thr . '"></label>'
            . '<button>Calcular</button></form>'
            . '<div class="cards">'
            . $card('Chats recibidos', $s['total'], $s['in_hours'] . ' en horario laboral')
            . $card('Contestados', $s['answered'], self::pct($s['answered'], $s['total']) . ' del total')
            . $card('Sin contestar', $s['unanswered'], self::pct($s['unanswered'], $s['total']))
            . $card('Contestados en &lt; ' . $thr . ' min', $s['within'], self::pct($s['within'], $s['total']) . ' del total')
            . $card('En &lt; ' . $thr . ' min laborables', $s['within_biz'], self::pct($s['within_biz'], $s['total']) . ' del total')
            . $card('Tiempo de respuesta medio', self::dur($s['avg']), 'Mediana: ' . self::dur($s['median']))
            . $card('Tiempo de respuesta en horario laboral', self::dur($s['avg_biz']), 'Mediana: ' . self::dur($s['median_biz']))
            . '</div>';

        $html .= '<h2>Por agente (primera respuesta)</h2><table><thead><tr><th>Agente</th><th>Chats contestados</th><th>Tiempo medio</th><th>Tiempo medio laboral</th></tr></thead><tbody>';
        foreach ($s['by_agent'] as $a) {
            $html .= '<tr><td>' . self::h($a['name']) . '</td><td>' . $a['answered'] . '</td><td>' . self::dur($a['avg']) . '</td><td>' . self::dur($a['avg_biz']) . '</td></tr>';
        }
        $html .= $s['by_agent'] ? '' : '<tr><td colspan="4">Sin datos</td></tr>';
        $html .= '</tbody></table><h2>Por canal</h2><table><thead><tr><th>Canal</th><th>Chats</th><th>Contestados</th></tr></thead><tbody>';
        $names = array('web' => 'Web', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook');
        foreach ($s['by_channel'] as $k => $v) {
            $html .= '<tr><td>' . self::h($names[$k] ?? $k) . '</td><td>' . $v['total'] . '</td><td>' . $v['answered'] . ' (' . self::pct($v['answered'], $v['total']) . ')</td></tr>';
        }
        $html .= $s['by_channel'] ? '' : '<tr><td colspan="3">Sin datos</td></tr>';
        $html .= '</tbody></table><h2>Chats por día</h2><div class="bars">';
        $max = 1;
        foreach ($s['by_day'] as $v) {
            $max = max($max, $v['total']);
        }
        foreach ($s['by_day'] as $d => $v) {
            $html .= '<div class="bar" title="' . $d . ': ' . $v['total'] . ' chats, ' . $v['answered'] . ' contestados"><i style="height:' . round($v['total'] * 100 / $max) . '%"><b style="height:' . ($v['total'] ? round($v['answered'] * 100 / $v['total']) : 0) . '%"></b></i><span>' . substr($d, 8) . '</span></div>';
        }
        $html .= '</div><p class="help">Azul claro: recibidos · Azul: contestados. Los tiempos se miden desde que el cliente inicia el chat hasta la primera respuesta de una persona.</p>';
        self::layout('Estadísticas', $html, $me);
    }
}
