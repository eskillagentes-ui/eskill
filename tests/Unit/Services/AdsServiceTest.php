<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AdsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \App\Services\AdsService
 */
class AdsServiceTest extends TestCase
{
    /**
     * Test service extends MercadoLivreClient
     */
    public function testServiceExtendsMercadoLivreClient(): void
    {
        $reflection = new ReflectionClass(AdsService::class);
        $parent = $reflection->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame('App\Services\MercadoLivreClient', $parent->getName());
    }

    /**
     * Test service has required methods
     */
    public function testServiceHasRequiredMethods(): void
    {
        $reflection = new ReflectionClass(AdsService::class);

        $requiredMethods = [
            'getCampaigns',
            'getCampaignMetrics',
            'createCampaign',
            'updateCampaign',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Missing required method: {$method}"
            );
        }
    }

    /**
     * Test isList utility method with empty array
     */
    public function testIsListWithEmptyArray(): void
    {
        $reflection = new ReflectionMethod(AdsService::class, 'isList');
        $reflection->setAccessible(true);

        $service = $this->createMockService();

        $this->assertTrue($reflection->invoke($service, []));
    }

    /**
     * Test isList utility method with indexed array
     */
    public function testIsListWithIndexedArray(): void
    {
        $reflection = new ReflectionMethod(AdsService::class, 'isList');
        $reflection->setAccessible(true);

        $service = $this->createMockService();

        $this->assertTrue($reflection->invoke($service, ['a', 'b', 'c']));
        $this->assertTrue($reflection->invoke($service, [0 => 'x', 1 => 'y']));
    }

    /**
     * Test isList utility method with associative array
     */
    public function testIsListWithAssociativeArray(): void
    {
        $reflection = new ReflectionMethod(AdsService::class, 'isList');
        $reflection->setAccessible(true);

        $service = $this->createMockService();

        $this->assertFalse($reflection->invoke($service, ['key' => 'value']));
        $this->assertFalse($reflection->invoke($service, [1 => 'a', 3 => 'b']));
    }

    /**
     * Test getCampaigns returns array with campaigns key
     */
    public function testGetCampaignsReturnsArrayStructure(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['ensureValidAccessToken', 'getCachedCampaigns'])
            ->getMock();

        $service->method('ensureValidAccessToken')->willReturn(false);
        $service->method('getCachedCampaigns')->willReturn([]);

        $result = $service->getCampaigns();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('campaigns', $result);
        $this->assertArrayHasKey('_meta', $result);
    }

    /**
     * Test getCampaigns uses cache when no token
     */
    public function testGetCampaignsUsesCacheWhenNoToken(): void
    {
        $cachedCampaigns = [
            ['id' => 1, 'name' => 'Campaign 1'],
            ['id' => 2, 'name' => 'Campaign 2'],
        ];

        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['ensureValidAccessToken', 'getCachedCampaigns'])
            ->getMock();

        $service->method('ensureValidAccessToken')->willReturn(false);
        $service->method('getCachedCampaigns')->willReturn($cachedCampaigns);

        $result = $service->getCampaigns();

        $this->assertSame($cachedCampaigns, $result['campaigns']);
        $this->assertSame('local_cache', $result['_meta']['data_source']);
    }

    /**
     * Test meta contains data source info
     */
    public function testMetaContainsDataSourceInfo(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['ensureValidAccessToken', 'getCachedCampaigns'])
            ->getMock();

        $service->method('ensureValidAccessToken')->willReturn(false);
        $service->method('getCachedCampaigns')->willReturn([]);

        $result = $service->getCampaigns();

        $this->assertArrayHasKey('data_source', $result['_meta']);
        $this->assertArrayHasKey('fetched_at', $result['_meta']);
    }

    /**
     * Test getCampaigns accepts status filter
     */
    public function testGetCampaignsAcceptsStatusFilter(): void
    {
        $reflection = new ReflectionMethod(AdsService::class, 'getCampaigns');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('status', $params[0]->getName());
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertSame('active', $params[0]->getDefaultValue());
    }

    /**
     * Test service has database property
     */
    public function testServiceHasDatabaseProperty(): void
    {
        $reflection = new ReflectionClass(AdsService::class);
        $this->assertTrue($reflection->hasProperty('db'));
    }

    /**
     * Test getCampaignMetricsForDates preserves api_status on 429 (no zero metrics mask)
     */
    public function testGetCampaignMetricsForDatesPreserves429(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();

        $service->method('resolvePadsAdvertiser')->willReturn([
            'advertiser_id' => 1509993,
            'site_id' => 'MLB',
        ]);
        $service->method('getWithHeaders')->willReturn([
            'error' => 'too_many_requests',
            'message' => 'Rate limit',
            'status' => 429,
            'success' => false,
        ]);

        $result = $service->getCampaignMetricsForDates('355714502', '2026-08-01', '2026-08-01');

        $this->assertFalse($result['ok']);
        $this->assertSame(429, $result['api_status']);
        $this->assertNotNull($result['error']);
        $this->assertNull($result['metrics']);
    }

    /**
     * Test getCampaignReport preserves 5xx without falling back to empty/cache zeros
     */
    public function testGetCampaignReportPreserves503(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCampaignMetricsForDates'])
            ->getMock();

        $service->method('getCampaignMetricsForDates')->willReturn([
            'ok' => false,
            'api_status' => 503,
            'error' => 'service_unavailable',
            'metrics' => null,
            'campaign_id' => '1',
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-01'],
        ]);

        $result = $service->getCampaignReport('1', '2026-08-01', '2026-08-01');

        $this->assertFalse($result['ok']);
        $this->assertSame(503, $result['api_status']);
        $this->assertNull($result['metrics']);
    }

    /**
     * Test getAdsItems preserves 429 in structured contract (not bare [])
     */
    public function testGetAdsItemsPreserves429(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();

        $service->method('resolvePadsAdvertiser')->willReturn([
            'advertiser_id' => 1509993,
            'site_id' => 'MLB',
        ]);
        $service->method('getWithHeaders')->willReturn([
            'error' => 'too_many_requests',
            'message' => 'Rate limit',
            'status' => 429,
            'success' => false,
        ]);

        $result = $service->getAdsItems('2026-08-01', '2026-08-01', 50, 'item');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertFalse($result['ok']);
        $this->assertSame(429, $result['api_status']);
        $this->assertSame([], $result['items']);
    }

    /**
     * Test getAdsItems pagination incomplete is fail-closed
     */
    public function testGetAdsItemsPaginationIncompleteFailClosed(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();

        $service->method('resolvePadsAdvertiser')->willReturn([
            'advertiser_id' => 1,
            'site_id' => 'MLB',
        ]);

        $service->method('getWithHeaders')->willReturnCallback(function () {
            static $n = 0;
            $n++;
            if ($n === 1) {
                return [
                    'paging' => ['offset' => 0, 'total' => 3, 'limit' => 2],
                    'results' => [
                        ['item_id' => 'MLB1', 'campaign_id' => 1, 'metrics' => ['cost' => 1]],
                        ['item_id' => 'MLB2', 'campaign_id' => 1, 'metrics' => ['cost' => 2]],
                    ],
                    'success' => true,
                ];
            }
            // Segunda página vazia com total=3 → incomplete
            return [
                'paging' => ['offset' => 2, 'total' => 3, 'limit' => 2],
                'results' => [],
                'success' => true,
            ];
        });

        $result = $service->getAdsItems('2026-08-01', '2026-08-01', 2, 'item');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['incomplete']);
        $this->assertSame('pagination_incomplete', $result['error']);
    }

    /**
     * @dataProvider invalidCampaignMetricsEnvelopeProvider
     * @param array<string, mixed> $wireResponse
     */
    public function testCampaignMetricsEnvelopeInvalidoFalhaFechado(array $wireResponse, string $expectedContext): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();
        $service->method('resolvePadsAdvertiser')->willReturn(['advertiser_id' => 1, 'site_id' => 'MLB']);
        $service->method('getWithHeaders')->willReturn($wireResponse);

        $result = $service->getCampaignMetricsForDates('campaign-1', '2026-08-01', '2026-08-01');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['metrics']);
        $this->assertStringContainsString($expectedContext, (string) $result['error']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public function invalidCampaignMetricsEnvelopeProvider(): array
    {
        return [
            'results missing' => [['success' => true], 'results'],
            'results wrong type' => [['success' => true, 'results' => 'invalid'], 'results'],
            'metrics missing' => [[
                'success' => true,
                'results' => [['id' => 'campaign-1']],
            ], 'metrics'],
            'metrics wrong type' => [[
                'success' => true,
                'results' => [['id' => 'campaign-1', 'metrics' => 'invalid']],
            ], 'metrics'],
        ];
    }

    public function testGetAdsItemsPaginaTodasAsPaginasReais(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();
        $service->method('resolvePadsAdvertiser')->willReturn(['advertiser_id' => 1, 'site_id' => 'MLB']);
        $offsets = [];
        $service->method('getWithHeaders')->willReturnCallback(function ($endpoint, array $query) use (&$offsets): array {
            $offsets[] = $query['offset'];
            if ($query['offset'] === 0) {
                return [
                    'success' => true,
                    'paging' => ['offset' => 0, 'limit' => 2, 'total' => 3],
                    'results' => [
                        ['item_id' => 'MLB1', 'campaign_id' => 'c1', 'metrics' => []],
                        ['item_id' => 'MLB2', 'campaign_id' => 'c1', 'metrics' => []],
                    ],
                ];
            }
            return [
                'success' => true,
                'paging' => ['offset' => 2, 'limit' => 2, 'total' => 3],
                'results' => [['item_id' => 'MLB3', 'campaign_id' => 'c1', 'metrics' => []]],
            ];
        });

        $result = $service->getAdsItems('2026-08-01', '2026-08-01', 2, 'item');

        $this->assertTrue($result['ok']);
        $this->assertSame([0, 2], $offsets);
        $this->assertSame(['MLB1', 'MLB2', 'MLB3'], array_column($result['items'], 'item_id'));
    }

    /**
     * @dataProvider invalidResultsProvider
     * @param array<string, mixed> $wireResponse
     */
    public function testGetAdsItemsResultsAusenteOuInvalidoFalhaFechado(array $wireResponse): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();
        $service->method('resolvePadsAdvertiser')->willReturn(['advertiser_id' => 1, 'site_id' => 'MLB']);
        $service->method('getWithHeaders')->willReturn($wireResponse);

        $result = $service->getAdsItems('2026-08-01', '2026-08-01', 2, 'item');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['items']);
        $this->assertSame('pads_invalid_wire_shape:results', $result['error']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function invalidResultsProvider(): array
    {
        return [
            'missing' => [['success' => true, 'paging' => ['offset' => 0, 'limit' => 2, 'total' => 0]]],
            'wrong type' => [[
                'success' => true,
                'paging' => ['offset' => 0, 'limit' => 2, 'total' => 0],
                'results' => 'invalid',
            ]],
        ];
    }

    public function testGetAdsItemsFalhaNaPaginaTardiaDescartaResultadosParciais(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();
        $service->method('resolvePadsAdvertiser')->willReturn(['advertiser_id' => 1, 'site_id' => 'MLB']);
        $service->method('getWithHeaders')->willReturnCallback(function ($endpoint, array $query): array {
            if ($query['offset'] === 0) {
                return [
                    'success' => true,
                    'paging' => ['offset' => 0, 'limit' => 2, 'total' => 3],
                    'results' => [
                        ['item_id' => 'MLB1', 'campaign_id' => 'c1', 'metrics' => []],
                        ['item_id' => 'MLB2', 'campaign_id' => 'c1', 'metrics' => []],
                    ],
                ];
            }
            return ['status' => 503, 'error' => 'service_unavailable'];
        });

        $result = $service->getAdsItems('2026-08-01', '2026-08-01', 2, 'item');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['incomplete']);
        $this->assertSame(503, $result['api_status']);
        $this->assertSame([], $result['items']);
    }

    /**
     * Test getAdsItems DAILY passa aggregation_type; wire oficial é date+metrics sem IDs inventados.
     */
    public function testGetAdsItemsDailyPassesAggregationType(): void
    {
        $service = $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePadsAdvertiser', 'getWithHeaders'])
            ->getMock();

        $service->method('resolvePadsAdvertiser')->willReturn([
            'advertiser_id' => 1,
            'site_id' => 'MLB',
        ]);

        $captured = new \stdClass();
        $captured->query = null;
        $service->method('getWithHeaders')->willReturnCallback(function ($endpoint, $query) use ($captured) {
            $captured->query = $query;
            // Shape oficial DAILY: date + métricas flat — sem item_id/campaign_id
            return [
                'paging' => ['offset' => 0, 'total' => 1, 'limit' => 50],
                'results' => [
                    [
                        'date' => '2026-07-30',
                        'clicks' => 1,
                        'cost' => 0.5,
                        'total_amount' => 2.0,
                    ],
                ],
                'success' => true,
            ];
        });

        $result = $service->getAdsItems('2026-07-01', '2026-08-01', 50, 'DAILY');

        $this->assertTrue($result['ok']);
        $this->assertSame('DAILY', $result['aggregation_type']);
        $this->assertSame('DAILY', $captured->query['aggregation_type'] ?? null);
        $this->assertSame('2026-07-30', $result['items'][0]['date']);
        $this->assertArrayHasKey('metrics', $result['items'][0]);
        $this->assertArrayNotHasKey('item_id', $result['items'][0]);
        $this->assertArrayNotHasKey('campaign_id', $result['items'][0]);
    }

    /**
     * Helper to create mock service
     */
    private function createMockService(): AdsService
    {
        return $this->getMockBuilder(AdsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }
}
