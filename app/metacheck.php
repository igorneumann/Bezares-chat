<?php
/**
 * Comprobación de permisos de WhatsApp y Facebook con Meta (panel → Configuración → WhatsApp y Facebook).
 * Solo lee: no envía mensajes ni cambia nada en Meta. El resultado se guarda sin ningún token.
 *
 * Cada línea: [s => ok|warn|bad|info, t => qué se ha comprobado, fix => cómo se arregla].
 */
class MetaCheck
{
    const KEY = 'meta:check';

    private static function row($s, $t, $fix = '')
    {
        return array('s' => $s, 't' => $t, 'fix' => $fix);
    }

    /** Fecha (en ms) en la zona horaria del panel. */
    private static function day($ms)
    {
        return (new DateTimeImmutable('@' . intdiv((int) $ms, 1000)))->setTimezone(tz())->format('d/m/Y H:i');
    }

    /** Consulta a Graph. Devuelve [ok, datos, error, código]. */
    private static function get($path, $token)
    {
        $r = Http::request('GET', Channels::graph($path), array('Authorization' => 'Bearer ' . $token), null, 15);
        $d = json_decode((string) $r['body'], true);
        if ($r['status'] >= 200 && $r['status'] < 300 && is_array($d)) {
            return array(true, $d, '', 0);
        }
        $e = is_array($d) && isset($d['error']) && is_array($d['error']) ? $d['error'] : array();
        $msg = (string) ($e['message'] ?? ($r['error'] ?: 'HTTP ' . $r['status']));
        // Por si Meta repitiera el token en el error: nunca se guarda ni se muestra.
        $msg = str_replace($token, '…', $msg);
        return array(false, array(), mb_substr($msg, 0, 220), (int) ($e['code'] ?? 0));
    }

    /** Último resultado guardado, o null. */
    public static function last()
    {
        $r = cache_get(self::KEY);
        return is_array($r) ? $r : null;
    }

    public static function run()
    {
        $numbers = array(); // ID => ['phone', 'waba'] que el token puede usar
        $app = '';
        $wa = self::whatsapp($numbers, $app);
        $fb = self::facebook($app);
        $res = array('t' => time(), 'wa' => $wa, 'fb' => $fb, 'numbers' => $numbers);
        cache_set(self::KEY, $res, 86400);
        return $res;
    }

    /** IDs que se ofrecen en «Usar este ID»: los que ha devuelto Meta y el del último mensaje recibido. */
    public static function can_use($id)
    {
        $seen = Channels::wa_seen();
        $last = self::last();
        return ($seen && $seen['id'] === $id) || ($last && isset($last['numbers'][$id]));
    }

    /* ------------------------------------------------ piezas comunes */

