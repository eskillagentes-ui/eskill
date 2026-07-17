<?php

declare(strict_types=1);

/**
 * Script legado de polling de pedidos (CRON).
 * Delegado ao orquestrador oficial ML para enfileirar syncs e processar fila.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

use App\Services\MercadoLivreOrchestratorService;

try {
    $service = new MercadoLivreOrchestratorService(dirname(__DIR__));

    echo "[" . date('Y-m-d H:i:s') . "] Iniciando polling de pedidos (orquestrador ML)...\n";

    $result = $service->runPolling(
        ['orders'],
        ['orders' => 100],
        true,
        50,
        1,
        true,
        30,
        true
    );

    if (!empty($result['skipped'])) {
        echo "Execução ignorada: " . ($result['reason'] ?? 'skipped') . "\n";
        exit(0);
    }

    $orders = $result['results']['orders'] ?? [];
    $jobsCreated = (int)($orders['jobs_created'] ?? 0);
    $totalAccounts = (int)($orders['total_accounts'] ?? 0);
    $queue = is_array($result['queue'] ?? null) ? $result['queue'] : [];
    $jobsProcessed = (int)($queue['jobs_processed'] ?? 0);
    $deletedJobs = (int)($queue['deleted_jobs'] ?? 0);

    echo "Contas processadas: {$totalAccounts}\n";
    echo "Jobs criados: {$jobsCreated}\n";
    echo "Jobs processados: {$jobsProcessed}\n";
    if ($deletedJobs > 0) {
        echo "Jobs antigos removidos: {$deletedJobs}\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Polling concluído com sucesso.\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
