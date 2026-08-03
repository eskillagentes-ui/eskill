#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Account Auto Sync Worker
 *
 * Sincroniza automaticamente contas ML ativas desatualizadas (badge DESINCRONIZADA),
 * sem exigir clique manual em "Sincronizar" no dashboard.
 *
 * Uso:
 *   php bin/account-auto-sync-worker.php --once
 *   php bin/account-auto-sync-worker.php --once --account=1335
 *   php bin/account-auto-sync-worker.php --once --limit=10 --verbose
 *
 * Cron sugerido (a cada 3h, minuto 15):
 *   15 0,3,6,9,12,15,18,21 * * * cd /path/to/project && php bin/account-auto-sync-worker.php --once >> storage/logs/account-auto-sync.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Services\AccountAutoSyncService;
use App\Services\StructuredLogService;

define('WORKER_NAME', 'account-auto-sync-worker');
define('LOG_FILE', __DIR__ . '/../storage/logs/account-auto-sync.log');
define('LOCK_FILE', __DIR__ . '/../storage/locks/account-auto-sync-worker.lock');

$options = getopt('', ['once', 'account:', 'limit:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Account Auto Sync Worker — sync automático de contas ML desatualizadas\n\n"
        . "Uso: php bin/account-auto-sync-worker.php [opcoes]\n\n"
        . "  --once           Executa uma vez e sai (obrigatório no cron)\n"
        . "  --account=ID     Força sync de uma conta específica\n"
        . "  --limit=N        Máximo de contas por execução (default: 20)\n"
        . "  --verbose        Log detalhado no stdout\n"
        . "  --help           Mostra esta ajuda\n";
    exit(0);
}

$runOnce = isset($options['once']);
$specificAccount = isset($options['account']) ? (int)$options['account'] : null;
$limit = isset($options['limit']) ? max(1, min(200, (int)$options['limit'])) : 20;
$verbose = isset($options['verbose']);

if (!$runOnce) {
    fwrite(STDERR, '[' . WORKER_NAME . "] Use --once (modo loop contínuo não é suportado neste worker)\n");
    exit(1);
}

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

$logMsg = static function (string $msg, string $level = 'info', array $ctx = []) use ($verbose, $logger): void {
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
};

$logMsg('Worker iniciado', 'info', [
    'account' => $specificAccount,
    'limit' => $limit,
]);

$exitCode = 0;

try {
    $service = new AccountAutoSyncService();
    $summary = $service->run($limit, $specificAccount);

    $logMsg('Sync automatico concluido', 'info', [
        'max_age_hours' => $summary['max_age_hours'],
        'total' => $summary['total'],
        'success' => $summary['success'],
        'failed' => $summary['failed'],
        'skipped' => $summary['skipped'],
    ]);

    if ($verbose) {
        echo json_encode([
            'max_age_hours' => $summary['max_age_hours'],
            'total' => $summary['total'],
            'success' => $summary['success'],
            'failed' => $summary['failed'],
            'skipped' => $summary['skipped'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    if ((int)$summary['failed'] > 0) {
        $exitCode = 2;
    }
} catch (\Throwable $e) {
    $exitCode = 1;
    $logMsg('Erro critico no worker', 'error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

$logMsg('Worker encerrado', 'info', ['exit_code' => $exitCode]);
exit($exitCode);
