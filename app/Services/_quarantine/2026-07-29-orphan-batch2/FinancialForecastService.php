<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Services\Financial\HasFinancialDependencies;
use PDO;

/**
 * Financial Forecast Service
 *
 * Serviço de projeções financeiras, saúde financeira, metas,
 * alertas e comparações de períodos.
 * Extraído de FinancialService.
 */
class FinancialForecastService
{
    use HasFinancialDependencies;

    private ?PnlReportService $pnlReportServiceInstance = null;
    private ?SellerReputationService $sellerReputationServiceInstance = null;
    private ?PaymentRefundService $paymentRefundServiceInstance = null;
    private ?SettlementReportService $settlementReportServiceInstance = null;

    private function pnlReport(): PnlReportService
    {
        return $this->pnlReportServiceInstance ??= new PnlReportService($this->accountId);
    }

    private function sellerReputation(): SellerReputationService
    {
        return $this->sellerReputationServiceInstance ??= new SellerReputationService($this->accountId);
    }

    private function paymentRefund(): PaymentRefundService
    {
        return $this->paymentRefundServiceInstance ??= new PaymentRefundService($this->accountId);
    }

    private function settlementReport(): SettlementReportService
    {
        return $this->settlementReportServiceInstance ??= new SettlementReportService($this->accountId);
    }

    public function getFinancialProjection(int $daysAhead = 30): array
    {
        // Usar últimos 30 dias como base
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-30 days'));

        $historical = $this->pnlReport()->getPnL($startDate, $endDate . ' 23:59:59');
        $dailyAvg = $historical['total_orders'] > 0
            ? $historical['gross_revenue'] / 30
            : 0;

        $projectedRevenue = $dailyAvg * $daysAhead;
        $projectedProfit = $historical['gross_revenue'] > 0
            ? ($historical['net_profit'] / $historical['gross_revenue']) * $projectedRevenue
            : 0;

        return [
            'projection_period_days' => $daysAhead,
            'based_on_days' => 30,
            'historical' => [
                'daily_avg_revenue' => round($dailyAvg, 2),
                'daily_avg_orders' => round($historical['total_orders'] / 30, 1),
                'avg_margin' => $historical['avg_margin'],
            ],
            'projected' => [
                'revenue' => round($projectedRevenue, 2),
                'profit' => round($projectedProfit, 2),
                'orders' => round(($historical['total_orders'] / 30) * $daysAhead),
            ],
            'confidence' => $historical['total_orders'] >= 30 ? 'high' : ($historical['total_orders'] >= 10 ? 'medium' : 'low'),
        ];
    }

    public function calculateFinancialHealthScore(string $startDate, string $endDate): array
    {
        // Coletar métricas
        $reputation = $this->sellerReputation()->getSellerReputation();
        $conversion = $this->sellerReputation()->calculateConversionRate($startDate, $endDate);
        $refunds = $this->paymentRefund()->getChargebacksRefundsReport($startDate, $endDate);

        $scores = [
            'reputation' => 0,
            'conversion' => 0,
            'refunds' => 0,
            'claims' => 0,
        ];

        // Score de reputação (0-25 pontos)
        if (!isset($reputation['error'])) {
            $repLevel = $reputation['level_id'] ?? '';
            $scores['reputation'] = match ($repLevel) {
                '5_green' => 25,
                '4_light_green' => 20,
                '3_yellow' => 15,
                '2_orange' => 10,
                '1_red' => 5,
                default => 0,
            };
        }

        // Score de conversão (0-25 pontos)
        if (!isset($conversion['error'])) {
            $convRate = $conversion['conversion_rate'] ?? 0;
            $scores['conversion'] = min(25, round($convRate * 5));
        }

        // Score de reembolsos (0-25 pontos)
        if (!isset($refunds['error'])) {
            $refundRate = $refunds['summary']['refund_rate'] ?? 0;
            // Quanto menor a taxa de reembolso, maior o score
            $scores['refunds'] = max(0, 25 - round($refundRate * 5));
        }

        // Score de claims (0-25 pontos) - baseado na reputação
        if (!isset($reputation['error'])) {
            $claimsRate = $reputation['metrics']['claims']['rate'] ?? 0;
            $scores['claims'] = max(0, 25 - round($claimsRate * 5));
        }

        $totalScore = array_sum($scores);

        return [
            'total_score' => $totalScore,
            'max_score' => 100,
            'grade' => $this->getHealthGrade($totalScore),
            'breakdown' => $scores,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'recommendations' => $this->getHealthRecommendations($scores),
        ];
    }

