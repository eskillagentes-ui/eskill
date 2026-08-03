#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Tick do índice ESKL11 — recalcula a cada execução (cron a cada minuto ou loop 45s).
 *
 * Uso:
 *   php bin/pregao-index-tick.php --account-id=1335
 *   php bin/pregao-index-tick.php --all
 *   php bin/pregao-index-tick.php --all --loop --interval=45
 *   php bin/pregao-index-tick.php --account-id=1335 --collect
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Pregao\AccountIndexService;
use App\Services\Pregao\PregaoMetricsCollector;

$opts = getopt('', ['account-id:', 'all', 'loop', 'interval:', 'consolidate', 'collect']);
$interval = max(15, (int) ($opts['interval'] ?? 45));
$loop = isset($opts['loop']);
$consolidate = isset($opts['consolidate']);
$collect = isset($opts['collect']);

function pregao_resolve_account_ids(array $opts): array
{
    if (isset($opts['account-id'])) {
        return [(int) $opts['account-id']];
    }
    if (!isset($opts['all'])) {
        fwrite(STDERR, "Informe --account-id=N ou --all\n");
        exit(1);
    }
    $pdo = Database::getInstance();
    $rows = $pdo->query(
        "SELECT id FROM ml_accounts WHERE status IN ('active','connected') OR status IS NULL LIMIT 200"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === []) {
        $rows = $pdo->query('SELECT account_id FROM account_index_metrics')->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_map('intval', $rows ?: []);
}

$service = new AccountIndexService();
$collector = $collect ? new PregaoMetricsCollector() : null;

do {
    $ids = pregao_resolve_account_ids($opts);
    foreach ($ids as $accountId) {
        if ($accountId <= 0) {
            continue;
        }
        try {
            if ($collector !== null) {
                // Coleta leve a cada tick; Ads (Ft/TACOS) incluso — read-only, sem fullHistory
                $collector->collect($accountId, ['reputation', 'health', 'questions', 'sales', 'visits', 'ads', 'robots']);
                try {
                    (new \App\Services\Sentinela\Sentinela())->collect($accountId);
                } catch (Throwable $sentinelaErr) {
                    fwrite(STDERR, "[sentinela] account={$accountId} {$sentinelaErr->getMessage()}\n");
                }
            }
            $result = $service->tick($accountId);
            fwrite(STDOUT, sprintf(
                "[%s] account=%d indice=%s · %s\n",
                date('H:i:s'),
                $accountId,
                $result['indice'] === null ? 'n/d' : number_format((float) $result['indice'], 2, '.', ''),
                $result['label']
            ));
            if ($consolidate) {
                $service->consolidateDailyCandle($accountId);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "[err] account={$accountId} {$e->getMessage()}\n");
        }
    }
    if ($loop) {
        sleep($interval);
    }
} while ($loop);
