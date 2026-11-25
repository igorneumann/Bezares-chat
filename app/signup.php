<?php
/**
 * Alta de agentes con enlace secreto.
 *
 * 1. El administrador genera el enlace en /admin/users (uno vigente como máximo; puede deshabilitarlo).
 * 2. El agente rellena el formulario. Solo se admiten los dominios de correo configurados y los roles sin
 *    acceso al panel (nunca Administrador ni Recepcionista).
 * 3. Recibe un correo para confirmar su dirección. La cuenta sigue DESACTIVADA.
 * 4. Al confirmarlo, los administradores reciben un aviso en el programa de Windows: habilitar, ignorar o borrar.
 *
 * Columna agents.signup: '' = usuario normal · 'unconfirmed' = falta confirmar el correo ·
 * 'pending' = espera al administrador · 'ignored' = el administrador lo ignoró (sigue desactivado).
 */
class Signup
{
    const CONFIRM_TTL = 86400;      // el enlace de confirmación vale 24 horas
    const MAX_UNCONFIRMED = 100;

    /* ------------------------------------------------ enlace secreto */

    /** ['token', 'by', 't'] del enlace vigente, o null si está deshabilitado. */
    public static function link()
    {
        $l = cache_get('signup:link');
        return is_array($l) && !empty($l['token']) ? $l : null;
    }

    public static function url($token)
    {
        return rtrim((string) cfg('base_url'), '/') . '/admin/alta?clave=' . $token;
    }

    /** Genera un enlace nuevo; el anterior deja de funcionar. */
    public static function generate($me)
    {
        cache_set('signup:link', array('token' => random_token(24), 'by' => (string) $me['name'], 't' => now_ms()));
        log_error('Enlace de alta de agentes generado por ' . $me['name']);
    }

    public static function disable($me)
    {
        cache_del('signup:link');
        log_error('Enlace de alta de agentes deshabilitado por ' . $me['name']);
    }

    public static function valid_link($token)
    {
        $l = self::link();
        return $l && is_string($token) && hash_equals((string) $l['token'], $token);
    }

    /* ------------------------------------------------ qué se admite */

