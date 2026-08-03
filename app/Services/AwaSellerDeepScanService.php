<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Orquestra deep scan AWA Sellers: coleta em massa + persistência no registry.
 *
 * Substitui/complementa AwaSellerDiscoveryService quando a busca pública de site
 * (sites + search) está bloqueada, usando AwaSellerBulkCollectorService.
 */
class AwaSellerDeepScanService
{
    private int $accountId;
    private AwaSellerBulkCollectorService $collector;
    private AwaSellerRegistryService $registry;
    private LoggingService $logger;

    public function __construct(
        int $accountId,
        ?AwaSellerBulkCollectorService $collector = null,
        ?AwaSellerRegistryService $registry = null,
        ?LoggingService $logger = null
    ) {
        if ($accountId <= 0) {
            throw new RuntimeException('Conta ML inválida para AwaSellerDeepScanService.');
        }

        $this->accountId = $accountId;
        $this->collector = $collector ?? new AwaSellerBulkCollectorService($accountId);
        $this->registry = $registry ?? new AwaSellerRegistryService($accountId);
        $this->logger = $logger ?? new LoggingService();
        $this->ensureExtendedSchema();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runScan(array $options = []): array
    {
        $maxItems = max(1, min(20000, (int) ($options['max_results'] ?? $options['max_items'] ?? 3000)));
        $querySeeds = $options['query_seeds'] ?? AwaSellerBulkCollectorService::DEFAULT_QUERY_SEEDS;
        $domains = $options['domains'] ?? AwaSellerBulkCollectorService::PRIORITY_DOMAINS;
        // Broad pass usa subset de domains para não explodir requests
        $broadDomains = array_slice(
            is_array($domains) ? $domains : AwaSellerBulkCollectorService::PRIORITY_DOMAINS,
            0,
            12
        );

        $scope = [
            'mode' => 'deep_scan_products_phased',
            'max_items' => $maxItems,
            'query_seeds' => $querySeeds,
            'domains' => $domains,
        ];

        $scanId = $this->registry->createScanRun($scope);
        $started = microtime(true);

        /** @var array<int, true> $seenSellerIds */
        $seenSellerIds = [];
        /** @var array<string, true> $seenItemIds */
        $seenItemIds = [];
        $itemsFound = 0;
        $sellersFound = 0;
        $mergedStats = [
            'requests' => 0,
            'retries' => 0,
            'errors' => 0,
            'products' => 0,
            'items' => 0,
            'sellers' => 0,
        ];
        $topSellers = [];

        $phases = [
            ['name' => 'seeds', 'steps' => ['seeds'], 'domains' => [], 'enrich' => false],
            ['name' => 'domains', 'steps' => ['domains'], 'domains' => $domains, 'enrich' => false],
            ['name' => 'broad', 'steps' => ['broad'], 'domains' => $broadDomains, 'enrich' => (bool) ($options['enrich_sellers'] ?? false)],
        ];

        try {
            foreach ($phases as $phase) {
                if ($itemsFound >= $maxItems) {
                    break;
                }

                $remaining = $maxItems - $itemsFound;
                $this->logger->info('AWA_DEEP_SCAN_PHASE', 'Fase de coleta', [
                    'scan_id' => $scanId,
                    'phase' => $phase['name'],
                    'items_so_far' => $itemsFound,
                    'remaining' => $remaining,
                ]);

                $collection = $this->collector->collect([
                    'max_items' => $remaining,
                    'query_seeds' => $querySeeds,
                    'domains' => $phase['domains'],
                    'steps' => $phase['steps'],
                    'include_noise_domains' => (bool) ($options['include_noise_domains'] ?? false),
                    'enrich_sellers' => $phase['enrich'],
                    'max_products_per_seed' => max(200, min(1200, (int) ($options['max_products_per_seed'] ?? 600))),
                ]);

                foreach ($collection['stats'] as $k => $v) {
                    if (isset($mergedStats[$k]) && is_numeric($v)) {
                        $mergedStats[$k] += (int) $v;
                    }
                }

                foreach ($collection['sellers'] as $sellerPayload) {
                    $normalized = $this->normalizeSellerPayload($sellerPayload);
                    $mlSellerId = (int) ($normalized['seller_id'] ?? 0);
                    $sellerRegistryId = $this->registry->upsertSeller($scanId, $normalized);

                    if ($mlSellerId > 0 && !isset($seenSellerIds[$mlSellerId])) {
                        $seenSellerIds[$mlSellerId] = true;
                        $sellersFound++;
                        $topSellers[] = [
                            'seller_id' => $mlSellerId,
                            'nickname' => $normalized['nickname'] ?? null,
                            'items_count' => (int) ($normalized['items_count'] ?? 0),
                            'city' => $normalized['city'] ?? null,
                            'state' => $normalized['state'] ?? null,
                            'account_status' => $normalized['account_status'] ?? null,
                        ];
                    }

                    foreach ($normalized['items'] as $itemPayload) {
                        $itemId = (string) ($itemPayload['id'] ?? $itemPayload['ml_item_id'] ?? '');
                        $this->registry->upsertSellerItem($sellerRegistryId, $itemPayload);
                        if ($itemId !== '' && !isset($seenItemIds[$itemId])) {
                            $seenItemIds[$itemId] = true;
                            $itemsFound++;
                        }
                    }

                    $this->persistAccountStatus($sellerRegistryId, $normalized['account_status'] ?? null);
                }

                $this->logger->info('AWA_DEEP_SCAN_PHASE_DONE', 'Fase persistida', [
                    'scan_id' => $scanId,
                    'phase' => $phase['name'],
                    'sellers_found' => $sellersFound,
                    'items_found' => $itemsFound,
                ]);
            }

            $mergedStats['items'] = $itemsFound;
            $mergedStats['sellers'] = $sellersFound;

            usort($topSellers, static fn(array $a, array $b): int => $b['items_count'] <=> $a['items_count']);

            $this->registry->markScanCompleted($scanId, $sellersFound, $itemsFound);

            $result = [
                'scan_id' => $scanId,
                'status' => 'completed',
                'account_id' => $this->accountId,
                'sellers_found' => $sellersFound,
                'items_found' => $itemsFound,
                'collection_mode' => 'products_search_sharded_phased',
                'collector_stats' => $mergedStats,
                'execution_time' => round(microtime(true) - $started, 2) . 's',
                'top_sellers' => array_slice($topSellers, 0, 20),
            ];

            $this->logger->info('AWA_DEEP_SCAN_COMPLETE', 'Deep scan AWA concluído', [
                'scan_id' => $scanId,
                'sellers_found' => $sellersFound,
                'items_found' => $itemsFound,
            ]);

            return $result;
        } catch (\Throwable $e) {
            // Mesmo com falha, grava o que já foi persistido nas fases anteriores
            if ($itemsFound > 0 || $sellersFound > 0) {
                $this->registry->markScanCompleted($scanId, $sellersFound, $itemsFound);
            } else {
                $this->registry->markScanFailed($scanId, $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $seller
     * @return array<string, mixed>
     */
    private function normalizeSellerPayload(array $seller): array
    {
        $itemPayloads = [];
        foreach ($seller['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemPayloads[] = [
                'ml_item_id' => (string) ($item['id'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'category_id' => $item['category_id'] ?? null,
                'price' => $item['price'] ?? null,
                'status' => $item['status'] ?? 'active',
                'brand_match_type' => (string) ($item['brand_match_type'] ?? 'catalog_match'),
                'has_brand_attribute' => true,
                'evidence' => [
                    'brand_analysis' => $item['brand_analysis'] ?? [],
                    'condition' => $item['condition'] ?? null,
                    'permalink' => $item['permalink'] ?? null,
                    'thumbnail' => $item['thumbnail'] ?? null,
                    'shipping' => $item['shipping'] ?? [],
                    'listing_type_id' => $item['listing_type_id'] ?? null,
                    'catalog_product_id' => $item['catalog_product_id'] ?? null,
                    'domain_id' => $item['domain_id'] ?? null,
                    'official_store_id' => $item['official_store_id'] ?? null,
                    'collection_mode' => 'products_search_sharded',
                ],
            ];
        }

        return [
            'seller_id' => (int) ($seller['seller_id'] ?? 0),
            'nickname' => (string) ($seller['nickname'] ?? 'Desconhecido'),
            'permalink' => $seller['permalink'] ?? null,
            'city' => $seller['city'] ?? null,
            'state' => $seller['state'] ?? null,
            'user_type' => $seller['user_type'] ?? null,
            'reputation_level' => $seller['reputation_level'] ?? null,
            'power_seller_status' => $seller['power_seller_status'] ?? null,
            'account_status' => $seller['account_status'] ?? null,
            'items_count' => count($itemPayloads),
            'categories' => is_array($seller['categories'] ?? null) ? $seller['categories'] : [],
            'items' => $itemPayloads,
        ];
    }

    private function persistAccountStatus(int $sellerRegistryId, mixed $accountStatus): void
    {
        if (!is_string($accountStatus) || trim($accountStatus) === '') {
            return;
        }

        try {
            $db = \App\Database::getInstance();
            if (!$this->columnExists($db, 'awa_seller_registry', 'account_status')) {
                return;
            }
            $stmt = $db->prepare(
                'UPDATE awa_seller_registry
                    SET account_status = :status, updated_at = NOW()
                  WHERE id = :id AND account_id = :account_id'
            );
            $stmt->execute([
                'status' => mb_substr(trim($accountStatus), 0, 50),
                'id' => $sellerRegistryId,
                'account_id' => $this->accountId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('AWA_DEEP_SCAN_STATUS_UPDATE_FAILED', 'Falha ao gravar account_status', [
                'seller_registry_id' => $sellerRegistryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ensureExtendedSchema(): void
    {
        try {
            $db = \App\Database::getInstance();
            if (!$this->columnExists($db, 'awa_seller_registry', 'account_status')) {
                $db->exec(
                    'ALTER TABLE awa_seller_registry
                     ADD COLUMN account_status VARCHAR(50) NULL
                     COMMENT \'site_status do perfil ML (active/suspended/etc)\'
                     AFTER power_seller_status'
                );
            }
        } catch (\Throwable $e) {
            // Schema já pode existir ou sem permissão ALTER — coleta segue sem a coluna.
            $this->logger->warning('AWA_DEEP_SCAN_SCHEMA_ENSURE', 'Não foi possível garantir coluna account_status', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function columnExists(\PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
