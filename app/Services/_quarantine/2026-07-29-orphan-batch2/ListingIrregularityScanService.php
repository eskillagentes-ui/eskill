<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;

/**
 * Varredura read-only de irregularidades que travam ou reduzem vendas no ML.
 *
 * Buckets oficiais (MCP gerenciar-moderacoes / com-pausa / moderacoes-de-imagens):
 * - status=under_review
 * - status=pending (itens moderados agregados)
 * - status=paused + tags=moderation_penalty
 * - status=active + tags=poor_quality_thumbnail (perda de exposição)
 *
 * Detalhe: GET /moderations/last_moderation/{ITEM}-ITM (resposta = array).
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
        $pending = $this->searchItemIds(['status' => 'pending', 'limit' => $limitPerBucket]);
        $pausedPenalty = $this->searchItemIds([
            'status' => 'paused',
            'tags' => 'moderation_penalty',
            'limit' => $limitPerBucket,
        ]);
        $poorThumbnail = $this->searchItemIds([
            'status' => 'active',
            'tags' => 'poor_quality_thumbnail',
            'limit' => $limitPerBucket,
        ]);

        // Prioridade de rótulo quando o mesmo item aparece em mais de um bucket
        $combined = [];
        foreach ($underReview as $id) {
            $combined[$id] = 'under_review';
        }
        foreach ($pending as $id) {
            $combined[$id] = $combined[$id] ?? 'pending';
        }
        foreach ($pausedPenalty as $id) {
            $combined[$id] = $combined[$id] ?? 'paused_moderation_penalty';
        }
        foreach ($poorThumbnail as $id) {
            $combined[$id] = $combined[$id] ?? 'active_poor_quality_thumbnail';
        }

        $blocked = [];
        foreach ($combined as $itemId => $sourceStatus) {
            $raw = $this->client->getLastModeration($itemId);
            $moderation = $this->visibilityService->normalizeModeration(
                is_array($raw) ? $raw : ['error' => 'invalid_response']
            );

            // Thumbnail pobre sem last_moderation ainda é exposição perdida
            if (
                ($moderation['active'] ?? false) !== true
                && $sourceStatus === 'active_poor_quality_thumbnail'
            ) {
                $moderation = [
                    'active' => true,
                    'severity' => 'exposure_loss',
                    'name' => 'POOR_QUALITY_THUMBNAIL',
                    'reason' => 'Tag poor_quality_thumbnail ativa no anúncio',
                    'remedy' => 'Corrija a foto de capa (fundo branco / qualidade) para recuperar exposição.',
                    'evidences' => [],
                ];
            }

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
                'pending' => count($pending),
                'paused_moderation_penalty' => count($pausedPenalty),
                'active_poor_quality_thumbnail' => count($poorThumbnail),
                'unique' => count($combined),
            ],
            'write_enabled' => false,
            'scanned_at' => gmdate('c'),
            'message' => 'Somente leitura — use reason/remedy oficiais; não reativar automaticamente',
            'source' => 'ml_moderations_official',
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
