<?php

declare(strict_types=1);

namespace App\Services;

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
        // padrão de JSON_EXTRACT já usado em getInventoryTurnover) e calculamos a
        // margem líquida real a partir de net_profit/total_amount (campo que É
        // preenchido corretamente pelo OrderService).
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
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        if ($accountId !== null && $accountId > 0) {
            $orderSqlSuffix .= " AND ml_account_id = :account_id";
            $orderParams['account_id'] = $accountId;
        }

        $currentStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM ml_orders
             WHERE date_created >= DATE_SUB(NOW(), INTERVAL :days DAY)" . $orderSqlSuffix
        );
        $currentStmt->execute(array_merge(['days' => $days], $orderParams));
        $revenueToday = (float)$currentStmt->fetchColumn();

        $previousStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM ml_orders
             WHERE date_created >= DATE_SUB(NOW(), INTERVAL :days2 DAY)
               AND date_created < DATE_SUB(NOW(), INTERVAL :days1 DAY)" . $orderSqlSuffix
        );
        $previousStmt->execute(array_merge(['days2' => $days * 2, 'days1' => $days], $orderParams));
        $revenueYesterday = (float)$previousStmt->fetchColumn();

        if ($revenueYesterday > 0) {
            $growth = (($revenueToday - $revenueYesterday) / $revenueYesterday) * 100;
        } else {
            // Sem base de comparação: 0% (neutro) se também não há receita atual,
            // ou +100% (crescimento a partir de zero) se passou a vender.
            $growth = $revenueToday > 0 ? 100.0 : 0.0;
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
            'pending_questions' => $pendingQuestions,
            'active_items' => $activeItems,
            'conversion_rate' => $conversionRate,
            'visits_7d' => $visits7d,
            'sales_7d' => $sales7d,
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
}
