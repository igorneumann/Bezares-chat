<?php
/** Detección de idioma y traducción con OpenAI (ChatGPT). */
class AI
{
    /** Idiomas que el equipo lee directamente. */
    const NATIVE = array('es', 'en');

    /** Último error de la llamada en curso (para el diagnóstico del panel). */
    public static $lastError = null;

    public static function enabled()
    {
        return (int) Settings::get('translate_enabled') === 1 && (string) Settings::get('openai_key') !== '';
    }

    /** Por qué no se traduce, en lenguaje claro; null si está activada y configurada. */
    public static function disabled_reason()
    {
        if ((int) Settings::get('translate_enabled') !== 1) {
            return 'La traducción automática está desactivada.';
        }
        if ((string) Settings::get('openai_key') === '') {
            return 'Falta la API key de OpenAI.';
        }
        return null;
    }

    /** Último fallo y último éxito guardados (se muestran en Configuración → Traducción). */
    public static function status()
    {
        return array('error' => cache_get('ai:last_error'), 'ok' => cache_get('ai:last_ok'));
    }

    private static function fail($msg)
    {
        self::$lastError = $msg;
        log_error('OpenAI ' . $msg);
        cache_set('ai:last_error', array('t' => time(), 'msg' => mb_substr($msg, 0, 400)), 30 * 86400);
        return null;
    }

    private static function chat($system, $user)
    {
        self::$lastError = null;
        if (!self::enabled()) {
            self::$lastError = self::disabled_reason();
            return null;
        }
        $r = Http::json('POST', 'https://api.openai.com/v1/chat/completions', array(
            'model'           => Settings::get('openai_model') ?: 'gpt-4o-mini',
            'temperature'     => 0,
            'response_format' => array('type' => 'json_object'),
            'messages'        => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
        ), array('Authorization' => 'Bearer ' . Settings::get('openai_key')), 20);
        if ($r['status'] !== 200 || empty($r['json']['choices'][0]['message']['content'])) {
            $detail = $r['error'] ?: (isset($r['json']['error']['message']) ? $r['json']['error']['message'] : substr($r['body'], 0, 300));
            return self::fail('HTTP ' . $r['status'] . ': ' . $detail);
        }
        $d = json_decode($r['json']['choices'][0]['message']['content'], true);
        if (!is_array($d)) {
            return self::fail('respuesta no válida del modelo');
        }
        cache_set('ai:last_ok', time(), 30 * 86400);
        return $d;
    }

    private static function lang_name($c)
    {
        $n = array('es' => 'Spanish', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese');
        return isset($n[$c]) ? $n[$c] : $c;
    }

    /** Detecta idioma; si no es es/en, traduce al idioma del equipo. */
    public static function analyze($text)
    {
        $none = array('lang' => '', 'translation' => null);
        $text = trim((string) $text);
        if ($text === '' || !self::enabled() || !self::has_words($text)) {
            return $none;
        }
        $target = Settings::get('agent_lang') ?: 'es';
        $d = self::chat(
            'You are a language detector and translator for a customer support chat. Answer ONLY with JSON. '
            . 'If the message is Spanish or English return {"lang":"es"} or {"lang":"en"}. '
            . 'Otherwise return {"lang":"<ISO 639-1 code>","translation":"<message translated to ' . self::lang_name($target) . '>"}. '
            . 'If it is too short or ambiguous (ok, a name, an email, emojis) return {"lang":""}.',
            $text
        );
        if (!$d) {
            return $none;
        }
        $lang = lang_norm(isset($d['lang']) ? $d['lang'] : '');
        $tr = isset($d['translation']) && is_string($d['translation']) ? trim($d['translation']) : null;
        if (in_array($lang, self::NATIVE, true)) {
            $tr = null;
        }
        return array('lang' => $lang, 'translation' => $tr ?: null);
    }

    /** Solo emojis, números o signos: no hay nada que traducir ni idioma que detectar. */
    private static function has_words($text)
    {
        return preg_match('/\p{L}/u', $text) !== 0;
    }

    public static function translate($text, $lang)
    {
        $text = trim((string) $text);
        $lang = lang_norm($lang);
        if ($text === '' || $lang === '' || !self::has_words($text)) {
            return null;
        }
        $d = self::chat(
            'Translate the user message to ' . self::lang_name($lang) . ' (ISO 639-1 "' . $lang . '") with the tone of a friendly customer support agent. '
            . 'Keep text in curly braces like {name} unchanged. Return ONLY JSON: {"translation":"..."}',
            $text
        );
        return $d && !empty($d['translation']) ? trim($d['translation']) : null;
    }

    public static function translate_cached($text, $lang)
    {
        $k = 'tr:' . md5($lang . '|' . $text);
        $hit = cache_get($k);
        if ($hit !== null) {
            return $hit;
        }
        $tr = self::translate($text, $lang);
        if ($tr) {
            cache_set($k, $tr, 30 * 86400);
        }
        return $tr;
    }
}