    /** Dominios de correo permitidos, en minúsculas y sin @. */
    public static function domains()
    {
        $out = array();
        foreach (preg_split('/[\s,;]+/', strtolower((string) Settings::get('signup_domains'))) as $d) {
            $d = ltrim(trim($d), '@');
            if (preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $d)) {
                $out[] = $d;
            }
        }
        return array_values(array_unique($out));
    }

    public static function allowed_email($email)
    {
        $at = strrpos($email, '@');
        return valid_email($email) && $at !== false && in_array(substr($email, $at + 1), self::domains(), true);
    }

    /** Roles que se pueden elegir: ninguno con acceso al panel, ni Administrador ni Recepcionista. */
    public static function roles()
    {
        $out = array();
        foreach (Roles::all() as $k => $r) {
            if ($k !== 'admin' && $k !== 'receptionist' && !in_array('admin_panel', $r['perms'], true)) {
                $out[$k] = $r['name'];
            }
        }
        return $out;
    }

    /* ------------------------------------------------ registro y confirmación */

    /** Crea la cuenta desactivada y envía el correo de confirmación. No revela si el correo ya tenía usuario. */
    public static function register($link, array $d)
    {
        if (!self::valid_link($link)) {
            throw new ApiError('Este enlace ya no es válido. Pide uno nuevo al administrador.', 404);
        }
        if (!rate_limit('signup', 10, 3600)) {
            throw new ApiError('Demasiados intentos. Vuelve a probar dentro de una hora.', 429);
        }
        $name = clean_line($d['name'] ?? '', 120);
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        $role = (string) ($d['role'] ?? '');
        $roles = self::roles();
        if ($name === '') {
            throw new ApiError('Escribe tu nombre');
        }
        if (!self::allowed_email($email)) {
            throw new ApiError('Solo se admiten correos de ' . implode(', ', array_map(function ($x) { return '@' . $x; }, self::domains())));
        }
        if ((string) ($d['password'] ?? '') !== (string) ($d['password2'] ?? '')) {
            throw new ApiError('Las dos contraseñas no coinciden');
        }
        if (!isset($roles[$role])) {
            throw new ApiError('Elige un rol');
        }
        $hash = Auth::hash_password((string) ($d['password'] ?? '')); // comprueba también los requisitos
        $kMail = 'signup:mail:' . md5($email);
        if (rate_exceeded($kMail, 3)) {
            throw new ApiError('Ya te hemos enviado varios correos. Revisa tu bandeja (también el correo no deseado) o prueba dentro de una hora.', 429);
        }
        rate_hit($kMail, 3600);

        $old = DB::row('SELECT * FROM agents WHERE email = ?', array($email));
        if ($old && $old['signup'] !== 'unconfirmed') {
            log_error('Alta de agente: ' . $email . ' ya tiene usuario; no se ha cambiado nada');
            return;
        }
        $token = random_token();
        $f = array(
            'name' => $name, 'role' => $role, 'pass_hash' => $hash, 'active' => 0, 'signup' => 'unconfirmed',
            'signup_token' => hash('sha256', $token), 'signup_expires' => time() + self::CONFIRM_TTL,
        );
        if ($old) {
            DB::update('agents', $f, array('id' => (int) $old['id'], 'signup' => 'unconfirmed'));
        } else {
            if ((int) DB::val("SELECT COUNT(*) FROM agents WHERE signup = 'unconfirmed'") >= self::MAX_UNCONFIRMED) {
                throw new ApiError('Hay demasiadas altas sin confirmar. Avisa al administrador.', 429);
            }
            DB::insert('agents', $f + array('email' => $email, 'created_at' => now_ms()));
        }
        $text = 'Hola ' . $name . ",\n\n"
            . 'Alguien ha pedido una cuenta de Bezares Chat con este correo (rol: ' . $roles[$role] . ").\n"
            . "Si has sido tú, confirma tu correo en este enlace (válido durante 24 horas):\n\n"
            . rtrim((string) cfg('base_url'), '/') . '/admin/confirmar?clave=' . $token . "\n\n"
            . "Después, un administrador tiene que habilitar la cuenta.\n\n"
            . "Si NO has sido tú, no abras el enlace y avisa al administrador.\n\n"
            . 'Bezares Chat';
        if (!Mailer::send($email, 'Confirma tu correo para Bezares Chat', $text)) {
            log_error('Alta de agente: ERROR al enviar la confirmación a ' . $email . ': ' . Mailer::$lastError);
            throw new ApiError('No se ha podido enviar el correo de confirmación. Inténtalo más tarde o avisa al administrador.', 502);
        }
    }

    /** Cuenta que espera confirmar el correo con ese enlace, o null. */
    public static function by_token($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return DB::row("SELECT * FROM agents WHERE signup = 'unconfirmed' AND signup_token = ? AND signup_expires > ?", array(hash('sha256', $token), time()));
    }

    /** Confirma el correo: la cuenta pasa a esperar al administrador. */
    public static function confirm($token)
    {
        $a = self::by_token($token);
        if (!$a) {
            throw new ApiError('El enlace no es válido, ya se ha usado o ha caducado.');
        }
        DB::update('agents', array('signup' => 'pending', 'signup_token' => '', 'signup_expires' => 0), array('id' => (int) $a['id'], 'signup' => 'unconfirmed'));
        log_error('Alta de agente: ' . $a['email'] . ' ha confirmado su correo; pendiente de habilitar');
        return $a;
    }

    /* ------------------------------------------------ decisión del administrador */

    /** Altas con el correo confirmado que esperan una decisión (las ignoradas no avisan). */
    public static function pending()
    {
        $out = array();
        foreach (DB::rows("SELECT * FROM agents WHERE signup = 'pending' AND active = 0 ORDER BY id LIMIT 50") as $a) {
            $out[] = array('id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email'], 'role_name' => Roles::name($a['role']), 'created_at' => (int) $a['created_at']);
        }
        return $out;
    }

    /** $action: enable, ignore o delete. Solo actúa sobre altas sin resolver, nunca sobre usuarios ya habilitados. */
    public static function decide($id, $action, $me)
    {
        $a = DB::row("SELECT * FROM agents WHERE id = ? AND active = 0 AND signup IN ('pending', 'ignored')", array((int) $id));
        if (!$a) {
            throw new ApiError('Esa solicitud ya está resuelta', 404);
        }
        if ($action === 'enable') {
            $roles = self::roles();
            if (!isset($roles[$a['role']])) {
                throw new ApiError('El rol elegido tiene ahora acceso al panel o ya no existe: revísalo en el panel (Usuarios) antes de habilitarlo.');
            }
            DB::update('agents', array('active' => 1, 'signup' => ''), array('id' => (int) $a['id']));
            Mailer::send($a['email'], 'Tu cuenta de Bezares Chat está activa', 'Hola ' . $a['name'] . ",\n\n"
                . "Un administrador ha habilitado tu cuenta. Ya puedes entrar en el programa con tu correo y tu contraseña.\n\n"
                . 'Descarga: ' . rtrim((string) cfg('base_url'), '/') . "/download/BezaresChat.exe\n\nBezares Chat");
        } elseif ($action === 'ignore') {
            DB::update('agents', array('signup' => 'ignored'), array('id' => (int) $a['id']));
        } elseif ($action === 'delete') {
            DB::delete('agents', array('id' => (int) $a['id']));
        } else {
            throw new ApiError('Acción no válida');
        }
        $verb = array('enable' => 'habilitada', 'ignore' => 'ignorada', 'delete' => 'borrada');
        log_error('Alta de agente: ' . $a['email'] . ' ' . $verb[$action] . ' por ' . $me['name'] . ' (agente ' . (int) $me['id'] . ')');
        return $a;
    }

    /** Limpieza diaria: altas cuyo correo no se confirmó a tiempo. */
    public static function cleanup()
    {
        DB::exec("DELETE FROM agents WHERE signup = 'unconfirmed' AND active = 0 AND signup_expires < ?", array(time() - 86400));
    }

    /* ------------------------------------------------ versión publicada del cliente de Windows */

    /** Versión del programa publicado en /download, o ''. */
    public static function client_version()
    {
        $d = json_decode((string) @file_get_contents(BZC_ROOT . '/public/download/version.json'), true);
        return is_array($d) && isset($d['version']) && preg_match('/^[\d.]{1,30}$/', (string) $d['version']) ? (string) $d['version'] : '';
    }
}
