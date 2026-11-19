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
