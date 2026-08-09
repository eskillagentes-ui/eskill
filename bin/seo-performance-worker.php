#!/usr/bin/env php
<?php
/**
 * 📈 SEO Performance Worker - Coleta de Métricas de Performance
 * 
 * Coleta métricas diárias (views, visitas, vendas, receita) dos itens
 * que foram otimizados pelo SEO Killer, permitindo acompanhar o ROI
 * das otimizações.
 * 
 * Uso:
 *   php bin/seo-performance-worker.php                    # Todas as contas
 *   php bin/seo-performance-worker.php --account=ID       # Conta específica
 *   php bin/seo-performance-worker.php --limit=50         # Limitar itens por conta
 *   php bin/seo-performance-worker.php --verbose          # Output detalhado
 *   php bin/seo-performance-worker.php --dry-run          # Apenas mostra o que faria
 *   php bin/seo-performance-worker.php --help             # Exibe ajuda
 * 
 * Cron recomendado (diariamente às 04:00):
 *   0 4 * * * php bin/seo-performance-worker.php >> storage/logs/seo-performance.log 2>&1
 * 
 * @package App\Bin
 */

declare(strict_types=1);

// Prevent web access
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

// Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            putenv($line);
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Services\AI\SEO\PerformanceTracker;

// ============================================================================
// Configuration
// ============================================================================

const DEFAULT_LIMIT = 100;       // Itens por conta por execução
const RATE_LIMIT_DELAY_MS = 300; // ms entre chamadas à API ML

// ============================================================================
// Helper Functions
// ============================================================================

function logMessage(string $level, string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$level}] {$message}\n";
}

function info(string $message): void
{
    logMessage('INFO', $message);
}

function error(string $message): void
{
    logMessage('ERROR', $message);
}

function success(string $message): void
{
    logMessage('OK', $message);
}

function showHelp(): void
{
    echo <<<HELP

📈 SEO Performance Worker - Coleta de Métricas

Uso:
  php bin/seo-performance-worker.php [opções]

Opções:
  --account=ID   Processa apenas uma conta específica
  --limit=N      Número máximo de itens por conta (padrão: 100)
  --verbose      Output detalhado com métricas de cada item
  --dry-run      Não coleta métricas, apenas lista o que faria
  --help         Exibe esta ajuda

Exemplos:
  php bin/seo-performance-worker.php                       # Todas as contas
  php bin/seo-performance-worker.php --account=5           # Conta específica
  php bin/seo-performance-worker.php --limit=20 --verbose  # 20 itens, verbose

HELP;
}

// ============================================================================
// Parse Arguments
// ============================================================================

$accountId = null;
$limit = DEFAULT_LIMIT;
$verbose = false;
$dryRun = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--account=') === 0) {
        $accountId = (int) str_replace('--account=', '', $arg);
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = (int) str_replace('--limit=', '', $arg);
    } elseif ($arg === '--verbose') {
        $verbose = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--help') {
        showHelp();
        exit(0);
    }
}

// ============================================================================
// Main
// ============================================================================

echo "\n📈 SEO Performance Worker\n";
echo str_repeat("=", 60) . "\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n";
echo "Limite: {$limit} itens/conta\n";
if ($dryRun) {
    echo "⚠️  Modo DRY RUN (sem coletar métricas)\n";
}
echo str_repeat("=", 60) . "\n\n";

try {
    $db = Database::getInstance();

    // Get active accounts
    if ($accountId) {
        $stmt = $db->prepare("SELECT id, nickname FROM ml_accounts WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $accountId]);
    } else {
        $stmt = $db->query("SELECT id, nickname FROM ml_accounts WHERE status = 'active'");
    }

    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($accounts)) {
        info("Nenhuma conta ativa encontrada");
        exit(0);
    }

    info("Processando " . count($accounts) . " conta(s)");

    $totalCollected = 0;
    $totalErrors = 0;
    $totalSkipped = 0;

    foreach ($accounts as $account) {
        $accId = (int) $account['id'];
        $accName = $account['nickname'] ?? "Conta #{$accId}";

        $limitSql = max(1, min(500, (int)$limit));

        echo "\n🔄 Processando {$accName} (ID: {$accId})...\n";

        try {
            // Get optimized items for this account (items that have optimization events)
            $stmt = $db->prepare("
                SELECT DISTINCT oe.item_id 
                FROM seo_optimization_events oe
                WHERE oe.account_id = :account_id
                ORDER BY oe.optimized_at DESC
                LIMIT {$limitSql}
            ");
            $stmt->bindValue(':account_id', $accId, PDO::PARAM_INT);
            $stmt->execute();
            $optimizedItems = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Also get items that already have metrics tracked (ongoing monitoring)
            $stmt = $db->prepare("
                SELECT DISTINCT item_id 
                FROM seo_performance_metrics
                WHERE account_id = :account_id
                AND metric_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                AND item_id NOT IN (
                    SELECT DISTINCT item_id FROM seo_optimization_events WHERE account_id = :account_id2
                )
                LIMIT {$limitSql}
            ");
            $stmt->bindValue(':account_id', $accId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id2', $accId, PDO::PARAM_INT);
            $stmt->execute();
            $trackedItems = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $allItems = array_unique(array_merge($optimizedItems, $trackedItems));

            if (empty($allItems)) {
                info("  Nenhum item otimizado/monitorado encontrado");
                $totalSkipped++;
                continue;
            }

            echo "  📋 " . count($allItems) . " itens para coletar métricas\n";

            if ($dryRun) {
                foreach ($allItems as $itemId) {
                    echo "    • {$itemId}\n";
                }
                continue;
            }

            $tracker = new PerformanceTracker($accId);
            $accountCollected = 0;
            $accountErrors = 0;

            foreach ($allItems as $itemId) {
                try {
                    $metrics = $tracker->collectItemMetrics($itemId);

                    if (isset($metrics['error'])) {
                        if ($verbose) {
                            echo "    ⚠️  {$itemId}: {$metrics['error']}\n";
                        }
                        $accountErrors++;
                    } else {
                        $accountCollected++;
                        if ($verbose) {
                            $views = $metrics['views'] ?? 0;
                            $sales = $metrics['sold_quantity'] ?? 0;
                            echo "    ✅ {$itemId}: views={$views}, vendas={$sales}\n";
                        }
                    }

                    // Rate limiting
                    usleep(RATE_LIMIT_DELAY_MS * 1000);
                } catch (\Exception $e) {
                    $accountErrors++;
                    if ($verbose) {
                        echo "    ❌ {$itemId}: " . $e->getMessage() . "\n";
                    }
                }
            }

            success("{$accName}: {$accountCollected} coletados, {$accountErrors} erros");
            $totalCollected += $accountCollected;
            $totalErrors += $accountErrors;
        } catch (\Exception $e) {
            error("Erro na conta {$accName}: " . $e->getMessage());
            $totalErrors++;
        }
    }

    // Summary
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "📊 RESUMO\n";
    echo str_repeat("=", 60) . "\n";
    echo "Contas processadas: " . count($accounts) . "\n";
    echo "Métricas coletadas: {$totalCollected}\n";
    echo "Erros: {$totalErrors}\n";
    echo "Contas sem itens: {$totalSkipped}\n";
    echo str_repeat("=", 60) . "\n\n";

    exit($totalErrors > 0 ? 1 : 0);
} catch (\Exception $e) {
    error("ERRO CRÍTICO: " . $e->getMessage());
    exit(1);
}
