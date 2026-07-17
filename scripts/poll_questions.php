<?php

declare(strict_types=1);

/**
 * Script legado de polling de perguntas (CRON).
 * Agora delega ao orquestrador oficial ML para enfileirar sync de perguntas.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

use App\Services\MercadoLivreOrchestratorService;

try {
    $service = new MercadoLivreOrchestratorService(dirname(__DIR__));

    echo "[" . date('Y-m-d H:i:s') . "] Iniciando polling de perguntas (orquestrador ML)...\n";

    $result = $service->runPolling(
        ['questions'],
        ['questions' => 50],
        false,
        25,
        1,
        false,
        30,
        true
    );

    if (!empty($result['skipped'])) {
        echo "Execução ignorada: " . ($result['reason'] ?? 'skipped') . "\n";
        exit(0);
    }

    $questions = $result['results']['questions'] ?? [];
    echo "Contas processadas: " . (int)($questions['total_accounts'] ?? 0) . "\n";
    echo "Jobs criados: " . (int)($questions['jobs_created'] ?? 0) . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Polling de perguntas concluído.\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
