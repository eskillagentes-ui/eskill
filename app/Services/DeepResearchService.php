<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\MercadoLivreClient;
use App\Services\CacheService;
use App\Services\SeoAnalyzerService;
use App\Database;

/**
 * Serviço de Pesquisa Profunda de Anúncios (Deep Research)
 *
 * Análise revolucionária e completa de marcas dentro de categorias:
 * - Mapeamento completo de todos os anúncios (catálogo e comuns)
 * - Análise detalhada de sellers (reputação, histórico, poder de mercado)
 * - Breakdown de fretes (grátis, pago, Full, Flex)
 * - Cálculo de comissões por tipo de listagem
 * - Análise de preços e margens estimadas
 * - Identificação de oportunidades e gaps de mercado
 * - Análise de sazonalidade e tendências
 */
class DeepResearchService
{
    private MercadoLivreClient $client;
    private CacheService $cache;
    private SeoAnalyzerService $seoAnalyzer;
    private string $siteId;
    private array $collectedData = [];

    // Comissões do ML por tipo de listagem (Brasil - 2024)
    private const ML_COMMISSIONS = [
        'gold_pro' => 16.5,      // Premium / Clássico
        'gold_premium' => 16.5,  // Premium
        'gold_special' => 12.0,  // Clássico
        'gold' => 11.0,          // Ouro
        'silver' => 10.0,        // Prata
        'bronze' => 9.0,         // Bronze
        'free' => 13.0,          // Grátis (com venda)
    ];

    // Taxa de pagamento Mercado Pago
    private const PAYMENT_FEE = 4.99;

    // Custos de frete Full estimados por faixa de peso
    private const FULL_SHIPPING_COSTS = [
        'light' => 15.90,    // até 500g
        'medium' => 19.90,   // 500g - 2kg
        'heavy' => 28.90,    // 2kg - 5kg
        'extra' => 45.90,    // 5kg+
    ];

    public function __construct(?int $accountId = null)
    {
        $config = \App\Core\Config::getInstance()->all();
        $this->siteId = $config['mercadolivre']['site_id'];
        $this->client = new MercadoLivreClient($accountId);
        $this->cache = new CacheService();
        $this->seoAnalyzer = new SeoAnalyzerService($accountId);
    }

