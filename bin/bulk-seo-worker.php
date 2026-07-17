#!/usr/bin/env php
<?php
/**
 * 🚀 Bulk SEO Worker
 * 
 * Worker CLI para processamento assíncrono de jobs Bulk SEO.
 * 
 * Uso:
 *   php bin/bulk-seo-worker.php <jobId>          # Processa um job específico
 *   php bin/bulk-seo-worker.php --process-queued # Processa todos jobs "queued" (cron mode)
 *   php bin/bulk-seo-worker.php --help           # Exibe ajuda
 * 
 * O worker:
 * - Lê job da tabela bulk_seo_jobs por job_id
 * - Atualiza status: pending/queued → processing → completed/failed
 * - Processa itens com rate limit e atualiza counters incrementalmente
 * - Salva results JSON com version_ids por item aplicado
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
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            putenv($line);
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

\App\Helpers\MlWriteAutomation::exitIfDisabledForCli(basename(__FILE__));

use App\Database;
use App\Services\BulkSEOService;
use App\Services\MercadoLivreClient;
use App\Services\SEO\VersioningService;
use App\Services\TechSheetSEOIntegrationService;

// ============================================================================
// Configuration
// ============================================================================

const RATE_LIMIT_DELAY_MS = 250; // ms entre chamadas à API
const MAX_RETRIES = 3;           // Tentativas para erros recuperáveis
const LOCK_TIMEOUT = 3600;       // 1 hora máximo para lock de job

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Log com timestamp
 */
function logMessage(string $level, string $message, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    echo "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
    
    // Também salva em arquivo de log
    $logFile = __DIR__ . '/../storage/logs/bulk-seo-worker.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, "[{$timestamp}] [{$level}] {$message}{$contextStr}\n", FILE_APPEND);
}

function info(string $message, array $context = []): void
{
    logMessage('INFO', $message, $context);
}

function error(string $message, array $context = []): void
{
    logMessage('ERROR', $message, $context);
}

function success(string $message, array $context = []): void
{
    logMessage('SUCCESS', $message, $context);
}

/**
 * Exibe ajuda
 */
function showHelp(): void
{
    echo <<<HELP
🚀 Bulk SEO Worker - Processador de Jobs Assíncronos

USAGE:
    php bin/bulk-seo-worker.php <jobId>              Processa um job específico
    php bin/bulk-seo-worker.php --job=<jobId>        Processa um job específico (formato alternativo)
    php bin/bulk-seo-worker.php --once               Processa 1 job queued e sai
    php bin/bulk-seo-worker.php --process-queued     Processa todos jobs "queued" (cron mode)
    php bin/bulk-seo-worker.php --recover-stuck      Marca jobs stuck (>1h em processing) como failed
    php bin/bulk-seo-worker.php --help               Exibe esta ajuda

OPTIONS:
    --dry-run       Simula processamento sem aplicar alterações no Mercado Livre
    --verbose       Exibe logs detalhados
    --recover-stuck Recupera jobs presos em 'processing' por mais de 1 hora

EXAMPLES:
    php bin/bulk-seo-worker.php bulk_seo_xyz123_1706574123
    php bin/bulk-seo-worker.php --job=bulk_seo_xyz123_1706574123 --dry-run
    php bin/bulk-seo-worker.php --once --verbose
    php bin/bulk-seo-worker.php --process-queued
    php bin/bulk-seo-worker.php --recover-stuck

CRON SETUP (processar jobs queued a cada 2 minutos + recuperar stuck a cada hora):
    */2 * * * * cd /path/to/project && php bin/bulk-seo-worker.php --once >> storage/logs/bulk-seo-worker.log 2>&1
    0 * * * * cd /path/to/project && php bin/bulk-seo-worker.php --recover-stuck >> storage/logs/bulk-seo-worker.log 2>&1

HELP;
}

// ============================================================================
// Job Processing
// ============================================================================

/**
 * Busca job do banco de dados
 */
