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
            'top_profitable' => array_map([$this, 'mapProfitabilityRow'], $topProducts),
            'least_profitable' => array_map([$this, 'mapProfitabilityRow'], $worstProducts),
        ];
    }

    /**
     * Normaliza linha de lucratividade. gross_margin em ml_orders frequentemente
     * fica zerado (não calculado no sync); nesse caso deriva margem de lucro/receita.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapProfitabilityRow(array $row): array
    {
        $revenue = round((float)($row['revenue'] ?? 0), 2);
        $profit = round((float)($row['profit'] ?? 0), 2);
        $avgMargin = (float)($row['avg_margin'] ?? 0);

        if ($avgMargin == 0.0 && $revenue > 0) {
            $avgMargin = ($profit / $revenue) * 100;
        }

        return [
            'item_id' => $row['item_id'],
            'title' => $row['title'] ?? 'Sem título',
            'revenue' => $revenue,
            'profit' => $profit,
            'sales' => (int)($row['sales'] ?? 0),
            'avg_margin' => round($avgMargin, 2),
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

        $limit = min(50, max(1, $limit));
        $begin = $startDate . 'T00:00:00.000-03:00';
        $end = $endDate . 'T23:59:59.999-03:00';

        // 1) MP movements/search (quando disponível no OAuth)
        $response = $this->mpGet('/v1/account/movements/search', [
            'begin_date' => $begin,
            'end_date' => $end,
            'limit' => $limit,
        ]);
        $source = 'mp_movements_search';

        // 2) Fallback comprovado: /v1/payments/search no host MP (ML mercadopago_account/movements → 404)
        if (isset($response['error']) || (!isset($response['results']) && !array_is_list($response))) {
            $response = $this->mpGet('/v1/payments/search', [
                'sort' => 'date_created',
                'criteria' => 'desc',
                'limit' => $limit,
                'offset' => 0,
                'range' => 'date_created',
                'begin_date' => $begin,
                'end_date' => $end,
            ]);
            $source = 'mp_payments_search';
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar movimentações',
                'results' => [],
                'source' => $source,
            ];
        }

        $rows = $response['results'] ?? (array_is_list($response) ? $response : []);
        if (!is_array($rows)) {
            $rows = [];
        }

        $movements = [];
        foreach ($rows as $mov) {
            if (!is_array($mov)) {
                continue;
            }
            if ($source === 'mp_payments_search') {
                $net = (float) ($mov['transaction_details']['net_received_amount'] ?? 0);
                $gross = (float) ($mov['transaction_amount'] ?? 0);
                $movements[] = [
                    'id' => $mov['id'] ?? null,
                    'type' => (string) ($mov['operation_type'] ?? 'payment'),
                    'amount' => $net !== 0.0 ? $net : $gross,
                    'balance' => 0.0,
                    'date_created' => $mov['date_created'] ?? null,
                    'reference_id' => $mov['order']['id'] ?? $mov['external_reference'] ?? null,
                    'description' => $mov['description'] ?? ($mov['status'] ?? null),
                    'status' => $mov['status'] ?? null,
                ];
                continue;
            }
            $movements[] = [
                'id' => $mov['id'] ?? null,
                'type' => $mov['type'] ?? 'unknown',
                'amount' => (float) ($mov['amount'] ?? 0),
                'balance' => (float) ($mov['balance'] ?? 0),
                'date_created' => $mov['date_created'] ?? null,
                'reference_id' => $mov['reference_id'] ?? null,
                'description' => $mov['description'] ?? null,
            ];
        }

        return [
            'results' => $movements,
            'total' => (int) ($response['paging']['total'] ?? count($movements)),
            'period' => ['start' => $startDate, 'end' => $endDate],
            'source' => $source,
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
        // Fonte: order_data JSON (tabela order_items pode estar vazia neste ambiente).
        $stmt = $this->db->prepare("
            SELECT
                jt.item_id,
                COALESCE(NULLIF(jt.item_title, ''), jt.item_id) as item_title,
                SUM(jt.quantity) as total_qty,
                SUM(jt.unit_price * jt.quantity) as total_revenue,
                COUNT(DISTINCT o.ml_order_id) as order_count
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
            WHERE o.ml_account_id = :account_id
            AND o.date_created BETWEEN :start_date AND :end_date
            AND o.status IN ('paid', 'delivered')
            AND jt.item_id IS NOT NULL
            GROUP BY jt.item_id, item_title
            ORDER BY total_revenue DESC
        ");

        $endBound = strlen($endDate) === 10 ? $endDate . ' 23:59:59' : $endDate;
        $startBound = strlen($startDate) === 10 ? $startDate . ' 00:00:00' : $startDate;

        $stmt->execute([
            'account_id' => $this->accountId,
            'start_date' => $startBound,
            'end_date' => $endBound,
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

        // Curva Z: produtos ativos no catálogo (ml_items) sem nenhuma venda no período
        $classZ = $this->findZeroSalesProducts(array_column($products, 'item_id'));

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
                'class_z' => [
                    'count' => count($classZ),
                    'revenue_share' => 0,
                    'description' => 'Produtos ativos no catálogo sem nenhuma venda no período',
                ],
            ],
            'products' => [
                'class_a' => array_slice($classA, 0, 20),
                'class_b' => array_slice($classB, 0, 10),
                'class_c' => array_slice($classC, 0, 10),
                'class_z' => array_slice($classZ, 0, 20),
            ],
        ];
    }

    /**
     * Busca produtos ativos do catálogo (ml_items) que não tiveram nenhuma venda
     * no período analisado — Curva Z.
     *
     * @param list<string> $excludeItemIds IDs de itens que já tiveram venda no período
     * @return list<array<string, mixed>>
     */
    private function findZeroSalesProducts(array $excludeItemIds): array
    {
        if (!$this->accountId) {
            return [];
        }

        $params = ['account_id' => $this->accountId];
        $sql = "SELECT
                    ml_item_id as item_id,
                    title as item_title,
                    available_quantity,
                    sold_quantity
                FROM ml_items
                WHERE account_id = :account_id
                AND status = 'active'";

        $excludeItemIds = array_values(array_filter(array_map('strval', $excludeItemIds)));
        if ($excludeItemIds !== []) {
            $placeholders = [];
            foreach ($excludeItemIds as $i => $itemId) {
                $key = "exclude_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $itemId;
            }
            $sql .= ' AND ml_item_id NOT IN (' . implode(',', $placeholders) . ')';
        }

        $sql .= ' ORDER BY available_quantity DESC LIMIT 100';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Tabela ml_items pode não existir/estar sincronizada para todas as contas ainda.
            return [];
        }
    }
}
