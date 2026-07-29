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

        // Calcular comiss\u00f5es e taxas do pedido
        $orderItems = $order['order_items'] ?? [];
        $subtotal = 0;
        $mlFee = 0;

        foreach ($orderItems as $item) {
            $subtotal += (float)($item['unit_price'] ?? 0) * (int)($item['quantity'] ?? 1);
            $mlFee += (float)($item['sale_fee'] ?? 0);
        }

        // Frete
        $shippingCost = (float)($order['shipping']['cost'] ?? 0);

        return [
            'order_id' => $order['id'] ?? null,
            'status' => $order['status'] ?? 'unknown',
            'date_created' => $order['date_created'] ?? null,
            'date_closed' => $order['date_closed'] ?? null,
            'total_amount' => (float)($order['total_amount'] ?? 0),
            'paid_amount' => $totalPaid,
            'subtotal' => $subtotal,
            'ml_fee' => $mlFee,
            'payment_fee' => $paymentFees,
            'shipping_cost' => $shippingCost,
            'payment_method' => $paymentMethod,
            'buyer_id' => $order['buyer']['id'] ?? null,
            'buyer_nickname' => $order['buyer']['nickname'] ?? null,
            'items' => array_map(fn(array $i): array => [
                'item_id' => $i['item']['id'] ?? null,
                'title' => $i['item']['title'] ?? null,
                'quantity' => (int)($i['quantity'] ?? 1),
                'unit_price' => (float)($i['unit_price'] ?? 0),
                'sale_fee' => (float)($i['sale_fee'] ?? 0),
            ], $orderItems),
        ];
    }

    /**
     * Obt\u00e9m detalhes de um pedido espec\u00edfico com dados financeiros
     * Endpoint: GET /orders/{order_id}
     *
     * @param string $orderId ID do pedido
     * @return array Detalhes financeiros do pedido
     */
    public function getOrderDetails(string $orderId): array
    {
        $client = $this->getClient();
        $response = $client->get("/orders/{$orderId}");

        if (isset($response['error'])) {
            return ['error' => $response['message'] ?? 'Pedido n\u00e3o encontrado'];
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
}
