<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use Throwable;

/**
 * Projeta o último estado sanitizado dos agentes para o snapshot do Pregão.
 */
final class PregaoAgentStatusService
{
    public const STALE_AFTER_SECONDS = 600;

    /** @var list<string> */
    private const EXPECTED_AGENTS = [
        'sentinela',
        'collector',
        'financeiro',
        'otimizador',
        'orquestrador',
    ];

    /** @var list<string> */
    private const STATUSES = ['success', 'skipped', 'blocked', 'failed'];

    /** @var list<string> */
    private const REASONS = [
        'aggregated',
        'agent_blocked',
        'agent_exception',
        'agent_failed',
        'collector_unavailable',
        'cost_validation_blocked',
        'financeiro_unavailable',
        'incomplete_legacy_payload',
        'invalid_legacy_payload',
        'invalid_optimizer_cost_snapshot',
        'invalid_optimizer_observation_snapshot',
        'legacy_error',
        'legacy_read_complete',
        'read_only_violation',
        'recommendations_ready',
        'runtime_exception',
        'sentinela_unavailable',
    ];

    /** @var array<string, list<string>> */
    private const REASONS_BY_STATUS = [
        'success' => ['aggregated', 'legacy_read_complete', 'recommendations_ready'],
        'skipped' => ['legacy_read_complete'],
        'blocked' => ['agent_blocked', 'cost_validation_blocked', 'read_only_violation'],
        'failed' => [
            'agent_exception',
            'agent_failed',
            'collector_unavailable',
            'financeiro_unavailable',
            'incomplete_legacy_payload',
            'invalid_legacy_payload',
            'invalid_optimizer_cost_snapshot',
            'invalid_optimizer_observation_snapshot',
            'legacy_error',
            'read_only_violation',
            'runtime_exception',
            'sentinela_unavailable',
        ],
    ];

    /** @var list<string> */
    private const PAYLOAD_KEYS = [
        'agent',
        'attempts',
        'correlation_id',
        'ml_write_automation',
        'reason',
        'state_changed',
        'status',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \App\Database::getInstance();
    }

    /**
     * @return array{
     *   summary: array{overall: string, total: int, reporting: int, healthy: int, attention: int, stale: int},
     *   items: list<array{agent: string, status: string, reason: string, correlation_id: string|null, attempts: int, state_changed: bool, ml_write_automation: bool, updated_at: string|null, stale: bool}>
     * }
     */
    public function latestForAccount(
        int $accountId,
        bool $seedEnabled = false,
        ?DateTimeImmutable $now = null
    ): array {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('invalid agent status account');
        }

        $clock = $now ?? new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $items = [];
        foreach (self::EXPECTED_AGENTS as $agent) {
            $items[$agent] = $this->emptyItem($agent);
        }

        try {
            $sourceFilter = (!$seedEnabled && $this->hasSourceColumn()) ? " AND source <> 'seed'" : '';
            $stmt = $this->db->prepare(
                "SELECT id, payload, ts FROM pregao_events
                 WHERE type = ? AND account_id = ?{$sourceFilter}
                 ORDER BY ts DESC, id DESC LIMIT 100"
            );
            $stmt->execute(['agent.status', $accountId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $payload = self::validatePayload($row['payload'] ?? null, $accountId);
                if ($payload === null) {
                    continue;
                }
                $agent = $payload['agent'];
                if ($items[$agent]['updated_at'] !== null) {
                    continue;
                }
                $updatedAt = $this->parseTimestamp((string) ($row['ts'] ?? ''));
                if ($updatedAt === null) {
                    continue;
                }
                $age = $clock->getTimestamp() - $updatedAt->getTimestamp();
                $items[$agent] = [
                    'agent' => $agent,
                    'status' => $payload['status'],
                    'reason' => $payload['reason'],
                    'correlation_id' => $payload['correlation_id'],
                    'attempts' => $payload['attempts'],
                    'state_changed' => $payload['state_changed'],
                    'ml_write_automation' => false,
                    'updated_at' => $updatedAt->format('Y-m-d\TH:i:sP'),
                    'stale' => $age < -60 || $age > self::STALE_AFTER_SECONDS,
                ];
            }
        } catch (Throwable) {
            log_warning('PregaoAgentStatusService: falha ao ler agentes', [
                'account_id' => $accountId,
                'reason' => 'snapshot_read_exception',
            ]);
        }

        $list = array_values($items);
        $reporting = count(array_filter($list, static fn (array $item): bool => $item['updated_at'] !== null));
        $healthy = count(array_filter(
            $list,
            static fn (array $item): bool => !$item['stale']
                && in_array($item['status'], ['success', 'skipped'], true)
        ));
        $attention = count(array_filter(
            $list,
            static fn (array $item): bool => $item['updated_at'] !== null
                && ($item['stale'] || in_array($item['status'], ['blocked', 'failed'], true))
        ));
        if ($reporting > 0) {
            $attention += count(self::EXPECTED_AGENTS) - $reporting;
        }
        $correlations = array_values(array_unique(array_filter(array_map(
            static fn (array $item): ?string => $item['updated_at'] !== null ? $item['correlation_id'] : null,
            $list
        ))));
        if ($reporting === count(self::EXPECTED_AGENTS) && count($correlations) !== 1) {
            $attention++;
        }
        $stale = count(array_filter(
            $list,
            static fn (array $item): bool => $item['updated_at'] !== null && $item['stale']
        ));
        $overall = $reporting === 0 ? 'waiting' : ($attention > 0 ? 'attention' : 'healthy');

        return [
            'summary' => [
                'overall' => $overall,
                'total' => count(self::EXPECTED_AGENTS),
                'reporting' => $reporting,
                'healthy' => $healthy,
                'attention' => $attention,
                'stale' => $stale,
            ],
            'items' => $list,
        ];
    }

