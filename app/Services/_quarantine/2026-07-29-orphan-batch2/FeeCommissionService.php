<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Helpers\SessionHelper;
use App\Services\Financial\HasFinancialDependencies;
use PDO;

/**
 * Fee & Commission Service
 *
 * Serviço responsável por taxas, comissões e faturamento (billing).
 * Inclui detalhes de cobrança ML/MP, fulfillment, flex, percepciones,
 * relatórios de pagamento e conciliação.
 */
class FeeCommissionService
{
    use HasFinancialDependencies;

    private ?PaymentRefundService $paymentRefundService = null;

    private function paymentRefund(): PaymentRefundService
    {
        return $this->paymentRefundService ??= new PaymentRefundService($this->accountId);
    }

    /**
     * Obtém informações de billing do vendedor
     *
     * @return array Informações de billing
     */
    public function getBillingInfo(): array
    {
        $sellerId = $this->getSellerId();
        if (!$sellerId) {
            return ['error' => 'Seller ID não encontrado'];
        }

        $client = $this->getClient();
        $response = $client->get("/users/{$sellerId}/billing/info", [], self::CACHE_TTL_MEDIUM);

        if (isset($response['error'])) {
            // Se endpoint não disponível, retornar estrutura vazia
            return [
                'billing_allowed' => false,
                'message' => $response['message'] ?? 'Informações de billing não disponíveis',
            ];
        }

        return $response;
    }

    /**
     * Obtém breakdown detalhado de taxas e comissões
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Breakdown de taxas
     */
    public function getFeesBreakdown(string $startDate, string $endDate): array
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