    /**
     * Determina a nota baseada no score
     */
    private function getHealthGrade(int|float $score): string
    {
        return match (true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B+',
            $score >= 60 => 'B',
            $score >= 50 => 'C+',
            $score >= 40 => 'C',
            $score >= 30 => 'D',
            default => 'F',
        };
    }

    /**
     * Gera recomendações baseadas nos scores
     */
    private function getHealthRecommendations(array $scores): array
    {
        $recommendations = [];

        if ($scores['reputation'] < 20) {
            $recommendations[] = [
                'area' => 'reputation',
                'priority' => 'high',
                'message' => 'Foque em melhorar a reputação: responda rápido e resolva problemas',
            ];
        }

        if ($scores['conversion'] < 15) {
            $recommendations[] = [
                'area' => 'conversion',
                'priority' => 'medium',
                'message' => 'Melhore a conversão: otimize títulos, fotos e descrições',
            ];
        }

        if ($scores['refunds'] < 20) {
            $recommendations[] = [
                'area' => 'refunds',
                'priority' => 'high',
                'message' => 'Reduza reembolsos: melhore embalagens e descrições precisas',
            ];
        }

        if ($scores['claims'] < 20) {
            $recommendations[] = [
                'area' => 'claims',
                'priority' => 'high',
                'message' => 'Reduza reclamações: melhore atendimento e logística',
            ];
        }

        return $recommendations;
    }

