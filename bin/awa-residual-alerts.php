#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Alertas residual AWA + seed de lojas oficiais.
 *
 *   php bin/awa-residual-alerts.php --account=1335
 *   php bin/awa-official-store-seed.php --account=1335 --dry-run
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../app/Services/_runtime/bootstrap.php';

use App\Services\AwaSellerAlertService;

$opts = getopt('', ['account:', 'days:', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php bin/awa-residual-alerts.php --account=1335 [--days=14]\n";
    exit(0);
}

$accountId = isset($opts['account']) ? (int) $opts['account'] : 1335;
$days = isset($opts['days']) ? (int) $opts['days'] : 14;

$svc = new AwaSellerAlertService($accountId);
$result = $svc->checkResidualAlerts($days);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
