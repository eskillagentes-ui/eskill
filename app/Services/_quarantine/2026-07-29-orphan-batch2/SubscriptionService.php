<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use App\Services\Financial\HasFinancialDependencies;

/**
 * Serviço de assinaturas e planos recorrentes (Mercado Pago).
 * Extraído de FinancialService.
 */
class SubscriptionService
{
    use HasFinancialDependencies;

    // =========================================================================
    // MERCADO PAGO - ASSINATURAS (PREAPPROVAL)
    // =========================================================================

    /**
     * Cria uma assinatura no MP
     * POST /preapproval
     *
     * @param array $subscriptionData Dados da assinatura
     * @return array Assinatura criada
     */
    public function createSubscription(array $subscriptionData): array
    {
        $client = $this->getMercadoPagoClient();

        // Estrutura padrão da assinatura
        $payload = [
            'payer_email' => $subscriptionData['payer_email'],
            'back_url' => $subscriptionData['back_url'] ?? ($_ENV['APP_URL'] ?? 'https://eskill.com.br') . '/subscriptions/callback',
            'status' => $subscriptionData['status'] ?? 'pending',
        ];

        // Assinatura com plano existente
        if (!empty($subscriptionData['preapproval_plan_id'])) {
            $payload['preapproval_plan_id'] = $subscriptionData['preapproval_plan_id'];
        } else {
            // Assinatura sem plano (valores customizados)
            $payload['reason'] = $subscriptionData['reason'];
            $payload['external_reference'] = $subscriptionData['external_reference'] ?? null;
            $payload['auto_recurring'] = [
                'frequency' => $subscriptionData['frequency'] ?? 1,
                'frequency_type' => $subscriptionData['frequency_type'] ?? 'months',
                'transaction_amount' => $subscriptionData['transaction_amount'],
                'currency_id' => 'BRL',
            ];

            if (!empty($subscriptionData['start_date'])) {
                $payload['auto_recurring']['start_date'] = $subscriptionData['start_date'];
            }
            if (!empty($subscriptionData['end_date'])) {
                $payload['auto_recurring']['end_date'] = $subscriptionData['end_date'];
            }
            if (!empty($subscriptionData['free_trial_days'])) {
                $payload['auto_recurring']['free_trial'] = [
                    'frequency' => $subscriptionData['free_trial_days'],
                    'frequency_type' => 'days',
                ];
            }
        }

        // Token do cartão para cobrança automática
        if (!empty($subscriptionData['card_token_id'])) {
            $payload['card_token_id'] = $subscriptionData['card_token_id'];
        }

        $response = $client->post('https://api.mercadopago.com/preapproval', $payload);

        // Salva localmente
        if (isset($response['id'])) {
            $this->saveSubscriptionLocally($response);
        }

        return $response;
    }

    /**
     * Busca assinaturas no MP
     * GET /preapproval/search
     *
     * @param array $filters Filtros da busca
     * @return array Lista de assinaturas
     */
    public function searchSubscriptions(array $filters = []): array
    {
        $client = $this->getMercadoPagoClient();

        $params = [];

        if (!empty($filters['status'])) {
            $params['status'] = $filters['status']; // pending, authorized, paused, cancelled
        }
        if (!empty($filters['payer_email'])) {
            $params['payer_email'] = $filters['payer_email'];
        }
        if (!empty($filters['payer_id'])) {
            $params['payer_id'] = $filters['payer_id'];
        }
        if (!empty($filters['preapproval_plan_id'])) {
            $params['preapproval_plan_id'] = $filters['preapproval_plan_id'];
        }

        $params['offset'] = $filters['offset'] ?? 0;
        $params['limit'] = $filters['limit'] ?? 50;

        $queryString = http_build_query($params);
        return $client->get("https://api.mercadopago.com/preapproval/search?{$queryString}");
    }

