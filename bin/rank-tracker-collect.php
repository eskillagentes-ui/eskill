#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Coletor Rank Tracker — API oficial api.mercadolibre.com (sem scraping).
 *
 * Uso:
 *   php bin/rank-tracker-collect.php --account-id=1335
 *   php bin/rank-tracker-collect.php --account-id=1335 --force
 *   php bin/rank-tracker-collect.php --account-id=1335 --demand-only
 *
 * --force        ignora janela 04–06h (search + trends)
 * --demand-only  só T1c (trends/highlights autenticados); sem janela; leve
 */

use App\Services\Rank\RankTrackerService;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$opts = getopt('', ['account-id:', 'force', 'demand-only']);
$accountId = (int) ($opts['account-id'] ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335));
$force = array_key_exists('force', $opts);
$demandOnly = array_key_exists('demand-only', $opts);

$svc = new RankTrackerService();

if ($demandOnly) {
    $result = $svc->collectDemandSignals($accountId);
    $result['mode'] = 'demand_only';
    $result['position_source'] = $svc->statusForPregao($accountId)['position_source'] ?? null;
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(!empty($result['ok']) ? 0 : 1);
}

$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('G');
if (!$force && ($hour < 4 || $hour > 6)) {
    // Fora da janela de search: ainda roda T1c (trends) — leve e necessário p/ Pregão fora de N/D
    $trends = $svc->collectDemandSignals($accountId);
    fwrite(STDOUT, json_encode([
        'ok' => (bool) ($trends['ok'] ?? false),
        'skipped_search' => true,
        'reason' => 'outside_offpeak_window_04_06_search_only',
        'hour' => $hour,
        'trends' => $trends,
        'position_source' => $svc->statusForPregao($accountId)['position_source'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(!empty($trends['ok']) ? 0 : 1);
}

$result = $svc->collect($accountId);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
exit(!empty($result['ok']) || !empty($result['skipped']) ? 0 : 1);
