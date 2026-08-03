<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Coletor em massa de anúncios/vendedores da marca AWA.
 *
 * Contexto de rede (produção eskill.com.br):
 * - GET /sites/MLB/search → 403 PolicyAgent (IP datacenter)
 * - GET /items?ids= (itens de terceiros) → 403 access_denied
 * - GET /users/{other}/items/search → 403
 * - GET /products/search + GET /products/{id}/items → disponíveis
 * - GET /users/{id} (perfil público) → disponível
 *
 * Estratégia para >2000 anúncios:
 * 1. Várias seeds de query (AWA + termos de peça)
 * 2. Paginação offset/limit (máx. ~1000 por query — API rejeita offset>=2000)
 * 3. Domínios moto / veículo (filtra ruído: cozinha, livros, etc.)
 * 4. Retry com backoff exponencial + jitter em 429/5xx/rede
 * 5. Deduplicação por item_id e consolidação por seller_id
 */
class AwaSellerBulkCollectorService
{
    public const SITE_ID = 'MLB';
    public const AWA_BRAND_VALUE_ID = '7297804';
    public const PAGE_SIZE = 50;
    /** Offset máximo seguro para /products/search (offset 1000 ok; 2000 → bad_request). */
    public const MAX_OFFSET = 1000;
    public const DEFAULT_MAX_RETRIES = 5;

    /** @var list<string> */
    public const DEFAULT_QUERY_SEEDS = [
        // Seeds com melhor yield moto (validados 2026-08-03)
        'AWA moto',
        'AWA Motos',
        'AWA COMPONENTES',
        'AWA baulet',
        'AWA bauleto',
        'AWA farol',
        'AWA pisca',
        'AWA guidão',
        'AWA guidon',
        'AWA manete',
        'AWA manopla',
        'AWA escapamento',
        'AWA ponteira',
        'AWA capacete',
        'AWA carenagem',
        'AWA pedaleira',
        'AWA estribo',
        'AWA protetor',
        'AWA rabeta',
        'AWA painel',
        'AWA retrovisor',
        'bauleto AWA',
        'retrovisor AWA moto',
        'AWA',
    ];

    /**
     * Seeds amplas — só com domain_id (evita ruído de cozinha/móveis).
     *
     * @var list<string>
     */
    public const BROAD_QUERY_SEEDS = [
        'AWA peças',
        'AWA pecas',
        'marca AWA',
        'AWA retrovisor',
    ];

    /** @var list<string> Domínios prioritários (filtro domain_id). */
    public const PRIORITY_DOMAINS = [
        'MLB-MOTORCYCLE_CASES',
        'MLB-MOTORCYCLE_SPEEDOMETERS',
        'MLB-MOTORCYCLE_HAND_GUARDS',
        'MLB-MOTORCYCLE_REARVIEW_MIRRORS',
        'MLB-MOTORCYCLE_HANDLEBARS',
        'MLB-MOTORCYCLE_HANDLEBAR_GRIPS',
        'MLB-MOTORCYCLE_BRAKE_LEVERS',
        'MLB-MOTORCYCLE_CLUTCH_LEVERS',
        'MLB-MOTORCYCLE_MIRRORS',
        'MLB-MOTORCYCLE_EXHAUSTS',
        'MLB-MOTORCYCLE_FAIRINGS',
        'MLB-MOTORCYCLE_HELMETS',
        'MLB-MOTORCYCLE_GLOVES',
        'MLB-MOTORCYCLE_JACKETS',
        'MLB-MOTORCYCLE_SIDE_STANDS',
        'MLB-MOTORCYCLE_FOOTPEGS',
        'MLB-MOTORCYCLE_TURN_SIGNAL_LIGHTS',
        'MLB-MOTORCYCLE_HEADLIGHTS',
        'MLB-MOTORCYCLE_TAILLIGHTS',
        'MLB-MOTORCYCLE_SEATS',
        'MLB-MOTORCYCLE_FENDERS',
        'MLB-MOTORCYCLE_CHAIN_AND_SPROCKET_KITS',
        'MLB-MOTORCYCLE_BRAKE_PADS',
        'MLB-MOTORCYCLE_AIR_FILTERS',
        'MLB-VEHICLE_REARVIEW_MIRRORS',
        'MLB-VEHICLE_LICENSE_PLATE_BRACKETS',
        'MLB-VEHICLE_LIGHT_BULBS',
        'MLB-CAR_AND_MOTORCYCLE_CARE_PRODUCTS',
    ];