    /**
     * Obtém detalhes de uma assinatura
     * GET /preapproval/{id}
     *
     * @param string $subscriptionId ID da assinatura
     * @return array Detalhes da assinatura
     */
    public function getSubscription(string $subscriptionId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/preapproval/{$subscriptionId}");
    }

    /**
     * Atualiza uma assinatura
     * PUT /preapproval/{id}
     *
     * @param string $subscriptionId ID da assinatura
     * @param array $updateData Dados para atualizar
     * @return array Assinatura atualizada
     */
    public function updateSubscription(string $subscriptionId, array $updateData): array
    {
        $client = $this->getMercadoPagoClient();

        $payload = [];

        // Campos atualizáveis
        if (isset($updateData['status'])) {
            $payload['status'] = $updateData['status']; // authorized, paused, cancelled
        }
        if (isset($updateData['back_url'])) {
            $payload['back_url'] = $updateData['back_url'];
        }
        if (isset($updateData['card_token_id'])) {
            $payload['card_token_id'] = $updateData['card_token_id'];
        }
        if (isset($updateData['reason'])) {
            $payload['reason'] = $updateData['reason'];
        }

        // Atualização de valores recorrentes
        if (!empty($updateData['auto_recurring'])) {
            $payload['auto_recurring'] = $updateData['auto_recurring'];
        }

        $response = $client->put("https://api.mercadopago.com/preapproval/{$subscriptionId}", $payload);

        // Atualiza localmente
        if (isset($response['id'])) {
            $this->updateSubscriptionLocally($response);
        }

        return $response;
    }

