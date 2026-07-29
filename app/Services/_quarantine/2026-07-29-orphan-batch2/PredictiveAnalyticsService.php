<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

/**
 * Predictive Analytics Service for SEO Killer 2.0
 *
 * Usa dados históricos e heurísticas (weighted averages) para:
 * - Estimar performance de itens
 * - Identificar oportunidades de otimização
 * - Estimar tendências de mercado
 * - Fornecer recomendações proativas
 *
 * Nota: Não usa Machine Learning — previsões são baseadas em pesos fixos e médias ponderadas.
 */
class PredictiveAnalyticsService
{
    private \PDO $db;
    private CacheService $cache;
    private array $models;
    private int $accountId;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = \App\Database::getInstance();
        $this->cache = new CacheService();
        $this->loadModels();
    }

    /**
     * Predict item performance for next 30 days
     */
    public function predictItemPerformance(string $itemId, int $days = 30): array
    {
        $cacheKey = "prediction_{$itemId}_{$days}";
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        // Get historical data
        $historical = $this->getHistoricalPerformance($itemId, 90);

        if (count($historical) < 7) {
            return [
                'success' => false,
                'error' => 'Insufficient historical data for prediction'
            ];
        }

        $prediction = $this->generatePrediction($historical, $days);

        // Add confidence score
        $prediction['confidence'] = $this->calculateConfidence($historical, $prediction);
        $prediction['recommendations'] = $this->generatePredictiveRecommendations($prediction);

        // Cache for 6 hours
        $this->cache->set($cacheKey, $prediction, 21600);

        return [
            'success' => true,
            'data' => $prediction
        ];
    }

    /**
     * Analyze market trends and predict category movements
     */
    public function analyzeMarketTrends(string $categoryId, int $days = 14): array
    {
        $cacheKey = "trends_{$categoryId}_{$days}";
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $trendData = $this->collectMarketData($categoryId, $days);
        $analysis = $this->performTrendAnalysis($trendData);

        $result = [
            'category_id' => $categoryId,
            'analysis_period' => $days,
            'trends' => $analysis,
            'opportunities' => $this->identifyOpportunities($analysis),
            'warnings' => $this->identifyMarketWarnings($analysis),
            'confidence_score' => $this->calculateTrendConfidence($analysis),
            'predicted_growth' => $this->predictCategoryGrowth($analysis),
            'generated_at' => time()
        ];

        // Cache for 12 hours
        $this->cache->set($cacheKey, $result, 43200);

        return $result;
    }

    /**
     * Predict optimal pricing strategy
     */
    public function predictOptimalPricing(string $itemId, string $categoryId): array
    {
        $cacheKey = "pricing_{$itemId}_{$categoryId}";
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        // Get item details
        $mlClient = new \App\Services\MercadoLivreClient();
        $item = $mlClient->getItemDetails($itemId);

        // Get competitor pricing data
        $competitors = $mlClient->getCompetitorAnalysis(
            $item['title'] ?? '',
            $categoryId
        );

        // Get historical pricing performance
        $pricingHistory = $this->getPricingHistory($itemId, 30);

        $prediction = $this->generatePricingPrediction($item, $competitors, $pricingHistory);

        $result = [
            'item_id' => $itemId,
            'current_price' => $item['price'] ?? 0,
            'predicted_optimal' => $prediction['optimal_price'],
            'price_range' => $prediction['price_range'],
            'confidence' => $prediction['confidence'],
            'market_position' => $prediction['market_position'],
            'recomendations' => $prediction['recommendations'],
            'competitor_analysis' => [
                'avg_price' => $competitors['price_analysis']['avg'] ?? 0,
                'price_percentile' => $prediction['price_percentile'],
                'competitive_advantage' => $prediction['competitive_advantage']
            ],
            'predicted_impact' => $this->predictPriceImpact($prediction)
        ];

        // Cache for 4 hours
        $this->cache->set($cacheKey, $result, 14400);

        return $result;
    }

    /**
     * Predict SEO score improvement potential
     */
    public function predictSEOImprovement(string $itemId): array
    {
        $cacheKey = "seo_prediction_{$itemId}";
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        // Get current SEO analysis
        $seoEngine = new SEOKillerEngine($this->accountId);
        $currentAnalysis = $seoEngine->diagnoseSingleItem($itemId);

        // Calculate improvement potential
        $improvements = $this->calculateImprovementPotential($currentAnalysis);

        // Predict timeline for improvements
        $timeline = $this->predictImprovementTimeline($improvements);

        // Estimate performance impact
        $impact = $this->predictSEOImpact($improvements, $currentAnalysis);

        $result = [
            'item_id' => $itemId,
            'current_score' => $currentAnalysis['overall_score'] ?? 0,
            'predicted_score' => $currentAnalysis['overall_score'] + $improvements['total_gain'],
            'improvement_potential' => $improvements,
            'optimization_timeline' => $timeline,
            'predicted_impact' => $impact,
            'priority_level' => $this->calculatePriority($improvements, $impact),
            'recommended_actions' => $this->prioritizeActions($improvements),
            'confidence_score' => $this->calculateSEOConfidence($currentAnalysis, $improvements)
        ];

        // Cache for 8 hours
        $this->cache->set($cacheKey, $result, 28800);

        return $result;
    }

    /**
     * Predict seasonal trends and opportunities
     */
    public function predictSeasonalOpportunities(string $categoryId): array
    {
        $cacheKey = "seasonal_{$categoryId}";
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        // Get historical seasonal data
        $seasonalData = $this->getSeasonalData($categoryId, 24); // 24 months

        // Identify seasonal patterns
        $patterns = $this->identifySeasonalPatterns($seasonalData);

        // Predict upcoming opportunities
        $opportunities = $this->predictUpcomingSeasonal($patterns);

        // Generate recommendations
        $recommendations = $this->generateSeasonalRecommendations($opportunities);

        $result = [
            'category_id' => $categoryId,
            'seasonal_patterns' => $patterns,
            'upcoming_opportunities' => $opportunities,
            'recommendations' => $recommendations,
            'confidence_level' => $this->calculateSeasonalConfidence($patterns),
            'next_peak' => $this->findNextPeak($patterns),
            'preparation_timeline' => $this->generatePreparationTimeline($opportunities)
        ];

        // Cache for 24 hours
        $this->cache->set($cacheKey, $result, 86400);

        return $result;
    }

    /**
     * Generate comprehensive predictive insights
     */
    public function generatePredictiveInsights(int $accountId): array
    {
        $cacheKey = "insights_{$accountId}";
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        // Get account items
        $mlClient = new \App\Services\MercadoLivreClient($accountId);
        $items = $mlClient->getMyItems(['limit' => 50]);

        $insights = [
            'account_id' => $accountId,
            'generated_at' => time(),
            'overall_health' => $this->calculateAccountHealth($items),
            'performance_forecast' => $this->forecastAccountPerformance($items),
            'optimization_opportunities' => $this->identifyTopOpportunities($items),
            'market_predictions' => $this->generateMarketPredictions($items),
            'strategic_recommendations' => $this->generateStrategicRecommendations($items),
            'risk_assessment' => $this->assessAccountRisks($items),
            'growth_potential' => $this->calculateGrowthPotential($items),
            'action_plan' => $this->generateActionPlan($items)
        ];

        // Cache for 6 hours
        $this->cache->set($cacheKey, $insights, 21600);

        return $insights;
    }

    /**
     * Load ML models for predictions
     */
    private function loadModels(): void
    {
        $this->models = [
            'performance' => new PerformancePredictionModel(),
            'pricing' => new PricingOptimizationModel(),
            'trends' => new TrendAnalysisModel(),
            'seasonal' => new SeasonalPatternModel()
        ];
    }

    /**
     * Get historical performance data
     */
    private function getHistoricalPerformance(string $itemId, int $days): array
    {
        $stmt = $this->db->prepare("
            SELECT metric_date AS date, COALESCE(views, 0) AS views, COALESCE(sold_quantity, 0) AS sales, COALESCE(revenue, 0) AS revenue, COALESCE(conversion_rate, 0) AS conversion_rate
            FROM seo_performance_metrics
            WHERE item_id = :item_id AND metric_date >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ORDER BY metric_date ASC
        ");

        $stmt->execute(['item_id' => $itemId, 'days' => $days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Generate prediction using ML model
     */
    private function generatePrediction(array $historical, int $days): array
    {
        // Use the performance prediction model
        return $this->models['performance']->predict($historical, $days);
    }

    /**
     * Calculate prediction confidence score
     */
    private function calculateConfidence(array $historical, array $prediction): float
    {
        // More data = higher confidence
        $dataPoints = count($historical);
        $baseConfidence = min(0.95, $dataPoints / 30);

        // Check for data consistency
        $variance = $this->calculateViance($historical);
        $consistencyBonus = max(0, 1 - ($variance / 100));

        return round($baseConfidence * $consistencyBonus, 2);
    }

    /**
     * Generate predictive recommendations
     */
    private function generatePredictiveRecommendations(array $prediction): array
    {
        $recommendations = [];

        // Analyze predicted trends
        if ($prediction['trend'] === 'declining') {
            $recommendations[] = [
                'type' => 'urgent',
                'title' => 'Previsão de Declínio',
                'description' => 'O desempenho do item deve diminuir nas próximas semanas',
                'action' => 'Executar otimização SEO completa imediatamente'
            ];
        }

        if ($prediction['trend'] === 'growing') {
            $recommendations[] = [
                'type' => 'opportunity',
                'title' => 'Oportunidade de Crescimento',
                'description' => 'O item tem potencial de crescimento significativo',
                'action' => 'Aumentar investimento em marketing e otimização'
            ];
        }

        return $recommendations;
    }

    /**
     * Collect market trend data
     */
    private function collectMarketData(string $categoryId, int $days): array
    {
        $mlClient = new \App\Services\MercadoLivreClient();

        // Get category trends
        $trends = $mlClient->getTrends($categoryId);

        // Get competitor data
        $competitors = $mlClient->searchByKeyword('', $categoryId, 50);

        // Get price trends
        $priceAnalysis = $mlClient->getCompetitorAnalysis('', $categoryId);

        return [
            'trends' => $trends,
            'competitors' => $competitors,
            'pricing' => $priceAnalysis,
            'time_period' => $days
        ];
    }

    /**
     * Perform trend analysis
     */
    private function performTrendAnalysis(array $trendData): array
    {
        return $this->models['trends']->analyze($trendData);
    }

    /**
     * Identify market opportunities
     */
    private function identifyOpportunities(array $analysis): array
    {
        $opportunities = [];

        // Price gap opportunities
        if (isset($analysis['pricing_gaps'])) {
            foreach ($analysis['pricing_gaps'] as $gap) {
                $opportunities[] = [
                    'type' => 'pricing_gap',
                    'description' => "Oportunidade de preço entre R$ {$gap['min']} e R$ {$gap['max']}",
                    'potential_impact' => $gap['impact_score']
                ];
            }
        }

        // Keyword opportunities
        if (isset($analysis['keyword_opportunities'])) {
            foreach ($analysis['keyword_opportunities'] as $keyword) {
                $opportunities[] = [
                    'type' => 'keyword',
                    'description' => "Keyword em alta: {$keyword['term']}",
                    'search_volume' => $keyword['volume'],
                    'competition' => $keyword['competition']
                ];
            }
        }

        return $opportunities;
    }

    /**
     * Generate pricing prediction
     */
    private function generatePricingPrediction(array $item, array $competitors, array $history): array
    {
        return $this->models['pricing']->predict($item, $competitors, $history);
    }

    /**
     * Predict price impact
     */
    private function predictPriceImpact(array $prediction): array
    {
        return [
            'view_change_percent' => $prediction['view_impact'],
            'sales_change_percent' => $prediction['sales_impact'],
            'revenue_change_percent' => $prediction['revenue_impact'],
            'confidence' => $prediction['confidence']
        ];
    }

    /**
     * Calculate SEO improvement potential
     */
    private function calculateImprovementPotential(array $analysis): array
    {
        $potential = [
            'title_optimization' => 0,
            'description_optimization' => 0,
            'attribute_completion' => 0,
            'image_optimization' => 0,
            'seo_strategy' => 0
        ];

        // Calculate potential improvements for each category
        $potential['title_optimization'] = min(20, (100 - ($analysis['title_score'] ?? 0)) * 0.3);
        $potential['description_optimization'] = min(25, (100 - ($analysis['description_score'] ?? 0)) * 0.4);
        $potential['attribute_completion'] = min(15, (100 - ($analysis['attributes_score'] ?? 0)) * 0.2);
        $potential['image_optimization'] = min(20, (100 - ($analysis['images_score'] ?? 0)) * 0.3);
        $potential['seo_strategy'] = min(20, (100 - ($analysis['strategy_score'] ?? 0)) * 0.25);

        $potential['total_gain'] = array_sum($potential);

        return $potential;
    }

    /**
     * Calculate data variance
     */
    private function calculateViance(array $data): float
    {
        if (empty($data)) return 0;

        $values = array_column($data, 'views');
        $mean = array_sum($values) / count($values);

        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return $variance / count($values);
    }

    // ============================================================
    // Bloco 1 — SEO & Pricing Helpers
    // ============================================================

    /**
     * Identify market warnings from trend analysis
     */
    private function identifyMarketWarnings(array $analysis): array
    {
        $warnings = [];

        try {
            // Demand decline warning
            $demandTrend = $analysis['demand_trend'] ?? $analysis['trend'] ?? null;
            if (is_numeric($demandTrend) && $demandTrend < -15) {
                $warnings[] = [
                    'type' => 'demand_decline',
                    'severity' => 'high',
                    'message' => sprintf('Queda de demanda de %.1f%% detectada na categoria', abs($demandTrend)),
                    'action' => 'Considere diversificar ou ajustar preço para manter volume'
                ];
            }

            // Volatility warning
            $volatility = $analysis['volatility'] ?? $analysis['price_volatility'] ?? 0;
            if (is_numeric($volatility) && $volatility > 0.3) {
                $warnings[] = [
                    'type' => 'high_volatility',
                    'severity' => $volatility > 0.5 ? 'critical' : 'medium',
                    'message' => sprintf('Alta volatilidade de preços (%.0f%%) — mercado instável', $volatility * 100),
                    'action' => 'Monitore preços diariamente e configure alertas automáticos'
                ];
            }

            // Competition increase
            $competitorGrowth = $analysis['competitor_growth'] ?? $analysis['new_sellers'] ?? 0;
            if (is_numeric($competitorGrowth) && $competitorGrowth > 10) {
                $warnings[] = [
                    'type' => 'competition_increase',
                    'severity' => $competitorGrowth > 25 ? 'high' : 'medium',
                    'message' => sprintf('Aumento de %.0f%% no número de concorrentes', $competitorGrowth),
                    'action' => 'Reforce diferenciação: frete grátis, Full, imagens profissionais'
                ];
            }

            // Price war detection
            $avgPriceChange = $analysis['avg_price_change'] ?? 0;
            if (is_numeric($avgPriceChange) && $avgPriceChange < -20) {
                $warnings[] = [
                    'type' => 'price_war',
                    'severity' => 'critical',
                    'message' => sprintf('Guerra de preços detectada — queda média de %.1f%%', abs($avgPriceChange)),
                    'action' => 'Evite entrar na guerra de preços; foque em valor agregado'
                ];
            }

            // Low conversion warning
            $conversionRate = $analysis['avg_conversion_rate'] ?? null;
            if (is_numeric($conversionRate) && $conversionRate < 0.02) {
                $warnings[] = [
                    'type' => 'low_conversion',
                    'severity' => 'medium',
                    'message' => sprintf('Taxa de conversão da categoria baixa (%.2f%%)', $conversionRate * 100),
                    'action' => 'Otimize títulos, imagens e descrições para melhorar conversão'
                ];
            }
        } catch (\Throwable $e) {
            // Return partial warnings on error
        }

        return $warnings;
    }

    /**
     * Calculate trend confidence based on data quality and consistency
     */
    private function calculateTrendConfidence(array $analysis): float
    {
        try {
            $confidence = 0.5; // base

            // Data volume factor (more data = higher confidence)
            $dataPoints = $analysis['data_points'] ?? $analysis['total_items'] ?? 0;
            if ($dataPoints >= 100) {
                $confidence += 0.2;
            } elseif ($dataPoints >= 30) {
                $confidence += 0.1;
            }

            // Consistency factor (low variance = higher confidence)
            $variance = $analysis['variance'] ?? $analysis['volatility'] ?? 0.5;
            $consistencyBonus = max(0, 0.15 * (1 - min(1, $variance)));
            $confidence += $consistencyBonus;

            // Trend clarity — strong trends in either direction are more confident
            $trendStrength = abs($analysis['trend_slope'] ?? $analysis['demand_trend'] ?? 0);
            if ($trendStrength > 10) {
                $confidence += 0.1;
            }

            // Time span factor — longer historical period = better
            $timePeriod = $analysis['time_period'] ?? $analysis['analysis_period'] ?? 7;
            if ($timePeriod >= 30) {
                $confidence += 0.05;
            }

            return round(min(0.95, max(0.1, $confidence)), 2);
        } catch (\Throwable $e) {
            return 0.5;
        }
    }

    /**
     * Predict category growth rate based on trend analysis
     */
    private function predictCategoryGrowth(array $analysis): float
    {
        try {
            // Base growth from trend slope
            $trendSlope = $analysis['trend_slope'] ?? 0;
            $demandTrend = $analysis['demand_trend'] ?? $analysis['trend'] ?? 0;

            if (is_numeric($trendSlope) && $trendSlope != 0) {
                $baseGrowth = $trendSlope;
            } elseif (is_numeric($demandTrend)) {
                $baseGrowth = $demandTrend * 0.7; // dampen raw trend
            } else {
                $baseGrowth = 0;
            }

            // Competitor effect — more competition can reduce individual growth
            $competitorGrowth = $analysis['competitor_growth'] ?? 0;
            $competitorDamper = is_numeric($competitorGrowth) ? max(0.5, 1 - ($competitorGrowth / 100)) : 1;

            // Seasonal adjustment
            $seasonalFactor = $analysis['seasonal_factor'] ?? 1.0;

            $predictedGrowth = $baseGrowth * $competitorDamper * $seasonalFactor;

            // Clamp to reasonable range (-50% to +100%)
            return round(max(-50, min(100, $predictedGrowth)), 1);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Get pricing history for an item
     */
    private function getPricingHistory(string $itemId, int $days): array
    {
        try {
            // Try competitor_price_history first (has item-level granularity)
            $stmt = $this->db->prepare("
                SELECT
                    cph.price,
                    cph.min_price,
                    cph.max_price,
                    cph.recorded_at AS date
                FROM competitor_price_history cph
                INNER JOIN competitor_items ci ON ci.id = cph.competitor_item_id
                WHERE ci.my_item_id = :item_id
                  AND ci.account_id = :account_id
                  AND cph.recorded_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY cph.recorded_at ASC
            ");
            $stmt->execute([
                'item_id' => $itemId,
                'account_id' => $this->accountId,
                'days' => $days
            ]);
            $history = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($history)) {
                return $history;
            }

            // Fallback: get category-level price history from the item's category
            $stmtItem = $this->db->prepare("
                SELECT category_id FROM ml_items WHERE id = :item_id AND account_id = :account_id LIMIT 1
            ");
            $stmtItem->execute(['item_id' => $itemId, 'account_id' => $this->accountId]);
            $categoryId = $stmtItem->fetchColumn();

            if (!$categoryId) {
                return [];
            }

            $stmtCat = $this->db->prepare("
                SELECT
                    avg_price AS price,
                    min_price,
                    max_price,
                    total_items,
                    recorded_at AS date
                FROM price_history
                WHERE category_id = :category_id
                  AND recorded_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY recorded_at ASC
            ");
            $stmtCat->execute(['category_id' => $categoryId, 'days' => $days]);
            return $stmtCat->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Predict timeline for SEO improvements to take effect
     */
    private function predictImprovementTimeline(array $improvements): array
    {
        $timeline = [];

        $impactDays = [
            'title_optimization' => ['days' => 7, 'label' => 'Otimização de Título'],
            'description_optimization' => ['days' => 14, 'label' => 'Otimização de Descrição'],
            'attribute_completion' => ['days' => 5, 'label' => 'Completar Atributos'],
            'image_optimization' => ['days' => 21, 'label' => 'Otimização de Imagens'],
            'seo_strategy' => ['days' => 30, 'label' => 'Estratégia SEO Geral']
        ];

        foreach ($impactDays as $key => $config) {
            $gain = $improvements[$key] ?? 0;
            if ($gain > 0) {
                $timeline[] = [
                    'optimization' => $key,
                    'label' => $config['label'],
                    'estimated_days' => $config['days'],
                    'expected_gain' => round($gain, 1),
                    'start_date' => date('Y-m-d'),
                    'estimated_completion' => date('Y-m-d', strtotime("+{$config['days']} days")),
                    'milestones' => [
                        ['day' => 1, 'description' => 'Implementar alteração'],
                        ['day' => (int) ceil($config['days'] * 0.3), 'description' => 'Primeiros sinais de indexação'],
                        ['day' => $config['days'], 'description' => 'Impacto completo esperado']
                    ]
                ];
            }
        }

        // Sort by days (quickest wins first)
        usort($timeline, fn($a, $b) => $a['estimated_days'] <=> $b['estimated_days']);

        return $timeline;
    }

    /**
     * Predict SEO impact from improvements
     */
    private function predictSEOImpact(array $improvements, array $current): array
    {
        try {
            $currentScore = $current['overall_score'] ?? 50;
            $totalGain = $improvements['total_gain'] ?? 0;

            // Score impact
            $predictedScore = min(100, $currentScore + $totalGain);

            // Conversion impact — each SEO point above 70 adds ~0.5% conversion lift
            $conversionLift = 0;
            if ($predictedScore > 70 && $currentScore <= 70) {
                $conversionLift = ($predictedScore - 70) * 0.5;
            } elseif ($currentScore > 70) {
                $conversionLift = ($predictedScore - $currentScore) * 0.3;
            } else {
                $conversionLift = $totalGain * 0.2;
            }

            // Visibility impact — title and attributes affect search ranking most
            $titleGain = $improvements['title_optimization'] ?? 0;
            $attrGain = $improvements['attribute_completion'] ?? 0;
            $visibilityLift = ($titleGain * 2 + $attrGain * 1.5) / 3;

            // Sales impact estimate
            $salesLift = $conversionLift * 0.6 + $visibilityLift * 0.4;

            return [
                'predicted_score' => round($predictedScore, 1),
                'score_gain' => round($totalGain, 1),
                'conversion_lift_percent' => round($conversionLift, 1),
                'visibility_lift_percent' => round($visibilityLift, 1),
                'estimated_sales_lift_percent' => round($salesLift, 1),
                'ranking_improvement' => $this->estimateRankingImprovement($totalGain),
                'time_to_impact_days' => $totalGain > 15 ? 7 : 14,
                'impact_breakdown' => [
                    'title' => round($titleGain * 1.5, 1),
                    'description' => round(($improvements['description_optimization'] ?? 0) * 1.2, 1),
                    'attributes' => round($attrGain * 1.3, 1),
                    'images' => round(($improvements['image_optimization'] ?? 0) * 1.1, 1),
                    'strategy' => round(($improvements['seo_strategy'] ?? 0) * 1.0, 1)
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'predicted_score' => $current['overall_score'] ?? 50,
                'score_gain' => 0,
                'conversion_lift_percent' => 0,
                'visibility_lift_percent' => 0,
                'estimated_sales_lift_percent' => 0
            ];
        }
    }

    /**
     * Estimate ranking improvement from SEO score gain
     */
    private function estimateRankingImprovement(float $scoreGain): string
    {
        if ($scoreGain >= 25) return 'significativa';
        if ($scoreGain >= 15) return 'moderada';
        if ($scoreGain >= 5) return 'leve';
        return 'mínima';
    }

    /**
     * Calculate priority based on improvement potential and predicted impact
     */
    private function calculatePriority(array $improvements, array $impact): string
    {
        try {
            $totalGain = $improvements['total_gain'] ?? 0;
            $salesLift = $impact['estimated_sales_lift_percent'] ?? $impact['sales_impact'] ?? 0;

            $score = ($totalGain * 0.4) + ($salesLift * 0.6);

            if ($score >= 20) return 'critical';
            if ($score >= 10) return 'high';
            if ($score >= 5) return 'medium';
            return 'low';
        } catch (\Throwable $e) {
            return 'medium';
        }
    }

    /**
     * Prioritize improvement actions by estimated ROI
     */
    private function prioritizeActions(array $improvements): array
    {
        $actions = [];

        $actionConfigs = [
            'title_optimization' => [
                'effort' => 1,
                'impact_multiplier' => 2.0,
                'label' => 'Otimizar Título',
                'description' => 'Reformule o título com keywords de alto impacto nos primeiros caracteres'
            ],
            'attribute_completion' => [
                'effort' => 1,
                'impact_multiplier' => 1.5,
                'label' => 'Completar Atributos',
                'description' => 'Preencha todos os atributos obrigatórios e recomendados da categoria'
            ],
            'image_optimization' => [
                'effort' => 3,
                'impact_multiplier' => 1.3,
                'label' => 'Otimizar Imagens',
                'description' => 'Adicione imagens 1200x1200+ com fundo branco de múltiplos ângulos'
            ],
            'description_optimization' => [
                'effort' => 2,
                'impact_multiplier' => 1.2,
                'label' => 'Melhorar Descrição',
                'description' => 'Estruture com bullets, mínimo 500 caracteres, inclua especificações'
            ],
            'seo_strategy' => [
                'effort' => 3,
                'impact_multiplier' => 1.0,
                'label' => 'Estratégia SEO Completa',
                'description' => 'Ative frete grátis, Full, e garanta ficha técnica completa'
            ]
        ];

        foreach ($actionConfigs as $key => $config) {
            $gain = $improvements[$key] ?? 0;
            if ($gain <= 0) continue;

            $impact = $gain * $config['impact_multiplier'];
            $roi = $impact / max(1, $config['effort']);

            $actions[] = [
                'action' => $key,
                'label' => $config['label'],
                'description' => $config['description'],
                'expected_gain' => round($gain, 1),
                'effort_level' => $config['effort'],
                'estimated_roi' => round($roi, 2),
                'priority' => $roi >= 10 ? 'critical' : ($roi >= 5 ? 'high' : ($roi >= 2 ? 'medium' : 'low'))
            ];
        }

        // Sort by ROI descending
        usort($actions, fn($a, $b) => $b['estimated_roi'] <=> $a['estimated_roi']);

        return $actions;
    }

    /**
     * Calculate SEO prediction confidence
     */
    private function calculateSEOConfidence(array $current, array $improvements): float
    {
        try {
            $confidence = 0.5;

            // Data completeness — more analysis fields = higher confidence
            $fieldsPresent = 0;
            $expectedFields = ['overall_score', 'title_score', 'description_score', 'attributes_score', 'images_score'];
            foreach ($expectedFields as $field) {
                if (isset($current[$field]) && is_numeric($current[$field])) {
                    $fieldsPresent++;
                }
            }
            $confidence += ($fieldsPresent / count($expectedFields)) * 0.2;

            // Improvement volume — larger improvements have slightly lower confidence
            $totalGain = $improvements['total_gain'] ?? 0;
            if ($totalGain > 30) {
                $confidence -= 0.1; // aggressive improvements are less certain
            } elseif ($totalGain > 0 && $totalGain <= 15) {
                $confidence += 0.1; // small improvements are highly predictable
            }

            // Current score affects confidence — mid-range scores are more predictable
            $currentScore = $current['overall_score'] ?? 50;
            if ($currentScore >= 30 && $currentScore <= 80) {
                $confidence += 0.1;
            }

            return round(min(0.95, max(0.2, $confidence)), 2);
        } catch (\Throwable $e) {
            return 0.5;
        }
    }

    // ============================================================
    // Bloco 3 — Sazonalidade
    // ============================================================

    /**
     * Get seasonal performance data for a category
     */
    private function getSeasonalData(string $categoryId, int $months): array
    {
        try {
            // Order-based seasonal data grouped by month
            $stmt = $this->db->prepare("
                SELECT
                    DATE_FORMAT(o.date_created, '%Y-%m') AS month,
                    COUNT(DISTINCT o.ml_order_id) AS total_orders,
                    SUM(oi.quantity) AS total_units,
                    SUM(oi.unit_price * oi.quantity) AS total_revenue,
                    AVG(oi.unit_price) AS avg_price,
                    COUNT(DISTINCT oi.item_id) AS unique_items
                FROM ml_orders o
                INNER JOIN order_items oi ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE o.ml_account_id = :account_id
                  AND oi.category_id = :category_id
                  AND o.date_created >= DATE_SUB(NOW(), INTERVAL :months MONTH)
                  AND o.status = 'paid'
                GROUP BY DATE_FORMAT(o.date_created, '%Y-%m')
                ORDER BY month ASC
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'category_id' => $categoryId,
                'months' => $months
            ]);
            $orderData = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get weekly granularity for recent months
            $stmtWeekly = $this->db->prepare("
                SELECT
                    YEARWEEK(o.date_created, 1) AS week,
                    COUNT(DISTINCT o.ml_order_id) AS orders,
                    SUM(oi.quantity) AS units,
                    SUM(oi.unit_price * oi.quantity) AS revenue
                FROM ml_orders o
                INNER JOIN order_items oi ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE o.ml_account_id = :account_id
                  AND oi.category_id = :category_id
                  AND o.date_created >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                  AND o.status = 'paid'
                GROUP BY YEARWEEK(o.date_created, 1)
                ORDER BY week ASC
            ");
            $stmtWeekly->execute([
                'account_id' => $this->accountId,
                'category_id' => $categoryId
            ]);
            $weeklyData = $stmtWeekly->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'monthly' => $orderData,
                'weekly' => $weeklyData,
                'category_id' => $categoryId,
                'months_analyzed' => $months
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Identify seasonal patterns from historical data
     */
    private function identifySeasonalPatterns(array $data): array
    {
        try {
            $monthly = $data['monthly'] ?? [];
            if (count($monthly) < 3) {
                return [];
            }

            $revenues = array_column($monthly, 'total_revenue');
            $orders = array_column($monthly, 'total_orders');

            $avgRevenue = array_sum($revenues) / count($revenues);
            $avgOrders = array_sum($orders) / count($orders);

            if ($avgRevenue == 0) {
                return [];
            }

            // Calculate standard deviation
            $variance = 0;
            foreach ($revenues as $rev) {
                $variance += pow($rev - $avgRevenue, 2);
            }
            $stdDev = sqrt($variance / count($revenues));

            $patterns = [];
            foreach ($monthly as $m) {
                $monthName = $m['month'];
                $revIndex = ($m['total_revenue'] - $avgRevenue) / max(1, $stdDev);
                $ordIndex = $avgOrders > 0 ? ($m['total_orders'] - $avgOrders) / max(1, $avgOrders) : 0;

                $type = 'normal';
                if ($revIndex > 1.5) $type = 'peak';
                elseif ($revIndex > 0.5) $type = 'above_average';
                elseif ($revIndex < -1.5) $type = 'valley';
                elseif ($revIndex < -0.5) $type = 'below_average';

                $patterns[] = [
                    'month' => $monthName,
                    'month_number' => (int) date('n', strtotime($monthName . '-01')),
                    'type' => $type,
                    'revenue_index' => round($revIndex, 2),
                    'order_index' => round($ordIndex, 2),
                    'revenue' => (float) $m['total_revenue'],
                    'orders' => (int) $m['total_orders'],
                    'avg_price' => round((float) ($m['avg_price'] ?? 0), 2)
                ];
            }

            return [
                'patterns' => $patterns,
                'avg_revenue' => round($avgRevenue, 2),
                'avg_orders' => round($avgOrders, 1),
                'std_dev' => round($stdDev, 2),
                'peaks' => array_values(array_filter($patterns, fn(array $p): bool => $p['type'] === 'peak')),
                'valleys' => array_values(array_filter($patterns, fn(array $p): bool => $p['type'] === 'valley')),
                'seasonality_strength' => $avgRevenue > 0 ? round($stdDev / $avgRevenue, 2) : 0
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Predict upcoming seasonal opportunities
     */
    private function predictUpcomingSeasonal(array $patterns): array
    {
        try {
            $patternList = $patterns['patterns'] ?? [];
            if (empty($patternList)) {
                return [];
            }

            $currentMonth = (int) date('n');
            $opportunities = [];

            // Known Brazilian seasonal events mapped to months
            $seasonalEvents = [
                1 => 'Volta às Aulas',
                2 => 'Carnaval',
                3 => 'Dia do Consumidor',
                5 => 'Dia das Mães',
                6 => 'Dia dos Namorados / Festa Junina',
                8 => 'Dia dos Pais',
                9 => 'Dia do Cliente',
                10 => 'Dia das Crianças',
                11 => 'Black Friday',
                12 => 'Natal'
            ];

            // Project next 6 months based on historical patterns
            for ($i = 1; $i <= 6; $i++) {
                $targetMonth = (($currentMonth + $i - 1) % 12) + 1;

                // Find historical performance for this month
                $monthPatterns = array_filter($patternList, fn(array $p): bool => ($p['month_number'] ?? 0) === $targetMonth);

                if (empty($monthPatterns)) {
                    continue;
                }

                $avgIndex = 0;
                foreach ($monthPatterns as $mp) {
                    $avgIndex += $mp['revenue_index'] ?? 0;
                }
                $avgIndex /= count($monthPatterns);

                if ($avgIndex > 0.3) {
                    $opportunities[] = [
                        'month' => $targetMonth,
                        'month_name' => date('F', mktime(0, 0, 0, $targetMonth, 1)),
                        'months_until' => $i,
                        'expected_index' => round($avgIndex, 2),
                        'type' => $avgIndex > 1.5 ? 'high_peak' : ($avgIndex > 0.5 ? 'moderate_peak' : 'slight_uptick'),
                        'event' => $seasonalEvents[$targetMonth] ?? null,
                        'confidence' => min(0.9, 0.5 + (count($monthPatterns) * 0.1))
                    ];
                }
            }

            usort($opportunities, fn($a, $b) => $b['expected_index'] <=> $a['expected_index']);

            return $opportunities;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Generate recommendations for seasonal opportunities
     */
    private function generateSeasonalRecommendations(array $opportunities): array
    {
        $recommendations = [];

        foreach ($opportunities as $opp) {
            $monthsUntil = $opp['months_until'] ?? 99;
            $type = $opp['type'] ?? 'slight_uptick';
            $event = $opp['event'] ?? null;

            $actions = [];

            // Stock preparation
            if ($monthsUntil <= 2) {
                $actions[] = [
                    'action' => 'prepare_stock',
                    'urgency' => 'high',
                    'description' => 'Aumente o estoque em 30-50% para a temporada',
                    'deadline_days' => max(1, ($monthsUntil * 30) - 15)
                ];
            }

            // Price strategy
            if ($type === 'high_peak') {
                $actions[] = [
                    'action' => 'price_optimization',
                    'urgency' => $monthsUntil <= 1 ? 'critical' : 'medium',
                    'description' => 'Otimize preços para alta demanda — reduza desconto e maximize margem',
                    'deadline_days' => max(1, ($monthsUntil * 30) - 7)
                ];
            } else {
                $actions[] = [
                    'action' => 'price_competitiveness',
                    'urgency' => 'medium',
                    'description' => 'Garanta preço competitivo para capturar aumento de demanda',
                    'deadline_days' => max(1, ($monthsUntil * 30) - 7)
                ];
            }

            // Ads strategy
            $actions[] = [
                'action' => 'ads_boost',
                'urgency' => $monthsUntil <= 1 ? 'high' : 'low',
                'description' => $event
                    ? "Aumente investimento em ads para {$event}"
                    : 'Aumente investimento em Product Ads para capturar tráfego sazonal',
                'deadline_days' => max(1, ($monthsUntil * 30) - 5)
            ];

            // SEO preparation
            if ($monthsUntil >= 2) {
                $actions[] = [
                    'action' => 'seo_preparation',
                    'urgency' => 'medium',
                    'description' => 'Otimize títulos e descrições com termos sazonais relevantes',
                    'deadline_days' => max(1, ($monthsUntil * 30) - 21)
                ];
            }

            $recommendations[] = [
                'opportunity' => $opp,
                'actions' => $actions,
                'total_actions' => count($actions)
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate confidence in seasonal patterns
     */
    private function calculateSeasonalConfidence(array $patterns): float
    {
        try {
            $patternList = $patterns['patterns'] ?? [];
            $dataPoints = count($patternList);

            if ($dataPoints === 0) {
                return 0.1;
            }

            // More months of data = higher confidence
            $dataFactor = min(0.4, $dataPoints / 24 * 0.4); // max at 24 months

            // Seasonality strength — clear patterns = higher confidence
            $strength = $patterns['seasonality_strength'] ?? 0;
            $strengthFactor = min(0.3, $strength * 0.5);

            // Peak/valley presence — having clear peaks increases confidence
            $peaks = count($patterns['peaks'] ?? []);
            $valleys = count($patterns['valleys'] ?? []);
            $extremeFactor = min(0.2, ($peaks + $valleys) * 0.05);

            $confidence = 0.2 + $dataFactor + $strengthFactor + $extremeFactor;

            return round(min(0.95, max(0.1, $confidence)), 2);
        } catch (\Throwable $e) {
            return 0.3;
        }
    }

    /**
     * Find the next expected seasonal peak
     */
    private function findNextPeak(array $patterns): array
    {
        try {
            $peaks = $patterns['peaks'] ?? [];
            if (empty($peaks)) {
                return [
                    'found' => false,
                    'message' => 'Nenhum pico sazonal identificado nos dados históricos'
                ];
            }

            $currentMonth = (int) date('n');
            $currentYear = (int) date('Y');

            // Find nearest future peak month
            $peakMonths = array_map(fn(array $p): int => $p['month_number'] ?? 0, $peaks);
            sort($peakMonths);

            $nextPeakMonth = null;
            $monthsUntil = 99;

            foreach ($peakMonths as $pm) {
                if ($pm > $currentMonth) {
                    $nextPeakMonth = $pm;
                    $monthsUntil = $pm - $currentMonth;
                    break;
                }
            }

            // Wrap to next year if no future peak found this year
            if ($nextPeakMonth === null && !empty($peakMonths)) {
                $nextPeakMonth = $peakMonths[0];
                $monthsUntil = (12 - $currentMonth) + $nextPeakMonth;
            }

            if ($nextPeakMonth === null) {
                return ['found' => false, 'message' => 'Não foi possível projetar próximo pico'];
            }

            // Find the peak data for magnitude
            $peakData = null;
            foreach ($peaks as $p) {
                if (($p['month_number'] ?? 0) === $nextPeakMonth) {
                    $peakData = $p;
                    break;
                }
            }

            $targetYear = $nextPeakMonth > $currentMonth ? $currentYear : $currentYear + 1;

            return [
                'found' => true,
                'month' => $nextPeakMonth,
                'month_name' => date('F', mktime(0, 0, 0, $nextPeakMonth, 1)),
                'year' => $targetYear,
                'estimated_date' => sprintf('%d-%02d-01', $targetYear, $nextPeakMonth),
                'months_until' => $monthsUntil,
                'magnitude' => round($peakData['revenue_index'] ?? 1.5, 2),
                'expected_revenue_multiplier' => round(1 + ($peakData['revenue_index'] ?? 0.5), 2)
            ];
        } catch (\Throwable $e) {
            return ['found' => false, 'message' => 'Erro ao calcular próximo pico'];
        }
    }

    /**
     * Generate preparation timeline for seasonal opportunities
     */
    private function generatePreparationTimeline(array $opportunities): array
    {
        $timeline = [];

        foreach ($opportunities as $opp) {
            $monthsUntil = $opp['months_until'] ?? 0;
            $daysUntil = $monthsUntil * 30;

            if ($daysUntil <= 0) continue;

            $phases = [];

            // Phase 1: Strategic planning (30+ days before)
            if ($daysUntil >= 30) {
                $phases[] = [
                    'phase' => 'planejamento',
                    'days_before_peak' => 30,
                    'start_date' => date('Y-m-d', strtotime("+{$daysUntil} days -30 days")),
                    'actions' => [
                        'Analisar performance da última temporada equivalente',
                        'Definir metas de vendas e margem',
                        'Planejar estoque e reposição'
                    ]
                ];
            }

            // Phase 2: Stock preparation (14-30 days before)
            if ($daysUntil >= 14) {
                $stockStart = min($daysUntil, 30);
                $phases[] = [
                    'phase' => 'estoque',
                    'days_before_peak' => $stockStart,
                    'start_date' => date('Y-m-d', strtotime("+{$daysUntil} days -{$stockStart} days")),
                    'actions' => [
                        'Garantir estoque para demanda projetada (+30-50%)',
                        'Ativar envio Full se disponível',
                        'Conferir prazos de entrega dos fornecedores'
                    ]
                ];
            }

            // Phase 3: Pricing & SEO (7-14 days before)
            $priceStart = min($daysUntil, 14);
            $phases[] = [
                'phase' => 'preco_seo',
                'days_before_peak' => $priceStart,
                'start_date' => date('Y-m-d', strtotime("+{$daysUntil} days -{$priceStart} days")),
                'actions' => [
                    'Ajustar preços conforme estratégia sazonal',
                    'Otimizar títulos com termos sazonais',
                    'Atualizar imagens e descrições'
                ]
            ];

            // Phase 4: Ads activation (5-7 days before)
            $adsStart = min($daysUntil, 7);
            $phases[] = [
                'phase' => 'anuncios',
                'days_before_peak' => $adsStart,
                'start_date' => date('Y-m-d', strtotime("+{$daysUntil} days -{$adsStart} days")),
                'actions' => [
                    'Aumentar budget de Product Ads',
                    'Ativar campanhas sazonais',
                    'Monitorar concorrência em tempo real'
                ]
            ];

            $timeline[] = [
                'opportunity' => $opp['month_name'] ?? "Mês {$opp['month']}",
                'event' => $opp['event'] ?? null,
                'days_until' => $daysUntil,
                'phases' => $phases
            ];
        }

        return $timeline;
    }

    // ============================================================
    // Bloco 4 — Account Health & Strategy
    // ============================================================

    /**
     * Calculate overall account health score
     */
    private function calculateAccountHealth(array $items): array
    {
        try {
            if (empty($items)) {
                return ['score' => 0, 'status' => 'no_data', 'factors' => []];
            }

            $factors = [];

            // Factor 1: Active items ratio
            $totalItems = count($items);
            $activeItems = count(array_filter($items, fn(array $i): bool => ($i['status'] ?? '') === 'active'));
            $activeRatio = $totalItems > 0 ? $activeItems / $totalItems : 0;
            $factors['active_ratio'] = [
                'score' => round($activeRatio * 100, 1),
                'weight' => 0.2,
                'detail' => "{$activeItems}/{$totalItems} itens ativos"
            ];

            // Factor 2: Average SEO readiness (based on title quality)
            $titleScores = [];
            foreach ($items as $item) {
                $title = $item['title'] ?? '';
                $titleLen = mb_strlen($title);
                $titleScores[] = min(100, ($titleLen >= 45 && $titleLen <= 58) ? 100 : ($titleLen / 58 * 100));
            }
            $avgTitleScore = !empty($titleScores) ? array_sum($titleScores) / count($titleScores) : 0;
            $factors['seo_readiness'] = [
                'score' => round($avgTitleScore, 1),
                'weight' => 0.25,
                'detail' => sprintf('Score médio de título: %.1f', $avgTitleScore)
            ];

            // Factor 3: Stock health
            $lowStock = 0;
            $noStock = 0;
            foreach ($items as $item) {
                $qty = $item['available_quantity'] ?? 0;
                if ($qty <= 0) $noStock++;
                elseif ($qty <= 3) $lowStock++;
            }
            $stockScore = $totalItems > 0 ? max(0, 100 - (($noStock * 10 + $lowStock * 3) / $totalItems * 100)) : 0;
            $factors['stock_health'] = [
                'score' => round($stockScore, 1),
                'weight' => 0.2,
                'detail' => "{$noStock} sem estoque, {$lowStock} com estoque baixo"
            ];

            // Factor 4: Sales performance
            $totalSold = array_sum(array_column($items, 'sold_quantity'));
            $avgSold = $totalItems > 0 ? $totalSold / $totalItems : 0;
            $salesScore = min(100, $avgSold * 5);
            $factors['sales_performance'] = [
                'score' => round($salesScore, 1),
                'weight' => 0.2,
                'detail' => sprintf('Média de %.1f vendas por item', $avgSold)
            ];

            // Factor 5: Price competitiveness (approximate via category diversity)
            $categories = array_unique(array_column($items, 'category_id'));
            $diversityScore = min(100, count($categories) * 10);
            $factors['catalog_diversity'] = [
                'score' => round($diversityScore, 1),
                'weight' => 0.15,
                'detail' => count($categories) . ' categorias ativas'
            ];

            // Calculate weighted overall score
            $overallScore = 0;
            foreach ($factors as $f) {
                $overallScore += $f['score'] * $f['weight'];
            }

            $status = 'critical';
            if ($overallScore >= 80) $status = 'excellent';
            elseif ($overallScore >= 60) $status = 'good';
            elseif ($overallScore >= 40) $status = 'needs_improvement';

            return [
                'score' => round($overallScore, 1),
                'status' => $status,
                'factors' => $factors,
                'total_items' => $totalItems,
                'active_items' => $activeItems
            ];
        } catch (\Throwable $e) {
            return ['score' => 0, 'status' => 'error', 'factors' => []];
        }
    }

    /**
     * Forecast account performance for next 30/60/90 days
     */
    private function forecastAccountPerformance(array $items): array
    {
        try {
            // Get recent sales data for trend calculation
            $stmt = $this->db->prepare("
                SELECT
                    DATE_FORMAT(o.date_created, '%Y-%m') AS month,
                    COUNT(DISTINCT o.ml_order_id) AS orders,
                    SUM(oi.quantity) AS units,
                    SUM(oi.unit_price * oi.quantity) AS revenue
                FROM ml_orders o
                INNER JOIN order_items oi ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE o.ml_account_id = :account_id
                  AND o.status = 'paid'
                  AND o.date_created >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(o.date_created, '%Y-%m')
                ORDER BY month ASC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $monthlyData = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($monthlyData) < 2) {
                return ['forecast_available' => false, 'reason' => 'Dados insuficientes para projeção'];
            }

            $revenues = array_column($monthlyData, 'revenue');
            $orders = array_column($monthlyData, 'orders');
            $lastRevenue = (float) end($revenues);
            $lastOrders = (int) end($orders);

            // Calculate month-over-month growth rate
            $growthRates = [];
            for ($i = 1; $i < count($revenues); $i++) {
                if ($revenues[$i - 1] > 0) {
                    $growthRates[] = ($revenues[$i] - $revenues[$i - 1]) / $revenues[$i - 1];
                }
            }
            $avgGrowthRate = !empty($growthRates) ? array_sum($growthRates) / count($growthRates) : 0;

            $forecast = [
                'forecast_available' => true,
                'base_revenue' => round($lastRevenue, 2),
                'base_orders' => $lastOrders,
                'avg_growth_rate' => round($avgGrowthRate * 100, 1),
                'periods' => []
            ];

            foreach ([30, 60, 90] as $days) {
                $months = $days / 30;
                $projectedRevenue = $lastRevenue * pow(1 + $avgGrowthRate, $months);
                $projectedOrders = $lastOrders * pow(1 + $avgGrowthRate, $months);

                $forecast['periods'][] = [
                    'days' => $days,
                    'projected_revenue' => round($projectedRevenue, 2),
                    'projected_orders' => (int) round($projectedOrders),
                    'growth_percent' => round(($projectedRevenue / max(1, $lastRevenue) - 1) * 100, 1),
                    'confidence' => round(max(0.3, 0.85 - ($days * 0.002)), 2)
                ];
            }

            return $forecast;
        } catch (\Throwable $e) {
            return ['forecast_available' => false, 'reason' => 'Erro ao calcular projeção'];
        }
    }

    /**
     * Identify top optimization opportunities across account items
     */
    private function identifyTopOpportunities(array $items): array
    {
        try {
            $opportunities = [];

            foreach ($items as $item) {
                $score = 0;
                $reasons = [];
                $itemId = $item['id'] ?? '';

                // Title length check
                $titleLen = mb_strlen($item['title'] ?? '');
                if ($titleLen < 45) {
                    $score += 20;
                    $reasons[] = 'Título curto — potencial de melhoria em ranking';
                }

                // Low sales with potential
                $sold = $item['sold_quantity'] ?? 0;
                $qty = $item['available_quantity'] ?? 0;
                if ($sold < 5 && $qty > 10) {
                    $score += 15;
                    $reasons[] = 'Estoque alto com poucas vendas — otimização pode destravar';
                }

                // No thumbnail (missing image)
                if (empty($item['thumbnail'])) {
                    $score += 25;
                    $reasons[] = 'Sem imagem principal — impacto crítico em conversão';
                }

                // Active but no sales
                if (($item['status'] ?? '') === 'active' && $sold === 0) {
                    $score += 20;
                    $reasons[] = 'Item ativo sem vendas — precisa de atenção urgente';
                }

                // Price optimization potential (very low or very high vs avg)
                $price = $item['price'] ?? 0;
                if ($price > 0 && $price < 10) {
                    $score += 5;
                    $reasons[] = 'Preço muito baixo — avaliar viabilidade';
                }

                if ($score > 0) {
                    $opportunities[] = [
                        'item_id' => $itemId,
                        'title' => $item['title'] ?? '',
                        'current_price' => $price,
                        'opportunity_score' => $score,
                        'reasons' => $reasons,
                        'priority' => $score >= 30 ? 'high' : ($score >= 15 ? 'medium' : 'low'),
                        'estimated_impact' => $score >= 30 ? 'alto' : ($score >= 15 ? 'médio' : 'baixo')
                    ];
                }
            }

            // Sort by opportunity score descending, take top 10
            usort($opportunities, fn($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);

            return array_slice($opportunities, 0, 10);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Generate market predictions by active categories
     */
    private function generateMarketPredictions(array $items): array
    {
        try {
            $categories = [];
            foreach ($items as $item) {
                $catId = $item['category_id'] ?? '';
                if (empty($catId)) continue;
                if (!isset($categories[$catId])) {
                    $categories[$catId] = ['items' => 0, 'total_revenue' => 0, 'total_sold' => 0];
                }
                $categories[$catId]['items']++;
                $categories[$catId]['total_revenue'] += ($item['price'] ?? 0) * ($item['sold_quantity'] ?? 0);
                $categories[$catId]['total_sold'] += $item['sold_quantity'] ?? 0;
            }

            $predictions = [];
            foreach ($categories as $catId => $catData) {
                // Get competitor count for this category
                $stmt = $this->db->prepare("
                    SELECT COUNT(DISTINCT seller_id) AS competitors, AVG(price) AS avg_price
                    FROM competitor_items
                    WHERE category_id = :category_id AND account_id = :account_id
                ");
                $stmt->execute(['category_id' => $catId, 'account_id' => $this->accountId]);
                $competition = $stmt->fetch(\PDO::FETCH_ASSOC);

                $competitorCount = (int) ($competition['competitors'] ?? 0);
                $avgCompPrice = (float) ($competition['avg_price'] ?? 0);

                // Simple growth prediction based on sales velocity
                $growthOutlook = $catData['total_sold'] > 10 ? 'positive' : ($catData['total_sold'] > 3 ? 'stable' : 'uncertain');

                $predictions[] = [
                    'category_id' => $catId,
                    'items_count' => $catData['items'],
                    'total_sold' => $catData['total_sold'],
                    'total_revenue' => round($catData['total_revenue'], 2),
                    'competitors' => $competitorCount,
                    'avg_competitor_price' => round($avgCompPrice, 2),
                    'competition_level' => $competitorCount > 20 ? 'high' : ($competitorCount > 5 ? 'medium' : 'low'),
                    'growth_outlook' => $growthOutlook,
                    'recommendation' => $this->getCategoryRecommendation($growthOutlook, $competitorCount)
                ];
            }

            usort($predictions, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

            return $predictions;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get recommendation text for a category based on outlook
     */
    private function getCategoryRecommendation(string $outlook, int $competitors): string
    {
        if ($outlook === 'positive' && $competitors <= 10) {
            return 'Excelente oportunidade — expanda catálogo nesta categoria';
        } elseif ($outlook === 'positive' && $competitors > 10) {
            return 'Categoria em crescimento mas competitiva — foque em diferenciação';
        } elseif ($outlook === 'stable') {
            return 'Categoria estável — otimize SEO e preços para ganhar market share';
        }
        return 'Categoria incerta — monitore e considere testar novas estratégias';
    }

    /**
     * Generate strategic recommendations for the account
     */
    private function generateStrategicRecommendations(array $items): array
    {
        try {
            $recommendations = [];
            $totalItems = count($items);
            if ($totalItems === 0) return [];

            $activeItems = array_filter($items, fn(array $i): bool => ($i['status'] ?? '') === 'active');
            $zeroSales = array_filter($items, fn(array $i): bool => ($i['sold_quantity'] ?? 0) === 0);
            $lowStock = array_filter($items, fn(array $i): bool => ($i['available_quantity'] ?? 0) > 0 && ($i['available_quantity'] ?? 0) <= 3);

            // SEO recommendation
            $shortTitles = array_filter($items, fn(array $i): bool => mb_strlen($i['title'] ?? '') < 45);
            if (count($shortTitles) > $totalItems * 0.3) {
                $recommendations[] = [
                    'type' => 'seo',
                    'priority' => 'high',
                    'title' => 'Otimização de Títulos em Massa',
                    'description' => sprintf('%d itens (%.0f%%) têm títulos curtos. Otimize com keywords de alto volume.', count($shortTitles), count($shortTitles) / $totalItems * 100),
                    'estimated_impact' => 'Aumento de 15-25% em visibilidade',
                    'effort' => 'medium',
                    'items_affected' => count($shortTitles)
                ];
            }

            // Stock recommendation
            if (count($lowStock) > 0) {
                $recommendations[] = [
                    'type' => 'stock',
                    'priority' => 'critical',
                    'title' => 'Reposição de Estoque Urgente',
                    'description' => sprintf('%d itens com estoque crítico (≤3 unidades). Risco de perda de posição.', count($lowStock)),
                    'estimated_impact' => 'Prevenir perda de vendas e ranking',
                    'effort' => 'low',
                    'items_affected' => count($lowStock)
                ];
            }

            // Zero sales activation
            if (count($zeroSales) > $totalItems * 0.2) {
                $recommendations[] = [
                    'type' => 'activation',
                    'priority' => 'high',
                    'title' => 'Ativação de Itens Sem Vendas',
                    'description' => sprintf('%d itens nunca venderam. Revise preço, SEO e considere Product Ads.', count($zeroSales)),
                    'estimated_impact' => 'Potencial de +20-40% na receita total',
                    'effort' => 'high',
                    'items_affected' => count($zeroSales)
                ];
            }

            // Pricing strategy
            $avgPrice = array_sum(array_column($items, 'price')) / max(1, $totalItems);
            $recommendations[] = [
                'type' => 'pricing',
                'priority' => 'medium',
                'title' => 'Revisão de Estratégia de Preços',
                'description' => sprintf('Preço médio: R$ %.2f. Analise competitividade por categoria.', $avgPrice),
                'estimated_impact' => 'Otimização de margem e conversão',
                'effort' => 'medium',
                'items_affected' => $totalItems
            ];

            // Catalog expansion
            $categories = array_unique(array_filter(array_column($items, 'category_id')));
            if (count($categories) <= 3) {
                $recommendations[] = [
                    'type' => 'expansion',
                    'priority' => 'low',
                    'title' => 'Diversificação de Catálogo',
                    'description' => sprintf('Apenas %d categorias ativas. Diversifique para reduzir risco.', count($categories)),
                    'estimated_impact' => 'Redução de risco e novos fluxos de receita',
                    'effort' => 'high',
                    'items_affected' => 0
                ];
            }

            usort($recommendations, function ($a, $b) {
                $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
                return ($priorityOrder[$a['priority']] ?? 4) <=> ($priorityOrder[$b['priority']] ?? 4);
            });

            return $recommendations;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Assess account-level risks
     */
    private function assessAccountRisks(array $items): array
    {
        try {
            $risks = [];
            $totalItems = count($items);
            if ($totalItems === 0) return [];

            // Risk 1: Low stock across catalog
            $noStockCount = count(array_filter($items, fn(array $i): bool => ($i['available_quantity'] ?? 0) <= 0));
            $noStockRatio = $noStockCount / $totalItems;
            if ($noStockRatio > 0.1) {
                $risks[] = [
                    'type' => 'stock_depletion',
                    'severity' => $noStockRatio > 0.3 ? 'critical' : 'high',
                    'score' => round(min(1, $noStockRatio * 2), 2),
                    'description' => sprintf('%.0f%% dos itens sem estoque (%d itens)', $noStockRatio * 100, $noStockCount),
                    'mitigation' => 'Reponha estoque dos itens mais vendidos prioritariamente'
                ];
            }

            // Risk 2: Category concentration
            $categoryCounts = array_count_values(array_filter(array_column($items, 'category_id')));
            $maxCategoryRatio = max($categoryCounts) / $totalItems;
            if ($maxCategoryRatio > 0.7) {
                $risks[] = [
                    'type' => 'category_concentration',
                    'severity' => 'medium',
                    'score' => round($maxCategoryRatio, 2),
                    'description' => sprintf('%.0f%% dos itens em uma única categoria — alto risco de concentração', $maxCategoryRatio * 100),
                    'mitigation' => 'Diversifique em categorias complementares'
                ];
            }

            // Risk 3: Stale listings (active but no sales)
            $staleItems = count(array_filter(
                $items,
                fn(array $i): bool => ($i['status'] ?? '') === 'active' && ($i['sold_quantity'] ?? 0) === 0
            ));
            $staleRatio = $staleItems / max(1, $totalItems);
            if ($staleRatio > 0.2) {
                $risks[] = [
                    'type' => 'stale_listings',
                    'severity' => $staleRatio > 0.5 ? 'high' : 'medium',
                    'score' => round(min(1, $staleRatio * 1.5), 2),
                    'description' => sprintf('%d itens ativos sem vendas (%.0f%%)', $staleItems, $staleRatio * 100),
                    'mitigation' => 'Otimize SEO, ajuste preços ou pause itens sem demanda'
                ];
            }

            // Risk 4: Low SEO readiness
            $shortTitles = count(array_filter($items, fn(array $i): bool => mb_strlen($i['title'] ?? '') < 40));
            if ($shortTitles > $totalItems * 0.3) {
                $risks[] = [
                    'type' => 'poor_seo',
                    'severity' => 'medium',
                    'score' => round($shortTitles / $totalItems, 2),
                    'description' => sprintf('%d itens com títulos fracos para SEO', $shortTitles),
                    'mitigation' => 'Execute otimização em lote de títulos com keywords de alto volume'
                ];
            }

            // Overall risk score
            $overallRisk = 0;
            if (!empty($risks)) {
                $overallRisk = array_sum(array_column($risks, 'score')) / count($risks);
            }

            return [
                'overall_risk_score' => round($overallRisk, 2),
                'risk_level' => $overallRisk > 0.7 ? 'critical' : ($overallRisk > 0.4 ? 'high' : ($overallRisk > 0.2 ? 'medium' : 'low')),
                'risks' => $risks,
                'total_risks' => count($risks)
            ];
        } catch (\Throwable $e) {
            return ['overall_risk_score' => 0, 'risk_level' => 'unknown', 'risks' => []];
        }
    }

    /**
     * Calculate growth potential per product/category
     */
    private function calculateGrowthPotential(array $items): array
    {
        try {
            $potentials = [];

            foreach ($items as $item) {
                $itemId = $item['id'] ?? '';
                $price = (float) ($item['price'] ?? 0);
                $sold = (int) ($item['sold_quantity'] ?? 0);
                $qty = (int) ($item['available_quantity'] ?? 0);
                $titleLen = mb_strlen($item['title'] ?? '');

                $score = 0;
                $actions = [];

                // SEO gap — short title means room to improve
                if ($titleLen < 45) {
                    $score += 20;
                    $actions[] = 'Otimizar título para 45-58 caracteres';
                }

                // Stock availability + low sales = underperforming with potential
                if ($qty > 5 && $sold < 5) {
                    $score += 25;
                    $actions[] = 'Item com estoque disponível e baixa venda — otimizar conversão';
                }

                // Active item with sales = room to scale
                if ($sold > 5 && ($item['status'] ?? '') === 'active') {
                    $score += 15;
                    $actions[] = 'Item com vendas comprovadas — aumentar estoque e investir em ads';
                }

                // Price optimization opportunity
                if ($price > 0 && $sold > 0) {
                    $revenuePerUnit = $price;
                    $estimatedCost = $price * 0.6;
                    $margin = ($price - $estimatedCost) / $price;
                    if ($margin < 0.25) {
                        $score += 10;
                        $actions[] = 'Margem baixa — avaliar aumento de preço ou redução de custo';
                    }
                }

                if ($score > 0) {
                    $potentials[] = [
                        'item_id' => $itemId,
                        'title' => $item['title'] ?? '',
                        'growth_score' => $score,
                        'current_sales' => $sold,
                        'available_stock' => $qty,
                        'actions' => $actions,
                        'potential_level' => $score >= 30 ? 'high' : ($score >= 15 ? 'medium' : 'low')
                    ];
                }
            }

            usort($potentials, fn($a, $b) => $b['growth_score'] <=> $a['growth_score']);

            // Aggregate by category
            $categoryPotential = [];
            foreach ($items as $item) {
                $catId = $item['category_id'] ?? 'uncategorized';
                if (!isset($categoryPotential[$catId])) {
                    $categoryPotential[$catId] = ['items' => 0, 'total_score' => 0, 'total_sold' => 0];
                }
                $categoryPotential[$catId]['items']++;
                $categoryPotential[$catId]['total_sold'] += $item['sold_quantity'] ?? 0;
            }

            return [
                'item_potentials' => array_slice($potentials, 0, 15),
                'category_summary' => $categoryPotential,
                'total_items_with_potential' => count($potentials)
            ];
        } catch (\Throwable $e) {
            return ['item_potentials' => [], 'category_summary' => [], 'total_items_with_potential' => 0];
        }
    }

    /**
     * Generate prioritized action plan
     */
    private function generateActionPlan(array $items): array
    {
        try {
            $plan = [
                'immediate' => [], // This week
                'short_term' => [], // Next 2-4 weeks
                'long_term' => []  // 1-3 months
            ];

            $totalItems = count($items);
            if ($totalItems === 0) return $plan;

            // Immediate: Fix critical issues
            $noStock = array_filter($items, fn(array $i): bool => ($i['available_quantity'] ?? 0) <= 0 && ($i['status'] ?? '') === 'active');
            if (!empty($noStock)) {
                $plan['immediate'][] = [
                    'action' => 'Repor estoque de ' . count($noStock) . ' itens ativos sem estoque',
                    'impact' => 'critical',
                    'items_affected' => count($noStock),
                    'estimated_effort' => 'Baixo — contatar fornecedores'
                ];
            }

            $lowStock = array_filter($items, fn(array $i): bool => ($i['available_quantity'] ?? 0) > 0 && ($i['available_quantity'] ?? 0) <= 3);
            if (!empty($lowStock)) {
                $plan['immediate'][] = [
                    'action' => 'Repor estoque de ' . count($lowStock) . ' itens com estoque crítico (≤3)',
                    'impact' => 'high',
                    'items_affected' => count($lowStock),
                    'estimated_effort' => 'Baixo'
                ];
            }

            // Short-term: SEO optimization
            $seoNeeded = array_filter($items, fn(array $i): bool => mb_strlen($i['title'] ?? '') < 45);
            if (!empty($seoNeeded)) {
                $plan['short_term'][] = [
                    'action' => 'Otimizar títulos de ' . count($seoNeeded) . ' itens para melhorar ranking',
                    'impact' => 'high',
                    'items_affected' => count($seoNeeded),
                    'estimated_effort' => 'Médio — usar ferramenta de otimização em lote'
                ];
            }

            // Short-term: Activate dormant items
            $dormant = array_filter(
                $items,
                fn(array $i): bool => ($i['status'] ?? '') === 'active' && ($i['sold_quantity'] ?? 0) === 0
            );
            if (count($dormant) > 3) {
                $plan['short_term'][] = [
                    'action' => 'Revisar e otimizar ' . count($dormant) . ' itens sem vendas',
                    'impact' => 'medium',
                    'items_affected' => count($dormant),
                    'estimated_effort' => 'Alto — análise individual necessária'
                ];
            }

            // Long-term: Expand catalog and diversify
            $categories = array_unique(array_filter(array_column($items, 'category_id')));
            if (count($categories) <= 3) {
                $plan['long_term'][] = [
                    'action' => 'Expandir para novas categorias — atualmente em ' . count($categories),
                    'impact' => 'medium',
                    'items_affected' => 0,
                    'estimated_effort' => 'Alto — pesquisa de mercado necessária'
                ];
            }

            // Long-term: Build brand presence
            $plan['long_term'][] = [
                'action' => 'Implementar estratégia de marca — Full, frete grátis, imagens profissionais',
                'impact' => 'high',
                'items_affected' => $totalItems,
                'estimated_effort' => 'Alto — investimento contínuo'
            ];

            return [
                'plan' => $plan,
                'total_actions' => count($plan['immediate']) + count($plan['short_term']) + count($plan['long_term']),
                'critical_actions' => count(array_filter($plan['immediate'], fn(array $a): bool => ($a['impact'] ?? '') === 'critical')),
                'generated_at' => date('Y-m-d H:i:s')
            ];
        } catch (\Throwable $e) {
            return ['plan' => ['immediate' => [], 'short_term' => [], 'long_term' => []], 'total_actions' => 0];
        }
    }
}
