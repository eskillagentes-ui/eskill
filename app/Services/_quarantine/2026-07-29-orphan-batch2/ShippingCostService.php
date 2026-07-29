<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Services\Financial\HasFinancialDependencies;

/**
 * Serviço de custos de envio e frete.
 * Extraído de FinancialService.
 */
class ShippingCostService
{
    use HasFinancialDependencies;

    /**
     * Obtém detalhes do envio de uma ordem (custos reais de frete)
     * Endpoint: GET /shipments/{shipment_id}
     *
     * @param string $shipmentId ID do envio
     * @return array Detalhes do envio com custos
     */
    public function getShipmentCosts(string $shipmentId): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get("/shipments/{$shipmentId}");
        } catch (\Exception $e) {
            log_error('Falha ao buscar custos de envio', [
                'service' => 'ShippingCostService',
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'data' => null,
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Envio não encontrado',
                'data' => null,
            ];
        }

        $senderCost = $response['cost_components']['special_discount'] ?? 0;
        $sellerCost = (float)($response['seller_cost'] ?? 0);
        $baseCost = (float)($response['base_cost'] ?? 0);
        $listCost = (float)($response['list_cost'] ?? 0);

        return [
            'shipment_id' => $response['id'] ?? $shipmentId,
            'status' => $response['status'] ?? null,
            'substatus' => $response['substatus'] ?? null,
            'mode' => $response['mode'] ?? null,
            'logistic_type' => $response['logistic_type'] ?? null,
            'shipping_option' => [
                'name' => $response['shipping_option']['name'] ?? null,
                'shipping_method_id' => $response['shipping_option']['shipping_method_id'] ?? null,
                'delivery_type' => $response['shipping_option']['delivery_type'] ?? null,
            ],
            'costs' => [
                'base_cost' => $baseCost,
                'list_cost' => $listCost,
                'seller_cost' => $sellerCost,
                'receiver_cost' => (float)($response['receiver_cost'] ?? 0),
                'free_shipping' => (bool)($response['free_shipping'] ?? false),
            ],
            'dimensions' => [
                'height' => $response['dimensions']['height'] ?? null,
                'width' => $response['dimensions']['width'] ?? null,
                'length' => $response['dimensions']['length'] ?? null,
                'weight' => $response['dimensions']['weight'] ?? null,
            ],
            'date_created' => $response['date_created'] ?? null,
            'date_first_printed' => $response['date_first_printed'] ?? null,
            'tracking_number' => $response['tracking_number'] ?? null,
            'carrier' => $response['service_id'] ?? null,
        ];
    }

    /**
     * Obtém lista de envios do vendedor com custos
     * Endpoint: GET /orders/{order_id}/shipments
     *
     * @param string $orderId ID da ordem
     * @return array Lista de envios da ordem
     */
    public function getOrderShipments(string $orderId): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get("/orders/{$orderId}/shipments");
        } catch (\Exception $e) {
            log_error('Falha ao buscar envios da ordem', [
                'service' => 'ShippingCostService',
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Envios não encontrados',
                'results' => [],
            ];
        }

        $shipments = [];
        $shipmentsData = is_array($response) && isset($response[0]) ? $response : [$response];

        foreach ($shipmentsData as $shipment) {
            if (empty($shipment['id'])) {
                continue;
            }

            $shipments[] = [
                'shipment_id' => $shipment['id'] ?? null,
                'status' => $shipment['status'] ?? null,
                'mode' => $shipment['mode'] ?? null,
                'logistic_type' => $shipment['logistic_type'] ?? null,
                'seller_cost' => (float)($shipment['seller_cost'] ?? 0),
                'receiver_cost' => (float)($shipment['receiver_cost'] ?? 0),
                'free_shipping' => (bool)($shipment['free_shipping'] ?? false),
                'date_created' => $shipment['date_created'] ?? null,
            ];
        }

        return [
            'order_id' => $orderId,
            'results' => $shipments,
            'total' => count($shipments),
        ];
    }

    /**
     * Obtém detalhes completos de custos por vender (comissões)
     * Endpoint: GET /items/{item_id}/sale_terms
     *
     * @param string $itemId ID do item
     * @return array Termos de venda e comissões
     */
    public function getItemSaleTerms(string $itemId): array
    {
        $client = $this->getClient();

        // Obter dados do item
        $itemResponse = $client->get("/items/{$itemId}");

        if (isset($itemResponse['error'])) {
            return [
                'error' => $itemResponse['message'] ?? 'Item não encontrado',
                'data' => null,
            ];
        }

        $listingTypeId = $itemResponse['listing_type_id'] ?? 'gold_special';
        $categoryId = $itemResponse['category_id'] ?? null;
        $price = (float)($itemResponse['price'] ?? 0);

        // Buscar informações de custos da categoria
        $categoryFees = [];
        if ($categoryId) {
            $catResponse = $client->get("/categories/{$categoryId}");
            if (!isset($catResponse['error'])) {
                $categoryFees = [
                    'category_id' => $categoryId,
                    'category_name' => $catResponse['name'] ?? null,
                ];
            }
        }

        // Obter listing type fee
        $listingFee = $this->getListingTypeFee($listingTypeId);

        return [
            'item_id' => $itemId,
            'title' => $itemResponse['title'] ?? null,
            'price' => $price,
            'listing_type_id' => $listingTypeId,
            'category' => $categoryFees,
            'estimated_fees' => [
                'listing_fee_percentage' => $listingFee,
                'estimated_ml_commission' => round($price * ($listingFee / 100), 2),
            ],
            'shipping' => [
                'free_shipping' => (bool)($itemResponse['shipping']['free_shipping'] ?? false),
                'logistic_type' => $itemResponse['shipping']['logistic_type'] ?? null,
            ],
        ];
    }

    /**
     * Obtém percentual de comissão por tipo de listagem
     */
    private function getListingTypeFee(string $listingTypeId): float
    {
        // Valores aproximados para Brasil (MLB) - podem variar por categoria
        $fees = [
            'gold_pro' => 16.0,
            'gold_special' => 13.0,
            'gold_premium' => 16.0,
            'gold' => 11.0,
            'silver' => 9.0,
            'bronze' => 5.0,
            'free' => 0.0,
        ];

        return $fees[$listingTypeId] ?? 13.0;
    }
}
