<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use App\Database;

/**
 * Serviço de Pesquisa Alternativa do Mercado Livre
 * 
 * Usa métodos alternativos quando a API de busca está bloqueada:
 * - Scraping do site (com User-Agent de navegador)
 * - API de tendências via categoria
 * - Busca por seller específico
 * - Cache de dados históricos
 */
class AlternativeSearchService
{
    private Client $httpClient;
    private CacheService $cache;
    private ProxyService $proxyService;
    private ?int $accountId;
    private string $siteId = 'MLB';

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
    ];

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->cache = new CacheService();
        $this->proxyService = new ProxyService();

        $this->httpClient = new Client([
            'timeout' => 30,
            'verify' => true,
            'cookies' => true,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Cache-Control' => 'max-age=0',
            ],
        ]);

        $config = \App\Core\Config::getInstance()->all();
        $this->siteId = $config['mercadolivre']['site_id'] ?? 'MLB';
    }

    /**
     * Busca produtos usando scraping do site
     */
    public function searchByScraping(string $query, array $filters = []): array
    {
        $cacheKey = 'scrape_search_' . md5($query . json_encode($filters));

        // Verificar cache (10 minutos)
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $url = $this->buildSearchUrl($query, $filters);
        $proxy = $this->proxyService->getBestProxy();

        $options = [
            'headers' => [
                'User-Agent' => $this->getRandomUserAgent(),
                'Referer' => 'https://www.mercadolivre.com.br/',
            ],
        ];

        if ($proxy) {
            $options['proxy'] = $proxy['url'];
        }

        try {
            $response = $this->httpClient->get($url, $options);
            $html = $response->getBody()->getContents();

            $results = $this->parseSearchResults($html);

            if ($proxy) {
                $this->proxyService->recordSuccess($proxy['id']);
            }

            // Cachear resultados
            $this->cache->set($cacheKey, $results, 600);

            return $results;
        } catch (\Exception $e) {
            if ($proxy) {
                $this->proxyService->recordFailure($proxy['id'], $e->getMessage());
            }

            log_warning('Erro no scraping de busca alternativa', [
                'service' => 'AlternativeSearchService',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    /**
     * Busca dados de categoria (funciona sem bloqueio)
     */
    public function getCategoryData(string $categoryId): array
    {
        $cacheKey = 'category_data_' . $categoryId;

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        try {
            $client = new MercadoLivreClient($this->accountId);

            // Esses endpoints geralmente não são bloqueados
            $category = $client->get("/categories/{$categoryId}");
            $attributes = $client->get("/categories/{$categoryId}/attributes");

            $data = [
                'success' => true,
                'category' => $category,
                'attributes' => $attributes,
                'brands' => $this->extractBrandsFromAttributes($attributes),
            ];

            $this->cache->set($cacheKey, $data, 3600);

            return $data;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Busca por sellers específicos (funciona com autenticação)
     */
    public function searchBySeller(int $sellerId, array $filters = []): array
    {
        $cacheKey = 'seller_items_' . $sellerId . '_' . md5(json_encode($filters));

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        try {
            $client = new MercadoLivreClient($this->accountId);

            $params = [
                'limit' => $filters['limit'] ?? 50,
                'offset' => $filters['offset'] ?? 0,
            ];

            if (!empty($filters['category'])) {
                $params['category'] = $filters['category'];
            }

            $response = $client->get("/users/{$sellerId}/items/search", $params);

            if (isset($response['error'])) {
                return [
                    'success' => false,
                    'error' => $response['message'] ?? 'Erro ao buscar items',
                ];
            }

            // Buscar detalhes dos items
            $itemIds = $response['results'] ?? [];
            $items = [];

            if (!empty($itemIds)) {
                $items = $this->getItemsDetails($itemIds);
            }

            $data = [
                'success' => true,
                'seller_id' => $sellerId,
                'total' => $response['paging']['total'] ?? 0,
                'items' => $items,
            ];

            $this->cache->set($cacheKey, $data, 600);

            return $data;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtém detalhes de múltiplos items em paralelo
     */
    public function getItemsDetails(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        // API do ML aceita até 20 items por vez no multiget
        $chunks = array_chunk($itemIds, 20);
        $allItems = [];

        $client = new MercadoLivreClient($this->accountId);

        foreach ($chunks as $chunk) {
            $ids = implode(',', $chunk);

            try {
                $response = $client->get("/items", ['ids' => $ids]);

                if (is_array($response)) {
                    foreach ($response as $item) {
                        if (isset($item['body'])) {
                            $allItems[] = $item['body'];
                        }
                    }
                }
            } catch (\Exception $e) {
                log_warning('Erro ao buscar detalhes de items', [
                    'service' => 'AlternativeSearchService',
                    'ids' => $ids,
                    'error' => $e->getMessage(),
                ]);
            }

            // Respeitar rate limit
            usleep(100000);
        }

        return $allItems;
    }

    /**
     * Analisa vendedores de uma marca usando dados disponíveis
     */
    public function analyzeBrandSellers(string $categoryId, string $brand): array
    {
        $cacheKey = 'brand_sellers_' . $categoryId . '_' . md5($brand);

        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // Tentar buscar via scraping primeiro
        $searchResults = $this->searchByScraping("{$brand}", [
            'category' => $categoryId,
            'limit' => 100,
        ]);

        if (!$searchResults['success'] || empty($searchResults['results'])) {
            // Fallback: usar dados do cache histórico
            $historicalData = $this->getHistoricalData($categoryId, $brand);

            if ($historicalData) {
                return $historicalData;
            }

            return [
                'success' => false,
                'error' => 'Não foi possível obter dados. API de busca bloqueada e sem dados históricos.',
                'suggestion' => 'Configure um proxy residencial para acessar a API de busca.',
            ];
        }

        // Processar resultados
        $sellers = [];
        $items = $searchResults['results'];

        foreach ($items as $item) {
            $sellerId = $item['seller_id'] ?? null;

            if (!$sellerId) continue;

            if (!isset($sellers[$sellerId])) {
                $sellers[$sellerId] = [
                    'id' => $sellerId,
                    'nickname' => $item['seller_nickname'] ?? 'Desconhecido',
                    'items_count' => 0,
                    'total_sold' => 0,
                    'price_range' => ['min' => PHP_INT_MAX, 'max' => 0],
                    'items' => [],
                ];
            }

            $sellers[$sellerId]['items_count']++;
            $sellers[$sellerId]['total_sold'] += $item['sold_quantity'] ?? 0;

            $price = $item['price'] ?? 0;
            if ($price > 0) {
                $sellers[$sellerId]['price_range']['min'] = min($sellers[$sellerId]['price_range']['min'], $price);
                $sellers[$sellerId]['price_range']['max'] = max($sellers[$sellerId]['price_range']['max'], $price);
            }

            $sellers[$sellerId]['items'][] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'price' => $price,
                'sold' => $item['sold_quantity'] ?? 0,
            ];
        }

        // Ordenar por quantidade vendida
        usort($sellers, fn($a, $b) => $b['total_sold'] <=> $a['total_sold']);

        $data = [
            'success' => true,
            'category_id' => $categoryId,
            'brand' => $brand,
            'total_items' => count($items),
            'total_sellers' => count($sellers),
            'sellers' => array_values($sellers),
            'source' => 'scraping',
            'timestamp' => time(),
        ];

        // Salvar no cache e histórico
        $this->cache->set($cacheKey, $data, 1800);
        $this->saveHistoricalData($categoryId, $brand, $data);

        return $data;
    }

    /**
     * Constrói URL de busca do ML
     */
    private function buildSearchUrl(string $query, array $filters = []): string
    {
        $baseUrl = 'https://lista.mercadolivre.com.br/';

        // Formatar query para URL
        $query = str_replace(' ', '-', trim($query));
        $url = $baseUrl . urlencode($query);

        $params = [];

        if (!empty($filters['category'])) {
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['price_min'])) {
            $params['price-min'] = $filters['price_min'];
        }

        if (!empty($filters['price_max'])) {
            $params['price-max'] = $filters['price_max'];
        }

        if (!empty($params)) {
            $url .= '_' . http_build_query($params, '', '_');
        }

        return $url;
    }

    /**
     * Parseia HTML da página de resultados
     */
    private function parseSearchResults(string $html): array
    {
        $results = [];

        // Tentar encontrar dados JSON embutidos
        if (preg_match('/<script[^>]*id="__PRELOADED_STATE__"[^>]*>(.+?)<\/script>/s', $html, $matches)) {
            $jsonData = json_decode($matches[1], true);

            if ($jsonData && isset($jsonData['initialState']['results'])) {
                foreach ($jsonData['initialState']['results'] as $item) {
                    $results[] = $this->normalizeItem($item);
                }

                return [
                    'success' => true,
                    'results' => $results,
                    'total' => count($results),
                    'source' => 'json_embedded',
                ];
            }
        }

        // Fallback: parsing HTML com regex (menos confiável)
        preg_match_all('/data-item-id="([^"]+)"/', $html, $itemIds);
        preg_match_all('/class="[^"]*ui-search-item__title[^"]*"[^>]*>([^<]+)</', $html, $titles);
        preg_match_all('/class="[^"]*price-tag-fraction[^"]*"[^>]*>([^<]+)</', $html, $prices);

        $count = min(count($itemIds[1]), count($titles[1]), count($prices[1]));

        for ($i = 0; $i < $count; $i++) {
            $results[] = [
                'id' => $itemIds[1][$i] ?? null,
                'title' => html_entity_decode($titles[1][$i] ?? ''),
                'price' => (float) preg_replace('/[^\d]/', '', $prices[1][$i] ?? '0'),
            ];
        }

        return [
            'success' => count($results) > 0,
            'results' => $results,
            'total' => count($results),
            'source' => 'html_parsing',
        ];
    }

    /**
     * Normaliza item do JSON para formato padrão
     */
    private function normalizeItem(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? '',
            'price' => $item['price'] ?? 0,
            'original_price' => $item['original_price'] ?? null,
            'sold_quantity' => $item['sold_quantity'] ?? 0,
            'available_quantity' => $item['available_quantity'] ?? 0,
            'condition' => $item['condition'] ?? 'new',
            'thumbnail' => $item['thumbnail'] ?? '',
            'permalink' => $item['permalink'] ?? '',
            'seller_id' => $item['seller']['id'] ?? null,
            'seller_nickname' => $item['seller']['nickname'] ?? '',
            'shipping_free' => $item['shipping']['free_shipping'] ?? false,
        ];
    }

    /**
     * Extrai marcas dos atributos da categoria
     */
    private function extractBrandsFromAttributes(array $attributes): array
    {
        $brands = [];

        foreach ($attributes as $attr) {
            if (($attr['id'] ?? '') === 'BRAND' && isset($attr['values'])) {
                foreach ($attr['values'] as $value) {
                    $brands[] = [
                        'id' => $value['id'] ?? null,
                        'name' => $value['name'] ?? '',
                    ];
                }
                break;
            }
        }

        return $brands;
    }

    /**
     * Obtém dados históricos do banco
     */
    private function getHistoricalData(string $categoryId, string $brand): ?array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT data, created_at 
                FROM ml_research_cache 
                WHERE category_id = :category AND brand = :brand 
                ORDER BY created_at DESC 
                LIMIT 1
            ");

            $stmt->execute([
                'category' => $categoryId,
                'brand' => $brand,
            ]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $data = json_decode($row['data'], true);
                $data['source'] = 'historical';
                $data['cached_at'] = $row['created_at'];
                return $data;
            }
        } catch (\Exception $e) {
            // Tabela pode não existir
        }

        return null;
    }

    /**
     * Salva dados no histórico
     */
    private function saveHistoricalData(string $categoryId, string $brand, array $data): void
    {
        try {
            $db = Database::getInstance();

            // Criar tabela se não existir
            $db->exec("
                CREATE TABLE IF NOT EXISTS ml_research_cache (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    category_id VARCHAR(20) NOT NULL,
                    brand VARCHAR(100) NOT NULL,
                    data LONGTEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_category_brand (category_id, brand),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmt = $db->prepare("
                INSERT INTO ml_research_cache (category_id, brand, data)
                VALUES (:category, :brand, :data)
            ");

            $stmt->execute([
                'category' => $categoryId,
                'brand' => $brand,
                'data' => json_encode($data),
            ]);

            // Limpar dados antigos (manter últimos 30 dias)
            $db->exec("
                DELETE FROM ml_research_cache 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
        } catch (\Exception $e) {
            log_warning('Erro ao salvar dados históricos de pesquisa', [
                'service' => 'AlternativeSearchService',
                'category_id' => $categoryId,
                'brand' => $brand,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retorna User-Agent aleatório
     */
    private function getRandomUserAgent(): string
    {
        return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    }
}
