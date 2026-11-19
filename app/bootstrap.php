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

function client_ip()
{
    $h = (string) cfg('client_ip_header', '');
    if ($h !== '' && !empty($_SERVER[$h])) {
        $ip = trim(explode(',', $_SERVER[$h])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (cfg('client_ip_header') && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/** Cabeceras de seguridad comunes a todas las respuestas. */
function security_headers()
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
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

/** Comprueba sin incrementar si se superó el límite. */
function rate_exceeded($key, $max)
{
    return (int) cache_get('rl:' . $key) >= $max;
}

/** Incrementa el contador de un límite. */
function rate_hit($key, $seconds)
{
    $k = 'rl:' . $key;
    cache_set($k, (int) cache_get($k) + 1, $seconds);
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

/** Limitador por IP. Devuelve false si se supera $max en $seconds. */
function rate_limit($key, $max, $seconds)
{
    $k = $key . ':' . md5(client_ip());
    if (rate_exceeded($k, $max)) {
        return false;
    }
    rate_hit($k, $seconds);
    return true;
}

function log_error($msg)
{
    error_log('[bezares-chat] ' . (is_string($msg) ? $msg : json_encode($msg)));
}
