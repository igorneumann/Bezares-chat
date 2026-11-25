<?php
/** WhatsApp Cloud API y Facebook Messenger (Meta Graph API). */
class Channels
{
    /** Ventana de 24 h de Meta para mensajes libres. */
    const WINDOW_MS = 86400000;

    public static function graph($path)
    {
        return 'https://graph.facebook.com/' . rawurlencode(Settings::get('graph_version') ?: 'v23.0') . '/' . ltrim($path, '/');
    }

    public static function verify_signature($raw)
    {
        $secret = Settings::get('meta_app_secret');
        if (!$secret) {
            // Sin App Secret no se puede comprobar que el aviso venga de Meta: se rechaza.
            log_error('Webhook rechazado: falta el App Secret de Meta en la configuración');
            return false;
        }
        $sig = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
        return strpos($sig, 'sha256=') === 0 && hash_equals(hash_hmac('sha256', $raw, $secret), substr($sig, 7));
    }

    public static function is_window_open($conv)
    {
        if (!in_array($conv['channel'], array('whatsapp', 'facebook'), true)) {
            return true;
        }
        return (now_ms() - (int) $conv['last_customer_at']) < self::WINDOW_MS;
    }

    /** WhatsApp con las 24 h pasadas: solo se puede contestar por correo. (En Facebook el mensaje se intenta y queda «No entregado».) */
    public static function must_email($conv)
    {
        return $conv['channel'] === 'whatsapp' && !self::is_window_open($conv);
    }

    /* ---------------------------------------------------------- WhatsApp */

