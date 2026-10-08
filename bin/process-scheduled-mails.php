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
use App\Services\PartnerTierService;
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

$tierResult = ['evaluated' => ['updated' => 0], 'warnings' => ['sent' => 0, 'errors' => []]];
try {
    $tierResult = (new PartnerTierService())->processDue();
    echo 'Niveles partner: evaluados=' . (int) ($tierResult['evaluated']['updated'] ?? 0)
        . ' avisos=' . (int) ($tierResult['warnings']['sent'] ?? 0) . PHP_EOL;
    foreach ($tierResult['warnings']['errors'] ?? [] as $err) {
        echo 'ERROR TIERS: ' . $err . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR TIERS: ' . $e->getMessage() . PHP_EOL;
    $tierResult['warnings']['errors'][] = $e->getMessage();
}

$hasErrors = $result['errors'] !== []
    || $mktResult['errors'] !== []
    || (($tierResult['warnings']['errors'] ?? []) !== []);
exit($hasErrors ? 1 : 0);
