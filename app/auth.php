<?php
/** Usuarios (administrador, recepcionista, comercial) y sesiones por token. */
class Auth
{
    const ROLES = array('admin' => 'Administrador', 'receptionist' => 'Recepcionista', 'sales' => 'Comercial');
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
            'role'   => $a['role'],
            'active' => (int) $a['active'],
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
        $token = random_token();
        $now = now_ms();
        DB::insert('tokens', array('token_hash' => hash('sha256', $token), 'agent_id' => $a['id'], 'expires' => $now + self::TTL_MS, 'created_at' => $now));
        DB::exec('DELETE FROM tokens WHERE expires < ?', array($now));
        return array('token' => $token, 'agent' => self::out($a));
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
            $a = DB::row('SELECT a.* FROM tokens t JOIN agents a ON a.id = t.agent_id WHERE t.token_hash = ? AND t.expires > ? AND a.active = 1', array(hash('sha256', $t), now_ms()));
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
        $t = self::token();
        if ($t) {
            DB::delete('tokens', array('token_hash' => hash('sha256', $t)));
        }
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
            if (!isset(self::ROLES[$d['role']])) {
                throw new ApiError('Rol no válido');
            }
            $f['role'] = $d['role'];
        }
        if (isset($d['active'])) {
            $f['active'] = $d['active'] ? 1 : 0;
        }
        if (!empty($d['password'])) {
            if (strlen($d['password']) < 10 || !preg_match('/[A-Za-z]/', $d['password']) || !preg_match('/\d/', $d['password'])) {
                throw new ApiError('La contraseña debe tener al menos 10 caracteres, con letras y números');
            }
            if (strlen($d['password']) > 200) {
                throw new ApiError('Contraseña demasiado larga');
            }
            $f['pass_hash'] = password_hash($d['password'], PASSWORD_DEFAULT);
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

    public static function can_see($a, $c)
    {
        if (!$a || !$c) {
            return false;
        }
        return in_array($a['role'], array('admin', 'receptionist'), true) || (int) $c['assigned_to'] === (int) $a['id'];
    }
}
