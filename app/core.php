<?php
/** Conversaciones y mensajes, comunes a todos los canales. */
class Core
{
    const OOH_REPEAT_MS = 43200000; // mensaje fuera de horario como máximo cada 12 h

    public static function conv($id)
    {
        return DB::row('SELECT * FROM conversations WHERE id = ?', array((int) $id));
    }

    public static function find($channel, $ext)
    {
        return DB::row('SELECT * FROM conversations WHERE channel = ? AND external_id = ? ORDER BY id DESC LIMIT 1', array($channel, $ext));
    }

    public static function update($id, array $f)
    {
        $f['updated_at'] = now_ms();
        DB::update('conversations', $f, array('id' => (int) $id));
    }

    public static function message($id)
    {
        return DB::row('SELECT * FROM messages WHERE id = ?', array((int) $id));
    }

    private static function preview($body, $type, $name = '')
    {
        $icons = array('image' => '📷 Imagen', 'audio' => '🎤 Audio', 'video' => '🎬 Vídeo', 'file' => '📄 ' . ($name !== '' ? $name : 'Documento'));
        $p = trim(preg_replace('/\s+/u', ' ', (string) $body));
        if ($p === '' && $type) {
            $p = $icons[$type] ?? '📎';
        }
        return mb_substr($p, 0, 250);
    }

    /** $o: agent_id, translated, lang, media (array), ext_id, created_at */
    public static function insert_message($conv_id, $dir, $body, array $o = array())
    {
        $m = $o['media'] ?? null;
        $id = DB::insert('messages', array(
            'conversation_id' => (int) $conv_id,
            'direction'       => $dir,
            'agent_id'        => (int) ($o['agent_id'] ?? 0),
            'body'            => (string) $body,
            'body_translated' => $o['translated'] ?? null,
            'lang'            => (string) ($o['lang'] ?? ''),
            'media_url'       => $m ? $m['url'] : '',
            'media_type'      => $m ? $m['type'] : '',
            'media_name'      => $m ? $m['name'] : '',
            'ext_id'          => (string) ($o['ext_id'] ?? ''),
            'created_at'      => isset($o['created_at']) ? (int) $o['created_at'] : now_ms(),
        ));
        self::update($conv_id, $dir === 'system' ? array() : array('last_preview' => self::preview($body, $m ? $m['type'] : '', $m ? $m['name'] : '')));
        return $id;
    }

    private static function send_result($msg_id, $r)
    {
        DB::update('messages', array(
            'send_status' => $r['ok'] ? 'sent' : 'error',
            'send_error'  => $r['ok'] ? '' : (string) $r['error'],
            'ext_id'      => $r['ok'] ? (string) $r['id'] : '',
        ), array('id' => $msg_id));
    }

    /** Mensaje automático en el idioma del cliente. */
    public static function bot_say($conv_id, $key)
    {
        $c = self::conv($conv_id);
        $text = Settings::text($key, $c['lang'], $c);
        if ($text === '') {
            return;
        }
        $id = self::insert_message($c['id'], 'bot', $text, array('lang' => $c['lang']));
        self::send_result($id, Channels::send($c, $text));
    }

    public static function note($conv_id, $text, $agent_id = 0)
    {
        self::insert_message($conv_id, 'system', $text, array('agent_id' => $agent_id));
    }

    /* ---------------------------------------------------------- entrantes */

