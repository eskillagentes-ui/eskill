<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\DebugAgentLog;
use App\Helpers\SessionHelper;
use App\Services\FinancialService;
use Dompdf\Dompdf;
use Dompdf\Options;

class FinancialReportController extends BaseController
{
    private FinancialService $financialService;
    private ?int $accountId;

    public function __construct()
    {
        parent::__construct();
        $this->accountId = SessionHelper::getActiveAccountId();
        $this->financialService = new FinancialService($this->accountId);
    }

    /**
     * Validate and sanitize date parameter
     */
    private function validateDate(string $date): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
    }

    /**
     * Get validated start/end date range from request, or send 400 error
     * Returns [startDate, endDate] or null (if error response was sent)
     */
    private function getValidatedDateRange(): ?array
    {
        $startDate = $this->request->get('start', date('Y-m-01'));
        $endDate = $this->request->get('end', date('Y-m-t'));

        if (!$this->validateDate($startDate) || !$this->validateDate($endDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'Formato de data inválido. Use YYYY-MM-DD.']);
            return null;
        }

        return [$startDate, $endDate];
    }

    /**
     * Render Financial Dashboard (P&L)
     * GET /dashboard/financials
     */
    public function index(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // #region agent log
        DebugAgentLog::write('I', 'FinancialReportController::index', 'financials_page_render', [
            'userId' => SessionHelper::getUserId(),
            'accountId' => SessionHelper::getActiveAccountId(),
        ]);
        // #endregion

        $this->renderView('dashboard/financials', [
            'pageTitle' => 'Relatórios Financeiros',
            'activePage' => 'financials',
        ]);
    }

    /**
     * API to get P&L Data
     * GET /api/financials/pnl?start=2023-01-01&end=2023-01-31
     */
    public function getPnLData(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $pnl = $this->financialService->getPnL($startDate, $endDate . ' 23:59:59');
            $daily = $this->financialService->getDailyRevenue($startDate, $endDate . ' 23:59:59');

            echo json_encode([
                'success' => true,
                'pnl' => $pnl,
                'chart' => $daily
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Export P&L to PDF
     * GET /api/financials/export?start=...&end=...
     */
    public function exportPdf(): void
    {
        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        $pnl = $this->financialService->getPnL($startDate, $endDate . ' 23:59:59');

        $html = "
        <html>
        <head>
            <style>
                body { font-family: Helvetica, sans-serif; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { padding: 10px; border-bottom: 1px solid #ddd; }
                .amount { text-align: right; }
                .total { font-weight: bold; background: #f4f4f4; }
                .profit { color: green; }
                .loss { color: red; }
                h1 { color: #333; }
            </style>
        </head>
        <body>
            <h1>Demonstrativo de Resultados (DRE)</h1>
            <p>Período: $startDate até $endDate</p>

            <table>
                <tr>
                    <td><strong>Receita Bruta</strong></td>
                    <td class='amount'>R$ " . number_format($pnl['gross_revenue'], 2, ',', '.') . "</td>
                </tr>
                 <tr>
                    <td>(-) Impostos</td>
                    <td class='amount text-danger'>R$ " . number_format($pnl['taxes'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td><strong>Receita Líquida</strong></td>
                    <td class='amount'><strong>R$ " . number_format($pnl['net_revenue'], 2, ',', '.') . "</strong></td>
                </tr>
                <tr><td colspan='2'>&nbsp;</td></tr>

                <tr>
                    <td>(-) Custo das Mercadorias (CMV)</td>
                    <td class='amount'>R$ " . number_format($pnl['cogs'], 2, ',', '.') . "</td>
                </tr>
                 <tr>
                    <td>(-) Comissões Marketplace</td>
                    <td class='amount'>R$ " . number_format($pnl['commissions'], 2, ',', '.') . "</td>
                </tr>
                 <tr>
                    <td>(-) Taxas de Pagamento</td>
                    <td class='amount'>R$ " . number_format($pnl['payment_fees'], 2, ',', '.') . "</td>
                </tr>
                 <tr>
                    <td>(-) Fretes</td>
                    <td class='amount'>R$ " . number_format($pnl['shipping_cost'], 2, ',', '.') . "</td>
                </tr>
                 <tr>
                    <td>(-) Descontos</td>
                    <td class='amount'>R$ " . number_format($pnl['discounts'], 2, ',', '.') . "</td>
                </tr>

                <tr class='total'>
                    <td>Lucro Líquido</td>
                    <td class='amount " . ($pnl['net_profit'] >= 0 ? 'profit' : 'loss') . "'>
                        R$ " . number_format($pnl['net_profit'], 2, ',', '.') . "
                    </td>
                </tr>
                 <tr style='font-size: 0.9em; color: #666;'>
                    <td>Margem Líquida</td>
                    <td class='amount'>" . number_format($pnl['avg_margin'], 1, ',', '.') . "%</td>
                </tr>
            </table>

            <p><small>Gerado automaticamente pelo Sistema ML Manager.</small></p>
        </body>
        </html>
        ";

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="DRE_' . $startDate . '.pdf"');
        echo $dompdf->output();
    }

    // ========================================================================
    // NOVOS ENDPOINTS - DADOS EM TEMPO REAL DA API
    // ========================================================================

    /**
     * API para obter saldo da conta Mercado Pago
     * GET /api/financials/balance
     */
    public function getBalance(): void
    {
        header('Content-Type: application/json');

        try {
            $balance = $this->financialService->getAccountBalance();
            echo json_encode([
                'success' => !isset($balance['error']),
                'data' => $balance,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter pedidos com dados financeiros da API ML
     * GET /api/financials/orders?start=2023-01-01&end=2023-01-31&limit=50&offset=0
     */
    public function getOrders(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;
        $limit = $this->request->getIntClamped('limit', 1, 50, 50);
        $offset = max(0, $this->request->getInt('offset', 0));

        try {
            $orders = $this->financialService->getOrdersFromApi($startDate, $endDate, $limit, $offset);
            echo json_encode([
                'success' => !isset($orders['error']),
                'data' => $orders,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de um pedido específico
     * GET /api/financials/orders/{orderId}
     */
    public function getOrderDetail(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $order = $this->financialService->getOrderDetails($orderId);
            echo json_encode([
                'success' => !isset($order['error']),
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter resumo financeiro em tempo real
     * GET /api/financials/realtime?start=2023-01-01&end=2023-01-31
     */
    public function getRealTimeSummary(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $summary = $this->financialService->getRealTimeFinancialSummary($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter breakdown de taxas e comissões
     * GET /api/financials/fees?start=2023-01-01&end=2023-01-31
     */
    public function getFeesBreakdown(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $fees = $this->financialService->getFeesBreakdown($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $fees,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter projeção financeira
     * GET /api/financials/projection?days=30
     */
    public function getProjection(): void
    {
        header('Content-Type: application/json');

        $days = $this->request->getIntClamped('days', 7, 90, 30);

        try {
            $projection = $this->financialService->getFinancialProjection($days);
            echo json_encode([
                'success' => true,
                'data' => $projection,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter receita por categoria
     * GET /api/financials/categories?start=2023-01-01&end=2023-01-31
     */
    public function getRevenueByCategory(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $categories = $this->financialService->getRevenueByCategory($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $categories,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter movimentações da conta
     * GET /api/financials/movements?start=2023-01-01&end=2023-01-31&limit=50
     */
    public function getMovements(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;
        $limit = $this->request->getIntClamped('limit', 1, 100, 50);

        try {
            $movements = $this->financialService->getAccountMovements($startDate, $endDate, $limit);
            echo json_encode([
                'success' => !isset($movements['error']),
                'data' => $movements,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter relatório de liquidações
     * GET /api/financials/settlements?start=2023-01-01&end=2023-01-31
     */
    public function getSettlements(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $settlements = $this->financialService->getSettlementReport($startDate, $endDate);
            echo json_encode([
                'success' => !isset($settlements['error']),
                'data' => $settlements,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para sincronizar pedidos com dados financeiros
     * POST /api/financials/sync
     */
    public function syncOrders(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json() ?? [];
        $startDate = $input['start'] ?? date('Y-m-01');
        $endDate = $input['end'] ?? date('Y-m-d');
        $forceSync = (bool)($input['force'] ?? false);

        try {
            $result = $this->financialService->syncOrdersWithFinancials($startDate, $endDate, $forceSync);
            echo json_encode([
                'success' => $result['success'] ?? false,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de um pagamento
     * GET /api/financials/payments/{paymentId}
     */
    public function getPaymentDetail(string $paymentId): void
    {
        header('Content-Type: application/json');

        try {
            $payment = $this->financialService->getPaymentDetails($paymentId);
            echo json_encode([
                'success' => !isset($payment['error']),
                'data' => $payment,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter dados de lucratividade por produto
     * GET /api/financials/profitability?start=2023-01-01&end=2023-01-31&limit=20
     */
    public function getProfitability(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;
        $limit = $this->request->getIntClamped('limit', 5, 50, 20);

        try {
            $profitability = $this->financialService->getProfitabilityByProduct($startDate, $endDate . ' 23:59:59', $limit);
            echo json_encode([
                'success' => true,
                'data' => $profitability,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter métricas financeiras
     * GET /api/financials/metrics?start=2023-01-01&end=2023-01-31
     */
    public function getMetrics(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $metrics = $this->financialService->getMetrics($startDate, $endDate . ' 23:59:59');
            echo json_encode([
                'success' => true,
                'data' => $metrics,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter comparação de períodos
     * GET /api/financials/compare?current_start=2023-02-01&current_end=2023-02-28&previous_start=2023-01-01&previous_end=2023-01-31
     */
    public function comparePeriods(): void
    {
        header('Content-Type: application/json');

        $currentStart = $this->request->get('current_start', date('Y-m-01'));
        $currentEnd = $this->request->get('current_end', date('Y-m-d'));
        $previousStart = $this->request->get('previous_start', date('Y-m-01', strtotime('-1 month')));
        $previousEnd = $this->request->get('previous_end', date('Y-m-t', strtotime('-1 month')));

        try {
            $comparison = $this->financialService->comparePeriods(
                $currentStart,
                $currentEnd . ' 23:59:59',
                $previousStart,
                $previousEnd . ' 23:59:59'
            );
            echo json_encode([
                'success' => true,
                'data' => $comparison,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter fluxo de caixa
     * GET /api/financials/cashflow?start=2023-01-01&end=2023-01-31
     */
    public function getCashFlow(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $cashflow = $this->financialService->getCashFlow($startDate, $endDate . ' 23:59:59');
            echo json_encode([
                'success' => true,
                'data' => $cashflow,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter resumo do dashboard
     * GET /api/financials/dashboard
     */
    public function getDashboardSummary(): void
    {
        header('Content-Type: application/json');

        try {
            $summary = $this->financialService->getDashboardSummary();

            // Adicionar saldo da conta em tempo real
            $balance = $this->financialService->getAccountBalance();
            $summary['account_balance'] = $balance;

            echo json_encode([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter receita diária (para gráficos)
     * GET /api/financials/daily?start=2023-01-01&end=2023-01-31
     */
    public function getDailyRevenue(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $daily = $this->financialService->getDailyRevenue($startDate, $endDate . ' 23:59:59');
            echo json_encode([
                'success' => true,
                'data' => $daily,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de faturamento do Mercado Livre
     * GET /api/financials/billing/ml?period=2024-01-01&document_type=BILL&limit=150
     */
    public function getBillingDetails(): void
    {
        header('Content-Type: application/json');

        $periodKey = $this->request->get('period', date('Y-m-01'));
        $documentType = $this->request->get('document_type', 'BILL');
        $limit = $this->request->getInt('limit', 150);
        $fromId = $this->request->getInt('from_id', 0);

        try {
            $data = $this->financialService->getBillingDetails(
                $periodKey,
                $documentType,
                $limit,
                $fromId
            );
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de faturamento do Mercado Pago
     * GET /api/financials/billing/mp?period=2024-01-01&document_type=BILL
     */
    public function getMPBillingDetails(): void
    {
        header('Content-Type: application/json');

        $periodKey = $this->request->get('period', date('Y-m-01'));
        $documentType = $this->request->get('document_type', 'BILL');
        $limit = $this->request->getInt('limit', 150);
        $fromId = $this->request->getInt('from_id', 0);

        try {
            $data = $this->financialService->getMercadoPagoBillingDetails(
                $periodKey,
                $documentType,
                $limit,
                $fromId
            );
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter faturamento por order específica
     * GET /api/financials/billing/order?order_ids=123,456&pack_id=789
     */
    public function getBillingByOrder(): void
    {
        header('Content-Type: application/json');

        $orderIdsParam = $this->request->get('order_ids', '');
        $packId = $this->request->get('pack_id');

        $orderIds = array_filter(explode(',', $orderIdsParam));

        if (empty($orderIds) && empty($packId)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Informe order_ids ou pack_id',
            ]);
            return;
        }

        try {
            $data = $this->financialService->getBillingByOrder($orderIds, $packId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de fulfillment (Full)
     * GET /api/financials/billing/fulfillment?period=2024-01-01
     */
    public function getFulfillmentBilling(): void
    {
        header('Content-Type: application/json');

        $periodKey = $this->request->get('period', date('Y-m-01'));
        $documentType = $this->request->get('document_type', 'BILL');
        $limit = $this->request->getInt('limit', 150);

        try {
            $data = $this->financialService->getFulfillmentBillingDetails(
                $periodKey,
                $documentType,
                $limit
            );
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de envios Flex
     * GET /api/financials/billing/flex?period=2024-01-01
     */
    public function getFlexBilling(): void
    {
        header('Content-Type: application/json');

        $periodKey = $this->request->get('period', date('Y-m-01'));
        $documentType = $this->request->get('document_type', 'BILL');
        $limit = $this->request->getInt('limit', 150);

        try {
            $data = $this->financialService->getFlexShippingBillingDetails(
                $periodKey,
                $documentType,
                $limit
            );
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter resumo consolidado de faturamento do período
     * GET /api/financials/billing/summary?period=2024-01-01
     */
    public function getBillingSummary(): void
    {
        header('Content-Type: application/json');

        $periodKey = $this->request->get('period', date('Y-m-01'));

        try {
            $data = $this->financialService->getBillingPeriodSummary($periodKey);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter dados fiscais do comprador de uma order
     * GET /api/financials/order/{orderId}/buyer-billing
     */
    public function getBuyerBillingInfo(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getBuyerBillingInfo($orderId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de taxas de uma order
     * GET /api/financials/order/{orderId}/fees
     */
    public function getOrderFees(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getOrderSaleFeeDetails($orderId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para gerar relatório de conciliação
     * GET /api/financials/reconciliation?start=2024-01-01&end=2024-01-31
     */
    public function getReconciliationReport(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $data = $this->financialService->generateReconciliationReport($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter resumo de percepciones (impostos retidos - Argentina)
     * GET /api/financials/perceptions/summary?period=2024-01-01&group=ML
     */
    public function getPerceptionsSummary(): void
    {
        header('Content-Type: application/json');

        $periodKey = $this->request->get('period', date('Y-m-01'));
        $group = $this->request->get('group', 'ML');

        try {
            $data = $this->financialService->getPerceptionsSummary($periodKey, $group);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de percepciones
     * GET /api/financials/perceptions/details?group=ML&document_id=123&tax_type=CIVA
     */
    public function getPerceptionsDetails(): void
    {
        header('Content-Type: application/json');

        $group = $this->request->get('group', 'ML');
        $documentId = $this->request->getInt('document_id', 0);
        $taxType = $this->request->get('tax_type', 'CIVA');
        $taxId = $this->request->get('tax_id') !== null ? $this->request->getInt('tax_id') : null;
        $limit = $this->request->getInt('limit', 150);

        if (!$documentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'document_id é obrigatório']);
            return;
        }

        try {
            $data = $this->financialService->getPerceptionsDetails(
                $group,
                $documentId,
                $taxType,
                $taxId,
                $limit
            );
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter relatório de pagamentos do período
     * GET /api/financials/payments/report?period=2024-01-01&limit=150
     */
    public function getPaymentReport(): void
    {
        header('Content-Type: application/json');

        $periodKey = $this->request->get('period', date('Y-m-01'));
        $limit = $this->request->getInt('limit', 150);
        $offset = $this->request->getInt('offset', 0);

        try {
            $data = $this->financialService->getPaymentReport($periodKey, $limit, $offset);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter detalhes de cargos de um pagamento
     * GET /api/financials/payments/{paymentId}/charges
     */
    public function getPaymentCharges(string $paymentId): void
    {
        header('Content-Type: application/json');

        $limit = $this->request->getInt('limit', 150);

        try {
            $data = $this->financialService->getPaymentChargesDetail($paymentId, $limit);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter custos de envio de um shipment
     * GET /api/financials/shipments/{shipmentId}/costs
     */
    public function getShipmentCosts(string $shipmentId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getShipmentCosts($shipmentId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter envios de uma order
     * GET /api/financials/order/{orderId}/shipments
     */
    public function getOrderShipments(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getOrderShipments($orderId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter termos de venda/comissão de um item
     * GET /api/financials/items/{itemId}/sale-terms
     */
    public function getItemSaleTerms(string $itemId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getItemSaleTerms($itemId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter relatório financeiro em tempo real
     * GET /api/financials/realtime?start=2024-01-01&end=2024-01-31
     */
    public function getRealTimeReport(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;

        try {
            $data = $this->financialService->generateRealTimeFinancialReport($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para obter top produtos com métricas financeiras
     * GET /api/financials/products/top?start=2024-01-01&end=2024-01-31&limit=20
     */
    public function getTopProductsMetrics(): void
    {
        header('Content-Type: application/json');

        $dates = $this->getValidatedDateRange();
        if (!$dates) {
            return;
        }
        [$startDate, $endDate] = $dates;
        $limit = $this->request->getInt('limit', 20);

        try {
            $data = $this->financialService->getTopProductsFinancialMetrics(
                $startDate,
                $endDate,
                $limit
            );
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * API para calcular ROI de um produto
     * POST /api/financials/products/{itemId}/roi
     * Body: { "product_cost": 50.00, "start_date": "2024-01-01", "end_date": "2024-01-31" }
     */
    public function calculateProductROI(string $itemId): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();
        $productCost = (float)($input['product_cost'] ?? 0);
        $startDate = $input['start_date'] ?? date('Y-m-01');
        $endDate = $input['end_date'] ?? date('Y-m-d');

        if ($productCost <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'product_cost é obrigatório e deve ser maior que zero',
            ]);
            return;
        }

        try {
            $data = $this->financialService->calculateProductROI(
                $itemId,
                $productCost,
                $startDate,
                $endDate
            );
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém descontos aplicados a uma ordem
     * GET /api/financials/orders/{orderId}/discounts
     */
    public function getOrderDiscounts(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getOrderDiscounts($orderId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Lista reclamações do vendedor
     * GET /api/financials/claims
     * Query: status=opened|closed, stage=claim|dispute|recontact, limit=30
     */
    public function getClaims(): void
    {
        header('Content-Type: application/json');

        $status = $this->request->get('status', 'opened');
        $stage = $this->request->get('stage');
        $limit = $this->request->getInt('limit', 30);

        try {
            $data = $this->financialService->getClaims($status, $stage, $limit);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de uma reclamação
     * GET /api/financials/claims/{claimId}
     */
    public function getClaimDetails(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getClaimDetails($claimId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Verifica impacto de reclamação na reputação
     * GET /api/financials/claims/{claimId}/reputation
     */
    public function getClaimReputationImpact(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getClaimReputationImpact($claimId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de uma devolução
     * GET /api/financials/claims/{claimId}/return
     */
    public function getReturnDetails(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getReturnDetails($claimId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém custo do frete de devolução
     * GET /api/financials/claims/{claimId}/return-cost
     * Query: calculate_usd=true|false
     */
    public function getReturnShippingCost(string $claimId): void
    {
        header('Content-Type: application/json');

        $calculateUsd = $this->request->getBool('calculate_usd');

        try {
            $data = $this->financialService->getReturnShippingCost($claimId, $calculateUsd);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Gera relatório financeiro de reclamações
     * GET /api/financials/claims/report
     * Query: start_date, end_date
     */
    public function getClaimsFinancialReport(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->getClaimsFinancialReport($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém histórico de ações de uma reclamação
     * GET /api/financials/claims/{claimId}/history
     */
    public function getClaimActionsHistory(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getClaimActionsHistory($claimId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém taxa de conversão de moeda
     * GET /api/financials/currency/conversion
     * Query: from=BRL, to=USD
     */
    public function getCurrencyConversion(): void
    {
        header('Content-Type: application/json');

        $from = $this->request->get('from', 'BRL');
        $to = $this->request->get('to', 'USD');

        try {
            $data = $this->financialService->getCurrencyConversion($from, $to);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Calcula total da ordem incluindo frete
     * GET /api/financials/orders/{orderId}/total
     */
    public function calculateOrderTotalWithShipping(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->calculateOrderTotalWithShipping($orderId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém dados financeiros completos de uma ordem
     * GET /api/financials/orders/{orderId}/complete
     */
    public function getCompleteOrderFinancialData(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getCompleteOrderFinancialData($orderId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém dados do produto de uma ordem (atributos especiais)
     * GET /api/financials/orders/{orderId}/product
     */
    public function getOrderProductData(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getOrderProductData($orderId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém reputação do vendedor
     * GET /api/financials/seller/reputation
     */
    public function getSellerReputation(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getSellerReputation();
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém total de visitas do vendedor
     * GET /api/financials/seller/visits
     * Query: start_date, end_date
     */
    public function getSellerVisits(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->getSellerTotalVisits($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém visitas do vendedor por janela de tempo
     * GET /api/financials/seller/visits/daily
     * Query: days=30
     */
    public function getSellerVisitsDaily(): void
    {
        header('Content-Type: application/json');

        $days = $this->request->getInt('days', 30);

        try {
            $data = $this->financialService->getSellerVisitsByTimeWindow($days);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém visitas de um item
     * GET /api/financials/items/{itemId}/visits
     * Query: start_date, end_date
     */
    public function getItemVisits(string $itemId): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date');
        $endDate = $this->request->get('end_date');

        try {
            if ($startDate && $endDate) {
                $data = $this->financialService->getItemVisitsByPeriod($itemId, $startDate, $endDate);
            } else {
                $data = $this->financialService->getItemVisitsTotal($itemId);
            }
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém visitas diárias de um item
     * GET /api/financials/items/{itemId}/visits/daily
     * Query: days=30
     */
    public function getItemVisitsDaily(string $itemId): void
    {
        header('Content-Type: application/json');

        $days = $this->request->getInt('days', 30);

        try {
            $data = $this->financialService->getItemVisitsByTimeWindow($itemId, $days);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém métricas de perguntas do vendedor
     * GET /api/financials/seller/questions
     * Query: start_date, end_date
     */
    public function getSellerQuestionsMetrics(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->getSellerQuestionsMetrics($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Calcula taxa de conversão
     * GET /api/financials/seller/conversion
     * Query: start_date, end_date
     */
    public function getConversionRate(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->calculateConversionRate($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Gera relatório de performance do vendedor
     * GET /api/financials/seller/performance
     * Query: start_date, end_date
     */
    public function getSellerPerformanceReport(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->generateSellerPerformanceReport($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém feedback de uma ordem
     * GET /api/financials/orders/{orderId}/feedback
     */
    public function getOrderFeedback(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getOrderFeedback($orderId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém opiniões de um produto
     * GET /api/financials/items/{itemId}/reviews
     * Query: limit=50
     */
    public function getProductReviews(string $itemId): void
    {
        header('Content-Type: application/json');

        $limit = $this->request->getInt('limit', 50);

        try {
            $data = $this->financialService->getProductReviews($itemId, $limit);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Calcula LTV (Lifetime Value) dos clientes
     * GET /api/financials/seller/ltv
     * Query: months=12
     */
    public function getCustomerLTV(): void
    {
        header('Content-Type: application/json');

        $months = $this->request->getInt('months', 12);

        try {
            $data = $this->financialService->calculateCustomerLTV($months);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de uma contestação (chargeback)
     * GET /api/financials/chargebacks/{chargebackId}
     */
    public function getChargebackDetails(string $chargebackId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getChargebackDetails($chargebackId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Busca pagamentos do Mercado Pago
     * GET /api/financials/mp/payments
     * Query: status, begin_date, end_date, limit, offset
     */
    public function searchMPPayments(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'status' => $this->request->get('status'),
            'begin_date' => $this->request->get('begin_date'),
            'end_date' => $this->request->get('end_date'),
            'limit' => $this->request->getInt('limit', 30),
            'offset' => $this->request->getInt('offset', 0),
        ];

        try {
            $data = $this->financialService->searchMPPayments(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de um pagamento MP
     * GET /api/financials/mp/payments/{paymentId}
     */
    public function getMPPaymentDetails(string $paymentId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getMPPaymentDetails($paymentId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Lista reembolsos de um pagamento
     * GET /api/financials/mp/payments/{paymentId}/refunds
     */
    public function getPaymentRefunds(string $paymentId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getPaymentRefunds($paymentId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cria um reembolso
     * POST /api/financials/mp/payments/{paymentId}/refunds
     * Body: { "amount": 50.00 } (opcional, se não informado = reembolso total)
     */
    public function createRefund(string $paymentId): void
    {
        header('Content-Type: application/json');

        try {
            $input = $this->request->json() ?? [];
            $amount = isset($input['amount']) ? (float)$input['amount'] : null;

            $data = $this->financialService->createRefund($paymentId, $amount);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Relatório de chargebacks e reembolsos
     * GET /api/financials/chargebacks/report
     * Query: start_date, end_date
     */
    public function getChargebacksRefundsReport(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->getChargebacksRefundsReport($startDate, $endDate);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Score de saúde financeira
     * GET /api/financials/seller/health-score
     * Query: start_date, end_date
     */
    public function getFinancialHealthScore(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->calculateFinancialHealthScore($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Dados fiscais de uma ordem para NF
     * GET /api/financials/orders/{orderId}/fiscal
     */
    public function getOrderFiscalData(string $orderId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getOrderFiscalData($orderId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Busca merchant orders do MP
     * GET /api/financials/mp/merchant-orders
     * Query: external_reference, limit, offset
     */
    public function searchMerchantOrders(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'external_reference' => $this->request->get('external_reference'),
            'limit' => $this->request->getInt('limit', 20),
            'offset' => $this->request->getInt('offset', 0),
        ];

        try {
            $data = $this->financialService->searchMerchantOrders(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Análise ABC (Pareto 80/20) de produtos
     * GET /api/financials/products/abc
     * Query: start_date, end_date
     */
    public function getABCAnalysis(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->calculateABCAnalysis($startDate, $endDate);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // MERCADO PAGO REPORTS - RELEASES (LIBERAÇÕES)
    // ============================================================================

    /**
     * Cria relatório de liberações
     * POST /api/financials/mp/reports/releases
     * Body: begin_date, end_date (formato: Y-m-d\TH:i:s\Z)
     */
    public function createReleasesReport(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();
        $beginDate = $input['begin_date'] ?? date('Y-m-01\T00:00:00\Z');
        $endDate = $input['end_date'] ?? date('Y-m-d\T23:59:59\Z');

        try {
            $data = $this->financialService->createReleasesReport($beginDate, $endDate);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Lista relatórios de liberações
     * GET /api/financials/mp/reports/releases
     * Query: limit, offset
     */
    public function listReleasesReports(): void
    {
        header('Content-Type: application/json');

        $limit = $this->request->getInt('limit', 50);
        $offset = $this->request->getInt('offset', 0);

        try {
            $data = $this->financialService->listReleasesReports($limit, $offset);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém status de um relatório de liberações
     * GET /api/financials/mp/reports/releases/{reportId}
     */
    public function getReleasesReportStatus(int $reportId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getReleasesReportStatus($reportId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Baixa relatório de liberações
     * GET /api/financials/mp/reports/releases/download/{fileName}
     */
    public function downloadReleasesReport(string $fileName): void
    {
        try {
            $data = $this->financialService->downloadReleasesReport($fileName);

            if (is_array($data) && isset($data['error'])) {
                header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => $data['error']]);
                return;
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            echo $data;
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém configurações do relatório de liberações
     * GET /api/financials/mp/reports/releases/config
     */
    public function getReleasesReportConfig(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getReleasesReportConfig();
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Salva configurações do relatório de liberações
     * POST/PUT /api/financials/mp/reports/releases/config
     */
    public function saveReleasesReportConfig(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();
        $update = $this->request->method() === 'PUT';

        try {
            $data = $this->financialService->saveReleasesReportConfig($input ?? [], $update);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Ativa geração automática de relatório de liberações
     * POST /api/financials/mp/reports/releases/schedule
     */
    public function enableReleasesAutoGeneration(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->enableReleasesAutoGeneration();
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Desativa geração automática de relatório de liberações
     * DELETE /api/financials/mp/reports/releases/schedule
     */
    public function disableReleasesAutoGeneration(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->disableReleasesAutoGeneration();
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // MERCADO PAGO REPORTS - SETTLEMENTS (DINHEIRO EM CONTA)
    // ============================================================================

    /**
     * Cria relatório de dinheiro em conta
     * POST /api/financials/mp/reports/settlements
     * Body: begin_date, end_date
     */
    public function createSettlementsReport(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();
        $beginDate = $input['begin_date'] ?? date('Y-m-01\T00:00:00\Z');
        $endDate = $input['end_date'] ?? date('Y-m-d\T23:59:59\Z');

        try {
            $data = $this->financialService->createSettlementsReport($beginDate, $endDate);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Lista relatórios de settlements
     * GET /api/financials/mp/reports/settlements
     */
    public function listSettlementsReports(): void
    {
        header('Content-Type: application/json');

        $limit = $this->request->getInt('limit', 50);
        $offset = $this->request->getInt('offset', 0);

        try {
            $data = $this->financialService->listSettlementsReports($limit, $offset);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém status de um relatório de settlements
     * GET /api/financials/mp/reports/settlements/{reportId}
     */
    public function getSettlementsReportStatus(int $reportId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getSettlementsReportStatus($reportId);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Baixa relatório de settlements
     * GET /api/financials/mp/reports/settlements/download/{fileName}
     */
    public function downloadSettlementsReport(string $fileName): void
    {
        try {
            $data = $this->financialService->downloadSettlementsReport($fileName);

            if (is_array($data) && isset($data['error'])) {
                header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => $data['error']]);
                return;
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            echo $data;
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém configurações do relatório de settlements
     * GET /api/financials/mp/reports/settlements/config
     */
    public function getSettlementsReportConfig(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getSettlementsReportConfig();
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Salva configurações do relatório de settlements
     * POST/PUT /api/financials/mp/reports/settlements/config
     */
    public function saveSettlementsReportConfig(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();
        $update = $this->request->method() === 'PUT';

        try {
            $data = $this->financialService->saveSettlementsReportConfig($input ?? [], $update);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Ativa geração automática de relatório de settlements
     * POST /api/financials/mp/reports/settlements/schedule
     */
    public function enableSettlementsAutoGeneration(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->enableSettlementsAutoGeneration();
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Desativa geração automática de relatório de settlements
     * DELETE /api/financials/mp/reports/settlements/schedule
     */
    public function disableSettlementsAutoGeneration(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->disableSettlementsAutoGeneration();
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // CONSOLIDATED REPORTS & ANALYTICS
    // ============================================================================

    /**
     * Gera relatórios consolidados de MP (releases + settlements)
     * POST /api/financials/mp/reports/consolidated
     */
    public function generateConsolidatedMPReports(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();
        $beginDate = $input['begin_date'] ?? date('Y-m-01\T00:00:00\Z');
        $endDate = $input['end_date'] ?? date('Y-m-d\T23:59:59\Z');

        try {
            $data = $this->financialService->generateConsolidatedMPReports($beginDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Verifica relatórios pendentes
     * GET /api/financials/mp/reports/pending
     */
    public function checkPendingReports(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->checkPendingReports();
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém relatórios prontos para download
     * GET /api/financials/mp/reports/ready
     */
    public function getReadyReports(): void
    {
        header('Content-Type: application/json');

        $limit = $this->request->getInt('limit', 20);

        try {
            $data = $this->financialService->getReadyReports($limit);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // FINANCIAL FORECASTING & GOALS
    // ============================================================================

    /**
     * Projeção financeira baseada em tendências
     * GET /api/financials/forecast
     * Query: months_ahead (default: 3)
     */
    /**
     * POST /api/debug/agent-log — client-side debug trace (session required).
     */
    public function agentDebugLog(): void
    {
        header('Content-Type: application/json');

        if (!SessionHelper::getUserId()) {
            http_response_code(401);
            echo json_encode(['ok' => false]);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }

        DebugAgentLog::write(
            (string) ($body['hypothesisId'] ?? 'X'),
            (string) ($body['location'] ?? 'client'),
            (string) ($body['message'] ?? ''),
            is_array($body['data'] ?? null) ? $body['data'] : []
        );

        echo json_encode(['ok' => true]);
    }

    public function getFinancialForecast(): void
    {
        header('Content-Type: application/json');

        $monthsAhead = $this->request->getInt('months_ahead', 3);
        $resolvedAccountId = SessionHelper::getActiveAccountId();

        // #region agent log
        DebugAgentLog::write('C', 'FinancialReportController::getFinancialForecast', 'forecast_request', [
            'resolvedAccountId' => $resolvedAccountId,
            'userId' => SessionHelper::getUserId(),
            'monthsAhead' => $monthsAhead,
        ]);
        // #endregion

        try {
            $forecastService = new \App\Services\Financial\FinancialForecastService($resolvedAccountId);
            $data = $forecastService->calculateFinancialForecast($monthsAhead);

            // #region agent log
            DebugAgentLog::write('D', 'FinancialReportController::getFinancialForecast', 'forecast_response', [
                'hasError' => isset($data['error']),
                'historyMonths' => isset($data['historical_data']) ? count($data['historical_data']) : 0,
                'projectionCount' => isset($data['projections']) ? count($data['projections']) : 0,
            ]);
            // #endregion

            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            // #region agent log
            DebugAgentLog::write('G', 'FinancialReportController::getFinancialForecast', 'forecast_exception', [
                'message' => $e->getMessage(),
            ]);
            // #endregion
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Progresso de metas financeiras
     * GET /api/financials/goals/progress
     * Query: monthly_target
     */
    public function getGoalProgress(): void
    {
        header('Content-Type: application/json');

        $monthlyTarget = $this->request->getFloat('monthly_target', 0);

        if ($monthlyTarget <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'monthly_target é obrigatório e deve ser maior que zero',
            ]);
            return;
        }

        try {
            $data = $this->financialService->calculateGoalProgress($monthlyTarget);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // WITHDRAWALS & BALANCE
    // ============================================================================

    /**
     * Histórico de saques/transferências
     * GET /api/financials/mp/withdrawals
     */
    public function getWithdrawalHistory(): void
    {
        header('Content-Type: application/json');

        $limit = $this->request->getInt('limit', 20);
        $offset = $this->request->getInt('offset', 0);

        try {
            $data = $this->financialService->getWithdrawalHistory($limit, $offset);
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // FINANCIAL ALERTS
    // ============================================================================

    /**
     * Verifica alertas financeiros
     * GET /api/financials/alerts
     */
    public function checkFinancialAlerts(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->checkFinancialAlerts();
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // PERIOD COMPARISONS
    // ============================================================================

    /**
     * Comparação detalhada entre dois períodos
     * POST /api/financials/compare
     * Body: period1_start, period1_end, period2_start, period2_end
     */
    public function compareFinancialPeriods(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        $required = ['period1_start', 'period1_end', 'period2_start', 'period2_end'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "Campo obrigatório: {$field}",
                ]);
                return;
            }
        }

        try {
            $data = $this->financialService->compareFinancialPeriods(
                $input['period1_start'],
                $input['period1_end'],
                $input['period2_start'],
                $input['period2_end']
            );
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // SUBSCRIPTIONS (ASSINATURAS)
    // ============================================================================

    /**
     * Cria uma nova assinatura
     * POST /api/financials/mp/subscriptions
     */
    public function createSubscription(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        if (empty($input['payer_email'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'payer_email é obrigatório']);
            return;
        }

        // Validação: precisa ter plano OU configuração manual
        if (empty($input['preapproval_plan_id']) && empty($input['reason'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Informe preapproval_plan_id OU reason com transaction_amount',
            ]);
            return;
        }

        try {
            $data = $this->financialService->createSubscription($input);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Busca assinaturas
     * GET /api/financials/mp/subscriptions
     */
    public function searchSubscriptions(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'status' => $this->request->get('status'),
            'payer_email' => $this->request->get('payer_email'),
            'payer_id' => $this->request->get('payer_id'),
            'preapproval_plan_id' => $this->request->get('plan_id'),
            'offset' => $this->request->getInt('offset', 0),
            'limit' => $this->request->getInt('limit', 50),
        ];

        try {
            $data = $this->financialService->searchSubscriptions(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de uma assinatura
     * GET /api/financials/mp/subscriptions/{subscriptionId}
     */
    public function getSubscription(string $subscriptionId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getSubscription($subscriptionId);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Atualiza uma assinatura
     * PUT /api/financials/mp/subscriptions/{subscriptionId}
     */
    public function updateSubscription(string $subscriptionId): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        try {
            $data = $this->financialService->updateSubscription($subscriptionId, $input);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Pausa uma assinatura
     * POST /api/financials/mp/subscriptions/{subscriptionId}/pause
     */
    public function pauseSubscription(string $subscriptionId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->pauseSubscription($subscriptionId);
            echo json_encode([
                'success' => ($data['status'] ?? '') === 'paused',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Reativa uma assinatura
     * POST /api/financials/mp/subscriptions/{subscriptionId}/activate
     */
    public function activateSubscription(string $subscriptionId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->activateSubscription($subscriptionId);
            echo json_encode([
                'success' => ($data['status'] ?? '') === 'authorized',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cancela uma assinatura
     * POST /api/financials/mp/subscriptions/{subscriptionId}/cancel
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->cancelSubscription($subscriptionId);
            echo json_encode([
                'success' => ($data['status'] ?? '') === 'cancelled',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Exporta assinaturas
     * GET /api/financials/mp/subscriptions/export
     */
    public function exportSubscriptions(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'status' => $this->request->get('status'),
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
        ];

        try {
            $data = $this->financialService->exportSubscriptions(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // SUBSCRIPTION PLANS (PLANOS DE ASSINATURA)
    // ============================================================================

    /**
     * Cria um plano de assinatura
     * POST /api/financials/mp/subscription-plans
     */
    public function createSubscriptionPlan(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        if (empty($input['reason']) || empty($input['transaction_amount'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'reason e transaction_amount são obrigatórios',
            ]);
            return;
        }

        try {
            $data = $this->financialService->createSubscriptionPlan($input);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Busca planos de assinatura
     * GET /api/financials/mp/subscription-plans
     */
    public function searchSubscriptionPlans(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'status' => $this->request->get('status'),
            'offset' => $this->request->getInt('offset', 0),
            'limit' => $this->request->getInt('limit', 50),
        ];

        try {
            $data = $this->financialService->searchSubscriptionPlans(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de um plano
     * GET /api/financials/mp/subscription-plans/{planId}
     */
    public function getSubscriptionPlan(string $planId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getSubscriptionPlan($planId);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Atualiza um plano
     * PUT /api/financials/mp/subscription-plans/{planId}
     */
    public function updateSubscriptionPlan(string $planId): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        try {
            $data = $this->financialService->updateSubscriptionPlan($planId, $input);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // SUBSCRIPTION INVOICES (FATURAS DE ASSINATURA)
    // ============================================================================

    /**
     * Busca faturas de assinaturas
     * GET /api/financials/mp/subscription-invoices
     */
    public function searchSubscriptionInvoices(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'preapproval_id' => $this->request->get('subscription_id'),
            'status' => $this->request->get('status'),
            'offset' => $this->request->getInt('offset', 0),
            'limit' => $this->request->getInt('limit', 50),
        ];

        try {
            $data = $this->financialService->searchSubscriptionInvoices(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de uma fatura
     * GET /api/financials/mp/subscription-invoices/{invoiceId}
     */
    public function getSubscriptionInvoice(string $invoiceId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getSubscriptionInvoice($invoiceId);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // RECURRING REVENUE ANALYSIS (MRR/ARR)
    // ============================================================================

    /**
     * Análise de receita recorrente
     * GET /api/financials/mp/recurring-revenue
     */
    public function getRecurringRevenueAnalysis(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getRecurringRevenueAnalysis();
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cálculo de churn de assinaturas
     * GET /api/financials/mp/subscription-churn
     */
    public function calculateSubscriptionChurn(): void
    {
        header('Content-Type: application/json');

        $month = $this->request->get('month', date('Y-m'));

        try {
            $data = $this->financialService->calculateSubscriptionChurn($month);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // CUSTOMERS (CLIENTES MP)
    // ============================================================================

    /**
     * Cria um cliente
     * POST /api/financials/mp/customers
     */
    public function createCustomer(): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        if (empty($input['email'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'email é obrigatório']);
            return;
        }

        try {
            $data = $this->financialService->createCustomer($input);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Busca clientes
     * GET /api/financials/mp/customers
     */
    public function searchCustomers(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'email' => $this->request->get('email'),
            'offset' => $this->request->getInt('offset', 0),
            'limit' => $this->request->getInt('limit', 50),
        ];

        try {
            $data = $this->financialService->searchCustomers(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de um cliente
     * GET /api/financials/mp/customers/{customerId}
     */
    public function getCustomer(string $customerId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getCustomer($customerId);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Atualiza um cliente
     * PUT /api/financials/mp/customers/{customerId}
     */
    public function updateCustomer(string $customerId): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        try {
            $data = $this->financialService->updateCustomer($customerId, $input);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // CUSTOMER CARDS (CARTÕES DE CLIENTES)
    // ============================================================================

    /**
     * Salva um cartão para cliente
     * POST /api/financials/mp/customers/{customerId}/cards
     */
    public function saveCustomerCard(string $customerId): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        if (empty($input['token'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'token é obrigatório']);
            return;
        }

        try {
            $data = $this->financialService->saveCustomerCard($customerId, $input['token']);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Lista cartões de um cliente
     * GET /api/financials/mp/customers/{customerId}/cards
     */
    public function getCustomerCards(string $customerId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getCustomerCards($customerId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de um cartão
     * GET /api/financials/mp/customers/{customerId}/cards/{cardId}
     */
    public function getCustomerCard(string $customerId, string $cardId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getCustomerCard($customerId, $cardId);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove um cartão
     * DELETE /api/financials/mp/customers/{customerId}/cards/{cardId}
     */
    public function deleteCustomerCard(string $customerId, string $cardId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->deleteCustomerCard($customerId, $cardId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // CLAIMS (RECLAMAÇÕES MP)
    // ============================================================================

    /**
     * Busca reclamações
     * GET /api/financials/mp/claims
     */
    public function searchClaims(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'status' => $this->request->get('status'),
            'resource_id' => $this->request->get('resource_id'),
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
            'offset' => $this->request->getInt('offset', 0),
            'limit' => $this->request->getInt('limit', 50),
        ];

        try {
            $data = $this->financialService->searchClaims(array_filter($filters));
            echo json_encode([
                'success' => !isset($data['error']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém detalhes de uma reclamação
     * GET /api/financials/mp/claims/{claimId}
     */
    public function getClaim(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getClaim($claimId);
            echo json_encode([
                'success' => isset($data['id']),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém histórico de uma reclamação
     * GET /api/financials/mp/claims/{claimId}/history
     */
    public function getClaimHistory(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getClaimHistory($claimId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém mensagens de uma reclamação
     * GET /api/financials/mp/claims/{claimId}/messages
     */
    public function getClaimMessages(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getClaimMessages($claimId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Envia mensagem em uma reclamação
     * POST /api/financials/mp/claims/{claimId}/messages
     */
    public function sendClaimMessage(string $claimId): void
    {
        header('Content-Type: application/json');

        $input = $this->request->json();

        if (empty($input['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'message é obrigatório']);
            return;
        }

        try {
            $data = $this->financialService->sendClaimMessage(
                $claimId,
                $input['message'],
                $input['attachments'] ?? []
            );
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Solicita mediação
     * POST /api/financials/mp/claims/{claimId}/mediation
     */
    public function requestClaimMediation(string $claimId): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->requestClaimMediation($claimId);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Análise de performance de reclamações
     * GET /api/financials/mp/claims/analysis
     */
    public function analyzeClaimsPerformance(): void
    {
        header('Content-Type: application/json');

        $startDate = $this->request->get('start_date', date('Y-m-01'));
        $endDate = $this->request->get('end_date', date('Y-m-d'));

        try {
            $data = $this->financialService->analyzeClaimsPerformance($startDate, $endDate);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // PAYMENT METHODS & IDENTIFICATION
    // ============================================================================

    /**
     * Obtém meios de pagamento disponíveis
     * GET /api/financials/mp/payment-methods
     */
    public function getPaymentMethods(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getPaymentMethods();
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtém tipos de documento de identificação
     * GET /api/financials/mp/identification-types
     */
    public function getIdentificationTypes(): void
    {
        header('Content-Type: application/json');

        try {
            $data = $this->financialService->getIdentificationTypes();
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ============================================================================
    // CONSOLIDATED DASHBOARD
    // ============================================================================

    /**
     * Dashboard financeiro consolidado
     * GET /api/financials/dashboard
     */
    public function getConsolidatedDashboard(): void
    {
        header('Content-Type: application/json');

        $period = $this->request->get('period', 'month'); // today, week, month, year

        try {
            $data = $this->financialService->getConsolidatedFinancialDashboard($period);
            echo json_encode([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
