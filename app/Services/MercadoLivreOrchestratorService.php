<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\TokenRefreshJob;

/**
 * Orquestrador oficial dos fluxos operacionais de ML (tokens + polling + fila).
 *
 * Objetivo: fornecer um caminho único para tarefas de cron/CLI e reduzir duplicidade
 * de scripts legados. Os scripts antigos podem delegar para este service.
 */
class MercadoLivreOrchestratorService
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
    }

    public function runQueue(
        int $batchSize = 10,
        int $maxBatches = 1,
        bool $cleanupOldJobs = false,
        int $cleanupDays = 30,
        bool $useLock = true,
        bool $replayFailedWebhooks = true,
        int $webhookReplayLimit = 25
    ): array {
        $runner = function () use (
            $batchSize,
            $maxBatches,
            $cleanupOldJobs,
            $cleanupDays,
            $replayFailedWebhooks,
            $webhookReplayLimit
        ): array {
            return $this->runQueueInternal(
                $batchSize,
                $maxBatches,
                $cleanupOldJobs,
                $cleanupDays,
                $replayFailedWebhooks,
                $webhookReplayLimit
            );
        };

        if (!$useLock) {
            return $runner();
        }

        return $this->withFileLock('queue', $runner);
    }

    /**
     * @param array<int, string> $resources
     * @param array<string, int> $resourceLimits
     */
    public function runPolling(
        array $resources,
        array $resourceLimits = [],
        bool $processQueue = false,
        int $queueBatchSize = 10,
        int $queueMaxBatches = 1,
        bool $cleanupOldJobs = false,
        int $cleanupDays = 30,
        bool $useLock = true
    ): array {
        $normalizedResources = $this->normalizeResources($resources);

        $runner = function () use (
            $normalizedResources,
            $resourceLimits,
            $processQueue,
            $queueBatchSize,
            $queueMaxBatches,
            $cleanupOldJobs,
            $cleanupDays
        ): array {
            return $this->runPollingInternal(
                $normalizedResources,
                $resourceLimits,
                $processQueue,
                $queueBatchSize,
                $queueMaxBatches,
                $cleanupOldJobs,
                $cleanupDays
            );
        };

        if (!$useLock) {
            return $runner();
        }

        $lockKey = 'poll-' . implode('-', $normalizedResources);
        return $this->withFileLock($lockKey, $runner);
    }

    public function runTokenRefresh(
        bool $forceAll = false,
        ?int $accountId = null,
        bool $useLock = true
    ): array {
        $runner = function () use ($forceAll, $accountId, $useLock): array {
            $job = new TokenRefreshJob();
            // Com flock do orchestrator ativo, evitar segundo lock do Unified
            // (corrida com scheduler gerava skip falso: "Lock acquisition failed").
            $innerLock = !$useLock;

            if ($accountId !== null && $accountId > 0) {
                $rawResult = $job->refreshAccount($accountId);
                $result = $this->normalizeAccountRefreshResult($rawResult, $accountId);
                return [
                    'success' => (bool)($result['success'] ?? false),
                    'mode' => 'account',
                    'account_id' => $accountId,
                    'result' => $result,
                    'timestamp' => date('c'),
                ];
            }

            $result = $job->run($forceAll, $innerLock);
            return [
                'success' => $this->resolveBatchRefreshSuccess($result),
                'mode' => $forceAll ? 'force_all' : 'expiring_only',
                'result' => $result,
                'timestamp' => date('c'),
            ];
        };

        if (!$useLock) {
            return $runner();
        }

        return $this->withFileLock('token-refresh', $runner);
    }

    /**
     * Executa ciclo consolidado de manutenção ML:
     * - refresh de tokens
     * - polling (orders/items/questions)
     * - processamento de fila
     *
     * @param array<string, mixed> $options
     */
    public function runCycle(array $options = [], bool $useLock = true): array
    {
        $runner = function () use ($options): array {
            $refresh = $this->runTokenRefresh(
                (bool)($options['force_all'] ?? false),
                isset($options['account_id']) ? (int)$options['account_id'] : null,
                false
            );

            $resourceLimits = [
                'orders' => (int)($options['orders_limit'] ?? 100),
                'items' => (int)($options['items_limit'] ?? 100),
                'questions' => (int)($options['questions_limit'] ?? 50),
            ];

            $poll = $this->runPollingInternal(
                ['orders', 'items', 'questions'],
                $resourceLimits,
                false,
                0,
                0,
                false,
                30
            );

            $queue = $this->runQueueInternal(
                (int)($options['queue_batch_size'] ?? 25),
                (int)($options['queue_max_batches'] ?? 4),
                (bool)($options['cleanup_old_jobs'] ?? false),
                (int)($options['cleanup_days'] ?? 30),
                !isset($options['replay_failed_webhooks']) || (bool)$options['replay_failed_webhooks'],
                (int)($options['webhook_replay_limit'] ?? 25)
            );

            return [
                'success' => (bool)($refresh['success'] ?? false) && (bool)($poll['success'] ?? false) && (bool)($queue['success'] ?? false),
                'mode' => 'cycle',
                'refresh' => $refresh,
                'poll' => $poll,
                'queue' => $queue,
                'timestamp' => date('c'),
            ];
        };

        if (!$useLock) {
            return $runner();
        }

        return $this->withFileLock('cycle', $runner);
    }

    /**
     * @param array<string, int> $resourceLimits
     * @param array<int, string> $resources
     */
    private function runPollingInternal(
        array $resources,
        array $resourceLimits,
        bool $processQueue,
        int $queueBatchSize,
        int $queueMaxBatches,
        bool $cleanupOldJobs,
        int $cleanupDays
    ): array {
        $polling = new PollingService();

        if (!$polling->isPollingEnabled()) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'polling_disabled',
                'resources' => $resources,
                'timestamp' => date('c'),
            ];
        }

        $results = [];
        $jobsCreated = 0;
        $errors = [];

        foreach ($resources as $resource) {
            $resourceResult = match ($resource) {
                'orders' => $polling->pollOrders((int)($resourceLimits['orders'] ?? 100)),
                'items' => $polling->pollItems((int)($resourceLimits['items'] ?? 100)),
                'questions' => $polling->pollQuestions((int)($resourceLimits['questions'] ?? 50)),
                default => throw new \InvalidArgumentException('Recurso de polling inválido: ' . $resource),
            };

            $results[$resource] = $resourceResult;
            $jobsCreated += (int)($resourceResult['jobs_created'] ?? 0);

            if (!empty($resourceResult['errors']) && is_array($resourceResult['errors'])) {
                foreach ($resourceResult['errors'] as $error) {
                    $errors[] = $error;
                }
            }
        }

        $queueResult = null;
        if ($processQueue) {
            $queueResult = $this->runQueueInternal($queueBatchSize, $queueMaxBatches, $cleanupOldJobs, $cleanupDays);
        }

        return [
            'success' => empty($errors),
            'resources' => $resources,
            'jobs_created' => $jobsCreated,
            'results' => $results,
            'errors' => $errors,
            'process_queue' => $processQueue,
            'queue' => $queueResult,
            'timestamp' => date('c'),
        ];
    }

    private function runQueueInternal(
        int $batchSize,
        int $maxBatches,
        bool $cleanupOldJobs,
        int $cleanupDays,
        bool $replayFailedWebhooks = true,
        int $webhookReplayLimit = 25
    ): array {
        $jobService = new JobService();
        $batchSize = max(1, min(500, $batchSize));
        $maxBatches = max(1, min(100, $maxBatches));
        $cleanupDays = max(1, min(3650, $cleanupDays));
        $webhookReplayLimit = max(1, min(1000, $webhookReplayLimit));

        $webhookReplay = [
            'success' => true,
            'skipped' => true,
            'reason' => 'disabled',
            'attempted' => 0,
            'replayed' => 0,
            'failed' => 0,
            'details' => [],
        ];

        if ($replayFailedWebhooks) {
            $autoReplayEnabled = $this->isTrueLikeEnv($_ENV['ML_WEBHOOK_AUTO_REPLAY_ENABLED'] ?? '1');
            if ($autoReplayEnabled) {
                $minFailedAgeSeconds = max(0, min(86400, (int)($_ENV['ML_WEBHOOK_REPLAY_MIN_FAILED_AGE_SECONDS'] ?? 60)));
                try {
                    $replayService = new MercadoLivreWebhookReplayService();
                    $webhookReplay = $replayService->replayFailedEvents(
                        $webhookReplayLimit,
                        null,
                        false,
                        'ml-orchestrator',
                        $minFailedAgeSeconds
                    );
                } catch (\Throwable $e) {
                    $webhookReplay = [
                        'success' => false,
                        'skipped' => false,
                        'reason' => 'exception',
                        'message' => $e->getMessage(),
                        'attempted' => 0,
                        'replayed' => 0,
                        'failed' => 1,
                        'details' => [],
                    ];
                }
            } else {
                $webhookReplay = [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'env_disabled',
                    'attempted' => 0,
                    'replayed' => 0,
                    'failed' => 0,
                    'details' => [],
                ];
            }
        }

        $batches = [];
        $totalProcessed = 0;
        $statusCounts = [
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'skipped' => 0,
        ];

        for ($i = 0; $i < $maxBatches; $i++) {
            $results = $jobService->process($batchSize);
            $count = count($results);
            $totalProcessed += $count;

            $batchSummary = [
                'batch' => $i + 1,
                'processed' => $count,
                'status_counts' => [],
            ];

            foreach ($results as $result) {
                $status = (string)($result['status'] ?? 'unknown');
                if (!isset($statusCounts[$status])) {
                    $statusCounts[$status] = 0;
                }
                $statusCounts[$status]++;

                if (!isset($batchSummary['status_counts'][$status])) {
                    $batchSummary['status_counts'][$status] = 0;
                }
                $batchSummary['status_counts'][$status]++;
            }

            $batches[] = $batchSummary;

            if ($count === 0 || $count < $batchSize) {
                break;
            }
        }

        $deletedJobs = 0;
        if ($cleanupOldJobs) {
            $deletedJobs = $jobService->cleanOldJobs($cleanupDays);
        }

        $replayHealthy = (bool)($webhookReplay['success'] ?? false) || (bool)($webhookReplay['skipped'] ?? false);

        return [
            'success' => $replayHealthy,
            'batch_size' => $batchSize,
            'max_batches' => $maxBatches,
            'batches_run' => count($batches),
            'jobs_processed' => $totalProcessed,
            'status_counts' => $statusCounts,
            'batches' => $batches,
            'cleanup_old_jobs' => $cleanupOldJobs,
            'cleanup_days' => $cleanupDays,
            'deleted_jobs' => $deletedJobs,
            'replay_failed_webhooks' => $replayFailedWebhooks,
            'webhook_replay_limit' => $webhookReplayLimit,
            'webhook_replay' => $webhookReplay,
            'timestamp' => date('c'),
        ];
    }

    /**
     * @param array<int, string> $resources
     * @return array<int, string>
     */
    private function normalizeResources(array $resources): array
    {
        $normalized = [];
        foreach ($resources as $resource) {
            $value = trim(strtolower((string)$resource));
            if ($value === '') {
                continue;
            }
            if (!in_array($value, ['orders', 'items', 'questions'], true)) {
                throw new \InvalidArgumentException('Recurso inválido: ' . $value);
            }
            $normalized[$value] = $value;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Ao menos um recurso de polling é obrigatório');
        }

        return array_values($normalized);
    }

    /**
     * @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function withFileLock(string $scope, callable $callback): array
    {
        $lockDir = $this->projectRoot . '/storage/locks';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new \RuntimeException('Falha ao criar diretório de locks: ' . $lockDir);
        }

        $lockFile = $lockDir . '/ml-orchestrator-' . preg_replace('/[^a-z0-9._-]+/i', '-', $scope) . '.lock';
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Falha ao abrir lock file: ' . $lockFile);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'lock_busy',
                'scope' => $scope,
                'lock_file' => $lockFile,
                'timestamp' => date('c'),
            ];
        }

        try {
            $result = $callback();
            $result['scope'] = $scope;
            $result['lock_file'] = $lockFile;
            $result['timestamp'] = $result['timestamp'] ?? date('c');
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed>|bool $rawResult
     * @return array<string, mixed>
     */
    private function normalizeAccountRefreshResult(array|bool $rawResult, int $accountId): array
    {
        if (is_array($rawResult)) {
            if (!array_key_exists('success', $rawResult)) {
                $rawResult['success'] = false;
            }
            if (!array_key_exists('account_id', $rawResult)) {
                $rawResult['account_id'] = $accountId;
            }
            return $rawResult;
        }

        return [
            'success' => $rawResult,
            'account_id' => $accountId,
            'source' => 'TokenRefreshJob::refreshAccount(bool)',
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function resolveBatchRefreshSuccess(array $result): bool
    {
        if (array_key_exists('success', $result)) {
            return (bool)$result['success'];
        }

        return true;
    }

    private function isTrueLikeEnv(string|bool|int|null $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
