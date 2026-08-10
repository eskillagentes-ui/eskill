#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Coletor Rank Tracker (API oficial /sites/{site}/search).
 *
 * Uso:
 *   php bin/rank-tracker-collect.php --account-id=1335
 *   php bin/rank-tracker-collect.php --account-id=1335 --force  # ignora janela 04-06h
 */

use App\Services\Rank\RankTrackerService;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$opts = getopt('', ['account-id:', 'force']);
$accountId = (int) ($opts['account-id'] ?? ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335));
$force = array_key_exists('force', $opts);

$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('G');
if (!$force && ($hour < 4 || $hour > 6)) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'skipped' => true,
        'reason' => 'outside_offpeak_window_04_06',
        'hour' => $hour,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
}

$svc = new RankTrackerService();
$result = $svc->collect($accountId);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
exit(!empty($result['ok']) || !empty($result['skipped']) ? 0 : 1);
