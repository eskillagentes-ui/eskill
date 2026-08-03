#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Coleta Ads read-only + alertas + tick opcional do índice.
 *
 * Uso:
 *   php bin/ads-collect.php --account-id=1335
 *   php bin/ads-collect.php --account-id=1335 --history --tick --json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\Ads\AdsAlertService;
use App\Services\Ads\AdsMetricsCollector;
use App\Services\Pregao\AccountIndexService;

$opts = getopt('', ['account-id:', 'history', 'tick', 'json']);
$accountId = (int) ($opts['account-id'] ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335));
if ($accountId <= 0) {
    fwrite(STDERR, "Informe --account-id=N\n");
    exit(1);
}

$collector = new AdsMetricsCollector();
$result = $collector->collect($accountId, isset($opts['history']));
$result['alerts'] = (new AdsAlertService())->evaluate($accountId);

if (isset($opts['tick'])) {
    $tick = (new AccountIndexService())->tick($accountId);
    $result['tick'] = [
        'indice' => $tick['indice'],
        'factors_active' => $tick['factors_active'],
        'label' => $tick['label'],
        'factors' => $tick['factors'],
    ];
}

if (isset($opts['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

fwrite(STDOUT, sprintf(
    "ads: ok=%s available=%s campanhas=%s tacos=%s acos=%s gasto_hoje=%s\n",
    !empty($result['ok']) ? '1' : '0',
    !empty($result['available']) ? '1' : '0',
    (string) ($result['active_campaigns'] ?? 0),
    $result['tacos'] === null ? 'n/d' : (string) $result['tacos'],
    $result['acos'] === null ? 'n/d' : (string) $result['acos'],
    $result['gasto_hoje'] === null ? 'n/d' : (string) $result['gasto_hoje']
));
if (!empty($result['message'])) {
    fwrite(STDOUT, 'msg: ' . $result['message'] . "\n");
}
foreach ($result['alerts'] ?? [] as $a) {
    fwrite(STDOUT, 'alert: ' . ($a['msg'] ?? '') . "\n");
}
if (isset($result['tick'])) {
    fwrite(STDOUT, sprintf(
        "tick: %s · %s\n",
        $result['tick']['indice'] === null ? 'n/d' : (string) $result['tick']['indice'],
        $result['tick']['label'] ?? ''
    ));
}
