<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Projeta somente metadados seguros de origem/disponibilidade para o dashboard.
 * Detalhes de erro e payloads internos nunca atravessam este contrato.
 */
final class PregaoDataSourceStatusService
{
    /** @var array<string, array{label: string, meta_key: string}> */
    private const DEFINITIONS = [
        'sales' => ['label' => 'Vendas e receita', 'meta_key' => 'vendas_hoje'],
        'ads' => ['label' => 'Ads / TACOS', 'meta_key' => 'tacos'],
        'visits' => ['label' => 'Visitas', 'meta_key' => 'visitas_7d'],
        'health' => ['label' => 'Saúde dos anúncios', 'meta_key' => 'health_medio'],
        'reputation' => ['label' => 'Reputação', 'meta_key' => 'reputacao'],
        'questions' => ['label' => 'Perguntas', 'meta_key' => 'perguntas_7d'],
        'ranks' => ['label' => 'Posição orgânica', 'meta_key' => 'posicao_media'],
    ];

    /** @var list<string> */
    private const SAFE_SOURCES = [
        'AdsMetricsCollector',
        'account_health_history',
        'collector_local',
        'items_multiget',
        'items_visits',
        'keyword_ranks',
        'ml_api',
        'ml_orders',
        'rank_history',
        'search_auth',
        'seller_reputation',
        'trends_partial',
    ];

    /** @var list<string> */
    private const SAFE_REASONS = [
        'ml_search_forbidden',
        'rank_tracker_disabled',
        'seller_not_found_in_search',
        'unavailable',
        'watchlist_empty',
        'circuit_open',
        'no_captures',
        'search_forbidden',
    ];

    /**
     * @param array<string, mixed> $meta
     * @param array{count?:int,last_checked_at?:string|null}|null $watchlist
     * @return array{
     *   consolidated_at: string|null,
     *   age_seconds: int|null,
     *   items: list<array{key:string,label:string,available:bool,source:string|null,observed_at:string|null,reason:string|null,count:int|null}>,
     *   read_only: true
     * }
     */
    public function build(
        array $meta,
        ?string $metricsUpdatedAt,
        ?DateTimeImmutable $now = null,
        ?array $watchlist = null,
        ?int $metricsUpdatedEpoch = null
    ): array {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $clock = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $consolidated = $metricsUpdatedEpoch !== null
            ? $this->parseEpoch($metricsUpdatedEpoch, $timezone, $clock)
            : $this->parseDatabaseTimestamp($metricsUpdatedAt, $timezone, $clock);
        $metricMeta = is_array($meta['metrics'] ?? null) ? $meta['metrics'] : [];
        $items = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $entry = is_array($metricMeta[$definition['meta_key']] ?? null)
                ? $metricMeta[$definition['meta_key']]
                : [];
            $available = ($entry['available'] ?? false) === true;
            $source = is_string($entry['source'] ?? null)
                && in_array($entry['source'], self::SAFE_SOURCES, true)
                ? $entry['source']
                : null;
            $reason = null;
            if (!$available) {
                $candidate = is_string($entry['reason'] ?? null) ? $entry['reason'] : 'unavailable';
                $reason = in_array($candidate, self::SAFE_REASONS, true) ? $candidate : 'unavailable';
            }

            $observed = null;
            if ($available && array_key_exists('collected_at', $entry)) {
                $observed = $this->parseEpoch($entry['collected_at'], $timezone, $clock);
            }

            $items[] = [
                'key' => $key,
                'label' => $definition['label'],
                'available' => $available,
                'source' => $source,
                'observed_at' => $observed?->format('Y-m-d\TH:i:sP'),
                'reason' => $reason,
                'count' => null,
            ];
        }

        $watchlistCount = max(0, (int) ($watchlist['count'] ?? 0));
        $watchlistObserved = $watchlistCount > 0 && is_string($watchlist['last_checked_at'] ?? null)
            ? $this->parseDatabaseTimestamp($watchlist['last_checked_at'], $timezone, $clock)
            : null;
        $items[] = [
            'key' => 'watchlist',
            'label' => 'Concorrentes',
            'available' => $watchlistCount > 0,
            'source' => $watchlistCount > 0 ? 'items_multiget' : null,
            'observed_at' => $watchlistObserved?->format('Y-m-d\TH:i:sP'),
            'reason' => $watchlistCount > 0 ? null : 'watchlist_empty',
            'count' => $watchlistCount,
        ];

        return [
            'consolidated_at' => $consolidated?->format('Y-m-d\TH:i:sP'),
            'age_seconds' => $consolidated !== null
                ? max(0, $clock->getTimestamp() - $consolidated->getTimestamp())
                : null,
            'items' => $items,
            'read_only' => true,
        ];
    }

    private function parseEpoch(
        mixed $value,
        DateTimeZone $displayTimezone,
        DateTimeImmutable $clock
    ): ?DateTimeImmutable {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0 || $value > $clock->getTimestamp() + 60) {
            return null;
        }

        return (new DateTimeImmutable('@' . (string) $value))->setTimezone($displayTimezone);
    }

    private function parseDatabaseTimestamp(
        ?string $value,
        DateTimeZone $displayTimezone,
        DateTimeImmutable $clock
    ): ?DateTimeImmutable {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $storageTimezone = new DateTimeZone('UTC');
        $parsed = null;
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i:s.v', 'Y-m-d H:i:s.u'] as $format) {
            $candidate = DateTimeImmutable::createFromFormat('!' . $format, $value, $storageTimezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($candidate !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $candidate->format($format) === $value
            ) {
                $parsed = $candidate;
                break;
            }
        }
        if ($parsed === null) {
            return null;
        }
        $parsed = $parsed->setTimezone($displayTimezone);
        if ($parsed->getTimestamp() > $clock->getTimestamp() + 60) {
            return null;
        }

        return $parsed;
    }
}
