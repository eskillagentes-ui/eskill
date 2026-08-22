#!/usr/bin/env php
<?php
/**
 * Item Performance Sync Worker
 *
 * Sincroniza o score oficial de qualidade do Mercado Livre (GET /item/{id}/performance)
 * para a tabela local de itens, permitindo que dashboards (como SEO Killer)
 * usem o score real sem fazer requisições síncronas pesadas.
 *
 * Read-only na API ML:
 *   GET /item/{id}/performance  → performance_*
 *   GET /items/{id}/visits/time_window → visits_30d / _visits_30d
 * Não escreve anúncios. 403 fail-soft: não grava visita 0 (fica pending).
 * Escopo por account_id (1335 e 1336 separados).
 *
 * Uso:
 *   php bin/item-performance-sync-worker.php                    # Contas ativas
 *   php bin/item-performance-sync-worker.php --account=ID       # Conta específica
 *   php bin/item-performance-sync-worker.php --limit=50         # Limitar itens por conta
 *   php bin/item-performance-sync-worker.php --verbose          # Output detalhado
 *
 * Cron (catálogo ~115 ativos, algumas voltas/dia, < ~2 min/corrida):
 *   15 * * * * php bin/item-performance-sync-worker.php --limit=120 >> storage/logs/item-performance-sync.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Services\HiddenSeo\ItemLocalMetricsMerger;
use App\Services\HiddenSeo\ItemPerformanceService;

$options = getopt('', ['account:', 'limit:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Item Performance Sync Worker\n\n";
    echo "Uso: php bin/item-performance-sync-worker.php [opcoes]\n\n";
    echo "  --account=ID     Processa apenas uma conta específica\n";
    echo "  --limit=N        Número máximo de itens por conta (padrão: 120)\n";
    echo "  --verbose        Log detalhado no stdout\n";
    echo "  Grava visits_30d só se GET /items/{id}/visits/time_window responder OK.\n";
    echo "  403/erro: não escreve 0 (Pregão trata como pending).\n";
    exit(0);
}

$accountId = isset($options['account']) ? (int)$options['account'] : null;
$limit = isset($options['limit']) ? (int)$options['limit'] : 120;
$verbose = isset($options['verbose']);
$delayUs = 250000; // 250ms entre GETs — abaixo do burst, cabe < ~2 min com limit=120
$maxRuntimeSeconds = 110;

if ($limit < 1) {
    $limit = 120;
}

echo "\n📈 Item Performance Sync Worker\n";
echo str_repeat("=", 60) . "\n";

try {
    $db = Database::getInstance();

    if ($accountId) {
        $stmt = $db->prepare("SELECT id, nickname FROM ml_accounts WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $accountId]);
    } else {
        $stmt = $db->query("SELECT id, nickname FROM ml_accounts WHERE status = 'active'");
    }

    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($accounts)) {
        echo "Nenhuma conta ativa encontrada.\n";
        exit(0);
    }

    $startedAt = microtime(true);

    foreach ($accounts as $account) {
        $accId = (int) $account['id'];
        $accName = $account['nickname'] ?? "Conta #{$accId}";

        echo "\n🔄 Processando {$accName} (ID: {$accId})...\n";

        if (str_contains(__DIR__, 'staging.eskill.com.br') && $accId === 1335) {
            echo "  staging workers must not point at FACILYTY 1335\n";
            continue;
        }

        if ((microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
            echo "  Tempo máximo de corrida atingido ({$maxRuntimeSeconds}s); restante fica para a próxima hora.\n";
            break;
        }

        // Ativos: performance ausente/stale OU visits_30d ausente/stale. Nunca mistura contas.
        $stmt = $db->prepare("
            SELECT ml_item_id, data
            FROM items
            WHERE account_id = :account_id
            AND status = 'active'
            AND (
                JSON_EXTRACT(data, '$.performance_score') IS NULL
                OR JSON_EXTRACT(data, '$.performance_updated_at') IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(data, '$.performance_updated_at')) < DATE_SUB(NOW(), INTERVAL 7 DAY)
                OR JSON_EXTRACT(data, '$.visits_30d') IS NULL
                OR JSON_EXTRACT(data, '$._visits_30d') IS NULL
                OR JSON_EXTRACT(data, '$.visits_updated_at') IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(data, '$.visits_updated_at')) < DATE_SUB(NOW(), INTERVAL 7 DAY)
            )
            ORDER BY
                CASE WHEN JSON_EXTRACT(data, '$.visits_30d') IS NULL THEN 0 ELSE 1 END ASC,
                CASE WHEN JSON_EXTRACT(data, '$.performance_score') IS NULL THEN 0 ELSE 1 END ASC,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.visits_updated_at')), '1970-01-01') ASC,
                ml_item_id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':account_id', $accId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            echo "  Nenhum item precisando de sync de performance/visitas.\n";
            continue;
        }

        $performanceService = new ItemPerformanceService($accId);
        $syncedCount = 0;
        $visitsSynced = 0;
        $errorCount = 0;
        $visitsPending = 0;
        $stoppedForTime = false;

        foreach ($items as $item) {
            if ((microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
                $stoppedForTime = true;
                break;
            }

            $itemId = (string) $item['ml_item_id'];
            $data = json_decode($item['data'] ?? '{}', true) ?: [];
            if (!is_array($data)) {
                $data = [];
            }
            $originalJson = json_encode($data, JSON_UNESCAPED_UNICODE);

            if (ItemLocalMetricsMerger::needsPerformance($data)) {
                if ($verbose) {
                    echo "  - performance {$itemId}... ";
                }
                $result = $performanceService->getItemPerformance($itemId);
                if ($result['success']) {
                    $data = ItemLocalMetricsMerger::applyPerformance($data, $result);
                    if ($verbose) {
                        echo "OK (Score: {$result['score']})\n";
                    }
                } else {
                    if ($verbose) {
                        echo "ERRO ({$result['error']}) fail-soft\n";
                    }
                    $errorCount++;
                }
                usleep($delayUs);
            }

            if ((microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
                $stoppedForTime = true;
            }

            if (!$stoppedForTime && ItemLocalMetricsMerger::needsVisits30d($data)) {
                if ($verbose) {
                    echo "  - visits_30d {$itemId}... ";
                }
                $visitsResult = $performanceService->getItemVisits30d($itemId);
                if ($visitsResult['success']) {
                    $before = $data;
                    $data = ItemLocalMetricsMerger::applyVisits30d($data, $visitsResult);
                    if ($data !== $before) {
                        $visitsSynced++;
                    }
                    if ($verbose) {
                        echo "OK (visits_30d={$visitsResult['visits']})\n";
                    }
                } else {
                    $visitsPending++;
                    if ($verbose) {
                        echo "PENDENTE ({$visitsResult['error']}) sem gravar 0\n";
                    }
                }
                usleep($delayUs);
            }

            $newJson = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_string($newJson) && $newJson !== $originalJson) {
                $updateStmt = $db->prepare("UPDATE items SET data = :data WHERE ml_item_id = :id AND account_id = :account_id");
                $updateStmt->execute([
                    'data' => $newJson,
                    'id' => $itemId,
                    'account_id' => $accId,
                ]);
                $syncedCount++;
            }
        }

        echo "  Concluído: {$syncedCount} itens gravados, {$visitsSynced} visits_30d, {$visitsPending} visitas pending (sem 0), {$errorCount} erros performance.\n";
        if ($stoppedForTime) {
            echo "  Tempo máximo de corrida atingido ({$maxRuntimeSeconds}s); restante fica para a próxima hora.\n";
            break;
        }
    }

    echo "\n✅ Worker finalizado.\n";

} catch (\Throwable $e) {
    echo "\n❌ Erro fatal: " . $e->getMessage() . "\n";
    exit(1);
}
