<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Helpers\SessionHelper;
use App\Services\Financial\HasFinancialDependencies;
use PDO;

/**
 * PnL Report Service
 *
 * Serviço de relatórios de P&L (Demonstrativo de Resultado), fluxo de caixa,
 * métricas financeiras e dashboard consolidado.
 * Extraído de FinancialService.
 */
class PnlReportService
{
    use HasFinancialDependencies;

    private ?SubscriptionService $subscriptionServiceInstance = null;
    private ?ClaimDisputeService $claimDisputeServiceInstance = null;
    private ?FinancialForecastService $financialForecastServiceInstance = null;
    private ?PaymentRefundService $paymentRefundServiceInstance = null;

    private function subscription(): SubscriptionService
    {
        return $this->subscriptionServiceInstance ??= new SubscriptionService($this->accountId);
    }

    private function claimDispute(): ClaimDisputeService
    {
        return $this->claimDisputeServiceInstance ??= new ClaimDisputeService($this->accountId);
    }

    private function financialForecast(): FinancialForecastService
    {
        return $this->financialForecastServiceInstance ??= new FinancialForecastService($this->accountId);
    }

    private function paymentRefund(): PaymentRefundService
    {
        return $this->paymentRefundServiceInstance ??= new PaymentRefundService($this->accountId);
    }

