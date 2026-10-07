<?php

declare(strict_types=1);

/**
 * Procesa correos/jobs programados (inventario, recordatorios)
 * y la cola de campañas de publicidad (envío escalonado).
 *
 * Cron sugerido (cada 10–15 min):
 *   php /ruta/al/proyecto/bin/process-scheduled-mails.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\MarketingCampaignService;
use App\Services\ScheduledMailService;

$svc = new ScheduledMailService();
$result = $svc->processDue(100);

echo 'Jobs programados: ' . $result['processed'] . PHP_EOL;
foreach ($result['errors'] as $err) {
    echo 'ERROR: ' . $err . PHP_EOL;
}

$mkt = new MarketingCampaignService();
$mktResult = $mkt->processDue();
echo 'Publicidad: ' . $mktResult['processed'] . PHP_EOL;
foreach ($mktResult['errors'] as $err) {
    echo 'ERROR MKT: ' . $err . PHP_EOL;
}

$hasErrors = $result['errors'] !== [] || $mktResult['errors'] !== [];
exit($hasErrors ? 1 : 0);