    /**
     * @return array{agent: string, status: string, reason: string, correlation_id: string, attempts: int, state_changed: bool, ml_write_automation: bool}|null
     */
    public static function validatePayload(array|string|null $raw, int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        try {
            $payload = is_string($raw)
                ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR)
                : $raw;
        } catch (JsonException) {
            return null;
        }

        $payloadKeys = is_array($payload) ? array_keys($payload) : [];
        sort($payloadKeys, SORT_STRING);
        if (!is_array($payload)
            || $payloadKeys !== self::PAYLOAD_KEYS
            || !is_string($payload['agent'])
            || !in_array($payload['agent'], self::EXPECTED_AGENTS, true)
            || !is_string($payload['status'])
            || !in_array($payload['status'], self::STATUSES, true)
            || !is_string($payload['reason'])
            || !self::isReasonAllowed($payload['reason'])
            || !self::isStatusReasonCoherent($payload['status'], $payload['reason'])
            || !is_string($payload['correlation_id'])
            || preg_match(
                '/^agent24x7-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}:'
                    . preg_quote((string) $accountId, '/') . '$/D',
                $payload['correlation_id']
            ) !== 1
            || !is_int($payload['attempts'])
            || $payload['attempts'] < 1
            || $payload['attempts'] > 3
            || $payload['state_changed'] !== false
            || $payload['ml_write_automation'] !== false
        ) {
            return null;
        }

        return $payload;
    }

    private static function isReasonAllowed(string $reason): bool
    {
        return in_array($reason, self::REASONS, true)
            || preg_match('/^legacy_http_[1345][0-9]{2}$/D', $reason) === 1;
    }

    public static function isStatusReasonCoherent(string $status, string $reason): bool
    {
        if (preg_match('/^legacy_http_[1345][0-9]{2}$/D', $reason) === 1) {
            return $status === 'failed';
        }
        return in_array($reason, self::REASONS_BY_STATUS[$status] ?? [], true);
    }

    private function parseTimestamp(string $timestamp): ?DateTimeImmutable
    {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i:s.v', 'Y-m-d H:i:s.u'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $timestamp, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed->format($format) === $timestamp
            ) {
                return $parsed;
            }
        }
        return null;
    }

    private function hasSourceColumn(): bool
    {
        try {
            $this->db->query('SELECT source FROM pregao_events WHERE 1 = 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{agent: string, status: string, reason: string, correlation_id: null, attempts: int, state_changed: bool, ml_write_automation: bool, updated_at: null, stale: bool}
     */
    private function emptyItem(string $agent): array
    {
        return [
            'agent' => $agent,
            'status' => 'waiting',
            'reason' => 'no_data',
            'correlation_id' => null,
            'attempts' => 0,
            'state_changed' => false,
            'ml_write_automation' => false,
            'updated_at' => null,
            'stale' => true,
        ];
    }
}