    public function calculateFinancialForecast(int $monthsAhead = 3): array
    {
        // Buscar dados históricos dos últimos 6 meses
        $stmt = $this->db->prepare("
            SELECT
                DATE_FORMAT(date_created, '%Y-%m') as month,
                COUNT(*) as order_count,
                SUM(total_amount) as revenue,
                AVG(total_amount) as avg_ticket
            FROM ml_orders
            WHERE ml_account_id = :account_id
            AND status = 'paid'
            AND date_created >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(date_created, '%Y-%m')
            ORDER BY month ASC
        ");

        $stmt->execute(['account_id' => $this->accountId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($history) < 3) {
            return ['error' => 'Dados insuficientes para projeção (mínimo 3 meses)'];
        }

        // Calcular tendências usando regressão linear simples
        $revenues = array_column($history, 'revenue');
        $orderCounts = array_column($history, 'order_count');
        $avgTickets = array_column($history, 'avg_ticket');

        $n = count($revenues);
        $xSum = ($n * ($n - 1)) / 2; // 0+1+2+...+(n-1)
        $x2Sum = ($n * ($n - 1) * (2 * $n - 1)) / 6;

        // Tendência de receita
        $revenueYSum = array_sum($revenues);
        $revenueXYSum = 0;
        foreach ($revenues as $i => $r) {
            $revenueXYSum += $i * $r;
        }
        $revenueSlope = ($n * $revenueXYSum - $xSum * $revenueYSum) / ($n * $x2Sum - $xSum * $xSum);
        $revenueIntercept = ($revenueYSum - $revenueSlope * $xSum) / $n;

        // Tendência de pedidos
        $orderYSum = array_sum($orderCounts);
        $orderXYSum = 0;
        foreach ($orderCounts as $i => $o) {
            $orderXYSum += $i * $o;
        }
        $orderSlope = ($n * $orderXYSum - $xSum * $orderYSum) / ($n * $x2Sum - $xSum * $xSum);
        $orderIntercept = ($orderYSum - $orderSlope * $xSum) / $n;

        // Gerar projeções
        $projections = [];
        $currentMonth = new \DateTime();
        for ($i = 1; $i <= $monthsAhead; $i++) {
            $currentMonth->modify('+1 month');
            $monthKey = $currentMonth->format('Y-m');
            $x = $n + $i - 1;

            $projectedRevenue = max(0, $revenueIntercept + $revenueSlope * $x);
            $projectedOrders = max(0, round($orderIntercept + $orderSlope * $x));
            $projectedTicket = $projectedOrders > 0 ? $projectedRevenue / $projectedOrders : 0;

            $projections[] = [
                'month' => $monthKey,
                'projected_revenue' => round($projectedRevenue, 2),
                'projected_orders' => $projectedOrders,
                'projected_avg_ticket' => round($projectedTicket, 2),
                'confidence' => $this->calculateForecastConfidence($history, $i),
            ];
        }

        // Calcular crescimento esperado
        $lastRevenue = end($revenues);
        $projectedTotal = array_sum(array_column($projections, 'projected_revenue'));
        $growthRate = $lastRevenue > 0 ? (($projections[0]['projected_revenue'] - $lastRevenue) / $lastRevenue) * 100 : 0;

        return [
            'historical_data' => $history,
            'projections' => $projections,
            'trends' => [
                'revenue_trend' => $revenueSlope > 0 ? 'growing' : ($revenueSlope < 0 ? 'declining' : 'stable'),
                'revenue_slope' => round($revenueSlope, 2),
                'orders_trend' => $orderSlope > 0 ? 'growing' : ($orderSlope < 0 ? 'declining' : 'stable'),
                'orders_slope' => round($orderSlope, 2),
            ],
            'summary' => [
                'projected_revenue_total' => round($projectedTotal, 2),
                'expected_monthly_growth_rate' => round($growthRate, 2),
                'avg_historical_revenue' => round(array_sum($revenues) / $n, 2),
                'avg_historical_orders' => round(array_sum($orderCounts) / $n),
            ],
        ];
    }

    /**
     * Calcula confiança da projeção
     *
     * @param array $history Dados históricos
     * @param int $monthsAhead Meses à frente
     * @return string Nível de confiança
     */
    private function calculateForecastConfidence(array $history, int $monthsAhead): string
    {
        $dataPoints = count($history);

        // Mais dados históricos = maior confiança
        // Mais meses à frente = menor confiança
        $baseConfidence = min(100, $dataPoints * 15);
        $decayFactor = max(0, 100 - ($monthsAhead * 20));

        $confidence = ($baseConfidence * $decayFactor) / 100;

        if ($confidence >= 70) {
            return 'high';
        } elseif ($confidence >= 40) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Calcula metas financeiras e progresso
     *
     * @param float $monthlyTarget Meta mensal
     * @return array Progresso das metas
     */
    public function calculateGoalProgress(float $monthlyTarget): array
    {
        $currentMonth = date('Y-m');
        $startOfMonth = date('Y-m-01');
        $today = date('Y-m-d');
        $daysInMonth = date('t');
        $dayOfMonth = date('j');

        // Receita atual do mês
        $stmt = $this->db->prepare("
            SELECT
                SUM(total_amount) as revenue,
                COUNT(*) as orders
            FROM ml_orders
            WHERE ml_account_id = :account_id
            AND status = 'paid'
            AND date_created >= :start_date
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'start_date' => $startOfMonth,
        ]);

        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentRevenue = (float)($current['revenue'] ?? 0);
        $currentOrders = (int)($current['orders'] ?? 0);

        // Calcular projeção para fim do mês
        $dailyAverage = $dayOfMonth > 0 ? $currentRevenue / $dayOfMonth : 0;
        $projectedMonthEnd = $dailyAverage * $daysInMonth;

        // Calcular quanto falta por dia
        $remainingDays = $daysInMonth - $dayOfMonth;
        $remainingAmount = max(0, $monthlyTarget - $currentRevenue);
        $dailyRequired = $remainingDays > 0 ? $remainingAmount / $remainingDays : $remainingAmount;

        // Progresso percentual
        $progressPercentage = $monthlyTarget > 0 ? ($currentRevenue / $monthlyTarget) * 100 : 0;
        $expectedProgress = ($dayOfMonth / $daysInMonth) * 100;

        return [
            'period' => $currentMonth,
            'target' => [
                'monthly' => $monthlyTarget,
                'daily_average_needed' => round($monthlyTarget / $daysInMonth, 2),
            ],
            'current' => [
                'revenue' => round($currentRevenue, 2),
                'orders' => $currentOrders,
                'daily_average' => round($dailyAverage, 2),
            ],
            'progress' => [
                'percentage' => round($progressPercentage, 2),
                'expected_percentage' => round($expectedProgress, 2),
                'ahead_or_behind' => $progressPercentage >= $expectedProgress ? 'ahead' : 'behind',
                'difference_percentage' => round($progressPercentage - $expectedProgress, 2),
            ],
            'projection' => [
                'month_end_estimated' => round($projectedMonthEnd, 2),
                'will_achieve_target' => $projectedMonthEnd >= $monthlyTarget,
                'remaining_amount' => round($remainingAmount, 2),
                'remaining_days' => $remainingDays,
                'daily_required_to_achieve' => round($dailyRequired, 2),
            ],
            'status' => $this->getGoalStatus($progressPercentage, $expectedProgress),
        ];
    }

    /**
     * Retorna status visual da meta
     */
    private function getGoalStatus(float $progress, float $expected): string
    {
        $diff = $progress - $expected;

        if ($progress >= 100) {
            return 'achieved';
        } elseif ($diff >= 10) {
            return 'excellent';
        } elseif ($diff >= 0) {
            return 'on_track';
        } elseif ($diff >= -10) {
            return 'attention';
        }
        return 'critical';
    }

    public function checkFinancialAlerts(): array
    {
        $alerts = [];

        // 1. Verificar taxa de reembolso alta
        $refundRate = $this->calculateRefundRate();
        if ($refundRate > 5) {
            $alerts[] = [
                'type' => 'high_refund_rate',
                'severity' => $refundRate > 10 ? 'critical' : 'warning',
                'message' => "Taxa de reembolso alta: {$refundRate}%",
                'recommendation' => 'Revise a qualidade dos produtos e descrições',
            ];
        }

        // 2. Verificar chargebacks
        $chargebackData = $this->paymentRefund()->getChargebacksRefundsReport(
            date('Y-m-01'),
            date('Y-m-d')
        );
        if (!isset($chargebackData['error']) && ($chargebackData['chargebacks']['total_count'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'chargebacks_detected',
                'severity' => 'critical',
                'message' => "Chargebacks detectados: " . ($chargebackData['chargebacks']['total_count'] ?? 0),
                'recommendation' => 'Responda às contestações imediatamente',
            ];
        }

        // 3. Verificar queda de receita
        $forecast = $this->calculateFinancialForecast(1);
        if (!isset($forecast['error']) && ($forecast['trends']['revenue_trend'] ?? '') === 'declining') {
            $alerts[] = [
                'type' => 'revenue_declining',
                'severity' => 'warning',
                'message' => 'Tendência de queda na receita detectada',
                'recommendation' => 'Analise causas e ajuste estratégias',
            ];
        }

        // 4. Verificar saldo baixo (se disponível)
        $balance = $this->pnlReport()->getAccountBalance();
        if (!isset($balance['error']) && ($balance['available_balance'] ?? 0) < 100) {
            $alerts[] = [
                'type' => 'low_balance',
                'severity' => 'info',
                'message' => 'Saldo disponível baixo: R$ ' . number_format($balance['available_balance'] ?? 0, 2, ',', '.'),
                'recommendation' => 'Considere transferir saldo quando disponível',
            ];
        }

        // 5. Verificar relatórios pendentes
        $pendingReports = $this->settlementReport()->checkPendingReports();
        if (($pendingReports['total_pending'] ?? 0) > 5) {
            $alerts[] = [
                'type' => 'many_pending_reports',
                'severity' => 'info',
                'message' => 'Muitos relatórios pendentes: ' . $pendingReports['total_pending'],
                'recommendation' => 'Aguarde processamento ou verifique erros',
            ];
        }

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_alerts' => count($alerts),
            'by_severity' => [
                'critical' => count(array_filter($alerts, fn(array $a): bool => $a['severity'] === 'critical')),
                'warning' => count(array_filter($alerts, fn(array $a): bool => $a['severity'] === 'warning')),
                'info' => count(array_filter($alerts, fn(array $a): bool => $a['severity'] === 'info')),
            ],
            'alerts' => $alerts,
        ];
    }

    /**
     * Calcula taxa de reembolso
     *
     * @return float Taxa em porcentagem
     */
    private function calculateRefundRate(): float
    {
        $startDate = date('Y-m-01', strtotime('-3 months'));
        $endDate = date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_orders
            FROM ml_orders
            WHERE ml_account_id = :account_id
            AND date_created BETWEEN :start_date AND :end_date
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalOrders = (int)($data['total_orders'] ?? 0);
        $refundedOrders = (int)($data['refunded_orders'] ?? 0);

        return $totalOrders > 0 ? round(($refundedOrders / $totalOrders) * 100, 2) : 0;
    }

    /**
     * Compara dois períodos financeiros com análise detalhada
     *
     * @param string $period1Start Início período 1
     * @param string $period1End Fim período 1
     * @param string $period2Start Início período 2
     * @param string $period2End Fim período 2
     * @return array Comparação
     */
    public function compareFinancialPeriods(
        string $period1Start,
        string $period1End,
        string $period2Start,
        string $period2End
    ): array {
        $period1 = $this->getPeriodMetrics($period1Start, $period1End);
        $period2 = $this->getPeriodMetrics($period2Start, $period2End);

        $calculateVariation = function ($current, $previous) {
            if ($previous == 0) {
                return $current > 0 ? 100 : 0;
            }
            return round((($current - $previous) / $previous) * 100, 2);
        };

        return [
            'period_1' => [
                'range' => ['start' => $period1Start, 'end' => $period1End],
                'metrics' => $period1,
            ],
            'period_2' => [
                'range' => ['start' => $period2Start, 'end' => $period2End],
                'metrics' => $period2,
            ],
            'variations' => [
                'revenue' => [
                    'absolute' => round($period2['revenue'] - $period1['revenue'], 2),
                    'percentage' => $calculateVariation($period2['revenue'], $period1['revenue']),
                ],
                'orders' => [
                    'absolute' => $period2['orders'] - $period1['orders'],
                    'percentage' => $calculateVariation($period2['orders'], $period1['orders']),
                ],
                'avg_ticket' => [
                    'absolute' => round($period2['avg_ticket'] - $period1['avg_ticket'], 2),
                    'percentage' => $calculateVariation($period2['avg_ticket'], $period1['avg_ticket']),
                ],
                'items_sold' => [
                    'absolute' => $period2['items_sold'] - $period1['items_sold'],
                    'percentage' => $calculateVariation($period2['items_sold'], $period1['items_sold']),
                ],
            ],
            'analysis' => $this->analyzePeriodComparison($period1, $period2),
        ];
    }

    /**
     * Obtém métricas de um período
     */
    private function getPeriodMetrics(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue,
                COALESCE(AVG(total_amount), 0) as avg_ticket,
                COALESCE(SUM(
                    (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ml_orders.id)
                ), 0) as items_sold
            FROM ml_orders
            WHERE ml_account_id = :account_id
            AND status = 'paid'
            AND date_created BETWEEN :start_date AND :end_date
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'orders' => (int)($data['orders'] ?? 0),
            'revenue' => round((float)($data['revenue'] ?? 0), 2),
            'avg_ticket' => round((float)($data['avg_ticket'] ?? 0), 2),
            'items_sold' => (int)($data['items_sold'] ?? 0),
        ];
    }

    /**
     * Analisa comparação de períodos
     */
    private function analyzePeriodComparison(array $period1, array $period2): array
    {
        $insights = [];

        // Análise de receita
        $revenueDiff = $period2['revenue'] - $period1['revenue'];
        if ($revenueDiff > 0) {
            $insights[] = [
                'metric' => 'revenue',
                'status' => 'positive',
                'message' => 'Receita aumentou R$ ' . number_format($revenueDiff, 2, ',', '.'),
            ];
        } elseif ($revenueDiff < 0) {
            $insights[] = [
                'metric' => 'revenue',
                'status' => 'negative',
                'message' => 'Receita diminuiu R$ ' . number_format(abs($revenueDiff), 2, ',', '.'),
            ];
        }

        // Análise de ticket médio
        $ticketDiff = $period2['avg_ticket'] - $period1['avg_ticket'];
        if ($ticketDiff > 0) {
            $insights[] = [
                'metric' => 'avg_ticket',
                'status' => 'positive',
                'message' => 'Ticket médio aumentou R$ ' . number_format($ticketDiff, 2, ',', '.'),
            ];
        } elseif ($ticketDiff < 0) {
            $insights[] = [
                'metric' => 'avg_ticket',
                'status' => 'negative',
                'message' => 'Ticket médio diminuiu R$ ' . number_format(abs($ticketDiff), 2, ',', '.'),
            ];
        }

        // Análise de volume
        $ordersDiff = $period2['orders'] - $period1['orders'];
        if ($ordersDiff > 0) {
            $insights[] = [
                'metric' => 'orders',
                'status' => 'positive',
                'message' => "Volume de pedidos aumentou em {$ordersDiff} pedidos",
            ];
        } elseif ($ordersDiff < 0) {
            $insights[] = [
                'metric' => 'orders',
                'status' => 'negative',
                'message' => 'Volume de pedidos diminuiu em ' . abs($ordersDiff) . ' pedidos',
            ];
        }

        return $insights;
    }
}
