<?php
/** Tareas periódicas: recordatorios automáticos y limpieza de chats antiguos. */
class Cron
{
    /** Se llama desde cron.php y, como respaldo, desde cada sincronización del cliente (máx. 1 vez/min). */
    public static function tick($force = false)
    {
        if (!$force) {
            if (cache_get('cron:lock')) {
                return;
            }
            cache_set('cron:lock', 1, 55);
        }
        self::reminders();
        if (time() - (int) cache_get('cron:cleanup') > 86400) {
            self::cleanup();
        }
    }

    public static function reminders()
    {
        $now = now_ms();

        // 1) El cliente escribió y nadie contestó en X minutos (solo en horario laboral).
        if (Settings::get('reminder_enabled') && Hours::is_open($now)) {
            $rows = DB::rows(
                "SELECT id, onboarding FROM conversations WHERE is_test = 0 AND status = 'open'
                 AND (onboarding = 'done' OR onboarding LIKE 'q%' OR onboarding LIKE 'f%' OR (onboarding = 'email' AND onboarding_tries >= 2))
                 AND last_customer_at > last_agent_at AND last_customer_at < ? AND last_customer_at > ?
                 AND reminder_sent_at < last_customer_at LIMIT 50",
                array($now - max(1, (int) Settings::get('reminder_minutes')) * 60000, $now - Channels::WINDOW_MS)
            );
            foreach ($rows as $r) {
                Core::update($r['id'], array('reminder_sent_at' => $now, 'accepted_at' => 0, 'accepted_by' => 0)); // vuelve a sonar
                if ($r['onboarding'] === 'done') { // si aún falta el correo, solo se avisa al equipo
                    Core::bot_say($r['id'], 'reminder');
                }
            }
        }

        // Sin respuesta a las X h (23 por defecto) en WhatsApp y Facebook: se avisa de que se contestará por correo (quedan 60 min de margen).
        // Si el diagrama publicado tiene ese aviso para un canal, lo hace él.
        $chans = Flow::live() ? array_values(array_filter(array('whatsapp', 'facebook'), function ($ch) { return !Flow::handles_window(null, $ch); })) : array('whatsapp', 'facebook');
        if (Settings::get('window_notice_enabled') && $chans) {
            $rows = DB::col(
                "SELECT id FROM conversations WHERE is_test = 0 AND status = 'open' AND channel IN ('" . implode("', '", $chans) . "')
                 AND last_customer_at > last_agent_at AND last_customer_at <= ? AND last_customer_at > ?
                 AND window_try_at < last_customer_at LIMIT 50",
                array($now - max(1, min(23, (int) Settings::get('window_hours'))) * 3600000, $now - Channels::WINDOW_MS + 60000)
            );
            foreach ($rows as $id) {
                Core::window_notice((int) $id);
            }
        }

        Flow::cron($now); // cajas de tiempo del diagrama publicado
        Robot::cron($now); // mensajes por tiempo añadidos en el robot anterior

        // 2) El agente contestó y el cliente no responde en X horas (dentro de la ventana de 24 h de Meta).
        if (Settings::get('followup_enabled')) {
            $rows = DB::rows(
                "SELECT id FROM conversations WHERE is_test = 0 AND status = 'open'
                 AND last_agent_at > last_customer_at AND last_agent_at < ? AND last_customer_at > ?
                 AND followup_sent_at < last_agent_at LIMIT 50",
                array($now - max(1, (int) Settings::get('followup_hours')) * 3600000, $now - Channels::WINDOW_MS + 600000)
            );
            foreach ($rows as $r) {
                Core::update($r['id'], array('followup_sent_at' => $now));
                Core::bot_say($r['id'], 'followup');
            }
        }
    }

    public static function cleanup()
    {
        cache_set('cron:cleanup', time());
        self::housekeeping();
        $months = (int) Settings::get('retention_months');
        if ($months <= 0) {
            return;
        }
        $cutoff = (new DateTimeImmutable('now', tz()))->modify('-' . $months . ' months')->getTimestamp() * 1000;
        for ($i = 0; $i < 20; $i++) {
            $ids = DB::col('SELECT id FROM conversations WHERE updated_at < ? LIMIT 200', array($cutoff));
            if (!$ids) {
                break;
            }
            $in = implode(',', array_map('intval', $ids));
            foreach (DB::col("SELECT media_url FROM messages WHERE conversation_id IN ($in) AND media_url <> ''") as $u) {
                Media::delete_stored($u);
            }
            DB::exec("DELETE FROM messages WHERE conversation_id IN ($in)");
            DB::exec("DELETE FROM conversations WHERE id IN ($in)");
        }
    }

    /** Limpieza técnica diaria: cachés y límites caducados, temporales huérfanos y migración de adjuntos antiguos. */
    public static function housekeeping()
    {
        // Chats de prueba del panel: se borran solos a las 24 h.
        $old = DB::col('SELECT id FROM conversations WHERE is_test = 1 AND created_at < ?', array(now_ms() - 86400000));
        if ($old) {
            $in = implode(',', array_map('intval', $old));
            foreach (DB::col("SELECT media_url FROM messages WHERE conversation_id IN ($in) AND media_url <> ''") as $u) {
                Media::delete_stored($u);
            }
            DB::exec("DELETE FROM messages WHERE conversation_id IN ($in)");
            DB::exec("DELETE FROM conversations WHERE id IN ($in)");
        }
        Signup::cleanup();
        DB::exec('DELETE r FROM robot_sent r LEFT JOIN conversations c ON c.id = r.conversation_id WHERE c.id IS NULL');
        DB::exec('DELETE FROM kv WHERE expires > 0 AND expires < ?', array(time()));
        DB::exec('DELETE FROM rate_limits WHERE window_end < ?', array(time() - 60));
        foreach ((array) glob(BZC_ROOT . '/storage/tmp/dl*') as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
        // Migración de adjuntos antiguos por lotes con cursor: los que fallen no bloquean al resto y se reintentan mañana.
        $cursor = 0;
        for ($i = 0; $i < 100; $i++) {
            list($cursor, $n) = Media::migrate_legacy($cursor);
            if ($n === 0) {
                break;
            }
        }
    }
}
