<?php
/** Usuarios (con roles y permisos configurables, ver roles.php) y sesiones por token. */
class Auth
{
    const TTL_MS = 2592000000; // 30 días

    private static $current = false;
    private static $names = array();

    public static function agent($id)
    {
        return DB::row('SELECT * FROM agents WHERE id = ?', array((int) $id));
    }

    public static function agents()
    {
        return DB::rows('SELECT * FROM agents ORDER BY name');
    }

    public static function first_name($id)
    {
        if (!isset(self::$names[$id])) {
            $a = $id ? self::agent($id) : null;
            self::$names[$id] = $a ? explode(' ', trim($a['name']))[0] : '';
        }
        return self::$names[$id];
    }

    public static function out($a)
    {
        return array(
            'id'     => (int) $a['id'],
            'name'   => $a['name'],
            'role'      => $a['role'],
            'role_name' => Roles::name($a['role']),
            'perms'     => Roles::perms($a['role']), // el cliente muestra u oculta botones según estos permisos
            'active'    => (int) $a['active'],
            'online' => (now_ms() - (int) $a['last_seen']) < 60000,
        );
    }

    /** Comprueba credenciales (con límite de intentos). Devuelve la fila del agente. */
    public static function check_credentials($email, $password)
    {
        $email = strtolower(trim((string) $email));
        // Solo cuentan los fallos: 10 por IP y 20 por cuenta cada 15 min (frena fuerza bruta distribuida).
        $kIp = 'loginfail:ip:' . md5(client_ip());
        $kAcc = 'loginfail:acc:' . md5($email);
        if (rate_exceeded($kIp, 10) || rate_exceeded($kAcc, 20)) {
            throw new ApiError('Demasiados intentos fallidos. Espera 15 minutos.', 429);
        }
        $a = DB::row('SELECT * FROM agents WHERE email = ?', array($email));
        // Se verifica siempre un hash (aunque el usuario no exista) para no revelar qué correos existen por el tiempo de respuesta.
        $hash = $a ? $a['pass_hash'] : password_hash(random_token(8), PASSWORD_DEFAULT);
        $ok = password_verify((string) $password, $hash);
        if (!$a || !$ok || !(int) $a['active']) {
            rate_hit($kIp, 900);
            rate_hit($kAcc, 900);
            throw new ApiError('Correo o contraseña incorrectos', 401);
        }
        if (password_needs_rehash($a['pass_hash'], PASSWORD_DEFAULT)) {
            DB::update('agents', array('pass_hash' => password_hash((string) $password, PASSWORD_DEFAULT)), array('id' => $a['id']));
        }
        return $a;
    }

    public static function login($email, $password)
    {
        $a = self::check_credentials($email, $password);
        return array('token' => self::issue_token($a['id']), 'agent' => self::out($a));
    }

    /**
     * Crea un token de sesión (cliente de Windows o "recordar sesión" del panel).
     * Solo se guarda su hash; se revoca al cambiar la contraseña o desactivar el usuario.
     */
    public static function issue_token($agent_id, $ttl_ms = self::TTL_MS)
    {
        $token = random_token();
        $now = now_ms();
        DB::insert('tokens', array('token_hash' => hash('sha256', $token), 'agent_id' => (int) $agent_id, 'expires' => $now + $ttl_ms, 'created_at' => $now));
        DB::exec('DELETE FROM tokens WHERE expires < ?', array($now));
        return $token;
    }

