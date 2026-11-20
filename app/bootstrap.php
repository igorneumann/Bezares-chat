<?php
/** Arranque común: configuración, base de datos, utilidades. */

define('BZC_ROOT', dirname(__DIR__));
define('BZC_VERSION', '1.0.0');

$cfgFile = BZC_ROOT . '/config.php';
if (!file_exists($cfgFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Falta config.php (copia config.sample.php como config.php y rellénalo).');
}
$GLOBALS['BZC_CONFIG'] = require $cfgFile;
date_default_timezone_set(cfg('timezone', 'Europe/Madrid'));

// Nunca mostrar errores de PHP al público (se registran en el log del servidor).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');
header_remove('X-Powered-By');

require __DIR__ . '/db.php';
require __DIR__ . '/http.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/hours.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/media.php';
require __DIR__ . '/channels.php';
require __DIR__ . '/core.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/cron.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/geo.php';
require __DIR__ . '/roles.php';

/** Error de API con código HTTP. */
class ApiError extends Exception
{
    public $status;

    public function __construct($message, $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function cfg($key, $default = null)
{
    return isset($GLOBALS['BZC_CONFIG'][$key]) ? $GLOBALS['BZC_CONFIG'][$key] : $default;
}

/** Marca de tiempo en milisegundos. */
function now_ms()
{
    return (int) round(microtime(true) * 1000);
}

function tz()
{
    return new DateTimeZone(cfg('timezone', 'Europe/Madrid'));
}

function random_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

function clean_text($s, $max = 4000)
{
    $s = trim(strip_tags((string) $s));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    return mb_substr($s, 0, $max);
}

function clean_line($s, $max = 190)
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', clean_text($s, $max * 2))), 0, $max);
}

function valid_email($s)
{
    return filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
}

function lang_norm($lang)
{
    return strtolower(substr(preg_replace('/[^a-zA-Z]/', '', (string) $lang), 0, 2));
}

/** Reemplaza {name}, {email}, {company}. */
function fill_vars($text, $conv = null)
{
    $out = strtr((string) $text, array(
        '{name}'    => $conv && !empty($conv['name']) ? $conv['name'] : '',
        '{email}'   => $conv && !empty($conv['email']) ? $conv['email'] : '',
        '{company}' => Settings::get('company_name'),
    ));
    $out = preg_replace('/\s+([,.!?])/u', '$1', $out);
    return trim(preg_replace('/ {2,}/', ' ', $out));
}

/** ¿Está la IP dentro de alguno de los rangos (IP o CIDR, IPv4/IPv6)? */
function ip_in_ranges($ip, array $ranges)
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($ranges as $r) {
        $parts = explode('/', trim((string) $r), 2);
        $net = @inet_pton($parts[0]);
        if ($net === false || strlen($net) !== strlen($bin)) {
            continue;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($bin) * 8;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            continue;
        }
        $rem = $bits % 8;
        if ($rem === 0 || ((ord($bin[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

/** La petición llega desde un proxy configurado en trusted_proxies (y solo entonces se creen sus cabeceras). */
function from_trusted_proxy()
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return $remote !== '' && ip_in_ranges($remote, (array) cfg('trusted_proxies', array()));
}

function client_ip()
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $h = (string) cfg('client_ip_header', '');
    // La cabecera con la IP real solo se acepta si la conexión viene de un proxy de confianza;
    // si no, cualquiera podría enviarla para falsear su IP y saltarse los límites.
    if ($h !== '' && !empty($_SERVER[$h]) && from_trusted_proxy()) {
        $ip = trim(explode(',', $_SERVER[$h])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $remote;
}

function is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (from_trusted_proxy() && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

/** Clave secreta del servidor (firmas de enlaces y retos anti-bot). Se genera una vez y se guarda en la BD. */
function server_secret()
{
    static $s = null;
    if ($s === null) {
        $s = DB::val("SELECT v FROM settings WHERE k = 'server_secret'");
        $s = $s ? json_decode($s, true) : '';
        if (!$s) {
            $s = random_token(32);
            DB::exec("INSERT IGNORE INTO settings (k, v) VALUES ('server_secret', ?)", array(json_encode($s)));
            $s = json_decode(DB::val("SELECT v FROM settings WHERE k = 'server_secret'"), true);
        }
    }
    return $s;
}

function sign($data)
{
    return hash_hmac('sha256', $data, server_secret());
}

/** Valor de frame-ancestors: la propia web + webs de confianza de la configuración. */
function frame_ancestors()
{
    static $v = null;
    if ($v === null) {
        $list = array("'self'");
        try {
            $list = array_merge($list, Settings::trusted_origins());
        } catch (Exception $e) {
            // Sin base de datos (p. ej. antes de instalar): solo la propia web.
        }
        $v = implode(' ', array_unique($list));
    }
    return $v;
}

/** Cabeceras de seguridad comunes a todas las respuestas. */
function security_headers()
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Solo las "webs de confianza" pueden mostrar chat.bezares.com dentro de un iframe (p. ej. Nextcloud → Sitios externos).
    // Se usa CSP frame-ancestors (admite lista) en lugar de X-Frame-Options (solo DENY/SAMEORIGIN).
    header('Content-Security-Policy: frame-ancestors ' . frame_ancestors());
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Fuerza HTTPS salvo en local. */
function enforce_https()
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $local = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
    if (cfg('force_https', true) && !$local && !is_https() && PHP_SAPI !== 'cli') {
        header('Location: https://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', $host) . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), true, 301);
        exit;
    }
}

/*
 * Límites anti-abuso con contadores ATÓMICOS en MySQL y ventanas fijas:
 * un único INSERT ... ON DUPLICATE KEY UPDATE incrementa el contador, así que dos peticiones
 * simultáneas nunca leen el mismo valor (no se puede eludir el límite por concurrencia).
 */

/** Comprueba sin incrementar si se alcanzó el límite en la ventana actual. */
function rate_exceeded($key, $max)
{
    $n = DB::val('SELECT n FROM rate_limits WHERE k = ? AND window_end >= ?', array(hash('sha256', $key), time()));
    return (int) $n >= $max;
}

/** Incrementa atómicamente el contador y devuelve el valor tras el incremento. */
function rate_hit($key, $seconds)
{
    $now = time();
    $end = $now + max(1, (int) $seconds);
    $st = DB::exec(
        'INSERT INTO rate_limits (k, n, window_end) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(IF(window_end < ?, 1, n + 1)), window_end = IF(window_end < ?, ?, window_end)',
        array(hash('sha256', $key), $end, $now, $now, $end)
    );
    // rowCount 1 = fila nueva (n = 1); 2 = actualizada (LAST_INSERT_ID tiene el nuevo n, por conexión).
    return $st->rowCount() === 1 ? 1 : (int) DB::val('SELECT LAST_INSERT_ID()');
}

/** Caché simple con caducidad en la tabla kv (sustituye a los transients). */
function cache_get($key)
{
    $row = DB::row('SELECT v, expires FROM kv WHERE k = ?', array($key));
    if (!$row || ((int) $row['expires'] && (int) $row['expires'] < time())) {
        return null;
    }
    return json_decode($row['v'], true);
}

function cache_set($key, $value, $ttl = 0)
{
    DB::exec('REPLACE INTO kv (k, v, expires) VALUES (?, ?, ?)', array($key, json_encode($value), $ttl ? time() + $ttl : 0));
}

function cache_del($key)
{
    DB::exec('DELETE FROM kv WHERE k = ?', array($key));
}

/** Limitador por IP. Devuelve false si se supera $max en $seconds. */
function rate_limit($key, $max, $seconds)
{
    return rate_hit($key . ':' . client_ip(), $seconds) <= $max;
}

function log_error($msg)
{
    error_log('[bezares-chat] ' . (is_string($msg) ? $msg : json_encode($msg)));
}
