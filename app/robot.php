<?php
/**
 * Pasos propios del robot, añadidos en el diagrama (panel → Robot).
 *
 * Se guardan en el ajuste «robot_steps»: {next, steps: [...]}. Cada paso va en un hueco del diagrama:
 *   before    entre el nombre y el correo (solo web)
 *   data      al recibir el correo, antes de «Gracias» / «Fuera de horario»
 *   end       después de ese mensaje
 *   no_agent  al cabo de un tiempo, si nadie contesta al cliente
 *   no_client al cabo de un tiempo, si el cliente no responde al agente
 * En los tres primeros puede ser un mensaje o una pregunta (espera la respuesta y la guarda); en los dos últimos, un mensaje.
 * Mientras espera la respuesta a la pregunta 7, conversations.onboarding vale «q7». Si contesta un agente, el robot se calla.
 */
class Robot
{
    const FLOW = array('before', 'data', 'end');
    const TIMED = array('no_agent', 'no_client');
    const MAX_STEPS = 40;
    const MAX_MINUTES = 1380; // 23 h: después WhatsApp y Facebook ya no dejan escribir

    /* ------------------------------------------------ configuración */

    public static function steps($slot = null)
    {
        $v = Settings::get('robot_steps');
        $all = is_array($v) && isset($v['steps']) && is_array($v['steps']) ? $v['steps'] : array();
        if ($slot === null) {
            return $all;
        }
        return array_values(array_filter($all, function ($s) use ($slot) { return $s['slot'] === $slot; }));
    }

    public static function find($id)
    {
        foreach (self::steps() as $s) {
            if ((int) $s['id'] === (int) $id) {
                return $s;
            }
        }
        return null;
    }

    private static function lines($text, $max)
    {
        $out = array();
        foreach (preg_split('/\R/u', (string) $text) as $l) {
            $l = clean_line($l, 80);
            if ($l !== '' && count($out) < $max) {
                $out[] = $l;
            }
        }
        return $out;
    }

