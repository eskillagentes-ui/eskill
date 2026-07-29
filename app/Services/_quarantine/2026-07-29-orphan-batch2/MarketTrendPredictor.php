<?php

declare(strict_types=1);

namespace App\Services\AI\ML;

use App\Database;
use App\Services\SEO\KeywordSourceService;
use PDO;

/**
 * Market Trend Predictor
 * 
 * Uses historical data and category patterns to predict future trends.
 * Currently uses heuristic models, designed to be upgraded to real ML models.
 * 
 * @author AI Development Team
 * @version 1.0.0
 */
class MarketTrendPredictor
{
    private $db;
    private KeywordSourceService $keywordSource;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->keywordSource = new KeywordSourceService();
    }

    /**
     * Analyze current trends for a category
     * 
     * @param string $categoryId
     * @return array Trend analysis
     */
    public function analyzeTrends(string $categoryId): array
    {
        $recentStmt = $this->db->prepare("
            SELECT AVG(imh.sold_quantity) as avg_sales
            FROM item_metrics_history imh
            JOIN items i ON i.ml_item_id = imh.item_id
            WHERE i.category_id = :category_id
              AND imh.date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $recentStmt->execute(['category_id' => $categoryId]);
        $recentAvg = (float)($recentStmt->fetchColumn() ?: 0);

        $prevStmt = $this->db->prepare("
            SELECT AVG(imh.sold_quantity) as avg_sales
            FROM item_metrics_history imh
            JOIN items i ON i.ml_item_id = imh.item_id
            WHERE i.category_id = :category_id
              AND imh.date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
              AND imh.date < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $prevStmt->execute(['category_id' => $categoryId]);
        $prevAvg = (float)($prevStmt->fetchColumn() ?: 0);

        if ($recentAvg <= 0 && $prevAvg <= 0) {
            return [
                'category_id' => $categoryId,
                'trend_score' => null,
                'direction' => null,
                'hot_keywords' => $this->getTrendingKeywords($categoryId),
                'market_saturation' => null,
                'error' => 'Dados insuficientes'
            ];
        }

        $ratio = $prevAvg > 0 ? $recentAvg / $prevAvg : 1.0;
        $trendScore = (int)round(min(100, max(0, 50 + (($ratio - 1) * 50))));
        $direction = $ratio > 1.1 ? 'rising' : ($ratio < 0.9 ? 'falling' : 'stable');

        return [
            'category_id' => $categoryId,
            'trend_score' => $trendScore,
            'direction' => $direction,
            'hot_keywords' => $this->getTrendingKeywords($categoryId),
            'market_saturation' => null
        ];
    }

    /**
     * Predict seasonal spikes for the next N months
     * 
     * @param string $categoryId
     * @param int $months
     * @return array Month-by-month prediction
     */
    public function predictSeasonalSpikes(string $categoryId, int $months = 6): array
    {
        $stmt = $this->db->prepare("
            SELECT MONTH(imh.date) as month, AVG(imh.sold_quantity) as avg_sales
            FROM item_metrics_history imh
            JOIN items i ON i.ml_item_id = imh.item_id
            WHERE i.category_id = :category_id
              AND imh.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY MONTH(imh.date)
        ");
        $stmt->execute(['category_id' => $categoryId]);
        $seasonalData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $overallAvg = 0;
        if (!empty($seasonalData)) {
            $overallAvg = array_sum($seasonalData) / count($seasonalData);
        }

        $predictions = [];
        $currentMonth = (int)date('n');
        
        for ($i = 0; $i < $months; $i++) {
            $monthIndex = ($currentMonth + $i - 1) % 12 + 1;
            $monthName = date('F', mktime(0, 0, 0, $monthIndex, 10));
            $baseVolume = isset($seasonalData[$monthIndex]) ? (float)$seasonalData[$monthIndex] : $overallAvg;
            $confidence = $baseVolume > 0 ? 0.7 : 0.0;

            $predictions[] = [
                'month' => $monthName,
                'predicted_volume_index' => round($baseVolume, 1),
                'probability' => $confidence
            ];
        }
        
        return $predictions;
    }

    private function getTrendingKeywords(string $categoryId): array
    {
        $payload = $this->keywordSource->getKeywords($categoryId, '');
        $keywords = [];
        foreach ($payload['keywords'] ?? [] as $keyword) {
            if (is_string($keyword)) {
                $keywords[] = $keyword;
            } elseif (is_array($keyword)) {
                $value = $keyword['keyword'] ?? $keyword['value'] ?? $keyword['term'] ?? null;
                if (is_string($value) && $value !== '') {
                    $keywords[] = $value;
                }
            }
        }
        return array_values(array_unique($keywords));
    }
}