    /** Agente activo dueño de un token válido, o null. */
    public static function agent_by_token($token)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', (string) $token)) {
            return null;
        }
        return DB::row('SELECT a.* FROM tokens t JOIN agents a ON a.id = t.agent_id WHERE t.token_hash = ? AND t.expires > ? AND a.active = 1', array(hash('sha256', $token), now_ms()));
    }

    public static function revoke_token($token)
    {
        if (preg_match('/^[a-f0-9]{64}$/', (string) $token)) {
            DB::delete('tokens', array('token_hash' => hash('sha256', $token)));
        }
    }

    /* ---------------------------------------------------------- recuperar contraseña */

    const RESET_TTL = 3600; // el enlace vale 1 hora

    /**
     * Envía el enlace de recuperación. Responde igual exista o no el correo (no revela usuarios).
     */
    public static function request_reset($email)
    {
        $email = strtolower(trim((string) $email));
        if (!rate_limit('forgot', 5, 3600)) {
            throw new ApiError('Demasiadas solicitudes. Inténtalo dentro de una hora.', 429);
        }
        // Al usuario siempre se le responde lo mismo; el resultado real queda en el registro del servidor.
        $kAcc = 'forgot:acc:' . md5($email);
        if (!valid_email($email)) {
            log_error('Recuperación de contraseña: correo no válido');
            return;
        }
        if (rate_exceeded($kAcc, 3)) {
            log_error('Recuperación de contraseña para ' . $email . ': NO enviado, límite de 3 solicitudes por hora alcanzado');
            return;
        }
        rate_hit($kAcc, 3600);
        $a = DB::row('SELECT * FROM agents WHERE email = ? AND active = 1', array($email));
        if (!$a) {
            log_error('Recuperación de contraseña para ' . $email . ': NO enviado, no hay ningún usuario activo con ese correo');
            return;
        }
        $token = random_token();
        cache_set('reset:' . hash('sha256', $token), (int) $a['id'], self::RESET_TTL);
        $link = rtrim(cfg('base_url'), '/') . '/admin/reset?token=' . $token;
        $text = "Hola " . $a['name'] . ",\n\n"
            . "Hemos recibido una solicitud para cambiar tu contraseña de Bezares Chat.\n"
            . "Para crear una nueva, abre este enlace (válido durante 1 hora y de un solo uso):\n\n"
            . $link . "\n\n"
            . "Si no lo has pedido tú, ignora este mensaje: tu contraseña actual sigue siendo válida.\n\n"
            . "Bezares Chat";
        if (Mailer::send($a['email'], 'Recuperar contraseña de Bezares Chat', $text)) {
            log_error('Recuperación de contraseña para ' . $a['email'] . ': entregado al servidor de correo (' . Mailer::transport() . '). Si no llega, revisa spam/cuarentena.');
        } else {
            log_error('Recuperación de contraseña para ' . $a['email'] . ': ERROR al enviar (' . Mailer::transport() . '): ' . Mailer::$lastError);
        }
    }

    /** Agente asociado a un enlace de recuperación válido, o null. */
    public static function reset_agent($token)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', (string) $token)) {
            return null;
        }
        $id = cache_get('reset:' . hash('sha256', $token));
        $a = $id ? self::agent($id) : null;
        return $a && (int) $a['active'] ? $a : null;
    }

    /** Cambia la contraseña con un enlace válido (un solo uso). Cierra todas las sesiones abiertas. */
    public static function complete_reset($token, $password)
    {
        $a = self::reset_agent($token);
        if (!$a) {
            throw new ApiError('El enlace no es válido o ha caducado. Solicita uno nuevo.');
        }
        self::save(array('password' => $password), $a['id']);
        cache_del('reset:' . hash('sha256', $token));
        return $a;
    }

    private static function token()
    {
        $t = isset($_SERVER['HTTP_X_BZC_TOKEN']) ? $_SERVER['HTTP_X_BZC_TOKEN'] : '';
        if ($t === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'bearer ') === 0) {
            $t = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
        }
        return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
    }

    /** Agente autenticado por token (API) o null. */
    public static function current()
    {
        if (self::$current !== false) {
            return self::$current;
        }
        self::$current = null;
        $t = self::token();
        if ($t) {
            $a = self::agent_by_token($t);
            if ($a) {
                DB::update('agents', array('last_seen' => now_ms()), array('id' => $a['id']));
                self::$current = $a;
            }
        }
        return self::$current;
    }

    public static function require_agent()
    {
        $a = self::current();
        if (!$a) {
            throw new ApiError('Sesión no válida', 401);
        }
        return $a;
    }

    public static function logout()
    {
        $a = self::agent_by_token(self::token());
        if ($a) {
            self::set_offline($a['id']);
        }
        self::revoke_token(self::token());
    }

    /** Aparece desconectado al instante (no recibe chats nuevos ni transferencias). */
    public static function set_offline($id)
    {
        DB::update('agents', array('last_seen' => 0), array('id' => (int) $id));
    }

    /** Comprueba los requisitos de la contraseña y devuelve su hash. */
    public static function hash_password($p)
    {
        if (strlen($p) < 10 || !preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            throw new ApiError('La contraseña debe tener al menos 10 caracteres, con letras y números');
        }
        if (strlen($p) > 200) {
            throw new ApiError('Contraseña demasiado larga');
        }
        return password_hash($p, PASSWORD_DEFAULT);
    }

    /** ¿Hay algún agente conectado que atienda chats? (para el indicador verde o rojo de la web) */
    public static function team_online()
    {
        $roles = array_unique(array_merge(Roles::with_perm('receive_new'), Roles::with_perm('fallback_new')));
        if (!$roles) {
            return false;
        }
        $in = implode(',', array_fill(0, count($roles), '?'));
        return (bool) DB::val("SELECT id FROM agents WHERE role IN ($in) AND active = 1 AND last_seen > ? LIMIT 1", array_merge(array_values($roles), array(now_ms() - 60000)));
    }

    public static function save(array $d, $id = 0)
    {
        $f = array();
        if (isset($d['name'])) {
            $f['name'] = clean_line($d['name'], 120);
        }
        if (isset($d['email'])) {
            $email = strtolower(trim($d['email']));
            if (!valid_email($email)) {
                throw new ApiError('Correo no válido');
            }
            if (DB::val('SELECT id FROM agents WHERE email = ? AND id <> ?', array($email, (int) $id))) {
                throw new ApiError('Ya existe un usuario con ese correo');
            }
            $f['email'] = $email;
        }
        if (isset($d['role'])) {
            if (!Roles::exists($d['role'])) {
                throw new ApiError('Rol no válido');
            }
            $f['role'] = $d['role'];
        }
        if (isset($d['active'])) {
            $f['active'] = $d['active'] ? 1 : 0;
            if ($f['active']) {
                $f += array('signup' => '', 'signup_token' => '', 'signup_expires' => 0); // habilitado desde el panel: alta resuelta
            }
        }
        if (!empty($d['password'])) {
            $f['pass_hash'] = self::hash_password((string) $d['password']);
        }
        if ($id) {
            DB::update('agents', $f, array('id' => (int) $id));
            if ((isset($f['active']) && !$f['active']) || isset($f['pass_hash'])) {
                DB::delete('tokens', array('agent_id' => (int) $id)); // cierra sus sesiones
            }
            return self::agent($id);
        }
        foreach (array('name', 'email', 'role', 'pass_hash') as $req) {
            if (empty($f[$req])) {
                throw new ApiError('Nombre, correo, rol y contraseña son obligatorios');
            }
        }
        $f += array('active' => 1);
        $f['created_at'] = now_ms();
        return self::agent(DB::insert('agents', $f));
    }

    public static function delete($id)
    {
        DB::delete('tokens', array('agent_id' => (int) $id));
        DB::delete('agents', array('id' => (int) $id));
        // Sus conversaciones vuelven a recepción.
        DB::exec('UPDATE conversations SET assigned_to = 0, accepted_at = 0, accepted_by = 0, updated_at = ? WHERE assigned_to = ?', array(now_ms(), (int) $id));
    }

    /** ¿Hay alguien con el permiso «Recibir chats nuevos» conectado (sincronizó en el último minuto)? */
    public static function reception_online()
    {
        static $online = null;
        if ($online === null) {
            $roles = Roles::with_perm('receive_new');
            if (!$roles) {
                return $online = false;
            }
            $in = implode(',', array_fill(0, count($roles), '?'));
            $online = (int) DB::val("SELECT COUNT(*) FROM agents WHERE role IN ($in) AND active = 1 AND last_seen > ?", array_merge($roles, array(now_ms() - 60000))) > 0;
        }
        return $online;
    }

    /** ¿Recibe este agente los chats nuevos sin asignar (por permiso, o como respaldo si no hay recepción)? */
    public static function gets_new($a)
    {
        return Roles::can($a, 'receive_new') || (Roles::can($a, 'fallback_new') && !self::reception_online());
    }

    /**
     * Visibilidad según los permisos del rol: «Ver todos los chats», los asignados/transferidos a él,
     * y los chats nuevos sin asignar si los recibe.
     */
    public static function can_see($a, $c)
    {
        if (!$a || !$c || !empty($c['is_test'])) {
            return false; // los chats de prueba solo se manejan desde la pestaña Pruebas del panel
        }
        return self::would_see($a, $c);
    }

    /** Regla de visibilidad por permisos (sin tener en cuenta si es chat de prueba). */
    public static function would_see($a, $c)
    {
        if (!$a || !$c) {
            return false;
        }
        if (Roles::can($a, 'view_all') || (int) $c['assigned_to'] === (int) $a['id']) {
            return true;
        }
        return !(int) $c['assigned_to'] && self::gets_new($a);
    }

    /** ¿Debe sonar este chat (abierto y sin atender) para este agente? */
    public static function should_ring($a, $c)
    {
        $assigned = (int) $c['assigned_to'];
        if ($assigned) {
            return $assigned === (int) $a['id']; // transferido o asignado a mí
        }
        return self::gets_new($a); // chat nuevo
    }

    /** Lanza 403 si el agente no tiene el permiso. */
    public static function require_perm($a, $perm)
    {
        if (!Roles::can($a, $perm)) {
            throw new ApiError('Tu rol no tiene permiso para esto (' . Roles::PERMS[$perm][0] . ')', 403);
        }
    }
}