    /** Valida lo que llega del panel (JSON). Devuelve {next, steps} o lanza ApiError. */
    public static function clean($json)
    {
        $in = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($in) || !isset($in['steps']) || !is_array($in['steps'])) {
            throw new ApiError('Los pasos del robot no se han podido leer. Recarga la página e inténtalo de nuevo.');
        }
        if (count($in['steps']) > self::MAX_STEPS) {
            throw new ApiError('Como máximo ' . self::MAX_STEPS . ' pasos propios');
        }
        $old = array();
        $next = 1;
        foreach (self::steps() as $s) {
            $old[(int) $s['id']] = $s;
            $next = max($next, (int) $s['id'] + 1);
        }
        $v = Settings::get('robot_steps');
        $next = max($next, is_array($v) ? (int) ($v['next'] ?? 1) : 1);
        $str = function ($x, $k) { return isset($x[$k]) && is_scalar($x[$k]) ? (string) $x[$k] : ''; };
        $text = function ($x, $k) use ($str) { return mb_substr(trim(strip_tags($str($x, $k))), 0, 1500); };
        $pick = function ($x, $k, array $list) use ($str) { $t = $str($x, $k); return in_array($t, $list, true) ? $t : $list[0]; };
        $steps = array();
        $used = array();
        foreach ($in['steps'] as $x) {
            if (!is_array($x)) {
                continue;
            }
            $id = (int) $str($x, 'id');
            if ($id < 1 || $id > 99999999 || isset($used[$id])) {
                $id = $next;
            }
            $next = max($next, $id + 1);
            $used[$id] = true;
            $slot = $pick($x, 'slot', array_merge(self::FLOW, self::TIMED));
            $timed = in_array($slot, self::TIMED, true);
            $type = $timed ? 'message' : $pick($x, 'type', array('message', 'question'));
            $kind = $type === 'question' ? $pick($x, 'kind', array('text', 'number', 'phone', 'options')) : 'text';
            $title = clean_line($str($x, 'title'), 60);
            $s = array(
                'id' => $id, 'slot' => $slot, 'type' => $type, 'title' => $title !== '' ? $title : ($type === 'question' ? 'Pregunta' : 'Mensaje'),
                'on' => empty($x['on']) ? 0 : 1,
                'channel' => $slot === 'before' ? 'web' : $pick($x, 'channel', array('all', 'web', 'whatsapp', 'facebook')),
                'when' => $pick($x, 'when', array('always', 'open', 'closed')),
                'es' => $text($x, 'es'), 'en' => $text($x, 'en'), 'kind' => $kind,
                'opts_es' => $kind === 'options' ? self::lines($str($x, 'opts_es'), 12) : array(),
                'opts_en' => $kind === 'options' ? self::lines($str($x, 'opts_en'), 12) : array(),
                'minutes' => $timed ? max(1, min(self::MAX_MINUTES, (int) $str($x, 'minutes'))) : 0,
            );
            if ($s['es'] === '') {
                $s['on'] = 0; // sin texto no se puede enviar
            }
            if ($kind === 'options' && count($s['opts_es']) < 2) {
                throw new ApiError('«' . $s['title'] . '»: escribe al menos dos opciones, una por línea');
            }
            // Desde cuándo está activo: un paso nuevo no se envía a los chats que ya llevaban tiempo esperando.
            $o = $old[$id] ?? null;
            $same = $o && $o['slot'] === $slot && (int) $o['on'] && (int) $o['minutes'] === $s['minutes'];
            $s['since'] = $s['on'] ? ($same ? (int) ($o['since'] ?? 0) : now_ms()) : 0;
            $steps[] = $s;
        }
        return array('next' => $next, 'steps' => $steps);
    }

    /* ------------------------------------------------ conversación */

    /** Estado del robot en ese chat: ['done' => huecos ya recorridos, 'a' => respuestas [{id, l, v}]]. */
    private static function state($c)
    {
        $d = json_decode((string) ($c['answers'] ?? ''), true);
        return array('done' => isset($d['done']) && is_array($d['done']) ? $d['done'] : array(), 'a' => isset($d['a']) && is_array($d['a']) ? $d['a'] : array());
    }

    /** Respuestas del cliente, para la ficha: [{label, value}]. */
    public static function answers($c)
    {
        return array_map(function ($x) { return array('label' => (string) $x['l'], 'value' => (string) $x['v']); }, self::state($c)['a']);
    }

    private static function applies($s, $c)
    {
        if (!(int) $s['on'] || $s['es'] === '' || ($s['channel'] !== 'all' && $s['channel'] !== $c['channel'])) {
            return false;
        }
        return $s['when'] === 'always' || ($s['when'] === 'open') === Hours::is_open();
    }

    private static function text($s, $c)
    {
        $list = function ($opts) {
            $o = '';
            foreach ($opts as $i => $l) {
                $o .= "\n" . ($i + 1) . '. ' . $l;
            }
            return $o;
        };
        $en = $s['en'];
        if ($s['kind'] === 'options' && $en !== '' && count($s['opts_en']) !== count($s['opts_es'])) {
            $en = ''; // faltan opciones en inglés: se traduce todo desde el español
        }
        return Settings::pick($s['es'] . $list($s['opts_es']), $en === '' ? '' : $en . $list($s['opts_en']), $c['lang'], $c);
    }

    /** Recorre un hueco del diagrama (a partir del paso $after) hasta una pregunta o hasta el final, y sigue con lo siguiente. */
    public static function run($id, $slot, $after = 0)
    {
        $c = Core::conv($id);
        if (!$c) {
            return;
        }
        $st = self::state($c);
        if (!in_array($slot, $st['done'], true)) {
            $answered = array_column($st['a'], 'id');
            $skip = $after > 0;
            foreach (self::steps($slot) as $s) {
                if ($skip) {
                    $skip = (int) $s['id'] !== (int) $after;
                    continue;
                }
                if (!self::applies($s, $c) || in_array((int) $s['id'], $answered, true)) {
                    continue;
                }
                if ($s['type'] === 'question') {
                    Core::update($id, array('onboarding' => 'q' . (int) $s['id'], 'onboarding_tries' => 0));
                    Core::bot_text($id, self::text($s, $c));
                    return; // espera la respuesta (ver answer)
                }
                Core::bot_text($id, self::text($s, $c));
            }
            $st['done'][] = $slot;
            Core::update($id, array('answers' => json_encode($st, JSON_UNESCAPED_UNICODE)));
        }
        if ($slot === 'before') {
            if ($c['email'] !== '') {
                self::run($id, 'data');
            } else {
                Core::update($id, array('onboarding' => 'email', 'onboarding_tries' => 0));
                Core::bot_say($id, 'ask_email');
            }
        } elseif ($slot === 'data') {
            Core::update($id, array('onboarding' => 'done', 'onboarding_tries' => 0));
            if (Settings::get('ooh_enabled') && !Hours::is_open()) {
                Core::update($id, array('ooh_sent_at' => now_ms()));
                Core::bot_say($id, 'ooh'); // fuera de horario no se promete que le atienden en seguida
            } else {
                Core::bot_say($id, 'thanks');
            }
            self::run($id, 'end');
        } else {
            Core::update($id, array('onboarding' => 'done', 'onboarding_tries' => 0));
        }
    }

    /** Respuesta del cliente a una pregunta del robot (onboarding = q + id). */
    public static function answer($c, $text)
    {
        $s = self::find((int) substr($c['onboarding'], 1));
        if (!$s || !(int) $s['on'] || $s['type'] !== 'question') {
            // La pregunta se ha borrado o desactivado mientras se esperaba: el robot termina sin repetir mensajes.
            Core::update($c['id'], array('onboarding' => 'done', 'onboarding_tries' => 0));
            return;
        }
        $value = self::valid($s, $text);
        if ($value === null) {
            if ((int) $c['onboarding_tries'] < 1) { // se repite la pregunta una vez; a la segunda se acepta lo que diga
                Core::update($c['id'], array('onboarding_tries' => 1));
                Core::bot_text($c['id'], self::text($s, $c));
                return;
            }
            $value = $text;
        }
        self::save_answer($c['id'], (int) $s['id'], $s['title'], $value);
        self::run($c['id'], $s['slot'], (int) $s['id']);
    }

    /** Guarda una respuesta en la ficha (sustituye la anterior a la misma pregunta) y la anota en el chat. */
    public static function save_answer($conv_id, $key, $label, $value)
    {
        $value = clean_line($value, 300);
        $value = $value !== '' ? $value : '(archivo)';
        $st = self::state(Core::conv($conv_id));
        $st['a'] = array_values(array_filter($st['a'], function ($x) use ($key) { return $x['id'] !== $key; }));
        $st['a'][] = array('id' => $key, 'l' => $label, 'v' => $value);
        Core::update($conv_id, array('answers' => json_encode($st, JSON_UNESCAPED_UNICODE)));
        Core::note($conv_id, $label . ': ' . $value);
    }

    /** Posición de la opción elegida (por número o por texto), o -1. */
    public static function option($s, $t)
    {
        if (preg_match('/^\D{0,3}(\d{1,2})\D{0,3}$/u', $t, $m) && isset($s['opts_es'][(int) $m[1] - 1])) {
            return (int) $m[1] - 1;
        }
        foreach (array($s['opts_es'], $s['opts_en']) as $list) {
            foreach ($list as $i => $o) {
                if (mb_strtolower($o) === mb_strtolower($t) && isset($s['opts_es'][$i])) {
                    return (int) $i;
                }
            }
        }
        return -1;
    }

    /** Valor aceptado, o null si la respuesta no vale para ese tipo de pregunta. */
    private static function valid($s, $text)
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text));
        switch ($s['kind']) {
            case 'number':
                return preg_match('/\d/', $t) ? $t : null;
            case 'phone':
                return strlen(preg_replace('/\D/', '', $t)) >= 6 ? $t : null;
            case 'options':
                $i = self::option($s, $t);
                return $i >= 0 ? $s['opts_es'][$i] : null;
            default:
                return $t; // texto libre (o un archivo)
        }
    }

    /* ------------------------------------------------ mensajes por tiempo (los llama Cron) */

    public static function cron($now)
    {
        foreach (array('no_agent' => array('last_customer_at', 'last_agent_at'), 'no_client' => array('last_agent_at', 'last_customer_at')) as $slot => $f) {
            foreach (self::steps($slot) as $s) {
                if (!(int) $s['on'] || $s['es'] === '') {
                    continue;
                }
                // Solo lo ocurrido desde que el paso está activo y dentro de las 24 h desde el último mensaje del cliente.
                $sql = "SELECT * FROM conversations WHERE is_test = 0 AND status = 'open' AND $f[0] > $f[1] AND $f[0] <= ? AND $f[0] > ? AND last_customer_at > ?"
                    . ($slot === 'no_agent' ? " AND onboarding = 'done'" : '') . ($s['channel'] !== 'all' ? ' AND channel = ?' : '') . ' LIMIT 50';
                $p = array($now - (int) $s['minutes'] * 60000, (int) $s['since'], $now - Channels::WINDOW_MS + 60000);
                if ($s['channel'] !== 'all') {
                    $p[] = $s['channel'];
                }
                foreach (DB::rows($sql, $p) as $c) {
                    if (!self::applies($s, $c)) {
                        continue;
                    }
                    // Una vez por cada mensaje que abrió la espera. La reserva es atómica: si coinciden dos ejecuciones, solo una envía.
                    $st = DB::exec('INSERT INTO robot_sent (conversation_id, step_id, ref) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ref = VALUES(ref)',
                        array((int) $c['id'], (int) $s['id'], (int) $c[$f[0]]));
                    if ($st->rowCount() > 0) {
                        Core::bot_text($c['id'], self::text($s, $c));
                    }
                }
            }
        }
    }
}
