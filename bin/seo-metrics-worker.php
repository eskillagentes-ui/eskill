#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SEO Metrics Worker — Coleta métricas de performance da API do Mercado Livre
 *
 * Popula a tabela seo_performance_metrics com dados reais de visitas e vendas.
 * Deve rodar diariamente via cron para alimentar os dashboards de analytics.
 *
 * Uso:
 *   php bin/seo-metrics-worker.php                    # Coleta do dia (todas as contas)
 *   php bin/seo-metrics-worker.php --days=7           # Últimos 7 dias
 *   php bin/seo-metrics-worker.php --account=1        # Conta específica
 *   php bin/seo-metrics-worker.php --account=1 --days=30  # Histórico completo, 1 conta
 *   php bin/seo-metrics-worker.php --max-items=100    # Limitar itens processados
 *   php bin/seo-metrics-worker.php --verbose          # Saída detalhada
 *   php bin/seo-metrics-worker.php --help             # Ajuda
 *
 * Crontab (execução diária às 6h):
 *   0 6 * * * php /home/eskill/htdocs/eskill.com.br/bin/seo-metrics-worker.php >> /home/eskill/htdocs/eskill.com.br/storage/logs/seo-metrics.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

if (file_exists(__DIR__ . '/../app/Helpers/LogHelper.php')) {
    require_once __DIR__ . '/../app/Helpers/LogHelper.php';
}

use App\Services\MercadoLivre\SEOMetricsCollectorService;

// Parse arguments
$options = getopt('', ['account:', 'days:', 'max-items:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP

  SEO Metrics Worker - Coleta de Metricas ML

  Opcoes:
    --account=ID    Conta ML especifica
    --days=N        Dias de historico (1-30, padrao: 1)
    --max-items=N   Maximo de itens (padrao: 500)
    --verbose       Saida detalhada
    --help          Esta mensagem

  Endpoints ML usados:
    GET /visits/items?ids=...  (visitas em batch)
    GET /items?ids=...         (detalhes multi-get)
    GET /users/{id}/items/search (listagem de itens)

HELP;
    exit(0);
}

$accountId = isset($options['account']) ? (int)$options['account'] : null;
$days = isset($options['days']) ? (int)$options['days'] : 1;
$maxItems = isset($options['max-items']) ? (int)$options['max-items'] : 500;
$verbose = isset($options['verbose']);

$days = min(max(1, $days), 30);

echo "\n======================================================\n";
echo "  eskill.com.br - SEO Metrics Collector\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "======================================================\n\n";

echo "Config:\n";
echo "   Conta: " . ($accountId !== null ? "#{$accountId}" : "Todas") . "\n";
echo "   Periodo: ultimos {$days} dia(s)\n";
echo "   Max itens: {$maxItems}\n";
echo "   Verbose: " . ($verbose ? 'Sim' : 'Nao') . "\n\n";

// Verificar DB
try {
    $db = \App\Database::getInstance();
    echo "[OK] MySQL conectado\n";
} catch (\Exception $e) {
    echo "[ERRO] MySQL falhou: " . $e->getMessage() . "\n";
    exit(1);
}

$startTime = microtime(true);
$totalStats = [
    'accounts_processed' => 0,
    'total_items' => 0,
    'total_metrics' => 0,
    'total_errors' => 0,
];

if ($accountId !== null) {
    // Conta especifica
    echo "\n-- Coletando metricas para conta #{$accountId} --\n";
    try {
        $collector = new SEOMetricsCollectorService($accountId);
        $collector->setMaxItemsPerRun($maxItems);
        $stats = $collector->collect($days, $verbose);

        $totalStats['accounts_processed'] = 1;
        $totalStats['total_items'] = $stats['items_processed'];
        $totalStats['total_metrics'] = $stats['metrics_saved'];
        $totalStats['total_errors'] = $stats['errors'];

        if ($verbose) {
            echo "\n   Stats: " . json_encode($stats, JSON_PRETTY_PRINT) . "\n";
        }
    } catch (\Throwable $e) {
        echo "[ERRO] " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // Todas as contas
    $allResults = SEOMetricsCollectorService::collectAllAccounts($days, $verbose || true);

    foreach ($allResults as $accId => $stats) {
        $totalStats['accounts_processed']++;
        if (isset($stats['error'])) {
            $totalStats['total_errors']++;
        } else {
            $totalStats['total_items'] += $stats['items_processed'] ?? 0;
            $totalStats['total_metrics'] += $stats['metrics_saved'] ?? 0;
            $totalStats['total_errors'] += $stats['errors'] ?? 0;
        }
    }
}

$durationSec = round(microtime(true) - $startTime, 1);

echo "\n======================================================\n";
echo "  Resumo da coleta:\n";
echo "     Contas processadas: {$totalStats['accounts_processed']}\n";
echo "     Itens processados:  {$totalStats['total_items']}\n";
echo "     Metricas salvas:    {$totalStats['total_metrics']}\n";
echo "     Erros:              {$totalStats['total_errors']}\n";
echo "     Duracao:            {$durationSec}s\n";
echo "======================================================\n\n";

if ($totalStats['total_metrics'] > 0) {
    echo "[OK] Metricas coletadas! Os dashboards de performance agora tem dados reais.\n";
    echo "   -> Dashboard: /api/seo-killer/performance/dashboard\n";
    echo "   -> Evolucao:  /api/seo-killer/performance/evolution\n\n";
} elseif ($totalStats['total_items'] === 0) {
    echo "[AVISO] Nenhum item encontrado.\n";
    echo "   1. Verifique se ha contas ML ativas: php bin/auth-status.php\n";
    echo "   2. Sincronize itens: php bin/collect-ml-data.php --items\n";
    echo "   3. Rode novamente: php bin/seo-metrics-worker.php --verbose\n\n";
} else {
    echo "[AVISO] Itens encontrados mas nenhuma metrica salva.\n";
    echo "   Rode com --verbose para detalhes.\n\n";
}

exit($totalStats['total_errors'] > 10 ? 1 : 0);
