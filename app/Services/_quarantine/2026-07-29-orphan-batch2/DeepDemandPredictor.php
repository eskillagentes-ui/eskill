<?php

declare(strict_types=1);

namespace App\Services\AI\ML;

use App\Database;
use PDO;

/**
 * Demand Predictor (nome legado: "Deep" — não usa deep learning)
 *
 * Usa dados reais de vendas e modelos estatísticos simples (média, desvio padrão,
 * sazonalidade hardcoded) para estimar demanda futura de SKUs.
 *
 * @author AI Development Team
 * @version 2.0.0
 */
class DeepDemandPredictor
{
    private PDO $db;

    // Seasonality factors by month (Brazilian market)
    private const SEASONALITY_FACTORS = [
        1 => 0.85,  // January - post-holiday slowdown
        2 => 0.80,  // February - carnival
        3 => 0.90,  // March
        4 => 0.95,  // April
        5 => 1.10,  // May - Mother's Day
        6 => 1.05,  // June - Valentine's Day (Brazil)
        7 => 0.95,  // July
        8 => 1.00,  // August - Father's Day
        9 => 0.95,  // September
        10 => 1.00, // October
        11 => 1.30, // November - Black Friday
        12 => 1.40, // December - Christmas
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Forecast demand for a specific SKU using real data
     *
     * @param string $sku SKU or ML item ID
     * @param int $days Number of days to forecast
     * @param int|null $accountId Account ID for filtering
     * @return array Forecast data with confidence intervals
     */
    public function forecastDemand(string $sku, int $days = 30, ?int $accountId = null): array
    {
        $accountId = $accountId ?? $_SESSION['active_ml_account_id'] ?? null;

        // Get historical sales data
        $salesHistory = $this->getSalesHistory($sku, $accountId, 90);

        // Calculate base velocity from real data
        $velocityStats = $this->calculateVelocityStatistics($salesHistory);
        $dailyVelocity = $velocityStats['mean'];
        $stdDev = $velocityStats['std_dev'];

        // Apply seasonality adjustment
        $seasonalityFactor = $this->getSeasonalityFactor($days);

        // Calculate trend (growing, declining, stable)
        $trend = $this->calculateTrend($salesHistory);

        // Apply adjustments
        $adjustedVelocity = $dailyVelocity * $seasonalityFactor * $trend['multiplier'];
        $predictedTotal = $adjustedVelocity * $days;

        // Calculate confidence intervals using standard deviation
        $confidenceMultiplier = 1.96; // 95% confidence interval
        $varianceOverDays = $stdDev * sqrt($days);

        return [
            'sku' => $sku,
            'forecast_days' => $days,
            'predicted_sales' => (int)ceil($predictedTotal),
            'confidence_interval' => [
                'low' => max(0, (int)floor($predictedTotal - ($confidenceMultiplier * $varianceOverDays))),
                'high' => (int)ceil($predictedTotal + ($confidenceMultiplier * $varianceOverDays)),
                'confidence_level' => '95%'
            ],
            'factors' => [
                'base_velocity' => round($dailyVelocity, 3),
                'adjusted_velocity' => round($adjustedVelocity, 3),
                'seasonality_factor' => $seasonalityFactor,
                'seasonality_impact' => $this->formatPercentage($seasonalityFactor - 1),
                'trend' => $trend['direction'],
                'trend_impact' => $this->formatPercentage($trend['multiplier'] - 1),
                'data_points' => count($salesHistory),
                'std_deviation' => round($stdDev, 3),
            ],
            'reliability' => $this->calculateReliability(count($salesHistory), $stdDev, $dailyVelocity),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Calculate optimal reorder point based on real data
     *
     * @param string $sku
     * @param int|null $accountId
     * @param int $leadTimeDays Supplier lead time in days
     * @param float $serviceLevel Desired service level (0.0 - 1.0)
     * @return array Reorder point with details
     */
    public function calculateReorderPoint(
        string $sku,
        ?int $accountId = null,
        int $leadTimeDays = 7,
        float $serviceLevel = 0.95
    ): array {
        $accountId = $accountId ?? $_SESSION['active_ml_account_id'] ?? null;

        // Get sales history
        $salesHistory = $this->getSalesHistory($sku, $accountId, 60);
        $velocityStats = $this->calculateVelocityStatistics($salesHistory);

        $dailyVelocity = $velocityStats['mean'];
        $stdDev = $velocityStats['std_dev'];

        // Calculate safety stock using service level
        // Z-score for service level (95% = 1.65, 99% = 2.33)
        $zScore = $this->getZScoreForServiceLevel($serviceLevel);

        // Safety stock formula: Z * σ * √(lead time)
        $safetyStock = $zScore * $stdDev * sqrt($leadTimeDays);

        // Reorder point: (daily demand * lead time) + safety stock
        $reorderPoint = ($dailyVelocity * $leadTimeDays) + $safetyStock;

        return [
            'sku' => $sku,
            'reorder_point' => (int)ceil($reorderPoint),
            'safety_stock' => (int)ceil($safetyStock),
            'details' => [
                'daily_velocity' => round($dailyVelocity, 2),
                'std_deviation' => round($stdDev, 2),
                'lead_time_days' => $leadTimeDays,
                'service_level' => ($serviceLevel * 100) . '%',
                'z_score' => $zScore,
            ],
            'recommendation' => $this->getRestockRecommendation($dailyVelocity, $stdDev, $leadTimeDays),
        ];
    }

    /**
     * Get real sales history from database
     */
    private function getSalesHistory(string $sku, ?int $accountId, int $days): array
    {
        try {
            // Try to get from order_items/ml_orders tables
            $query = "
                SELECT
                    DATE(o.date_created) as sale_date,
                    SUM(oi.quantity) as units_sold
                FROM order_items oi
                JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                WHERE (oi.sku = :sku OR oi.item_id = :sku2)
                AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                AND o.status NOT IN ('cancelled', 'refunded')
            ";

            $params = ['sku' => $sku, 'sku2' => $sku, 'days' => $days];

            if ($accountId) {
                $query .= " AND o.ml_account_id = :account_id";
                $params['account_id'] = $accountId;
            }

            $query .= " GROUP BY DATE(o.date_created) ORDER BY sale_date ASC";

            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($results)) {
                // Try alternative table structure (ml_orders)
                return $this->getSalesHistoryFromMLOrders($sku, $accountId, $days);
            }

            // Fill in missing days with zeros
            return $this->fillMissingDays($results, $days);
        } catch (\Throwable $e) {
            log_warning('Erro ao obter histórico de vendas', [
                'service' => 'DeepDemandPredictor',
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Alternative: Get sales from ML orders table
     */
    private function getSalesHistoryFromMLOrders(string $sku, ?int $accountId, int $days): array
    {
        try {
            $cutoffDate = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
            $query = "
                SELECT
                    date_created,
                    order_data
                FROM ml_orders
                WHERE date_created >= :cutoff_date
                AND status NOT IN ('cancelled')
                AND (
                    JSON_SEARCH(order_data, 'one', :sku, NULL, '$.order_items[*].item.id') IS NOT NULL
                    OR JSON_SEARCH(order_data, 'one', :sku, NULL, '$.order_items[*].item_id') IS NOT NULL
                    OR JSON_SEARCH(order_data, 'one', :sku, NULL, '$.order_items[*].id') IS NOT NULL
                    OR JSON_SEARCH(order_data, 'one', :sku, NULL, '$.items[*].id') IS NOT NULL
                )
            ";

            $params = [
                'cutoff_date' => $cutoffDate,
                'sku' => $sku,
            ];

            if ($accountId) {
                $query .= " AND ml_account_id = :account_id";
                $params['account_id'] = $accountId;
            }

            $query .= " ORDER BY date_created ASC";

            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $dailyTotals = [];
            foreach ($rows as $row) {
                $orderData = json_decode((string) ($row['order_data'] ?? '{}'), true);
                if (!is_array($orderData)) {
                    continue;
                }

                $items = $orderData['order_items'] ?? $orderData['items'] ?? [];
                if (!is_array($items)) {
                    continue;
                }

                $dailyUnits = 0;
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $itemId = (string) ($item['item']['id'] ?? $item['item_id'] ?? $item['id'] ?? '');
                    if ($itemId !== $sku) {
                        continue;
                    }

                    $dailyUnits += (int) ($item['quantity'] ?? 0);
                }

                if ($dailyUnits <= 0) {
                    continue;
                }

                $saleDate = (string) ($row['date_created'] ?? '');
                $date = substr($saleDate, 0, 10);
                if ($date === '') {
                    continue;
                }
                $dailyTotals[$date] = ($dailyTotals[$date] ?? 0) + $dailyUnits;
            }

            ksort($dailyTotals);
            $results = [];
            foreach ($dailyTotals as $date => $units) {
                $results[] = [
                    'sale_date' => $date,
                    'units_sold' => $units,
                ];
            }

            return $this->fillMissingDays($results, $days);
        } catch (\Throwable $e) {
            // Table might not exist, return empty
            return [];
        }
    }

    /**
     * Fill missing days with zero sales
     */
    private function fillMissingDays(array $salesData, int $days): array
    {
        $filled = [];
        $salesByDate = [];

        foreach ($salesData as $row) {
            $salesByDate[$row['sale_date']] = (int)$row['units_sold'];
        }

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $filled[] = [
                'date' => $date,
                'units' => $salesByDate[$date] ?? 0
            ];
        }

        return $filled;
    }

    /**
     * Calculate velocity statistics from sales history
     */
    private function calculateVelocityStatistics(array $salesHistory): array
    {
        if (empty($salesHistory)) {
            return ['mean' => 0, 'std_dev' => 0, 'max' => 0, 'min' => 0];
        }

        $values = array_column($salesHistory, 'units');
        $count = count($values);
        $sum = array_sum($values);
        $mean = $sum / $count;

        // Calculate standard deviation
        $squaredDiffs = array_map(function (float|int $val) use ($mean): float|int {
            return pow($val - $mean, 2);
        }, $values);

        $variance = array_sum($squaredDiffs) / $count;
        $stdDev = sqrt($variance);

        return [
            'mean' => $mean,
            'std_dev' => $stdDev,
            'max' => max($values),
            'min' => min($values),
            'total' => $sum,
        ];
    }

    /**
     * Calculate trend direction and multiplier
     */
    private function calculateTrend(array $salesHistory): array
    {
        if (count($salesHistory) < 14) {
            return ['direction' => 'insufficient_data', 'multiplier' => 1.0];
        }

        // Compare last 2 weeks vs previous 2 weeks
        $recentWeeks = array_slice($salesHistory, -14);
        $previousWeeks = array_slice($salesHistory, -28, 14);

        if (count($previousWeeks) < 14) {
            return ['direction' => 'stable', 'multiplier' => 1.0];
        }

        $recentAvg = array_sum(array_column($recentWeeks, 'units')) / 14;
        $previousAvg = array_sum(array_column($previousWeeks, 'units')) / 14;

        if ($previousAvg == 0) {
            return ['direction' => 'new_product', 'multiplier' => 1.0];
        }

        $changeRate = ($recentAvg - $previousAvg) / $previousAvg;

        if ($changeRate > 0.15) {
            return ['direction' => 'growing', 'multiplier' => 1 + min($changeRate, 0.3)];
        } elseif ($changeRate < -0.15) {
            return ['direction' => 'declining', 'multiplier' => 1 + max($changeRate, -0.3)];
        } else {
            return ['direction' => 'stable', 'multiplier' => 1.0];
        }
    }

    /**
     * Get seasonality factor for forecast period
     */
    private function getSeasonalityFactor(int $forecastDays): float
    {
        $currentMonth = (int)date('n');
        $nextMonth = $currentMonth + 1 > 12 ? 1 : $currentMonth + 1;

        // Weight current and next month based on forecast period
        if ($forecastDays <= 15) {
            return self::SEASONALITY_FACTORS[$currentMonth];
        } else {
            // Weighted average for longer forecasts
            $currentWeight = 0.6;
            $nextWeight = 0.4;
            return (self::SEASONALITY_FACTORS[$currentMonth] * $currentWeight) +
                (self::SEASONALITY_FACTORS[$nextMonth] * $nextWeight);
        }
    }

    /**
     * Get Z-score for service level
     */
    private function getZScoreForServiceLevel(float $serviceLevel): float
    {
        // Common service levels and their Z-scores
        // Note: float array keys are truncated to int in PHP, so use tuples
        $zScores = [
            [0.80, 0.84],
            [0.85, 1.04],
            [0.90, 1.28],
            [0.95, 1.65],
            [0.97, 1.88],
            [0.99, 2.33],
        ];

        // Find closest match
        $closestZ = 1.65; // default: 0.95 service level
        $minDiff = PHP_FLOAT_MAX;

        foreach ($zScores as [$level, $z]) {
            $diff = abs($level - $serviceLevel);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closestZ = $z;
            }
        }

        return $closestZ;
    }

    /**
     * Calculate forecast reliability score
     */
    private function calculateReliability(int $dataPoints, float $stdDev, float $mean): array
    {
        $score = 100;
        $issues = [];

        // Penalize for insufficient data
        if ($dataPoints < 7) {
            $score -= 40;
            $issues[] = 'Insufficient data (< 7 days)';
        } elseif ($dataPoints < 30) {
            $score -= 20;
            $issues[] = 'Limited data (< 30 days)';
        }

        // Penalize for high variance (coefficient of variation)
        if ($mean > 0) {
            $cv = $stdDev / $mean;
            if ($cv > 1.5) {
                $score -= 30;
                $issues[] = 'Very high variance in sales';
            } elseif ($cv > 1.0) {
                $score -= 15;
                $issues[] = 'High variance in sales';
            }
        }

        // No sales is unreliable
        if ($mean == 0) {
            $score = 10;
            $issues[] = 'No historical sales data';
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'level' => $score >= 80 ? 'high' : ($score >= 50 ? 'medium' : 'low'),
            'issues' => $issues,
        ];
    }

    /**
     * Get restock recommendation
     */
    private function getRestockRecommendation(float $velocity, float $stdDev, int $leadTime): string
    {
        if ($velocity == 0) {
            return 'No recent sales - consider discontinuing or promoting this product';
        }

        $cv = $velocity > 0 ? $stdDev / $velocity : 0;

        if ($cv > 1.0) {
            return 'High demand variability - consider higher safety stock or more frequent ordering';
        } elseif ($velocity > 5) {
            return 'High velocity item - consider automated reordering';
        } else {
            return 'Standard reorder point based on lead time and service level';
        }
    }

    /**
     * Format percentage for display
     */
    private function formatPercentage(float $value): string
    {
        $percent = round($value * 100, 1);
        return ($percent >= 0 ? '+' : '') . $percent . '%';
    }

    /**
     * Batch forecast for multiple SKUs
     */
    public function batchForecast(array $skus, int $days = 30, ?int $accountId = null): array
    {
        $results = [];

        foreach ($skus as $sku) {
            $results[$sku] = $this->forecastDemand($sku, $days, $accountId);
        }

        return $results;
    }
}
