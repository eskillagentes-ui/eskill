<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;
use App\Services\CacheService;
use App\Services\StructuredLogService;

/**
 * Competitor Intelligence System
 *
 * Features:
 * - Real-time competitor tracking
 * - Market share analysis
 * - Opportunity gap detection
 * - Competitive advantage analysis
 * - Price monitoring
 * - Alert system
 * - Intelligence reports
 */
class CompetitorIntelligenceService
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
        $this->config = $this->loadIntelligenceConfig();
    }

    /**
     * Start comprehensive competitor monitoring
     */
    public function startCompetitorMonitoring(array $monitoringConfig = []): array
    {
        try {
            $config = array_merge($this->config['monitoring'], $monitoringConfig);
            $results = [];

            // Get competitors to monitor
            $competitors = $this->getCompetitorsForMonitoring($config);

            foreach ($competitors as $competitor) {
                $monitoring = $this->monitorCompetitor($competitor, $config);
                $results[] = $monitoring;

                // Check for alerts
                if ($monitoring['alerts']) {
                    foreach ($monitoring['alerts'] as $alert) {
                        $this->createCompetitorAlert($alert);
                    }
                }
            }

            // Market intelligence summary
            $marketIntelligence = $this->generateMarketIntelligence($results);

            return [
                'success' => true,
                'competitors_monitored' => count($competitors),
                'alerts_generated' => count(array_filter($results, fn(array $r): bool => !empty($r['alerts']))),
                'monitoring_results' => $results,
                'market_intelligence' => $marketIntelligence,
                'summary' => $this->generateMonitoringSummary($results)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::startCompetitorMonitoring error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Analyze market opportunities
     */
    public function analyzeMarketOpportunities(array $categories = []): array
    {
        try {
            $opportunities = [];

            if (empty($categories)) {
                $categories = $this->getMonitoredCategories();
            }

            foreach ($categories as $category) {
                $categoryAnalysis = $this->analyzeCategoryOpportunities($category);
                $opportunities[] = $categoryAnalysis;
            }

            // Cross-category analysis
            $crossCategoryOpportunities = $this->findCrossCategoryOpportunities($opportunities);

            return [
                'success' => true,
                'categories_analyzed' => count($categories),
                'category_opportunities' => $opportunities,
                'cross_category_opportunities' => $crossCategoryOpportunities,
                'total_opportunities' => count($opportunities) + count($crossCategoryOpportunities),
                'opportunity_score' => $this->calculateOpportunityScore($opportunities, $crossCategoryOpportunities)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::analyzeMarketOpportunities error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Track competitive advantages
     */
    public function trackCompetitiveAdvantages(array $analysisConfig = []): array
    {
        try {
            $advantages = [];

            // Get our products and competitor products
            $ourProducts = $this->getOurProducts($analysisConfig);
            $competitorProducts = $this->getCompetitorProducts($analysisConfig);

            foreach ($ourProducts as $ourProduct) {
                $advantage = $this->calculateCompetitiveAdvantage($ourProduct, $competitorProducts);
                $advantages[] = $advantage;
            }

            // Overall competitive position
            $overallPosition = $this->calculateOverallCompetitivePosition($advantages);

            // Strategic recommendations
            $recommendations = $this->generateCompetitiveRecommendations($advantages, $overallPosition);

            return [
                'success' => true,
                'products_analyzed' => count($advantages),
                'competitive_advantages' => $advantages,
                'overall_position' => $overallPosition,
                'recommendations' => $recommendations,
                'action_plan' => $this->generateActionPlan($recommendations)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::trackCompetitiveAdvantages error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate intelligence reports
     */
    public function generateIntelligenceReports(array $reportConfig = []): array
    {
        try {
            $reports = [];

            $reportTypes = [
                'daily_competitor_report' => $this->generateDailyCompetitorReport(),
                'weekly_market_analysis' => $this->generateWeeklyMarketAnalysis(),
                'monthly_opportunity_report' => $this->generateMonthlyOpportunityReport(),
                'price_competition_analysis' => $this->generatePriceCompetitionAnalysis(),
                'market_share_trends' => $this->generateMarketShareTrends(),
                'emerging_threats' => $this->generateEmergingThreatsReport()
            ];

            foreach ($reportTypes as $reportType => $reportData) {
                if ($reportData) {
                    $reports[] = [
                        'type' => $reportType,
                        'generated_at' => time(),
                        'data' => $reportData,
                        'insights' => $this->extractReportInsights($reportType, $reportData)
                    ];
                }
            }

            // Executive summary
            $executiveSummary = $this->generateExecutiveSummary($reports);

            return [
                'success' => true,
                'reports_generated' => count($reports),
                'reports' => $reports,
                'executive_summary' => $executiveSummary,
                'action_items' => $this->extractActionItems($reports)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::generateIntelligenceReports error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Real-time market monitoring
     */
    public function realTimeMarketMonitoring(array $monitoringTargets): array
    {
        try {
            $monitoringResults = [];

            foreach ($monitoringTargets as $target) {
                $realtimeData = $this->getRealTimeMarketData($target);

                // Check for significant changes
                $changes = $this->detectSignificantChanges($target, $realtimeData);

                if (!empty($changes)) {
                    $monitoringResults[] = [
                        'target' => $target,
                        'realtime_data' => $realtimeData,
                        'changes' => $changes,
                        'alerts' => $this->generateRealTimeAlerts($changes),
                        'timestamp' => time()
                    ];
                }
            }

            return [
                'success' => true,
                'targets_monitored' => count($monitoringTargets),
                'significant_changes' => count($monitoringResults),
                'monitoring_results' => $monitoringResults,
                'market_pulse' => $this->calculateMarketPulse($monitoringResults),
                'recommendations' => $this->generateRealTimeRecommendations($monitoringResults)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::realTimeMarketMonitoring error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Monitor single competitor
     */
    private function monitorCompetitor(array $competitor, array $config): array
    {
        $competitorData = [
            'competitor_id' => $competitor['id'],
            'competitor_name' => $competitor['name'],
            'monitoring_timestamp' => time()
        ];

        // Monitor prices
        $priceMonitoring = $this->monitorCompetitorPrices($competitor);
        $competitorData['price_monitoring'] = $priceMonitoring;

        // Monitor listings
        $listingMonitoring = $this->monitorCompetitorListings($competitor);
        $competitorData['listing_monitoring'] = $listingMonitoring;

        // Monitor advertising
        $adMonitoring = $this->monitorCompetitorAdvertising($competitor);
        $competitorData['ad_monitoring'] = $adMonitoring;

        // Monitor reputation
        $reputationMonitoring = $this->monitorCompetitorReputation($competitor);
        $competitorData['reputation_monitoring'] = $reputationMonitoring;

        // Generate alerts
        $alerts = [];

        if ($priceMonitoring['significant_changes']) {
            $alerts[] = [
                'type' => 'price_change',
                'severity' => $priceMonitoring['severity'],
                'message' => $priceMonitoring['alert_message']
            ];
        }

        if ($listingMonitoring['new_products']) {
            $alerts[] = [
                'type' => 'new_listings',
                'severity' => 'medium',
                'message' => count($listingMonitoring['new_products']) . ' new products detected'
            ];
        }

        $competitorData['alerts'] = $alerts;

        return $competitorData;
    }

    /**
     * Analyze category opportunities
     */
    private function analyzeCategoryOpportunities(string $category): array
    {
        // Get category market data
        $marketData = $this->getCategoryMarketData($category);

        // Identify opportunity gaps
        $gaps = $this->identifyCategoryGaps($marketData);

        // Calculate opportunity scores
        $opportunityScore = $this->calculateCategoryOpportunityScore($marketData, $gaps);

        return [
            'category' => $category,
            'market_data' => $marketData,
            'identified_gaps' => $gaps,
            'opportunity_score' => $opportunityScore,
            'recommendations' => $this->generateCategoryRecommendations($category, $gaps),
            'estimated_market_size' => $this->estimateCategoryMarketSize($marketData),
            'competition_level' => $this->assessCompetitionLevel($marketData)
        ];
    }

    /**
     * Calculate competitive advantage
     */
    private function calculateCompetitiveAdvantage(array $ourProduct, array $competitorProducts): array
    {
        $advantages = [
            'price_advantage' => 0,
            'quality_advantage' => 0,
            'listing_advantage' => 0,
            'service_advantage' => 0,
            'overall_advantage' => 0
        ];

        foreach ($competitorProducts as $competitor) {
            // Price comparison
            if ($ourProduct['price'] < $competitor['price']) {
                $advantages['price_advantage'] += ($competitor['price'] - $ourProduct['price']) / $competitor['price'];
            }

            // Quality comparison (based on reviews, attributes, etc.)
            $qualityScore = $this->calculateQualityScore($ourProduct) - $this->calculateQualityScore($competitor);
            if ($qualityScore > 0) {
                $advantages['quality_advantage'] += $qualityScore;
            }

            // Listing comparison
            $listingScore = $this->calculateListingScore($ourProduct) - $this->calculateListingScore($competitor);
            if ($listingScore > 0) {
                $advantages['listing_advantage'] += $listingScore;
            }

            // Service comparison (shipping, response time, etc.)
            $serviceScore = $this->calculateServiceScore($ourProduct) - $this->calculateServiceScore($competitor);
            if ($serviceScore > 0) {
                $advantages['service_advantage'] += $serviceScore;
            }
        }

        // Calculate overall advantage
        $competitorCount = count($competitorProducts);
        if ($competitorCount > 0) {
            $advantages['price_advantage'] /= $competitorCount;
            $advantages['quality_advantage'] /= $competitorCount;
            $advantages['listing_advantage'] /= $competitorCount;
            $advantages['service_advantage'] /= $competitorCount;
        }

        $advantages['overall_advantage'] = (
            $advantages['price_advantage'] * 0.3 +
            $advantages['quality_advantage'] * 0.25 +
            $advantages['listing_advantage'] * 0.25 +
            $advantages['service_advantage'] * 0.2
        );

        return [
            'product_id' => $ourProduct['id'],
            'product_title' => $ourProduct['title'],
            'advantages' => $advantages,
            'competitive_position' => $this->assessCompetitivePosition($advantages),
            'strengths' => $this->identifyProductStrengths($advantages),
            'weaknesses' => $this->identifyProductWeaknesses($advantages),
            'action_needed' => $this->determineActionNeeded($advantages)
        ];
    }

    /**
     * Generate daily competitor report
     */
    private function generateDailyCompetitorReport(): array
    {
        $yesterday = time() - 86400;
        $today = time();

        $competitors = $this->getActiveCompetitors();
        $dailyChanges = [];

        foreach ($competitors as $competitor) {
            $changes = $this->getCompetitorChangesBetween($competitor['id'], $yesterday, $today);
            if (!empty($changes)) {
                $dailyChanges[] = [
                    'competitor' => $competitor,
                    'changes' => $changes,
                    'impact_level' => $this->assessChangeImpact($changes)
                ];
            }
        }

        return [
            'report_date' => date('Y-m-d'),
            'competitors_with_changes' => count($dailyChanges),
            'total_changes' => array_sum(array_column($dailyChanges, 'changes_count')),
            'high_impact_changes' => count(array_filter($dailyChanges, fn(array $c): bool => $c['impact_level'] === 'high')),
            'daily_changes' => $dailyChanges,
            'market_summary' => $this->generateDailyMarketSummary($dailyChanges),
            'recommendations' => $this->generateDailyRecommendations($dailyChanges)
        ];
    }

    /**
     * Load intelligence configuration
     */
    private function loadIntelligenceConfig(): array
    {
        return [
            'monitoring' => [
                'real_time_enabled' => true,
                'monitoring_interval_minutes' => 15,
                'price_change_threshold' => 5.0,
                'new_listing_threshold' => 1,
                'ad_change_threshold' => 10.0,
                'reputation_change_threshold' => 10
            ],
            'opportunities' => [
                'min_opportunity_score' => 7.0,
                'gap_analysis_enabled' => true,
                'market_size_threshold' => 10000,
                'competition_level_weights' => [
                    'low' => 0.8,
                    'medium' => 0.5,
                    'high' => 0.2
                ]
            ],
            'reports' => [
                'daily_reports_enabled' => true,
                'weekly_reports_enabled' => true,
                'monthly_reports_enabled' => true,
                'executive_summary_enabled' => true,
                'auto_email_reports' => true
            ],
            'alerts' => [
                'email_alerts' => true,
                'push_alerts' => true,
                'sms_alerts' => false,
                'webhook_alerts' => true,
                'alert_cooldown_minutes' => 30
            ]
        ];
    }

    /**
     * Generate weekly market analysis report
     */
    private function generateWeeklyMarketAnalysis(): array
    {
        $cacheKey = "weekly_market_analysis_{$this->accountId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $categories = $this->getMonitoredCategories();
        $weeklyData = [];

        foreach ($categories as $category) {
            try {
                $searchResults = $this->mlClient->searchItems(['category' => $category, 'sort' => 'relevance', 'limit' => 50]);
                $items = $searchResults['results'] ?? [];
                $prices = array_column($items, 'price');

                $weeklyData[] = [
                    'category' => $category,
                    'total_listings' => $searchResults['paging']['total'] ?? count($items),
                    'avg_price' => !empty($prices) ? round(array_sum($prices) / count($prices), 2) : 0,
                    'price_range' => ['min' => !empty($prices) ? min($prices) : 0, 'max' => !empty($prices) ? max($prices) : 0],
                    'top_sellers' => array_slice(array_unique(array_column($items, 'seller_id')), 0, 5),
                ];
            } catch (\Exception $e) {
                $this->logger->warning('CompetitorIntelligenceService::generateWeeklyMarketAnalysis error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                $weeklyData[] = ['category' => $category, 'error' => $e->getMessage()];
            }
        }

        $result = [
            'period' => 'weekly',
            'generated_at' => date('Y-m-d H:i:s'),
            'categories_analyzed' => count($categories),
            'data' => $weeklyData,
        ];

        $this->cache->set($cacheKey, $result, 86400);
        return $result;
    }

    /**
     * Generate monthly opportunity report
     */
    private function generateMonthlyOpportunityReport(): array
    {
        $categories = $this->getMonitoredCategories();
        $opportunities = [];

        foreach ($categories as $category) {
            $marketData = $this->getCategoryMarketData($category);
            $gaps = $this->identifyCategoryGaps($marketData);
            if (!empty($gaps)) {
                $opportunities[] = [
                    'category' => $category,
                    'gaps' => $gaps,
                    'score' => $this->calculateCategoryOpportunityScore($marketData, $gaps),
                ];
            }
        }

        usort($opportunities, fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'period' => 'monthly',
            'generated_at' => date('Y-m-d H:i:s'),
            'top_opportunities' => array_slice($opportunities, 0, 10),
            'total_found' => count($opportunities),
        ];
    }

    /**
     * Generate price competition analysis
     */
    private function generatePriceCompetitionAnalysis(): array
    {
        $stmt = $this->db->prepare("
            SELECT i.id, i.title, i.price, i.category_id
            FROM items i
            WHERE i.account_id = :account_id AND i.status = 'active'
            ORDER BY i.price DESC
            LIMIT 50
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $ourItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $analysis = [];
        foreach ($ourItems as $item) {
            try {
                $competitors = $this->mlClient->searchItems(['q' => $item['title'], 'limit' => 10]);
                $competitorPrices = array_column($competitors['results'] ?? [], 'price');
                $avgCompetitorPrice = !empty($competitorPrices) ? array_sum($competitorPrices) / count($competitorPrices) : 0;

                $analysis[] = [
                    'item_id' => $item['id'],
                    'title' => $item['title'],
                    'our_price' => (float)$item['price'],
                    'avg_competitor_price' => round($avgCompetitorPrice, 2),
                    'price_difference_pct' => $avgCompetitorPrice > 0
                        ? round((($item['price'] - $avgCompetitorPrice) / $avgCompetitorPrice) * 100, 2)
                        : 0,
                    'position' => $avgCompetitorPrice > 0
                        ? ($item['price'] < $avgCompetitorPrice ? 'below_market' : ($item['price'] > $avgCompetitorPrice * 1.1 ? 'above_market' : 'at_market'))
                        : 'unknown',
                ];
            } catch (\Exception $e) {
                $this->logger->warning('CompetitorIntelligenceService::generatePriceCompetitionAnalysis error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                continue;
            }
        }

        return [
            'items_analyzed' => count($analysis),
            'below_market' => count(array_filter($analysis, fn(array $a): bool => $a['position'] === 'below_market')),
            'at_market' => count(array_filter($analysis, fn(array $a): bool => $a['position'] === 'at_market')),
            'above_market' => count(array_filter($analysis, fn(array $a): bool => $a['position'] === 'above_market')),
            'details' => $analysis,
        ];
    }

    /**
     * Generate market share trends
     */
    private function generateMarketShareTrends(): array
    {
        $stmt = $this->db->prepare("
            SELECT DATE(o.date_created) as sale_date,
                   COUNT(DISTINCT oi.item_id) as items_sold,
                   SUM(oi.quantity) as total_quantity,
                   SUM(oi.unit_price * oi.quantity) as total_revenue
            FROM ml_orders o
            JOIN order_items oi ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
            WHERE o.ml_account_id = :account_id
            AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(o.date_created)
            ORDER BY sale_date DESC
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $salesData = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'period' => 'last_30_days',
            'daily_sales' => $salesData,
            'total_revenue' => array_sum(array_column($salesData, 'total_revenue')),
            'total_items_sold' => array_sum(array_column($salesData, 'total_quantity')),
            'avg_daily_revenue' => count($salesData) > 0
                ? round(array_sum(array_column($salesData, 'total_revenue')) / count($salesData), 2)
                : 0,
        ];
    }

    /**
     * Generate emerging threats report
     */
    private function generateEmergingThreatsReport(): array
    {
        $competitors = $this->getActiveCompetitors();
        $threats = [];

        foreach ($competitors as $competitor) {
            $changes = $this->getCompetitorChangesBetween(
                $competitor['competitor_id'],
                time() - (7 * 86400),
                time()
            );

            $priceDrops = array_filter($changes, fn(array $c): bool => ($c['type'] ?? '') === 'price_decrease');
            $newProducts = array_filter($changes, fn(array $c): bool => ($c['type'] ?? '') === 'new_listing');

            if (count($priceDrops) > 3 || count($newProducts) > 5) {
                $threats[] = [
                    'competitor_id' => $competitor['competitor_id'],
                    'competitor_name' => $competitor['competitor_name'],
                    'threat_level' => count($priceDrops) > 5 ? 'high' : 'medium',
                    'price_drops' => count($priceDrops),
                    'new_products' => count($newProducts),
                    'description' => "Concorrente {$competitor['competitor_name']} fez " . count($priceDrops) . " reduções de preço e adicionou " . count($newProducts) . " novos produtos nos últimos 7 dias",
                ];
            }
        }

        return [
            'threats_detected' => count($threats),
            'threats' => $threats,
            'overall_threat_level' => count($threats) > 3 ? 'high' : (count($threats) > 0 ? 'medium' : 'low'),
        ];
    }

    // ========================================================================
    // Helper Methods - Real Implementations
    // ========================================================================

    private function getCompetitorsForMonitoring(array $config): array
    {
        $cacheKey = "competitors_monitoring_{$this->accountId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT cm.competitor_id, cm.competitor_name, cm.monitoring_config, cm.last_scan
            FROM ml_competitor_monitoring cm
            WHERE cm.account_id = :account_id AND cm.is_active = 1
            ORDER BY cm.last_scan ASC
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $competitors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fallback: use competitor_watchlist if no monitoring records
        if (empty($competitors)) {
            $stmt2 = $this->db->prepare("
                SELECT DISTINCT competitor_seller_id as competitor_id,
                       nickname as competitor_name
                FROM competitor_watchlist
                WHERE account_id = :account_id AND status = 'active'
            ");
            $stmt2->execute(['account_id' => $this->accountId]);
            $competitors = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        }

        $this->cache->set($cacheKey, $competitors, 1800);
        return $competitors;
    }

    private function createCompetitorAlert(array $alert): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ml_competitor_alerts (competitor_id, alert_type, severity, alert_data, message, action_required)
                VALUES (:competitor_id, :alert_type, :severity, :alert_data, :message, :action_required)
            ");
            $stmt->execute([
                'competitor_id' => $alert['competitor_id'] ?? $alert['type'] ?? 'unknown',
                'alert_type' => $alert['type'] ?? 'general',
                'severity' => $alert['severity'] ?? 'medium',
                'alert_data' => json_encode($alert),
                'message' => $alert['message'] ?? 'Alert triggered',
                'action_required' => !empty($alert['action_required']) ? 1 : 0,
            ]);
        } catch (\Exception $e) {
            // Log but don't fail the monitoring process
            log_error('CompetitorIntelligence: Failed to create alert', ['error' => $e->getMessage()]);
        }
    }

    private function generateMarketIntelligence(array $results): array
    {
        $totalAlerts = 0;
        $priceChanges = 0;
        $newListings = 0;

        foreach ($results as $result) {
            $alerts = $result['alerts'] ?? [];
            $totalAlerts += count($alerts);
            foreach ($alerts as $alert) {
                if (($alert['type'] ?? '') === 'price_change') {
                    $priceChanges++;
                }
                if (($alert['type'] ?? '') === 'new_listings') {
                    $newListings++;
                }
            }
        }

        $avgPriceMonitoring = [];
        foreach ($results as $result) {
            if (isset($result['price_monitoring']['avg_price'])) {
                $avgPriceMonitoring[] = $result['price_monitoring']['avg_price'];
            }
        }

        return [
            'total_alerts' => $totalAlerts,
            'price_change_events' => $priceChanges,
            'new_listing_events' => $newListings,
            'market_avg_price' => !empty($avgPriceMonitoring) ? round(array_sum($avgPriceMonitoring) / count($avgPriceMonitoring), 2) : 0,
            'market_volatility' => $priceChanges > count($results) * 0.5 ? 'high' : ($priceChanges > 0 ? 'moderate' : 'stable'),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function generateMonitoringSummary(array $results): array
    {
        $competitorsWithAlerts = count(array_filter($results, fn(array $r): bool => !empty($r['alerts'])));
        $totalAlerts = array_sum(array_map(fn(array $r): int => count($r['alerts'] ?? []), $results));

        return [
            'total_competitors' => count($results),
            'competitors_with_alerts' => $competitorsWithAlerts,
            'total_alerts' => $totalAlerts,
            'monitoring_timestamp' => date('Y-m-d H:i:s'),
            'status' => $totalAlerts > 10 ? 'needs_attention' : ($totalAlerts > 0 ? 'normal' : 'quiet'),
        ];
    }

    private function getMonitoredCategories(): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT category_id
            FROM items
            WHERE account_id = :account_id AND status = 'active' AND category_id IS NOT NULL
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function findCrossCategoryOpportunities(array $opportunities): array
    {
        $crossOpportunities = [];
        $categories = array_column($opportunities, 'category');

        // Find categories with high opportunity that could complement each other
        $highOpportunity = array_filter($opportunities, fn(array $o): bool => ($o['opportunity_score'] ?? 0) > 7.0);

        foreach ($highOpportunity as $i => $opp1) {
            foreach (array_slice($highOpportunity, $i + 1) as $opp2) {
                $crossOpportunities[] = [
                    'categories' => [$opp1['category'], $opp2['category']],
                    'combined_score' => (($opp1['opportunity_score'] ?? 0) + ($opp2['opportunity_score'] ?? 0)) / 2,
                    'type' => 'cross_category_bundle',
                    'recommendation' => "Considere criar kits combinando produtos de {$opp1['category']} e {$opp2['category']}",
                ];
            }
        }

        return $crossOpportunities;
    }

    private function calculateOpportunityScore(array $opportunities, array $crossCategory): float
    {
        if (empty($opportunities)) {
            return 0.0;
        }

        $scores = array_column($opportunities, 'opportunity_score');
        $avgScore = array_sum($scores) / count($scores);
        $crossBonus = count($crossCategory) * 0.5;

        return min(10.0, round($avgScore + $crossBonus, 2));
    }

    private function getOurProducts(array $config): array
    {
        $limit = $config['limit'] ?? 50;
        $categoryFilter = '';
        $params = ['account_id' => $this->accountId];

        if (!empty($config['category_id'])) {
            $categoryFilter = 'AND i.category_id = :category_id';
            $params['category_id'] = $config['category_id'];
        }

        $stmt = $this->db->prepare("
            SELECT i.id, i.title, i.price, i.category_id, i.status,
                   COALESCE(m.available_quantity, 0) as stock,
                   COALESCE(m.sold_quantity, 0) as sold_quantity
            FROM items i
            LEFT JOIN ml_items m ON i.ml_item_id = m.id
            WHERE i.account_id = :account_id AND i.status = 'active' {$categoryFilter}
            ORDER BY i.price DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getCompetitorProducts(array $config): array
    {
        $cacheKey = "competitor_products_{$this->accountId}_" . md5(json_encode($config));
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT cw.competitor_item_id as id, cw.title, cw.price,
                   cw.sold_quantity, cw.available_quantity as stock,
                   cw.category_id, cw.seo_score, cw.pictures_count,
                   cw.attributes_filled, cw.free_shipping, cw.shipping_mode
            FROM competitor_watchlist cw
            WHERE cw.account_id = :account_id AND cw.status = 'active'
            ORDER BY cw.sold_quantity DESC
            LIMIT 100
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->cache->set($cacheKey, $products, 3600);
        return $products;
    }

    private function calculateOverallCompetitivePosition(array $advantages): array
    {
        if (empty($advantages)) {
            return ['position' => 'unknown', 'score' => 0, 'trend' => 'stable'];
        }

        $totalScore = 0;
        $strongCount = 0;
        $weakCount = 0;

        foreach ($advantages as $advantage) {
            $overall = $advantage['advantages']['overall_advantage'] ?? 0;
            $totalScore += $overall;
            if ($overall > 0.3) {
                $strongCount++;
            } elseif ($overall < -0.1) {
                $weakCount++;
            }
        }

        $avgScore = $totalScore / count($advantages);

        if ($avgScore > 0.3) {
            $position = 'leader';
        } elseif ($avgScore > 0.1) {
            $position = 'strong';
        } elseif ($avgScore > -0.1) {
            $position = 'neutral';
        } else {
            $position = 'weak';
        }

        return [
            'position' => $position,
            'score' => round($avgScore, 4),
            'strong_products' => $strongCount,
            'weak_products' => $weakCount,
            'total_products' => count($advantages),
            'trend' => $strongCount > $weakCount ? 'improving' : ($weakCount > $strongCount ? 'declining' : 'stable'),
        ];
    }

    private function generateCompetitiveRecommendations(array $advantages, array $position): array
    {
        $recommendations = [];

        if ($position['position'] === 'weak') {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'price_review',
                'message' => 'Revisar estratégia de preços — posição competitiva fraca em múltiplos produtos',
            ];
        }

        foreach ($advantages as $adv) {
            $advData = $adv['advantages'] ?? [];
            if (($advData['price_advantage'] ?? 0) < -0.15) {
                $recommendations[] = [
                    'priority' => 'high',
                    'type' => 'price_adjustment',
                    'product_id' => $adv['product_id'] ?? '',
                    'message' => "Produto \"{$adv['product_title']}\" está acima do preço médio dos concorrentes",
                ];
            }
            if (($advData['listing_advantage'] ?? 0) < -0.1) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'type' => 'listing_optimization',
                    'product_id' => $adv['product_id'] ?? '',
                    'message' => "Melhorar qualidade do anúncio para \"{$adv['product_title']}\"",
                ];
            }
        }

        return array_slice($recommendations, 0, 20);
    }

    private function generateActionPlan(array $recommendations): array
    {
        $plan = [
            'immediate' => [],
            'short_term' => [],
            'long_term' => [],
        ];

        foreach ($recommendations as $rec) {
            $priority = $rec['priority'] ?? 'medium';
            if ($priority === 'high') {
                $plan['immediate'][] = [
                    'action' => $rec['message'],
                    'type' => $rec['type'],
                    'product_id' => $rec['product_id'] ?? null,
                    'deadline' => date('Y-m-d', strtotime('+3 days')),
                ];
            } elseif ($priority === 'medium') {
                $plan['short_term'][] = [
                    'action' => $rec['message'],
                    'type' => $rec['type'],
                    'product_id' => $rec['product_id'] ?? null,
                    'deadline' => date('Y-m-d', strtotime('+14 days')),
                ];
            } else {
                $plan['long_term'][] = [
                    'action' => $rec['message'],
                    'type' => $rec['type'],
                    'deadline' => date('Y-m-d', strtotime('+30 days')),
                ];
            }
        }

        return $plan;
    }

    private function extractReportInsights(string $reportType, array $reportData): array
    {
        $insights = [];

        switch ($reportType) {
            case 'daily_competitor_report':
                $highImpact = $reportData['high_impact_changes'] ?? 0;
                if ($highImpact > 0) {
                    $insights[] = "{$highImpact} mudança(s) de alto impacto detectada(s) nos concorrentes";
                }
                break;
            case 'price_competition_analysis':
                $aboveMarket = $reportData['above_market'] ?? 0;
                $belowMarket = $reportData['below_market'] ?? 0;
                if ($aboveMarket > 0) {
                    $insights[] = "{$aboveMarket} produto(s) acima do preço de mercado";
                }
                if ($belowMarket > 0) {
                    $insights[] = "{$belowMarket} produto(s) com preço competitivo (abaixo do mercado)";
                }
                break;
            case 'emerging_threats':
                $threats = $reportData['threats_detected'] ?? 0;
                if ($threats > 0) {
                    $insights[] = "{$threats} ameaça(s) emergente(s) identificada(s)";
                }
                break;
            default:
                $insights[] = "Relatório {$reportType} gerado com sucesso";
        }

        return $insights;
    }

    private function extractActionItems(array $reports): array
    {
        $actionItems = [];

        foreach ($reports as $report) {
            $insights = $report['insights'] ?? [];
            $reportType = $report['type'] ?? 'unknown';

            foreach ($insights as $insight) {
                $actionItems[] = [
                    'source' => $reportType,
                    'insight' => $insight,
                    'priority' => $this->assessInsightPriority($insight),
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        usort($actionItems, fn($a, $b) => ($a['priority'] === 'high' ? 0 : 1) - ($b['priority'] === 'high' ? 0 : 1));
        return $actionItems;
    }

    private function assessInsightPriority(string $insight): string
    {
        $highKeywords = ['alto impacto', 'ameaça', 'acima do preço', 'crítico'];
        foreach ($highKeywords as $kw) {
            if (stripos($insight, $kw) !== false) {
                return 'high';
            }
        }
        return 'medium';
    }

    private function getRealTimeMarketData(array $target): array
    {
        $cacheKey = "realtime_market_{$this->accountId}_" . md5(json_encode($target));
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $searchQuery = $target['query'] ?? $target['category_id'] ?? '';
        $data = [];

        try {
            $searchResults = $this->mlClient->searchItems(['q' => $searchQuery, 'limit' => 20, 'sort' => 'relevance']);
            $items = $searchResults['results'] ?? [];
            $prices = array_column($items, 'price');

            $data = [
                'query' => $searchQuery,
                'total_results' => $searchResults['paging']['total'] ?? count($items),
                'avg_price' => !empty($prices) ? round(array_sum($prices) / count($prices), 2) : 0,
                'min_price' => !empty($prices) ? min($prices) : 0,
                'max_price' => !empty($prices) ? max($prices) : 0,
                'top_items' => array_slice($items, 0, 5),
                'timestamp' => time(),
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::getRealTimeMarketData error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            $data = ['error' => $e->getMessage(), 'timestamp' => time()];
        }

        $this->cache->set($cacheKey, $data, 300); // Cache 5 min for real-time
        return $data;
    }

    private function detectSignificantChanges(array $target, array $data): array
    {
        $changes = [];
        $threshold = $this->config['monitoring']['price_change_threshold'] ?? 5.0;

        // Check if price changed significantly from stored average
        if (!empty($target['last_avg_price']) && !empty($data['avg_price'])) {
            $priceDiff = abs($data['avg_price'] - $target['last_avg_price']);
            $pctChange = ($priceDiff / $target['last_avg_price']) * 100;

            if ($pctChange > $threshold) {
                $changes[] = [
                    'type' => 'price_shift',
                    'severity' => $pctChange > 15 ? 'high' : 'medium',
                    'old_value' => $target['last_avg_price'],
                    'new_value' => $data['avg_price'],
                    'change_pct' => round($pctChange, 2),
                    'message' => "Preço médio de mercado mudou {$pctChange}% para query '{$target['query']}'",
                ];
            }
        }

        // Check for high volume changes
        if (!empty($target['last_total_results']) && !empty($data['total_results'])) {
            $volumeChange = $data['total_results'] - $target['last_total_results'];
            $volumePct = ($volumeChange / max(1, $target['last_total_results'])) * 100;

            if (abs($volumePct) > 20) {
                $changes[] = [
                    'type' => 'volume_shift',
                    'severity' => abs($volumePct) > 50 ? 'high' : 'low',
                    'change_pct' => round($volumePct, 2),
                    'message' => "Volume de listagens mudou {$volumePct}% para query '{$target['query']}'",
                ];
            }
        }

        return $changes;
    }

    private function generateRealTimeAlerts(array $changes): array
    {
        $alerts = [];
        foreach ($changes as $change) {
            $alerts[] = [
                'type' => $change['type'],
                'severity' => $change['severity'],
                'message' => $change['message'],
                'created_at' => date('Y-m-d H:i:s'),
                'requires_action' => $change['severity'] === 'high',
            ];
        }
        return $alerts;
    }

    private function calculateMarketPulse(array $monitoringResults): array
    {
        $totalChanges = 0;
        $highSeverity = 0;

        foreach ($monitoringResults as $result) {
            $changes = $result['changes'] ?? [];
            $totalChanges += count($changes);
            $highSeverity += count(array_filter($changes, fn(array $c): bool => ($c['severity'] ?? '') === 'high'));
        }

        if ($highSeverity > 3) {
            $pulse = 'volatile';
        } elseif ($totalChanges > 5) {
            $pulse = 'active';
        } elseif ($totalChanges > 0) {
            $pulse = 'moderate';
        } else {
            $pulse = 'calm';
        }

        return [
            'status' => $pulse,
            'total_changes_detected' => $totalChanges,
            'high_severity_count' => $highSeverity,
            'targets_with_changes' => count($monitoringResults),
            'timestamp' => time(),
        ];
    }

    private function generateRealTimeRecommendations(array $results): array
    {
        $recommendations = [];

        foreach ($results as $result) {
            foreach ($result['changes'] ?? [] as $change) {
                if ($change['type'] === 'price_shift' && ($change['change_pct'] ?? 0) > 10) {
                    $recommendations[] = [
                        'type' => 'price_review',
                        'priority' => 'high',
                        'message' => "Revisar preços — mercado teve variação de {$change['change_pct']}%",
                        'target' => $result['target']['query'] ?? '',
                    ];
                }
                if ($change['type'] === 'volume_shift' && ($change['change_pct'] ?? 0) > 30) {
                    $recommendations[] = [
                        'type' => 'stock_review',
                        'priority' => 'medium',
                        'message' => "Aumento significativo de listagens — verificar estoque e posicionamento",
                        'target' => $result['target']['query'] ?? '',
                    ];
                }
            }
        }

        return $recommendations;
    }

    private function monitorCompetitorPrices(array $competitor): array
    {
        $competitorId = $competitor['competitor_id'] ?? '';

        $stmt = $this->db->prepare("
            SELECT cw.price, cw.title, cw.competitor_item_id
            FROM competitor_watchlist cw
            WHERE cw.competitor_seller_id = :competitor_id
            AND cw.account_id = :account_id AND cw.status = 'active'
        ");
        $stmt->execute([
            'competitor_id' => $competitorId,
            'account_id' => $this->accountId,
        ]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $prices = array_column($items, 'price');
        $significantChanges = false;
        $alertMessage = '';
        $severity = 'low';

        // Compare with any stored historical data
        if (!empty($prices)) {
            $avgPrice = array_sum($prices) / count($prices);

            $stmt2 = $this->db->prepare("
                SELECT cd.extracted_data
                FROM ml_competitor_data cd
                WHERE cd.competitor_id = :competitor_id AND cd.data_type = 'prices'
                ORDER BY cd.analyzed_at DESC LIMIT 1
            ");
            $stmt2->execute(['competitor_id' => $competitorId]);
            $lastData = $stmt2->fetch(\PDO::FETCH_ASSOC);

            if ($lastData && !empty($lastData['extracted_data'])) {
                $previousData = json_decode($lastData['extracted_data'], true);
                $previousAvg = $previousData['avg_price'] ?? 0;

                if ($previousAvg > 0) {
                    $changePct = (($avgPrice - $previousAvg) / $previousAvg) * 100;
                    if (abs($changePct) > ($this->config['monitoring']['price_change_threshold'] ?? 5.0)) {
                        $significantChanges = true;
                        $severity = abs($changePct) > 15 ? 'high' : 'medium';
                        $alertMessage = sprintf(
                            "Concorrente %s: preço médio mudou %.1f%% (de R$%.2f para R$%.2f)",
                            $competitor['competitor_name'] ?? $competitorId,
                            $changePct,
                            $previousAvg,
                            $avgPrice
                        );
                    }
                }
            }

            // Store current data for future comparison
            try {
                $stmt3 = $this->db->prepare("
                    INSERT INTO ml_competitor_data (competitor_id, data_type, raw_data, extracted_data, confidence_score)
                    VALUES (:competitor_id, 'prices', :raw_data, :extracted_data, 0.9)
                ");
                $stmt3->execute([
                    'competitor_id' => $competitorId,
                    'raw_data' => json_encode($items),
                    'extracted_data' => json_encode([
                        'avg_price' => round($avgPrice, 2),
                        'min_price' => min($prices),
                        'max_price' => max($prices),
                        'item_count' => count($items),
                    ]),
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('CompetitorIntelligenceService::monitorCompetitorPrices error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
                // Non-blocking
            }
        }

        return [
            'items_tracked' => count($items),
            'avg_price' => !empty($prices) ? round(array_sum($prices) / count($prices), 2) : 0,
            'price_range' => !empty($prices) ? ['min' => min($prices), 'max' => max($prices)] : [],
            'significant_changes' => $significantChanges,
            'severity' => $severity,
            'alert_message' => $alertMessage,
        ];
    }

    private function monitorCompetitorListings(array $competitor): array
    {
        $competitorId = $competitor['competitor_id'] ?? '';

        $stmt = $this->db->prepare("
            SELECT cw.competitor_item_id, cw.title, cw.price, cw.created_at
            FROM competitor_watchlist cw
            WHERE cw.competitor_seller_id = :competitor_id
            AND cw.account_id = :account_id AND cw.status = 'active'
            ORDER BY cw.created_at DESC
        ");
        $stmt->execute([
            'competitor_id' => $competitorId,
            'account_id' => $this->accountId,
        ]);
        $allListings = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Identify new products (created in last 24h)
        $recentThreshold = date('Y-m-d H:i:s', time() - 86400);
        $newProducts = array_filter($allListings, fn(array $l): bool => ($l['created_at'] ?? '') > $recentThreshold);

        return [
            'total_listings' => count($allListings),
            'new_products' => array_values($newProducts),
            'new_products_count' => count($newProducts),
        ];
    }

    private function monitorCompetitorAdvertising(array $competitor): array
    {
        // Check competitor_data for ad monitoring
        $competitorId = $competitor['competitor_id'] ?? '';

        $stmt = $this->db->prepare("
            SELECT cd.extracted_data, cd.analyzed_at
            FROM ml_competitor_data cd
            WHERE cd.competitor_id = :competitor_id AND cd.data_type = 'advertising'
            ORDER BY cd.analyzed_at DESC LIMIT 1
        ");
        $stmt->execute(['competitor_id' => $competitorId]);
        $lastAdData = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'has_ad_data' => !empty($lastAdData),
            'last_checked' => $lastAdData['analyzed_at'] ?? null,
            'ad_activity' => $lastAdData ? json_decode($lastAdData['extracted_data'], true) : [],
        ];
    }

    private function monitorCompetitorReputation(array $competitor): array
    {
        $competitorId = $competitor['competitor_id'] ?? '';

        try {
            // Try ML API for seller reputation
            $sellerData = $this->mlClient->get("/users/{$competitorId}");
            $reputation = $sellerData['seller_reputation'] ?? [];

            return [
                'power_seller' => $reputation['power_seller_status'] ?? null,
                'level_id' => $reputation['level_id'] ?? null,
                'transactions_completed' => $reputation['transactions']['completed'] ?? 0,
                'positive_rating' => $reputation['transactions']['ratings']['positive'] ?? 0,
                'negative_rating' => $reputation['transactions']['ratings']['negative'] ?? 0,
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::monitorCompetitorReputation error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'power_seller' => null,
                'level_id' => null,
                'error' => 'Dados de reputação indisponíveis',
            ];
        }
    }

    private function getCategoryMarketData(string $category): array
    {
        $cacheKey = "category_market_{$category}";
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $data = ['category_id' => $category, 'total_listings' => 0, 'sellers' => [], 'avg_price' => 0];

        try {
            $results = $this->mlClient->searchItems(['category' => $category, 'limit' => 50]);
            $items = $results['results'] ?? [];
            $prices = array_column($items, 'price');
            $sellers = array_unique(array_column($items, 'seller_id'));

            $data = [
                'category_id' => $category,
                'total_listings' => $results['paging']['total'] ?? count($items),
                'avg_price' => !empty($prices) ? round(array_sum($prices) / count($prices), 2) : 0,
                'min_price' => !empty($prices) ? min($prices) : 0,
                'max_price' => !empty($prices) ? max($prices) : 0,
                'unique_sellers' => count($sellers),
                'sellers' => $sellers,
                'price_distribution' => $this->calculatePriceDistribution($prices),
                'sample_items' => array_slice($items, 0, 10),
            ];
        } catch (\Exception $e) {
            $this->logger->warning('CompetitorIntelligenceService::getCategoryMarketData error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            $data['error'] = $e->getMessage();
        }

        $this->cache->set($cacheKey, $data, 3600);
        return $data;
    }

    private function calculatePriceDistribution(array $prices): array
    {
        if (empty($prices)) {
            return [];
        }

        sort($prices);
        $count = count($prices);

        return [
            'p10' => $prices[(int)($count * 0.1)] ?? 0,
            'p25' => $prices[(int)($count * 0.25)] ?? 0,
            'p50' => $prices[(int)($count * 0.5)] ?? 0,
            'p75' => $prices[(int)($count * 0.75)] ?? 0,
            'p90' => $prices[(int)($count * 0.9)] ?? 0,
        ];
    }

    private function identifyCategoryGaps(array $marketData): array
    {
        $gaps = [];
        $ourProducts = $this->getOurProducts(['category_id' => $marketData['category_id'] ?? '']);

        if (empty($ourProducts) && ($marketData['total_listings'] ?? 0) > 0) {
            $gaps[] = [
                'type' => 'product_gap',
                'description' => 'Sem produtos nesta categoria que tem demanda ativa',
                'severity' => 'high',
            ];
        }

        // Price gap analysis
        $ourPrices = array_column($ourProducts, 'price');
        $marketAvg = $marketData['avg_price'] ?? 0;

        if (!empty($ourPrices) && $marketAvg > 0) {
            $ourAvg = array_sum($ourPrices) / count($ourPrices);
            if ($ourAvg > $marketAvg * 1.2) {
                $gaps[] = [
                    'type' => 'price_gap',
                    'description' => 'Preços 20%+ acima da média do mercado',
                    'severity' => 'medium',
                    'our_avg' => round($ourAvg, 2),
                    'market_avg' => round($marketAvg, 2),
                ];
            }
        }

        // Service gap
        if (($marketData['unique_sellers'] ?? 0) < 5 && ($marketData['total_listings'] ?? 0) > 50) {
            $gaps[] = [
                'type' => 'service_gap',
                'description' => 'Poucos vendedores em categoria com alta demanda — oportunidade de diferenciação',
                'severity' => 'medium',
            ];
        }

        return $gaps;
    }

    private function calculateCategoryOpportunityScore(array $marketData, array $gaps): float
    {
        $score = 5.0; // Base

        // More gaps = more opportunity
        $score += count($gaps) * 1.5;

        // Large market = more opportunity
        $totalListings = $marketData['total_listings'] ?? 0;
        if ($totalListings > 1000) {
            $score += 2.0;
        } elseif ($totalListings > 100) {
            $score += 1.0;
        }

        // Few sellers = less competition
        $sellers = $marketData['unique_sellers'] ?? 0;
        if ($sellers < 10) {
            $score += 1.5;
        }

        return min(10.0, round($score, 2));
    }

    private function generateCategoryRecommendations(string $category, array $gaps): array
    {
        $recommendations = [];

        foreach ($gaps as $gap) {
            switch ($gap['type']) {
                case 'product_gap':
                    $recommendations[] = [
                        'action' => 'Adicionar produtos na categoria ' . $category,
                        'priority' => 'high',
                        'expected_impact' => 'Capturar demanda não atendida',
                    ];
                    break;
                case 'price_gap':
                    $recommendations[] = [
                        'action' => 'Revisar preços para se aproximar da média de mercado',
                        'priority' => 'medium',
                        'expected_impact' => 'Melhorar posicionamento competitivo',
                    ];
                    break;
                case 'service_gap':
                    $recommendations[] = [
                        'action' => 'Oferecer diferenciais (frete grátis, entrega rápida)',
                        'priority' => 'medium',
                        'expected_impact' => 'Diferenciação frente a poucos concorrentes',
                    ];
                    break;
            }
        }

        return $recommendations;
    }

    private function estimateCategoryMarketSize(array $marketData): int
    {
        $totalListings = $marketData['total_listings'] ?? 0;
        $avgPrice = $marketData['avg_price'] ?? 0;

        // Rough estimate: total listings * avg price * estimated monthly turnover rate
        return (int)($totalListings * $avgPrice * 0.1);
    }

    private function assessCompetitionLevel(array $marketData): string
    {
        $sellers = $marketData['unique_sellers'] ?? 0;
        $listings = $marketData['total_listings'] ?? 0;

        if ($sellers > 50 || $listings > 5000) {
            return 'high';
        }
        if ($sellers > 10 || $listings > 500) {
            return 'medium';
        }
        return 'low';
    }

    private function calculateQualityScore(array $product): float
    {
        $score = 0.0;

        // Pictures quality
        $pictures = $product['pictures_count'] ?? $product['pictures'] ?? 0;
        if (is_array($pictures)) {
            $pictures = count($pictures);
        }
        $score += min(3.0, $pictures * 0.5);

        // Attributes filled
        $attributes = $product['attributes_filled'] ?? 0;
        $score += min(3.0, $attributes * 0.3);

        // Sold quantity as quality signal
        $sold = $product['sold_quantity'] ?? 0;
        if ($sold > 100) {
            $score += 2.0;
        } elseif ($sold > 10) {
            $score += 1.0;
        }

        // SEO score if available
        $seo = $product['seo_score'] ?? 0;
        $score += ($seo / 100) * 2.0;

        return round(min(10.0, $score), 2);
    }

    private function calculateListingScore(array $product): float
    {
        $score = 0.0;

        // Title length
        $titleLen = mb_strlen($product['title'] ?? '');
        if ($titleLen >= 45 && $titleLen <= 60) {
            $score += 3.0;
        } elseif ($titleLen >= 30) {
            $score += 1.5;
        }

        // Pictures
        $pictures = $product['pictures_count'] ?? 0;
        if (is_array($pictures)) {
            $pictures = count($pictures);
        }
        $score += min(3.0, $pictures * 0.5);

        // Free shipping bonus
        if (!empty($product['free_shipping'])) {
            $score += 2.0;
        }

        // Attributes
        $attrs = $product['attributes_filled'] ?? 0;
        $score += min(2.0, $attrs * 0.2);

        return round(min(10.0, $score), 2);
    }

    private function calculateServiceScore(array $product): float
    {
        $score = 0.0;

        // Free shipping
        if (!empty($product['free_shipping'])) {
            $score += 3.0;
        }

        // Fulfillment (shipping_mode)
        $shippingMode = $product['shipping_mode'] ?? '';
        if ($shippingMode === 'me2' || $shippingMode === 'fulfillment') {
            $score += 3.0;
        } elseif ($shippingMode === 'me1') {
            $score += 1.5;
        }

        // Available stock
        $stock = $product['available_quantity'] ?? $product['stock'] ?? 0;
        if ($stock > 50) {
            $score += 2.0;
        } elseif ($stock > 10) {
            $score += 1.0;
        }

        // Listing type
        $listingType = $product['listing_type'] ?? '';
        if ($listingType === 'gold_pro' || $listingType === 'gold_premium') {
            $score += 2.0;
        }

        return round(min(10.0, $score), 2);
    }

    private function assessCompetitivePosition(array $advantages): string
    {
        $overall = $advantages['overall_advantage'] ?? 0;

        if ($overall > 0.4) {
            return 'dominant';
        }
        if ($overall > 0.2) {
            return 'strong';
        }
        if ($overall > 0) {
            return 'slight_advantage';
        }
        if ($overall > -0.2) {
            return 'neutral';
        }
        return 'disadvantaged';
    }

    private function identifyProductStrengths(array $advantages): array
    {
        $strengths = [];
        $threshold = 0.1;

        if (($advantages['price_advantage'] ?? 0) > $threshold) {
            $strengths[] = ['area' => 'price', 'score' => $advantages['price_advantage'], 'label' => 'Preço competitivo'];
        }
        if (($advantages['quality_advantage'] ?? 0) > $threshold) {
            $strengths[] = ['area' => 'quality', 'score' => $advantages['quality_advantage'], 'label' => 'Qualidade superior'];
        }
        if (($advantages['listing_advantage'] ?? 0) > $threshold) {
            $strengths[] = ['area' => 'listing', 'score' => $advantages['listing_advantage'], 'label' => 'Anúncio bem otimizado'];
        }
        if (($advantages['service_advantage'] ?? 0) > $threshold) {
            $strengths[] = ['area' => 'service', 'score' => $advantages['service_advantage'], 'label' => 'Nível de serviço superior'];
        }

        return $strengths;
    }

    private function identifyProductWeaknesses(array $advantages): array
    {
        $weaknesses = [];
        $threshold = -0.05;

        if (($advantages['price_advantage'] ?? 0) < $threshold) {
            $weaknesses[] = ['area' => 'price', 'score' => $advantages['price_advantage'], 'label' => 'Preço acima da concorrência'];
        }
        if (($advantages['quality_advantage'] ?? 0) < $threshold) {
            $weaknesses[] = ['area' => 'quality', 'score' => $advantages['quality_advantage'], 'label' => 'Qualidade inferior à concorrência'];
        }
        if (($advantages['listing_advantage'] ?? 0) < $threshold) {
            $weaknesses[] = ['area' => 'listing', 'score' => $advantages['listing_advantage'], 'label' => 'Anúncio precisa de melhorias'];
        }
        if (($advantages['service_advantage'] ?? 0) < $threshold) {
            $weaknesses[] = ['area' => 'service', 'score' => $advantages['service_advantage'], 'label' => 'Nível de serviço precisa melhorar'];
        }

        return $weaknesses;
    }

    private function determineActionNeeded(array $advantages): array
    {
        $actions = [];
        $weaknesses = $this->identifyProductWeaknesses($advantages);

        foreach ($weaknesses as $weakness) {
            switch ($weakness['area']) {
                case 'price':
                    $actions[] = ['type' => 'price_adjustment', 'priority' => 'high', 'description' => 'Revisar estratégia de preço'];
                    break;
                case 'quality':
                    $actions[] = ['type' => 'quality_improvement', 'priority' => 'medium', 'description' => 'Melhorar fotos e descrição'];
                    break;
                case 'listing':
                    $actions[] = ['type' => 'seo_optimization', 'priority' => 'medium', 'description' => 'Otimizar título e atributos'];
                    break;
                case 'service':
                    $actions[] = ['type' => 'service_upgrade', 'priority' => 'low', 'description' => 'Considerar frete grátis ou Full'];
                    break;
            }
        }

        return $actions;
    }

    private function generateExecutiveSummary(array $reports): array
    {
        $totalInsights = 0;
        $highPriorityItems = 0;

        foreach ($reports as $report) {
            $totalInsights += count($report['insights'] ?? []);
        }

        $actionItems = $this->extractActionItems($reports);
        $highPriorityItems = count(array_filter($actionItems, fn(array $a): bool => $a['priority'] === 'high'));

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'reports_count' => count($reports),
            'total_insights' => $totalInsights,
            'high_priority_actions' => $highPriorityItems,
            'overall_status' => $highPriorityItems > 3 ? 'needs_immediate_attention' : ($highPriorityItems > 0 ? 'action_recommended' : 'stable'),
            'key_takeaways' => array_slice(array_column($actionItems, 'insight'), 0, 5),
        ];
    }

    private function getActiveCompetitors(): array
    {
        $stmt = $this->db->prepare("
            SELECT cm.competitor_id, cm.competitor_name, cm.monitoring_config
            FROM ml_competitor_monitoring cm
            WHERE cm.account_id = :account_id AND cm.is_active = 1
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $competitors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($competitors)) {
            // Fallback to watchlist
            $stmt2 = $this->db->prepare("
                SELECT DISTINCT competitor_seller_id as competitor_id,
                       nickname as competitor_name
                FROM competitor_watchlist
                WHERE account_id = :account_id AND status = 'active'
            ");
            $stmt2->execute(['account_id' => $this->accountId]);
            $competitors = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $competitors;
    }

    private function getCompetitorChangesBetween(string $competitorId, int $from, int $to): array
    {
        $fromDate = date('Y-m-d H:i:s', $from);
        $toDate = date('Y-m-d H:i:s', $to);

        $stmt = $this->db->prepare("
            SELECT ca.alert_type as type, ca.severity, ca.message, ca.alert_data, ca.created_at
            FROM ml_competitor_alerts ca
            WHERE ca.competitor_id = :competitor_id
            AND ca.created_at BETWEEN :from_date AND :to_date
            ORDER BY ca.created_at DESC
        ");
        $stmt->execute([
            'competitor_id' => $competitorId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);

        $alerts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Map alert_type to change types
        return array_map(function (array $alert): array {
            return [
                'type' => $this->mapAlertTypeToChangeType($alert['type']),
                'severity' => $alert['severity'],
                'message' => $alert['message'],
                'data' => json_decode($alert['alert_data'] ?? '{}', true),
                'timestamp' => $alert['created_at'],
            ];
        }, $alerts);
    }

    private function mapAlertTypeToChangeType(string $alertType): string
    {
        $map = [
            'price_change' => 'price_decrease',
            'new_product' => 'new_listing',
            'ad_change' => 'ad_change',
            'reputation_change' => 'reputation_change',
        ];
        return $map[$alertType] ?? $alertType;
    }

    private function assessChangeImpact(array $changes): string
    {
        $highSeverity = count(array_filter($changes, fn(array $c): bool => ($c['severity'] ?? '') === 'high' || ($c['severity'] ?? '') === 'critical'));

        if ($highSeverity > 2) {
            return 'high';
        }
        if ($highSeverity > 0 || count($changes) > 5) {
            return 'medium';
        }
        return 'low';
    }

    private function generateDailyMarketSummary(array $changes): array
    {
        $totalChanges = array_sum(array_map(fn(array $c): int => count($c['changes'] ?? []), $changes));
        $competitorsChanged = count(array_filter($changes, fn(array $c): bool => !empty($c['changes'])));

        return [
            'date' => date('Y-m-d'),
            'total_changes' => $totalChanges,
            'competitors_with_activity' => $competitorsChanged,
            'market_activity_level' => $totalChanges > 20 ? 'high' : ($totalChanges > 5 ? 'moderate' : 'low'),
        ];
    }

    private function generateDailyRecommendations(array $changes): array
    {
        $recommendations = [];

        foreach ($changes as $change) {
            $impact = $change['impact_level'] ?? 'low';
            if ($impact === 'high') {
                $competitorName = $change['competitor']['competitor_name'] ?? 'Concorrente';
                $recommendations[] = [
                    'priority' => 'high',
                    'message' => "Ação necessária: {$competitorName} fez mudanças de alto impacto",
                    'suggested_action' => 'Revisar preços e posicionamento',
                ];
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'priority' => 'low',
                'message' => 'Mercado estável — nenhuma ação imediata necessária',
                'suggested_action' => 'Manter monitoramento',
            ];
        }

        return $recommendations;
    }
}