    /**
     * Executa pesquisa profunda de uma marca em uma categoria
     *
     * @param string $categoryId ID da categoria (ex: MLB1071 - Acessórios para Motos)
     * @param string $brand Nome da marca (ex: AWA)
     * @param array $options Opções adicionais
     * @return array Resultado completo da análise
     */
    public function researchBrand(string $categoryId, string $brand, array $options = []): array
    {
        $maxItems = $options['max_items'] ?? 1000;
        $includeSellerDetails = $options['include_seller_details'] ?? true;
        $analyzeShipping = $options['analyze_shipping'] ?? true;
        $calculateCommissions = $options['calculate_commissions'] ?? true;

        // Filtros avançados
        $filters = [
            'price_min' => $options['price_min'] ?? null,
            'price_max' => $options['price_max'] ?? null,
            'condition' => $options['condition'] ?? null,
            'shipping' => $options['shipping'] ?? null,
            'listing_type' => $options['listing_type'] ?? null,
            'seller_reputation' => $options['seller_reputation'] ?? null,
            'sort' => $options['sort'] ?? null,
        ];

        $startTime = microtime(true);

        $result = [
            'research_id' => uniqid('dr_'),
            'category_id' => $categoryId,
            'brand' => $brand,
            'research_date' => date('Y-m-d H:i:s'),
            'status' => 'processing',
            'filters_applied' => array_filter($filters, fn(mixed $v): bool => $v !== null),
            'summary' => [],
            'listings' => [],
            'sellers' => [],
            'pricing' => [],
            'shipping' => [],
            'commissions' => [],
            'opportunities' => [],
            'insights' => [],
        ];

        try {
            // 1. Coletar todos os anúncios da marca na categoria (com filtros)
            $allListings = $this->collectAllListings($categoryId, $brand, $maxItems, $filters);
            $result['summary']['total_listings'] = count($allListings);

            if (empty($allListings)) {
                $result['status'] = 'no_results';
                $result['message'] = 'Nenhum anúncio encontrado para esta marca/categoria';
                return $result;
            }

            // 2. Classificar anúncios (catálogo vs comum)
            $classified = $this->classifyListings($allListings);
            $result['listings'] = $classified;

            // 3. Analisar sellers
            if ($includeSellerDetails) {
                $result['sellers'] = $this->analyzeSellersFull($allListings);
            }

            // 4. Analisar preços detalhadamente
            $result['pricing'] = $this->analyzePricingDeep($allListings);

            // 5. Analisar fretes
            if ($analyzeShipping) {
                $result['shipping'] = $this->analyzeShippingDeep($allListings);
            }

            // 6. Calcular comissões
            if ($calculateCommissions) {
                $result['commissions'] = $this->analyzeCommissions($allListings);
            }

            // 7. Análise de Velocidade de Vendas (Novo - Professional Grade)
            $result['sales_velocity'] = $this->analyzeSalesVelocity($allListings);

            // 8. Análise de Qualidade SEO dos Concorrentes (Novo - Professional Grade)
            $result['competitor_seo'] = $this->analyzeCompetitorSeo($allListings);

            // 9. Identificar oportunidades
            $result['opportunities'] = $this->identifyOpportunities($result);

            // 10. Gerar insights estratégicos
            $result['insights'] = $this->generateStrategicInsights($result);

            // 11. Resumo executivo
            $result['summary'] = $this->generateExecutiveSummary($result);

            $result['status'] = 'completed';
            $result['processing_time_seconds'] = round(microtime(true) - $startTime, 2);
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Coleta TODOS os anúncios de uma marca na categoria (paginação completa)
     *
     * @param string $categoryId ID da categoria
     * @param string $brand Nome da marca
     * @param int $maxItems Máximo de itens a coletar
     * @param array $filters Filtros avançados (price, condition, shipping, etc)
     */
    private function collectAllListings(string $categoryId, string $brand, int $maxItems, array $filters = []): array
    {
        // Tentar API oficial primeiro
        try {
            $items = $this->collectViaOfficialApi($categoryId, $brand, $maxItems, $filters);
            if (!empty($items)) {
                return $items;
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Se não for bloqueio de política, propagar erro
            if (
                strpos($errorMessage, 'indisponível') === false &&
                strpos($errorMessage, 'forbidden') === false &&
                strpos($errorMessage, 'Policy') === false &&
                strpos($errorMessage, 'bloqueada') === false
            ) {
                throw $e;
            }

            log_info('API oficial bloqueada, tentando alternativas', [
                'category_id' => $categoryId,
                'brand' => $brand,
            ]);
        }

        // Fallback 1: Buscar via sellers conhecidos da categoria
        try {
            log_info('Tentando busca via sellers conhecidos');
            $items = $this->collectViaCategorySellers($categoryId, $brand, $maxItems);
            if (!empty($items)) {
                log_info('Sucesso via sellers', ['count' => count($items)]);
                return $items;
            }
        } catch (\Exception $e) {
            log_warning('Fallback via sellers falhou', ['error' => $e->getMessage()]);
        }

        // Fallback 2: Serviço alternativo com scraping
        try {
            log_info('Tentando scraping como fallback');
            $altService = new AlternativeSearchService($this->client->getAccountId());
            $result = $altService->analyzeBrandSellers($categoryId, $brand);

            if ($result['success'] && !empty($result['sellers'])) {
                // Converter formato do serviço alternativo para formato esperado
                $items = [];
                foreach ($result['sellers'] as $seller) {
                    foreach ($seller['items'] ?? [] as $item) {
                        $items[] = array_merge($item, [
                            'seller' => [
                                'id' => $seller['id'],
                                'nickname' => $seller['nickname'],
                            ],
                        ]);
                    }
                }

                if (!empty($items)) {
                    log_info('Sucesso via scraping', ['count' => count($items)]);
                    return $items;
                }
            }
        } catch (\Exception $e) {
            log_warning('Fallback via scraping falhou', ['error' => $e->getMessage()]);
        }

        // Fallback 3: Dados do cache histórico
        $cacheKey = "research_cache_{$categoryId}_{$brand}";
        $cached = $this->cache->get($cacheKey);
        if ($cached && !empty($cached['items'])) {
            log_info('Usando dados do cache histórico para pesquisa');
            return $cached['items'];
        }

        // Nenhum método funcionou - fornecer mensagem detalhada
        $message = 'Não foi possível obter dados de pesquisa de mercado. ';

        // Verificar se há itens próprios do usuário para análise
        try {
            $userInfo = $this->client->get('/users/me');
            if (!isset($userInfo['error']) && isset($userInfo['id'])) {
                $userId = $userInfo['id'];
                $myItems = $this->client->get("/users/{$userId}/items/search", ['category' => $categoryId, 'limit' => 1]);
                if (isset($myItems['paging']['total']) && $myItems['paging']['total'] > 0) {
                    $message .= "Porém, você tem {$myItems['paging']['total']} anúncios nesta categoria que podem ser analisados em 'Meus Anúncios'. ";
                }
            }
        } catch (\Exception $e) {
            // Ignorar erro aqui
        }

        $message .= 'A API de busca pública do Mercado Livre bloqueia requisições de servidores de data center. ';
        $message .= 'Opções: 1) Configure um proxy residencial em Configurações > Proxies; ';
        $message .= '2) Use a função "Analisar Meus Anúncios" para seus próprios produtos; ';
        $message .= '3) Acesse de uma rede residencial.';

        throw new \Exception($message);
    }

    /**
     * Coleta items via sellers conhecidos da categoria
     * Este método usa endpoints que NÃO são bloqueados pelo ML
     */
    private function collectViaCategorySellers(string $categoryId, string $brand, int $maxItems): array
    {
        $items = [];
        $seenItemIds = [];

        // 1. Primeiro, tentar buscar itens do próprio usuário na categoria
        try {
            $myItems = $this->collectMyItemsInCategory($categoryId, $brand);
            if (!empty($myItems)) {
                foreach ($myItems as $item) {
                    if (!in_array($item['id'], $seenItemIds)) {
                        $seenItemIds[] = $item['id'];
                        $items[] = $item;
                    }
                }
                log_info('Itens próprios encontrados na categoria', ['count' => count($items)]);
            }
        } catch (\Exception $e) {
            log_warning('Erro ao buscar itens próprios na categoria', ['error' => $e->getMessage()]);
        }

        // 2. Buscar sellers conhecidos da categoria
        $knownSellers = $this->getKnownSellersForCategory($categoryId);

        if (empty($knownSellers)) {
            // Se não temos sellers conhecidos, tentar descobrir via trends/attributes
            $knownSellers = $this->discoverSellersFromTrends($categoryId, $brand);
        }

        log_info('Buscando itens de sellers conhecidos', ['seller_count' => count($knownSellers)]);

        // 3. Para cada seller, buscar seus itens na categoria
        foreach ($knownSellers as $sellerId) {
            if (count($items) >= $maxItems) break;

            try {
                // Tentar buscar items do seller (pode precisar de auth)
                $response = $this->client->get("/users/{$sellerId}/items/search", [
                    'category' => $categoryId,
                    'limit' => 50,
                ]);

                if (isset($response['error'])) continue;

                $itemIds = $response['results'] ?? [];

                // Buscar detalhes dos itens
                foreach (array_chunk($itemIds, 20) as $chunk) {
                    if (count($items) >= $maxItems) break;

                    $idsParam = implode(',', $chunk);
                    $itemsData = $this->client->get("/items", ['ids' => $idsParam]);

                    foreach ($itemsData as $itemResponse) {
                        if (isset($itemResponse['body'])) {
                            $item = $itemResponse['body'];

                            // Filtrar por marca se especificado
                            if (!empty($brand) && !$this->itemMatchesBrand($item, $brand)) {
                                continue;
                            }

                            if (!in_array($item['id'], $seenItemIds)) {
                                $seenItemIds[] = $item['id'];
                                $items[] = $item;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                log_warning('Erro ao buscar itens do seller', [
                    'seller_id' => $sellerId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $items;
    }

    /**
     * Obtém sellers conhecidos para uma categoria
     */
    private function getKnownSellersForCategory(string $categoryId): array
    {
        // Buscar do cache
        $cacheKey = "known_sellers_{$categoryId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // Buscar do banco de dados (se a tabela existir)
        try {
            $db = Database::getInstance();

            // Verificar se a tabela existe
            $stmt = $db->query("SHOW TABLES LIKE 'ml_items'");
            if ($stmt->rowCount() > 0) {
                $stmt = $db->prepare("
                    SELECT DISTINCT seller_id
                    FROM ml_items
                    WHERE category_id = :category_id
                    AND seller_id IS NOT NULL
                    LIMIT 50
                ");
                $stmt->execute(['category_id' => $categoryId]);
                $sellers = $stmt->fetchAll(\PDO::FETCH_COLUMN);

                if (!empty($sellers)) {
                    $this->cache->set($cacheKey, $sellers, 3600);
                    return $sellers;
                }
            }

            // Alternativa: buscar da tabela de pesquisas anteriores
            $stmt = $db->query("SHOW TABLES LIKE 'ml_research_cache'");
            if ($stmt->rowCount() > 0) {
                $stmt = $db->prepare("
                    SELECT data FROM ml_research_cache
                    WHERE category_id = :category_id
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmt->execute(['category_id' => $categoryId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row && !empty($row['data'])) {
                    $data = json_decode($row['data'], true);
                    if (isset($data['sellers'])) {
                        $sellers = array_column($data['sellers'], 'id');
                        if (!empty($sellers)) {
                            $this->cache->set($cacheKey, $sellers, 3600);
                            return $sellers;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            log_warning('Erro ao buscar sellers do DB', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Descobre sellers via trends, highlights e itens populares da categoria
     */
    private function discoverSellersFromTrends(string $categoryId, string $brand): array
    {
        $sellers = [];
        $cacheKey = "discovered_sellers_{$categoryId}_{$brand}";

        // Verificar cache
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            // 1. Tentar via trends
            try {
                $trends = $this->client->get("/trends/{$this->siteId}/{$categoryId}");

                if (isset($trends['content']) && is_array($trends['content'])) {
                    foreach ($trends['content'] as $trend) {
                        if (isset($trend['url'])) {
                            // Extrair seller ID se disponível na URL
                            if (preg_match('/\/perfil\/(\d+)/', $trend['url'], $matches)) {
                                $sellers[] = (int) $matches[1];
                            }
                            // Extrair item ID da URL para buscar seller
                            if (preg_match('/MLB-?(\d+)/', $trend['url'], $matches)) {
                                try {
                                    $item = $this->client->get("/items/MLB{$matches[1]}");
                                    if (isset($item['seller_id'])) {
                                        $sellers[] = (int) $item['seller_id'];
                                    }
                                } catch (\Exception $e) {
                                    continue;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                log_warning('Trends não disponível para descoberta de sellers', ['error' => $e->getMessage()]);
            }

            // 2. Tentar via highlights
            try {
                $highlights = $this->client->get("/highlights/{$this->siteId}/{$categoryId}");
                if (isset($highlights['content'])) {
                    foreach ($highlights['content'] as $highlight) {
                        if (isset($highlight['id'])) {
                            try {
                                $item = $this->client->get("/items/{$highlight['id']}");
                                if (isset($item['seller_id'])) {
                                    $sellers[] = (int) $item['seller_id'];
                                }
                            } catch (\Exception $e) {
                                continue;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                log_warning('Highlights não disponível para descoberta de sellers', ['error' => $e->getMessage()]);
            }

            // 3. Tentar via category info (top sellers)
            try {
                $categoryInfo = $this->client->get("/categories/{$categoryId}");

                // Buscar algumas subcategorias para mais diversidade
                if (isset($categoryInfo['children_categories'])) {
                    foreach (array_slice($categoryInfo['children_categories'], 0, 3) as $child) {
                        try {
                            $childHighlights = $this->client->get("/highlights/{$this->siteId}/{$child['id']}");
                            if (isset($childHighlights['content'])) {
                                foreach (array_slice($childHighlights['content'], 0, 5) as $h) {
                                    if (isset($h['id'])) {
                                        try {
                                            $item = $this->client->get("/items/{$h['id']}");
                                            if (isset($item['seller_id'])) {
                                                $sellers[] = (int) $item['seller_id'];
                                            }
                                        } catch (\Exception $e) {
                                            continue;
                                        }
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }
            } catch (\Exception $e) {
                log_warning('Category info não disponível para descoberta de sellers', ['error' => $e->getMessage()]);
            }

            // 4. Se ainda não temos sellers, usar lista de grandes vendedores conhecidos do ML Brasil
            if (empty($sellers)) {
                // Sellers com grande volume que provavelmente têm produtos em várias categorias
                $fallbackSellers = [
                    // Grandes lojas oficiais (ML Shops)
                    157702097, // Loja oficial genérica
                    266815427, // Outro grande vendedor
                    // Adicione mais conforme conhecer
                ];
                $sellers = $fallbackSellers;
            }
        } catch (\Exception $e) {
            log_error('Erro geral ao descobrir sellers', ['error' => $e->getMessage()]);
        }

        $uniqueSellers = array_values(array_unique($sellers));

        // Cachear por 1 hora
        if (!empty($uniqueSellers)) {
            $this->cache->set($cacheKey, $uniqueSellers, 3600);
        }

        return $uniqueSellers;
    }

    /**
     * Busca itens do próprio usuário em uma categoria específica
     * Este endpoint NUNCA é bloqueado quando autenticado
     */
    private function collectMyItemsInCategory(string $categoryId, string $brand): array
    {
        $items = [];

        try {
            // Buscar ID do usuário atual
            $userInfo = $this->client->get('/users/me');
            if (isset($userInfo['error']) || !isset($userInfo['id'])) {
                return [];
            }

            $userId = $userInfo['id'];

            // Buscar itens do próprio usuário na categoria
            $offset = 0;
            $limit = 50;

            do {
                $response = $this->client->get("/users/{$userId}/items/search", [
                    'category' => $categoryId,
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

                if (isset($response['error'])) {
                    break;
                }

                $itemIds = $response['results'] ?? [];
                if (empty($itemIds)) {
                    break;
                }

                // Buscar detalhes dos itens
                $idsParam = implode(',', $itemIds);
                $itemsData = $this->client->get("/items", ['ids' => $idsParam]);

                foreach ($itemsData as $itemResponse) {
                    if (isset($itemResponse['body'])) {
                        $item = $itemResponse['body'];

                        // Filtrar por marca se especificado
                        if (!empty($brand) && !$this->itemMatchesBrand($item, $brand)) {
                            continue;
                        }

                        $items[] = $item;
                    }
                }

                $offset += $limit;
                $total = $response['paging']['total'] ?? 0;
            } while ($offset < $total && $offset < 200);
        } catch (\Exception $e) {
            log_warning('Erro ao buscar itens próprios para pesquisa', ['error' => $e->getMessage()]);
        }

        return $items;
    }

    /**
     * Verifica se item corresponde à marca
     */
    private function itemMatchesBrand(array $item, string $brand): bool
    {
        $brandLower = mb_strtolower($brand);

        // Verificar atributos
        if (isset($item['attributes'])) {
            foreach ($item['attributes'] as $attr) {
                if (in_array($attr['id'], ['BRAND', 'MARCA'])) {
                    if (mb_strtolower($attr['value_name'] ?? '') === $brandLower) {
                        return true;
                    }
                }
            }
        }

        // Verificar no título
        if (isset($item['title'])) {
            if (stripos($item['title'], $brand) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Coleta via API oficial do Mercado Livre
     */
    private function collectViaOfficialApi(string $categoryId, string $brand, int $maxItems, array $filters = []): array
    {
        $allItems = [];
        $offset = 0;
        $limit = 50; // Máximo por página do ML

        // Construir parâmetros base
        $params = [
            'category' => $categoryId,
            'BRAND' => $brand,
            'limit' => $limit,
            'offset' => $offset,
        ];

        // Aplicar filtros do Mercado Livre
        if (!empty($filters['price_min'])) {
            $params['price'] = ($filters['price_min'] ?? '*') . '-' . ($filters['price_max'] ?? '*');
        } elseif (!empty($filters['price_max'])) {
            $params['price'] = '*-' . $filters['price_max'];
        }

        // Condição: new ou used
        if (!empty($filters['condition']) && $filters['condition'] !== 'all') {
            $params['ITEM_CONDITION'] = $filters['condition'];
        }

        // Frete grátis
        if (!empty($filters['shipping'])) {
            if ($filters['shipping'] === 'free') {
                $params['shipping_cost'] = 'free';
            } elseif ($filters['shipping'] === 'full') {
                $params['shipping'] = 'fulfillment';
            }
        }

        // Ordenação
        if (!empty($filters['sort'])) {
            $sortMapping = [
                'price_asc' => 'price_asc',
                'price_desc' => 'price_desc',
                'sold_quantity' => 'sold_quantity_desc',
                'relevance' => 'relevance',
            ];
            $params['sort'] = $sortMapping[$filters['sort']] ?? 'relevance';
        }

        do {
            $params['offset'] = $offset;

            $response = $this->client->get("/sites/{$this->siteId}/search", $params);

            // Verificar erro na resposta
            if (isset($response['error']) && $response['error']) {
                $statusCode = $response['status'] ?? $response['status_code'] ?? 0;
                $errorMessage = $response['message'] ?? '';
                $errorDetails = $response['details'] ?? [];

                // Verificar se é bloqueio de política (PolicyAgent) - não é problema de token
                if ($statusCode == 403) {
                    $blockedBy = $errorDetails['blocked_by'] ?? '';
                    $detailMessage = $errorDetails['message'] ?? $errorMessage;

                    // Se for bloqueio por PolicyAgent, é restrição de IP/região do servidor
                    if (strpos($blockedBy, 'Policy') !== false || strpos($detailMessage, 'forbidden') !== false) {
                        throw new \Exception(
                            'A API de busca do Mercado Livre está temporariamente indisponível para este servidor. ' .
                                'Isso pode ser uma restrição temporária de IP ou região. ' .
                                'Tente novamente em alguns minutos ou entre em contato com o suporte.'
                        );
                    }

                    // Se for erro de autenticação real, tentar renovar token
                    if ($this->client->getAccountId()) {
                        $authService = new \App\Services\MercadoLivreAuthService();
                        if ($authService->refreshToken($this->client->getAccountId())) {
                            $this->client->setAccountId($this->client->getAccountId());
                            $response = $this->client->get("/sites/{$this->siteId}/search", $params);

                            if (isset($response['error']) && $response['error']) {
                                // Verificar novamente se é bloqueio de política
                                $newDetails = $response['details'] ?? [];
                                $newBlockedBy = $newDetails['blocked_by'] ?? '';
                                if (strpos($newBlockedBy, 'Policy') !== false) {
                                    throw new \Exception(
                                        'A API de busca do Mercado Livre está temporariamente indisponível. ' .
                                            'Tente novamente em alguns minutos.'
                                    );
                                }
                                throw new \Exception('Erro ao acessar a API do Mercado Livre. Por favor, tente novamente.');
                            }
                        } else {
                            throw new \Exception('Token do Mercado Livre expirado. Por favor, reconecte sua conta no painel.');
                        }
                    } else {
                        throw new \Exception('Nenhuma conta do Mercado Livre conectada. Por favor, conecte sua conta no painel.');
                    }
                } elseif ($statusCode == 401) {
                    throw new \Exception('Token do Mercado Livre expirado. Por favor, reconecte sua conta no painel.');
                } else {
                    // Outro erro, sair do loop
                    log_warning('Erro na API de pesquisa de mercado', [
                        'response' => $response,
                    ]);
                    break;
                }
            }

            $items = $response['results'] ?? [];

            // Filtros adicionais que a API do ML não suporta nativamente
            if (!empty($filters['listing_type']) && $filters['listing_type'] !== 'all') {
                $items = array_filter($items, function (array $item) use ($filters): bool {
                    if ($filters['listing_type'] === 'catalog') {
                        return !empty($item['catalog_product_id']);
                    } else {
                        return empty($item['catalog_product_id']);
                    }
                });
            }

            // Filtrar por reputação do seller (requer processamento adicional)
            if (!empty($filters['seller_reputation']) && $filters['seller_reputation'] !== 'all') {
                $items = $this->filterBySellerReputation($items, $filters['seller_reputation']);
            }

            $allItems = array_merge($allItems, array_values($items));

            $total = $response['paging']['total'] ?? 0;
            $offset += $limit;

            // Respeitar limite de rate e máximo de itens
            usleep(100000); // 100ms entre requests

        } while ($offset < $total && $offset < $maxItems);

        // Salvar no cache para uso futuro
        if (!empty($allItems)) {
            $this->cache->set("research_cache_{$categoryId}_{$brand}", [
                'items' => $allItems,
                'timestamp' => time(),
            ], 3600);
        }

        return $allItems;
    }

    /**
     * Filtra itens por reputação do vendedor
     */
    private function filterBySellerReputation(array $items, string $reputation): array
    {
        return array_filter($items, function (array $item) use ($reputation): bool {
            $seller = $item['seller'] ?? [];
            $powerStatus = strtolower($seller['power_seller_status'] ?? '');

            switch ($reputation) {
                case 'mercadolider':
                    return in_array($powerStatus, ['gold', 'platinum']);
                case 'platinum':
                    return $powerStatus === 'platinum';
                default:
                    return true;
            }
        });
    }

    /**
     * Classifica anúncios em catálogo, comum e variações
     */
    private function classifyListings(array $items): array
    {
        $catalog = [];
        $common = [];
        $withVariations = [];
        $catalogProductIds = [];

        foreach ($items as $item) {
            $itemData = [
                'id' => $item['id'],
                'title' => $item['title'],
                'price' => $item['price'],
                'original_price' => $item['original_price'] ?? null,
                'sold_quantity' => $item['sold_quantity'] ?? 0,
                'available_quantity' => $item['available_quantity'] ?? 0,
                'condition' => $item['condition'] ?? 'new',
                'listing_type_id' => $item['listing_type_id'] ?? '',
                'catalog_product_id' => $item['catalog_product_id'] ?? null,
                'permalink' => $item['permalink'] ?? '',
                'thumbnail' => $item['thumbnail'] ?? '',
                'seller' => [
                    'id' => $item['seller']['id'] ?? null,
                    'nickname' => $item['seller']['nickname'] ?? '',
                ],
                'shipping' => $item['shipping'] ?? [],
                'attributes' => $item['attributes'] ?? [],
                'installments' => $item['installments'] ?? null,
            ];

            // Verificar se tem variações
            if (!empty($item['variations']) || ($item['buying_mode'] ?? '') === 'buy_it_now') {
                $withVariations[] = $itemData;
            }

            // Classificar catálogo vs comum
            if (!empty($item['catalog_product_id'])) {
                $catalog[] = $itemData;
                $catalogProductIds[$item['catalog_product_id']] = true;
            } else {
                $common[] = $itemData;
            }
        }

        return [
            'total' => count($items),
            'catalog' => [
                'count' => count($catalog),
                'percentage' => round((count($catalog) / count($items)) * 100, 1),
                'unique_products' => count($catalogProductIds),
                'items' => $catalog,
            ],
            'common' => [
                'count' => count($common),
                'percentage' => round((count($common) / count($items)) * 100, 1),
                'items' => $common,
            ],
            'with_variations' => [
                'count' => count($withVariations),
                'percentage' => round((count($withVariations) / count($items)) * 100, 1),
            ],
        ];
    }

    /**
     * Análise completa e profunda de sellers
     */
    private function analyzeSellersFull(array $items): array
    {
        $sellersMap = [];

        // Agrupar itens por seller
        foreach ($items as $item) {
            $sellerId = $item['seller']['id'] ?? null;
            if (!$sellerId) continue;

            if (!isset($sellersMap[$sellerId])) {
                $sellersMap[$sellerId] = [
                    'id' => $sellerId,
                    'nickname' => $item['seller']['nickname'] ?? '',
                    'items' => [],
                    'total_items' => 0,
                    'total_sales' => 0,
                    'prices' => [],
                    'listing_types' => [],
                    'catalog_items' => 0,
                    'common_items' => 0,
                    'free_shipping_items' => 0,
                    'full_items' => 0,
                ];
            }

            $seller = &$sellersMap[$sellerId];
            $seller['items'][] = $item['id'];
            $seller['total_items']++;
            $seller['total_sales'] += $item['sold_quantity'] ?? 0;
            $seller['prices'][] = $item['price'] ?? 0;

            // Tipo de listagem
            $listingType = $item['listing_type_id'] ?? 'unknown';
            $seller['listing_types'][$listingType] = ($seller['listing_types'][$listingType] ?? 0) + 1;

            // Catálogo vs comum
            if (!empty($item['catalog_product_id'])) {
                $seller['catalog_items']++;
            } else {
                $seller['common_items']++;
            }

            // Frete
            if ($item['shipping']['free_shipping'] ?? false) {
                $seller['free_shipping_items']++;
            }
            if (($item['shipping']['logistic_type'] ?? '') === 'fulfillment') {
                $seller['full_items']++;
            }
        }

        // Buscar detalhes adicionais de cada seller (top 20)
        $sellerIds = array_keys($sellersMap);
        $topSellerIds = array_slice($sellerIds, 0, 20);

        foreach ($topSellerIds as $sellerId) {
            $sellerDetails = $this->getSellerDetails($sellerId);
            if ($sellerDetails) {
                $sellersMap[$sellerId]['details'] = $sellerDetails;
            }
        }

        // Calcular métricas finais
        foreach ($sellersMap as &$seller) {
            $seller['avg_price'] = count($seller['prices']) > 0
                ? round(array_sum($seller['prices']) / count($seller['prices']), 2)
                : 0;
            $seller['min_price'] = count($seller['prices']) > 0 ? min($seller['prices']) : 0;
            $seller['max_price'] = count($seller['prices']) > 0 ? max($seller['prices']) : 0;
            // Métrica observada na amostra de busca — NÃO é market share real do ML
            $seller['observed_sample_share'] = round(($seller['total_items'] / count($items)) * 100, 2);
            $seller['market_share'] = $seller['observed_sample_share']; // alias legado
            $seller['free_shipping_rate'] = $seller['total_items'] > 0
                ? round(($seller['free_shipping_items'] / $seller['total_items']) * 100, 1)
                : 0;
            $seller['full_rate'] = $seller['total_items'] > 0
                ? round(($seller['full_items'] / $seller['total_items']) * 100, 1)
                : 0;
            $seller['catalog_rate'] = $seller['total_items'] > 0
                ? round(($seller['catalog_items'] / $seller['total_items']) * 100, 1)
                : 0;

            // Remover array de itens individuais para reduzir tamanho
            unset($seller['items']);
            unset($seller['prices']);
        }

        // Ordenar por vendas totais
        uasort($sellersMap, fn($a, $b) => $b['total_sales'] - $a['total_sales']);

        // Análise de concentração de mercado
        $totalSales = array_sum(array_column($sellersMap, 'total_sales'));
        $top3Sales = array_sum(array_column(array_slice($sellersMap, 0, 3), 'total_sales'));
        $top10Sales = array_sum(array_column(array_slice($sellersMap, 0, 10), 'total_sales'));

        return [
            'total_sellers' => count($sellersMap),
            'market_concentration' => [
                'top_3_share' => $totalSales > 0 ? round(($top3Sales / $totalSales) * 100, 1) : 0,
                'top_10_share' => $totalSales > 0 ? round(($top10Sales / $totalSales) * 100, 1) : 0,
                'herfindahl_index' => $this->calculateHerfindahlIndex($sellersMap, $totalSales),
            ],
            'sellers' => array_values($sellersMap),
        ];
    }

    /**
     * Obtém detalhes completos de um seller
     */
    private function getSellerDetails(int $sellerId): ?array
    {
        $response = $this->client->get("/users/{$sellerId}");

        if (isset($response['error'])) {
            return null;
        }

        return [
            'nickname' => $response['nickname'] ?? '',
            'registration_date' => $response['registration_date'] ?? null,
            'country_id' => $response['country_id'] ?? '',
            'address' => [
                'city' => $response['address']['city'] ?? '',
                'state' => $response['address']['state'] ?? '',
            ],
            'seller_reputation' => [
                'level_id' => $response['seller_reputation']['level_id'] ?? '',
                'power_seller_status' => $response['seller_reputation']['power_seller_status'] ?? null,
                'transactions' => [
                    'completed' => $response['seller_reputation']['transactions']['completed'] ?? 0,
                    'canceled' => $response['seller_reputation']['transactions']['canceled'] ?? 0,
                ],
                'ratings' => [
                    'positive' => $response['seller_reputation']['transactions']['ratings']['positive'] ?? 0,
                    'negative' => $response['seller_reputation']['transactions']['ratings']['negative'] ?? 0,
                    'neutral' => $response['seller_reputation']['transactions']['ratings']['neutral'] ?? 0,
                ],
                'metrics' => [
                    'sales' => $response['seller_reputation']['metrics']['sales'] ?? [],
                    'claims' => $response['seller_reputation']['metrics']['claims'] ?? [],
                    'delayed_handling_time' => $response['seller_reputation']['metrics']['delayed_handling_time'] ?? [],
                    'cancellations' => $response['seller_reputation']['metrics']['cancellations'] ?? [],
                ],
            ],
            'points' => $response['points'] ?? 0,
            'site_id' => $response['site_id'] ?? '',
            'status' => [
                'site_status' => $response['status']['site_status'] ?? '',
            ],
        ];
    }

    /**
     * Calcula índice Herfindahl-Hirschman de concentração de mercado
     */
    private function calculateHerfindahlIndex(array $sellers, float $totalSales): float
    {
        if ($totalSales <= 0) return 0;

        $hhi = 0;
        foreach ($sellers as $seller) {
            $marketShare = ($seller['total_sales'] / $totalSales) * 100;
            $hhi += pow($marketShare, 2);
        }

        return round($hhi, 2);
    }

    /**
     * Análise profunda de preços
     */
    private function analyzePricingDeep(array $items): array
    {
        $prices = [];
        $catalogPrices = [];
        $commonPrices = [];
        $byCondition = ['new' => [], 'used' => []];
        $byListingType = [];
        $withDiscount = [];
        $withoutDiscount = [];

        foreach ($items as $item) {
            $price = $item['price'] ?? 0;
            if ($price <= 0) continue;

            $prices[] = $price;

            // Por tipo (catálogo vs comum)
            if (!empty($item['catalog_product_id'])) {
                $catalogPrices[] = $price;
            } else {
                $commonPrices[] = $price;
            }

            // Por condição
            $condition = $item['condition'] ?? 'new';
            $byCondition[$condition][] = $price;

            // Por tipo de listagem
            $listingType = $item['listing_type_id'] ?? 'unknown';
            if (!isset($byListingType[$listingType])) {
                $byListingType[$listingType] = [];
            }
            $byListingType[$listingType][] = $price;

            // Desconto
            if (!empty($item['original_price']) && $item['original_price'] > $price) {
                $discount = round((($item['original_price'] - $price) / $item['original_price']) * 100, 1);
                $withDiscount[] = [
                    'price' => $price,
                    'original' => $item['original_price'],
                    'discount' => $discount,
                ];
            } else {
                $withoutDiscount[] = $price;
            }
        }

        // Calcular estatísticas
        sort($prices);

        $result = [
            'overall' => $this->calculatePriceStats($prices),
            'by_type' => [
                'catalog' => $this->calculatePriceStats($catalogPrices),
                'common' => $this->calculatePriceStats($commonPrices),
                'price_gap' => $this->calculatePriceGap($catalogPrices, $commonPrices),
            ],
            'by_condition' => [],
            'by_listing_type' => [],
            'discounts' => [
                'with_discount_count' => count($withDiscount),
                'without_discount_count' => count($withoutDiscount),
                'discount_rate' => count($prices) > 0
                    ? round((count($withDiscount) / count($prices)) * 100, 1)
                    : 0,
                'avg_discount' => count($withDiscount) > 0
                    ? round(array_sum(array_column($withDiscount, 'discount')) / count($withDiscount), 1)
                    : 0,
                'max_discount' => count($withDiscount) > 0
                    ? max(array_column($withDiscount, 'discount'))
                    : 0,
            ],
            'price_distribution' => $this->calculatePriceDistribution($prices),
        ];

        // Por condição
        foreach ($byCondition as $cond => $condPrices) {
            if (!empty($condPrices)) {
                $result['by_condition'][$cond] = $this->calculatePriceStats($condPrices);
            }
        }

        // Por tipo de listagem
        foreach ($byListingType as $type => $typePrices) {
            if (!empty($typePrices)) {
                $result['by_listing_type'][$type] = $this->calculatePriceStats($typePrices);
            }
        }

        return $result;
    }

    /**
     * Calcula estatísticas de preço
     */
    private function calculatePriceStats(array $prices): array
    {
        if (empty($prices)) {
            return ['count' => 0];
        }

        sort($prices);
        $count = count($prices);

        return [
            'count' => $count,
            'min' => $prices[0],
            'max' => $prices[$count - 1],
            'avg' => round(array_sum($prices) / $count, 2),
            'median' => $this->calculateMedian($prices),
            'p10' => $this->calculatePercentile($prices, 10),
            'p25' => $this->calculatePercentile($prices, 25),
            'p75' => $this->calculatePercentile($prices, 75),
            'p90' => $this->calculatePercentile($prices, 90),
            'std_dev' => $this->calculateStdDev($prices),
        ];
    }

    /**
     * Calcula gap de preço entre catálogo e comum
     */
    private function calculatePriceGap(array $catalogPrices, array $commonPrices): array
    {
        if (empty($catalogPrices) || empty($commonPrices)) {
            return ['available' => false];
        }

        $catalogAvg = array_sum($catalogPrices) / count($catalogPrices);
        $commonAvg = array_sum($commonPrices) / count($commonPrices);

        return [
            'available' => true,
            'catalog_avg' => round($catalogAvg, 2),
            'common_avg' => round($commonAvg, 2),
            'absolute_gap' => round($catalogAvg - $commonAvg, 2),
            'percentage_gap' => $commonAvg > 0 ? round((($catalogAvg - $commonAvg) / $commonAvg) * 100, 1) : 0,
            'insight' => $catalogAvg > $commonAvg
                ? 'Catálogo em média mais caro - normal por maior visibilidade'
                : 'Comum mais caro - oportunidade em catálogo',
        ];
    }

    /**
     * Calcula distribuição de preços em faixas
     */
    private function calculatePriceDistribution(array $prices): array
    {
        if (empty($prices)) return [];

        $min = min($prices);
        $max = max($prices);
        $range = $max - $min;

        if ($range === 0) {
            return [['range' => "R$ {$min}", 'count' => count($prices), 'percentage' => 100]];
        }

        // Criar 5 faixas
        $bins = 5;
        $binSize = $range / $bins;
        $distribution = [];

        for ($i = 0; $i < $bins; $i++) {
            $binMin = $min + ($i * $binSize);
            $binMax = $min + (($i + 1) * $binSize);

            $count = count(array_filter($prices, function (float|int $p) use ($binMin, $binMax, $i, $bins): bool {
                return $i === $bins - 1
                    ? ($p >= $binMin && $p <= $binMax)
                    : ($p >= $binMin && $p < $binMax);
            }));

            $distribution[] = [
                'range' => 'R$ ' . number_format($binMin, 2, ',', '.') . ' - R$ ' . number_format($binMax, 2, ',', '.'),
                'min' => round($binMin, 2),
                'max' => round($binMax, 2),
                'count' => $count,
                'percentage' => round(($count / count($prices)) * 100, 1),
            ];
        }

        return $distribution;
    }

    /**
     * Análise profunda de frete
     */
    private function analyzeShippingDeep(array $items): array
    {
        $freeShipping = [];
        $paidShipping = [];
        $fullItems = [];
        $flexItems = [];
        $dropOffItems = [];
        $otherLogistics = [];

        $shippingModes = [];
        $logisticTypes = [];

        foreach ($items as $item) {
            $shipping = $item['shipping'] ?? [];
            $price = $item['price'] ?? 0;

            $itemData = [
                'id' => $item['id'],
                'price' => $price,
                'sold_quantity' => $item['sold_quantity'] ?? 0,
            ];

            // Frete grátis vs pago
            if ($shipping['free_shipping'] ?? false) {
                $freeShipping[] = $itemData;
            } else {
                $paidShipping[] = $itemData;
            }

            // Tipo de logística
            $logisticType = $shipping['logistic_type'] ?? 'unknown';
            $logisticTypes[$logisticType] = ($logisticTypes[$logisticType] ?? 0) + 1;

            switch ($logisticType) {
                case 'fulfillment':
                    $fullItems[] = $itemData;
                    break;
                case 'cross_docking':
                case 'xd_drop_off':
                    $flexItems[] = $itemData;
                    break;
                case 'drop_off':
                    $dropOffItems[] = $itemData;
                    break;
                default:
                    $otherLogistics[] = $itemData;
            }

            // Modo de envio
            $mode = $shipping['mode'] ?? 'unknown';
            $shippingModes[$mode] = ($shippingModes[$mode] ?? 0) + 1;
        }

        $totalItems = count($items);

        // Calcular métricas
        $freeShippingSales = array_sum(array_column($freeShipping, 'sold_quantity'));
        $paidShippingSales = array_sum(array_column($paidShipping, 'sold_quantity'));
        $fullSales = array_sum(array_column($fullItems, 'sold_quantity'));

        $totalSales = $freeShippingSales + $paidShippingSales;

        return [
            'overview' => [
                'free_shipping' => [
                    'count' => count($freeShipping),
                    'percentage' => round((count($freeShipping) / $totalItems) * 100, 1),
                    'total_sales' => $freeShippingSales,
                    'sales_share' => $totalSales > 0 ? round(($freeShippingSales / $totalSales) * 100, 1) : 0,
                ],
                'paid_shipping' => [
                    'count' => count($paidShipping),
                    'percentage' => round((count($paidShipping) / $totalItems) * 100, 1),
                    'total_sales' => $paidShippingSales,
                    'sales_share' => $totalSales > 0 ? round(($paidShippingSales / $totalSales) * 100, 1) : 0,
                ],
            ],
            'logistics' => [
                'full' => [
                    'count' => count($fullItems),
                    'percentage' => round((count($fullItems) / $totalItems) * 100, 1),
                    'total_sales' => $fullSales,
                    'avg_price' => count($fullItems) > 0
                        ? round(array_sum(array_column($fullItems, 'price')) / count($fullItems), 2)
                        : 0,
                ],
                'flex' => [
                    'count' => count($flexItems),
                    'percentage' => round((count($flexItems) / $totalItems) * 100, 1),
                ],
                'drop_off' => [
                    'count' => count($dropOffItems),
                    'percentage' => round((count($dropOffItems) / $totalItems) * 100, 1),
                ],
                'other' => [
                    'count' => count($otherLogistics),
                    'percentage' => round((count($otherLogistics) / $totalItems) * 100, 1),
                ],
            ],
            'logistic_types_breakdown' => $logisticTypes,
            'shipping_modes_breakdown' => $shippingModes,
            'insights' => $this->generateShippingInsights($freeShipping, $paidShipping, $fullItems, $totalSales),
        ];
    }

    /**
     * Gera insights de frete
     */
    private function generateShippingInsights(array $free, array $paid, array $full, float $totalSales): array
    {
        $insights = [];

        $freeRate = count($free) / (count($free) + count($paid)) * 100;
        $fullRate = count($full) / (count($free) + count($paid)) * 100;

        if ($freeRate < 50) {
            $insights[] = [
                'type' => 'opportunity',
                'message' => "Apenas " . round($freeRate, 1) . "% dos anúncios têm frete grátis - oportunidade de diferenciação",
            ];
        }

        if ($fullRate < 20) {
            $insights[] = [
                'type' => 'opportunity',
                'message' => "Poucos sellers usam Full (" . round($fullRate, 1) . "%) - usar Full pode dar vantagem competitiva",
            ];
        }

        if ($freeRate > 80) {
            $insights[] = [
                'type' => 'warning',
                'message' => "Mercado saturado com frete grátis (" . round($freeRate, 1) . "%) - essencial para competir",
            ];
        }

        return $insights;
    }

    /**
     * Análise de comissões
     */
    private function analyzeCommissions(array $items): array
    {
        $byListingType = [];
        $totalRevenue = 0;
        $totalCommission = 0;
        $totalPaymentFee = 0;

        foreach ($items as $item) {
            $price = $item['price'] ?? 0;
            $sales = $item['sold_quantity'] ?? 0;
            $listingType = $item['listing_type_id'] ?? 'unknown';
            $revenue = $price * $sales;

            $commissionRate = self::ML_COMMISSIONS[$listingType] ?? 12.0;
            $commission = $revenue * ($commissionRate / 100);
            $paymentFee = $revenue * (self::PAYMENT_FEE / 100);

            $totalRevenue += $revenue;
            $totalCommission += $commission;
            $totalPaymentFee += $paymentFee;

            if (!isset($byListingType[$listingType])) {
                $byListingType[$listingType] = [
                    'count' => 0,
                    'revenue' => 0,
                    'commission' => 0,
                    'commission_rate' => $commissionRate,
                ];
            }

            $byListingType[$listingType]['count']++;
            $byListingType[$listingType]['revenue'] += $revenue;
            $byListingType[$listingType]['commission'] += $commission;
        }

        // Estimar custos de frete Full
        $estimatedFullCosts = $this->estimateFullShippingCosts($items);

        return [
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_ml_commission' => round($totalCommission, 2),
                'total_payment_fee' => round($totalPaymentFee, 2),
                'total_fees' => round($totalCommission + $totalPaymentFee, 2),
                'avg_commission_rate' => $totalRevenue > 0
                    ? round(($totalCommission / $totalRevenue) * 100, 2)
                    : 0,
                'effective_rate' => $totalRevenue > 0
                    ? round((($totalCommission + $totalPaymentFee) / $totalRevenue) * 100, 2)
                    : 0,
            ],
            'by_listing_type' => $byListingType,
            'commission_rates_reference' => self::ML_COMMISSIONS,
            'payment_fee_rate' => self::PAYMENT_FEE,
            'full_shipping_costs_estimate' => $estimatedFullCosts,
        ];
    }

    /**
     * Estima custos de frete Full
     */
    private function estimateFullShippingCosts(array $items): array
    {
        // Sem acesso ao peso real, usamos distribuição estimada
        $fullItems = array_filter(
            $items,
            fn(array $item): bool => ($item['shipping']['logistic_type'] ?? '') === 'fulfillment'
        );

        $count = count($fullItems);

        // Distribuição estimada de peso
        return [
            'total_full_items' => $count,
            'estimated_distribution' => [
                'light' => round($count * 0.4), // 40% leves
                'medium' => round($count * 0.35), // 35% médios
                'heavy' => round($count * 0.2), // 20% pesados
                'extra' => round($count * 0.05), // 5% muito pesados
            ],
            'cost_per_category' => self::FULL_SHIPPING_COSTS,
            'estimated_total_cost' => round(
                ($count * 0.4 * self::FULL_SHIPPING_COSTS['light']) +
                    ($count * 0.35 * self::FULL_SHIPPING_COSTS['medium']) +
                    ($count * 0.2 * self::FULL_SHIPPING_COSTS['heavy']) +
                    ($count * 0.05 * self::FULL_SHIPPING_COSTS['extra']),
                2
            ),
        ];
    }

    /**
     * Análise de Velocidade de Vendas (Sales Velocity)
     * Estima vendas por dia/mês baseado na data de criação e quantidade vendida
     */
    private function analyzeSalesVelocity(array $items): array
    {
        // Pegar top 50 itens mais vendidos para análise detalhada
        // (Precisamos buscar detalhes para ter date_created)
        usort($items, fn($a, $b) => ($b['sold_quantity'] ?? 0) - ($a['sold_quantity'] ?? 0));
        $topItems = array_slice($items, 0, 50);

        $velocities = [];
        $totalVelocity = 0;
        $validItems = 0;

        // Buscar detalhes em lote (multiget)
        $ids = array_column($topItems, 'id');
        $chunks = array_chunk($ids, 20);

        foreach ($chunks as $chunk) {
            $idsString = implode(',', $chunk);
            $response = $this->client->get("/items", ['ids' => $idsString]);

            if (isset($response['error'])) continue;

            foreach ($response as $itemResponse) {
                if (($itemResponse['code'] ?? 0) !== 200) continue;

                $item = $itemResponse['body'];
                $soldQty = $item['sold_quantity'] ?? 0;
                $dateCreated = $item['date_created'] ?? null;

                if ($soldQty > 0 && $dateCreated) {
                    $daysActive = (time() - strtotime($dateCreated)) / 86400;
                    // Evitar divisão por zero ou dias muito curtos
                    $daysActive = max($daysActive, 1);

                    $velocity = $soldQty / $daysActive; // Vendas por dia

                    $velocities[] = [
                        'id' => $item['id'],
                        'title' => $item['title'],
                        'sold_quantity' => $soldQty,
                        'days_active' => round($daysActive),
                        'sales_per_day' => round($velocity, 2),
                        'sales_per_month' => round($velocity * 30, 1),
                        'revenue_per_month' => round($velocity * 30 * ($item['price'] ?? 0), 2)
                    ];

                    $totalVelocity += $velocity;
                    $validItems++;
                }
            }
        }

        // Ordenar por velocidade
        usort($velocities, fn($a, $b) => $b['sales_per_day'] <=> $a['sales_per_day']);

        $avgVelocity = $validItems > 0 ? $totalVelocity / $validItems : 0;

        return [
            'average_sales_per_day' => round($avgVelocity, 2),
            'average_sales_per_month' => round($avgVelocity * 30, 1),
            'top_performers' => array_slice($velocities, 0, 10),
            'market_velocity_index' => $this->calculateVelocityIndex($avgVelocity),
        ];
    }

    /**
     * Calcula índice de velocidade do mercado (0-100)
     */
    private function calculateVelocityIndex(float $avgDailySales): string
    {
        if ($avgDailySales > 10) return 'Explosivo (High Demand)';
        if ($avgDailySales > 5) return 'Alto Giro (Fast Moving)';
        if ($avgDailySales > 1) return 'Moderado (Steady)';
        return 'Lento (Slow Moving)';
    }

    /**
     * Análise de Qualidade SEO dos Concorrentes
     */
    private function analyzeCompetitorSeo(array $items): array
    {
        // Analisar top 10 concorrentes
        usort($items, fn($a, $b) => ($b['sold_quantity'] ?? 0) - ($a['sold_quantity'] ?? 0));
        $topItems = array_slice($items, 0, 10);
        $ids = array_column($topItems, 'id');

        // Usar SeoAnalyzerService em lote
        $analysis = $this->seoAnalyzer->analyzeBatch($ids);

        $scores = [];
        $weaknesses = [];

        foreach ($analysis['items'] as $itemId => $result) {
            if (isset($result['score'])) {
                $scores[] = $result['score'];

                // Coletar pontos fracos comuns
                if (!empty($result['critical_issues'])) {
                    foreach ($result['critical_issues'] as $issue) {
                        $weaknesses[$issue] = ($weaknesses[$issue] ?? 0) + 1;
                    }
                }
            }
        }

        $avgScore = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;
        arsort($weaknesses);

        return [
            'average_competitor_score' => round($avgScore, 1),
            'difficulty_level' => $avgScore > 80 ? 'Hard' : ($avgScore > 60 ? 'Medium' : 'Easy'),
            'common_weaknesses' => array_slice($weaknesses, 0, 5),
            'top_competitor_analysis' => $analysis['items']
        ];
    }

    /**
     * Identifica oportunidades de mercado
     */
    private function identifyOpportunities(array $data): array
    {
        $opportunities = [];

        // 0. Oportunidade: Alta velocidade de vendas (Novo)
        $avgVelocity = $data['sales_velocity']['average_sales_per_day'] ?? 0;
        if ($avgVelocity > 3) {
            $opportunities[] = [
                'type' => 'high_demand',
                'priority' => 'high',
                'title' => 'Alta Demanda Detectada',
                'description' => "Produtos top vendem média de " . round($avgVelocity, 1) . " unidades/dia. Mercado aquecido.",
                'potential_impact' => 'Giro rápido de estoque',
            ];
        }

        // 0.1 Oportunidade: Concorrentes com SEO fraco (Novo)
        $avgSeo = $data['competitor_seo']['average_competitor_score'] ?? 100;
        if ($avgSeo < 60) {
            $opportunities[] = [
                'type' => 'seo_gap',
                'priority' => 'high',
                'title' => 'Concorrência com SEO Fraco',
                'description' => "Média de SEO dos concorrentes é apenas {$avgSeo}. Um anúncio bem otimizado pode rankear facilmente.",
                'potential_impact' => 'Facilidade de posicionamento orgânico',
            ];
        }

        // 1. Oportunidade: Baixa penetração de catálogo
        $catalogRate = $data['listings']['catalog']['percentage'] ?? 0;
        if ($catalogRate < 50) {
            $opportunities[] = [
                'type' => 'catalog_opportunity',
                'priority' => 'high',
                'title' => 'Baixa penetração de catálogo',
                'description' => "Apenas {$catalogRate}% dos anúncios estão em catálogo. Criar produtos de catálogo pode aumentar visibilidade.",
                'potential_impact' => 'Aumento de visibilidade e conversão',
            ];
        }

        // 2. Oportunidade: Mercado fragmentado (baixa concentração)
        $hhi = $data['sellers']['market_concentration']['herfindahl_index'] ?? 0;
        if ($hhi < 1000) {
            $opportunities[] = [
                'type' => 'market_fragmentation',
                'priority' => 'high',
                'title' => 'Mercado fragmentado',
                'description' => "HHI de {$hhi} indica mercado fragmentado. Oportunidade de consolidação com estratégia agressiva.",
                'potential_impact' => 'Possibilidade de ganhar market share rapidamente',
            ];
        }

        // 3. Oportunidade: Gap de preço catálogo vs comum
        $priceGap = $data['pricing']['by_type']['price_gap'] ?? [];
        if (($priceGap['percentage_gap'] ?? 0) > 15) {
            $opportunities[] = [
                'type' => 'price_arbitrage',
                'priority' => 'medium',
                'title' => 'Gap de preço significativo',
                'description' => "Catálogo é " . $priceGap['percentage_gap'] . "% mais caro. Oportunidade de preço competitivo em catálogo.",
                'potential_impact' => 'Margem adicional ou preço mais competitivo',
            ];
        }

        // 4. Oportunidade: Poucos sellers com Full
        $fullRate = $data['shipping']['logistics']['full']['percentage'] ?? 0;
        if ($fullRate < 30) {
            $opportunities[] = [
                'type' => 'logistics_advantage',
                'priority' => 'high',
                'title' => 'Baixa adoção de Full',
                'description' => "Apenas {$fullRate}% usam Full. Usar Full pode garantir melhor posicionamento.",
                'potential_impact' => 'Melhor ranking e conversão',
            ];
        }

        // 5. Oportunidade: Poucos descontos
        $discountRate = $data['pricing']['discounts']['discount_rate'] ?? 0;
        if ($discountRate < 20) {
            $opportunities[] = [
                'type' => 'promotion_opportunity',
                'priority' => 'low',
                'title' => 'Poucos usam desconto',
                'description' => "Apenas {$discountRate}% dos anúncios mostram desconto. Usar preço riscado pode destacar.",
                'potential_impact' => 'Maior atratividade visual',
            ];
        }

        // 6. Oportunidade: Vendedor dominante vulnerável
        $topSeller = $data['sellers']['sellers'][0] ?? null;
        if ($topSeller) {
            $topSellerFullRate = $topSeller['full_rate'] ?? 0;
            if ($topSellerFullRate < 50 && $topSeller['market_share'] > 10) {
                $opportunities[] = [
                    'type' => 'competitor_weakness',
                    'priority' => 'high',
                    'title' => 'Líder sem Full',
                    'description' => "Líder de mercado ({$topSeller['nickname']}) tem apenas {$topSellerFullRate}% em Full. Vulnerável a concorrente com melhor logística.",
                    'potential_impact' => 'Possibilidade de superar líder',
                ];
            }
        }

        return $opportunities;
    }

    /**
     * Gera insights estratégicos
     */
    private function generateStrategicInsights(array $data): array
    {
        $insights = [];

        // Insight sobre concentração de mercado
        $top3Share = $data['sellers']['market_concentration']['top_3_share'] ?? 0;
        if ($top3Share > 60) {
            $insights[] = [
                'category' => 'market_structure',
                'insight' => "Mercado concentrado: Top 3 sellers controlam {$top3Share}% das vendas. Entrada difícil, mas possível com diferenciação.",
                'recommendation' => 'Foque em nichos ou diferenciais que os líderes não atendem.',
            ];
        } else {
            $insights[] = [
                'category' => 'market_structure',
                'insight' => "Mercado pulverizado: Top 3 controlam apenas {$top3Share}%. Oportunidade de crescimento.",
                'recommendation' => 'Estratégia agressiva de volume pode funcionar.',
            ];
        }

        // Insight sobre preços
        $priceStats = $data['pricing']['overall'] ?? [];
        if (!empty($priceStats)) {
            $priceRange = $priceStats['max'] - $priceStats['min'];
            $avgPrice = $priceStats['avg'];

            if ($priceRange > $avgPrice * 2) {
                $insights[] = [
                    'category' => 'pricing',
                    'insight' => 'Grande variação de preços no mercado. Há espaço para posicionamento em diferentes faixas.',
                    'recommendation' => "Preço médio: R$ " . number_format($avgPrice, 2, ',', '.') . ". Considere posicionar 10-15% abaixo para volume ou acima com diferenciação.",
                ];
            }
        }

        // Insight sobre frete
        $freeShippingShare = $data['shipping']['overview']['free_shipping']['sales_share'] ?? 0;
        if ($freeShippingShare > 70) {
            $insights[] = [
                'category' => 'shipping',
                'insight' => "Frete grátis domina {$freeShippingShare}% das vendas. É essencial para competir.",
                'recommendation' => 'Inclua frete no preço ou absorva o custo. Sem frete grátis, conversão será muito menor.',
            ];
        }

        // Insight sobre tipo de listagem
        $commissions = $data['commissions']['by_listing_type'] ?? [];
        $premiumCount = ($commissions['gold_pro']['count'] ?? 0) + ($commissions['gold_premium']['count'] ?? 0);
        $totalCount = array_sum(array_column($commissions, 'count'));

        if ($totalCount > 0) {
            $premiumRate = ($premiumCount / $totalCount) * 100;
            if ($premiumRate > 50) {
                $insights[] = [
                    'category' => 'listing_type',
                    'insight' => round($premiumRate, 1) . "% dos anúncios são Premium. Concorrência por visibilidade é alta.",
                    'recommendation' => 'Usar anúncio Premium é necessário para competir neste mercado.',
                ];
            }
        }

        return $insights;
    }

    /**
     * Gera resumo executivo
     */
    private function generateExecutiveSummary(array $data): array
    {
        return [
            'total_listings' => $data['listings']['total'] ?? 0,
            'catalog_listings' => $data['listings']['catalog']['count'] ?? 0,
            'common_listings' => $data['listings']['common']['count'] ?? 0,
            'total_sellers' => $data['sellers']['total_sellers'] ?? 0,
            'market_leader' => $data['sellers']['sellers'][0]['nickname'] ?? 'N/A',
            'market_leader_share' => $data['sellers']['sellers'][0]['market_share'] ?? 0,
            'avg_price' => $data['pricing']['overall']['avg'] ?? 0,
            'price_range' => [
                'min' => $data['pricing']['overall']['min'] ?? 0,
                'max' => $data['pricing']['overall']['max'] ?? 0,
            ],
            'free_shipping_rate' => $data['shipping']['overview']['free_shipping']['percentage'] ?? 0,
            'full_rate' => $data['shipping']['logistics']['full']['percentage'] ?? 0,
            'total_opportunities' => count($data['opportunities'] ?? []),
            'high_priority_opportunities' => count(array_filter($data['opportunities'] ?? [], fn(array $o): bool => $o['priority'] === 'high')),
        ];
    }

    // Funções auxiliares de cálculo
    private function calculateMedian(array $values): float
    {
        $count = count($values);
        if ($count === 0) return 0;

        sort($values);
        $middle = (int) floor($count / 2);

        return $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    private function calculatePercentile(array $values, int $percentile): float
    {
        if (empty($values)) return 0;

        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $lower === $upper
            ? $values[$lower]
            : $values[$lower] + ($values[$upper] - $values[$lower]) * ($index - $lower);
    }

    private function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count < 2) return 0;

        $mean = array_sum($values) / $count;
        $sumSquares = array_sum(array_map(fn(float|int $v): float|int => pow($v - $mean, 2), $values));

        return round(sqrt($sumSquares / ($count - 1)), 2);
    }

    /**
     * Pesquisa rápida (versão resumida)
     */
    public function quickResearch(string $categoryId, string $brand): array
    {
        return $this->researchBrand($categoryId, $brand, [
            'max_items' => 200,
            'include_seller_details' => false,
        ]);
    }

    /**
     * Compara duas marcas na mesma categoria
     */
    public function compareBrands(string $categoryId, string $brand1, string $brand2): array
    {
        $research1 = $this->quickResearch($categoryId, $brand1);
        $research2 = $this->quickResearch($categoryId, $brand2);

        return [
            'category_id' => $categoryId,
            'comparison_date' => date('Y-m-d H:i:s'),
            'brand_1' => [
                'name' => $brand1,
                'summary' => $research1['summary'] ?? [],
            ],
            'brand_2' => [
                'name' => $brand2,
                'summary' => $research2['summary'] ?? [],
            ],
            'analysis' => $this->generateBrandComparison($research1, $research2),
        ];
    }

    /**
     * Gera comparação entre marcas
     */
    private function generateBrandComparison(array $r1, array $r2): array
    {
        $s1 = $r1['summary'] ?? [];
        $s2 = $r2['summary'] ?? [];

        return [
            'listings_comparison' => [
                'brand_1' => $s1['total_listings'] ?? 0,
                'brand_2' => $s2['total_listings'] ?? 0,
                'leader' => ($s1['total_listings'] ?? 0) > ($s2['total_listings'] ?? 0) ? 'brand_1' : 'brand_2',
            ],
            'price_comparison' => [
                'brand_1_avg' => $s1['avg_price'] ?? 0,
                'brand_2_avg' => $s2['avg_price'] ?? 0,
                'cheaper' => ($s1['avg_price'] ?? 0) < ($s2['avg_price'] ?? 0) ? 'brand_1' : 'brand_2',
            ],
            'sellers_comparison' => [
                'brand_1' => $s1['total_sellers'] ?? 0,
                'brand_2' => $s2['total_sellers'] ?? 0,
            ],
            'shipping_comparison' => [
                'brand_1_free_rate' => $s1['free_shipping_rate'] ?? 0,
                'brand_2_free_rate' => $s2['free_shipping_rate'] ?? 0,
            ],
        ];
    }

    /**
     * Simula lucratividade para um produto nesta categoria
     *
     * @param float $costPrice Preço de custo do produto
     * @param float $targetPrice Preço de venda alvo (opcional, se null usa média)
     * @param string $taxRegime Regime tributário (simples, presumido, real)
     * @param float $taxRate Alíquota de imposto (%)
     * @return array Análise de viabilidade financeira
     */
    public function simulateProfitability(float $costPrice, ?float $targetPrice = null, string $taxRegime = 'simples', float $taxRate = 10.0): array
    {
        // Se não forneceu preço alvo, usar dados coletados ou estimativa
        if (!$targetPrice) {
            // Tentar pegar média da última pesquisa ou erro
            if (empty($this->collectedData['pricing']['overall']['avg'])) {
                return ['error' => 'Execute uma pesquisa primeiro ou forneça um preço alvo'];
            }
            $targetPrice = $this->collectedData['pricing']['overall']['avg'];
        }

        $scenarios = [];
        $listingTypes = ['gold_special', 'gold_pro']; // Clássico e Premium

        foreach ($listingTypes as $type) {
            $commissionRate = self::ML_COMMISSIONS[$type] ?? 11.0;
            $commission = ($targetPrice * $commissionRate) / 100;

            // Taxa fixa se preço < 79 (regra ML Brasil)
            $fixedFee = $targetPrice < 79 ? 6.00 : 0.00;

            // Frete grátis (obrigatório >= 79)
            $shippingCost = 0;
            if ($targetPrice >= 79) {
                // Estimativa média de custo de frete grátis (varia por peso/reputação)
                // Aqui assumimos um desconto de reputação verde (50% ou 40% off)
                $shippingCost = 20.90; // Valor base estimado
            }

            $taxes = ($targetPrice * $taxRate) / 100;
            $totalCosts = $costPrice + $commission + $fixedFee + $shippingCost + $taxes;
            $netProfit = $targetPrice - $totalCosts;
            $margin = ($targetPrice > 0) ? ($netProfit / $targetPrice) * 100 : 0;
            $roi = ($costPrice > 0) ? ($netProfit / $costPrice) * 100 : 0;

            $scenarios[$type] = [
                'sale_price' => $targetPrice,
                'costs' => [
                    'product' => $costPrice,
                    'commission_ml' => $commission,
                    'fixed_fee' => $fixedFee,
                    'shipping' => $shippingCost,
                    'taxes' => $taxes,
                    'total' => $totalCosts
                ],
                'result' => [
                    'net_profit' => round($netProfit, 2),
                    'margin_percent' => round($margin, 2),
                    'roi_percent' => round($roi, 2),
                    'is_profitable' => $netProfit > 0
                ]
            ];
        }

        return [
            'inputs' => [
                'cost_price' => $costPrice,
                'target_price' => $targetPrice,
                'tax_regime' => $taxRegime,
                'tax_rate' => $taxRate
            ],
            'scenarios' => $scenarios,
            'recommendation' => $this->generateProfitabilityRecommendation($scenarios)
        ];
    }

    private function generateProfitabilityRecommendation(array $scenarios): string
    {
        $premium = $scenarios['gold_pro']['result'];
        $classic = $scenarios['gold_special']['result'];

        if ($premium['margin_percent'] > 15) {
            return "Excelente oportunidade! Margem saudável (>15%) mesmo no Premium.";
        } elseif ($classic['margin_percent'] > 10) {
            return "Viável no Clássico. No Premium a margem fica apertada.";
        } elseif ($classic['is_profitable']) {
            return "Margem muito baixa (<10%). Alto risco de prejuízo com devoluções.";
        } else {
            return "Inviável financeiramente com este preço de custo.";
        }
    }

    /**
     * Analisa palavras-chave dos títulos mais vendidos
     */
    public function analyzeTopKeywords(array $items = [], int $limit = 20): array
    {
        // Se não passou itens, usar os coletados
        if (empty($items) && !empty($this->collectedData['listings']['all'])) {
            $items = $this->collectedData['listings']['all'];
        }

        if (empty($items)) return [];

        // Filtrar apenas os top 20% mais vendidos (estimado por sold_quantity)
        // Como sold_quantity nem sempre é exato, usamos também a posição na busca (relevância)
        $topItems = array_slice($items, 0, (int) ceil(count($items) * 0.2));

        $text = '';
        foreach ($topItems as $item) {
            $text .= ' ' . ($item['title'] ?? '');
        }

        // Limpar e tokenizar
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $words = explode(' ', $text);

        // Stopwords (português)
        $stopwords = ['de', 'a', 'o', 'que', 'e', 'do', 'da', 'em', 'um', 'para', 'com', 'não', 'uma', 'os', 'no', 'se', 'na', 'por', 'mais', 'as', 'dos', 'como', 'mas', 'ao', 'ele', 'das', 'à', 'seu', 'sua', 'ou', 'quando', 'muito', 'nos', 'já', 'eu', 'também', 'só', 'pelo', 'pela', 'até', 'isso', 'ela', 'entre', 'depois', 'sem', 'mesmo', 'aos', 'seus', 'quem', 'nas', 'me', 'esse', 'eles', 'você', 'essa', 'num', 'nem', 'suas', 'meu', 'às', 'minha', 'numa', 'pelos', 'elas', 'qual', 'nós', 'lhe', 'deles', 'essas', 'esses', 'pelas', 'este', 'dele', 'tu', 'te', 'vocês', 'vos', 'lhes', 'meus', 'minhas', 'teu', 'tua', 'teus', 'tuas', 'nosso', 'nossa', 'nossos', 'nossas', 'dela', 'delas', 'esta', 'estes', 'estas', 'aquele', 'aquela', 'aqueles', 'aquelas', 'isto', 'aquilo', 'estou', 'está', 'estamos', 'estão', 'estive', 'esteve', 'estivemos', 'estiveram', 'estava', 'estávamos', 'estavam', 'estivera', 'estivéramos', 'esteja', 'estejamos', 'estejam', 'estivesse', 'estivéssemos', 'estivessem', 'estiver', 'estivermos', 'estiverem', 'hei', 'há', 'havemos', 'hão', 'houve', 'houvemos', 'houveram', 'houvera', 'houvéramos', 'haja', 'hajamos', 'hajam', 'houvesse', 'houvéssemos', 'houvessem', 'houver', 'houvermos', 'houverem', 'houverei', 'houverá', 'houveremos', 'houverão', 'houveria', 'houveríamos', 'houveriam', 'sou', 'somos', 'são', 'era', 'éramos', 'eram', 'fui', 'foi', 'fomos', 'foram', 'fora', 'fôramos', 'seja', 'sejamos', 'sejam', 'fosse', 'fôssemos', 'fossem', 'for', 'formos', 'forem', 'serei', 'será', 'seremos', 'serão', 'seria', 'seríamos', 'seriam', 'tenho', 'tem', 'temos', 'tém', 'tinha', 'tínhamos', 'tinham', 'tive', 'teve', 'tivemos', 'tiveram', 'tivera', 'tivéramos', 'tenha', 'tenhamos', 'tenham', 'tivesse', 'tivéssemos', 'tivessem', 'tiver', 'tivermos', 'tiverem', 'terei', 'terá', 'teremos', 'terão', 'teria', 'teríamos', 'teriam'];

        // Adicionar marca e categoria como stopwords contextuais
        if (!empty($this->collectedData['brand'])) {
            $stopwords[] = mb_strtolower($this->collectedData['brand']);
        }

        $words = array_filter($words, function (string $w) use ($stopwords): bool {
            return !in_array($w, $stopwords) && mb_strlen($w) > 2;
        });

        // Contar frequência
        $frequency = array_count_values($words);
        arsort($frequency);

        return array_slice($frequency, 0, $limit);
    }
}
