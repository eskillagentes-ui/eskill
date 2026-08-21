<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnalyticsService;
use App\Services\UserService;

class AnalyticsController extends BaseController
{
    private $service;
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->service = new AnalyticsService();
        $this->userService = $userService;
    }

    /**
     * Main Analytics Dashboard
     */
    public function index(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $pageTitle = 'Business Intelligence';
        $activePage = 'analytics';

        ob_start();
        require __DIR__ . '/../Views/dashboard/analytics/index.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * Dashboard method (alias to index)
     */
    public function dashboard(): void
    {
        $this->index();
    }

    /**
     * API: Get full dashboard data for advanced-analytics view
     */
    public function getDashboard(): void
    {
        header('Content-Type: application/json');

        $accountId = $this->getActiveAccountId();
        $period = $this->request->get('period', '30days');

        $days = match($period) {
            '7days' => 7,
            '90days' => 90,
            default => 30,
        };

        $start = date('Y-m-d', strtotime("-{$days} days"));
        $end = date('Y-m-d');

        try {
            $summary = $this->service->getDashboardSummary($accountId, $days);
            $trend = $this->service->getRevenueTrend($start, $end, 'day', $accountId);
            $funnel = $this->service->getConversionFunnel($accountId);
            $topProducts = $this->service->getTopProducts($start, $end, $accountId, 8);
            $seoOptimizations = $this->service->countSeoOptimizations($start, $end, $accountId);
            $categories = $this->service->getCategoryDistribution($accountId);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao carregar dados de analytics.']);
            return;
        }

        $labels = array_column($trend, 'period');
        $revenueData = array_map('floatval', array_column($trend, 'revenue'));
        $totalOrders = (int) array_sum(array_column($trend, 'orders'));
        $totalRevenue = (float) array_sum($revenueData);

        $insights = [];
        if ($summary['growth_rate'] > 0) {
            $rate = $summary['growth_rate'];
            $insights[] = ['icon' => 'trending-up', 'title' => 'Crescimento de Receita', 'description' => "Receita cresceu {$rate}% hoje comparado a ontem."];
        } elseif ($summary['growth_rate'] < 0) {
            $rate = abs($summary['growth_rate']);
            $insights[] = ['icon' => 'trending-down', 'title' => 'Queda de Receita', 'description' => "Receita caiu {$rate}% hoje comparado a ontem."];
        }
        if ($summary['pending_questions'] > 0) {
            $q = $summary['pending_questions'];
            $insights[] = ['icon' => 'chat-dots', 'title' => 'Perguntas Pendentes', 'description' => "{$q} perguntas aguardam resposta."];
        }

        $visits7d = (int)($summary['visits_7d'] ?? 0);

        $data = [
            'metrics' => [
                'revenue'           => $totalRevenue,
                'orders'            => $totalOrders,
                'visits'            => $visits7d,
                'visits_window'     => '7d',
                'conversion_rate'   => (float)($summary['conversion_rate'] ?? 0.0),
                'revenue_change'    => $summary['growth_rate'],
                'orders_change'     => $summary['orders_change'] ?? 0,
                'visits_change'     => $summary['visits_change'] ?? 0,
                'conversion_change' => 0,
            ],
            'charts' => [
                'sales_trend'  => ['labels' => $labels, 'data' => $revenueData],
                'distribution' => ['labels' => [], 'data' => []],
                'categories'   => $categories,
                'funnel'       => ['data' => [
                    $visits7d,
                    $funnel['questions'] ?? 0,
                    0,
                    $totalOrders,
                ]],
            ],
            'insights'     => $insights,
            'top_products' => $topProducts,
            'ai_stats'     => ['optimizations' => $seoOptimizations, 'cost' => 0, 'roi' => 0.0, 'time_saved' => 0],
        ];

        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * API: Get revenue trend data
     */
    public function getRevenueTrend(): void
    {
        header('Content-Type: application/json');

        $start = $this->request->get('start', date('Y-m-01'));
        $end = $this->request->get('end', date('Y-m-d'));
        $accountId = $this->getActiveAccountId();

        // Validate date format
        $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($datePattern, $start) || !preg_match($datePattern, $end)) {
            http_response_code(400);
            echo json_encode(['error' => 'Formato de data inválido. Use YYYY-MM-DD.']);
            return;
        }

        $allowedGranularity = ['day', 'week', 'month'];
        $granularity = $this->request->get('granularity', 'day');
        if (!in_array($granularity, $allowedGranularity, true)) {
            $granularity = 'day';
        }

        $data = $this->service->getRevenueTrend($start, $end, $granularity, $accountId);
        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * API: Get customer LTV segments
     */
    public function getCustomerLTV(): void
    {
        header('Content-Type: application/json');
        $data = $this->service->getCustomerLTV($this->getActiveAccountId());
        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * API: Get inventory turnover
     */
    public function getInventoryTurnover(): void
    {
        header('Content-Type: application/json');
        $data = $this->service->getInventoryTurnover($this->getActiveAccountId());
        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * API: Get profit margins
     */
    public function getProfitMargins(): void
    {
        header('Content-Type: application/json');
        $data = $this->service->getProfitMargins($this->getActiveAccountId());
        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * API: Get dashboard summary
     */
    public function getSummary(): void
    {
        header('Content-Type: application/json');
        $days = $this->request->getIntClamped('days', 1, 90, 7);
        $data = $this->service->getDashboardSummary($this->getActiveAccountId(), $days);
        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * API: Get forecast
     */
    public function getForecast(): void
    {
        header('Content-Type: application/json');
        $days = $this->request->getInt('days', 7);
        $data = $this->service->getForecast($days, $this->getActiveAccountId());
        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * API: Get API usage statistics (rate limits, request counts)
     */
    public function apiStats(): void
    {
        header('Content-Type: application/json');

        try {
            $db = \App\Database::getInstance();
            $hours = $this->request->getInt('hours', 24);
            $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));

            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total_requests,
                    COUNT(DISTINCT ip_address) as unique_ips,
                    MIN(created_at) as earliest,
                    MAX(created_at) as latest
                FROM rate_limits
                WHERE created_at > :cutoff
            ");
            $stmt->execute([':cutoff' => $cutoff]);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $stmtTop = $db->prepare("
                SELECT ip_address, COUNT(*) as request_count
                FROM rate_limits
                WHERE created_at > :cutoff
                GROUP BY ip_address
                ORDER BY request_count DESC
                LIMIT 10
            ");
            $stmtTop->execute([':cutoff' => $cutoff]);
            $topIps = $stmtTop->fetchAll(\PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'period_hours' => $hours,
                    'total_requests' => (int) ($stats['total_requests'] ?? 0),
                    'unique_ips' => (int) ($stats['unique_ips'] ?? 0),
                    'earliest' => $stats['earliest'] ?? null,
                    'latest' => $stats['latest'] ?? null,
                    'top_ips' => $topIps,
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to fetch API stats: ' . $e->getMessage()]);
        }
    }

    /**
     * API: Get requests chart data (hourly distribution)
     */
    public function requestsChart(): void
    {
        header('Content-Type: application/json');

        try {
            $db = \App\Database::getInstance();
            $days = $this->request->getInt('days', 7);
            $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

            $stmt = $db->prepare("
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_bucket,
                    COUNT(*) as request_count
                FROM rate_limits
                WHERE created_at > :cutoff
                GROUP BY hour_bucket
                ORDER BY hour_bucket ASC
            ");
            $stmt->execute([':cutoff' => $cutoff]);
            $chartData = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'period_days' => $days,
                    'chart' => $chartData,
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to fetch requests chart: ' . $e->getMessage()]);
        }
    }

    /**
     * API: Get sales metrics (revenue, orders, ticket médio)
     */
    public function salesMetrics(): void
    {
        header('Content-Type: application/json');

        try {
            $db = \App\Database::getInstance();
            $days = $this->request->getInt('days', 30);
            $cutoff = date('Y-m-d', strtotime("-{$days} days"));
            $accountId = $this->getActiveAccountId();

            $dailySql = "
                SELECT
                    DATE(date_created) as day,
                    COUNT(*) as orders,
                    SUM(total_amount) as revenue,
                    AVG(total_amount) as avg_ticket,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_orders,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders
                FROM ml_orders
                WHERE DATE(date_created) >= :cutoff
            ";

            $params = [':cutoff' => $cutoff];
            if ($accountId !== null && $accountId > 0) {
                $dailySql .= " AND ml_account_id = :account_id";
                $params[':account_id'] = $accountId;
            }

            $dailySql .= " GROUP BY day ORDER BY day ASC";
            $stmt = $db->prepare($dailySql);
            $stmt->execute($params);
            $daily = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $totalsSql = "
                SELECT
                    COUNT(*) as total_orders,
                    SUM(total_amount) as total_revenue,
                    AVG(total_amount) as avg_ticket,
                    SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid_revenue
                FROM ml_orders
                WHERE DATE(date_created) >= :cutoff
            ";

            if ($accountId !== null && $accountId > 0) {
                $totalsSql .= " AND ml_account_id = :account_id";
            }

            $stmtTotals = $db->prepare($totalsSql);
            $stmtTotals->execute($params);
            $totals = $stmtTotals->fetch(\PDO::FETCH_ASSOC) ?: [];

            echo json_encode([
                'success' => true,
                'data' => [
                    'period_days' => $days,
                    'totals' => [
                        'total_orders' => (int) ($totals['total_orders'] ?? 0),
                        'total_revenue' => round((float) ($totals['total_revenue'] ?? 0), 2),
                        'avg_ticket' => round((float) ($totals['avg_ticket'] ?? 0), 2),
                        'paid_revenue' => round((float) ($totals['paid_revenue'] ?? 0), 2),
                    ],
                    'daily' => $daily,
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to fetch sales metrics: ' . $e->getMessage()]);
        }
    }

    /**
     * API: Get listings metrics (active, paused, closed items)
     */
    public function listingsMetrics(): void
    {
        header('Content-Type: application/json');

        try {
            $db = \App\Database::getInstance();
            $accountId = $this->getActiveAccountId();

            $params = [];

            $statusSql = "
                SELECT
                    status,
                    COUNT(*) as count,
                    SUM(price) as total_value,
                    AVG(price) as avg_price,
                    SUM(available_quantity) as total_stock
                FROM items
                WHERE 1 = 1
            ";
            if ($accountId !== null && $accountId > 0) {
                $statusSql .= " AND account_id = :account_id";
                $params[':account_id'] = $accountId;
            }
            $statusSql .= " GROUP BY status ORDER BY count DESC";

            $stmt = $db->prepare($statusSql);
            $stmt->execute($params);
            $byStatus = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $categorySql = "
                SELECT
                    category_id,
                    COUNT(*) as count,
                    AVG(price) as avg_price
                FROM items
                WHERE status = 'active'
            ";

            if ($accountId !== null && $accountId > 0) {
                $categorySql .= " AND account_id = :account_id";
            }
            $categorySql .= " GROUP BY category_id ORDER BY count DESC LIMIT 15";

            $stmtCat = $db->prepare($categorySql);
            $stmtCat->execute($params);
            $byCategory = $stmtCat->fetchAll(\PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'by_status' => $byStatus,
                    'by_category' => $byCategory,
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to fetch listings metrics: ' . $e->getMessage()]);
        }
    }

    /**
     * API: Get questions metrics (answered, unanswered, response time)
     */
    public function questionsMetrics(): void
    {
        header('Content-Type: application/json');

        try {
            $db = \App\Database::getInstance();
            $days = $this->request->getInt('days', 30);
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $accountId = $this->getActiveAccountId();
            $params = [':cutoff' => $cutoff];

            $byStatusSql = "
                SELECT
                    status,
                    COUNT(*) as count
                FROM ml_questions
                WHERE date_created >= :cutoff
            ";
            if ($accountId !== null && $accountId > 0) {
                $byStatusSql .= " AND account_id = :account_id";
                $params[':account_id'] = $accountId;
            }
            $byStatusSql .= " GROUP BY status";

            $stmt = $db->prepare($byStatusSql);
            $stmt->execute($params);
            $byStatus = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $dailySql = "
                SELECT
                    DATE(date_created) as day,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'ANSWERED' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN status = 'UNANSWERED' THEN 1 ELSE 0 END) as unanswered
                FROM ml_questions
                WHERE date_created >= :cutoff
            ";
            if ($accountId !== null && $accountId > 0) {
                $dailySql .= " AND account_id = :account_id";
            }
            $dailySql .= " GROUP BY day ORDER BY day ASC";

            $stmtDaily = $db->prepare($dailySql);
            $stmtDaily->execute($params);
            $daily = $stmtDaily->fetchAll(\PDO::FETCH_ASSOC);

            $avgResponseSql = "
                SELECT
                    AVG(TIMESTAMPDIFF(MINUTE, date_created, answer_date)) as avg_response_minutes
                FROM ml_questions
                WHERE answer_date IS NOT NULL
                AND date_created >= :cutoff
            ";
            if ($accountId !== null && $accountId > 0) {
                $avgResponseSql .= " AND account_id = :account_id";
            }

            $stmtAvgResponse = $db->prepare($avgResponseSql);
            $stmtAvgResponse->execute($params);
            $avgResponse = $stmtAvgResponse->fetch(\PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'period_days' => $days,
                    'by_status' => $byStatus,
                    'daily' => $daily,
                    'avg_response_minutes' => round((float) ($avgResponse['avg_response_minutes'] ?? 0), 1),
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to fetch questions metrics: ' . $e->getMessage()]);
        }
    }

    /**
     * API: Export analytics report (CSV download)
     */
    public function exportReport(): void
    {
        try {
            $db = \App\Database::getInstance();
            $type = $this->request->get('type', 'sales');
            $days = $this->request->getInt('days', 30);
            $format = $this->request->get('format', 'csv');
            $cutoff = date('Y-m-d', strtotime("-{$days} days"));
            $accountId = $this->getActiveAccountId();

            $rows = match ($type) {
                'sales' => $this->exportSalesData($db, $cutoff, $accountId),
                'listings' => $this->exportListingsData($db, $accountId),
                'questions' => $this->exportQuestionsData($db, $cutoff, $accountId),
                default => throw new \InvalidArgumentException("Invalid export type: {$type}"),
            };

            if ($format === 'json') {
                header('Content-Type: application/json');
                header("Content-Disposition: attachment; filename=\"analytics-{$type}-{$days}d.json\"");
                echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
                return;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"analytics-{$type}-{$days}d.csv\"");

            $output = fopen('php://output', 'w');
            if (!empty($rows)) {
                fputcsv($output, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($output, $row);
                }
            }
            fclose($output);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to export report: ' . $e->getMessage()]);
        }
    }

    /**
     * Export sales data for CSV/JSON
     */
    private function exportSalesData(\PDO $db, string $cutoff, ?int $accountId = null): array
    {
        $sql = "
            SELECT
                ml_order_id as order_id,
                ml_account_id as account_id,
                status,
                total_amount,
                DATE(date_created) as order_date
            FROM ml_orders
            WHERE DATE(date_created) >= :cutoff
        ";

        $params = [':cutoff' => $cutoff];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND ml_account_id = :account_id";
            $params[':account_id'] = $accountId;
        }
        $sql .= " ORDER BY date_created DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Export listings data for CSV/JSON
     */
    private function exportListingsData(\PDO $db, ?int $accountId = null): array
    {
        $sql = "
            SELECT
                item_id, title, status, price,
                available_quantity, category_id, permalink
            FROM items
            WHERE 1 = 1
        ";

        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND account_id = :account_id";
            $params[':account_id'] = $accountId;
        }
        $sql .= " ORDER BY status ASC, price DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Export questions data for CSV/JSON
     */
    private function exportQuestionsData(\PDO $db, string $cutoff, ?int $accountId = null): array
    {
        $sql = "
            SELECT
                question_id, item_id, status, question_text,
                answer_text, from_user_id, DATE(date_created) as question_date,
                DATE(answer_date) as answered_date
            FROM ml_questions
            WHERE date_created >= :cutoff
        ";

        $params = [':cutoff' => $cutoff . ' 00:00:00'];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND account_id = :account_id";
            $params[':account_id'] = $accountId;
        }
        $sql .= " ORDER BY date_created DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
