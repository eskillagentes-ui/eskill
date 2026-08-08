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

    private ?FinancialLedgerService $ledgerServiceInstance = null;

    private function ledger(): FinancialLedgerService
    {
        return $this->ledgerServiceInstance ??= new FinancialLedgerService($this->db);
    }

    /**
     * Despesas de billing sem pedido (ex.: Product Ads) no período, via ledger.
     * Não vêm de ml_orders — só existem no livro financeiro (BillingChargeIngestionService).
     * Retorna 0.0 quando accountId é nulo (não fabrica soma cross-conta).
     */
    private function getAdvertisingExpenses(string $startBound, string $endBound): float
    {
        if (!$this->accountId) {
            return 0.0;
        }

        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(signed_amount), 0) AS total
             FROM financial_ledger_entries
             WHERE account_id = :account_id
               AND entry_category = :category
               AND occurred_at BETWEEN :start AND :end
               AND status NOT IN (\'cancelled\', \'rejected\')'
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':category' => FinancialEntryCategory::ADVERTISING,
            ':start' => $startBound,
            ':end' => $endBound,
        ]);
        $signedTotal = (float)$stmt->fetchColumn();

        // signed_amount é negativo para débito (custo). Expõe como despesa
        // positiva (custo líquido) — se houver mais reversão que cobrança,
        // signedTotal fica positivo e a "despesa" vira negativa (crédito líquido).
        return round(-1 * $signedTotal, 2);
    }

    /**
     * Resumo de caixa do ledger no período (liberado / pendente / sacado / hold).
     *
     * @return array{
     *   released_amount: float,
     *   pending_release_amount: float,
     *   withdrawn_amount: float,
     *   hold_amount: float,
     *   released_not_withdrawn: float,
     *   marketplace_net: float,
     *   entries_count: int
     * }
     */
    private function getCashSummaryFromLedger(string $startBound, string $endBound): array
    {
        $empty = [
            'released_amount' => 0.0,
            'pending_release_amount' => 0.0,
            'withdrawn_amount' => 0.0,
            'hold_amount' => 0.0,
            'released_not_withdrawn' => 0.0,
            'marketplace_net' => 0.0,
            'entries_count' => 0,
        ];
        if (!$this->accountId) {
            return $empty;
        }

        $summary = $this->ledger()->summarizePeriod(
            $this->accountId,
            substr($startBound, 0, 10),
            substr($endBound, 0, 10)
        );

        $released = (float)$summary['released_amount'];
        $withdrawn = (float)$summary['withdrawn_amount'];

        return [
            'released_amount' => $released,
            'pending_release_amount' => (float)$summary['pending_release_amount'],
            'withdrawn_amount' => $withdrawn,
            'hold_amount' => (float)$summary['hold_amount'],
            'released_not_withdrawn' => round(max(0.0, $released - $withdrawn), 2),
            'marketplace_net' => (float)$summary['marketplace_net'],
            'entries_count' => (int)$summary['entries_count'],
        ];
    }

    /**
     * Normaliza intervalo Y-m-d para incluir o dia final inteiro (MySQL trata 'Y-m-d' como 00:00:00).
     *
     * @return array{0: string, 1: string}
     */
    private function boundDateTimeRange(string $startDate, string $endDate): array
    {
        $start = strlen($startDate) === 10 ? $startDate . ' 00:00:00' : $startDate;
        $end = strlen($endDate) === 10 ? $endDate . ' 23:59:59' : $endDate;

        return [$start, $end];
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
        [$startBound, $endBound] = $this->boundDateTimeRange($startDate, $endDate);
        $whereConditions = ['date_created BETWEEN :start AND :end', "status IN ('paid', 'delivered')"];
        $params = [':start' => $startBound, ':end' => $endBound];

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
                    COALESCE(AVG(gross_margin), 0) as avg_margin,
                    COALESCE(SUM((
                        SELECT COALESCE(SUM(jt.qty), 0)
                        FROM JSON_TABLE(
                            ml_orders.order_data,
                            '$.order_items[*]' COLUMNS (qty INT PATH '$.quantity')
                        ) AS jt
                    )), 0) as units_sold
                FROM ml_orders
                WHERE {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calcular valores derivados (base ml_orders — fallback se ledger sem cobertura)
        $grossRevenue = (float)($data['gross_revenue'] ?? 0);
        $taxes = (float)($data['taxes'] ?? 0);
        $netRevenue = $grossRevenue - $taxes;

        $commissions = (float)($data['commissions'] ?? 0);
        $paymentFees = (float)($data['payment_fees'] ?? 0);
        $fixedFees = (float)($data['fixed_fees'] ?? 0);
        $shippingCost = (float)($data['shipping_cost'] ?? 0);
        $discounts = (float)($data['discounts'] ?? 0);
        $cogs = (float)($data['cogs'] ?? 0);
        $netProfit = (float)($data['net_profit'] ?? 0);
        $source = 'ml_orders';
        $cogsSource = $cogs > 0 ? 'ml_orders' : 'none';

        // Quando ml_orders.product_cost está zerado, estima CMV via sku_custos (mlb_id × qty).
        if ($cogs <= 0.0) {
            $estimatedCogs = $this->estimateCogsFromSkuCustos($startBound, $endBound);
            if ($estimatedCogs > 0.0) {
                $cogs = $estimatedCogs;
                $cogsSource = 'sku_custos';
            }
        }

        if ($cogsSource === 'sku_custos' || ($netProfit === 0.0 && $grossRevenue > 0)) {
            $totalCosts = $commissions + $paymentFees + $fixedFees + $shippingCost + $cogs;
            $netProfit = $netRevenue - $totalCosts - $discounts;
        }

        // PATCH 6: preferir livro financeiro para linhas operacionais do marketplace.
        $ledgerOps = $this->getOperationalPnLFromLedger($startBound, $endBound);
        if ($ledgerOps !== null) {
            $grossRevenue = $ledgerOps['gross_revenue'];
            $commissions = $ledgerOps['commissions'];
            $paymentFees = $ledgerOps['payment_fees'];
            $shippingCost = $ledgerOps['shipping_cost'];
            $discounts = $ledgerOps['discounts'];
            $fixedFees = $ledgerOps['fixed_fees'];
            $netRevenue = $grossRevenue - $taxes;
            // marketplace_net do ledger = receita − fees − frete − descontos − refunds posted (+ proteção).
            // CMV/impostos continuam em ml_orders (ou sku_custos); ads billing sem pedido entra abaixo.
            $netProfit = $ledgerOps['marketplace_net'] - $cogs - $taxes;
            $source = 'ledger';
        }

        $advertisingExpenses = $this->getAdvertisingExpenses($startBound, $endBound);
        $netProfit -= $advertisingExpenses;

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
            'cogs_source' => $cogsSource,
            'commissions' => round($commissions, 2),
            'payment_fees' => round($paymentFees, 2),
            'fixed_fees' => round($fixedFees, 2),
            'shipping_cost' => round($shippingCost, 2),
            'discounts' => round($discounts, 2),
            'advertising_expenses' => round($advertisingExpenses, 2),
            'net_profit' => round($netProfit, 2),
            'avg_margin' => round($avgMargin, 2),
            'units_sold' => (int)($data['units_sold'] ?? 0),
            'source' => $source,
            'cash' => $this->getCashSummaryFromLedger($startBound, $endBound),
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
        ];
    }

    /**
     * Estima CMV do período a partir de sku_custos × quantidade dos itens do pedido.
     * Usado quando ml_orders.product_cost está vazio/zerado.
     *
     * Evita JOIN SQL entre JSON_TABLE e sku_custos (collations incompatíveis):
     * extrai linhas do pedido e resolve custos em PHP (mesmo padrão de OrderFinancialService).
     */
    private function estimateCogsFromSkuCustos(string $startBound, string $endBound): float
    {
        if (!$this->accountId) {
            return 0.0;
        }

        $where = [
            'o.date_created BETWEEN :start AND :end',
            "o.status IN ('paid', 'delivered')",
            'o.ml_account_id = :account_id',
        ];
        $params = [
            ':start' => $startBound,
            ':end' => $endBound,
            ':account_id' => $this->accountId,
        ];
        $userId = SessionHelper::getUserId();
        if ($userId) {
            $where[] = 'o.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        try {
            $sql = 'SELECT jt.item_id AS item_id, jt.qty AS qty
                    FROM ml_orders o
                    INNER JOIN JSON_TABLE(
                        o.order_data,
                        \'$.order_items[*]\' COLUMNS (
                            item_id VARCHAR(32) PATH \'$.item.id\',
                            qty INT PATH \'$.quantity\'
                        )
                    ) AS jt
                    WHERE ' . implode(' AND ', $where);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            /** @var list<array{item_id: ?string, qty: int|string|null}> $lines */
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($lines === []) {
                return 0.0;
            }

            $itemIds = [];
            foreach ($lines as $line) {
                $itemId = trim((string)($line['item_id'] ?? ''));
                if ($itemId !== '') {
                    $itemIds[$itemId] = true;
                }
            }
            $itemIds = array_keys($itemIds);
            if ($itemIds === []) {
                return 0.0;
            }

            $costByMlb = $this->loadSkuCustoProdutoMap($itemIds);
            if ($costByMlb === []) {
                return 0.0;
            }

            $cogs = 0.0;
            foreach ($lines as $line) {
                $itemId = trim((string)($line['item_id'] ?? ''));
                $qty = (int)($line['qty'] ?? 0);
                if ($itemId === '' || $qty <= 0 || !isset($costByMlb[$itemId])) {
                    continue;
                }
                $cogs += $qty * $costByMlb[$itemId];
            }

            return round($cogs, 2);
        } catch (\Throwable $e) {
            log_error('PnlReportService: falha ao estimar CMV via sku_custos', [
                'account_id' => $this->accountId,
                'start' => $startBound,
                'end' => $endBound,
                'error' => $e->getMessage(),
            ]);

            return 0.0;
        }
    }

    /**
     * @param list<string> $itemIds
     * @return array<string, float> mlb_id => custo_produto
     */
    private function loadSkuCustoProdutoMap(array $itemIds): array
    {
        if ($itemIds === [] || !$this->accountId) {
            return [];
        }

        $map = [];
        foreach (array_chunk($itemIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare(
                "SELECT mlb_id, custo_produto
                 FROM sku_custos
                 WHERE account_id = ?
                   AND custo_produto > 0
                   AND mlb_id IN ({$placeholders})"
            );
            $stmt->execute([$this->accountId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mlbId = trim((string)($row['mlb_id'] ?? ''));
                if ($mlbId === '') {
                    continue;
                }
                $map[$mlbId] = (float)$row['custo_produto'];
            }
        }

        return $map;
    }

    /**
     * Agrega P&L operacional do ledger para pedidos paid/delivered do período.
     * Retorna null se não houver cobertura (fallback ml_orders).
     *
     * @return array{
     *   gross_revenue: float,
     *   commissions: float,
     *   payment_fees: float,
     *   fixed_fees: float,
     *   shipping_cost: float,
     *   discounts: float,
     *   marketplace_net: float,
     *   orders_with_ledger: int
     * }|null
     */
    private function getOperationalPnLFromLedger(string $startBound, string $endBound): ?array
    {
        if (!$this->accountId) {
            return null;
        }

        $where = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
            'ml_account_id = :account_id',
        ];
        $params = [
            ':start' => $startBound,
            ':end' => $endBound,
            ':account_id' => $this->accountId,
        ];
        $userId = SessionHelper::getUserId();
        if ($userId) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $stmt = $this->db->prepare(
            'SELECT CAST(ml_order_id AS CHAR) AS order_id
             FROM ml_orders
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $orderIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $oid = (string)($row['order_id'] ?? '');
            if ($oid !== '') {
                $orderIds[] = $oid;
            }
        }
        if ($orderIds === []) {
            return null;
        }

        $summaries = $this->ledger()->summarizeOrders($this->accountId, $orderIds);
        $withLedger = 0;
        $gross = 0.0;
        $commissions = 0.0;
        $paymentFees = 0.0;
        $shipping = 0.0;
        $discounts = 0.0;
        $marketplaceNet = 0.0;

        foreach ($orderIds as $oid) {
            $sum = $summaries[$oid] ?? null;
            if ($sum === null || empty($sum['has_ledger'])) {
                continue;
            }
            $withLedger++;
            $byType = $sum['by_type'] ?? [];
            $gross += (float)($byType[FinancialEntryType::SALE_REVENUE] ?? 0);
            $commissions += abs((float)($byType[FinancialEntryType::SALE_FEE] ?? 0));
            $paymentFees += abs((float)($byType[FinancialEntryType::PAYMENT_FEE] ?? 0));
            $shipping += abs((float)($byType[FinancialEntryType::SHIPPING_COST] ?? 0));
            $discounts += abs((float)($byType[FinancialEntryType::COMMERCIAL_DISCOUNT] ?? 0));
            $marketplaceNet += (float)($sum['marketplace_net'] ?? 0);
        }

        // Exige cobertura >= 80% dos pedidos do período para não misturar bases.
        $coverage = $withLedger / max(1, count($orderIds));
        if ($coverage < 0.8) {
            return null;
        }

        return [
            'gross_revenue' => round($gross, 2),
            'commissions' => round($commissions, 2),
            'payment_fees' => round($paymentFees, 2),
            'fixed_fees' => 0.0,
            'shipping_cost' => round($shipping, 2),
            'discounts' => round($discounts, 2),
            'marketplace_net' => round($marketplaceNet, 2),
            'orders_with_ledger' => $withLedger,
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
        [$startBound, $endBound] = $this->boundDateTimeRange($startDate, $endDate);

        $ledgerDaily = $this->getDailyRevenueFromLedger($startBound, $endBound);
        if ($ledgerDaily !== null) {
            return $ledgerDaily;
        }

        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startBound, ':end' => $endBound];

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
            'source' => 'ml_orders',
        ], $rows);
    }

    /**
     * Receita/lucro diários a partir do ledger (pedidos paid/delivered do período).
     *
     * @return list<array{date: string, revenue: float, profit: float, orders: int, source: string}>|null
     */
    private function getDailyRevenueFromLedger(string $startBound, string $endBound): ?array
    {
        if (!$this->accountId) {
            return null;
        }

        $where = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
            'ml_account_id = :account_id',
        ];
        $params = [
            ':start' => $startBound,
            ':end' => $endBound,
            ':account_id' => $this->accountId,
        ];
        $userId = SessionHelper::getUserId();
        if ($userId) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $stmt = $this->db->prepare(
            'SELECT CAST(ml_order_id AS CHAR) AS order_id,
                    DATE(date_created) AS day,
                    COALESCE(product_cost, 0) AS product_cost,
                    COALESCE(taxes, 0) AS taxes
             FROM ml_orders
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY date_created ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return null;
        }

        $orderIds = [];
        $metaByOrder = [];
        foreach ($rows as $row) {
            $oid = (string)$row['order_id'];
            if ($oid === '') {
                continue;
            }
            $orderIds[] = $oid;
            $metaByOrder[$oid] = [
                'day' => (string)$row['day'],
                'product_cost' => (float)$row['product_cost'],
                'taxes' => (float)$row['taxes'],
            ];
        }
        if ($orderIds === []) {
            return null;
        }

        $summaries = $this->ledger()->summarizeOrders($this->accountId, $orderIds);
        $withLedger = 0;
        foreach ($orderIds as $oid) {
            if (!empty($summaries[$oid]['has_ledger'])) {
                $withLedger++;
            }
        }
        if (($withLedger / max(1, count($orderIds))) < 0.8) {
            return null;
        }

        /** @var array<string, array{revenue: float, marketplace_net: float, cogs: float, taxes: float, orders: int}> $byDay */
        $byDay = [];
        foreach ($orderIds as $oid) {
            $meta = $metaByOrder[$oid];
            $day = $meta['day'];
            if (!isset($byDay[$day])) {
                $byDay[$day] = [
                    'revenue' => 0.0,
                    'marketplace_net' => 0.0,
                    'cogs' => 0.0,
                    'taxes' => 0.0,
                    'orders' => 0,
                ];
            }
            $sum = $summaries[$oid] ?? null;
            $byDay[$day]['orders']++;
            $byDay[$day]['cogs'] += $meta['product_cost'];
            $byDay[$day]['taxes'] += $meta['taxes'];
            if ($sum !== null && !empty($sum['has_ledger'])) {
                $byDay[$day]['revenue'] += (float)(($sum['by_type'][FinancialEntryType::SALE_REVENUE] ?? 0));
                $byDay[$day]['marketplace_net'] += (float)($sum['marketplace_net'] ?? 0);
            }
        }

        ksort($byDay);
        $out = [];
        foreach ($byDay as $day => $agg) {
            $profit = $agg['marketplace_net'] - $agg['cogs'] - $agg['taxes'];
            $out[] = [
                'date' => $day,
                'revenue' => round($agg['revenue'], 2),
                'profit' => round($profit, 2),
                'orders' => $agg['orders'],
                'source' => 'ledger',
            ];
        }

        return $out;
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
        [$startBound, $endBound] = $this->boundDateTimeRange($startDate, $endDate);
        $ledgerCash = $this->getCashSummaryFromLedger($startBound, $endBound);

        // PATCH 8: quando há movimentos de caixa no ledger, o fluxo canônico é liberado/sacado/hold/ads.
        if ($this->accountId && (int)$ledgerCash['entries_count'] > 0
            && ((float)$ledgerCash['released_amount'] > 0
                || (float)$ledgerCash['pending_release_amount'] > 0
                || (float)$ledgerCash['withdrawn_amount'] > 0
                || (float)$ledgerCash['hold_amount'] > 0)
        ) {
            $ads = $this->getAdvertisingExpenses($startBound, $endBound);
            $inTotal = (float)$ledgerCash['released_amount'];
            $outTotal = (float)$ledgerCash['withdrawn_amount']
                + (float)$ledgerCash['hold_amount']
                + max(0.0, $ads);

            return [
                'inflows' => [
                    'released' => round((float)$ledgerCash['released_amount'], 2),
                    'pending_release' => round((float)$ledgerCash['pending_release_amount'], 2),
                    'sales' => round((float)$ledgerCash['released_amount'], 2),
                    'transactions' => (int)$ledgerCash['entries_count'],
                    'total' => round($inTotal, 2),
                ],
                'outflows' => [
                    'withdrawals' => round((float)$ledgerCash['withdrawn_amount'], 2),
                    'holds' => round((float)$ledgerCash['hold_amount'], 2),
                    'advertising' => round(max(0.0, $ads), 2),
                    'total' => round($outTotal, 2),
                ],
                'balance' => round($inTotal - $outTotal, 2),
                'released_not_withdrawn' => round((float)$ledgerCash['released_not_withdrawn'], 2),
                'source' => 'ledger',
                'ledger_cash' => $ledgerCash,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
            ];
        }

        $inflows = $this->getInflows($startDate, $endDate);
        $outflows = $this->getOutflows($startDate, $endDate);
        $balance = $inflows['total'] - $outflows['total'];

        return [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'balance' => round($balance, 2),
            'source' => 'ml_orders',
            'ledger_cash' => $ledgerCash,
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
        [$startBound, $endBound] = $this->boundDateTimeRange($startDate, $endDate);
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startBound, ':end' => $endBound];

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
        [$startBound, $endBound] = $this->boundDateTimeRange($startDate, $endDate);
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered')",
        ];
        $params = [':start' => $startBound, ':end' => $endBound];

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

        // Taxa de conversão de custos (inclui ads do ledger quando presentes)
        $ads = (float)($pnl['advertising_expenses'] ?? 0);
        $costRate = $pnl['gross_revenue'] > 0
            ? (($pnl['commissions'] + $pnl['payment_fees'] + $pnl['fixed_fees'] + $ads) / $pnl['gross_revenue']) * 100
            : 0;

        // ROI
        $totalCosts = $pnl['cogs'] + $pnl['commissions'] + $pnl['payment_fees'] + $pnl['fixed_fees'] + $pnl['shipping_cost'] + $ads;
        $roi = $totalCosts > 0
            ? (($pnl['net_profit'] / $totalCosts) * 100)
            : 0;

        return [
            'total_orders' => $pnl['total_orders'],
            'gross_revenue' => $pnl['gross_revenue'],
            'net_profit' => $pnl['net_profit'],
            'advertising_expenses' => $ads,
            'avg_ticket' => round($avgTicket, 2),
            'avg_margin' => $pnl['avg_margin'],
            'cost_rate' => round($costRate, 2),
            'roi' => round($roi, 2),
            'cash' => $pnl['cash'] ?? null,
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

        // Host correto: api.mercadopago.com. ML /mercadopago_account/balance → 403 e polui logs.
        $response = $this->mpGet('/v1/account/balance');

        if (isset($response['error'])) {
            return [
                'error' => 'Saldo MP indisponível com o OAuth atual ('
                    . (string) ($response['message'] ?? $response['error'])
                    . ')',
                'available_balance' => 0,
                'total_amount' => 0,
                'unavailable_balance' => 0,
                'api_blocked' => true,
                'source' => 'mp_account_balance',
            ];
        }

        return [
            'available_balance' => (float) ($response['available_balance'] ?? 0),
            'total_amount' => (float) ($response['total_amount'] ?? 0),
            'unavailable_balance' => (float) ($response['unavailable_balance'] ?? $response['blocked_balance'] ?? 0),
            'currency_id' => $response['currency_id'] ?? 'BRL',
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'mp_account_balance',
            'api_blocked' => false,
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
