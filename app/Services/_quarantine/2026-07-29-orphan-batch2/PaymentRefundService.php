<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Services\Financial\HasFinancialDependencies;

/**
 * Serviço de pagamentos, reembolsos e chargebacks.
 * Extraído de FinancialService.
 */
class PaymentRefundService
{
    use HasFinancialDependencies;

    /**
     * Obtém detalhes de um pagamento
     * Endpoint: GET /payments/{id}
     *
     * @param string $paymentId ID do pagamento
     * @return array Detalhes do pagamento
     */
    public function getPaymentDetails(string $paymentId): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get("/payments/{$paymentId}");
        } catch (\Exception $e) {
            log_error('Falha ao buscar detalhes do pagamento', [
                'service' => 'PaymentRefundService',
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }

        if (isset($response['error'])) {
            return ['error' => $response['message'] ?? 'Pagamento não encontrado'];
        }

        return [
            'id' => $response['id'] ?? null,
            'status' => $response['status'] ?? 'unknown',
            'status_detail' => $response['status_detail'] ?? null,
            'transaction_amount' => (float)($response['transaction_amount'] ?? 0),
            'total_paid_amount' => (float)($response['total_paid_amount'] ?? 0),
            'net_received_amount' => (float)($response['net_received_amount'] ?? 0),
            'currency_id' => $response['currency_id'] ?? 'BRL',
            'payment_type' => $response['payment_type_id'] ?? null,
            'payment_method' => $response['payment_method_id'] ?? null,
            'installments' => (int)($response['installments'] ?? 1),
            'fee_details' => $response['fee_details'] ?? [],
            'date_created' => $response['date_created'] ?? null,
            'date_approved' => $response['date_approved'] ?? null,
        ];
    }

    /**
     * Obtém conversão de moedas
     * Endpoint: GET /currency_conversions/search
     *
     * @param string $from Moeda de origem
     * @param string $to Moeda de destino
     * @return array Dados de conversão
     */
    public function getCurrencyConversion(string $from, string $to): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get('/currency_conversions/search', [
                'from' => $from,
                'to' => $to,
            ]);
        } catch (\Exception $e) {
            log_error('Falha ao buscar conversão de moeda', [
                'service' => 'PaymentRefundService',
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'ratio' => null,
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar conversão',
                'ratio' => null,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'ratio' => (float)($response['ratio'] ?? 0),
            'inverse_ratio' => ($response['ratio'] ?? 0) > 0
                ? round(1 / (float)$response['ratio'], 6)
                : 0,
        ];
    }

    /**
     * Obtém detalhes de uma contestação (chargeback)
     * Endpoint: GET /v1/chargebacks/{id}
     *
     * @param string $chargebackId ID da contestação
     * @return array Detalhes da contestação
     */
    public function getChargebackDetails(string $chargebackId): array
    {
        $client = $this->getClient();

        try {
            $data = $client->get("/v1/chargebacks/{$chargebackId}");
        } catch (\Exception $e) {
            log_error('Falha ao buscar detalhes da contestação', [
                'service' => 'PaymentRefundService',
                'chargeback_id' => $chargebackId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao buscar contestação'];
        }

        return [
            'id' => $data['id'] ?? $chargebackId,
            'payments' => $data['payments'] ?? [],
            'currency' => $data['currency'] ?? 'BRL',
            'amount' => (float)($data['amount'] ?? 0),
            'coverage_applied' => $data['coverage_applied'] ?? false,
            'coverage_elegible' => $data['coverage_elegible'] ?? false,
            'documentation_required' => $data['documentation_required'] ?? false,
            'documentation_status' => $data['documentation_status'] ?? null,
            'documentation' => $data['documentation'] ?? [],
            'date_documentation_deadline' => $data['date_documentation_deadline'] ?? null,
            'date_created' => $data['date_created'] ?? null,
            'date_last_updated' => $data['date_last_updated'] ?? null,
            'live_mode' => $data['live_mode'] ?? false,
            'status_interpretation' => $this->interpretChargebackStatus($data),
        ];
    }

    /**
     * Interpreta o status da contestação
     */
    private function interpretChargebackStatus(array $data): array
    {
        $interpretation = [
            'status' => 'unknown',
            'action_required' => false,
            'message' => '',
        ];

        if ($data['documentation_required'] ?? false) {
            $interpretation['status'] = 'documentation_required';
            $interpretation['action_required'] = true;

            $deadline = $data['date_documentation_deadline'] ?? null;
            if ($deadline) {
                $daysRemaining = ceil((strtotime($deadline) - time()) / 86400);
                $interpretation['message'] = "Documentação necessária. Prazo: {$daysRemaining} dias";
            } else {
                $interpretation['message'] = 'Documentação necessária para contestar';
            }
        } elseif (($data['documentation_status'] ?? '') === 'valid') {
            $interpretation['status'] = 'documentation_submitted';
            $interpretation['message'] = 'Documentação enviada e validada, aguardando resolução';
        } elseif ($data['coverage_applied'] ?? false) {
            $interpretation['status'] = 'covered';
            $interpretation['message'] = 'Proteção ao vendedor aplicada';
        }

        return $interpretation;
    }

    /**
     * Busca pagamentos do Mercado Pago
     * API: GET /v1/payments/search
     *
     * @param array $filters Filtros de busca
     * @return array Lista de pagamentos
     */
    public function searchMPPayments(array $filters = []): array
    {
        $client = $this->getClient();

        $params = [
            'sort' => $filters['sort'] ?? 'date_created',
            'criteria' => $filters['criteria'] ?? 'desc',
            'limit' => $filters['limit'] ?? 30,
            'offset' => $filters['offset'] ?? 0,
        ];

        if (!empty($filters['external_reference'])) {
            $params['external_reference'] = $filters['external_reference'];
        }

        if (!empty($filters['status'])) {
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['begin_date'])) {
            $params['range'] = 'date_created';
            $params['begin_date'] = $filters['begin_date'];
            $params['end_date'] = $filters['end_date'] ?? 'NOW';
        }

        $query = http_build_query($params);
        $data = $client->get("/v1/payments/search?{$query}");

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao buscar pagamentos'];
        }

        return [
            'paging' => $data['paging'] ?? ['total' => 0, 'limit' => 30, 'offset' => 0],
            'results' => array_map(function (array $payment): array {
                return [
                    'id' => $payment['id'],
                    'date_created' => $payment['date_created'] ?? null,
                    'date_approved' => $payment['date_approved'] ?? null,
                    'payment_method_id' => $payment['payment_method_id'] ?? null,
                    'payment_type_id' => $payment['payment_type_id'] ?? null,
                    'status' => $payment['status'] ?? null,
                    'status_detail' => $payment['status_detail'] ?? null,
                    'currency_id' => $payment['currency_id'] ?? 'BRL',
                    'description' => $payment['description'] ?? null,
                    'external_reference' => $payment['external_reference'] ?? null,
                    'transaction_amount' => (float)($payment['transaction_amount'] ?? 0),
                    'transaction_amount_refunded' => (float)($payment['transaction_amount_refunded'] ?? 0),
                    'net_received_amount' => (float)($payment['transaction_details']['net_received_amount'] ?? 0),
                    'installments' => (int)($payment['installments'] ?? 1),
                    'payer' => [
                        'id' => $payment['payer']['id'] ?? null,
                        'email' => $payment['payer']['email'] ?? null,
                        'type' => $payment['payer']['type'] ?? null,
                    ],
                ];
            }, $data['results'] ?? []),
        ];
    }

    /**
     * Obtém detalhes de um pagamento específico
     * API: GET /v1/payments/{id}
     *
     * @param string $paymentId ID do pagamento
     * @return array Detalhes do pagamento
     */
    public function getMPPaymentDetails(string $paymentId): array
    {
        $client = $this->getClient();

        $data = $client->get("/v1/payments/{$paymentId}");

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao buscar pagamento'];
        }

        return [
            'id' => $data['id'],
            'date_created' => $data['date_created'] ?? null,
            'date_approved' => $data['date_approved'] ?? null,
            'date_last_updated' => $data['date_last_updated'] ?? null,
            'money_release_date' => $data['money_release_date'] ?? null,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'payment_type_id' => $data['payment_type_id'] ?? null,
            'status' => $data['status'] ?? null,
            'status_detail' => $data['status_detail'] ?? null,
            'currency_id' => $data['currency_id'] ?? 'BRL',
            'description' => $data['description'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'transaction_amount' => (float)($data['transaction_amount'] ?? 0),
            'transaction_amount_refunded' => (float)($data['transaction_amount_refunded'] ?? 0),
            'coupon_amount' => (float)($data['coupon_amount'] ?? 0),
            'transaction_details' => [
                'net_received_amount' => (float)($data['transaction_details']['net_received_amount'] ?? 0),
                'total_paid_amount' => (float)($data['transaction_details']['total_paid_amount'] ?? 0),
                'overpaid_amount' => (float)($data['transaction_details']['overpaid_amount'] ?? 0),
                'installment_amount' => (float)($data['transaction_details']['installment_amount'] ?? 0),
            ],
            'installments' => (int)($data['installments'] ?? 1),
            'payer' => $data['payer'] ?? [],
            'fee_details' => $data['fee_details'] ?? [],
            'charges_details' => $data['charges_details'] ?? [],
            'refunds' => $data['refunds'] ?? [],
            'card' => $data['card'] ?? [],
        ];
    }

    /**
     * Lista reembolsos de um pagamento
     * API: GET /v1/payments/{id}/refunds
     *
     * @param string $paymentId ID do pagamento
     * @return array Lista de reembolsos
     */
    public function getPaymentRefunds(string $paymentId): array
    {
        $client = $this->getClient();

        $data = $client->get("/v1/payments/{$paymentId}/refunds");

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao buscar reembolsos'];
        }

        return array_map(function (array $refund) use ($paymentId): array {
            return [
                'id' => $refund['id'],
                'payment_id' => $paymentId,
                'amount' => (float)($refund['amount'] ?? 0),
                'status' => $refund['status'] ?? null,
                'refund_mode' => $refund['refund_mode'] ?? 'standard',
                'date_created' => $refund['date_created'] ?? null,
                'reason' => $refund['reason'] ?? null,
                'source' => $refund['source'] ?? [],
            ];
        }, is_array($data) ? $data : []);
    }

    /**
     * Cria um reembolso para um pagamento
     * API: POST /v1/payments/{id}/refunds
     *
     * @param string $paymentId ID do pagamento
     * @param float|null $amount Valor do reembolso (null = total)
     * @return array Resultado do reembolso
     */
    public function createRefund(string $paymentId, ?float $amount = null): array
    {
        $client = $this->getClient();

        $body = [];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }

        $data = $client->post("/v1/payments/{$paymentId}/refunds", $body);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao criar reembolso'];
        }

        return [
            'success' => true,
            'id' => $data['id'],
            'payment_id' => $data['payment_id'] ?? $paymentId,
            'amount' => (float)($data['amount'] ?? 0),
            'status' => $data['status'] ?? null,
            'refund_mode' => $data['refund_mode'] ?? 'standard',
            'date_created' => $data['date_created'] ?? null,
        ];
    }

    /**
     * Gera relatório de chargebacks e reembolsos
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Relatório consolidado
     */
    public function getChargebacksRefundsReport(string $startDate, string $endDate): array
    {
        // Buscar pagamentos com reembolsos no período
        $payments = $this->searchMPPayments([
            'begin_date' => $startDate . 'T00:00:00.000Z',
            'end_date' => $endDate . 'T23:59:59.999Z',
            'limit' => 100,
        ]);

        if (isset($payments['error'])) {
            return ['error' => $payments['error']];
        }

        $totalRefunds = 0;
        $totalRefundAmount = 0;
        $refundedPayments = [];

        foreach ($payments['results'] as $payment) {
            if ($payment['transaction_amount_refunded'] > 0) {
                $totalRefunds++;
                $totalRefundAmount += $payment['transaction_amount_refunded'];
                $refundedPayments[] = [
                    'payment_id' => $payment['id'],
                    'original_amount' => $payment['transaction_amount'],
                    'refunded_amount' => $payment['transaction_amount_refunded'],
                    'date' => $payment['date_created'],
                    'status' => $payment['status'],
                ];
            }
        }

        $totalPayments = count($payments['results']);
        $refundRate = $totalPayments > 0 ? ($totalRefunds / $totalPayments) * 100 : 0;

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'summary' => [
                'total_payments' => $totalPayments,
                'payments_with_refunds' => $totalRefunds,
                'total_refund_amount' => $totalRefundAmount,
                'refund_rate' => round($refundRate, 2),
            ],
            'refunded_payments' => $refundedPayments,
            'insights' => $this->getRefundInsights($refundRate, $totalRefundAmount),
        ];
    }

    /**
     * Gera insights sobre reembolsos
     */
    private function getRefundInsights(float $refundRate, float $totalAmount): array
    {
        $insights = [];

        if ($refundRate > 5) {
            $insights[] = [
                'type' => 'warning',
                'message' => 'Taxa de reembolso acima de 5%. Revisar qualidade dos produtos ou descrições.',
            ];
        } elseif ($refundRate > 2) {
            $insights[] = [
                'type' => 'attention',
                'message' => 'Taxa de reembolso entre 2-5%. Monitorar motivos dos pedidos.',
            ];
        } else {
            $insights[] = [
                'type' => 'success',
                'message' => 'Taxa de reembolso saudável (< 2%).',
            ];
        }

        if ($totalAmount > 1000) {
            $insights[] = [
                'type' => 'info',
                'message' => sprintf('Total de R$ %.2f em reembolsos no período.', $totalAmount),
            ];
        }

        return $insights;
    }

    /**
     * Obtém histórico de saques
     *
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return array Histórico de saques
     */
    public function getWithdrawalHistory(int $limit = 20, int $offset = 0): array
    {
        $client = $this->getClient();
        $sellerId = $this->getSellerId();

        // Buscar movimentações de saque
        $query = http_build_query([
            'user_id' => $sellerId,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $data = $client->get("/v1/account/bank_report/list?{$query}");

        if (isset($data['error'])) {
            // Tentar endpoint alternativo
            $data = $client->get("/users/{$sellerId}/mercadopago_account/movements?{$query}");
        }

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao obter histórico de saques'];
        }

        return [
            'total' => $data['paging']['total'] ?? count($data),
            'withdrawals' => array_map(function (array $w): array {
                return [
                    'id' => $w['id'] ?? null,
                    'type' => $w['type'] ?? null,
                    'amount' => (float)($w['amount'] ?? 0),
                    'currency_id' => $w['currency_id'] ?? 'BRL',
                    'status' => $w['status'] ?? null,
                    'date_created' => $w['date_created'] ?? null,
                    'bank_info' => $w['bank_info'] ?? null,
                ];
            }, $data['results'] ?? $data ?? []),
        ];
    }

    /**
     * Busca pagamentos via Mercado Pago client
     *
     * @param array $params Parâmetros de busca
     * @return array Resultados da busca
     */
    public function searchPayments(array $params = []): array
    {
        try {
            $client = $this->getMercadoPagoClient();
            return $client->get('/v1/payments/search', $params);
        } catch (\Exception $e) {
            log_error('FinancialService::searchPayments error', ['error' => $e->getMessage()]);
            return ['results' => [], 'paging' => ['total' => 0]];
        }
    }

    /**
     * Obtém meios de pagamento disponíveis no Mercado Pago
     *
     * @return array Lista de meios de pagamento
     */
    public function getPaymentMethods(): array
    {
        try {
            $client = $this->getMercadoPagoClient();
            return $client->get('/v1/payment_methods');
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Obtém tipos de documento de identificação disponíveis
     *
     * @return array Lista de tipos de identificação
     */
    public function getIdentificationTypes(): array
    {
        try {
            $client = $this->getMercadoPagoClient();
            return $client->get('/v1/identification_types');
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