    public static function handle_whatsapp(array $p)
    {
        if (!Settings::get('wa_enabled')) {
            return;
        }
        foreach ((array) ($p['entry'] ?? array()) as $entry) {
            foreach ((array) ($entry['changes'] ?? array()) as $ch) {
                $v = $ch['value'] ?? array();
                // Número que ha recibido el mensaje, según Meta: sirve para detectar un Phone number ID mal puesto (MetaCheck).
                $pid = preg_replace('/\D/', '', (string) ($v['metadata']['phone_number_id'] ?? ''));
                if ($pid !== '' && $pid !== '123456123' && !empty($v['messages'])) { // 123456123 = prueba de la consola de Meta
                    cache_set('wa:seen', array(
                        'id'    => $pid,
                        'phone' => preg_replace('/[^\d+ ]/', '', (string) ($v['metadata']['display_phone_number'] ?? '')),
                        'waba'  => preg_replace('/\D/', '', (string) ($entry['id'] ?? '')),
                        't'     => time(),
                    ));
                }
                $names = array();
                foreach ((array) ($v['contacts'] ?? array()) as $c) {
                    if (!empty($c['wa_id'])) {
                        $names[$c['wa_id']] = $c['profile']['name'] ?? '';
                    }
                }
                foreach ((array) ($v['messages'] ?? array()) as $m) {
                    $from = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                    if ($from === '') {
                        continue;
                    }
                    $type = $m['type'] ?? 'text';
                    $text = '';
                    $media = null;
                    switch ($type) {
                        case 'text':
                            $text = $m['text']['body'] ?? '';
                            break;
                        case 'image':
                        case 'audio':
                        case 'video':
                        case 'document':
                        case 'sticker':
                            $o = $m[$type] ?? array();
                            $text = $o['caption'] ?? '';
                            $media = self::wa_download($o['id'] ?? '', $o['mime_type'] ?? '', $o['filename'] ?? '');
                            if (!$media) {
                                $text = trim($text . ' [' . $type . ' no descargado]');
                            }
                            break;
                        case 'location':
                            $text = '📍 https://maps.google.com/?q=' . ($m['location']['latitude'] ?? '') . ',' . ($m['location']['longitude'] ?? '');
                            break;
                        case 'button':
                            $text = $m['button']['text'] ?? '';
                            break;
                        case 'interactive':
                            $text = $m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? '');
                            break;
                        default:
                            $text = '[' . $type . ']';
                    }
                    Core::incoming('whatsapp', $from, array('name' => $names[$from] ?? '', 'phone' => '+' . $from, 'country' => Geo::from_phone($from)), $text, $media, (string) ($m['id'] ?? ''));
                }
            }
        }
    }

    private static function wa_download($id, $mime, $filename)
    {
        if (!$id) {
            return null;
        }
        $auth = array('Authorization' => 'Bearer ' . Settings::get('wa_token'));
        $r = Http::request('GET', self::graph(rawurlencode($id)), $auth, null, 15);
        $d = json_decode($r['body'], true);
        if (empty($d['url'])) {
            return null;
        }
        return Media::store_remote($d['url'], $auth, $mime ?: ($d['mime_type'] ?? ''), $filename);
    }

    private static function wa_send($to, array $msg)
    {
        $r = Http::json('POST', self::graph(rawurlencode(Settings::get('wa_phone_id')) . '/messages'), array_merge(array(
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => preg_replace('/\D/', '', $to),
        ), $msg), array('Authorization' => 'Bearer ' . Settings::get('wa_token')));
        return self::result($r, $r['json']['messages'][0]['id'] ?? '');
    }

    /** Último número que recibió un mensaje de WhatsApp: ['id', 'phone', 'waba', 't'] o null. */
    public static function wa_seen()
    {
        $s = cache_get('wa:seen');
        return is_array($s) && !empty($s['id']) ? $s : null;
    }

    /* ---------------------------------------------------------- Messenger */

    public static function handle_facebook(array $p)
    {
        if (!Settings::get('fb_enabled') || ($p['object'] ?? '') !== 'page') {
            return;
        }
        foreach ((array) ($p['entry'] ?? array()) as $entry) {
            foreach ((array) ($entry['messaging'] ?? array()) as $ev) {
                $msg = $ev['message'] ?? null;
                $psid = (string) ($ev['sender']['id'] ?? '');
                if (!$msg || $psid === '' || !empty($msg['is_echo'])) {
                    continue;
                }
                $mid = (string) ($msg['mid'] ?? '');
                $text = (string) ($msg['text'] ?? '');
                $profile = self::fb_profile($psid);
                $atts = (array) ($msg['attachments'] ?? array());
                if (!$atts) {
                    Core::incoming('facebook', $psid, $profile, $text, null, $mid);
                    continue;
                }
                foreach ($atts as $i => $a) {
                    $t = $i === 0 ? $text : '';
                    $media = null;
                    $url = $a['payload']['url'] ?? '';
                    $type = $a['type'] ?? '';
                    if ($url && in_array($type, array('image', 'audio', 'video', 'file'), true)) {
                        $media = Media::store_remote($url);
                        if (!$media) {
                            $t = trim($t . ' ' . $url);
                        }
                    } elseif ($type === 'location') {
                        $c = $a['payload']['coordinates'] ?? array();
                        $t = trim($t . ' 📍 https://maps.google.com/?q=' . ($c['lat'] ?? '') . ',' . ($c['long'] ?? ''));
                    } elseif ($url) {
                        $t = trim($t . ' ' . $url);
                    }
                    Core::incoming('facebook', $psid, $profile, $t, $media, $mid . ($i ? '#' . $i : ''));
                }
            }
        }
    }

    private static function fb_profile($psid)
    {
        $k = 'fbp:' . md5($psid);
        $c = cache_get($k);
        if (is_array($c)) {
            return $c;
        }
        // El token va en la cabecera Authorization, no en la URL (no queda en registros de intermediarios).
        $url = self::graph(rawurlencode($psid)) . '?fields=first_name,last_name';
        $d = json_decode(Http::request('GET', $url, self::fb_auth(), null, 10)['body'], true);
        $out = array('name' => trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')));
        cache_set($k, $out, 86400);
        return $out;
    }

    private static function fb_auth()
    {
        return array('Authorization' => 'Bearer ' . Settings::get('fb_page_token'));
    }

    private static function fb_send($psid, array $message)
    {
        $r = Http::json('POST', self::graph('me/messages'), array(
            'recipient'      => array('id' => $psid),
            'messaging_type' => 'RESPONSE',
            'message'        => $message,
        ), self::fb_auth());
        return self::result($r, $r['json']['message_id'] ?? '');
    }

    /* ---------------------------------------------------------- común */

    private static function result($r, $id)
    {
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return array('ok' => true, 'id' => (string) $id);
        }
        $err = $r['json']['error']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['status']);
        return array('ok' => false, 'error' => mb_substr($err, 0, 250));
    }

    /** Envía un mensaje saliente por el canal de la conversación ($text ya traducido). */
    public static function send($conv, $text, $media = null)
    {
        if ($conv['channel'] === 'web') {
            return array('ok' => true, 'id' => '');
        }
        if (!self::is_window_open($conv)) {
            return array('ok' => false, 'error' => 'Ventana de 24 h cerrada: el cliente debe escribir primero (norma de Meta).');
        }
        if ($conv['channel'] === 'whatsapp') {
            if (!Settings::get('wa_enabled')) {
                return array('ok' => false, 'error' => 'WhatsApp desactivado');
            }
            if ($media) {
                $t = $media['type'] === 'file' ? 'document' : $media['type'];
                $o = array('link' => Media::url($media['url'], Media::TTL_META)); // enlace firmado temporal para que Meta lo descargue
                if ($text !== '' && in_array($t, array('image', 'video', 'document'), true)) {
                    $o['caption'] = $text;
                    $text = '';
                }
                if ($t === 'document') {
                    $o['filename'] = $media['name'];
                }
                $r = self::wa_send($conv['external_id'], array('type' => $t, $t => $o));
                if (!$r['ok'] || $text === '') {
                    return $r;
                }
            }
            return self::wa_send($conv['external_id'], array('type' => 'text', 'text' => array('body' => $text, 'preview_url' => true)));
        }
        if ($conv['channel'] === 'facebook') {
            if (!Settings::get('fb_enabled')) {
                return array('ok' => false, 'error' => 'Facebook desactivado');
            }
            if ($media) {
                $r = self::fb_send($conv['external_id'], array('attachment' => array(
                    'type'    => $media['type'],
                    'payload' => array('url' => Media::url($media['url'], Media::TTL_META), 'is_reusable' => false),
                )));
                if (!$r['ok'] || $text === '') {
                    return $r;
                }
            }
            return self::fb_send($conv['external_id'], array('text' => $text));
        }
        return array('ok' => false, 'error' => 'Canal desconocido');
    }
}