    /** Mensaje de un cliente por cualquier canal. $profile: name, phone, email, lang, page_url */
    public static function incoming($channel, $ext, array $profile, $text, $media = null, $ext_id = '')
    {
        $text = trim((string) $text);
        if ($text === '' && !$media) {
            return null;
        }
        if ($ext_id !== '' && DB::val('SELECT id FROM messages WHERE ext_id = ? LIMIT 1', array($ext_id))) {
            return null; // reintento de Meta
        }
        $now = now_ms();
        $c = self::find($channel, $ext);
        $new = false;

        if (!$c) {
            $new = true;
            $onb = 'done';
            if ($channel === 'web') {
                $onb = 'name';
            } elseif (Settings::get('onboarding_social')) {
                $onb = !empty($profile['name']) ? 'email' : 'name';
            }
            $id = DB::insert('conversations', array(
                'channel'     => $channel,
                'external_id' => $ext,
                'name'        => mb_substr((string) ($profile['name'] ?? ''), 0, 120),
                'phone'       => (string) ($profile['phone'] ?? ''),
                'email'       => (string) ($profile['email'] ?? ''),
                'lang'        => lang_norm($profile['lang'] ?? ''),
                'country'     => Geo::norm($profile['country'] ?? ''),
                'is_test'     => empty($profile['test']) ? 0 : 1, // chat de prueba del panel: el equipo no lo ve
                'page_url'    => mb_substr((string) ($profile['page_url'] ?? ''), 0, 500),
                'status'      => 'open',
                'onboarding'  => $onb,
                'created_at'  => $now,
                'updated_at'  => $now,
            ));
            $c = self::conv($id);
            // El primer mensaje del cliente es su consulta; el bot responde después pidiendo el nombre (ver bot_flow).
        } elseif ($c['status'] === 'closed' || $c['status'] === 'resolved') {
            // El cliente vuelve a escribir en un chat resuelto/cerrado: se reabre y vuelve a sonar.
            self::update($c['id'], array('status' => 'open', 'accepted_by' => 0, 'accepted_at' => 0));
            self::note($c['id'], 'Conversación reabierta por el cliente');
        }
        if ($channel === 'web' && !empty($profile['page_url'])) {
            self::update($c['id'], array('page_url' => mb_substr($profile['page_url'], 0, 500)));
        }
        // Completa el país si aún no se conocía (chats anteriores a esta función o datos que llegan después).
        if ($c['country'] === '' && !empty($profile['country'])) {
            self::update($c['id'], array('country' => Geo::norm($profile['country'])));
        }

        // Idioma + traducción: el primer mensaje siempre (es la consulta); luego, no en las respuestas de nombre/correo.
        $o = array('ext_id' => $ext_id, 'media' => $media);
        if ($text !== '' && ($new || !in_array($c['onboarding'], array('name', 'email'), true))) {
            $a = AI::analyze($text);
            $o['lang'] = $a['lang'];
            $o['translated'] = $a['translation'];
            if ($a['lang'] !== '' && $a['lang'] !== $c['lang']) {
                self::update($c['id'], array('lang' => $a['lang']));
            }
        }
        $msg = self::insert_message($c['id'], 'in', $text, $o);
        self::update($c['id'], array('last_customer_at' => now_ms()));

        self::bot_flow(self::conv($c['id']), $text, $new);
        return $msg;
    }

