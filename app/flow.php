<?php
/**
 * Robot con caminos dibujados (panel → Robot). Un grafo de cajas unidas por líneas:
 *
 *   start  llega un cliente                          salida 0
 *   say    envía un mensaje                          salida 0
 *   ask    pregunta y espera la respuesta            salida 0, o una por opción (0, 1, 2…); «no» = no responde bien (opcional)
 *   cond   bifurca según horario, canal o datos      salidas 0, 1 (y 2 en canal)
 *   ai     contesta la AI (assistant.php)            salidas 0 (más preguntas de las permitidas) y 1 (no contesta)
 *          Tras cada respuesta el chat queda resuelto; si el cliente vuelve a escribir se reabre y contesta otra vez, y si solo
 *          se despide, vuelve a quedar resuelto.
 *   end    pasa a una persona                        sin salidas
 *   timer  empieza solo, cuando pasa un tiempo       salida 0 (no le llegan líneas)
 *
 * Se guarda en el ajuste «robot_flow»: {ver, live, draft}. Mientras no se publica (live = null) funciona el robot anterior
 * (Core::bot_flow y Robot). Mientras espera la respuesta a la caja 7, conversations.onboarding vale «f7» y flow_ver dice con
 * qué versión empezó el chat: al publicar otra, los chats a medias terminan con la suya (se guarda 7 días).
 *
 * El chat de prueba de public/flow.js repite esta lógica: si cambia aquí, hay que cambiarla también allí.
 */
class Flow
{
    const AI_TURNS = 5;           // respuestas de la AI por chat, si no se cambia en la caja
    const MAX_NODES = 80;
    const MAX_STEPS = 60;         // cajas recorridas como mucho por cada mensaje del cliente
    const KINDS = array('text', 'name', 'email', 'number', 'phone', 'options');
    const CONDS = array('hours', 'channel', 'email', 'name');
    const SENT = 100000000;       // robot_sent: las cajas de tiempo se numeran aparte de los pasos propios del robot anterior

    /* ------------------------------------------------ guardado */

    private static function data()
    {
        $d = Settings::get('robot_flow');
        return (is_array($d) ? $d : array()) + array('ver' => 0, 'live' => null, 'draft' => null);
    }

    /** Grafo publicado, o null si sigue el robot anterior. */
    public static function live()
    {
        $l = self::data()['live'];
        return is_array($l) && !empty($l['nodes']) ? $l : null;
    }

    public static function draft()
    {
        $d = self::data();
        $g = is_array($d['draft']) && !empty($d['draft']['nodes']) ? $d['draft'] : self::live();
        if (!$g) {
            return self::from_settings();
        }
        return self::missing_window($g) ? self::add_window($g) : $g;
    }

    /** ¿Es un diagrama anterior a las cajas de tiempo, al que falta el aviso de WhatsApp? */
    private static function missing_window($g)
    {
        return empty(self::data()['timers']) && (int) Settings::get('window_notice_enabled') && !self::handles_window($g);
    }

    /** ¿Se encarga este diagrama del aviso antes del límite de 24 h de WhatsApp? (si no, sigue el de Tiempos) */
    public static function handles_window($g = null)
    {
        $g = $g ?: self::live();
        foreach ($g ? $g['nodes'] : array() as $x) {
            if ($x['type'] === 'timer' && (int) $x['stop'] && $x['trigger'] === 'no_agent' && in_array($x['channel'], array('whatsapp', 'all'), true)) {
                return true;
            }
        }
        return false;
    }

    /** Añade a la derecha las cajas del aviso de WhatsApp, con los textos configurados. */
    private static function add_window($g)
    {
        $id = 0;
        $x0 = 0;
        foreach ($g['nodes'] as $x) {
            $id = max($id, (int) $x['id']);
            $x0 = max($x0, (int) $x['x']);
        }
        $placed = $x0 > 0; // si todo está a 0, lo coloca flow.js
        $s = function ($k) { return (string) Settings::get($k); };
        $new = array(
            array('type' => 'timer', 'title' => 'Límite de WhatsApp', 'trigger' => 'no_agent', 'minutes' => max(1, min(23, (int) Settings::get('window_hours'))) * 60, 'channel' => 'whatsapp', 'when' => 'always', 'stop' => 1, 'busy' => 1),
            array('type' => 'cond', 'title' => '¿Tenemos su correo?', 'cond' => 'email'),
            array('type' => 'say', 'title' => 'Responderemos por correo', 'es' => $s('window_notice_es'), 'en' => $s('window_notice_en')),
            array('type' => 'ask', 'title' => 'Pide el correo (límite de WhatsApp)', 'kind' => 'email', 'es' => $s('window_noemail_es'), 'en' => $s('window_noemail_en'), 'tries' => 2,
                'retry_es' => $s('invalid_email_es'), 'retry_en' => $s('invalid_email_en'), 'need_es' => '', 'need_en' => '', 'opts_es' => array(), 'opts_en' => array()),
            array('type' => 'say', 'title' => 'Correo recibido', 'es' => '¡Gracias! Te responderemos por correo electrónico, en {email}.', 'en' => 'Thank you! We will answer you by email, at {email}.'),
            array('type' => 'end', 'title' => 'Se contesta por correo'),
        );
        $at = array(array(0, 0), array(0, 150), array(-115, 300), array(115, 300), array(115, 460), array(0, 610));
        foreach ($new as $i => $n) {
            $g['nodes'][] = array('id' => $id + $i + 1, 'x' => $placed ? $x0 + 440 + $at[$i][0] : 0, 'y' => $placed ? 24 + $at[$i][1] : 0) + $n;
        }
        foreach (array(array(1, 0, 2), array(2, 0, 3), array(2, 1, 4), array(3, 0, 6), array(4, 0, 5), array(5, 0, 6)) as $e) {
            $g['edges'][] = array('f' => $id + $e[0], 'p' => (string) $e[1], 't' => $id + $e[2]);
        }
        return $g;
    }