    /**
     * Pausa uma assinatura
     *
     * @param string $subscriptionId ID da assinatura
     * @return array Assinatura pausada
     */
    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->updateSubscription($subscriptionId, ['status' => 'paused']);
    }

    /**
     * Reativa uma assinatura pausada
     *
     * @param string $subscriptionId ID da assinatura
     * @return array Assinatura reativada
     */
    public function activateSubscription(string $subscriptionId): array
    {
        return $this->updateSubscription($subscriptionId, ['status' => 'authorized']);
    }

    /**
     * Cancela uma assinatura
     *
     * @param string $subscriptionId ID da assinatura
     * @return array Assinatura cancelada
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->updateSubscription($subscriptionId, ['status' => 'cancelled']);
    }

    /**
     * Exporta assinaturas para CSV
     * GET /preapproval/export
     *
     * @param array $filters Filtros para exportação
     * @return array URL do arquivo para download
     */
    public function exportSubscriptions(array $filters = []): array
    {
        $client = $this->getMercadoPagoClient();

        $params = [];
        if (!empty($filters['status'])) {
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $params['date_created_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $params['date_created_to'] = $filters['date_to'];
        }

        $queryString = http_build_query($params);
        return $client->get("https://api.mercadopago.com/preapproval/search?{$queryString}");
    }

    // =========================================================================
    // MERCADO PAGO - PLANOS DE ASSINATURA
    // =========================================================================

    /**
     * Cria um plano de assinatura
     * POST /preapproval_plan
     *
     * @param array $planData Dados do plano
     * @return array Plano criado
     */
    public function createSubscriptionPlan(array $planData): array
    {
        $client = $this->getMercadoPagoClient();

        $payload = [
            'reason' => $planData['reason'],
            'auto_recurring' => [
                'frequency' => $planData['frequency'] ?? 1,
                'frequency_type' => $planData['frequency_type'] ?? 'months',
                'transaction_amount' => $planData['transaction_amount'],
                'currency_id' => 'BRL',
                'billing_day' => $planData['billing_day'] ?? 10,
                'billing_day_proportional' => $planData['billing_day_proportional'] ?? true,
            ],
            'back_url' => $planData['back_url'] ?? ($_ENV['APP_URL'] ?? 'https://eskill.com.br') . '/subscriptions/callback',
        ];

        // Período de teste gratuito
        if (!empty($planData['free_trial_days'])) {
            $payload['auto_recurring']['free_trial'] = [
                'frequency' => $planData['free_trial_days'],
                'frequency_type' => 'days',
            ];
        }

        // Repetições (número de cobranças)
        if (!empty($planData['repetitions'])) {
            $payload['auto_recurring']['repetitions'] = $planData['repetitions'];
        }

        $response = $client->post('https://api.mercadopago.com/preapproval_plan', $payload);

        // Salva localmente
        if (isset($response['id'])) {
            $this->saveSubscriptionPlanLocally($response);
        }

        return $response;
    }

    /**
     * Busca planos de assinatura
     * GET /preapproval_plan/search
     *
     * @param array $filters Filtros
     * @return array Lista de planos
     */
    public function searchSubscriptionPlans(array $filters = []): array
    {
        $client = $this->getMercadoPagoClient();

        $params = [];
        if (!empty($filters['status'])) {
            $params['status'] = $filters['status']; // active, inactive
        }
        $params['offset'] = $filters['offset'] ?? 0;
        $params['limit'] = $filters['limit'] ?? 50;

        $queryString = http_build_query($params);
        return $client->get("https://api.mercadopago.com/preapproval_plan/search?{$queryString}");
    }

    /**
     * Obtém detalhes de um plano de assinatura
     * GET /preapproval_plan/{id}
     *
     * @param string $planId ID do plano
     * @return array Detalhes do plano
     */
    public function getSubscriptionPlan(string $planId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/preapproval_plan/{$planId}");
    }

    /**
     * Atualiza um plano de assinatura
     * PUT /preapproval_plan/{id}
     *
     * @param string $planId ID do plano
     * @param array $updateData Dados para atualizar
     * @return array Plano atualizado
     */
    public function updateSubscriptionPlan(string $planId, array $updateData): array
    {
        $client = $this->getMercadoPagoClient();

        $payload = [];
        if (isset($updateData['reason'])) {
            $payload['reason'] = $updateData['reason'];
        }
        if (isset($updateData['back_url'])) {
            $payload['back_url'] = $updateData['back_url'];
        }
        if (isset($updateData['status'])) {
            $payload['status'] = $updateData['status']; // active, inactive
        }
        if (!empty($updateData['auto_recurring'])) {
            $payload['auto_recurring'] = $updateData['auto_recurring'];
        }

        return $client->put("https://api.mercadopago.com/preapproval_plan/{$planId}", $payload);
    }

    // =========================================================================
    // MERCADO PAGO - FATURAS DE ASSINATURA
    // =========================================================================

    /**
     * Obtém informação de uma fatura de assinatura
     * GET /authorized_payments/{id}
     *
     * @param string $invoiceId ID da fatura/pagamento autorizado
     * @return array Detalhes da fatura
     */
    public function getSubscriptionInvoice(string $invoiceId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/authorized_payments/{$invoiceId}");
    }

    /**
     * Busca faturas de assinaturas
     * GET /authorized_payments/search
     *
     * @param array $filters Filtros
     * @return array Lista de faturas
     */
    public function searchSubscriptionInvoices(array $filters = []): array
    {
        // preapproval_id is required by MP API /authorized_payments/search
        if (empty($filters['preapproval_id'])) {
            return ['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]];
        }

        $client = $this->getMercadoPagoClient();

        $params = [];
        if (!empty($filters['preapproval_id'])) {
            $params['preapproval_id'] = $filters['preapproval_id'];
        }
        if (!empty($filters['status'])) {
            $params['status'] = $filters['status']; // scheduled, processed, recycling, cancelled
        }
        $params['offset'] = $filters['offset'] ?? 0;
        $params['limit'] = $filters['limit'] ?? 50;

        $queryString = http_build_query($params);
        return $client->get("https://api.mercadopago.com/authorized_payments/search?{$queryString}");
    }

    /**
     * Obtém análise de receita recorrente (MRR)
     *
     * @return array Análise de receita recorrente
     */
    public function getRecurringRevenueAnalysis(): array
    {
        // Busca assinaturas ativas
        $activeSubscriptions = $this->searchSubscriptions(['status' => 'authorized', 'limit' => 100]);

        $mrr = 0;
        $arr = 0;
        $subscriptionsByPlan = [];
        $subscriptionsByFrequency = [];
        $nextPayments = [];

        foreach ($activeSubscriptions['results'] ?? [] as $subscription) {
            // Calcula MRR baseado na frequência
            $amount = $subscription['auto_recurring']['transaction_amount'] ?? 0;
            $frequency = $subscription['auto_recurring']['frequency'] ?? 1;
            $frequencyType = $subscription['auto_recurring']['frequency_type'] ?? 'months';

            // Normaliza para mensal
            $monthlyAmount = match ($frequencyType) {
                'days' => ($amount / $frequency) * 30,
                'months' => $amount / $frequency,
                'years' => $amount / ($frequency * 12),
                default => $amount,
            };

            $mrr += $monthlyAmount;

            // Agrupa por plano
            $planId = $subscription['preapproval_plan_id'] ?? 'custom';
            if (!isset($subscriptionsByPlan[$planId])) {
                $subscriptionsByPlan[$planId] = ['count' => 0, 'mrr' => 0];
            }
            $subscriptionsByPlan[$planId]['count']++;
            $subscriptionsByPlan[$planId]['mrr'] += $monthlyAmount;

            // Agrupa por frequência
            $freqKey = "{$frequency} {$frequencyType}";
            if (!isset($subscriptionsByFrequency[$freqKey])) {
                $subscriptionsByFrequency[$freqKey] = ['count' => 0, 'mrr' => 0];
            }
            $subscriptionsByFrequency[$freqKey]['count']++;
            $subscriptionsByFrequency[$freqKey]['mrr'] += $monthlyAmount;

            // Próximos pagamentos
            if (!empty($subscription['next_payment_date'])) {
                $nextPayments[] = [
                    'subscription_id' => $subscription['id'],
                    'payer_email' => $subscription['payer_email'] ?? null,
                    'amount' => $amount,
                    'date' => $subscription['next_payment_date'],
                ];
            }
        }

        $arr = $mrr * 12;

        // Ordena próximos pagamentos por data
        usort($nextPayments, fn($a, $b) => strtotime($a['date']) - strtotime($b['date']));

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($arr, 2),
            'total_active_subscriptions' => count($activeSubscriptions['results'] ?? []),
            'subscriptions_by_plan' => $subscriptionsByPlan,
            'subscriptions_by_frequency' => $subscriptionsByFrequency,
            'next_payments' => array_slice($nextPayments, 0, 10),
            'avg_subscription_value' => count($activeSubscriptions['results'] ?? []) > 0
                ? round($mrr / count($activeSubscriptions['results']), 2)
                : 0,
        ];
    }

    /**
     * Calcula churn rate de assinaturas
     *
     * @param string $month Mês no formato Y-m
     * @return array Análise de churn
     */
    public function calculateSubscriptionChurn(string $month): array
    {
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        // Busca assinaturas canceladas no período
        $cancelled = $this->searchSubscriptions([
            'status' => 'cancelled',
            'limit' => 100,
        ]);

        $cancelledInPeriod = array_filter(
            $cancelled['results'] ?? [],
            function (array $sub) use ($startDate, $endDate): bool {
                $lastModified = $sub['last_modified'] ?? null;
                return $lastModified >= $startDate && $lastModified <= $endDate;
            }
        );

        // Busca total de assinaturas ativas no início do período
        $activeAtStart = $this->searchSubscriptions(['status' => 'authorized', 'limit' => 100]);
        $totalActiveAtStart = count($activeAtStart['results'] ?? []) + count($cancelledInPeriod);

        $churnRate = $totalActiveAtStart > 0
            ? (count($cancelledInPeriod) / $totalActiveAtStart) * 100
            : 0;

        $lostMrr = 0;
        foreach ($cancelledInPeriod as $sub) {
            $amount = $sub['auto_recurring']['transaction_amount'] ?? 0;
            $frequency = $sub['auto_recurring']['frequency'] ?? 1;
            $frequencyType = $sub['auto_recurring']['frequency_type'] ?? 'months';

            $monthlyAmount = match ($frequencyType) {
                'days' => ($amount / $frequency) * 30,
                'months' => $amount / $frequency,
                'years' => $amount / ($frequency * 12),
                default => $amount,
            };
            $lostMrr += $monthlyAmount;
        }

        return [
            'month' => $month,
            'active_at_start' => $totalActiveAtStart,
            'cancelled_count' => count($cancelledInPeriod),
            'churn_rate' => round($churnRate, 2),
            'lost_mrr' => round($lostMrr, 2),
            'cancelled_subscriptions' => array_map(fn(array $s): array => [
                'id' => $s['id'],
                'reason' => $s['reason'] ?? null,
                'cancelled_at' => $s['last_modified'] ?? null,
            ], $cancelledInPeriod),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Salva assinatura localmente
     */
    private function saveSubscriptionLocally(array $subscription): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO mp_subscriptions (
                account_id, subscription_id, payer_email, payer_id, plan_id,
                reason, status, transaction_amount, frequency, frequency_type,
                next_payment_date, date_created, external_reference
            ) VALUES (
                :account_id, :subscription_id, :payer_email, :payer_id, :plan_id,
                :reason, :status, :transaction_amount, :frequency, :frequency_type,
                :next_payment_date, :date_created, :external_reference
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                next_payment_date = VALUES(next_payment_date),
                transaction_amount = VALUES(transaction_amount),
                updated_at = NOW()
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'subscription_id' => $subscription['id'],
            'payer_email' => $subscription['payer_email'] ?? null,
            'payer_id' => $subscription['payer_id'] ?? null,
            'plan_id' => $subscription['preapproval_plan_id'] ?? null,
            'reason' => $subscription['reason'] ?? null,
            'status' => $subscription['status'] ?? 'pending',
            'transaction_amount' => $subscription['auto_recurring']['transaction_amount'] ?? 0,
            'frequency' => $subscription['auto_recurring']['frequency'] ?? 1,
            'frequency_type' => $subscription['auto_recurring']['frequency_type'] ?? 'months',
            'next_payment_date' => $subscription['next_payment_date'] ?? null,
            'date_created' => $subscription['date_created'] ?? date('Y-m-d H:i:s'),
            'external_reference' => $subscription['external_reference'] ?? null,
        ]);
    }

    /**
     * Atualiza assinatura localmente
     */
    private function updateSubscriptionLocally(array $subscription): void
    {
        $this->saveSubscriptionLocally($subscription);
    }

    /**
     * Salva plano de assinatura localmente
     */
    private function saveSubscriptionPlanLocally(array $plan): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO mp_subscription_plans (
                account_id, plan_id, reason, status, transaction_amount,
                frequency, frequency_type, billing_day, date_created
            ) VALUES (
                :account_id, :plan_id, :reason, :status, :transaction_amount,
                :frequency, :frequency_type, :billing_day, :date_created
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                transaction_amount = VALUES(transaction_amount),
                updated_at = NOW()
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'plan_id' => $plan['id'],
            'reason' => $plan['reason'] ?? null,
            'status' => $plan['status'] ?? 'active',
            'transaction_amount' => $plan['auto_recurring']['transaction_amount'] ?? 0,
            'frequency' => $plan['auto_recurring']['frequency'] ?? 1,
            'frequency_type' => $plan['auto_recurring']['frequency_type'] ?? 'months',
            'billing_day' => $plan['auto_recurring']['billing_day'] ?? 10,
            'date_created' => $plan['date_created'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}
