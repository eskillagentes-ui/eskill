#!/usr/bin/env php
<?php

/**
 * Clone Sync Worker
 *
 * Worker para sincronização automática de itens clonados.
 * Atualiza métricas, verifica status e sincroniza dados.
 *
 * Uso: php bin/clone-sync-worker.php [--once] [--account=ID] [--limit=N]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Services\CloneSyncService;
use App\Services\StructuredLogService;

// Parse argumentos
$options = getopt('', ['once', 'account:', 'limit:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Clone Sync Worker - Sincronização automática de itens clonados

Uso: php bin/clone-sync-worker.php [opções]

Opções:
  --once          Executa uma vez e sai (sem loop)
  --account=ID    Processa apenas uma conta específica
  --limit=N       Limite de itens por conta (default: 50)
  --help          Mostra esta ajuda

Exemplos:
  php bin/clone-sync-worker.php --once
  php bin/clone-sync-worker.php --account=123 --limit=100

HELP;
    exit(0);
}

$runOnce = isset($options['once']);
$specificAccount = isset($options['account']) ? (int) $options['account'] : null;
$limit = isset($options['limit']) ? (int) $options['limit'] : 50;

define('WORKER_NAME', 'clone-sync-worker');
define('WORKER_LOG_FILE', __DIR__ . '/../storage/logs/clone-sync-worker.log');

putenv('LOG_PATH=' . WORKER_LOG_FILE);
$logger = new StructuredLogService();

echo "=== Clone Sync Worker ===\n";
echo "Modo: " . ($runOnce ? 'Execução única' : 'Loop contínuo') . "\n";
echo "Limite por conta: $limit itens\n";
if ($specificAccount) {
    echo "Conta específica: $specificAccount\n";
}
echo str_repeat('-', 50) . "\n\n";

$logger->info('Worker started', [
    'worker' => WORKER_NAME,
    'once' => $runOnce,
    'specific_account' => $specificAccount,
    'limit' => $limit,
]);

// Acquire exclusive lock to prevent concurrent execution
$_lockDir = __DIR__ . '/../storage/locks';
if (!is_dir($_lockDir)) {
    @mkdir($_lockDir, 0755, true);
}
$_lockFile = $_lockDir . '/clone-sync-worker.lock';
$_lock = fopen($_lockFile, 'c');
if ($_lock === false || !flock($_lock, LOCK_EX | LOCK_NB)) {
    echo "[clone-sync-worker] Já em execução (lock ocupado) — saindo\n";
    if ($_lock !== false) {
        fclose($_lock);
    }
    exit(0);
}

$db = Database::getInstance();

/**
 * Obtém contas ativas com itens clonados
 */
function getActiveAccounts(PDO $db, ?int $specificAccount): array
{
    $query = "
        SELECT DISTINCT target_account_id as account_id
        FROM cloned_items
        WHERE status = 'completed'
    ";

    if ($specificAccount) {
        $query .= " AND target_account_id = :account_id";
    }

    $query .= " ORDER BY target_account_id";

    $stmt = $db->prepare($query);
    if ($specificAccount) {
        $stmt->execute(['account_id' => $specificAccount]);
    } else {
        $stmt->execute();
    }

    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'account_id');
}

/**
 * Executa uma operação com retry/backoff para erros transientes.
 *
 * @template T
 * @param callable():T $fn
 * @return T
 */
function withRetry(callable $fn)
{
    $delays = [1, 2, 4];
    $attempt = 0;

    while (true) {
        try {
            return $fn();
        } catch (Throwable $e) {
            if ($attempt >= count($delays)) {
                throw $e;
            }

            $sleep = $delays[$attempt];
            $attempt++;
            sleep($sleep);
        }
    }
}

/**
 * Processa sincronização de uma conta
 */
