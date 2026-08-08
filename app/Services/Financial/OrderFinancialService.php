<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Helpers\SessionHelper;
use PDO;

/**
 * Order Financial Service
 *
 * Servi\u00e7o para opera\u00e7\u00f5es financeiras relacionadas a pedidos.
 * Extra\u00eddo de FinancialService para responsabilidade \u00fanica.
 */
class OrderFinancialService
{
    use HasFinancialDependencies;

    private ?PnlReportService $pnlReportServiceInstance = null;
    private ?FeeCommissionService $feeCommissionServiceInstance = null;
    private ?PaymentRefundService $paymentRefundServiceInstance = null;

    /** @var array<int|string, float> cache request-scoped de frete do vendedor por shipment_id */
    private array $sellerShippingCostCache = [];

    private ?FinancialLedgerService $ledgerServiceInstance = null;
    private ?FinancialReconciliationService $reconciliationServiceInstance = null;

    private function pnlReport(): PnlReportService
    {
        return $this->pnlReportServiceInstance ??= new PnlReportService($this->accountId);
    }

    private function feeCommission(): FeeCommissionService
    {
        return $this->feeCommissionServiceInstance ??= new FeeCommissionService($this->accountId);
    }

    private function paymentRefund(): PaymentRefundService
    {
        return $this->paymentRefundServiceInstance ??= new PaymentRefundService($this->accountId);
    }

    private function ledger(): FinancialLedgerService
    {
        return $this->ledgerServiceInstance ??= new FinancialLedgerService($this->db);
    }

    private function reconciliation(): FinancialReconciliationService
    {
        $accountId = (int)($this->accountId ?? 0);
        return $this->reconciliationServiceInstance ??= new FinancialReconciliationService($accountId, $this->db, $this->ledger());
    }

    /**
     * Busca pedidos da API com dados financeiros
     * Endpoint: GET /orders/search
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @param int $limit Limite de resultados
     * @param int $offset Offset para pagina\u00e7\u00e3o
     * @return array Lista de pedidos com dados financeiros
     */
    public function getOrdersFromApi(string $startDate, string $endDate, int $limit = 50, int $offset = 0): array
    {
        $sellerId = $this->getSellerId();
        if (!$sellerId) {
            return ['error' => 'Seller ID n\u00e3o encontrado', 'results' => []];
        }

        $client = $this->getClient();

        $params = [
            'seller' => $sellerId,
            'order.date_created.from' => $startDate . 'T00:00:00.000-03:00',
            'order.date_created.to' => $endDate . 'T23:59:59.999-03:00',
            'sort' => 'date_desc',
            'limit' => min(50, $limit),
            'offset' => $offset,
        ];

        $response = $client->get('/orders/search', $params);

        if ($this->isOrdersCapabilityUnavailable($response)) {
            return [
                'error' => 'orders_access_unavailable',
                'feature_unavailable' => true,
                'results' => [],
                'paging' => ['total' => 0, 'offset' => $offset, 'limit' => $limit],
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar pedidos',
                'results' => [],
                'paging' => ['total' => 0, 'offset' => $offset, 'limit' => $limit],
            ];
        }

        $orders = [];
        foreach ($response['results'] ?? [] as $order) {
            $orders[] = $this->extractOrderFinancials($order);
        }

        return [
            'results' => $orders,
            'paging' => $response['paging'] ?? ['total' => count($orders), 'offset' => $offset, 'limit' => $limit],
        ];
    }

    /**
     * Extrai dados financeiros de um pedido
     */
    private function extractOrderFinancials(array $order): array
    {
        $payments = $order['payments'] ?? [];
        $totalPaid = 0;
        $paymentFees = 0;
        $paymentMethod = null;

        foreach ($payments as $payment) {
            if (($payment['status'] ?? '') === 'approved') {
                $totalPaid += (float)($payment['total_paid_amount'] ?? $payment['transaction_amount'] ?? 0);
                $paymentFees += (float)($payment['fee_details'][0]['amount'] ?? 0);
                $paymentMethod = $payment['payment_type'] ?? $paymentMethod;
            }
        }

        // Calcular comissões e taxas do pedido
        $orderItems = $order['order_items'] ?? [];
        $subtotal = 0;
        $mlFee = 0;

        foreach ($orderItems as $item) {
            $subtotal += (float)($item['unit_price'] ?? 0) * (int)($item['quantity'] ?? 1);
            $mlFee += (float)($item['sale_fee'] ?? 0);
        }

        // Frete do vendedor (não o cost cobrado do comprador)
        $shippingCost = (float)($order['shipping_cost'] ?? 0);
        $shipmentId = $order['shipping']['id'] ?? null;
        if ($shippingCost <= 0.0 && $shipmentId) {
            $shippingCost = $this->resolveSellerShippingCost($shipmentId);
        }
        $totalAmount = (float)($order['total_amount'] ?? 0);
        $marketplaceNet = round($totalAmount - $mlFee - $paymentFees, 2);

        $base = [
            'order_id' => $order['id'] ?? null,
            'status' => $order['status'] ?? 'unknown',
            'date_created' => $order['date_created'] ?? null,
            'date_closed' => $order['date_closed'] ?? null,
            'total_amount' => $totalAmount,
            'paid_amount' => $totalPaid > 0 ? $totalPaid : $totalAmount,
            'subtotal' => $subtotal,
            'ml_fee' => $mlFee,
            'payment_fee' => $paymentFees,
            'shipping_cost' => $shippingCost,
            'marketplace_net' => $marketplaceNet,
            'payment_method' => $paymentMethod,
            'buyer_id' => $order['buyer']['id'] ?? null,
            'buyer_nickname' => $order['buyer']['nickname'] ?? null,
            'shipping_label' => $this->resolveShippingLabel($order['shipping'] ?? []),
            'items' => array_map(fn(array $i): array => [
                'item_id' => $i['item']['id'] ?? null,
                'title' => $i['item']['title'] ?? null,
                'sku' => $i['item']['seller_sku'] ?? $i['item']['seller_custom_field'] ?? null,
                'quantity' => (int)($i['quantity'] ?? 1),
                'unit_price' => (float)($i['unit_price'] ?? 0),
                'line_total' => round((float)($i['unit_price'] ?? 0) * (int)($i['quantity'] ?? 1), 2),
                'sale_fee' => (float)($i['sale_fee'] ?? 0),
                'thumbnail' => null,
                'product_cost' => 0.0,
                'extra_cost' => 0.0,
                'tax' => 0.0,
                'profit' => 0.0,
                'margin_pct' => 0.0,
                'linked_product' => false,
            ], $orderItems),
        ];

        return $this->enrichSalesRowsWithCosts([$base], $this->accountId)[0] ?? $base;
    }

