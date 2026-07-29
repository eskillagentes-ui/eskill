<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;
use App\Services\CacheService;

/**
 * Advanced Pricing Rules Engine
 *
 * Features:
 * - Real-time competitor monitoring
 * - Elasticity-based pricing
 * - Psychological pricing
 * - Dynamic repricing
 * - Budget-aware pricing
 * - ROI optimization
 */
class AdvancedPricingEngine
{
    private \PDO $db;
    private MercadoLivreClient $mlClient;
    private CacheService $cache;
    private int $accountId;
    private array $config;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = \App\Database::getInstance();
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->cache = new CacheService();
        $this->config = $this->loadPricingConfig();
    }

    /**
     * Start dynamic pricing engine
     */
    public function startDynamicPricing(array $rules = []): array
    {
        try {
            $pricingRules = array_merge($this->config['dynamic_pricing'], $rules);
            $results = [];

            // Get all products with pricing rules
            $products = $this->getProductsForPricing($pricingRules);

            foreach ($products as $product) {
                $priceDecision = $this->calculateOptimalPrice($product, $pricingRules);

                if ($priceDecision['adjust_price']) {
                    $result = $this->applyPriceChange($product, $priceDecision);
                    $results[] = $result;
                }
            }

            return [
                'success' => true,
                'products_analyzed' => count($products),
                'price_changes' => count($results),
                'results' => $results,
                'summary' => $this->generatePricingSummary($results)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Monitor competitors and adjust prices
     */
    public function monitorCompetitorsAndAdjust(array $competitorItems = []): array
    {
        try {
            $monitoringResults = [];

            if (empty($competitorItems)) {
                $competitorItems = $this->getActiveCompetitorItems();
            }

            foreach ($competitorItems as $competitorItem) {
                $monitoring = $this->monitorCompetitorItem($competitorItem);

                if ($monitoring['requires_action']) {
                    $adjustment = $this->calculateCompetitorAdjustment($competitorItem, $monitoring);
                    if ($adjustment['adjust']) {
                        $result = $this->applyCompetitorAdjustment($competitorItem, $adjustment);
                        $monitoringResults[] = array_merge($monitoring, $result);
                    }
                }
            }

            return [
                'success' => true,
                'competitors_monitored' => count($competitorItems),
                'adjustments_made' => count($monitoringResults),
                'results' => $monitoringResults,
                'market_intelligence' => $this->generateMarketIntelligence($monitoringResults)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Apply psychological pricing rules
     */
    public function applyPsychologicalPricing(array $productIds = []): array
    {
        try {
            $results = [];

            if (empty($productIds)) {
                $productIds = $this->getProductsForPsychologicalPricing();
            }

            foreach ($productIds as $productId) {
                $psychologicalPrice = $this->calculatePsychologicalPrice($productId);

                if ($psychologicalPrice['adjust_price']) {
                    $result = $this->applyPsychologicalPrice($productId, $psychologicalPrice);
                    $results[] = $result;
                }
            }

            return [
                'success' => true,
                'products_processed' => count($results),
                'results' => $results,
                'psychological_patterns' => $this->getPsychologicalPatterns($results),
                'estimated_conversion_lift' => $this->estimateConversionLift($results)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Calculate price elasticity and optimize
     */
    public function optimizeWithElasticity(array $products = []): array
    {
        try {
            $results = [];

            if (empty($products)) {
                $products = $this->getProductsWithElasticityData();
            }

            foreach ($products as $product) {
                $elasticityAnalysis = $this->calculatePriceElasticity($product);
                $optimalPrice = $this->findOptimalPriceByElasticity($product, $elasticityAnalysis);

                if ($optimalPrice['adjust_price']) {
                    $result = $this->applyElasticityBasedPrice($product, $optimalPrice);
                    $results[] = $result;
                }
            }

            return [
                'success' => true,
                'products_analyzed' => count($results),
                'results' => $results,
                'elasticity_insights' => $this->generateElasticityInsights($results),
                'estimated_revenue_impact' => $this->estimateRevenueImpact($results)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Batch price optimization
     */
    public function batchPriceOptimization(array $optimizationConfig): array
    {
        try {
            $batchResults = [];
            $batchId = $this->generateBatchId();

            // Get products for optimization
            $products = $this->getProductsForBatchOptimization($optimizationConfig);

            foreach ($products as $product) {
                $optimization = $this->performComprehensiveOptimization($product, $optimizationConfig);
                $optimization['batch_id'] = $batchId;
                $batchResults[] = $optimization;
            }

            // Apply optimizations in batch
            $appliedResults = $this->applyBatchOptimizations($batchResults);

            return [
                'success' => true,
                'batch_id' => $batchId,
                'total_products' => count($products),
                'optimizations_generated' => count($batchResults),
                'optimizations_applied' => count($appliedResults),
                'batch_results' => $batchResults,
                'applied_results' => $appliedResults,
                'summary' => $this->generateBatchOptimizationSummary($batchResults, $appliedResults)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get pricing analytics and insights
     */
    public function getPricingAnalytics(array $filters = []): array
    {
        try {
            $analytics = [
                'pricing_overview' => $this->getPricingOverview($filters),
                'price_performance' => $this->getPricePerformanceAnalysis($filters),
                'competitor_pricing' => $this->getCompetitorPricingAnalysis($filters),
                'elasticity_analysis' => $this->getElasticityAnalysis($filters),
                'margin_analysis' => $this->getMarginAnalysis($filters),
                'conversion_by_price_point' => $this->getConversionByPricePoint($filters),
                'price_recommendations' => $this->getPriceRecommendations($filters),
                'market_positioning' => $this->getMarketPositioningAnalysis($filters),
                'roi_metrics' => $this->getROIMetrics($filters)
            ];

            return [
                'success' => true,
                'analytics' => $analytics,
                'generated_at' => time(),
                'data_period' => $filters['period'] ?? 'last_30_days'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Calculate optimal price for product
     */
    private function calculateOptimalPrice(array $product, array $rules): array
    {
        $currentPrice = $product['price'];
        $competitorPrices = $this->getCompetitorPrices($product['id']);
        $elasticity = $this->getProductElasticity($product['id']);
        $stockLevel = $product['stock'];
        $demandLevel = $this->getDemandLevel($product['id']);

        // Calculate pricing factors
        $factors = [
            'competitor_position' => $this->calculateCompetitorPosition($currentPrice, $competitorPrices),
            'elasticity_factor' => $elasticity,
            'stock_pressure' => $this->calculateStockPressure($stockLevel, $rules),
            'demand_factor' => $demandLevel,
            'margin_protection' => $this->calculateMarginProtection($product, $rules),
            'psychological_factor' => $this->calculatePsychologicalFactor($currentPrice),
            'time_factor' => $this->calculateTimePricingFactor()
        ];

        // Calculate optimal price
        $baseAdjustment = 0;

        // Competitor-based adjustment
        if ($factors['competitor_position'] > 0.8) { // More expensive than 80% of competitors
            $baseAdjustment -= $rules['competitor_adjustment_rate'] ?? 0.05;
        }

        // Demand-based adjustment
        if ($demandLevel > 1.2) { // High demand
            $baseAdjustment += $rules['demand_premium_rate'] ?? 0.03;
        }

        // Stock-based adjustment
        if ($stockLevel < $rules['low_stock_threshold'] ?? 5) {
            $baseAdjustment += $rules['stock_scarcity_premium'] ?? 0.02;
        }

        $optimalPrice = $currentPrice * (1 + $baseAdjustment);

        // Apply psychological pricing
        $optimalPrice = $this->applyPsychologicalAdjustment($optimalPrice);

        // Apply price limits
        $minPrice = max($product['cost'] * 1.1, $rules['min_price_margin'] ?? 1.0);
        $maxPrice = $currentPrice * ($rules['max_increase_rate'] ?? 1.2);

        $optimalPrice = max($minPrice, min($optimalPrice, $maxPrice));

        return [
            'product_id' => $product['id'],
            'current_price' => $currentPrice,
            'optimal_price' => round($optimalPrice, 2),
            'adjust_price' => abs($optimalPrice - $currentPrice) > ($rules['min_adjustment_threshold'] ?? 0.01),
            'adjustment_percentage' => round((($optimalPrice - $currentPrice) / $currentPrice) * 100, 2),
            'factors' => $factors,
            'confidence_score' => $this->calculatePricingConfidence($factors),
            'expected_impact' => $this->estimatePriceChangeImpact($product, $optimalPrice)
        ];
    }

    /**
     * Monitor single competitor item
     */
    private function monitorCompetitorItem(array $competitorItem): array
    {
        $currentPrice = $competitorItem['price'];
        $historicalPrices = $this->getCompetitorPriceHistory($competitorItem['id']);
        $ourProducts = $this->getOurCompetingProducts($competitorItem);

        $monitoring = [
            'competitor_item_id' => $competitorItem['id'],
            'current_price' => $currentPrice,
            'price_change_detected' => $this->detectPriceChange($currentPrice, $historicalPrices),
            'price_trend' => $this->calculatePriceTrend($historicalPrices),
            'our_position' => $this->calculateOurPosition($competitorItem, $ourProducts),
            'market_impact' => $this->calculateMarketImpact($competitorItem, $ourProducts),
            'requires_action' => false,
            'action_type' => null,
            'urgency' => 'low'
        ];

        // Determine if action is required
        if ($monitoring['price_change_detected'] && $monitoring['our_position']['is_more_expensive']) {
            $monitoring['requires_action'] = true;
            $monitoring['action_type'] = 'price_match_or_undercut';
            $monitoring['urgency'] = $this->calculateUrgency($monitoring);
        }

        return $monitoring;
    }

    /**
     * Calculate psychological price
     */
    private function calculatePsychologicalPrice(string $productId): array
    {
        $currentPrice = $this->getProductPrice($productId);

        // Common psychological price endings
        $psychologicalEndings = [
            0.99,
            0.95,
            0.90,
            0.87,
            0.85
        ];

        $bestPsychologicalPrice = $currentPrice;
        $bestPsychologicalScore = 0;

        foreach ($psychologicalEndings as $ending) {
            $candidatePrice = floor($currentPrice) + $ending;

            // Calculate psychological score
            $score = $this->calculatePsychologicalScore($candidatePrice, $currentPrice);

            if ($score > $bestPsychologicalScore) {
                $bestPsychologicalScore = $score;
                $bestPsychologicalPrice = $candidatePrice;
            }
        }

        // Consider charm pricing
        $charmPrices = [
            $currentPrice * 0.99,  // Just under
            $currentPrice * 1.01,  // Just over
            ($currentPrice + 1) * 0.95  // Slight increase with discount
        ];

        foreach ($charmPrices as $candidatePrice) {
            $score = $this->calculatePsychologicalScore($candidatePrice, $currentPrice);
            if ($score > $bestPsychologicalScore) {
                $bestPsychologicalScore = $score;
                $bestPsychologicalPrice = $candidatePrice;
            }
        }

        return [
            'product_id' => $productId,
            'current_price' => $currentPrice,
            'psychological_price' => round($bestPsychologicalPrice, 2),
            'adjust_price' => abs($bestPsychologicalPrice - $currentPrice) > 0.01,
            'adjustment_amount' => round($bestPsychologicalPrice - $currentPrice, 2),
            'adjustment_percentage' => round((($bestPsychologicalPrice - $currentPrice) / $currentPrice) * 100, 2),
            'psychological_score' => $bestPsychologicalScore,
            'pricing_type' => $this->identifyPsychologicalPricingType($bestPsychologicalPrice, $currentPrice)
        ];
    }

    /**
     * Calculate price elasticity
     */
    private function calculatePriceElasticity(array $product): array
    {
        $historicalData = $this->getHistoricalPricingData($product['id']);

        if (count($historicalData) < 10) {
            return [
                'elasticity_coefficient' => 0,
                'confidence' => 0,
                'recommendation' => 'insufficient_data'
            ];
        }

        // Calculate elasticity using regression
        $elasticity = $this->calculateElasticityCoefficient($historicalData);

        // Determine price elasticity classification
        $elasticityType = 'unit_elastic';
        if ($elasticity > 1.5) {
            $elasticityType = 'elastic';
        } elseif ($elasticity < 0.5) {
            $elasticityType = 'inelastic';
        }

        return [
            'product_id' => $product['id'],
            'elasticity_coefficient' => $elasticity,
            'elasticity_type' => $elasticityType,
            'confidence' => $this->calculateElasticityConfidence($historicalData),
            'data_points' => count($historicalData),
            'price_sensitivity' => $this->calculatePriceSensitivity($elasticity),
            'recommendation' => $this->generateElasticityRecommendation($elasticity, $elasticityType)
        ];
    }

    /**
     * Load pricing configuration
     */
    private function loadPricingConfig(): array
    {
        return [
            'dynamic_pricing' => [
                'enabled' => true,
                'competitor_adjustment_rate' => 0.05,
                'demand_premium_rate' => 0.03,
                'stock_scarcity_premium' => 0.02,
                'low_stock_threshold' => 5,
                'min_adjustment_threshold' => 0.01,
                'max_increase_rate' => 1.2
            ],
            'psychological_pricing' => [
                'enabled' => true,
                'min_adjustment_threshold' => 0.01,
                'max_psychological_increase' => 0.10,
                'apply_charm_pricing' => true
            ],
            'elasticity_pricing' => [
                'enabled' => true,
                'min_data_points' => 10,
                'confidence_threshold' => 0.7,
                'max_elasticity_adjustment' => 0.15
            ],
            'margin_protection' => [
                'min_margin_percentage' => 10,
                'target_margin_percentage' => 25,
                'max_margin_percentage' => 50
            ]
        ];
    }

    // ─── Utility ───────────────────────────────────────────────────────────

    private function generateBatchId(): string
    {
        return 'pricing_batch_' . uniqid();
    }

    // ─── Product query helpers ──────────────────────────────────────────

    private function getProductsForPricing(array $rules): array
    {
        try {
            $limit = max(1, min(500, (int)($rules['max_products'] ?? 100)));
            $stmt = $this->db->prepare("
                SELECT id, title, price, available_quantity AS stock,
                       sold_quantity, category_id, status,
                       ROUND(price * 0.6, 2) AS cost
                FROM ml_items
                WHERE account_id = :account_id
                  AND status = 'active'
                  AND price > 0
                ORDER BY sold_quantity DESC
                LIMIT :limit
            ");
            $stmt->bindValue('account_id', $this->accountId, \PDO::PARAM_INT);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getProductsForPricing failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getActiveCompetitorItems(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT ci.id, ci.ml_item_id, ci.seller_id, ci.title, ci.price,
                       ci.original_price, ci.available_quantity, ci.sold_quantity,
                       ci.category_id, ci.my_item_id
                FROM competitor_items ci
                WHERE ci.account_id = :account_id
                  AND ci.status = 'active'
                ORDER BY ci.updated_at DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getActiveCompetitorItems failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getProductsForPsychologicalPricing(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, title, price, available_quantity AS stock, category_id
                FROM ml_items
                WHERE account_id = :account_id
                  AND status = 'active'
                  AND price > 0
                  AND ROUND(price - FLOOR(price), 2) NOT IN (0.99, 0.95, 0.90)
                ORDER BY sold_quantity DESC
                LIMIT 100
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getProductsForPsychologicalPricing failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getProductsWithElasticityData(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT i.id, i.title, i.price, i.available_quantity AS stock,
                       i.sold_quantity, i.category_id,
                       COUNT(oi.item_id) AS order_count
                FROM ml_items i
                LEFT JOIN order_items oi ON oi.item_id = i.id
                WHERE i.account_id = :account_id
                  AND i.status = 'active'
                  AND i.price > 0
                GROUP BY i.id
                HAVING order_count >= 10
                ORDER BY order_count DESC
                LIMIT 50
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getProductsWithElasticityData failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getProductsForBatchOptimization(array $config): array
    {
        try {
            $params = ['account_id' => $this->accountId];
            $catFilter = '';
            if (!empty($config['category_id'])) {
                $catFilter = 'AND category_id = :category_id';
                $params['category_id'] = $config['category_id'];
            }
            $limit = max(1, min(500, (int)($config['batch_size'] ?? 200)));

            $stmt = $this->db->prepare("
                SELECT id, title, price, available_quantity AS stock,
                       sold_quantity, category_id,
                       ROUND(price * 0.6, 2) AS cost
                FROM ml_items
                WHERE account_id = :account_id
                  AND status = 'active'
                  AND price > 0
                  {$catFilter}
                ORDER BY sold_quantity DESC
                LIMIT :batch_limit
            ");
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue('batch_limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getProductsForBatchOptimization failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getProductPrice(string $productId): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT price FROM ml_items
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['id' => $productId, 'account_id' => $this->accountId]);
            $price = $stmt->fetchColumn();
            return $price !== false ? (float)$price : 0.0;
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getProductPrice failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return 0.0;
        }
    }

    private function getCompetitorPrices(string $productId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT category_id FROM ml_items
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['id' => $productId, 'account_id' => $this->accountId]);
            $cat = $stmt->fetchColumn();
            if (!$cat) {
                return [];
            }

            $stmt2 = $this->db->prepare("
                SELECT ml_item_id, title, price, sold_quantity, available_quantity
                FROM competitor_items
                WHERE account_id = :account_id
                  AND category_id = :category_id
                  AND price > 0
                ORDER BY price ASC
            ");
            $stmt2->execute(['account_id' => $this->accountId, 'category_id' => $cat]);
            return $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getCompetitorPrices failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getProductElasticity(string $productId): float
    {
        try {
            $data = $this->getHistoricalPricingData($productId);
            return count($data) >= 5 ? $this->calculateElasticityCoefficient($data) : 1.0;
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getProductElasticity failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return 1.0;
        }
    }

    private function getDemandLevel(string $productId): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) AS recent
                FROM order_items oi
                JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE oi.item_id = :item_id
                  AND o.ml_account_id = :account_id
                  AND o.date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute(['item_id' => $productId, 'account_id' => $this->accountId]);
            $recent = (int)$stmt->fetchColumn();

            $stmt2 = $this->db->prepare("
                SELECT COUNT(*) / 4.0 AS avg_weekly
                FROM order_items oi
                JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE oi.item_id = :item_id
                  AND o.ml_account_id = :account_id
                  AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt2->execute(['item_id' => $productId, 'account_id' => $this->accountId]);
            $avg = (float)$stmt2->fetchColumn();

            if ($avg <= 0) {
                return $recent > 0 ? 1.5 : 0.5;
            }
            return round($recent / $avg, 2);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getDemandLevel failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return 1.0;
        }
    }

    private function getCompetitorPriceHistory(string $competitorId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT cph.price, cph.min_price, cph.max_price,
                       cph.last_price, cph.stock, cph.recorded_at
                FROM competitor_price_history cph
                JOIN competitor_items ci ON ci.id = cph.competitor_item_id
                WHERE ci.ml_item_id = :competitor_id
                  AND ci.account_id = :account_id
                ORDER BY cph.recorded_at DESC
                LIMIT 90
            ");
            $stmt->execute(['competitor_id' => $competitorId, 'account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getCompetitorPriceHistory failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getOurCompetingProducts(array $competitorItem): array
    {
        try {
            $cat = $competitorItem['category_id'] ?? null;
            if (!$cat) {
                return [];
            }
            $stmt = $this->db->prepare("
                SELECT id, title, price, available_quantity, sold_quantity, category_id
                FROM ml_items
                WHERE account_id = :account_id
                  AND category_id = :category_id
                  AND status = 'active'
                ORDER BY sold_quantity DESC
            ");
            $stmt->execute(['account_id' => $this->accountId, 'category_id' => $cat]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getOurCompetingProducts failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    private function getHistoricalPricingData(string $productId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT oi.unit_price AS price, o.date_created AS date,
                       oi.quantity AS sold_quantity
                FROM order_items oi
                JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE oi.item_id = :item_id
                  AND o.ml_account_id = :account_id
                ORDER BY o.date_created DESC
                LIMIT 100
            ");
            $stmt->execute(['item_id' => $productId, 'account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('AdvancedPricingEngine::getHistoricalPricingData failed', ['exception' => $e->getMessage(), 'service' => 'AdvancedPricingEngine']);
            return [];
        }
    }

    // ─── Price update helpers ───────────────────────────────────────────

    private function applyPriceChange(array $product, array $decision): array
    {
        try {
            $itemId = $product['id'];
            $newPrice = $decision['optimal_price'];

            $this->mlClient->updateItem($itemId, ['price' => $newPrice]);

            $stmt = $this->db->prepare("
                UPDATE ml_items SET price = :price, updated_at = NOW()
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['price' => $newPrice, 'id' => $itemId, 'account_id' => $this->accountId]);

            return [
                'item_id' => $itemId,
                'old_price' => $product['price'],
                'new_price' => $newPrice,
                'change_percentage' => $decision['adjustment_percentage'] ?? 0,
                'applied' => true,
                'applied_at' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            return ['item_id' => $product['id'] ?? '', 'applied' => false, 'error' => $e->getMessage()];
        }
    }

    private function applyPsychologicalPrice(string $productId, array $price): array
    {
        try {
            $newPrice = $price['psychological_price'];
            $this->mlClient->updateItem($productId, ['price' => $newPrice]);

            $stmt = $this->db->prepare("
                UPDATE ml_items SET price = :price, updated_at = NOW()
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['price' => $newPrice, 'id' => $productId, 'account_id' => $this->accountId]);

            return [
                'item_id' => $productId,
                'old_price' => $price['current_price'],
                'new_price' => $newPrice,
                'pricing_type' => $price['pricing_type'] ?? 'psychological',
                'applied' => true
            ];
        } catch (\Exception $e) {
            return ['item_id' => $productId, 'applied' => false, 'error' => $e->getMessage()];
        }
    }

    private function applyElasticityBasedPrice(array $product, array $optimalPrice): array
    {
        try {
            $itemId = $product['id'];
            $newPrice = $optimalPrice['optimal_price'];

            $this->mlClient->updateItem($itemId, ['price' => $newPrice]);

            $stmt = $this->db->prepare("
                UPDATE ml_items SET price = :price, updated_at = NOW()
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['price' => $newPrice, 'id' => $itemId, 'account_id' => $this->accountId]);

            return [
                'item_id' => $itemId,
                'old_price' => $optimalPrice['current_price'] ?? $product['price'],
                'new_price' => $newPrice,
                'elasticity_type' => $optimalPrice['reason'] ?? 'elasticity',
                'applied' => true
            ];
        } catch (\Exception $e) {
            return ['item_id' => $product['id'] ?? '', 'applied' => false, 'error' => $e->getMessage()];
        }
    }

    private function applyBatchOptimizations(array $batchResults): array
    {
        $applied = [];
        foreach ($batchResults as $result) {
            if (!($result['apply'] ?? false)) {
                continue;
            }
            try {
                $itemId = $result['product_id'];
                $newPrice = $result['recommended_price'];

                $this->mlClient->updateItem($itemId, ['price' => $newPrice]);

                $stmt = $this->db->prepare("
                    UPDATE ml_items SET price = :price, updated_at = NOW()
                    WHERE id = :id AND account_id = :account_id
                ");
                $stmt->execute(['price' => $newPrice, 'id' => $itemId, 'account_id' => $this->accountId]);

                $applied[] = array_merge($result, ['applied' => true]);
            } catch (\Exception $e) {
                $applied[] = array_merge($result, ['applied' => false, 'error' => $e->getMessage()]);
            }
        }
        return $applied;
    }

    private function applyCompetitorAdjustment(array $competitorItem, array $adjustment): array
    {
        try {
            $targetPrice = $adjustment['target_price'];
            $myItemId = $competitorItem['my_item_id'] ?? null;
            if (!$myItemId) {
                return ['applied' => false, 'error' => 'no_matching_product'];
            }

            $this->mlClient->updateItem($myItemId, ['price' => $targetPrice]);

            $stmt = $this->db->prepare("
                UPDATE ml_items SET price = :price, updated_at = NOW()
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['price' => $targetPrice, 'id' => $myItemId, 'account_id' => $this->accountId]);

            return [
                'applied' => true,
                'item_id' => $myItemId,
                'old_price' => $adjustment['current_price'],
                'new_price' => $targetPrice,
                'strategy' => $adjustment['strategy'] ?? 'competitor_match',
                'applied_at' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            return ['applied' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Aggregation & summary helpers ──────────────────────────────────

    private function generatePricingSummary(array $results): array
    {
        $applied = array_filter($results, fn(array $r): bool => ($r['applied'] ?? false));
        $increases = 0;
        $decreases = 0;
        foreach ($applied as $r) {
            if (($r['new_price'] ?? 0) > ($r['old_price'] ?? 0)) {
                $increases++;
            } else {
                $decreases++;
            }
        }
        return [
            'total_changes' => count($applied),
            'total_failed' => count($results) - count($applied),
            'price_increases' => $increases,
            'price_decreases' => $decreases,
            'avg_change_pct' => count($applied) > 0
                ? round(array_sum(array_column($applied, 'change_percentage')) / count($applied), 2)
                : 0,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    private function getPsychologicalPatterns(array $results): array
    {
        $patterns = ['charm_pricing' => 0, 'prestige_pricing' => 0, 'odd_pricing' => 0, 'even_pricing' => 0, 'discount_pricing' => 0, 'standard' => 0];
        foreach ($results as $r) {
            $type = $r['pricing_type'] ?? 'standard';
            if (isset($patterns[$type])) {
                $patterns[$type]++;
            }
        }
        return [
            'distribution' => $patterns,
            'most_common' => !empty($patterns) ? array_keys($patterns, max($patterns))[0] : 'standard',
            'total_applied' => array_sum($patterns)
        ];
    }

    private function generateBatchOptimizationSummary(array $generated, array $applied): array
    {
        $ok = count(array_filter($applied, fn(array $a): bool => ($a['applied'] ?? false)));
        $avgAdj = count($applied) > 0
            ? array_sum(array_column($applied, 'adjustment_percentage')) / count($applied)
            : 0;

        return [
            'total_generated' => count($generated),
            'total_applied' => count($applied),
            'successful' => $ok,
            'failed' => count($applied) - $ok,
            'skipped' => count($generated) - count($applied),
            'avg_adjustment_pct' => round($avgAdj, 2),
            'completed_at' => date('Y-m-d H:i:s')
        ];
    }

    private function generateMarketIntelligence(array $results): array
    {
        $actions = count(array_filter($results, fn(array $r): bool => ($r['requires_action'] ?? false)));
        $changes = count(array_filter($results, fn(array $r): bool => ($r['price_change_detected'] ?? false)));

        $urgency = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $trends  = ['increasing' => 0, 'decreasing' => 0, 'stable' => 0];
        foreach ($results as $r) {
            $u = $r['urgency'] ?? 'low';
            if (isset($urgency[$u])) $urgency[$u]++;
            $t = $r['price_trend'] ?? 'stable';
            if (isset($trends[$t])) $trends[$t]++;
        }

        return [
            'total_monitored' => count($results),
            'actions_required' => $actions,
            'price_changes_detected' => $changes,
            'urgency_distribution' => $urgency,
            'trend_distribution' => $trends,
            'market_status' => $actions > count($results) * 0.3 ? 'volatile' : 'stable',
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    private function generateElasticityInsights(array $results): array
    {
        $elastic = count(array_filter($results, fn(array $r): bool => ($r['elasticity_type'] ?? '') === 'elasticity'));
        return [
            'total_analyzed' => count($results),
            'adjustments_applied' => count(array_filter($results, fn(array $r): bool => ($r['applied'] ?? false))),
            'elastic_products' => $elastic,
            'avg_adjustment_pct' => count($results) > 0
                ? round(array_sum(array_map(fn(array $r): float => abs((float)($r['new_price'] ?? 0) - (float)($r['old_price'] ?? 0)) / max(1, (float)($r['old_price'] ?? 1)) * 100, $results)) / count($results), 2)
                : 0
        ];
    }

    private function estimateRevenueImpact(array $results): float
    {
        $impact = 0.0;
        foreach ($results as $r) {
            $impact += ((float)($r['new_price'] ?? 0) - (float)($r['old_price'] ?? 0));
        }
        return round($impact, 2);
    }

    // ─── Optimization helpers ───────────────────────────────────────────

    private function findOptimalPriceByElasticity(array $product, array $elasticity): array
    {
        $current = (float)($product['price'] ?? 0);
        $coeff   = $elasticity['elasticity_coefficient'] ?? 1.0;
        $conf    = $elasticity['confidence'] ?? 0;

        if ($conf < 0.5 || $current <= 0) {
            return ['product_id' => $product['id'], 'optimal_price' => $current, 'adjust_price' => false, 'reason' => 'insufficient_confidence'];
        }

        $adj = 0;
        if ($coeff > 1.5) $adj = -0.05;
        elseif ($coeff > 1.0) $adj = -0.03;
        elseif ($coeff < 0.5) $adj = 0.08;
        elseif ($coeff < 1.0) $adj = 0.05;

        $max = $this->config['elasticity_pricing']['max_elasticity_adjustment'] ?? 0.15;
        $adj = max(-$max, min($adj, $max));
        $optimal = round($current * (1 + $adj), 2);

        return [
            'product_id' => $product['id'],
            'current_price' => $current,
            'optimal_price' => $optimal,
            'adjust_price' => abs($optimal - $current) > 0.01,
            'adjustment_percentage' => round($adj * 100, 2),
            'elasticity_coefficient' => $coeff,
            'confidence' => $conf,
            'reason' => $elasticity['elasticity_type'] ?? 'unit_elastic'
        ];
    }

    private function performComprehensiveOptimization(array $product, array $config): array
    {
        $current = (float)($product['price'] ?? 0);
        $adjustments = [];
        $recommendations = [];

        // Competitor factor
        $compPrices = $this->getCompetitorPrices($product['id']);
        if (!empty($compPrices)) {
            $avgComp = array_sum(array_column($compPrices, 'price')) / count($compPrices);
            $adjustments[] = (($avgComp - $current) / max(1, $current)) * 0.4;
            $recommendations[] = 'competitor_adjustment';
        }

        // Demand factor
        $demand = $this->getDemandLevel($product['id']);
        if ($demand > 1.2) {
            $adjustments[] = 0.03;
            $recommendations[] = 'high_demand_premium';
        } elseif ($demand < 0.8) {
            $adjustments[] = -0.05;
            $recommendations[] = 'low_demand_discount';
        }

        // Stock factor
        $stock = (int)($product['stock'] ?? 0);
        if ($stock < 3) {
            $adjustments[] = 0.02;
            $recommendations[] = 'scarcity_premium';
        } elseif ($stock > 50) {
            $adjustments[] = -0.03;
            $recommendations[] = 'overstock_discount';
        }

        $totalAdj = !empty($adjustments) ? array_sum($adjustments) / count($adjustments) : 0;
        $maxAdj = $config['max_adjustment'] ?? 0.15;
        $totalAdj = max(-$maxAdj, min($totalAdj, $maxAdj));

        $newPrice = $this->applyPsychologicalAdjustment(round($current * (1 + $totalAdj), 2));

        return [
            'product_id' => $product['id'],
            'current_price' => $current,
            'recommended_price' => $newPrice,
            'adjustment_percentage' => round($totalAdj * 100, 2),
            'recommendations' => $recommendations,
            'apply' => abs($newPrice - $current) > ($config['min_threshold'] ?? 0.50),
            'confidence' => min(0.95, 0.5 + count($recommendations) * 0.15)
        ];
    }

    private function calculateCompetitorAdjustment(array $competitorItem, array $monitoring): array
    {
        $compPrice = (float)($competitorItem['price'] ?? 0);
        $ourPos    = $monitoring['our_position'] ?? [];
        $ourPrice  = (float)($ourPos['cheapest_our_price'] ?? 0);

        if ($compPrice <= 0 || $ourPrice <= 0) {
            return ['adjust' => false, 'reason' => 'insufficient_data'];
        }

        $diff = ($ourPrice - $compPrice) / $compPrice;
        if ($diff <= 0.02) {
            return ['adjust' => false, 'reason' => 'already_competitive'];
        }

        $target = max($compPrice * 0.99, $ourPrice * 0.85);
        $target = $this->applyPsychologicalAdjustment($target);

        return [
            'adjust' => true,
            'competitor_price' => $compPrice,
            'current_price' => $ourPrice,
            'target_price' => round($target, 2),
            'adjustment_pct' => round(($target - $ourPrice) / $ourPrice * 100, 2),
            'strategy' => 'competitive_undercut',
            'urgency' => $monitoring['urgency'] ?? 'low'
        ];
    }

    // ─── Analytics helpers ──────────────────────────────────────────────

    private function getPricingOverview(array $filters): array
    {
        try {
            $days = match ($filters['period'] ?? 'last_30_days') {
                'last_7_days' => 7,
                'last_90_days' => 90,
                default => 30
            };

            $stmt = $this->db->prepare("
                SELECT COUNT(*) AS total_products,
                       ROUND(AVG(price),2) AS avg_price,
                       MIN(price) AS min_price,
                       MAX(price) AS max_price,
                       SUM(CASE WHEN available_quantity > 0 THEN 1 ELSE 0 END) AS in_stock,
                       SUM(CASE WHEN available_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
                       ROUND(AVG(sold_quantity),1) AS avg_sold
                FROM ml_items
                WHERE account_id = :account_id AND status = 'active'
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $overview = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $since = date('Y-m-d', strtotime("-{$days} days"));
            $stmt2 = $this->db->prepare("
                SELECT COUNT(*) AS recent_changes
                FROM ml_items
                WHERE account_id = :account_id AND updated_at >= :since
            ");
            $stmt2->execute(['account_id' => $this->accountId, 'since' => $since]);
            $overview['recent_price_changes'] = (int)($stmt2->fetchColumn() ?: 0);
            $overview['period'] = $filters['period'] ?? 'last_30_days';

            return $overview;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getPricePerformanceAnalysis(array $filters): array
    {
        try {
            $days = match ($filters['period'] ?? 'last_30_days') {
                'last_7_days' => 7,
                'last_90_days' => 90,
                default => 30
            };
            $since = date('Y-m-d', strtotime("-{$days} days"));

            $stmt = $this->db->prepare("
                SELECT i.id, i.title, i.price, i.sold_quantity,
                       COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_revenue,
                       COALESCE(SUM(oi.quantity), 0) AS units_sold
                FROM ml_items i
                LEFT JOIN order_items oi ON oi.item_id = i.id
                LEFT JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id) AND o.date_created >= :since
                WHERE i.account_id = :account_id AND i.status = 'active'
                GROUP BY i.id
                ORDER BY total_revenue DESC
                LIMIT 50
            ");
            $stmt->execute(['account_id' => $this->accountId, 'since' => $since]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getCompetitorPricingAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT ci.category_id,
                       COUNT(ci.id) AS competitor_count,
                       ROUND(AVG(ci.price),2) AS avg_competitor_price,
                       MIN(ci.price) AS min_competitor_price,
                       MAX(ci.price) AS max_competitor_price
                FROM competitor_items ci
                WHERE ci.account_id = :account_id AND ci.price > 0
                GROUP BY ci.category_id
                ORDER BY competitor_count DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Enrich with our average price per category
            foreach ($rows as &$row) {
                $stmt2 = $this->db->prepare("
                    SELECT ROUND(AVG(price),2) AS our_avg
                    FROM ml_items
                    WHERE account_id = :account_id AND category_id = :cat AND status = 'active'
                ");
                $stmt2->execute(['account_id' => $this->accountId, 'cat' => $row['category_id']]);
                $row['our_avg_price'] = (float)($stmt2->fetchColumn() ?: 0);
                $row['price_gap'] = round($row['our_avg_price'] - (float)$row['avg_competitor_price'], 2);
            }
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getElasticityAnalysis(array $filters): array
    {
        try {
            $products = $this->getProductsWithElasticityData();
            $analysis = [];
            foreach (array_slice($products, 0, 20) as $p) {
                $e = $this->calculatePriceElasticity($p);
                $analysis[] = ['product_id' => $p['id'], 'title' => $p['title'], 'price' => $p['price'], 'elasticity' => $e];
            }
            return [
                'products_analyzed' => count($analysis),
                'elastic' => count(array_filter($analysis, fn(array $a): bool => ($a['elasticity']['elasticity_type'] ?? '') === 'elastic')),
                'inelastic' => count(array_filter($analysis, fn(array $a): bool => ($a['elasticity']['elasticity_type'] ?? '') === 'inelastic')),
                'details' => $analysis
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getMarginAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT category_id,
                       COUNT(*) AS product_count,
                       ROUND(AVG(price),2) AS avg_price,
                       ROUND(AVG(price * 0.6),2) AS avg_est_cost,
                       ROUND(AVG(price * 0.4),2) AS avg_est_margin
                FROM ml_items
                WHERE account_id = :account_id AND status = 'active' AND price > 0
                GROUP BY category_id
                ORDER BY avg_est_margin DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $byCategory = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt2 = $this->db->prepare("
                SELECT COUNT(*) AS total, ROUND(SUM(price),2) AS sum_price,
                       ROUND(SUM(price * 0.4),2) AS total_margin
                FROM ml_items
                WHERE account_id = :account_id AND status = 'active' AND price > 0
            ");
            $stmt2->execute(['account_id' => $this->accountId]);

            return ['overall' => $stmt2->fetch(\PDO::FETCH_ASSOC) ?: [], 'by_category' => $byCategory];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getConversionByPricePoint(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    CASE
                        WHEN i.price < 50 THEN 'under_50'
                        WHEN i.price < 100 THEN '50_to_100'
                        WHEN i.price < 250 THEN '100_to_250'
                        WHEN i.price < 500 THEN '250_to_500'
                        WHEN i.price < 1000 THEN '500_to_1000'
                        ELSE 'over_1000'
                    END AS price_range,
                    COUNT(DISTINCT i.id) AS product_count,
                    ROUND(AVG(i.price),2) AS avg_price,
                    SUM(i.sold_quantity) AS total_sold
                FROM ml_items i
                WHERE i.account_id = :account_id AND i.status = 'active' AND i.price > 0
                GROUP BY price_range
                ORDER BY AVG(i.price) ASC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getPriceRecommendations(array $filters): array
    {
        try {
            $recs = [];

            // Overpriced vs competitors
            $stmt = $this->db->prepare("
                SELECT i.id, i.title, i.price AS our_price,
                       ROUND(AVG(ci.price),2) AS avg_comp
                FROM ml_items i
                JOIN competitor_items ci ON ci.category_id = i.category_id AND ci.account_id = i.account_id
                WHERE i.account_id = :account_id AND i.status = 'active' AND ci.price > 0
                GROUP BY i.id
                HAVING our_price > avg_comp * 1.15
                ORDER BY (our_price - avg_comp) DESC
                LIMIT 10
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $item) {
                $recs[] = [
                    'product_id' => $item['id'],
                    'title' => $item['title'],
                    'type' => 'reduce_price',
                    'current' => $item['our_price'],
                    'suggested' => round((float)$item['avg_comp'] * 1.05, 2),
                    'reason' => 'Preço acima da média dos concorrentes',
                    'priority' => 'high'
                ];
            }

            // High demand + low stock
            $stmt2 = $this->db->prepare("
                SELECT id, title, price, sold_quantity
                FROM ml_items
                WHERE account_id = :account_id AND status = 'active'
                  AND sold_quantity > 20 AND available_quantity < 5
                ORDER BY sold_quantity DESC LIMIT 10
            ");
            $stmt2->execute(['account_id' => $this->accountId]);
            foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $item) {
                $recs[] = [
                    'product_id' => $item['id'],
                    'title' => $item['title'],
                    'type' => 'increase_price',
                    'current' => $item['price'],
                    'suggested' => round((float)$item['price'] * 1.08, 2),
                    'reason' => 'Alta demanda com estoque baixo',
                    'priority' => 'medium'
                ];
            }
            return $recs;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getMarketPositioningAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT i.category_id,
                       COUNT(DISTINCT i.id) AS our_products,
                       ROUND(AVG(i.price),2) AS our_avg_price,
                       SUM(i.sold_quantity) AS our_total_sold
                FROM ml_items i
                WHERE i.account_id = :account_id AND i.status = 'active'
                GROUP BY i.category_id
                HAVING our_products >= 2
                ORDER BY our_total_sold DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $positions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($positions as &$pos) {
                $stmt2 = $this->db->prepare("
                    SELECT ROUND(AVG(price),2) AS avg_comp, AVG(sold_quantity) AS avg_sold
                    FROM competitor_items
                    WHERE account_id = :account_id AND category_id = :cat AND price > 0
                ");
                $stmt2->execute(['account_id' => $this->accountId, 'cat' => $pos['category_id']]);
                $comp = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: [];

                $ourAvg  = (float)$pos['our_avg_price'];
                $compAvg = (float)($comp['avg_comp'] ?? 0);
                $pos['competitor_avg_price'] = $compAvg;
                $pos['competitor_avg_sold']  = (float)($comp['avg_sold'] ?? 0);

                if ($compAvg > 0) {
                    $pos['price_position'] = $ourAvg < $compAvg * 0.95 ? 'below_market'
                        : ($ourAvg > $compAvg * 1.05 ? 'above_market' : 'at_market');
                    $pos['price_index'] = round($ourAvg / $compAvg * 100, 1);
                } else {
                    $pos['price_position'] = 'no_competitor_data';
                    $pos['price_index'] = 100;
                }
            }
            return $positions;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getROIMetrics(array $filters): array
    {
        try {
            $days = match ($filters['period'] ?? 'last_30_days') {
                'last_7_days' => 7,
                'last_90_days' => 90,
                default => 30
            };
            $since = date('Y-m-d', strtotime("-{$days} days"));

            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT oi.item_id) AS products_with_sales,
                       ROUND(SUM(oi.quantity * oi.unit_price),2) AS total_revenue,
                       SUM(oi.quantity) AS total_units,
                       ROUND(AVG(oi.unit_price),2) AS avg_selling_price,
                       ROUND(SUM(oi.quantity * oi.unit_price * 0.4),2) AS estimated_profit
                FROM order_items oi
                JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE o.ml_account_id = :account_id AND o.date_created >= :since AND o.status = 'paid'
            ");
            $stmt->execute(['account_id' => $this->accountId, 'since' => $since]);
            $m = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $m['period'] = $filters['period'] ?? 'last_30_days';
            $m['roi_pct'] = ((float)($m['estimated_profit'] ?? 0) > 0 && (float)($m['total_revenue'] ?? 0) > 0)
                ? round((float)$m['estimated_profit'] / (float)$m['total_revenue'] * 100, 2) : 0;
            return $m;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ─── Pure calculation helpers ───────────────────────────────────────

    private function estimateConversionLift(array $results): float
    {
        if (empty($results)) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($results as $r) {
            $total += match ($r['pricing_type'] ?? 'standard') {
                'charm_pricing' => 0.08,
                'prestige_pricing' => 0.03,
                'odd_pricing' => 0.05,
                'even_pricing' => 0.02,
                default => 0.04
            };
        }
        return round($total / count($results), 4);
    }

    private function calculateCompetitorPosition(float $currentPrice, array $competitorPrices): float
    {
        if (empty($competitorPrices)) {
            return 0.5;
        }
        $prices = array_map(fn(array|float $cp): float => (float)($cp['price'] ?? $cp), $competitorPrices);
        $cheaper = count(array_filter($prices, fn(float $p): bool => $p < $currentPrice));
        return round($cheaper / count($prices), 2);
    }

    private function calculateStockPressure(int $stockLevel, array $rules): float
    {
        $low  = $rules['low_stock_threshold'] ?? 5;
        $high = $rules['high_stock_threshold'] ?? 50;

        if ($stockLevel <= 0) return 1.0;
        if ($stockLevel < $low) return round(1.0 - ($stockLevel / $low), 2);
        if ($stockLevel > $high) return round(-1.0 * min(1.0, ($stockLevel - $high) / $high), 2);
        return 0.0;
    }

    private function calculateMarginProtection(array $product, array $rules): float
    {
        $price = (float)($product['price'] ?? 0);
        $cost  = (float)($product['cost'] ?? $price * 0.6);
        $minM  = ($rules['min_margin_percentage'] ?? 10) / 100;

        if ($price <= 0) return 0.0;
        $curr = ($price - $cost) / $price;
        if ($curr <= $minM) return 1.0;

        $tgtM = ($rules['target_margin_percentage'] ?? 25) / 100;
        return round(max(0, 1.0 - ($curr - $minM) / max(0.01, $tgtM - $minM)), 2);
    }

    private function calculatePsychologicalFactor(float $price): float
    {
        $dec = round($price - floor($price), 2);
        // Note: float array keys are truncated to int in PHP, so use match()
        $score = match (true) {
            abs($dec - 0.99) < 0.02 => 0.9,
            abs($dec - 0.95) < 0.02 => 0.8,
            abs($dec - 0.90) < 0.02 => 0.7,
            abs($dec - 0.00) < 0.02 => 0.5,
            default => null,
        };
        if ($score !== null) return $score;
        return ($price % 100 >= 95) ? 0.3 : 0.1;
    }

    private function calculateTimePricingFactor(): float
    {
        $h = (int)date('G');
        $d = (int)date('w');

        if ($d === 0 || $d === 6) return 1.02;      // Weekend
        if ($h >= 18 && $h <= 22)  return 1.01;      // Peak evening
        if ($h >= 2 && $h <= 6)    return 0.99;       // Off-peak
        return 1.0;
    }

    private function applyPsychologicalAdjustment(float $price): float
    {
        if ($price <= 0) return $price;

        $dec = round($price - floor($price), 2);
        if (in_array($dec, [0.99, 0.95, 0.90])) return $price;

        if ($price < 100)  return floor($price) + 0.99;
        if ($price < 1000) return floor($price) + 0.90;
        return floor($price / 10) * 10 - 1 + 0.90;
    }

    private function calculatePricingConfidence(array $factors): float
    {
        $w = [
            'competitor_position' => 0.25,
            'elasticity_factor' => 0.20,
            'stock_pressure' => 0.10,
            'demand_factor' => 0.20,
            'margin_protection' => 0.15,
            'psychological_factor' => 0.05,
            'time_factor' => 0.05
        ];
        $tw = 0;
        $ts = 0;
        foreach ($w as $k => $wt) {
            if (isset($factors[$k])) {
                $ts += min(1.0, abs((float)$factors[$k])) * $wt;
                $tw += $wt;
            }
        }
        return $tw > 0 ? round(min(0.95, 0.5 + $ts / $tw * 0.5), 2) : 0.5;
    }

    private function estimatePriceChangeImpact(array $product, float $newPrice): array
    {
        $cur = (float)($product['price'] ?? 0);
        if ($cur <= 0) return ['estimated_sales_change' => 0, 'estimated_revenue_change' => 0];

        $pChg = ($newPrice - $cur) / $cur;
        $el   = $this->getProductElasticity($product['id'] ?? '');
        $sChg = -$pChg * $el;
        $sold = (float)($product['sold_quantity'] ?? 0);
        $newSold = $sold * (1 + $sChg);

        return [
            'price_change_pct' => round($pChg * 100, 2),
            'sales_change_pct' => round($sChg * 100, 2),
            'current_revenue'  => round($cur * $sold, 2),
            'new_revenue'      => round($newPrice * $newSold, 2),
            'revenue_change'   => round($newPrice * $newSold - $cur * $sold, 2)
        ];
    }

    private function detectPriceChange(float $currentPrice, array $historicalPrices): bool
    {
        if (empty($historicalPrices)) return false;
        return abs($currentPrice - (float)($historicalPrices[0]['price'] ?? 0)) > 0.01;
    }

    private function calculatePriceTrend(array $historicalPrices): string
    {
        if (count($historicalPrices) < 3) return 'stable';

        $recent = array_slice($historicalPrices, 0, 5);
        $older  = array_slice($historicalPrices, 5, 5);

        $rAvg = array_sum(array_column($recent, 'price')) / count($recent);
        $oAvg = !empty($older) ? array_sum(array_column($older, 'price')) / count($older) : $rAvg;

        if ($oAvg <= 0) return 'stable';
        $chg = ($rAvg - $oAvg) / $oAvg;

        if ($chg > 0.05)  return 'increasing';
        if ($chg < -0.05) return 'decreasing';
        return 'stable';
    }

    private function calculateOurPosition(array $competitorItem, array $ourProducts): array
    {
        $compPrice = (float)($competitorItem['price'] ?? 0);
        if (empty($ourProducts) || $compPrice <= 0) {
            return ['is_more_expensive' => false, 'cheapest_our_price' => 0, 'price_difference' => 0, 'products_count' => 0];
        }

        $ourPrices = array_map(fn(array $p): float => (float)$p['price'], $ourProducts);
        $cheapest  = min($ourPrices);

        return [
            'is_more_expensive' => $cheapest > $compPrice,
            'cheapest_our_price' => $cheapest,
            'price_difference' => round($cheapest - $compPrice, 2),
            'price_difference_pct' => round(($cheapest - $compPrice) / $compPrice * 100, 2),
            'products_count' => count($ourProducts),
            'avg_our_price' => round(array_sum($ourPrices) / count($ourPrices), 2)
        ];
    }

    private function calculateMarketImpact(array $competitorItem, array $ourProducts): array
    {
        $cSales = (int)($competitorItem['sold_quantity'] ?? 0);
        $oSales = (int)array_sum(array_column($ourProducts, 'sold_quantity'));
        $total  = $oSales + $cSales;

        return [
            'our_market_share' => $total > 0 ? round($oSales / $total * 100, 2) : 0,
            'competitor_share' => $total > 0 ? round($cSales / $total * 100, 2) : 0,
            'total_volume' => $total,
            'price_competitiveness' => (!empty($ourProducts) && min(array_column($ourProducts, 'price')) <= (float)($competitorItem['price'] ?? PHP_FLOAT_MAX))
                ? 'competitive' : 'premium',
            'impact_level' => $cSales > $oSales ? 'high' : ($cSales > $oSales * 0.5 ? 'medium' : 'low')
        ];
    }

    private function calculateUrgency(array $monitoring): string
    {
        $s = 0;
        if ($monitoring['price_change_detected'] ?? false) $s += 3;
        if ($monitoring['our_position']['is_more_expensive'] ?? false) $s += 2;
        if (($monitoring['price_trend'] ?? 'stable') === 'decreasing') $s += 2;
        if (($monitoring['market_impact']['impact_level'] ?? 'low') === 'high') $s += 3;

        if ($s >= 7) return 'critical';
        if ($s >= 5) return 'high';
        if ($s >= 3) return 'medium';
        return 'low';
    }

    private function calculatePsychologicalScore(float $candidatePrice, float $currentPrice): float
    {
        if ($candidatePrice <= 0) return 0.0;

        $score = 0.0;
        $dec = round($candidatePrice - floor($candidatePrice), 2);

        // Note: float array keys are truncated to int in PHP, so use match()
        $charmScore = match (true) {
            abs($dec - 0.99) < 0.005 => 3.0,
            abs($dec - 0.95) < 0.005 => 2.5,
            abs($dec - 0.90) < 0.005 => 2.0,
            abs($dec - 0.87) < 0.005 => 1.5,
            abs($dec - 0.85) < 0.005 => 1.5,
            default => 0.0,
        };
        $score += $charmScore;

        if (floor($candidatePrice) < floor($currentPrice)) $score += 2.0;

        $diff = abs($candidatePrice - $currentPrice) / max(0.01, $currentPrice);
        if ($diff < 0.03) $score += 1.5;
        elseif ($diff < 0.05) $score += 1.0;

        if (abs($dec) < 0.01) $score -= 0.5;

        return round(max(0, $score), 2);
    }

    private function identifyPsychologicalPricingType(float $candidatePrice, float $currentPrice): string
    {
        $dec = round($candidatePrice - floor($candidatePrice), 2);
        if ($dec === 0.99 || $dec === 0.95) return 'charm_pricing';
        if ($dec === 0.00 && $candidatePrice >= 100) return 'prestige_pricing';
        if (in_array($dec, [0.01, 0.03, 0.07])) return 'odd_pricing';
        if (in_array($dec, [0.00, 0.50])) return 'even_pricing';
        if ($candidatePrice < $currentPrice * 0.95) return 'discount_pricing';
        return 'standard';
    }

    // ─── Elasticity calculation helpers ─────────────────────────────────

    private function calculateElasticityCoefficient(array $data): float
    {
        if (count($data) < 5) return 1.0;

        $groups = [];
        foreach ($data as $e) {
            $p = round((float)($e['price'] ?? 0), 0);
            if ($p <= 0) continue;
            if (!isset($groups[$p])) $groups[$p] = ['qty' => 0, 'n' => 0];
            $groups[$p]['qty'] += (int)($e['sold_quantity'] ?? 1);
            $groups[$p]['n']++;
        }
        if (count($groups) < 2) return 1.0;

        $prices = array_keys($groups);
        sort($prices);
        $changes = [];
        for ($i = 1; $i < count($prices); $i++) {
            $p1 = $prices[$i - 1];
            $p2 = $prices[$i];
            $q1 = $groups[$p1]['qty'] / $groups[$p1]['n'];
            $q2 = $groups[$p2]['qty'] / $groups[$p2]['n'];
            if ($p1 > 0 && $q1 > 0) {
                $dp = ($p2 - $p1) / $p1;
                $dq = ($q2 - $q1) / $q1;
                if (abs($dp) > 0.01) $changes[] = abs($dq / $dp);
            }
        }
        return !empty($changes) ? round(array_sum($changes) / count($changes), 2) : 1.0;
    }

    private function calculateElasticityConfidence(array $data): float
    {
        $n = count($data);
        if ($n < 5) return 0.1;
        if ($n < 10) return 0.3;
        if ($n < 20) return 0.5;
        if ($n < 50) return 0.7;
        if ($n < 100) return 0.85;
        return 0.95;
    }

    private function calculatePriceSensitivity(float $elasticity): string
    {
        if ($elasticity >= 2.0) return 'very_high';
        if ($elasticity >= 1.5) return 'high';
        if ($elasticity >= 0.8) return 'medium';
        if ($elasticity >= 0.3) return 'low';
        return 'very_low';
    }

    private function generateElasticityRecommendation(float $elasticity, string $type): string
    {
        return match ($type) {
            'elastic'   => $elasticity > 2.0 ? 'reduce_price_significantly' : 'reduce_price_moderately',
            'inelastic' => $elasticity < 0.3 ? 'increase_price_significantly' : 'increase_price_moderately',
            default     => 'maintain_price'
        };
    }
}