    /**
     * Inspecciona un token. Añade filas a $rows y devuelve los datos de Meta (o null si no se ha podido).
     * $want: tipo de token esperado. $scopes: permiso => [obligatorio, para qué].
     */
    private static function token(array &$rows, $token, $want, array $scopes, $where)
    {
        list($ok, $d, $err, $code) = self::get('debug_token?input_token=' . rawurlencode($token), $token);
        $d = $ok && isset($d['data']) && is_array($d['data']) ? $d['data'] : null;
        if (!$d) {
            $rows[] = $code === 190
                ? self::row('bad', 'El token no es válido o ha caducado. Meta dice: ' . $err, 'Genera uno nuevo y pégalo en «' . $where . '».')
                : self::row('warn', 'Meta no ha dejado inspeccionar el token: ' . $err, $want === 'SYSTEM_USER' ? 'Asigna el usuario del sistema a la app Chat Bezares con control total y genera el token otra vez.' : '');
            return null;
        }
        if (empty($d['is_valid'])) {
            $rows[] = self::row('bad', 'El token ya no es válido. ' . mb_substr((string) ($d['error']['message'] ?? ''), 0, 200), 'Genera uno nuevo y pégalo en «' . $where . '».');
            return $d;
        }
        $exp = (int) ($d['expires_at'] ?? 0);
        $rows[] = $exp === 0
            ? self::row('ok', 'El token es válido y no caduca' . (!empty($d['application']) ? ' (app «' . $d['application'] . '»)' : '') . '.')
            : self::row('warn', 'El token es válido, pero caduca el ' . date('d/m/Y H:i', $exp) . '.', 'Genera uno con caducidad «Nunca».');
        $type = strtoupper((string) ($d['type'] ?? ''));
        if ($type !== '' && $type !== $want) {
            if ($want === 'SYSTEM_USER') {
                $rows[] = $type === 'PAGE'
                    ? self::row('bad', 'Es el token de una página de Facebook, no el de WhatsApp.', 'En «' . $where . '» va el token del usuario del sistema; el de la página va en «Page access token».')
                    : self::row('warn', 'No es un token de usuario del sistema (es de tipo ' . $type . ').', 'Puede dejar de funcionar: usa uno de Usuarios del sistema, con caducidad «Nunca».');
            } else {
                $rows[] = self::row('bad', 'No es el token de una página (es de tipo ' . $type . ').', 'En «' . $where . '» va el token de acceso de la página de Facebook.');
            }
        }
        $have = isset($d['scopes']) && is_array($d['scopes']) ? $d['scopes'] : array();
        foreach ($scopes as $s => $x) {
            if (in_array($s, $have, true)) {
                $rows[] = self::row('ok', 'Permiso ' . $s . '.');
            } else {
                $rows[] = self::row($x[0] ? 'bad' : 'warn', 'Falta el permiso ' . $s . ' (' . $x[1] . ').', 'Genera un token nuevo marcando ese permiso.');
            }
        }
        return $d;
    }

    /** La clave secreta, ¿es la de la app que generó el token? */
    private static function secret(array &$rows, $app)
    {
        $secret = (string) Settings::get('meta_app_secret');
        if ($secret === '') {
            $rows[] = self::row('bad', 'Falta el App Secret: se rechazan los mensajes entrantes.', 'Cópialo de la app (Configuración → Básica) en «App Secret».');
            return;
        }
        if ($app === '') {
            return;
        }
        list($ok, , $err, $code) = self::get(rawurlencode($app) . '?fields=id', $app . '|' . $secret);
        if ($ok) {
            $rows[] = self::row('ok', 'El App Secret es el de la misma app que el token.');
        } elseif ($code === 190 || $code === 101 || $code === 1) {
            $rows[] = self::row('bad', 'El App Secret no es el de la app que generó el token.', 'Usa el token y el App Secret de la misma app (Chat Bezares).');
        } else {
            $rows[] = self::row('warn', 'No se ha podido comprobar el App Secret: ' . $err);
        }
    }

    /* ------------------------------------------------ WhatsApp */

