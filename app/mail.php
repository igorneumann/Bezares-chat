<?php
/**
 * Envío de correo: por SMTP si está configurado en config.php (recomendado) o con mail() de PHP.
 */
class Mailer
{
    /** Último error de envío (para el diagnóstico del panel y el registro). */
    public static $lastError = '';

    /** Cómo se envía: "SMTP host:puerto (tls)" o "mail() de PHP". */
    public static function transport()
    {
        return cfg('smtp_host')
            ? 'SMTP ' . cfg('smtp_host') . ':' . (int) cfg('smtp_port', 587) . ' (' . (cfg('smtp_secure', 'tls') ?: 'sin cifrar') . ')'
            : 'mail() de PHP (sin SMTP)';
    }

    public static function send($to, $subject, $text)
    {
        self::$lastError = '';
        if (!valid_email($to)) {
            self::$lastError = 'Destinatario no válido';
            return false;
        }
        $from = (string) cfg('mail_from', 'no-reply@' . (parse_url(cfg('base_url'), PHP_URL_HOST) ?: 'localhost'));
        $fromName = (string) cfg('mail_from_name', 'Bezares Chat');
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = array(
            'From' => '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
            'Date' => date('r'),
            'Message-ID' => '<' . random_token(12) . '@' . (parse_url(cfg('base_url'), PHP_URL_HOST) ?: 'localhost') . '>',
        );
        $body = chunk_split(base64_encode($text));
        try {
            if (cfg('smtp_host')) {
                return self::smtp($from, $to, $subjectEnc, $headers, $body);
            }
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= $k . ': ' . $v . "\r\n";
            }
            $ok = mail($to, $subjectEnc, $body, $h, '-f' . $from);
            if (!$ok) {
                $err = error_get_last();
                self::$lastError = 'mail() devolvió error' . ($err ? ': ' . $err['message'] : '');
            }
            return $ok;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            log_error('Correo no enviado: ' . $e->getMessage());
            return false;
        }
    }

    private static function smtp($from, $to, $subject, array $headers, $body)
    {
        $host = (string) cfg('smtp_host');
        $port = (int) cfg('smtp_port', 587);
        $secure = (string) cfg('smtp_secure', 'tls'); // tls (STARTTLS), ssl o ''
        $ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true)));
        $fp = stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new Exception("SMTP: $errstr ($errno)");
        }
        stream_set_timeout($fp, 15);
        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function ($c, $expect) use ($fp, $read) {
            if ($c !== null) {
                fwrite($fp, $c . "\r\n");
            }
            $r = $read();
            if ((int) substr($r, 0, 3) !== $expect) {
                throw new Exception('SMTP: respuesta inesperada ' . trim($r));
            }
            return $r;
        };
        $me = parse_url(cfg('base_url'), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, 220);
        $cmd('EHLO ' . $me, 250);
        if ($secure === 'tls') {
            $cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new Exception('SMTP: no se pudo activar TLS');
            }
            $cmd('EHLO ' . $me, 250);
        }
        if (cfg('smtp_user')) {
            $cmd('AUTH LOGIN', 334);
            $cmd(base64_encode((string) cfg('smtp_user')), 334);
            $cmd(base64_encode((string) cfg('smtp_pass')), 235);
        }
        $cmd('MAIL FROM:<' . $from . '>', 250);
        $cmd('RCPT TO:<' . $to . '>', 250);
        $cmd('DATA', 354);
        $msg = 'To: <' . $to . ">\r\nSubject: " . $subject . "\r\n";
        foreach ($headers as $k => $v) {
            $msg .= $k . ': ' . $v . "\r\n";
        }
        fwrite($fp, $msg . "\r\n" . $body . "\r\n.\r\n");
        $cmd(null, 250);
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }
}
