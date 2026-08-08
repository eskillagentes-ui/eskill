<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\MercadoLivreClient;

class SearchService
{
    private MercadoLivreClient $client;
    private string $siteId;

    public function __construct(?int $accountId = null)
    {
        $config = \App\Core\Config::getInstance()->all();
        $this->siteId = $config['mercadolivre']['site_id'];
        $this->client = new MercadoLivreClient($accountId);
    }

    /**
     * Busca itens com filtros avançados
     */
    public function search(array $filters = []): array
    {
        $params = [];

        // Categoria
        if (isset($filters['category'])) {
            $params['category'] = $filters['category'];
        }

        // Marca (BRAND)
        if (isset($filters['BRAND'])) {
            $params['BRAND'] = $filters['BRAND'];
        }

        // Condição
        if (isset($filters['condition'])) {
            $params['condition'] = $filters['condition'];
        }

        // Preço mínimo
        if (isset($filters['price_min'])) {
            $params['price'] = ($filters['price_min'] ?? 0) . '-' . ($filters['price_max'] ?? 999999999);
        }

        // Frete grátis
        if (isset($filters['free_shipping']) && $filters['free_shipping']) {
            $params['shipping_cost'] = 'free';
        }

        // Filtros dinâmicos baseados em atributos
        if (isset($filters['attributes']) && is_array($filters['attributes'])) {
            foreach ($filters['attributes'] as $attrId => $attrValue) {
                // Atributos dinâmicos são adicionados diretamente aos parâmetros
                // O Mercado Livre aceita filtros por atributo usando o ID do atributo
                if (!empty($attrValue)) {
                    $params[$attrId] = $attrValue;
                }
            }
        }

        // Paginação
        $params['limit'] = $filters['limit'] ?? 50;
        $params['offset'] = $filters['offset'] ?? 0;

        // Ordenação
        if (isset($filters['sort'])) {
            $params['sort'] = $filters['sort']; // price_asc, price_desc, relevance, etc
        }

        $response = $this->client->get("/sites/{$this->siteId}/search", $params);

        return $response;
    }

    /**
     * Busca por categoria e marca
     */
    public function searchByCategoryAndBrand(string $categoryId, string $brand): array
    {
        return $this->search([
            'category' => $categoryId,
            'BRAND' => $brand,
        ]);
    }

    /**
     * Analisa anúncios diferenciando catálogo vs comum
     */
    /**
     * Analisa anúncios diferenciando catálogo vs comum
     */
    public function analyzeListings(string $categoryId, string $brand, array $additionalFilters = []): array
    {
        $this->ensureAnalysesTable();

        $allItems = [];
        $offset = 0;
        $limit = 50;
        $maxResults = 1000; // Limite da API

        $filters = array_merge([
            'category' => $categoryId,
            'BRAND' => $brand,
        ], $additionalFilters);

        do {
            $filters['limit'] = $limit;
            $filters['offset'] = $offset;

            $response = $this->search($filters);

            if (isset($response['error'])) {
                return $response;
            }

            if (isset($response['results'])) {
                $allItems = array_merge($allItems, $response['results']);
            }

            $offset += $limit;
            $total = $response['paging']['total'] ?? 0;
        } while ($offset < $total && $offset < $maxResults);

        // Aplicar filtro de tipo de anúncio se especificado
        $listingType = $additionalFilters['listing_type'] ?? null;
        if ($listingType === 'catalog') {
            $allItems = array_filter($allItems, fn($item) => !empty($item['catalog_product_id']));
        } elseif ($listingType === 'common') {
            $allItems = array_filter($allItems, fn($item) => empty($item['catalog_product_id']));
        }

        $result = $this->categorizeListings($allItems);

        // Salvar análise no banco
        $this->saveAnalysis($categoryId, $brand, $result);

        return $result;
    }

