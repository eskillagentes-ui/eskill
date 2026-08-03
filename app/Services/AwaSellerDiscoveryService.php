<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class AwaSellerDiscoveryService
{
    private int $accountId;
    private BrandAnalyzerService $brandAnalyzer;
    private AwaSellerRegistryService $registry;

    public function __construct(
        ?int $accountId = null,
        ?BrandAnalyzerService $brandAnalyzer = null,
        ?AwaSellerRegistryService $registry = null
    ) {
        if (($accountId ?? 0) <= 0) {
            throw new RuntimeException('Conta Mercado Livre inválida para a descoberta AWA Sellers.');
        }

        $this->accountId = (int) $accountId;
        $this->brandAnalyzer = $brandAnalyzer ?? new BrandAnalyzerService($this->accountId);
        $this->registry = $registry ?? new AwaSellerRegistryService($this->accountId);
    }

    public function runScan(array $options = []): array
    {
        $mode = strtolower(trim((string) ($options['mode'] ?? 'auto')));

        // Deep scan — caminho preferencial. Em testes unitários passe mode=legacy.
        if ($mode === 'deep' || $mode === 'auto') {
            try {
                $deep = new AwaSellerDeepScanService($this->accountId, null, $this->registry);
                $result = $deep->runScan($options);
                $result['scan_engine'] = 'deep_products';

                // Se deep não achou nada e o modo é auto, tenta legado (ex.: mocks de teste).
                if (
                    $mode === 'auto'
                    && (int) ($result['items_found'] ?? 0) === 0
                    && (int) ($result['sellers_found'] ?? 0) === 0
                ) {
                    $legacy = $this->runLegacyScan($options);
                    $legacy['scan_engine'] = 'legacy_after_empty_deep';
                    $legacy['deep_result'] = $result;
                    return $legacy;
                }

                return $result;
            } catch (\Throwable $deepError) {
                if ($mode === 'deep') {
                    throw $deepError;
                }
                // auto: cai para o fluxo legado
            }
        }

        return $this->runLegacyScan($options);
    }

    /**
     * Fluxo legado via BrandAnalyzer (/sites/search ou catalog fallback).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runLegacyScan(array $options = []): array
    {
        $scope = $this->normalizeScope($options);
        $scanId = $this->registry->createScanRun($scope);

        try {
            // Tenta a busca principal (requer endpoint /search acessível do IP do servidor).
            // Se o endpoint estiver bloqueado (WAF/PolicyAgent do ML em IPs de datacenter),
            // cai automaticamente para o modo catálogo que usa endpoints autenticados
            // (products/search e users items/search) — nunca bloqueados por IP.
            $analysis = $this->brandAnalyzer->analyzeAwaBrand($scope);

            if (empty($analysis['items']) && !$this->brandAnalyzer->isSearchEndpointAccessible()) {
                $analysis = $this->brandAnalyzer->analyzeAwaBrandFromCatalog($scope);
            }

            $sellerPayloads = $this->buildSellerPayloads($analysis);

            $itemsFound = 0;
            foreach ($sellerPayloads as $sellerPayload) {
                $sellerRegistryId = $this->registry->upsertSeller($scanId, $sellerPayload);

                foreach ($sellerPayload['items'] as $itemPayload) {
                    $this->registry->upsertSellerItem($sellerRegistryId, $itemPayload);
                    $itemsFound++;
                }
            }

            $sellersFound = count($sellerPayloads);
            $this->registry->markScanCompleted($scanId, $sellersFound, $itemsFound);

            return [
                'scan_id' => $scanId,
                'status' => 'completed',
                'account_id' => $this->accountId,
                'sellers_found' => $sellersFound,
                'items_found' => $itemsFound,
                'analysis_date' => $analysis['analysis_date'] ?? null,
                'execution_time' => $analysis['execution_time'] ?? null,
                'brand_consistency_score' => (float) ($analysis['brand_consistency_score'] ?? 0),
                'categories' => $scope['categories'],
                'top_sellers' => $this->buildTopSellers($sellerPayloads),
                'scan_engine' => 'legacy_brand_analyzer',
            ];
        } catch (\Throwable $throwable) {
            $this->registry->markScanFailed($scanId, $throwable->getMessage());
            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{categories: array<int, string>, max_results: int, include_details: bool}
     */
    private function normalizeScope(array $options): array
    {
        $rawCategories = $options['categories'] ?? array_keys(BrandAnalyzerService::MOTO_CATEGORIES);
        $categories = $this->normalizeCategories($rawCategories);
        if ($categories === []) {
            $categories = array_keys(BrandAnalyzerService::MOTO_CATEGORIES);
        }

        return [
            'categories' => $categories,
            'max_results' => max(1, min(5000, (int) ($options['max_results'] ?? 500))),
            'include_details' => true,
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    private function buildSellerPayloads(array $analysis): array
    {
        $itemsBySeller = [];
        foreach (($analysis['items'] ?? []) as $item) {
            $sellerId = (int) ($item['seller_id'] ?? 0);
            if ($sellerId > 0) {
                $itemsBySeller[$sellerId][] = $item;
            }
        }

        $payloads = [];
        foreach (array_keys($itemsBySeller) as $sellerId) {
            $sellerData = $this->findSellerData($analysis['sellers'] ?? [], $sellerId);
            $payloads[] = $this->buildSellerPayload($sellerId, $sellerData, $itemsBySeller[$sellerId]);
        }

        usort($payloads, static function (array $left, array $right): int {
            return $right['items_count'] <=> $left['items_count'];
        });

        return $payloads;
    }

    /**
     * @param array<int|string, mixed> $sellers
     * @return array<string, mixed>
     */
    private function findSellerData(array $sellers, int $sellerId): array
    {
        if (isset($sellers[$sellerId]) && is_array($sellers[$sellerId])) {
            return $sellers[$sellerId];
        }

        foreach ($sellers as $seller) {
            if (is_array($seller) && (int) ($seller['id'] ?? 0) === $sellerId) {
                return $seller;
            }
        }

        return [
            'id' => $sellerId,
            'nickname' => 'Seller ' . $sellerId,
        ];
    }

    /**
     * @param array<string, mixed> $sellerData
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildSellerPayload(int $sellerId, array $sellerData, array $items): array
    {
        $categories = [];
        $itemPayloads = [];

        foreach ($items as $item) {
            $categoryId = (string) ($item['category_id'] ?? '');
            if ($categoryId !== '') {
                $categories[$categoryId] = true;
            }

            $itemPayloads[] = $this->buildItemPayload($item);
        }

        return [
            'seller_id' => $sellerId,
            'nickname' => (string) ($sellerData['nickname'] ?? ('Seller ' . $sellerId)),
            'permalink' => $this->nullableString($sellerData['permalink'] ?? null),
            'city' => $this->normalizeLocationValue($sellerData['address']['city'] ?? null),
            'state' => $this->normalizeLocationValue($sellerData['address']['state'] ?? null),
            'user_type' => $this->nullableString($sellerData['user_type'] ?? null),
            'reputation_level' => $this->nullableString($sellerData['seller_reputation']['level_id'] ?? null),
            'power_seller_status' => $this->nullableString($sellerData['seller_reputation']['power_seller_status'] ?? null),
            'items_count' => count($itemPayloads),
            'categories' => array_keys($categories),
            'items' => $itemPayloads,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildItemPayload(array $item): array
    {
        return [
            'ml_item_id' => (string) ($item['id'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'category_id' => $this->nullableString($item['category_id'] ?? null),
            'price' => isset($item['price']) ? (float) $item['price'] : null,
            'status' => $this->nullableString($item['status'] ?? null),
            'brand_match_type' => $this->resolveBrandMatchType($item),
            'has_brand_attribute' => (bool) (($item['brand_analysis']['has_brand'] ?? false) === true),
            'evidence' => $this->buildEvidence($item),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildEvidence(array $item): array
    {
        return [
            'brand_analysis' => is_array($item['brand_analysis'] ?? null) ? $item['brand_analysis'] : [],
            'condition' => $item['condition'] ?? null,
            'permalink' => $item['permalink'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'shipping' => is_array($item['shipping'] ?? null) ? $item['shipping'] : [],
            'listing_type_id' => $item['listing_type_id'] ?? null,
            'catalog_product_id' => $item['catalog_product_id'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveBrandMatchType(array $item): string
    {
        $brandAnalysis = is_array($item['brand_analysis'] ?? null) ? $item['brand_analysis'] : [];
        $hasBrand = ($brandAnalysis['has_brand'] ?? false) === true;
        $isCorrect = ($brandAnalysis['is_correct'] ?? false) === true;
        $titleMatches = $this->containsAwaKeyword((string) ($item['title'] ?? ''));

        if ($hasBrand && $isCorrect) {
            return 'attribute_match';
        }

        if ($hasBrand && !$isCorrect) {
            return 'attribute_mismatch';
        }

        if ($titleMatches) {
            return 'title_match_only';
        }

        return 'unclassified';
    }

    private function containsAwaKeyword(string $title): bool
    {
        return preg_match('/(^|\b)A[\s\.-]*W[\s\.-]*A(\b|$)/iu', $title) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $sellerPayloads
     * @return array<int, array<string, mixed>>
     */
    private function buildTopSellers(array $sellerPayloads): array
    {
        $topSellers = [];

        foreach (array_slice($sellerPayloads, 0, 10) as $sellerPayload) {
            $topSellers[] = [
                'seller_id' => $sellerPayload['seller_id'],
                'nickname' => $sellerPayload['nickname'],
                'items_count' => $sellerPayload['items_count'],
                'reputation_level' => $sellerPayload['reputation_level'],
                'city' => $sellerPayload['city'],
                'state' => $sellerPayload['state'],
            ];
        }

        return $topSellers;
    }

    /**
     * @param array<int, string>|string|null $categories
     * @return array<int, string>
     */
    private function normalizeCategories(array|string|null $categories): array
    {
        if (is_string($categories)) {
            $categories = array_map('trim', explode(',', $categories));
        }

        if (!is_array($categories)) {
            return [];
        }

        $normalized = [];
        foreach ($categories as $category) {
            $value = trim((string) $category);
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private function normalizeLocationValue(array|string|null $value): ?string
    {
        if (is_string($value)) {
            return $this->nullableString($value);
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['name', 'city', 'state_name', 'id'] as $candidateKey) {
            $candidate = $value[$candidateKey] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function nullableString(null|string|int|float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    // =========================================================================
    // RASTREAMENTO MANUAL DE CONCORRENTES
    // =========================================================================

    /**
     * Adiciona manualmente um seller ao registry pelo ML seller_id.
     * Usa o endpoint /users/{id}/items/search para coletar os itens AWA do seller.
     *
     * @param  int                    $mlSellerId ML seller_id externo
     * @return array<string, mixed>
     * @throws RuntimeException se o seller não for encontrado no ML
     */
    public function trackSellerById(int $mlSellerId): array
    {
        if ($mlSellerId <= 0) {
            throw new RuntimeException('ML seller_id inválido.');
        }

        // Verificar se já está rastreado
        $existing = $this->registry->findByMlSellerId($mlSellerId);
        if ($existing !== null) {
            return [
                'status'      => 'already_tracked',
                'registry_id' => (int) $existing['id'],
                'seller_id'   => $mlSellerId,
                'nickname'    => $existing['nickname'] ?? null,
                'items_count' => (int) ($existing['items_count'] ?? 0),
            ];
        }

        // Buscar perfil do seller no ML
        $profile = $this->brandAnalyzer->getSellerDetails($mlSellerId);

        if (empty($profile['nickname']) || ($profile['nickname'] === 'Desconhecido' && empty($profile['id']))) {
            throw new RuntimeException("Seller ML ID {$mlSellerId} não encontrado ou inacessível.");
        }

        // Buscar items do seller via ML users endpoint
        $client      = new MercadoLivreClient($this->accountId);
        $itemsResult = $client->get("/users/{$mlSellerId}/items/search", ['limit' => 200], null, false);
        $itemIds     = $itemsResult['results'] ?? [];

        $itemPayloads = [];
        foreach (array_slice($itemIds, 0, 200) as $rawId) {
            $itemId  = is_array($rawId) ? ($rawId['id'] ?? null) : (string) $rawId;
            if (empty($itemId)) {
                continue;
            }

            $itemDetails = $client->get("/items/{$itemId}", [], null, false);
            if (isset($itemDetails['error'])) {
                continue;
            }

            $brandAnalysis = $this->brandAnalyzer->analyzeBrandAttribute($itemDetails);
            $title         = strtoupper((string) ($itemDetails['title'] ?? ''));

            // Incluir somente itens com marca AWA ou AWA no título
            if (!$brandAnalysis['is_correct'] && !$this->containsAwaKeyword($itemDetails['title'] ?? '')) {
                continue;
            }

            $itemPayloads[] = $this->buildItemPayload(array_merge(
                $itemDetails,
                ['brand_analysis' => $brandAnalysis]
            ));
        }

        // Criar scan de rastreamento manual
        $scanId = $this->registry->createScanRun([
            'categories'     => [],
            'max_results'    => 200,
            'include_details' => true,
            'manual_track'   => true,
        ]);

        $sellerPayload = $this->buildSellerPayload($mlSellerId, $profile, array_map(
            static fn (array $ip): array => array_merge($ip, [
                'seller_id'      => $mlSellerId,
                'brand_analysis' => ['has_brand' => $ip['has_brand_attribute'], 'is_correct' => $ip['brand_match_type'] === 'attribute_match'],
            ]),
            $itemPayloads
        ));

        $registryId = $this->registry->upsertSeller($scanId, $sellerPayload);

        foreach ($itemPayloads as $itemPayload) {
            $this->registry->upsertSellerItem($registryId, $itemPayload);
        }

        $this->registry->markScanCompleted($scanId, 1, count($itemPayloads));

        return [
            'status'      => 'tracked',
            'registry_id' => $registryId,
            'seller_id'   => $mlSellerId,
            'nickname'    => $profile['nickname'] ?? null,
            'items_found' => count($itemPayloads),
        ];
    }
}
