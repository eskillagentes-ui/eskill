<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;

/**
 * Prioriza ações oficiais de /item/{id}/performance para ativar exposição na busca ML.
 *
 * Fonte canônica: API /performance (substitui /health desde fev/2025).
 * WARNING derruba score até corrigir; OPPORTUNITY aumenta qualidade/exposição.
 *
 * Somente leitura — não altera anúncios.
 */
final class ListingSearchVisibilityService
{
    /** Chaves de regra fortemente ligadas a descoberta na busca. */
    private const SEARCH_ENGINE_RULE_KEYS = [
        'TITLE_LENGTH_MIN',
        'UP_TITLE_LENGTH_MIN',
        'PICTURES_QUANTITY_MIN',
        'UP_PICTURES_QUANTITY_MIN',
        'HAS_GTIN',
        'UP_HAS_GTIN',
        'TS_MAIN_QUANTITY',
        'UP_TS_MAIN_QUANTITY',
        'TS_MAIN_QUALITY_INCOMPLETE_REQUIRED',
        'UP_TS_MAIN_QUALITY_INCOMPLETE_REQUIRED',
        'HAS_STOCK_DEPOSITO',
        'UP_HAS_STOCK_DEPOSITO',
        'HAS_FREE_SHIPPING',
        'UP_HAS_FREE_SHIPPING',
        'HAS_MERCADO_ENVIOS',
        'UP_HAS_MERCADO_ENVIOS',
        'BEST_FINANCING',
        'UP_BEST_FINANCING',
        'BEST_STOCK_AVAILABILITY_TIME',
        'UP_BEST_STOCK_AVAILABILITY_TIME',
    ];

    public function __construct(
        private readonly MercadoLivreClient $client,
    ) {}