    /**
     * Categoriza anúncios em catálogo vs comum
     */
    private function categorizeListings(array $items): array
    {
        $catalog = [];
        $common = [];
        $prices = [];
        $conditions = ['new' => 0, 'used' => 0];
        $shipping = ['free' => 0, 'paid' => 0];

        foreach ($items as $item) {
            // Verificar se é catálogo
            $isCatalog = !empty($item['catalog_product_id']);

            if ($isCatalog) {
                $catalog[] = $item;
            } else {
                $common[] = $item;
            }

            // Coletar preços
            if (isset($item['price'])) {
                $prices[] = $item['price'];
            }

            // Condição
            $condition = $item['condition'] ?? 'unknown';
            if (isset($conditions[$condition])) {
                $conditions[$condition]++;
            }

            // Frete
            if ($item['shipping']['free_shipping'] ?? false) {
                $shipping['free']++;
            } else {
                $shipping['paid']++;
            }
        }

        // Calcular estatísticas de preço
        $priceStats = [];
        if (!empty($prices)) {
            $priceStats = [
                'min' => min($prices),
                'max' => max($prices),
                'avg' => round(array_sum($prices) / count($prices), 2),
                'count' => count($prices),
            ];
        }

        // Análise de vendedores
        $sellers = $this->analyzeSellers($items);

        return [
            'total' => count($items),
            'catalog' => [
                'count' => count($catalog),
                'items' => $catalog,
            ],
            'common' => [
                'count' => count($common),
                'items' => $common,
            ],
            'prices' => $priceStats,
            'conditions' => $conditions,
            'shipping' => $shipping,
            'sellers' => $sellers,
        ];
    }

    /**
     * Analisa vendedores por marca/categoria
     */
    private function analyzeSellers(array $items): array
    {
        $sellers = [];
        $sellerStats = [];

        foreach ($items as $item) {
            $sellerId = $item['seller']['id'] ?? null;
            $sellerNickname = $item['seller']['nickname'] ?? 'Desconhecido';

            if (!$sellerId) {
                continue;
            }

            if (!isset($sellers[$sellerId])) {
                $sellers[$sellerId] = [
                    'id' => $sellerId,
                    'nickname' => $sellerNickname,
                    'items_count' => 0,
                    'prices' => [],
                    'catalog_count' => 0,
                    'common_count' => 0,
                    'free_shipping_count' => 0,
                ];
            }

            $sellers[$sellerId]['items_count']++;

            if (isset($item['price'])) {
                $sellers[$sellerId]['prices'][] = $item['price'];
            }

            if (!empty($item['catalog_product_id'])) {
                $sellers[$sellerId]['catalog_count']++;
            } else {
                $sellers[$sellerId]['common_count']++;
            }

            if ($item['shipping']['free_shipping'] ?? false) {
                $sellers[$sellerId]['free_shipping_count']++;
            }
        }

        // Calcular estatísticas por vendedor
        foreach ($sellers as $sellerId => &$seller) {
            if (!empty($seller['prices'])) {
                $seller['price_stats'] = [
                    'min' => min($seller['prices']),
                    'max' => max($seller['prices']),
                    'avg' => round(array_sum($seller['prices']) / count($seller['prices']), 2),
                ];
            }
            unset($seller['prices']);
        }

        // Ordenar por quantidade de itens
        usort($sellers, fn($a, $b) => $b['items_count'] - $a['items_count']);

        return [
            'total_unique' => count($sellers),
            'top_sellers' => array_slice($sellers, 0, 10), // Top 10
            'all_sellers' => $sellers,
        ];
    }

    /**
     * Busca todos os itens paginando automaticamente
     */
    public function searchAll(array $filters = [], int $maxResults = 1000): array
    {
        $allItems = [];
        $offset = 0;
        $limit = 50;

        do {
            $filters['offset'] = $offset;
            $filters['limit'] = $limit;

            $response = $this->search($filters);

            if (isset($response['error'])) {
                return $response;
            }

            if (isset($response['results'])) {
                $allItems = array_merge($allItems, $response['results']);
            }

            $offset += $limit;
            $total = $response['paging']['total'] ?? 0;
        } while ($offset < $total && $offset < $maxResults);

        return [
            'items' => $allItems,
            'total' => count($allItems),
            'paging' => $response['paging'] ?? [],
        ];
    }