        $sql = "SELECT
                    COUNT(*) as total_orders,
                    COALESCE(SUM(total_amount), 0) as gross_revenue,
                    COALESCE(SUM(ml_commission), 0) as ml_commission,
                    COALESCE(SUM(payment_fee), 0) as payment_fees,
                    COALESCE(SUM(fixed_fee), 0) as fixed_fees,
                    COALESCE(SUM(shipping_cost), 0) as shipping_cost
                FROM ml_orders
                WHERE {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $grossRevenue = (float)($data['gross_revenue'] ?? 0);
        $mlCommission = (float)($data['ml_commission'] ?? 0);
        $paymentFees = (float)($data['payment_fees'] ?? 0);
        $fixedFees = (float)($data['fixed_fees'] ?? 0);
        $shippingCost = (float)($data['shipping_cost'] ?? 0);

        $totalFees = $mlCommission + $paymentFees + $fixedFees;
        $feeRate = $grossRevenue > 0 ? ($totalFees / $grossRevenue) * 100 : 0;

        return [
            'gross_revenue' => round($grossRevenue, 2),
            'fees' => [
                'ml_commission' => round($mlCommission, 2),
                'payment_fees' => round($paymentFees, 2),
                'fixed_fees' => round($fixedFees, 2),
                'total' => round($totalFees, 2),
            ],
            'shipping_cost' => round($shippingCost, 2),
            'fee_rate' => round($feeRate, 2),
            'breakdown_by_type' => [
                [
                    'type' => 'Comissão ML',
                    'amount' => round($mlCommission, 2),
                    'percentage' => $grossRevenue > 0 ? round(($mlCommission / $grossRevenue) * 100, 2) : 0,
                ],
                [
                    'type' => 'Taxa de Pagamento',
                    'amount' => round($paymentFees, 2),
                    'percentage' => $grossRevenue > 0 ? round(($paymentFees / $grossRevenue) * 100, 2) : 0,
                ],
                [
                    'type' => 'Taxas Fixas',
                    'amount' => round($fixedFees, 2),
                    'percentage' => $grossRevenue > 0 ? round(($fixedFees / $grossRevenue) * 100, 2) : 0,
                ],
            ],
            'total_orders' => (int)($data['total_orders'] ?? 0),
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    /**
     * Obtém detalhes de faturamento do Mercado Livre
     * Endpoint: GET /billing/integration/periods/key/{period}/group/ML/details
     *
     * @param string $periodKey Período no formato YYYY-MM-01
     * @param string $documentType Tipo de documento: BILL ou CREDIT_NOTE
     * @param int $limit Limite de resultados
     * @param int $fromId ID inicial para paginação
     * @return array Detalhes de faturamento ML
     */
    public function getBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150,
        int $fromId = 0
    ): array {
        $client = $this->getClient();

        $params = [
            'document_type' => $documentType,
            'limit' => min(1000, $limit),
        ];

        if ($fromId > 0) {
            $params['from_id'] = $fromId;
        }

        $response = $client->get(
            "/billing/integration/periods/key/{$periodKey}/group/ML/details",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar detalhes de faturamento',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['results'] ?? [] as $item) {
            $chargeInfo = $item['charge_info'] ?? [];
            $salesInfo = $item['sales_info'][0] ?? [];
            $itemsInfo = $item['items_info'][0] ?? [];
            $shippingInfo = $item['shipping_info'] ?? [];
            $discountInfo = $item['discount_info'] ?? [];

            $results[] = [
                'detail_id' => $chargeInfo['detail_id'] ?? null,
                'legal_document_number' => $chargeInfo['legal_document_number'] ?? null,
                'legal_document_status' => $chargeInfo['legal_document_status'] ?? null,
                'transaction_detail' => $chargeInfo['transaction_detail'] ?? null,
                'detail_amount' => (float)($chargeInfo['detail_amount'] ?? 0),
                'detail_type' => $chargeInfo['detail_type'] ?? null,
                'detail_sub_type' => $chargeInfo['detail_sub_type'] ?? null,
                'creation_date_time' => $chargeInfo['creation_date_time'] ?? null,
                'debited_from_operation' => $chargeInfo['debited_from_operation'] ?? null,
                'order_id' => $salesInfo['order_id'] ?? null,
                'operation_id' => $salesInfo['operation_id'] ?? null,
                'sale_date_time' => $salesInfo['sale_date_time'] ?? null,
                'sales_channel' => $salesInfo['sales_channel'] ?? null,
                'payer_nickname' => $salesInfo['payer_nickname'] ?? null,
                'state_name' => $salesInfo['state_name'] ?? null,
                'transaction_amount' => (float)($salesInfo['transaction_amount'] ?? 0),
                'sale_fee' => $salesInfo['sale_fee'] ?? null,
                'item_id' => $itemsInfo['item_id'] ?? null,
                'item_title' => $itemsInfo['item_title'] ?? null,
                'item_type' => $itemsInfo['item_type'] ?? null,
                'item_category' => $itemsInfo['item_category'] ?? null,
                'item_price' => (float)($itemsInfo['item_price'] ?? 0),
                'item_amount' => (int)($itemsInfo['item_amount'] ?? 0),
                'shipping_id' => $shippingInfo['shipping_id'] ?? null,
                'receiver_shipping_cost' => (float)($shippingInfo['receiver_shipping_cost'] ?? 0),
                'discount_amount' => (float)($discountInfo['discount_amount'] ?? 0),
                'discount_reason' => $discountInfo['discount_reason'] ?? null,
                'charge_amount_without_discount' => (float)($discountInfo['charge_amount_without_discount'] ?? 0),
            ];
        }

        return [
            'results' => $results,
            'total' => $response['total'] ?? count($results),
            'last_id' => $response['last_id'] ?? null,
            'offset' => $response['offset'] ?? 0,
            'limit' => $response['limit'] ?? $limit,
            'period' => $periodKey,
            'document_type' => $documentType,
        ];
    }

    /**
     * Obtém detalhes de faturamento do Mercado Pago
     * Endpoint: GET /billing/integration/periods/key/{period}/group/MP/details
     *
     * @param string $periodKey Período no formato YYYY-MM-01
     * @param string $documentType Tipo de documento: BILL ou CREDIT_NOTE
     * @param int $limit Limite de resultados
     * @param int $fromId ID inicial para paginação
     * @return array Detalhes de faturamento MP
     */
    public function getMercadoPagoBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150,
        int $fromId = 0
    ): array {
        $client = $this->getClient();

        $params = [
            'document_type' => $documentType,
            'limit' => min(1000, $limit),
        ];

        if ($fromId > 0) {
            $params['from_id'] = $fromId;
        }

        $response = $client->get(
            "/billing/integration/periods/key/{$periodKey}/group/MP/details",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar detalhes de faturamento MP',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['results'] ?? [] as $item) {
            $chargeInfo = $item['charge_info'] ?? [];
            $operationInfo = $item['operation_info'] ?? [];
            $perceptionInfo = $item['perception_info'] ?? [];

            $results[] = [
                'detail_id' => $chargeInfo['detail_id'] ?? null,
                'movement_id' => $chargeInfo['movement_id'] ?? null,
                'legal_document_number' => $chargeInfo['legal_document_number'] ?? null,
                'legal_document_status' => $chargeInfo['legal_document_status'] ?? null,
                'transaction_detail' => $chargeInfo['transaction_detail'] ?? null,
                'detail_amount' => (float)($chargeInfo['detail_amount'] ?? 0),
                'detail_type' => $chargeInfo['detail_type'] ?? null,
                'detail_sub_type' => $chargeInfo['detail_sub_type'] ?? null,
                'creation_date_time' => $chargeInfo['creation_date_time'] ?? null,
                'debited_from_operation' => $chargeInfo['debited_from_operation'] ?? null,
                'status' => $chargeInfo['status'] ?? null,
                'status_description' => $chargeInfo['status_description'] ?? null,
                'operation_type' => $operationInfo['operation_type'] ?? null,
                'operation_type_description' => $operationInfo['operation_type_description'] ?? null,
                'reference_id' => $operationInfo['reference_id'] ?? null,
                'sales_channel' => $operationInfo['sales_channel'] ?? null,
                'external_reference' => $operationInfo['external_reference'] ?? null,
                'payer_nickname' => $operationInfo['payer_nickname'] ?? null,
                'transaction_amount' => (float)($operationInfo['transaction_amount'] ?? 0),
                'store_id' => $operationInfo['store_id'] ?? null,
                'store_name' => $operationInfo['store_name'] ?? null,
                'perception_aliquot' => $perceptionInfo['aliquot'] ?? null,
                'perception_taxable_amount' => (float)($perceptionInfo['taxable_amount'] ?? 0),
            ];
        }

        return [
            'results' => $results,
            'total' => $response['total'] ?? count($results),
            'last_id' => $response['last_id'] ?? null,
            'period' => $periodKey,
            'document_type' => $documentType,
        ];
    }

