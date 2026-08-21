<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Services\Pregao\PregaoHojeQueueService;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

final class AgentRuntimeWorker
{
    private const ENVIRONMENTS = ['local', 'staging', 'production'];

    private AgentRuntimeAccountSourceInterface $accountSource;
    private AgentRuntimeExecutorInterface $executor;
    private ?AgentRuntimeReporterInterface $reporter;
    private ?PregaoHojeQueueService $hojeQueue;

    public function __construct(
        AgentRuntimeAccountSourceInterface $accountSource,
        AgentRuntimeExecutorInterface $executor,
        ?AgentRuntimeReporterInterface $reporter = null,
        ?PregaoHojeQueueService $hojeQueue = null
    ) {
        $this->accountSource = $accountSource;
        $this->executor = $executor;
        $this->reporter = $reporter;
        $this->hojeQueue = $hojeQueue;
    }

    /**
     * @return list<array{
     *   accountId: int,
     *   correlation: string,
     *   status: string,
     *   reason: string,
     *   attempts: int,
     *   queues: array<string, mixed>
     * }>
     */
    public function runCycle(string $environment, string $cycleId, int $maxAttempts = 2): array
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $cycleId) !== 1
            || $maxAttempts < 1
            || $maxAttempts > 3
        ) {
            throw new InvalidArgumentException('invalid worker cycle options');
        }

        $accountIds = $this->accountSource->activeAccountIds();
        $this->assertAccountIds($accountIds);
        $records = [];

        foreach ($accountIds as $accountId) {
            $correlationId = $cycleId . ':' . $accountId;
            $attempts = 0;
            do {
                $attempts++;
                try {
                    $result = $this->executor->execute(
                        $accountId,
                        $correlationId,
                        $environment,
                        'monitor'
                    );
                } catch (Throwable) {
                    $result = AgentResult::failed('agent-runtime', 'runtime_exception');
                }
            } while ($result->status() === 'failed' && $attempts < $maxAttempts);

            if ($result->status() === 'failed') {
                $this->logAggregateFailure($accountId, $correlationId, $result, $attempts);
            }

            if ($this->reporter !== null) {
                try {
                    $this->reporter->report($accountId, $correlationId, $result, $attempts);
                } catch (Throwable) {
                    log_warning('AgentRuntimeWorker: falha na telemetria Pregão', [
                        'account_id' => $accountId,
                        'correlation_id' => $correlationId,
                        'reason' => 'telemetry_exception',
                    ]);
                }
            }

            $queues = $this->observeQueueHeartbeat($accountId);
            $records[] = [
                'accountId' => $accountId,
                'correlation' => $correlationId,
                'status' => $result->status(),
                'reason' => $result->reason(),
                'attempts' => $attempts,
                'queues' => $queues,
            ];
        }

        return $records;
    }

    /**
     * Loga a causa raiz de uma falha agregada do orquestrador (ou do runtime em si),
     * listando qual(is) sub-agente(s) falharam e com que motivo — "agent failed" sozinho
     * não é auto-diagnosticável.
     */
    private function logAggregateFailure(
        int $accountId,
        string $correlationId,
        AgentResult $result,
        int $attempts
    ): void {
        $breakdown = [];
        $children = $result->data()['results'] ?? null;
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof AgentResult) {
                    $breakdown[] = [
                        'agent' => $child->agent(),
                        'status' => $child->status(),
                        'reason' => $child->reason(),
                    ];
                }
            }
        }

        log_warning('AgentRuntimeWorker: ciclo de agente falhou', [
            'account_id' => $accountId,
            'correlation_id' => $correlationId,
            'agent' => $result->agent(),
            'reason' => $result->reason(),
            'attempts' => $attempts,
            'failed_children' => array_values(array_filter(
                $breakdown,
                static fn (array $entry): bool => $entry['status'] === 'failed'
            )),
        ]);
    }

    /**
     * Read-only: which of the three observe+queue buckets are open.
     * No write tools. Fail-soft.
     *
     * @return array<string, mixed>
     */
    private function observeQueueHeartbeat(int $accountId): array
    {
        if ($this->hojeQueue === null) {
            return ['refreshed' => false];
        }

        try {
            $snap = $this->hojeQueue->build($accountId);
        } catch (Throwable) {
            return ['refreshed' => false];
        }

        $open = [];
        foreach ($snap['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if (!in_array($id, PregaoHojeQueueService::OBSERVE_QUEUE_IDS, true)) {
                continue;
            }
            if (in_array((string) ($item['severity'] ?? ''), ['critico', 'alto', 'medio'], true)) {
                $open[] = $id;
            }
        }

        $payload = [
            'refreshed' => true,
            'account_id' => $accountId,
            'open' => $open,
            'apply_blocked' => (bool) ($snap['apply_blocked'] ?? true),
            'ml_write' => (bool) ($snap['ml_write'] ?? false),
            'source' => (string) ($snap['source'] ?? 'local'),
        ];
        if (function_exists('log_info')) {
            log_info('observe-queue heartbeat: ficha/perguntas/ads refreshed', $payload);
        }

        return $payload;
    }

    /** @param array<array-key, mixed> $accountIds */
    private function assertAccountIds(array $accountIds): void
    {
        if ($accountIds !== [] && array_keys($accountIds) !== range(0, count($accountIds) - 1)) {
            throw new UnexpectedValueException('invalid account list');
        }
        if (count($accountIds) > 200) {
            throw new UnexpectedValueException('invalid account list');
        }

        $seen = [];
        foreach ($accountIds as $accountId) {
            if (!is_int($accountId) || $accountId <= 0 || isset($seen[$accountId])) {
                throw new UnexpectedValueException('invalid account list');
            }
            $seen[$accountId] = true;
        }
    }
}
