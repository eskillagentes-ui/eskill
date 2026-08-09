<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;
use App\Services\ItemMetricsService;
use App\Services\AccountGovernanceService;
use Monolog\Logger;

/**
 * AccountGovernanceIntegrationService - Integração ML para Governança de Conta
 *
 * Busca dados reais da API do Mercado Livre e alimenta o AccountGovernanceService:
 * - Dados do vendedor via /users/me e /users/{id}/reputation
 * - Lista de items via /users/{id}/items/search
 * - Métricas de visitas via /items/{id}/visits
 * - Vendas dos últimos 30 dias via /orders/search
 *
 * @version 1.0.0
 */
class AccountGovernanceIntegrationService
{
    private MercadoLivreClient $mlClient;
    private ItemMetricsService $metricsService;
    private AccountGovernanceService $governanceService;
    private ?Logger $logger;

    // Rate limiting
    private const ITEMS_PER_BATCH = 20;
    private const BATCH_DELAY_MS = 200;
    private const MAX_ITEMS_FOR_DIAGNOSTIC = 500;

    public function __construct(
        ?int $accountId = null,
        ?MercadoLivreClient $mlClient = null,
        ?ItemMetricsService $metricsService = null,
        ?AccountGovernanceService $governanceService = null,
        ?Logger $logger = null
    ) {
        $this->mlClient = $mlClient ?? new MercadoLivreClient($accountId);
        $this->metricsService = $metricsService ?? new ItemMetricsService($accountId);
        $this->governanceService = $governanceService ?? new AccountGovernanceService(
            defaultMinMarginPct: 0.05,
            maxPriceDropPct: 0.15,
            logger: $logger
        );
        $this->logger = $logger;
    }

