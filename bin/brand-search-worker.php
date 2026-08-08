#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Brand Search Worker — Módulo 20 BRAND-003
 *
 * Consome a fila de buscas pendentes e executa BrandSearchService::executeSearch().
 *
 * Uso:
 *   php bin/brand-search-worker.php                 # processa todos pending (execução única)
 *   php bin/brand-search-worker.php --search-id=42  # processa busca específica
 *   php bin/brand-search-worker.php --daemon        # loop contínuo (sleep 10s entre ciclos)
 *   php bin/brand-search-worker.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Models\BrandSearchModel;
use App\Services\MercadoLivre\BrandSearchService;
use App\Services\StructuredLogService;

set_time_limit(0);
ini_set('memory_limit', '512M');

define('WORKER_NAME', 'brand-search-worker');
define('LOG_FILE',  __DIR__ . '/../storage/logs/brand-search-worker.log');
define('LOCK_FILE', __DIR__ . '/../storage/locks/brand-search-worker.lock');
define('DAEMON_SLEEP', 10);

// ── CLI Options ───────────────────────────────────────────────────────────────
$options = getopt('', ['search-id:', 'daemon', 'help']);

if (isset($options['help'])) {
    echo "Brand Search Worker — Módulo 20 BRAND-003\n\n"
        . "Uso: php bin/brand-search-worker.php [opcoes]\n\n"
        . "  (sem args)          Processa toda a fila pending e sai\n"
        . "  --search-id=N       Processa a busca com ID=N e sai\n"
        . "  --daemon            Loop contínuo; sleep " . DAEMON_SLEEP . "s entre ciclos\n"
        . "  --help              Esta ajuda\n";
    exit(0);
}

$specificSearchId = isset($options['search-id']) ? (int)$options['search-id'] : null;
$isDaemon         = isset($options['daemon']);

// ── Flock: prevenir execução concorrente ──────────────────────────────────────
$lockDir = dirname(LOCK_FILE);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = fopen(LOCK_FILE, 'c');
$lockAcquired = $lockHandle !== false && flock($lockHandle, LOCK_EX | LOCK_NB);
if (!$lockAcquired) {
    echo '[' . WORKER_NAME . "] Outra instância já está em execução — saindo\n";
    if ($lockHandle !== false) {
        fclose($lockHandle);
    }
    exit(0);
}

// ── Logger ────────────────────────────────────────────────────────────────────
putenv('LOG_PATH=' . LOG_FILE);
$logger = new StructuredLogService();

function logMsg(string $msg, string $level = 'info', array $ctx = []): void
{
    global $logger;
    $ctx = array_merge(['worker' => WORKER_NAME], $ctx);
    $ts  = date('Y-m-d H:i:s');
    $ctxStr = empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
    echo "[{$ts}] [" . strtoupper($level) . "] {$msg}{$ctxStr}\n";
    try {
        if (method_exists($logger, $level)) {
            $logger->{$level}($msg, $ctx);
        } else {
            $logger->info($msg, $ctx);
        }
    } catch (\Throwable) {
        // logger failure must never stop the worker
    }
}

// ── Process a single search ID ────────────────────────────────────────────────
function processSearch(int $searchId): void
{
    logMsg('Iniciando execução', 'info', ['search_id' => $searchId]);
    try {
        $search    = (new BrandSearchModel())->getSearch($searchId);
        $accountId = isset($search['account_id']) ? (int) $search['account_id'] : null;
        $service   = new BrandSearchService($accountId);
        $service->executeSearch($searchId);
        logMsg('Execução concluída com sucesso', 'info', ['search_id' => $searchId]);
    } catch (\Throwable $e) {
        logMsg('Execução falhou — status definido como failed', 'error', [
            'search_id' => $searchId,
            'error'     => $e->getMessage(),
        ]);
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────
logMsg('Worker iniciado', 'info', [
    'mode'      => $specificSearchId !== null ? 'single' : ($isDaemon ? 'daemon' : 'queue'),
    'search_id' => $specificSearchId,
]);

// Single-search mode
if ($specificSearchId !== null) {
    processSearch($specificSearchId);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    logMsg('Worker encerrado', 'info');
    exit(0);
}

// Queue / daemon mode
do {
    try {
        $model   = new BrandSearchModel();
        $pending = $model->getPendingSearches();

        if (empty($pending)) {
            logMsg('Nenhuma busca pendente', 'info');
        } else {
            logMsg('Buscas pendentes encontradas', 'info', ['count' => count($pending)]);
            foreach ($pending as $row) {
                processSearch((int)$row['id']);
            }
        }
    } catch (\Throwable $e) {
        logMsg('Erro crítico no ciclo', 'error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    if (!$isDaemon) {
        break;
    }

    logMsg('Aguardando próximo ciclo', 'info', ['sleep_s' => DAEMON_SLEEP]);
    sleep(DAEMON_SLEEP);

} while (true);

// Cleanup
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
logMsg('Worker encerrado', 'info');
exit(0);
