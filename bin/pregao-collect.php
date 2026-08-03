#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Coleta métricas reais do Pregão e opcionalmente recalcula o índice.
 *
 * Uso:
 *   php bin/pregao-collect.php --account-id=1335
 *   php bin/pregao-collect.php --account-id=1335 --only=reputation,health,questions,sales
 *   php bin/pregao-collect.php --account-id=1335 --only=keywords
 *   php bin/pregao-collect.php --account-id=1335 --tick
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\Pregao\AccountIndexService;
use App\Services\Pregao\PregaoMetricsCollector;

$opts = getopt('', ['account-id:', 'only:', 'tick', 'json']);
$accountId = (int) ($opts['account-id'] ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335));
if ($accountId <= 0) {
    fwrite(STDERR, "Informe --account-id=N\n");
    exit(1);
}

$only = null;
if (isset($opts['only']) && is_string($opts['only']) && $opts['only'] !== '') {
    $only = array_values(array_filter(array_map('trim', explode(',', $opts['only']))));
}

$collector = new PregaoMetricsCollector();
$result = $collector->collect($accountId, $only);

if (isset($opts['tick'])) {
    $tick = (new AccountIndexService())->tick($accountId);
    $result['tick'] = [
        'indice' => $tick['indice'],
        'factors_active' => $tick['factors_active'],
        'label' => $tick['label'],
    ];
}

if (isset($opts['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

foreach ($result as $key => $value) {
    if ($key === 'meta') {
        $avail = $value['available'] ?? [];
        $active = array_sum(array_map(static fn ($v) => $v ? 1 : 0, $avail));
        fwrite(STDOUT, sprintf("meta: %d de 5 fatores ativos %s\n", $active, json_encode($avail)));
        continue;
    }
    if ($key === 'tick') {
        fwrite(STDOUT, sprintf(
            "tick: indice=%s · %s\n",
            $value['indice'] === null ? 'n/d' : (string) $value['indice'],
            $value['label'] ?? ''
        ));
        continue;
    }
    $ok = !empty($value['ok']);
    fwrite(STDOUT, sprintf("[%s] %s\n", $ok ? 'ok' : 'fail', $key));
}
