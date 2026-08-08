<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;

/**
 * 📈 PERFORMANCE TRACKER - Monitoramento de Performance
 *
 * Rastreia a performance dos anúncios após otimização:
 * - Views, clicks, vendas
 * - Comparação antes/depois
 * - ROI das otimizações
 * - Trends e insights
 *
 * @author AI Development Team
 * @version 1.0.0
 */
class PerformanceTracker
{
    private PDO $db;
    private int $accountId;
    private ?MercadoLivreClient $mlClient = null;

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);

        $this->ensureTableExists();
    }

    /**
     * Ensure tracking table exists
     */
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_performance_metrics (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    item_id VARCHAR(50) NOT NULL,
                    metric_date DATE NOT NULL,
                    views INT DEFAULT 0,
                    visits INT DEFAULT 0,
                    sold_quantity INT DEFAULT 0,
                    revenue DECIMAL(12,2) DEFAULT 0,
                    conversion_rate DECIMAL(5,2) DEFAULT 0,
                    position_avg DECIMAL(5,2) DEFAULT 0,
                    was_optimized TINYINT(1) DEFAULT 0,
                    optimization_date DATE NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_item_date (item_id, metric_date),
                    INDEX idx_account (account_id),
                    INDEX idx_item (item_id),
                    INDEX idx_date (metric_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_optimization_events (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    item_id VARCHAR(50) NOT NULL,
                    optimization_type ENUM('title', 'description', 'attributes', 'full') NOT NULL,
                    old_value TEXT,
                    new_value TEXT,
                    score_before INT DEFAULT 0,
                    score_after INT DEFAULT 0,
                    optimized_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_account (account_id),
                    INDEX idx_item (item_id),
                    INDEX idx_date (optimized_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            log_error('Falha ao criar tabela seo_optimizations', [
                'service' => 'AI\\SEO\\PerformanceTracker',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 📊 Coletar métricas de um item
     */
    public function collectItemMetrics(string $itemId): array
    {
        try {
            // Get item data from ML
            $item = $this->mlClient->get("/items/{$itemId}");

            // Get visits (would need ML metrics API)
            $visits = $this->getItemVisits($itemId);

            $metrics = [
                'item_id' => $itemId,
                'date' => date('Y-m-d'),
                'views' => $visits['total'] ?? 0,
                'visits' => $visits['visits'] ?? 0,
                'sold_quantity' => $item['sold_quantity'] ?? 0,
                'price' => $item['price'] ?? 0,
                'revenue' => ($item['sold_quantity'] ?? 0) * ($item['price'] ?? 0),
            ];

            // Save metrics
            $this->saveMetrics($itemId, $metrics);

            return $metrics;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 📈 Obter performance de um item
     */
    public function getItemPerformance(string $itemId, int $days = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM seo_performance_metrics
            WHERE item_id = ?
            AND metric_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY metric_date ASC
        ");
        $stmt->execute([$itemId, $days]);

        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get optimization events
        $stmt = $this->db->prepare("
            SELECT * FROM seo_optimization_events
            WHERE item_id = ?
            ORDER BY optimized_at DESC
        ");
        $stmt->execute([$itemId]);
        $optimizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate trends
        $trend = $this->calculateTrend($metrics);

        return [
            'item_id' => $itemId,
            'period' => "{$days} dias",
            'metrics' => $metrics,
            'optimizations' => $optimizations,
            'trend' => $trend,
            'summary' => $this->summarizePerformance($metrics, $optimizations),
        ];
    }

    /**
     * 📊 Comparar performance antes/depois da otimização
     */
    public function compareBeforeAfter(string $itemId): array
    {
        // Get last optimization date
        $stmt = $this->db->prepare("
            SELECT optimized_at FROM seo_optimization_events
            WHERE item_id = ?
            ORDER BY optimized_at DESC
            LIMIT 1
        ");
        $stmt->execute([$itemId]);
        $lastOpt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastOpt) {
            return [
                'item_id' => $itemId,
                'optimized' => false,
                'message' => 'Item ainda não foi otimizado'
            ];
        }

        $optimizationDate = $lastOpt['optimized_at'];

        // Get metrics before
        $stmt = $this->db->prepare("
            SELECT
                AVG(views) as avg_views,
                AVG(visits) as avg_visits,
                SUM(sold_quantity) as total_sales,
                SUM(revenue) as total_revenue,
                COUNT(*) as days
            FROM seo_performance_metrics
            WHERE item_id = ?
            AND metric_date < DATE(?)
            AND metric_date >= DATE_SUB(DATE(?), INTERVAL 30 DAY)
        ");
        $stmt->execute([$itemId, $optimizationDate, $optimizationDate]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get metrics after
        $stmt = $this->db->prepare("
            SELECT
                AVG(views) as avg_views,
                AVG(visits) as avg_visits,
                SUM(sold_quantity) as total_sales,
                SUM(revenue) as total_revenue,
                COUNT(*) as days
            FROM seo_performance_metrics
            WHERE item_id = ?
            AND metric_date >= DATE(?)
        ");
        $stmt->execute([$itemId, $optimizationDate]);
        $after = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate improvements
        $comparison = [
            'item_id' => $itemId,
            'optimization_date' => $optimizationDate,
            'before' => [
                'period' => '30 dias antes',
                'avg_views' => round($before['avg_views'] ?? 0, 1),
                'avg_visits' => round($before['avg_visits'] ?? 0, 1),
                'total_sales' => (int) ($before['total_sales'] ?? 0),
                'total_revenue' => round($before['total_revenue'] ?? 0, 2),
            ],
            'after' => [
                'period' => ($after['days'] ?? 0) . ' dias depois',
                'avg_views' => round($after['avg_views'] ?? 0, 1),
                'avg_visits' => round($after['avg_visits'] ?? 0, 1),
                'total_sales' => (int) ($after['total_sales'] ?? 0),
                'total_revenue' => round($after['total_revenue'] ?? 0, 2),
            ],
        ];

        // Calculate percentage changes
        $comparison['improvements'] = [
            'views' => $this->calculatePercentChange($before['avg_views'], $after['avg_views']),
            'visits' => $this->calculatePercentChange($before['avg_visits'], $after['avg_visits']),
            'sales' => $this->calculatePercentChange($before['total_sales'], $after['total_sales']),
            'revenue' => $this->calculatePercentChange($before['total_revenue'], $after['total_revenue']),
        ];

        $comparison['roi_analysis'] = $this->calculateROI($comparison);

        return $comparison;
    }

    /**
     * 🏆 Ranking de items por performance pós-otimização
     * Com fallback para top por receita quando ainda não há optimization events.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function getTopPerformers(int $limit = 10): array
    {
        $limitSql = max(1, min(200, (int) $limit));

        $stmt = $this->db->prepare("
            SELECT
                pm.item_id,
                MAX(i.title) as title,
                MAX(i.thumbnail) as thumbnail,
                MAX(oe.optimized_at) as optimized_at,
                AVG(pm.views) as avg_views,
                SUM(pm.sold_quantity) as total_sales,
                SUM(pm.revenue) as total_revenue,
                MAX(oe.score_before) as score_before,
                MAX(oe.score_after) as score_after,
                MAX(oe.score_after) - MAX(oe.score_before) as score_improvement
            FROM seo_performance_metrics pm
            INNER JOIN seo_optimization_events oe ON pm.item_id = oe.item_id
                AND oe.account_id = pm.account_id
            LEFT JOIN items i ON pm.item_id COLLATE utf8mb4_unicode_ci = i.ml_item_id
                AND i.account_id = pm.account_id
            WHERE pm.account_id = ?
            AND pm.metric_date >= DATE(oe.optimized_at)
            GROUP BY pm.item_id
            ORDER BY total_revenue DESC
            LIMIT {$limitSql}
        ");
        $stmt->execute([$this->accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            $accountId = (int) $this->accountId;
            $stmt = $this->db->prepare("
                SELECT
                    pm.item_id,
                    MAX(i.title) as title,
                    MAX(i.thumbnail) as thumbnail,
                    NULL as optimized_at,
                    AVG(pm.views) as avg_views,
                    SUM(pm.sold_quantity) as total_sales,
                    SUM(pm.revenue) as total_revenue,
                    (
                        SELECT h.overall_score
                        FROM seo_scores_history h
                        WHERE h.account_id = {$accountId}
                          AND h.item_id = pm.item_id
                        ORDER BY h.created_at DESC
                        LIMIT 1
                    ) as score_after
                FROM seo_performance_metrics pm
                LEFT JOIN items i ON pm.item_id COLLATE utf8mb4_unicode_ci = i.ml_item_id
                    AND i.account_id = pm.account_id
                WHERE pm.account_id = ?
                AND pm.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY pm.item_id
                ORDER BY total_revenue DESC
                LIMIT {$limitSql}
            ");
            $stmt->execute([$this->accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $items = array_map(static function (array $row): array {
            $scoreAfter = $row['score_after'] !== null ? (int) round((float) $row['score_after']) : 0;
            $scoreBefore = array_key_exists('score_before', $row) && $row['score_before'] !== null
                ? (int) round((float) $row['score_before'])
                : $scoreAfter;
            $improvement = array_key_exists('score_improvement', $row) && $row['score_improvement'] !== null
                ? (int) round((float) $row['score_improvement'])
                : ($scoreAfter - $scoreBefore);

            return [
                'item_id' => (string) ($row['item_id'] ?? ''),
                'title' => (string) ($row['title'] ?? $row['item_id'] ?? ''),
                'thumbnail' => (string) ($row['thumbnail'] ?? ''),
                'score_before' => $scoreBefore,
                'score_after' => $scoreAfter,
                'score_improvement' => $improvement,
                'sales_increase' => 0,
                'total_sales' => (int) ($row['total_sales'] ?? 0),
                'total_revenue' => round((float) ($row['total_revenue'] ?? 0), 2),
                'optimized_at' => $row['optimized_at'] ?? null,
            ];
        }, $rows);

        return ['items' => $items];
    }

    /**
     * 📊 Dashboard de performance geral
     */
    public function getDashboard(): array
    {
        // Overall stats
        $stmt = $this->db->prepare("
            SELECT
                COUNT(DISTINCT item_id) as total_items,
                SUM(views) as total_views,
                SUM(sold_quantity) as total_sales,
                SUM(revenue) as total_revenue
            FROM seo_performance_metrics
            WHERE account_id = ?
            AND metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$this->accountId]);
        $overall = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_items' => 0,
            'total_views' => 0,
            'total_sales' => 0,
            'total_revenue' => 0,
        ];

        // Optimized vs non-optimized
        $stmt = $this->db->prepare("
            SELECT
                was_optimized,
                COUNT(DISTINCT item_id) as items,
                AVG(views) as avg_views,
                SUM(sold_quantity) as sales
            FROM seo_performance_metrics
            WHERE account_id = ?
            AND metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY was_optimized
        ");
        $stmt->execute([$this->accountId]);
        $comparison = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top performers
        $topPerformers = $this->getTopPerformers(5)['items'];

        // Score evolution
        $autoPilot = new AutoPilot($this->accountId);
        $scoreEvolution = $autoPilot->getScoreEvolution(30);

        // Campos flat esperados por performance-tracker-tab.php
        $optStmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_optimizations,
                AVG(score_before) as average_score_before,
                AVG(score_after) as average_score_after,
                AVG(score_after - score_before) as score_improvement
            FROM seo_optimization_events
            WHERE account_id = ?
            AND optimized_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $optStmt->execute([$this->accountId]);
        $opt = $optStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalOptimizations = (int) ($opt['total_optimizations'] ?? 0);
        $avgBefore = $opt['average_score_before'] !== null ? (int) round((float) $opt['average_score_before']) : null;
        $avgAfter = $opt['average_score_after'] !== null ? (int) round((float) $opt['average_score_after']) : null;
        $scoreImprovement = $opt['score_improvement'] !== null ? (int) round((float) $opt['score_improvement']) : 0;

        // Sem eventos de otimização: usa média atual de seo_scores_history
        if ($avgBefore === null || $avgAfter === null) {
            $histAvg = null;
            if ($scoreEvolution !== []) {
                $last = $scoreEvolution[array_key_last($scoreEvolution)];
                $histAvg = isset($last['score']) ? (int) round((float) $last['score']) : null;
            }
            if ($histAvg === null) {
                $histStmt = $this->db->prepare("
                    SELECT AVG(overall_score) AS avg_score
                    FROM seo_scores_history
                    WHERE account_id = ?
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $histStmt->execute([$this->accountId]);
                $histRow = $histStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($histRow['avg_score'] !== null) {
                    $histAvg = (int) round((float) $histRow['avg_score']);
                }
            }
            if ($histAvg !== null) {
                $avgBefore = $avgBefore ?? $histAvg;
                $avgAfter = $avgAfter ?? $histAvg;
            }
        }

        $totalViews = (int) ($overall['total_views'] ?? 0);
        $totalSales = (int) ($overall['total_sales'] ?? 0);
        $totalRevenue = (float) ($overall['total_revenue'] ?? 0);
        $conversionsIncrease = $totalViews > 0
            ? (int) round(($totalSales / $totalViews) * 100)
            : 0;

        return [
            'period' => 'últimos 30 dias',
            'overall' => $overall,
            'optimized_vs_not' => $comparison,
            'top_performers' => $topPerformers,
            'score_evolution' => $scoreEvolution,
            // Contrato UI (cards Overview Geral)
            'total_optimizations' => $totalOptimizations,
            'optimizations_change' => 0,
            'average_score_before' => $avgBefore ?? '-',
            'average_score_after' => $avgAfter ?? '-',
            'score_improvement' => $scoreImprovement,
            'estimated_roi' => round($totalRevenue, 2),
            'roi_change' => 0,
            'conversions_increase' => $conversionsIncrease,
            'views_increase' => 0,
        ];
    }

    /**
     * 📝 Registrar evento de otimização
     */
    public function recordOptimization(
        string $itemId,
        string $type,
        ?string $oldValue,
        ?string $newValue,
        int $scoreBefore,
        int $scoreAfter
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO seo_optimization_events
            (account_id, item_id, optimization_type, old_value, new_value, score_before, score_after)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $this->accountId,
            $itemId,
            $type,
            $oldValue,
            $newValue,
            $scoreBefore,
            $scoreAfter
        ]);

        // Mark item as optimized in metrics
        $stmt = $this->db->prepare("
            UPDATE seo_performance_metrics
            SET was_optimized = 1, optimization_date = CURDATE()
            WHERE item_id = ?
        ");
        $stmt->execute([$itemId]);
    }

    // Private helpers

    private function getItemVisits(string $itemId): array
    {
        try {
            return $this->mlClient->getItemVisits($itemId, 30);
        } catch (\Exception $e) {
            return ['total' => 0, 'visits' => 0, 'daily' => []];
        }
    }

    private function saveMetrics(string $itemId, array $metrics): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO seo_performance_metrics
            (account_id, item_id, metric_date, views, visits, sold_quantity, revenue)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            views = VALUES(views),
            visits = VALUES(visits),
            sold_quantity = VALUES(sold_quantity),
            revenue = VALUES(revenue)
        ");

        $stmt->execute([
            $this->accountId,
            $itemId,
            $metrics['date'],
            $metrics['views'],
            $metrics['visits'],
            $metrics['sold_quantity'],
            $metrics['revenue'],
        ]);
    }

    private function calculateTrend(array $metrics): array
    {
        if (count($metrics) < 2) {
            return ['direction' => 'stable', 'change' => 0];
        }

        $firstHalf = array_slice($metrics, 0, (int) floor(count($metrics) / 2));
        $secondHalf = array_slice($metrics, (int) floor(count($metrics) / 2));

        $avgFirst = count($firstHalf) ? array_sum(array_column($firstHalf, 'views')) / count($firstHalf) : 0;
        $avgSecond = count($secondHalf) ? array_sum(array_column($secondHalf, 'views')) / count($secondHalf) : 0;

        $change = $avgFirst > 0 ? (($avgSecond - $avgFirst) / $avgFirst) * 100 : 0;

        return [
            'direction' => $change > 5 ? 'up' : ($change < -5 ? 'down' : 'stable'),
            'change' => round($change, 1),
        ];
    }

    private function summarizePerformance(array $metrics, array $optimizations): array
    {
        $totalViews = array_sum(array_column($metrics, 'views'));
        $totalSales = array_sum(array_column($metrics, 'sold_quantity'));
        $totalRevenue = array_sum(array_column($metrics, 'revenue'));

        return [
            'total_views' => $totalViews,
            'total_sales' => $totalSales,
            'total_revenue' => round($totalRevenue, 2),
            'optimizations_count' => count($optimizations),
            'conversion_rate' => $totalViews > 0 ? round(($totalSales / $totalViews) * 100, 2) : 0,
        ];
    }

    private function calculatePercentChange($before, $after): string
    {
        if (!$before || $before == 0) {
            return $after > 0 ? '+100%' : '0%';
        }

        $change = (($after - $before) / $before) * 100;
        $sign = $change >= 0 ? '+' : '';

        return $sign . round($change, 1) . '%';
    }

    private function calculateROI(array $comparison): array
    {
        $revenueBefore = $comparison['before']['total_revenue'] ?? 0;
        $revenueAfter = $comparison['after']['total_revenue'] ?? 0;
        $revenueIncrease = $revenueAfter - $revenueBefore;

        // Estimate optimization cost (time/AI calls)
        $estimatedCost = 10; // R$ 10 per optimization (AI API costs)

        $roi = $estimatedCost > 0 ? (($revenueIncrease - $estimatedCost) / $estimatedCost) * 100 : 0;

        return [
            'revenue_increase' => round($revenueIncrease, 2),
            'estimated_cost' => $estimatedCost,
            'net_gain' => round($revenueIncrease - $estimatedCost, 2),
            'roi_percentage' => round($roi, 1),
            'verdict' => $roi > 0 ? 'Positivo' : 'Negativo',
        ];
    }

    /**
     * 📊 Métricas consolidadas de todos os itens otimizados
     *
     * @return array Estatísticas consolidadas
     */
    public function getConsolidatedMetrics(): array
    {
        // Buscar todos os itens otimizados
        $stmt = $this->db->prepare("
            SELECT DISTINCT item_id, optimization_date, score_before, score_after
            FROM seo_optimization_events
            WHERE account_id = ?
            ORDER BY optimized_at DESC
        ");
        $stmt->execute([$this->accountId]);
        $optimizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($optimizations)) {
            return [
                'total_items_optimized' => 0,
                'avg_score_improvement' => 0,
                'total_revenue_impact' => 0,
                'avg_roi' => 0,
                'items' => [],
            ];
        }

        $totalRevenue = 0;
        $totalROI = 0;
        $totalScoreImprovement = 0;
        $itemsWithData = 0;

        foreach ($optimizations as $opt) {
            $comparison = $this->compareBeforeAfter($opt['item_id']);

            if (isset($comparison['roi'])) {
                $totalRevenue += $comparison['roi']['revenue_increase'];
                $totalROI += $comparison['roi']['roi_percentage'];
                $itemsWithData++;
            }

            $scoreImp = ($opt['score_after'] ?? 0) - ($opt['score_before'] ?? 0);
            $totalScoreImprovement += $scoreImp;
        }

        return [
            'total_items_optimized' => count($optimizations),
            'avg_score_improvement' => count($optimizations) > 0
                ? round($totalScoreImprovement / count($optimizations), 1)
                : 0,
            'total_revenue_impact' => round($totalRevenue, 2),
            'avg_roi' => $itemsWithData > 0 ? round($totalROI / $itemsWithData, 1) : 0,
            'items_with_positive_impact' => $itemsWithData,
        ];
    }

    /**
     * 📈 Evolução temporal das métricas
     *
     * @param int $days Período em dias
     * @return array Dados para gráfico
     */
    public function getMetricsEvolution(int $days = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT
                metric_date,
                SUM(views) as total_views,
                SUM(sold_quantity) as total_sales,
                SUM(revenue) as total_revenue,
                AVG(conversion_rate) as avg_conversion,
                COUNT(DISTINCT item_id) as items_count
            FROM seo_performance_metrics
            WHERE account_id = ?
            AND metric_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY metric_date
            ORDER BY metric_date ASC
        ");
        $stmt->execute([$this->accountId, $days]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Preparar dados para Chart.js
        $labels = [];
        $datasets = [
            'views' => [],
            'sales' => [],
            'revenue' => [],
            'conversion' => [],
        ];

        foreach ($data as $row) {
            $labels[] = date('d/m', strtotime($row['metric_date']));
            $datasets['views'][] = (int)$row['total_views'];
            $datasets['sales'][] = (int)$row['total_sales'];
            $datasets['revenue'][] = round((float)$row['total_revenue'], 2);
            $datasets['conversion'][] = round((float)$row['avg_conversion'], 2);
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
            'summary' => [
                'total_days' => count($data),
                'avg_daily_views' => count($data) > 0
                    ? round(array_sum($datasets['views']) / count($data), 0)
                    : 0,
                'avg_daily_sales' => count($data) > 0
                    ? round(array_sum($datasets['sales']) / count($data), 1)
                    : 0,
                'total_revenue' => round(array_sum($datasets['revenue']), 2),
            ],
        ];
    }

    /**
     * 🏆 Ranking de performance por categoria
     *
     * @return array Rankings
     */
    public function getCategoryPerformance(): array
    {
        $stmt = $this->db->prepare("
            SELECT item_id
            FROM seo_performance_metrics
            WHERE account_id = ?
            GROUP BY item_id
            LIMIT 100
        ");
        $stmt->execute([$this->accountId]);
        $items = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $categories = [];
        $categoryNames = [];

        foreach ($items as $itemId) {
            try {
                $item = $this->mlClient->get("/items/{$itemId}");
                $catId = $item['category_id'] ?? 'unknown';

                if (!isset($categories[$catId])) {
                    if ($catId !== 'unknown' && !isset($categoryNames[$catId])) {
                        try {
                            $categoryData = $this->mlClient->get("/categories/{$catId}");
                            $categoryNames[$catId] = $categoryData['name'] ?? $catId;
                        } catch (\Exception $e) {
                            $categoryNames[$catId] = $catId;
                        }
                    }
                    $categories[$catId] = [
                        'category_id' => $catId,
                        'category_name' => $categoryNames[$catId] ?? $catId,
                        'items_count' => 0,
                        'total_sales' => 0,
                        'total_revenue' => 0,
                    ];
                }

                $categories[$catId]['items_count']++;
                $categories[$catId]['total_sales'] += $item['sold_quantity'] ?? 0;
                $categories[$catId]['total_revenue'] += ($item['sold_quantity'] ?? 0) * ($item['price'] ?? 0);
            } catch (\Exception $e) {
                continue;
            }
        }

        // Ordenar por receita
        usort($categories, function ($a, $b) {
            return $b['total_revenue'] <=> $a['total_revenue'];
        });

        return array_slice($categories, 0, 10);
    }

    /**
     * 📊 Export de dados para relatório
     *
     * @param string $format 'csv' ou 'json'
     * @return array|string Dados formatados
     */
    public function exportPerformanceReport(string $format = 'json'): array|string
    {
        $dashboard = $this->getDashboard();
        $consolidated = $this->getConsolidatedMetrics();
        $evolution = $this->getMetricsEvolution(30);
        $topPerformers = $this->getTopPerformers(20)['items'];

        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'account_id' => $this->accountId,
            'summary' => [
                'total_optimizations' => $dashboard['summary']['total_optimizations'],
                'total_items_optimized' => $consolidated['total_items_optimized'],
                'avg_score_improvement' => $consolidated['avg_score_improvement'],
                'total_revenue_impact' => $consolidated['total_revenue_impact'],
                'avg_roi' => $consolidated['avg_roi'],
            ],
            'evolution_30_days' => $evolution,
            'top_performers' => $topPerformers,
        ];

        if ($format === 'csv') {
            return $this->convertToCSV($report);
        }

        return $report;
    }

    /**
     * Convert report data to CSV format
     */
    private function convertToCSV(array $report): string
    {
        $csv = "SEO KILLER - Performance Report\n";
        $csv .= "Generated at: {$report['generated_at']}\n\n";

        $csv .= "SUMMARY\n";
        $csv .= "Total Optimizations," . $report['summary']['total_optimizations'] . "\n";
        $csv .= "Items Optimized," . $report['summary']['total_items_optimized'] . "\n";
        $csv .= "Avg Score Improvement," . $report['summary']['avg_score_improvement'] . "\n";
        $csv .= "Total Revenue Impact,R$ " . $report['summary']['total_revenue_impact'] . "\n";
        $csv .= "Avg ROI," . $report['summary']['avg_roi'] . "%\n\n";

        $csv .= "TOP PERFORMERS\n";
        $csv .= "Item ID,Title,Score Before,Score After,Improvement,Revenue Increase\n";

        foreach ($report['top_performers'] as $item) {
            $csv .= sprintf(
                "%s,%s,%d,%d,%d,R$ %.2f\n",
                $item['item_id'],
                str_replace(',', ';', $item['title'] ?? ''),
                $item['score_before'] ?? 0,
                $item['score_after'] ?? 0,
                $item['improvement'] ?? 0,
                $item['revenue_increase'] ?? 0
            );
        }

        return $csv;
    }
}
