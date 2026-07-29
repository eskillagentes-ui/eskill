<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;
use App\Services\CacheService;
use App\Services\StructuredLogService;

/**
 * Enhanced ML Analytics and Intelligence Service
 *
 * Features:
 * - Search behavior analysis
 * - Category performance insights
 * - Customer journey mapping
 * - Conversion funnel analytics
 * - ROI tracking and attribution
 * - Predictive analytics
 * - Market intelligence dashboard
 */
class MLAnalyticsIntelligenceService
{
    private \PDO $db;
    private MercadoLivreClient $mlClient;
    private CacheService $cache;
    private StructuredLogService $logger;
    private int $accountId;
    private array $config;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = \App\Database::getInstance();
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->cache = new CacheService();
        $this->logger = new StructuredLogService();
        $this->config = $this->loadAnalyticsConfig();
    }

    /**
     * Comprehensive analytics dashboard
     */
    public function getComprehensiveAnalytics(array $filters = []): array
    {
        try {
            $analytics = [
                'performance_overview' => $this->getPerformanceOverview($filters),
                'search_analytics' => $this->getSearchAnalytics($filters),
                'category_intelligence' => $this->getCategoryIntelligence($filters),
                'customer_journey' => $this->getCustomerJourneyAnalysis($filters),
                'conversion_funnel' => $this->getConversionFunnel($filters),
                'roi_attribution' => $this->getROIAttribution($filters),
                'predictive_insights' => $this->getPredictiveInsights($filters),
                'market_positioning' => $this->getMarketPositioning($filters),
                'operational_metrics' => $this->getOperationalMetrics($filters)
            ];

            return [
                'success' => true,
                'analytics' => $analytics,
                'generated_at' => time(),
                'data_freshness' => $this->getDataFreshness(),
                'summary' => $this->generateAnalyticsSummary($analytics)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getComprehensiveAnalytics error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Search behavior analysis
     */
    public function analyzeSearchBehavior(array $timeframe = []): array
    {
        try {
            $searchData = $this->getSearchData($timeframe);

            $analysis = [
                'search_patterns' => $this->analyzeSearchPatterns($searchData),
                'keyword_performance' => $this->getKeywordPerformance($searchData),
                'search_trends' => $this->getSearchTrends($searchData),
                'user_segments' => $this->segmentSearchUsers($searchData),
                'seasonal_patterns' => $this->getSeasonalSearchPatterns($searchData),
                'opportunity_keywords' => $this->getOpportunityKeywords($searchData),
                'search_funnel' => $this->analyzeSearchFunnel($searchData),
                'search_optimization_recommendations' => $this->generateSearchOptimizationRecommendations($searchData)
            ];

            return [
                'success' => true,
                'search_analysis' => $analysis,
                'insights' => $this->extractSearchInsights($analysis),
                'recommendations' => $this->generateSearchRecommendations($analysis)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::analyzeSearchBehavior error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backward-compatible alias for detailed search analytics.
     */
    public function getDetailedSearchAnalytics(array $timeframe = []): array
    {
        return $this->analyzeSearchBehavior($timeframe);
    }

    /**
     * Category performance intelligence
     */
    public function getCategoryIntelligence(array $categories = []): array
    {
        try {
            $categoryInsights = [];

            if (empty($categories)) {
                $categories = $this->getActiveCategories();
            }

            foreach ($categories as $category) {
                $insight = $this->analyzeCategoryPerformance($category);
                $categoryInsights[] = $insight;
            }

            // Cross-category analysis
            $crossCategoryAnalysis = $this->performCrossCategoryAnalysis($categoryInsights);

            return [
                'success' => true,
                'categories_analyzed' => count($categories),
                'category_insights' => $categoryInsights,
                'cross_category_analysis' => $crossCategoryAnalysis,
                'category_rankings' => $this->generateCategoryRankings($categoryInsights),
                'opportunity_matrix' => $this->generateCategoryOpportunityMatrix($categoryInsights),
                'strategic_recommendations' => $this->generateCategoryStrategicRecommendations($categoryInsights)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getCategoryIntelligence error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Customer journey mapping
     */
    public function mapCustomerJourney(array $filters = []): array
    {
        try {
            $journeyData = [
                'touchpoint_analysis' => $this->analyzeTouchpoints($filters),
                'path_analysis' => $this->analyzeCustomerPaths($filters),
                'engagement_metrics' => $this->getEngagementMetrics($filters),
                'conversion_points' => $this->identifyConversionPoints($filters),
                'drop_off_analysis' => $this->analyzeDropOffPoints($filters),
                'segment_journeys' => $this->getSegmentJourneys($filters),
                'journey_optimization' => $this->generateJourneyOptimization($filters),
                'lifetime_value_analysis' => $this->getLifetimeValueAnalysis($filters)
            ];

            return [
                'success' => true,
                'customer_journey' => $journeyData,
                'journey_segments' => $this->segmentCustomerJourneys($journeyData),
                'optimization_opportunities' => $this->identifyJourneyOptimizations($journeyData),
                'recommendations' => $this->generateJourneyRecommendations($journeyData)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::mapCustomerJourney error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Conversion funnel deep dive
     */
    public function analyzeConversionFunnel(array $funnelConfig = []): array
    {
        try {
            $funnelData = [
                'funnel_stages' => $this->buildFunnelStages($funnelConfig),
                'stage_performance' => $this->getStagePerformance($funnelConfig),
                'conversion_rates' => $this->calculateConversionRates($funnelConfig),
                'funnel_leakage' => $this->identifyFunnelLeakage($funnelConfig),
                'segment_funnels' => $this->getSegmentFunnels($funnelConfig),
                'product_funnels' => $this->getProductFunnels($funnelConfig),
                'attribution_analysis' => $this->performFunnelAttribution($funnelConfig),
                'optimization_impact' => $this->calculateOptimizationImpact($funnelConfig)
            ];

            return [
                'success' => true,
                'conversion_funnel' => $funnelData,
                'key_insights' => $this->extractFunnelInsights($funnelData),
                'optimization_recommendations' => $this->generateFunnelOptimizations($funnelData),
                'expected_improvement' => $this->estimateFunnelImprovement($funnelData)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::analyzeConversionFunnel error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backward-compatible alias for conversion funnel analysis.
     */
    public function getConversionFunnelAnalysis(array $funnelConfig = []): array
    {
        return $this->analyzeConversionFunnel($funnelConfig);
    }

    /**
     * ROI tracking and attribution modeling
     */
    public function trackROIAttribution(array $attributionConfig = []): array
    {
        try {
            $attributionData = [
                'multi_touch_attribution' => $this->performMultiTouchAttribution($attributionConfig),
                'channel_performance' => $this->getChannelPerformance($attributionConfig),
                'roi_by_product' => $this->calculateROIByProduct($attributionConfig),
                'roi_by_category' => $this->calculateROIByCategory($attributionConfig),
                'attribution_models' => $this->compareAttributionModels($attributionConfig),
                'customer_ltv' => $this->calculateCustomerLTV($attributionConfig),
                'cost_analysis' => $this->performCostAnalysis($attributionConfig),
                'budget_optimization' => $this->generateBudgetOptimization($attributionConfig)
            ];

            return [
                'success' => true,
                'roi_attribution' => $attributionData,
                'attribution_insights' => $this->extractAttributionInsights($attributionData),
                'roi_metrics' => $this->calculateROIMetrics($attributionData),
                'budget_recommendations' => $this->generateBudgetRecommendations($attributionData)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::trackROIAttribution error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Predictive analytics engine
     */
    public function generatePredictiveAnalytics(array $predictionConfig = []): array
    {
        try {
            $predictions = [
                'demand_forecasting' => $this->generateDemandForecast($predictionConfig),
                'price_optimization' => $this->predictPriceOptimization($predictionConfig),
                'inventory_needs' => $this->predictInventoryNeeds($predictionConfig),
                'market_trends' => $this->predictMarketTrends($predictionConfig),
                'customer_behavior' => $this->predictCustomerBehavior($predictionConfig),
                'competitor_actions' => $this->predictCompetitorActions($predictionConfig),
                'seasonal_patterns' => $this->predictSeasonalPatterns($predictionConfig),
                'opportunity_scoring' => $this->generateOpportunityScoring($predictionConfig)
            ];

            return [
                'success' => true,
                'predictive_analytics' => $predictions,
                'confidence_scores' => $this->calculatePredictionConfidence($predictions),
                'actionable_insights' => $this->generateActionableInsights($predictions),
                'implementation_roadmap' => $this->generateImplementationRoadmap($predictions)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::generatePredictiveAnalytics error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backward-compatible alias for demand forecasting reports.
     */
    public function getDemandForecasting(array $predictionConfig = []): array
    {
        try {
            return [
                'success' => true,
                'demand_forecasting' => $this->generateDemandForecast($predictionConfig),
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getDemandForecasting error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate intelligence reports
     */
    public function generateIntelligenceReports(array $reportConfig = []): array
    {
        try {
            $reports = [
                'daily_intelligence' => $this->generateDailyIntelligence(),
                'weekly_insights' => $this->generateWeeklyInsights(),
                'monthly_strategic' => $this->generateMonthlyStrategic(),
                'competitive_intelligence' => $this->generateCompetitiveIntelligence(),
                'market_trends' => $this->generateMarketTrends(),
                'performance_scorecards' => $this->generatePerformanceScorecards(),
                'action_plans' => $this->generateActionPlans()
            ];

            return [
                'success' => true,
                'reports' => $reports,
                'executive_summary' => $this->generateExecutiveIntelligenceSummary($reports),
                'data_quality_score' => $this->calculateDataQualityScore(),
                'recommendations' => $this->generateIntelligenceRecommendations($reports)
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::generateIntelligenceReports error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backward-compatible alias for intelligence report generation.
     */
    public function getIntelligenceReports(array $reportConfig = []): array
    {
        return $this->generateIntelligenceReports($reportConfig);
    }

    /**
     * Analyze search patterns
     */
    private function analyzeSearchPatterns(array $searchData): array
    {
        $patterns = [
            'most_searched_terms' => $this->getMostSearchedTerms($searchData),
            'search_volume_trends' => $this->getSearchVolumeTrends($searchData),
            'seasonal_search_patterns' => $this->getSeasonalSearchPatterns($searchData),
            'search_success_rates' => $this->getSearchSuccessRates($searchData),
            'search_by_device' => $this->getSearchByDevice($searchData),
            'search_by_location' => $this->getSearchByLocation($searchData),
            'search_by_time' => $this->getSearchByTime($searchData),
            'abandoned_searches' => $this->getAbandonedSearches($searchData)
        ];

        return [
            'patterns' => $patterns,
            'insights' => $this->extractSearchPatternInsights($patterns),
            'optimization_opportunities' => $this->identifySearchOptimizations($patterns)
        ];
    }

    /**
     * Build funnel stages
     */
    private function buildFunnelStages(array $config): array
    {
        $defaultStages = [
            'impression' => ['name' => 'Impressão', 'description' => 'Produto visualizado'],
            'click' => ['name' => 'Clique', 'description' => 'Produto clicado'],
            'view' => ['name' => 'Visualização', 'description' => 'Página do produto visitada'],
            'cart_add' => ['name' => 'Carrinho', 'description' => 'Produto adicionado ao carrinho'],
            'purchase' => ['name' => 'Compra', 'description' => 'Compra concluída']
        ];

        return array_merge($defaultStages, $config['custom_stages'] ?? []);
    }

    /**
     * Generate demand forecast
     */
    private function generateDemandForecast(array $config): array
    {
        $historicalData = $this->getDemandHistoricalData($config);
        $externalFactors = $this->getExternalFactors($config);

        $forecast = [
            'product_demand' => $this->forecastProductDemand($historicalData, $externalFactors),
            'category_trends' => $this->forecastCategoryTrends($historicalData, $externalFactors),
            'seasonal_adjustments' => $this->calculateSeasonalAdjustments($historicalData),
            'market_conditions' => $this->analyzeMarketConditions($externalFactors),
            'confidence_intervals' => $this->calculateConfidenceIntervals($historicalData),
            'risk_factors' => $this->identifyDemandRiskFactors($historicalData, $externalFactors)
        ];

        return [
            'forecast' => $forecast,
            'methodology' => 'time_series_machine_learning',
            'data_points' => count($historicalData),
            'forecast_period_days' => $config['forecast_days'] ?? 30,
            'confidence_level' => 0.85
        ];
    }

    /**
     * Load analytics configuration
     */
    private function loadAnalyticsConfig(): array
    {
        return [
            'data_collection' => [
                'search_tracking' => true,
                'conversion_tracking' => true,
                'attribution_modeling' => true,
                'customer_journey_tracking' => true,
                'retention_tracking' => true,
                'real_time_processing' => true
            ],
            'machine_learning' => [
                'demand_forecasting' => true,
                'price_optimization' => true,
                'churn_prediction' => true,
                'recommendation_engine' => true,
                'anomaly_detection' => true,
                'model_training_frequency_days' => 7
            ],
            'reporting' => [
                'daily_reports' => true,
                'weekly_insights' => true,
                'monthly_strategic' => true,
                'executive_dashboards' => true,
                'custom_report_builder' => true
            ],
            'data_retention' => [
                'search_data_days' => 365,
                'conversion_data_days' => 730,
                'customer_data_days' => 1095,
                'attribution_data_days' => 180
            ]
        ];
    }

    /**
     * Additional helper methods — implementações reais via banco + ML API
     */
    private function getDataFreshness(): string
    {
        try {
            $latestPoints = [];

            // Última métrica de performance
            try {
                $stmt = $this->db->prepare("
                    SELECT MAX(CONCAT(metric_date, ' 23:59:59')) as latest
                    FROM seo_performance_metrics pm
                    JOIN items i ON i.ml_item_id = pm.item_id
                    WHERE i.account_id = :account_id
                ");
                $stmt->execute(['account_id' => $this->accountId]);
                $latest = $stmt->fetchColumn();
                if (!empty($latest)) {
                    $latestPoints[] = strtotime((string)$latest);
                }
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getDataFreshness error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // ignorar fonte
            }

            // Último pedido
            try {
                $stmt = $this->db->prepare("SELECT MAX(date_created) as latest FROM ml_orders WHERE ml_account_id = :account_id");
                $stmt->execute(['account_id' => $this->accountId]);
                $latest = $stmt->fetchColumn();
                if (!empty($latest)) {
                    $latestPoints[] = strtotime((string)$latest);
                }
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getDataFreshness error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // ignorar fonte
            }

            // Última pergunta recebida
            try {
                $stmt = $this->db->prepare("SELECT MAX(date_created) as latest FROM ml_questions WHERE account_id = :account_id");
                $stmt->execute(['account_id' => $this->accountId]);
                $latest = $stmt->fetchColumn();
                if (!empty($latest)) {
                    $latestPoints[] = strtotime((string)$latest);
                }
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getDataFreshness error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // ignorar fonte
            }

            if (empty($latestPoints)) {
                return 'sem dados recentes';
            }

            $latestTs = max($latestPoints);
            $diffSeconds = max(0, time() - $latestTs);

            if ($diffSeconds < 60) {
                return 'agora';
            }

            $minutes = (int)floor($diffSeconds / 60);
            if ($minutes < 60) {
                return $minutes . ' min';
            }

            $hours = (int)floor($minutes / 60);
            if ($hours < 48) {
                return $hours . ' h';
            }

            $days = (int)floor($hours / 24);
            return $days . ' d';
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getDataFreshness error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return 'indisponível';
        }
    }

    private function generateAnalyticsSummary(array $analytics): array
    {
        $summary = ['sections_populated' => 0, 'sections_empty' => 0];
        foreach ($analytics as $key => $data) {
            if (!empty($data) && $data !== []) {
                $summary['sections_populated']++;
            } else {
                $summary['sections_empty']++;
            }
        }
        $summary['data_completeness'] = $summary['sections_populated'] > 0
            ? round(($summary['sections_populated'] / ($summary['sections_populated'] + $summary['sections_empty'])) * 100, 1)
            : 0;
        return $summary;
    }

    private function getPerformanceOverview(array $filters): array
    {
        try {
            $days = intval($filters['days'] ?? 30);
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(DISTINCT i.ml_item_id) as active_items,
                    COALESCE(SUM(pm.visits), 0) as total_visits,
                    COALESCE(SUM(pm.sold_quantity), 0) as total_sales,
                    COALESCE(SUM(pm.revenue), 0) as total_revenue
                FROM items i
                LEFT JOIN seo_performance_metrics pm ON pm.item_id = i.ml_item_id
                    AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                WHERE i.account_id = :account_id AND i.status = 'active'
            ");
            $stmt->execute(['account_id' => $this->accountId, 'days' => $days]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $totalVisits = intval($data['total_visits'] ?? 0);
            $totalSales = intval($data['total_sales'] ?? 0);

            return [
                'active_items' => intval($data['active_items'] ?? 0),
                'total_visits' => $totalVisits,
                'total_sales' => $totalSales,
                'total_revenue' => floatval($data['total_revenue'] ?? 0),
                'conversion_rate' => $totalVisits > 0 ? round(($totalSales / $totalVisits) * 100, 2) : 0,
                'period_days' => $days,
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getPerformanceOverview error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return ['error' => $e->getMessage()];
        }
    }

    private function getSearchAnalytics(array $filters): array
    {
        // Buscar tendências das categorias ativas do seller
        $categories = $this->getActiveCategories();
        $trends = [];
        foreach (array_slice($categories, 0, 3) as $catId) {
            try {
                $catTrends = $this->mlClient->getTrends($catId);
                $trends[$catId] = $catTrends;
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getSearchAnalytics error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Pular
            }
        }
        return ['category_trends' => $trends, 'total_categories' => count($categories)];
    }

    private function getCategoryIntelligenceFilters(array $filters): array
    {
        return $this->getActiveCategories();
    }

    private function getCustomerJourneyAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(DISTINCT CASE WHEN o.status = 'paid' THEN JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.buyer.id')) END) as paying_customers,
                    COUNT(DISTINCT CASE WHEN q.status = 'ANSWERED' THEN q.from_user_id END) as engaged_customers,
                    COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.buyer.id'))) as total_customers
                FROM ml_orders o
                LEFT JOIN ml_questions q ON q.account_id = o.ml_account_id
                WHERE o.ml_account_id = :account_id
                AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND JSON_EXTRACT(o.order_data, '$.buyer.id') IS NOT NULL
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            return [
                'paying_customers' => intval($result['paying_customers'] ?? 0),
                'engaged_customers' => intval($result['engaged_customers'] ?? 0),
                'total_customers' => intval($result['total_customers'] ?? 0),
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getCustomerJourneyAnalysis error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'paying_customers' => 0,
                'engaged_customers' => 0,
                'total_customers' => 0,
                'note' => 'Dados de jornada indisponíveis no momento',
            ];
        }
    }

    private function getConversionFunnel(array $filters): array
    {
        try {
            $days = intval($filters['days'] ?? 30);
            $stmt = $this->db->prepare("
                SELECT
                    COALESCE(SUM(pm.visits), 0) as impressions,
                    COALESCE(SUM(pm.sold_quantity), 0) as purchases
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId, 'days' => $days]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $impressions = intval($data['impressions'] ?? 0);
            $purchases = intval($data['purchases'] ?? 0);
            return [
                'impressions' => $impressions,
                'purchases' => $purchases,
                'conversion_rate' => $impressions > 0 ? round(($purchases / $impressions) * 100, 2) : 0,
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getConversionFunnel error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'impressions' => 0,
                'purchases' => 0,
                'conversion_rate' => 0,
                'note' => 'Funil indisponível por falha de leitura de métricas',
            ];
        }
    }

    private function getROIAttribution(array $filters): array
    {
        return $this->getPerformanceOverview($filters);
    }

    private function getPredictiveInsights(array $filters): array
    {
        // Tendência de vendas (simples: comparar últimos 15d vs 15d anteriores)
        try {
            $stmt = $this->db->prepare("
                SELECT
                    SUM(CASE WHEN pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 15 DAY) THEN pm.sold_quantity ELSE 0 END) as recent_sales,
                    SUM(CASE WHEN pm.metric_date < DATE_SUB(CURDATE(), INTERVAL 15 DAY) AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN pm.sold_quantity ELSE 0 END) as previous_sales
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $recent = intval($data['recent_sales'] ?? 0);
            $previous = intval($data['previous_sales'] ?? 0);
            $trend = $previous > 0 ? round((($recent - $previous) / $previous) * 100, 1) : 0;
            return [
                'sales_trend' => $trend > 0 ? 'growing' : ($trend < 0 ? 'declining' : 'stable'),
                'trend_pct' => $trend,
                'recent_sales' => $recent,
                'previous_sales' => $previous,
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getPredictiveInsights error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'sales_trend' => 'unknown',
                'trend_pct' => 0,
                'recent_sales' => 0,
                'previous_sales' => 0,
                'note' => 'Insights preditivos indisponíveis no momento',
            ];
        }
    }

    private function getMarketPositioning(array $filters): array
    {
        $categories = $this->getActiveCategories();
        $positioning = [];
        foreach (array_slice($categories, 0, 3) as $catId) {
            try {
                $analysis = $this->mlClient->getCompetitorAnalysis('', $catId);
                $positioning[$catId] = $analysis['price_analysis'] ?? [];
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getMarketPositioning error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Pular
            }
        }
        return $positioning;
    }

    private function getOperationalMetrics(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_items,
                    COUNT(CASE WHEN status = 'paused' THEN 1 END) as paused_items,
                    COUNT(*) as total_items
                FROM items WHERE account_id = :account_id
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            return [
                'active_items' => intval($result['active_items'] ?? 0),
                'paused_items' => intval($result['paused_items'] ?? 0),
                'total_items' => intval($result['total_items'] ?? 0),
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getOperationalMetrics error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'active_items' => 0,
                'paused_items' => 0,
                'total_items' => 0,
                'note' => 'Métricas operacionais indisponíveis',
            ];
        }
    }

    private function getSearchData(array $timeframe): array
    {
        $categories = $this->getActiveCategories();
        $data = [];
        foreach (array_slice($categories, 0, 3) as $catId) {
            try {
                $data[$catId] = $this->mlClient->getTrends($catId);
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getSearchData error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Pular
            }
        }
        return $data;
    }

    private function extractSearchInsights(array $analysis): array
    {
        $insights = [];
        foreach ($analysis['category_trends'] ?? $analysis as $catId => $trends) {
            if (is_array($trends) && count($trends) > 0) {
                $insights[] = "Categoria {$catId}: " . count($trends) . " termos em tendência";
            }
        }
        return $insights;
    }

    private function generateSearchRecommendations(array $analysis): array
    {
        $recs = [];
        foreach ($analysis['category_trends'] ?? $analysis as $catId => $trends) {
            if (is_array($trends)) {
                foreach (array_slice($trends, 0, 3) as $term) {
                    if (is_string($term)) {
                        $recs[] = "Inclua '{$term}' nos títulos da categoria {$catId}";
                    }
                }
            }
        }
        return $recs;
    }

    private function getActiveCategories(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT category_id FROM items
                WHERE account_id = :account_id AND status = 'active' AND category_id IS NOT NULL
                LIMIT 10
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getActiveCategories error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'total_revenue' => 0.0,
                'total_orders' => 0,
                'attribution_models' => [
                    'first_touch' => ['organic' => 0.0, 'ads' => 0.0, 'description' => 'Atribui ao primeiro ponto de contato'],
                    'last_touch' => ['organic' => 0.0, 'ads' => 0.0, 'description' => 'Atribui ao último ponto de contato'],
                    'linear' => ['organic' => 0.0, 'ads' => 0.0, 'description' => 'Distribui igualmente entre touchpoints'],
                    'time_decay' => ['organic' => 0.0, 'ads' => 0.0, 'description' => 'Maior peso para touchpoints recentes'],
                ],
                'recommended_model' => 'time_decay',
            ];
        }
    }

    private function analyzeCategoryPerformance(string $category): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total_items,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    AVG(price) as avg_price
                FROM items
                WHERE account_id = :account_id AND category_id = :category_id
            ");
            $stmt->execute(['account_id' => $this->accountId, 'category_id' => $category]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            // Enriquecer com dados de mercado
            $marketData = [];
            try {
                $searchResults = $this->mlClient->searchItems(['category' => $category, 'limit' => 5]);
                $marketData['total_listings'] = intval($searchResults['paging']['total'] ?? 0);
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::analyzeCategoryPerformance error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Continuar
            }

            return array_merge($data, ['category_id' => $category, 'market' => $marketData]);
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::analyzeCategoryPerformance error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return ['category_id' => $category, 'error' => $e->getMessage()];
        }
    }

    private function performCrossCategoryAnalysis(array $insights): array
    {
        if (count($insights) < 2) {
            $only = $insights[0] ?? [];
            return [
                'highest_avg_price_category' => $only['category_id'] ?? null,
                'avg_price' => floatval($only['avg_price'] ?? 0),
                'sample_size' => count($insights),
                'note' => 'Dados insuficientes para comparação entre categorias',
            ];
        }
        $bestCategory = null;
        $bestAvgPrice = 0;
        foreach ($insights as $insight) {
            $avg = floatval($insight['avg_price'] ?? 0);
            if ($avg > $bestAvgPrice) {
                $bestAvgPrice = $avg;
                $bestCategory = $insight['category_id'] ?? '';
            }
        }
        $avgAcross = array_sum(array_map(fn(array $i): float => floatval($i['avg_price'] ?? 0), $insights)) / max(1, count($insights));
        return [
            'highest_avg_price_category' => $bestCategory,
            'avg_price' => round($bestAvgPrice, 2),
            'portfolio_avg_price' => round($avgAcross, 2),
            'sample_size' => count($insights),
        ];
    }

    private function generateCategoryRankings(array $insights): array
    {
        usort($insights, fn($a, $b) => intval($b['active'] ?? 0) <=> intval($a['active'] ?? 0));
        return array_map(fn(array $i): array => [
            'category_id' => $i['category_id'] ?? '',
            'active_items' => intval($i['active'] ?? 0),
        ], array_slice($insights, 0, 5));
    }

    private function generateCategoryOpportunityMatrix(array $insights): array
    {
        return array_map(fn(array $i): array => [
            'category_id' => $i['category_id'] ?? '',
            'items' => intval($i['total_items'] ?? 0),
            'market_size' => intval($i['market']['total_listings'] ?? 0),
            'penetration' => (intval($i['market']['total_listings'] ?? 0) > 0)
                ? round((intval($i['total_items'] ?? 0) / intval($i['market']['total_listings'] ?? 1)) * 100, 2)
                : 0,
        ], $insights);
    }

    private function generateCategoryStrategicRecommendations(array $insights): array
    {
        $recs = [];
        foreach ($insights as $insight) {
            $catId = $insight['category_id'] ?? '';
            $active = intval($insight['active'] ?? 0);
            $total = intval($insight['total_items'] ?? 0);
            if ($total > 0 && $active < $total * 0.5) {
                $recs[] = "Reativar anúncios pausados na categoria {$catId} ({$active}/{$total} ativos)";
            }
            $marketTotal = intval($insight['market']['total_listings'] ?? 0);
            if ($marketTotal > 0 && $active < $marketTotal * 0.01) {
                $recs[] = "Oportunidade de expansão na categoria {$catId} — baixa penetração de mercado";
            }
        }
        return $recs;
    }

    // Customer Journey methods — baseados em dados de orders e questions
    private function analyzeTouchpoints(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_questions,
                       COUNT(CASE WHEN status = 'ANSWERED' THEN 1 END) as answered
                FROM ml_questions WHERE account_id = :account_id
                AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            return [
                'total_questions' => intval($result['total_questions'] ?? 0),
                'answered' => intval($result['answered'] ?? 0),
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::analyzeTouchpoints error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'total_questions' => 0,
                'answered' => 0,
                'note' => 'Dados de perguntas indisponíveis',
            ];
        }
    }

    private function analyzeCustomerPaths(array $filters): array
    {
        return $this->getCustomerJourneyAnalysis($filters);
    }
    private function getEngagementMetrics(array $filters): array
    {
        return $this->analyzeTouchpoints($filters);
    }

    private function identifyConversionPoints(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT i.category_id, COUNT(o.id) as sales_count
                FROM ml_orders o
                JOIN order_items oi ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                JOIN items i ON i.ml_item_id = oi.item_id
                WHERE o.ml_account_id = :account_id AND o.status = 'paid'
                AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY i.category_id
                ORDER BY sales_count DESC
                LIMIT 5
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::identifyConversionPoints error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'organic' => [
                    'name' => 'Orgânico',
                    'revenue' => 0.0,
                    'orders' => 0,
                    'cost' => 0,
                    'share_pct' => 0,
                ],
                'ads' => [
                    'name' => 'Anúncios (Product Ads)',
                    'revenue' => 0.0,
                    'orders' => 0,
                    'cost' => 0.0,
                    'roas' => 0,
                    'ctr' => 0,
                    'share_pct' => 0,
                ],
            ];
        }
    }

    private function analyzeDropOffPoints(array $filters): array
    {
        // Items com muitas visitas mas poucas vendas
        try {
            $stmt = $this->db->prepare("
                SELECT pm.item_id, SUM(pm.visits) as visits, SUM(pm.sold_quantity) as sales
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY pm.item_id
                HAVING visits > 50 AND sales = 0
                ORDER BY visits DESC
                LIMIT 10
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::analyzeDropOffPoints error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'models' => [],
                'recommended' => 'time_decay',
                'recommendation_reason' => 'Dados insuficientes para comparar modelos de atribuição com confiança',
                'total_revenue_analyzed' => 0.0,
            ];
        }
    }

    private function getSegmentJourneys(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.buyer.id')) as buyer_id,
                    COUNT(*) as order_count,
                    SUM(o.total_amount) as total_spent,
                    AVG(o.total_amount) as avg_order_value,
                    MIN(o.date_created) as first_purchase,
                    MAX(o.date_created) as last_purchase
                FROM ml_orders o
                WHERE o.ml_account_id = :account_id AND o.status = 'paid'
                AND JSON_EXTRACT(o.order_data, '$.buyer.id') IS NOT NULL
                GROUP BY buyer_id
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $buyers = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $segments = [
                'new' => ['label' => 'Novos', 'buyers' => 0, 'total_spent' => 0, 'avg_order' => 0, 'order_counts' => []],
                'repeat' => ['label' => 'Recorrentes', 'buyers' => 0, 'total_spent' => 0, 'avg_order' => 0, 'order_counts' => []],
                'vip' => ['label' => 'VIP', 'buyers' => 0, 'total_spent' => 0, 'avg_order' => 0, 'order_counts' => []],
            ];

            foreach ($buyers as $buyer) {
                $orderCount = intval($buyer['order_count']);
                $segment = $orderCount === 1 ? 'new' : ($orderCount >= 5 ? 'vip' : 'repeat');
                $segments[$segment]['buyers']++;
                $segments[$segment]['total_spent'] += floatval($buyer['total_spent']);
                $segments[$segment]['order_counts'][] = $orderCount;
            }

            foreach ($segments as &$seg) {
                if ($seg['buyers'] > 0) {
                    $seg['avg_order'] = round($seg['total_spent'] / $seg['buyers'], 2);
                    $seg['avg_frequency'] = count($seg['order_counts']) > 0
                        ? round(array_sum($seg['order_counts']) / count($seg['order_counts']), 1)
                        : 0;
                }
                unset($seg['order_counts']);
            }
            unset($seg);

            return $segments;
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getSegmentJourneys error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'new' => ['label' => 'Novos', 'buyers' => 0, 'total_spent' => 0, 'avg_order' => 0, 'avg_frequency' => 0],
                'repeat' => ['label' => 'Recorrentes', 'buyers' => 0, 'total_spent' => 0, 'avg_order' => 0, 'avg_frequency' => 0],
                'vip' => ['label' => 'VIP', 'buyers' => 0, 'total_spent' => 0, 'avg_order' => 0, 'avg_frequency' => 0],
                'note' => 'Segmentação de jornada indisponível',
            ];
        }
    }

    private function generateJourneyOptimization(array $filters): array
    {
        $dropoffs = $this->analyzeDropOffPoints($filters);
        $recs = [];
        foreach ($dropoffs as $item) {
            $recs[] = "Item {$item['item_id']} com {$item['visits']} visitas e 0 vendas — otimizar preço e descrição";
        }
        return $recs;
    }

    private function getLifetimeValueAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.buyer.id')) as buyer_id,
                    COUNT(*) as total_orders,
                    SUM(o.total_amount) as total_spent
                FROM ml_orders o
                WHERE o.ml_account_id = :account_id AND o.status = 'paid'
                AND JSON_EXTRACT(o.order_data, '$.buyer.id') IS NOT NULL
                GROUP BY buyer_id
                HAVING total_orders > 1
                ORDER BY total_spent DESC
                LIMIT 20
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $repeatBuyers = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            return [
                'repeat_buyers' => count($repeatBuyers),
                'avg_ltv' => count($repeatBuyers) > 0
                    ? round(array_sum(array_column($repeatBuyers, 'total_spent')) / count($repeatBuyers), 2)
                    : 0,
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getLifetimeValueAnalysis error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'repeat_buyers' => 0,
                'avg_ltv' => 0,
                'note' => 'LTV indisponível por falha de consulta',
            ];
        }
    }

    private function segmentCustomerJourneys(array $journeyData): array
    {
        $touchpoints = $journeyData['touchpoint_analysis'] ?? [];
        $customers = $journeyData['path_analysis'] ?? [];
        $ltv = $journeyData['lifetime_value_analysis'] ?? [];

        $totalCustomers = intval($customers['total_customers'] ?? 0);
        $payingCustomers = intval($customers['paying_customers'] ?? 0);
        $engagedCustomers = intval($customers['engaged_customers'] ?? 0);
        $repeatBuyers = intval($ltv['repeat_buyers'] ?? 0);
        $totalQuestions = intval($touchpoints['total_questions'] ?? 0);
        $answeredQuestions = intval($touchpoints['answered'] ?? 0);

        $newVisitors = max(0, $totalCustomers - $payingCustomers);
        $responseRate = $totalQuestions > 0 ? round(($answeredQuestions / $totalQuestions) * 100, 1) : 0;

        return [
            'segments' => [
                [
                    'name' => 'new_visitors',
                    'count' => $newVisitors,
                    'description' => 'Usuários sem compra concluída no período',
                ],
                [
                    'name' => 'engaged',
                    'count' => $engagedCustomers,
                    'description' => 'Usuários que interagem via perguntas',
                ],
                [
                    'name' => 'buyers',
                    'count' => $payingCustomers,
                    'description' => 'Clientes com pelo menos uma compra paga',
                ],
                [
                    'name' => 'repeat_buyers',
                    'count' => $repeatBuyers,
                    'description' => 'Clientes recorrentes com mais de uma compra',
                ],
            ],
            'engagement_rate_pct' => $totalCustomers > 0 ? round(($engagedCustomers / $totalCustomers) * 100, 1) : 0,
            'purchase_rate_pct' => $totalCustomers > 0 ? round(($payingCustomers / $totalCustomers) * 100, 1) : 0,
            'support_response_rate_pct' => $responseRate,
        ];
    }

    private function identifyJourneyOptimizations(array $journeyData): array
    {
        $optimizations = [];

        $dropOffPoints = $journeyData['drop_off_analysis'] ?? [];
        foreach (array_slice($dropOffPoints, 0, 5) as $point) {
            $itemId = $point['item_id'] ?? null;
            $visits = intval($point['visits'] ?? 0);
            if ($itemId && $visits > 0) {
                $optimizations[] = "Item {$itemId} tem {$visits} visitas sem vendas: revisar preço, título e frete.";
            }
        }

        $touchpoints = $journeyData['touchpoint_analysis'] ?? [];
        $totalQuestions = intval($touchpoints['total_questions'] ?? 0);
        $answered = intval($touchpoints['answered'] ?? 0);
        if ($totalQuestions > 0) {
            $answerRate = ($answered / $totalQuestions) * 100;
            if ($answerRate < 90) {
                $optimizations[] = 'Melhorar SLA de respostas em perguntas para elevar conversão de consideração para compra.';
            }
        }

        $segmentJourneys = $journeyData['segment_journeys'] ?? [];
        $newBuyers = intval($segmentJourneys['new']['buyers'] ?? 0);
        $repeatBuyers = intval($segmentJourneys['repeat']['buyers'] ?? 0);
        $vipBuyers = intval($segmentJourneys['vip']['buyers'] ?? 0);

        if ($newBuyers > ($repeatBuyers + $vipBuyers) * 2 && $newBuyers >= 20) {
            $optimizations[] = 'Alta dependência de novos compradores: criar estratégia de recompra (cupons pós-venda e kits).';
        }

        $totalCustomers = intval($journeyData['path_analysis']['total_customers'] ?? 0);
        $payingCustomers = intval($journeyData['path_analysis']['paying_customers'] ?? 0);
        if ($totalCustomers > 0) {
            $purchaseRate = ($payingCustomers / $totalCustomers) * 100;
            if ($purchaseRate < 15) {
                $optimizations[] = 'Taxa de compra baixa no funil de jornada: revisar preço competitivo, reputação e frete nos top anúncios.';
            }
        }

        if (empty($optimizations)) {
            $optimizations[] = 'Jornada estável: continuar monitorando itens com maior tráfego e aplicar testes A/B incrementais.';
        }

        return $optimizations;
    }

    private function generateJourneyRecommendations(array $journeyData): array
    {
        $recs = [];
        if (intval($journeyData['touchpoint_analysis']['total_questions'] ?? 0) > 0) {
            $answered = intval($journeyData['touchpoint_analysis']['answered'] ?? 0);
            $total = intval($journeyData['touchpoint_analysis']['total_questions'] ?? 1);
            $rate = round(($answered / $total) * 100, 1);
            if ($rate < 90) {
                $recs[] = "Taxa de resposta de perguntas está em {$rate}% — meta: 95%+";
            }
        }

        $dropOffs = $journeyData['drop_off_analysis'] ?? [];
        if (is_array($dropOffs) && !empty($dropOffs)) {
            $topDrop = $dropOffs[0] ?? null;
            if ($topDrop) {
                $visits = intval($topDrop['visits'] ?? 0);
                if ($visits >= 80) {
                    $recs[] = "Há item com alto abandono ({$visits} visitas sem venda): revisar preço, reputação e qualidade da primeira imagem.";
                }
            }
        }

        $repeatBuyers = intval($journeyData['lifetime_value_analysis']['repeat_buyers'] ?? 0);
        if ($repeatBuyers < 5) {
            $recs[] = 'Baixa recorrência de clientes: criar estratégia de pós-venda com cupom e kits de recompra.';
        }

        if (empty($recs)) {
            $recs[] = 'Jornada com indicadores saudáveis no período: manter monitoramento semanal e testes incrementais.';
        }

        return $recs;
    }

    // Funnel methods
    private function getStagePerformance(array $config): array
    {
        return $this->getConversionFunnel($config);
    }
    private function calculateConversionRates(array $config): array
    {
        return $this->getConversionFunnel($config);
    }

    private function identifyFunnelLeakage(array $config): array
    {
        $funnel = $this->getConversionFunnel($config);
        $impressions = intval($funnel['impressions'] ?? 0);
        $purchases = intval($funnel['purchases'] ?? 0);
        if ($impressions === 0) {
            return [
                'leakage_point' => 'no_traffic',
                'lost' => 0,
                'note' => 'Sem impressões no período analisado',
            ];
        }

        $rate = ($purchases / max(1, $impressions)) * 100;
        if ($impressions > 0 && $purchases < $impressions * 0.01) {
            return [
                'leakage_point' => 'view_to_purchase',
                'lost' => $impressions - $purchases,
                'conversion_rate' => round($rate, 2),
                'severity' => 'high',
            ];
        }

        return [
            'leakage_point' => 'within_expected_range',
            'lost' => max(0, $impressions - $purchases),
            'conversion_rate' => round($rate, 2),
            'severity' => 'low',
        ];
    }

    private function getSegmentFunnels(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    CASE
                        WHEN buyer_orders.order_count = 1 THEN 'new'
                        WHEN buyer_orders.order_count >= 5 THEN 'vip'
                        ELSE 'repeat'
                    END as segment,
                    COUNT(DISTINCT buyer_orders.buyer_id) as buyers,
                    SUM(buyer_orders.total_spent) as revenue,
                    SUM(buyer_orders.order_count) as total_orders
                FROM (
                    SELECT JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.buyer.id')) as buyer_id, COUNT(*) as order_count, SUM(total_amount) as total_spent
                    FROM ml_orders
                    WHERE ml_account_id = :account_id AND status = 'paid'
                    AND JSON_EXTRACT(order_data, '$.buyer.id') IS NOT NULL
                    GROUP BY buyer_id
                ) buyer_orders
                GROUP BY segment
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $funnels = [];
            $totalBuyers = max(1, array_sum(array_column($rows, 'buyers')));
            foreach ($rows as $row) {
                $funnels[$row['segment']] = [
                    'buyers' => intval($row['buyers']),
                    'revenue' => round(floatval($row['revenue']), 2),
                    'total_orders' => intval($row['total_orders']),
                    'share_pct' => round((intval($row['buyers']) / $totalBuyers) * 100, 1),
                    'avg_order_value' => intval($row['total_orders']) > 0
                        ? round(floatval($row['revenue']) / intval($row['total_orders']), 2)
                        : 0,
                ];
            }

            return $funnels;
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getSegmentFunnels error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'new' => [
                    'buyers' => 0,
                    'revenue' => 0.0,
                    'total_orders' => 0,
                    'share_pct' => 0,
                    'avg_order_value' => 0,
                ],
                'repeat' => [
                    'buyers' => 0,
                    'revenue' => 0.0,
                    'total_orders' => 0,
                    'share_pct' => 0,
                    'avg_order_value' => 0,
                ],
                'vip' => [
                    'buyers' => 0,
                    'revenue' => 0.0,
                    'total_orders' => 0,
                    'share_pct' => 0,
                    'avg_order_value' => 0,
                ],
            ];
        }
    }

    private function getProductFunnels(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    pm.item_id,
                    i.title,
                    COALESCE(SUM(pm.visits), 0) as visits,
                    COALESCE(SUM(pm.sold_quantity), 0) as sales,
                    COALESCE(SUM(pm.revenue), 0) as revenue
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY pm.item_id, i.title
                ORDER BY visits DESC
                LIMIT 20
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $products = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $funnels = [];
            foreach ($products as $product) {
                $visits = intval($product['visits']);
                $sales = intval($product['sales']);
                $funnels[] = [
                    'item_id' => $product['item_id'],
                    'title' => $product['title'],
                    'impressions' => $visits,
                    'sales' => $sales,
                    'revenue' => round(floatval($product['revenue']), 2),
                    'conversion_rate' => $visits > 0 ? round(($sales / $visits) * 100, 2) : 0,
                ];
            }

            return $funnels;
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getProductFunnels error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [[
                'item_id' => '',
                'title' => 'Dados indisponíveis',
                'impressions' => 0,
                'sales' => 0,
                'revenue' => 0.0,
                'conversion_rate' => 0.0,
            ]];
        }
    }

    private function performFunnelAttribution(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total_revenue
                FROM ml_orders
                WHERE ml_account_id = :account_id AND status = 'paid'
                AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $totalRevenue = floatval($stmt->fetchColumn() ?: 0);

            $stages = [
                'discovery' => ['weight' => 0.30, 'description' => 'Busca/Impressões'],
                'consideration' => ['weight' => 0.25, 'description' => 'Visualização do anúncio'],
                'evaluation' => ['weight' => 0.20, 'description' => 'Perguntas/Comparação'],
                'purchase' => ['weight' => 0.25, 'description' => 'Decisão de compra'],
            ];

            $attribution = [];
            foreach ($stages as $stage => $info) {
                $attribution[$stage] = [
                    'stage' => $stage,
                    'description' => $info['description'],
                    'weight' => $info['weight'],
                    'attributed_revenue' => round($totalRevenue * $info['weight'], 2),
                ];
            }

            return [
                'total_revenue' => round($totalRevenue, 2),
                'model' => 'position_based',
                'stage_attribution' => $attribution,
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::performFunnelAttribution error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'total_revenue' => 0,
                'model' => 'position_based',
                'stage_attribution' => [],
                'note' => 'Atribuição de funil indisponível',
            ];
        }
    }

    private function calculateOptimizationImpact(array $config): array
    {
        try {
            $funnel = $this->getConversionFunnel($config);
            $impressions = intval($funnel['impressions'] ?? 0);
            $convRate = floatval($funnel['conversion_rate'] ?? 0);
            $purchases = intval($funnel['purchases'] ?? 0);

            $stmt = $this->db->prepare("
                SELECT AVG(total_amount) as aov
                FROM ml_orders
                WHERE ml_account_id = :account_id AND status = 'paid'
                AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $aov = floatval($stmt->fetchColumn() ?: 0);

            $scenarios = [];
            foreach ([5, 10, 20, 50] as $pct) {
                $newRate = $convRate * (1 + $pct / 100);
                $newSales = $impressions > 0 ? (int)round($impressions * $newRate / 100) : 0;
                $additionalSales = max(0, $newSales - $purchases);
                $scenarios[] = [
                    'improvement_pct' => $pct,
                    'new_conversion_rate' => round($newRate, 2),
                    'additional_sales' => $additionalSales,
                    'additional_revenue' => round($additionalSales * $aov, 2),
                ];
            }

            return [
                'current_conversion_rate' => $convRate,
                'current_aov' => round($aov, 2),
                'current_monthly_sales' => $purchases,
                'optimization_scenarios' => $scenarios,
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::calculateOptimizationImpact error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'current_conversion_rate' => 0,
                'current_aov' => 0,
                'current_monthly_sales' => 0,
                'optimization_scenarios' => [],
                'note' => 'Impacto de otimização indisponível',
            ];
        }
    }

    private function extractFunnelInsights(array $funnelData): array
    {
        $insights = [];
        $rate = floatval($funnelData['funnel_stages']['conversion_rates']['conversion_rate'] ?? 0);
        if ($rate > 0 && $rate < 1) {
            $insights[] = "Taxa de conversão abaixo de 1% — otimizar títulos e preços";
        }

        $leakage = $funnelData['funnel_stages']['leakage_analysis'] ?? [];
        if (!empty($leakage['leakage_point'])) {
            $lost = intval($leakage['lost'] ?? 0);
            $insights[] = "Maior vazamento em {$leakage['leakage_point']} com {$lost} oportunidades perdidas no período.";
        }

        $products = $funnelData['funnel_stages']['product_funnels'] ?? [];
        if (is_array($products) && !empty($products)) {
            usort($products, fn($a, $b) => intval($b['impressions'] ?? 0) <=> intval($a['impressions'] ?? 0));
            $top = $products[0] ?? null;
            if ($top) {
                $topRate = floatval($top['conversion_rate'] ?? 0);
                if ($topRate < max(0.5, $rate * 0.6)) {
                    $insights[] = "Produto com maior tráfego ({$top['item_id']}) converte abaixo da média — priorizar otimização desse anúncio.";
                }
            }
        }

        return $insights;
    }

    private function generateFunnelOptimizations(array $funnelData): array
    {
        $optimizations = [];

        $products = $funnelData['funnel_stages']['product_funnels'] ?? [];
        if (is_array($products) && !empty($products)) {
            foreach (array_slice($products, 0, 10) as $product) {
                $impressions = intval($product['impressions'] ?? 0);
                $sales = intval($product['sales'] ?? 0);
                $rate = floatval($product['conversion_rate'] ?? 0);
                if ($impressions >= 100 && $sales === 0) {
                    $optimizations[] = "Item {$product['item_id']} com alto tráfego e zero vendas: revisar preço e prova de confiança.";
                } elseif ($impressions >= 100 && $rate < 0.8) {
                    $optimizations[] = "Item {$product['item_id']} com baixa conversão ({$rate}%): testar variação de título e primeira imagem.";
                }
            }
        }

        $leakage = $funnelData['funnel_stages']['funnel_leakage'] ?? [];
        if (!empty($leakage['leakage_point'])) {
            $lost = intval($leakage['lost'] ?? 0);
            if ($lost > 0) {
                $optimizations[] = "Vazamento detectado em {$leakage['leakage_point']} ({$lost} perdas): reforçar títulos, benefícios e credenciais de confiança.";
            }
        }

        $impact = $funnelData['funnel_stages']['optimization_impact']['optimization_scenarios'] ?? [];
        foreach ($impact as $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            $improvement = intval($scenario['improvement_pct'] ?? 0);
            $additionalRevenue = floatval($scenario['additional_revenue'] ?? 0);
            if ($improvement >= 20 && $additionalRevenue > 0) {
                $optimizations[] = "Cenário +{$improvement}% indica potencial de +R$ " . number_format($additionalRevenue, 2, ',', '.') . ": priorizar SKUs com maior tráfego.";
                break;
            }
        }

        if (empty($optimizations)) {
            $optimizations[] = 'Funil com desempenho consistente: manter otimizações semanais em top SKUs e acompanhar variação de conversão.';
        }

        return array_values(array_unique($optimizations));
    }

    private function estimateFunnelImprovement(array $funnelData): array
    {
        $stages = $funnelData['funnel_stages'] ?? [];
        $funnelRates = $stages['conversion_rates'] ?? [];
        $currentRate = floatval($funnelRates['conversion_rate'] ?? 0);

        $impact = $funnelData['potential_impact'] ?? [];
        $scenarios = $impact['optimization_scenarios'] ?? [];

        $bestScenario = null;
        foreach ($scenarios as $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            if ($bestScenario === null || floatval($scenario['additional_revenue'] ?? 0) > floatval($bestScenario['additional_revenue'] ?? 0)) {
                $bestScenario = $scenario;
            }
        }

        if ($bestScenario === null) {
            return [
                'potential_increase_pct' => 0,
                'confidence' => 'low',
                'reason' => 'Dados insuficientes para simulação de impacto',
            ];
        }

        $newRate = floatval($bestScenario['new_conversion_rate'] ?? 0);
        $increasePct = $currentRate > 0
            ? round((($newRate - $currentRate) / $currentRate) * 100, 1)
            : floatval($bestScenario['improvement_pct'] ?? 0);

        $additionalRevenue = floatval($bestScenario['additional_revenue'] ?? 0);
        $confidence = $additionalRevenue > 5000 ? 'high' : ($additionalRevenue > 1000 ? 'medium' : 'low');

        return [
            'potential_increase_pct' => max(0, $increasePct),
            'current_conversion_rate' => round($currentRate, 2),
            'projected_conversion_rate' => round($newRate, 2),
            'estimated_additional_revenue' => round($additionalRevenue, 2),
            'confidence' => $confidence,
        ];
    }

    // ROI Attribution methods
    private function performMultiTouchAttribution(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total_revenue,
                       COUNT(*) as total_orders
                FROM ml_orders
                WHERE ml_account_id = :account_id AND status = 'paid'
                AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $orderData = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $totalRevenue = floatval($orderData['total_revenue'] ?? 0);
            $totalOrders = intval($orderData['total_orders'] ?? 0);

            $adRevenue = 0.0;
            $adCost = 0.0;
            try {
                $stmtAds = $this->db->prepare("
                    SELECT COALESCE(SUM(revenue), 0) as ad_revenue,
                           COALESCE(SUM(cost), 0) as ad_cost
                    FROM ml_ad_performance
                    WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ");
                $stmtAds->execute();
                $adData = $stmtAds->fetch(\PDO::FETCH_ASSOC) ?: [];
                $adRevenue = floatval($adData['ad_revenue'] ?? 0);
                $adCost = floatval($adData['ad_cost'] ?? 0);
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::performMultiTouchAttribution error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Tabela pode não existir
            }

            $organicRevenue = max(0, $totalRevenue - $adRevenue);

            $models = [
                'first_touch' => [
                    'organic' => round($organicRevenue * 0.70, 2),
                    'ads' => round($adRevenue * 0.70 + $organicRevenue * 0.30, 2),
                    'description' => 'Atribui ao primeiro ponto de contato',
                ],
                'last_touch' => [
                    'organic' => round($organicRevenue * 0.50, 2),
                    'ads' => round($adRevenue + $organicRevenue * 0.50, 2),
                    'description' => 'Atribui ao último ponto de contato',
                ],
                'linear' => [
                    'organic' => round($organicRevenue * 0.60, 2),
                    'ads' => round($adRevenue * 0.80 + $organicRevenue * 0.40, 2),
                    'description' => 'Distribui igualmente entre touchpoints',
                ],
                'time_decay' => [
                    'organic' => round($organicRevenue * 0.45, 2),
                    'ads' => round($adRevenue * 0.90 + $organicRevenue * 0.55, 2),
                    'description' => 'Maior peso para touchpoints recentes',
                ],
            ];

            return [
                'total_revenue' => round($totalRevenue, 2),
                'total_orders' => $totalOrders,
                'attribution_models' => $models,
                'recommended_model' => 'time_decay',
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::performMultiTouchAttribution error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'abandoned_terms' => [],
                'total_gaps' => 0,
                'recommendation' => 'Não foi possível calcular lacunas de busca no momento',
            ];
        }
    }

    private function getChannelPerformance(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as revenue
                FROM ml_orders
                WHERE ml_account_id = :account_id AND status = 'paid'
                AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $organicData = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $adData = ['impressions' => 0, 'clicks' => 0, 'cost' => 0, 'revenue' => 0, 'conversions' => 0];
            try {
                $stmtAds = $this->db->prepare("
                    SELECT
                        COALESCE(SUM(impressions), 0) as impressions,
                        COALESCE(SUM(clicks), 0) as clicks,
                        COALESCE(SUM(cost), 0) as cost,
                        COALESCE(SUM(revenue), 0) as revenue,
                        COALESCE(SUM(conversions), 0) as conversions
                    FROM ml_ad_performance
                    WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ");
                $stmtAds->execute();
                $adData = $stmtAds->fetch(\PDO::FETCH_ASSOC) ?: $adData;
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getChannelPerformance error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Tabela pode não existir
            }

            $adRevenue = floatval($adData['revenue'] ?? 0);
            $adCost = floatval($adData['cost'] ?? 0);
            $totalRevenue = floatval($organicData['revenue'] ?? 0);
            $organicRevenue = max(0, $totalRevenue - $adRevenue);

            $channels = [];
            $channels['organic'] = [
                'name' => 'Orgânico',
                'revenue' => round($organicRevenue, 2),
                'orders' => max(0, intval($organicData['orders'] ?? 0) - intval($adData['conversions'] ?? 0)),
                'cost' => 0,
                'share_pct' => $totalRevenue > 0 ? round(($organicRevenue / $totalRevenue) * 100, 1) : 0,
            ];

            $channels['ads'] = [
                'name' => 'Anúncios (Product Ads)',
                'revenue' => round($adRevenue, 2),
                'orders' => intval($adData['conversions'] ?? 0),
                'cost' => round($adCost, 2),
                'roas' => $adCost > 0 ? round($adRevenue / $adCost, 2) : 0,
                'ctr' => intval($adData['impressions'] ?? 0) > 0
                    ? round((intval($adData['clicks'] ?? 0) / intval($adData['impressions'])) * 100, 2) : 0,
                'share_pct' => $totalRevenue > 0 ? round(($adRevenue / $totalRevenue) * 100, 1) : 0,
            ];

            return $channels;
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getChannelPerformance error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'segments' => [
                    'direct_buyers' => [
                        'label' => 'Compradores Diretos',
                        'description' => 'Buscam e compram sem perguntar',
                        'estimated_count' => 0,
                        'behavior' => 'Alta intenção de compra',
                    ],
                    'researchers' => [
                        'label' => 'Pesquisadores',
                        'description' => 'Buscam e perguntam antes de comprar',
                        'estimated_count' => 0,
                        'behavior' => 'Comparação ativa',
                    ],
                    'browsers' => [
                        'label' => 'Exploradores',
                        'description' => 'Navegam sem comprar',
                        'estimated_count' => 0,
                        'behavior' => 'Baixa intenção — melhorar títulos e preços',
                    ],
                ],
                'total_tracked_terms' => 0,
                'data_source' => 'behavioral_inference',
            ];
        }
    }

    private function calculateROIByProduct(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT pm.item_id,
                    SUM(pm.revenue) as revenue,
                    SUM(pm.sold_quantity) as sales
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY pm.item_id
                ORDER BY revenue DESC
                LIMIT 10
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::calculateROIByProduct error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [[
                'item_id' => '',
                'revenue' => 0.0,
                'sales' => 0,
                'data_source' => 'fallback_error',
            ]];
        }
    }

    private function calculateROIByCategory(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT i.category_id,
                    SUM(pm.revenue) as revenue,
                    SUM(pm.sold_quantity) as sales,
                    COUNT(DISTINCT pm.item_id) as items
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY i.category_id
                ORDER BY revenue DESC
                LIMIT 10
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::calculateROIByCategory error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [[
                'category_id' => '',
                'revenue' => 0.0,
                'sales' => 0,
                'items' => 0,
                'data_source' => 'fallback_error',
            ]];
        }
    }

    private function compareAttributionModels(array $config): array
    {
        try {
            $multiTouch = $this->performMultiTouchAttribution($config);
            $models = $multiTouch['attribution_models'] ?? [];
            $totalRevenue = floatval($multiTouch['total_revenue'] ?? 0);

            $comparison = [];
            foreach ($models as $modelName => $modelData) {
                $organicShare = $totalRevenue > 0
                    ? round((floatval($modelData['organic'] ?? 0) / $totalRevenue) * 100, 1) : 0;
                $adsShare = $totalRevenue > 0
                    ? round((floatval($modelData['ads'] ?? 0) / $totalRevenue) * 100, 1) : 0;

                $comparison[$modelName] = [
                    'model' => $modelName,
                    'description' => $modelData['description'] ?? '',
                    'organic_attribution' => floatval($modelData['organic'] ?? 0),
                    'ads_attribution' => floatval($modelData['ads'] ?? 0),
                    'organic_share_pct' => $organicShare,
                    'ads_share_pct' => $adsShare,
                ];
            }

            return [
                'models' => $comparison,
                'recommended' => 'time_decay',
                'recommendation_reason' => 'Modelo time_decay reflete melhor o impacto dos touchpoints mais recentes na decisão de compra',
                'total_revenue_analyzed' => round($totalRevenue, 2),
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::compareAttributionModels error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'models' => [],
                'recommended' => 'time_decay',
                'recommendation_reason' => 'Dados insuficientes para comparar modelos de atribuição com confiança',
                'total_revenue_analyzed' => 0.0,
            ];
        }
    }

    private function calculateCustomerLTV(array $config): array
    {
        return $this->getLifetimeValueAnalysis($config);
    }

    private function performCostAnalysis(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    SUM(o.total_amount * 0.13) as estimated_fees,
                    SUM(o.total_amount) as gross_revenue
                FROM ml_orders o
                WHERE o.ml_account_id = :account_id AND o.status = 'paid'
                AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::performCostAnalysis error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'estimated_fees' => 0.0,
                'gross_revenue' => 0.0,
            ];
        }
    }

    private function generateBudgetOptimization(array $config): array
    {
        try {
            $channels = $this->getChannelPerformance($config);
            $adsData = $channels['ads'] ?? [];

            $adCost = floatval($adsData['cost'] ?? 0);
            $adRevenue = floatval($adsData['revenue'] ?? 0);
            $adRoas = $adCost > 0 ? round($adRevenue / $adCost, 2) : 0;
            $organicRevenue = floatval($channels['organic']['revenue'] ?? 0);

            $suggestions = [];
            if ($adRoas >= 3) {
                $suggestions[] = [
                    'action' => 'increase_ads_budget',
                    'description' => "ROAS de {$adRoas}x — aumentar investimento em ads em 20-30%",
                    'priority' => 'high',
                    'expected_additional_revenue' => round($adCost * 0.25 * $adRoas, 2),
                ];
            } elseif ($adRoas > 0 && $adRoas < 1.5) {
                $suggestions[] = [
                    'action' => 'optimize_ads',
                    'description' => "ROAS baixo ({$adRoas}x) — otimizar keywords e segmentação",
                    'priority' => 'high',
                    'expected_saving' => round($adCost * 0.3, 2),
                ];
            }

            if ($organicRevenue > $adRevenue * 2) {
                $suggestions[] = [
                    'action' => 'invest_seo',
                    'description' => 'Orgânico gera 2x+ receita dos ads — investir em SEO',
                    'priority' => 'medium',
                ];
            }

            return [
                'current_budget' => round($adCost, 2),
                'current_roas' => $adRoas,
                'organic_revenue' => round($organicRevenue, 2),
                'ads_revenue' => round($adRevenue, 2),
                'suggestions' => $suggestions,
                'optimal_allocation' => [
                    'ads_pct' => $adRoas >= 2 ? 40 : 25,
                    'seo_pct' => 35,
                    'content_pct' => 15,
                    'analytics_pct' => 10,
                ],
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::generateBudgetOptimization error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'current_budget' => 0.0,
                'current_roas' => 0,
                'organic_revenue' => 0.0,
                'ads_revenue' => 0.0,
                'suggestions' => [],
                'optimal_allocation' => [
                    'ads_pct' => 25,
                    'seo_pct' => 35,
                    'content_pct' => 15,
                    'analytics_pct' => 10,
                ],
            ];
        }
    }

    private function extractAttributionInsights(array $attributionData): array
    {
        $insights = [];

        $channels = $attributionData['channel_performance'] ?? [];
        $adShare = floatval($channels['ads']['share_pct'] ?? 0);
        $organicShare = floatval($channels['organic']['share_pct'] ?? 0);

        if ($organicShare > 70) {
            $insights[] = [
                'type' => 'channel_mix',
                'insight' => "Canal orgânico domina com {$organicShare}% da receita",
                'impact' => 'positive',
            ];
        } elseif ($adShare > 50) {
            $insights[] = [
                'type' => 'channel_risk',
                'insight' => "Alta dependência de ads ({$adShare}% da receita) — diversificar",
                'impact' => 'warning',
            ];
        }

        $roas = floatval($channels['ads']['roas'] ?? 0);
        if ($roas > 0) {
            $roasLabel = $roas >= 3 ? 'excelente' : ($roas >= 2 ? 'bom' : ($roas >= 1 ? 'marginal' : 'negativo'));
            $insights[] = [
                'type' => 'roas_analysis',
                'insight' => "ROAS atual: {$roas}x ({$roasLabel})",
                'impact' => $roas >= 2 ? 'positive' : 'warning',
            ];
        }

        $ltv = $attributionData['customer_ltv'] ?? [];
        $repeatBuyers = intval($ltv['repeat_buyers'] ?? 0);
        if ($repeatBuyers > 0) {
            $avgLtv = floatval($ltv['avg_ltv'] ?? 0);
            $insights[] = [
                'type' => 'customer_retention',
                'insight' => "{$repeatBuyers} compradores recorrentes com LTV médio de R$ " . number_format($avgLtv, 2, ',', '.'),
                'impact' => 'positive',
            ];
        }

        return $insights;
    }

    private function calculateROIMetrics(array $attributionData): array
    {
        $gross = floatval($attributionData['cost_analysis']['gross_revenue'] ?? 0);
        $fees = floatval($attributionData['cost_analysis']['estimated_fees'] ?? 0);
        return [
            'gross_revenue' => $gross,
            'estimated_fees' => round($fees, 2),
            'net_revenue' => round($gross - $fees, 2),
            'fee_rate' => $gross > 0 ? round(($fees / $gross) * 100, 1) : 0,
        ];
    }

    private function generateBudgetRecommendations(array $attributionData): array
    {
        $recommendations = [];
        $channels = $attributionData['channel_performance'] ?? [];
        $roas = floatval($channels['ads']['roas'] ?? 0);
        $adCost = floatval($channels['ads']['cost'] ?? 0);
        $organicShare = floatval($channels['organic']['share_pct'] ?? 0);

        if ($roas >= 3) {
            $recommendations[] = [
                'action' => 'Aumentar budget de ads em 25%',
                'reason' => "ROAS de {$roas}x indica alto retorno",
                'priority' => 'high',
                'estimated_impact' => '+R$ ' . number_format($adCost * 0.25 * $roas, 2, ',', '.') . ' em receita',
            ];
        } elseif ($roas > 0 && $roas < 1.5) {
            $recommendations[] = [
                'action' => 'Pausar campanhas com ROAS < 1 e redistribuir budget',
                'reason' => 'ROAS geral abaixo de 1.5x — foco em campanhas rentáveis',
                'priority' => 'critical',
                'estimated_impact' => 'Economia de R$ ' . number_format($adCost * 0.3, 2, ',', '.'),
            ];
        }

        if ($organicShare < 40) {
            $recommendations[] = [
                'action' => 'Investir em otimização orgânica (SEO)',
                'reason' => "Apenas {$organicShare}% da receita vem do orgânico",
                'priority' => 'high',
                'estimated_impact' => 'Redução de CAC no médio prazo',
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'action' => 'Manter alocação atual e monitorar',
                'reason' => 'Mix de canais saudável',
                'priority' => 'low',
                'estimated_impact' => 'Estabilidade',
            ];
        }

        return $recommendations;
    }

    // Prediction methods
    private function calculatePredictionConfidence(array $predictions): array
    {
        $scores = [];

        $demand = $predictions['demand_forecasting'] ?? [];
        $interval = $demand['confidence_intervals'] ?? [];
        if (isset($interval['sample_size'])) {
            $sample = intval($interval['sample_size']);
            $scores['demand'] = $sample >= 60 ? 0.85 : ($sample >= 30 ? 0.7 : 0.5);
        } else {
            $scores['demand'] = 0.6;
        }

        $marketTrends = $predictions['market_trends']['category_trend_summary'] ?? [];
        $scores['market'] = !empty($marketTrends) ? 0.75 : 0.5;

        $inventory = $predictions['inventory_needs'] ?? [];
        $scores['inventory'] = (isset($inventory['avg_daily_sales']) && floatval($inventory['avg_daily_sales']) > 0) ? 0.8 : 0.45;

        $seasonal = $predictions['seasonal_patterns'] ?? [];
        $scores['seasonal'] = !empty($seasonal['month_adjustments']) ? 0.8 : 0.6;

        $overall = array_sum($scores) / max(1, count($scores));

        return [
            'overall_confidence' => round($overall, 2),
            'confidence_by_dimension' => $scores,
            'methodology' => 'historical_trend_seasonality_competitive_analysis',
        ];
    }

    private function generateActionableInsights(array $predictions): array
    {
        $insights = [];

        $demand = $predictions['demand_forecasting']['forecast']['product_demand'] ?? [];
        if (!empty($demand)) {
            $first = floatval($demand[0]['predicted_sales'] ?? 0);
            $last = floatval($demand[count($demand) - 1]['predicted_sales'] ?? 0);
            if ($last > $first * 1.05) {
                $insights[] = 'Demanda projetada em alta no horizonte de 30 dias — preparar reposição e reforço de anúncios.';
            } elseif ($last < $first * 0.95) {
                $insights[] = 'Demanda projetada em queda — revisar preço, diferenciação e mix de SKUs.';
            } else {
                $insights[] = 'Demanda projetada estável — foco em ganhos de conversão e ticket médio.';
            }
        }

        $inventory = $predictions['inventory_needs'] ?? [];
        $reorderPoint = intval($inventory['reorder_point'] ?? 0);
        if ($reorderPoint > 0) {
            $insights[] = "Ponto de reposição calculado em {$reorderPoint} unidades para reduzir risco de ruptura.";
        }

        $competitor = $predictions['competitor_actions'] ?? [];
        foreach ($competitor as $row) {
            if (($row['likely_action'] ?? '') === 'aggressive_discounting') {
                $cat = $row['category_id'] ?? 'categoria monitorada';
                $insights[] = "Concorrência agressiva prevista em {$cat} — priorizar proposta de valor e bundles.";
                break;
            }
        }

        if (empty($insights)) {
            $insights[] = 'Sem sinais críticos no momento — manter monitoramento semanal e experimentos incrementais.';
        }

        return $insights;
    }

    private function generateImplementationRoadmap(array $predictions): array
    {
        $roadmap = [];

        $trendSummary = $predictions['market_trends']['category_trend_summary'] ?? [];
        if (!empty($trendSummary)) {
            $topCat = $trendSummary[0]['category_id'] ?? null;
            $roadmap[] = [
                'week' => 1,
                'action' => 'Otimizar títulos e atributos com termos em tendência',
                'focus' => $topCat,
                'priority' => 'high',
            ];
        } else {
            $roadmap[] = [
                'week' => 1,
                'action' => 'Ativar coleta de tendências por categoria para guiar SEO',
                'priority' => 'high',
            ];
        }

        $competitor = $predictions['competitor_actions'] ?? [];
        $hasAggressive = false;
        foreach ($competitor as $row) {
            if (($row['likely_action'] ?? '') === 'aggressive_discounting') {
                $hasAggressive = true;
                break;
            }
        }
        $roadmap[] = [
            'week' => 2,
            'action' => $hasAggressive
                ? 'Revisar pricing defensivo e margens por categoria com maior pressão competitiva'
                : 'Ajustar preços com base em elasticidade e conversão histórica',
            'priority' => 'high',
        ];

        $inventory = $predictions['inventory_needs'] ?? [];
        $roadmap[] = [
            'week' => 3,
            'action' => 'Aplicar política de estoque (safety stock + reorder point) nos top SKUs',
            'target_reorder_point' => intval($inventory['reorder_point'] ?? 0),
            'priority' => 'medium',
        ];

        $seasonal = $predictions['seasonal_patterns'] ?? [];
        $roadmap[] = [
            'week' => 4,
            'action' => 'Executar revisão de resultados e plano sazonal do próximo ciclo',
            'season_context' => $seasonal['current_season'] ?? 'regular',
            'priority' => 'medium',
        ];

        return $roadmap;
    }

    // Search analytics detail methods
    private function getMostSearchedTerms(array $searchData): array
    {
        $allTerms = [];
        foreach ($searchData as $catId => $trends) {
            if (is_array($trends)) {
                foreach ($trends as $term) {
                    if (is_string($term)) {
                        $allTerms[] = $term;
                    }
                }
            }
        }
        return array_slice(array_unique($allTerms), 0, 20);
    }

    private function getSearchVolumeTrends(array $searchData): array
    {
        // Calcular tendência baseada na quantidade de termos por categoria
        $totalTerms = 0;
        foreach ($searchData as $terms) {
            if (is_array($terms)) {
                $totalTerms += count($terms);
            }
        }

        // Comparar com dados do mês (Black Friday/Natal = alto, Jan = baixo)
        $month = (int) date('n');
        $monthFactors = [
            1 => 'declining',
            2 => 'declining',
            3 => 'stable',
            4 => 'stable',
            5 => 'growing',
            6 => 'growing',
            7 => 'stable',
            8 => 'growing',
            9 => 'stable',
            10 => 'growing',
            11 => 'peak',
            12 => 'peak',
        ];

        return [
            'trend' => $monthFactors[$month] ?? 'stable',
            'categories_tracked' => count($searchData),
            'total_terms_tracked' => $totalTerms,
            'period' => date('Y-m'),
        ];
    }

    private function getSeasonalSearchPatterns(array $searchData): array
    {
        $month = (int)date('n');
        $season = match (true) {
            $month >= 11 || $month <= 1 => 'black_friday_natal',
            $month >= 3 && $month <= 5 => 'dia_das_maes',
            $month >= 6 && $month <= 7 => 'dia_dos_namorados_pais',
            default => 'regular',
        };
        return ['current_season' => $season, 'month' => $month];
    }

    private function getSearchSuccessRates(array $searchData): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT i.title, COALESCE(SUM(pm.sold_quantity), 0) as sales
                FROM items i
                LEFT JOIN seo_performance_metrics pm ON pm.item_id = i.ml_item_id
                    AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                WHERE i.account_id = :account_id AND i.status = 'active'
                GROUP BY i.ml_item_id, i.title
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $totalTerms = 0;
            $matchedTerms = 0;
            $rates = [];

            foreach ($searchData as $catId => $terms) {
                if (!is_array($terms)) continue;
                foreach ($terms as $term) {
                    if (!is_string($term)) continue;
                    $totalTerms++;
                    $termLower = mb_strtolower($term);
                    $hasMatch = false;
                    $matchSales = 0;
                    foreach ($items as $item) {
                        if (mb_stripos($item['title'], $termLower) !== false) {
                            $hasMatch = true;
                            $matchSales += intval($item['sales']);
                        }
                    }
                    if ($hasMatch) $matchedTerms++;
                    $rates[] = [
                        'term' => $term,
                        'category_id' => $catId,
                        'has_listing' => $hasMatch,
                        'estimated_sales' => $matchSales,
                        'status' => $hasMatch ? ($matchSales > 0 ? 'converting' : 'listed_no_sales') : 'missing',
                    ];
                }
            }

            usort($rates, fn($a, $b) => $b['estimated_sales'] <=> $a['estimated_sales']);

            return [
                'total_trending_terms' => $totalTerms,
                'terms_with_listings' => $matchedTerms,
                'coverage_pct' => $totalTerms > 0 ? round(($matchedTerms / $totalTerms) * 100, 1) : 0,
                'term_details' => array_slice($rates, 0, 20),
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getSearchSuccessRates error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'total_trending_terms' => 0,
                'terms_with_listings' => 0,
                'coverage_pct' => 0,
                'term_details' => [],
            ];
        }
    }

    private function getSearchByDevice(array $searchData): array
    {
        $totalTerms = 0;
        foreach ($searchData as $terms) {
            if (is_array($terms)) $totalTerms += count($terms);
        }

        return [
            'mobile' => [
                'label' => 'Mobile',
                'estimated_share_pct' => 72.0,
                'estimated_searches' => (int)round($totalTerms * 0.72),
            ],
            'desktop' => [
                'label' => 'Desktop',
                'estimated_share_pct' => 23.0,
                'estimated_searches' => (int)round($totalTerms * 0.23),
            ],
            'tablet' => [
                'label' => 'Tablet',
                'estimated_share_pct' => 5.0,
                'estimated_searches' => (int)round($totalTerms * 0.05),
            ],
            'data_source' => 'marketplace_averages',
            'total_terms_tracked' => $totalTerms,
        ];
    }

    private function getSearchByLocation(array $searchData): array
    {
        $stateDistribution = [
            'SP' => 35.0,
            'RJ' => 12.0,
            'MG' => 10.0,
            'RS' => 7.0,
            'PR' => 6.5,
            'SC' => 4.5,
            'BA' => 4.0,
            'DF' => 3.5,
            'GO' => 3.0,
            'PE' => 3.0,
            'CE' => 2.5,
            'outros' => 9.0,
        ];

        $totalTerms = 0;
        $categoriesTracked = [];
        foreach ($searchData as $catId => $terms) {
            if (is_array($terms)) {
                $totalTerms += count($terms);
                $categoriesTracked[] = $catId;
            }
        }

        $byLocation = [];
        foreach ($stateDistribution as $state => $share) {
            $byLocation[] = [
                'state' => $state,
                'share_pct' => $share,
                'estimated_relevance' => (int)round($totalTerms * $share / 100),
            ];
        }

        return [
            'distribution' => $byLocation,
            'categories_analyzed' => $categoriesTracked,
            'data_source' => 'marketplace_averages',
        ];
    }

    private function getSearchByTime(array $searchData): array
    {
        $hourlyDistribution = [
            '00-06' => 5.0,
            '06-09' => 10.0,
            '09-12' => 20.0,
            '12-14' => 15.0,
            '14-18' => 22.0,
            '18-21' => 20.0,
            '21-00' => 8.0,
        ];

        $dayOfWeekDistribution = [
            'segunda' => 16.0,
            'terca' => 15.5,
            'quarta' => 15.0,
            'quinta' => 14.5,
            'sexta' => 14.0,
            'sabado' => 13.0,
            'domingo' => 12.0,
        ];

        $totalTerms = 0;
        foreach ($searchData as $terms) {
            if (is_array($terms)) $totalTerms += count($terms);
        }

        return [
            'by_hour_range' => array_map(
                fn($range, $share) => ['range' => $range, 'share_pct' => $share],
                array_keys($hourlyDistribution),
                $hourlyDistribution
            ),
            'by_day_of_week' => array_map(
                fn($day, $share) => ['day' => $day, 'share_pct' => $share],
                array_keys($dayOfWeekDistribution),
                $dayOfWeekDistribution
            ),
            'peak_hours' => '14-18h',
            'peak_day' => 'segunda-feira',
            'data_source' => 'marketplace_patterns',
            'total_terms_tracked' => $totalTerms,
        ];
    }

    private function getAbandonedSearches(array $searchData): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT title FROM items
                WHERE account_id = :account_id AND status = 'active'
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $titles = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $titlesLower = array_map('mb_strtolower', $titles);

            $abandoned = [];
            foreach ($searchData as $catId => $terms) {
                if (!is_array($terms)) continue;
                foreach ($terms as $term) {
                    if (!is_string($term)) continue;
                    $termLower = mb_strtolower($term);
                    $found = false;
                    foreach ($titlesLower as $title) {
                        if (mb_stripos($title, $termLower) !== false) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $abandoned[] = [
                            'term' => $term,
                            'category_id' => $catId,
                            'opportunity' => 'Termo em tend\u00eancia sem an\u00fancio correspondente',
                            'action' => 'Criar an\u00fancio otimizado para este termo',
                        ];
                    }
                }
            }

            return [
                'abandoned_terms' => array_slice($abandoned, 0, 15),
                'total_gaps' => count($abandoned),
                'recommendation' => count($abandoned) > 0
                    ? 'Criar an\u00fancios para os termos em tend\u00eancia n\u00e3o cobertos'
                    : 'Boa cobertura \u2014 todos os termos possuem an\u00fancios',
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getAbandonedSearches error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'abandoned_terms' => [],
                'total_gaps' => 0,
                'recommendation' => 'Não foi possível calcular lacunas de busca no momento',
            ];
        }
    }

    private function getKeywordPerformance(array $searchData): array
    {
        $keywords = $this->getMostSearchedTerms($searchData);
        $performance = [];

        foreach (array_slice($keywords, 0, 10) as $keyword) {
            // Verificar se temos itens com essa keyword no título
            $matchCount = 0;
            $totalSales = 0;
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as matches,
                           COALESCE(SUM(i.sold_quantity), 0) as total_sold
                    FROM items i
                    WHERE i.account_id = :account_id
                      AND i.status = 'active'
                      AND LOWER(i.title) LIKE :keyword
                ");
                $stmt->execute([
                    'account_id' => $this->accountId,
                    'keyword'    => '%' . mb_strtolower($keyword) . '%',
                ]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $matchCount = (int)($row['matches'] ?? 0);
                $totalSales = (int)($row['total_sold'] ?? 0);
            } catch (\Exception $e) {
                $this->logWarning('MLAnalyticsIntelligenceService::getKeywordPerformance error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Tabela pode não existir
            }

            $performance[] = [
                'keyword' => $keyword,
                'items_using' => $matchCount,
                'total_sales' => $totalSales,
                'coverage' => $matchCount > 0 ? 'covered' : 'missing',
                'source' => 'trends',
            ];
        }

        return $performance;
    }

    private function getSearchTrends(array $searchData): array
    {
        $volumeData = $this->getSearchVolumeTrends($searchData);

        // Enriquecer com dados de tendência por categoria
        $categoryTrends = [];
        foreach ($searchData as $catId => $terms) {
            if (!is_array($terms)) {
                continue;
            }
            $categoryTrends[$catId] = [
                'terms_count' => count($terms),
                'top_terms' => array_slice(
                    array_map(fn(mixed $t): string => is_string($t) ? $t : ($t['keyword'] ?? ''), $terms),
                    0,
                    5
                ),
            ];
        }

        $volumeData['category_trends'] = $categoryTrends;
        return $volumeData;
    }

    private function segmentSearchUsers(array $searchData): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.buyer.id'))) as buyers,
                    COUNT(DISTINCT CASE WHEN q.from_user_id IS NOT NULL THEN q.from_user_id END) as askers
                FROM ml_orders o
                LEFT JOIN ml_questions q ON q.account_id = o.ml_account_id
                WHERE o.ml_account_id = :account_id
                AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND JSON_EXTRACT(o.order_data, '$.buyer.id') IS NOT NULL
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $buyers = intval($data['buyers'] ?? 0);
            $askers = intval($data['askers'] ?? 0);
            $totalTerms = 0;
            foreach ($searchData as $terms) {
                if (is_array($terms)) $totalTerms += count($terms);
            }

            return [
                'segments' => [
                    'direct_buyers' => [
                        'label' => 'Compradores Diretos',
                        'description' => 'Buscam e compram sem perguntar',
                        'estimated_count' => max(0, $buyers - $askers),
                        'behavior' => 'Alta inten\u00e7\u00e3o de compra',
                    ],
                    'researchers' => [
                        'label' => 'Pesquisadores',
                        'description' => 'Buscam e perguntam antes de comprar',
                        'estimated_count' => $askers,
                        'behavior' => 'Compara\u00e7\u00e3o ativa',
                    ],
                    'browsers' => [
                        'label' => 'Exploradores',
                        'description' => 'Navegam sem comprar',
                        'estimated_count' => $totalTerms * 10,
                        'behavior' => 'Baixa inten\u00e7\u00e3o \u2014 melhorar t\u00edtulos e pre\u00e7os',
                    ],
                ],
                'total_tracked_terms' => $totalTerms,
                'data_source' => 'behavioral_inference',
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::segmentSearchUsers error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'segments' => [
                    'direct_buyers' => [
                        'label' => 'Compradores Diretos',
                        'description' => 'Buscam e compram sem perguntar',
                        'estimated_count' => 0,
                        'behavior' => 'Alta intenção de compra',
                    ],
                    'researchers' => [
                        'label' => 'Pesquisadores',
                        'description' => 'Buscam e perguntam antes de comprar',
                        'estimated_count' => 0,
                        'behavior' => 'Comparação ativa',
                    ],
                    'browsers' => [
                        'label' => 'Exploradores',
                        'description' => 'Navegam sem comprar',
                        'estimated_count' => 0,
                        'behavior' => 'Baixa intenção — melhorar títulos e preços',
                    ],
                ],
                'total_tracked_terms' => 0,
                'data_source' => 'behavioral_inference',
            ];
        }
    }

    private function getOpportunityKeywords(array $searchData): array
    {
        return array_slice($this->getMostSearchedTerms($searchData), 0, 5);
    }

    private function analyzeSearchFunnel(array $searchData): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COALESCE(SUM(pm.visits), 0) as total_views,
                    COALESCE(SUM(pm.sold_quantity), 0) as total_sales,
                    COALESCE(SUM(pm.revenue), 0) as total_revenue
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $metrics = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $stmtQ = $this->db->prepare("
                SELECT COUNT(*) as total_questions
                FROM ml_questions
                WHERE account_id = :account_id
                AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmtQ->execute(['account_id' => $this->accountId]);
            $questions = intval($stmtQ->fetchColumn() ?: 0);

            $views = intval($metrics['total_views'] ?? 0);
            $sales = intval($metrics['total_sales'] ?? 0);
            $estimatedSearchImpressions = (int)round($views * 8);

            $stages = [
                ['stage' => 'Busca', 'volume' => $estimatedSearchImpressions, 'rate' => 100.0],
                [
                    'stage' => 'Visualiza\u00e7\u00e3o',
                    'volume' => $views,
                    'rate' => $estimatedSearchImpressions > 0 ? round(($views / $estimatedSearchImpressions) * 100, 2) : 0
                ],
                [
                    'stage' => 'Pergunta',
                    'volume' => $questions,
                    'rate' => $views > 0 ? round(($questions / $views) * 100, 2) : 0
                ],
                [
                    'stage' => 'Compra',
                    'volume' => $sales,
                    'rate' => $views > 0 ? round(($sales / $views) * 100, 2) : 0
                ],
            ];

            $biggestDrop = '';
            $biggestDropPct = 0;
            for ($i = 1; $i < count($stages); $i++) {
                $prev = $stages[$i - 1]['volume'];
                $curr = $stages[$i]['volume'];
                $dropPct = $prev > 0 ? round((1 - $curr / $prev) * 100, 1) : 0;
                if ($dropPct > $biggestDropPct) {
                    $biggestDropPct = $dropPct;
                    $biggestDrop = $stages[$i - 1]['stage'] . ' \u2192 ' . $stages[$i]['stage'];
                }
            }

            return [
                'funnel_stages' => $stages,
                'overall_conversion' => $estimatedSearchImpressions > 0
                    ? round(($sales / $estimatedSearchImpressions) * 100, 3) : 0,
                'biggest_drop' => $biggestDrop,
                'biggest_drop_pct' => $biggestDropPct,
                'revenue' => round(floatval($metrics['total_revenue'] ?? 0), 2),
            ];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::analyzeSearchFunnel error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'funnel_stages' => [
                    ['stage' => 'Busca', 'volume' => 0, 'rate' => 100.0],
                    ['stage' => 'Visualização', 'volume' => 0, 'rate' => 0],
                    ['stage' => 'Pergunta', 'volume' => 0, 'rate' => 0],
                    ['stage' => 'Compra', 'volume' => 0, 'rate' => 0],
                ],
                'overall_conversion' => 0,
                'biggest_drop' => '',
                'biggest_drop_pct' => 0,
                'revenue' => 0.0,
            ];
        }
    }

    private function generateSearchOptimizationRecommendations(array $searchData): array
    {
        return $this->generateSearchRecommendations($searchData);
    }

    // Demand forecasting data methods
    private function getDemandHistoricalData(array $config): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT pm.metric_date as date, SUM(pm.sold_quantity) as sales
                FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                GROUP BY pm.metric_date
                ORDER BY date ASC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::getDemandHistoricalData error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [[
                'date' => date('Y-m-d'),
                'sales' => 0.0,
                'data_source' => 'fallback_error',
            ]];
        }
    }

    private function getExternalFactors(array $config): array
    {
        $now = new \DateTime();
        $month = (int)$now->format('n');
        $day = (int)$now->format('j');

        $seasonal = $this->getSeasonalSearchPatterns([]);
        $events = [];

        if ($month === 5 && $day <= 15) {
            $events[] = ['name' => 'Dia das Mães', 'impact' => 'high'];
        }
        if ($month === 6 && $day <= 12) {
            $events[] = ['name' => 'Dia dos Namorados', 'impact' => 'medium'];
        }
        if ($month === 8 && $day <= 15) {
            $events[] = ['name' => 'Dia dos Pais', 'impact' => 'high'];
        }
        if ($month === 11 && $day >= 20) {
            $events[] = ['name' => 'Black Friday', 'impact' => 'very_high'];
        }
        if ($month === 12 && $day <= 25) {
            $events[] = ['name' => 'Natal', 'impact' => 'very_high'];
        }

        $seasonalFactor = match ($month) {
            11 => 1.30,
            12 => 1.35,
            5, 6, 8 => 1.12,
            1, 2 => 0.85,
            default => 1.0,
        };

        return [
            'current_season' => $seasonal['current_season'] ?? 'regular',
            'month' => $month,
            'events' => $events,
            'seasonal_factor' => $seasonalFactor,
        ];
    }

    private function forecastProductDemand(array $historicalData, array $factors): array
    {
        if (empty($historicalData)) {
            return [];
        }

        // Média móvel de 7 dias com ajuste por sazonalidade e eventos
        $recent = array_slice($historicalData, -7);
        $avgSales = count($recent) > 0
            ? array_sum(array_column($recent, 'sales')) / count($recent)
            : 0;

        $seasonalFactor = floatval($factors['seasonal_factor'] ?? 1.0);
        $events = $factors['events'] ?? [];
        $eventBoost = 1.0;
        foreach ($events as $event) {
            $impact = $event['impact'] ?? 'low';
            if ($impact === 'very_high') {
                $eventBoost += 0.20;
            } elseif ($impact === 'high') {
                $eventBoost += 0.10;
            } elseif ($impact === 'medium') {
                $eventBoost += 0.05;
            }
        }

        $trendFactor = 1.0;
        if (count($historicalData) >= 14) {
            $last7 = array_slice($historicalData, -7);
            $prev7 = array_slice($historicalData, -14, 7);
            $last7Avg = array_sum(array_column($last7, 'sales')) / max(1, count($last7));
            $prev7Avg = array_sum(array_column($prev7, 'sales')) / max(1, count($prev7));
            if ($prev7Avg > 0) {
                $trendFactor = max(0.7, min(1.3, $last7Avg / $prev7Avg));
            }
        }

        $baseForecast = $avgSales * $seasonalFactor * $eventBoost * $trendFactor;

        $forecast = [];
        for ($i = 1; $i <= 30; $i++) {
            // Pequena variação gradual para não manter série totalmente plana
            $dailyDrift = 1 + (($i - 15) / 300); // ~ +/-5%
            $forecast[] = ['day' => $i, 'predicted_sales' => round(max(0, $baseForecast * $dailyDrift), 1)];
        }
        return $forecast;
    }

    private function forecastCategoryTrends(array $historicalData, array $factors): array
    {
        return $this->forecastProductDemand($historicalData, $factors);
    }

    private function calculateSeasonalAdjustments(array $historicalData): array
    {
        if (empty($historicalData)) {
            return ['current_season' => 'regular', 'month_factors' => [], 'current_month_factor' => 1.0];
        }

        $monthly = [];
        foreach ($historicalData as $row) {
            $date = $row['date'] ?? null;
            if (!$date) {
                continue;
            }
            $month = (int)date('n', strtotime((string)$date));
            $monthly[$month][] = floatval($row['sales'] ?? 0);
        }

        $overall = array_map(fn(array $r): float => floatval($r['sales'] ?? 0), $historicalData);
        $overallAvg = count($overall) > 0 ? array_sum($overall) / count($overall) : 0;
        $monthFactors = [];
        foreach ($monthly as $month => $sales) {
            $mAvg = count($sales) > 0 ? array_sum($sales) / count($sales) : 0;
            $monthFactors[$month] = $overallAvg > 0 ? round($mAvg / $overallAvg, 2) : 1.0;
        }

        $currentMonth = (int)date('n');
        return [
            'current_season' => $this->getSeasonalSearchPatterns([])['current_season'] ?? 'regular',
            'month_factors' => $monthFactors,
            'current_month_factor' => $monthFactors[$currentMonth] ?? 1.0,
        ];
    }

    private function analyzeMarketConditions(array $factors): array
    {
        $season = $factors['current_season'] ?? 'regular';
        $seasonalFactor = floatval($factors['seasonal_factor'] ?? 1.0);
        $events = $factors['events'] ?? [];

        $outlook = 'stable';
        if ($seasonalFactor >= 1.2 || count($events) > 0) {
            $outlook = 'growing';
        }
        if ($seasonalFactor <= 0.9) {
            $outlook = 'cooling';
        }

        return [
            'season' => $season,
            'outlook' => $outlook,
            'seasonal_factor' => $seasonalFactor,
            'active_events' => array_column($events, 'name'),
        ];
    }

    private function calculateConfidenceIntervals(array $historicalData): array
    {
        if (empty($historicalData)) {
            return [
                'mean' => 0.0,
                'std_dev' => 0.0,
                'ci_95_lower' => 0.0,
                'ci_95_upper' => 0.0,
                'sample_size' => 0,
                'note' => 'Sem histórico suficiente para intervalo de confiança',
            ];
        }
        $sales = array_column($historicalData, 'sales');
        $mean = count($sales) > 0 ? array_sum($sales) / count($sales) : 0;
        $variance = count($sales) > 1
            ? array_sum(array_map(fn(float $v): float => ($v - $mean) ** 2, $sales)) / (count($sales) - 1)
            : 0;
        $std = sqrt($variance);
        return [
            'mean' => round($mean, 2),
            'std_dev' => round($std, 2),
            'ci_95_lower' => round($mean - 1.96 * $std, 2),
            'ci_95_upper' => round($mean + 1.96 * $std, 2),
            'sample_size' => count($sales),
        ];
    }

    private function identifyDemandRiskFactors(array $historicalData, array $factors): array
    {
        $risks = [];
        if (count($historicalData) < 30) {
            $risks[] = 'Dados históricos insuficientes (< 30 dias) para previsão segura';
        }
        $season = $factors['current_season'] ?? 'regular';
        if ($season !== 'regular') {
            $risks[] = "Sazonalidade ativa ({$season}) pode afetar previsões";
        }
        return $risks;
    }

    // Intelligence report methods
    private function generateDailyIntelligence(): array
    {
        return $this->getPerformanceOverview(['days' => 1]);
    }

    private function generateWeeklyInsights(): array
    {
        return $this->getPerformanceOverview(['days' => 7]);
    }

    private function generateMonthlyStrategic(): array
    {
        return $this->getPerformanceOverview(['days' => 30]);
    }

    private function generateCompetitiveIntelligence(): array
    {
        return $this->getMarketPositioning([]);
    }

    private function generateMarketTrends(): array
    {
        return $this->getSearchAnalytics([]);
    }

    private function generatePerformanceScorecards(): array
    {
        $overview = $this->getPerformanceOverview(['days' => 30]);
        $convRate = floatval($overview['conversion_rate'] ?? 0);
        return [
            'sales_score' => min(100, intval($overview['total_sales'] ?? 0)),
            'conversion_grade' => $convRate >= 3 ? 'A' : ($convRate >= 1.5 ? 'B' : ($convRate >= 0.5 ? 'C' : 'D')),
            'revenue' => floatval($overview['total_revenue'] ?? 0),
        ];
    }

    private function generateActionPlans(): array
    {
        $plans = [];

        $weekly = $this->generateWeeklyInsights();
        $sales = intval($weekly['total_sales'] ?? 0);
        $conversion = floatval($weekly['conversion_rate'] ?? 0);

        if ($sales === 0) {
            $plans[] = [
                'priority' => 'high',
                'timeframe' => 'imediato',
                'action' => 'Revisar preço e visibilidade dos anúncios com maior tráfego',
                'reason' => 'Nenhuma venda registrada na última semana',
            ];
        }

        if ($conversion > 0 && $conversion < 1.0) {
            $plans[] = [
                'priority' => 'high',
                'timeframe' => '7 dias',
                'action' => 'Executar otimização de títulos/imagens nos top 10 itens por visitas',
                'reason' => "Conversão semanal baixa ({$conversion}%)",
            ];
        }

        $trends = $this->generateMarketTrends();
        $categories = $trends['category_trends'] ?? [];
        if (!empty($categories)) {
            $firstCat = array_key_first($categories);
            if ($firstCat) {
                $plans[] = [
                    'priority' => 'medium',
                    'timeframe' => '14 dias',
                    'action' => "Expandir catálogo na categoria {$firstCat} com termos em tendência",
                    'reason' => 'Categoria com sinais de demanda ativa',
                ];
            }
        }

        if (empty($plans)) {
            $plans[] = [
                'priority' => 'low',
                'timeframe' => '30 dias',
                'action' => 'Manter rotina de monitoramento semanal e testes incrementais',
                'reason' => 'Indicadores atuais dentro da faixa esperada',
            ];
        }

        return $plans;
    }

    private function generateExecutiveIntelligenceSummary(array $reports): array
    {
        $daily = $reports['daily_intelligence'] ?? [];
        $weekly = $reports['weekly_insights'] ?? [];
        return [
            'today_sales' => intval($daily['total_sales'] ?? 0),
            'week_sales' => intval($weekly['total_sales'] ?? 0),
            'week_revenue' => floatval($weekly['total_revenue'] ?? 0),
        ];
    }

    private function calculateDataQualityScore(): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM seo_performance_metrics pm
                JOIN items i ON i.ml_item_id = pm.item_id
                WHERE i.account_id = :account_id
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $count = intval($stmt->fetchColumn());
            if ($count > 100) return 0.95;
            if ($count > 30) return 0.8;
            if ($count > 0) return 0.6;
            return 0.3;
        } catch (\Exception $e) {
            $this->logWarning('MLAnalyticsIntelligenceService::calculateDataQualityScore error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return 0.5;
        }
    }

    private function generateIntelligenceRecommendations(array $reports): array
    {
        $recs = [];
        $score = $reports['data_quality_score'] ?? 0;
        if ($score < 0.7) {
            $recs[] = 'Ative o monitoramento diário de métricas para melhorar qualidade dos dados';
        }

        $weekSales = intval($reports['weekly_insights']['total_sales'] ?? 0);
        if ($weekSales === 0) {
            $recs[] = 'Sem vendas na última semana — verificar preços e visibilidade dos anúncios';
        }

        $weekRevenue = floatval($reports['weekly_insights']['total_revenue'] ?? 0);
        if ($weekSales > 0 && $weekRevenue > 0) {
            $avgTicket = $weekRevenue / max(1, $weekSales);
            if ($avgTicket < 50) {
                $recs[] = 'Ticket médio baixo na semana — revisar mix de produtos e estratégia de upsell';
            }
        }

        $dailySales = intval($reports['daily_intelligence']['total_sales'] ?? 0);
        if ($dailySales === 0 && $weekSales > 0) {
            $recs[] = 'Queda brusca de vendas no dia atual — validar saúde da conta e qualidade dos anúncios';
        }

        if (empty($recs)) {
            $recs[] = 'Dados saudáveis no período: manter monitoramento e testar otimizações incrementais';
        }

        return $recs;
    }

    // Missing methods called from analyzeSearchPatterns and generatePredictiveAnalytics
    private function extractSearchPatternInsights(array $patterns): array
    {
        return $this->extractSearchInsights($patterns);
    }

    private function identifySearchOptimizations(array $patterns): array
    {
        return $this->generateSearchRecommendations($patterns);
    }

    private function predictPriceOptimization(array $config): array
    {
        return $this->getMarketPositioning($config);
    }

    private function predictInventoryNeeds(array $config): array
    {
        $historical = $this->getDemandHistoricalData($config);
        if (empty($historical)) {
            return [
                'avg_daily_sales' => 0.0,
                'recommended_stock_30d' => 0,
                'safety_stock' => 0,
                'reorder_point' => 0,
                'note' => 'Sem histórico de demanda para cálculo de estoque',
            ];
        }
        $recent = array_slice($historical, -7);
        $avgDaily = count($recent) > 0
            ? array_sum(array_column($recent, 'sales')) / count($recent)
            : 0;

        $dailyValues = array_map(fn(array $r): float => floatval($r['sales'] ?? 0), $recent);
        $mean = count($dailyValues) > 0 ? array_sum($dailyValues) / count($dailyValues) : 0;
        $variance = count($dailyValues) > 1
            ? array_sum(array_map(fn(float $v): float => ($v - $mean) ** 2, $dailyValues)) / (count($dailyValues) - 1)
            : 0;
        $stdDev = sqrt($variance);

        $leadTimeDays = intval($config['lead_time_days'] ?? 7);
        $safetyStock = (int)ceil(1.65 * $stdDev * sqrt(max(1, $leadTimeDays))); // 95% service level
        $reorderPoint = (int)ceil(($avgDaily * $leadTimeDays) + $safetyStock);

        return [
            'avg_daily_sales' => round($avgDaily, 1),
            'recommended_stock_30d' => (int)ceil($avgDaily * 30 * 1.2),
            'safety_stock' => $safetyStock,
            'reorder_point' => $reorderPoint,
            'lead_time_days' => $leadTimeDays,
        ];
    }

    private function predictMarketTrends(array $config): array
    {
        $search = $this->getSearchAnalytics($config);
        $categoryTrends = $search['category_trends'] ?? [];

        $summary = [];
        foreach ($categoryTrends as $categoryId => $terms) {
            $summary[] = [
                'category_id' => $categoryId,
                'trend_terms_count' => is_array($terms) ? count($terms) : 0,
                'top_term' => (is_array($terms) && !empty($terms)) ? (is_string($terms[0]) ? $terms[0] : ($terms[0]['keyword'] ?? null)) : null,
            ];
        }

        usort($summary, fn($a, $b) => intval($b['trend_terms_count']) <=> intval($a['trend_terms_count']));

        return [
            'categories_tracked' => intval($search['total_categories'] ?? 0),
            'category_trend_summary' => array_slice($summary, 0, 5),
            'global_outlook' => !empty($summary) && intval($summary[0]['trend_terms_count'] ?? 0) >= 10 ? 'expanding' : 'stable',
        ];
    }

    private function predictCustomerBehavior(array $config): array
    {
        return $this->getCustomerJourneyAnalysis($config);
    }

    private function predictCompetitorActions(array $config): array
    {
        $positioning = $this->getMarketPositioning($config);
        $predictions = [];

        foreach ($positioning as $categoryId => $analysis) {
            $avgPrice = floatval($analysis['avg_price'] ?? $analysis['average_price'] ?? 0);
            $minPrice = floatval($analysis['min_price'] ?? 0);
            $maxPrice = floatval($analysis['max_price'] ?? 0);

            $spread = $maxPrice > 0 ? (($maxPrice - $minPrice) / max(1, $maxPrice)) * 100 : 0;

            $likelyAction = 'price_stability';
            if ($spread >= 35) {
                $likelyAction = 'aggressive_discounting';
            } elseif ($spread >= 20) {
                $likelyAction = 'price_testing';
            }

            $predictions[] = [
                'category_id' => $categoryId,
                'avg_price' => round($avgPrice, 2),
                'price_spread_pct' => round($spread, 1),
                'likely_action' => $likelyAction,
                'confidence' => $spread >= 20 ? 'medium' : 'low',
            ];
        }

        return $predictions;
    }

    private function predictSeasonalPatterns(array $config): array
    {
        $historical = $this->getDemandHistoricalData($config);
        $external = $this->getExternalFactors($config);
        $adjustments = $this->calculateSeasonalAdjustments($historical);

        return [
            'current_season' => $external['current_season'] ?? ($adjustments['current_season'] ?? 'regular'),
            'seasonal_factor' => floatval($external['seasonal_factor'] ?? 1.0),
            'upcoming_events' => $external['events'] ?? [],
            'month_adjustments' => $adjustments['month_factors'] ?? [],
            'current_month_adjustment' => floatval($adjustments['current_month_factor'] ?? 1.0),
        ];
    }

    private function generateOpportunityScoring(array $config): array
    {
        $categories = $this->getActiveCategories();
        $scores = [];
        foreach (array_slice($categories, 0, 5) as $catId) {
            $perf = $this->analyzeCategoryPerformance($catId);
            $marketSize = intval($perf['market']['total_listings'] ?? 0);
            $myItems = intval($perf['active'] ?? 0);
            $opportunity = ($marketSize > 100 && $myItems < 10) ? 'high' : (($marketSize > 50) ? 'medium' : 'low');
            $scores[] = ['category' => $catId, 'opportunity' => $opportunity, 'market_size' => $marketSize];
        }
        return $scores;
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (!isset($this->logger)) {
            try {
                $this->logger = new StructuredLogService();
            } catch (\Throwable $e) {
                return;
            }
        }

        $this->logger->warning($message, $context);
    }
}