function syncAccount(int $accountId, int $limit): array
{
    global $logger;

    echo "[" . date('H:i:s') . "] Sincronizando conta $accountId...\n";

    $startTime = microtime(true);

    try {
        $logger->info('Sync account started', ['worker' => WORKER_NAME, 'account_id' => $accountId, 'limit' => $limit]);

        $result = withRetry(function () use ($accountId, $limit): array {
            $sync = new CloneSyncService($accountId);
            return $sync->syncAll([
                'limit' => $limit,
                'days' => 30,
                'types' => ['price', 'stock', 'status', 'metrics']
            ]);
        });

        $elapsed = round(microtime(true) - $startTime, 2);

        echo "  → Total: {$result['total']}, Sincronizados: {$result['synced']}, Erros: {$result['errors']}\n";
        echo "  → Tempo: {$elapsed}s\n";

        $logger->info('Sync account finished', [
            'worker' => WORKER_NAME,
            'account_id' => $accountId,
            'elapsed_s' => $elapsed,
            'result' => [
                'total' => $result['total'] ?? null,
                'synced' => $result['synced'] ?? null,
                'errors' => $result['errors'] ?? null,
                'skipped' => $result['skipped'] ?? null,
            ],
        ]);

        return $result;
    } catch (Exception $e) {
        echo "  [ERRO] " . $e->getMessage() . "\n";

        $logger->error('Sync account error', [
            'worker' => WORKER_NAME,
            'account_id' => $accountId,
            'error' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Registra métricas de execução
 */
function logExecution(PDO $db, array $stats): void
{
    global $logger;
    try {
        $stmt = $db->prepare("
            INSERT INTO worker_execution_logs (
                worker_name, stats, executed_at
            ) VALUES (
                'clone-sync-worker', :stats, NOW()
            )
        ");
        $stmt->execute(['stats' => json_encode($stats)]);
    } catch (PDOException $e) {
        // Tabela pode não existir
        $logger->warning('Failed to persist worker_execution_logs', [
            'worker' => WORKER_NAME,
            'error' => $e->getMessage(),
        ]);
    }
}

// Loop principal
$iteration = 0;
$maxIterations = $runOnce ? 1 : PHP_INT_MAX;

while ($iteration < $maxIterations) {
    $iteration++;

    if (!$runOnce) {
        echo "\n[" . date('Y-m-d H:i:s') . "] Iteração #$iteration\n";
    }

    $accounts = getActiveAccounts($db, $specificAccount);

    if (empty($accounts)) {
        echo "Nenhuma conta com itens clonados encontrada.\n";
        if ($runOnce) break;
        sleep(300); // 5 minutos
        continue;
    }

    echo "Contas para processar: " . count($accounts) . "\n\n";

    $totalStats = [
        'accounts' => count($accounts),
        'total_items' => 0,
        'total_synced' => 0,
        'total_errors' => 0,
        'started_at' => date('c'),
        'finished_at' => null
    ];

    foreach ($accounts as $accountId) {
        $result = syncAccount((int) $accountId, $limit);

        $totalStats['total_items'] += ($result['total'] ?? 0);
        $totalStats['total_synced'] += ($result['synced'] ?? 0);
        $totalStats['total_errors'] += ($result['errors'] ?? 0);

        // Pequena pausa entre contas
        usleep(500000); // 500ms
    }

    $totalStats['finished_at'] = date('c');

    // Registrar execução
    logExecution($db, $totalStats);

    echo "\n[Resumo] Items: {$totalStats['total_items']}, Sincronizados: {$totalStats['total_synced']}, Erros: {$totalStats['total_errors']}\n";
    $logger->info('Iteration finished', ['worker' => WORKER_NAME, 'stats' => $totalStats]);

    if ($runOnce) {
        break;
    }

    // Intervalo entre execuções (15 minutos)
    echo "\nPróxima execução em 15 minutos...\n";
    sleep(900);
}

echo "\n=== Worker finalizado ===\n";
$logger->info('Worker finished', ['worker' => WORKER_NAME]);
flock($_lock, LOCK_UN);
fclose($_lock);
