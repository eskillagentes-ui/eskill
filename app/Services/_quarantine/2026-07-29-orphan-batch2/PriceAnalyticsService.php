<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Price Analytics Service
 * 
 * Análises avançadas de precificação:
 * - Métricas de performance por período
 * - Tendências de mercado e previsões
 * - Análise de elasticidade de demanda
 * - Comparativo com concorrência
 * - ROI de mudanças de preço
 * - Forecasting baseado em histórico
 * 
 * @package App\Services
 */
class PriceAnalyticsService
{
    private int $accountId;
    private PDO $db;
    private MercadoLivreClient $mlClient;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
        $this->mlClient = new MercadoLivreClient($accountId);
    }

    /**
     * Dashboard de métricas gerais
     */
    public function getDashboardMetrics(string $period = '30d'): array
    {
        $startDate = $this->getStartDate($period);

        // Métricas de mudanças de preço
        $priceChanges = $this->getPriceChangeMetrics($startDate);

        // Métricas de margem
        $marginMetrics = $this->getMarginMetrics($startDate);

        // Métricas de competitividade
        $competitiveMetrics = $this->getCompetitiveMetrics();

        // Top performers
        $topPerformers = $this->getTopPerformers($startDate, 10);

        // Alertas ativos
        $activeAlerts = $this->getActiveAlerts();

        return [
            'success' => true,
            'period' => $period,
            'start_date' => $startDate,
            'metrics' => [
                'price_changes' => $priceChanges,
                'margin' => $marginMetrics,
                'competitive' => $competitiveMetrics,
                'top_performers' => $topPerformers,
                'active_alerts' => $activeAlerts
            ]
        ];
    }

    /**
     * Métricas de mudanças de preço
     */
    private function getPriceChangeMetrics(string $startDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_changes,
                SUM(CASE WHEN change_type = 'increase' THEN 1 ELSE 0 END) as increases,
                SUM(CASE WHEN change_type = 'decrease' THEN 1 ELSE 0 END) as decreases,
                AVG(ABS((new_price - old_price) / old_price * 100)) as avg_change_percent,
                COUNT(DISTINCT item_id) as items_affected
            FROM pricing_history
            WHERE account_id = :account_id
            AND created_at >= :start_date
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'start_date' => $startDate
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Métricas de margem
     */
    private function getMarginMetrics(string $startDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                AVG(margin_percent) as avg_margin,
                MIN(margin_percent) as min_margin,
                MAX(margin_percent) as max_margin,
                SUM(CASE WHEN margin_percent < 10 THEN 1 ELSE 0 END) as low_margin_items,
                SUM(CASE WHEN margin_percent >= 10 AND margin_percent < 20 THEN 1 ELSE 0 END) as medium_margin_items,
                SUM(CASE WHEN margin_percent >= 20 THEN 1 ELSE 0 END) as high_margin_items
            FROM item_costs
            WHERE account_id = :account_id
        ");
        $stmt->execute(['account_id' => $this->accountId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Métricas de competitividade
     */
    private function getCompetitiveMetrics(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                AVG(CASE WHEN price_position <= 3 THEN 1 ELSE 0 END) * 100 as top3_percent,
                AVG(CASE WHEN price_position <= 5 THEN 1 ELSE 0 END) * 100 as top5_percent,
                AVG(price_position) as avg_position,
                AVG(price_vs_avg * 100) as avg_vs_market
            FROM competitor_analysis_cache
            WHERE account_id = :account_id
            AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute(['account_id' => $this->accountId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: [
            'top3_percent' => 0,
            'top5_percent' => 0,
            'avg_position' => 0,
            'avg_vs_market' => 0
        ];
    }

    /**
     * Top performers por receita/margem
     */
    private function getTopPerformers(string $startDate, int $limit = 10): array
    {
        $limitSql = max(1, min((int)$limit, 200));

        // Usar dados de vendas do ML se disponível
        $stmt = $this->db->prepare("
            SELECT 
                ic.item_id,
                ic.item_title,
                ic.current_price,
                ic.margin_percent,
                ic.profit_per_unit,
                COALESCE(s.sold_quantity, 0) as sold_quantity,
                COALESCE(s.sold_quantity * ic.profit_per_unit, 0) as total_profit
            FROM item_costs ic
            LEFT JOIN (
                SELECT item_id, SUM(quantity) as sold_quantity
                FROM sales_data
                WHERE account_id = :account_id
                AND sale_date >= :start_date
                GROUP BY item_id
            ) s ON ic.item_id = s.item_id
            WHERE ic.account_id = :account_id2
            ORDER BY total_profit DESC
            LIMIT {$limitSql}
        ");

        $stmt->bindValue(':account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->bindValue(':account_id2', $this->accountId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $startDate);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alertas ativos
     */
    private function getActiveAlerts(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                alert_type,
                COUNT(*) as count
            FROM pricing_alerts
            WHERE account_id = :account_id
            AND is_read = 0
            GROUP BY alert_type
        ");
        $stmt->execute(['account_id' => $this->accountId]);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Análise de tendência de preços para um item
     */
    public function getPriceTrend(string $itemId, string $period = '90d'): array
    {
        $startDate = $this->getStartDate($period);

        // Histórico de preços
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date,
                old_price,
                new_price,
                change_type,
                change_source
            FROM pricing_history
            WHERE account_id = :account_id
            AND item_id = :item_id
            AND created_at >= :start_date
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'item_id' => $itemId,
            'start_date' => $startDate
        ]);
        $priceHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcular tendência
        $prices = array_column($priceHistory, 'new_price');
        $trend = $this->calculateTrend($prices);

        // Preço atual do ML
        $item = $this->mlClient->get("/items/{$itemId}");
        $currentPrice = (float)($item['price'] ?? 0);

        // Previsão
        $forecast = $this->forecastPrice($prices, 30);

        return [
            'success' => true,
            'item_id' => $itemId,
            'current_price' => $currentPrice,
            'period' => $period,
            'history' => $priceHistory,
            'trend' => $trend,
            'forecast' => $forecast,
            'statistics' => [
                'min_price' => count($prices) > 0 ? min($prices) : $currentPrice,
                'max_price' => count($prices) > 0 ? max($prices) : $currentPrice,
                'avg_price' => count($prices) > 0 ? array_sum($prices) / count($prices) : $currentPrice,
                'total_changes' => count($priceHistory),
                'volatility' => $this->calculateVolatility($prices)
            ]
        ];
    }

    /**
     * Análise de elasticidade de demanda
     */
    public function analyzeElasticity(string $itemId): array
    {
        // Buscar histórico de preços e vendas
        $stmt = $this->db->prepare("
            SELECT 
                ph.old_price,
                ph.new_price,
                ph.created_at as price_change_date,
                COALESCE(SUM(sd.quantity), 0) as sales_before,
                COALESCE(SUM(sd2.quantity), 0) as sales_after
            FROM pricing_history ph
            LEFT JOIN sales_data sd ON sd.item_id = ph.item_id 
                AND sd.sale_date BETWEEN DATE_SUB(ph.created_at, INTERVAL 7 DAY) AND ph.created_at
            LEFT JOIN sales_data sd2 ON sd2.item_id = ph.item_id 
                AND sd2.sale_date BETWEEN ph.created_at AND DATE_ADD(ph.created_at, INTERVAL 7 DAY)
            WHERE ph.account_id = :account_id
            AND ph.item_id = :item_id
            GROUP BY ph.id
            ORDER BY ph.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'item_id' => $itemId
        ]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($data) < 2) {
            return [
                'success' => true,
                'item_id' => $itemId,
                'elasticity' => null,
                'message' => 'Dados insuficientes para calcular elasticidade',
                'recommendation' => 'Aguarde mais mudanças de preço para análise'
            ];
        }

        // Calcular elasticidade
        $elasticities = [];
        foreach ($data as $point) {
            if ($point['sales_before'] > 0 && $point['old_price'] > 0) {
                $priceChange = ($point['new_price'] - $point['old_price']) / $point['old_price'];
                $demandChange = ($point['sales_after'] - $point['sales_before']) / $point['sales_before'];

                if (abs($priceChange) > 0.01) {
                    $elasticities[] = $demandChange / $priceChange;
                }
            }
        }

        $avgElasticity = count($elasticities) > 0 ? array_sum($elasticities) / count($elasticities) : 0;

        // Interpretar elasticidade
        $interpretation = $this->interpretElasticity($avgElasticity);

        return [
            'success' => true,
            'item_id' => $itemId,
            'elasticity' => round($avgElasticity, 3),
            'data_points' => count($elasticities),
            'interpretation' => $interpretation['label'],
            'recommendation' => $interpretation['recommendation'],
            'optimal_price_strategy' => $interpretation['strategy']
        ];
    }

    /**
     * Interpretar coeficiente de elasticidade
     */
    private function interpretElasticity(float $elasticity): array
    {
        $absElasticity = abs($elasticity);

        if ($absElasticity < 0.5) {
            return [
                'label' => 'Inelástico',
                'recommendation' => 'A demanda é pouco sensível ao preço. Você pode aumentar preços com baixo impacto nas vendas.',
                'strategy' => 'premium'
            ];
        } elseif ($absElasticity < 1) {
            return [
                'label' => 'Relativamente Inelástico',
                'recommendation' => 'A demanda responde moderadamente ao preço. Aumentos pequenos são seguros.',
                'strategy' => 'moderate'
            ];
        } elseif ($absElasticity == 1) {
            return [
                'label' => 'Unitário',
                'recommendation' => 'A demanda responde proporcionalmente ao preço. Mudanças de preço não afetam receita total.',
                'strategy' => 'balanced'
            ];
        } elseif ($absElasticity < 2) {
            return [
                'label' => 'Relativamente Elástico',
                'recommendation' => 'A demanda é sensível ao preço. Reduções de preço podem aumentar receita.',
                'strategy' => 'competitive'
            ];
        } else {
            return [
                'label' => 'Muito Elástico',
                'recommendation' => 'A demanda é muito sensível ao preço. Foque em preços competitivos.',
                'strategy' => 'aggressive'
            ];
        }
    }

    /**
     * Análise comparativa com concorrentes
     */
    public function getCompetitiveAnalysis(string $itemId): array
    {
        $item = $this->mlClient->get("/items/{$itemId}");
        if (!$item || isset($item['error'])) {
            return ['success' => false, 'message' => 'Item não encontrado'];
        }

        $myPrice = (float)($item['price'] ?? 0);
        $categoryId = $item['category_id'] ?? null;

        if (!$categoryId) {
            return ['success' => false, 'message' => 'Categoria não disponível'];
        }

        // Buscar concorrentes
        $searchResult = $this->mlClient->get(
            "/sites/MLB/search?category={$categoryId}&sort=price_asc&limit=50"
        );
        $results = $searchResult['results'] ?? [];

        // Filtrar próprio item e calcular estatísticas
        $competitorPrices = [];
        $myPosition = null;
        $position = 0;

        foreach ($results as $result) {
            $position++;
            $price = (float)($result['price'] ?? 0);

            if ($result['id'] === $itemId) {
                $myPosition = $position;
            } else {
                $competitorPrices[] = $price;
            }
        }

        if (count($competitorPrices) === 0) {
            return [
                'success' => true,
                'item_id' => $itemId,
                'my_price' => $myPrice,
                'message' => 'Sem concorrentes encontrados na categoria'
            ];
        }

        $minPrice = min($competitorPrices);
        $maxPrice = max($competitorPrices);
        $avgPrice = array_sum($competitorPrices) / count($competitorPrices);
        $medianPrice = $this->calculateMedian($competitorPrices);

        // Percentis
        sort($competitorPrices);
        $p25 = $this->getPercentile($competitorPrices, 25);
        $p75 = $this->getPercentile($competitorPrices, 75);

        // Calcular posição de preço
        $pricesBelowMe = count(array_filter($competitorPrices, fn($p) => $p < $myPrice));
        $percentilePosition = ($pricesBelowMe / count($competitorPrices)) * 100;

        // Recomendação
        $recommendation = $this->getCompetitiveRecommendation($myPrice, $minPrice, $avgPrice, $medianPrice);

        return [
            'success' => true,
            'item_id' => $itemId,
            'item_title' => $item['title'] ?? '',
            'my_price' => $myPrice,
            'position' => $myPosition,
            'total_competitors' => count($competitorPrices),
            'market_analysis' => [
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
                'avg_price' => round($avgPrice, 2),
                'median_price' => round($medianPrice, 2),
                'percentile_25' => round($p25, 2),
                'percentile_75' => round($p75, 2)
            ],
            'my_position_analysis' => [
                'percentile' => round($percentilePosition, 1),
                'vs_min' => round((($myPrice - $minPrice) / $minPrice) * 100, 1),
                'vs_avg' => round((($myPrice - $avgPrice) / $avgPrice) * 100, 1),
                'vs_median' => round((($myPrice - $medianPrice) / $medianPrice) * 100, 1)
            ],
            'recommendation' => $recommendation
        ];
    }

    /**
     * Obter recomendação competitiva
     */
    private function getCompetitiveRecommendation(float $myPrice, float $minPrice, float $avgPrice, float $medianPrice): array
    {
        $vsAvg = (($myPrice - $avgPrice) / $avgPrice) * 100;

        if ($vsAvg > 20) {
            return [
                'status' => 'high',
                'message' => 'Seu preço está significativamente acima do mercado',
                'action' => 'Considere reduzir para aumentar competitividade',
                'suggested_price' => round($avgPrice * 1.05, 2)
            ];
        } elseif ($vsAvg > 5) {
            return [
                'status' => 'above_average',
                'message' => 'Seu preço está acima da média do mercado',
                'action' => 'Preço pode afetar vendas, avalie redução',
                'suggested_price' => round($avgPrice, 2)
            ];
        } elseif ($vsAvg >= -5) {
            return [
                'status' => 'competitive',
                'message' => 'Seu preço está competitivo',
                'action' => 'Mantenha e monitore concorrentes',
                'suggested_price' => null
            ];
        } elseif ($vsAvg >= -15) {
            return [
                'status' => 'below_average',
                'message' => 'Seu preço está abaixo da média',
                'action' => 'Oportunidade de aumentar margem',
                'suggested_price' => round($medianPrice * 0.98, 2)
            ];
        } else {
            return [
                'status' => 'low',
                'message' => 'Seu preço está muito abaixo do mercado',
                'action' => 'Considere aumentar preço para melhorar margem',
                'suggested_price' => round($avgPrice * 0.9, 2)
            ];
        }
    }

    /**
     * ROI de mudanças de preço
     */
    public function calculatePriceChangeROI(string $itemId, int $days = 30): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        // Buscar mudanças de preço
        $stmt = $this->db->prepare("
            SELECT 
                ph.id,
                ph.old_price,
                ph.new_price,
                ph.created_at,
                ph.change_source
            FROM pricing_history ph
            WHERE ph.account_id = :account_id
            AND ph.item_id = :item_id
            AND ph.created_at >= :start_date
            ORDER BY ph.created_at ASC
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'item_id' => $itemId,
            'start_date' => $startDate
        ]);
        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($changes) === 0) {
            return [
                'success' => true,
                'item_id' => $itemId,
                'message' => 'Sem mudanças de preço no período'
            ];
        }

        $roiAnalysis = [];

        foreach ($changes as $change) {
            $changeDate = $change['created_at'];
            $oldPrice = (float)$change['old_price'];
            $newPrice = (float)$change['new_price'];

            // Buscar vendas antes e depois
            $salesBefore = $this->getSalesInPeriod(
                $itemId,
                date('Y-m-d', strtotime('-7 days', strtotime($changeDate))),
                $changeDate
            );

            $salesAfter = $this->getSalesInPeriod(
                $itemId,
                $changeDate,
                date('Y-m-d', strtotime('+7 days', strtotime($changeDate)))
            );

            // Calcular receita
            $revenueBefore = $salesBefore * $oldPrice;
            $revenueAfter = $salesAfter * $newPrice;
            $revenueChange = $revenueAfter - $revenueBefore;
            $roiPercent = $revenueBefore > 0 ? ($revenueChange / $revenueBefore) * 100 : 0;

            $roiAnalysis[] = [
                'date' => $changeDate,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'price_change_percent' => round((($newPrice - $oldPrice) / $oldPrice) * 100, 2),
                'sales_before' => $salesBefore,
                'sales_after' => $salesAfter,
                'revenue_before' => round($revenueBefore, 2),
                'revenue_after' => round($revenueAfter, 2),
                'revenue_change' => round($revenueChange, 2),
                'roi_percent' => round($roiPercent, 2),
                'source' => $change['change_source']
            ];
        }

        // Calcular totais
        $totalRevenueBefore = array_sum(array_column($roiAnalysis, 'revenue_before'));
        $totalRevenueAfter = array_sum(array_column($roiAnalysis, 'revenue_after'));
        $totalROI = $totalRevenueBefore > 0 
            ? (($totalRevenueAfter - $totalRevenueBefore) / $totalRevenueBefore) * 100 
            : 0;

        return [
            'success' => true,
            'item_id' => $itemId,
            'period_days' => $days,
            'total_changes' => count($changes),
            'analysis' => $roiAnalysis,
            'summary' => [
                'total_revenue_before' => round($totalRevenueBefore, 2),
                'total_revenue_after' => round($totalRevenueAfter, 2),
                'total_roi_percent' => round($totalROI, 2)
            ]
        ];
    }

    /**
     * Obter vendas em um período
     */
    private function getSalesInPeriod(string $itemId, string $startDate, string $endDate): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(quantity), 0) as total
            FROM sales_data
            WHERE account_id = :account_id
            AND item_id = :item_id
            AND sale_date BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'item_id' => $itemId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Forecast de preço usando médias móveis
     */
    public function forecastPrice(array $prices, int $days = 30): array
    {
        if (count($prices) < 3) {
            return [
                'available' => false,
                'message' => 'Histórico insuficiente para previsão'
            ];
        }

        // Média móvel simples
        $windowSize = min(7, count($prices));
        $lastPrices = array_slice($prices, -$windowSize);
        $sma = array_sum($lastPrices) / count($lastPrices);

        // Média móvel exponencial
        $ema = $this->calculateEMA($prices, $windowSize);

        // Tendência
        $trend = $this->calculateTrend($prices);
        $dailyTrend = $trend['slope'] ?? 0;

        // Projeções
        $forecasts = [];
        $basePrice = end($prices);

        for ($i = 1; $i <= $days; $i++) {
            $forecasts[] = [
                'day' => $i,
                'date' => date('Y-m-d', strtotime("+{$i} days")),
                'sma_forecast' => round($sma, 2),
                'ema_forecast' => round($ema, 2),
                'trend_forecast' => round($basePrice + ($dailyTrend * $i), 2)
            ];
        }

        return [
            'available' => true,
            'method' => 'Moving Average + Trend',
            'current_price' => $basePrice,
            'sma_7day' => round($sma, 2),
            'ema_7day' => round($ema, 2),
            'daily_trend' => round($dailyTrend, 4),
            'trend_direction' => $dailyTrend > 0 ? 'up' : ($dailyTrend < 0 ? 'down' : 'stable'),
            'forecasts' => array_slice($forecasts, 0, 7), // Próximos 7 dias detalhados
            'forecast_30d' => end($forecasts)
        ];
    }

    /**
     * Calcular EMA (Exponential Moving Average)
     */
    private function calculateEMA(array $prices, int $period): float
    {
        if (count($prices) < $period) {
            return array_sum($prices) / count($prices);
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($prices, 0, $period)) / $period;

        for ($i = $period; $i < count($prices); $i++) {
            $ema = (($prices[$i] - $ema) * $multiplier) + $ema;
        }

        return $ema;
    }

    /**
     * Calcular tendência (regressão linear simples)
     */
    private function calculateTrend(array $prices): array
    {
        $n = count($prices);
        if ($n < 2) {
            return ['slope' => 0, 'direction' => 'stable', 'r_squared' => 0];
        }

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;
        $sumY2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $prices[$i];
            $sumXY += $i * $prices[$i];
            $sumX2 += $i * $i;
            $sumY2 += $prices[$i] * $prices[$i];
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / (($n * $sumX2) - ($sumX * $sumX));
        
        // R-squared
        $meanY = $sumY / $n;
        $ssTot = $sumY2 - ($n * $meanY * $meanY);
        $ssRes = 0;
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        for ($i = 0; $i < $n; $i++) {
            $predicted = $intercept + $slope * $i;
            $ssRes += pow($prices[$i] - $predicted, 2);
        }
        
        $rSquared = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'direction' => $slope > 0.01 ? 'up' : ($slope < -0.01 ? 'down' : 'stable'),
            'r_squared' => round($rSquared, 4),
            'strength' => $rSquared > 0.7 ? 'strong' : ($rSquared > 0.3 ? 'moderate' : 'weak')
        ];
    }

    /**
     * Calcular volatilidade
     */
    private function calculateVolatility(array $prices): float
    {
        $n = count($prices);
        if ($n < 2) {
            return 0;
        }

        $mean = array_sum($prices) / $n;
        $sumSquaredDiff = 0;

        foreach ($prices as $price) {
            $sumSquaredDiff += pow($price - $mean, 2);
        }

        return sqrt($sumSquaredDiff / ($n - 1));
    }

    /**
     * Calcular mediana
     */
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        
        if ($count === 0) {
            return 0;
        }
        
        $middle = floor($count / 2);
        
        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }
        
        return $values[$middle];
    }

    /**
     * Obter percentil
     */
    private function getPercentile(array $values, int $percentile): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }
        
        sort($values);
        $index = ($percentile / 100) * ($count - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower === $upper) {
            return $values[$lower];
        }
        
        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($index - $lower);
    }

    /**
     * Obter data de início baseada no período
     */
    private function getStartDate(string $period): string
    {
        return match ($period) {
            '7d' => date('Y-m-d', strtotime('-7 days')),
            '30d' => date('Y-m-d', strtotime('-30 days')),
            '90d' => date('Y-m-d', strtotime('-90 days')),
            '180d' => date('Y-m-d', strtotime('-180 days')),
            '365d', '1y' => date('Y-m-d', strtotime('-1 year')),
            default => date('Y-m-d', strtotime('-30 days'))
        };
    }

    /**
     * Relatório de performance de preços
     */
    public function generatePerformanceReport(string $period = '30d'): array
    {
        $startDate = $this->getStartDate($period);

        return [
            'success' => true,
            'report_date' => date('Y-m-d H:i:s'),
            'period' => $period,
            'start_date' => $startDate,
            'sections' => [
                'overview' => $this->getDashboardMetrics($period)['metrics'],
                'price_distribution' => $this->getPriceDistribution(),
                'margin_analysis' => $this->getMarginDistribution(),
                'top_opportunities' => $this->getOptimizationOpportunities(),
                'recent_changes' => $this->getRecentChanges(10)
            ]
        ];
    }

    /**
     * Distribuição de preços
     */
    private function getPriceDistribution(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                CASE 
                    WHEN current_price < 50 THEN '0-50'
                    WHEN current_price < 100 THEN '50-100'
                    WHEN current_price < 200 THEN '100-200'
                    WHEN current_price < 500 THEN '200-500'
                    WHEN current_price < 1000 THEN '500-1000'
                    ELSE '1000+'
                END as price_range,
                COUNT(*) as count
            FROM item_costs
            WHERE account_id = :account_id
            GROUP BY price_range
            ORDER BY MIN(current_price)
        ");
        $stmt->execute(['account_id' => $this->accountId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Distribuição de margens
     */
    private function getMarginDistribution(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                CASE 
                    WHEN margin_percent < 0 THEN 'Negativa'
                    WHEN margin_percent < 10 THEN '0-10%'
                    WHEN margin_percent < 20 THEN '10-20%'
                    WHEN margin_percent < 30 THEN '20-30%'
                    ELSE '30%+'
                END as margin_range,
                COUNT(*) as count,
                AVG(current_price) as avg_price
            FROM item_costs
            WHERE account_id = :account_id
            GROUP BY margin_range
        ");
        $stmt->execute(['account_id' => $this->accountId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Oportunidades de otimização
     */
    private function getOptimizationOpportunities(): array
    {
        // Itens com margem negativa
        $stmt = $this->db->prepare("
            SELECT item_id, item_title, current_price, margin_percent
            FROM item_costs
            WHERE account_id = :account_id
            AND margin_percent < 5
            ORDER BY margin_percent ASC
            LIMIT 10
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $lowMargin = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Itens acima do mercado
        $stmt = $this->db->prepare("
            SELECT c.item_id, ic.item_title, ic.current_price, c.price_vs_avg
            FROM competitor_analysis_cache c
            JOIN item_costs ic ON c.item_id = ic.item_id AND c.account_id = ic.account_id
            WHERE c.account_id = :account_id
            AND c.price_vs_avg > 0.15
            ORDER BY c.price_vs_avg DESC
            LIMIT 10
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $aboveMarket = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'low_margin_items' => $lowMargin,
            'above_market_items' => $aboveMarket
        ];
    }

    /**
     * Mudanças recentes de preço
     */
    private function getRecentChanges(int $limit = 10): array
    {
        $limitSql = max(1, min((int)$limit, 200));

        $stmt = $this->db->prepare("
            SELECT 
                ph.item_id,
                ic.item_title,
                ph.old_price,
                ph.new_price,
                ph.change_type,
                ph.change_source,
                ph.created_at
            FROM pricing_history ph
            LEFT JOIN item_costs ic ON ph.item_id = ic.item_id AND ph.account_id = ic.account_id
            WHERE ph.account_id = :account_id
            ORDER BY ph.created_at DESC
            LIMIT {$limitSql}
        ");
        $stmt->bindValue(':account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
