#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Shipments Sync Worker
 *
 * Sincroniza envios (shipments) do Mercado Livre para o banco local.
 * Executa continuamente ou via cron com --once.
 *
 * Uso:
 *   php bin/shipments-sync-worker.php [--once] [--account=ID] [--days=N] [--limit=N]
 *       [--order-limit=N] [--sleep-us=N] [--verbose]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Services\ShipmentSyncService;
use App\Services\StructuredLogService;

define('WORKER_NAME', 'shipments-sync-worker');
define('LOG_FILE', __DIR__ . '/../storage/logs/shipments-sync-worker.log');
define('LOCK_FILE', __DIR__ . '/../storage/locks/shipments-sync-worker.lock');
define('SLEEP_SECONDS', 1800);

$options = getopt('', ['once', 'account:', 'days:', 'limit:', 'order-limit:', 'sleep-us:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Shipments Sync Worker — Mercado Livre\n\n"
        . "Uso: php bin/shipments-sync-worker.php [opções]\n\n"
        . "  --once            Executa uma vez e sai (ideal para cron)\n"
        . "  --account=ID      Processa apenas a conta especificada\n"
        . "  --days=N          Janela em dias para pedidos locais (padrão: 30, máx: 365)\n"
        . "  --limit=N         Limite de envios por conta (padrão: sem limite)\n"
        . "  --order-limit=N   Limite de pedidos locais lidos (padrão: 2000, máx: 5000)\n"
        . "  --sleep-us=N      Pausa entre envios em microssegundos (padrão: 100000)\n"
        . "  --verbose         Log detalhado no stdout\n"
        . "  --help            Mostra esta ajuda\n";
    exit(0);
}

$runOnce = isset($options['once']);
$specificAccount = isset($options['account']) ? (int)$options['account'] : null;
$days = isset($options['days']) ? max(1, min(365, (int)$options['days'])) : 30;
$shipmentLimit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
$orderLimit = isset($options['order-limit']) ? max(1, min(5000, (int)$options['order-limit'])) : 2000;
$sleepUs = isset($options['sleep-us']) ? max(0, (int)$options['sleep-us']) : 100000;
$verbose = isset($options['verbose']);

$lockDir = dirname(LOCK_FILE);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = fopen(LOCK_FILE, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . WORKER_NAME . "] Outra instancia ja em execucao — saindo\n";
    if ($lockHandle !== false) {
        fclose($lockHandle);
    }
    exit(0);
}

putenv('LOG_PATH=' . LOG_FILE);
$logger = new StructuredLogService();

function logMsg(string $msg, string $level = 'info', array $ctx = []): void
{
    global $verbose, $logger;
    $ctx = array_merge(['worker' => WORKER_NAME], $ctx);
    try {
        if (method_exists($logger, $level)) {
            $logger->{$level}($msg, $ctx);
        } else {
            $logger->info($msg, $ctx);
        }
    } catch (Throwable $e) {
        error_log('[' . WORKER_NAME . '] log error: ' . $e->getMessage());
    }

    if ($verbose || in_array($level, ['error', 'warning', 'critical'], true)) {
        printf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg);
    }
}

logMsg('Worker iniciado', 'info', [
    'once' => $runOnce,
    'account' => $specificAccount,
    'days' => $days,
    'limit' => $shipmentLimit,
    'order_limit' => $orderLimit,
    'sleep_us' => $sleepUs,
]);

while (true) {
    try {
        $db = Database::getInstance();

        if ($specificAccount !== null) {
            $stmt = $db->prepare("SELECT id, nickname FROM ml_accounts WHERE id = :id AND status != 'disconnected'");
            $stmt->execute(['id' => $specificAccount]);
        } else {
            $stmt = $db->prepare("SELECT id, nickname FROM ml_accounts WHERE status != 'disconnected' ORDER BY id");
            $stmt->execute();
        }

        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($accounts)) {
            logMsg('Nenhuma conta ativa encontrada', 'warning');
        }

        $shipmentService = new ShipmentSyncService();

        foreach ($accounts as $account) {
            $accountId = (int)$account['id'];
            $nickname = (string)($account['nickname'] ?? "conta_{$accountId}");

            logMsg('Sincronizando envios', 'info', [
                'account_id' => $accountId,
                'nickname' => $nickname,
                'days' => $days,
            ]);

            try {
                $stats = $shipmentService->syncForAccount($accountId, $days, [
                    'limit' => $shipmentLimit,
                    'order_limit' => $orderLimit,
                    'sleep_us' => $sleepUs,
                ]);

                logMsg('Sync concluido', 'info', [
                    'account_id' => $accountId,
                    'found' => $stats['found'] ?? 0,
                    'synced' => $stats['synced'] ?? 0,
                    'errors' => $stats['errors'] ?? 0,
                ]);

                if (!empty($stats['error_details'])) {
                    foreach ((array)$stats['error_details'] as $detail) {
                        logMsg('Erro ao sincronizar envio', 'warning', [
                            'account_id' => $accountId,
                            'shipment_id' => $detail['shipment_id'] ?? '?',
                            'error' => $detail['error'] ?? '?',
                        ]);
                    }
                }
            } catch (Throwable $e) {
                logMsg('Falha ao sincronizar conta', 'error', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    } catch (Throwable $e) {
        logMsg('Erro critico no worker', 'error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    if ($runOnce) {
        logMsg('Modo --once: encerrando', 'info');
        break;
    }

    logMsg('Aguardando proximo ciclo', 'info', ['sleep' => SLEEP_SECONDS]);
    sleep(SLEEP_SECONDS);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

logMsg('Worker encerrado', 'info');
exit(0);
