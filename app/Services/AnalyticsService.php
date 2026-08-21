<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AccountScopeHelper;

use App\Database;
use App\Helpers\RevenueHelper;
use App\Services\CategoryService;

class AnalyticsService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get revenue trend over time (daily aggregation)
     */
    public function getRevenueTrend(string $startDate, string $endDate, string $granularity = 'day', ?int $accountId = null): array
    {
        $dateFormat = $granularity === 'day' ? '%Y-%m-%d' : '%Y-%m';

        $sql = "
            SELECT
                DATE_FORMAT(date_created, '$dateFormat') as period,
                SUM(total_amount) as revenue,
                COUNT(*) as orders,
                AVG(total_amount) as avg_ticket
            FROM ml_orders
            WHERE date_created BETWEEN ? AND ?
            AND " . RevenueHelper::paidStatusesSql() . "
        ";

        $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND ml_account_id = ?";
            $params[] = $accountId;
        }

        $sql .= "
            GROUP BY period
            ORDER BY period ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Calculate Customer Lifetime Value (Top segments)
     */
    public function getCustomerLTV(?int $accountId = null): array
    {
        $sql = "
            SELECT
                CASE
                    WHEN total >= 1000 THEN 'VIP'
                    WHEN total >= 500 THEN 'Premium'
                    WHEN total >= 100 THEN 'Regular'
                    ELSE 'New'
                END as segment,
                COUNT(*) as customer_count,
                AVG(total) as avg_ltv,
                SUM(total) as total_revenue
            FROM (
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(order_data, '\$.buyer.id')) as buyer_id,
                    SUM(total_amount) as total
                FROM ml_orders
                WHERE " . RevenueHelper::paidStatusesSql() . "
                AND JSON_UNQUOTE(JSON_EXTRACT(order_data, '\$.buyer.id')) IS NOT NULL
        ";

        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND ml_account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $sql .= "
                GROUP BY JSON_UNQUOTE(JSON_EXTRACT(order_data, '\$.buyer.id'))
            ) as customer_totals
            GROUP BY segment
            ORDER BY avg_ltv DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Inventory Turnover Rate (Last 30 days)
     */
    public function getInventoryTurnover(?int $accountId = null): array
    {
        $sql = "
            SELECT
                i.category_id,
                COUNT(i.id) as total_items,
                SUM(i.available_quantity) as stock_units,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(i.data, '$.sold_quantity')) AS UNSIGNED)) as units_sold,
                ROUND(
                    SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(i.data, '$.sold_quantity')) AS UNSIGNED)) /
                    NULLIF(SUM(i.available_quantity), 0) * 100,
                    2
                ) as turnover_rate
            FROM items i
            WHERE i.status = 'active'
        ";

        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND i.account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $sql .= "
            GROUP BY i.category_id
            HAVING turnover_rate > 0
            ORDER BY turnover_rate DESC
            LIMIT 10
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->attachCategoryNames($rows, $accountId);
    }

    /**
     * Resolve category_id -> nome legível (Onda 2 / T3: "Giro de Estoque"
     * exibia IDs crus como "MLB186370"). Usa CategoryService, que já cacheia
     * a resposta da API do ML por 24h; falha isolada em uma categoria não
     * derruba o restante da lista (fallback para o próprio ID).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachCategoryNames(array $rows, ?int $accountId): array
    {
        if ($rows === []) {
            return $rows;
        }

        $categoryService = new CategoryService($accountId);

        foreach ($rows as &$row) {
            $categoryId = $row['category_id'] ?? null;
            $row['category_name'] = $categoryId;

            if (is_string($categoryId) && $categoryId !== '') {
                try {
                    $category = $categoryService->getCategory($categoryId);
                    if (!empty($category['name'])) {
                        $row['category_name'] = $category['name'];
                    }
                } catch (\Throwable) {
                    // Mantém o ID como fallback; nunca quebra o card por uma categoria.
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Profit Margin Analysis by Category
     */
    public function getProfitMargins(?int $accountId = null): array
    {
        // listing_type e gross_margin nunca são preenchidos em ml_orders (ETL não
        // grava esses campos, mesmo com o dado disponível no payload da API) — por
        // isso a query original sempre agrupava em um único bucket "N/A" com margem
        // 0 (Onda 2 / T3). Extraímos o tipo de anúncio direto do order_data (mesmo
        // padrão de JSON_EXTRACT já usado em getInventoryTurnover).
        // ml_orders.net_profit NÃO subtrai CMV (product_cost fica 0 no ETL). Não
        // rotular isso como "margem líquida real": missing CMV → n/d / sem CMV.
        $sql = "
            SELECT
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '\$.order_items[0].listing_type_id')), 'unknown') as listing_type,
                COUNT(*) as order_count,
                SUM(o.total_amount) as revenue,
                SUM(o.net_profit) as profit,
                ROUND(AVG(o.net_profit / NULLIF(o.total_amount, 0)) * 100, 2) as avg_margin
            FROM ml_orders o
            WHERE " . RevenueHelper::paidStatusesSql('o.status') . "
            AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";

        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND o.ml_account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $sql .= "
            GROUP BY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '\$.order_items[0].listing_type_id')), 'unknown')
            ORDER BY avg_margin DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // net_profit does not subtract CMV. Numeric margins only when every sold
        // unit in the 30d window has sku_custos.custo_produto > 0.
        $cogsComplete = $this->profitMarginsCogsComplete($accountId);

        return \App\Services\Financial\MissingCogsPolicy::presentProfitMargins($rows, $cogsComplete);
    }

    /**
     * True only if every paid unit in the last 30d has a known unit cost.
     */
    private function profitMarginsCogsComplete(?int $accountId): bool
    {
        if ($accountId === null || $accountId <= 0) {
            return false;
        }

        try {
            $sql = "SELECT jt.item_id AS item_id, jt.qty AS qty
                    FROM ml_orders o
                    INNER JOIN JSON_TABLE(
                        o.order_data,
                        '$.order_items[*]' COLUMNS (
                            item_id VARCHAR(32) PATH '$.item.id',
                            qty INT PATH '$.quantity'
                        )
                    ) AS jt
                    WHERE " . RevenueHelper::paidStatusesSql('o.status') . "
                      AND o.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND o.ml_account_id = :account_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['account_id' => $accountId]);
            $lines = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($lines === []) {
                return false;
            }

            $itemIds = [];
            foreach ($lines as $line) {
                $id = trim((string)($line['item_id'] ?? ''));
                if ($id !== '') {
                    $itemIds[$id] = true;
                }
            }
            $ids = array_keys($itemIds);
            if ($ids === []) {
                return false;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $costStmt = $this->db->prepare(
                "SELECT mlb_id, custo_produto FROM sku_custos
                 WHERE account_id = ? AND custo_produto > 0 AND mlb_id IN ({$placeholders})"
            );
            $costStmt->execute([$accountId, ...$ids]);
            $costByMlb = [];
            foreach ($costStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $mlb = trim((string)($row['mlb_id'] ?? ''));
                if ($mlb !== '') {
                    $costByMlb[$mlb] = (float)$row['custo_produto'];
                }
            }

            $with = 0;
            $without = 0;
            foreach ($lines as $line) {
                $id = trim((string)($line['item_id'] ?? ''));
                $qty = (int)($line['qty'] ?? 0);
                if ($id === '' || $qty <= 0) {
                    continue;
                }
                if (($costByMlb[$id] ?? 0) > 0) {
                    $with += $qty;
                } else {
                    $without += $qty;
                }
            }

            return \App\Services\Financial\MissingCogsPolicy::hasRealCogs($with, $without);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Conversion Funnel Analysis
     */
    public function getConversionFunnel(?int $accountId = null): array
    {
        $questionsSql = "SELECT COUNT(*) FROM ml_questions WHERE date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $ordersSql = "SELECT COUNT(*) FROM ml_orders WHERE date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $params = [];

        if ($accountId !== null && $accountId > 0) {
            $questionsSql .= " AND account_id = :account_id";
            $ordersSql .= " AND ml_account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $questionsStmt = $this->db->prepare($questionsSql);
        $questionsStmt->execute($params);
        $questions = $questionsStmt->fetchColumn();

        $ordersStmt = $this->db->prepare($ordersSql);
        $ordersStmt->execute($params);
        $orders = $ordersStmt->fetchColumn();

        return [
            'questions' => (int)$questions,
            'orders' => (int)$orders,
            'conversion_rate' => $questions > 0 ? round(($orders / $questions) * 100, 2) : 0,
        ];
    }

    /**
     * Predictive Revenue Forecast (Simple linear regression)
     */
    public function getForecast(int $daysAhead = 7, ?int $accountId = null): array
    {
        $sql = "
            SELECT
                DATE(date_created) as day,
                SUM(total_amount) as revenue
            FROM ml_orders
            WHERE date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND " . RevenueHelper::paidStatusesSql() . "
        ";

        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND ml_account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $sql .= "
            GROUP BY day
            ORDER BY day ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $historical = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($historical)) {
            return [];
        }

        $last7 = array_slice($historical, -7);
        $recentAvg = array_sum(array_column($last7, 'revenue')) / max(1, count($last7));

        $forecast = [];
        for ($i = 1; $i <= $daysAhead; $i++) {
            $forecast[] = [
                'date' => date('Y-m-d', strtotime("+$i days")),
                'predicted_revenue' => round($recentAvg, 2),
            ];
        }

        return $forecast;
    }

    /**
     * Real-time Dashboard Summary
     *
     * Onda 2 / T3: o comparativo original era "hoje (parcial, só o que já
     * vendeu desde 00h)" vs "ontem (dia inteiro)". Isso produzia "-100%" quase
     * sempre no início do dia, mesmo com vendas normais — não é um bug de
     * dado, é um erro de metodologia (períodos de tamanhos diferentes). Agora
     * comparamos dois períodos completos e do mesmo tamanho: últimos $days
     * dias vs os $days dias imediatamente anteriores.
     */
    public function getDashboardSummary(?int $accountId = null, int $days = 7): array
    {
        $days = max(1, $days);

        $orderSqlSuffix = " AND " . RevenueHelper::paidStatusesSql();
        $orderParams = [];
        $scope = AccountScopeHelper::constrain('ml_account_id', $accountId);
        $orderSqlSuffix .= $scope['sql'];
        $orderParams = array_merge($orderParams, $scope['params']);

        $currentStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS orders FROM ml_orders
             WHERE date_created >= DATE_SUB(NOW(), INTERVAL :days DAY)" . $orderSqlSuffix
        );
        $currentStmt->execute(array_merge(['days' => $days], $orderParams));
        $currentRow = $currentStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $revenueToday = (float)($currentRow['revenue'] ?? 0);
        $ordersToday = (int)($currentRow['orders'] ?? 0);

        $previousStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS orders FROM ml_orders
             WHERE date_created >= DATE_SUB(NOW(), INTERVAL :days2 DAY)
               AND date_created < DATE_SUB(NOW(), INTERVAL :days1 DAY)" . $orderSqlSuffix
        );
        $previousStmt->execute(array_merge(['days2' => $days * 2, 'days1' => $days], $orderParams));
        $previousRow = $previousStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $revenueYesterday = (float)($previousRow['revenue'] ?? 0);
        $ordersYesterday = (int)($previousRow['orders'] ?? 0);

        if ($revenueYesterday > 0) {
            $growth = (($revenueToday - $revenueYesterday) / $revenueYesterday) * 100;
        } else {
            // Sem base de comparação: 0% (neutro) se também não há receita atual,
            // ou +100% (crescimento a partir de zero) se passou a vender.
            $growth = $revenueToday > 0 ? 100.0 : 0.0;
        }

        if ($ordersYesterday > 0) {
            $ordersChange = (($ordersToday - $ordersYesterday) / $ordersYesterday) * 100;
        } else {
            $ordersChange = $ordersToday > 0 ? 100.0 : 0.0;
        }

        $questionsSql = "SELECT COUNT(*) FROM ml_questions WHERE status = 'UNANSWERED'";
        $itemsSql = "SELECT COUNT(*) FROM items WHERE status = 'active'";
        $sharedParams = [];
        if ($accountId !== null && $accountId > 0) {
            $questionsSql .= " AND account_id = :account_id";
            $itemsSql .= " AND account_id = :account_id";
            $sharedParams['account_id'] = $accountId;
        }

        $questionsStmt = $this->db->prepare($questionsSql);
        $questionsStmt->execute($sharedParams);
        $pendingQuestions = (int)$questionsStmt->fetchColumn();

        $itemsStmt = $this->db->prepare($itemsSql);
        $itemsStmt->execute($sharedParams);
        $activeItems = (int)$itemsStmt->fetchColumn();

        // Taxa de conversão = vendas 7d / visitas 7d (Onda 2 / T3). Reaproveita
        // account_index_metrics, já coletado pelo Pregão (mesma janela de 7d
        // exibida em "Exposição"), em vez de bater na API do ML de novo.
        [$conversionRate, $visits7d, $sales7d] = $this->getConversionRateFromIndexMetrics($accountId);
        $visitsChange = $this->getVisitsChangeFromBaselines($accountId, $visits7d);

        return [
            // Nomes mantidos por compatibilidade com o front-end existente, mas
            // agora representam "período atual" (últimos $days dias) e
            // "período anterior" (os $days dias antes desse), não mais um
            // "hoje parcial" vs "ontem inteiro".
            'revenue_today' => $revenueToday,
            'revenue_yesterday' => $revenueYesterday,
            'revenue_period' => $revenueToday,
            'revenue_previous_period' => $revenueYesterday,
            'period_days' => $days,
            'growth_rate' => round($growth, 2),
            'orders_period' => $ordersToday,
            'orders_previous_period' => $ordersYesterday,
            'orders_change' => round($ordersChange, 2),
            'pending_questions' => $pendingQuestions,
            'active_items' => $activeItems,
            'conversion_rate' => $conversionRate,
            'visits_7d' => $visits7d,
            'sales_7d' => $sales7d,
            'visits_change' => $visitsChange,
        ];
    }

    /**
     * Taxa de conversão = vendas / visitas na janela de 7 dias, lida de
     * account_index_metrics (mesma fonte usada pelo painel Pregão). Retorna
     * null quando não há visitas coletadas ainda (evita 0% falso/silencioso).
     *
     * @return array{0: ?float, 1: ?float, 2: ?float} [conversion_rate, visits_7d, sales_7d]
     */
    private function getConversionRateFromIndexMetrics(?int $accountId): array
    {
        $sql = "SELECT vendas_7d, visitas_7d FROM account_index_metrics WHERE 1=1";
        $params = [];
        if ($accountId) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }
        $sql .= " ORDER BY updated_at DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['visitas_7d'] === null) {
            return [null, null, null];
        }

        $visits = (float)$row['visitas_7d'];
        $sales = (float)($row['vendas_7d'] ?? 0);
        $rate = $visits > 0 ? round(($sales / $visits) * 100, 2) : null;

        return [$rate, $visits, $sales];
    }

    /**
     * Variação de visitas 7d vs baseline local (account_index_baselines).
     * Leitura apenas — não dispara collector do Pregão nem API do ML.
     */
    private function getVisitsChangeFromBaselines(?int $accountId, mixed $visits7d): float
    {
        if ($visits7d === null || $accountId === null || $accountId <= 0) {
            return 0.0;
        }

        $stmt = $this->db->prepare(
            'SELECT visitas_baseline FROM account_index_baselines WHERE account_id = :account_id LIMIT 1'
        );
        $stmt->execute(['account_id' => $accountId]);
        $baseline = $stmt->fetchColumn();
        $baseline = $baseline === false ? 0.0 : (float)$baseline;

        if ($baseline > 0) {
            return round((((float)$visits7d - $baseline) / $baseline) * 100, 2);
        }

        return (float)$visits7d > 0 ? 100.0 : 0.0;
    }

    /**
     * Top produtos no período a partir de ml_orders.order_data (JSON_TABLE).
     * Thumbnail vem da tabela items local — sem collector de visitas nem API ML.
     *
     * @return list<array{id: string, title: string, thumbnail: ?string, sales: int, revenue: float, conversion_rate: float, trend: float}>
     */
    public function getTopProducts(string $startDate, string $endDate, ?int $accountId = null, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $startBound = strlen($startDate) === 10 ? $startDate . ' 00:00:00' : $startDate;
        $endBound = strlen($endDate) === 10 ? $endDate . ' 23:59:59' : $endDate;

        $sql = "
            SELECT
                jt.item_id AS id,
                COALESCE(NULLIF(MAX(jt.item_title), ''), MAX(i.title), jt.item_id) AS title,
                MAX(i.thumbnail) AS thumbnail,
                SUM(jt.quantity) AS sales,
                SUM(jt.unit_price * jt.quantity) AS revenue
            FROM ml_orders o
            JOIN JSON_TABLE(
                o.order_data,
                '$.order_items[*]' COLUMNS (
                    item_id VARCHAR(50) PATH '$.item.id',
                    item_title VARCHAR(255) PATH '$.item.title',
                    quantity INT PATH '$.quantity',
                    unit_price DECIMAL(12,2) PATH '$.unit_price'
                )
            ) AS jt
            LEFT JOIN items i ON i.ml_item_id = CONVERT(jt.item_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            WHERE o.date_created BETWEEN ? AND ?
              AND " . RevenueHelper::paidStatusesSql('o.status') . "
              AND o.order_data IS NOT NULL
              AND JSON_VALID(o.order_data)
              AND jt.item_id IS NOT NULL
        ";

        $params = [$startBound, $endBound];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND o.ml_account_id = ?";
            $params[] = $accountId;
        }

        $sql .= "
            GROUP BY jt.item_id
            ORDER BY revenue DESC
            LIMIT {$limit}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $thumbnail = $row['thumbnail'] ?? null;
            $out[] = [
                'id' => (string)($row['id'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'thumbnail' => is_string($thumbnail) && $thumbnail !== '' ? $thumbnail : null,
                'sales' => (int)($row['sales'] ?? 0),
                'revenue' => round((float)($row['revenue'] ?? 0), 2),
                'conversion_rate' => 0.0,
                'trend' => 0.0,
            ];
        }

        return $out;
    }

    /**
     * Contagem local de otimizações SEO no período (tabela seo_optimization_events).
     */
    public function countSeoOptimizations(string $startDate, string $endDate, ?int $accountId = null): int
    {
        $startBound = strlen($startDate) === 10 ? $startDate . ' 00:00:00' : $startDate;
        $endBound = strlen($endDate) === 10 ? $endDate . ' 23:59:59' : $endDate;

        $sql = "SELECT COUNT(*) FROM seo_optimization_events WHERE optimized_at BETWEEN ? AND ?";
        $params = [$startBound, $endBound];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND account_id = ?";
            $params[] = $accountId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Distribuição de anúncios ativos por categoria (mesmo agrupamento de listingsMetrics).
     * Usa category_name já persistido em items — sem tracker de performance nem API ML.
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    public function getCategoryDistribution(?int $accountId = null, int $limit = 8): array
    {
        $limit = max(1, min(15, $limit));
        $labelExpr = "COALESCE(NULLIF(category_name, ''), category_id, 'Sem categoria')";
        $sql = "
            SELECT
                {$labelExpr} AS label,
                COUNT(*) AS cnt
            FROM items
            WHERE status = 'active'
        ";
        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " AND account_id = ?";
            $params[] = $accountId;
        }
        $sql .= " GROUP BY {$labelExpr} ORDER BY cnt DESC LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'labels' => array_map('strval', array_column($rows, 'label')),
            'data' => array_map('intval', array_column($rows, 'cnt')),
        ];
    }
}
