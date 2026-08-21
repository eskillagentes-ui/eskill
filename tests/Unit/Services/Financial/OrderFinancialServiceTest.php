<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use App\Services\Financial\OrderFinancialService;
use App\Services\MercadoLivreClient;

/**
 * Testes DB-free para OrderFinancialService
 *
 * @covers \App\Services\Financial\OrderFinancialService
 *
 * Verifica:
 * - capability error handling em /orders/search
 * - capability error handling em /merchant_orders/search
 * - propagação de feature_unavailable em syncOrdersWithFinancials
 * - erros genéricos NÃO ativam feature_unavailable
 */
class OrderFinancialServiceTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function createMockClient(
        ?string $sellerId = '123456',
        array $getReturnMap = []
    ): MercadoLivreClient {
        $mock = $this->createMock(MercadoLivreClient::class);

        $mock->method('getSellerId')
            ->willReturn($sellerId);

        if (!empty($getReturnMap)) {
            $mock->method('get')
                ->willReturnCallback(function (string $endpoint, array $params = []) use ($getReturnMap): array {
                    foreach ($getReturnMap as $pattern => $response) {
                        if (str_contains($endpoint, $pattern)) {
                            return $response;
                        }
                    }
                    return ['error' => 'not_found'];
                });
        }

        return $mock;
    }

    private function buildService(MercadoLivreClient $mockClient): OrderFinancialService
    {
        $ref = new \ReflectionClass(OrderFinancialService::class);
        /** @var OrderFinancialService $service */
        $service = $ref->newInstanceWithoutConstructor();

        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $mockClient);

        // Also set accountId to prevent null errors
        $accountIdProp = $ref->getProperty('accountId');
        $accountIdProp->setAccessible(true);
        $accountIdProp->setValue($service, 1);

        return $service;
    }

    private function buildOrdersCapabilityError(): array
    {
        return [
            'error' => 'orders_access_unavailable',
            'message' => 'The user does not have the orders capability.',
            'feature' => 'orders',
            'optional_feature' => true,
        ];
    }

    private function buildMerchantOrdersCapabilityError(): array
    {
        return [
            'error' => 'merchant_orders_unavailable',
            'message' => 'The user does not have the merchant_orders capability.',
            'feature' => 'merchant_orders',
            'optional_feature' => true,
        ];
    }

    private function buildOrdersSuccessResponse(array $orders = [], int $total = 0): array
    {
        return [
            'results' => $orders,
            'paging' => [
                'total' => $total ?: count($orders),
                'offset' => 0,
                'limit' => 50,
            ],
        ];
    }

    private function buildSampleOrder(float $amount = 100.0): array
    {
        return [
            'id' => random_int(100000, 999999),
            'status' => 'paid',
            'total_amount' => $amount,
            'date_created' => '2024-01-15T10:30:00.000-03:00',
            'date_closed' => '2024-01-15T10:35:00.000-03:00',
            'payments' => [[
                'status' => 'approved',
                'total_paid_amount' => $amount,
                'transaction_amount' => $amount,
                'payment_type' => 'credit_card',
                'fee_details' => [['amount' => 5.0]],
            ]],
            'order_items' => [[
                'item' => ['id' => 'MLB123', 'title' => 'Test Item'],
                'quantity' => 1,
                'unit_price' => $amount,
                'sale_fee' => 10.0,
            ]],
            'shipping' => ['cost' => 0],
            'buyer' => ['id' => 999, 'nickname' => 'BUYER'],
        ];
    }

    // ===========================
    // getOrdersFromApi — capability error
    // ===========================

    public function testGetOrdersFromApiReturnsFeatureUnavailableOnCapabilityError(): void
    {
        $mockClient = $this->createMockClient('123456', [
            '/orders/search' => $this->buildOrdersCapabilityError(),
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->getOrdersFromApi('2024-01-01', '2024-01-31');

        $this->assertTrue($result['feature_unavailable']);
        $this->assertSame('orders_access_unavailable', $result['error']);
        $this->assertEmpty($result['results']);
        $this->assertSame(0, $result['paging']['total']);
    }

    public function testGetOrdersFromApiReturnsOrdersOnSuccess(): void
    {
        $orders = [$this->buildSampleOrder(150.0)];
        $mockClient = $this->createMockClient('123456', [
            '/orders/search' => $this->buildOrdersSuccessResponse($orders, 1),
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->getOrdersFromApi('2024-01-01', '2024-01-31');

        $this->assertArrayNotHasKey('feature_unavailable', $result);
        $this->assertCount(1, $result['results']);
        $this->assertSame(150.0, $result['results'][0]['total_amount']);
    }

    public function testGetOrdersFromApiDoesNotSetFeatureUnavailableOnGenericError(): void
    {
        $mockClient = $this->createMockClient('123456', [
            '/orders/search' => ['error' => 'timeout', 'message' => 'Request timed out'],
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->getOrdersFromApi('2024-01-01', '2024-01-31');

        $this->assertArrayNotHasKey('feature_unavailable', $result);
        $this->assertSame('Request timed out', $result['error']);
    }

    // ===========================
    // searchMerchantOrders — capability error
    // ===========================

    public function testSearchMerchantOrdersReturnsFeatureUnavailableOnCapabilityError(): void
    {
        $mockClient = $this->createMockClient('123456', [
            '/merchant_orders/search' => $this->buildMerchantOrdersCapabilityError(),
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->searchMerchantOrders();

        $this->assertTrue($result['feature_unavailable']);
        $this->assertSame('merchant_orders_unavailable', $result['error']);
        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['elements']);
    }

    public function testSearchMerchantOrdersDoesNotSetFeatureUnavailableOnGenericError(): void
    {
        $mockClient = $this->createMockClient('123456', [
            '/merchant_orders/search' => ['error' => 'forbidden', 'message' => 'Access denied'],
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->searchMerchantOrders();

        $this->assertArrayNotHasKey('feature_unavailable', $result);
        $this->assertSame('Access denied', $result['error']);
    }

    public function testSearchMerchantOrdersReturnsDataOnSuccess(): void
    {
        $mockClient = $this->createMockClient('123456', [
            '/merchant_orders/search' => [
                'total' => 1,
                'elements' => [[
                    'id' => 12345,
                    'status' => 'closed',
                    'external_reference' => 'REF001',
                    'preference_id' => 'PREF001',
                    'total_amount' => 200.0,
                    'paid_amount' => 200.0,
                    'refunded_amount' => 0,
                    'shipping_cost' => 0,
                    'date_created' => '2024-01-15T10:00:00.000-03:00',
                    'last_updated' => '2024-01-15T10:05:00.000-03:00',
                    'items' => [],
                    'payments' => [],
                ]],
            ],
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->searchMerchantOrders();

        $this->assertArrayNotHasKey('feature_unavailable', $result);
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['elements']);
    }

    // ===========================
    // Capability check — strict field validation
    // ===========================

    public function testOrdersCapabilityCheckRequiresAllFields(): void
    {
        // Missing 'feature' field
        $mockClient = $this->createMockClient('123456', [
            '/orders/search' => [
                'error' => 'orders_access_unavailable',
                'optional_feature' => true,
                // 'feature' => 'orders'  — missing
            ],
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->getOrdersFromApi('2024-01-01', '2024-01-31');

        // Should be treated as generic error, NOT capability error
        $this->assertArrayNotHasKey('feature_unavailable', $result);
    }

    public function testMerchantOrdersCapabilityCheckRequiresAllFields(): void
    {
        // Missing 'optional_feature' field
        $mockClient = $this->createMockClient('123456', [
            '/merchant_orders/search' => [
                'error' => 'merchant_orders_unavailable',
                'feature' => 'merchant_orders',
                // 'optional_feature' => true  — missing
            ],
        ]);

        $service = $this->buildService($mockClient);
        $result = $service->searchMerchantOrders();

        // Should be treated as generic error
        $this->assertArrayNotHasKey('feature_unavailable', $result);
    }


    // ===========================
    // Missing CMV is unknown, not zero
    // ===========================

    public function testSummarizeSalesDoesNotTreatMissingCogsAsZeroProfit(): void
    {
        $service = $this->buildService($this->createMockClient());
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('summarizeSales');
        $method->setAccessible(true);

        $summary = $method->invoke($service, [
            [
                'total_amount' => 100.0,
                'profit' => null,
                'has_cogs' => false,
                'marketplace_net' => 80.0,
                'taxes' => 0.0,
                'items' => [
                    ['item_id' => 'MLB1', 'linked_product' => false, 'has_cogs' => false],
                ],
            ],
            [
                'total_amount' => 200.0,
                'profit' => 50.0,
                'has_cogs' => true,
                'marketplace_net' => 160.0,
                'taxes' => 0.0,
                'items' => [
                    ['item_id' => 'MLB2', 'linked_product' => true, 'has_cogs' => true],
                ],
            ],
        ]);

        $this->assertSame(1, $summary['unlinked_items']);
        $this->assertFalse($summary['has_real_cogs']);
        $this->assertSame(50.0, $summary['lucro_conhecido']);
        // KPI total_profit is not a complete green number when any line lacks CMV.
        $this->assertNull($summary['total_profit']);
        $this->assertNull($summary['avg_margin']);
    }

    public function testSummarizeSalesComputesProfitWhenEveryItemHasCogs(): void
    {
        $service = $this->buildService($this->createMockClient());
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('summarizeSales');
        $method->setAccessible(true);

        $summary = $method->invoke($service, [
            [
                'total_amount' => 200.0,
                'profit' => 50.0,
                'has_cogs' => true,
                'marketplace_net' => 160.0,
                'taxes' => 0.0,
                'items' => [
                    ['item_id' => 'MLB2', 'linked_product' => true, 'has_cogs' => true],
                ],
            ],
        ]);

        $this->assertTrue($summary['has_real_cogs']);
        $this->assertSame(50.0, $summary['total_profit']);
        $this->assertSame(25.0, $summary['avg_margin']);
        $this->assertSame(0, $summary['unlinked_items']);
    }
}