    private static function whatsapp(array &$numbers, &$app)
    {
        $rows = array();
        $id = trim((string) Settings::get('wa_phone_id'));
        $token = (string) Settings::get('wa_token');
        if (!Settings::get('wa_enabled')) {
            $rows[] = self::row('warn', 'WhatsApp está desactivado.', 'Marca «WhatsApp activado» y guarda.');
        }
        if ($token === '') {
            $rows[] = self::row('bad', 'Falta el token permanente de WhatsApp.', 'Genéralo con el usuario del sistema (paso 3 de la guía).');
            return $rows;
        }
        $d = self::token($rows, $token, 'SYSTEM_USER', array(
            'whatsapp_business_messaging'  => array(true, 'enviar mensajes'),
            'whatsapp_business_management' => array(true, 'consultar los números y las cuentas'),
        ), 'WhatsApp token permanente');
        if ($d && empty($d['is_valid'])) {
            return $rows;
        }
        $app = $d ? preg_replace('/\D/', '', (string) ($d['app_id'] ?? '')) : '';

        // Cuentas de WhatsApp a las que alcanza el token (si el permiso vale para todas, Meta no las enumera).
        $targets = array();
        $listed = false;
        foreach ($d && isset($d['granular_scopes']) && is_array($d['granular_scopes']) ? $d['granular_scopes'] : array() as $g) {
            if (in_array($g['scope'] ?? '', array('whatsapp_business_messaging', 'whatsapp_business_management'), true) && !empty($g['target_ids'])) {
                $listed = true;
                foreach ((array) $g['target_ids'] as $t) {
                    $targets[preg_replace('/\D/', '', (string) $t)] = true;
                }
            }
        }
        $seen = Channels::wa_seen();
        $waba = $seen ? (string) $seen['waba'] : '';
        $check = array_slice(array_keys($targets), 0, 10);
        if ($waba !== '' && !in_array($waba, array_map('strval', $check), true)) {
            $check[] = $waba;
        }
        $assign = 'En business.facebook.com (cartera Bezares SA, ID 000000000000000) → Usuarios del sistema → Asignar activos: marca la cuenta de WhatsApp' . ($waba !== '' ? ' ' . $waba : '') . ' con control total y la app. Después genera un token nuevo.';
        $names = array();
        $reach = array();
        foreach ($check as $w) {
            $w = (string) $w;
            list($ok, $x, $err) = self::get(rawurlencode($w) . '?fields=id,name', $token);
            if (!$ok) {
                $rows[] = self::row($w === $waba ? 'bad' : 'warn', 'El token no puede leer la cuenta de WhatsApp ' . $w . ($w === $waba ? ', que es la que recibe vuestros mensajes' : '') . '. Meta dice: ' . $err, $assign);
                continue;
            }
            $reach[$w] = true;
            $names[$w] = (string) ($x['name'] ?? '');
            list($ok, $x) = self::get(rawurlencode($w) . '/phone_numbers?fields=id,display_phone_number&limit=50', $token);
            foreach ($ok ? (array) ($x['data'] ?? array()) : array() as $p) {
                $pid = preg_replace('/\D/', '', (string) ($p['id'] ?? ''));
                if ($pid !== '') {
                    $numbers[$pid] = array('phone' => preg_replace('/[^\d+ ]/', '', (string) ($p['display_phone_number'] ?? '')), 'waba' => $w, 'name' => $names[$w]);
                }
            }
        }
        if ($waba !== '' && isset($reach[$waba])) {
            $rows[] = self::row('ok', 'El token tiene acceso a la cuenta de WhatsApp que recibe vuestros mensajes: ' . $waba . ($names[$waba] !== '' ? ' («' . $names[$waba] . '»)' : '') . '.');
        } elseif (!$reach) {
            $rows[] = self::row('bad', $listed || $waba !== '' ? 'El token no tiene acceso a ninguna cuenta de WhatsApp.' : 'No se sabe a qué cuentas de WhatsApp tiene acceso el token.',
                $waba === '' ? 'Envía un WhatsApp a vuestro número y vuelve a comprobar. ' . $assign : $assign);
        }

        // El Phone number ID configurado.
        $list = array();
        foreach ($numbers as $k => $n) {
            $list[] = $n['phone'] . ' = ID ' . $k;
        }
        $use = $list ? 'Números que puede usar este token: ' . implode('; ', $list) . '. Elige el correcto con el botón de abajo.' : $assign;
        if ($id === '') {
            $rows[] = self::row('bad', 'Falta el Phone number ID.', $use);
        } elseif (!ctype_digit($id)) {
            $rows[] = self::row('bad', 'El Phone number ID debe tener solo números (no es el teléfono).', $use);
        } else {
            list($ok, $x, $err) = self::get(rawurlencode($id) . '?fields=id,display_phone_number,verified_name', $token);
            if (isset($targets[$id]) || isset($reach[$id]) || $id === $waba) {
                $rows[] = self::row('bad', $id . ' es el ID de la cuenta de WhatsApp, no el del número.', $use);
            } elseif (!$ok) {
                $rows[] = self::row('bad', 'El token no puede usar el Phone number ID ' . $id . '. Es la causa del error «Unsupported post request». Meta dice: ' . $err, $use);
            } elseif (empty($x['display_phone_number'])) {
                $rows[] = self::row('bad', 'El ID ' . $id . ' existe, pero no es un número de WhatsApp.', $use);
            } else {
                $rows[] = self::row('ok', 'El token puede usar el ID ' . $id . ': ' . $x['display_phone_number'] . ' («' . ($x['verified_name'] ?? '') . '»).');
                list($ok2, $y) = self::get(rawurlencode($id) . '?fields=status,platform_type', $token);
                if ($ok2 && !empty($y['platform_type']) && $y['platform_type'] !== 'CLOUD_API') {
                    $rows[] = self::row('bad', 'El número no está registrado en la API en la nube (' . $y['platform_type'] . ').', 'Regístralo en WhatsApp Manager → Números de teléfono.');
                }
                if ($ok2 && !empty($y['status'])) {
                    $rows[] = $y['status'] === 'CONNECTED' ? self::row('ok', 'El número está conectado.') : self::row('warn', 'Estado del número en Meta: ' . $y['status'] . '.', 'Míralo en WhatsApp Manager → Números de teléfono.');
                }
            }
            if ($seen && $seen['id'] !== $id) {
                $rows[] = self::row('bad', 'Los mensajes llegan a otro número: ' . $seen['phone'] . ', ID ' . $seen['id'] . '. Las respuestas no saldrían por el mismo.', 'Pulsa «Usar este ID» en ese número.');
            } elseif ($seen) {
                $rows[] = self::row('ok', 'Es el mismo número que recibe los mensajes.');
            }
        }

        // Mensajes entrantes: la app suscrita a la cuenta.
        if ($waba !== '' && isset($reach[$waba]) && $app !== '') {
            list($ok, $x) = self::get(rawurlencode($waba) . '/subscribed_apps', $token);
            if ($ok) {
                $on = false;
                foreach ((array) ($x['data'] ?? array()) as $s) {
                    $on = $on || (string) ($s['whatsapp_business_api_data']['id'] ?? '') === $app;
                }
                $rows[] = $on ? self::row('ok', 'La app está suscrita a los mensajes de esa cuenta.') : self::row('warn', 'La app del token no está suscrita a los mensajes de esa cuenta.', 'Puede que los reciba otra app: revisa el webhook en la app Chat Bezares (WhatsApp → Configuración).');
            }
        }
        self::secret($rows, $app);
        if (!$seen) {
            $rows[] = self::row('info', 'Todavía no se ha registrado ningún mensaje entrante: envía un WhatsApp a vuestro número para comprobar también la cuenta y el número que lo reciben.');
        }
        return $rows;
    }