    /**
     * Executa diagnóstico completo com dados reais da API do Mercado Livre
     *
     * @param array $options Opções: max_items, include_paused, fetch_visits, fetch_sales
     * @return array Resultado do diagnóstico ou erro
     */
    public function runDiagnosticFromAPI(array $options = []): array
    {
        $startTime = hrtime(true);

        $maxItems = min((int) ($options['max_items'] ?? 200), self::MAX_ITEMS_FOR_DIAGNOSTIC);
        $includePaused = (bool) ($options['include_paused'] ?? true);
        $fetchVisits = (bool) ($options['fetch_visits'] ?? true);
        $fetchSales = (bool) ($options['fetch_sales'] ?? true);

        try {
            // Step 1: Verificar conexão com ML
            if ($this->mlClient->getAccessToken() === '') {
                return $this->errorResponse('ml_not_configured', 'Cliente ML não configurado. Verifique token de acesso.');
            }

            // Step 2: Buscar dados do vendedor
            $this->log('info', 'Buscando dados do vendedor');
            $sellerData = $this->fetchSellerData();
            if (isset($sellerData['error'])) {
                return $this->errorResponse('seller_fetch_failed', $sellerData['message'] ?? 'Erro ao buscar dados do vendedor');
            }

            // Step 3: Buscar lista de items
            $this->log('info', 'Buscando lista de items', ['max_items' => $maxItems]);
            $items = $this->fetchAllItems($maxItems, $includePaused);
            if (empty($items)) {
                return $this->errorResponse('no_items', 'Nenhum item encontrado na conta');
            }

            // Step 4: Enriquecer items com métricas (visitas, vendas)
            if ($fetchVisits || $fetchSales) {
                $this->log('info', 'Enriquecendo items com métricas', ['count' => count($items)]);
                $items = $this->enrichItemsWithMetrics($items, $fetchVisits, $fetchSales, $sellerData['seller_id']);
            }

            // Step 5: Preparar accountData no formato esperado
            $accountData = $this->formatAccountData($sellerData);

            // Step 6: Preparar sellerContext
            $sellerContext = $this->buildSellerContext($sellerData, $items);

            // Step 7: Executar diagnóstico via engine puro
            $this->log('info', 'Executando diagnóstico de governança', ['items_count' => count($items)]);
            $diagnostic = $this->governanceService->runFullDiagnostic($accountData, $items, $sellerContext);

            $elapsedMs = round((hrtime(true) - $startTime) / 1_000_000, 2);

            // Step 8: Adicionar metadados da integração
            $diagnostic['meta']['integration'] = [
                'source' => 'mercado_livre_api',
                'account_id' => $this->mlClient->getAccountId(),
                'seller_id' => $sellerData['seller_id'] ?? null,
                'fetched_at' => date('c'),
                'options' => [
                    'max_items' => $maxItems,
                    'include_paused' => $includePaused,
                    'fetch_visits' => $fetchVisits,
                    'fetch_sales' => $fetchSales,
                ],
                'total_elapsed_ms' => $elapsedMs,
            ];

            $this->log('info', 'Diagnóstico concluído com sucesso', [
                'items' => count($items),
                'elapsed_ms' => $elapsedMs,
            ]);

            return $diagnostic;
        } catch (\InvalidArgumentException $e) {
            $this->log('warning', 'Erro de validação no diagnóstico', ['error' => $e->getMessage()]);
            return $this->errorResponse('validation_error', $e->getMessage());
        } catch (\Exception $e) {
            $this->log('error', 'Erro inesperado no diagnóstico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse('unexpected_error', 'Erro interno: ' . $e->getMessage());
        }
    }

    /**
     * Busca dados do vendedor incluindo reputação e métricas
     *
     * @return array Dados formatados do vendedor ou erro
     */
    private function fetchSellerData(): array
    {
        try {
            // GET /users/me
            $me = $this->mlClient->getMe();
            if (isset($me['error'])) {
                return ['error' => true, 'message' => $me['message'] ?? 'Erro ao buscar /users/me'];
            }

            $sellerId = (string) ($me['id'] ?? '');
            if ($sellerId === '') {
                return ['error' => true, 'message' => 'Seller ID não encontrado na resposta'];
            }

            // Dados de reputação estão em $me['seller_reputation']
            $sellerRep = $me['seller_reputation'] ?? [];
            $transactions = $sellerRep['transactions'] ?? [];
            $metrics = $sellerRep['metrics'] ?? [];

            // Calcular taxas
            $claimsData = $metrics['claims'] ?? [];
            $delayedData = $metrics['delayed_handling_time'] ?? [];
            $cancellationsData = $metrics['cancellations'] ?? [];

            $claimsRate = $this->extractRate($claimsData);
            $lateShipmentRate = $this->extractRate($delayedData);
            $cancellationRate = $this->extractRate($cancellationsData);

            // Level de reputação
            $powerSellerStatus = $sellerRep['power_seller_status'] ?? null;
            $levelId = $sellerRep['level_id'] ?? null;
            $reputationLevel = $this->mapReputationLevel($powerSellerStatus, $levelId);

            return [
                'seller_id' => $sellerId,
                'nickname' => $me['nickname'] ?? '',
                'email' => $me['email'] ?? '',
                'site_id' => $me['site_id'] ?? 'MLB',
                'registration_date' => $me['registration_date'] ?? null,
                'reputation_level' => $reputationLevel,
                'power_seller_status' => $powerSellerStatus,
                'level_id' => $levelId,
                'total_transactions' => (int) ($transactions['total'] ?? 0),
                'completed_transactions' => (int) ($transactions['completed'] ?? 0),
                'claims_rate' => $claimsRate,
                'late_shipment_rate' => $lateShipmentRate,
                'cancellation_rate' => $cancellationRate,
                'raw_reputation' => $sellerRep,
            ];
        } catch (\Exception $e) {
            $this->log('error', 'Exceção ao buscar dados do vendedor', ['error' => $e->getMessage()]);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Extrai taxa de métricas do ML
     */
    private function extractRate(array $metricData): float
    {
        if (isset($metricData['rate'])) {
            return (float) $metricData['rate'];
        }
        return 0.0;
    }

    /**
     * Mapeia level de reputação do ML para formato do governance
     */
    private function mapReputationLevel(?string $powerSellerStatus, ?string $levelId): string
    {
        if ($powerSellerStatus !== null) {
            return match (strtolower($powerSellerStatus)) {
                'platinum' => 'platinum',
                'gold' => 'gold',
                'silver' => 'silver',
                default => $levelId ?? 'standard',
            };
        }

        if ($levelId !== null) {
            return match ($levelId) {
                '5_green' => 'platinum',
                '4_light_green' => 'gold',
                '3_yellow' => 'silver',
                '2_orange' => 'bronze',
                '1_red' => 'red',
                default => $levelId,
            };
        }

        return 'unknown';
    }

    /**
     * Busca todos os items do vendedor (paginado)
     */
    private function fetchAllItems(int $maxItems, bool $includePaused): array
    {
        $allItems = [];
        $offset = 0;
        $limit = 50;

        $sellerId = $this->mlClient->getSellerId();
        if (!$sellerId) {
            $this->log('warning', 'Seller ID não disponível para buscar items');
            return [];
        }

        // Buscar items ativos
        while (count($allItems) < $maxItems) {
            $remaining = $maxItems - count($allItems);
            $fetchLimit = min($limit, $remaining);

            $response = $this->mlClient->get("/users/{$sellerId}/items/search", [
                'status' => 'active',
                'offset' => $offset,
                'limit' => $fetchLimit,
            ]);

            if (isset($response['error']) || !isset($response['results'])) {
                break;
            }

            $itemIds = $response['results'] ?? [];
            if (empty($itemIds)) {
                break;
            }

            $batchItems = $this->fetchItemDetailsBatch($itemIds);
            $allItems = array_merge($allItems, $batchItems);

            $offset += count($itemIds);
            $total = $response['paging']['total'] ?? 0;
            if ($offset >= $total) {
                break;
            }

            usleep(self::BATCH_DELAY_MS * 1000);
        }

        // Buscar items pausados se solicitado
        if ($includePaused && count($allItems) < $maxItems) {
            $offset = 0;
            while (count($allItems) < $maxItems) {
                $remaining = $maxItems - count($allItems);
                $fetchLimit = min($limit, $remaining);

                $response = $this->mlClient->get("/users/{$sellerId}/items/search", [
                    'status' => 'paused',
                    'offset' => $offset,
                    'limit' => $fetchLimit,
                ]);

                if (isset($response['error']) || !isset($response['results'])) {
                    break;
                }

                $itemIds = $response['results'] ?? [];
                if (empty($itemIds)) {
                    break;
                }

                $batchItems = $this->fetchItemDetailsBatch($itemIds);
                $allItems = array_merge($allItems, $batchItems);

                $offset += count($itemIds);
                $total = $response['paging']['total'] ?? 0;
                if ($offset >= $total) {
                    break;
                }

                usleep(self::BATCH_DELAY_MS * 1000);
            }
        }

        $this->log('info', 'Items buscados', ['total' => count($allItems), 'max_items' => $maxItems]);
        return $allItems;
    }

    /**
     * Busca detalhes de items em batch via multi-get
     */
    private function fetchItemDetailsBatch(array $itemIds): array
    {
        $items = [];
        $chunks = array_chunk($itemIds, self::ITEMS_PER_BATCH);

        foreach ($chunks as $chunk) {
            $response = $this->mlClient->get('/items', ['ids' => implode(',', $chunk)]);

            if (!is_array($response)) {
                continue;
            }

            foreach ($response as $entry) {
                $body = $entry['body'] ?? $entry;
                if (!isset($body['id'])) {
                    continue;
                }
                $items[] = $this->formatItemForGovernance($body);
            }

            usleep(self::BATCH_DELAY_MS * 1000);
        }

        return $items;
    }

    /**
     * Formata item da API ML para o formato esperado pelo AccountGovernanceService
     */
    private function formatItemForGovernance(array $body): array
    {
        return [
            'id' => $body['id'] ?? '',
            'title' => $body['title'] ?? '',
            'price' => (float) ($body['price'] ?? 0),
            'original_price' => (float) ($body['original_price'] ?? $body['price'] ?? 0),
            'currency_id' => $body['currency_id'] ?? 'BRL',
            'available_quantity' => (int) ($body['available_quantity'] ?? 0),
            'sold_quantity' => (int) ($body['sold_quantity'] ?? 0),
            'status' => $body['status'] ?? 'unknown',
            'sub_status' => $body['sub_status'] ?? [],
            'listing_type_id' => $body['listing_type_id'] ?? '',
            'category_id' => $body['category_id'] ?? '',
            'permalink' => $body['permalink'] ?? '',
            'thumbnail' => $body['thumbnail'] ?? '',
            'date_created' => $body['date_created'] ?? null,
            'last_updated' => $body['last_updated'] ?? null,
            'start_time' => $body['start_time'] ?? null,
            'shipping' => $body['shipping'] ?? [],
            'health' => $body['health'] ?? null,
            'visits_30d' => 0,
            'visits_14d' => 0,
            'sales_30d' => 0,
            'sales_14d' => 0,
            'conversion_30d' => 0.0,
        ];
    }

    /**
     * Enriquece items com métricas de visitas e vendas
     */
    private function enrichItemsWithMetrics(array $items, bool $fetchVisits, bool $fetchSales, string $sellerId): array
    {
        $itemIds = array_column($items, 'id');
        $itemMap = [];
        foreach ($items as $item) {
            $itemMap[$item['id']] = $item;
        }

        // Buscar vendas em batch (30d e 14d, na mesma chamada de orders)
        $salesByItem = [];
        $sales14ByItem = [];
        if ($fetchSales) {
            [$salesByItem, $sales14ByItem] = $this->fetchSalesByItem($sellerId, $itemIds);
        }

        // Buscar visitas em batch via getMultiItemVisits (50 IDs por chamada, sem limite de 100)
        if ($fetchVisits && !empty($itemIds)) {
            $this->log('info', 'Buscando visitas para items (batch)', ['count' => count($itemIds)]);

            $chunks = array_chunk($itemIds, 50); // ML aceita até 50 IDs por chamada
            foreach ($chunks as $chunk) {
                try {
                    $visitsResult = $this->mlClient->getMultiItemVisits($chunk, 30);
                    foreach ($visitsResult as $itemId => $visitData) {
                        if (isset($itemMap[$itemId])) {
                            $itemMap[$itemId]['visits_30d'] = (int) ($visitData['total'] ?? $visitData['visits'] ?? 0);
                            // Correção de bug: visits_14d nunca era preenchido (ficava
                            // sempre 0), o que fazia AccountGovernanceService calcular
                            // trend = -100% para todo item com tráfego em 30d, marcando
                            // FALLING indiscriminadamente. getMultiItemVisits() já retorna
                            // o detalhamento diário (visits_detail) da mesma chamada de
                            // 30d — usamos isso para somar só os últimos 14 dias, sem
                            // precisar de uma segunda chamada à API.
                            $visits14 = $this->sumLastNDaysFromDaily($visitData['daily'] ?? [], 14);
                            // Sem detalhamento diário na resposta (formato legado da API),
                            // usa estimativa proporcional em vez de 0 fixo — 0 fixo geraria
                            // sempre trend = -100% (FALLING) no AccountGovernanceService.
                            $itemMap[$itemId]['visits_14d'] = $visits14
                                ?? (int) round($itemMap[$itemId]['visits_30d'] * (14 / 30));
                        }
                    }
                } catch (\Exception $e) {
                    $this->log('warning', 'Erro ao buscar visitas em batch', [
                        'chunk_size' => count($chunk),
                        'error' => $e->getMessage(),
                    ]);
                }
                usleep(self::BATCH_DELAY_MS * 1000);
            }
        }

        // Aplicar vendas
        foreach ($salesByItem as $itemId => $salesCount) {
            if (isset($itemMap[$itemId])) {
                $itemMap[$itemId]['sales_30d'] = $salesCount;
                $itemMap[$itemId]['sales_14d'] = $sales14ByItem[$itemId] ?? 0;
                $visits = $itemMap[$itemId]['visits_30d'] ?? 0;
                if ($visits > 0) {
                    $itemMap[$itemId]['conversion_30d'] = $salesCount / $visits;
                }
            }
        }

        return array_values($itemMap);
    }

    /**
     * Soma visitas dos últimos N dias a partir do detalhamento diário
     * (visits_detail) retornado por getMultiItemVisits(). Retorna null quando
     * não há detalhamento diário disponível (resposta em formato legado),
     * para o chamador decidir um fallback em vez de assumir 0 dias com visita.
     *
     * @param array<int, array{date: string, total: int}> $daily
     */
    private function sumLastNDaysFromDaily(array $daily, int $days): ?int
    {
        if (empty($daily)) {
            return null;
        }

        $cutoff = strtotime("-{$days} days");
        $sum = 0;
        $found = false;

        foreach ($daily as $entry) {
            $timestamp = strtotime((string) ($entry['date'] ?? ''));
            if ($timestamp === false) {
                continue;
            }
            if ($timestamp >= $cutoff) {
                $sum += (int) ($entry['total'] ?? 0);
                $found = true;
            }
        }

        return $found ? $sum : null;
    }

    /**
     * Busca vendas por item nos últimos 30 dias (e, no mesmo lote, também nos
     * últimos 14 dias) via /orders/search.
     *
     * @return array{0: array<string, int>, 1: array<string, int>} [sales_30d, sales_14d] por item
     */
    private function fetchSalesByItem(string $sellerId, array $itemIds): array
    {
        $salesByItem = array_fill_keys($itemIds, 0);
        $sales14ByItem = array_fill_keys($itemIds, 0);
        $cutoff14 = strtotime('-14 days');
        $dateFrom = date('Y-m-d\TH:i:s.000-00:00', strtotime('-30 days'));

        $offset = 0;
        $limit = 50;
        $maxOrders = 500;
        $fetchedOrders = 0;

        while ($fetchedOrders < $maxOrders) {
            try {
                $response = $this->mlClient->get('/orders/search', [
                    'seller' => $sellerId,
                    'order.date_created.from' => $dateFrom,
                    'order.status' => 'paid',
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

                if (
                    isset($response['error'])
                    && $response['error'] === 'orders_access_unavailable'
                    && ($response['feature'] ?? null) === 'orders'
                    && ($response['optional_feature'] ?? false) === true
                ) {
                    $this->log('info', 'Orders capability unavailable for seller — skipping sales metrics', [
                        'seller_id' => $sellerId,
                    ]);
                    break;
                }

                if (isset($response['error']) || !isset($response['results'])) {
                    break;
                }

                $orders = $response['results'] ?? [];
                if (empty($orders)) {
                    break;
                }

                foreach ($orders as $order) {
                    $orderTimestamp = strtotime((string) ($order['date_created'] ?? ''));
                    $isWithin14d = $orderTimestamp !== false && $orderTimestamp >= $cutoff14;

                    $orderItems = $order['order_items'] ?? [];
                    foreach ($orderItems as $orderItem) {
                        $itemId = $orderItem['item']['id'] ?? '';
                        $qty = (int) ($orderItem['quantity'] ?? 1);
                        if (isset($salesByItem[$itemId])) {
                            $salesByItem[$itemId] += $qty;
                            // Correção de bug: sales_14d nunca era calculado (ficava
                            // sempre 0), fazendo NO_SALES_14 disparar para todo item,
                            // mesmo com vendas recentes. Reaproveita as mesmas ordens já
                            // buscadas para a janela de 30d em vez de uma chamada extra.
                            if ($isWithin14d) {
                                $sales14ByItem[$itemId] += $qty;
                            }
                        }
                    }
                }

                $fetchedOrders += count($orders);
                $offset += count($orders);

                $total = $response['paging']['total'] ?? 0;
                if ($offset >= $total) {
                    break;
                }

                usleep(self::BATCH_DELAY_MS * 1000);
            } catch (\Exception $e) {
                $this->log('warning', 'Erro ao buscar vendas', ['error' => $e->getMessage()]);
                break;
            }
        }

        $this->log('info', 'Vendas buscadas', [
            'orders_processed' => $fetchedOrders,
            'items_with_sales' => count(array_filter($salesByItem, fn(int $v): bool => $v > 0)),
        ]);

        return [$salesByItem, $sales14ByItem];
    }

    /**
     * Formata accountData no formato esperado pelo AccountGovernanceService
     */
    private function formatAccountData(array $sellerData): array
    {
        return [
            'seller_id' => $sellerData['seller_id'],
            'nickname' => $sellerData['nickname'] ?? '',
            'reputation_level' => $sellerData['reputation_level'] ?? 'unknown',
            'power_seller_status' => $sellerData['power_seller_status'] ?? null,
            'claims_rate' => $sellerData['claims_rate'] ?? 0.0,
            'late_shipment_rate' => $sellerData['late_shipment_rate'] ?? 0.0,
            'cancellation_rate' => $sellerData['cancellation_rate'] ?? 0.0,
            'total_transactions' => $sellerData['total_transactions'] ?? 0,
            'completed_transactions' => $sellerData['completed_transactions'] ?? 0,
        ];
    }

    /**
     * Constrói contexto do vendedor para o diagnóstico
     */
    private function buildSellerContext(array $sellerData, array $items): array
    {
        $categoryCount = [];
        foreach ($items as $item) {
            $cat = $item['category_id'] ?? '';
            if ($cat !== '') {
                $categoryCount[$cat] = ($categoryCount[$cat] ?? 0) + 1;
            }
        }
        arsort($categoryCount);
        $dominantCategory = array_key_first($categoryCount) ?? '';

        $prices = array_filter(array_column($items, 'price'), fn(float $p): bool => $p > 0);
        $avgPrice = count($prices) > 0 ? array_sum($prices) / count($prices) : 0;

        return [
            'site_id' => $sellerData['site_id'] ?? 'MLB',
            'dominant_category' => $dominantCategory,
            'category_distribution' => array_slice($categoryCount, 0, 5, true),
            'avg_price' => $avgPrice,
            'total_items' => count($items),
            'registration_date' => $sellerData['registration_date'] ?? null,
        ];
    }

    /**
     * Cria resposta de erro padronizada
     */
    private function errorResponse(string $code, string $message): array
    {
        return [
            'error' => true,
            'error_code' => $code,
            'message' => $message,
            'executive_summary' => null,
            'account_status' => null,
            'items' => [],
            'meta' => [
                'integration' => [
                    'source' => 'mercado_livre_api',
                    'error_at' => date('c'),
                ],
            ],
        ];
    }

    /**
     * Log com contexto
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $context['service'] = 'AccountGovernanceIntegrationService';

        match ($level) {
            'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            'info' => $this->logger->info($message, $context),
            'debug' => $this->logger->debug($message, $context),
            default => $this->logger->info($message, $context),
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC GETTERS FOR TESTING
    // ═══════════════════════════════════════════════════════════════════════

    public function getClient(): MercadoLivreClient
    {
        return $this->mlClient;
    }

    public function getMetricsService(): ItemMetricsService
    {
        return $this->metricsService;
    }

    public function getGovernanceService(): AccountGovernanceService
    {
        return $this->governanceService;
    }
}