    /** Pone una caja «AI» justo antes de «Pasa a una persona» (sin tocar los caminos que solo empiezan por tiempo). */
    private static function add_ai($g)
    {
        list($n, $e) = self::index($g);
        $todo = array();
        $id = 0;
        foreach ($n as $k => $x) {
            $id = max($id, $k);
            if ($x['type'] === 'ai') {
                return $g; // ya la tiene
            }
            if ($x['type'] === 'start') {
                $todo[] = $k;
            }
        }
        $seen = array();
        while ($todo) {
            $k = array_pop($todo);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $todo = array_merge($todo, array_values($e[$k] ?? array()));
            }
        }
        $ends = array();
        foreach ($n as $k => $x) {
            if ($x['type'] === 'end' && isset($seen[$k])) {
                $ends[$k] = mb_strtolower(trim($x['title'])) === 'pasa a una persona';
            }
        }
        $ends = array_filter($ends) ?: $ends; // si ninguna se llama así, todos los finales
        foreach (array_keys($ends) as $end) {
            if (count($g['nodes']) >= self::MAX_NODES) {
                break;
            }
            $ai = ++$id;
            foreach ($g['edges'] as $i => $l) {
                if ((int) $l['t'] === $end && isset($seen[(int) $l['f']])) {
                    $g['edges'][$i]['t'] = $ai;
                }
            }
            $x0 = (int) $n[$end]['x'];
            $y0 = (int) $n[$end]['y'];
            $placed = $x0 > 0 || $y0 > 0; // si todo está a 0, lo coloca flow.js
            foreach ($placed ? $g['nodes'] : array() as $i => $x) { // sitio: baja el final y lo que tenga debajo
                if ((int) $x['y'] >= $y0 && abs((int) $x['x'] - $x0) < 210) {
                    $g['nodes'][$i]['y'] = (int) $x['y'] + 150;
                }
            }
            $g['nodes'][] = array('id' => $ai, 'type' => 'ai', 'title' => 'AI', 'x' => $x0, 'y' => $y0, 'turns' => self::AI_TURNS);
            $g['edges'][] = array('f' => $ai, 'p' => '0', 't' => $end);
            $g['edges'][] = array('f' => $ai, 'p' => '1', 't' => $end);
        }
        return $g;
    }

    /**
     * Antes de la AI, pregunta «¿Con qué podemos ayudarle?» y espera la respuesta. Lo que había entre el correo y la AI (horario,
     * «Te pasamos con una persona», «Fuera de horario») pasa a decirse solo si la AI no lo resuelve:
     *   correo → [horario, avisos] → AI → final      se convierte en      correo → pregunta → AI → [horario, avisos] → final
     * Si el diagrama no tiene esa forma, no se toca.
     */
    private static function add_question($g)
    {
        list($n, $e) = self::index($g);
        $ai = 0;
        $start = 0;
        foreach ($n as $k => $x) {
            if ($x['type'] === 'ai') {
                if ($ai) {
                    return $g; // varias cajas AI: lo ha cambiado el equipo
                }
                $ai = $k;
            }
            $start = $x['type'] === 'start' ? $k : $start;
        }
        $end = $ai ? (int) ($e[$ai]['0'] ?? 0) : 0;
        if (!$start || !$end || ($e[$ai]['1'] ?? 0) !== $end || $n[$end]['type'] !== 'end') {
            return $g;
        }
        // Preguntas del correo que llevan (sin otra pregunta en medio) a la AI, y la primera caja que va detrás de ellas.
        $seen = array();
        $todo = array($start);
        $first = 0;
        $mails = array();
        while ($todo) {
            $k = array_pop($todo);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $todo = array_merge($todo, array_values($e[$k] ?? array()));
            if ($n[$k]['type'] === 'ask' && $n[$k]['kind'] === 'email') {
                $t = (int) ($e[$k]['0'] ?? 0);
                if (!$t || ($first && $t !== $first)) {
                    return $g;
                }
                $first = $t;
                $mails[$k] = true;
            }
        }
        if (!$first || $first === $ai) {
            return $g;
        }
        // Lo que hay en medio: solo mensajes y bifurcaciones, y todo acaba en la AI.
        $tail = array();
        $todo = array($first);
        while ($todo) {
            $k = array_pop($todo);
            if ($k === $ai || isset($tail[$k])) {
                continue;
            }
            if ($n[$k]['type'] !== 'say' && $n[$k]['type'] !== 'cond') {
                return $g;
            }
            $tail[$k] = true;
            for ($p = 0; $p < self::ports($n[$k]); $p++) {
                if (!isset($e[$k][(string) $p])) {
                    return $g;
                }
                $todo[] = $e[$k][(string) $p];
            }
        }
        foreach ($g['edges'] as $l) { // al medio solo se llega desde el correo
            $in = (int) $l['t'];
            $from = (int) $l['f'];
            if ((isset($tail[$in]) && !isset($tail[$from]) && !isset($mails[$from])) || ($in === $ai && !isset($tail[$from]))) {
                return $g;
            }
        }
        if (count($g['nodes']) >= self::MAX_NODES) {
            return $g;
        }
        $q = max(array_keys($n)) + 1;
        foreach ($g['edges'] as $i => $l) {
            $f = (int) $l['f'];
            if (isset($mails[$f]) && (int) $l['t'] === $first) {
                $g['edges'][$i]['t'] = $q;
            } elseif (isset($tail[$f]) && (int) $l['t'] === $ai) {
                $g['edges'][$i]['t'] = $end;
            } elseif ($f === $ai) {
                $g['edges'][$i]['t'] = $first;
            }
        }
        $g['edges'][] = array('f' => $q, 'p' => '0', 't' => $ai);
        $x0 = (int) $n[$first]['x'];
        $y0 = (int) $n[$first]['y'];
        if ($x0 > 0 || $y0 > 0) { // si todo está a 0, lo coloca flow.js
            foreach ($g['nodes'] as $i => $x) {
                $k = (int) $x['id'];
                if (isset($tail[$k])) {
                    $g['nodes'][$i]['y'] = (int) $x['y'] + 290;
                } elseif ($k === $ai) {
                    $g['nodes'][$i]['x'] = $x0;
                    $g['nodes'][$i]['y'] = $y0 + 130;
                } elseif ($k === $end) {
                    $g['nodes'][$i]['y'] = (int) $x['y'] + 140;
                }
            }
        }
        $g['nodes'][] = array('id' => $q, 'type' => 'ask', 'title' => 'Consulta', 'x' => $x0, 'y' => $y0, 'es' => '¿Con qué podemos ayudarle?', 'en' => 'How can we help you?',
            'kind' => 'text', 'tries' => 1, 'retry_es' => '', 'retry_en' => '', 'need_es' => '', 'need_en' => '', 'opts_es' => array(), 'opts_en' => array());
        return $g;
    }

    /** Al actualizar, una sola vez cada cambio: caja «AI», sus respuestas y la pregunta anterior, en lo publicado y en el borrador. */
    public static function upgrade_ai()
    {
        $d = self::data();
        if (!empty($d['ai']) && !empty($d['ai2']) && !empty($d['ai3'])) {
            return;
        }
        $old = $d['live'];
        foreach (array('live', 'draft') as $k) {
            if (!is_array($d[$k]) || empty($d[$k]['nodes'])) {
                continue;
            }
            $g = empty($d['ai']) ? self::add_ai($d[$k]) : $d[$k];
            if (empty($d['ai2'])) { // las cajas que se pusieron con una sola respuesta
                foreach ($g['nodes'] as $i => $x) {
                    if ($x['type'] === 'ai' && (int) ($x['turns'] ?? 1) === 1) {
                        $g['nodes'][$i]['turns'] = self::AI_TURNS;
                    }
                }
            }
            if (empty($d['ai3'])) {
                $new = self::add_question($g);
                if ($new === $g) {
                    log_error('Robot: la pregunta antes de la AI no se ha añadido (' . $k . '): el diagrama tiene otra forma');
                }
                $g = $new;
            }
            $d[$k] = $g;
        }
        if ($old && $d['live'] !== $old) {
            cache_set('flow:' . (int) $d['ver'], $old, 7 * 86400); // los chats a medias terminan con el de antes
            $d['ver'] = (int) $d['ver'] + 1;
        }
        Settings::put('robot_flow', array('ai' => 1, 'ai2' => 1, 'ai3' => 1) + $d);
    }

    /** ¿Está la caja «AI» en el diagrama publicado? */
    public static function has_ai()
    {
        foreach (self::live()['nodes'] ?? array() as $x) {
            if ($x['type'] === 'ai') {
                return true;
            }
        }
        return false;
    }

    /** ¿Hay cambios en el borrador sin publicar? */
    public static function pending()
    {
        $d = self::data();
        return (is_array($d['draft']) && json_encode($d['draft']) !== json_encode($d['live'])) || ($d['live'] && self::missing_window($d['draft'] ?: $d['live']));
    }

    public static function save_draft($graph)
    {
        $d = self::data();
        $d['draft'] = $graph;
        $d['timers'] = 1; // a partir de aquí, si falta el aviso de WhatsApp es porque se ha quitado a propósito
        Settings::put('robot_flow', $d);
    }

    public static function publish($graph, $me)
    {
        $bad = self::problems($graph);
        if ($bad) {
            self::save_draft($graph);
            throw new ApiError('No se ha publicado (el borrador sí se ha guardado): ' . implode(' · ', array_slice($bad, 0, 4)));
        }
        $d = self::data();
        if ($d['live']) {
            cache_set('flow:' . (int) $d['ver'], $d['live'], 7 * 86400); // para los chats a medias
        }
        // Desde cuándo está activa cada caja de tiempo: una nueva no se aplica a los chats que ya llevaban tiempo esperando.
        $old = array();
        foreach ($d['live'] ? $d['live']['nodes'] : array() as $x) {
            if ($x['type'] === 'timer') {
                $old[(int) $x['id']] = $x;
            }
        }
        $since = array();
        foreach ($graph['nodes'] as $x) {
            if ($x['type'] === 'timer') {
                $o = $old[(int) $x['id']] ?? null;
                $same = $o && $o['trigger'] === $x['trigger'] && (int) $o['minutes'] === (int) $x['minutes'] && $o['channel'] === $x['channel'];
                $since[(int) $x['id']] = $same ? (int) ($d['since'][(int) $x['id']] ?? 0) : now_ms();
            }
        }
        Settings::put('robot_flow', array('ver' => (int) $d['ver'] + 1, 'live' => $graph, 'draft' => $graph, 'timers' => 1, 'ai' => 1, 'ai2' => 1, 'ai3' => 1, 'since' => $since));
        log_error('Robot: diagrama publicado por ' . $me['name']);
    }

    /** Vuelve al robot anterior; el diagrama queda como borrador. */
    public static function off($me)
    {
        $d = self::data();
        if ($d['live']) {
            cache_set('flow:' . (int) $d['ver'], $d['live'], 7 * 86400);
            $d['draft'] = $d['draft'] ?: $d['live'];
            $d['live'] = null;
            Settings::put('robot_flow', $d);
            log_error('Robot: vuelta al robot anterior por ' . $me['name']);
        }
    }

    /** Descarta el borrador: vuelve a lo publicado o, si no hay nada publicado, al diagrama equivalente al robot anterior. */
    public static function discard()
    {
        $d = self::data();
        $d['draft'] = null;
        if ($d['live']) {
            $d['timers'] = 1;
        }
        Settings::put('robot_flow', $d);
    }

    /* ------------------------------------------------ validación */

    /** Número de salidas normales de una caja. */
    public static function ports($n)
    {
        switch ($n['type']) {
            case 'end':
                return 0;
            case 'ai':
                return 2;
            case 'cond':
                return $n['cond'] === 'channel' ? 3 : 2;
            case 'ask':
                return $n['kind'] === 'options' ? count($n['opts_es']) : 1;
            default:
                return 1;
        }
    }

    /** Limpia lo que llega del panel (JSON). */
    public static function clean($json)
    {
        $in = json_decode((string) $json, true);
        if (!is_array($in) || empty($in['nodes']) || !is_array($in['nodes']) || !isset($in['edges']) || !is_array($in['edges'])) {
            throw new ApiError('El diagrama no se ha podido leer. Recarga la página e inténtalo de nuevo.');
        }
        if (count($in['nodes']) > self::MAX_NODES) {
            throw new ApiError('Como máximo ' . self::MAX_NODES . ' cajas');
        }
        $str = function ($x, $k) { return isset($x[$k]) && is_scalar($x[$k]) ? (string) $x[$k] : ''; };
        $text = function ($x, $k) use ($str) { return mb_substr(trim(strip_tags($str($x, $k))), 0, 1500); };
        $pick = function ($x, $k, array $list) use ($str) { $t = $str($x, $k); return in_array($t, $list, true) ? $t : $list[0]; };
        $lines = function ($x, $k) {
            $o = array();
            foreach ((array) ($x[$k] ?? array()) as $l) {
                $l = is_scalar($l) ? clean_line($l, 80) : '';
                if ($l !== '' && count($o) < 12) {
                    $o[] = $l;
                }
            }
            return $o;
        };
        $nodes = array();
        $start = 0;
        foreach ($in['nodes'] as $x) {
            $id = is_array($x) ? (int) $str($x, 'id') : 0;
            if ($id < 1 || $id > 99999999 || isset($nodes[$id])) {
                continue;
            }
            $type = $pick($x, 'type', array('say', 'ask', 'cond', 'end', 'start', 'timer', 'ai'));
            if ($type === 'start' && $start++) {
                continue; // solo un inicio
            }
            $n = array('id' => $id, 'type' => $type, 'title' => clean_line($str($x, 'title'), 60), 'x' => max(0, min(20000, (int) $str($x, 'x'))), 'y' => max(0, min(20000, (int) $str($x, 'y'))));
            if ($type === 'say' || $type === 'ask') {
                $n += array('es' => $text($x, 'es'), 'en' => $text($x, 'en'));
            }
            if ($type === 'ask') {
                $kind = $pick($x, 'kind', self::KINDS);
                $n += array('kind' => $kind, 'tries' => max(1, min(20, (int) $str($x, 'tries'))),
                    'retry_es' => $text($x, 'retry_es'), 'retry_en' => $text($x, 'retry_en'),
                    'need_es' => $kind === 'email' ? $text($x, 'need_es') : '', 'need_en' => $kind === 'email' ? $text($x, 'need_en') : '',
                    'opts_es' => $kind === 'options' ? $lines($x, 'opts_es') : array(), 'opts_en' => $kind === 'options' ? $lines($x, 'opts_en') : array());
            }
            if ($type === 'cond') {
                $n['cond'] = $pick($x, 'cond', self::CONDS);
            }
            if ($type === 'ai') {
                $n['turns'] = max(1, min(10, (int) $str($x, 'turns')));
            }
            if ($type === 'timer') {
                $n += array('trigger' => $pick($x, 'trigger', Robot::TIMED), 'minutes' => max(1, min(Robot::MAX_MINUTES, (int) $str($x, 'minutes'))),
                    'channel' => $pick($x, 'channel', array('all', 'web', 'whatsapp', 'facebook')), 'when' => $pick($x, 'when', array('always', 'open', 'closed')),
                    'stop' => empty($x['stop']) ? 0 : 1, 'busy' => empty($x['busy']) ? 0 : 1);
            }
            if ($n['title'] === '') {
                $t = array('start' => 'Llega un cliente', 'say' => 'Mensaje', 'ask' => 'Pregunta', 'cond' => 'Bifurcación', 'end' => 'Pasa a una persona', 'timer' => 'Pasa un tiempo', 'ai' => 'AI');
                $n['title'] = $t[$type];
            }
            $nodes[$id] = $n;
        }
        $edges = array();
        foreach ($in['edges'] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $f = (int) $str($e, 'f');
            $t = (int) $str($e, 't');
            $p = $str($e, 'p');
            if (!isset($nodes[$f], $nodes[$t]) || $nodes[$t]['type'] === 'start' || $nodes[$t]['type'] === 'timer') {
                continue;
            }
            $ok = $p === 'no' ? $nodes[$f]['type'] === 'ask' && $nodes[$f]['kind'] !== 'text' : ctype_digit($p) && (int) $p < self::ports($nodes[$f]);
            if ($ok) {
                $edges[$f . ':' . $p] = array('f' => $f, 'p' => $p, 't' => $t); // una línea por salida
            }
        }
        return array('nodes' => array_values($nodes), 'edges' => array_values($edges));
    }

    private static function index($g)
    {
        $n = array();
        foreach ($g['nodes'] as $x) {
            $n[(int) $x['id']] = $x;
        }
        $e = array();
        foreach ($g['edges'] as $x) {
            $e[(int) $x['f']][(string) $x['p']] = (int) $x['t'];
        }
        return array($n, $e);
    }

    /** Lo que impide publicar (lista de textos; vacía = correcto). */
    public static function problems($g)
    {
        list($n, $e) = self::index($g);
        $bad = array();
        $start = 0;
        $todo = array();
        foreach ($n as $id => $x) {
            if ($x['type'] === 'start') {
                $start = $id;
            }
            if ($x['type'] === 'start' || $x['type'] === 'timer') {
                $todo[] = $id;
            }
        }
        if (!$start) {
            return array('falta la caja de inicio');
        }
        // Cajas a las que se llega.
        $seen = array();
        while ($todo) {
            $id = array_pop($todo);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($e[$id] ?? array() as $t) {
                $todo[] = $t;
            }
        }
        $end = false;
        foreach ($seen as $id => $_) {
            $x = $n[$id];
            $end = $end || $x['type'] === 'end';
            if (($x['type'] === 'say' || $x['type'] === 'ask') && $x['es'] === '') {
                $bad[] = '«' . $x['title'] . '» no tiene texto';
            }
            if ($x['type'] === 'ask' && $x['kind'] === 'options' && count($x['opts_es']) < 2) {
                $bad[] = '«' . $x['title'] . '» necesita al menos dos opciones';
            }
            for ($p = 0; $p < self::ports($x); $p++) {
                if (!isset($e[$id][(string) $p])) {
                    $bad[] = '«' . $x['title'] . '» tiene una salida sin línea';
                    break;
                }
            }
        }
        if (!$end) {
            $bad[] = 'ningún camino llega a «Pasa a una persona»';
        }
        // Bucle sin ninguna pregunta: el robot hablaría sin parar.
        $color = array();
        $visit = function ($id) use (&$visit, &$color, $n, $e) {
            $color[$id] = 1;
            foreach ($e[$id] ?? array() as $t) {
                if ($n[$t]['type'] === 'ask') {
                    continue; // aquí se espera al cliente
                }
                $c = $color[$t] ?? 0;
                if ($c === 1 || ($c === 0 && $visit($t))) {
                    return true;
                }
            }
            $color[$id] = 2;
            return false;
        };
        foreach ($seen as $id => $_) {
            if (empty($color[$id]) && $visit($id)) {
                $bad[] = 'hay un bucle sin ninguna pregunta: el robot no pararía de hablar';
                break;
            }
        }
        return $bad;
    }

    /* ------------------------------------------------ diagrama equivalente al robot anterior */

    /** Construye el diagrama con los textos y pasos que hay en Configuración del robot anterior. */
    public static function from_settings()
    {
        $nodes = array();
        $edges = array();
        $id = 0;
        $s = function ($k) { return (string) Settings::get($k); };
        $add = function ($type, $title, array $x = array()) use (&$nodes, &$id) {
            $nodes[] = array('id' => ++$id, 'type' => $type, 'title' => $title, 'x' => 0, 'y' => 0) + $x;
            return $id;
        };
        $link = function ($f, $p, $t) use (&$edges) { $edges[] = array('f' => $f, 'p' => (string) $p, 't' => $t); };
        $ask = function ($kind, $es, $en, array $x = array()) {
            return $x + array('kind' => $kind, 'es' => $es, 'en' => $en, 'tries' => 1, 'retry_es' => '', 'retry_en' => '', 'need_es' => '', 'need_en' => '', 'opts_es' => array(), 'opts_en' => array());
        };
        $mail = array('tries' => max(1, (int) Settings::get('email_asks')), 'retry_es' => $s('invalid_email_es'), 'retry_en' => $s('invalid_email_en'), 'need_es' => $s('need_email_es'), 'need_en' => $s('need_email_en'));

        // Encadena los pasos propios de un hueco. $from = salidas pendientes [[caja, salida], …]; devuelve las nuevas.
        $chain = function ($slot, $from) use ($add, $link, $ask) {
            foreach (Robot::steps($slot) as $x) {
                if (!(int) $x['on'] || $x['es'] === '') {
                    continue;
                }
                $skip = array(); // salidas que se saltan este paso
                if ($x['channel'] !== 'all' && $slot !== 'before') {
                    $c = $add('cond', '¿Qué canal?', array('cond' => 'channel'));
                    foreach ($from as $o) {
                        $link($o[0], $o[1], $c);
                    }
                    $from = array();
                    foreach (array('web', 'whatsapp', 'facebook') as $i => $ch) {
                        if ($ch === $x['channel']) {
                            $from[] = array($c, $i);
                        } else {
                            $skip[] = array($c, $i);
                        }
                    }
                }
                if ($x['when'] !== 'always') {
                    $c = $add('cond', '¿En horario?', array('cond' => 'hours'));
                    foreach ($from as $o) {
                        $link($o[0], $o[1], $c);
                    }
                    $from = array(array($c, $x['when'] === 'open' ? 0 : 1));
                    $skip[] = array($c, $x['when'] === 'open' ? 1 : 0);
                }
                $q = $x['type'] === 'question';
                $n = $q ? $add('ask', $x['title'], $ask($x['kind'], $x['es'], $x['en'], array('opts_es' => $x['opts_es'], 'opts_en' => $x['opts_en'])))
                    : $add('say', $x['title'], array('es' => $x['es'], 'en' => $x['en']));
                foreach ($from as $o) {
                    $link($o[0], $o[1], $n);
                }
                $from = array();
                for ($i = 0; $i < ($q && $x['kind'] === 'options' ? count($x['opts_es']) : 1); $i++) {
                    $from[] = array($n, $i);
                }
                $from = array_merge($from, $skip);
            }
            return $from;
        };
        $join = function ($from, $to) use ($link) {
            foreach ($from as $o) {
                $link($o[0], $o[1], $to);
            }
        };

        $start = $add('start', 'Llega un cliente');
        $name = $add('ask', 'Bienvenida: nombre', $ask('name', $s('welcome_es'), $s('welcome_en')));
        $link($start, 0, $name);
        $chan = $add('cond', '¿Qué canal?', array('cond' => 'channel'));
        $link($name, 0, $chan);
        $web = $chain('before', array(array($chan, 0)));
        $m1 = $add('ask', 'Pide el correo', $ask('email', $s('ask_email_es'), $s('ask_email_en'), $mail));
        $join($web, $m1);
        $m2 = $add('ask', 'Pide el correo (WhatsApp y Facebook)', $ask('email', $s('welcome_known_es'), $s('welcome_known_en'), $mail));
        $link($chan, 1, $m2);
        $link($chan, 2, $m2);
        $from = $chain('data', array(array($m1, 0), array($m2, 0)));
        $thanks = $add('say', 'Gracias', array('es' => $s('thanks_es'), 'en' => $s('thanks_en')));
        if ((int) Settings::get('ooh_enabled')) {
            $h = $add('cond', '¿En horario?', array('cond' => 'hours'));
            $join($from, $h);
            $ooh = $add('say', 'Fuera de horario', array('es' => $s('ooh_es'), 'en' => $s('ooh_en')));
            $link($h, 0, $thanks);
            $link($h, 1, $ooh);
            $from = array(array($thanks, 0), array($ooh, 0));
        } else {
            $join($from, $thanks);
            $from = array(array($thanks, 0));
        }
        $from = $chain('end', $from);
        $join($from, $add('end', 'Pasa a una persona'));
        $g = self::add_question(self::add_ai(array('nodes' => $nodes, 'edges' => $edges)));
        return (int) Settings::get('window_notice_enabled') ? self::add_window($g) : $g;
    }

    /* ------------------------------------------------ conversación */

    /** Grafo con el que empezó ese chat. */
    private static function graph_of($c)
    {
        $d = self::data();
        if ((int) $c['flow_ver'] === (int) $d['ver'] && $d['live']) {
            return $d['live'];
        }
        $old = cache_get('flow:' . (int) $c['flow_ver']);
        return is_array($old) ? $old : self::live();
    }

    private static function finish($id)
    {
        // El aviso de fuera de horario ya lo ha dado el diagrama: no se repite hasta pasadas 12 horas.
        Core::update($id, array('onboarding' => 'done', 'onboarding_tries' => 0) + (Hours::is_open() ? array() : array('ooh_sent_at' => now_ms())));
    }

    /** Primer mensaje de un chat nuevo. */
    public static function start($c, $text)
    {
        $g = self::live();
        $f = array('flow_ver' => (int) self::data()['ver']);
        $email = $c['email'] === '' ? Core::find_email($text) : '';
        if ($email !== '') {
            $f['email'] = $email;
        }
        Core::update($c['id'], $f);
        list($n, $e) = self::index($g);
        foreach ($n as $id => $x) {
            if ($x['type'] === 'start') {
                self::go($c['id'], $g, $e[$id]['0'] ?? 0);
                return;
            }
        }
        self::finish($c['id']);
    }

    /** Espera el correo del cliente en la primera caja que lo pide (true si la hay). $say: envía antes el texto de insistir. */
    public static function wait_email($id, $say)
    {
        foreach (self::live()['nodes'] as $x) {
            if ($x['type'] === 'ask' && $x['kind'] === 'email') {
                Core::update($id, array('onboarding' => 'f' . (int) $x['id'], 'onboarding_tries' => 1, 'flow_ver' => (int) self::data()['ver']));
                if ($say) {
                    $c = Core::conv($id);
                    Core::bot_text($id, Settings::pick($x['need_es'] !== '' ? $x['need_es'] : $x['es'], $x['need_es'] !== '' ? $x['need_en'] : $x['en'], $c['lang'], $c));
                }
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------ cajas de tiempo (las llama Cron) */

    public static function cron($now)
    {
        $d = self::data();
        $g = self::live();
        if (!$g) {
            return;
        }
        list(, $e) = self::index($g);
        foreach ($g['nodes'] as $x) {
            $next = $x['type'] === 'timer' ? ($e[(int) $x['id']]['0'] ?? 0) : 0;
            if (!$next || ($x['when'] !== 'always' && ($x['when'] === 'open') !== Hours::is_open($now))) {
                continue;
            }
            $f = $x['trigger'] === 'no_agent' ? array('last_customer_at', 'last_agent_at') : array('last_agent_at', 'last_customer_at');
            // Solo dentro de las 24 h desde el último mensaje del cliente. Las que paran el tiempo valen también para lo anterior
            // a publicarlas (window_try_at evita repetir el aviso que ya hubiera enviado el robot anterior).
            $sql = "SELECT * FROM conversations WHERE is_test = 0 AND status = 'open' AND $f[0] > $f[1] AND $f[0] <= ? AND $f[0] > ? AND last_customer_at > ?"
                . ((int) $x['busy'] ? '' : " AND onboarding = 'done'") . ((int) $x['stop'] ? ' AND window_try_at < last_customer_at' : '') . ($x['channel'] !== 'all' ? ' AND channel = ?' : '') . ' LIMIT 50';
            $p = array($now - (int) $x['minutes'] * 60000, (int) $x['stop'] ? 0 : (int) ($d['since'][(int) $x['id']] ?? 0), $now - Channels::WINDOW_MS + 60000);
            if ($x['channel'] !== 'all') {
                $p[] = $x['channel'];
            }
            foreach (DB::rows($sql, $p) as $c) {
                // Una vez por cada espera. La reserva es atómica: si coinciden dos ejecuciones, solo una la recorre.
                $st = DB::exec('INSERT INTO robot_sent (conversation_id, step_id, ref) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ref = VALUES(ref)',
                    array((int) $c['id'], self::SENT + (int) $x['id'], (int) $c[$f[0]]));
                if ($st->rowCount() < 1) {
                    continue;
                }
                try {
                    self::timer($c, $g, $x, $next, (int) $d['ver'], $now);
                } catch (Throwable $ex) {
                    log_error('Robot (tiempo): ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
                }
            }
        }
    }

    private static function timer($c, $g, $x, $next, $ver, $now)
    {
        $id = (int) $c['id'];
        Core::update($id, array('flow_ver' => $ver) + ((int) $x['stop'] ? array('window_try_at' => $now) : array()));
        $ok = self::go($id, $g, $next);
        $after = Core::conv($id);
        if ($after['onboarding'] === 'done') { // el camino ha terminado: si el robot esperaba una respuesta, la sigue esperando
            Core::update($id, array('onboarding' => $c['onboarding'], 'onboarding_tries' => (int) $c['onboarding_tries'], 'flow_ver' => (int) $c['flow_ver'], 'ooh_sent_at' => (int) $c['ooh_sent_at']));
        }
        if ((int) $x['stop'] && $ok) {
            Core::update($id, array('window_notice_at' => $now) + ((int) $c['window_first_at'] ? array() : array('window_first_at' => $now)));
            Core::note($id, '«' . $x['title'] . '»: se deja de contar el tiempo de respuesta.');
        }
    }

    /** Recorre el diagrama desde una caja hasta una pregunta o hasta el final. Devuelve si se ha entregado todo lo enviado. */
    private static function go($id, $g, $node)
    {
        list($n, $e) = self::index($g);
        $sent = true;
        for ($i = 0; $i < self::MAX_STEPS && isset($n[$node]); $i++) {
            $x = $n[$node];
            $c = Core::conv($id);
            $port = '0';
            switch ($x['type']) {
                case 'say':
                    $sent = Core::bot_text($id, Settings::pick($x['es'], $x['en'], $c['lang'], $c)) && $sent;
                    break;
                case 'ask':
                    if (($x['kind'] === 'email' && $c['email'] !== '') || ($x['kind'] === 'name' && $c['name'] !== '')) {
                        break; // ya lo sabemos: no se pregunta
                    }
                    Core::update($id, array('onboarding' => 'f' . (int) $x['id'], 'onboarding_tries' => 0));
                    return Core::bot_text($id, self::question($x, $c)) && $sent;
                case 'cond':
                    $port = self::branch($x, $c);
                    break;
                case 'ai':
                    list($state, $okAi) = self::ai($c, $x);
                    $sent = $sent && $okAi;
                    if ($state === null) {
                        return $sent;
                    }
                    $port = $state;
                    break;
                case 'end':
                    self::finish($id);
                    return $sent;
            }
            $node = $e[(int) $x['id']][$port] ?? 0;
        }
        self::finish($id);
        return $sent;
    }

    /** ¿Ha contestado ya la AI en este chat y espera el siguiente mensaje del cliente? */
    public static function ai_waiting($c)
    {
        if (!preg_match('/^f(\d+)$/', (string) $c['onboarding'], $m) || (int) $c['onboarding_tries'] < 1) {
            return false;
        }
        $g = self::graph_of($c);
        foreach ($g ? $g['nodes'] : array() as $x) {
            if ((int) $x['id'] === (int) $m[1]) {
                return $x['type'] === 'ai';
            }
        }
        return false;
    }

    /** Caja «AI». Devuelve [salida, entregado]; salida null = espera otro mensaje del cliente o ya ha contestado un agente. */
    private static function ai($c, $x)
    {
        $id = (int) $c['id'];
        $wait = 'f' . (int) $x['id'];
        $used = $c['onboarding'] === $wait ? (int) $c['onboarding_tries'] : 0; // respuestas que ya ha dado en esta caja
        $last = function () use ($id) { return (int) DB::val("SELECT MAX(id) FROM messages WHERE conversation_id = ? AND direction = 'in'", array($id)); };
        Core::update($id, array('onboarding' => $wait, 'onboarding_tries' => $used));
        for ($i = 0; $i < 2; $i++) {
            $seen = $last();
            $answer = null;
            cache_set('assistant:busy:' . $id, 1, Assistant::BUSY); // lo que escriba el cliente mientras tanto no empieza otra respuesta
            try {
                $answer = Assistant::reply(Core::conv($id) ?: $c, $used > 0);
            } catch (Throwable $e) {
                log_error('AI: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
            cache_del('assistant:busy:' . $id);
            if ($last() === $seen) {
                break; // si el cliente ha escrito algo más, se piensa otra vez con todo
            }
        }
        $now = Core::conv($id);
        if (!$now || $now['onboarding'] !== $wait) {
            return array(null, true); // mientras pensaba, ha contestado un agente
        }
        $all = $last() === $seen; // no ha quedado ningún mensaje del cliente sin leer
        if (Assistant::$ends && $all && $used > 0) {
            Core::resolve($now, array('id' => 0, 'name' => 'La AI'), true);
            return array(null, true);
        }
        if ($answer === null || $used >= max(1, (int) ($x['turns'] ?? 1))) {
            if ($used > 0 && !(int) $now['accepted_by']) {
                Core::update($id, array('accepted_at' => 0)); // pasa a una persona: ahora sí suena
            }
            if ($answer === null && Assistant::$reason !== '') {
                Core::note($id, '🤖 La AI no ha contestado: ' . mb_substr(Assistant::$reason, 0, 400));
            }
            return array($answer === null ? '1' : '0', true);
        }
        $ok = Core::bot_text($id, $answer);
        Core::note($id, Assistant::$clarify ? '🤖 La AI ha preguntado por el producto.' : '🤖 Contestado por la AI.');
        Core::update($id, array('onboarding_tries' => $used + 1));
        if ($ok && $all && Assistant::$clarify) {
            return array(null, true); // espera la respuesta del cliente: no está resuelto
        }
        if ($ok && $all) {
            Core::resolve(Core::conv($id), array('id' => 0, 'name' => 'La AI'), true);
        } elseif (!(int) $now['accepted_by']) {
            Core::update($id, array('accepted_at' => 0)); // no se ha entregado o hay algo sin leer: que lo mire una persona
        }
        return array(null, $ok);
    }

    private static function branch($x, $c)
    {
        switch ($x['cond']) {
            case 'channel':
                return (string) max(0, (int) array_search($c['channel'], array('web', 'whatsapp', 'facebook'), true));
            case 'email':
                return $c['email'] !== '' ? '0' : '1';
            case 'name':
                return $c['name'] !== '' ? '0' : '1';
            default:
                return Hours::is_open() ? '0' : '1';
        }
    }

    private static function question($x, $c)
    {
        $list = function ($opts) {
            $o = '';
            foreach ($opts as $i => $l) {
                $o .= "\n" . ($i + 1) . '. ' . $l;
            }
            return $o;
        };
        $en = $x['en'];
        if ($x['kind'] === 'options' && count($x['opts_en']) !== count($x['opts_es'])) {
            $en = ''; // faltan opciones en inglés: se traduce todo desde el español
        }
        return Settings::pick($x['es'] . $list($x['opts_es']), $en === '' ? '' : $en . $list($x['opts_en']), $c['lang'], $c);
    }

    /** Respuesta del cliente a una caja «ask» (onboarding = f + id). */
    public static function answer($c, $text)
    {
        $id = (int) $c['id'];
        $g = self::graph_of($c);
        if (!$g) {
            self::finish($id);
            return;
        }
        list($n, $e) = self::index($g);
        $x = $n[(int) substr($c['onboarding'], 1)] ?? null;
        if (!$x || ($x['type'] !== 'ask' && $x['type'] !== 'ai')) {
            self::finish($id);
            return;
        }
        $email = Core::find_email($text);
        if ($email !== '' && ($c['email'] === '' || ($x['kind'] ?? '') === 'email')) {
            Core::update($id, array('email' => $email));
        }
        if ($x['type'] === 'ai') {
            if (!cache_get('assistant:busy:' . $id)) { // si aún está pensando la respuesta anterior, no se la interrumpe
                self::go($id, $g, (int) $x['id']);
            }
            return;
        }
        list($ok, $value, $port) = self::valid($x, $text, $email);
        if (!$ok) {
            $tries = (int) $c['onboarding_tries'];
            if ($tries < (int) $x['tries']) {
                $ago = now_ms() - (int) DB::val("SELECT MAX(created_at) FROM messages WHERE conversation_id = ? AND direction = 'bot'", array($id));
                $bad = strpos($text, '@') !== false;
                if ($tries > 0 && !$bad && $ago < Core::EMAIL_BURST_MS) {
                    return; // varios mensajes seguidos: se insiste una sola vez
                }
                Core::update($id, array('onboarding_tries' => $tries + 1));
                Core::bot_text($id, self::retry($x, Core::conv($id), $bad));
                return;
            }
            if (isset($e[(int) $x['id']]['no'])) {
                self::go($id, $g, $e[(int) $x['id']]['no']);
                return;
            }
            if ($x['kind'] === 'email') { // se calla; vuelve a pedirlo si el cliente escribe pasadas 12 horas
                $ago = now_ms() - (int) DB::val("SELECT MAX(created_at) FROM messages WHERE conversation_id = ? AND direction = 'bot'", array($id));
                if ($ago > Core::OOH_REPEAT_MS) {
                    Core::update($id, array('onboarding_tries' => 1));
                    Core::bot_text($id, self::retry($x, Core::conv($id), false));
                }
                return;
            }
            $value = $text; // los demás tipos: se acepta lo que diga y se sigue por la primera salida
            $port = '0';
        }
        if ($x['kind'] === 'name') {
            if ($value !== '') {
                Core::update($id, array('name' => mb_substr($value, 0, 120)));
            }
        } elseif ($x['kind'] !== 'email') {
            Robot::save_answer($id, 'n' . (int) $x['id'], $x['title'], $value);
        }
        self::go($id, $g, $e[(int) $x['id']][$port] ?? 0);
    }

    private static function retry($x, $c, $bad)
    {
        if ($x['kind'] === 'email' && !$bad && $x['need_es'] !== '') {
            return Settings::pick($x['need_es'], $x['need_en'], $c['lang'], $c);
        }
        return $x['retry_es'] !== '' ? Settings::pick($x['retry_es'], $x['retry_en'], $c['lang'], $c) : self::question($x, $c);
    }

    /** [vale, valor, salida] */
    private static function valid($x, $text, $email)
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text));
        switch ($x['kind']) {
            case 'email':
                return array($email !== '', $email, '0');
            case 'name':
                $name = Core::parse_name($text, $email);
                return array($name !== '' || $email !== '', $name, '0'); // solo el correo: no se le retiene por el nombre
            case 'number':
                return array(preg_match('/\d/', $t) === 1, $t, '0');
            case 'phone':
                return array(strlen(preg_replace('/\D/', '', $t)) >= 6, $t, '0');
            case 'options':
                $i = Robot::option($x, $t);
                return array($i >= 0, $i >= 0 ? $x['opts_es'][$i] : '', (string) max(0, $i));
            default:
                return array(true, $t, '0');
        }
    }
}
