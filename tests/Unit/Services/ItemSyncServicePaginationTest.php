<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ItemSyncService;
use App\Services\MercadoLivreClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \App\Services\ItemSyncService
 */
class ItemSyncServicePaginationTest extends TestCase
{
    public function testFetchAllItemIdsSourcePrefersScan(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/../app/Services/ItemSyncService.php'
        );

        $this->assertStringContainsString("search_type' => 'scan'", $source);
        $this->assertStringContainsString('scroll_id', $source);
        $this->assertStringContainsString('ITEM_SYNC_OFFSET_TRUNCATED', $source);
        $this->assertStringContainsString('fetchAllItemIdsViaScan', $source);
        $this->assertStringContainsString('refreshItemsMissingFromRemoteCatalog', $source);
        $this->assertStringContainsString('stale_refreshed', $source);
        $this->assertStringContainsString('touchAccountLastSyncedAt', $source);
    }

    public function testFetchAllItemIdsViaScanWalksScrollId(): void
    {
        $service = $this->getMockBuilder(ItemSyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $ml = $this->createMock(MercadoLivreClient::class);
        $ml->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint, array $params = []) {
                $this->assertStringContainsString('/items/search', $endpoint);
                $this->assertSame('scan', $params['search_type'] ?? null);

                if (!isset($params['scroll_id'])) {
                    return [
                        'results' => ['MLB1', 'MLB2'],
                        'scroll_id' => 'scroll-abc',
                        'paging' => ['total' => 3],
                    ];
                }

                $this->assertSame('scroll-abc', $params['scroll_id']);
                return [
                    'results' => ['MLB3'],
                    'scroll_id' => '',
                    'paging' => ['total' => 3],
                ];
            });

        $refClient = new \ReflectionProperty(ItemSyncService::class, 'mlClient');
        $refClient->setAccessible(true);
        $refClient->setValue($service, $ml);

        $method = new ReflectionMethod(ItemSyncService::class, 'fetchAllItemIdsViaScan');
        $method->setAccessible(true);
        /** @var list<string>|null $ids */
        $ids = $method->invoke($service, '3058804121');

        $this->assertSame(['MLB1', 'MLB2', 'MLB3'], $ids);
    }

    public function testFetchAllItemIdsViaScanReturnsNullOnFirstPageError(): void
    {
        $service = $this->getMockBuilder(ItemSyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $ml = $this->createMock(MercadoLivreClient::class);
        $ml->method('get')->willReturn([
            'error' => 'forbidden',
            'message' => 'no access',
        ]);

        $refClient = new \ReflectionProperty(ItemSyncService::class, 'mlClient');
        $refClient->setAccessible(true);
        $refClient->setValue($service, $ml);

        $method = new ReflectionMethod(ItemSyncService::class, 'fetchAllItemIdsViaScan');
        $method->setAccessible(true);
        $ids = $method->invoke($service, '3058804121');

        $this->assertNull($ids);
    }



    public function testWebhookControllerDelegatesToIdempotentController(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/../app/Controllers/WebhookController.php'
        );

        $this->assertStringContainsString('MercadoLivreWebhookController', $source);
        $this->assertStringContainsString('(new MercadoLivreWebhookController())->receive()', $source);
        $this->assertStringNotContainsString('INSERT INTO webhook_events', $source);
    }

    public function testClientDisablesProxyWithEmptyStringNotBooleanFalse(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/../app/Services/MercadoLivreClient.php'
        );

        $this->assertStringContainsString("? \$proxy : ''", $source);
        $this->assertStringContainsString('CURLOPT_PROXY must be a string', $source);
    }
}