function getJob(PDO $db, string $jobId): ?array
{
    $stmt = $db->prepare("
        SELECT 
            id, job_id, account_id, user_id, status, 
            total_items, processed_items, successful_items, failed_items,
            job_data, results, created_at, started_at, completed_at
        FROM bulk_seo_jobs
        WHERE job_id = :job_id
    ");
    $stmt->execute(['job_id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($job) {
        if (!empty($job['job_data'])) {
            $job['job_data'] = json_decode($job['job_data'], true);
        }
        if (!empty($job['results'])) {
            $job['results'] = json_decode($job['results'], true);
        }
    }
    
    return $job ?: null;
}

/**
 * Busca todos os jobs queued (para processamento via cron)
 */
function getQueuedJobs(PDO $db, int $limit = 10): array
{
    $limitSql = max(1, min(100, (int)$limit));
    $stmt = $db->prepare("
        SELECT job_id
        FROM bulk_seo_jobs
        WHERE status IN ('pending', 'queued')
        ORDER BY created_at ASC
        LIMIT {$limitSql}
    ");
    $stmt->execute();
    
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'job_id');
}

/**
 * Recupera jobs stuck (presos em 'processing' por muito tempo)
 * Jobs stuck são marcados como 'failed' com mensagem de timeout
 */
function recoverStuckJobs(PDO $db, int $timeoutSeconds = LOCK_TIMEOUT): int
{
    // Buscar jobs que estão em 'processing' há mais tempo que o timeout
    $stmt = $db->prepare("
        SELECT job_id, started_at, total_items, processed_items
        FROM bulk_seo_jobs
        WHERE status = 'processing'
        AND started_at IS NOT NULL
        AND started_at < DATE_SUB(NOW(), INTERVAL :timeout SECOND)
    ");
    $stmt->execute(['timeout' => $timeoutSeconds]);
    $stuckJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stuckJobs)) {
        info("Nenhum job stuck encontrado");
        return 0;
    }
    
    info("Encontrados " . count($stuckJobs) . " jobs stuck para recuperar");
    
    $recovered = 0;
    foreach ($stuckJobs as $job) {
        $jobId = $job['job_id'];
        $minutesStuck = round((time() - strtotime($job['started_at'])) / 60, 1);
        
        // Marcar como failed com mensagem explicativa
        updateJobStatus($db, $jobId, 'failed', [
            'completed_at' => date('Y-m-d H:i:s'),
            'results' => [
                'success' => false,
                'error' => "Job timeout: stuck em 'processing' por {$minutesStuck} minutos",
                'processed_items' => (int)$job['processed_items'],
                'total_items' => (int)$job['total_items'],
                'recovered_at' => date('Y-m-d H:i:s'),
            ],
        ]);
        
        error("Job {$jobId} marcado como failed (stuck por {$minutesStuck} min)");
        $recovered++;
    }
    
    return $recovered;
}

/**
 * Atualiza status do job
 */
function updateJobStatus(
    PDO $db,
    string $jobId,
    string $status,
    array $extra = []
): void {
    $updates = ['status' => $status];
    $updates = array_merge($updates, $extra);
    
    $setParts = [];
    $params = ['job_id' => $jobId];
    
    foreach ($updates as $key => $value) {
        if ($key === 'results' || $key === 'job_data') {
            $setParts[] = "{$key} = :{$key}";
            $params[$key] = json_encode($value);
        } else {
            $setParts[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }
    }

    $setClause = implode(', ', $setParts);
    
    $stmt = $db->prepare("
        UPDATE bulk_seo_jobs
        SET {$setClause}, updated_at = NOW()
        WHERE job_id = :job_id
    ");
    $stmt->execute($params);
}

/**
 * Atualiza contadores do job incrementalmente
 */
function updateJobCounters(
    PDO $db,
    string $jobId,
    int $processedIncrement,
    int $successIncrement,
    int $failedIncrement
): void {
    $stmt = $db->prepare("
        UPDATE bulk_seo_jobs
        SET 
            processed_items = processed_items + :processed,
            successful_items = successful_items + :success,
            failed_items = failed_items + :failed,
            updated_at = NOW()
        WHERE job_id = :job_id
    ");
    $stmt->execute([
        'job_id' => $jobId,
        'processed' => $processedIncrement,
        'success' => $successIncrement,
        'failed' => $failedIncrement,
    ]);
}

/**
 * Adquire lock para processar job (evita processamento duplicado)
 */
function acquireJobLock(PDO $db, string $jobId): bool
{
    // Usa UPDATE com condição para lock otimista
    $stmt = $db->prepare("
        UPDATE bulk_seo_jobs
        SET 
            status = 'processing',
            started_at = NOW(),
            updated_at = NOW()
        WHERE job_id = :job_id
        AND status IN ('pending', 'queued')
    ");
    $stmt->execute(['job_id' => $jobId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Verifica se erro é recuperável (retry)
 */
function isRetryableError(array $response): bool
{
    $status = (int)($response['status'] ?? 0);
    $error = $response['error'] ?? '';

    // Rate limit e erros de servidor são recuperáveis
    if (in_array($status, [429, 500, 502, 503, 504])) {
        return true;
    }

    if ($error === 'temporarily_unavailable') {
        return true;
    }

    return false;
}

/**
 * Processa um item individual
 */
function processItem(
    string $itemId,
    ?string $title,
    ?string $description,
    int $userId,
    int $accountId,
    array $meta
): array {
    $isDryRun = $GLOBALS['BULK_SEO_DRY_RUN'] ?? false;
    $isVerbose = $GLOBALS['BULK_SEO_VERBOSE'] ?? false;
    
    $result = [
        'success' => true,
        'item_id' => $itemId,
        'title_applied' => false,
        'description_applied' => false,
        'version_ids' => [],
        'status' => 'applied',
        'dry_run' => $isDryRun,
    ];

    // Dry-run mode: simular sem aplicar
    if ($isDryRun) {
        if ($isVerbose) {
            info("  [DRY-RUN] Item {$itemId}", [
                'title' => $title ? substr($title, 0, 50) . '...' : null,
                'description' => $description ? substr($description, 0, 50) . '...' : null,
            ]);
        }
        $result['title_applied'] = $title !== null && $title !== '';
        $result['description_applied'] = $description !== null && $description !== '';
        $result['status'] = 'simulated';
        return $result;
    }

    try {
        $seoService = new TechSheetSEOIntegrationService($accountId);
        
        // Aplicar título
        if ($title !== null && $title !== '') {
            $titleResult = $seoService->applyOptimizedTitle($itemId, $title, $userId, $meta);
            
            if ($titleResult['success'] ?? false) {
                $result['title_applied'] = true;
                if (isset($titleResult['version_id'])) {
                    $result['version_ids'][] = $titleResult['version_id'];
                }
            } else {
                // Verificar se é erro recuperável
                if (isRetryableError($titleResult)) {
                    $result['retryable'] = true;
                }
                $result['success'] = false;
                $result['error'] = $titleResult['error'] ?? 'Falha ao aplicar título';
                $result['status'] = 'error';
                return $result;
            }
        }

        // Aplicar descrição
        if ($description !== null && $description !== '') {
            $descResult = $seoService->applyOptimizedDescription($itemId, $description, $userId, $meta);
            
            if ($descResult['success'] ?? false) {
                $result['description_applied'] = true;
                if (isset($descResult['version_id'])) {
                    $result['version_ids'][] = $descResult['version_id'];
                }
            } else {
                // Se título foi aplicado mas descrição falhou, marcar como parcial
                if ($result['title_applied']) {
                    $result['status'] = 'partial';
                    $result['description_error'] = $descResult['error'] ?? 'Falha ao aplicar descrição';
                } else {
                    if (isRetryableError($descResult)) {
                        $result['retryable'] = true;
                    }
                    $result['success'] = false;
                    $result['error'] = $descResult['error'] ?? 'Falha ao aplicar descrição';
                    $result['status'] = 'error';
                }
            }
        }

        // Se nenhuma mudança foi solicitada
        if (!$result['title_applied'] && !$result['description_applied'] && $result['success']) {
            $result['status'] = 'no_op';
        }

    } catch (\Exception $e) {
        $result['success'] = false;
        $result['error'] = $e->getMessage();
        $result['status'] = 'error';
    }

    return $result;
}

/**
 * Processa job completo
 */
function processJob(PDO $db, string $jobId): bool
{
    info("Iniciando processamento do job: {$jobId}");
    
    // Buscar job
    $job = getJob($db, $jobId);
    if (!$job) {
        error("Job não encontrado: {$jobId}");
        return false;
    }

    // Verificar status
    if (!in_array($job['status'], ['pending', 'queued', 'processing'])) {
        info("Job já processado ou em status inválido: {$job['status']}", ['job_id' => $jobId]);
        return false;
    }

    // Se já está em processing, apenas continuar (foi iniciado pelo dispatchBackgroundJob)
    if ($job['status'] === 'processing') {
        info("Job já em processing, continuando processamento...");
        // Atualizar started_at se ainda não foi definido
        if (empty($job['started_at'])) {
            updateJobStatus($db, $jobId, 'processing', ['started_at' => date('Y-m-d H:i:s')]);
        }
    } else {
        // Tentar adquirir lock
        if (!acquireJobLock($db, $jobId)) {
            info("Não foi possível adquirir lock do job (pode estar sendo processado por outro worker)");
            return false;
        }
        info("Lock adquirido, iniciando processamento...");
    }

    // Extrair dados do job
    $jobData = $job['job_data'] ?? [];
    $accountId = (int)($job['account_id'] ?? $jobData['account_id'] ?? 0);
    $userId = (int)($job['user_id'] ?? $jobData['user_id'] ?? 0);
    $items = $jobData['items'] ?? [];
    $meta = $jobData['meta'] ?? ['source' => 'bulk_seo_worker'];

    if (empty($items)) {
        error("Job sem itens para processar", ['job_id' => $jobId]);
        updateJobStatus($db, $jobId, 'failed', [
            'results' => ['error' => 'Nenhum item para processar'],
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        return false;
    }

    if ($accountId <= 0) {
        error("Account ID inválido", ['job_id' => $jobId]);
        updateJobStatus($db, $jobId, 'failed', [
            'results' => ['error' => 'Account ID inválido'],
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        return false;
    }

    info("Processando {$job['total_items']} itens para account_id={$accountId}");

    // Processar itens
    $results = [];
    $stats = [
        'total' => count($items),
        'titles_applied' => 0,
        'descriptions_applied' => 0,
        'no_op' => 0,
        'errors' => 0,
        'version_ids' => [],
    ];
    $failures = [];
    $processed = 0;

    foreach ($items as $approvedItem) {
        $itemId = (string)($approvedItem['item_id'] ?? '');
        if ($itemId === '') {
            continue;
        }

        $applyTitle = (bool)($approvedItem['apply_title'] ?? false);
        $applyDescription = (bool)($approvedItem['apply_description'] ?? false);

        if (!$applyTitle && !$applyDescription) {
            $results[$itemId] = [
                'success' => true,
                'status' => 'no_op',
                'message' => 'Nenhuma alteração selecionada',
            ];
            $stats['no_op']++;
            $processed++;
            updateJobCounters($db, $jobId, 1, 0, 0);
            continue;
        }

        // Processar item com retry
        $result = null;
        $retries = 0;
        
        do {
            $result = processItem(
                $itemId,
                $applyTitle ? (string)($approvedItem['title'] ?? '') : null,
                $applyDescription ? (string)($approvedItem['description'] ?? '') : null,
                $userId,
                $accountId,
                $meta
            );
            
            if (!$result['success'] && ($result['retryable'] ?? false) && $retries < MAX_RETRIES) {
                $retries++;
                info("Retry {$retries}/{" . MAX_RETRIES . "} para item {$itemId}");
                usleep(500000); // 500ms antes de retry
            } else {
                break;
            }
        } while (true);

        $results[$itemId] = $result;
        $processed++;

        if (!$result['success']) {
            $stats['errors']++;
            $failures[] = [
                'item_id' => $itemId,
                'error' => $result['error'] ?? 'Erro desconhecido',
            ];
            updateJobCounters($db, $jobId, 1, 0, 1);
            error("Erro ao processar item {$itemId}: " . ($result['error'] ?? 'desconhecido'));
        } else {
            if ($result['title_applied'] ?? false) {
                $stats['titles_applied']++;
            }
            if ($result['description_applied'] ?? false) {
                $stats['descriptions_applied']++;
            }
            if ($result['status'] === 'no_op') {
                $stats['no_op']++;
            }
            if (isset($result['version_ids'])) {
                $stats['version_ids'] = array_merge($stats['version_ids'], $result['version_ids']);
            }
            
            $successCount = ($result['title_applied'] ? 1 : 0) + ($result['description_applied'] ? 1 : 0);
            updateJobCounters($db, $jobId, 1, $successCount > 0 ? 1 : 0, 0);
            
            if ($result['status'] !== 'no_op') {
                info("Item {$itemId} processado com sucesso", [
                    'title' => $result['title_applied'] ?? false,
                    'desc' => $result['description_applied'] ?? false,
                ]);
            }
        }

        // Rate limit entre chamadas à API
        usleep(RATE_LIMIT_DELAY_MS * 1000);
    }

    // Finalizar job
    $finalStatus = $stats['errors'] === $stats['total'] ? 'failed' : 'completed';
    
    $finalResults = [
        'success' => true,
        'stats' => $stats,
        'items' => $results,
        'failures' => $failures,
    ];

    updateJobStatus($db, $jobId, $finalStatus, [
        'results' => $finalResults,
        'completed_at' => date('Y-m-d H:i:s'),
    ]);

    success("Job {$jobId} finalizado", [
        'status' => $finalStatus,
        'processed' => $processed,
        'titles' => $stats['titles_applied'],
        'descriptions' => $stats['descriptions_applied'],
        'errors' => $stats['errors'],
    ]);

    return true;
}

/**
 * Processa todos os jobs queued (modo cron)
 */
function processQueuedJobs(PDO $db): int
{
    $jobIds = getQueuedJobs($db, 10);
    
    if (empty($jobIds)) {
        info("Nenhum job queued para processar");
        return 0;
    }

    info("Encontrados " . count($jobIds) . " jobs para processar");
    
    $processed = 0;
    foreach ($jobIds as $jobId) {
        if (processJob($db, $jobId)) {
            $processed++;
        }
    }

    return $processed;
}

// ============================================================================
// Main Execution
// ============================================================================

$args = array_slice($argv, 1);

// Parse options
$options = [
    'dry-run' => false,
    'verbose' => false,
    'job' => null,
    'once' => false,
    'process-queued' => false,
    'recover-stuck' => false,
];

$positionalArgs = [];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        showHelp();
        exit(0);
    }
    if ($arg === '--dry-run') {
        $options['dry-run'] = true;
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $options['verbose'] = true;
    } elseif ($arg === '--once') {
        $options['once'] = true;
    } elseif ($arg === '--process-queued') {
        $options['process-queued'] = true;
    } elseif ($arg === '--recover-stuck') {
        $options['recover-stuck'] = true;
    } elseif (preg_match('/^--job=(.+)$/', $arg, $m)) {
        $options['job'] = $m[1];
    } elseif (!str_starts_with($arg, '-')) {
        $positionalArgs[] = $arg;
    }
}

// Set global dry-run flag
$GLOBALS['BULK_SEO_DRY_RUN'] = $options['dry-run'];
$GLOBALS['BULK_SEO_VERBOSE'] = $options['verbose'];

if ($options['dry-run']) {
    info("⚠️  MODO DRY-RUN ATIVADO - Nenhuma alteração será aplicada");
}

try {
    $db = Database::getInstance();
} catch (\Exception $e) {
    error("Falha ao conectar ao banco de dados: " . $e->getMessage());
    exit(1);
}

// Modo recover-stuck: recupera jobs presos em 'processing'
if ($options['recover-stuck']) {
    info("=== Bulk SEO Worker - Modo Recover Stuck ===");
    $count = recoverStuckJobs($db, LOCK_TIMEOUT);
    info("Recuperados {$count} jobs stuck");
    exit(0);
}

// Modo cron: processar todos os queued
if ($options['process-queued']) {
    info("=== Bulk SEO Worker - Modo Cron ===");
    // Primeiro recuperar stuck jobs, depois processar queued
    $stuckCount = recoverStuckJobs($db, LOCK_TIMEOUT);
    if ($stuckCount > 0) {
        info("Recuperados {$stuckCount} jobs stuck antes do processamento");
    }
    $count = processQueuedJobs($db);
    info("Processados {$count} jobs");
    exit(0);
}

// Modo once: pega 1 job queued e processa
if ($options['once']) {
    info("=== Bulk SEO Worker - Modo Once ===");
    $jobIds = getQueuedJobs($db, 1);
    if (empty($jobIds)) {
        info("Nenhum job queued para processar");
        exit(0);
    }
    $success = processJob($db, $jobIds[0]);
    exit($success ? 0 : 1);
}

// Modo job específico (--job=ID ou argumento posicional)
$jobId = $options['job'] ?? ($positionalArgs[0] ?? null);
if (empty($jobId)) {
    error("Job ID não informado");
    showHelp();
    exit(1);
}

info("=== Bulk SEO Worker ===");
$success = processJob($db, $jobId);
exit($success ? 0 : 1);
