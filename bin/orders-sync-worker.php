#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Orders Sync Worker
 *
 * Sincroniza pedidos do Mercado Livre para o banco local para todas as contas
 * ativas. Suporta modo --once (single pass) e loop contínuo.
 *
 * Features:
 *  - flock para prevenir execução concorrente
 *  - Logging via StructuredLogService (Monolog)
 *  - Processa todas as contas com status != 'disconnected'
 *  - Suporte a --account=ID para processar conta específica
 *  - Suporte a --full-backfill para reprocessar histórico completo
 *
 * Uso:
 *   php bin/orders-sync-worker.php [--once] [--account=ID] [--full-backfill] [--limit=N]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Services\OrderService;
use App\Services\StructuredLogService;

define('WORKER_NAME', 'orders-sync-worker');
define('LOG_FILE', __DIR__ . '/../storage/logs/orders-sync-worker.log');
define('LOCK_FILE', __DIR__ . '/../storage/locks/orders-sync-worker.lock');
define('SLEEP_SECONDS', 300); // 5 min entre ciclos em modo loop

// ── CLI Options ───────────────────────────────────────────────────────────────
$options = getopt('', ['once', 'account:', 'full-backfill', 'limit:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Orders Sync Worker — Sincroniza pedidos ML para banco local\n\n"
        . "Uso: php bin/orders-sync-worker.php [opcoes]\n\n"
        . "  --once              Executa uma vez e sai (sem loop)\n"
        . "  --account=ID        Processa apenas uma conta ML especifica\n"
        . "  --full-backfill     Forca sincronizacao completa (ignora checkpoint)\n"
        . "  --limit=N           Pedidos por pagina (default: 50, max: 200)\n"
        . "  --verbose           Log detalhado no stdout\n"
        . "  --help              Mostra esta ajuda\n";
    exit(0);
}

$runOnce = isset($options['once']);
$specificAccount = isset($options['account']) ? (int)$options['account'] : null;
$fullBackfill = isset($options['full-backfill']);
$pageLimit = isset($options['limit']) ? min(200, max(1, (int)$options['limit'])) : 50;
$verbose = isset($options['verbose']);

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
    'once' => $runOnce,
    'account' => $specificAccount,
    'full_backfill' => $fullBackfill,
    'page_limit' => $pageLimit,
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
            $nickname = (string)($account['nickname'] ?? "conta_{$accountId}");

            logMsg('Sincronizando pedidos', 'info', [
                'account_id' => $accountId,
                'nickname' => $nickname,
                'full_backfill' => $fullBackfill,
            ]);

            try {
                $orderService = new OrderService($accountId);
                $result = $orderService->syncOrders($accountId, $pageLimit, [
                    'full_backfill' => $fullBackfill,
                    'persist_checkpoint' => true,
                    'overlap_seconds' => 300,
                    'page_limit' => 20,
                ]);

                logMsg('Sync concluido', 'info', [
                    'account_id' => $accountId,
                    'synced' => $result['synced'] ?? 0,
                    'errors' => $result['error_count'] ?? 0,
                    'has_more' => $result['has_more'] ?? false,
                ]);

                if (!empty($result['errors'])) {
                    foreach ((array)$result['errors'] as $err) {
                        logMsg('Erro ao sincronizar pedido', 'warning', [
                            'account_id' => $accountId,
                            'order_id' => $err['order_id'] ?? '?',
                            'error' => $err['error'] ?? '?',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                logMsg('Falha ao sincronizar conta', 'error', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
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