    /**
     * Obtém relatório de faturamento por order/pack específico
     * Endpoint: GET /billing/integration/group/ML/order/details
     *
     * @param array $orderIds Array de IDs de orders
     * @param string|null $packId ID do pack (opcional)
     * @return array Detalhes de faturamento por order
     */
    public function getBillingByOrder(array $orderIds, ?string $packId = null): array
    {
        $client = $this->getClient();

        $params = [];
        if (!empty($orderIds)) {
            $params['order_ids'] = implode(',', $orderIds);
        }
        if ($packId) {
            $params['pack_id'] = $packId;
        }

        $response = $client->get('/billing/integration/group/ML/order/details', $params);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar faturamento por order',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['results'] ?? [] as $orderData) {
            $orderId = $orderData['order_id'] ?? null;
            $paymentInfo = $orderData['payment_info'][0] ?? [];
            $saleFee = $orderData['sale_fee'] ?? [];
            $details = $orderData['details'] ?? [];

            $charges = [];
            foreach ($details as $detail) {
                $chargeInfo = $detail['charge_info'] ?? [];
                $salesInfo = $detail['sales_info'][0] ?? [];
                $itemsInfo = $detail['items_info'][0] ?? [];
                $discountInfo = $detail['discount_info'] ?? [];

                $charges[] = [
                    'detail_id' => $chargeInfo['detail_id'] ?? null,
                    'transaction_detail' => $chargeInfo['transaction_detail'] ?? null,
                    'detail_amount' => (float)($chargeInfo['detail_amount'] ?? 0),
                    'detail_type' => $chargeInfo['detail_type'] ?? null,
                    'detail_sub_type' => $chargeInfo['detail_sub_type'] ?? null,
                    'debited_from_operation' => $chargeInfo['debited_from_operation'] ?? null,
                    'item_id' => $itemsInfo['item_id'] ?? null,
                    'item_title' => $itemsInfo['item_title'] ?? null,
                    'discount_amount' => (float)($discountInfo['discount_amount'] ?? 0),
                    'discount_reason' => $discountInfo['discount_reason'] ?? null,
                ];
            }

            // Extrair informações de impostos (tax_details)
            $taxDetails = [];
            foreach ($paymentInfo['tax_details'] ?? [] as $tax) {
                $taxDetails[] = [
                    'type' => $tax['mov_detail'] ?? null,
                    'entity' => $tax['mov_financial_entity'] ?? null,
                    'amount' => (float)($tax['original_amount'] ?? 0),
                    'refunded' => (float)($tax['refunded_amount'] ?? 0),
                    'status' => $tax['tax_status'] ?? null,
                ];
            }

            $results[] = [
                'order_id' => $orderId,
                'payment' => [
                    'payment_id' => $paymentInfo['payment_id'] ?? null,
                    'status' => $paymentInfo['status'] ?? null,
                    'payment_method_id' => $paymentInfo['payment_method_id'] ?? null,
                    'payment_type_id' => $paymentInfo['payment_type_id'] ?? null,
                    'date_approved' => $paymentInfo['date_approved'] ?? null,
                    'date_created' => $paymentInfo['date_created'] ?? null,
                    'money_release_date' => $paymentInfo['money_release_date'] ?? null,
                    'money_release_days' => (int)($paymentInfo['money_release_days'] ?? 0),
                    'money_release_status' => $paymentInfo['money_release_status'] ?? null,
                ],
                'sale_fee' => [
                    'gross' => (float)($saleFee['gross'] ?? 0),
                    'net' => (float)($saleFee['net'] ?? 0),
                    'rebate' => (float)($saleFee['rebate'] ?? 0),
                    'discount' => (float)($saleFee['discount'] ?? 0),
                    'discount_reason' => $saleFee['discount_reason'] ?? null,
                ],
                'taxes' => $taxDetails,
                'charges' => $charges,
            ];
        }

