<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use App\Services\Financial\HasFinancialDependencies;

/**
 * Serviço de clientes e cartões de pagamento (Mercado Pago).
 * Extraído de FinancialService.
 */
class CustomerPaymentMethodService
{
    use HasFinancialDependencies;

    // =========================================================================
    // MERCADO PAGO - CLIENTES
    // =========================================================================

    /**
     * Cria um cliente no MP
     * POST /v1/customers
     *
     * @param array $customerData Dados do cliente
     * @return array Cliente criado
     */
    public function createCustomer(array $customerData): array
    {
        $client = $this->getMercadoPagoClient();

        $payload = [
            'email' => $customerData['email'],
        ];

        // Campos opcionais
        if (!empty($customerData['first_name'])) {
            $payload['first_name'] = $customerData['first_name'];
        }
        if (!empty($customerData['last_name'])) {
            $payload['last_name'] = $customerData['last_name'];
        }
        if (!empty($customerData['phone'])) {
            $payload['phone'] = [
                'area_code' => $customerData['phone']['area_code'] ?? '',
                'number' => $customerData['phone']['number'] ?? $customerData['phone'],
            ];
        }
        if (!empty($customerData['identification'])) {
            $payload['identification'] = [
                'type' => $customerData['identification']['type'] ?? 'CPF',
                'number' => $customerData['identification']['number'] ?? $customerData['identification'],
            ];
        }
        if (!empty($customerData['address'])) {
            $payload['address'] = $customerData['address'];
        }
        if (!empty($customerData['description'])) {
            $payload['description'] = $customerData['description'];
        }
        if (!empty($customerData['default_card'])) {
            $payload['default_card'] = $customerData['default_card'];
        }

        $response = $client->post('https://api.mercadopago.com/v1/customers', $payload);

        // Salva localmente
        if (isset($response['id'])) {
            $this->saveCustomerLocally($response);
        }

        return $response;
    }

    /**
     * Busca clientes no MP
     * GET /v1/customers/search
     *
     * @param array $filters Filtros
     * @return array Lista de clientes
     */
    public function searchCustomers(array $filters = []): array
    {
        $client = $this->getMercadoPagoClient();

        $params = [];
        if (!empty($filters['email'])) {
            $params['email'] = $filters['email'];
        }
        $params['offset'] = $filters['offset'] ?? 0;
        $params['limit'] = $filters['limit'] ?? 50;

        $queryString = http_build_query($params);
        return $client->get("https://api.mercadopago.com/v1/customers/search?{$queryString}");
    }

    /**
     * Obtém detalhes de um cliente
     * GET /v1/customers/{id}
     *
     * @param string $customerId ID do cliente
     * @return array Detalhes do cliente
     */
    public function getCustomer(string $customerId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/v1/customers/{$customerId}");
    }

    /**
     * Atualiza um cliente
     * PUT /v1/customers/{id}
     *
     * @param string $customerId ID do cliente
     * @param array $updateData Dados para atualizar
     * @return array Cliente atualizado
     */
    public function updateCustomer(string $customerId, array $updateData): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->put("https://api.mercadopago.com/v1/customers/{$customerId}", $updateData);
    }

    // =========================================================================
    // MERCADO PAGO - CARTÕES DE CLIENTES
    // =========================================================================

    /**
     * Salva um cartão para um cliente
     * POST /v1/customers/{customer_id}/cards
     *
     * @param string $customerId ID do cliente
     * @param string $cardToken Token do cartão
     * @return array Cartão salvo
     */
    public function saveCustomerCard(string $customerId, string $cardToken): array
    {
        $client = $this->getMercadoPagoClient();

        return $client->post("https://api.mercadopago.com/v1/customers/{$customerId}/cards", [
            'token' => $cardToken,
        ]);
    }

    /**
     * Lista cartões de um cliente
     * GET /v1/customers/{customer_id}/cards
     *
     * @param string $customerId ID do cliente
     * @return array Lista de cartões
     */
    public function getCustomerCards(string $customerId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/v1/customers/{$customerId}/cards");
    }

    /**
     * Obtém detalhes de um cartão
     * GET /v1/customers/{customer_id}/cards/{id}
     *
     * @param string $customerId ID do cliente
     * @param string $cardId ID do cartão
     * @return array Detalhes do cartão
     */
    public function getCustomerCard(string $customerId, string $cardId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/v1/customers/{$customerId}/cards/{$cardId}");
    }

    /**
     * Atualiza um cartão
     * PUT /v1/customers/{customer_id}/cards/{id}
     *
     * @param string $customerId ID do cliente
     * @param string $cardId ID do cartão
     * @param array $updateData Dados para atualizar
     * @return array Cartão atualizado
     */
    public function updateCustomerCard(string $customerId, string $cardId, array $updateData): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->put("https://api.mercadopago.com/v1/customers/{$customerId}/cards/{$cardId}", $updateData);
    }

    /**
     * Remove um cartão do cliente
     * DELETE /v1/customers/{customer_id}/cards/{id}
     *
     * @param string $customerId ID do cliente
     * @param string $cardId ID do cartão
     * @return array Resultado da remoção
     */
    public function deleteCustomerCard(string $customerId, string $cardId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->delete("https://api.mercadopago.com/v1/customers/{$customerId}/cards/{$cardId}");
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    private function saveCustomerLocally(array $customer): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO mp_customers (
                account_id, customer_id, email, first_name, last_name,
                phone, identification_type, identification_number, date_created
            ) VALUES (
                :account_id, :customer_id, :email, :first_name, :last_name,
                :phone, :identification_type, :identification_number, :date_created
            )
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                updated_at = NOW()
        ");

        $stmt->execute([
            'account_id' => $this->accountId,
            'customer_id' => $customer['id'],
            'email' => $customer['email'] ?? null,
            'first_name' => $customer['first_name'] ?? null,
            'last_name' => $customer['last_name'] ?? null,
            'phone' => isset($customer['phone']) ? json_encode($customer['phone']) : null,
            'identification_type' => $customer['identification']['type'] ?? null,
            'identification_number' => $customer['identification']['number'] ?? null,
            'date_created' => $customer['date_created'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}