    private MercadoLivreClient $client;
    private LoggingService $logger;
    private int $maxRetries;
    private int $requestDelayMs;

    /** @var array{requests:int,retries:int,errors:int,products:int,items:int,sellers:int} */
    private array $stats;

    public function __construct(
        int $accountId,
        ?MercadoLivreClient $client = null,
        ?LoggingService $logger = null,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $requestDelayMs = 20
    ) {
        if ($accountId <= 0) {
            throw new RuntimeException('Conta ML inválida para AwaSellerBulkCollectorService.');
        }

        $this->client = $client ?? new MercadoLivreClient($accountId);
        $this->logger = $logger ?? new LoggingService();
        $this->maxRetries = max(1, $maxRetries);
        $this->requestDelayMs = max(0, $requestDelayMs);
        $this->resetStats();
    }

    /**
     * Coleta anúncios AWA e consolida por vendedor.
     *
     * @param array{
     *   max_items?:int,
     *   query_seeds?:list<string>,
     *   domains?:list<string>,
     *   include_noise_domains?:bool,
     *   enrich_sellers?:bool
     * } $options
     * @return array{
     *   items: list<array<string,mixed>>,
     *   sellers: list<array<string,mixed>>,
     *   stats: array<string,mixed>,
     *   collection_mode: string
     * }
     */
    public function collect(array $options = []): array
    {
        $this->resetStats();
        $maxItems = max(1, min(20000, (int) ($options['max_items'] ?? 5000)));
        $querySeeds = $this->normalizeStringList($options['query_seeds'] ?? self::DEFAULT_QUERY_SEEDS);
        if ($querySeeds === []) {
            $querySeeds = self::DEFAULT_QUERY_SEEDS;
        }
        $domains = $this->normalizeStringList($options['domains'] ?? self::PRIORITY_DOMAINS);
        $includeNoise = (bool) ($options['include_noise_domains'] ?? false);
        $enrichSellers = (bool) ($options['enrich_sellers'] ?? true);

        /** @var array<string, array<string, mixed>> $itemsById */
        $itemsById = [];
        /** @var array<int, true> $seenProducts */
        $seenProducts = [];

        $maxProductsPerSeed = max(100, min(1500, (int) ($options['max_products_per_seed'] ?? 800)));
        $steps = $options['steps'] ?? ['seeds', 'domains', 'broad'];
        if (!is_array($steps) || $steps === []) {
            $steps = ['seeds', 'domains', 'broad'];
        }

        // Passo 1: seeds focadas (paginação até MAX_OFFSET)
        if (in_array('seeds', $steps, true)) {
            foreach ($querySeeds as $query) {
                if (count($itemsById) >= $maxItems) {
                    break;
                }
                $this->logger->info('AWA_BULK_SEED', 'Iniciando seed de products/search', [
                    'query' => $query,
                    'items_so_far' => count($itemsById),
                ]);
                $this->collectFromProductSearch(
                    $query,
                    null,
                    $itemsById,
                    $seenProducts,
                    $maxItems,
                    $includeNoise,
                    $maxProductsPerSeed
                );
            }
        }

        // Passo 2: sharding por domain_id (cobre fatias >1000)
        if (in_array('domains', $steps, true)) {
            foreach ($domains as $domainId) {
                if (count($itemsById) >= $maxItems) {
                    break;
                }
                $this->logger->info('AWA_BULK_DOMAIN', 'Iniciando domain shard', [
                    'domain_id' => $domainId,
                    'items_so_far' => count($itemsById),
                ]);
                // Com domain, exige AWA no nome (includeNoise=false) para não misturar ruído
                $this->collectFromProductSearch(
                    'AWA',
                    $domainId,
                    $itemsById,
                    $seenProducts,
                    $maxItems,
                    false,
                    $maxProductsPerSeed
                );
            }
        }

        // Passo 3: seeds amplas somente com domain (evita varrer 10k de ruído)
        if (in_array('broad', $steps, true)) {
            foreach (self::BROAD_QUERY_SEEDS as $broadQuery) {
                foreach ($domains as $domainId) {
                    if (count($itemsById) >= $maxItems) {
                        break 2;
                    }
                    $this->collectFromProductSearch(
                        $broadQuery,
                        $domainId,
                        $itemsById,
                        $seenProducts,
                        $maxItems,
                        false,
                        $maxProductsPerSeed
                    );
                }
            }
        }

        $items = array_values($itemsById);
        $this->stats['items'] = count($items);

        $sellers = $this->consolidateSellers($items, $enrichSellers);
        $this->stats['sellers'] = count($sellers);

        $this->logger->info('AWA_BULK_COLLECT_DONE', 'Coleta AWA concluída', $this->stats);

        return [
            'items' => $items,
            'sellers' => $sellers,
            'stats' => $this->stats,
            'collection_mode' => 'products_search_sharded',
            'brand_value_id' => self::AWA_BRAND_VALUE_ID,
            'site_id' => self::SITE_ID,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $itemsById
     * @param array<int, true> $seenProducts
     */
    private function collectFromProductSearch(
        string $query,
        ?string $domainId,
        array &$itemsById,
        array &$seenProducts,
        int $maxItems,
        bool $includeNoise,
        int $maxProductsPerSeed = 250
    ): void {
        $offset = 0;
        $total = null;
        $productsTouched = 0;

        do {
            $params = [
                'status' => 'active',
                'site_id' => self::SITE_ID,
                'q' => $query,
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ];
            if ($domainId !== null && $domainId !== '') {
                $params['domain_id'] = $domainId;
            }

            $page = $this->requestGet('/products/search', $params);
            if (isset($page['error'])) {
                $this->stats['errors']++;
                $this->logger->warning('AWA_BULK_PRODUCTS_SEARCH_ERROR', 'Falha em products/search', [
                    'query' => $query,
                    'domain_id' => $domainId,
                    'offset' => $offset,
                    'error' => $page['error'] ?? null,
                    'message' => $page['message'] ?? null,
                ]);
                break;
            }

            $total = (int) ($page['paging']['total'] ?? 0);
            $results = is_array($page['results'] ?? null) ? $page['results'] : [];
            if ($results === []) {
                break;
            }

            foreach ($results as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $productId = (string) ($product['id'] ?? '');
                if ($productId === '' || isset($seenProducts[$productId])) {
                    continue;
                }
                $seenProducts[$productId] = true;
                $this->stats['products']++;

                $domain = (string) ($product['domain_id'] ?? '');
                $productName = (string) ($product['name'] ?? '');

                // Sem domain shard: exige domínio moto OU nome AWA+peça.
                // Com domain shard: exige marca AWA no nome (domain já restringe categoria).
                $relevant = $domainId !== null && $domainId !== ''
                    ? $this->containsAwaKeyword($productName)
                    : ($includeNoise
                        || $this->isRelevantDomain($domain)
                        || $this->isRelevantProductName($productName));

                if (!$relevant) {
                    continue;
                }

                $beforeCount = count($itemsById);
                $this->collectProductItems($productId, $domain, $productName, $itemsById, $maxItems);
                // Conta só produtos que renderam anúncios (muitos catálogos têm 0 itens ativos)
                if (count($itemsById) > $beforeCount) {
                    $productsTouched++;
                }

                if (count($itemsById) >= $maxItems || $productsTouched >= $maxProductsPerSeed) {
                    return;
                }
            }

            $offset += self::PAGE_SIZE;
            if ($offset > self::MAX_OFFSET) {
                break;
            }
        } while ($total !== null && $offset < $total);
    }

    /**
     * @param array<string, array<string, mixed>> $itemsById
     */
    private function collectProductItems(
        string $productId,
        string $domainId,
        string $productName,
        array &$itemsById,
        int $maxItems
    ): void {
        $offset = 0;
        $total = null;

        do {
            $page = $this->requestGet('/products/' . rawurlencode($productId) . '/items', [
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ]);

            if (isset($page['error'])) {
                $this->stats['errors']++;
                break;
            }

            $total = (int) ($page['paging']['total'] ?? 0);
            $results = is_array($page['results'] ?? null) ? $page['results'] : [];
            if ($results === []) {
                break;
            }

            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $itemId = (string) ($row['item_id'] ?? $row['id'] ?? '');
                if ($itemId === '' || isset($itemsById[$itemId])) {
                    continue;
                }

                $sellerId = (int) ($row['seller_id'] ?? 0);
                $city = null;
                $state = null;
                if (isset($row['seller_address']) && is_array($row['seller_address'])) {
                    $city = $this->nestedName($row['seller_address']['city'] ?? null);
                    $state = $this->nestedName($row['seller_address']['state'] ?? null);
                }

                $titleHint = $productName !== '' ? $productName : $itemId;
                $hasAwaInTitle = $this->containsAwaKeyword($titleHint);

                $itemsById[$itemId] = [
                    'id' => $itemId,
                    'title' => $titleHint,
                    'seller_id' => $sellerId,
                    'category_id' => (string) ($row['category_id'] ?? ''),
                    'price' => isset($row['price']) ? (float) $row['price'] : null,
                    'status' => 'active',
                    'condition' => $row['condition'] ?? null,
                    'permalink' => null,
                    'thumbnail' => null,
                    'listing_type_id' => $row['listing_type_id'] ?? null,
                    'catalog_product_id' => $productId,
                    'domain_id' => $domainId,
                    'official_store_id' => $row['official_store_id'] ?? null,
                    'shipping' => is_array($row['shipping'] ?? null) ? $row['shipping'] : [],
                    'seller_city' => $city,
                    'seller_state' => $state,
                    'brand_analysis' => [
                        'has_brand' => true,
                        'is_correct' => true,
                        'source' => 'catalog_product_search',
                        'brand_value_id' => self::AWA_BRAND_VALUE_ID,
                    ],
                    'brand_match_type' => $hasAwaInTitle ? 'attribute_match' : 'catalog_match',
                ];

                if (count($itemsById) >= $maxItems) {
                    return;
                }
            }

            $offset += self::PAGE_SIZE;
            // /products/{id}/items não documenta o mesmo hard-cap; ainda assim limitamos páginas.
            if ($offset > 5000) {
                break;
            }
        } while ($total !== null && $offset < $total);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function consolidateSellers(array $items, bool $enrich): array
    {
        /** @var array<int, array<string, mixed>> $bySeller */
        $bySeller = [];

        foreach ($items as $item) {
            $sellerId = (int) ($item['seller_id'] ?? 0);
            if ($sellerId <= 0) {
                continue;
            }

            if (!isset($bySeller[$sellerId])) {
                $bySeller[$sellerId] = [
                    'seller_id' => $sellerId,
                    'nickname' => 'Seller ' . $sellerId,
                    'permalink' => null,
                    'city' => $item['seller_city'] ?? null,
                    'state' => $item['seller_state'] ?? null,
                    'user_type' => null,
                    'reputation_level' => null,
                    'power_seller_status' => null,
                    'account_status' => null,
                    'items_count' => 0,
                    'categories' => [],
                    'items' => [],
                ];
            }

            $bySeller[$sellerId]['items_count']++;
            $bySeller[$sellerId]['items'][] = $item;

            $categoryId = (string) ($item['category_id'] ?? '');
            if ($categoryId !== '') {
                $bySeller[$sellerId]['categories'][$categoryId] = true;
            }

            if (empty($bySeller[$sellerId]['city']) && !empty($item['seller_city'])) {
                $bySeller[$sellerId]['city'] = $item['seller_city'];
            }
            if (empty($bySeller[$sellerId]['state']) && !empty($item['seller_state'])) {
                $bySeller[$sellerId]['state'] = $item['seller_state'];
            }
        }

        foreach ($bySeller as $sellerId => &$seller) {
            $seller['categories'] = array_keys($seller['categories']);
            if ($enrich) {
                $profile = $this->fetchSellerProfile($sellerId);
                if ($profile !== null) {
                    $seller['nickname'] = $profile['nickname'] ?? $seller['nickname'];
                    $seller['permalink'] = $profile['permalink'] ?? null;
                    $seller['city'] = $seller['city'] ?: ($profile['city'] ?? null);
                    $seller['state'] = $seller['state'] ?: ($profile['state'] ?? null);
                    $seller['user_type'] = $profile['user_type'] ?? null;
                    $seller['reputation_level'] = $profile['reputation_level'] ?? null;
                    $seller['power_seller_status'] = $profile['power_seller_status'] ?? null;
                    $seller['account_status'] = $profile['account_status'] ?? null;
                }
            }
        }
        unset($seller);

        $list = array_values($bySeller);
        usort($list, static fn(array $a, array $b): int => $b['items_count'] <=> $a['items_count']);

        return $list;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSellerProfile(int $sellerId): ?array
    {
        $response = $this->requestGet('/users/' . $sellerId, []);
        if (isset($response['error']) || empty($response['id'])) {
            $this->stats['errors']++;
            return null;
        }

        return [
            'nickname' => (string) ($response['nickname'] ?? ('Seller ' . $sellerId)),
            'permalink' => $response['permalink'] ?? null,
            'city' => $this->nestedName($response['address']['city'] ?? null),
            'state' => $this->nestedName($response['address']['state'] ?? null),
            'user_type' => $response['user_type'] ?? null,
            'reputation_level' => $response['seller_reputation']['level_id'] ?? null,
            'power_seller_status' => $response['seller_reputation']['power_seller_status'] ?? null,
            'account_status' => $response['status']['site_status'] ?? null,
        ];
    }

    /**
     * GET com retry/backoff para 429, 5xx e erros transitórios.
     *
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    private function requestGet(string $endpoint, array $params): array
    {
        $attempt = 0;
        $last = ['error' => 'unknown', 'message' => 'Sem resposta'];

        while ($attempt < $this->maxRetries) {
            $attempt++;
            $this->stats['requests']++;

            if ($this->requestDelayMs > 0) {
                usleep($this->requestDelayMs * 1000);
            }

            try {
                $response = $this->client->get($endpoint, $params, 90, false);
            } catch (\Throwable $e) {
                $this->stats['retries']++;
                $this->sleepBackoff($attempt);
                $last = ['error' => 'exception', 'message' => $e->getMessage()];
                continue;
            }

            if (!is_array($response)) {
                $this->stats['retries']++;
                $this->sleepBackoff($attempt);
                $last = ['error' => 'invalid_response', 'message' => 'Resposta não-array'];
                continue;
            }

            $status = (int) ($response['status'] ?? $response['http_status'] ?? 0);
            $error = $response['error'] ?? null;

            if ($error === null && $status < 400) {
                return $response;
            }

            $retryable = $status === 429
                || $status >= 500
                || in_array((string) $error, ['too_many_requests', 'timeout', 'connection_error', 'server_error'], true);

            if (!$retryable || $attempt >= $this->maxRetries) {
                return $response;
            }

            $this->stats['retries']++;
            $retryAfter = (int) ($response['retry_after'] ?? 0);
            if ($status === 429 && $retryAfter > 0 && $retryAfter <= 60) {
                sleep($retryAfter + 1);
            } else {
                $this->sleepBackoff($attempt);
            }
            $last = $response;
        }

        return is_array($last) ? $last : ['error' => 'retries_exhausted'];
    }

    private function sleepBackoff(int $attempt): void
    {
        $baseMs = (int) min(8000, (2 ** max(0, $attempt - 1)) * 250);
        $jitterMs = random_int(0, 200);
        usleep(($baseMs + $jitterMs) * 1000);
    }

    private function isRelevantProductName(string $name): bool
    {
        if ($name === '' || !$this->containsAwaKeyword($name)) {
            return false;
        }

        return preg_match(
            '/moto|baulet|ba[uú]l|retrovisor|espelho|farol|pisca|lanterna|guid[aã]o|guidon|manete|manopla|escap|ponteira|capacete|luva|protetor|pedaleira|estribo|rabeta|carenagem|speed[oô]metro|painel|peças|pecas|componente|amortecedor|filtro|corrente|pastilha/iu',
            $name
        ) === 1;
    }

    private function isRelevantDomain(string $domainId): bool
    {
        if ($domainId === '') {
            return false;
        }

        $upper = strtoupper($domainId);

        // Exclui ruído explícito (bike lifestyle / casa / livros)
        foreach (['BICYCLE_', 'KITCHEN', 'BOOK', 'CANDY', 'MAKEUP', 'POUF', 'TROPH', 'PAINT'] as $deny) {
            if (str_contains($upper, $deny)) {
                return false;
            }
        }

        foreach (['MOTORCYCLE', 'VEHICLE_', 'MOTO', 'AUTOMOTIVE', 'CAR_AND_MOTORCYCLE'] as $needle) {
            if (str_contains($upper, $needle)) {
                return true;
            }
        }

        return in_array($domainId, self::PRIORITY_DOMAINS, true);
    }

    private function containsAwaKeyword(string $title): bool
    {
        return preg_match('/(^|\b)A[\s\.-]*W[\s\.-]*A(\b|$)/iu', $title) === 1;
    }

    /**
     * @param mixed $node
     */
    private function nestedName(mixed $node): ?string
    {
        if (is_string($node) && trim($node) !== '') {
            return trim($node);
        }
        if (is_array($node)) {
            $name = $node['name'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    private function resetStats(): void
    {
        $this->stats = [
            'requests' => 0,
            'retries' => 0,
            'errors' => 0,
            'products' => 0,
            'items' => 0,
            'sellers' => 0,
            'max_offset' => self::MAX_OFFSET,
            'page_size' => self::PAGE_SIZE,
        ];
    }
}
