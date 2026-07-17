<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Quality;

use PHPUnit\Framework\TestCase;
use App\Services\Quality\HealthCheckService;
use App\Services\MercadoLivreClient;

/**
 * @covers \App\Services\Quality\HealthCheckService
 */
class HealthCheckServiceTest extends TestCase
{
    private function buildService(MercadoLivreClient $mockClient): HealthCheckService
    {
        $ref = new \ReflectionClass(HealthCheckService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $mockClient);

        // CategoryService is also needed — inject a mock
        $catMock = $this->createMock(\App\Services\CategoryService::class);
        $catProp = $ref->getProperty('categoryService');
        $catProp->setAccessible(true);
        $catProp->setValue($service, $catMock);

        return $service;
    }

    public function testCheckItemHealthSuccess(): void
    {
        $mock = $this->createMock(MercadoLivreClient::class);
        $mock->method('get')
            ->willReturn([
                'id' => 'MLB123',
                'title' => 'Bagageiro CG 160 Titan',
                'status' => 'active',
                'price' => 189.90,
                'pictures' => [['id' => 'p1'], ['id' => 'p2'], ['id' => 'p3']],
                'attributes' => [['id' => 'BRAND', 'value_name' => 'AWA']],
                'shipping' => ['free_shipping' => true],
                'listing_type_id' => 'gold_special',
            ]);
        $mock->method('getItemHealth')
            ->with('MLB123')
            ->willReturn([
                'item_id' => 'MLB123',
                'health_score' => 72,
                'status' => 'fair',
                'level_wording' => 'Regular',
                'issues' => [],
                'recommendations' => ['Completar título'],
                'buckets' => [],
                'calculated_at' => '2026-07-17T12:00:00Z',
            ]);

        $service = $this->buildService($mock);
        $result = $service->checkItemHealth('MLB123');

        $this->assertTrue($result['success']);
        $this->assertSame('MLB123', $result['item_id']);
        $this->assertArrayHasKey('health', $result);
        $this->assertArrayHasKey('score', $result['health']);
        $this->assertTrue($result['health']['api_health']['available']);
        $this->assertSame('official_ml_performance', $result['health']['api_health']['source']);
        $this->assertSame(72, $result['health']['api_health']['score']);
    }

    public function testCheckItemHealthApiError(): void
    {
        $mock = $this->createMock(MercadoLivreClient::class);
        $mock->method('get')
            ->willReturn(['error' => 'not_found', 'message' => 'Item not found']);

        $service = $this->buildService($mock);
        $result = $service->checkItemHealth('MLB999');

        $this->assertFalse($result['success']);
        $this->assertSame('Item not found', $result['error']);
    }

    public function testCheckItemHealthException(): void
    {
        $mock = $this->createMock(MercadoLivreClient::class);
        $mock->method('get')
            ->willThrowException(new \RuntimeException('Connection timed out'));

        $service = $this->buildService($mock);
        $result = $service->checkItemHealth('MLB123');

        $this->assertFalse($result['success']);
        $this->assertSame('Connection timed out', $result['error']);
    }
}
