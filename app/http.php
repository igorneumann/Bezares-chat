<?php
/** Cliente HTTP saliente basado en cURL (OpenAI, Meta). Nunca sigue redirecciones automáticamente. */
class Http
{
    private static function base_opts($method, array $headers, $timeout, array &$respHeaders)
    {
        $h = array();
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        return array(
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_FOLLOWLOCATION  => false,          // las redirecciones se validan a mano (anti-SSRF)
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_HTTPHEADER      => $h,
            CURLOPT_USERAGENT       => 'BezaresChat/' . BZC_VERSION,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS, // solo HTTPS saliente
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HEADERFUNCTION  => function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        );
    }

    /**
     * Petición con respuesta en memoria (APIs JSON pequeñas).
     * @return array{status:int, body:string, headers:array, error:?string}
     */
    public static function request($method, $url, array $headers = array(), $body = null, $timeout = 20)
    {
        $respHeaders = array();
        $ch = curl_init($url);
        curl_setopt_array($ch, self::base_opts($method, $headers, $timeout, $respHeaders) + array(CURLOPT_RETURNTRANSFER => true));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        $err = $res === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array('status' => $status, 'body' => (string) $res, 'headers' => $respHeaders, 'error' => $err);
    }

    public static function json($method, $url, $data, array $headers = array(), $timeout = 20)
    {
        $headers['Content-Type'] = 'application/json';
        $r = self::request($method, $url, $headers, json_encode($data), $timeout);
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }

    /**
     * Descarga directa a un archivo, sin cargarla en memoria, abortando si supera $maxBytes.
     * $pinIp: IP ya validada a la que se fuerza la conexión (evita cambios de DNS entre la validación y la descarga).
     * @return array{status:int, headers:array, error:?string, bytes:int}
     */
    public static function download($url, array $headers, $dest, $maxBytes, $pinIp = null, $timeout = 60)
    {
        $respHeaders = array();
        $fp = fopen($dest, 'wb');
        if (!$fp) {
            return array('status' => 0, 'headers' => array(), 'error' => 'no se pudo crear el archivo temporal', 'bytes' => 0);
        }
        $ch = curl_init($url);
        $opts = self::base_opts('GET', $headers, $timeout, $respHeaders) + array(
            CURLOPT_FILE             => $fp,
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => function ($c, $dlTotal, $dl) use ($maxBytes) {
                return ($dlTotal > $maxBytes || $dl > $maxBytes) ? 1 : 0; // aborta si es demasiado grande
            },
        );
        if ($pinIp) {
            $host = parse_url($url, PHP_URL_HOST);
            $opts[CURLOPT_RESOLVE] = array($host . ':443:' . (strpos($pinIp, ':') !== false ? '[' . $pinIp . ']' : $pinIp));
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $err = $ok === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        clearstatcache(true, $dest);
        return array('status' => $status, 'headers' => $respHeaders, 'error' => $err, 'bytes' => (int) @filesize($dest));
    }
}