    /**
     * Relatório de visibilidade de busca para um anúncio (read-only).
     *
     * @return array<string, mixed>
     */
    public function analyzeListing(string $itemId): array
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return [
                'error' => 'invalid_item_id',
                'message' => 'item_id obrigatório',
            ];
        }

        $performance = $this->client->getItemPerformance($itemId);
        if (isset($performance['error'])) {
            return [
                'listing_id' => $itemId,
                'error' => $performance['error'],
                'source' => 'ml_performance',
            ];
        }

        $actions = $this->extractPendingActions($performance);
        $prioritized = $this->prioritizeSeoActions($actions);
        $score = (int) ($performance['score'] ?? 0);
        $levelWording = (string) ($performance['level_wording'] ?? $performance['level'] ?? '');

        $moderation = $this->client->getLastModeration($itemId);
        $moderationNormalized = $this->normalizeModeration($moderation);

        $activation = $this->classifySearchActivation(
            $score,
            $levelWording,
            $prioritized,
            $moderationNormalized
        );

        return [
            'listing_id' => $itemId,
            'source' => 'ml_performance',
            'score' => $score,
            'level' => (string) ($performance['level'] ?? ''),
            'level_wording' => $levelWording,
            'calculated_at' => $performance['calculated_at'] ?? null,
            'search_activation' => $activation,
            'pending_warnings' => count(array_filter(
                $prioritized,
                static fn(array $a): bool => ($a['mode'] ?? '') === 'WARNING'
            )),
            'pending_opportunities' => count(array_filter(
                $prioritized,
                static fn(array $a): bool => ($a['mode'] ?? '') === 'OPPORTUNITY'
            )),
            'seo_actions' => $prioritized,
            'moderation' => $moderationNormalized,
            'write_enabled' => false,
            'message' => 'Somente leitura — nenhuma alteração enviada ao Mercado Livre',
        ];
    }

    /**
     * Fila priorizada de anúncios ativos para alavancar busca.
     *
     * @param list<string>|null $itemIds Se null, usa amostra de itens active via getMyItems
     * @return array{queue: list<array<string, mixed>>, scanned: int, errors: int}
     */
    public function buildSearchActivationQueue(?array $itemIds = null, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $ids = $itemIds ?? $this->fetchActiveItemSample($limit);

        $queue = [];
        $errors = 0;

        foreach (array_slice($ids, 0, $limit) as $itemId) {
            if (!is_string($itemId) || trim($itemId) === '') {
                continue;
            }

            $report = $this->analyzeListing($itemId);
            if (isset($report['error'])) {
                $errors++;
                continue;
            }

            $priority = $this->queuePriorityScore($report);
            $queue[] = [
                'listing_id' => $report['listing_id'],
                'score' => $report['score'],
                'level_wording' => $report['level_wording'],
                'search_activation' => $report['search_activation'],
                'pending_warnings' => $report['pending_warnings'],
                'pending_opportunities' => $report['pending_opportunities'],
                'top_action' => $report['seo_actions'][0] ?? null,
                'has_moderation' => ($report['moderation']['active'] ?? false) === true,
                'priority_score' => $priority,
            ];
        }

        usort(
            $queue,
            static fn(array $a, array $b): int => ($b['priority_score'] <=> $a['priority_score'])
        );

        return [
            'queue' => $queue,
            'scanned' => count($ids),
            'errors' => $errors,
            'write_enabled' => false,
        ];
    }

    /**
     * Extrai rules PENDING do payload /performance.
     *
     * @param array<string, mixed> $performance
     * @return list<array<string, mixed>>
     */
    public function extractPendingActions(array $performance): array
    {
        $actions = [];

        foreach ($performance['buckets'] ?? [] as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $bucketKey = (string) ($bucket['key'] ?? '');

            foreach ($bucket['variables'] ?? [] as $variable) {
                if (!is_array($variable) || ($variable['status'] ?? '') !== 'PENDING') {
                    continue;
                }

                foreach ($variable['rules'] ?? [] as $rule) {
                    if (!is_array($rule) || ($rule['status'] ?? '') !== 'PENDING') {
                        continue;
                    }

                    $key = (string) ($rule['key'] ?? '');
                    $mode = strtoupper((string) ($rule['mode'] ?? 'OPPORTUNITY'));
                    $actions[] = [
                        'bucket' => $bucketKey,
                        'variable' => (string) ($variable['key'] ?? ''),
                        'key' => $key,
                        'mode' => $mode,
                        'progress' => isset($rule['progress']) ? (float) $rule['progress'] : null,
                        'title' => (string) ($rule['wordings']['title'] ?? $variable['title'] ?? ''),
                        'label' => (string) ($rule['wordings']['label'] ?? ''),
                        'link' => (string) ($rule['wordings']['link'] ?? ''),
                        'affects_search' => in_array($key, self::SEARCH_ENGINE_RULE_KEYS, true)
                            || str_contains($key, 'TITLE')
                            || str_contains($key, 'PICTURE')
                            || str_contains($key, 'GTIN')
                            || str_contains($key, 'TS_MAIN'),
                    ];
                }
            }
        }

        return $actions;
    }

    /**
     * WARNING primeiro, depois OPPORTUNITY que afetam busca, depois demais.
     *
     * @param list<array<string, mixed>> $actions
     * @return list<array<string, mixed>>
     */
    public function prioritizeSeoActions(array $actions): array
    {
        usort($actions, function (array $a, array $b): int {
            $modeRank = static function (array $row): int {
                return ($row['mode'] ?? '') === 'WARNING' ? 0 : 1;
            };
            $searchRank = static function (array $row): int {
                return !empty($row['affects_search']) ? 0 : 1;
            };

            return ($modeRank($a) <=> $modeRank($b))
                ?: ($searchRank($a) <=> $searchRank($b))
                ?: strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });

        return array_values($actions);
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed>|null $moderation
     */
    public function classifySearchActivation(
        int $score,
        string $levelWording,
        array $actions,
        ?array $moderation = null
    ): string {
        if (($moderation['active'] ?? false) === true) {
            $severity = (string) ($moderation['severity'] ?? 'block');
            if (in_array($severity, ['block', 'exposure_loss'], true)) {
                return 'blocked';
            }
        }

        $warnings = count(array_filter(
            $actions,
            static fn(array $a): bool => ($a['mode'] ?? '') === 'WARNING'
        ));

        if ($warnings > 0 || $score < 40) {
            return 'critical';
        }

        $level = mb_strtolower($levelWording);
        if ($score < 70 || str_contains($level, 'básica') || str_contains($level, 'basica') || str_contains($level, 'basic')) {
            return 'improve';
        }

        if ($score >= 85 && $warnings === 0) {
            return 'healthy';
        }

        return 'improve';
    }

    /**
     * @param list<array<string, mixed>>|array{error?: string} $raw
     * @return array{active: bool, severity: string, name: ?string, reason: ?string, remedy: ?string, evidences: list<array<string, mixed>>}
     */
    public function normalizeModeration(array $raw): array
    {
        if (isset($raw['error'])) {
            return [
                'active' => false,
                'severity' => 'none',
                'name' => null,
                'reason' => null,
                'remedy' => null,
                'evidences' => [],
                'error' => (string) $raw['error'],
            ];
        }

        // API retorna lista; às vezes envelope
        $rows = $raw;
        if (isset($raw[0]) && is_array($raw[0])) {
            $rows = $raw;
        } elseif (isset($raw['name'])) {
            $rows = [$raw];
        } else {
            $rows = [];
        }

        if ($rows === []) {
            return [
                'active' => false,
                'severity' => 'none',
                'name' => null,
                'reason' => null,
                'remedy' => null,
                'evidences' => [],
            ];
        }

        /** @var array<string, mixed> $first */
        $first = $rows[0];
        $reason = null;
        $remedy = null;
        foreach ($first['wordings'] ?? [] as $wording) {
            if (!is_array($wording)) {
                continue;
            }
            $type = strtoupper((string) ($wording['type'] ?? ''));
            if ($type === 'REASON') {
                $reason = (string) ($wording['value'] ?? '');
            }
            if ($type === 'REMEDY') {
                $remedy = (string) ($wording['value'] ?? '');
            }
        }

        $name = (string) ($first['name'] ?? '');
        $severity = $this->mapModerationSeverity($name, $remedy !== null && $remedy !== '');

        $evidences = [];
        foreach (($first['evidences'] ?? $first['evidence'] ?? []) as $evidence) {
            if (is_array($evidence)) {
                $evidences[] = $evidence;
            }
        }

        return [
            'active' => true,
            'severity' => $severity,
            'name' => $name !== '' ? $name : null,
            'reason' => $reason,
            'remedy' => $remedy,
            'evidences' => $evidences,
            'date_created' => $first['date_created'] ?? null,
        ];
    }

    private function mapModerationSeverity(string $name, bool $hasRemedy): string
    {
        $upper = strtoupper($name);
        if ($upper === 'DENYLIST' || !$hasRemedy && $upper !== '') {
            return 'block';
        }
        if (str_contains($upper, 'THUMBNAIL') || str_contains($upper, 'POOR_QUALITY')) {
            return 'exposure_loss';
        }
        if (str_contains($upper, 'PAUSED') || str_contains($upper, 'ABANDONED')) {
            return 'block';
        }

        return $hasRemedy ? 'block' : 'block';
    }

    /**
     * @param array<string, mixed> $report
     */
    private function queuePriorityScore(array $report): int
    {
        $activation = (string) ($report['search_activation'] ?? 'improve');
        $base = match ($activation) {
            'blocked' => 1000,
            'critical' => 800,
            'improve' => 400,
            'healthy' => 50,
            default => 100,
        };

        $base += ((int) ($report['pending_warnings'] ?? 0)) * 40;
        $base += ((int) ($report['pending_opportunities'] ?? 0)) * 5;
        $base += max(0, 100 - (int) ($report['score'] ?? 0));

        if (!empty($report['has_moderation'])) {
            $base += 200;
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    private function fetchActiveItemSample(int $limit): array
    {
        $response = $this->client->getMyItems([
            'status' => 'active',
            'limit' => $limit,
            'offset' => 0,
        ]);

        if (isset($response['error'])) {
            return [];
        }

        $results = $response['results'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        $ids = [];
        foreach ($results as $row) {
            if (is_string($row) && $row !== '') {
                $ids[] = $row;
            } elseif (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }
}
