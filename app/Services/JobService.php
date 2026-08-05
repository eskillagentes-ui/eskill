<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

use App\Services\OrderService;
use App\Services\ItemService;
use App\Services\QuestionService;
use App\Services\CatalogCloneService;
use App\Services\MercadoLivreClient;
use App\Services\MercadoLivreWebhookService;
use App\Services\TechSheetService;
use App\Services\TechSheetAutoOptimizerService;
use App\Services\AI\SEO\BulkOptimizer;
use App\Services\EanService;
use App\Services\WebhookInboxService;
use App\Services\AutonomousAgentService;
use App\Services\AssistantActionExecutorService;
use App\Services\AssistantConnectorService;

class JobService
{
    private \PDO $db;

    private const DEFAULT_RETRY_BASE_SECONDS = 5;
    private const DEFAULT_RETRY_MAX_SECONDS = 300;
    private const DEFAULT_RETRY_JITTER_PERCENT = 0.20;
    private const DEFAULT_STALE_PROCESSING_SECONDS = 900;
    private const DEFAULT_RECLAIM_DELAY_SECONDS = 2;


    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTableExists();
    }

    /**
     * Cria tabela de jobs se não existir
     */
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS jobs (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    type VARCHAR(100) NOT NULL,
                    payload JSON NOT NULL,
                    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                    attempts INT DEFAULT 0,
                    max_attempts INT DEFAULT 3,
                    error_message TEXT NULL,
                    result JSON NULL,
                    scheduled_at DATETIME NULL,
                    next_attempt_at DATETIME NULL,
                    claim_token VARCHAR(64) NULL,
                    claimed_by VARCHAR(128) NULL,
                    claimed_at DATETIME NULL,
                    started_at DATETIME NULL,
                    completed_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_status (status),
                    INDEX idx_type (type),
                    INDEX idx_scheduled_at (scheduled_at),
                    INDEX idx_next_attempt_at (next_attempt_at),
                    INDEX idx_claim_token (claim_token),
                    INDEX idx_claimed_at (claimed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Para instalações antigas: garante colunas novas
            $this->ensureColumnsExist();
        } catch (\Exception $e) {
            log_error('Erro ao criar tabela jobs', [
                'service' => 'JobService',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Garante colunas adicionais em instalações pré-existentes.
     */
    private function ensureColumnsExist(): void
    {
        try {
            $stmt = $this->db->query("SELECT DATABASE() AS db");
            $dbName = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC)['db'] ?? null) : null;
            if (!$dbName) {
                return;
            }

            $colsStmt = $this->db->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_NAME = 'jobs'
            ");
            $colsStmt->execute([':db' => $dbName]);
            $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
            $cols = is_array($cols) ? $cols : [];
            $colsMap = array_fill_keys($cols, true);

            $idxStmt = $this->db->prepare("
                SELECT INDEX_NAME
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_NAME = 'jobs'
            ");
            $idxStmt->execute([':db' => $dbName]);
            $indexes = $idxStmt->fetchAll(PDO::FETCH_COLUMN);
            $idxMap = array_fill_keys(is_array($indexes) ? $indexes : [], true);

            if (!isset($colsMap['result'])) {
                $this->db->exec("ALTER TABLE jobs ADD COLUMN result JSON NULL");
            }

            if (!isset($colsMap['updated_at'])) {
                $this->db->exec("ALTER TABLE jobs ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }

            if (!isset($colsMap['next_attempt_at'])) {
                $this->db->exec("ALTER TABLE jobs ADD COLUMN next_attempt_at DATETIME NULL AFTER scheduled_at");
            }

            if (!isset($colsMap['claim_token'])) {
                $this->db->exec("ALTER TABLE jobs ADD COLUMN claim_token VARCHAR(64) NULL AFTER scheduled_at");
            }

            if (!isset($colsMap['claimed_by'])) {
                $this->db->exec("ALTER TABLE jobs ADD COLUMN claimed_by VARCHAR(128) NULL AFTER claim_token");
            }

            if (!isset($colsMap['claimed_at'])) {
                $this->db->exec("ALTER TABLE jobs ADD COLUMN claimed_at DATETIME NULL AFTER claimed_by");
            }

            $this->ensureIndexExists($idxMap, 'idx_scheduled_at', "ALTER TABLE jobs ADD INDEX idx_scheduled_at (scheduled_at)");
            $this->ensureIndexExists($idxMap, 'idx_next_attempt_at', "ALTER TABLE jobs ADD INDEX idx_next_attempt_at (next_attempt_at)");
            $this->ensureIndexExists($idxMap, 'idx_claim_token', "ALTER TABLE jobs ADD INDEX idx_claim_token (claim_token)");
            $this->ensureIndexExists($idxMap, 'idx_claimed_at', "ALTER TABLE jobs ADD INDEX idx_claimed_at (claimed_at)");
        } catch (\Exception $e) {
            log_warning('JobService: schema migration skipped', ['exception' => $e->getMessage(), 'service' => 'JobService']);
        }
    }

    /**
     * @param array<string, bool> $idxMap
     */
    private function ensureIndexExists(array &$idxMap, string $indexName, string $createSql): void
    {
        if (isset($idxMap[$indexName])) {
            return;
        }

        $this->db->exec($createSql);
        $idxMap[$indexName] = true;
    }

    /**
     * Adiciona um job à fila
     */
    public function dispatch(string $type, array $payload, ?\DateTime $scheduledAt = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO jobs (type, payload, scheduled_at)
            VALUES (:type, :payload, :scheduled_at)
        ");

        $stmt->execute([
            ':type' => $type,
            ':payload' => json_encode($payload),
            ':scheduled_at' => $scheduledAt ? $scheduledAt->format('Y-m-d H:i:s') : null,
        ]);

        $jobId = (int)$this->db->lastInsertId();

        // Push to Redis Queue if not scheduled for future
        if (!$scheduledAt || $scheduledAt <= new \DateTime()) {
            try {
                $queue = new QueueService();
                $queue->push('process_job', ['job_id' => $jobId], $this->resolveQueueName());
            } catch (\Exception $e) {
                // Redis failure shouldn't block the request, worker fallback (polling) handles it
                log_warning('Falha ao enviar job para Redis', [
                    'service' => 'JobService',
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $jobId;
    }

    private function resolveQueueName(): string
    {
        $queueName = trim((string)($_ENV['JOB_QUEUE_NAME'] ?? getenv('JOB_QUEUE_NAME') ?? 'default'));
        if ($queueName === '') {
            return 'default';
        }

        return $queueName;
    }

    /**
     * Busca um job pelo ID
     */
    public function getJob(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Busca jobs pendentes
     */
    public function getPendingJobs(int $limit = 50): array
    {
        $limitSql = max(1, min((int)$limit, 200));

        $stmt = $this->db->prepare("
            SELECT * FROM jobs
            WHERE status = 'pending'
            AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
            AND attempts < max_attempts
            ORDER BY COALESCE(next_attempt_at, scheduled_at, created_at) ASC, created_at ASC
            LIMIT {$limitSql}
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Processa jobs pendentes
     */
    public function process(int $limit = 10): array
    {
        $processed = [];
        $reclaimed = $this->reclaimStaleProcessingJobs(
            $this->getStaleProcessingTimeoutSeconds(),
            max(10, min(500, $limit * 5))
        );
        if ($reclaimed > 0) {
            log_warning('JobService: jobs reclaimados após timeout de claim', [
                'service' => 'JobService',
                'reclaimed' => $reclaimed,
            ]);
        }

        $jobs = $this->claimPendingJobs($limit);

        foreach ($jobs as $job) {
            $result = $this->processJob($job);
            $processed[] = $result;
        }

        return $processed;
    }

    /**
     * Processa um job específico
     */
    public function processJob(array $job): array
    {
        $jobId = (int)($job['id'] ?? 0);
        $type = (string)($job['type'] ?? '');
        $jobStatus = (string)($job['status'] ?? 'pending');

        if ($jobId <= 0 || $type === '') {
            throw new \InvalidArgumentException('Job inválido para processamento');
        }

        if (in_array($jobStatus, ['completed', 'failed'], true)) {
            return [
                'id' => $jobId,
                'type' => $type,
                'status' => $jobStatus,
            ];
        }

        if ($jobStatus === 'pending') {
            $claimed = $this->claimJob($jobId);
            if ($claimed === null) {
                return [
                    'id' => $jobId,
                    'type' => $type,
                    'status' => 'skipped',
                    'error' => 'Job não pôde ser claimado (já em processamento ou concluído)',
                ];
            }
            $job = $claimed;
            $jobStatus = (string)($job['status'] ?? 'processing');
        }

        if ($jobStatus !== 'processing') {
            $this->updateJobStatus($jobId, 'processing', null, new \DateTime());
        }

        $payload = json_decode((string)($job['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        try {
            // Executar job baseado no tipo
            $result = $this->executeJob($type, $payload, $jobId);

            // Marcar como concluído
            $this->updateJobStatus($jobId, 'completed', null, null, new \DateTime(), null, $result);
            $this->syncMlWebhookInboxStatusOnSuccess($type, is_array($payload) ? $payload : [], $jobId, $result);

            return [
                'id' => $jobId,
                'type' => $type,
                'status' => 'completed',
                'result' => $result,
            ];
        } catch (\Exception $e) {
            $attempts = (int)$job['attempts'] + 1;
            $maxAttempts = (int)$job['max_attempts'];

            if ($attempts >= $maxAttempts) {
                // Marcar como falhou
                $this->updateJobStatus($jobId, 'failed', $e->getMessage());
                $this->syncMlWebhookInboxStatusOnFailure($type, is_array($payload) ? $payload : [], $jobId, $e->getMessage(), true, $attempts, $maxAttempts);

                // DLQ best-effort para assistant_action
                if ($type === 'assistant_action') {
                    try {
                        $actionRunId = isset($payload['action_run_id']) ? (int)$payload['action_run_id'] : 0;
                        if ($actionRunId > 0) {
                            $connector = new AssistantConnectorService();
                            $connector->markActionRunFailed($actionRunId, $e->getMessage());
                        }
                    } catch (\Throwable $inner) {
                        log_warning('JobService: falha ao marcar assistant_action como failed', [
                            'service' => 'JobService',
                            'job_id' => $jobId,
                            'error' => $inner->getMessage(),
                        ]);
                    }
                }

                // DLQ best-effort para catalog_clone_batch e catalog_clone_item
                if (in_array($type, ['catalog_clone_batch', 'catalog_clone_item'], true)) {
                    try {
                        $batchJobId = (string)($payload['batch_job_id'] ?? '');
                        if ($batchJobId !== '') {
                            $this->db->prepare(
                                "UPDATE catalog_clone_jobs
                                 SET status = 'failed', completed_at = NOW(),
                                     error_message = :err
                                 WHERE job_id = :job_id AND status NOT IN ('completed','failed','cancelled')"
                            )->execute([
                                'err' => substr($e->getMessage(), 0, 1000),
                                'job_id' => $batchJobId,
                            ]);
                        }
                    } catch (\Throwable $inner) {
                        log_warning('JobService: falha ao marcar catalog_clone como failed no DLQ', [
                            'service' => 'JobService',
                            'job_id' => $jobId,
                            'type' => $type,
                            'error' => $inner->getMessage(),
                        ]);
                    }
                }

                // DLQ best-effort para bulk_optimize_exec
                if ($type === 'bulk_optimize_exec') {
                    try {
                        $seoJobId = isset($payload['seo_job_id']) ? (int)$payload['seo_job_id'] : 0;
                        if ($seoJobId > 0) {
                            $this->db->prepare(
                                "UPDATE seo_bulk_jobs
                                 SET status = 'failed', completed_at = NOW(),
                                     results = JSON_SET(COALESCE(results, '{}'), '$.dlq_error', :err)
                                 WHERE id = :id AND status NOT IN ('completed','failed')"
                            )->execute([
                                'err' => substr($e->getMessage(), 0, 1000),
                                'id' => $seoJobId,
                            ]);
                        }
                    } catch (\Throwable $inner) {
                        log_warning('JobService: falha ao marcar bulk_optimize_exec como failed no DLQ', [
                            'service' => 'JobService',
                            'job_id' => $jobId,
                            'error' => $inner->getMessage(),
                        ]);
                    }
                }
            } else {
                // Reagendar para tentar novamente
                $retryDelaySeconds = $this->calculateRetryDelaySeconds($attempts);
                $nextAttemptAt = (new DateTimeImmutable())->modify('+' . $retryDelaySeconds . ' seconds');
                $this->updateJobStatus($jobId, 'pending', $e->getMessage(), null, null, $attempts, null, $nextAttemptAt);
                $this->syncMlWebhookInboxStatusOnFailure(
                    $type,
                    is_array($payload) ? $payload : [],
                    $jobId,
                    $e->getMessage(),
                    false,
                    $attempts,
                    $maxAttempts,
                    $nextAttemptAt,
                    $retryDelaySeconds
                );
            }

            return [
                'id' => $jobId,
                'type' => $type,
                'status' => $attempts >= $maxAttempts ? 'failed' : 'pending',
                'error' => $e->getMessage(),
                'attempts' => $attempts,
                'next_attempt_at' => isset($nextAttemptAt) ? $nextAttemptAt->format(DATE_ATOM) : null,
            ];
        }
    }

    /**
     * Mantém a inbox de webhook ML consistente com o ciclo de vida do job.
     * Fluxo esperado: received -> queued -> processed/failed.
     */
    private function syncMlWebhookInboxStatusOnSuccess(string $type, array $payload, int $jobId, $result): void
    {
        if ($type !== 'ml_webhook') {
            return;
        }

        $eventHash = (string)($payload['event_hash'] ?? '');
        if ($eventHash === '') {
            return;
        }

        $resultPayload = is_array($result) ? $result : ['result' => $result];
        $resultPayload['job_id'] = $jobId;
        $resultPayload['job_status'] = 'completed';
        $resultPayload['processed_by'] = 'job_service';

        try {
            $inbox = new WebhookInboxService();
            $inbox->markProcessed('mercadolivre', $eventHash, $resultPayload);
        } catch (\Throwable $t) {
            log_warning('Falha ao marcar inbox de webhook ML como processed', [
                'service' => 'JobService',
                'job_id' => $jobId,
                'event_hash' => $eventHash,
                'error' => $t->getMessage(),
            ]);
        }
    }

    /**
     * Sincroniza status de falha/retry do job com a inbox de webhook ML.
     */
    private function syncMlWebhookInboxStatusOnFailure(
        string $type,
        array $payload,
        int $jobId,
        string $errorMessage,
        bool $terminalFailure,
        int $attempts,
        int $maxAttempts,
        ?DateTimeInterface $nextAttemptAt = null,
        ?int $retryDelaySeconds = null
    ): void {
        if ($type !== 'ml_webhook') {
            return;
        }

        $eventHash = (string)($payload['event_hash'] ?? '');
        if ($eventHash === '') {
            return;
        }

        try {
            $inbox = new WebhookInboxService();
            if ($terminalFailure) {
                $inbox->markFailed('mercadolivre', $eventHash, $errorMessage);
                return;
            }

            $inbox->markQueued('mercadolivre', $eventHash, $jobId, [
                'queue_status' => 'retry_pending',
                'last_error' => mb_substr($errorMessage, 0, 500),
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'next_attempt_at' => $nextAttemptAt ? $nextAttemptAt->format(DATE_ATOM) : null,
                'retry_delay_seconds' => $retryDelaySeconds,
                'updated_by' => 'job_service',
            ]);
        } catch (\Throwable $t) {
            log_warning('Falha ao atualizar inbox de webhook ML após erro de job', [
                'service' => 'JobService',
                'job_id' => $jobId,
                'event_hash' => $eventHash,
                'terminal_failure' => $terminalFailure,
                'error' => $t->getMessage(),
            ]);
        }
    }

    /**
     * Executa o job baseado no tipo
     */
    private function executeJob(string $type, array $payload, int $jobId = null): mixed
    {
        switch ($type) {
            case 'sync_orders':
                return $this->syncOrdersJob($payload);

            case 'sync_items':
                return $this->syncItemsJob($payload);

            case 'sync_questions':
                return $this->syncQuestionsJob($payload);

            case 'update_price_history':
                return $this->updatePriceHistoryJob($payload);

            case 'check_alerts':
                return $this->checkAlertsJob($payload);

            case 'catalog_clone_item':
                return $this->catalogCloneItemJob($payload, $jobId);

            case 'catalog_clone_batch':
                return $this->catalogCloneBatchJob($payload, $jobId);

            case 'gap_analysis':
                return $this->gapAnalysisJob($payload);

            case 'ai_generation':
                return $this->aiGenerationJob($payload);

            case 'tech_sheet_generate_suggestions':
                return $this->techSheetGenerateSuggestionsJob($payload);

            case 'tech_sheet_apply_approved':
                return $this->techSheetApplyApprovedJob($payload);

            case 'tech_sheet_approve_pending':
                return $this->techSheetApprovePendingJob($payload);

            case 'tech_sheet_auto_optimize':
                return $this->techSheetAutoOptimizeJob($payload);

            case 'bulk_optimize_exec':
                return $this->bulkOptimizeExecJob($payload);

            case 'ml_webhook':
                return $this->mlWebhookJob($payload);

            case 'ean_mp_webhook':
                return $this->eanMercadoPagoWebhookJob($payload);

            case 'run_agent':
                return $this->runAutonomousAgentJob($payload);

            case 'assistant_action':
                $executor = new AssistantActionExecutorService();
                return $executor->execute($payload, (int)($jobId ?? 0));

            default:
                throw new \Exception("Tipo de job desconhecido: {$type}");
        }
    }

    /**
     * Job: processar webhook do Mercado Pago (módulo EAN) e sincronizar ML.
     */
    private function eanMercadoPagoWebhookJob(array $payload): array
    {
        $eventKey = (string)($payload['event_key'] ?? '');
        $requestId = (string)($payload['request_id'] ?? '');
        $data = $payload['data'] ?? null;

        if ($eventKey === '' || !is_array($data)) {
            throw new \Exception('event_key e data são obrigatórios para ean_mp_webhook');
        }

        $inbox = new WebhookInboxService();
        $eanService = new EanService();

        try {
            $result = $eanService->processPaymentWebhook($data);
            $inbox->markProcessed('mercadopago', $eventKey, array_merge($result, [
                'processed_by' => 'job_service',
                'request_id' => $requestId,
            ]));

            $accountId = $this->resolveAccountIdByPaymentWebhookData($data);
            $syncJobs = [];

            if (
                $accountId !== null
                && in_array((string)($result['status'] ?? ''), ['confirmed', 'already_paid'], true)
            ) {
                $syncOrdersJobId = $this->dispatch('sync_orders', [
                    'account_id' => $accountId,
                    'limit' => 100,
                    'source' => 'ean_mp_webhook',
                ]);

                $syncItemsJobId = $this->dispatch('sync_items', [
                    'account_id' => $accountId,
                    'limit' => 50,
                    'source' => 'ean_mp_webhook',
                ]);

                $syncJobs = [
                    'sync_orders_job_id' => $syncOrdersJobId,
                    'sync_items_job_id' => $syncItemsJobId,
                ];
            }

            return [
                'success' => true,
                'event_key' => $eventKey,
                'request_id' => $requestId,
                'process_result' => $result,
                'ml_sync_jobs' => $syncJobs,
            ];
        } catch (\Throwable $e) {
            $inbox->markFailed('mercadopago', $eventKey, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Resolve account_id pela referência de pagamento do webhook MP.
     */
    private function resolveAccountIdByPaymentWebhookData(array $data): ?int
    {
        $paymentId = (string)($data['data']['id'] ?? $data['id'] ?? '');
        if ($paymentId === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT account_id
             FROM ean_purchases
             WHERE payment_id = :payment_id OR payment_external_id = :payment_id
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute(['payment_id' => $paymentId]);
        $accountId = $stmt->fetchColumn();

        return $accountId !== false ? (int)$accountId : null;
    }

    /**
     * Job: Executar Agente Autônomo (Sniper, Guardian, etc)
     */
    private function runAutonomousAgentJob(array $payload): array
    {
        $agentCode = $payload['agent'] ?? '';
        $accountId = $payload['account_id'] ?? null;

        if (empty($agentCode)) {
            throw new \Exception('Agent code is required');
        }

        $service = new AutonomousAgentService($accountId);
        return $service->runAgent($agentCode);
    }

    /**
     * Job: Processar evento de webhook Mercado Livre
     */
    private function mlWebhookJob(array $payload): array
    {
        $accountId = isset($payload['internal_account_id']) ? (int)$payload['internal_account_id'] : 0;
        if ($accountId <= 0) {
            throw new \Exception('internal_account_id é obrigatório para ml_webhook');
        }

        $webhookService = new MercadoLivreWebhookService($accountId);
        $result = $webhookService->processWebhookEvent($payload);

        if (!(bool)($result['success'] ?? false)) {
            throw new \Exception((string)($result['error'] ?? 'Erro desconhecido no processamento de webhook'));
        }

        return [
            'account_id' => $accountId,
            'event_hash' => $payload['event_hash'] ?? null,
            'topic' => $payload['topic'] ?? null,
            'resource' => $payload['resource'] ?? null,
            'event_id' => $result['event_id'] ?? null,
            'success' => true,
        ];
    }

    /**
     * Processa um job batch de clonagem (tabela catalog_clone_jobs/catalog_clone_job_items).
     * Estratégia: processa um pequeno lote por execução e re-despacha se ainda houver pendências.
     */
    private function catalogCloneBatchJob(array $payload, ?int $jobId = null): array
    {
        $batchJobId = (string)($payload['batch_job_id'] ?? '');
        if ($batchJobId === '') {
            throw new \InvalidArgumentException('batch_job_id é obrigatório');
        }

        $stmtJob = $this->db->prepare('SELECT * FROM catalog_clone_jobs WHERE job_id = :job_id');
        $stmtJob->execute([':job_id' => $batchJobId]);
        $job = $stmtJob->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new \RuntimeException('Job batch não encontrado: ' . $batchJobId);
        }

        $status = $job['status'] ?? 'pending';
        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            return [
                'status' => 'noop',
                'batch_job_id' => $batchJobId,
                'message' => 'Job batch já finalizado (' . $status . ')'
            ];
        }

        // Marcar como processing na primeira execução
        if (in_array($status, ['pending', 'queued'], true)) {
            $stmtStart = $this->db->prepare("
                UPDATE catalog_clone_jobs
                SET status = 'processing',
                    started_at = IFNULL(started_at, NOW())
                WHERE job_id = :job_id
            ");
            $stmtStart->execute([':job_id' => $batchJobId]);
        }

        $targetAccountId = (int)($job['target_account_id'] ?? 0);
        $sourceAccountId = !empty($job['source_account_id']) ? (int)$job['source_account_id'] : null;
        $options = [];
        if (!empty($job['options'])) {
            $decoded = json_decode((string)$job['options'], true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        // Normalizar opções do wizard (flat) para formato structured do cloneItem()
        $normalized = CatalogCloneService::normalizeCloneOptions($options);
        $pricingStrategy = $normalized['pricing_strategy'];
        $stockStrategy = $normalized['stock_strategy'];
        // Mesclar opções normalizadas com as originais (preserva seller_filters, etc.)
        $options = array_merge($options, $normalized['options']);

        // ── Resolução lazy de itens para jobs source_type=seller ──────────────
        // Quando o job foi criado sem item_ids (modo "clone all"), o worker busca
        // os itens via API do ML usando seller_id + filtros armazenados em options.
        if (($job['source_type'] ?? '') === 'seller') {
            $stmtCount = $this->db->prepare(
                'SELECT COUNT(*) FROM catalog_clone_job_items WHERE job_id = :job_id'
            );
            $stmtCount->execute([':job_id' => $batchJobId]);
            $existingCount = (int)$stmtCount->fetchColumn();

            if ($existingCount === 0) {
                $sellerId     = preg_replace('/\D/', '', (string)($job['source_seller_id'] ?? ''));
                $sellerFilters = is_array($options['seller_filters'] ?? null) ? $options['seller_filters'] : [];
                $maxItems     = max(1, (int)($sellerFilters['max_items'] ?? 1000));

                if ($sellerId !== '') {
                    $cloneSvc = new CatalogCloneService();
                    $allItems = [];
                    $offset   = 0;
                    $pageSize = 50;

                    try {
                        do {
                            $apiResult = $cloneSvc->listSellerItems(
                                $sellerId,
                                array_merge($sellerFilters, ['offset' => $offset, 'limit' => $pageSize])
                            );

                            $page    = $apiResult['items'] ?? [];
                            $allItems = array_merge($allItems, $page);
                            $offset  += count($page);

                            if (count($page) < $pageSize) {
                                break;
                            }
                            if (count($allItems) >= $maxItems) {
                                break;
                            }

                            usleep(200000); // 200 ms inter-page rate-limit
                        } while (true);

                        $allItems = array_slice($allItems, 0, $maxItems);

                        $stmtIns = $this->db->prepare(
                            "INSERT INTO catalog_clone_job_items (job_id, source_item_id, status)
                             VALUES (:job_id, :source_item_id, 'pending')"
                        );
                        foreach ($allItems as $item) {
                            $itemId = (string)($item['id'] ?? '');
                            if ($itemId === '') {
                                continue;
                            }
                            $stmtIns->execute([
                                ':job_id'         => $batchJobId,
                                ':source_item_id' => $itemId,
                            ]);
                        }

                        $this->db->prepare(
                            'UPDATE catalog_clone_jobs SET total_items = :total WHERE job_id = :job_id'
                        )->execute([':total' => count($allItems), ':job_id' => $batchJobId]);
                    } catch (\Throwable $e) {
                        $this->db->prepare(
                            "UPDATE catalog_clone_jobs SET status = 'failed', completed_at = NOW() WHERE job_id = :job_id"
                        )->execute([':job_id' => $batchJobId]);

                        throw new \RuntimeException(
                            'Falha ao resolver itens do seller durante execução do job: ' . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                }
            }
        }
        // ── Fim resolução lazy ─────────────────────────────────────────────────

        // Tamanho do lote por execução (evita timeouts em batch grande)
        $batchSize = (int)($payload['batch_size'] ?? 3);
        $batchSize = max(1, min($batchSize, 10));

        // Selecionar pendências com lock leve
        $this->db->beginTransaction();
        try {
            $batchSizeSql = max(1, min(10, (int)$batchSize));
            $stmtItems = $this->db->prepare("
                SELECT id, source_item_id, source_snapshot, attempts
                FROM catalog_clone_job_items
                WHERE job_id = :job_id
                  AND status = 'pending'
                ORDER BY id ASC
                                LIMIT {$batchSizeSql}
                FOR UPDATE
            ");
            $stmtItems->bindValue(':job_id', $batchJobId);
            $stmtItems->execute();
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                $this->db->commit();
                // Se não há pendências, finalizar se tudo processado
                $stmtPending = $this->db->prepare("SELECT COUNT(*) FROM catalog_clone_job_items WHERE job_id = :job_id AND status IN ('pending','processing')");
                $stmtPending->execute([':job_id' => $batchJobId]);
                $remaining = (int)$stmtPending->fetchColumn();

                if ($remaining === 0) {
                    $stmtFinish = $this->db->prepare("
                        UPDATE catalog_clone_jobs
                        SET status = 'completed',
                            completed_at = IFNULL(completed_at, NOW())
                        WHERE job_id = :job_id
                    ");
                    $stmtFinish->execute([':job_id' => $batchJobId]);
                }

                return [
                    'status' => 'ok',
                    'batch_job_id' => $batchJobId,
                    'processed_now' => 0,
                    'remaining' => $remaining,
                    'message' => $remaining === 0 ? 'Job batch finalizado' : 'Sem itens pendentes no momento'
                ];
            }

            $ids = array_map(static fn($r) => (int)$r['id'], $items);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtMark = $this->db->prepare("
                UPDATE catalog_clone_job_items
                SET status = 'processing',
                    attempts = attempts + 1,
                    updated_at = NOW()
                WHERE id IN ($placeholders)
            ");
            $stmtMark->execute($ids);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $cloneService = new CatalogCloneService();
        $sourceClient = $sourceAccountId ? new MercadoLivreClient($sourceAccountId) : new MercadoLivreClient();

        $processedNow = 0;
        $continuationNeeded = false;

        foreach ($items as $row) {
            $jobItemId = (int)$row['id'];
            $sourceItemId = (string)$row['source_item_id'];
            $snapshot = null;

            // Capturar snapshot/brand/is_catalog (para reporting/facets)
            if (!empty($row['source_snapshot'])) {
                $decoded = json_decode((string)$row['source_snapshot'], true);
                if (is_array($decoded)) {
                    $snapshot = $decoded;
                }
            }

            if (!$snapshot) {
                $itemData = $sourceClient->get("/items/{$sourceItemId}");
                if (!isset($itemData['error'])) {
                    $snapshot = $itemData;
                }
            }

            $isCatalog = 0;
            $brand = null;
            if (is_array($snapshot)) {
                $isCatalog = !empty($snapshot['catalog_product_id']) ? 1 : 0;
                $brand = $this->extractBrandFromAttributes($snapshot['attributes'] ?? []);

                $stmtSnap = $this->db->prepare("
                    UPDATE catalog_clone_job_items
                    SET source_snapshot = IFNULL(source_snapshot, :snap),
                        is_catalog = :is_catalog,
                        brand = :brand
                    WHERE id = :id
                ");
                $stmtSnap->execute([
                    ':snap' => json_encode($snapshot),
                    ':is_catalog' => $isCatalog,
                    ':brand' => $brand,
                    ':id' => $jobItemId,
                ]);
            }

            // Montar parâmetros de clonagem
            $params = [
                'source_item_id' => $sourceItemId,
                'target_account_id' => $targetAccountId,
                'source_account_id' => $sourceAccountId,
                'options' => $options,
                'job_id' => $jobId,
            ];

            if (!empty($job['template_slug'])) {
                $params['template_slug'] = $job['template_slug'];
            }
            if (is_array($pricingStrategy)) {
                $params['pricing_strategy'] = $pricingStrategy;
            }
            if (is_array($stockStrategy)) {
                $params['stock_strategy'] = $stockStrategy;
            }

            $result = null;
            try {
                $result = $cloneService->cloneItem($params);
            } catch (\Throwable $e) {
                $result = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }

            $processedNow++;
            $processedAt = (new \DateTime())->format('Y-m-d H:i:s');

            $itemStatus = $result['status'] ?? 'error';
            $message = (string)($result['message'] ?? '');
            $targetItemId = $result['target_item_id'] ?? null;

            if ($itemStatus === 'success') {
                $stmtOk = $this->db->prepare("
                    UPDATE catalog_clone_job_items
                    SET status = 'completed',
                        target_item_id = :target_item_id,
                        error_message = NULL,
                        processed_at = :processed_at,
                        result = :result
                    WHERE id = :id
                ");
                $stmtOk->execute([
                    ':target_item_id' => $targetItemId,
                    ':processed_at' => $processedAt,
                    ':result' => json_encode($result),
                    ':id' => $jobItemId,
                ]);

                $stmtInc = $this->db->prepare("
                    UPDATE catalog_clone_jobs
                    SET processed_items = processed_items + 1,
                        successful_items = successful_items + 1
                    WHERE job_id = :job_id
                ");
                $stmtInc->execute([':job_id' => $batchJobId]);
            } elseif ($itemStatus === 'skipped_duplicate') {
                $stmtSkip = $this->db->prepare("
                    UPDATE catalog_clone_job_items
                    SET status = 'skipped',
                        error_message = :error_message,
                        processed_at = :processed_at,
                        result = :result
                    WHERE id = :id
                ");
                $stmtSkip->execute([
                    ':error_message' => $message,
                    ':processed_at' => $processedAt,
                    ':result' => json_encode($result),
                    ':id' => $jobItemId,
                ]);

                $stmtInc = $this->db->prepare("
                    UPDATE catalog_clone_jobs
                    SET processed_items = processed_items + 1,
                        skipped_items = skipped_items + 1
                    WHERE job_id = :job_id
                ");
                $stmtInc->execute([':job_id' => $batchJobId]);
            } else {
                $stmtFail = $this->db->prepare("
                    UPDATE catalog_clone_job_items
                    SET status = 'failed',
                        error_message = :error_message,
                        processed_at = :processed_at,
                        result = :result
                    WHERE id = :id
                ");
                $stmtFail->execute([
                    ':error_message' => $message,
                    ':processed_at' => $processedAt,
                    ':result' => json_encode($result),
                    ':id' => $jobItemId,
                ]);

                $stmtInc = $this->db->prepare("
                    UPDATE catalog_clone_jobs
                    SET processed_items = processed_items + 1,
                        failed_items = failed_items + 1
                    WHERE job_id = :job_id
                ");
                $stmtInc->execute([':job_id' => $batchJobId]);
            }
        }

        // Verificar se ainda há pendências e re-despachar continuação
        $stmtRemain = $this->db->prepare("SELECT COUNT(*) FROM catalog_clone_job_items WHERE job_id = :job_id AND status = 'pending'");
        $stmtRemain->execute([':job_id' => $batchJobId]);
        $remainingPending = (int)$stmtRemain->fetchColumn();

        if ($remainingPending > 0) {
            $continuationNeeded = true;
            $this->dispatch('catalog_clone_batch', ['batch_job_id' => $batchJobId, 'batch_size' => $batchSize], new \DateTime('+2 seconds'));
        }

        if ($remainingPending === 0) {
            $stmtFinish = $this->db->prepare("
                UPDATE catalog_clone_jobs
                SET status = 'completed',
                    completed_at = IFNULL(completed_at, NOW())
                WHERE job_id = :job_id
            ");
            $stmtFinish->execute([':job_id' => $batchJobId]);
        }

        return [
            'status' => 'ok',
            'batch_job_id' => $batchJobId,
            'processed_now' => $processedNow,
            'remaining_pending' => $remainingPending,
            'continuation_scheduled' => $continuationNeeded,
        ];
    }

    private function extractBrandFromAttributes(array $attributes): ?string
    {
        foreach ($attributes as $attr) {
            $id = (string)($attr['id'] ?? '');
            if (in_array($id, ['BRAND', 'MARCA', 'brand'], true)) {
                $name = $attr['value_name'] ?? null;
                return $name ? (string)$name : null;
            }
        }
        return null;
    }

    /**
     * Job: Ficha Técnica - Aprovar pendentes por confiança (batch)
     *
     * Payload:
     * - account_id (int) obrigatório
     * - user_id (int) obrigatório
     * - item_ids (string[]) obrigatório
     * - min_confidence (int) opcional (0-100)
     */
    private function techSheetApprovePendingJob(array $payload): array
    {
        $accountId = (int)($payload['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \Exception('account_id é obrigatório para tech_sheet_approve_pending');
        }

        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \Exception('user_id é obrigatório para tech_sheet_approve_pending');
        }

        $itemIds = $payload['item_ids'] ?? null;
        if (!is_array($itemIds) || !$itemIds) {
            throw new \Exception('item_ids é obrigatório (array)');
        }

        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        if (!$itemIds) {
            throw new \Exception('item_ids vazio após sanitização');
        }

        if (count($itemIds) > 200) {
            throw new \Exception('Limite excedido: máximo 200 item_ids por job');
        }

        $minConfidence = isset($payload['min_confidence']) ? (int)$payload['min_confidence'] : 85;
        if ($minConfidence < 0) {
            $minConfidence = 0;
        }
        if ($minConfidence > 100) {
            $minConfidence = 100;
        }

        $service = new TechSheetService($accountId);
        $processed = 0;
        $successful = 0;
        $failed = 0;
        $approvedTotal = 0;
        $failures = [];

        foreach ($itemIds as $itemId) {
            $processed++;
            try {
                $res = $service->approvePendingByConfidence($itemId, $userId, $minConfidence);
                if (($res['success'] ?? false) === true) {
                    $successful++;
                    $approvedTotal += (int)($res['approved'] ?? 0);
                } else {
                    $failed++;
                    if (count($failures) < 20) {
                        $failures[] = [
                            'item_id' => $itemId,
                            'error' => $res['error'] ?? 'Falha ao aprovar pendentes',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $failed++;
                if (count($failures) < 20) {
                    $failures[] = [
                        'item_id' => $itemId,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'account_id' => $accountId,
            'min_confidence' => $minConfidence,
            'processed_items' => $processed,
            'successful_items' => $successful,
            'failed_items' => $failed,
            'suggestions_approved_total' => $approvedTotal,
            'failures' => $failures,
        ];
    }

    /**
     * Job: Ficha Técnica - Gerar sugestões (batch)
     *
     * Payload:
     * - account_id (int) obrigatório
     * - item_ids (string[]) obrigatório
     */
    private function techSheetGenerateSuggestionsJob(array $payload): array
    {
        $accountId = (int)($payload['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \Exception('account_id é obrigatório para tech_sheet_generate_suggestions');
        }

        $itemIds = $payload['item_ids'] ?? null;
        if (!is_array($itemIds) || !$itemIds) {
            throw new \Exception('item_ids é obrigatório (array)');
        }

        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        if (!$itemIds) {
            throw new \Exception('item_ids vazio após sanitização');
        }

        // Guardrail para evitar jobs gigantescos em uma única execução
        if (count($itemIds) > 200) {
            throw new \Exception('Limite excedido: máximo 200 item_ids por job');
        }

        $service = new TechSheetService($accountId);
        $processed = 0;
        $successful = 0;
        $failed = 0;
        $createdTotal = 0;
        $failures = [];

        foreach ($itemIds as $itemId) {
            $processed++;
            try {
                $res = $service->generateSuggestions($itemId);
                if (($res['success'] ?? false) === true) {
                    $successful++;
                    $createdTotal += (int)($res['created'] ?? 0);
                } else {
                    $failed++;
                    if (count($failures) < 20) {
                        $failures[] = [
                            'item_id' => $itemId,
                            'error' => $res['error'] ?? 'Falha ao gerar sugestões',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $failed++;
                if (count($failures) < 20) {
                    $failures[] = [
                        'item_id' => $itemId,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'account_id' => $accountId,
            'processed_items' => $processed,
            'successful_items' => $successful,
            'failed_items' => $failed,
            'suggestions_created_total' => $createdTotal,
            'failures' => $failures,
        ];
    }

    /**
     * Job: Ficha Técnica - Aplicar sugestões aprovadas (batch)
     *
     * Payload:
     * - account_id (int) obrigatório
     * - user_id (int) obrigatório (para auditoria)
     * - item_ids (string[]) obrigatório
     */
    private function techSheetApplyApprovedJob(array $payload): array
    {
        $accountId = (int)($payload['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \Exception('account_id é obrigatório para tech_sheet_apply_approved');
        }

        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \Exception('user_id é obrigatório para tech_sheet_apply_approved');
        }

        $itemIds = $payload['item_ids'] ?? null;
        if (!is_array($itemIds) || !$itemIds) {
            throw new \Exception('item_ids é obrigatório (array)');
        }

        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        if (!$itemIds) {
            throw new \Exception('item_ids vazio após sanitização');
        }

        if (count($itemIds) > 200) {
            throw new \Exception('Limite excedido: máximo 200 item_ids por job');
        }

        $service = new TechSheetService($accountId);
        $processed = 0;
        $successful = 0;
        $failed = 0;
        $appliedTotal = 0;
        $failures = [];

        foreach ($itemIds as $itemId) {
            $processed++;
            try {
                $res = $service->applyApproved($itemId, $userId);
                if (($res['success'] ?? false) === true) {
                    $successful++;
                    $appliedTotal += (int)($res['applied'] ?? 0);
                } else {
                    $failed++;
                    if (count($failures) < 20) {
                        $failures[] = [
                            'item_id' => $itemId,
                            'error' => $res['error'] ?? 'Falha ao aplicar no ML',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $failed++;
                if (count($failures) < 20) {
                    $failures[] = [
                        'item_id' => $itemId,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'account_id' => $accountId,
            'processed_items' => $processed,
            'successful_items' => $successful,
            'failed_items' => $failed,
            'attributes_applied_total' => $appliedTotal,
            'failures' => $failures,
        ];
    }

    /**
     * Job: Ficha Técnica - Auto-optimize (aplica sugestões de alta confiança)
     *
     * Payload:
     * - account_id (int) obrigatório
     * - options (array) opcional: limit, force, dry_run
     */
    private function techSheetAutoOptimizeJob(array $payload): array
    {
        $accountId = (int)($payload['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \Exception('account_id é obrigatório para tech_sheet_auto_optimize');
        }

        $options = $payload['options'] ?? [];

        // Força execução pois foi disparado explicitamente
        $options['force'] = $options['force'] ?? true;
        $options['limit'] = min($options['limit'] ?? 100, 200);
        $options['dry_run'] = $options['dry_run'] ?? false;

        $service = new TechSheetAutoOptimizerService($accountId);
        $result = $service->autoOptimize($options);

        return [
            'success' => $result['success'] ?? false,
            'account_id' => $accountId,
            'dry_run' => $result['dry_run'] ?? false,
            'total_eligible' => $result['results']['total_eligible'] ?? 0,
            'processed' => $result['results']['processed'] ?? 0,
            'approved' => $result['results']['approved'] ?? 0,
            'applied' => $result['results']['applied'] ?? 0,
            'errors' => $result['results']['errors'] ?? 0,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Job: Sincronizar pedidos
     */
    private function syncOrdersJob(array $payload): array
    {
        $accountId = $payload['account_id'] ?? null;

        if (!$accountId) {
            throw new \Exception('account_id é obrigatório para sync_orders');
        }

        $orderService = new OrderService($accountId);
        $limit = (int)($payload['limit'] ?? 100);
        $result = $orderService->syncOrders(null, $limit, [
            'cursor' => isset($payload['cursor']) ? (int)$payload['cursor'] : null,
            'since' => $payload['since'] ?? null,
            'until' => $payload['until'] ?? null,
            'page_limit' => isset($payload['page_limit']) ? (int)$payload['page_limit'] : 20,
            'full_backfill' => (bool)($payload['full_backfill'] ?? false),
            'persist_checkpoint' => !isset($payload['persist_checkpoint']) || (bool)$payload['persist_checkpoint'],
            'overlap_seconds' => isset($payload['overlap_seconds']) ? (int)$payload['overlap_seconds'] : 300,
        ]);

        return [
            'account_id' => $accountId,
            'synced' => $result['synced'] ?? 0,
            'errors' => $result['errors'] ?? [],
            'error_count' => $result['error_count'] ?? (is_array($result['errors'] ?? null) ? count($result['errors']) : 0),
            'has_more' => $result['has_more'] ?? false,
            'next_cursor' => $result['next_cursor'] ?? null,
            'pages_processed' => $result['pages_processed'] ?? 0,
        ];
    }

    /**
     * Job: Sincronizar anúncios
     */
    private function syncItemsJob(array $payload): array
    {
        $accountId = $payload['account_id'] ?? null;

        if (!$accountId) {
            throw new \Exception('account_id é obrigatório para sync_items');
        }

        $itemService = new ItemService($accountId);
        $limit = (int)($payload['limit'] ?? 50);
        $result = $itemService->syncItems($limit);

        return [
            'account_id' => $accountId,
            'success' => $result['success'] ?? false,
            'synced' => $result['synced'] ?? 0,
            'errors' => $result['errors'] ?? 0,
            'total_found' => $result['total_found'] ?? null,
        ];
    }

    /**
     * Job: Sincronizar perguntas
     */
    private function syncQuestionsJob(array $payload): array
    {
        $accountId = $payload['account_id'] ?? null;

        if (!$accountId) {
            throw new \Exception('account_id é obrigatório para sync_questions');
        }

        $service = new QuestionService((int)$accountId);
        $limit = (int)($payload['limit'] ?? 50);
        $result = $service->syncQuestions($limit);

        return [
            'account_id' => (int)$accountId,
            'synced' => (int)($result['synced'] ?? 0),
            'errors' => (int)($result['errors'] ?? 0),
            'last_error' => $result['last_error'] ?? null,
        ];
    }

    /**
     * Job: Atualizar histórico de preços
     */
    private function updatePriceHistoryJob(array $payload): array
    {
        $categoryId = $payload['category_id'] ?? null;
        $brand = $payload['brand'] ?? null;

        if (!$categoryId || !$brand) {
            throw new \Exception('category_id e brand são obrigatórios');
        }

        $searchService = new SearchService();
        $analysis = $searchService->analyzeListings($categoryId, $brand);

        // Registrar histórico de preços
        if (isset($analysis['prices']['avg'])) {
            $priceHistoryService = new PriceHistoryService();
            // API atual do service: faz a análise e grava o histórico
            $priceHistoryService->recordPriceHistory((string)$categoryId, (string)$brand);
        }

        return [
            'category_id' => $categoryId,
            'brand' => $brand,
            'avg_price' => $analysis['prices']['avg'] ?? null,
        ];
    }

    /**
     * Job: Verificar alertas
     */
    private function checkAlertsJob(array $payload): array
    {
        $alertService = new AlertService();
        $result = $alertService->checkAllAlerts();

        return [
            'alerts_checked' => $result['checked'] ?? 0,
            'alerts_triggered' => $result['triggered'] ?? 0,
        ];
    }

    /**
     * Atualiza status de um job
     */
    private function updateJobStatus(
        int $jobId,
        string $status,
        ?string $errorMessage = null,
        ?\DateTime $startedAt = null,
        ?\DateTime $completedAt = null,
        ?int $attempts = null,
        mixed $result = null,
        ?DateTimeInterface $nextAttemptAt = null
    ): void {
        $updates = ['status = :status'];
        $params = [':status' => $status, ':id' => $jobId];

        if ($errorMessage !== null) {
            $updates[] = 'error_message = :error_message';
            $params[':error_message'] = $errorMessage;
        }

        if ($startedAt !== null) {
            $updates[] = 'started_at = :started_at';
            $params[':started_at'] = $startedAt->format('Y-m-d H:i:s');
        }

        if ($completedAt !== null) {
            $updates[] = 'completed_at = :completed_at';
            $params[':completed_at'] = $completedAt->format('Y-m-d H:i:s');
        }

        if ($attempts !== null) {
            $updates[] = 'attempts = :attempts';
            $params[':attempts'] = $attempts;
        }

        if ($result !== null) {
            $updates[] = 'result = :result';
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
            $params[':result'] = $encoded === false ? null : $encoded;
        }

        if ($status === 'pending') {
            if ($nextAttemptAt !== null) {
                $updates[] = 'next_attempt_at = :next_attempt_at';
                $params[':next_attempt_at'] = $nextAttemptAt->format('Y-m-d H:i:s');
            } else {
                $updates[] = 'next_attempt_at = NULL';
            }
        }

        if (in_array($status, ['completed', 'failed'], true)) {
            $updates[] = 'next_attempt_at = NULL';
        }

        if (in_array($status, ['pending', 'completed', 'failed'], true)) {
            $updates[] = 'claim_token = NULL';
            $updates[] = 'claimed_by = NULL';
            $updates[] = 'claimed_at = NULL';
        }

        $sql = "UPDATE jobs SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Claim atômico de jobs pendentes para evitar processamento duplicado entre workers.
     */
    public function claimPendingJobs(int $limit = 10, ?string $workerId = null): array
    {
        $limitSql = max(1, min((int)$limit, 200));
        $workerId = $this->normalizeWorkerId($workerId);

        return $this->claimJobsBySelection(
            "status = 'pending'
             AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             AND attempts < max_attempts",
            [],
            $limitSql,
            $workerId
        );
    }

    /**
     * Claim atômico de um job específico.
     */
    public function claimJob(int $jobId, ?string $workerId = null): ?array
    {
        $rows = $this->claimJobsBySelection(
            "id = :job_id
             AND status = 'pending'
             AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             AND attempts < max_attempts",
            [':job_id' => $jobId],
            1,
            $this->normalizeWorkerId($workerId)
        );

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function claimJobsBySelection(string $whereSql, array $params, int $limitSql, string $workerId): array
    {
        $claimToken = bin2hex(random_bytes(16));

        $this->db->beginTransaction();
        try {
            $selectSqlBase = "
                SELECT id
                FROM jobs
                WHERE {$whereSql}
                ORDER BY COALESCE(next_attempt_at, scheduled_at, created_at) ASC, created_at ASC
                LIMIT {$limitSql}
                FOR UPDATE
            ";

            $idsStmt = $this->db->prepare($selectSqlBase . ' SKIP LOCKED');
            try {
                $idsStmt->execute($params);
            } catch (\PDOException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                $this->db->beginTransaction();
                $idsStmt = $this->db->prepare($selectSqlBase);
                $idsStmt->execute($params);
            }

            $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);
            $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));

            if ($ids === []) {
                $this->db->commit();
                return [];
            }

            $placeholders = [];
            $updateParams = [
                ':claim_token' => $claimToken,
                ':claimed_by' => $workerId,
            ];
            foreach ($ids as $idx => $id) {
                $ph = ':id_' . $idx;
                $placeholders[] = $ph;
                $updateParams[$ph] = $id;
            }

            $updateStmt = $this->db->prepare(
                "UPDATE jobs
                 SET status = 'processing',
                     started_at = COALESCE(started_at, NOW()),
                     error_message = NULL,
                     claim_token = :claim_token,
                     claimed_by = :claimed_by,
                     claimed_at = NOW()
                 WHERE id IN (" . implode(',', $placeholders) . ")
                   AND status = 'pending'"
            );
            $updateStmt->execute($updateParams);

            if ((int)$updateStmt->rowCount() === 0) {
                $this->db->commit();
                return [];
            }

            $fetchStmt = $this->db->prepare(
                "SELECT *
                 FROM jobs
                 WHERE claim_token = :claim_token
                 ORDER BY created_at ASC"
            );
            $fetchStmt->execute([':claim_token' => $claimToken]);
            $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

            $this->db->commit();

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reagenda jobs presos em processing por timeout de claim.
     */
    public function reclaimStaleProcessingJobs(?int $staleAfterSeconds = null, int $limit = 50): int
    {
        $staleAfterSeconds = $staleAfterSeconds ?? $this->getStaleProcessingTimeoutSeconds();
        $staleAfterSeconds = max(30, min(86400, $staleAfterSeconds));
        $limitSql = max(1, min((int)$limit, 500));

        $cutoff = (new DateTimeImmutable())->modify('-' . $staleAfterSeconds . ' seconds');
        $reclaimDelaySeconds = max(0, min(300, (int)($_ENV['JOB_RECLAIM_RETRY_DELAY_SECONDS'] ?? self::DEFAULT_RECLAIM_DELAY_SECONDS)));
        $nextAttemptAt = (new DateTimeImmutable())->modify('+' . $reclaimDelaySeconds . ' seconds');
        $reclaimErrorMessage = sprintf('Job reclaimado após timeout de %ds em processing', $staleAfterSeconds);

        $this->db->beginTransaction();
        try {
            $selectBase = "
                SELECT id
                FROM jobs
                WHERE status = 'processing'
                  AND claimed_at IS NOT NULL
                  AND claimed_at < :cutoff
                  AND attempts < max_attempts
                ORDER BY claimed_at ASC
                LIMIT {$limitSql}
                FOR UPDATE
            ";

            $idsStmt = $this->db->prepare($selectBase . ' SKIP LOCKED');
            try {
                $idsStmt->execute([':cutoff' => $cutoff->format('Y-m-d H:i:s')]);
            } catch (\PDOException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                $this->db->beginTransaction();
                $idsStmt = $this->db->prepare($selectBase);
                $idsStmt->execute([':cutoff' => $cutoff->format('Y-m-d H:i:s')]);
            }

            $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);
            $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));
            if ($ids === []) {
                $this->db->commit();
                return 0;
            }

            $placeholders = [];
            $updateParams = [
                ':error_message' => $reclaimErrorMessage,
                ':next_attempt_at' => $nextAttemptAt->format('Y-m-d H:i:s'),
            ];
            foreach ($ids as $idx => $id) {
                $ph = ':id_' . $idx;
                $placeholders[] = $ph;
                $updateParams[$ph] = $id;
            }

            $updateStmt = $this->db->prepare(
                "UPDATE jobs
                 SET status = 'pending',
                     claim_token = NULL,
                     claimed_by = NULL,
                     claimed_at = NULL,
                     started_at = NULL,
                     next_attempt_at = :next_attempt_at,
                     error_message = :error_message
                 WHERE id IN (" . implode(',', $placeholders) . ")
                   AND status = 'processing'"
            );
            $updateStmt->execute($updateParams);
            $reclaimed = (int)$updateStmt->rowCount();

            $this->db->commit();

            return $reclaimed;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function calculateRetryDelaySeconds(int $attempts): int
    {
        $attempts = max(1, $attempts);
        $base = max(1, (int)($_ENV['JOB_RETRY_BASE_SECONDS'] ?? self::DEFAULT_RETRY_BASE_SECONDS));
        $max = max($base, (int)($_ENV['JOB_RETRY_MAX_SECONDS'] ?? self::DEFAULT_RETRY_MAX_SECONDS));
        $jitterPercent = (float)($_ENV['JOB_RETRY_JITTER_PERCENT'] ?? self::DEFAULT_RETRY_JITTER_PERCENT);
        $jitterPercent = max(0.0, min(1.0, $jitterPercent));

        $exp = min(20, $attempts - 1);
        $coreDelay = (int)min($max, $base * (2 ** $exp));
        $jitterMax = (int)floor($coreDelay * $jitterPercent);
        $jitter = $jitterMax > 0 ? random_int(0, $jitterMax) : 0;

        return min($max, $coreDelay + $jitter);
    }

    private function getStaleProcessingTimeoutSeconds(): int
    {
        $value = (int)($_ENV['JOB_STALE_PROCESSING_SECONDS'] ?? self::DEFAULT_STALE_PROCESSING_SECONDS);
        return max(60, min(86400, $value));
    }

    private function normalizeWorkerId(?string $workerId): string
    {
        $workerId = trim((string)($workerId ?? ''));
        if ($workerId !== '') {
            return mb_substr($workerId, 0, 128);
        }

        $host = gethostname();
        $host = is_string($host) && $host !== '' ? $host : 'unknown-host';
        return mb_substr(sprintf('%s:%d', $host, getmypid()), 0, 128);
    }

    /**
     * Retorna detalhes do job para consumo por UI/polling.
     * Não inclui payload para evitar vazamento de dados sensíveis.
     */
    public function getJobPublic(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT id, type, status, attempts, max_attempts, error_message, started_at, completed_at, scheduled_at, next_attempt_at, created_at, updated_at, result FROM jobs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return null;
        }

        if (!empty($job['result'])) {
            $decoded = json_decode($job['result'], true);
            $job['result'] = is_array($decoded) ? $decoded : $job['result'];
        } else {
            $job['result'] = null;
        }

        return $job;
    }

    /**
     * Job: Clonar item de catálogo
     */
    private function catalogCloneItemJob(array $payload, ?int $jobId = null): array
    {
        $service = new CatalogCloneService();

        // Injetar o job_id no payload para tracking
        $payload['job_id'] = $jobId;

        // O payload do job deve corresponder exatamente ao que o service espera
        // source_account_id, source_item_id, target_account_id, pricing_strategy, stock_strategy

        $result = $service->cloneCatalogItem($payload);

        if (($result['status'] ?? 'error') === 'error') {
            throw new \Exception($result['message'] ?? 'Erro desconhecido na clonagem');
        }

        return $result;
    }

    /**
     * Job: Análise de Gap em Background
     */
    private function gapAnalysisJob(array $payload): array
    {
        $categoryId = $payload['category_id'] ?? null;
        if (!$categoryId) {
            throw new \Exception('Category ID Required');
        }

        $service = new \App\Services\GapHunterService();
        return $service->analyzeCategory($categoryId, $payload['options'] ?? []);
    }

    /**
     * Job: Geração de AI em Background
     */
    private function aiGenerationJob(array $payload): array
    {
        // Payload: [prompt, system, complexity, context, user_id]
        $prompt = $payload['prompt'] ?? '';
        $system = $payload['system'] ?? '';
        $complexity = $payload['complexity'] ?? 'basic';

        if (empty($prompt)) {
            throw new \Exception('Prompt Required');
        }

        $llm = new \App\Services\LLMService();
        $result = $llm->generate($prompt, $system, $complexity);

        if (!$result['success']) {
            throw new \Exception("AI Error: " . ($result['error'] ?? 'Unknown'));
        }

        return $result;
    }
    /**
     * Obtém estatísticas dos jobs
     */
    public function getStats(): array
    {
        $stmt = $this->db->query("
            SELECT
                status,
                COUNT(*) as count
            FROM jobs
            GROUP BY status
        ");

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['status']] = (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Limpa jobs antigos (completados há mais de X dias)
     */
    public function cleanOldJobs(int $days = 30): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM jobs
            WHERE status IN ('completed', 'failed')
            AND completed_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");

        $stmt->execute([':days' => $days]);

        return $stmt->rowCount();
    }
    /**
     * Obtém status de múltiplos jobs
     */
    public function getJobsStatus(array $jobIds): array
    {
        if (empty($jobIds)) {
            return [];
        }

        // Sanitizar IDs (apenas inteiros)
        $ids = array_map('intval', $jobIds);
        $inQuery = implode(',', $ids);

        $stmt = $this->db->query("
            SELECT id, status, error_message
            FROM jobs
            WHERE id IN ($inQuery)
        ");

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['id']] = $row;
        }

        return $result;
    }
    /**
     * Job: Execução de Otimização em Massa
     */
    private function bulkOptimizeExecJob(array $payload): array
    {
        $seoJobId = $payload['seo_job_id'] ?? null;
        if (!$seoJobId) {
            throw new \Exception('seo_job_id é obrigatório para bulk_optimize_exec');
        }

        // Recuperar o account_id do job de SEO para instanciar o optimizer
        $stmt = $this->db->prepare("SELECT account_id FROM seo_bulk_jobs WHERE id = ?");
        $stmt->execute([$seoJobId]);
        $accountId = $stmt->fetchColumn();

        if (!$accountId) {
            throw new \Exception("SEO Bulk Job #{$seoJobId} não encontrado");
        }

        $optimizer = new BulkOptimizer((int)$accountId);
        return $optimizer->processJob((int)$seoJobId);
    }
}
