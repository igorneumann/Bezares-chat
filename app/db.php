<?php
/** Envoltorio mínimo de PDO (MySQL / MariaDB). */
class DB
{
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
            self::$pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        }
        return self::$pdo;
    }

    public static function exec($sql, array $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function row($sql, array $params = array())
    {
        $r = self::exec($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function rows($sql, array $params = array())
    {
        return self::exec($sql, $params)->fetchAll();
    }

    public static function val($sql, array $params = array())
    {
        $r = self::exec($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function col($sql, array $params = array())
    {
        return self::exec($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert($table, array $data)
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update($table, array $data, array $where)
    {
        if (!$data) {
            return;
        }
        $set = implode(',', array_map(function ($c) { return '`' . $c . '` = ?'; }, array_keys($data)));
        $w = implode(' AND ', array_map(function ($c) { return '`' . $c . '` = ?'; }, array_keys($where)));
        self::exec('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $w, array_merge(array_values($data), array_values($where)));
    }

    public static function delete($table, array $where)
    {
        $w = implode(' AND ', array_map(function ($c) { return '`' . $c . '` = ?'; }, array_keys($where)));
        self::exec('DELETE FROM `' . $table . '` WHERE ' . $w, array_values($where));
    }

    const SCHEMA_VERSION = '7';

    /** Aplica el esquema si la versión guardada no es la actual (tras actualizar el código). */
    public static function ensure_schema()
    {
        try {
            $v = self::val("SELECT v FROM settings WHERE k = 'schema_version'");
        } catch (PDOException $e) {
            $v = null; // tablas aún no creadas
        }
        if ($v !== json_encode(self::SCHEMA_VERSION)) {
            self::install();
            self::exec("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)", array(json_encode(self::SCHEMA_VERSION)));
        }
    }

    /** Crea / actualiza las tablas. */
    public static function install()
    {
        $e = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $sql = array(
            "CREATE TABLE IF NOT EXISTS agents (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(120) NOT NULL,
              email VARCHAR(190) NOT NULL UNIQUE,
              role VARCHAR(20) NOT NULL DEFAULT 'sales',
              pass_hash VARCHAR(255) NOT NULL,
              active TINYINT(1) NOT NULL DEFAULT 1,
              last_seen BIGINT NOT NULL DEFAULT 0,
              created_at BIGINT NOT NULL DEFAULT 0
            ) $e",
            "CREATE TABLE IF NOT EXISTS tokens (
              token_hash CHAR(64) NOT NULL PRIMARY KEY,
              agent_id INT UNSIGNED NOT NULL,
              expires BIGINT NOT NULL,
              created_at BIGINT NOT NULL DEFAULT 0,
              KEY agent_id (agent_id)
            ) $e",
            "CREATE TABLE IF NOT EXISTS conversations (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              channel VARCHAR(20) NOT NULL,
              external_id VARCHAR(190) NOT NULL,
              name VARCHAR(120) NOT NULL DEFAULT '',
              email VARCHAR(190) NOT NULL DEFAULT '',
              phone VARCHAR(40) NOT NULL DEFAULT '',
              lang VARCHAR(8) NOT NULL DEFAULT '',
              page_url VARCHAR(500) NOT NULL DEFAULT '',
              status VARCHAR(20) NOT NULL DEFAULT 'open',
              onboarding VARCHAR(10) NOT NULL DEFAULT 'done',
              onboarding_tries TINYINT NOT NULL DEFAULT 0,
              assigned_to INT UNSIGNED NOT NULL DEFAULT 0,
              accepted_by INT UNSIGNED NOT NULL DEFAULT 0,
              accepted_at BIGINT NOT NULL DEFAULT 0,
              created_at BIGINT NOT NULL DEFAULT 0,
              updated_at BIGINT NOT NULL DEFAULT 0,
              last_customer_at BIGINT NOT NULL DEFAULT 0,
              last_agent_at BIGINT NOT NULL DEFAULT 0,
              first_response_at BIGINT NOT NULL DEFAULT 0,
              first_response_by INT UNSIGNED NOT NULL DEFAULT 0,
              reminder_sent_at BIGINT NOT NULL DEFAULT 0,
              followup_sent_at BIGINT NOT NULL DEFAULT 0,
              ooh_sent_at BIGINT NOT NULL DEFAULT 0,
              last_preview VARCHAR(255) NOT NULL DEFAULT '',
              KEY channel_ext (channel, external_id),
              KEY updated_at (updated_at),
              KEY assigned_to (assigned_to),
              KEY created_at (created_at)
            ) $e",
            "CREATE TABLE IF NOT EXISTS messages (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              conversation_id INT UNSIGNED NOT NULL,
              direction VARCHAR(10) NOT NULL,
              agent_id INT UNSIGNED NOT NULL DEFAULT 0,
              body TEXT NULL,
              body_translated TEXT NULL,
              lang VARCHAR(8) NOT NULL DEFAULT '',
              media_url VARCHAR(500) NOT NULL DEFAULT '',
              media_type VARCHAR(10) NOT NULL DEFAULT '',
              media_name VARCHAR(190) NOT NULL DEFAULT '',
              ext_id VARCHAR(190) NOT NULL DEFAULT '',
              send_status VARCHAR(20) NOT NULL DEFAULT '',
              send_error VARCHAR(255) NOT NULL DEFAULT '',
              created_at BIGINT NOT NULL DEFAULT 0,
              KEY conv (conversation_id, id),
              KEY created_at (created_at),
              KEY ext_id (ext_id)
            ) $e",
            "CREATE TABLE IF NOT EXISTS settings (
              k VARCHAR(64) NOT NULL PRIMARY KEY,
              v MEDIUMTEXT NOT NULL
            ) $e",
            "CREATE TABLE IF NOT EXISTS roles (
              role_key VARCHAR(40) NOT NULL PRIMARY KEY,
              name VARCHAR(60) NOT NULL,
              permissions TEXT NOT NULL,
              builtin TINYINT(1) NOT NULL DEFAULT 0
            ) $e",
            "CREATE TABLE IF NOT EXISTS templates (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              title VARCHAR(120) NOT NULL,
              body TEXT NOT NULL,
              created_by INT UNSIGNED NOT NULL DEFAULT 0,
              updated_at BIGINT NOT NULL DEFAULT 0
            ) $e",
            "CREATE TABLE IF NOT EXISTS rate_limits (
              k CHAR(64) NOT NULL PRIMARY KEY,
              n INT UNSIGNED NOT NULL DEFAULT 0,
              window_end INT UNSIGNED NOT NULL,
              KEY window_end (window_end)
            ) $e",
            "CREATE TABLE IF NOT EXISTS kv (
              k VARCHAR(190) NOT NULL PRIMARY KEY,
              v MEDIUMTEXT NOT NULL,
              expires INT NOT NULL DEFAULT 0
            ) $e",
        );
        foreach ($sql as $q) {
            self::pdo()->exec($q);
        }
        // Columnas añadidas en versiones posteriores (instalaciones existentes).
        $cols = self::col('SHOW COLUMNS FROM conversations');
        // Claves de rol personalizadas más largas que los roles originales.
        self::pdo()->exec("ALTER TABLE agents MODIFY role VARCHAR(40) NOT NULL DEFAULT 'sales'");
        Roles::ensure_defaults();
        if (!in_array('resolved_at', $cols, true)) {
            self::pdo()->exec("ALTER TABLE conversations ADD resolved_at BIGINT NOT NULL DEFAULT 0 AFTER first_response_by");
        }
        if (!in_array('country', $cols, true)) {
            self::pdo()->exec("ALTER TABLE conversations ADD country CHAR(2) NOT NULL DEFAULT '' AFTER lang");
        }
    }
}
