<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\NotificationService;
use PDO;

/**
 * 🕵️ COMPETITOR SPY - Espionagem de Concorrentes
 *
 * Analisa o que os top sellers fazem:
 * - Títulos dos mais vendidos
 * - Estratégias de preço
 * - Atributos que usam
 * - Padrões de sucesso
 *
 * @author AI Development Team
 * @version 1.0.0
 */
class CompetitorSpy
{
    private PDO $db;
    private ?int $accountId;
    private ?MercadoLivreClient $mlClient = null;

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;

        if ($accountId) {
            $this->mlClient = new MercadoLivreClient($accountId);
        }
    }

    /**
     * 🔍 Espionar um produto específico
     */
    public function spyProduct(string $searchTerm, int $limit = 20): array
    {
        $result = [
            'search_term' => $searchTerm,
            'analyzed' => 0,
            'top_sellers' => [],
            'title_patterns' => [],
            'price_analysis' => [],
            'attribute_patterns' => [],
            'keywords_frequency' => [],
            'winning_strategies' => [],
        ];

        if (!$this->mlClient) {
            $result['error'] = 'ML client não disponível';
            return $result;
        }

        try {
            // Search for top selling products
            $searchResult = $this->mlClient->get('/sites/MLB/search', [
                'q' => $searchTerm,
                'limit' => $limit,
                'sort' => 'sold_quantity_desc',
            ]);

            $items = $searchResult['results'] ?? [];

            // Filter out my own items to ensure true competitor analysis
            $mySellerId = $this->mlClient ? $this->mlClient->getSellerId() : null;
            if ($mySellerId) {
                $items = array_filter($items, function (array $item) use ($mySellerId): bool {
                    return isset($item['seller']['id']) && $item['seller']['id'] != $mySellerId;
                });
            }

            $result['analyzed'] = count($items);

            // Analyze each item
            $allKeywords = [];
            $allPrices = [];
            $titleLengths = [];
            $attributeCounts = [];
            $imageCounts = [];
            $shippingTypes = ['free' => 0, 'paid' => 0];

            foreach ($items as $item) {
                // Basic info
                $result['top_sellers'][] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'price' => $item['price'],
                    'sold_quantity' => $item['sold_quantity'] ?? 0,
                    'seller_id' => $item['seller']['id'] ?? null,
                    'seller_reputation' => $item['seller']['seller_reputation']['level_id'] ?? null,
                    'free_shipping' => $item['shipping']['free_shipping'] ?? false,
                    'thumbnail' => $item['thumbnail'],
                ];

                // Extract keywords from title
                $words = preg_split('/[\s\-\/]+/', mb_strtolower($item['title']));
                foreach ($words as $word) {
                    $word = trim($word);
                    if (mb_strlen($word) >= 3) {
                        $allKeywords[$word] = ($allKeywords[$word] ?? 0) + 1;
                    }
                }

                // Collect data for analysis
                $allPrices[] = $item['price'];
                $titleLengths[] = mb_strlen($item['title']);
                $attributeCounts[] = count($item['attributes'] ?? []);
                $imageCounts[] = count($item['pictures'] ?? []);

                if ($item['shipping']['free_shipping'] ?? false) {
                    $shippingTypes['free']++;
                } else {
                    $shippingTypes['paid']++;
                }
            }

            // Analyze title patterns
            arsort($allKeywords);
            $result['keywords_frequency'] = array_slice($allKeywords, 0, 30, true);

            // Price analysis
            if (!empty($allPrices)) {
                sort($allPrices);
                $result['price_analysis'] = [
                    'min' => min($allPrices) ?: 0,
                    'max' => max($allPrices) ?: 0,
                    'avg' => count($allPrices) ? round(array_sum($allPrices) / count($allPrices), 2) : 0,
                    'median' => $allPrices[(int) floor(count($allPrices) / 2)] ?? 0,
                    'recommended_range' => [
                        'low' => round(min($allPrices) * 0.95, 2),
                        'high' => round(max($allPrices) * 1.05, 2),
                    ],
                ];
            } else {
                $result['price_analysis'] = [
                    'min' => 0,
                    'max' => 0,
                    'avg' => 0,
                    'median' => 0,
                    'recommended_range' => ['low' => 0, 'high' => 0]
                ];
            }

            // Title patterns
            $result['title_patterns'] = [
                'avg_length' => count($titleLengths) ? round(array_sum($titleLengths) / count($titleLengths)) : 0,
                'min_length' => !empty($titleLengths) ? min($titleLengths) : 0,
                'max_length' => !empty($titleLengths) ? max($titleLengths) : 0,
                'optimal_length' => '50-60 caracteres',
            ];

            // Attribute patterns
            $result['attribute_patterns'] = [
                'avg_count' => count($attributeCounts) ? round(array_sum($attributeCounts) / count($attributeCounts)) : 0,
                'optimal' => '15+ atributos',
            ];

            // Image patterns
            $result['image_patterns'] = [
                'avg_count' => count($imageCounts) ? round(array_sum($imageCounts) / count($imageCounts)) : 0,
                'optimal' => '5-10 imagens',
            ];

            // Free shipping analysis
            $totalShipping = ($shippingTypes['free'] ?? 0) + ($shippingTypes['paid'] ?? 0);
            $result['shipping_analysis'] = [
                'free_shipping_percentage' => $totalShipping > 0
                    ? round(($shippingTypes['free'] ?? 0) / $totalShipping * 100)
                    : 0,
                'recommendation' => 'Oferecer frete grátis aumenta CTR em até 30%',
            ];

            // Winning strategies
            $result['winning_strategies'] = $this->identifyWinningStrategies($result);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 💰 Analisar competitividade de preço
     */
    public function analyzePriceCompetitiveness(array $item): array
    {
        $result = [
            'score' => 50, // default
            'price' => $item['price'] ?? 0,
            'avg_price' => 0,
            'status' => 'unknown',
            'issues' => [],
        ];

        if (!$this->mlClient) {
            return $result;
        }

        try {
            // Find competitors
            $title = $item['title'] ?? '';
            $searchTerm = mb_substr($title, 0, 40); // Use first 40 chars

            // Search top 20 items
            $searchResult = $this->mlClient->get('/sites/MLB/search', [
                'q' => $searchTerm,
                'limit' => 20,
                'sort' => 'sold_quantity_desc',
            ]);


            $competitors = $searchResult['results'] ?? [];
            if (empty($competitors)) {
                return $result;
            }

            // Calculate detailed stats
            $prices = array_column($competitors, 'price');
            sort($prices);

            // Remove outliers (top/bottom 10%)
            $count = count($prices);
            $trim = (int)($count * 0.1);
            if ($count > 5) {
                $prices = array_slice($prices, $trim, $count - ($trim * 2));
            }

            $avgPrice = array_sum($prices) / count($prices);
            $minPrice = min($prices);

            $myPrice = $item['price'] ?? 0;
            $result['avg_price'] = round($avgPrice, 2);

            // Scoring logic
            $score = 100;
            $issues = [];

            if ($myPrice <= $minPrice) {
                $score = 100; // Best price
                $status = 'best_price';
            } elseif ($myPrice <= $avgPrice) {
                $score = 90; // Below average
                $status = 'competitive';
            } elseif ($myPrice <= $avgPrice * 1.05) {
                $score = 80; // Slightly above average
                $status = 'average';
            } elseif ($myPrice <= $avgPrice * 1.15) {
                $score = 60; // Expensive
                $status = 'above_average';
                $issues[] = 'Preço 15% acima da média de mercado';
            } else {
                $score = 40; // Very expensive
                $status = 'expensive';
                $issues[] = 'Preço muito acima dos concorrentes diretos';
            }

            // Check free shipping impact
            $marketFreeShipping = 0;
            foreach ($competitors as $comp) {
                if ($comp['shipping']['free_shipping'] ?? false) $marketFreeShipping++;
            }
            $marketFreeShippingPct = ($marketFreeShipping / count($competitors)) * 100;

            $myFreeShipping = $item['shipping']['free_shipping'] ?? false;

            if (!$myFreeShipping && $marketFreeShippingPct > 50) {
                $score -= 15;
                $issues[] = 'Concorrentes oferecem Frete Grátis e você não';
            }

            $result['score'] = max(0, $score);
            $result['status'] = $status;
            $result['issues'] = $issues;
        } catch (\Exception $e) {
            // Keep defaults on error
        }

        return $result;
    }

    /**
     * 📊 Comparar seu anúncio com os concorrentes
     */
    public function compareWithCompetitors(string $itemId): array
    {
        $result = [
            'item_id' => $itemId,
            'your_listing' => null,
            'competitors' => [],
            'comparison' => [],
            'gaps' => [],
            'recommendations' => [],
        ];

        if (!$this->mlClient) {
            $result['error'] = 'ML client não disponível';
            return $result;
        }

        try {
            // Get your listing
            $yourItem = $this->mlClient->get("/items/{$itemId}");

            if (!$yourItem || !isset($yourItem['title'])) {
                // Return basic info using just the ID if API fails or item not found
                $result['your_listing'] = [
                    'title' => 'Item ' . $itemId,
                    'title_length' => 0,
                    'price' => 0,
                    'attributes' => 0,
                    'images' => 0,
                    'free_shipping' => false,
                    'sold_quantity' => 0,
                ];
                // Try to proceed with search using ID as term or abort?
                // Abort is safer as we can't search without title
                $result['error'] = 'Não foi possível obter dados do item ' . $itemId;
                return $result;
            }

            $result['your_listing'] = [
                'title' => $yourItem['title'],
                'title_length' => mb_strlen($yourItem['title']),
                'price' => $yourItem['price'] ?? 0,
                'attributes' => count($yourItem['attributes'] ?? []),
                'images' => count($yourItem['pictures'] ?? []),
                'free_shipping' => $yourItem['shipping']['free_shipping'] ?? false,
                'sold_quantity' => $yourItem['sold_quantity'] ?? 0,
            ];

            // Search for similar products
            $searchTerm = mb_substr($yourItem['title'], 0, 30);
            $spy = $this->spyProduct($searchTerm, 10);

            // Calculate averages from competitors
            $topSellers = $spy['top_sellers'] ?? [];

            if (!empty($topSellers)) {
                $avgPrice = array_sum(array_column($topSellers, 'price')) / count($topSellers);
                $avgSold = array_sum(array_column($topSellers, 'sold_quantity')) / count($topSellers);

                $result['comparison'] = [
                    'price' => [
                        'yours' => $yourItem['price'],
                        'competitor_avg' => round($avgPrice, 2),
                        'difference' => round($yourItem['price'] - $avgPrice, 2),
                        'status' => $yourItem['price'] > $avgPrice * 1.1 ? 'overpriced' : ($yourItem['price'] < $avgPrice * 0.9 ? 'underpriced' : 'competitive'),
                    ],
                    'title_length' => [
                        'yours' => mb_strlen($yourItem['title']),
                        'competitor_avg' => $spy['title_patterns']['avg_length'],
                        'status' => mb_strlen($yourItem['title']) >= 50 ? 'good' : 'needs_improvement',
                    ],
                    'attributes' => [
                        'yours' => count($yourItem['attributes'] ?? []),
                        'competitor_avg' => $spy['attribute_patterns']['avg_count'],
                        'status' => count($yourItem['attributes'] ?? []) >= 15 ? 'good' : 'needs_improvement',
                    ],
                    'images' => [
                        'yours' => count($yourItem['pictures'] ?? []),
                        'competitor_avg' => $spy['image_patterns']['avg_count'],
                        'status' => count($yourItem['pictures'] ?? []) >= 5 ? 'good' : 'needs_improvement',
                    ],
                ];

                // Identify gaps
                if ($result['comparison']['title_length']['status'] === 'needs_improvement') {
                    $result['gaps'][] = [
                        'area' => 'title',
                        'issue' => 'Título mais curto que a média dos concorrentes',
                        'action' => 'Expandir título para 50-60 caracteres',
                    ];
                }

                if (
                    !($yourItem['shipping']['free_shipping'] ?? false) &&
                    $spy['shipping_analysis']['free_shipping_percentage'] > 50
                ) {
                    $result['gaps'][] = [
                        'area' => 'shipping',
                        'issue' => (int)$spy['shipping_analysis']['free_shipping_percentage'] . '% dos concorrentes oferecem frete grátis',
                        'action' => 'Ativar frete grátis para competir',
                    ];
                }

                if ($result['comparison']['attributes']['status'] === 'needs_improvement') {
                    $result['gaps'][] = [
                        'area' => 'attributes',
                        'issue' => 'Menos atributos que a média dos concorrentes',
                        'action' => 'Preencher todos os atributos disponíveis',
                    ];
                }

                // Keywords missing
                $yourKeywords = preg_split('/[\s\-\/]+/', mb_strtolower($yourItem['title']));
                $topKeywords = array_slice(array_keys($spy['keywords_frequency']), 0, 15);

                foreach ($topKeywords as $kw) {
                    if (!in_array($kw, $yourKeywords) && mb_strlen($kw) >= 4) {
                        $result['gaps'][] = [
                            'area' => 'keywords',
                            'issue' => "Keyword popular '{$kw}' não está no seu título",
                            'action' => "Considere incluir '{$kw}' no título",
                        ];
                        if (count($result['gaps']) > 10) break;
                    }
                }
            }

            // Generate recommendations
            $result['recommendations'] = $this->generateRecommendations($result);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 📈 Analisar top sellers de uma categoria
     */
    public function analyzeTopSellers(string $categoryId, int $limit = 20): array
    {
        $result = [
            'category_id' => $categoryId,
            'sellers' => [],
            'patterns' => [],
        ];

        if (!$this->mlClient) {
            return $result;
        }

        try {
            $searchResult = $this->mlClient->get('/sites/MLB/search', [
                'category' => $categoryId,
                'limit' => $limit,
                'sort' => 'sold_quantity_desc',
            ]);

            $sellerData = [];

            foreach ($searchResult['results'] ?? [] as $item) {
                $sellerId = $item['seller']['id'] ?? null;
                if (!$sellerId) continue;

                if (!isset($sellerData[$sellerId])) {
                    $sellerData[$sellerId] = [
                        'seller_id' => $sellerId,
                        'nickname' => $item['seller']['nickname'] ?? 'Unknown',
                        'reputation' => $item['seller']['seller_reputation']['level_id'] ?? null,
                        'items' => [],
                        'total_sold' => 0,
                        'avg_price' => 0,
                    ];
                }

                $sellerData[$sellerId]['items'][] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'price' => $item['price'],
                    'sold' => $item['sold_quantity'] ?? 0,
                ];
                $sellerData[$sellerId]['total_sold'] += $item['sold_quantity'] ?? 0;
            }

            // Calculate averages
            foreach ($sellerData as $sellerId => &$seller) {
                $prices = array_column($seller['items'], 'price');
                $seller['avg_price'] = count($prices) ? round(array_sum($prices) / count($prices), 2) : 0;
                $seller['item_count'] = count($seller['items']);
            }

            // Sort by total sold
            uasort($sellerData, fn($a, $b) => $b['total_sold'] <=> $a['total_sold']);

            $result['sellers'] = array_values(array_slice($sellerData, 0, 10));
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    // Private methods

    private function identifyWinningStrategies(array $data): array
    {
        $strategies = [];

        // Price strategy
        if (isset($data['price_analysis'])) {
            $strategies[] = [
                'strategy' => 'Precificação competitiva',
                'insight' => "Preço médio: R\$ {$data['price_analysis']['avg']}",
                'recommendation' => "Considere preço entre R\$ {$data['price_analysis']['recommended_range']['low']} e R\$ {$data['price_analysis']['recommended_range']['high']}",
            ];
        }

        // Title strategy
        if (!empty($data['keywords_frequency'])) {
            $topKeywords = array_slice(array_keys($data['keywords_frequency']), 0, 5);
            $strategies[] = [
                'strategy' => 'Keywords de alto impacto',
                'insight' => 'Keywords mais usadas pelos top sellers',
                'keywords' => $topKeywords,
                'recommendation' => 'Inclua estas keywords no seu título',
            ];
        }

        // Free shipping strategy
        if (isset($data['shipping_analysis']) && $data['shipping_analysis']['free_shipping_percentage'] > 50) {
            $strategies[] = [
                'strategy' => 'Frete grátis dominante',
                'insight' => "{$data['shipping_analysis']['free_shipping_percentage']}% oferecem frete grátis",
                'recommendation' => 'Ativar frete grátis é essencial para competir',
            ];
        }

        return $strategies;
    }

    private function generateRecommendations(array $comparison): array
    {
        $recommendations = [];

        foreach ($comparison['gaps'] ?? [] as $gap) {
            $recommendations[] = [
                'priority' => $gap['area'] === 'keywords' ? 'high' : 'medium',
                'area' => $gap['area'],
                'action' => $gap['action'],
            ];
        }

        // Sort by priority
        usort(
            $recommendations,
            fn($a, $b) => ($a['priority'] === 'high' ? 0 : 1) <=> ($b['priority'] === 'high' ? 0 : 1)
        );

        return array_slice($recommendations, 0, 5);
    }

    // ==========================================
    // 🔖 WATCHLIST METHODS
    // ==========================================

    /**
     * 📌 Adicionar concorrente à watchlist
     */
    public function addToWatchlist(string $competitorItemId, array $options = []): array
    {
        if (!$this->accountId || !$this->mlClient) {
            return ['success' => false, 'error' => 'Account ID ou ML client não disponível'];
        }

        try {
            // Fetch competitor data from ML
            $item = $this->mlClient->get("/items/{$competitorItemId}");

            // Prepare data
            $data = [
                'account_id' => $this->accountId,
                'competitor_item_id' => $competitorItemId,
                'competitor_seller_id' => $item['seller']['id'] ?? null,
                'nickname' => $item['seller']['nickname'] ?? null,
                'title' => $item['title'],
                'price' => $item['price'],
                'sold_quantity' => $item['sold_quantity'] ?? 0,
                'available_quantity' => $item['available_quantity'] ?? 0,
                'listing_type' => $item['listing_type_id'] ?? null,
                'condition' => $item['condition'] ?? null,
                'title_length' => mb_strlen($item['title']),
                'pictures_count' => count($item['pictures'] ?? []),
                'attributes_filled' => count($item['attributes'] ?? []),
                'free_shipping' => $item['shipping']['free_shipping'] ?? false,
                'shipping_mode' => $item['shipping']['mode'] ?? null,
                'status' => $item['status'],
                'category_id' => $item['category_id'] ?? null,
                'tags' => $options['tags'] ?? null,
                'notes' => $options['notes'] ?? null,
                'alert_on_changes' => $options['alert_on_changes'] ?? true,
                'last_checked_at' => date('Y-m-d H:i:s'),
            ];

            // Calculate SEO score
            $data['seo_score'] = $this->calculateQuickSeoScore($item);

            // Insert or update
            $stmt = $this->db->prepare("
                INSERT INTO competitor_watchlist
                (account_id, competitor_item_id, competitor_seller_id, nickname, title, price,
                 sold_quantity, available_quantity, listing_type, condition, seo_score,
                 title_length, pictures_count, attributes_filled, free_shipping, shipping_mode,
                 status, category_id, tags, notes, alert_on_changes, last_checked_at)
                VALUES
                (:account_id, :competitor_item_id, :competitor_seller_id, :nickname, :title, :price,
                 :sold_quantity, :available_quantity, :listing_type, :condition, :seo_score,
                 :title_length, :pictures_count, :attributes_filled, :free_shipping, :shipping_mode,
                 :status, :category_id, :tags, :notes, :alert_on_changes, :last_checked_at)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    price = VALUES(price),
                    sold_quantity = VALUES(sold_quantity),
                    available_quantity = VALUES(available_quantity),
                    seo_score = VALUES(seo_score),
                    title_length = VALUES(title_length),
                    pictures_count = VALUES(pictures_count),
                    attributes_filled = VALUES(attributes_filled),
                    free_shipping = VALUES(free_shipping),
                    shipping_mode = VALUES(shipping_mode),
                    status = VALUES(status),
                    last_checked_at = VALUES(last_checked_at),
                    tags = COALESCE(VALUES(tags), tags),
                    notes = COALESCE(VALUES(notes), notes)
            ");

            $stmt->execute($data);
            $watchlistId = $this->db->lastInsertId() ?: $this->getWatchlistId($competitorItemId);

            return [
                'success' => true,
                'watchlist_id' => $watchlistId,
                'message' => 'Concorrente adicionado à watchlist',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 📋 Listar watchlist
     */
    public function getWatchlist(array $filters = []): array
    {
        if (!$this->accountId) {
            return [];
        }

        $where = ['account_id = :account_id'];
        $params = ['account_id' => $this->accountId];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }

        if (!empty($filters['tags'])) {
            $where[] = 'tags LIKE :tags';
            $params['tags'] = '%' . $filters['tags'] . '%';
        }

        // Whitelist ORDER BY to prevent SQL injection
        $allowedOrders = [
            'created_at DESC',
            'created_at ASC',
            'name ASC',
            'name DESC',
            'priority DESC',
            'priority ASC',
            'updated_at DESC',
        ];
        $orderBy = in_array($filters['order_by'] ?? '', $allowedOrders, true)
            ? $filters['order_by'] : 'created_at DESC';
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 200));

        $sql = "SELECT * FROM competitor_watchlist
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 🔄 Atualizar dados de um item da watchlist
     */
    public function updateWatchlistItem(int $watchlistId): array
    {
        if (!$this->mlClient) {
            return ['success' => false, 'error' => 'ML client não disponível'];
        }

        try {
            // Get current watchlist data
            $stmt = $this->db->prepare("SELECT * FROM competitor_watchlist WHERE id = :id");
            $stmt->execute(['id' => $watchlistId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                return ['success' => false, 'error' => 'Watchlist item não encontrado'];
            }

            // Fetch updated data from ML
            $item = $this->mlClient->get("/items/{$current['competitor_item_id']}");

            $changes = [];

            // Detect changes
            if ($item['price'] != $current['price']) {
                $changes[] = [
                    'field' => 'price',
                    'old' => $current['price'],
                    'new' => $item['price'],
                    'type' => $item['price'] < $current['price'] ? 'decreased' : 'increased',
                ];
            }

            if ($item['title'] !== $current['title']) {
                $changes[] = [
                    'field' => 'title',
                    'old' => $current['title'],
                    'new' => $item['title'],
                    'type' => 'changed',
                ];
            }

            if (($item['sold_quantity'] ?? 0) > $current['sold_quantity']) {
                $changes[] = [
                    'field' => 'sold_quantity',
                    'old' => $current['sold_quantity'],
                    'new' => $item['sold_quantity'] ?? 0,
                    'type' => 'increased',
                ];
            }

            $newFreeShipping = $item['shipping']['free_shipping'] ?? false;
            if ($newFreeShipping != $current['free_shipping']) {
                $changes[] = [
                    'field' => 'free_shipping',
                    'old' => $current['free_shipping'],
                    'new' => $newFreeShipping,
                    'type' => $newFreeShipping ? 'activated' : 'deactivated',
                ];
            }

            // Save history
            foreach ($changes as $change) {
                $this->db->prepare("
                    INSERT INTO competitor_history
                    (watchlist_id, field_changed, old_value, new_value, change_type)
                    VALUES (:watchlist_id, :field, :old_value, :new_value, :change_type)
                ")->execute([
                    'watchlist_id' => $watchlistId,
                    'field' => $change['field'],
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                    'change_type' => $change['type'],
                ]);
            }

            // Create alerts if enabled
            if ($current['alert_on_changes'] && !empty($changes)) {
                foreach ($changes as $change) {
                    $this->createAlert($current['account_id'], $watchlistId, $change);
                }
            }

            // Update watchlist
            $newSeoScore = $this->calculateQuickSeoScore($item);

            $this->db->prepare("
                UPDATE competitor_watchlist SET
                    title = :title,
                    price = :price,
                    sold_quantity = :sold_quantity,
                    available_quantity = :available_quantity,
                    seo_score = :seo_score,
                    title_length = :title_length,
                    pictures_count = :pictures_count,
                    attributes_filled = :attributes_filled,
                    free_shipping = :free_shipping,
                    shipping_mode = :shipping_mode,
                    status = :status,
                    last_checked_at = NOW()
                WHERE id = :id
            ")->execute([
                'id' => $watchlistId,
                'title' => $item['title'],
                'price' => $item['price'],
                'sold_quantity' => $item['sold_quantity'] ?? 0,
                'available_quantity' => $item['available_quantity'] ?? 0,
                'seo_score' => $newSeoScore,
                'title_length' => mb_strlen($item['title']),
                'pictures_count' => count($item['pictures'] ?? []),
                'attributes_filled' => count($item['attributes'] ?? []),
                'free_shipping' => $newFreeShipping,
                'shipping_mode' => $item['shipping']['mode'] ?? null,
                'status' => $item['status'],
            ]);

            return [
                'success' => true,
                'changes_detected' => count($changes),
                'changes' => $changes,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 🗑️ Remover da watchlist
     */
    public function removeFromWatchlist(int $watchlistId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM competitor_watchlist
            WHERE id = :id AND account_id = :account_id
        ");

        return $stmt->execute([
            'id' => $watchlistId,
            'account_id' => $this->accountId,
        ]);
    }

    /**
     * 📊 Obter histórico de mudanças
     */
    public function getHistory(int $watchlistId, int $days = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM competitor_history
            WHERE watchlist_id = :watchlist_id
              AND detected_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ORDER BY detected_at DESC
        ");

        $stmt->execute([
            'watchlist_id' => $watchlistId,
            'days' => $days,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 🧠 Gerar otimização baseada em concorrente
     */
    public function generateOptimizationFromCompetitor(string $competitorId, string $myItemId): array
    {
        if (!$this->mlClient) {
            return ['error' => 'ML client não disponível'];
        }

        try {
            // Fetch both items
            $competitor = $this->mlClient->get("/items/{$competitorId}");
            $myItem = $this->mlClient->get("/items/{$myItemId}");

            $suggestions = [
                'competitor_title' => $competitor['title'],
                'my_current_title' => $myItem['title'],
                'suggested_title' => $this->adaptTitle($competitor['title'], $myItem['title']),
                'keywords_to_copy' => [],
                'attributes_to_verify' => []
            ];

            // Analyze Keywords
            $compKeywords = $this->extractKeywords($competitor['title']);
            $myKeywords = $this->extractKeywords($myItem['title']);

            foreach ($compKeywords as $kw => $freq) {
                if (!isset($myKeywords[$kw]) && mb_strlen($kw) > 3) {
                    $suggestions['keywords_to_copy'][] = $kw;
                }
            }

            // Limit keywords
            $suggestions['keywords_to_copy'] = array_slice($suggestions['keywords_to_copy'], 0, 10);

            return $suggestions;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function adaptTitle(string $competitorTitle, string $myTitle): string
    {
        // Simple logic: maintain structure but perhaps keep my brand?
        // Actually, safer to just suggest competitor title structure.
        return $competitorTitle;
    }

    private function extractKeywords(string $text): array
    {
        $words = preg_split('/[\s\-\/]+/', mb_strtolower($text));
        $keywords = [];
        $ignored = ['o', 'a', 'os', 'as', 'de', 'do', 'da', 'em', 'para', 'com', 'frete', 'grátis', 'original', 'nota', 'fiscal'];

        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) >= 3 && !in_array($word, $ignored)) {
                $keywords[$word] = ($keywords[$word] ?? 0) + 1;
            }
        }
        return $keywords;
    }

    /**
     * 🔔 Obter alertas
     */
    public function getAlerts(array $filters = []): array
    {
        if (!$this->accountId) {
            return [];
        }

        $where = ['account_id = :account_id'];
        $params = ['account_id' => $this->accountId];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[] = 'priority = :priority';
            $params['priority'] = $filters['priority'];
        }

        $limit = $filters['limit'] ?? 50;

        $sql = "SELECT * FROM competitor_alerts
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ✅ Marcar alerta como lido
     */
    public function markAlertAsRead(int $alertId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE competitor_alerts
            SET status = 'read', read_at = NOW()
            WHERE id = :id AND account_id = :account_id
        ");

        return $stmt->execute([
            'id' => $alertId,
            'account_id' => $this->accountId,
        ]);
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================

    private function getWatchlistId(string $competitorItemId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM competitor_watchlist
            WHERE account_id = :account_id AND competitor_item_id = :item_id
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'item_id' => $competitorItemId,
        ]);

        return $stmt->fetchColumn() ?: null;
    }

    private function calculateQuickSeoScore(array $item): int
    {
        $score = 0;

        // Title (30 points)
        $titleLen = mb_strlen($item['title'] ?? '');
        if ($titleLen >= 50 && $titleLen <= 60) {
            $score += 30;
        } elseif ($titleLen >= 40) {
            $score += 20;
        } elseif ($titleLen >= 30) {
            $score += 10;
        }

        // Pictures (20 points)
        $picturesCount = count($item['pictures'] ?? []);
        if ($picturesCount >= 6) {
            $score += 20;
        } elseif ($picturesCount >= 3) {
            $score += 10;
        }

        // Attributes (20 points)
        $attributesCount = count($item['attributes'] ?? []);
        if ($attributesCount >= 15) {
            $score += 20;
        } elseif ($attributesCount >= 10) {
            $score += 15;
        } elseif ($attributesCount >= 5) {
            $score += 10;
        }

        // Free shipping (15 points)
        if ($item['shipping']['free_shipping'] ?? false) {
            $score += 15;
        }

        // Description (15 points)
        if (!empty($item['descriptions'])) {
            $score += 15;
        } elseif (!empty($item['description'])) {
            $descLen = mb_strlen($item['description']);
            $score += $descLen >= 500 ? 15 : ($descLen >= 300 ? 10 : 5);
        }

        return min(100, $score);
    }

    private function createAlert(int $accountId, int $watchlistId, array $change): void
    {
        $alertMessages = [
            'price_decreased' => 'Concorrente baixou o preço',
            'price_increased' => 'Concorrente aumentou o preço',
            'title' => 'Concorrente mudou o título',
            'free_shipping_activated' => 'Concorrente ativou frete grátis',
            'sold_quantity' => 'Concorrente vendeu mais unidades',
        ];

        $alertKey = $change['field'] . ($change['type'] !== 'changed' ? '_' . $change['type'] : '');
        $title = $alertMessages[$alertKey] ?? 'Mudança detectada';

        $message = sprintf(
            "Campo '%s' mudou de '%s' para '%s'",
            $change['field'],
            $change['old'],
            $change['new']
        );

        $priority = 'medium';
        if ($change['field'] === 'price' && $change['type'] === 'decreased') {
            $priority = 'high';
        } elseif ($change['field'] === 'free_shipping' && $change['type'] === 'activated') {
            $priority = 'high';
        }

        $alertId = null;

        try {
            $this->db->prepare("
                INSERT INTO competitor_alerts
                (account_id, watchlist_id, alert_type, title, message, priority)
                VALUES (:account_id, :watchlist_id, :alert_type, :title, :message, :priority)
            ")->execute([
                'account_id' => $accountId,
                'watchlist_id' => $watchlistId,
                'alert_type' => $alertKey,
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
            ]);

            $alertId = $this->db->lastInsertId();

            // Enviar notificação se alta prioridade
            if ($priority === 'high') {
                $this->sendAlertNotification($accountId, [
                    'id' => $alertId,
                    'title' => $title,
                    'message' => $message,
                    'priority' => $priority,
                    'watchlist_id' => $watchlistId,
                ]);
            }
        } catch (\Exception $e) {
            log_warning('CompetitorSpy: erro ao criar alerta', [
                'service' => 'CompetitorSpy',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendAlertNotification(int $accountId, array $alert): void
    {
        try {
            $notificationService = new NotificationService();
            $notificationService->sendAlert(
                $alert['title'] ?? 'Alerta de Competidor',
                $alert['message'] ?? "Alerta para conta {$accountId}",
                ($alert['priority'] ?? 'medium') === 'high' ? 'HIGH' : 'MEDIUM'
            );
        } catch (\Exception $e) {
            log_warning('CompetitorSpy: erro ao enviar notificação de alerta', [
                'service' => 'CompetitorSpy',
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
