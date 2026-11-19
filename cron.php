<?php
/**
 * Tareas periódicas (recordatorios y limpieza). Programar cada minuto en el hosting:
 *   * * * * * php /ruta/a/server/cron.php
 * (Si no hay cron, se ejecutan igualmente mientras haya algún cliente de Windows conectado.)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos');
}
require __DIR__ . '/app/bootstrap.php';
Cron::tick(true);
echo "OK\n";