        return [
            'results' => $results,
            'total' => count($results),
        ];
    }

    /**
     * Obtém detalhes de fulfillment (envios Full)
     * Endpoint: GET /billing/integration/periods/key/{period}/group/ML/full/details
     *
     * @param string $periodKey Período no formato YYYY-MM-01
     * @param string $documentType Tipo de documento: BILL ou CREDIT_NOTE
     * @param int $limit Limite de resultados
     * @return array Detalhes de fulfillment
     */
    public function getFulfillmentBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150
    ): array {
        $client = $this->getClient();

        $params = [
            'document_type' => $documentType,
            'limit' => min(1000, $limit),
        ];

        $response = $client->get(
            "/billing/integration/periods/key/{$periodKey}/group/ML/full/details",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar detalhes de fulfillment',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['results'] ?? [] as $item) {
            $chargeInfo = $item['charge_info'] ?? [];
            $fulfillmentInfo = $item['fulfillment_info'] ?? [];

            $results[] = [
                'detail_id' => $chargeInfo['detail_id'] ?? null,
                'legal_document_number' => $chargeInfo['legal_document_number'] ?? null,
                'transaction_detail' => $chargeInfo['transaction_detail'] ?? null,
                'detail_amount' => (float)($chargeInfo['detail_amount'] ?? 0),
                'detail_type' => $chargeInfo['detail_type'] ?? null,
                'detail_sub_type' => $chargeInfo['detail_sub_type'] ?? null,
                'concept_type' => $chargeInfo['concept_type'] ?? null,
                'creation_date_time' => $chargeInfo['creation_date_time'] ?? null,
                'fulfillment_type' => $fulfillmentInfo['type'] ?? null,
                'amount_per_unit' => (float)($fulfillmentInfo['amount_per_unit'] ?? 0),
                'fulfillment_amount' => (float)($fulfillmentInfo['amount'] ?? 0),
                'sku' => $fulfillmentInfo['sku'] ?? null,
                'ean' => $fulfillmentInfo['ean'] ?? null,
                'item_id' => $fulfillmentInfo['item_id'] ?? null,
                'item_title' => $fulfillmentInfo['item_title'] ?? null,
                'variation' => $fulfillmentInfo['variation'] ?? null,
                'quantity' => (int)($fulfillmentInfo['quantity'] ?? 0),
                'volume_type' => $fulfillmentInfo['volume_type'] ?? null,
                'inventory_id' => $fulfillmentInfo['inventory_id'] ?? null,
                'space' => $fulfillmentInfo['space'] ?? null,
                'inbound_id' => $fulfillmentInfo['inbound_id'] ?? null,
            ];
        }

        return [
            'results' => $results,
            'total' => $response['total'] ?? count($results),
            'last_id' => $response['last_id'] ?? null,
            'period' => $periodKey,
            'document_type' => $documentType,
        ];
    }

    /**
     * Obtém detalhes de Mercado Envios Flex
     * Endpoint: GET /billing/integration/periods/key/{period}/group/ML/flex/details
     *
     * @param string $periodKey Período no formato YYYY-MM-01
     * @param string $documentType Tipo de documento: BILL ou CREDIT_NOTE
     * @param int $limit Limite de resultados
     * @return array Detalhes de envios Flex
     */
    public function getFlexShippingBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150
    ): array {
        $client = $this->getClient();

        $params = [
            'document_type' => $documentType,
            'limit' => min(1000, $limit),
        ];

        $response = $client->get(
            "/billing/integration/periods/key/{$periodKey}/group/ML/flex/details",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar detalhes de Flex',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['results'] ?? [] as $item) {
            $chargeInfo = $item['charge_info'] ?? [];
            $shippingInfo = $item['shipping_info'] ?? [];
            $orderInfo = $shippingInfo['order'] ?? [];

            $results[] = [
                'detail_id' => $chargeInfo['detail_id'] ?? null,
                'detail_associated_id' => $chargeInfo['detail_associated_id'] ?? null,
                'legal_document_number' => $chargeInfo['legal_document_number'] ?? null,
                'transaction_detail' => $chargeInfo['transaction_detail'] ?? null,
                'detail_amount' => (float)($chargeInfo['detail_amount'] ?? 0),
                'detail_type' => $chargeInfo['detail_type'] ?? null,
                'detail_sub_type' => $chargeInfo['detail_sub_type'] ?? null,
                'concept_type' => $chargeInfo['concept_type'] ?? null,
                'creation_date_time' => $chargeInfo['creation_date_time'] ?? null,
                'shipping_id' => $shippingInfo['shipping_id'] ?? null,
                'receiver_nickname' => $shippingInfo['receiver_nickname'] ?? null,
                'pack_id' => $shippingInfo['pack_id'] ?? null,
                'receiver_shipping_cost' => (float)($shippingInfo['receiver_shipping_cost'] ?? 0),
                'order_id' => $orderInfo['order_id'] ?? null,
                'order_date_created' => $orderInfo['date_created'] ?? null,
                'order_total_amount' => (float)($orderInfo['total_amount'] ?? 0),
                'payment_id' => $orderInfo['payment_id'] ?? null,
                'buyer_nickname' => $orderInfo['buyer_nickname'] ?? null,
            ];
        }

        return [
            'results' => $results,
            'total' => $response['total'] ?? count($results),
            'last_id' => $response['last_id'] ?? null,
            'period' => $periodKey,
            'document_type' => $documentType,
        ];
    }

    /**
     * Obtém resumo consolidado de faturamento do período
     * Combina dados de ML, MP, Full e Flex
     *
     * @param string $periodKey Período no formato YYYY-MM-01
     * @return array Resumo consolidado
     */
    public function getBillingPeriodSummary(string $periodKey): array
    {
        // Buscar dados de todas as fontes
        $mlBilling = $this->getBillingDetails($periodKey, 'BILL', 1000);
        $mpBilling = $this->getMercadoPagoBillingDetails($periodKey, 'BILL', 1000);
        $fullBilling = $this->getFulfillmentBillingDetails($periodKey, 'BILL', 1000);
        $flexBilling = $this->getFlexShippingBillingDetails($periodKey, 'BILL', 1000);

        // Calcular totais de ML
        $mlTotal = 0;
        $mlChargeTypes = [];
        foreach ($mlBilling['results'] ?? [] as $item) {
            $amount = $item['detail_amount'] ?? 0;
            $mlTotal += $amount;
            $subType = $item['detail_sub_type'] ?? 'OTHER';
            $mlChargeTypes[$subType] = ($mlChargeTypes[$subType] ?? 0) + $amount;
        }

        // Calcular totais de MP
        $mpTotal = 0;
        foreach ($mpBilling['results'] ?? [] as $item) {
            $mpTotal += $item['detail_amount'] ?? 0;
        }

        // Calcular totais de Fulfillment
        $fullTotal = 0;
        $fullTypes = [];
        foreach ($fullBilling['results'] ?? [] as $item) {
            $amount = $item['detail_amount'] ?? 0;
            $fullTotal += $amount;
            $type = $item['fulfillment_type'] ?? 'OTHER';
            $fullTypes[$type] = ($fullTypes[$type] ?? 0) + $amount;
        }

        // Calcular totais de Flex
        $flexTotal = 0;
        foreach ($flexBilling['results'] ?? [] as $item) {
            $flexTotal += $item['detail_amount'] ?? 0;
        }

        $grandTotal = $mlTotal + $mpTotal + $fullTotal + $flexTotal;

        return [
            'period' => $periodKey,
            'summary' => [
                'mercado_libre' => [
                    'total' => round($mlTotal, 2),
                    'count' => count($mlBilling['results'] ?? []),
                    'by_type' => $mlChargeTypes,
                ],
                'mercado_pago' => [
                    'total' => round($mpTotal, 2),
                    'count' => count($mpBilling['results'] ?? []),
                ],
                'fulfillment' => [
                    'total' => round($fullTotal, 2),
                    'count' => count($fullBilling['results'] ?? []),
                    'by_type' => $fullTypes,
                ],
                'flex_shipping' => [
                    'total' => round($flexTotal, 2),
                    'count' => count($flexBilling['results'] ?? []),
                ],
                'grand_total' => round($grandTotal, 2),
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtém dados de informação fiscal do comprador para faturamento
     * Endpoint: GET /orders/billing-info/{site_id}/{billing_info_id}
     *
     * @param string $orderId ID da order para obter billing_info_id
     * @return array Dados fiscais do comprador
     */
    public function getBuyerBillingInfo(string $orderId): array
    {
        $client = $this->getClient();

        // Primeiro, obter a order para pegar o billing_info_id
        $orderResponse = $client->get("/orders/{$orderId}");

        if (isset($orderResponse['error'])) {
            return [
                'error' => $orderResponse['message'] ?? 'Order não encontrada',
                'data' => null,
            ];
        }

        $billingInfoId = $orderResponse['buyer']['billing_info']['doc_number'] ?? null;
        $siteId = 'MLB'; // Brasil

        // Tentar obter do billing_info da order
        $billingInfo = $orderResponse['buyer']['billing_info'] ?? [];

        if (empty($billingInfo)) {
            return [
                'error' => 'Dados fiscais não disponíveis para esta order',
                'data' => null,
            ];
        }

        // Retornar dados disponíveis
        return [
            'order_id' => $orderId,
            'buyer_id' => $orderResponse['buyer']['id'] ?? null,
            'buyer_nickname' => $orderResponse['buyer']['nickname'] ?? null,
            'billing_info' => [
                'doc_type' => $billingInfo['doc_type'] ?? null,
                'doc_number' => $billingInfo['doc_number'] ?? null,
                'first_name' => $billingInfo['first_name'] ?? null,
                'last_name' => $billingInfo['last_name'] ?? null,
                'additional_info' => $billingInfo['additional_info'] ?? [],
            ],
            'shipping_address' => $orderResponse['shipping']['receiver_address'] ?? [],
        ];
    }

    /**
     * Obtém detalhes de taxas específicas de uma venda (sale_fee)
     * Usado para obter breakdown detalhado das taxas do ML
     *
     * @param string $orderId ID da order
     * @return array Detalhes das taxas
     */
    public function getOrderSaleFeeDetails(string $orderId): array
    {
        $client = $this->getClient();

        // Obter order para extrair sale_fee e marketplace_fee
        $orderResponse = $client->get("/orders/{$orderId}");

        if (isset($orderResponse['error'])) {
            return [
                'error' => $orderResponse['message'] ?? 'Order não encontrada',
                'data' => null,
            ];
        }

        $totalAmount = (float)($orderResponse['total_amount'] ?? 0);
        $payments = $orderResponse['payments'] ?? [];

        $paymentDetails = [];
        $totalFees = 0;
        $totalReceived = 0;

        foreach ($payments as $payment) {
            $paymentId = $payment['id'] ?? null;
            $transactionAmount = (float)($payment['transaction_amount'] ?? 0);

            // Buscar detalhes do pagamento
            $feeDetails = [];
            if ($paymentId) {
                $paymentData = $this->paymentRefund()->getPaymentDetails($paymentId);
                $feeDetails = $paymentData['fee_details'] ?? [];
                $totalReceived += (float)($paymentData['net_received_amount'] ?? 0);
            }

            $fees = 0;
            $feeBreakdown = [];
            foreach ($feeDetails as $fee) {
                $feeAmount = (float)($fee['amount'] ?? 0);
                $fees += $feeAmount;
                $feeBreakdown[] = [
                    'type' => $fee['type'] ?? 'unknown',
                    'fee_payer' => $fee['fee_payer'] ?? null,
                    'amount' => $feeAmount,
                ];
            }
            $totalFees += $fees;

            $paymentDetails[] = [
                'payment_id' => $paymentId,
                'status' => $payment['status'] ?? null,
                'transaction_amount' => $transactionAmount,
                'marketplace_fee' => (float)($payment['marketplace_fee'] ?? 0),
                'fee_details' => $feeBreakdown,
                'total_fees' => $fees,
            ];
        }

        // Obter order items para sale_fee individual
        $orderItems = $orderResponse['order_items'] ?? [];
        $itemFees = [];
        foreach ($orderItems as $orderItem) {
            $itemFees[] = [
                'item_id' => $orderItem['item']['id'] ?? null,
                'title' => $orderItem['item']['title'] ?? null,
                'quantity' => (int)($orderItem['quantity'] ?? 1),
                'unit_price' => (float)($orderItem['unit_price'] ?? 0),
                'sale_fee' => (float)($orderItem['sale_fee'] ?? 0),
                'listing_type_id' => $orderItem['listing_type_id'] ?? null,
            ];
        }

        return [
            'order_id' => $orderId,
            'total_amount' => $totalAmount,
            'total_fees' => round($totalFees, 2),
            'net_received' => round($totalReceived, 2),
            'effective_fee_rate' => $totalAmount > 0 ? round(($totalFees / $totalAmount) * 100, 2) : 0,
            'payments' => $paymentDetails,
            'items' => $itemFees,
        ];
    }

    /**
     * Gera relatório de conciliação para um período
     * Compara dados do ML com dados locais para identificar divergências
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Relatório de conciliação
     */
    public function generateReconciliationReport(string $startDate, string $endDate): array
    {
        // Dados da API
        $periodKey = date('Y-m-01', strtotime($startDate));
        $apiBilling = $this->getBillingDetails($periodKey, 'BILL', 1000);

        // Agrupar dados da API por order_id
        $apiByOrder = [];
        foreach ($apiBilling['results'] ?? [] as $item) {
            $orderId = $item['order_id'] ?? null;
            if ($orderId) {
                if (!isset($apiByOrder[$orderId])) {
                    $apiByOrder[$orderId] = [
                        'charges' => 0,
                        'transaction_amount' => $item['transaction_amount'] ?? 0,
                        'details' => [],
                    ];
                }
                $apiByOrder[$orderId]['charges'] += $item['detail_amount'] ?? 0;
                $apiByOrder[$orderId]['details'][] = $item;
            }
        }

        // Dados locais
        $whereConditions = [
            'date_created BETWEEN :start AND :end',
        ];
        $params = [':start' => $startDate, ':end' => $endDate . ' 23:59:59'];

        if ($this->accountId) {
            $whereConditions[] = 'ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        $whereSql = implode(' AND ', $whereConditions);

        $sql = "SELECT ml_order_id, total_amount, ml_commission, payment_fee, shipping_cost, status
                FROM ml_orders WHERE {$whereSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $localOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Comparar e identificar divergências
        $divergences = [];
        $matched = 0;
        $localTotal = 0;
        $apiTotal = 0;

        foreach ($localOrders as $localOrder) {
            $orderId = $localOrder['ml_order_id'];
            $localFees = (float)$localOrder['ml_commission'] + (float)$localOrder['payment_fee'];
            $localTotal += $localFees;

            if (isset($apiByOrder[$orderId])) {
                $apiFees = $apiByOrder[$orderId]['charges'];
                $apiTotal += $apiFees;

                $diff = abs($localFees - $apiFees);
                if ($diff > 0.01) { // Diferença maior que 1 centavo
                    $divergences[] = [
                        'order_id' => $orderId,
                        'local_fees' => round($localFees, 2),
                        'api_fees' => round($apiFees, 2),
                        'difference' => round($diff, 2),
                        'status' => $localOrder['status'],
                    ];
                } else {
                    $matched++;
                }
                unset($apiByOrder[$orderId]);
            } else {
                $divergences[] = [
                    'order_id' => $orderId,
                    'local_fees' => round($localFees, 2),
                    'api_fees' => 0,
                    'difference' => round($localFees, 2),
                    'status' => $localOrder['status'],
                    'issue' => 'Não encontrada na API',
                ];
            }
        }

        // Orders na API mas não no local
        foreach ($apiByOrder as $orderId => $apiData) {
            $divergences[] = [
                'order_id' => $orderId,
                'local_fees' => 0,
                'api_fees' => round($apiData['charges'], 2),
                'difference' => round($apiData['charges'], 2),
                'issue' => 'Não encontrada no banco local',
            ];
            $apiTotal += $apiData['charges'];
        }

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'summary' => [
                'total_orders_local' => count($localOrders),
                'total_orders_api' => count($apiBilling['results'] ?? []),
                'matched' => $matched,
                'divergences_count' => count($divergences),
                'local_total_fees' => round($localTotal, 2),
                'api_total_fees' => round($apiTotal, 2),
                'total_difference' => round(abs($localTotal - $apiTotal), 2),
            ],
            'divergences' => array_slice($divergences, 0, 100), // Limitar a 100
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtém resumo de percepciones (impostos retidos) do período
     * Endpoint: GET /billing/integration/periods/key/{period}/perceptions/summary
     * Aplica apenas para Argentina
     *
     * @param string $periodKey Período no formato YYYY-MM-01
     * @param string $group Grupo: ML ou MP
     * @return array Resumo de percepciones
     */
    public function getPerceptionsSummary(string $periodKey, string $group = 'ML'): array
    {
        $client = $this->getClient();

        $params = ['group' => $group];

        $response = $client->get(
            "/billing/integration/periods/key/{$periodKey}/perceptions/summary",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar percepciones',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['summary'] ?? [] as $item) {
            $results[] = [
                'document_id' => $item['document_id'] ?? null,
                'society' => $item['society'] ?? null,
                'legal_document_number' => $item['legal_document_number'] ?? null,
                'user_fiscal_condition' => $item['user_fiscal_condition'] ?? null,
                'amount' => (float)($item['amount'] ?? 0),
                'regimen_tax_type' => $item['regimen_tax_type'] ?? null,
                'regimen_tax_type_description' => $item['regimen_tax_type_description'] ?? null,
                'taxable_amount' => (float)($item['taxable_amount'] ?? 0),
                'aliquot' => (float)($item['aliquot'] ?? 0),
                'coefficient' => (float)($item['coefficient'] ?? 1),
                'perception_charge_number' => $item['perception_charge_number'] ?? null,
                'tax_type' => $item['tax_type'] ?? null,
                'tax_type_description' => $item['tax_type_description'] ?? null,
                'bill_date' => $item['bill_date'] ?? null,
                'status' => $item['status'] ?? null,
                'status_description' => $item['status_description'] ?? null,
                'tax_ids' => $item['tax_ids'] ?? [],
            ];
        }

        return [
            'results' => $results,
            'total' => count($results),
            'period' => $periodKey,
            'group' => $group,
        ];
    }

    /**
     * Obtém detalhes de uma percepção específica (impostos retidos)
     * Endpoint: GET /billing/integration/group/{group}/perceptions/details
     *
     * @param string $group Grupo: ML ou MP
     * @param int $documentId ID do documento
     * @param string $taxType Tipo de imposto (CIVA, CIVAMP, etc)
     * @param int|null $taxId ID do imposto (necessário para MP)
     * @param int $limit Limite de resultados
     * @return array Detalhes das percepciones
     */
    public function getPerceptionsDetails(
        string $group,
        int $documentId,
        string $taxType,
        ?int $taxId = null,
        int $limit = 150
    ): array {
        $client = $this->getClient();

        $params = [
            'document_id' => $documentId,
            'tax_type' => $taxType,
            'limit' => min(1000, $limit),
        ];

        if ($taxId !== null && $group === 'MP') {
            $params['tax_id'] = $taxId;
        }

        $response = $client->get(
            "/billing/integration/group/{$group}/perceptions/details",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar detalhes de percepciones',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['results'] ?? [] as $item) {
            $results[] = [
                'detail_id' => $item['detail_id'] ?? null,
                'date_created' => $item['date_created'] ?? null,
                'taxable_amount' => (float)($item['taxable_amount'] ?? 0),
                'aliquot' => (float)($item['aliquot'] ?? 0),
                'tax_amount' => (float)($item['tax_amount'] ?? 0),
                'transaction_detail' => $item['transaction_detail'] ?? null,
                'transaction_detail_description' => $item['transaction_detail_description'] ?? null,
                'charge_bonified_id' => $item['charge_bonified_id'] ?? null,
                'amount' => (float)($item['amount'] ?? 0),
                'gross_amount' => (float)($item['gross_amount'] ?? 0),
                'detail_type' => $item['detail_type'] ?? null,
                'detail_type_description' => $item['detail_type_description'] ?? null,
                // Campos adicionais para Régimen Especial
                'publish_number' => $item['publish_number'] ?? null,
                'publish_title' => $item['publish_title'] ?? null,
                'sale_date' => $item['sale_date'] ?? null,
                'sale_number' => $item['sale_number'] ?? null,
                'buyer_name' => $item['buyer_name'] ?? null,
                'buyer_state_name' => $item['buyer_state_name'] ?? null,
                // Campo adicional para Régimen Tucumán
                'coefficient' => $item['coefficient'] ?? null,
                // Campos adicionais para MP
                'movement_id' => $item['movement_id'] ?? null,
                'reference_id' => $item['reference_id'] ?? null,
            ];
        }

        return [
            'results' => $results,
            'total' => $response['total'] ?? count($results),
            'offset' => $response['offset'] ?? 0,
            'group' => $group,
            'document_id' => $documentId,
            'tax_type' => $taxType,
        ];
    }

    /**
     * Obtém relatório de pagamentos realizados no período
     * Endpoint: GET /billing/integration/periods/key/{period}/group/ML/payment/details
     *
     * @param string $periodKey Período no formato YYYY-MM-01
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return array Detalhes dos pagamentos
     */
    public function getPaymentReport(string $periodKey, int $limit = 150, int $offset = 0): array
    {
        $client = $this->getClient();

        $params = [
            'limit' => min(1000, $limit),
            'offset' => $offset,
        ];

        $response = $client->get(
            "/billing/integration/periods/key/{$periodKey}/group/ML/payment/details",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar relatório de pagamentos',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['results'] ?? [$response] as $item) {
            $paymentInfo = $item['payment_info'] ?? $item;
            $results[] = [
                'payment_id' => $paymentInfo['payment_id'] ?? null,
                'credit_note_number' => $paymentInfo['credit_note_number'] ?? null,
                'payment_date' => $paymentInfo['payment_date'] ?? null,
                'payment_type' => $paymentInfo['payment_type'] ?? null,
                'payment_type_description' => $paymentInfo['payment_type_description'] ?? null,
                'payment_method' => $paymentInfo['payment_method'] ?? null,
                'payment_method_description' => $paymentInfo['payment_method_description'] ?? null,
                'payment_status' => $paymentInfo['payment_status'] ?? null,
                'payment_status_description' => $paymentInfo['payment_status_description'] ?? null,
                'payment_amount' => (float)($paymentInfo['payment_amount'] ?? 0),
                'amount_in_this_period' => (float)($paymentInfo['amount_in_this_period'] ?? 0),
                'amount_in_other_period' => (float)($paymentInfo['amount_in_other_period'] ?? 0),
                'remaining_amount' => (float)($paymentInfo['remaining_amount'] ?? 0),
                'return_amount' => (float)($paymentInfo['return_amount'] ?? 0),
            ];
        }

        return [
            'results' => $results,
            'total' => count($results),
            'period' => $periodKey,
        ];
    }

    /**
     * Obtém detalhes de cargos associados a um pagamento
     * Endpoint: GET /billing/integration/payment/{payment_id}/charges
     *
     * @param string $paymentId ID do pagamento
     * @param int $limit Limite de resultados
     * @return array Detalhes dos cargos
     */
    public function getPaymentChargesDetail(string $paymentId, int $limit = 150): array
    {
        $client = $this->getClient();

        $params = ['limit' => min(1000, $limit)];

        $response = $client->get(
            "/billing/integration/payment/{$paymentId}/charges",
            $params
        );

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar detalhes do pagamento',
                'results' => [],
            ];
        }

        $results = [];
        foreach ($response['payment_details'] ?? [] as $item) {
            $paymentInfo = $item['payment_info'] ?? [];
            $chargeInfo = $item['charge_info'] ?? [];

            $results[] = [
                'payment_id' => $paymentInfo['payment_id'] ?? null,
                'payment_date' => $paymentInfo['payment_date'] ?? null,
                'association_amount' => (float)($paymentInfo['association_amount'] ?? 0),
                'payment_amount' => (float)($paymentInfo['payment_amount'] ?? 0),
                'charge_detail_id' => $chargeInfo['detail_id'] ?? null,
                'charge_description' => $chargeInfo['detail_description'] ?? null,
                'charge_date' => $chargeInfo['detail_date'] ?? null,
            ];
        }

        return [
            'results' => $results,
            'total' => count($results),
            'payment_id' => $paymentId,
        ];
    }
}
