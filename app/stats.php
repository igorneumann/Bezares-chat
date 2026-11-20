<?php
/** Estadísticas de atención. */
class Stats
{
    private static function avg($a)
    {
        return $a ? (int) round(array_sum($a) / count($a)) : null;
    }

    private static function median($a)
    {
        if (!$a) {
            return null;
        }
        sort($a);
        $n = count($a);
        $m = intdiv($n, 2);
        return (int) ($n % 2 ? $a[$m] : round(($a[$m - 1] + $a[$m]) / 2));
    }

    public static function compute($from, $to, $threshold_min)
    {
        $start = (new DateTimeImmutable($from . ' 00:00:00', tz()))->getTimestamp() * 1000;
        $end = (new DateTimeImmutable($to . ' 23:59:59', tz()))->getTimestamp() * 1000 + 999;
        $thr = max(1, (int) $threshold_min) * 60000;
        $rows = DB::rows('SELECT channel, created_at, first_response_at, first_response_by, resolved_at, accepted_by FROM conversations WHERE is_test = 0 AND created_at BETWEEN ? AND ?', array($start, $end));

        $names = array();
        foreach (Auth::agents() as $a) {
            $names[(int) $a['id']] = $a['name'];
        }
        $s = array(
            'total' => count($rows), 'answered' => 0, 'resolved' => 0, 'within' => 0, 'within_biz' => 0, 'in_hours' => 0,
            'resp' => array(), 'resp_biz' => array(), 'by_channel' => array(), 'by_agent' => array(), 'by_day' => array(),
        );
        foreach ($rows as $r) {
            $ch = $r['channel'];
            $day = (new DateTimeImmutable('@' . intdiv((int) $r['created_at'], 1000)))->setTimezone(tz())->format('Y-m-d');
            $s['by_channel'][$ch] = $s['by_channel'][$ch] ?? array('total' => 0, 'answered' => 0);
            $s['by_day'][$day] = $s['by_day'][$day] ?? array('total' => 0, 'answered' => 0);
            $s['by_channel'][$ch]['total']++;
            $s['by_day'][$day]['total']++;
            if (Hours::is_open((int) $r['created_at'])) {
                $s['in_hours']++;
            }
            // Fin del tiempo de respuesta: la primera respuesta de una persona o, si fue antes, la marca de "resuelta".
            $fr = (int) $r['first_response_at'];
            $rs = (int) $r['resolved_at'];
            if ($rs) {
                $s['resolved']++;
            }
            $endAt = ($fr && $rs) ? min($fr, $rs) : ($fr ?: $rs);
            if (!$endAt) {
                continue;
            }
            $s['answered']++;
            $s['by_channel'][$ch]['answered']++;
            $s['by_day'][$day]['answered']++;
            $d = $endAt - (int) $r['created_at'];
            $db = Hours::business_ms((int) $r['created_at'], $endAt);
            $s['resp'][] = $d;
            $s['resp_biz'][] = $db;
            if ($d <= $thr) {
                $s['within']++;
            }
            if ($db <= $thr) {
                $s['within_biz']++;
            }
            $aid = ($fr && $fr === $endAt) ? (int) $r['first_response_by'] : (int) $r['accepted_by'];
            if (!isset($s['by_agent'][$aid])) {
                $s['by_agent'][$aid] = array('name' => $names[$aid] ?? '(eliminado)', 'answered' => 0, 'resp' => array(), 'resp_biz' => array());
            }
            $s['by_agent'][$aid]['answered']++;
            $s['by_agent'][$aid]['resp'][] = $d;
            $s['by_agent'][$aid]['resp_biz'][] = $db;
        }
        $agents = array();
        foreach ($s['by_agent'] as $a) {
            $agents[] = array('name' => $a['name'], 'answered' => $a['answered'], 'avg' => self::avg($a['resp']), 'avg_biz' => self::avg($a['resp_biz']));
        }
        usort($agents, function ($x, $y) { return $y['answered'] - $x['answered']; });
        ksort($s['by_day']);
        return array(
            'threshold'   => (int) $threshold_min,
            'total'       => $s['total'],
            'answered'    => $s['answered'],
            'unanswered'  => $s['total'] - $s['answered'],
            'resolved'    => $s['resolved'],
            'in_hours'    => $s['in_hours'],
            'within'      => $s['within'],
            'within_biz'  => $s['within_biz'],
            'avg'         => self::avg($s['resp']),
            'median'      => self::median($s['resp']),
            'avg_biz'     => self::avg($s['resp_biz']),
            'median_biz'  => self::median($s['resp_biz']),
            'by_channel'  => $s['by_channel'],
            'by_agent'    => $agents,
            'by_day'      => $s['by_day'],
        );
    }
}
