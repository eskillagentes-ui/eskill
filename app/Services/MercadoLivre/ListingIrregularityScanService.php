<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;

/**
 * Varredura read-only de irregularidades que travam ou reduzem vendas no ML.
 *
 * Usa items/search (under_review, paused+moderation_penalty) + last_moderation.
 * Não reativa nem edita anúncios.
 */
final class ListingIrregularityScanService
{
    public function __construct(
        private readonly MercadoLivreClient $client,
        private readonly ListingSearchVisibilityService $visibilityService,
    ) {}

    /**
     * @return array{
     *   blocked: list<array<string, mixed>>,
     *   totals: array<string, int>,
     *   write_enabled: bool,
     *   scanned_at: string
     * }
     */
    public function scan(int $limitPerBucket = 30): array
    {
        $limitPerBucket = max(1, min(50, $limitPerBucket));

        $underReview = $this->searchItemIds(['status' => 'under_review', 'limit' => $limitPerBucket]);
        $pausedPenalty = $this->searchItemIds([
            'status' => 'paused',
            'tags' => 'moderation_penalty',
            'limit' => $limitPerBucket,
        ]);

        $combined = [];
        foreach ($underReview as $id) {
            $combined[$id] = 'under_review';
        }
        foreach ($pausedPenalty as $id) {
            $combined[$id] = $combined[$id] ?? 'paused_moderation_penalty';
        }

        $blocked = [];
        foreach ($combined as $itemId => $sourceStatus) {
            $raw = $this->client->getLastModeration($itemId);
            $moderation = $this->visibilityService->normalizeModeration(
                is_array($raw) ? $raw : ['error' => 'invalid_response']
            );

            $blocked[] = [
                'listing_id' => $itemId,
                'source_status' => $sourceStatus,
                'severity' => $moderation['severity'] ?? 'block',
                'moderation' => $moderation,
                'next_step' => $this->suggestNextStep($moderation),
            ];
        }

        usort(
            $blocked,
            static function (array $a, array $b): int {
                $rank = static fn(array $row): int => match ($row['severity'] ?? '') {
                    'block' => 0,
                    'exposure_loss' => 1,
                    default => 2,
                };
                return $rank($a) <=> $rank($b);
            }
        );

        return [
            'blocked' => $blocked,
            'totals' => [
                'under_review' => count($underReview),
                'paused_moderation_penalty' => count($pausedPenalty),
                'unique' => count($combined),
            ],
            'write_enabled' => false,
            'scanned_at' => gmdate('c'),
            'message' => 'Somente leitura — use reason/remedy oficiais; não reativar automaticamente',
        ];
    }

    /**
     * Infrações recentes do vendedor (histórico oficial).
     *
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    public function listInfractions(array $params = []): array
    {
        $sellerId = $this->client->getSellerId();
        if ($sellerId === null || $sellerId === '') {
            return [
                'error' => 'seller_not_found',
                'infractions' => [],
                'write_enabled' => false,
            ];
        }

        $response = $this->client->getModerationInfractions((string) $sellerId, $params);
        $response['write_enabled'] = false;
        $response['seller_id'] = (string) $sellerId;

        return $response;
    }

    /**
     * @param array<string, mixed> $moderation
     */
    private function suggestNextStep(array $moderation): string
    {
        if (($moderation['active'] ?? false) !== true) {
            return 'Revisar status do anúncio no ML e confirmar se ainda há restrição.';
        }

        $remedy = trim((string) ($moderation['remedy'] ?? ''));
        if ($remedy !== '') {
            return $remedy;
        }

        $reason = trim((string) ($moderation['reason'] ?? ''));
        if ($reason !== '') {
            return 'Sem remedy recuperável. Motivo: ' . $reason;
        }

        return 'Consultar detalhe da moderação no Mercado Livre antes de qualquer ação.';
    }

    /**
     * @param array<string, scalar> $params
     * @return list<string>
     */
    private function searchItemIds(array $params): array
    {
        $response = $this->client->getMyItems($params);
        if (isset($response['error'])) {
            log_warning('ListingIrregularityScan: falha items/search', [
                'params' => $params,
                'error' => $response['error'],
                'message' => $response['message'] ?? null,
            ]);
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

        return array_values(array_unique($ids));
    }
}
