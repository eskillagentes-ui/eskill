<?php

/**
 * ML ↔ AI Optimization Worker
 *
 * Background worker for automated SEO optimization of Mercado Livre listings.
 *
 * Usage:
 *   php bin/ml-ai-optimization-worker.php --account=1               # Optimize all items for account 1
 *   php bin/ml-ai-optimization-worker.php --account=1 --item=MLB123 # Optimize specific item
 *   php bin/ml-ai-optimization-worker.php --account=1 --auto-apply  # Optimize AND apply changes
 *   php bin/ml-ai-optimization-worker.php --account=1 --limit=10    # Limit to 10 items
 *   php bin/ml-ai-optimization-worker.php --account=1 --dry-run     # Preview only, no API writes
 *   php bin/ml-ai-optimization-worker.php --help                    # Show help
 *
 * The worker:
 *  1. Lists active seller items via ML API
 *  2. Scores each item's SEO quality
 *  3. Optimizes items below threshold using AI (with template fallback)
 *  4. Optionally applies changes back to ML listings
 *  5. Logs all operations with Monolog
 *
 * Recommended cron: every 6 hours
 *   0 * /6 * * * php /path/to/bin/ml-ai-optimization-worker.php --account=1 --limit=20 >> /path/to/storage/logs/ml-ai-worker.log 2>&1
 *
 * @package App\Bin
 */

declare(strict_types=1);

// CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

// Load .env
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

\App\Helpers\MlWriteAutomation::exitIfDisabledForCli(basename(__FILE__));

use App\Services\MercadoLivre\MercadoLivreAIIntegrationService;

// ============================================================================
// Configuration
// ============================================================================

const RATE_LIMIT_DELAY_MS = 500;      // ms between API calls
const DEFAULT_LIMIT = 20;             // Default items per run
const MAX_LIMIT = 100;                // Max items per run
const SEO_SCORE_THRESHOLD = 70;       // Items below this score get optimized
const WORKER_VERSION = '1.0.0';

// ============================================================================
// CLI Argument Parsing
// ============================================================================

$opts = getopt('', [
    'account:',
    'item:',
    'limit:',
    'auto-apply',
    'dry-run',
    'help',
    'verbose',
    'category:',
]);

if (isset($opts['help'])) {
    echo <<<HELP
ML ↔ AI Optimization Worker v{WORKER_VERSION}

Usage:
  php bin/ml-ai-optimization-worker.php [options]

Options:
  --account=ID     Required. ML account ID to optimize
  --item=MLB...    Optional. Optimize a specific item ID
  --limit=N        Max items to process (default: 20, max: 100)
  --category=ID    Filter by ML category ID
  --auto-apply     Apply optimizations to ML listings automatically
  --dry-run        Preview mode — no API writes
  --verbose        Show detailed output
  --help           Show this help

Examples:
  php bin/ml-ai-optimization-worker.php --account=1 --limit=10
  php bin/ml-ai-optimization-worker.php --account=1 --item=MLB1234567890 --auto-apply
  php bin/ml-ai-optimization-worker.php --account=1 --dry-run --verbose

HELP;
    exit(0);
}

// ============================================================================
// Validate Arguments
// ============================================================================

$accountId = isset($opts['account']) ? (int)$opts['account'] : 0;
$specificItem = $opts['item'] ?? null;
$limit = min((int)($opts['limit'] ?? DEFAULT_LIMIT), MAX_LIMIT);
$autoApply = isset($opts['auto-apply']);
$dryRun = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);
$category = $opts['category'] ?? null;

if ($accountId <= 0) {
    fwrite(STDERR, "Error: --account=ID is required (positive integer)\n");
    exit(1);
}

if ($dryRun && $autoApply) {
    fwrite(STDERR, "Warning: --dry-run overrides --auto-apply. No changes will be applied.\n");
    $autoApply = false;
}

// ============================================================================
// Worker Logic
// ============================================================================

$startTime = microtime(true);

output("=== ML ↔ AI Optimization Worker v" . WORKER_VERSION . " ===");
output("Account: {$accountId} | Limit: {$limit} | Auto-apply: " . ($autoApply ? 'YES' : 'NO') . " | Dry-run: " . ($dryRun ? 'YES' : 'NO'));
output("Started at: " . date('Y-m-d H:i:s'));
output("");