    /* ------------------------------------------------ Facebook */

    private static function facebook($app)
    {
        $rows = array();
        $token = (string) Settings::get('fb_page_token');
        if ($token === '') {
            $rows[] = Settings::get('fb_enabled')
                ? self::row('bad', 'Falta el Page access token.', 'Genéralo en la app → Messenger → Configuración (paso 4 de la guía).')
                : self::row('info', 'Facebook Messenger no está configurado.');
            return $rows;
        }
        if (!Settings::get('fb_enabled')) {
            $rows[] = self::row('warn', 'Facebook Messenger está desactivado.', 'Marca «Facebook Messenger activado» y guarda.');
        }
        $d = self::token($rows, $token, 'PAGE', array(
            'pages_messaging' => array(true, 'leer y contestar mensajes'),
        ), 'Page access token');
        if ($d && empty($d['is_valid'])) {
            return $rows;
        }
        $fbApp = $d ? preg_replace('/\D/', '', (string) ($d['app_id'] ?? '')) : '';
        $scopes = $d && isset($d['scopes']) && is_array($d['scopes']) ? $d['scopes'] : array();

        // El nombre de la página pide pages_read_engagement, que el chat no usa: si falta, no se dice nada.
        list($ok, $x, $err, $code) = self::get('me?fields=id,name', $token);
        if ($ok) {
            $rows[] = self::row('ok', 'Página: «' . ($x['name'] ?? '') . '» (' . ($x['id'] ?? '') . ').');
        } elseif (!$d || $code === 190 || in_array('pages_read_engagement', $scopes, true)) {
            $rows[] = self::row('bad', 'El token no puede leer la página. Meta dice: ' . $err, 'Genera otro token de la página.');
        }

        // Prueba real con el mismo permiso que usan los mensajes (solo lee el saludo de Messenger).
        list($ok, , $err) = self::get('me/messenger_profile?fields=greeting', $token);
        $rows[] = $ok
            ? self::row('ok', 'El token puede usar Messenger en la página.')
            : self::row('bad', 'El token no puede usar Messenger en la página. Meta dice: ' . $err, 'En la app → Messenger → Configuración, conecta la página, genera otro token y pégalo en «Page access token».');

        // Mensajes entrantes: webhook de la app (con el App Secret) y suscripción de la página.
        $secret = (string) Settings::get('meta_app_secret');
        if ($fbApp !== '' && $secret !== '') {
            list($ok, $x) = self::get(rawurlencode($fbApp) . '/subscriptions', $fbApp . '|' . $secret);
            if ($ok) {
                $url = '';
                $msgs = false;
                foreach ((array) ($x['data'] ?? array()) as $s) {
                    if (($s['object'] ?? '') === 'page' && !empty($s['active'])) {
                        $url = (string) ($s['callback_url'] ?? '');
                        foreach ((array) ($s['fields'] ?? array()) as $f) {
                            $msgs = $msgs || (is_array($f) ? ($f['name'] ?? '') : $f) === 'messages';
                        }
                    }
                }
                $want = rtrim((string) cfg('base_url'), '/') . '/api/webhook/facebook';
                $fix = 'En la app → Messenger → Configuración → Webhooks: URL ' . $want . ' y el campo messages.';
                if ($url === '') {
                    $rows[] = self::row('bad', 'La app no tiene webhook de Messenger: no llegan los mensajes.', $fix);
                } elseif (rtrim($url, '/') !== $want) {
                    $rows[] = self::row('bad', 'El webhook de Messenger apunta a otra dirección: ' . mb_substr($url, 0, 150), $fix);
                } elseif (!$msgs) {
                    $rows[] = self::row('bad', 'El webhook de Messenger no tiene el campo «messages»: no llegan los mensajes.', $fix);
                } else {
                    $rows[] = self::row('ok', 'El webhook de Messenger apunta a este chat y tiene «messages».');
                }
            }
        }
        list($ok, $x) = self::get('me/subscribed_apps?fields=id,subscribed_fields', $token);
        if ($ok) {
            $on = false;
            foreach ((array) ($x['data'] ?? array()) as $s) {
                $on = $on || (($fbApp === '' || (string) ($s['id'] ?? '') === $fbApp) && in_array('messages', (array) ($s['subscribed_fields'] ?? array()), true));
            }
            $rows[] = $on ? self::row('ok', 'La página está suscrita a «messages».') : self::row('bad', 'La página no está suscrita a «messages»: no llegarán los mensajes.', 'En la app → Messenger → Configuración → Webhooks, suscribe la página al campo messages.');
        }

        // Lo que ha pasado de verdad: último mensaje recibido y último enviado.
        $last = (int) DB::val("SELECT MAX(last_customer_at) FROM conversations WHERE channel = 'facebook'");
        if ($last > 0) {
            $rows[] = self::row('ok', 'Último mensaje recibido de Facebook: ' . self::day($last) . '.');
        } else {
            $rows[] = self::row($ok ? 'info' : 'warn', 'Todavía no ha llegado ningún mensaje de Facebook' . ($ok ? '.' : ', y Meta no deja ver si la página está suscrita.'),
                'Escribe a la página desde un móvil. Si no llega al chat, suscribe la página a «messages» en la app → Messenger → Configuración → Webhooks.');
        }
        $m = DB::row("SELECT m.send_status, m.send_error, m.created_at FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.channel = 'facebook' AND m.send_status IN ('sent', 'error') ORDER BY m.id DESC LIMIT 1");
        if ($m) {
            $rows[] = $m['send_status'] === 'sent'
                ? self::row('ok', 'Último mensaje enviado a Facebook: ' . self::day($m['created_at']) . '.')
                : self::row('warn', 'El último mensaje a Facebook (' . self::day($m['created_at']) . ') no se entregó: ' . $m['send_error'], 'Si ya lo has arreglado, contesta a un chat de Facebook y vuelve a comprobar.');
        }
        if ($app !== '' && $fbApp !== '' && $app !== $fbApp) {
            $rows[] = self::row('bad', 'Los tokens de WhatsApp y Facebook son de apps distintas, y solo hay un App Secret.', 'Genera los dos con la misma app (Chat Bezares).');
        }
        self::secret($rows, $fbApp);
        return $rows;
    }
}