    /** Captura de nombre/correo + respuesta fuera de horario. */
    private static function bot_flow($c, $text, $new)
    {
        // Primera respuesta a un chat nuevo (web, WhatsApp o Facebook): pedir el nombre (o el correo si ya lo conocemos).
        if ($new && $c['onboarding'] !== 'done') {
            self::bot_say($c['id'], $c['onboarding'] === 'email' ? 'welcome_known' : 'welcome');
        } elseif ($c['onboarding'] === 'name') {
            $name = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
            $name = preg_replace('/^(me llamo|soy|mi nombre es|my name is|i am|i\'m)\s+/iu', '', $name);
            if ($name !== '') {
                self::update($c['id'], array('name' => mb_substr($name, 0, 120), 'onboarding' => 'email', 'onboarding_tries' => 0));
                self::bot_say($c['id'], 'ask_email');
            }
        } elseif ($c['onboarding'] === 'email') {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) && valid_email($m[0])) {
                self::update($c['id'], array('email' => strtolower($m[0]), 'onboarding' => 'done'));
                self::bot_say($c['id'], 'thanks');
            } elseif ((int) $c['onboarding_tries'] >= 1) {
                self::update($c['id'], array('onboarding' => 'done')); // no bloqueamos la conversación
                self::bot_say($c['id'], 'thanks');
            } else {
                self::update($c['id'], array('onboarding_tries' => (int) $c['onboarding_tries'] + 1));
                self::bot_say($c['id'], 'invalid_email');
            }
        }

        $now = now_ms();
        $c = self::conv($c['id']);
        if (Settings::get('ooh_enabled') && !Hours::is_open($now) && ($now - (int) $c['ooh_sent_at']) > self::OOH_REPEAT_MS) {
            self::update($c['id'], array('ooh_sent_at' => $now));
            self::bot_say($c['id'], 'ooh');
        }
    }

    /* ---------------------------------------------------------- agentes */

    public static function agent_send($c, $agent, $text, $media = null)
    {
        $text = trim((string) $text);
        if ($text === '' && !$media) {
            throw new ApiError('Mensaje vacío');
        }
        $now = now_ms();
        $tr = null;
        if ($text !== '' && $c['lang'] && !in_array($c['lang'], AI::NATIVE, true) && AI::enabled()) {
            $tr = AI::translate($text, $c['lang']);
        }
        $id = self::insert_message($c['id'], 'out', $text, array(
            'agent_id' => $agent['id'], 'translated' => $tr, 'lang' => $tr ? $c['lang'] : '', 'media' => $media,
        ));
        $f = array('last_agent_at' => $now, 'status' => 'open', 'onboarding' => 'done');
        if (!(int) $c['first_response_at']) {
            $f['first_response_at'] = $now;
            $f['first_response_by'] = $agent['id'];
        }
        if (!(int) $c['accepted_at']) {
            $f['accepted_at'] = $now;
            $f['accepted_by'] = $agent['id'];
        }
        if (!(int) $c['assigned_to']) {
            $f['assigned_to'] = $agent['id'];
        }
        self::update($c['id'], $f);
        self::send_result($id, Channels::send(self::conv($c['id']), $tr ?: $text, $media));
        return $id;
    }

    public static function accept($c, $agent)
    {
        $f = array('accepted_at' => now_ms(), 'accepted_by' => $agent['id']);
        if (!(int) $c['assigned_to']) {
            $f['assigned_to'] = $agent['id'];
        }
        self::update($c['id'], $f);
    }

    public static function transfer($c, $from, $to, $note = '')
    {
        self::update($c['id'], array('assigned_to' => $to['id'], 'accepted_at' => 0, 'accepted_by' => 0, 'status' => 'open'));
        $t = $from['name'] . ' transfirió la conversación a ' . $to['name'];
        if (trim($note) !== '') {
            $t .= ': ' . clean_text($note, 500);
        }
        self::note($c['id'], $t, $from['id']);
    }

    public static function set_status($c, $agent, $status)
    {
        self::update($c['id'], array('status' => $status));
        self::note($c['id'], $agent['name'] . ($status === 'closed' ? ' cerró la conversación' : ' reabrió la conversación'), $agent['id']);
    }

    /**
     * Marca (o desmarca) la conversación como resuelta. Al resolverla se deja de contar el tiempo de respuesta
     * (resolved_at, se guarda la primera vez), deja de sonar y no se envían recordatorios automáticos.
     */
    public static function resolve($c, $agent, $on)
    {
        $now = now_ms();
        if ($on) {
            $f = array('status' => 'resolved');
            if (!(int) $c['resolved_at']) {
                $f['resolved_at'] = $now;
            }
            if (!(int) $c['accepted_at']) {
                $f['accepted_at'] = $now;
                $f['accepted_by'] = $agent['id'];
            }
            self::update($c['id'], $f);
            self::note($c['id'], $agent['name'] . ' marcó la conversación como resuelta', $agent['id']);
        } else {
            self::update($c['id'], array('status' => 'open'));
            self::note($c['id'], $agent['name'] . ' reabrió la conversación', $agent['id']);
        }
    }

    /* ---------------------------------------------------------- salida JSON */

    public static function conv_out($c)
    {
        $o = array();
        foreach (array('id', 'assigned_to', 'accepted_at', 'created_at', 'updated_at', 'last_customer_at', 'first_response_at', 'resolved_at') as $k) {
            $o[$k] = (int) $c[$k];
        }
        foreach (array('channel', 'name', 'email', 'phone', 'lang', 'country', 'page_url', 'status', 'onboarding', 'last_preview') as $k) {
            $o[$k] = $c[$k];
        }
        $o['window_open'] = Channels::is_window_open($c);
        return $o;
    }

    public static function msg_out($m, $visitor = false)
    {
        $o = array(
            'id'         => (int) $m['id'],
            'direction'  => $m['direction'],
            'body'       => $m['body'],
            'media_url'  => Media::url($m['media_url']), // enlace firmado que caduca
            'media_type' => $m['media_type'],
            'media_name' => $m['media_name'],
            'created_at' => (int) $m['created_at'],
        );
        if ($visitor) {
            if ($m['direction'] === 'out') {
                if ($m['body_translated']) {
                    $o['body'] = $m['body_translated']; // el visitante ve la versión traducida
                }
                $o['agent_name'] = Auth::first_name($m['agent_id']);
            }
            return $o;
        }
        $o['conversation_id'] = (int) $m['conversation_id'];
        $o['agent_id'] = (int) $m['agent_id'];
        $o['translated'] = $m['body_translated'];
        $o['lang'] = $m['lang'];
        $o['send_status'] = $m['send_status'];
        $o['send_error'] = $m['send_error'];
        return $o;
    }
}
