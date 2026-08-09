#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Items Sync Worker
 *
 * Sincroniza anúncios (itens) do Mercado Livre para o banco local para todas
 * as contas ativas. Serve como trilha de compensação — o caminho principal de
 * atualização são os eventos de webhook (ML-BLG-040).
 *
 * Suporta modo --once (single pass ideal para cron) e loop contínuo.
 *
 * Features:
 *  - flock para prevenir execução concorrente
 *  - Logging via StructuredLogService / Monolog
 *  - Processa todas as contas com status != 'disconnected'
 *  - Suporte a --account=ID para processar conta específica
 *
 * Uso:
 *   php bin/items-sync-worker.php [--once] [--account=ID] [--verbose]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Services\ItemSyncService;
use App\Services\StructuredLogService;

define('WORKER_NAME', 'items-sync-worker');
define('LOG_FILE', __DIR__ . '/../storage/logs/items-sync-worker.log');
define('LOCK_FILE', __DIR__ . '/../storage/locks/items-sync-worker.lock');
define('SLEEP_SECONDS', 3600); // 1h entre ciclos em modo loop

// ── CLI Options ───────────────────────────────────────────────────────────────
$options = getopt('', ['once', 'account:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Items Sync Worker — Sincroniza anúncios ML para banco local (trilha de compensação)\n\n"
        . "Uso: php bin/items-sync-worker.php [opcoes]\n\n"
        . "  --once           Executa uma vez e sai (sem loop)\n"
        . "  --account=ID     Processa apenas uma conta ML especifica\n"
        . "  --verbose        Log detalhado no stdout\n"
        . "  --help           Mostra esta ajuda\n";
    exit(0);
}

$runOnce         = isset($options['once']);
$specificAccount = isset($options['account']) ? (int)$options['account'] : null;
$verbose         = isset($options['verbose']);

// ── Flock: prevenir execução concorrente ──────────────────────────────────────
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

// ── Logging via StructuredLogService ─────────────────────────────────────────
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
    } catch (\Throwable $e) {
        error_log('[' . WORKER_NAME . '] log error: ' . $e->getMessage());
    }
    if ($verbose || in_array($level, ['error', 'warning', 'critical'], true)) {
        printf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg);
    }
}

logMsg('Worker iniciado', 'info', [
    'once'    => $runOnce,
    'account' => $specificAccount,
]);

// ── Main loop ─────────────────────────────────────────────────────────────────
while (true) {
    try {
        $db = Database::getInstance();

        // Buscar contas ativas (status != 'disconnected')
        if ($specificAccount !== null) {
            $stmt = $db->prepare("SELECT id, nickname FROM ml_accounts WHERE id = :id AND status != 'disconnected'");
            $stmt->execute(['id' => $specificAccount]);
        } else {
            $stmt = $db->prepare("SELECT id, nickname FROM ml_accounts WHERE status != 'disconnected' ORDER BY id");
            $stmt->execute();
        }

        $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($accounts)) {
            logMsg('Nenhuma conta ativa encontrada', 'warning');
        }

        foreach ($accounts as $account) {
            $accountId = (int)$account['id'];
            $nickname  = (string)($account['nickname'] ?? "conta_{$accountId}");

            logMsg('Sincronizando itens', 'info', [
                'account_id' => $accountId,
                'nickname'   => $nickname,
            ]);

            try {
                $syncService = new ItemSyncService();
                $result = $syncService->syncForAccount($accountId);

                logMsg('Sync de itens concluido', 'info', [
                    'account_id'    => $accountId,
                    'total_found'   => $result['total_found'] ?? 0,
                    'total_synced'  => $result['total_synced'] ?? 0,
                    'batches'       => $result['batches'] ?? 0,
                ]);
            } catch (\Throwable $e) {
                logMsg('Falha ao sincronizar itens da conta', 'error', [
                    'account_id' => $accountId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    } catch (\Throwable $e) {
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

// Liberar lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

logMsg('Worker encerrado', 'info');
exit(0);
