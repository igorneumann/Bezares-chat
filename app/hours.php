<?php
/** Horario laboral: ¿abierto ahora? y tiempo laborable entre dos instantes. */
class Hours
{
    private static function holidays()
    {
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string) Settings::get('holidays')) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($d))) {
                $out[trim($d)] = true;
            }
        }
        return $out;
    }

    private static function minutes($hhmm)
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string) $hhmm, $m)) {
            return 0;
        }
        return min(1440, ((int) $m[1]) * 60 + (int) $m[2]);
    }

    /** [inicio, fin] en segundos unix del tramo laboral de ese día, o null. */
    private static function window(DateTimeImmutable $day, $hours, $holidays)
    {
        if (isset($holidays[$day->format('Y-m-d')])) {
            return null;
        }
        $w = (int) $day->format('w');
        $c = isset($hours[$w]) ? $hours[$w] : (isset($hours[(string) $w]) ? $hours[(string) $w] : null);
        if (!$c || empty($c['on'])) {
            return null;
        }
        $s = self::minutes($c['start']);
        $e = self::minutes($c['end']);
        if ($e <= $s) {
            return null;
        }
        return array(
            $day->setTime(intdiv($s, 60), $s % 60)->getTimestamp(),
            $day->setTime(intdiv($e, 60), $e % 60)->getTimestamp(),
        );
    }

    public static function is_open($ms = null)
    {
        $ts = $ms === null ? time() : intdiv((int) $ms, 1000);
        $day = (new DateTimeImmutable('@' . $ts))->setTimezone(tz());
        $w = self::window($day, Settings::get('hours'), self::holidays());
        return $w !== null && $ts >= $w[0] && $ts < $w[1];
    }

    /** Horario semanal en texto: "de lunes a viernes, de 8:00 a 17:00" ('' si no hay ningún día laborable). */
    public static function summary($lang)
    {
        // Por idioma: días (desde el domingo), plantilla de varios días y plantilla de un solo día (a y b = días, s y e = horas).
        $tr = array(
            'es' => array(array('domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'), 'de {a} a {b}, de {s} a {e}', 'los {as}, de {s} a {e}'),
            'en' => array(array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), '{a} to {b}, {s} to {e}', '{as}, {s} to {e}'),
            'fr' => array(array('dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'), 'du {a} au {b}, de {s} à {e}', 'le {a}, de {s} à {e}'),
            'de' => array(array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'), '{a} bis {b}, {s} bis {e} Uhr', '{a}, {s} bis {e} Uhr'),
            'pt' => array(array('domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'), 'de {a} a {b}, das {s} às {e}', '{a}, das {s} às {e}'),
            'it' => array(array('domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'), 'da {a} a {b}, dalle {s} alle {e}', '{a}, dalle {s} alle {e}'),
        );
        list($names, $many, $one) = $tr[$lang] ?? $tr['es'];
        $hours = Settings::get('hours');
        $runs = array(); // días seguidos con el mismo horario: [primero, último, inicio, fin]
        foreach (array(1, 2, 3, 4, 5, 6, 0) as $i => $w) {
            $c = isset($hours[$w]) ? $hours[$w] : (isset($hours[(string) $w]) ? $hours[(string) $w] : null);
            if (!$c || empty($c['on']) || self::minutes($c['end']) <= self::minutes($c['start'])) {
                continue;
            }
            $n = count($runs) - 1;
            if ($n >= 0 && $runs[$n]['pos'] === $i - 1 && $runs[$n]['start'] === $c['start'] && $runs[$n]['end'] === $c['end']) {
                $runs[$n]['pos'] = $i;
                $runs[$n]['last'] = $w;
            } else {
                $runs[] = array('pos' => $i, 'first' => $w, 'last' => $w, 'start' => $c['start'], 'end' => $c['end']);
            }
        }
        $out = array();
        foreach ($runs as $r) {
            $a = $names[$r['first']];
            $b = $names[$r['last']];
            $s = preg_replace('/^0(?=\d:)/', '', $r['start']);
            $e = preg_replace('/^0(?=\d:)/', '', $r['end']);
            $out[] = strtr($a === $b ? $one : $many, array('{a}' => $a, '{as}' => preg_replace('/([^s])$/u', '$1s', $a), '{b}' => $b, '{s}' => $s, '{e}' => $e));
        }
        return implode('; ', $out);
    }

    /** Milisegundos laborables entre dos instantes. */
    public static function business_ms($from_ms, $to_ms)
    {
        if ($to_ms <= $from_ms) {
            return 0;
        }
        $from = intdiv((int) $from_ms, 1000);
        $to = intdiv((int) $to_ms, 1000);
        $hours = Settings::get('hours');
        $hol = self::holidays();
        $day = (new DateTimeImmutable('@' . $from))->setTimezone(tz())->setTime(0, 0);
        $total = 0;
        for ($i = 0; $i < 400 && $day->getTimestamp() <= $to; $i++) {
            $w = self::window($day, $hours, $hol);
            if ($w) {
                $a = max($from, $w[0]);
                $b = min($to, $w[1]);
                if ($b > $a) {
                    $total += $b - $a;
                }
            }
            $day = $day->modify('+1 day');
        }
        return $total * 1000;
    }
}