try {
    $service = new MercadoLivreAIIntegrationService($accountId);

    // Step 1: Health check
    output("[1/4] Checking ML API + AI provider health...");
    $health = $service->getHealthStatus();

    if (!$health['ml']['connected']) {
        output("  ERROR: ML API not connected — " . ($health['ml']['error'] ?? 'check token'));
        exit(1);
    }

    $aiCount = $health['ai']['available_count'] ?? 0;
    output("  ML API: Connected (items: {$health['ml']['items_count']})");
    output("  AI Providers: {$aiCount} available" . ($aiCount === 0 ? ' (will use template fallback)' : ''));
    output("");

    // Step 2: Get items
    if ($specificItem !== null) {
        output("[2/4] Fetching specific item: {$specificItem}...");
        $status = $service->getItemStatus($specificItem);

        if ($status === null) {
            output("  ERROR: Item {$specificItem} not found or failed to fetch");
            exit(1);
        }

        $itemIds = [$specificItem];
        $score = $status['seo_score']['total'] ?? 100;
        output("  Item: {$status['title']}");
        output("  SEO Score: {$score}/100");
        output("  Issues: " . implode('; ', $status['seo_score']['issues'] ?? []));
        output("");
    } else {
        output("[2/4] Listing items for optimization...");
        $filters = ['limit' => $limit];
        if ($category !== null) {
            $filters['category'] = $category;
        }

        $listing = $service->getItemsForOptimization($filters);
        $summary = $listing['optimization_summary'] ?? [];

        output("  Total items: " . ($summary['total_items'] ?? 0));
        output("  Need optimization: " . ($summary['needs_optimization'] ?? 0));
        output("  Good SEO: " . ($summary['good_seo'] ?? 0));
        output("  Avg score: " . ($summary['avg_score'] ?? 0) . "/100");
        output("");

        // Filter to items below threshold
        $itemIds = [];
        foreach ($listing['items'] ?? [] as $item) {
            if (($item['seo_score']['total'] ?? 100) < SEO_SCORE_THRESHOLD) {
                $itemIds[] = $item['id'];
                if ($verbose) {
                    output("  → {$item['id']}: {$item['title']} (score: {$item['seo_score']['total']})");
                }
            }
        }

        if (empty($itemIds)) {
            output("  All items have good SEO scores (>= " . SEO_SCORE_THRESHOLD . "). Nothing to do.");
            exit(0);
        }

        output("  Items to optimize: " . count($itemIds));
        output("");
    }

    // Step 3: Optimize
    output("[3/4] Running optimization pipeline" . ($dryRun ? ' (DRY RUN)' : '') . "...");

    if ($dryRun) {
        output("  Dry-run mode: previewing optimizations without applying...");
        $succeeded = 0;
        $failed = 0;

        foreach ($itemIds as $index => $itemId) {
            $result = $service->optimizeWithContext($itemId, [
                'optimize_title' => true,
                'optimize_description' => true,
                'optimize_attributes' => true,
            ]);

            if ($result['success']) {
                $succeeded++;
                $optz = $result['optimizations'] ?? [];
                output("  [{$index}] {$itemId}: OK");
                if (isset($optz['title']['optimized_title'])) {
                    output("    Title: \"{$optz['title']['original_title']}\" → \"{$optz['title']['optimized_title']}\"");
                }
                if (isset($optz['description'])) {
                    output("    Description: " . mb_strlen($optz['description']['description'] ?? '') . " chars");
                }
                if (!empty($optz['attributes']['missing_required'])) {
                    output("    Missing required attrs: " . count($optz['attributes']['missing_required']));
                }
            } else {
                $failed++;
                output("  [{$index}] {$itemId}: FAILED — " . ($result['error'] ?? 'unknown'));
            }

            // Rate limit
            if ($index < count($itemIds) - 1) {
                usleep(RATE_LIMIT_DELAY_MS * 1000);
            }
        }

        output("");
        output("  Dry-run complete: {$succeeded} succeeded, {$failed} failed");
    } else {
        $batchResult = $service->batchPipeline($itemIds, [
            'optimize_title' => true,
            'optimize_description' => true,
            'optimize_attributes' => true,
        ], $autoApply);

        $summary = $batchResult['summary'] ?? [];
        output("  Processed: " . ($summary['total'] ?? 0));
        output("  Succeeded: " . ($summary['succeeded'] ?? 0));
        output("  Failed: " . ($summary['failed'] ?? 0));
        output("  Success rate: " . ($summary['success_rate'] ?? 0) . "%");
        output("  Duration: " . ($summary['duration_seconds'] ?? 0) . "s");

        if ($verbose) {
            foreach ($batchResult['results'] ?? [] as $r) {
                $status = $r['success'] ? 'OK' : 'FAILED';
                $applied = isset($r['applied']['applied']) ? implode(',', $r['applied']['applied']) : '-';
                output("  {$r['item_id']}: {$status} (applied: {$applied})");
            }
        }
    }

    output("");

    // Step 4: Summary
    $duration = round(microtime(true) - $startTime, 2);
    output("[4/4] Worker completed in {$duration}s");
    output("Finished at: " . date('Y-m-d H:i:s'));
    exit(0);
} catch (\Throwable $e) {
    $duration = round(microtime(true) - $startTime, 2);
    output("");
    output("FATAL ERROR after {$duration}s: " . $e->getMessage());
    output("File: " . $e->getFile() . ":" . $e->getLine());
    if ($verbose) {
        output("Trace:\n" . $e->getTraceAsString());
    }
    exit(1);
}

// ============================================================================
// Helpers
// ============================================================================

function output(string $message): void
{
    echo "[" . date('H:i:s') . "] {$message}\n";
}