    /**
     * Calcula o DRE (Demonstrativo de Resultado) para o período
     *
     * @param string $startDate Data inicial (Y-m-d)
     * @param string $endDate Data final (Y-m-d H:i:s)
     * @return array Dados do P&L
     */
    public function getPnL(string $startDate, string $endDate): array
    {
        $whereConditions = ['date_created BETWEEN :start AND :end', "status IN ('paid', 'delivered')"];
        $params = [':start' => $startDate, ':end' => $endDate];

        // Filtrar por conta se especificado
        if ($this->accountId) {
            $whereConditions[] = 'ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        // Filtrar por usuário
        $userId = SessionHelper::getUserId();
        if ($userId) {
            $whereConditions[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $whereSql = implode(' AND ', $whereConditions);

        // Consultar dados agregados dos pedidos
        $sql = "SELECT
                    COUNT(*) as total_orders,
                    COALESCE(SUM(total_amount), 0) as gross_revenue,
                    COALESCE(SUM(subtotal), 0) as subtotal,
                    COALESCE(SUM(ml_commission), 0) as commissions,
                    COALESCE(SUM(payment_fee), 0) as payment_fees,
                    COALESCE(SUM(fixed_fee), 0) as fixed_fees,
                    COALESCE(SUM(shipping_cost), 0) as shipping_cost,
                    COALESCE(SUM(discount_amount), 0) as discounts,
                    COALESCE(SUM(taxes), 0) as taxes,
                    COALESCE(SUM(product_cost), 0) as cogs,
                    COALESCE(SUM(net_profit), 0) as net_profit,
                    COALESCE(AVG(gross_margin), 0) as avg_margin
                FROM ml_orders
                WHERE {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calcular valores derivados
        $grossRevenue = (float)($data['gross_revenue'] ?? 0);
        $taxes = (float)($data['taxes'] ?? 0);
        $netRevenue = $grossRevenue - $taxes;

        // Se não tiver campos detalhados, calcular estimativas
        $commissions = (float)($data['commissions'] ?? 0);
        $paymentFees = (float)($data['payment_fees'] ?? 0);
        $fixedFees = (float)($data['fixed_fees'] ?? 0);
        $shippingCost = (float)($data['shipping_cost'] ?? 0);
        $discounts = (float)($data['discounts'] ?? 0);
        $cogs = (float)($data['cogs'] ?? 0);
        $netProfit = (float)($data['net_profit'] ?? 0);

        // Se net_profit estiver zerado, calcular
        if ($netProfit === 0.0 && $grossRevenue > 0) {
            $totalCosts = $commissions + $paymentFees + $fixedFees + $shippingCost + $cogs;
            $netProfit = $netRevenue - $totalCosts - $discounts;
        }

        // Calcular margem se não existir
        $avgMargin = (float)($data['avg_margin'] ?? 0);
        if ($avgMargin === 0.0 && $grossRevenue > 0) {
            $avgMargin = ($netProfit / $grossRevenue) * 100;
        }

        return [
            'total_orders' => (int)($data['total_orders'] ?? 0),
            'gross_revenue' => round($grossRevenue, 2),
            'taxes' => round($taxes, 2),
            'net_revenue' => round($netRevenue, 2),
            'cogs' => round($cogs, 2),
            'commissions' => round($commissions, 2),
            'payment_fees' => round($paymentFees, 2),
            'fixed_fees' => round($fixedFees, 2),
            'shipping_cost' => round($shippingCost, 2),
            'discounts' => round($discounts, 2),
            'net_profit' => round($netProfit, 2),
            'avg_margin' => round($avgMargin, 2),
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
        ];
    }

    /**
     * Retorna receita e lucro diários para gráficos
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Array de dados diários
     */
    public function getDailyRevenue(string $startDate, string $endDate): array
    {
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startDate, ':end' => $endDate];

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

        $sql = "SELECT
                    DATE(date_created) as date,
                    SUM(total_amount) as revenue,
                    SUM(net_profit) as profit,
                    COUNT(*) as orders
                FROM ml_orders
                WHERE {$whereSql}
                GROUP BY DATE(date_created)
                ORDER BY date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row): array => [
            'date' => $row['date'],
            'revenue' => round((float)$row['revenue'], 2),
            'profit' => round((float)$row['profit'], 2),
            'orders' => (int)$row['orders'],
        ], $rows);
    }

    /**
     * Retorna o fluxo de caixa do período
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Dados de fluxo de caixa
     */
    public function getCashFlow(string $startDate, string $endDate): array
    {
        // Entradas: receitas dos pedidos
        $inflows = $this->getInflows($startDate, $endDate);

        // Saídas: custos e taxas
        $outflows = $this->getOutflows($startDate, $endDate);

        // Saldo
        $balance = $inflows['total'] - $outflows['total'];

        return [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'balance' => round($balance, 2),
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
        ];
    }

    /**
     * Calcula entradas (receitas)
     */
    private function getInflows(string $startDate, string $endDate): array
    {
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startDate, ':end' => $endDate];

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

        $sql = "SELECT
                    SUM(total_amount) as sales,
                    COUNT(*) as transactions
                FROM ml_orders
                WHERE {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $sales = (float)($data['sales'] ?? 0);

        return [
            'sales' => round($sales, 2),
            'transactions' => (int)($data['transactions'] ?? 0),
            'total' => round($sales, 2),
        ];
    }

    /**
     * Calcula saídas (custos)
     */
    private function getOutflows(string $startDate, string $endDate): array
    {
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startDate, ':end' => $endDate];

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

        $sql = "SELECT
                    SUM(ml_commission) as commissions,
                    SUM(payment_fee) as payment_fees,
                    SUM(fixed_fee) as fixed_fees,
                    SUM(shipping_cost) as shipping,
                    SUM(product_cost) as cogs,
                    SUM(taxes) as taxes
                FROM ml_orders
                WHERE {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $commissions = (float)($data['commissions'] ?? 0);
        $paymentFees = (float)($data['payment_fees'] ?? 0);
        $fixedFees = (float)($data['fixed_fees'] ?? 0);
        $shipping = (float)($data['shipping'] ?? 0);
        $cogs = (float)($data['cogs'] ?? 0);
        $taxes = (float)($data['taxes'] ?? 0);

        $total = $commissions + $paymentFees + $fixedFees + $shipping + $cogs + $taxes;

        return [
            'commissions' => round($commissions, 2),
            'payment_fees' => round($paymentFees, 2),
            'fixed_fees' => round($fixedFees, 2),
            'shipping' => round($shipping, 2),
            'cogs' => round($cogs, 2),
            'taxes' => round($taxes, 2),
            'total' => round($total, 2),
        ];
    }

    /**
     * Retorna métricas financeiras do período
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Métricas calculadas
     */
    public function getMetrics(string $startDate, string $endDate): array
    {
        $pnl = $this->getPnL($startDate, $endDate);

        // Ticket médio
        $avgTicket = $pnl['total_orders'] > 0
            ? $pnl['gross_revenue'] / $pnl['total_orders']
            : 0;

        // Taxa de conversão de custos
        $costRate = $pnl['gross_revenue'] > 0
            ? (($pnl['commissions'] + $pnl['payment_fees'] + $pnl['fixed_fees']) / $pnl['gross_revenue']) * 100
            : 0;

        // ROI
        $totalCosts = $pnl['cogs'] + $pnl['commissions'] + $pnl['payment_fees'] + $pnl['fixed_fees'] + $pnl['shipping_cost'];
        $roi = $totalCosts > 0
            ? (($pnl['net_profit'] / $totalCosts) * 100)
            : 0;

        return [
            'total_orders' => $pnl['total_orders'],
            'gross_revenue' => $pnl['gross_revenue'],
            'net_profit' => $pnl['net_profit'],
            'avg_ticket' => round($avgTicket, 2),
            'avg_margin' => $pnl['avg_margin'],
            'cost_rate' => round($costRate, 2),
            'roi' => round($roi, 2),
        ];
    }

    /**
     * Compara períodos (mês atual vs anterior, por exemplo)
     *
     * @param string $currentStart Início do período atual
     * @param string $currentEnd Fim do período atual
     * @param string $previousStart Início do período anterior
     * @param string $previousEnd Fim do período anterior
     * @return array Comparação com variações
     */
    public function comparePeriods(
        string $currentStart,
        string $currentEnd,
        string $previousStart,
        string $previousEnd
    ): array {
        $current = $this->getPnL($currentStart, $currentEnd);
        $previous = $this->getPnL($previousStart, $previousEnd);

        $calculateVariation = function ($current, $previous): float {
            if ($previous == 0) {
                return $current > 0 ? 100 : 0;
            }
            return round((($current - $previous) / $previous) * 100, 2);
        };

        return [
            'current' => $current,
            'previous' => $previous,
            'variations' => [
                'gross_revenue' => $calculateVariation($current['gross_revenue'], $previous['gross_revenue']),
                'net_profit' => $calculateVariation($current['net_profit'], $previous['net_profit']),
                'total_orders' => $calculateVariation($current['total_orders'], $previous['total_orders']),
                'avg_margin' => round($current['avg_margin'] - $previous['avg_margin'], 2),
            ],
        ];
    }

    /**
     * Retorna resumo financeiro para cards do dashboard
     */
    public function getDashboardSummary(): array
    {
        // Período atual (mês)
        $currentMonthStart = date('Y-m-01');
        $currentMonthEnd = date('Y-m-t 23:59:59');

        // Mês anterior
        $previousMonthStart = date('Y-m-01', strtotime('-1 month'));
        $previousMonthEnd = date('Y-m-t 23:59:59', strtotime('-1 month'));

        $comparison = $this->comparePeriods(
            $currentMonthStart,
            $currentMonthEnd,
            $previousMonthStart,
            $previousMonthEnd
        );

        // Hoje
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $today = $this->getPnL($todayStart, $todayEnd);

        return [
            'today' => $today,
            'current_month' => $comparison['current'],
            'previous_month' => $comparison['previous'],
            'variations' => $comparison['variations'],
        ];
    }

    /**
     * Obtém saldo da conta no Mercado Pago
     * Endpoint: GET /users/{user_id}/mercadopago_account/balance
     *
     * @return array Saldo disponível e total
     */
    public function getAccountBalance(): array
    {
        $sellerId = $this->getSellerId();
        if (!$sellerId) {
            return ['error' => 'Seller ID não encontrado', 'available_balance' => 0, 'total_amount' => 0];
        }

        $client = $this->getClient();
        $response = $client->get("/users/{$sellerId}/mercadopago_account/balance", [], self::CACHE_TTL_SHORT);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao obter saldo',
                'available_balance' => 0,
                'total_amount' => 0,
                'unavailable_balance' => 0,
            ];
        }

