<?php
/** Cliente HTTP saliente basado en cURL (OpenAI, Meta). */
class Http
{
    /**
     * @return array{status:int, body:string, headers:array, error:?string}
     */
    public static function request($method, $url, array $headers = array(), $body = null, $timeout = 20, $maxBytes = 67108864)
    {
        $ch = curl_init($url);
        $respHeaders = array();
        $h = array();
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_USERAGENT      => 'BezaresChat/' . BZC_VERSION,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,       // solo HTTPS saliente
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($c, $dlTotal, $dl) use ($maxBytes) {
                return $dl > $maxBytes ? 1 : 0; // aborta descargas demasiado grandes
            },
        ));
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
}
