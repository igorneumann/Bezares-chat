<?php
/** Detección de idioma y traducción con OpenAI (ChatGPT). */
class AI
{
    /** Idiomas que el equipo lee directamente. */
    const NATIVE = array('es', 'en');

    public static function enabled()
    {
        return (int) Settings::get('translate_enabled') === 1 && Settings::get('openai_key') !== '';
    }

    private static function chat($system, $user)
    {
        if (!self::enabled()) {
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
            log_error('OpenAI ' . $r['status'] . ' ' . ($r['error'] ?: substr($r['body'], 0, 300)));
            return null;
        }
        $d = json_decode($r['json']['choices'][0]['message']['content'], true);
        return is_array($d) ? $d : null;
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
        if ($text === '' || !self::enabled()) {
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

    public static function translate($text, $lang)
    {
        $text = trim((string) $text);
        $lang = lang_norm($lang);
        if ($text === '' || $lang === '') {
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
