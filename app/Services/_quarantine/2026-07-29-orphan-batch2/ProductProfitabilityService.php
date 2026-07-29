<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Helpers\SessionHelper;
use PDO;

class ProductProfitabilityService
{
    use HasFinancialDependencies;

    public function getProfitabilityByProduct(string $startDate, string $endDate, int $limit = 20): array
    {
        $whereConditions = [
            'o.date_created BETWEEN :start AND :end',
            "o.status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startDate, ':end' => $endDate];

        if ($this->accountId) {
            $whereConditions[] = 'o.ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        $userId = SessionHelper::getUserId();
        if ($userId) {
            $whereConditions[] = 'o.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $whereSql = implode(' AND ', $whereConditions);

        // Extrair item_id do JSON order_data
        $limitSql = max(1, min(500, (int)$limit));

        $sql = "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.order_items[0].item.id')) as item_id,
                    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.order_items[0].item.title')) as title,
                    SUM(o.total_amount) as revenue,
                    SUM(o.net_profit) as profit,
                    COUNT(*) as sales,
                    AVG(o.gross_margin) as avg_margin
                FROM ml_orders o
                WHERE {$whereSql}
                GROUP BY item_id, title
                HAVING item_id IS NOT NULL
                ORDER BY profit DESC
            LIMIT {$limitSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar os menos lucrativos
        $sqlWorst = str_replace('ORDER BY profit DESC', 'ORDER BY profit ASC', $sql);
        $stmt = $this->db->prepare($sqlWorst);
        $stmt->execute($params);
        $worstProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'top_profitable' => array_map(fn(array $row): array => [
                'item_id' => $row['item_id'],
                'title' => $row['title'] ?? 'Sem título',
                'revenue' => round((float)$row['revenue'], 2),
                'profit' => round((float)$row['profit'], 2),
                'sales' => (int)$row['sales'],
                'avg_margin' => round((float)$row['avg_margin'], 2),
            ], $topProducts),
            'least_profitable' => array_map(fn(array $row): array => [
                'item_id' => $row['item_id'],
                'title' => $row['title'] ?? 'Sem título',
                'revenue' => round((float)$row['revenue'], 2),
                'profit' => round((float)$row['profit'], 2),
                'sales' => (int)$row['sales'],
                'avg_margin' => round((float)$row['avg_margin'], 2),
            ], $worstProducts),
        ];
    }

    public function getRevenueByCategory(string $startDate, string $endDate): array
    {
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startDate, ':end' => $endDate . ' 23:59:59'];

        if ($this->accountId) {
            $whereConditions[] = 'ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        $userId = SessionHelper::getUserId();
        if ($userId) {
            $whereConditions[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $whereSql = implode(' AND ', $whereConditions);

        // Extrair categoria do JSON de order_data
        $sql = "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.order_items[0].item.category_id')) as category_id,
                    SUM(total_amount) as revenue,
                    SUM(net_profit) as profit,
                    COUNT(*) as orders
                FROM ml_orders
                WHERE {$whereSql}
                GROUP BY JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.order_items[0].item.category_id'))
                HAVING category_id IS NOT NULL
                ORDER BY revenue DESC
                LIMIT 20";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enriquecer com nomes de categorias
        $categories = [];
        $client = $this->getClient();

        foreach ($rows as $row) {
            $categoryId = $row['category_id'];
            $categoryName = $categoryId;

            // Tentar obter nome da categoria
            if ($categoryId) {
                try {
                    $catInfo = $client->get("/categories/{$categoryId}", [], self::CACHE_TTL_LONG, true);
                    $categoryName = $catInfo['name'] ?? $categoryId;
                } catch (\Exception $e) {
                    // Manter ID como nome
                }
            }

            $categories[] = [
                'category_id' => $categoryId,
                'category_name' => $categoryName,
                'revenue' => round((float)$row['revenue'], 2),
                'profit' => round((float)$row['profit'], 2),
                'orders' => (int)$row['orders'],
            ];
        }

        return [
            'categories' => $categories,
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    public function getAccountMovements(string $startDate, string $endDate, int $limit = 50): array
    {
        $sellerId = $this->getSellerId();
        if (!$sellerId) {
            return ['error' => 'Seller ID não encontrado', 'results' => []];
        }

        $client = $this->getClient();

        $params = [
            'begin_date' => $startDate . 'T00:00:00.000-03:00',
            'end_date' => $endDate . 'T23:59:59.999-03:00',
            'limit' => min(50, $limit),
        ];

        $response = $client->get("/users/{$sellerId}/mercadopago_account/movements", $params);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar movimentações',
                'results' => [],
            ];
        }

        $movements = [];
        foreach ($response['results'] ?? $response as $mov) {
            $movements[] = [
                'id' => $mov['id'] ?? null,
                'type' => $mov['type'] ?? 'unknown',
                'amount' => (float)($mov['amount'] ?? 0),
                'balance' => (float)($mov['balance'] ?? 0),
                'date_created' => $mov['date_created'] ?? null,
                'reference_id' => $mov['reference_id'] ?? null,
                'description' => $mov['description'] ?? null,
            ];
        }

        return [
            'results' => $movements,
            'total' => count($movements),
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    public function getTopProductsFinancialMetrics(
        string $startDate,
        string $endDate,
        int $limit = 20
    ): array {
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startDate, ':end' => $endDate . ' 23:59:59'];

        if ($this->accountId) {
            $whereConditions[] = 'ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        $userId = SessionHelper::getUserId();
        if ($userId) {
            $whereConditions[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $whereSql = implode(' AND ', $whereConditions);

        $limitSql = max(1, min(200, (int)$limit));

        $sql = "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.order_items[0].item.id')) as item_id,
                    JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.order_items[0].item.title')) as title,
                    COUNT(*) as total_sales,
                    SUM(total_amount) as total_revenue,
                    SUM(ml_commission) as total_ml_fee,
                    SUM(payment_fee) as total_payment_fee,
                    SUM(shipping_cost) as total_shipping,
                    SUM(net_profit) as total_profit,
                    AVG(total_amount) as avg_ticket
                FROM ml_orders
                WHERE {$whereSql}
                GROUP BY item_id, title
                HAVING item_id IS NOT NULL
                ORDER BY total_revenue DESC
            LIMIT {$limitSql}";

        $stmt = $this->db->prepare($sql);

        // PDO pode falhar ao bindar LIMIT/OFFSET com prepares nativos
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $products = [];
        foreach ($rows as $row) {
            $revenue = (float)($row['total_revenue'] ?? 0);
            $profit = (float)($row['total_profit'] ?? 0);
            $totalFees = (float)($row['total_ml_fee'] ?? 0)
                + (float)($row['total_payment_fee'] ?? 0);

            $products[] = [
                'item_id' => $row['item_id'],
                'title' => $row['title'] ?? 'Sem título',
                'metrics' => [
                    'total_sales' => (int)($row['total_sales'] ?? 0),
                    'total_revenue' => round($revenue, 2),
                    'avg_ticket' => round((float)($row['avg_ticket'] ?? 0), 2),
                    'total_fees' => round($totalFees, 2),
                    'total_shipping' => round((float)($row['total_shipping'] ?? 0), 2),
                    'total_profit' => round($profit, 2),
                    'profit_margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                    'fee_rate' => $revenue > 0 ? round(($totalFees / $revenue) * 100, 2) : 0,
                ],
            ];
        }

        return [
            'products' => $products,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_products' => count($products),
        ];
    }

    public function calculateProductROI(
        string $itemId,
        float $productCost,
        string $startDate,
        string $endDate
    ): array {
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
            "JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.order_items[0].item.id')) = :item_id",
        ];
        $params = [
            ':start' => $startDate,
            ':end' => $endDate . ' 23:59:59',
            ':item_id' => $itemId,
        ];

        if ($this->accountId) {
            $whereConditions[] = 'ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        $whereSql = implode(' AND ', $whereConditions);

        $sql = "SELECT
                    COUNT(*) as total_sales,
                    SUM(JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.order_items[0].quantity'))) as total_units,
                    SUM(total_amount) as total_revenue,
                    SUM(ml_commission + payment_fee + fixed_fee) as total_fees,
                    SUM(shipping_cost) as total_shipping
                FROM ml_orders
                WHERE {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalSales = (int)($data['total_sales'] ?? 0);
        $totalUnits = (int)($data['total_units'] ?? $totalSales);
        $totalRevenue = (float)($data['total_revenue'] ?? 0);
        $totalFees = (float)($data['total_fees'] ?? 0);
        $totalShipping = (float)($data['total_shipping'] ?? 0);

        $totalCost = $productCost * $totalUnits;
        $totalExpenses = $totalFees + $totalShipping + $totalCost;
        $netProfit = $totalRevenue - $totalExpenses;
        $roi = $totalCost > 0 ? (($netProfit / $totalCost) * 100) : 0;

        return [
            'item_id' => $itemId,
            'product_cost' => $productCost,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'sales' => [
                'total_orders' => $totalSales,
                'total_units' => $totalUnits,
                'avg_units_per_order' => $totalSales > 0 ? round($totalUnits / $totalSales, 2) : 0,
            ],
            'financials' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_product_cost' => round($totalCost, 2),
                'total_fees' => round($totalFees, 2),
                'total_shipping' => round($totalShipping, 2),
                'total_expenses' => round($totalExpenses, 2),
                'net_profit' => round($netProfit, 2),
            ],
            'metrics' => [
                'roi_percentage' => round($roi, 2),
                'profit_margin' => $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0,
                'profit_per_unit' => $totalUnits > 0 ? round($netProfit / $totalUnits, 2) : 0,
                'breakeven_units' => $netProfit < 0 && $productCost > 0
                    ? ceil(abs($netProfit) / $productCost)
                    : 0,
            ],
        ];
    }

    public function calculateABCAnalysis(string $startDate, string $endDate): array
    {
        // Buscar vendas por produto no período
        $stmt = $this->db->prepare("
            SELECT
                oi.item_id,
                COALESCE(NULLIF(oi.title, ''), oi.item_id) as item_title,
                SUM(quantity) as total_qty,
                SUM(unit_price * quantity) as total_revenue,
                COUNT(DISTINCT o.ml_order_id) as order_count
            FROM order_items oi
            JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
            WHERE o.ml_account_id = :account_id
            AND o.date_created BETWEEN :start_date AND :end_date
            AND o.status = 'paid'
            GROUP BY oi.item_id, item_title
            ORDER BY total_revenue DESC
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products)) {
            return ['error' => 'Sem dados suficientes para análise ABC'];
        }

        $totalRevenue = array_sum(array_column($products, 'total_revenue'));
        $cumulativeRevenue = 0;
        $classA = [];
        $classB = [];
        $classC = [];

        foreach ($products as $product) {
            $cumulativeRevenue += $product['total_revenue'];
            $cumulativePercentage = ($cumulativeRevenue / $totalRevenue) * 100;

            $product['revenue_percentage'] = round(($product['total_revenue'] / $totalRevenue) * 100, 2);
            $product['cumulative_percentage'] = round($cumulativePercentage, 2);

            if ($cumulativePercentage <= 80) {
                $product['class'] = 'A';
                $classA[] = $product;
            } elseif ($cumulativePercentage <= 95) {
                $product['class'] = 'B';
                $classB[] = $product;
            } else {
                $product['class'] = 'C';
                $classC[] = $product;
            }
        }

        $revenueA = array_sum(array_column($classA, 'total_revenue'));
        $revenueB = array_sum(array_column($classB, 'total_revenue'));
        $revenueC = array_sum(array_column($classC, 'total_revenue'));

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_revenue' => $totalRevenue,
            'total_products' => count($products),
            'summary' => [
                'class_a' => [
                    'count' => count($classA),
                    'percentage' => round((count($classA) / count($products)) * 100, 2),
                    'revenue_share' => $totalRevenue > 0 ? round(($revenueA / $totalRevenue) * 100, 2) : 0,
                    'description' => 'Produtos vitais - alta receita, prioridade máxima',
                ],
                'class_b' => [
                    'count' => count($classB),
                    'percentage' => round((count($classB) / count($products)) * 100, 2),
                    'revenue_share' => $totalRevenue > 0 ? round(($revenueB / $totalRevenue) * 100, 2) : 0,
                    'description' => 'Produtos importantes - receita moderada',
                ],
                'class_c' => [
                    'count' => count($classC),
                    'percentage' => round((count($classC) / count($products)) * 100, 2),
                    'revenue_share' => $totalRevenue > 0 ? round(($revenueC / $totalRevenue) * 100, 2) : 0,
                    'description' => 'Produtos de baixa relevância - avaliar descontinuação',
                ],
            ],
            'products' => [
                'class_a' => array_slice($classA, 0, 20),
                'class_b' => array_slice($classB, 0, 10),
                'class_c' => array_slice($classC, 0, 10),
            ],
        ];
    }
}
