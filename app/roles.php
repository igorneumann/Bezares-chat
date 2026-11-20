<?php
/**
 * Roles configurables con permisos (tabla `roles`).
 * Los roles predefinidos (admin, receptionist, sales) se crean con los permisos de siempre y se pueden editar;
 * el rol "admin" conserva siempre el acceso al panel para que nadie se quede fuera.
 */
class Roles
{
    /** Permisos disponibles: clave => [etiqueta, descripción]. */
    const PERMS = array(
        'view_all'     => array('Ver todos los chats', 'Si no lo tiene, solo ve los chats que le asignan o transfieren.'),
        'receive_new'  => array('Recibir chats nuevos', 'Suena el aviso cuando entra un chat nuevo sin asignar.'),
        'fallback_new' => array('Atender si no hay recepción', 'Ve y recibe los chats nuevos cuando no hay nadie con «Recibir chats nuevos» conectado.'),
        'transfer'     => array('Transferir', 'Puede pasar chats a otros compañeros conectados.'),
        'delete'       => array('Borrar', 'Puede borrar conversaciones definitivamente.'),
        'templates'    => array('Plantillas', 'Puede usar, crear y editar respuestas preparadas.'),
        'email'        => array('Enviar por correo', 'Puede enviar la transcripción de un chat por correo.'),
        'resolve'      => array('Marcar como resuelta', 'Puede marcar y reabrir conversaciones resueltas.'),
        'admin_panel'  => array('Panel de administración', 'Entra en chat.bezares.com/admin: configuración, usuarios, roles y estadísticas.'),
    );

    const DEFAULTS = array(
        'admin'        => array('Administrador', array('transfer', 'delete', 'email', 'resolve', 'admin_panel')),
        'receptionist' => array('Recepcionista', array('view_all', 'receive_new', 'transfer', 'delete', 'templates', 'email', 'resolve')),
        'sales'        => array('Comercial', array('fallback_new', 'email', 'resolve')),
    );

    private static $cache = null;

    /** Crea los roles predefinidos si no existen (instalación y actualización). */
    public static function ensure_defaults()
    {
        foreach (self::DEFAULTS as $key => $d) {
            DB::exec('INSERT IGNORE INTO roles (role_key, name, permissions, builtin) VALUES (?, ?, ?, 1)', array($key, $d[0], json_encode($d[1])));
        }
        self::$cache = null;
    }

    /** Todos los roles: clave => ['name', 'perms' => [...], 'builtin']. */
    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = array();
            try {
                foreach (DB::rows('SELECT * FROM roles ORDER BY builtin DESC, name') as $r) {
                    $perms = json_decode($r['permissions'], true);
                    self::$cache[$r['role_key']] = array(
                        'name'    => $r['name'],
                        'perms'   => array_values(array_intersect(array_keys(self::PERMS), is_array($perms) ? $perms : array())),
                        'builtin' => (int) $r['builtin'],
                    );
                }
            } catch (PDOException $e) {
                self::$cache = array();
            }
            if (!self::$cache) { // tabla aún no creada: valores predefinidos
                foreach (self::DEFAULTS as $k => $d) {
                    self::$cache[$k] = array('name' => $d[0], 'perms' => $d[1], 'builtin' => 1);
                }
            }
        }
        return self::$cache;
    }

    public static function exists($key)
    {
        return isset(self::all()[$key]);
    }

    public static function name($key)
    {
        $all = self::all();
        return isset($all[$key]) ? $all[$key]['name'] : (string) $key;
    }

    public static function perms($key)
    {
        $all = self::all();
        return isset($all[$key]) ? $all[$key]['perms'] : array();
    }

    /** ¿Tiene el agente este permiso (según su rol)? */
    public static function can($agent, $perm)
    {
        return $agent && in_array($perm, self::perms($agent['role']), true);
    }

    /** Claves de los roles que incluyen un permiso. */
    public static function with_perm($perm)
    {
        $out = array();
        foreach (self::all() as $k => $r) {
            if (in_array($perm, $r['perms'], true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** Guarda los permisos de un rol existente. */
    public static function save_perms($key, array $perms)
    {
        if (!self::exists($key)) {
            throw new ApiError('Rol no encontrado', 404);
        }
        $perms = array_values(array_intersect(array_keys(self::PERMS), $perms));
        if ($key === 'admin' && !in_array('admin_panel', $perms, true)) {
            $perms[] = 'admin_panel'; // el administrador nunca pierde el acceso al panel
        }
        DB::update('roles', array('permissions' => json_encode($perms)), array('role_key' => $key));
        self::$cache = null;
    }

    public static function rename($key, $name)
    {
        $name = clean_line($name, 60);
        if ($name === '') {
            throw new ApiError('El rol necesita un nombre');
        }
        DB::update('roles', array('name' => $name), array('role_key' => $key));
        self::$cache = null;
    }

    /** Crea un rol nuevo a partir de su nombre. Devuelve la clave. */
    public static function create($name, array $perms)
    {
        $name = clean_line($name, 60);
        if ($name === '') {
            throw new ApiError('El rol necesita un nombre');
        }
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : '';
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii ?: $name)), '_') ?: 'rol';
        $base = substr($base, 0, 30);
        $key = $base;
        for ($i = 2; self::exists($key); $i++) {
            $key = $base . '_' . $i;
        }
        if (count(self::all()) >= 30) {
            throw new ApiError('Se ha alcanzado el máximo de 30 roles');
        }
        DB::insert('roles', array('role_key' => $key, 'name' => $name, 'permissions' => json_encode(array_values(array_intersect(array_keys(self::PERMS), $perms))), 'builtin' => 0));
        self::$cache = null;
        return $key;
    }

    /** Borra un rol personalizado sin usuarios. */
    public static function delete($key)
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new ApiError('Rol no encontrado', 404);
        }
        if ($all[$key]['builtin']) {
            throw new ApiError('Los roles predefinidos no se pueden borrar (sí puedes cambiar sus permisos)');
        }
        $n = (int) DB::val('SELECT COUNT(*) FROM agents WHERE role = ?', array($key));
        if ($n > 0) {
            throw new ApiError('No se puede borrar: ' . $n . ' usuario(s) tienen este rol. Cámbiales el rol antes.');
        }
        DB::delete('roles', array('role_key' => $key));
        self::$cache = null;
    }
}