    /**
     * Salva o resultado da análise no banco
     */
    private function saveAnalysis(string $categoryId, string $brand, array $analysisData): void
    {
        try {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO market_analyses (account_id, category_id, brand, analysis_data, created_at)
                VALUES (:account_id, :category_id, :brand, :analysis_data, NOW())
            ");

            $accountId = $this->client->getAccountId() ?? 0;

            $stmt->execute([
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'brand' => $brand,
                'analysis_data' => json_encode($analysisData),
            ]);
        } catch (\Exception $e) {
            log_warning('Erro ao salvar análise de mercado', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Garante que a tabela de análises existe
     */
    private function ensureAnalysesTable(): void
    {
        $db = \App\Database::getInstance();
        $db->exec("
            CREATE TABLE IF NOT EXISTS market_analyses (
                id INT PRIMARY KEY AUTO_INCREMENT,
                account_id INT NOT NULL,
                category_id VARCHAR(50) NOT NULL,
                brand VARCHAR(100),
                analysis_data JSON NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_account_id (account_id),
                INDEX idx_category (category_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    /**
     * Escaneia categoria por oportunidades (Blue Oceans)
     * Procura itens com ALTAS VENDAS mas BAIXA QUALIDADE de anúncio
     */
    public function scanForOpportunities(string $categoryId): array
    {
        // 1. Buscar itens mais vendidos na categoria
        // Ordenamos por 'sold_quantity_desc' (se suportado) ou 'relevance'
        $results = $this->searchAll([
            'category' => $categoryId,
            'sort' => 'relevance', // Proxy para vendas
            'condition' => 'new'
        ], 200); // Analisar top 200

        // PolicyAgent / token / rede: searchAll devolve {error,...} sem items.
        if (!is_array($results) || isset($results['error']) || ($results['success'] ?? true) === false) {
            return [
                'success' => false,
                'opportunities' => [],
                'error' => $results['error'] ?? 'search_unavailable',
                'message' => $results['message'] ?? 'Busca de oportunidades indisponível',
            ];
        }

        $opportunities = [];
        $items = $results['items'] ?? [];
        if (!is_array($items)) {
            return ['success' => true, 'opportunities' => []];
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $score = 0;
            $reasons = [];

            // Critérios de "Baixa Qualidade"

            // 1. Título Curto ou Mal Otimizado (< 40 chars)
            if (mb_strlen($item['title']) < 40) {
                $score += 2;
                $reasons[] = 'Título curto/pouco descritivo';
            }

            // 2. Poucas fotos
            // A search api nem sempre retorna todas as fotos, apenas a thumbnail.
            // Precisaríamos de um get detail para ter certeza.
            // Vamos usar proxy: se não tem atributos chave preenchidos

            // 3. Frete Pago (Shipping Cost não free)
            if (!($item['shipping']['free_shipping'] ?? false)) {
                $score += 1;
                $reasons[] = 'Sem frete grátis';
            }

            // 4. Vendedor sem Medalha (se disponível na API search)
            $sellerLevel = $item['seller']['seller_reputation']['power_seller_status'] ?? null;
            if (!$sellerLevel) {
                $score += 1;
                $reasons[] = 'Vendedor sem medalha';
            }

            // 5. Preço (Oportunidade de bater preço?)
            // Difícil saber sem saber o custo, mas podemos anotar.

            // ALTA DEMANDA: Sold Quantity (Se disponível)
            $sold = $item['sold_quantity'] ?? 0;
            if ($sold > 50 && $score >= 3) {
                // Jackpot: Vende muito e o anúncio é "ruim"
                $opportunities[] = [
                    'item_id' => $item['id'],
                    'title' => $item['title'],
                    'price' => $item['price'],
                    'sold_quantity' => $sold,
                    'quality_score' => 10 - $score, // Score inverso
                    'opportunity_score' => $score, // Quanto maior, mais fácil de competir
                    'reasons' => $reasons,
                    'link' => $item['permalink']
                ];
            }

            // Se score > 4, mesmo com poucas vendas, pode ser oportunidade de nicho
            if ($score >= 4) {
                $opportunities[] = [
                    'item_id' => $item['id'],
                    'title' => $item['title'],
                    'price' => $item['price'],
                    'sold_quantity' => $sold,
                    'quality_score' => 10 - $score,
                    'opportunity_score' => $score,
                    'reasons' => $reasons,
                    'link' => $item['permalink']
                ];
            }
        }

        // Ordenar por oportunidade
        usort($opportunities, fn($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);

        return [
            'success' => true,
            'opportunities' => array_slice($opportunities, 0, 50),
        ];
    }
}
