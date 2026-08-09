#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reprice Simulate — Fase 0 do repricing automático (READ-ONLY).
 *
 * Roda a simulação de repricing sobre os itens com auto_reprice=1 de uma conta,
 * calculando o preço que o SniperAgent sugeriria SEM escrever nada (nem no ML,
 * nem no banco). Gera relatório JSON em storage/reports/.
 *
 * Uso:
 *   php bin/reprice-simulate.php --account=1335
 *   php bin/reprice-simulate.php --account=1335 --limit=3
 *   php bin/reprice-simulate.php --account=1335 --json
 *
 * Exit codes: 0 = ok (ou outra instância em execução), 1 = erro fatal, 2 = uso inválido.
 *
 * Spec: .github/prompts/repricing-automatico.prompt.md
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

use App\Database;
use App\Services\Pricing\RepriceSimulationService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

define('WORKER_NAME', 'reprice-simulate');

$opts = getopt('', ['account:', 'limit:', 'json', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__) ?: "reprice-simulate\n");
    exit(0);
}

if (!isset($opts['account']) || (int)$opts['account'] <= 0) {
    fwrite(STDERR, "Uso: php bin/reprice-simulate.php --account=ID [--limit=N] [--json]\n");
    exit(2);
}

$accountId = (int)$opts['account'];
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : null;
$asJson = isset($opts['json']);

$lockFile = dirname(__DIR__) . '/storage/locks/reprice-simulate-' . $accountId . '.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, '[' . WORKER_NAME . "] Outra instância em execução — saindo\n");
    if ($lockHandle !== false) {
        fclose($lockHandle);
    }
    exit(0);
}

try {
    $logger = new Logger('reprice-simulation');
    $logger->pushHandler(new StreamHandler(dirname(__DIR__) . '/storage/logs/reprice-simulation.log'));

    // Provider de dados de mercado: CompetitorSpy (somente leitura via API oficial),
    // com delay entre chamadas para respeitar rate limit do ML.
    $marketDataProvider = static function (string $title, int $accountId): array {
        usleep(300000); // 300ms entre consultas
        return (new \App\Services\AI\SEO\CompetitorSpy($accountId))->spyProduct($title, 15);
    };

    $service = new RepriceSimulationService(
        Database::getInstance(),
        $marketDataProvider,
        null,
        $logger
    );

    $report = $service->simulate($accountId, $limit);

    // Relatório em arquivo
    $reportsDir = dirname(__DIR__) . '/storage/reports';
    if (!is_dir($reportsDir)) {
        @mkdir($reportsDir, 0755, true);
    }
    $reportFile = $reportsDir . '/reprice-simulation-' . date('Ymd-His') . '.json';
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($reportFile, $json) === false) {
        throw new RuntimeException('Falha ao gravar relatório em ' . $reportFile);
    }

    if ($asJson) {
        fwrite(STDOUT, $json . PHP_EOL);
    } else {
        $s = $report['summary'];
        fwrite(STDOUT, "=== Reprice Simulation (READ-ONLY) ===" . PHP_EOL);
        fwrite(STDOUT, "Conta: {$accountId}" . ($report['safety']['forbidden_account'] ? ' [FORBIDDEN_ACCOUNTS — apply bloqueado]' : '') . PHP_EOL);
        fwrite(STDOUT, "Tetos: max_delta={$report['config']['reprice_max_pct']}% | max_itens={$report['config']['reprice_max_items_per_run']} | margem_min={$report['config']['reprice_min_margin_pct']}%" . PHP_EOL);
        fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
        foreach ($report['items'] as $item) {
            $linha = sprintf(
                '  %s | atual R$ %.2f | sugerido %s | delta %s | %s',
                $item['item_id'],
                $item['preco_atual'],
                $item['preco_sugerido'] !== null ? 'R$ ' . number_format((float)$item['preco_sugerido'], 2, '.', '') : '-',
                $item['delta_pct'] !== null ? number_format((float)$item['delta_pct'], 2, '.', '') . '%' : '-',
                $item['seria_aplicado'] ? 'APLICARIA' : 'skip: ' . $item['motivo_skip']
            );
            fwrite(STDOUT, $linha . PHP_EOL);
        }
        fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
        fwrite(STDOUT, "Candidatos: {$s['candidatos']} | Seria aplicado: {$s['seria_aplicado']} | Skipped: {$s['skipped']}" . PHP_EOL);
        fwrite(STDOUT, "Relatório: {$reportFile}" . PHP_EOL);
    }

    $logger->info('Simulation run finished', [
        'worker' => WORKER_NAME,
        'account_id' => $accountId,
        'report_file' => $reportFile,
        'summary' => $report['summary'],
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERRO FATAL: {$e->getMessage()}\n");
    exit(1);
} finally {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit(0);
