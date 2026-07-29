<?php
declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;
use App\Services\CacheService;

/**
 * ML Ads Advanced Service - Smart Advertising Optimization
 *
 * Features:
 * - Smart Campaign Optimization
 * - Dynamic Bid Management
 * - Advanced Targeting
 * - Cross-Product Upselling
 * - Performance Analytics
 * - Budget Automation
 */
class MLAdsAdvancedService
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
        $this->config = $this->loadAdsConfig();
    }

    /**
     * Smart Campaign Optimization
     */
    public function optimizeCampaigns(array $campaignIds = []): array
    {
        try {
            $results = [];

            // Get all campaigns if none specified
            if (empty($campaignIds)) {
                $campaignIds = $this->getAllActiveCampaignIds();
            }

            foreach ($campaignIds as $campaignId) {
                $optimization = $this->optimizeSingleCampaign($campaignId);
                $results[] = $optimization;

                // Apply optimizations
                if ($optimization['recommended_actions']) {
                    $this->applyCampaignOptimizations($campaignId, $optimization['recommended_actions']);
                }
            }

            return [
                'success' => true,
                'optimized_campaigns' => count($results),
                'results' => $results,
                'summary' => $this->generateOptimizationSummary($results)
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Dynamic Bid Management
     */
    public function manageBids(array $config = []): array
    {
        try {
            $rules = array_merge($this->config['bid_rules'], $config);
            $bidAdjustments = [];

            // Get all active campaigns and items
            $campaigns = $this->getActiveCampaignsWithItems();

            foreach ($campaigns as $campaign) {
                foreach ($campaign['items'] as $item) {
                    $adjustment = $this->calculateOptimalBid($item, $campaign, $rules);
                    if ($adjustment['adjust']) {
                        $bidAdjustments[] = $adjustment;
                        $this->applyBidAdjustment($item['id'], $adjustment);
                    }
                }
            }

            return [
                'success' => true,
                'total_adjustments' => count($bidAdjustments),
                'adjustments' => $bidAdjustments,
                'estimated_impact' => $this->estimateBidImpact($bidAdjustments)
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Advanced Targeting Setup
     */
    public function setupAdvancedTargeting(array $campaignConfig): array
    {
        try {
            $targeting = [
                'behavioral_audiences' => $this->createBehavioralAudiences(),
                'retargeting_campaigns' => $this->setupRetargeting($campaignConfig),
                'demographic_targeting' => $this->setupDemographicTargeting(),
                'geographic_targeting' => $this->setupGeographicTargeting($campaignConfig),
                'interest_based_targeting' => $this->setupInterestBasedTargeting()
            ];

            // Apply targeting configurations
            $results = [];
            foreach ($targeting as $type => $config) {
                $result = $this->applyTargetingConfiguration($type, $config);
                $results[$type] = $result;
            }

            return [
                'success' => true,
                'targeting_configurations' => $targeting,
                'results' => $results,
                'estimated_reach' => $this->calculateTotalReach($targeting)
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cross-Product Upselling
     */
    public function setupCrossProductUpselling(array $baseProducts): array
    {
        try {
            $upsellCampaigns = [];

            foreach ($baseProducts as $product) {
                $relatedProducts = $this->findRelatedProducts($product);
                $upsellConfig = $this->generateUpsellConfiguration($product, $relatedProducts);

                $campaign = $this->createUpsellCampaign($upsellConfig);
                if ($campaign['success']) {
                    $upsellCampaigns[] = [
                        'base_product' => $product,
                        'related_products' => $relatedProducts,
                        'campaign_id' => $campaign['campaign_id'],
                        'estimated_conversion_lift' => $upsellConfig['conversion_lift']
                    ];
                }
            }

            return [
                'success' => true,
                'upsell_campaigns' => count($upsellCampaigns),
                'campaigns' => $upsellCampaigns,
                'total_estimated_lift' => array_sum(array_column($upsellCampaigns, 'estimated_conversion_lift'))
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Budget Automation
     */
    public function automateBudgets(array $budgetRules = []): array
    {
        try {
            $rules = array_merge($this->config['budget_rules'], $budgetRules);
            $budgetChanges = [];

            $campaigns = $this->getAllCampaignsWithMetrics();

            foreach ($campaigns as $campaign) {
                $newBudget = $this->calculateOptimalBudget($campaign, $rules);

                if ($newBudget['adjust']) {
                    $budgetChanges[] = $newBudget;
                    $this->applyBudgetChange($campaign['id'], $newBudget);
                }
            }

            return [
                'success' => true,
                'budget_changes' => count($budgetChanges),
                'changes' => $budgetChanges,
                'total_budget_reallocated' => array_sum(array_column($budgetChanges, 'amount_change'))
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Performance Analytics Dashboard
     */
    public function getPerformanceAnalytics(array $filters = []): array
    {
        try {
            $analytics = [
                'overview' => $this->getPerformanceOverview($filters),
                'campaign_performance' => $this->getCampaignPerformance($filters),
                'keyword_performance' => $this->getKeywordPerformance($filters),
                'audience_performance' => $this->getAudiencePerformance($filters),
                'roi_analysis' => $this->getROIAnalysis($filters),
                'optimization_opportunities' => $this->getOptimizationOpportunities($filters),
                'competitor_ad_analysis' => $this->getCompetitorAdAnalysis($filters)
            ];

            return [
                'success' => true,
                'analytics' => $analytics,
                'generated_at' => time(),
                'data_freshness' => $this->getDataFreshness()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Optimize single campaign
     */
    private function optimizeSingleCampaign(string $campaignId): array
    {
        // Get campaign performance data
        $performance = $this->getCampaignPerformanceData($campaignId);

        // Analyze performance patterns
        $analysis = $this->analyzeCampaignPerformance($performance);

        // Generate recommendations
        $recommendations = $this->generateCampaignRecommendations($analysis);

        return [
            'campaign_id' => $campaignId,
            'current_performance' => $performance,
            'analysis' => $analysis,
            'recommended_actions' => $recommendations,
            'estimated_improvement' => $this->estimateImprovement($recommendations),
            'confidence_score' => $this->calculateRecommendationConfidence($analysis, $recommendations)
        ];
    }

    /**
     * Calculate optimal bid
     */
    private function calculateOptimalBid(array $item, array $campaign, array $rules): array
    {
        $currentBid = $item['current_bid'] ?? 0;
        $performance = $this->getItemBidPerformance($item['id']);

        // Calculate bid adjustment factors
        $factors = [
            'conversion_rate' => $this->calculateConversionRateFactor($performance),
            'competition_level' => $this->getCompetitionLevel($item['id']),
            'position_preference' => $rules['target_position'] ?? 'middle',
            'budget_utilization' => $this->getBudgetUtilization($campaign['id']),
            'time_of_day' => $this->getTimeOfDayFactor(),
            'day_of_week' => $this->getDayOfWeekFactor()
        ];

        $adjustmentPercentage = $this->calculateBidAdjustment($factors);
        $newBid = $currentBid * (1 + $adjustmentPercentage);

        // Apply bid limits
        $newBid = max($rules['min_bid'] ?? 0.01, $newBid);
        $newBid = min($rules['max_bid'] ?? 10.00, $newBid);

        return [
            'item_id' => $item['id'],
            'current_bid' => $currentBid,
            'recommended_bid' => round($newBid, 2),
            'adjustment_percentage' => round($adjustmentPercentage * 100, 2),
            'adjust' => abs($adjustmentPercentage) > ($rules['threshold'] ?? 0.05),
            'factors' => $factors
        ];
    }

    /**
     * Create behavioral audiences
     */
    private function createBehavioralAudiences(): array
    {
        return [
            'cart_abandoners' => [
                'name' => 'Usuários que abandonaram carrinho',
                'criteria' => ['cart_add_event', 'no_purchase_in_7d'],
                'estimated_size' => $this->estimateAudienceSize('cart_abandoners'),
                'conversion_rate' => 0.15
            ],
            'recent_viewers' => [
                'name' => 'Visualizou produtos recentemente',
                'criteria' => ['product_view_event', 'within_3d'],
                'estimated_size' => $this->estimateAudienceSize('recent_viewers'),
                'conversion_rate' => 0.08
            ],
            'high_value_customers' => [
                'name' => 'Clientes de alto valor',
                'criteria' => ['total_spent_gt_500', 'orders_gt_5'],
                'estimated_size' => $this->estimateAudienceSize('high_value'),
                'conversion_rate' => 0.25
            ],
            'seasonal_shoppers' => [
                'name' => 'Compradores sazonais',
                'criteria' => ['seasonal_purchase_pattern'],
                'estimated_size' => $this->estimateAudienceSize('seasonal'),
                'conversion_rate' => 0.12
            ]
        ];
    }

    /**
     * Setup retargeting campaigns
     */
    private function setupRetargeting(array $campaignConfig): array
    {
        return [
            'product_view_retargeting' => [
                'name' => 'Remarketing Visualização Produto',
                'trigger' => 'product_page_view',
                'delay_hours' => 2,
                'duration_days' => 14,
                'frequency_cap' => 3,
                'bid_adjustment' => 1.25
            ],
            'cart_abandon_retargeting' => [
                'name' => 'Remarketing Carrinho Abandonado',
                'trigger' => 'cart_add_no_purchase',
                'delay_hours' => 1,
                'duration_days' => 7,
                'frequency_cap' => 5,
                'bid_adjustment' => 1.40
            ],
            'purchase_cross_sell' => [
                'name' => 'Cross-sell Pós-Compra',
                'trigger' => 'purchase_completed',
                'delay_hours' => 24,
                'duration_days' => 30,
                'exclude_purchased_items' => true,
                'bid_adjustment' => 1.15
            ]
        ];
    }

    /**
     * Find related products for upselling
     */
    private function findRelatedProducts(array $product): array
    {
        // Get related products based on various criteria
        $related = [
            'category_products' => $this->getCategoryProducts($product['category_id'], $product['id']),
            'frequently_bought_together' => $this->getFrequentlyBoughtTogether($product['id']),
            'complementary_products' => $this->getComplementaryProducts($product['id']),
            'upgrade_products' => $this->getUpgradeProducts($product['id'])
        ];

        // Score and rank related products
        $scoredProducts = [];
        foreach ($related as $type => $products) {
            foreach ($products as $relatedProduct) {
                $score = $this->calculateRelatedProductScore($product, $relatedProduct, $type);
                $scoredProducts[] = array_merge($relatedProduct, [
                    'relation_type' => $type,
                    'score' => $score
                ]);
            }
        }

        // Sort by score and return top related products
        usort($scoredProducts, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scoredProducts, 0, 10);
    }

    /**
     * Get all active campaign IDs
     */
    private function getAllActiveCampaignIds(): array
    {
        $stmt = $this->db->prepare("
            SELECT campaign_id FROM ml_ad_campaigns
            WHERE account_id = :account_id AND status = 'active'
        ");

        $stmt->execute(['account_id' => $this->accountId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Get campaign performance data
     */
    private function getCampaignPerformanceData(string $campaignId): array
    {
        $cacheKey = "campaign_performance_{$campaignId}_{$this->accountId}";
        $data = $this->cache->get($cacheKey);

        if (!$data) {
            $stmt = $this->db->prepare("
                SELECT
                    c.*,
                    COUNT(DISTINCT i.item_id) as total_items,
                    SUM(i.impressions) as total_impressions,
                    SUM(i.clicks) as total_clicks,
                    SUM(i.cost) as total_cost,
                    SUM(i.conversions) as total_conversions,
                    AVG(i.position) as avg_position
                FROM ml_ad_campaigns c
                LEFT JOIN ml_ad_items i ON c.campaign_id = i.campaign_id
                WHERE c.campaign_id = :campaign_id AND c.account_id = :account_id
                GROUP BY c.campaign_id
            ");

            $stmt->execute(['campaign_id' => $campaignId, 'account_id' => $this->accountId]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Cache for 1 hour
            $this->cache->set($cacheKey, $data, 3600);
        }

        return $data ?: [];
    }

    /**
     * Load ads configuration
     */
    private function loadAdsConfig(): array
    {
        return [
            'bid_rules' => [
                'min_bid' => 0.01,
                'max_bid' => 10.00,
                'target_position' => 'middle',
                'threshold' => 0.05
            ],
            'budget_rules' => [
                'min_daily_budget' => 5.00,
                'max_daily_budget' => 1000.00,
                'reallocation_enabled' => true,
                'performance_threshold' => 2.0
            ],
            'targeting_rules' => [
                'min_audience_size' => 1000,
                'max_retention_days' => 30,
                'frequency_cap_default' => 3
            ]
        ];
    }

    // ─── Missing methods called from public ────────────────────────────

    private function getActiveCampaignsWithItems(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.campaign_id, c.campaign_name, c.status, c.daily_budget
                FROM ml_ad_campaigns_advanced c
                WHERE c.account_id = :account_id AND c.status = 'active'
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $campaigns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($campaigns as &$camp) {
                $stmt2 = $this->db->prepare("
                    SELECT item_id AS id, current_bid, optimal_bid, target_position,
                           conversion_rate, impressions, clicks, cost, revenue, roas
                    FROM ml_ad_items_advanced
                    WHERE campaign_id = :cid
                ");
                $stmt2->execute(['cid' => $camp['campaign_id']]);
                $camp['items'] = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
            }
            return $campaigns;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getAllCampaignsWithMetrics(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.campaign_id AS id, c.campaign_name, c.status,
                       c.daily_budget, c.total_budget,
                       COALESCE(SUM(p.impressions),0) AS impressions,
                       COALESCE(SUM(p.clicks),0) AS clicks,
                       COALESCE(SUM(p.cost),0) AS cost,
                       COALESCE(SUM(p.revenue),0) AS revenue,
                       COALESCE(SUM(p.conversions),0) AS conversions
                FROM ml_ad_campaigns_advanced c
                LEFT JOIN ml_ad_performance p ON p.campaign_id = c.campaign_id
                    AND p.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                WHERE c.account_id = :account_id
                GROUP BY c.campaign_id
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['roas'] = (float)($r['cost'] ?? 0) > 0
                    ? round((float)$r['revenue'] / (float)$r['cost'], 2) : 0;
                $r['ctr'] = (int)($r['impressions'] ?? 0) > 0
                    ? round((int)$r['clicks'] / (int)$r['impressions'] * 100, 2) : 0;
            }
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function calculateOptimalBudget(array $campaign, array $rules): array
    {
        $current = (float)($campaign['daily_budget'] ?? 0);
        $roas    = (float)($campaign['roas'] ?? 0);
        $cost    = (float)($campaign['cost'] ?? 0);
        $revenue = (float)($campaign['revenue'] ?? 0);

        $threshold = $rules['performance_threshold'] ?? 2.0;
        $newBudget = $current;

        if ($roas >= $threshold * 1.5) {
            $newBudget = $current * 1.20;
        } elseif ($roas >= $threshold) {
            $newBudget = $current * 1.10;
        } elseif ($roas > 0 && $roas < $threshold * 0.5) {
            $newBudget = $current * 0.75;
        } elseif ($roas > 0 && $roas < $threshold) {
            $newBudget = $current * 0.90;
        }

        $min = $rules['min_daily_budget'] ?? 5.0;
        $max = $rules['max_daily_budget'] ?? 1000.0;
        $newBudget = max($min, min($max, $newBudget));

        return [
            'campaign_id' => $campaign['id'] ?? '',
            'current_budget' => $current,
            'recommended_budget' => round($newBudget, 2),
            'amount_change' => round($newBudget - $current, 2),
            'adjust' => abs($newBudget - $current) > 0.50,
            'roas' => $roas,
            'reason' => $roas >= $threshold ? 'high_performance' : ($roas > 0 ? 'underperforming' : 'no_data')
        ];
    }

    // ─── Campaign optimization helpers ──────────────────────────────────

    private function applyCampaignOptimizations(string $campaignId, array $actions): void
    {
        try {
            foreach ($actions as $action) {
                $type = $action['type'] ?? '';
                switch ($type) {
                    case 'adjust_bid':
                        $this->applyBidAdjustment($action['item_id'] ?? '', $action);
                        break;
                    case 'adjust_budget':
                        $this->applyBudgetChange($campaignId, $action);
                        break;
                    case 'pause_item':
                        $this->db->prepare("
                            UPDATE ml_ad_items_advanced SET current_bid = 0
                            WHERE campaign_id = :cid AND item_id = :iid
                        ")->execute(['cid' => $campaignId, 'iid' => $action['item_id'] ?? '']);
                        break;
                }
            }
        } catch (\Exception $e) {
            // non-critical
        }
    }

    private function generateOptimizationSummary(array $results): array
    {
        $totalRecs = 0;
        $applied = 0;
        $estImprovements = [];
        $optimized = 0;
        foreach ($results as $r) {
            $recs = $r['recommended_actions'] ?? [];
            $totalRecs += count($recs);
            $applied += count(array_filter($recs, fn(array $a): bool => ($a['applied'] ?? false)));
            $estImprovements[] = $r['estimated_improvement']['revenue_lift_pct'] ?? 0;
            if (($r['status'] ?? '') === 'optimized') {
                $optimized++;
            }
            if (isset($r['actions_applied'])) {
                $applied += (int)$r['actions_applied'];
            }
        }

        $totalCampaigns = count($results);

        return [
            // Campos legados esperados por testes/módulos existentes
            'total_campaigns' => $totalCampaigns,
            'optimized' => $optimized,
            'actions_applied' => $applied,
            // Campos atuais detalhados
            'campaigns_analyzed' => count($results),
            'total_recommendations' => $totalRecs,
            'applied' => $applied,
            'avg_confidence' => count($results) > 0
                ? round(array_sum(array_column($results, 'confidence_score')) / count($results), 2) : 0,
            'avg_revenue_lift_pct' => !empty($estImprovements) ? round(array_sum($estImprovements) / count($estImprovements), 2) : 0,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    private function analyzeCampaignPerformance(array $performance): array
    {
        $impressions = (int)($performance['total_impressions'] ?? $performance['impressions'] ?? 0);
        $clicks      = (int)($performance['total_clicks'] ?? $performance['clicks'] ?? 0);
        $cost        = (float)($performance['total_cost'] ?? $performance['cost'] ?? 0);
        $revenue     = (float)($performance['total_revenue'] ?? $performance['revenue'] ?? 0);
        $conversions = (int)($performance['total_conversions'] ?? $performance['conversions'] ?? 0);

        $ctr = $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0;
        $cvr = $clicks > 0 ? round($conversions / $clicks * 100, 2) : 0;
        $cpc = $clicks > 0 ? round($cost / $clicks, 2) : 0;
        $cpa = $conversions > 0 ? round($cost / $conversions, 2) : 0;
        $roas = $cost > 0 ? round($revenue / $cost, 2) : 0;

        $performanceGrade = $ctr >= 2.0 && $cvr >= 5.0 ? 'excellent'
            : ($ctr >= 1.0 && $cvr >= 2.0 ? 'good'
            : ($ctr > 0.3 ? 'average' : 'poor'));

        return [
            'ctr' => $ctr,
            'cvr' => $cvr,
            'cpc' => $cpc,
            'cpa' => $cpa,
            'roas' => $roas,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'cost' => $cost,
            'revenue' => $revenue,
            'conversions' => $conversions,
            'performance_grade' => $performanceGrade,
            'performance_rating' => $performanceGrade,
            'bottleneck' => $ctr < 0.5 ? 'low_ctr' : ($cvr < 1.0 ? 'low_conversion' : 'none')
        ];
    }

    private function generateCampaignRecommendations(array $analysis): array
    {
        $recs = [];

        if ($analysis['ctr'] < 0.5) {
            $recs[] = ['type' => 'improve_creative', 'priority' => 'high', 'description' => 'CTR muito baixo — melhorar títulos e imagens dos anúncios'];
        }
        if ($analysis['cvr'] < 1.0 && $analysis['clicks'] > 50) {
            $recs[] = ['type' => 'optimize_landing', 'priority' => 'high', 'description' => 'Taxa de conversão baixa — otimizar página do produto'];
        }
        if ($analysis['cpc'] > 2.0) {
            $recs[] = ['type' => 'adjust_bid', 'priority' => 'medium', 'description' => 'CPC elevado — reduzir lances ou segmentar melhor'];
        }
        if ($analysis['cpa'] > 50.0 && $analysis['conversions'] > 0) {
            $recs[] = ['type' => 'adjust_budget', 'priority' => 'medium', 'description' => 'Custo por aquisição alto — reavaliar campanhas'];
        }
        if ($analysis['performance_grade'] === 'excellent') {
            $recs[] = ['type' => 'scale_budget', 'priority' => 'low', 'description' => 'Performance excelente — considerar aumento de orçamento'];
        }

        return $recs;
    }

    private function estimateImprovement(array $recommendations): array
    {
        $revLift = 0;
        $costSavings = 0;
        foreach ($recommendations as $r) {
            $type = $r['type'] ?? '';
            $revLift += match ($type) {
                'improve_creative' => 5.0,
                'optimize_landing' => 8.0,
                'scale_budget' => 12.0,
                default => 2.0
            };
            $costSavings += match ($type) {
                'adjust_bid' => 10.0,
                'adjust_budget' => 15.0,
                default => 0
            };
        }
        $result = [
            'revenue_lift_pct' => round($revLift, 1),
            'cost_savings_pct' => round($costSavings, 1),
            'confidence' => min(0.90, 0.5 + count($recommendations) * 0.1)
        ];

        $result['estimated_improvement'] = [
            'revenue_lift_pct' => $result['revenue_lift_pct'],
            'cost_savings_pct' => $result['cost_savings_pct'],
        ];

        return $result;
    }

    private function calculateRecommendationConfidence(array $analysis, array $recommendations): float
    {
        $base = 0.5;
        if ((int)($analysis['impressions'] ?? 0) > 1000) $base += 0.1;
        if ((int)($analysis['clicks'] ?? 0) > 100) $base += 0.1;
        if ((int)($analysis['conversions'] ?? 0) > 10) $base += 0.1;
        if (!empty($recommendations)) $base += 0.05;
        return round(min(0.95, $base), 2);
    }

    // ─── Bid management helpers ─────────────────────────────────────────

    private function applyBidAdjustment(string $itemId, array $adjustment): void
    {
        if (empty($itemId)) return;
        try {
            $this->db->prepare("
                UPDATE ml_ad_items_advanced
                SET current_bid = :bid, bid_adjustment_percentage = :pct, last_updated = NOW()
                WHERE item_id = :item_id
            ")->execute([
                'bid' => $adjustment['recommended_bid'] ?? $adjustment['new_bid'] ?? 0,
                'pct' => $adjustment['adjustment_percentage'] ?? 0,
                'item_id' => $itemId
            ]);
        } catch (\Exception $e) {
            // non-critical
        }
    }

    private function estimateBidImpact(array $adjustments): array
    {
        $totalIncrease = 0;
        $totalDecrease = 0;
        foreach ($adjustments as $a) {
            $p = (float)($a['adjustment_percentage'] ?? 0);
            if ($p > 0) $totalIncrease += $p;
            else $totalDecrease += abs($p);
        }
        $summary = [
            'total_adjustments' => count($adjustments),
            'avg_change_pct' => count($adjustments) > 0
                ? round(array_sum(array_column($adjustments, 'adjustment_percentage')) / count($adjustments), 2) : 0,
            'bid_increases' => count(array_filter($adjustments, fn(array $a): bool => ($a['adjustment_percentage'] ?? 0) > 0)),
            'bid_decreases' => count(array_filter($adjustments, fn(array $a): bool => ($a['adjustment_percentage'] ?? 0) < 0)),
            'estimated_cost_change_pct' => round(($totalIncrease - $totalDecrease) / max(1, count($adjustments)), 2)
        ];

        $summary['total_items_adjusted'] = $summary['total_adjustments'];

        return $summary;
    }

    private function getItemBidPerformance(string $itemId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT item_id, current_bid, optimal_bid, conversion_rate,
                       impressions, clicks, cost, revenue, roas, optimization_score
                FROM ml_ad_items_advanced
                WHERE item_id = :item_id
            ");
            $stmt->execute(['item_id' => $itemId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function calculateConversionRateFactor(array $performance): float
    {
        if (array_key_exists('conversion_rate', $performance)) {
            $cvr = (float)$performance['conversion_rate'];
        } else {
            $clicks = (float)($performance['clicks'] ?? 0);
            $conversions = (float)($performance['conversions'] ?? 0);
            $cvr = $clicks > 0 ? ($conversions / $clicks) : 0.0;
        }

        // Compatibilidade: aceitar CVR em porcentagem (ex.: 15) ou razão (ex.: 0.15)
        if ($cvr > 1.0) {
            $cvr = $cvr / 100;
        }

        if ($cvr >= 0.10) return 1.3;
        if ($cvr >= 0.05) return 1.15;
        if ($cvr >= 0.02) return 1.0;
        if ($cvr > 0)     return 0.85;
        return 0.7;
    }

    private function getCompetitionLevel(string $itemId): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT category_id FROM ml_items WHERE id = :id AND account_id = :aid
            ");
            $stmt->execute(['id' => $itemId, 'aid' => $this->accountId]);
            $cat = $stmt->fetchColumn();
            if (!$cat) return 0.5;

            $stmt2 = $this->db->prepare("
                SELECT COUNT(*) FROM competitor_items
                WHERE account_id = :aid AND category_id = :cat
            ");
            $stmt2->execute(['aid' => $this->accountId, 'cat' => $cat]);
            $count = (int)$stmt2->fetchColumn();

            if ($count > 20) return 0.9;
            if ($count > 10) return 0.7;
            if ($count > 3) return 0.5;
            return 0.3;
        } catch (\Exception $e) {
            return 0.5;
        }
    }

    private function getBudgetUtilization(string $campaignId): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.daily_budget, COALESCE(SUM(p.cost),0) AS today_cost
                FROM ml_ad_campaigns_advanced c
                LEFT JOIN ml_ad_performance p ON p.campaign_id = c.campaign_id AND p.date = CURDATE()
                WHERE c.campaign_id = :cid
                GROUP BY c.campaign_id
            ");
            $stmt->execute(['cid' => $campaignId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $budget = (float)($row['daily_budget'] ?? 0);
            $spent  = (float)($row['today_cost'] ?? 0);
            return $budget > 0 ? round(min(1.0, $spent / $budget), 2) : 0;
        } catch (\Exception $e) {
            return 0.5;
        }
    }

    private function getTimeOfDayFactor(): float
    {
        $h = (int)date('G');
        if ($h >= 18 && $h <= 22) return 1.15;
        if ($h >= 10 && $h <= 14) return 1.10;
        if ($h >= 2 && $h <= 6)   return 0.80;
        return 1.0;
    }

    private function getDayOfWeekFactor(): float
    {
        $d = (int)date('w');
        if ($d === 0) return 1.10;
        if ($d === 6) return 1.05;
        if ($d === 1) return 0.95;
        return 1.0;
    }

    private function calculateBidAdjustment(array $factors): float
    {
        $weights = [
            'conversion_rate' => 0.30,
            'competition_level' => 0.20,
            'budget_utilization' => 0.15,
            'time_of_day' => 0.10,
            'day_of_week' => 0.10
        ];

        $adj = 0.0;
        $cvr = (float)($factors['conversion_rate'] ?? 1.0);
        $adj += ($cvr - 1.0) * ($weights['conversion_rate']);

        $comp = (float)($factors['competition_level'] ?? 0.5);
        $adj += ($comp - 0.5) * 0.1 * ($weights['competition_level']);

        $budgetUtil = (float)($factors['budget_utilization'] ?? 0.7);
        if ($budgetUtil > 0.9) $adj -= 0.05;
        elseif ($budgetUtil < 0.3) $adj += 0.05;

        $tod = (float)($factors['time_of_day'] ?? 1.0);
        $dow = (float)($factors['day_of_week'] ?? 1.0);
        $adj += ($tod - 1.0) * $weights['time_of_day'];
        $adj += ($dow - 1.0) * $weights['day_of_week'];

        return round(max(-0.30, min(0.30, $adj)), 4);
    }

    // ─── Budget helpers ─────────────────────────────────────────────────

    private function applyBudgetChange(string $campaignId, array $change): void
    {
        if (empty($campaignId)) return;
        try {
            $this->db->prepare("
                UPDATE ml_ad_campaigns_advanced
                SET daily_budget = :budget, updated_at = NOW()
                WHERE campaign_id = :cid AND account_id = :aid
            ")->execute([
                'budget' => $change['recommended_budget'] ?? $change['new_budget'] ?? 0,
                'cid' => $campaignId,
                'aid' => $this->accountId
            ]);
        } catch (\Exception $e) {
            // non-critical
        }
    }

    // ─── Targeting helpers ──────────────────────────────────────────────

    private function applyTargetingConfiguration(string $type, array $config): array
    {
        try {
            $campaignId = $config['campaign_id'] ?? ($this->getAllActiveCampaignIds()[0] ?? '');
            if (empty($campaignId)) {
                return ['applied' => false, 'reason' => 'no_active_campaign'];
            }

            $this->db->prepare("
                INSERT INTO ml_ad_targeting (campaign_id, targeting_type, targeting_data, audience_size)
                VALUES (:cid, :type, :data, :size)
                ON DUPLICATE KEY UPDATE targeting_data = :data2, audience_size = :size2
            ")->execute([
                'cid' => $campaignId,
                'type' => $type,
                'data' => json_encode($config),
                'size' => $config['estimated_size'] ?? 0,
                'data2' => json_encode($config),
                'size2' => $config['estimated_size'] ?? 0
            ]);

            return ['applied' => true, 'type' => $type, 'campaign_id' => $campaignId];
        } catch (\Exception $e) {
            return ['applied' => false, 'error' => $e->getMessage()];
        }
    }

    private function calculateTotalReach(array $targeting): int
    {
        $total = 0;
        foreach ($targeting as $config) {
            if (!is_array($config)) {
                continue;
            }

            if (isset($config['estimated_size']) || isset($config['audience_size'])) {
                $total += (int)($config['estimated_size'] ?? $config['audience_size'] ?? 0);
                continue;
            }

            foreach ($config as $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                $total += (int)($segment['estimated_size'] ?? $segment['audience_size'] ?? 0);
            }
        }
        return $total;
    }

    private function estimateAudienceSize(string $type): int
    {
        try {
            return match ($type) {
                'cart_abandoners' => (function (): int {
                    $stmt = $this->db->prepare("
                        SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.buyer.id')))
                        FROM ml_orders
                        WHERE ml_account_id = :aid
                          AND status IN ('cancelled', 'pending')
                          AND date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ");
                    $stmt->execute(['aid' => $this->accountId]);
                    return (int)$stmt->fetchColumn();
                })(),
                'high_value' => (function (): int {
                    $stmt = $this->db->prepare("
                        SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.buyer.id')))
                        FROM ml_orders
                        WHERE ml_account_id = :aid
                          AND total_amount > 500
                    ");
                    $stmt->execute(['aid' => $this->accountId]);
                    return (int)$stmt->fetchColumn();
                })(),
                default => 1000
            };
        } catch (\Exception $e) {
            return match ($type) {
                'cart_abandoners' => 500,
                'recent_viewers' => 2000,
                'high_value' => 200,
                'seasonal' => 800,
                default => 1000
            };
        }
    }

    private function setupDemographicTargeting(): array
    {
        return [
            'type' => 'demographic',
            'age_groups' => [
                ['range' => '18-24', 'bid_modifier' => 0.90],
                ['range' => '25-34', 'bid_modifier' => 1.15],
                ['range' => '35-44', 'bid_modifier' => 1.10],
                ['range' => '45-54', 'bid_modifier' => 1.00],
                ['range' => '55+',   'bid_modifier' => 0.85],
            ],
            'gender' => ['all' => 1.0],
            'device' => [
                'mobile' => 1.10,
                'desktop' => 1.0,
                'tablet' => 0.90
            ]
        ];
    }

    private function setupGeographicTargeting(array $config): array
    {
        $topRegions = ['SP', 'RJ', 'MG', 'PR', 'RS'];
        $geo = [];
        foreach ($topRegions as $state) {
            $geo[$state] = [
                'state' => $state,
                'bid_modifier' => $state === 'SP' ? 1.15 : 1.0,
                'enabled' => true
            ];
        }
        return $geo;
    }

    private function setupInterestBasedTargeting(): array
    {
        return [
            'type' => 'interest_based',
            'in_market' => [
                'description' => 'Usuários pesquisando ativamente produtos similares',
                'bid_modifier' => 1.25,
                'estimated_size' => 5000
            ],
            'affinity' => [
                'description' => 'Usuários com afinidade com a categoria',
                'bid_modifier' => 1.10,
                'estimated_size' => 15000
            ],
            'custom_intent' => [
                'description' => 'Audiência personalizada por palavras-chave',
                'bid_modifier' => 1.20,
                'estimated_size' => 3000
            ]
        ];
    }

    // ─── Upselling helpers ──────────────────────────────────────────────

    private function getCategoryProducts(string $categoryId, string $excludeId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, title, price, category_id, sold_quantity
                FROM ml_items
                WHERE account_id = :aid AND category_id = :cat
                  AND id != :exclude AND status = 'active'
                ORDER BY sold_quantity DESC
                LIMIT 5
            ");
            $stmt->execute(['aid' => $this->accountId, 'cat' => $categoryId, 'exclude' => $excludeId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getFrequentlyBoughtTogether(string $productId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT oi2.item_id AS id, oi2.title, COUNT(*) AS co_purchase_count
                FROM order_items oi1
                JOIN order_items oi2 ON oi2.order_id = oi1.order_id AND oi2.item_id != oi1.item_id
                JOIN ml_orders o ON (oi1.order_id = o.id OR oi1.order_id = o.ml_order_id)
                WHERE oi1.item_id = :pid AND o.ml_account_id = :aid
                GROUP BY oi2.item_id
                ORDER BY co_purchase_count DESC
                LIMIT 5
            ");
            $stmt->execute(['pid' => $productId, 'aid' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getComplementaryProducts(string $productId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT category_id, price FROM ml_items
                WHERE id = :id AND account_id = :aid
            ");
            $stmt->execute(['id' => $productId, 'aid' => $this->accountId]);
            $product = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$product) return [];

            $stmt2 = $this->db->prepare("
                SELECT id, title, price, category_id, sold_quantity
                FROM ml_items
                WHERE account_id = :aid AND category_id != :cat AND status = 'active'
                  AND price BETWEEN :pmin AND :pmax
                ORDER BY sold_quantity DESC
                LIMIT 5
            ");
            $price = (float)$product['price'];
            $stmt2->execute([
                'aid' => $this->accountId,
                'cat' => $product['category_id'],
                'pmin' => $price * 0.2,
                'pmax' => $price * 0.8
            ]);
            return $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getUpgradeProducts(string $productId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT category_id, price FROM ml_items
                WHERE id = :id AND account_id = :aid
            ");
            $stmt->execute(['id' => $productId, 'aid' => $this->accountId]);
            $product = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$product) return [];

            $stmt2 = $this->db->prepare("
                SELECT id, title, price, category_id, sold_quantity
                FROM ml_items
                WHERE account_id = :aid AND category_id = :cat AND status = 'active'
                  AND price > :price AND id != :id
                ORDER BY price ASC
                LIMIT 3
            ");
            $stmt2->execute([
                'aid' => $this->accountId,
                'cat' => $product['category_id'],
                'price' => $product['price'],
                'id' => $productId
            ]);
            return $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function calculateRelatedProductScore(array $product, array $relatedProduct, string $type): float
    {
        $score = 0.0;

        // Type weights
        $score += match ($type) {
            'frequently_bought_together' => 4.0,
            'complementary_products'     => 3.0,
            'upgrade_products'           => 2.5,
            'category_products'          => 1.5,
            default => 1.0
        };

        // Sales volume bonus
        $sold = (int)($relatedProduct['sold_quantity'] ?? 0);
        if ($sold > 50) $score += 2.0;
        elseif ($sold > 10) $score += 1.0;

        // Price proximity bonus
        $priceDiff = abs((float)($product['price'] ?? 0) - (float)($relatedProduct['price'] ?? 0));
        $avgPrice = ((float)($product['price'] ?? 0) + (float)($relatedProduct['price'] ?? 0)) / 2;
        if ($avgPrice > 0 && $priceDiff / $avgPrice < 0.3) $score += 1.0;

        // Co-purchase count
        $coPurchase = (int)($relatedProduct['co_purchase_count'] ?? 0);
        $score += min(3.0, $coPurchase * 0.5);

        return round($score, 2);
    }

    private function generateUpsellConfiguration(array $product, array $relatedProducts): array
    {
        $upsellItems = array_slice($relatedProducts, 0, 5);
        $avgScore = !empty($upsellItems)
            ? array_sum(array_column($upsellItems, 'score')) / count($upsellItems) : 0;

        return [
            // Chaves legadas para compatibilidade de testes/módulos antigos
            'base_product' => $product['id'] ?? '',
            'upsell_products' => $upsellItems,
            // Chaves internas atuais
            'base_product_id' => $product['id'] ?? '',
            'upsell_items' => array_column($upsellItems, 'id'),
            'strategy' => count($upsellItems) > 0 ? 'related_products' : 'none',
            'bid_modifier' => 1.10,
            'conversion_lift' => round(min(15, $avgScore * 1.5), 2),
            'daily_budget' => 10.0,
            'max_impressions' => 5000
        ];
    }

    private function createUpsellCampaign(array $config): array
    {
        try {
            if (empty($config['upsell_items'])) {
                return ['success' => false, 'reason' => 'no_upsell_items'];
            }

            $campaignId = 'upsell_' . uniqid();
            $this->db->prepare("
                INSERT INTO ml_ad_campaigns_advanced
                    (account_id, campaign_id, campaign_name, status, daily_budget, optimization_level, auto_optimization)
                VALUES (:aid, :cid, :name, 'active', :budget, 'advanced', 1)
            ")->execute([
                'aid' => $this->accountId,
                'cid' => $campaignId,
                'name' => 'Upsell - ' . ($config['base_product_id'] ?? ''),
                'budget' => $config['daily_budget'] ?? 10.0
            ]);

            foreach ($config['upsell_items'] as $itemId) {
                $this->db->prepare("
                    INSERT INTO ml_ad_items_advanced (campaign_id, item_id, current_bid, target_position)
                    VALUES (:cid, :iid, :bid, 'middle')
                ")->execute([
                    'cid' => $campaignId,
                    'iid' => $itemId,
                    'bid' => 0.50
                ]);
            }

            return ['success' => true, 'campaign_id' => $campaignId];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Analytics helpers ──────────────────────────────────────────────

    private function getPerformanceOverview(array $filters): array
    {
        try {
            $days = match ($filters['period'] ?? 'last_30_days') {
                'last_7_days' => 7, 'last_90_days' => 90, default => 30
            };
            $since = date('Y-m-d', strtotime("-{$days} days"));

            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT c.campaign_id) AS total_campaigns,
                       SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_campaigns,
                       COALESCE(SUM(p.impressions),0) AS total_impressions,
                       COALESCE(SUM(p.clicks),0) AS total_clicks,
                       COALESCE(SUM(p.cost),0) AS total_cost,
                       COALESCE(SUM(p.revenue),0) AS total_revenue,
                       COALESCE(SUM(p.conversions),0) AS total_conversions
                FROM ml_ad_campaigns_advanced c
                LEFT JOIN ml_ad_performance p ON p.campaign_id = c.campaign_id AND p.date >= :since
                WHERE c.account_id = :aid
            ");
            $stmt->execute(['aid' => $this->accountId, 'since' => $since]);
            $o = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $o['roas'] = (float)($o['total_cost'] ?? 0) > 0
                ? round((float)$o['total_revenue'] / (float)$o['total_cost'], 2) : 0;
            $o['ctr'] = (int)($o['total_impressions'] ?? 0) > 0
                ? round((int)$o['total_clicks'] / (int)$o['total_impressions'] * 100, 2) : 0;
            $o['period'] = $filters['period'] ?? 'last_30_days';
            return $o;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getCampaignPerformance(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.campaign_id, c.campaign_name, c.status, c.daily_budget,
                       COALESCE(SUM(p.impressions),0) AS impressions,
                       COALESCE(SUM(p.clicks),0) AS clicks,
                       COALESCE(SUM(p.cost),0) AS cost,
                       COALESCE(SUM(p.revenue),0) AS revenue,
                       COALESCE(SUM(p.conversions),0) AS conversions
                FROM ml_ad_campaigns_advanced c
                LEFT JOIN ml_ad_performance p ON p.campaign_id = c.campaign_id
                    AND p.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                WHERE c.account_id = :aid
                GROUP BY c.campaign_id
                ORDER BY revenue DESC
            ");
            $stmt->execute(['aid' => $this->accountId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['roas'] = (float)($r['cost'] ?? 0) > 0 ? round((float)$r['revenue'] / (float)$r['cost'], 2) : 0;
            }
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getKeywordPerformance(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT ai.item_id, mi.title,
                       ai.impressions, ai.clicks, ai.cost, ai.revenue, ai.roas,
                       ai.conversion_rate
                FROM ml_ad_items_advanced ai
                JOIN ml_ad_campaigns_advanced c ON c.campaign_id = ai.campaign_id
                LEFT JOIN ml_items mi ON mi.id = ai.item_id
                WHERE c.account_id = :aid
                ORDER BY ai.roas DESC
                LIMIT 20
            ");
            $stmt->execute(['aid' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getAudiencePerformance(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT t.targeting_type, t.audience_size,
                       COUNT(t.id) AS segments
                FROM ml_ad_targeting t
                JOIN ml_ad_campaigns_advanced c ON c.campaign_id = t.campaign_id
                WHERE c.account_id = :aid
                GROUP BY t.targeting_type
            ");
            $stmt->execute(['aid' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getROIAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.campaign_id, c.campaign_name,
                       SUM(p.cost) AS total_cost,
                       SUM(p.revenue) AS total_revenue,
                       SUM(p.revenue) - SUM(p.cost) AS profit,
                       CASE WHEN SUM(p.cost) > 0
                            THEN ROUND(SUM(p.revenue)/SUM(p.cost), 2) ELSE 0
                       END AS roas
                FROM ml_ad_campaigns_advanced c
                JOIN ml_ad_performance p ON p.campaign_id = c.campaign_id
                WHERE c.account_id = :aid
                GROUP BY c.campaign_id
                ORDER BY profit DESC
            ");
            $stmt->execute(['aid' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getOptimizationOpportunities(array $filters): array
    {
        try {
            $opportunities = [];

            // Low ROAS campaigns
            $stmt = $this->db->prepare("
                SELECT c.campaign_id, c.campaign_name, c.daily_budget,
                       COALESCE(SUM(p.cost),0) AS cost, COALESCE(SUM(p.revenue),0) AS revenue
                FROM ml_ad_campaigns_advanced c
                LEFT JOIN ml_ad_performance p ON p.campaign_id = c.campaign_id
                    AND p.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                WHERE c.account_id = :aid AND c.status = 'active'
                GROUP BY c.campaign_id
                HAVING cost > 0 AND (revenue / cost) < 1.5
            ");
            $stmt->execute(['aid' => $this->accountId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $opportunities[] = [
                    'type' => 'low_roas',
                    'campaign_id' => $row['campaign_id'],
                    'campaign_name' => $row['campaign_name'],
                    'current_roas' => (float)$row['cost'] > 0 ? round((float)$row['revenue'] / (float)$row['cost'], 2) : 0,
                    'suggestion' => 'Reduzir orçamento ou otimizar segmentação',
                    'priority' => 'high'
                ];
            }

            // High performing underbudgeted
            $stmt2 = $this->db->prepare("
                SELECT c.campaign_id, c.campaign_name, c.daily_budget,
                       COALESCE(SUM(p.cost),0) AS cost, COALESCE(SUM(p.revenue),0) AS revenue
                FROM ml_ad_campaigns_advanced c
                LEFT JOIN ml_ad_performance p ON p.campaign_id = c.campaign_id
                    AND p.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                WHERE c.account_id = :aid AND c.status = 'active'
                GROUP BY c.campaign_id
                HAVING cost > 0 AND (revenue / cost) > 3.0 AND daily_budget < 50
            ");
            $stmt2->execute(['aid' => $this->accountId]);
            foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $opportunities[] = [
                    'type' => 'scale_opportunity',
                    'campaign_id' => $row['campaign_id'],
                    'campaign_name' => $row['campaign_name'],
                    'current_roas' => round((float)$row['revenue'] / max(1, (float)$row['cost']), 2),
                    'suggestion' => 'Performance excelente — aumentar orçamento diário',
                    'priority' => 'medium'
                ];
            }
            return $opportunities;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getCompetitorAdAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT category_id, COUNT(*) AS competitor_count,
                       AVG(price) AS avg_price, AVG(sold_quantity) AS avg_sales
                FROM competitor_items
                WHERE account_id = :aid AND status = 'active'
                GROUP BY category_id
                ORDER BY competitor_count DESC
                LIMIT 10
            ");
            $stmt->execute(['aid' => $this->accountId]);
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($categories as &$cat) {
                $cat['estimated_ad_spend'] = round((float)$cat['competitor_count'] * 5.0, 2);
                $cat['competition_level'] = (int)$cat['competitor_count'] > 10 ? 'high' : ((int)$cat['competitor_count'] > 3 ? 'medium' : 'low');
            }
            return $categories;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getDataFreshness(): string
    {
        if (!isset($this->db) || !isset($this->accountId)) {
            return 'unknown';
        }

        try {
            $stmt = $this->db->prepare("
                SELECT MAX(last_updated) AS latest
                FROM ml_ad_items_advanced ai
                JOIN ml_ad_campaigns_advanced c ON c.campaign_id = ai.campaign_id
                WHERE c.account_id = :aid
            ");
            $stmt->execute(['aid' => $this->accountId]);
            $latest = $stmt->fetchColumn();
            if (!$latest) return 'no_data';

            $diff = time() - strtotime($latest);
            if ($diff < 300) return 'less_than_5_min';
            if ($diff < 3600) return round($diff / 60) . '_minutes';
            return round($diff / 3600) . '_hours';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}