    /**
     * Lista vendas locais com P&L por item (fonte: ml_orders.order_data + sku_custos/items).
     *
     * @return array{results: list<array<string, mixed>>, paging: array<string, mixed>, summary: array<string, mixed>}
     */
    public function listLocalSalesWithProfitability(
        string $startDate,
        string $endDate,
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
        ?string $search = null
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = [
            'o.date_created BETWEEN :start AND :end',
        ];
        $params = [
            ':start' => $startDate . (strlen($startDate) === 10 ? ' 00:00:00' : ''),
            ':end' => $endDate . (strlen($endDate) === 10 ? ' 23:59:59' : ''),
        ];

        if ($this->accountId) {
            $where[] = 'o.ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $where[] = 'o.status = :status';
            $params[':status'] = $status;
        } else {
            $where[] = "o.status IN ('paid', 'delivered', 'confirmed', 'ready_to_ship', 'shipped', 'handling')";
        }

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $where[] = '(CAST(o.ml_order_id AS CHAR) LIKE :q_order
                OR COALESCE(a.nickname, \'\') LIKE :q_nick
                OR o.order_data LIKE :q_data)';
            $like = '%' . $search . '%';
            $params[':q_order'] = $like;
            $params[':q_nick'] = $like;
            $params[':q_data'] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ml_orders o
             LEFT JOIN ml_accounts a ON a.id = o.ml_account_id
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT
                    o.id,
                    o.ml_order_id,
                    o.ml_account_id,
                    o.status,
                    o.date_created,
                    o.delivered_at,
                    o.total_amount,
                    o.ml_commission,
                    o.payment_fee,
                    o.shipping_cost,
                    o.taxes,
                    o.product_cost,
                    o.net_profit,
                    o.order_data,
                    a.nickname AS account_nickname
                FROM ml_orders o
                LEFT JOIN ml_accounts a ON a.id = o.ml_account_id
                WHERE {$whereSql}
                ORDER BY o.date_created DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sales = [];
        foreach ($rows as $row) {
            $sales[] = $this->mapLocalOrderToSale($row);
        }
        $sales = $this->enrichSalesRowsWithCosts($sales, $this->accountId);
        $sales = $this->applyLedgerToSales($sales);

        $summaryCap = 2000;
        $summary = [
            'unlinked_items' => 0,
            'unlinked_unique_items' => 0,
            'total_profit' => 0.0,
            'total_revenue' => 0.0,
            'avg_margin' => 0.0,
            'marketplace_net' => 0.0,
            'total_tax' => 0.0,
            'tax_configured' => false,
            'partial' => false,
        ];

        if ($total <= $limit && $offset === 0) {
            $summary = $this->summarizeSales($sales);
        } elseif ($total > 0) {
            $summaryLimit = min($total, $summaryCap);
            $summarySql = "SELECT
                    o.id,
                    o.ml_order_id,
                    o.ml_account_id,
                    o.status,
                    o.date_created,
                    o.delivered_at,
                    o.total_amount,
                    o.ml_commission,
                    o.payment_fee,
                    o.shipping_cost,
                    o.taxes,
                    o.product_cost,
                    o.net_profit,
                    o.order_data,
                    a.nickname AS account_nickname
                FROM ml_orders o
                LEFT JOIN ml_accounts a ON a.id = o.ml_account_id
                WHERE {$whereSql}
                ORDER BY o.date_created DESC
                LIMIT {$summaryLimit}";
            $summaryStmt = $this->db->prepare($summarySql);
            $summaryStmt->execute($params);
            $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
            $summarySales = [];
            foreach ($summaryRows as $row) {
                $summarySales[] = $this->mapLocalOrderToSale($row);
            }
            $summarySales = $this->enrichSalesRowsWithCosts($summarySales, $this->accountId);
            $summary = $this->summarizeSales($summarySales);
            $summary['partial'] = $total > $summaryCap;
        }

        return [
            'results' => $sales,
            'paging' => [
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @param list<array<string, mixed>> $sales
     * @return array{unlinked_items: int, total_profit: float, total_revenue: float, avg_margin: float, marketplace_net: float, partial: bool}
     */
    private function summarizeSales(array $sales): array
    {
        $unlinked = 0;
        $unlinkedUnique = [];
        $totalProfit = 0.0;
        $totalRevenue = 0.0;
        $totalNet = 0.0;
        $totalTax = 0.0;
        foreach ($sales as $sale) {
            $totalProfit += (float)($sale['profit'] ?? 0);
            $totalRevenue += (float)($sale['total_amount'] ?? 0);
            $totalNet += (float)($sale['marketplace_net'] ?? 0);
            $totalTax += (float)($sale['taxes'] ?? 0);
            foreach ($sale['items'] as $item) {
                if (empty($item['linked_product'])) {
                    $unlinked++;
                    $itemId = trim((string)($item['item_id'] ?? ''));
                    $sku = trim((string)($item['sku'] ?? ''));
                    $key = $itemId !== '' ? $itemId : ($sku !== '' ? 'sku:' . $sku : 'row:' . $unlinked);
                    $unlinkedUnique[$key] = true;
                }
            }
        }

        return [
            'unlinked_items' => $unlinked,
            'unlinked_unique_items' => count($unlinkedUnique),
            'total_profit' => round($totalProfit, 2),
            'total_revenue' => round($totalRevenue, 2),
            'avg_margin' => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0.0,
            'marketplace_net' => round($totalNet, 2),
            'total_tax' => round($totalTax, 2),
            'tax_configured' => $totalTax > 0,
            'partial' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapLocalOrderToSale(array $row): array
    {
        $orderData = [];
        if (!empty($row['order_data'])) {
            $decoded = is_string($row['order_data'])
                ? json_decode($row['order_data'], true)
                : $row['order_data'];
            $orderData = is_array($decoded) ? $decoded : [];
        }

        $orderItems = $orderData['order_items'] ?? [];
        $mlFee = (float)($row['ml_commission'] ?? 0);
        $paymentFee = (float)($row['payment_fee'] ?? 0);
        if ($mlFee <= 0) {
            foreach ($orderItems as $item) {
                $mlFee += (float)($item['sale_fee'] ?? 0);
            }
        }

        $totalAmount = (float)($row['total_amount'] ?? 0);
        // shipping.cost / payments.shipping_cost = frete do COMPRADOR (muitas vezes 0 no frete grátis).
        // O custo real do vendedor vem de GET /shipments/{id}/costs → senders[].cost
        $shippingCost = (float)($row['shipping_cost'] ?? 0);
        $shipmentId = $orderData['shipping']['id'] ?? null;
        if ($shippingCost <= 0.0 && $shipmentId) {
            $resolved = $this->resolveSellerShippingCost($shipmentId);
            if ($resolved > 0.0) {
                $shippingCost = $resolved;
                $localId = (int)($row['id'] ?? 0);
                if ($localId > 0) {
                    $this->persistOrderShippingCost($localId, $shippingCost);
                }
            }
        }
        $taxes = (float)($row['taxes'] ?? ($orderData['taxes']['amount'] ?? 0));
        $payment = is_array($orderData['payments'][0] ?? null) ? $orderData['payments'][0] : [];
        if ($taxes <= 0) {
            $taxes = (float)($payment['taxes_amount'] ?? 0);
        }
        $couponAmount = (float)($payment['coupon_amount'] ?? ($orderData['coupon']['amount'] ?? 0));
        $marketplaceNet = round($totalAmount - $mlFee - $paymentFee, 2);

        $items = [];
        foreach ($orderItems as $item) {
            $qty = (int)($item['quantity'] ?? 1);
            $unit = (float)($item['unit_price'] ?? 0);
            $items[] = [
                'item_id' => $item['item']['id'] ?? null,
                'title' => $item['item']['title'] ?? null,
                'sku' => $item['item']['seller_sku']
                    ?? $item['item']['seller_custom_field']
                    ?? null,
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => round($unit * $qty, 2),
                'sale_fee' => (float)($item['sale_fee'] ?? 0),
                'thumbnail' => null,
                'product_cost' => 0.0,
                'extra_cost' => 0.0,
                'tax' => 0.0,
                'profit' => 0.0,
                'margin_pct' => 0.0,
                'linked_product' => false,
            ];
        }

        return [
            'order_id' => $row['ml_order_id'] ?? $row['id'],
            'local_id' => (int)($row['id'] ?? 0),
            'account_id' => (int)($row['ml_account_id'] ?? 0),
            'account_nickname' => $row['account_nickname'] ?? null,
            'status' => $row['status'] ?? 'unknown',
            'date_created' => $row['date_created'] ?? null,
            'date_closed' => $row['delivered_at'] ?? ($orderData['date_closed'] ?? null),
            'total_amount' => $totalAmount,
            'paid_amount' => $totalAmount,
            'subtotal' => array_sum(array_column($items, 'line_total')),
            'ml_fee' => round($mlFee, 2),
            'payment_fee' => round($paymentFee, 2),
            'shipping_cost' => round($shippingCost, 2),
            'taxes' => round($taxes, 2),
            'marketplace_net' => $marketplaceNet,
            'payment_method' => $payment['payment_type'] ?? null,
            'payment_method_id' => $payment['payment_method_id'] ?? null,
            'installments' => (int)($payment['installments'] ?? 1),
            'coupon_amount' => round($couponAmount, 2),
            'buyer_id' => $orderData['buyer']['id'] ?? null,
            'buyer_nickname' => $orderData['buyer']['nickname'] ?? null,
            'shipping_label' => $this->resolveShippingLabel($orderData['shipping'] ?? []),
            'items' => $items,
            'profit' => 0.0,
            'margin_pct' => 0.0,
            'product_cost' => 0.0,
            'extra_cost' => 0.0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $sales
     * @return list<array<string, mixed>>
     */
    private function enrichSalesRowsWithCosts(array $sales, ?int $accountId): array
    {
        $itemIds = [];
        $skus = [];
        foreach ($sales as $sale) {
            foreach ($sale['items'] as $item) {
                if (!empty($item['item_id'])) {
                    $itemIds[] = (string)$item['item_id'];
                }
                if (!empty($item['sku'])) {
                    $skus[] = (string)$item['sku'];
                }
            }
        }
        $itemIds = array_values(array_unique($itemIds));
        $skus = array_values(array_unique($skus));
        $costMap = $this->loadCostMap($itemIds, $accountId);
        $costBySku = $this->loadCostMapBySku($skus, $accountId);
        $itemMeta = $this->loadItemMeta($itemIds);
        $productCosts = $this->loadProductCosts($itemIds, $skus, $accountId);
        $defaultTaxRate = $this->resolveDefaultTaxRate();

        foreach ($sales as &$sale) {
            $orderTax = (float)($sale['taxes'] ?? 0);
            $orderShipping = (float)($sale['shipping_cost'] ?? 0);
            $orderMlFee = (float)($sale['ml_fee'] ?? 0);
            $orderPaymentFee = (float)($sale['payment_fee'] ?? 0);
            $orderTotal = (float)($sale['total_amount'] ?? 0);
            $itemsTotal = array_sum(array_map(static fn(array $i): float => (float)$i['line_total'], $sale['items']));
            if ($itemsTotal <= 0) {
                $itemsTotal = $orderTotal > 0 ? $orderTotal : 1.0;
            }

            $saleProductCost = 0.0;
            $saleExtraCost = 0.0;
            $saleTax = 0.0;
            $saleProfit = 0.0;

            foreach ($sale['items'] as &$item) {
                $itemId = (string)($item['item_id'] ?? '');
                $sku = (string)($item['sku'] ?? '');
                $qty = max(1, (int)($item['quantity'] ?? 1));
                $lineTotal = (float)($item['line_total'] ?? 0);
                $share = $lineTotal / $itemsTotal;

                $meta = $itemMeta[$itemId] ?? null;
                $cost = $costMap[$itemId] ?? null;
                $pc = $productCosts['by_item'][$itemId]
                    ?? ($sku !== '' ? ($productCosts['by_sku'][$sku] ?? null) : null);

                if ($meta) {
                    if (empty($item['thumbnail']) && !empty($meta['thumbnail'])) {
                        $item['thumbnail'] = $meta['thumbnail'];
                    }
                    if ($sku === '' && !empty($meta['sku'])) {
                        $sku = (string)$meta['sku'];
                        $item['sku'] = $sku;
                    }
                }

                $unitCost = 0.0;
                $opsPct = 0.0;
                $linked = false;
                $costSource = 'none';

                if ($cost && (float)$cost['custo_produto'] > 0) {
                    $unitCost = (float)$cost['custo_produto'];
                    $opsPct = (float)($cost['custos_operacionais_pct'] ?? 0);
                    $linked = true;
                    $costSource = 'sku_custos';
                } elseif ($sku !== '' && isset($costBySku[$sku]) && (float)$costBySku[$sku]['custo_produto'] > 0) {
                    $unitCost = (float)$costBySku[$sku]['custo_produto'];
                    $opsPct = (float)($costBySku[$sku]['custos_operacionais_pct'] ?? 0);
                    $linked = true;
                    $costSource = 'sku';
                } elseif ($pc && (float)($pc['custo_producao'] ?? 0) > 0) {
                    $unitCost = (float)$pc['custo_producao'];
                    $linked = true;
                    $costSource = 'product_costs';
                } elseif ($meta && (float)($meta['cost_price'] ?? 0) > 0) {
                    $unitCost = (float)$meta['cost_price'];
                    $linked = true;
                    $costSource = 'items';
                }

                $productCost = round($unitCost * $qty, 2);
                $extraCost = round($lineTotal * ($opsPct / 100), 2);

                $lineTax = 0.0;
                $taxSource = 'none';
                if ($orderTax > 0) {
                    $lineTax = round($orderTax * $share, 2);
                    $taxSource = 'order';
                } elseif ($meta && (float)($meta['tax_rate'] ?? 0) > 0) {
                    $lineTax = round($lineTotal * ((float)$meta['tax_rate'] / 100), 2);
                    $taxSource = 'items';
                } elseif ($pc && (float)($pc['taxa_imposto'] ?? 0) > 0) {
                    $lineTax = round($lineTotal * ((float)$pc['taxa_imposto'] / 100), 2);
                    $taxSource = 'product_costs';
                } elseif ($defaultTaxRate > 0) {
                    $lineTax = round($lineTotal * ($defaultTaxRate / 100), 2);
                    $taxSource = 'settings';
                }

                $lineMlFee = (float)($item['sale_fee'] ?? 0);
                if ($lineMlFee <= 0 && $orderMlFee > 0) {
                    $lineMlFee = round($orderMlFee * $share, 2);
                }
                $linePaymentFee = round($orderPaymentFee * $share, 2);
                $lineShipping = round($orderShipping * $share, 2);
                $lineNet = round($lineTotal - $lineMlFee - $linePaymentFee, 2);
                $lineProfit = round($lineNet - $lineTax - $productCost - $extraCost - $lineShipping, 2);
                $lineMargin = $lineTotal > 0 ? round(($lineProfit / $lineTotal) * 100, 2) : 0.0;

                $item['product_cost'] = $productCost;
                $item['extra_cost'] = $extraCost;
                $item['tax'] = $lineTax;
                $item['marketplace_net'] = $lineNet;
                $item['profit'] = $lineProfit;
                $item['margin_pct'] = $lineMargin;
                $item['linked_product'] = $linked;
                $item['cost_source'] = $costSource;
                $item['tax_source'] = $taxSource;

                $saleProductCost += $productCost;
                $saleExtraCost += $extraCost;
                $saleTax += $lineTax;
                $saleProfit += $lineProfit;
            }
            unset($item);

            $sale['product_cost'] = round($saleProductCost, 2);
            $sale['extra_cost'] = round($saleExtraCost, 2);
            $sale['taxes'] = round($saleTax > 0 ? $saleTax : $orderTax, 2);
            $sale['profit'] = round($saleProfit, 2);
            $sale['margin_pct'] = $orderTotal > 0 ? round(($saleProfit / $orderTotal) * 100, 2) : 0.0;
            $sale['marketplace_net'] = round($orderTotal - $orderMlFee - $orderPaymentFee, 2);
        }
        unset($sale);

        return $sales;
    }

    /**
     * Overlay do livro financeiro canônico sobre a venda.
     * Sem ledger → fallback order_data (ajustado para incluir frete seller no net).
     *
     * @param list<array<string, mixed>> $sales
     * @return list<array<string, mixed>>
     */
    private function applyLedgerToSales(array $sales): array
    {
        if ($sales === []) {
            return $sales;
        }

        $accountId = (int)($this->accountId ?? 0);
        if ($accountId <= 0) {
            foreach ($sales as $sale) {
                $aid = (int)($sale['account_id'] ?? 0);
                if ($aid > 0) {
                    $accountId = $aid;
                    break;
                }
            }
        }

        $orderIds = [];
        foreach ($sales as $sale) {
            $oid = (string)($sale['order_id'] ?? '');
            if ($oid !== '') {
                $orderIds[] = $oid;
            }
        }

        $summaries = $accountId > 0
            ? $this->ledger()->summarizeOrders($accountId, $orderIds)
            : [];

        foreach ($sales as &$sale) {
            $oid = (string)($sale['order_id'] ?? '');
            $sum = $summaries[$oid] ?? null;

            if ($sum === null || empty($sum['has_ledger']) || (int)$sum['entries_count'] <= 0) {
                $sale['ledger_source'] = 'fallback_order';
                $sale['refund_covered'] = 0.0;
                $sale['refund_net'] = 0.0;
                $sale['protection_net'] = 0.0;
                $sale['marketplace_net'] = round(
                    (float)($sale['total_amount'] ?? 0)
                    - (float)($sale['ml_fee'] ?? 0)
                    - (float)($sale['payment_fee'] ?? 0)
                    - (float)($sale['shipping_cost'] ?? 0)
                    - (float)($sale['coupon_amount'] ?? 0),
                    2
                );
                $sale['profit'] = round(
                    (float)$sale['marketplace_net']
                    - (float)($sale['product_cost'] ?? 0)
                    - (float)($sale['extra_cost'] ?? 0)
                    - (float)($sale['taxes'] ?? 0),
                    2
                );
                $rev = (float)($sale['total_amount'] ?? 0);
                $sale['margin_pct'] = $rev > 0 ? round(((float)$sale['profit'] / $rev) * 100, 2) : 0.0;
                continue;
            }

            $byType = $sum['by_type'];
            $byCat = $sum['by_category'];

            $sale['ledger_source'] = 'ledger';
            $sale['sale_revenue'] = (float)($byType[FinancialEntryType::SALE_REVENUE] ?? $sale['total_amount'] ?? 0);
            if (isset($byType[FinancialEntryType::SALE_FEE])) {
                $sale['ml_fee'] = abs((float)$byType[FinancialEntryType::SALE_FEE]);
            }
            if (isset($byType[FinancialEntryType::PAYMENT_FEE])) {
                $sale['payment_fee'] = abs((float)$byType[FinancialEntryType::PAYMENT_FEE]);
            }
            if (isset($byType[FinancialEntryType::SHIPPING_COST])) {
                $sale['shipping_cost'] = abs((float)$byType[FinancialEntryType::SHIPPING_COST]);
            }
            if (isset($byType[FinancialEntryType::COMMERCIAL_DISCOUNT])) {
                $sale['coupon_amount'] = abs((float)$byType[FinancialEntryType::COMMERCIAL_DISCOUNT]);
            }

            $sale['refund_covered'] = (float)$sum['refund_covered'];
            $sale['refund_net'] = (float)($byCat[FinancialEntryCategory::REFUND] ?? 0);
            $sale['protection_net'] = (float)($byCat[FinancialEntryCategory::PROTECTION] ?? 0);
            $sale['marketplace_net'] = (float)$sum['marketplace_net'];
            $sale['ledger_entries_count'] = (int)$sum['entries_count'];
            $sale['ledger_summary'] = [
                'marketplace_net' => (float)$sum['marketplace_net'],
                'settlement_net' => (float)($sum['settlement_net'] ?? 0),
                'released_amount' => (float)($sum['released_amount'] ?? 0),
                'pending_release_amount' => (float)($sum['pending_release_amount'] ?? 0),
                'entries_count' => (int)$sum['entries_count'],
                'refund_covered' => (float)$sum['refund_covered'],
            ];

            $sale['profit'] = round(
                (float)$sale['marketplace_net']
                - (float)($sale['product_cost'] ?? 0)
                - (float)($sale['extra_cost'] ?? 0)
                - (float)($sale['taxes'] ?? 0),
                2
            );
            $rev = (float)($sale['total_amount'] ?? 0);
            $sale['margin_pct'] = $rev > 0 ? round(((float)$sale['profit'] / $rev) * 100, 2) : 0.0;
        }
        unset($sale);

        return $sales;
    }

    /**
     * @param list<string> $skus
     * @return array<string, array<string, mixed>>
     */
    private function loadCostMapBySku(array $skus, ?int $accountId): array
    {
        if ($skus === [] || !$accountId) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT i.sku, sc.custo_produto, sc.comissao_pct, sc.frete_medio, sc.custos_operacionais_pct
                 FROM sku_custos sc
                 INNER JOIN items i ON i.ml_item_id = sc.mlb_id AND i.account_id = sc.account_id
                 WHERE sc.account_id = ? AND i.sku IN ({$placeholders})
                   AND i.sku IS NOT NULL AND i.sku != ''"
            );
            $stmt->execute([$accountId, ...$skus]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sku = (string)$row['sku'];
                if ($sku !== '' && !isset($map[$sku])) {
                    $map[$sku] = $row;
                }
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<string> $itemIds
     * @param list<string> $skus
     * @return array{by_item: array<string, array<string, mixed>>, by_sku: array<string, array<string, mixed>>}
     */
    private function loadProductCosts(array $itemIds, array $skus, ?int $accountId): array
    {
        $empty = ['by_item' => [], 'by_sku' => []];
        if (!$accountId || ($itemIds === [] && $skus === [])) {
            return $empty;
        }

        try {
            $byItem = [];
            $bySku = [];
            if ($itemIds !== []) {
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $stmt = $this->db->prepare(
                    "SELECT item_id, sku, custo_producao, taxa_imposto
                     FROM product_costs
                     WHERE account_id = ? AND item_id IN ({$placeholders})"
                );
                $stmt->execute([$accountId, ...$itemIds]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $byItem[(string)$row['item_id']] = $row;
                    if (!empty($row['sku'])) {
                        $bySku[(string)$row['sku']] = $row;
                    }
                }
            }
            if ($skus !== []) {
                $placeholders = implode(',', array_fill(0, count($skus), '?'));
                $stmt = $this->db->prepare(
                    "SELECT item_id, sku, custo_producao, taxa_imposto
                     FROM product_costs
                     WHERE account_id = ? AND sku IN ({$placeholders})"
                );
                $stmt->execute([$accountId, ...$skus]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!empty($row['sku']) && !isset($bySku[(string)$row['sku']])) {
                        $bySku[(string)$row['sku']] = $row;
                    }
                }
            }
            return ['by_item' => $byItem, 'by_sku' => $bySku];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    private function resolveDefaultTaxRate(): float
    {
        if (!$this->accountId) {
            return self::DEFAULT_TAX_RATE > 0 ? self::DEFAULT_TAX_RATE * 100 : 0.0;
        }

        try {
            $settings = new \App\Services\SettingsService($this->accountId, $this->db);
            $rate = $settings->getDefaultTaxRate();
            if ($rate > 0) {
                return $rate;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT AVG(taxa_imposto) FROM product_costs WHERE account_id = ? AND taxa_imposto > 0'
            );
            $stmt->execute([$this->accountId]);
            $avg = $stmt->fetchColumn();
            if ($avg !== false && (float)$avg > 0) {
                return (float)$avg;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return self::DEFAULT_TAX_RATE > 0 ? self::DEFAULT_TAX_RATE * 100 : 0.0;
    }

    private function loadCostMap(array $itemIds, ?int $accountId): array
    {
        if ($itemIds === [] || !$accountId) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT mlb_id, custo_produto, comissao_pct, frete_medio, custos_operacionais_pct
                 FROM sku_custos
                 WHERE account_id = ? AND mlb_id IN ({$placeholders})"
            );
            $stmt->execute([$accountId, ...$itemIds]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(string)$row['mlb_id']] = $row;
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<string> $itemIds
     * @return array<string, array<string, mixed>>
     */
    private function loadItemMeta(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT ml_item_id, sku, thumbnail, cost_price, tax_rate
                 FROM items
                 WHERE ml_item_id IN ({$placeholders})"
            );
            $stmt->execute($itemIds);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(string)$row['ml_item_id']] = $row;
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Frete pago pelo vendedor (senders[].cost em /shipments/{id}/costs).
     * Diferente de shipping.cost / payments.shipping_cost (valor do comprador).
     */
    private function resolveSellerShippingCost(int|string $shipmentId): float
    {
        $cacheKey = (string)$shipmentId;
        if (array_key_exists($cacheKey, $this->sellerShippingCostCache)) {
            return $this->sellerShippingCostCache[$cacheKey];
        }

        $cost = 0.0;
        try {
            if (!$this->accountId) {
                $this->sellerShippingCostCache[$cacheKey] = 0.0;
                return 0.0;
            }

            $client = $this->getClient();
            $response = $client->get('/shipments/' . $cacheKey . '/costs');
            if (isset($response['body']) && is_array($response['body'])) {
                $response = $response['body'];
            }
            if (isset($response['data']) && is_array($response['data']) && isset($response['data']['senders'])) {
                $response = $response['data'];
            }

            $senders = $response['senders'] ?? [];
            if (is_array($senders)) {
                foreach ($senders as $sender) {
                    if (!is_array($sender)) {
                        continue;
                    }
                    $cost += (float)($sender['cost'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            $cost = 0.0;
        }

        $cost = round($cost, 2);
        $this->sellerShippingCostCache[$cacheKey] = $cost;
        return $cost;
    }

    private function persistOrderShippingCost(int $localOrderId, float $shippingCost): void
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE ml_orders SET shipping_cost = :cost WHERE id = :id AND (shipping_cost IS NULL OR shipping_cost <= 0)'
            );
            $stmt->execute([
                ':cost' => $shippingCost,
                ':id' => $localOrderId,
            ]);
        } catch (\Throwable $e) {
            // best-effort: não bloquear listagem
        }
    }

    /**
     * @param array<string, mixed> $shipping
     */
    private function resolveShippingLabel(array $shipping): string
    {
        $logistic = (string)($shipping['logistic_type'] ?? $shipping['mode'] ?? '');
        $map = [
            'fulfillment' => 'Full / Fulfillment',
            'xd_drop_off' => 'Coleta / Drop-off',
            'drop_off' => 'Agência ML',
            'self_service' => 'Flex',
            'cross_docking' => 'Cross docking',
            'me2' => 'Mercado Envios',
            'custom' => 'Envio a combinar',
            'not_specified' => 'Não especificado',
        ];

        if ($logistic !== '' && isset($map[$logistic])) {
            return $map[$logistic];
        }
        if ($logistic !== '') {
            return ucwords(str_replace('_', ' ', $logistic));
        }
        return 'Logística ML';
    }

    /**
     * Obtém detalhes de um pedido específico com dados financeiros
     * Endpoint: GET /orders/{order_id}
     *
     * @param string $orderId ID do pedido
     * @return array Detalhes financeiros do pedido
     */
    public function getOrderDetails(string $orderId): array
    {
        // Preferir enriquecimento local (custos reais) quando o pedido já estiver sincronizado
        try {
            $stmt = $this->db->prepare(
                "SELECT o.id, o.ml_order_id, o.ml_account_id, o.status, o.date_created, o.delivered_at,
                        o.total_amount, o.ml_commission, o.payment_fee, o.shipping_cost, o.taxes,
                        o.product_cost, o.net_profit, o.order_data, a.nickname AS account_nickname
                 FROM ml_orders o
                 LEFT JOIN ml_accounts a ON a.id = o.ml_account_id
                 WHERE o.ml_order_id = :oid OR o.id = :oid2
                 LIMIT 1"
            );
            $stmt->execute([':oid' => $orderId, ':oid2' => $orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $sale = $this->mapLocalOrderToSale($row);
                $sale = $this->enrichSalesRowsWithCosts([$sale], (int)($row['ml_account_id'] ?? $this->accountId))[0];
                $sale = $this->applyLedgerToSales([$sale])[0];
                $accountId = (int)($row['ml_account_id'] ?? $this->accountId ?? 0);
                $orderKey = (string)($sale['order_id'] ?? $orderId);
                if ($accountId > 0) {
                    $sale['ledger_entries'] = $this->sanitizeLedgerEntriesForUi(
                        $this->ledger()->listByOrder($accountId, $orderKey)
                    );
                    $sale['ledger_summary'] = $this->ledger()->summarizeOrder($accountId, $orderKey);
                    try {
                        $this->accountId = $accountId;
                        $sale['discrepancies'] = $this->reconciliation()->listByOrder($orderKey, 'open');
                        $sale['reconciliation'] = $this->reconciliation()->getOrderReconciliationView($orderKey);
                    } catch (\Throwable $e) {
                        $sale['discrepancies'] = [];
                        $sale['reconciliation'] = null;
                    }
                }
                return $sale;
            }
        } catch (\Throwable $e) {
            // fallback API
        }

        $client = $this->getClient();
        $response = $client->get("/orders/{$orderId}");

        if (isset($response['error'])) {
            return ['error' => $response['message'] ?? 'Pedido não encontrado'];
        }

        return $this->extractOrderFinancials($response);
    }

    /**
     * Sincroniza pedidos com dados financeiros da API para o banco local
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @param bool $forceSync For\u00e7ar sincroniza\u00e7\u00e3o
     * @return array Resultado da sincroniza\u00e7\u00e3o
     */
    public function syncOrdersWithFinancials(string $startDate, string $endDate, bool $forceSync = false): array
    {
        $sellerId = $this->getSellerId();
        if (!$sellerId) {
            return ['error' => 'Seller ID n\u00e3o encontrado', 'synced' => 0];
        }

        $userId = SessionHelper::getUserId();
        $synced = 0;
        $errors = [];
        $offset = 0;
        $limit = 50;
        $hasMore = true;

        while ($hasMore) {
            $response = $this->getOrdersFromApi($startDate, $endDate, $limit, $offset);

            if (!empty($response['feature_unavailable'])) {
                return [
                    'success' => false,
                    'error' => $response['error'],
                    'feature_unavailable' => true,
                    'synced' => $synced,
                    'period' => ['start' => $startDate, 'end' => $endDate],
                ];
            }

            if (isset($response['error'])) {
                $errors[] = $response['error'];
                break;
            }

            $orders = $response['results'] ?? [];

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                try {
                    $this->saveOrderWithFinancials($order, $userId);
                    $synced++;
                } catch (\Exception $e) {
                    $errors[] = "Order {$order['order_id']}: " . $e->getMessage();
                }
            }

            $paging = $response['paging'] ?? [];
            $total = $paging['total'] ?? 0;
            $offset += $limit;
            $hasMore = $offset < $total && count($orders) === $limit;

            // Limite de seguran\u00e7a
            if ($offset > 5000) {
                break;
            }
        }

        return [
            'success' => empty($errors),
            'synced' => $synced,
            'errors' => $errors,
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    /**
     * Salva pedido com dados financeiros no banco
     */
    private function saveOrderWithFinancials(array $order, ?int $userId): void
    {
        if (empty($order['order_id'])) {
            return;
        }

        // Verificar se userId n\u00e3o est\u00e1 na sess\u00e3o (CRON), buscar da conta
        if (!$userId && $this->accountId) {
            $stmt = $this->db->prepare("SELECT user_id FROM ml_accounts WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $this->accountId]);
            $userId = $stmt->fetchColumn() ?: null;
        }

        $stmt = $this->db->prepare("
            INSERT INTO ml_orders (
                ml_order_id, ml_account_id, user_id, order_data, status,
                total_amount, subtotal, ml_commission, payment_fee, shipping_cost,
                date_created, synced_at
            ) VALUES (
                :ml_order_id, :ml_account_id, :user_id, :order_data, :status,
                :total_amount, :subtotal, :ml_commission, :payment_fee, :shipping_cost,
                :date_created, NOW()
            )
            ON DUPLICATE KEY UPDATE
                order_data = VALUES(order_data),
                status = VALUES(status),
                total_amount = VALUES(total_amount),
                subtotal = VALUES(subtotal),
                ml_commission = VALUES(ml_commission),
                payment_fee = VALUES(payment_fee),
                shipping_cost = VALUES(shipping_cost),
                synced_at = NOW()
        ");

        $orderJson = json_encode($order);

        $stmt->execute([
            ':ml_order_id' => $order['order_id'],
            ':ml_account_id' => $this->accountId,
            ':user_id' => $userId,
            ':order_data' => $orderJson,
            ':status' => $order['status'] ?? 'unknown',
            ':total_amount' => $order['total_amount'] ?? 0,
            ':subtotal' => $order['subtotal'] ?? 0,
            ':ml_commission' => $order['ml_fee'] ?? 0,
            ':payment_fee' => $order['payment_fee'] ?? 0,
            ':shipping_cost' => $order['shipping_cost'] ?? 0,
            ':date_created' => $order['date_created'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Obt\u00e9m resumo financeiro em tempo real (API + local)
     * Combina dados da API com dados locais para vis\u00e3o completa
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Resumo financeiro completo
     */
    public function getRealTimeFinancialSummary(string $startDate, string $endDate): array
    {
        // Dados do banco local (j\u00e1 sincronizados)
        $localPnl = $this->pnlReport()->getPnL($startDate, $endDate);

        // Saldo atual da conta
        $balance = $this->pnlReport()->getAccountBalance();

        // Tentar obter dados recentes da API para per\u00edodo curto (\u00faltimos 7 dias)
        $recentOrders = [];
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        if ($startDate >= $weekAgo) {
            $apiOrders = $this->getOrdersFromApi($startDate, min($endDate, $today), 50);
            $recentOrders = $apiOrders['results'] ?? [];
        }

        // Calcular m\u00e9tricas dos pedidos recentes da API
        $apiMetrics = $this->calculateMetricsFromOrders($recentOrders);

        return [
            'local_data' => $localPnl,
            'account_balance' => $balance,
            'api_recent_orders' => [
                'count' => count($recentOrders),
                'metrics' => $apiMetrics,
            ],
            'combined' => [
                'gross_revenue' => $localPnl['gross_revenue'] + $apiMetrics['revenue'],
                'total_orders' => $localPnl['total_orders'] + $apiMetrics['orders'],
                'available_balance' => $balance['available_balance'] ?? 0,
            ],
            'data_freshness' => [
                'local_data' => 'historical',
                'api_data' => 'real-time',
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * Calcula m\u00e9tricas a partir de lista de pedidos
     */
    private function calculateMetricsFromOrders(array $orders): array
    {
        $revenue = 0;
        $fees = 0;
        $shipping = 0;
        $count = count($orders);

        foreach ($orders as $order) {
            $revenue += (float)($order['total_amount'] ?? 0);
            $fees += (float)($order['ml_fee'] ?? 0) + (float)($order['payment_fee'] ?? 0);
            $shipping += (float)($order['shipping_cost'] ?? 0);
        }

        $profit = $revenue - $fees - $shipping;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        return [
            'orders' => $count,
            'revenue' => round($revenue, 2),
            'fees' => round($fees, 2),
            'shipping' => round($shipping, 2),
            'profit' => round($profit, 2),
            'margin' => round($margin, 2),
        ];
    }

    /**
     * Gera relat\u00f3rio financeiro em tempo real completo
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Relat\u00f3rio financeiro completo
     */
    public function generateRealTimeFinancialReport(string $startDate, string $endDate): array
    {
        $periodKey = date('Y-m-01', strtotime($startDate));

        // Buscar dados de m\u00faltiplas fontes em paralelo (conceitualmente)
        $balance = $this->pnlReport()->getAccountBalance();
        $orders = $this->getOrdersFromApi($startDate, $endDate, 100);
        $mlBilling = $this->feeCommission()->getBillingDetails($periodKey, 'BILL', 500);
        $mpBilling = $this->feeCommission()->getMercadoPagoBillingDetails($periodKey, 'BILL', 500);
        $payments = $this->feeCommission()->getPaymentReport($periodKey, 100);

        // Calcular m\u00e9tricas dos pedidos
        $orderMetrics = $this->calculateMetricsFromOrders($orders['results'] ?? []);

        // Totalizar billing
        $totalMLCharges = 0;
        $totalMPCharges = 0;
        $chargesByType = [];

        foreach ($mlBilling['results'] ?? [] as $item) {
            $amount = (float)($item['detail_amount'] ?? 0);
            $totalMLCharges += $amount;
            $subType = $item['detail_sub_type'] ?? 'OTHER';
            $chargesByType[$subType] = ($chargesByType[$subType] ?? 0) + $amount;
        }

        foreach ($mpBilling['results'] ?? [] as $item) {
            $totalMPCharges += (float)($item['detail_amount'] ?? 0);
        }

        // Totalizar pagamentos recebidos
        $totalPayments = 0;
        foreach ($payments['results'] ?? [] as $payment) {
            $totalPayments += (float)($payment['payment_amount'] ?? 0);
        }

        $grossRevenue = $orderMetrics['revenue'];
        $totalFees = $totalMLCharges + $totalMPCharges;
        $netRevenue = $grossRevenue - $totalFees;
        $margin = $grossRevenue > 0 ? ($netRevenue / $grossRevenue) * 100 : 0;

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'account' => [
                'available_balance' => $balance['available_balance'] ?? 0,
                'total_balance' => $balance['total_balance'] ?? 0,
                'reserved' => $balance['reserved'] ?? 0,
            ],
            'sales' => [
                'total_orders' => $orderMetrics['orders'],
                'gross_revenue' => round($grossRevenue, 2),
                'average_ticket' => $orderMetrics['orders'] > 0
                    ? round($grossRevenue / $orderMetrics['orders'], 2)
                    : 0,
            ],
            'fees' => [
                'mercado_libre' => round($totalMLCharges, 2),
                'mercado_pago' => round($totalMPCharges, 2),
                'total' => round($totalFees, 2),
                'by_type' => $chargesByType,
            ],
            'profitability' => [
                'net_revenue' => round($netRevenue, 2),
                'margin_percentage' => round($margin, 2),
                'fee_rate' => $grossRevenue > 0
                    ? round(($totalFees / $grossRevenue) * 100, 2)
                    : 0,
            ],
            'payments' => [
                'total_received' => round($totalPayments, 2),
                'count' => count($payments['results'] ?? []),
            ],
            'data_sources' => [
                'orders_count' => count($orders['results'] ?? []),
                'ml_billing_count' => count($mlBilling['results'] ?? []),
                'mp_billing_count' => count($mpBilling['results'] ?? []),
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obt\u00e9m descontos aplicados a um pedido
     * Endpoint: GET /orders/{order_id}/discounts
     *
     * @param string $orderId ID do pedido
     * @return array Dados de descontos do pedido
     */
    public function getOrderDiscounts(string $orderId): array
    {
        $client = $this->getClient();

        $response = $client->get("/orders/{$orderId}/discounts");

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Descontos n\u00e3o encontrados',
                'results' => [],
            ];
        }

        $discounts = [];
        $totalDiscount = 0;
        $sellerDiscount = 0;

        foreach ($response['details'] ?? [] as $detail) {
            $type = $detail['type'] ?? 'unknown';
            $items = [];

            foreach ($detail['items'] ?? [] as $item) {
                $total = (float)($item['amounts']['total'] ?? 0);
                $seller = (float)($item['amounts']['seller'] ?? 0);

                $items[] = [
                    'item_id' => $item['id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'total_discount' => $total,
                    'seller_discount' => $seller,
                ];

                $totalDiscount += $total;
                $sellerDiscount += $seller;
            }

            $discount = [
                'type' => $type,
                'items' => $items,
            ];

            // Adicionar dados espec\u00edficos por tipo
            if ($type === 'coupon' && isset($detail['coupon'])) {
                $discount['coupon_id'] = $detail['coupon']['id'] ?? null;
            }

            if (isset($detail['supplier'])) {
                $discount['supplier'] = [
                    'campaign' => $detail['supplier']['meli_campaign'] ?? null,
                    'offer_id' => $detail['supplier']['offer_id'] ?? null,
                    'funding_mode' => $detail['supplier']['funding_mode'] ?? null,
                    'campaign_id' => $detail['supplier']['campaign_id'] ?? null,
                ];
            }

            if ($type === 'cashback' && isset($detail['cashback'])) {
                $discount['cashback_id'] = $detail['cashback']['id'] ?? null;
                if (isset($detail['counter_currency'])) {
                    $discount['counter_currency'] = [
                        'currency_id' => $detail['counter_currency']['currency_id'] ?? null,
                        'value' => $detail['counter_currency']['value'] ?? null,
                    ];
                }
            }

            $discounts[] = $discount;
        }

        return [
            'order_id' => $orderId,
            'discounts' => $discounts,
            'summary' => [
                'total_discount' => round($totalDiscount, 2),
                'seller_discount' => round($sellerDiscount, 2),
                'meli_discount' => round($totalDiscount - $sellerDiscount, 2),
            ],
            'total_discounts' => count($discounts),
        ];
    }

    /**
     * Calcula total da ordem incluindo frete e impostos
     *
     * @param string $orderId ID da ordem
     * @return array Breakdown do total com frete
     */
    public function calculateOrderTotalWithShipping(string $orderId): array
    {
        $client = $this->getClient();

        // Buscar ordem
        $order = $client->get("/orders/{$orderId}");

        if (isset($order['error'])) {
            return [
                'error' => $order['message'] ?? 'Ordem n\u00e3o encontrada',
                'data' => null,
            ];
        }

        $totalAmount = (float)($order['total_amount'] ?? 0);
        $currencyId = $order['currency_id'] ?? 'BRL';
        $taxAmount = (float)($order['taxes']['amount'] ?? 0);
        $taxCurrency = $order['taxes']['currency_id'] ?? $currencyId;

        // Buscar envio se existir
        $shippingCost = 0;
        $shipmentId = $order['shipping']['id'] ?? null;

        if ($shipmentId) {
            $shipment = $client->get("/shipments/{$shipmentId}");
            if (!isset($shipment['error'])) {
                $shippingCost = (float)($shipment['cost_components']['special_discount']
                    ?? $shipment['seller_cost']
                    ?? 0);
            }
        }

        // Converter impostos se necess\u00e1rio
        if ($taxCurrency !== $currencyId && $taxAmount > 0) {
            $conversion = $this->paymentRefund()->getCurrencyConversion($taxCurrency, $currencyId);
            if ($conversion['ratio']) {
                $taxAmount = $taxAmount * $conversion['ratio'];
            }
        }

        $grandTotal = $totalAmount + $taxAmount + $shippingCost;

        return [
            'order_id' => $orderId,
            'breakdown' => [
                'items_total' => round($totalAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'shipping_cost' => round($shippingCost, 2),
            ],
            'grand_total' => round($grandTotal, 2),
            'currency_id' => $currencyId,
        ];
    }

    /**
     * Obt\u00e9m dados completos de uma ordem com todos os campos financeiros
     *
     * @param string $orderId ID da ordem
     * @return array Dados completos da ordem
     */
    public function getCompleteOrderFinancialData(string $orderId): array
    {
        $client = $this->getClient();

        $order = $client->get("/orders/{$orderId}");

        if (isset($order['error'])) {
            return [
                'error' => $order['message'] ?? 'Ordem n\u00e3o encontrada',
                'data' => null,
            ];
        }

        // Extrair itens
        $items = [];
        $totalGrossPrice = 0;

        foreach ($order['order_items'] ?? [] as $item) {
            $unitPrice = (float)($item['unit_price'] ?? 0);
            $fullUnitPrice = (float)($item['full_unit_price'] ?? $unitPrice);
            $quantity = (int)($item['quantity'] ?? 1);
            $grossPrice = (float)($item['gross_price'] ?? ($fullUnitPrice * $quantity));
            $saleFee = (float)($item['sale_fee'] ?? 0);

            $discountFull = 0;
            foreach ($item['discounts'] ?? [] as $discount) {
                $discountFull += (float)($discount['amounts']['full'] ?? 0);
            }

            $items[] = [
                'item_id' => $item['item']['id'] ?? null,
                'title' => $item['item']['title'] ?? null,
                'variation_id' => $item['item']['variation_id'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'full_unit_price' => $fullUnitPrice,
                'gross_price' => $grossPrice,
                'sale_fee' => $saleFee,
                'discount_per_unit' => $discountFull,
                'total_discount' => $discountFull * $quantity,
                'listing_type_id' => $item['listing_type_id'] ?? null,
                'currency_id' => $item['currency_id'] ?? $order['currency_id'] ?? 'BRL',
            ];

            $totalGrossPrice += $grossPrice;
        }

        // Pagamentos
        $payments = [];
        $totalPaid = 0;
        $totalMarketplaceFee = 0;

        foreach ($order['payments'] ?? [] as $payment) {
            $amount = (float)($payment['transaction_amount'] ?? 0);
            $fee = (float)($payment['marketplace_fee'] ?? 0);

            $payments[] = [
                'payment_id' => $payment['id'] ?? null,
                'status' => $payment['status'] ?? null,
                'status_detail' => $payment['status_detail'] ?? null,
                'payment_type' => $payment['payment_type'] ?? null,
                'payment_method_id' => $payment['payment_method_id'] ?? null,
                'transaction_amount' => $amount,
                'total_paid_amount' => (float)($payment['total_paid_amount'] ?? $amount),
                'marketplace_fee' => $fee,
                'installments' => $payment['installments'] ?? 1,
                'date_approved' => $payment['date_approved'] ?? null,
            ];

            if (($payment['status'] ?? '') === 'approved') {
                $totalPaid += $amount;
                $totalMarketplaceFee += $fee;
            }
        }

        $totalAmount = (float)($order['total_amount'] ?? 0);
        $paidAmount = (float)($order['paid_amount'] ?? $totalPaid);
        $totalDiscounts = $totalGrossPrice - $totalAmount;

        return [
            'order_id' => $order['id'] ?? $orderId,
            'status' => $order['status'] ?? null,
            'status_detail' => $order['status_detail'] ?? null,
            'date_created' => $order['date_created'] ?? null,
            'date_closed' => $order['date_closed'] ?? null,
            'buyer' => [
                'id' => $order['buyer']['id'] ?? null,
            ],
            'seller' => [
                'id' => $order['seller']['id'] ?? null,
            ],
            'items' => $items,
            'payments' => $payments,
            'financials' => [
                'currency_id' => $order['currency_id'] ?? 'BRL',
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'gross_amount' => round($totalGrossPrice, 2),
                'total_discounts' => round($totalDiscounts, 2),
                'total_marketplace_fee' => round($totalMarketplaceFee, 2),
                'coupon_amount' => (float)($order['coupon']['amount'] ?? 0),
                'shipping_cost' => (float)($order['shipping_cost'] ?? 0),
                'taxes' => [
                    'amount' => (float)($order['taxes']['amount'] ?? 0),
                    'currency_id' => $order['taxes']['currency_id'] ?? null,
                ],
            ],
            'shipping' => [
                'id' => $order['shipping']['id'] ?? null,
            ],
            'context' => [
                'channel' => $order['context']['channel'] ?? null,
                'site' => $order['context']['site'] ?? null,
                'flows' => $order['context']['flows'] ?? [],
            ],
            'tags' => $order['tags'] ?? [],
            'pack_id' => $order['pack_id'] ?? null,
        ];
    }

    /**
     * Obt\u00e9m dados de produtos em uma ordem (atributos especiais como IMEI)
     * Endpoint: GET /orders/{order_id}/product
     *
     * @param string $orderId ID da ordem
     * @return array Dados do produto
     */
    public function getOrderProductData(string $orderId): array
    {
        $client = $this->getClient();

        $response = $client->get("/orders/{$orderId}/product");

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Dados do produto n\u00e3o encontrados',
                'attributes' => [],
            ];
        }

        $attributes = [];
        foreach ($response['attributes'] ?? [] as $attr) {
            $attributes[] = [
                'id' => $attr['id'] ?? null,
                'name' => $attr['name'] ?? null,
                'value' => $attr['value'] ?? null,
            ];
        }

        return [
            'order_id' => $orderId,
            'attributes' => $attributes,
            'total_attributes' => count($attributes),
        ];
    }

    /**
     * Obt\u00e9m dados fiscais completos de um pedido para emiss\u00e3o de NF
     *
     * @param string $orderId ID do pedido
     * @return array Dados fiscais do pedido
     */
    public function getOrderFiscalData(string $orderId): array
    {
        $client = $this->getClient();

        // Buscar dados completos da ordem
        $order = $client->get("/orders/{$orderId}");

        if (isset($order['error'])) {
            return ['error' => $order['message'] ?? 'Erro ao buscar ordem'];
        }

        // Buscar dados do comprador
        $buyerId = $order['buyer']['id'] ?? null;
        $buyer = $buyerId ? $client->get("/users/{$buyerId}") : [];

        // Buscar dados do envio
        $shipmentId = $order['shipping']['id'] ?? null;
        $shipment = $shipmentId ? $client->get("/shipments/{$shipmentId}") : [];

        $items = [];
        foreach ($order['order_items'] ?? [] as $item) {
            $items[] = [
                'sku' => $item['item']['seller_sku'] ?? '',
                'title' => $item['item']['title'] ?? '',
                'quantity' => (int)($item['quantity'] ?? 1),
                'unit_price' => (float)($item['unit_price'] ?? 0),
                'total_price' => (float)($item['unit_price'] ?? 0) * (int)($item['quantity'] ?? 1),
                'category_id' => $item['item']['category_id'] ?? '',
            ];
        }

        return [
            'order_id' => $orderId,
            'order_date' => $order['date_created'] ?? null,
            'marketplace' => 'Mercado Livre',
            'buyer' => [
                'id' => $buyerId,
                'nickname' => $buyer['nickname'] ?? $order['buyer']['nickname'] ?? '',
                'first_name' => $buyer['first_name'] ?? $order['buyer']['first_name'] ?? '',
                'last_name' => $buyer['last_name'] ?? $order['buyer']['last_name'] ?? '',
                'email' => $buyer['email'] ?? '',
                'phone' => $buyer['phone']['number'] ?? '',
                'document' => [
                    'type' => $buyer['identification']['type'] ?? 'CPF',
                    'number' => $buyer['identification']['number'] ?? '',
                ],
            ],
            'shipping_address' => [
                'street' => $shipment['receiver_address']['street_name'] ?? '',
                'number' => $shipment['receiver_address']['street_number'] ?? '',
                'complement' => $shipment['receiver_address']['comment'] ?? '',
                'neighborhood' => $shipment['receiver_address']['neighborhood']['name'] ?? '',
                'city' => $shipment['receiver_address']['city']['name'] ?? '',
                'state' => $shipment['receiver_address']['state']['id'] ?? '',
                'zip_code' => $shipment['receiver_address']['zip_code'] ?? '',
                'country' => 'BR',
            ],
            'items' => $items,
            'totals' => [
                'subtotal' => (float)($order['total_amount'] ?? 0),
                'shipping' => (float)($order['shipping']['cost'] ?? 0),
                'discount' => (float)($order['coupon']['amount'] ?? 0),
                'total' => (float)($order['paid_amount'] ?? $order['total_amount'] ?? 0),
            ],
            'payment_method' => $order['payments'][0]['payment_type'] ?? 'unknown',
        ];
    }

    /**
     * Obt\u00e9m merchant orders do Mercado Pago
     * API: GET /merchant_orders/search
     *
     * @param array $filters Filtros
     * @return array Lista de merchant orders
     */
    public function searchMerchantOrders(array $filters = []): array
    {
        $client = $this->getClient();
        $sellerId = $this->getSellerId();

        $params = [
            'collector_id' => $sellerId,
            'limit' => $filters['limit'] ?? 20,
            'offset' => $filters['offset'] ?? 0,
        ];

        if (!empty($filters['external_reference'])) {
            $params['external_reference'] = $filters['external_reference'];
        }

        $query = http_build_query($params);
        $data = $client->get("/merchant_orders/search?{$query}");

        if ($this->isMerchantOrdersCapabilityUnavailable($data)) {
            return [
                'error' => 'merchant_orders_unavailable',
                'feature_unavailable' => true,
                'total' => 0,
                'elements' => [],
            ];
        }

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao buscar merchant orders'];
        }

        return [
            'total' => $data['total'] ?? 0,
            'elements' => array_map(function (array $order): array {
                return [
                    'id' => $order['id'],
                    'status' => $order['status'] ?? null,
                    'external_reference' => $order['external_reference'] ?? null,
                    'preference_id' => $order['preference_id'] ?? null,
                    'total_amount' => (float)($order['total_amount'] ?? 0),
                    'paid_amount' => (float)($order['paid_amount'] ?? 0),
                    'refunded_amount' => (float)($order['refunded_amount'] ?? 0),
                    'shipping_cost' => (float)($order['shipping_cost'] ?? 0),
                    'date_created' => $order['date_created'] ?? null,
                    'last_updated' => $order['last_updated'] ?? null,
                    'items' => $order['items'] ?? [],
                    'payments' => array_map(function (array $p): array {
                        return [
                            'id' => $p['id'],
                            'status' => $p['status'] ?? null,
                            'transaction_amount' => (float)($p['transaction_amount'] ?? 0),
                        ];
                    }, $order['payments'] ?? []),
                ];
            }, $data['elements'] ?? []),
        ];
    }

    /**
     * Verifica se a resposta da API indica que a capability de orders não está disponível
     */
    private function isOrdersCapabilityUnavailable(array $response): bool
    {
        return isset($response['error'])
            && $response['error'] === 'orders_access_unavailable'
            && ($response['feature'] ?? null) === 'orders'
            && ($response['optional_feature'] ?? false) === true;
    }

    /**
     * Verifica se a resposta da API indica que a capability de merchant_orders não está disponível
     */
    private function isMerchantOrdersCapabilityUnavailable(array $response): bool
    {
        return isset($response['error'])
            && $response['error'] === 'merchant_orders_unavailable'
            && ($response['feature'] ?? null) === 'merchant_orders'
            && ($response['optional_feature'] ?? false) === true;
    }

    /**
     * Remove campos sensíveis de raw_data antes de expor na UI (PATCH 9).
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function sanitizeLedgerEntriesForUi(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (array_key_exists('raw_data', $entry)) {
                $raw = $entry['raw_data'];
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : [];
                }
                $entry['raw_data'] = is_array($raw)
                    ? $this->sanitizeRawFinancialPayload($raw)
                    : null;
                $entry['raw_data_redacted'] = true;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeRawFinancialPayload(array $payload): array
    {
        $sensitiveKeys = [
            'access_token', 'refresh_token', 'password', 'secret', 'authorization',
            'card_number', 'security_code', 'cvv', 'first_six_digits', 'last_four_digits',
            'email', 'phone', 'phone_number', 'doc_number', 'identification',
            'payer', 'collector', 'bank_info', 'account_number', 'routing_number',
            'tax_id', 'cpf', 'cnpj',
        ];

        $clean = [];
        foreach ($payload as $key => $value) {
            $keyLower = strtolower((string)$key);
            $blocked = false;
            foreach ($sensitiveKeys as $sensitive) {
                if ($keyLower === $sensitive || str_contains($keyLower, $sensitive)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                $clean[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitizeRawFinancialPayload($value);
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }
}
