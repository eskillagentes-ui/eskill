<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

use App\Services\MercadoLivreClient;

/**
 * Qualidade do anúncio via API /performance (substitui /health).
 *
 * @see https://developers.mercadolivre.com.br/pt_br/qualidade-das-publicacoes
 * @see https://api.mercadolibre.com/item/$ITEM_ID/performance
 */
class ItemPerformanceService
{
    private MercadoLivreClient $mlClient;

    public function __construct(int $accountId, ?MercadoLivreClient $mlClient = null)
    {
        $this->mlClient = $mlClient ?? new MercadoLivreClient($accountId);
    }

    /**
     * @return array{
     *   success:bool,
     *   item_id:string,
     *   score:?float,
     *   level:?string,
     *   level_wording:?string,
     *   pending_tech_specs:bool,
     *   pending_gtin:bool,
     *   pending_rules:list<array{key:string,mode:string,title:string}>,
     *   raw?:array,
     *   error?:string
     * }
     */
    public function getItemPerformance(string $itemId): array
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return ['success' => false, 'item_id' => '', 'score' => null, 'level' => null, 'level_wording' => null, 'pending_tech_specs' => false, 'pending_gtin' => false, 'pending_rules' => [], 'error' => 'item_id inválido'];
        }

        try {
            $raw = $this->mlClient->get("/item/{$itemId}/performance");
            if (!is_array($raw) || isset($raw['error'])) {
                return [
                    'success' => false,
                    'item_id' => $itemId,
                    'score' => null,
                    'level' => null,
                    'level_wording' => null,
                    'pending_tech_specs' => false,
                    'pending_gtin' => false,
                    'pending_rules' => [],
                    'error' => (string)($raw['message'] ?? $raw['error'] ?? 'performance_unavailable'),
                    'raw' => is_array($raw) ? $raw : [],
                ];
            }

            return array_merge(
                ['success' => true, 'item_id' => $itemId, 'raw' => $raw],
                $this->parsePerformancePayload($raw)
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'item_id' => $itemId,
                'score' => null,
                'level' => null,
                'level_wording' => null,
                'pending_tech_specs' => false,
                'pending_gtin' => false,
                'pending_rules' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parser puro (testável sem HTTP).
     *
     * @param array<string, mixed> $raw
     * @return array{
     *   score:?float,
     *   level:?string,
     *   level_wording:?string,
     *   pending_tech_specs:bool,
     *   pending_gtin:bool,
     *   pending_rules:list<array{key:string,mode:string,title:string}>
     * }
     */
    public function parsePerformancePayload(array $raw): array
    {
        $pendingRules = [];
        $pendingTech = false;
        $pendingGtin = false;

        foreach (($raw['buckets'] ?? []) as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            foreach (($bucket['variables'] ?? []) as $variable) {
                if (!is_array($variable)) {
                    continue;
                }
                $varKey = (string)($variable['key'] ?? '');
                $varStatus = strtoupper((string)($variable['status'] ?? ''));

                if ($varStatus === 'PENDING') {
                    if (str_contains($varKey, 'TECHNICAL_SPECIFICATIONS') || str_contains($varKey, 'TS_MAIN') || $varKey === 'CHARACTERISTICS') {
                        $pendingTech = true;
                    }
                    if (str_contains($varKey, 'GTIN')) {
                        $pendingGtin = true;
                    }
                }

                foreach (($variable['rules'] ?? []) as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    if (strtoupper((string)($rule['status'] ?? '')) !== 'PENDING') {
                        continue;
                    }
                    $key = (string)($rule['key'] ?? '');
                    $title = (string)(($rule['wordings']['title'] ?? $variable['title'] ?? $key));
                    $pendingRules[] = [
                        'key' => $key,
                        'mode' => (string)($rule['mode'] ?? ''),
                        'title' => $title,
                    ];
                    if (str_contains($key, 'TS_MAIN') || str_contains($key, 'TECHNICAL_SPEC')) {
                        $pendingTech = true;
                    }
                    if (str_contains($key, 'GTIN')) {
                        $pendingGtin = true;
                    }
                }
            }
        }

        return [
            'score' => isset($raw['score']) ? (float)$raw['score'] : null,
            'level' => isset($raw['level']) ? (string)$raw['level'] : null,
            'level_wording' => isset($raw['level_wording']) ? (string)$raw['level_wording'] : null,
            'pending_tech_specs' => $pendingTech,
            'pending_gtin' => $pendingGtin,
            'pending_rules' => $pendingRules,
        ];
    }
}