        return [
            'available_balance' => (float)($response['available_balance'] ?? 0),
            'total_amount' => (float)($response['total_amount'] ?? 0),
            'unavailable_balance' => (float)($response['unavailable_balance'] ?? 0),
            'currency_id' => $response['currency_id'] ?? 'BRL',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Dashboard financeiro consolidado com dados de múltiplas fontes.
     */
    public function getConsolidatedFinancialDashboard(string $period = 'month'): array
    {
        $dates = $this->getPeriodDates($period);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        // Coleta dados em paralelo (onde possível)
        $payments = $this->getPaymentsSummary($startDate, $endDate);
        $subscriptions = $this->subscription()->getRecurringRevenueAnalysis();
        $claims = $this->claimDispute()->analyzeClaimsPerformance($startDate, $endDate);
        $forecast = $this->financialForecast()->calculateFinancialForecast(3);
        $alerts = $this->financialForecast()->checkFinancialAlerts();
        $healthScore = $this->financialForecast()->calculateFinancialHealthScore($startDate, $endDate);

        return [
            'period' => [
                'type' => $period,
                'start' => $startDate,
                'end' => $endDate,
            ],
            'summary' => [
                'total_revenue' => $payments['total_approved'] ?? 0,
                'total_pending' => $payments['total_pending'] ?? 0,
                'total_refunded' => $payments['total_refunded'] ?? 0,
                'net_revenue' => ($payments['total_approved'] ?? 0) - ($payments['total_refunded'] ?? 0),
                'mrr' => $subscriptions['mrr'],
                'arr' => $subscriptions['arr'],
            ],
            'payments' => $payments,
            'subscriptions' => [
                'mrr' => $subscriptions['mrr'],
                'arr' => $subscriptions['arr'],
                'active_count' => $subscriptions['total_active_subscriptions'],
                'avg_value' => $subscriptions['avg_subscription_value'],
            ],
            'claims' => [
                'total' => $claims['total_claims'],
                'pending' => $claims['pending'],
                'resolution_rate' => $claims['resolution_rate'],
                'health_status' => $claims['health_indicator']['status'],
            ],
            'forecast' => [
                'next_month' => $forecast['projections'][0] ?? null,
                'trend' => $forecast['trends']['revenue_trend'] ?? 'stable',
            ],
            'alerts' => [
                'critical' => $alerts['by_severity']['critical'] ?? 0,
                'warning' => $alerts['by_severity']['warning'] ?? 0,
                'items' => array_slice($alerts['alerts'] ?? [], 0, 5),
            ],
            'health' => [
                'score' => $healthScore['total_score'] ?? null,
                'grade' => $healthScore['grade'] ?? null,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtém resumo de pagamentos para um período.
     */
    private function getPaymentsSummary(string $startDate, string $endDate): array
    {
        $payments = $this->paymentRefund()->searchPayments([
            'begin_date' => $startDate . 'T00:00:00.000-03:00',
            'end_date' => $endDate . 'T23:59:59.999-03:00',
            'limit' => 100,
        ]);

        $totalApproved = 0;
        $totalPending = 0;
        $totalRefunded = 0;
        $byMethod = [];
        $count = 0;

        foreach ($payments['results'] ?? [] as $payment) {
            $amount = $payment['transaction_amount'] ?? 0;
            $status = $payment['status'] ?? '';
            $method = $payment['payment_type_id'] ?? $payment['payment_method_id'] ?? 'other';

            $count++;

            if ($status === 'approved') {
                $totalApproved += $amount;
            } elseif (in_array($status, ['pending', 'in_process', 'authorized'])) {
                $totalPending += $amount;
            } elseif (in_array($status, ['refunded', 'cancelled', 'charged_back'])) {
                $totalRefunded += $amount;
            }

            $byMethod[$method] = ($byMethod[$method] ?? 0) + $amount;
        }

        return [
            'total_approved' => round($totalApproved, 2),
            'total_pending' => round($totalPending, 2),
            'total_refunded' => round($totalRefunded, 2),
            'count' => $count,
            'avg_ticket' => $count > 0 ? round($totalApproved / $count, 2) : 0,
            'by_method' => $byMethod,
        ];
    }

    /**
     * Converte período em datas
     */
    private function getPeriodDates(string $period): array
    {
        $now = new \DateTime();

        switch ($period) {
            case 'today':
                $start = $now->format('Y-m-d');
                $end = $start;
                break;
            case 'week':
                $start = $now->modify('-7 days')->format('Y-m-d');
                $end = (new \DateTime())->format('Y-m-d');
                break;
            case 'year':
                $start = $now->format('Y') . '-01-01';
                $end = (new \DateTime())->format('Y-m-d');
                break;
            case 'month':
            default:
                $start = $now->format('Y-m') . '-01';
                $end = (new \DateTime())->format('Y-m-d');
                break;
        }

        return ['start' => $start, 'end' => $end];
    }
}
