<?php

declare(strict_types=1);

/**
 * Procesa correos/jobs programados (p. ej. acceso inventario 3 días antes del examen).
 *
 * Cron sugerido (cada 10–15 min):
 *   php /ruta/al/proyecto/bin/process-scheduled-mails.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ScheduledMailService;

$svc = new ScheduledMailService();
$result = $svc->processDue(100);

echo 'Procesados: ' . $result['processed'] . PHP_EOL;
foreach ($result['errors'] as $err) {
    echo 'ERROR: ' . $err . PHP_EOL;
}

exit($result['errors'] === [] ? 0 : 1);
